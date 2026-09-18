<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Domain\SpeakerService;
use Meydan\Core\Domain\SpeakerAdminService;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use WP_REST_Request;
use WP_REST_Response;
use WP_User_Query;

/**
 * Speaker profiles, backed by WordPress users holding the `meydan_speaker` role.
 *
 * There is no speaker post any more: every endpoint addresses the speaker
 * account by user id, and response shapes mirror the previous post-backed API
 * so existing clients keep working.
 */
final class SpeakerController extends BaseController
{
    public function list(WP_REST_Request $r): WP_REST_Response
    {
        return $this->query($r);
    }

    /** Administrator list keeps the same speaker shape but is explicitly private. */
    public function adminList(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->speakerAdmin()) {
            return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        }
        return $this->query($r, true);
    }

    public function get(WP_REST_Request $r): WP_REST_Response
    {
        $d = $this->enrich(Serializer::speaker((int) $r['id']));
        return $d
            ? Response::cache(Response::ok($d), 'public, max-age=60, stale-while-revalidate=300')
            : Response::error('not_found', 'سخنران پیدا نشد.', 404);
    }

    /** Admin: the accounts selectable for promotion to speaker. */
    public function linkableUsers(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->speakerAdmin()) {
            return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        }
        $out = [];
        foreach (SpeakerService::promotableUsers() as $id => $name) {
            $out[] = ['id' => (int) $id, 'name' => $name];
        }
        return Response::ok($out);
    }

    /** Topical categories for the filter UI. Public and cheap. */
    public function categories(WP_REST_Request $r): WP_REST_Response
    {
        return Response::cache(Response::ok(SpeakerService::categoryTerms()), 'public, max-age=60, stale-while-revalidate=300');
    }

    /** Promotes an existing account, then saves the supplied profile fields. */
    public function adminCreate(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->speakerAdmin()) {
            return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        }
        $p = $this->json($r);
        $userId = (int) ($p['user_id'] ?? 0);
        if ($userId <= 0 || !get_userdata($userId)) {
            return Response::error('validation_failed', 'کاربر انتخاب‌شده معتبر نیست.', 422, ['user_id' => 'invalid']);
        }
        $promoted = SpeakerService::promote($userId);
        if (is_wp_error($promoted)) {
            return $this->error($promoted);
        }
        return $this->saveProfile($userId, $p, null);
    }

    /** Admin-only direct account creation; no OTP challenge is needed here. */
    public function adminCreateAccount(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->speakerAdmin()) {
            return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        }
        $created = SpeakerAdminService::create($this->json($r));
        if (is_wp_error($created)) {
            return $this->error($created);
        }
        return Response::ok($this->enrich(Serializer::speaker($created['user_id'])), [], 201);
    }

    public function adminUpdate(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->speakerAdmin()) {
            return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        }
        $id = (int) $r['id'];
        $before = Serializer::speaker($id);
        if (!$before) {
            return Response::error('not_found', 'سخنران پیدا نشد.', 404);
        }
        return $this->saveProfile($id, $this->json($r), $before);
    }

    /** Removes the speaker role; the account and its profile meta stay. */
    public function adminDelete(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->speakerAdmin()) {
            return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        }
        $id = (int) $r['id'];
        $before = Serializer::speaker($id);
        if (!$before) {
            return Response::error('not_found', 'سخنران پیدا نشد.', 404);
        }
        $demoted = SpeakerService::demote($id);
        if (is_wp_error($demoted)) {
            return $this->error($demoted);
        }
        AuditLogger::log('speaker_demoted', 'speaker', $id, $before, ['account_type' => 'user']);
        return Response::ok(['deleted' => true]);
    }

    /**
     * Public speaker discovery remains a lightweight capped list, while the
     * administrator directory exposes a real page/per_page contract.
     */
    private function query(WP_REST_Request $r, bool $paginate = false): WP_REST_Response
    {
        $meta = [];
        if ($this->bool($r->get_param('verified'))) {
            $meta[] = ['key' => 'meydan_verified', 'value' => '1'];
        }
        // Topical category is the speaker filter axis.
        if ($category = sanitize_key((string) $r->get_param('speaker_category'))) {
            $meta[] = ['key' => 'meydan_speaker_categories', 'value' => $category, 'compare' => 'LIKE'];
        }

        $city = (int) $r->get_param('city_id');
        if ($city > 0) {
            // `meydan_cities` is a serialized integer array. Matching the
            // integer token avoids the false positives of searching "12".
            $meta[] = ['key' => 'meydan_cities', 'value' => 'i:' . $city . ';', 'compare' => 'LIKE'];
        }

        $page = max(1, (int) ($r->get_param('page') ?: 1));
        $perPage = min(100, max(1, (int) ($r->get_param('per_page') ?: 20)));
        $args = [
            'role' => SpeakerService::ROLE,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => $paginate ? $perPage : 50,
            'fields' => 'ID',
        ];
        if ($paginate) {
            $args['offset'] = ($page - 1) * $perPage;
            $args['count_total'] = true;
        }
        if ($q = trim((string) $r->get_param('q'))) {
            $args['search'] = '*' . $q . '*';
        }
        if ($meta) {
            $args['meta_query'] = $meta;
        }
        if (!$paginate) {
            $args['meta_query'][] = ['relation' => 'OR', ['key' => 'meydan_disabled', 'compare' => 'NOT EXISTS'], ['key' => 'meydan_disabled', 'value' => '1', 'compare' => '!=']];
        }

        $total = 0;
        if ($paginate) {
            $query = new WP_User_Query($args);
            $ids = (array) $query->get_results();
            $total = (int) $query->get_total();
        } else {
            $ids = (array) get_users($args);
        }

        $data = [];
        foreach ($ids as $id) {
            $item = $this->enrich(Serializer::speaker((int) $id));
            if ($item) {
                $data[] = $item;
            }
        }
        if ($paginate) {
            return Response::ok($data, [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ]);
        }

        return Response::cache(Response::ok($data), 'public, max-age=60, stale-while-revalidate=300');
    }

    private function saveProfile(int $userId, array $p, ?array $before): WP_REST_Response
    {
        if (array_key_exists('verified', $p)) {
            $p['verified'] = $this->bool($p['verified']);
        }
        // The identifier is the route/body, never a profile field.
        unset($p['user_id']);

        $saved = SpeakerService::save($p, $userId);
        if (is_wp_error($saved)) {
            return $this->error($saved);
        }
        AuditLogger::log($before ? 'speaker_updated' : 'speaker_created', 'speaker', $userId, $before, Serializer::speaker($userId));
        return Response::ok($this->enrich(Serializer::speaker($userId)), [], $before ? 200 : 201);
    }

    private function enrich(?array $d): ?array
    {
        if (!$d) {
            return null;
        }
        $id = (int) $d['id'];
        $d['slug'] = sanitize_title((string) $d['name']) ?: (string) $id;
        $d['handle'] = (string) get_user_meta($id, 'meydan_handle', true);
        $d['expertise'] = (string) get_user_meta($id, 'meydan_expertise', true);
        $d['initials'] = (string) get_user_meta($id, 'meydan_initials', true);
        return $d;
    }

    private function speakerAdmin(): bool
    {
        return current_user_can('manage_meydan_speakers');
    }
}

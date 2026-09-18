<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Domain\SpeakerService;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Cursor;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use Meydan\Core\Support\SquareActivity;
use WP_Query;
use WP_REST_Request;

final class MeController extends BaseController
{
    public function me()
    {
        if ($e = $this->guard()) return $e;
        $uid = get_current_user_id();
        $type = $this->accountType($uid);

        if ($type === 'square') {
            $sid = (int) get_user_meta($uid, 'meydan_square_id', true);
            return Response::cache(
                Response::ok([
                    'account_type' => 'square',
                    ...$this->rolePayload($uid),
                    'square' => $this->squareProfile($sid),
                ]),
                'private, no-store'
            );
        }

        if ($type === 'speaker') {
            return Response::cache(
                Response::ok([
                    'account_type' => 'speaker',
                    ...$this->rolePayload($uid),
                    'profile' => $this->profile($uid),
                    'speaker' => Serializer::speaker($uid),
                ]),
                'private, no-store'
            );
        }

        return Response::cache(
            Response::ok([
                'account_type' => 'user',
                ...$this->rolePayload($uid),
                'profile' => $this->profile($uid),
            ]),
            'private, no-store'
        );
    }

    /** @return array{role:?string,roles:array<int,string>} */
    private function rolePayload(int $uid): array
    {
        $user = get_userdata($uid);
        $roles = $user ? array_values(array_map('sanitize_key', (array) $user->roles)) : [];

        return [
            'role' => $roles[0] ?? null,
            'roles' => $roles,
        ];
    }

    public function patchProfile(WP_REST_Request $r)
    {
        if ($e = $this->guard()) return $e;
        $uid = get_current_user_id();
        $p = $this->json($r);
        $before = $this->profile($uid);

        foreach (['full_name', 'headline', 'about', 'location_label'] as $k) {
            if (array_key_exists($k, $p)) {
                update_user_meta(
                    $uid,
                    'meydan_' . $k,
                    $k === 'about' ? wp_kses_post((string) $p[$k]) : sanitize_text_field((string) $p[$k])
                );
            }
        }

        foreach (['province_id', 'city_id'] as $k) {
            if (array_key_exists($k, $p)) update_user_meta($uid, 'meydan_' . $k, (int) $p[$k]);
        }
        if (array_key_exists('avatar_media_id', $p)) {
            $avatarId = $this->profileImageMediaId($p['avatar_media_id'], $uid, 'avatar');
            if (is_wp_error($avatarId)) return $this->error($avatarId);
            update_user_meta($uid, 'meydan_avatar_media_id', $avatarId);
        }
        if (array_key_exists('cover_media_id', $p)) {
            $coverId = $this->profileImageMediaId($p['cover_media_id'], $uid, 'cover');
            if (is_wp_error($coverId)) return $this->error($coverId);
            update_user_meta($uid, 'meydan_cover_media_id', $coverId);
        }

        // A speaker edits the speaker part of its own profile through the same
        // endpoint; the account is the profile, so there is no second object.
        if (Actor::isSpeaker($uid)) {
            $speaker = [];
            foreach (['role', 'handle', 'expertise', 'initials'] as $k) {
                if (array_key_exists($k, $p)) $speaker[$k] = $p[$k];
            }
            if (array_key_exists('categories', $p)) $speaker['categories'] = (array) $p['categories'];
            if (array_key_exists('speaker_categories', $p)) $speaker['categories'] = (array) $p['speaker_categories'];
            if (array_key_exists('social_links', $p)) $speaker['social_links'] = $p['social_links'];
            if (array_key_exists('cities', $p)) $speaker['cities'] = $p['cities'];
            if ($speaker) {
                $saved = SpeakerService::save($speaker, $uid);
                if (is_wp_error($saved)) return $this->error($saved);
            }
        }

        if (isset($p['skills'])) {
            update_user_meta(
                $uid,
                'meydan_skills',
                array_values(array_filter(array_map('sanitize_text_field', (array) $p['skills'])))
            );
        }

        if (isset($p['resume_stats'])) {
            $stats = [];
            foreach ((array) $p['resume_stats'] as $item) {
                if (!is_array($item)) continue;
                $label = sanitize_text_field((string) ($item['label'] ?? ''));
                $value = sanitize_text_field((string) ($item['value'] ?? ''));
                if ($label === '' || $value === '') continue;
                $row = ['label' => $label, 'value' => $value];
                if (($item['tone'] ?? '') === 'success') $row['tone'] = 'success';
                $stats[] = $row;
            }
            update_user_meta($uid, 'meydan_resume_stats', $stats);
        }

        AuditLogger::log('profile_updated', 'user', $uid, $before, $this->profile($uid));
        return Response::ok($this->profile($uid));
    }

    public function narratives(WP_REST_Request $r)
    {
        if ($e = $this->guard()) return $e;
        $uid = get_current_user_id();
        // Author actor type never carries `speaker`: it is a user actor.
        $type = Actor::actorType($uid);
        $actorId = $type === 'square' ? (int) get_user_meta($uid, 'meydan_square_id', true) : $uid;
        return $this->actorNarratives($type, $actorId, $r);
    }

    public function following()
    {
        if ($e = $this->guard()) return $e;
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT object_type,object_id,created_at FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND action='follow' ORDER BY id DESC LIMIT 100", get_current_user_id()), ARRAY_A);
        $data = [];
        foreach ($rows ?: [] as $row) {
            $a = Actor::parse($row['object_type'], (int) $row['object_id']);
            if ($a) {
                $a['followed_at'] = gmdate(DATE_ATOM, strtotime($row['created_at'] . ' UTC'));
                $data[] = $a;
            }
        }
        return Response::ok($data);
    }

    public function initiatives()
    {
        if ($e = $this->guard()) return $e;
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT initiative_id FROM {$wpdb->prefix}meydan_initiative_members WHERE user_id=%d AND status='active' ORDER BY joined_at DESC", get_current_user_id()));
        return Response::ok(array_values(array_filter(array_map([Serializer::class, 'initiative'], array_map('intval', $ids ?: [])))));
    }

    public function bookmarks()
    {
        if ($e = $this->guard()) return $e;
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND object_type='content' AND action='bookmark' ORDER BY created_at DESC", get_current_user_id()));
        return Response::ok(array_values(array_filter(array_map([Serializer::class, 'content'], array_map('intval', $ids ?: [])))));
    }

    public function speakerRequests()
    {
        if ($e = $this->guard()) return $e;
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_speaker_requests WHERE requester_user_id=%d ORDER BY created_at DESC", get_current_user_id()), ARRAY_A);
        return Response::ok(array_map([$this, 'speakerRow'], $rows ?: []));
    }

    public function patchSquare(WP_REST_Request $r)
    {
        if ($e = $this->guardSquare()) return $e;
        $uid = get_current_user_id();
        $sid = (int) get_user_meta($uid, 'meydan_square_id', true);
        $p = $this->json($r);
        $before = $this->squareProfile($sid);
        if (isset($p['name'])) update_user_meta($uid, 'meydan_full_name', sanitize_text_field((string) $p['name']));
        if (isset($p['description'])) update_user_meta($uid, 'meydan_about', wp_kses_post((string) $p['description']));
        if (array_key_exists('avatar_media_id', $p)) {
            $avatarId = $this->profileImageMediaId($p['avatar_media_id'], $uid, 'avatar');
            if (is_wp_error($avatarId)) return $this->error($avatarId);
            update_user_meta($uid, 'meydan_avatar_media_id', $avatarId);
        }
        if (array_key_exists('cover_media_id', $p)) {$coverId = $this->profileImageMediaId($p['cover_media_id'], $uid, 'cover');if (is_wp_error($coverId)) return $this->error($coverId);update_user_meta($uid, 'meydan_cover_media_id', $coverId);}
        if (isset($p['subtitle'])) update_user_meta($uid, 'meydan_headline', sanitize_text_field((string) $p['subtitle']));
        if (isset($p['profile_about'])) update_user_meta($uid, 'meydan_about', wp_kses_post((string) $p['profile_about']));
        if (isset($p['profile_skills'])) update_user_meta($uid, 'meydan_skills', array_values(array_filter(array_map('sanitize_text_field', (array) $p['profile_skills']))));
        if (array_key_exists('start_date', $p)) {
            $saved = SquareActivity::setStartDate($sid, $p['start_date']);
            if (is_wp_error($saved)) return $this->error($saved);
        }
        AuditLogger::log('square_updated', 'square', $sid, $before, $this->squareProfile($sid));
        return Response::ok($this->squareProfile($sid));
    }

    public function putSquareLocation(WP_REST_Request $r)
    {
        if ($e = $this->guardSquare()) return $e;
        $sid = (int) get_user_meta(get_current_user_id(), 'meydan_square_id', true);
        $p = $this->json($r);
        foreach (['province_id', 'city_id', 'address', 'latitude', 'longitude'] as $k) {
            if (!isset($p[$k])) return Response::error('validation_failed', 'اطلاعات موقعیت کامل نیست.', 422, [$k => 'required']);
        }
        global $wpdb;
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_geo WHERE square_id=%d", $sid), ARRAY_A);
        $wpdb->replace($wpdb->prefix . 'meydan_square_geo', [
            'square_id' => $sid,
            'province_id' => (int) $p['province_id'],
            'city_id' => (int) $p['city_id'],
            'address' => sanitize_textarea_field((string) $p['address']),
            'latitude' => (float) $p['latitude'],
            'longitude' => (float) $p['longitude'],
            'updated_at' => current_time('mysql', true),
        ]);
        AuditLogger::log('square_location_updated', 'square', $sid, $before, $p);
        return Response::ok($this->squareProfile($sid)['location']);
    }

    public function schedule(WP_REST_Request $r)
    {
        if ($e = $this->guardSquare()) return $e;
        $sid = (int) get_user_meta(get_current_user_id(), 'meydan_square_id', true);
        return Response::ok(Serializer::squareSchedule($sid));
    }

    public function createSchedule(WP_REST_Request $r){return $this->writeSchedule($r,0);}
    public function updateSchedule(WP_REST_Request $r){return $this->writeSchedule($r,(int)$r['id']);}

    public function deleteSchedule(WP_REST_Request $r)
    {
        if ($e = $this->guardSquare()) return $e;
        $sid = (int) get_user_meta(get_current_user_id(), 'meydan_square_id', true);
        global $wpdb;
        $id = (int) $r['id'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_schedule WHERE id=%d AND square_id=%d", $id, $sid), ARRAY_A);
        if (!$row) return Response::error('not_found', 'برنامه پیدا نشد.', 404);
        $wpdb->delete($wpdb->prefix . 'meydan_square_schedule', ['id' => $id]);
        AuditLogger::log('schedule_deleted', 'schedule', $id, $row, null);
        return Response::ok(['deleted' => true]);
    }

    /**
     * Persists the programme order. The client has always called
     * `PUT /me/square/schedule/order`, but the route was never registered, so
     * reordering silently failed and the list snapped back.
     */
    public function reorderSchedule(WP_REST_Request $r)
    {
        if ($e = $this->guardSquare()) return $e;
        $sid = (int) get_user_meta(get_current_user_id(), 'meydan_square_id', true);
        $p = $this->json($r);
        $ids = array_values(array_filter(array_map('intval', (array) ($p['schedule_ids'] ?? []))));
        if (!$ids) return Response::error('validation_failed', 'فهرست برنامه‌ها الزامی است.', 422);

        global $wpdb;
        $table = $wpdb->prefix . 'meydan_square_schedule';
        foreach ($ids as $position => $id) {
            $wpdb->update(
                $table,
                ['position' => $position, 'updated_at' => current_time('mysql', true)],
                ['id' => $id, 'square_id' => $sid]
            );
        }
        AuditLogger::log('schedule_reordered', 'schedule', $sid, null, ['ids' => $ids]);
        return Response::ok(Serializer::squareSchedule($sid));
    }

    private function writeSchedule(WP_REST_Request $r, int $id)
    {
        if ($e = $this->guardSquare()) return $e;
        $sid = (int) get_user_meta(get_current_user_id(), 'meydan_square_id', true);
        $p = $this->json($r);
        if (empty($p['title']) || empty($p['starts_at'])) return Response::error('validation_failed', 'عنوان و زمان شروع الزامی است.', 422);
        global $wpdb;
        $data = [
            'square_id' => $sid,
            'title' => sanitize_text_field((string) $p['title']),
            'description' => sanitize_textarea_field((string) ($p['description'] ?? '')),
            'starts_at' => gmdate('Y-m-d H:i:s', strtotime((string) $p['starts_at'])),
            'ends_at' => !empty($p['ends_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $p['ends_at'])) : null,
            'location_label' => sanitize_text_field((string) ($p['location_label'] ?? '')),
            'status' => sanitize_key((string) ($p['status'] ?? 'published')),
            'position' => (int) ($p['position'] ?? 0),
            'updated_at' => current_time('mysql', true),
        ];
        $before = null;
        if ($id) {
            $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_schedule WHERE id=%d AND square_id=%d", $id, $sid), ARRAY_A);
            if (!$before) return Response::error('not_found', 'برنامه پیدا نشد.', 404);
            $wpdb->update($wpdb->prefix . 'meydan_square_schedule', $data, ['id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql', true);
            $wpdb->insert($wpdb->prefix . 'meydan_square_schedule', $data);
            $id = (int) $wpdb->insert_id;
        }
        AuditLogger::log($before ? 'schedule_updated' : 'schedule_created', 'schedule', $id, $before, $data);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_schedule WHERE id=%d", $id), ARRAY_A);
        return Response::ok(Serializer::scheduleRow($row), [], $before ? 200 : 201);
    }

    private function profile(int $uid): array
    {
        $narratives = (int) (new WP_Query([
            'post_type' => 'meydan_narrative',
            'post_status' => 'publish',
            'meta_query' => [
                ['key' => 'meydan_author_actor_type', 'value' => 'user'],
                ['key' => 'meydan_author_actor_id', 'value' => $uid],
            ],
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]))->found_posts;

        $resumeStats = array_values((array) get_user_meta($uid, 'meydan_resume_stats', true));
        if (!$resumeStats) {
            $resumeStats = [
                ['value' => (string) $narratives, 'label' => 'روایت منتشرشده'],
                ['value' => 'فعال', 'label' => 'وضعیت عضویت', 'tone' => 'success'],
                ['value' => Actor::isVerifiedUser($uid) ? 'تأییدشده' : 'عادی', 'label' => 'اعتبار هویت'],
            ];
        }

        return [
            'id' => $uid,
            'full_name' => (string) get_user_meta($uid, 'meydan_full_name', true) ?: (get_userdata($uid)?->display_name ?: 'کاربر میدان'),
            'avatar_media_id' => (int) get_user_meta($uid, 'meydan_avatar_media_id', true) ?: null,
            'avatar_url' => Actor::avatarUrl((int) get_user_meta($uid, 'meydan_avatar_media_id', true)),
            'cover_media_id' => (int) get_user_meta($uid, 'meydan_cover_media_id', true) ?: null,
            'cover_url' => Actor::coverUrl($uid),
            'headline' => (string) get_user_meta($uid, 'meydan_headline', true),
            'verified' => Actor::isVerifiedUser($uid),
            'province_id' => (int) get_user_meta($uid, 'meydan_province_id', true) ?: null,
            'city_id' => (int) get_user_meta($uid, 'meydan_city_id', true) ?: null,
            'location_label' => (string) get_user_meta($uid, 'meydan_location_label', true),
            'about' => (string) get_user_meta($uid, 'meydan_about', true),
            'skills' => array_values((array) get_user_meta($uid, 'meydan_skills', true)),
            'resume_stats' => $resumeStats,
            'stats' => ['narratives' => $narratives],
        ];
    }

    private function squareProfile(int $sid): ?array
    {
        $data = Serializer::square($sid);
        if (!$data) return null;
        $post = get_post($sid);
        $data['slug'] = $post ? $post->post_name : (string) $sid;
        $ownerId = Actor::squareOwnerUserId($sid);
        $data['avatar_media_id'] = (int) get_user_meta($ownerId, 'meydan_avatar_media_id', true) ?: ((int) get_post_meta($sid, 'meydan_avatar_media_id', true) ?: null);
        $data['cover_media_id'] = (int) get_user_meta($ownerId, 'meydan_cover_media_id', true) ?: null;
        $data['cover_url'] = Actor::coverUrl($ownerId);
        $data['handle'] = (string) get_post_meta($sid, 'meydan_handle', true);
        $data['subtitle'] = (string) get_user_meta($ownerId, 'meydan_headline', true) ?: (string) get_post_meta($sid, 'meydan_subtitle', true);
        $data['profile_about'] = (string) get_user_meta($ownerId, 'meydan_about', true) ?: (string) get_post_meta($sid, 'meydan_profile_about', true);
        $data['profile_skills'] = array_values((array) get_user_meta($ownerId, 'meydan_skills', true) ?: (array) get_post_meta($sid, 'meydan_profile_skills', true));
        $data['square_stats'] = array_values((array) get_post_meta($sid, 'meydan_square_stats', true));
        $data['resume_stats'] = array_values((array) get_post_meta($sid, 'meydan_resume_stats', true));
        $data['start_date'] = SquareActivity::startDate($sid);
        $data['stats'] = is_array($data['stats'] ?? null) ? $data['stats'] : [];
        $data['stats']['active_nights'] = SquareActivity::activeNights($sid);
        return $data;
    }

    private function actorNarratives(string $type, int $id, WP_REST_Request $r)
    {
        $meta = [
            'relation' => 'AND',
            ['key' => 'meydan_author_actor_type', 'value' => $type],
            ['key' => 'meydan_author_actor_id', 'value' => $id],
        ];
        if ($type === 'square') {
            $ownerId = Actor::squareOwnerUserId($id);
            if ($ownerId > 0) {
                $meta = [
                    'relation' => 'OR',
                    ['relation' => 'AND', ['key' => 'meydan_author_actor_type', 'value' => 'square'], ['key' => 'meydan_author_actor_id', 'value' => $id]],
                    ['relation' => 'AND', ['key' => 'meydan_author_actor_type', 'value' => 'user'], ['key' => 'meydan_author_actor_id', 'value' => $ownerId]],
                ];
            }
        }
        return ProfileNarrativePage::list($meta, $r, $type . ':' . $id);

    }

    /** Canonical resolution lives on Actor so other controllers share it. */
    private function accountType(int $uid): string
    {
        return Actor::accountType($uid);
    }

    private function profileImageMediaId(mixed $value, int $uid, string $purpose): int|\WP_Error
    {
        $mediaId = (int) $value;
        if ($mediaId === 0) return 0;
        if (!wp_attachment_is_image($mediaId)) return new \WP_Error('validation_failed', 'تصویر پروفایل باید معتبر باشد.', ['status' => 422]);
        if ((int) get_post_meta($mediaId, 'meydan_upload_owner_user_id', true) !== $uid || get_post_meta($mediaId, 'meydan_upload_purpose', true) !== $purpose) {
            return new \WP_Error('forbidden', 'فقط تصویر آپلودشده توسط خودتان قابل انتخاب است.', ['status' => 403]);
        }
        return $mediaId;
    }

    private function guard(){return is_user_logged_in()?null:Response::error('unauthenticated','برای انجام این عملیات باید وارد شوید.',401);}
    private function guardSquare(){if($e=$this->guard())return $e;return Actor::isSquare((int)get_current_user_id())?null:Response::error('forbidden','این عملیات فقط برای حساب میدان مجاز است.',403);}
    public function speakerRow(array $r):array{return ['id'=>(int)$r['id'],'creator_id'=>(int)$r['creator_id'],'speaker_user_id'=>(int)($r['speaker_user_id']??0)?:null,'venue'=>$r['venue'],'requested_at'=>gmdate(DATE_ATOM,strtotime($r['requested_at'].' UTC')),'note'=>$r['note'],'status'=>$r['status'],'created_at'=>gmdate(DATE_ATOM,strtotime($r['created_at'].' UTC'))];}
}

<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Admin\Admin;
use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Domain\SquareAdminService;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use WP_Query;
use WP_REST_Request;

final class AdminSquareController extends BaseController
{
    public function list(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $page = max(1, (int) ($r->get_param('page') ?: 1));
        $perPage = min(100, max(1, (int) ($r->get_param('per_page') ?: 20)));
        $args = ['post_type' => 'meydan_square', 'post_status' => ['publish', 'pending', 'draft'], 'posts_per_page' => $perPage, 'paged' => $page, 'orderby' => 'date', 'order' => 'DESC'];
        if ($q = trim((string) $r->get_param('q'))) $args['s'] = $q;
        $meta = [];
        if ($status = sanitize_key((string) $r->get_param('status'))) $meta[] = ['key' => 'meydan_approval_status', 'value' => $status];
        if ($r->has_param('verified')) $meta[] = ['key' => 'meydan_verified', 'value' => $this->bool($r->get_param('verified')) ? '1' : '0'];
        global $wpdb;
        $province = (int) $r->get_param('province_id');
        $city = (int) $r->get_param('city_id');
        if ($province > 0 || $city > 0) {
            $where = [];
            $geoArgs = [];
            if ($province > 0) { $where[] = 'province_id=%d'; $geoArgs[] = $province; }
            if ($city > 0) { $where[] = 'city_id=%d'; $geoArgs[] = $city; }
            $geoSql = 'SELECT square_id FROM ' . $wpdb->prefix . 'meydan_square_geo WHERE ' . implode(' AND ', $where);
            $ids = $wpdb->get_col($wpdb->prepare($geoSql, ...$geoArgs));
            $args['post__in'] = $ids ? array_map('intval', $ids) : [0];
        }
        if ($meta) $args['meta_query'] = $meta;
        $q = new WP_Query($args);
        $items = array_values(array_filter(
            array_map([$this, 'adminSquare'], $q->posts),
            static fn ($item): bool => is_array($item) && isset($item['id']),
        ));
        return Response::ok($items, ['page' => $page, 'per_page' => $perPage, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages]);
    }

    public function create(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $result = SquareAdminService::create($this->json($r));
        if (is_wp_error($result)) return $this->error($result);
        return Response::ok($this->adminSquare(get_post((int) $result['square_id'])), [], 201);
    }

    public function get(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $id = (int) $r['id'];
        if (get_post_type($id) !== 'meydan_square' || get_post_status($id) === 'trash') return Response::error('not_found', 'میدان پیدا نشد.', 404);
        return Response::ok($this->adminSquare(get_post($id)));
    }

    public function update(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $id = (int) $r['id'];
        $before = Serializer::square($id);
        if (!$before) return Response::error('not_found', 'میدان پیدا نشد.', 404);
        $saved = SquareAdminService::update($id, $this->json($r));
        if (is_wp_error($saved)) return $this->error($saved);
        return Response::ok($this->adminSquare(get_post($id)));
    }

    public function delete(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $id = (int) $r['id'];
        $before = Serializer::square($id);
        if (!$before) return Response::error('not_found', 'میدان پیدا نشد.', 404);
        wp_trash_post($id);
        AuditLogger::log('square_deleted', 'square', $id, $before, ['status' => 'trash']);
        return Response::ok(['deleted' => true, 'id' => $id]);
    }

    public function status(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $id = (int) $r['id'];
        if (get_post_type($id) !== 'meydan_square') return Response::error('not_found', 'میدان پیدا نشد.', 404);
        $p = $this->json($r);
        $status = sanitize_key((string) ($p['status'] ?? ''));
        if (!in_array($status, SquareAdminService::STATUSES, true)) return Response::error('validation_failed', 'وضعیت انتخاب‌شده معتبر نیست.', 422, ['status' => 'invalid']);
        Admin::applySquareStatus($id, $status, sanitize_textarea_field((string) ($p['admin_note'] ?? '')));
        return Response::ok($this->adminSquare(get_post($id)));
    }

    public function map(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        global $wpdb;
        $rows = $wpdb->get_results("SELECT g.*,p.post_title,p.post_status FROM {$wpdb->prefix}meydan_square_geo g JOIN {$wpdb->posts} p ON p.ID=g.square_id WHERE p.post_type='meydan_square' AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.post_date DESC", ARRAY_A);
        return Response::ok(array_map(static function (array $row): array {
            $id = (int) $row['square_id'];
            return ['id' => $id, 'name' => (string) $row['post_title'], 'post_status' => (string) $row['post_status'], 'approval_status' => (string) get_post_meta($id, 'meydan_approval_status', true), 'verified' => (bool) get_post_meta($id, 'meydan_verified', true), 'location' => ['province_id' => (int) $row['province_id'], 'city_id' => (int) $row['city_id'], 'address' => (string) $row['address'], 'latitude' => (float) $row['latitude'], 'longitude' => (float) $row['longitude']]];
        }, $rows ?: []));
    }

    private function adminSquare(?\WP_Post $post): ?array
    {
        if (!$post) return null;
        $data = Serializer::square($post);
        if (!$data) return null;
        $id = (int) $post->ID;
        $ownerId = (int) get_post_meta($id, 'meydan_owner_user_id', true);
        $data['post_status'] = (string) $post->post_status;
        $data['owner_user_id'] = $ownerId ?: null;
        $data['owner'] = $ownerId > 0 && get_userdata($ownerId) ? ['id' => $ownerId, 'name' => (string) get_userdata($ownerId)->display_name] : null;
        $data['admin_note'] = (string) get_post_meta($id, 'meydan_admin_note', true);
        $data['verified'] = (bool) get_post_meta($id, 'meydan_verified', true);
        return $data;
    }

    private function allowed(): bool
    {
        return current_user_can('manage_meydan_squares');
    }

    private function forbidden()
    {
        return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
    }
}

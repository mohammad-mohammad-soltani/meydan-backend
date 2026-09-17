<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Support\Response;
use WP_Query;
use WP_REST_Request;

/** CRUD for the admin-managed initiative and campaign CPTs. */
final class AdminProgramController extends BaseController
{
    public function listInitiatives(WP_REST_Request $r) { return $this->listPosts('meydan_initiative', $r); }
    public function createInitiative(WP_REST_Request $r) { return $this->savePost('meydan_initiative', $r, 0); }
    public function getInitiative(WP_REST_Request $r) { return $this->getPost('meydan_initiative', (int) $r['id']); }
    public function updateInitiative(WP_REST_Request $r) { return $this->savePost('meydan_initiative', $r, (int) $r['id']); }
    public function deleteInitiative(WP_REST_Request $r) { return $this->deletePost('meydan_initiative', (int) $r['id']); }
    public function listCampaigns(WP_REST_Request $r) { return $this->listPosts('meydan_campaign', $r); }
    public function createCampaign(WP_REST_Request $r) { return $this->savePost('meydan_campaign', $r, 0); }
    public function getCampaign(WP_REST_Request $r) { return $this->getPost('meydan_campaign', (int) $r['id']); }
    public function updateCampaign(WP_REST_Request $r) { return $this->savePost('meydan_campaign', $r, (int) $r['id']); }
    public function deleteCampaign(WP_REST_Request $r) { return $this->deletePost('meydan_campaign', (int) $r['id']); }

    public function participants(WP_REST_Request $r)
    {
        if (!current_user_can('manage_meydan_initiatives')) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $id = (int) $r['id'];
        if (get_post_type($id) !== 'meydan_initiative') return Response::error('not_found', 'ابتکار پیدا نشد.', 404);
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_initiative_members WHERE initiative_id=%d ORDER BY joined_at DESC,id DESC LIMIT 100", $id), ARRAY_A);
        return Response::ok($rows ?: []);
    }

    public function updateParticipant(WP_REST_Request $r)
    {
        if (!current_user_can('manage_meydan_initiatives')) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $id = (int) $r['id'];
        if (get_post_type($id) !== 'meydan_initiative') return Response::error('not_found', 'ابتکار پیدا نشد.', 404);
        $memberId = (int) $r['member_id']; $p = $this->json($r);
        global $wpdb; $table = $wpdb->prefix . 'meydan_initiative_members';
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND initiative_id=%d", $memberId, $id), ARRAY_A);
        if (!$before) return Response::error('not_found', 'عضو ابتکار پیدا نشد.', 404);
        $data = [];
        foreach (['status', 'joined_at'] as $key) if (array_key_exists($key, $p)) $data[$key] = sanitize_text_field((string) $p[$key]);
        if (!$data) return Response::error('validation_failed', 'حداقل یک فیلد برای تغییر لازم است.', 422);
        $wpdb->update($table, $data, ['id' => $memberId]);
        AuditLogger::log('initiative_member_updated', 'initiative_member', $memberId, $before, $data);
        return Response::ok($wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $memberId), ARRAY_A));
    }

    private function listPosts(string $type, WP_REST_Request $r)
    {
        if (!$this->allowedFor($type)) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $q = new WP_Query(['post_type' => $type, 'post_status' => ['publish', 'draft', 'pending', 'future'], 'posts_per_page' => min(100, max(1, (int) ($r->get_param('per_page') ?: 50))), 'paged' => max(1, (int) ($r->get_param('page') ?: 1)), 'orderby' => 'date', 'order' => 'DESC']);
        return Response::ok(array_map(fn(\WP_Post $post): array => $this->item($post), $q->posts), ['total' => (int) $q->found_posts]);
    }

    private function getPost(string $type, int $id)
    {
        if (!$this->allowedFor($type)) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $post = get_post($id);
        return $post && $post->post_type === $type && $post->post_status !== 'trash' ? Response::ok($this->item($post)) : Response::error('not_found', 'رکورد پیدا نشد.', 404);
    }

    private function savePost(string $type, WP_REST_Request $r, int $id)
    {
        if (!$this->allowedFor($type)) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $p = $this->json($r); $before = $id ? $this->raw($type, $id) : null;
        if ($id && !$before) return Response::error('not_found', 'رکورد پیدا نشد.', 404);
        $title = sanitize_text_field((string) ($p['title'] ?? ($before['title'] ?? '')));
        if ($title === '') return Response::error('validation_failed', 'عنوان الزامی است.', 422, ['title' => 'required']);
        $post = ['post_type' => $type, 'post_title' => $title, 'post_content' => wp_kses_post((string) ($p['description'] ?? ($before['description'] ?? ''))), 'post_status' => in_array(($p['post_status'] ?? 'publish'), ['publish', 'draft', 'pending', 'future'], true) ? $p['post_status'] : 'publish'];
        $result = $id ? wp_update_post(['ID' => $id] + $post, true) : wp_insert_post($post, true);
        if (is_wp_error($result)) return $this->error($result);
        $id = (int) $result;
        foreach (['cta_label', 'starts_at', 'ends_at', 'status', 'labels', 'linked_content', 'schedule', 'order'] as $key) if (array_key_exists($key, $p)) update_post_meta($id, 'meydan_' . $key, is_array($p[$key]) ? array_values($p[$key]) : sanitize_text_field((string) $p[$key]));
        if ($type === 'meydan_initiative' && array_key_exists('allow_guest_join', $p)) update_post_meta($id, 'meydan_allow_guest_join', $this->bool($p['allow_guest_join']) ? 1 : 0);
        AuditLogger::log($before ? $type . '_updated' : $type . '_created', substr($type, 7), $id, $before, $this->raw($type, $id));
        return Response::ok($this->item(get_post($id)), [], $before ? 200 : 201);
    }

    private function deletePost(string $type, int $id)
    {
        if (!$this->allowedFor($type)) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $before = $this->raw($type, $id); if (!$before) return Response::error('not_found', 'رکورد پیدا نشد.', 404);
        wp_trash_post($id); AuditLogger::log($type . '_deleted', substr($type, 7), $id, $before, ['status' => 'trash']);
        return Response::ok(['deleted' => true, 'id' => $id]);
    }

    private function raw(string $type, int $id): ?array
    {
        $post = get_post($id); if (!$post || $post->post_type !== $type || $post->post_status === 'trash') return null;
        return $this->item($post);
    }

    private function item(\WP_Post $post): array
    {
        $id = (int) $post->ID;
        return ['id' => $id, 'title' => (string) $post->post_title, 'description' => (string) $post->post_content, 'post_status' => (string) $post->post_status, 'cta_label' => (string) get_post_meta($id, 'meydan_cta_label', true), 'starts_at' => (string) get_post_meta($id, 'meydan_starts_at', true), 'ends_at' => (string) get_post_meta($id, 'meydan_ends_at', true), 'status' => (string) get_post_meta($id, 'meydan_status', true) ?: 'active', 'allow_guest_join' => (bool) get_post_meta($id, 'meydan_allow_guest_join', true), 'participant_count' => $this->participantCount($id), 'current' => (bool) get_post_meta($id, 'meydan_current', true), 'labels' => (array) get_post_meta($id, 'meydan_labels', true), 'linked_content' => array_values(array_map('intval', (array) get_post_meta($id, 'meydan_linked_content', true))), 'schedule' => (array) get_post_meta($id, 'meydan_schedule', true), 'order' => (int) get_post_meta($id, 'meydan_order', true)];
    }

    private function participantCount(int $id): int { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}meydan_initiative_members WHERE initiative_id=%d AND status='active'", $id)); }

    private function allowedFor(string $type): bool
    {
        return current_user_can($type === 'meydan_campaign' ? 'manage_meydan_campaigns' : 'manage_meydan_initiatives');
    }
}

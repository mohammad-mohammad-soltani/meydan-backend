<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Notifications\SpeakerInvitationService;
use Meydan\Core\Support\Response;
use WP_REST_Request;

/** Administrator view and moderation for the shared speaker-request table. */
final class AdminSpeakerRequestController extends BaseController
{
    public function list(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        global $wpdb;
        $where = ['1=1']; $args = [];
        if ($status = sanitize_key((string) $r->get_param('status'))) { $where[] = 'status=%s'; $args[] = $status; }
        $sql = 'SELECT * FROM ' . SpeakerInvitationService::table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC,id DESC LIMIT 100';
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        return Response::ok(array_map(fn(array $row): array => $this->adminRow($row), $rows ?: []));
    }

    public function get(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $row = $this->find((int) $r['id']);
        return $row ? Response::ok($this->adminRow($row)) : Response::error('not_found', 'درخواست پیدا نشد.', 404);
    }

    public function update(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $row = $this->find((int) $r['id']);
        if (!$row) return Response::error('not_found', 'درخواست پیدا نشد.', 404);
        $status = sanitize_key((string) ($this->json($r)['status'] ?? ''));
        if (!in_array($status, [SpeakerInvitationService::STATUS_PENDING, SpeakerInvitationService::STATUS_ACCEPTED, SpeakerInvitationService::STATUS_REJECTED, SpeakerInvitationService::STATUS_CANCELLED], true)) return Response::error('validation_failed', 'وضعیت انتخاب‌شده معتبر نیست.', 422, ['status' => 'invalid']);
        $before = $row;
        $now = current_time('mysql', true);
        global $wpdb;
        $updated = ['status' => $status, 'updated_at' => $now, 'decided_at' => $status === SpeakerInvitationService::STATUS_PENDING ? null : $now, 'accepted_at' => $status === SpeakerInvitationService::STATUS_ACCEPTED ? $now : null];
        $wpdb->update(SpeakerInvitationService::table(), $updated, ['id' => (int) $row['id']]);
        AuditLogger::log('speaker_request_' . $status, 'speaker_request', (int) $row['id'], $before, $updated);
        $inviter = (int) (($row['inviter_user_id'] ?? 0) ?: ($row['requester_user_id'] ?? 0));
        $speaker = (int) ($row['speaker_user_id'] ?? 0);
        if ($inviter > 0 && $speaker > 0 && $status !== SpeakerInvitationService::STATUS_PENDING) {
            SpeakerInvitationService::notify($inviter, 'speaker_invitation_' . $status, $speaker, (int) $row['id']);
        }
        return Response::ok($this->adminRow($this->find((int) $row['id'])));
    }

    private function adminRow(array $row): array
    {
        $item = SpeakerInvitationService::serialize($row, 0);
        $item['requester_user_id'] = !empty($row['requester_user_id']) ? (int) $row['requester_user_id'] : null;
        $item['speaker_user_id'] = !empty($row['speaker_user_id']) ? (int) $row['speaker_user_id'] : null;
        $item['admin_note'] = (string) ($row['internal_note'] ?? '');
        return $item;
    }

    private function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SpeakerInvitationService::table() . ' WHERE id=%d', $id), ARRAY_A);
        return $row ?: null;
    }

    private function allowed(): bool { return current_user_can('manage_meydan_speaker_requests'); }
    private function forbidden() { return Response::error('forbidden', 'دسترسی کافی ندارید.', 403); }
}

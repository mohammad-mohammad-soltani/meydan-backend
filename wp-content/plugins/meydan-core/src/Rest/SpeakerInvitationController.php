<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Database\Migrations;
use Meydan\Core\Domain\CreatorService;
use Meydan\Core\Notifications\SpeakerInvitationService;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\ChatRepository;
use Meydan\Core\Support\Response;
use WP_REST_Request;

/**
 * Speaker invitations between real users.
 *
 * Separate from SpeakerRequestController, which keeps serving the legacy
 * guest-request flow; both read the same table.
 */
final class SpeakerInvitationController extends BaseController
{
    /**
     * Speakers that can be invited.
     *
     * Invitability is defined by the creator -> user link, which is the same
     * rule `Actor::isSpeaker()` uses. The creator_type taxonomy is deliberately
     * not filtered on: it labels the profile (سخنران / مداح / …), so requiring
     * the `speaker` term would hide a linked reciter or writer who is equally
     * able to receive an invitation.
     */
    public function speakers(WP_REST_Request $r): \WP_REST_Response
    {
        $search = sanitize_text_field((string) $r->get_param('q'));
        $creatorIds = get_posts([
            'post_type' => 'meydan_creator',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        $items = [];
        foreach ($creatorIds as $creatorId) {
            $creatorId = (int) $creatorId;
            $userId = (int) get_post_meta($creatorId, Migrations::CREATOR_USER_META, true);
            // Unlinked creator profiles stay public but cannot receive invitations.
            if ($userId <= 0 || !get_userdata($userId)) {
                continue;
            }
            // A square is an inviter, never an invitee; excluding them here also
            // keeps a square from finding itself in the picker.
            if (Actor::isSquare($userId)) {
                continue;
            }

            $actor = Actor::forUser($userId);
            $name = (string) ($actor['display_name'] ?? '');
            if ($search !== '' && !str_contains($name, $search)) {
                continue;
            }

            $types = wp_get_post_terms($creatorId, 'meydan_creator_type', ['fields' => 'slugs']);
            $role = (string) get_post_meta($creatorId, 'meydan_role', true);
            $expertise = (string) get_post_meta($creatorId, 'meydan_expertise', true);

            $items[] = [
                'user_id' => $userId,
                'creator_id' => $creatorId,
                'actor' => $actor,
                'role' => $role,
                'expertise' => $expertise,
                // Creator type (سخنران/مداح/…) and topical category are distinct
                // axes; the picker filters on the latter.
                'types' => is_wp_error($types) ? [] : array_values($types),
                'speaker_categories' => CreatorService::categoriesOf($creatorId),
                'verified_speaker' => Actor::isVerifiedSpeaker($userId),
            ];
        }

        return Response::ok($items);
    }

    /** Invitations the viewer sent or received. */
    public function list(WP_REST_Request $r): \WP_REST_Response
    {
        if ($e = $this->guard()) return $e;
        $viewerId = get_current_user_id();
        $box = sanitize_key((string) ($r->get_param('box') ?: 'received'));

        global $wpdb;
        $table = SpeakerInvitationService::table();
        if ($box === 'sent') {
            // Legacy rows predate `inviter_user_id` and only populated
            // `requester_user_id`, so both columns identify the sender.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE inviter_user_id = %d OR requester_user_id = %d ORDER BY created_at DESC, id DESC LIMIT 100",
                $viewerId,
                $viewerId
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE speaker_user_id = %d ORDER BY created_at DESC, id DESC LIMIT 100",
                $viewerId
            ), ARRAY_A);
        }

        return Response::ok(array_map(
            static fn(array $row): array => SpeakerInvitationService::serialize($row, $viewerId),
            $rows ?: []
        ));
    }

    public function get(WP_REST_Request $r): \WP_REST_Response
    {
        if ($e = $this->guard()) return $e;
        $row = $this->find((int) $r['id']);
        if (!$row) return Response::error('not_found', 'دعوت پیدا نشد.', 404);
        if (!$this->canView($row)) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);

        return Response::ok(SpeakerInvitationService::serialize($row, get_current_user_id()));
    }

    public function create(WP_REST_Request $r): \WP_REST_Response
    {
        if ($e = $this->guard()) return $e;
        $viewerId = get_current_user_id();
        $p = $this->json($r);

        $speakerUserId = (int) ($p['speaker_user_id'] ?? 0);
        if ($speakerUserId <= 0 || !get_userdata($speakerUserId)) {
            return Response::error('validation_failed', 'سخنران انتخاب‌شده معتبر نیست.', 422, ['speaker_user_id' => 'invalid']);
        }
        if ($speakerUserId === $viewerId) {
            return Response::error('validation_failed', 'نمی‌توانید خودتان را دعوت کنید.', 422, ['speaker_user_id' => 'self']);
        }
        if (!Actor::isSpeaker($speakerUserId)) {
            return Response::error('validation_failed', 'این کاربر سخنران نیست.', 422, ['speaker_user_id' => 'not_speaker']);
        }

        // Only square accounts invite: the venue is their own registered
        // address, so the client never supplies it.
        if (!Actor::isSquare($viewerId)) {
            return Response::error('forbidden', 'فقط حساب‌های میدان می‌توانند دعوت سخنرانی ارسال کنند.', 403);
        }
        $location = Actor::squareAddress(Actor::squareId($viewerId));
        if ($location === '') {
            return Response::error('validation_failed', 'نشانی میدان شما ثبت نشده است.', 422, ['location' => 'missing_square_address']);
        }

        $date = sanitize_text_field((string) ($p['requested_date'] ?? ''));
        $time = sanitize_text_field((string) ($p['requested_time'] ?? ''));
        if (!$this->validDate($date)) {
            return Response::error('validation_failed', 'تاریخ درخواست معتبر نیست.', 422, ['requested_date' => 'invalid']);
        }
        if (!$this->validTime($time)) {
            return Response::error('validation_failed', 'ساعت درخواست معتبر نیست.', 422, ['requested_time' => 'invalid']);
        }

        $initiativeId = (int) ($p['initiative_id'] ?? 0);
        $creatorId = (int) get_user_meta($speakerUserId, Migrations::USER_SPEAKER_META, true);
        $now = current_time('mysql', true);

        global $wpdb;
        $inserted = $wpdb->insert(SpeakerInvitationService::table(), [
            'creator_id' => $creatorId,
            'requester_user_id' => $viewerId,
            'inviter_user_id' => $viewerId,
            'speaker_user_id' => $speakerUserId,
            'initiative_id' => $initiativeId > 0 ? $initiativeId : null,
            'venue' => $location,
            'location' => $location,
            'requested_at' => $now,
            'requested_date' => $date,
            'requested_time' => $time,
            'message' => sanitize_textarea_field((string) ($p['message'] ?? '')),
            'status' => SpeakerInvitationService::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!$inserted) {
            return Response::error('internal_error', 'ثبت دعوت ناموفق بود.', 500);
        }

        $id = (int) $wpdb->insert_id;
        AuditLogger::log('speaker_invitation_created', 'speaker_request', $id, null, [
            'speaker_user_id' => $speakerUserId,
            'inviter_user_id' => $viewerId,
        ]);

        SpeakerInvitationService::notify($speakerUserId, 'speaker_invitation', $viewerId, $id, [
            'location' => $location,
            'requested_date' => $date,
            'requested_time' => $time,
        ]);

        self::syncInvitationChat($id, $viewerId, $speakerUserId, $location, $date, $time, (string) ($p['message'] ?? ''));

        $row = $this->find($id);
        return Response::ok($row ? SpeakerInvitationService::serialize($row, $viewerId) : null, [], 201);
    }

    /**
     * Posts the invitation into the inviter<->speaker direct conversation.
     *
     * Best-effort by design: the invitation row and its notification are already
     * committed, so a chat failure must never fail the request or roll anything
     * back. Failures are swallowed rather than surfaced.
     */
    private static function syncInvitationChat(
        int $invitationId,
        int $inviterId,
        int $speakerId,
        string $location,
        string $date,
        string $time,
        string $note,
    ): void {
        try {
            $chat = new ChatRepository();
            // Both ids are user ids; the square's chat identity is its owner.
            $conversation = $chat->createDirect($inviterId, $speakerId);
            if (is_wp_error($conversation)) {
                return;
            }
            $conversationId = (int) ($conversation['id'] ?? 0);
            if ($conversationId <= 0) {
                return;
            }

            $body = 'دعوت سخنرانی' . "\n" . 'تاریخ: ' . $date . '  ساعت: ' . $time . "\n" . 'مکان: ' . $location;
            if (trim($note) !== '') {
                $body .= "\n" . trim($note);
            }

            $chat->send($conversationId, $inviterId, [
                // Deterministic key: the UNIQUE(sender_user_id, client_id) index
                // makes a retried request update rather than double-post.
                'client_id' => 'speaker-invitation:' . $invitationId,
                'body' => $body,
            ]);
        } catch (\Throwable) {
            // Chat is a side channel for the invitation; ignore any failure.
        }
    }

    /** Speaker accepts or rejects; only the invited speaker may decide. */
    public function decide(WP_REST_Request $r): \WP_REST_Response
    {
        if ($e = $this->guard()) return $e;
        $viewerId = get_current_user_id();
        $row = $this->find((int) $r['id']);
        if (!$row) return Response::error('not_found', 'دعوت پیدا نشد.', 404);

        if ((int) ($row['speaker_user_id'] ?? 0) !== $viewerId) {
            return Response::error('forbidden', 'فقط سخنران دعوت‌شده می‌تواند پاسخ دهد.', 403);
        }

        $status = sanitize_key((string) ($this->json($r)['status'] ?? ''));
        if (!in_array($status, SpeakerInvitationService::DECISIONS, true)) {
            return Response::error('validation_failed', 'وضعیت انتخاب‌شده معتبر نیست.', 422, ['status' => 'invalid']);
        }
        if ((string) $row['status'] !== SpeakerInvitationService::STATUS_PENDING) {
            return Response::error('conflict', 'این دعوت قبلاً پاسخ داده شده است.', 409);
        }

        $now = current_time('mysql', true);
        global $wpdb;
        $wpdb->update(SpeakerInvitationService::table(), [
            'status' => $status,
            'accepted_at' => $status === SpeakerInvitationService::STATUS_ACCEPTED ? $now : null,
            'decided_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        AuditLogger::log('speaker_invitation_' . $status, 'speaker_request', (int) $row['id'], $row, ['status' => $status]);

        $inviterId = (int) ($row['inviter_user_id'] ?: $row['requester_user_id'] ?? 0);
        SpeakerInvitationService::notify(
            $inviterId,
            $status === SpeakerInvitationService::STATUS_ACCEPTED ? 'speaker_invitation_accepted' : 'speaker_invitation_rejected',
            $viewerId,
            (int) $row['id'],
        );

        $updated = $this->find((int) $row['id']);
        return Response::ok($updated ? SpeakerInvitationService::serialize($updated, $viewerId) : null);
    }

    /** Inviter cancels their own pending invitation. */
    public function cancel(WP_REST_Request $r): \WP_REST_Response
    {
        if ($e = $this->guard()) return $e;
        $viewerId = get_current_user_id();
        $row = $this->find((int) $r['id']);
        if (!$row) return Response::error('not_found', 'دعوت پیدا نشد.', 404);

        $inviterId = (int) ($row['inviter_user_id'] ?: $row['requester_user_id'] ?? 0);
        if ($inviterId !== $viewerId) {
            return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        }
        if ((string) $row['status'] !== SpeakerInvitationService::STATUS_PENDING) {
            return Response::error('conflict', 'فقط دعوت‌های در انتظار قابل لغو هستند.', 409);
        }

        $now = current_time('mysql', true);
        global $wpdb;
        $wpdb->update(SpeakerInvitationService::table(), [
            'status' => SpeakerInvitationService::STATUS_CANCELLED,
            'decided_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        AuditLogger::log('speaker_invitation_cancelled', 'speaker_request', (int) $row['id'], $row, ['status' => 'cancelled']);

        $updated = $this->find((int) $row['id']);
        return Response::ok($updated ? SpeakerInvitationService::serialize($updated, $viewerId) : null);
    }

    /** Returns an error response when the caller is not authenticated, else null. */
    private function guard(): ?\WP_REST_Response
    {
        return is_user_logged_in()
            ? null
            : Response::error('unauthenticated', 'برای انجام این عملیات باید وارد شوید.', 401);
    }

    private function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . SpeakerInvitationService::table() . ' WHERE id = %d',
            $id
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Only the two parties (or an admin) may read an invitation. */
    private function canView(array $row): bool
    {
        if (current_user_can('manage_meydan_speaker_requests')) {
            return true;
        }
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return false;
        }
        $inviterId = (int) ($row['inviter_user_id'] ?: $row['requester_user_id'] ?? 0);
        return $viewerId === $inviterId || $viewerId === (int) ($row['speaker_user_id'] ?? 0);
    }

    private function validDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return checkdate($m, $d, $y);
    }

    private function validTime(string $time): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }
}

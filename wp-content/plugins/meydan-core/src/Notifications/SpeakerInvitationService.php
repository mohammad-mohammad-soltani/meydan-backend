<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Crypto;

/**
 * Speaker invitations ("منبر" requests) between two real users.
 *
 * The linked user account — not the curated creator post — owns the invitation:
 * it receives notifications, decides accept/reject, and owns the phone number
 * that is revealed only after acceptance.
 */
final class SpeakerInvitationService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    private const TABLE = 'meydan_speaker_requests';

    /** Speaker-visible decisions. Cancelling is the inviter's action. */
    public const DECISIONS = [self::STATUS_ACCEPTED, self::STATUS_REJECTED];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Serialises an invitation row.
     *
     * `speaker.phone` is included only when the viewer is the inviter AND the
     * invitation was accepted — the single place contact details are released.
     */
    public static function serialize(array $row, int $viewerId): array
    {
        // Inviter falls back to the legacy requester column for rows written
        // before the invitations model existed.
        $inviterId = (int) ($row['inviter_user_id'] ?? 0);
        if ($inviterId <= 0) {
            $inviterId = (int) ($row['requester_user_id'] ?? 0);
        }
        $speakerId = (int) ($row['speaker_user_id'] ?? 0);
        $status = (string) ($row['status'] ?? self::STATUS_PENDING);

        $speaker = $speakerId > 0 ? self::actor($speakerId) : null;
        $inviter = $inviterId > 0 ? self::actor($inviterId) : null;

        $revealPhone = $status === self::STATUS_ACCEPTED && $viewerId > 0 && $viewerId === $inviterId;
        if ($speaker !== null && $revealPhone) {
            $speaker['phone'] = self::phone($speakerId);
        }

        return [
            'id' => (int) $row['id'],
            'status' => $status,
            'speaker' => $speaker,
            'inviter' => $inviter,
            'initiative_id' => (int) ($row['initiative_id'] ?? 0) ?: null,
            'creator_id' => (int) ($row['creator_id'] ?? 0) ?: null,
            'location' => self::text($row, ['location', 'venue']),
            'message' => self::text($row, ['message', 'note']),
            'requested_date' => (string) ($row['requested_date'] ?? '') ?: null,
            'requested_time' => (string) ($row['requested_time'] ?? '') ?: null,
            'requested_at' => self::iso((string) ($row['requested_at'] ?? '')),
            'accepted_at' => self::iso((string) ($row['accepted_at'] ?? '')),
            'decided_at' => self::iso((string) ($row['decided_at'] ?? '')),
            // Tells the client whether to render the phone block at all.
            'phone_visible' => $revealPhone,
            'created_at' => self::iso((string) ($row['created_at'] ?? '')),
        ];
    }

    /** First non-empty value among the given columns. */
    private static function text(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** Decrypted phone for a user, or "" when none is stored. */
    public static function phone(int $userId): string
    {
        $cipher = (string) get_user_meta($userId, 'meydan_phone_ciphertext', true);
        if ($cipher === '') {
            return '';
        }
        $phone = Crypto::decrypt($cipher);
        return is_string($phone) ? $phone : '';
    }

    /** Creates a notification through the shared notification system. */
    public static function notify(
        int $recipientUserId,
        string $type,
        int $actorUserId,
        int $invitationId,
        array $payload = [],
    ): void {
        if ($recipientUserId <= 0 || $recipientUserId === $actorUserId) {
            return;
        }

        (new NotificationService())->fromTemplate(
            $recipientUserId,
            $type,
            'user',
            $actorUserId,
            'speaker_request',
            $invitationId,
            '/speaker-invitations',
            $type . ':speaker_request:' . $invitationId,
            false,
            $payload,
        );
    }

    private static function actor(int $userId): array
    {
        $actor = Actor::forUser($userId);
        return [
            'id' => (string) ($actor['id'] ?? 'usr_' . $userId),
            'type' => (string) ($actor['type'] ?? 'user'),
            'display_name' => (string) ($actor['display_name'] ?? 'کاربر میدان'),
            'avatar_url' => (string) ($actor['avatar_url'] ?? ''),
            'verified' => (bool) ($actor['verified'] ?? false),
            'verified_speaker' => (bool) ($actor['verified_speaker'] ?? false),
        ];
    }

    private static function iso(string $value): ?string
    {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        $time = strtotime($value . ' UTC');
        return $time ? gmdate(DATE_ATOM, $time) : null;
    }
}

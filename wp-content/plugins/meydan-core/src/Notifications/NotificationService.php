<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use Meydan\Core\Support\Actor;
use Meydan\Core\Support\SoketiRealtime;

final class NotificationService
{
    /**
     * Built-in copy for every notification type. Admin overrides stored in the
     * `meydan_notification_templates` option are layered on top per key, so a
     * partial or empty option can never degrade a notification to generic text.
     */
    public const TEMPLATES = [
        'like' => ['title' => 'پسند جدید', 'body' => '{actor} روایت شما را پسندید.'],
        'repost' => ['title' => 'بازنشر جدید', 'body' => '{actor} روایت شما را بازنشر کرد.'],
        'follow' => ['title' => 'دنبال‌کننده جدید', 'body' => '{actor} شما را دنبال کرد.'],
        'comment' => ['title' => 'نظر جدید', 'body' => '{actor} روی روایت شما نظر گذاشت.'],
        'comment_reply' => ['title' => 'پاسخ جدید', 'body' => '{actor} به نظر شما پاسخ داد.'],
        'mention' => ['title' => 'اشاره جدید', 'body' => '{actor} شما را در یک روایت نام برد.'],
        'initiative_join' => ['title' => 'عضو جدید در کار خوب', 'body' => '{actor} به کار خوب شما ملحق شد.'],
        'initiative_update' => ['title' => 'به‌روزرسانی کار خوب', 'body' => 'کاری که در آن عضو هستید به‌روزرسانی شد.'],
        'initiative_join_confirmed' => ['title' => 'عضویت در کار خوب', 'body' => 'عضویت شما در کار خوب ثبت شد.'],
        'media_reflection_added' => ['title' => 'بازنشر رسانه‌ای', 'body' => 'یک بازنشر رسانه‌ای برای روایت شما ثبت شد.'],
        'square_verified' => ['title' => 'تأیید میدان', 'body' => 'میدان شما تأیید شد.'],
        'square_rejected' => ['title' => 'وضعیت میدان', 'body' => 'درخواست میدان شما رد شد.'],
        'speaker_request_created' => ['title' => 'درخواست سخنران', 'body' => 'درخواست سخنران شما ثبت شد.'],
        'speaker_request_status_changed' => ['title' => 'وضعیت درخواست سخنران', 'body' => 'وضعیت درخواست سخنران شما تغییر کرد.'],
        'speaker_invitation' => ['title' => 'دعوت سخنرانی جدید', 'body' => '{actor} شما را برای سخنرانی دعوت کرده است.'],
        'speaker_invitation_accepted' => ['title' => 'پذیرش دعوت سخنرانی', 'body' => '{actor} دعوت سخنرانی شما را پذیرفت.'],
        'speaker_invitation_rejected' => ['title' => 'رد دعوت سخنرانی', 'body' => '{actor} دعوت سخنرانی شما را نپذیرفت.'],
        'admin_notice' => ['title' => 'پیام میدان', 'body' => 'پیام جدیدی از مدیریت میدان دارید.'],
        'system' => ['title' => 'اعلان سیستم', 'body' => 'یک اعلان سیستمی جدید دارید.'],
        'content_published' => ['title' => 'محتوای جدید', 'body' => 'محتوای جدیدی منتشر شد.'],
    ];

    /** @return array{title: string, body: string} */
    public static function template(string $type): array
    {
        $overrides = (array) get_option('meydan_notification_templates', []);
        $key = sanitize_key($type);
        $custom = (array) ($overrides[$key] ?? []);
        $base = self::TEMPLATES[$key] ?? [];

        return [
            'title' => (string) ($custom['title'] ?? $base['title'] ?? 'اعلان میدان'),
            'body' => (string) ($custom['body'] ?? $base['body'] ?? 'رویداد جدیدی در میدان ثبت شد.'),
        ];
    }

    public function create(
        int $recipientUserId,
        string $type,
        ?string $actorType,
        ?int $actorId,
        ?string $entityType,
        ?int $entityId,
        string $title,
        string $body,
        ?string $deepLink = null,
        ?string $groupKey = null,
        array $payload = [],
        ?string $parentEntityType = null,
        ?int $parentEntityId = null,
        bool $aggregate = false,
    ): int {
        if ($recipientUserId <= 0 || ($actorType && $actorId && Actor::ownerUserId($actorType, $actorId) === $recipientUserId)) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'meydan_notifications';
        if ($entityType && $entityId && $actorType && $actorId) {
            $dupe = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE recipient_user_id = %d AND type = %s AND actor_type = %s AND actor_id = %d AND entity_type = %s AND entity_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) LIMIT 1",
                $recipientUserId, $type, $actorType, $actorId, $entityType, $entityId
            ));
            if ($dupe) {
                return (int) $dupe;
            }
        }

        if ($aggregate && $groupKey) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE recipient_user_id = %d AND group_key = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE) ORDER BY id DESC LIMIT 1",
                $recipientUserId,
                $groupKey
            ), ARRAY_A);
            if ($existing) {
                $existingPayload = !empty($existing['payload_json']) ? json_decode((string) $existing['payload_json'], true) : [];
                $count = max(1, (int) ($existingPayload['aggregate_count'] ?? 1)) + 1;
                $payload['aggregate_count'] = $count;
                $newBody = Aggregator::body($type, (string) ($existing['actor_type'] ?: $actorType), (int) ($existing['actor_id'] ?: $actorId), $count, $body);
                $wpdb->update($table, [
                    'body' => $newBody,
                    'payload_json' => wp_json_encode($payload),
                    'created_at' => current_time('mysql', true),
                    'read_at' => null,
                    'archived_at' => null,
                ], ['id' => (int) $existing['id']]);
                $id = (int) $existing['id'];
                SoketiRealtime::publishToUser($recipientUserId, 'notification:updated', ['id' => (string) $id]);
                return $id;
            }
        }

        $payload['aggregate_count'] = (int) ($payload['aggregate_count'] ?? 1);
        $wpdb->insert($table, [
            'recipient_user_id' => $recipientUserId,
            'type' => sanitize_key($type),
            'actor_type' => $actorType ? sanitize_key($actorType) : null,
            'actor_id' => $actorId,
            'entity_type' => $entityType ? sanitize_key($entityType) : null,
            'entity_id' => $entityId,
            'parent_entity_type' => $parentEntityType ? sanitize_key($parentEntityType) : null,
            'parent_entity_id' => $parentEntityId,
            'title' => sanitize_text_field($title),
            'body' => sanitize_textarea_field($body),
            'deep_link' => $deepLink ? esc_url_raw($deepLink) : null,
            'group_key' => $groupKey,
            'payload_json' => wp_json_encode($payload),
            'created_at' => current_time('mysql', true),
        ]);
        $id = (int) $wpdb->insert_id;
        if ($id > 0) {
            SoketiRealtime::publishToUser($recipientUserId, 'notification:created', [
                'id' => (string) $id,
                'type' => sanitize_key($type),
                'title' => sanitize_text_field($title),
                'body' => sanitize_textarea_field($body),
                'deep_link' => $deepLink ? esc_url_raw($deepLink) : null,
                'created_at' => gmdate('c'),
                'read_at' => null,
            ]);
        }
        return $id;
    }

    public function fromTemplate(int $recipientUserId, string $type, ?string $actorType = null, ?int $actorId = null, ?string $entityType = null, ?int $entityId = null, ?string $deepLink = null, ?string $groupKey = null, bool $aggregate = false, array $payload = []): int
    {
        $tpl = self::template($type);
        $actor = ($actorType && $actorId) ? Actor::parse($actorType, $actorId) : null;
        $name = (string) ($actor['display_name'] ?? 'یک کاربر');
        $title = $tpl['title'];
        $body = str_replace('{actor}', $name, $tpl['body']);
        return $this->create($recipientUserId, $type, $actorType, $actorId, $entityType, $entityId, $title, $body, $deepLink, $groupKey, $payload, null, null, $aggregate);
    }

    public function broadcast(string $title, string $body, array $audience, ?string $deepLink = null): int
    {
        $users = $this->resolveAudience($audience);
        $count = 0;
        foreach ($users as $userId) {
            $id = $this->create((int) $userId, 'admin_notice', null, null, 'broadcast', null, $title, $body, $deepLink, null, ['audience' => $audience]);
            if ($id > 0) $count++;
        }
        return $count;
    }

    /** @return int[] */
    private function resolveAudience(array $audience): array
    {
        $type = sanitize_key((string) ($audience['type'] ?? 'all'));
        if ($type === 'specific_ids') {
            return array_values(array_unique(array_filter(array_map('intval', (array) ($audience['ids'] ?? [])))));
        }
        $args = ['fields' => 'ID', 'number' => -1];
        if ($type === 'users') {
            $args['meta_key'] = 'meydan_account_type';
            $args['meta_value'] = 'user';
        } elseif ($type === 'squares') {
            $args['meta_key'] = 'meydan_account_type';
            $args['meta_value'] = 'square';
        } elseif (in_array($type, ['province', 'city'], true)) {
            $args['meta_key'] = $type === 'province' ? 'meydan_province_id' : 'meydan_city_id';
            $args['meta_value'] = (int) ($audience['id'] ?? 0);
        }
        return array_map('intval', get_users($args));
    }
}

<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use Meydan\Core\Support\Actor;

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
        'quote' => ['title' => 'نقل‌قول جدید', 'body' => '{actor} روایت شما را نقل‌قول کرد.'],
        'follow' => ['title' => 'دنبال‌کننده جدید', 'body' => '{actor} شما را دنبال کرد.'],
        'comment' => ['title' => 'نظر جدید', 'body' => '{actor} روی روایت شما نظر گذاشت.'],
        'comment_reply' => ['title' => 'پاسخ جدید', 'body' => '{actor} به نظر شما پاسخ داد.'],
        'mention' => ['title' => 'اشاره جدید', 'body' => '{actor} شما را در یک روایت نام برد.'],
        'initiative_join' => ['title' => 'عضو جدید در کار', 'body' => '{actor} به کار شما ملحق شد.'],
        'initiative_update' => ['title' => 'به‌روزرسانی کار', 'body' => 'کاری که در آن عضو هستید به‌روزرسانی شد.'],
        'initiative_join_confirmed' => ['title' => 'عضویت در کار', 'body' => 'عضویت شما در کار ثبت شد.'],
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
        'work_message_updated' => ['title' => 'به‌روزرسانی کار', 'body' => '{actor} یک پیام در کار را به‌روز کرد.'],
        'work_task_created' => ['title' => 'وظیفه جدید', 'body' => '{actor} یک وظیفه جدید در کار گذاشت.'],
        'work_task_assigned' => ['title' => 'مسئولیت وظیفه', 'body' => '{actor} شما را مسئول یک وظیفه کرد.'],
        'work_task_status' => ['title' => 'وضعیت وظیفه', 'body' => '{actor} وضعیت یک وظیفه را تغییر داد.'],
        'work_task_reminder' => ['title' => 'یادآوری وظیفه', 'body' => '{actor} انجام وظیفه شما را یادآوری کرد.'],
        'work_meeting_created' => ['title' => 'جلسه جدید', 'body' => '{actor} یک جلسه جدید ثبت کرد.'],
        'work_announcement' => ['title' => 'اعلان کار', 'body' => '{actor} یک اعلان جدید فرستاد.'],
        'work_announcement_seen' => ['title' => 'دیدن اعلان', 'body' => '{actor} اعلان شما را دید.'],
        'work_announcement_reminder' => ['title' => 'یادآوری اعلان', 'body' => '{actor} یادآوری کرد اعلان را ببینید.'],
        'work_poll_created' => ['title' => 'نظرسنجی جدید', 'body' => '{actor} یک نظرسنجی جدید گذاشت.'],
        'work_mention' => ['title' => 'اشاره در کار', 'body' => '{actor} شما را در یک کار نام برد.'],
        'work_member_joined' => ['title' => 'عضو جدید در کار', 'body' => '{actor} به کار پیوست.'],
        'work_role_changed' => ['title' => 'نقش شما در کار', 'body' => 'نقش شما در یک کار تغییر کرد.'],
    ];

    /** @return array{title: string, body: string, icon_media_id: int} */
    public static function template(string $type): array
    {
        $overrides = (array) get_option('meydan_notification_templates', []);
        $key = sanitize_key($type);
        $custom = (array) ($overrides[$key] ?? []);
        $base = self::TEMPLATES[$key] ?? [];

        return [
            'title' => (string) ($custom['title'] ?? $base['title'] ?? 'اعلان میدان'),
            'body' => (string) ($custom['body'] ?? $base['body'] ?? 'رویداد جدیدی در میدان ثبت شد.'),
            'icon_media_id' => max(0, (int) ($custom['icon_media_id'] ?? 0)),
        ];
    }

    public static function iconUrl(string $type, ?string $actorType = null, ?int $actorId = null): ?string
    {
        if ($actorType && $actorId) {
            $actor = Actor::parse($actorType, $actorId);
            $avatar = trim((string) ($actor['avatar_url'] ?? ''));
            if ($avatar !== '') {
                return $avatar;
            }
        }

        $template = self::template($type);
        $mediaId = (int) ($template['icon_media_id'] ?? 0);
        if ($mediaId <= 0) {
            return null;
        }

        $url = wp_get_attachment_image_url($mediaId, 'thumbnail');
        if (!$url) {
            $url = wp_get_attachment_url($mediaId);
        }
        return $url ? (string) $url : null;
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
        bool $sendPush = true,
    ): int {
        if ($recipientUserId <= 0 || ($actorType && $actorId && Actor::ownerUserId($actorType, $actorId) === $recipientUserId)) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'meydan_notifications';
        if ($entityType && $entityId && $actorType && $actorId && !in_array($type, ['work_announcement_seen', 'initiative_join'], true)) {
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
                AsyncDispatcher::queueRealtimeToUser($recipientUserId, 'notification:updated', ['id' => (string) $id]);
                if ($sendPush) {
                    self::sendPushToUser(
                        $recipientUserId,
                        (string) ($existing['title'] ?: $title),
                        $newBody,
                        (string) ($existing['deep_link'] ?: $deepLink),
                        self::iconUrl($type, $actorType, $actorId),
                        ['type' => sanitize_key($type), 'notification_id' => (string) $id, 'tag' => 'notification-' . sanitize_key($type)],
                    );
                }
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
            $iconUrl = self::iconUrl($type, $actorType, $actorId);
            AsyncDispatcher::queueRealtimeToUser($recipientUserId, 'notification:created', [
                'id' => (string) $id,
                'type' => sanitize_key($type),
                'title' => sanitize_text_field($title),
                'body' => sanitize_textarea_field($body),
                'icon_url' => $iconUrl,
                'deep_link' => $deepLink ? esc_url_raw($deepLink) : null,
                'created_at' => gmdate('c'),
                'read_at' => null,
            ]);
            if ($sendPush) {
                self::sendPushToUser(
                    $recipientUserId,
                    $title,
                    $body,
                    $deepLink,
                    $iconUrl,
                    ['type' => sanitize_key($type), 'notification_id' => (string) $id, 'tag' => 'notification-' . sanitize_key($type)],
                );
            }
        }
        return $id;
    }

    /**
     * Bulk fan-out for group events: one multi-row INSERT per 500 recipients,
     * one queued realtime job and one queued push job — never a query or a
     * scheduled event per recipient. Dedupe is by group_key (one notification
     * per message), so no per-recipient lookup is needed.
     *
     * @param int[] $recipientUserIds
     * @param array<string,mixed> $payload
     * @return int number of notifications stored
     */
    public function createMany(
        array $recipientUserIds,
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
        bool $sendPush = true,
    ): int {
        $ownerId = ($actorType && $actorId) ? Actor::ownerUserId($actorType, $actorId) : 0;
        $recipients = array_values(array_unique(array_filter(
            array_map('intval', $recipientUserIds),
            static fn(int $id): bool => $id > 0 && $id !== $ownerId
        )));
        if (!$recipients) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'meydan_notifications';
        $type = sanitize_key($type);
        $title = sanitize_text_field($title);
        $body = sanitize_textarea_field($body);
        $deepLink = $deepLink ? esc_url_raw($deepLink) : null;
        $payload['aggregate_count'] = (int) ($payload['aggregate_count'] ?? 1);
        $payloadJson = (string) wp_json_encode($payload);
        $now = current_time('mysql', true);
        $stored = 0;

        // wpdb::prepare() turns a null %s into '' — build NULLs explicitly.
        $str = static fn(?string $v): string => $v === null ? 'NULL' : $wpdb->prepare('%s', $v);
        $int = static fn(?int $v): string => $v === null ? 'NULL' : (string) (int) $v;
        $shared = implode(',', [
            $str($type),
            $str($actorType ? sanitize_key($actorType) : null),
            $int($actorId),
            $str($entityType ? sanitize_key($entityType) : null),
            $int($entityId),
            $str($title),
            $str($body),
            $str($deepLink),
            $str($groupKey),
            $str($payloadJson),
            $str($now),
        ]);

        foreach (array_chunk($recipients, 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $uid) {
                $rows[] = '(' . (int) $uid . ',' . $shared . ')';
            }
            $sql = "INSERT INTO {$table} (recipient_user_id,type,actor_type,actor_id,entity_type,entity_id,title,body,deep_link,group_key,payload_json,created_at) VALUES " . implode(',', $rows);
            if ($wpdb->query($sql) !== false) {
                $stored += count($chunk);
            }
        }

        if ($stored > 0) {
            AsyncDispatcher::queueRealtimeToUsers($recipients, 'notification:created', [
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'deep_link' => $deepLink,
                'created_at' => gmdate('c'),
            ]);
            if ($sendPush) {
                self::sendPushToUsers(
                    $recipients,
                    $title,
                    $body,
                    $deepLink,
                    self::iconUrl($type, $actorType, $actorId),
                    ['type' => $type, 'tag' => 'notification-' . $type . ($groupKey ? '-' . substr(md5($groupKey), 0, 8) : '')],
                );
            }
        }
        return $stored;
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
        $pushUsers = [];
        $count = 0;
        foreach ($users as $userId) {
            $uid = (int) $userId;
            $id = $this->create($uid, 'admin_notice', null, null, 'broadcast', null, $title, $body, $deepLink, null, ['audience' => $audience], null, null, false, false);
            if ($id > 0) {
                $count++;
                $pushUsers[] = $uid;
            }
        }
        if ($pushUsers) {
            self::sendPushToUsers(
                $pushUsers,
                $title,
                $body,
                $deepLink,
                self::iconUrl('admin_notice'),
                ['type' => 'admin_notice', 'tag' => 'admin-notice'],
            );
        }
        return $count;
    }

    /** @param array<string,mixed> $data */
    private static function sendPushToUser(int $userId, string $title, string $body, ?string $deepLink, ?string $iconUrl, array $data): void
    {
        AsyncDispatcher::queuePush([$userId], $title, $body, $deepLink, $iconUrl, $data);
    }

    /** @param int[] $userIds @param array<string,mixed> $data */
    private static function sendPushToUsers(array $userIds, string $title, string $body, ?string $deepLink, ?string $iconUrl, array $data): void
    {
        AsyncDispatcher::queuePush($userIds, $title, $body, $deepLink, $iconUrl, $data);
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
        } elseif (in_array($type, ['squares', 'media', 'collectives', 'organizations'], true)) {
            $args['meta_key'] = 'meydan_account_type';
            $args['meta_value'] = ['squares' => 'square', 'media' => 'media', 'collectives' => 'collective', 'organizations' => 'organization'][$type];
        } elseif (in_array($type, ['province', 'city'], true)) {
            $args['meta_key'] = $type === 'province' ? 'meydan_province_id' : 'meydan_city_id';
            $args['meta_value'] = (int) ($audience['id'] ?? 0);
        }
        return array_map('intval', get_users($args));
    }
}

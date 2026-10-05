<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use Meydan\Core\Support\Actor;
use WP_Error;
use WP_Post;

/**
 * «اعلان‌های نمایه»: a viewer asks to be told when an account publishes.
 *
 * Subscriptions live in the interactions table (action `notify`, keyed by the
 * actor). Delivery never runs inside the publishing request: publishing only
 * schedules a background job, which notifies subscribers in batches, so an
 * account with many subscribers cannot slow down the author's post.
 */
final class ProfileSubscriptions
{
    public const ACTION = 'notify';
    public const HOOK = 'meydan_profile_post_notify';
    private const BATCH = 200;

    public static function register(): void
    {
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
        add_action(self::HOOK, [self::class, 'deliver'], 10, 2);
    }

    public static function isSubscribed(int $userId, string $type, int $id): bool
    {
        global $wpdb;
        return $userId > 0 && (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND object_type=%s AND object_id=%d AND action=%s LIMIT 1",
            $userId, $type, $id, self::ACTION
        ));
    }

    public static function set(int $userId, string $type, int $id, bool $on): true|WP_Error
    {
        global $wpdb;
        if (!Actor::parse($type, $id)) return new WP_Error('not_found', 'حساب پیدا نشد.', ['status' => 404]);
        if (Actor::ownerUserId($type, $id) === $userId) return new WP_Error('validation_failed', 'برای نمایه خودتان اعلان لازم نیست.', ['status' => 422]);
        $table = $wpdb->prefix . 'meydan_interactions';
        if ($on) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (user_id, object_type, object_id, action, created_at) VALUES (%d, %s, %d, %s, %s)",
                $userId, $type, $id, self::ACTION, current_time('mysql', true)
            ));
        } else {
            $wpdb->delete($table, ['user_id' => $userId, 'object_type' => $type, 'object_id' => $id, 'action' => self::ACTION]);
        }
        return true;
    }

    /** A narrative went live for the first time: queue the fan-out. */
    public static function onTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($post->post_type !== 'meydan_narrative' || $newStatus !== 'publish' || $oldStatus === 'publish') return;
        // The author meta is written right after insert, so read it in the job, not here.
        if (!wp_next_scheduled(self::HOOK, [(int) $post->ID, 0])) {
            wp_schedule_single_event(time() + 5, self::HOOK, [(int) $post->ID, 0]);
        }
    }

    /** Notifies one batch of subscribers and queues the next. */
    public static function deliver(int $narrativeId, int $afterId): void
    {
        global $wpdb;
        if (get_post_status($narrativeId) !== 'publish') return;
        $type = (string) get_post_meta($narrativeId, 'meydan_author_actor_type', true);
        $actorId = (int) get_post_meta($narrativeId, 'meydan_author_actor_id', true);
        if ($type === '' || $actorId <= 0) return;
        $authorUserId = (int) get_post_field('post_author', $narrativeId);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id FROM {$wpdb->prefix}meydan_interactions WHERE object_type=%s AND object_id=%d AND action=%s AND id > %d ORDER BY id ASC LIMIT %d",
            $type, $actorId, self::ACTION, $afterId, self::BATCH
        ), ARRAY_A) ?: [];
        $recipients = [];
        foreach ($rows as $row) {
            $recipient = (int) $row['user_id'];
            if ($recipient > 0 && $recipient !== $authorUserId) $recipients[] = $recipient;
        }
        (new NotificationService())->fromTemplateMany($recipients, 'profile_post', $type, $actorId, 'narrative', $narrativeId, '/posts/' . $narrativeId);
        if (count($rows) === self::BATCH) {
            wp_schedule_single_event(time() + 5, self::HOOK, [$narrativeId, (int) end($rows)['id']]);
        }
    }
}

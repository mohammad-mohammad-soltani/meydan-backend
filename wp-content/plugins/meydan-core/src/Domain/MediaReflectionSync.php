<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Notifications\NotificationService;
use Meydan\Core\Support\Actor;
use WP_Error;

/**
 * Media reflections (بازتاب رسانه‌ای) created by an approved media account:
 * automatically when it reposts or quotes a post, or manually from the post.
 *
 * Rows live in the existing `meydan_media_reflections` table; `source` tells
 * how a row came to be so an undone repost or a removed quote takes only its
 * own row away, never an admin-curated one.
 */
final class MediaReflectionSync
{
    public const SOURCES = ['manual', 'own_post', 'repost', 'quote'];

    /** The republishing outlet of an approved media account, or 0. */
    public static function outletForUser(int $userId): int
    {
        $sid = (int) get_user_meta($userId, 'meydan_square_id', true);
        if ($sid <= 0 || get_post_type($sid) !== 'meydan_square') return 0;
        if (EntityKinds::kindOf($sid) !== 'media') return 0;
        if ((string) get_post_meta($sid, 'meydan_approval_status', true) !== 'approved') return 0;
        return EntityKinds::linkedOutlet($sid);
    }

    public static function repost(int $userId, int $narrativeId, bool $on): void
    {
        $outletId = self::outletForUser($userId);
        if ($outletId <= 0) return;
        $sid = (int) get_user_meta($userId, 'meydan_square_id', true);
        if ($on) {
            if (self::isOwn($narrativeId, $sid)) return;
            self::record($narrativeId, $outletId, 'repost', 0, '/square/' . $sid, 'بازنشر در ' . get_the_title($outletId), '', $userId);
        } else {
            self::remove($narrativeId, $outletId, 'repost', 0);
        }
    }

    /** Called on every publish-state change of a quote narrative. */
    public static function quote(int $quoteId, int $quotedId, bool $live): void
    {
        $userId = (int) get_post_field('post_author', $quoteId);
        $outletId = $userId > 0 ? self::outletForUser($userId) : 0;
        if ($outletId <= 0) return;
        $sid = (int) get_user_meta($userId, 'meydan_square_id', true);
        if ($live) {
            // A quote of the media's own post is never a reflection, nor one the author opted out of.
            if (self::isOwn($quotedId, $sid) || get_post_meta($quoteId, 'meydan_skip_media_reflection', true) === '1') return;
            $text = trim(wp_strip_all_tags((string) get_post_field('post_content', $quoteId)));
            $title = $text !== '' ? mb_substr($text, 0, 120) : 'نقل‌قول در ' . get_the_title($outletId);
            self::record($quotedId, $outletId, 'quote', $quoteId, '/posts/' . $quoteId, $title, '', $userId);
        } else {
            self::remove($quotedId, $outletId, 'quote', $quoteId);
        }
    }

    /**
     * Manual reflection from the post page. `$ownNarrativeId` > 0 publishes an
     * existing post of the media's own account as the reflection.
     *
     * @return int|WP_Error reflection id
     */
    public static function createManual(int $userId, int $narrativeId, string $url, int $ownNarrativeId, string $title, string $summary): int|WP_Error
    {
        $outletId = self::outletForUser($userId);
        if ($outletId <= 0) {
            return new WP_Error('forbidden', 'فقط رسانه‌ی تأییدشده می‌تواند بازتاب ثبت کند.', ['status' => 403]);
        }
        if (get_post_type($narrativeId) !== 'meydan_narrative' || get_post_status($narrativeId) !== 'publish' || !UserAccess::visibleNarrative($narrativeId)) {
            return new WP_Error('not_found', 'روایت پیدا نشد.', ['status' => 404]);
        }
        $sid = (int) get_user_meta($userId, 'meydan_square_id', true);
        if ($ownNarrativeId > 0) {
            if (
                get_post_type($ownNarrativeId) !== 'meydan_narrative'
                || get_post_status($ownNarrativeId) !== 'publish'
                || !self::isOwn($ownNarrativeId, $sid)
                || $ownNarrativeId === $narrativeId
            ) {
                return new WP_Error('validation_failed', 'پست انتخاب‌شده متعلق به اکانت شما نیست.', ['status' => 422, 'fields' => ['own_narrative_id' => 'invalid']]);
            }
            $url = '/posts/' . $ownNarrativeId;
            $source = 'own_post';
            if ($title === '') {
                $text = trim(wp_strip_all_tags((string) get_post_field('post_content', $ownNarrativeId)));
                $title = $text !== '' ? mb_substr($text, 0, 120) : 'بازتاب در ' . get_the_title($outletId);
            }
        } else {
            $url = esc_url_raw($url);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                return new WP_Error('validation_failed', 'لینک بازتاب معتبر نیست.', ['status' => 422, 'fields' => ['url' => 'invalid']]);
            }
            $source = 'manual';
            if ($title === '') $title = 'بازتاب در ' . get_the_title($outletId);
        }
        if (self::isOwn($narrativeId, $sid)) {
            return new WP_Error('validation_failed', 'برای پست خودتان بازتاب ثبت نمی‌شود.', ['status' => 422]);
        }
        return self::record($narrativeId, $outletId, $source, $ownNarrativeId, $url, mb_substr($title, 0, 255), $summary, $userId);
    }

    public static function deleteMine(int $userId, int $reflectionId): true|WP_Error
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_media_reflections WHERE id=%d", $reflectionId), ARRAY_A);
        $outletId = self::outletForUser($userId);
        if (!$row || $outletId <= 0 || (int) $row['outlet_id'] !== $outletId) {
            return new WP_Error('not_found', 'بازتاب پیدا نشد.', ['status' => 404]);
        }
        $wpdb->delete($wpdb->prefix . 'meydan_media_reflections', ['id' => $reflectionId]);
        AuditLogger::log('media_reflection_deleted', 'media_reflection', $reflectionId, $row, null);
        return true;
    }

    private static function isOwn(int $narrativeId, int $squareId): bool
    {
        return $squareId > 0
            && (string) get_post_meta($narrativeId, 'meydan_author_actor_type', true) === 'square'
            && (int) get_post_meta($narrativeId, 'meydan_author_actor_id', true) === $squareId;
    }

    /** Idempotent insert; returns the (existing or new) row id. */
    private static function record(int $narrativeId, int $outletId, string $source, int $sourceNarrativeId, string $url, string $title, string $summary, int $userId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_media_reflections';
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE narrative_id=%d AND outlet_id=%d AND source=%s AND source_narrative_id=%d",
            $narrativeId, $outletId, $source, $sourceNarrativeId
        ));
        // Manual links may repeat with different URLs; only automatic/own-post rows are unique.
        if ($exists > 0 && $source !== 'manual') return $exists;
        $now = current_time('mysql', true);
        $data = [
            'narrative_id' => $narrativeId,
            'outlet' => (string) get_the_title($outletId),
            'outlet_id' => $outletId,
            'title' => $title,
            'summary' => sanitize_textarea_field($summary),
            'url' => $url,
            'logo_media_id' => (int) get_post_meta($outletId, 'meydan_avatar_media_id', true),
            'published_at' => $now,
            'status' => 'published',
            'position' => 0,
            'source' => $source,
            'source_narrative_id' => $sourceNarrativeId,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $wpdb->insert($table, $data);
        $id = (int) $wpdb->insert_id;
        AuditLogger::log('media_reflection_created', 'media_reflection', $id, null, $data);
        $author = Actor::fromNarrative($narrativeId);
        $recipient = Actor::ownerUserId($author['type'], $author['type'] === 'square' ? (int) str_replace('sq_', '', $author['id']) : (int) str_replace('usr_', '', $author['id']));
        if ($recipient && $recipient !== $userId) {
            (new NotificationService())->fromTemplate($recipient, 'media_reflection_added', 'user', $userId, 'narrative', $narrativeId, '/posts/' . $narrativeId, null, false, ['reflection_id' => $id]);
        }
        return $id;
    }

    private static function remove(int $narrativeId, int $outletId, string $source, int $sourceNarrativeId): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'meydan_media_reflections', [
            'narrative_id' => $narrativeId,
            'outlet_id' => $outletId,
            'source' => $source,
            'source_narrative_id' => $sourceNarrativeId,
        ]);
    }
}

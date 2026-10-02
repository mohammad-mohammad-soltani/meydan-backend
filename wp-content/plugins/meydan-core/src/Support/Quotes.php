<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Domain\EntityKinds;

use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Notifications\NotificationService;
use WP_Error;
use WP_Post;

/**
 * Quote narratives: a regular narrative that carries its own text and embeds
 * another narrative. The link lives in `meydan_quoted_narrative_id` post meta.
 *
 * The quoted narrative's `quotes` counter follows the quote's publish state
 * (scheduled quotes count once they go live, trashed ones stop counting), so it
 * is driven from `transition_post_status` rather than from the REST handler.
 */
final class Quotes
{
    public const META = 'meydan_quoted_narrative_id';

    public static function register(): void
    {
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
    }

    /** The narrative a quote points at, or 0 when `$narrativeId` is not a quote. */
    public static function quotedId(int $narrativeId): int
    {
        return max(0, (int) get_post_meta($narrativeId, self::META, true));
    }

    /** Whether a narrative can be quoted by the current viewer. */
    public static function validateTarget(int $quotedId): true|WP_Error
    {
        $post = $quotedId > 0 ? get_post($quotedId) : null;
        if (
            !$post
            || $post->post_type !== 'meydan_narrative'
            || $post->post_status !== 'publish'
            || !UserAccess::visibleNarrative($quotedId)
        ) {
            return new WP_Error('validation_failed', 'روایتی که می‌خواهید نقل‌قول کنید در دسترس نیست.', [
                'status' => 422,
                'fields' => ['quoted_narrative_id' => 'invalid'],
            ]);
        }
        return true;
    }

    public static function onTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($post->post_type !== 'meydan_narrative' || $newStatus === $oldStatus) {
            return;
        }
        $quotedId = self::quotedId((int) $post->ID);
        if ($quotedId <= 0) {
            return;
        }
        if ($newStatus === 'publish') {
            Stats::incrementNarrative($quotedId, 'quotes', 1);
            self::notify((int) $post->ID, $quotedId, (int) $post->post_author);
            \Meydan\Core\Domain\MediaReflectionSync::quote((int) $post->ID, $quotedId, true);
        } elseif ($oldStatus === 'publish') {
            Stats::incrementNarrative($quotedId, 'quotes', -1);
            \Meydan\Core\Domain\MediaReflectionSync::quote((int) $post->ID, $quotedId, false);
        }
    }

    private static function notify(int $quoteId, int $quotedId, int $authorUserId): void
    {
        $actorType = (string) get_post_meta($quoteId, 'meydan_author_actor_type', true) ?: 'user';
        $actorId = (int) get_post_meta($quoteId, 'meydan_author_actor_id', true) ?: $authorUserId;

        $quotedActorType = (string) get_post_meta($quotedId, 'meydan_author_actor_type', true);
        $quotedActorId = (int) get_post_meta($quotedId, 'meydan_author_actor_id', true);
        Affinity::bump($authorUserId, $quotedActorType, $quotedActorId, 'quote');

        $recipient = (int) get_post_field('post_author', $quotedId);
        if ($recipient <= 0 || $recipient === $authorUserId) {
            return;
        }
        (new NotificationService())->fromTemplate(
            $recipient,
            'quote',
            EntityKinds::isEntityActorType((string) $actorType) ? (string) $actorType : 'user',
            $actorId,
            'narrative',
            $quoteId,
            '/posts/' . $quoteId,
            null,
            false
        );
    }
}

<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Domain\EntityKinds;
use Meydan\Core\Domain\MemorialService;
use Meydan\Core\Domain\UserAccess;
use WP_Error;

/**
 * «ادای احترام» (tribute) posts: a regular narrative, written by the visitor
 * and published as their own, that is addressed to a یادبود (memorial) account.
 * The link lives in `meydan_tribute_memorial_id` post meta, the same way a
 * quote's lives in `meydan_quoted_narrative_id`. A tribute is a post type of
 * its own beside «روایت» and «کار»: the composer only offers it when opened
 * from a memorial's page.
 */
final class Tributes
{
    public const META = 'meydan_tribute_memorial_id';

    /** The memorial a tribute is addressed to, or 0 when `$narrativeId` is not a tribute. */
    public static function memorialId(int $narrativeId): int
    {
        return max(0, (int) get_post_meta($narrativeId, self::META, true));
    }

    /** Whether the memorial can receive tributes right now (exists, published, visible). */
    public static function validateTarget(int $memorialId): true|WP_Error
    {
        $post = $memorialId > 0 ? get_post($memorialId) : null;
        if (
            !$post
            || $post->post_type !== EntityKinds::postType(MemorialService::KIND)
            || $post->post_status !== 'publish'
            || !UserAccess::visibleEntity($memorialId)
        ) {
            return new WP_Error('validation_failed', 'یادبودی که می‌خواهید به آن ادای احترام کنید در دسترس نیست.', [
                'status' => 422,
                'fields' => ['tribute_memorial_id' => 'invalid'],
            ]);
        }
        return true;
    }

    /**
     * The compact card a tribute carries: who it honours. `null` for a post that is
     * not a tribute; `unavailable` when the memorial was removed afterwards.
     *
     * @return array<string,mixed>|null
     */
    public static function summary(int $narrativeId): ?array
    {
        $memorialId = self::memorialId($narrativeId);
        if ($memorialId <= 0) return null;
        $valid = self::validateTarget($memorialId);
        if (is_wp_error($valid)) return ['id' => $memorialId, 'unavailable' => true];
        $actor = Actor::forEntity($memorialId);
        return [
            'id' => $memorialId,
            'unavailable' => false,
            'name' => (string) ($actor['display_name'] ?? ''),
            'handle' => (string) ($actor['handle'] ?? ''),
            'avatar_url' => (string) ($actor['avatar_url'] ?? ''),
            'position' => (string) get_post_meta($memorialId, MemorialService::POSITION_META, true),
            'death_date' => (string) get_post_meta($memorialId, 'meydan_death_date', true),
        ];
    }
}

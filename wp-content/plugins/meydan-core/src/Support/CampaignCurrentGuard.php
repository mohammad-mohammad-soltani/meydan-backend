<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class CampaignCurrentGuard
{
    private const NORMALIZED_OPTION = 'meydan_campaign_current_guard_v1';

    public static function register(): void
    {
        add_action('init', [self::class, 'normalizeExisting'], 30);
        add_action('added_post_meta', [self::class, 'sync'], 20, 4);
        add_action('updated_post_meta', [self::class, 'sync'], 20, 4);
    }

    public static function normalizeExisting(): void
    {
        if (get_option(self::NORMALIZED_OPTION, false)) {
            return;
        }

        $ids = get_posts([
            'post_type' => 'meydan_campaign',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => 'meydan_current',
            'meta_value' => '1',
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        foreach (array_slice($ids, 1) as $otherId) {
            update_post_meta((int) $otherId, 'meydan_current', 0);
        }

        update_option(self::NORMALIZED_OPTION, 1, false);
    }

    public static function sync(int $metaId, int $postId, string $metaKey, mixed $metaValue): void
    {
        if (
            $metaKey !== 'meydan_current'
            || (string) $metaValue !== '1'
            || get_post_type($postId) !== 'meydan_campaign'
        ) {
            return;
        }

        $otherIds = get_posts([
            'post_type' => 'meydan_campaign',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post__not_in' => [$postId],
            'meta_key' => 'meydan_current',
            'meta_value' => '1',
            'no_found_rows' => true,
        ]);

        foreach ($otherIds as $otherId) {
            update_post_meta((int) $otherId, 'meydan_current', 0);
        }
    }
}

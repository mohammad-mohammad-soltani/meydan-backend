<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class CampaignCurrentGuard
{
    public static function register(): void
    {
        add_action('added_post_meta', [self::class, 'sync'], 20, 4);
        add_action('updated_post_meta', [self::class, 'sync'], 20, 4);
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

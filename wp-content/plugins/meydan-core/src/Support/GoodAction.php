<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_Query;

final class GoodAction
{
    private const BACKFILL_OPTION = 'meydan_good_action_backfill_v1';

    public static function register(): void
    {
        add_action('added_post_meta', [self::class, 'syncEchoMeta'], 10, 4);
        add_action('updated_post_meta', [self::class, 'syncEchoMeta'], 10, 4);
        add_action('init', [self::class, 'backfill'], 30);
    }

    public static function syncEchoMeta(int $metaId, int $objectId, string $metaKey, mixed $metaValue): void
    {
        if ($metaKey !== 'meydan_is_echo' || !(bool) $metaValue) {
            return;
        }

        self::ensureInitiative($objectId);
    }

    public static function backfill(): void
    {
        if ((bool) get_option(self::BACKFILL_OPTION, false)) {
            return;
        }

        $query = new WP_Query([
            'post_type' => 'meydan_narrative',
            'post_status' => ['publish', 'future'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => 'meydan_is_echo',
                'value' => '1',
            ]],
        ]);

        foreach ($query->posts as $narrativeId) {
            self::ensureInitiative((int) $narrativeId);
        }

        update_option(self::BACKFILL_OPTION, 1, false);
    }

    private static function ensureInitiative(int $narrativeId): void
    {
        $narrative = get_post($narrativeId);
        if (!$narrative || $narrative->post_type !== 'meydan_narrative' || $narrative->post_status === 'trash') {
            return;
        }

        $existingId = (int) get_post_meta($narrativeId, 'meydan_initiative_id', true);
        if ($existingId > 0 && get_post_type($existingId) === 'meydan_initiative') {
            return;
        }

        $plain = trim(wp_strip_all_tags(strip_shortcodes($narrative->post_content)));
        $firstLine = trim((string) preg_split('/\R/u', $plain, 2)[0]);
        $title = $firstLine !== '' ? mb_substr($firstLine, 0, 120) : 'کار میدان';

        $initiativeId = wp_insert_post([
            'post_type' => 'meydan_initiative',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $narrative->post_content,
            'post_author' => (int) $narrative->post_author,
        ], true);

        if (is_wp_error($initiativeId) || !$initiativeId) {
            return;
        }

        update_post_meta((int) $initiativeId, 'meydan_cta_label', 'پیوستن');
        update_post_meta((int) $initiativeId, 'meydan_status', 'active');
        update_post_meta((int) $initiativeId, 'meydan_allow_guest_join', 0);
        update_post_meta((int) $initiativeId, 'meydan_source_narrative_id', $narrativeId);
        update_post_meta($narrativeId, 'meydan_initiative_id', (int) $initiativeId);
    }
}

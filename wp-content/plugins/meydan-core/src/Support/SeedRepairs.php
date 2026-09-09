<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_Query;

final class SeedRepairs
{
    public static function run(): void
    {
        $query = new WP_Query([
            'post_type' => 'meydan_content',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($query->posts as $contentId) {
            $format = (string) get_post_meta((int) $contentId, 'meydan_format', true);
            $featured = (bool) get_post_meta((int) $contentId, 'meydan_featured', true);
            $slug = $featured || $format === 'video' ? 'featured' : ($format === 'audio' ? 'audio' : 'talks');
            $labels = ['featured' => 'ویژه', 'talks' => 'منبر', 'audio' => 'صوت'];
            $term = term_exists($slug, 'meydan_content_category');
            if (!$term) {
                $term = wp_insert_term($labels[$slug], 'meydan_content_category', ['slug' => $slug]);
            }
            if (!is_wp_error($term)) {
                $termId = is_array($term) ? (int) $term['term_id'] : (int) $term;
                wp_set_post_terms((int) $contentId, [$termId], 'meydan_content_category', false);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

use WP_Error;

final class FeedSettings
{
    public const OPTION = 'meydan_feed_settings';
    private const CACHE_KEY = 'resolved';
    private const CACHE_GROUP = 'meydan_feed_settings';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'feed_algorithm_v2_enabled' => false,
            'like_weight' => 1.0, 'view_weight' => 0.03, 'comment_weight' => 2.5, 'share_weight' => 3.0,
            'max_engagement_score' => 25.0,
            'square_role_multiplier' => 1.25, 'speaker_role_multiplier' => 1.5, 'official_role_multiplier' => 2.25,
            'editorial_multiplier' => 1.7, 'good_deed_multiplier' => 1.25,
            'same_city_multiplier' => 1.5, 'same_province_multiplier' => 1.25,
            'following_multiplier' => 1.6, 'max_total_boost' => 6.0,
            'max_post_age_hours' => 72, 'candidate_pool_size' => 300,
            'max_same_author_in_top_n' => 3, 'diversity_top_n' => 20,
            'source_quotas' => [
                'following' => 150, 'recent' => 100, 'speaker' => 75,
                'editorial' => 50, 'good_deed' => 50, 'location' => 50, 'general' => 25,
            ],
            'max_speaker_ratio_top_20' => 40.0,
            'max_official_ratio_top_20' => 40.0,
            'freshness_buckets' => [
                ['min_hours' => 0, 'max_hours' => 6, 'multiplier' => 1.8],
                ['min_hours' => 6, 'max_hours' => 12, 'multiplier' => 1.45],
                ['min_hours' => 12, 'max_hours' => 24, 'multiplier' => 1.15],
                ['min_hours' => 24, 'max_hours' => 48, 'multiplier' => 0.75],
                ['min_hours' => 48, 'max_hours' => 72, 'multiplier' => 0.45],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function get(): array
    {
        $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if (is_array($cached)) return $cached;
        $validated = self::validate((array) get_option(self::OPTION, []));
        $settings = $validated instanceof WP_Error ? self::defaults() : $validated;
        wp_cache_set(self::CACHE_KEY, $settings, self::CACHE_GROUP);
        return $settings;
    }

    public static function enabled(): bool
    {
        return (bool) (self::get()['feed_algorithm_v2_enabled'] ?? false);
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public static function update(array $input): array|WP_Error
    {
        $settings = self::validate($input);
        if ($settings instanceof WP_Error) return $settings;
        update_option(self::OPTION, $settings, false);
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
        return $settings;
    }

    /** @return array<string,mixed>|WP_Error */
    public static function reset(): array|WP_Error
    {
        return self::update(self::defaults());
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public static function validate(array $input): array|WP_Error
    {
        $settings = array_replace(self::defaults(), $input);
        $errors = [];
        if (!is_bool($settings['feed_algorithm_v2_enabled'])) $errors['feed_algorithm_v2_enabled'] = 'invalid';
        foreach (['like_weight','view_weight','comment_weight','share_weight','max_engagement_score','square_role_multiplier','speaker_role_multiplier','official_role_multiplier','editorial_multiplier','good_deed_multiplier','same_city_multiplier','same_province_multiplier','following_multiplier','max_total_boost'] as $key) {
            if (!is_numeric($settings[$key]) || !is_finite((float) $settings[$key]) || (float) $settings[$key] < 0 || (float) $settings[$key] > 100) $errors[$key] = 'invalid';
            else $settings[$key] = (float) $settings[$key];
        }
        foreach (['max_post_age_hours' => [1,168], 'candidate_pool_size' => [1,1000], 'max_same_author_in_top_n' => [1,100], 'diversity_top_n' => [1,100]] as $key => [$min,$max]) {
            if (filter_var($settings[$key], FILTER_VALIDATE_INT) === false || (int) $settings[$key] < $min || (int) $settings[$key] > $max) $errors[$key] = 'invalid';
            else $settings[$key] = (int) $settings[$key];
        }
        $quotas = $settings['source_quotas'];
        $quotaKeys = ['following','recent','speaker','editorial','good_deed','location','general'];
        if (!is_array($quotas)) {
            $errors['source_quotas'] = 'invalid';
        } else {
            foreach ($quotaKeys as $key) {
                if (filter_var($quotas[$key] ?? null, FILTER_VALIDATE_INT) === false || (int) $quotas[$key] < 0 || (int) $quotas[$key] > 1000) {
                    $errors['source_quotas'] = 'invalid';
                    break;
                }
                $quotas[$key] = (int) $quotas[$key];
            }
            $settings['source_quotas'] = $quotas;
        }
        foreach (['max_speaker_ratio_top_20', 'max_official_ratio_top_20'] as $key) {
            if (!is_numeric($settings[$key]) || !is_finite((float) $settings[$key]) || (float) $settings[$key] < 0 || (float) $settings[$key] > 100) $errors[$key] = 'invalid';
            else $settings[$key] = (float) $settings[$key];
        }
        $buckets = $settings['freshness_buckets'];
        if (!is_array($buckets) || $buckets === []) $errors['freshness_buckets'] = 'invalid';
        else {
            $normal = []; $expectedMin = 0;
            foreach ($buckets as $bucket) {
                if (!is_array($bucket) || !is_numeric($bucket['min_hours'] ?? null) || !is_numeric($bucket['max_hours'] ?? null) || !is_numeric($bucket['multiplier'] ?? null)) { $errors['freshness_buckets'] = 'invalid'; break; }
                $min=(float)$bucket['min_hours']; $max=(float)$bucket['max_hours']; $multiplier=(float)$bucket['multiplier'];
                if (!is_finite($min)||!is_finite($max)||!is_finite($multiplier)||$min != $expectedMin||$max<=$min||$max>$settings['max_post_age_hours']||$multiplier<0||$multiplier>100) { $errors['freshness_buckets']='invalid'; break; }
                $normal[]=['min_hours'=>$min,'max_hours'=>$max,'multiplier'=>$multiplier]; $expectedMin=$max;
            }
            if ($expectedMin != (float)$settings['max_post_age_hours']) $errors['freshness_buckets']='invalid';
            $settings['freshness_buckets']=$normal;
        }
        if ($settings['max_same_author_in_top_n'] > $settings['diversity_top_n']) $errors['max_same_author_in_top_n']='invalid';
        return $errors ? new WP_Error('validation_failed', 'تنظیمات فید معتبر نیست.', ['status'=>422,'fields'=>$errors]) : $settings;
    }
}

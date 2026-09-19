#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src'
settings = (root / 'Feed/FeedSettings.php').read_text(encoding='utf-8')

assert "meydan_feed_settings" in settings
assert "wp_cache_delete" in settings
assert "function validate" in settings
print('Feed V2 settings contract OK.')

generator = (root / 'Feed/CandidateGenerator.php').read_text(encoding='utf-8')
hydrator = (root / 'Feed/CandidateHydrator.php').read_text(encoding='utf-8')
for source in ['following', 'recent', 'editorial', 'good_deed', 'same_city', 'same_province', 'general']:
    assert source in generator
assert 'post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)' in generator
assert "ORDER BY p.post_date_gmt DESC, p.ID DESC" in generator
assert 'RAND(' not in generator and 'mt_rand' not in generator
assert 'score' not in generator.lower() and 'rank' not in generator.lower()
assert 'get_post(' not in hydrator and 'get_post_meta(' not in hydrator
assert 'IN (' in hydrator
print('Feed V2 candidate pipeline contract OK.')

service = (root / 'Feed/FeedService.php').read_text(encoding='utf-8')
for stage in ['FeedContext', 'CandidateGenerator', 'CandidateHydrator', 'FeedFilter', 'FeedScorer', 'FeedRanker', 'FeedDiversity']:
    assert stage in service
for side_effect in ['incrementViews', 'served_history', 'EventLogger', 'wp_cache_set', 'wp_cache_add']:
    assert side_effect not in service
print('Feed V2 orchestration contract OK.')

routes = (root / 'Rest/Routes.php').read_text(encoding='utf-8')
timeline = (root / 'Rest/TimelineController.php').read_text(encoding='utf-8')
assert "'/admin/feed/settings','GET'" in routes
assert "'/admin/feed/settings','PUT'" in routes
assert "'/admin/feed/preview','GET'" in routes
assert 'FeedSettings::enabled()' in timeline
assert 'debug_feed' in timeline and 'isAdministrator' in timeline
assert 'Stats::incrementViewsBulk($ids)' in timeline
print('Feed V2 REST integration contract OK.')

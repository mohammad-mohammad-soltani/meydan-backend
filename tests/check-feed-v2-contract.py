#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src'
settings = (root / 'Feed/FeedSettings.php').read_text(encoding='utf-8')

assert "meydan_feed_settings" in settings
assert "wp_cache_delete" in settings
assert "function validate" in settings
print('Feed V2 settings contract OK.')

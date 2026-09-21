#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src'
routes = (root / 'Rest/Routes.php').read_text(encoding='utf-8')
content = (root / 'Rest/ContentController.php').read_text(encoding='utf-8')
serializer = (root / 'Support/Serializer.php').read_text(encoding='utf-8')

assert r"self::r('/admin/narratives/(?P<id>\d+)/content','POST'" in routes
assert r"self::r('/admin/narratives/(?P<id>\d+)/content','DELETE'" in routes
assert 'function convertNarrative' in content
assert 'function removeNarrativeContent' in content
assert "'is_content'" in serializer
assert "'content_id'" in serializer
assert "'producer'" in serializer
assert "meydan_source_narrative_id" in content
assert "meydan_producer_actor_type" in content
assert "meydan_producer_actor_id" in content
assert "validateOwner" in content
assert "meydan_user_id" in content and "meydan_creator_id" in content
assert "content_creators" in serializer and "display_name" in serializer
conversion = content.split('public function convertNarrative', 1)[1].split('public function removeNarrativeContent', 1)[0]
assert "format" in conversion
assert "meydan_format" in conversion
print('Content conversion contract OK.')

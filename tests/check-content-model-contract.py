#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src'
routes = (root / 'Rest/Routes.php').read_text(encoding='utf-8')
content = (root / 'Rest/ContentController.php').read_text(encoding='utf-8')
serializer = (root / 'Support/Serializer.php').read_text(encoding='utf-8')
creator = (root / 'Rest/CreatorController.php').read_text(encoding='utf-8')

assert r"self::r('/admin/content','GET'" in routes
assert "/admin/content/(?P<id>" in routes and "'adminGet'" in routes
assert "'/admin/creators','GET'" in routes
assert "function adminGet" in creator
assert "CONTENT_TYPES" in content
assert "'is_user'" in serializer
assert "'user_id'" in serializer
assert "'creator_id'" in serializer
assert "'content_type'" in serializer
assert "'view_counts'" in serializer
assert "'attached_media'" in serializer
assert "'media_cover'" in serializer
assert "'created_at'" in serializer
assert "'media_title'" in serializer
assert "'media_subtitle'" in serializer
assert "'media_mime_type'" in serializer
assert "'media_size'" in serializer
assert "validateOwner" in content
assert "normalizeAttachedMedia" in content
assert "content_type" in content.split('public function convertNarrative', 1)[1]
assert "meydan_is_user" in content
assert "meydan_user_id" in content
assert "meydan_creator_id" in content
assert "content_owned" in creator
print('Content model contract OK.')

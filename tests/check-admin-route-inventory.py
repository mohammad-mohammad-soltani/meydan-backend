#!/usr/bin/env python3
import re
from pathlib import Path

root = Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src'
routes = (root / 'Rest/Routes.php').read_text(encoding='utf-8')
found = {(method, route) for route, method in re.findall(r"self::r\('([^']+)','([^']+)'", routes)}
required = {
    ('GET', '/admin/squares'), ('POST', '/admin/squares'),
    ('GET', r'/admin/squares/(?P<id>\d+)'), ('PATCH', r'/admin/squares/(?P<id>\d+)'),
    ('DELETE', r'/admin/squares/(?P<id>\d+)'), ('POST', r'/admin/squares/(?P<id>\d+)/status'),
    ('GET', '/admin/squares/map'),
    ('GET', '/admin/speakers'), ('POST', '/admin/speakers'),
    ('GET', '/admin/speaker-requests'), ('GET', r'/admin/speaker-requests/(?P<id>\d+)'),
    ('PATCH', r'/admin/speaker-requests/(?P<id>\d+)'),
    ('GET', '/admin/speaker-invitations'), ('PATCH', r'/admin/speaker-invitations/(?P<id>\d+)'),
    ('GET', '/admin/initiatives'), ('POST', '/admin/initiatives'),
    ('GET', r'/admin/initiatives/(?P<id>\d+)'), ('PATCH', r'/admin/initiatives/(?P<id>\d+)'),
    ('DELETE', r'/admin/initiatives/(?P<id>\d+)'),
    ('GET', '/admin/campaigns'), ('POST', '/admin/campaigns'),
    ('GET', r'/admin/campaigns/(?P<id>\d+)'), ('PATCH', r'/admin/campaigns/(?P<id>\d+)'),
    ('DELETE', r'/admin/campaigns/(?P<id>\d+)'),
}
missing = sorted(required - found)
assert not missing, f'Missing admin routes: {missing}'
assert "self::isAdministrator()" in routes
assert "'unauthenticated'" in routes and "'forbidden'" in routes
print(f'Admin route inventory OK: {len(required)} routes present and administrator permission is centralized.')

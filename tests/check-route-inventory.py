#!/usr/bin/env python3
import re, sys
from pathlib import Path
routes = Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src/Rest/Routes.php'
text = routes.read_text(encoding='utf-8')
found={(m,r) for r,m in re.findall(r"self::r\('([^']+)','([^']+)'", text)}
expected='''
POST /auth/otp/request
POST /auth/otp/verify
POST /auth/refresh
POST /auth/logout
POST /auth/logout-all
POST /auth/register/user
POST /auth/register/square
GET /me
PATCH /me/profile
GET /me/narratives
GET /me/following
GET /me/initiatives
GET /me/bookmarks
GET /me/speaker-requests
PATCH /me/square
PUT /me/square/location
GET /me/square/schedule
POST /me/square/schedule
PATCH /me/square/schedule/(?P<id>\\d+)
DELETE /me/square/schedule/(?P<id>\\d+)
GET /users/(?P<id>\\d+)
GET /users/(?P<id>\\d+)/narratives
GET /narratives/(?P<id>\\d+)
POST /narratives
PATCH /narratives/(?P<id>\\d+)
DELETE /narratives/(?P<id>\\d+)
PUT /narratives/(?P<id>\\d+)/like
DELETE /narratives/(?P<id>\\d+)/like
PUT /narratives/(?P<id>\\d+)/repost
DELETE /narratives/(?P<id>\\d+)/repost
POST /narratives/(?P<id>\\d+)/share
GET /narratives/(?P<id>\\d+)/comments
POST /narratives/(?P<id>\\d+)/comments
GET /comments/(?P<id>\\d+)/replies
PATCH /comments/(?P<id>\\d+)
DELETE /comments/(?P<id>\\d+)
GET /narratives/(?P<id>\\d+)/media-reflections
POST /admin/narratives/(?P<id>\\d+)/media-reflections
PATCH /admin/media-reflections/(?P<id>\\d+)
DELETE /admin/media-reflections/(?P<id>\\d+)
POST /uploads
PUT /uploads/(?P<upload_id>[A-Za-z0-9_\\-]+)/chunks/(?P<index>\\d+)
POST /uploads/(?P<upload_id>[A-Za-z0-9_\\-]+)/complete
DELETE /uploads/(?P<upload_id>[A-Za-z0-9_\\-]+)
GET /timeline
PUT /actors/(?P<type>user|square)/(?P<id>\\d+)/follow
DELETE /actors/(?P<type>user|square)/(?P<id>\\d+)/follow
GET /actors/(?P<type>user|square)/(?P<id>\\d+)/followers
GET /actors/(?P<type>user|square)/(?P<id>\\d+)/following
GET /content
GET /content/(?P<id>\\d+)
PUT /content/(?P<id>\\d+)/bookmark
DELETE /content/(?P<id>\\d+)/bookmark
POST /content/(?P<id>\\d+)/share
POST /content/(?P<id>\\d+)/files/(?P<file_id>\\d+)/download
POST /admin/content
PATCH /admin/content/(?P<id>\\d+)
DELETE /admin/content/(?P<id>\\d+)
GET /creators
GET /creators/(?P<id>\\d+)
GET /speakers
GET /speakers/(?P<id>\\d+)
POST /admin/creators
PATCH /admin/creators/(?P<id>\\d+)
DELETE /admin/creators/(?P<id>\\d+)
POST /speaker-requests
GET /speaker-requests/(?P<id>\\d+)
DELETE /speaker-requests/(?P<id>\\d+)
GET /squares
GET /squares/map
GET /squares/(?P<id>\\d+)
GET /squares/(?P<id>\\d+)/narratives
GET /squares/(?P<id>\\d+)/media-reflections/count
GET /squares/(?P<id>\\d+)/schedule
GET /geo/provinces
GET /geo/cities
GET /initiatives
GET /initiatives/(?P<id>\\d+)
PUT /initiatives/(?P<id>\\d+)/join
DELETE /initiatives/(?P<id>\\d+)/join
GET /explore/search
GET /explore/trends
GET /explore/suggestions
GET /campaigns
GET /campaigns/current
GET /campaigns/(?P<id>\\d+)
GET /campaigns/(?P<id>\\d+)/schedule
GET /notifications
GET /notifications/unread-count
PUT /notifications/(?P<id>\\d+)/read
DELETE /notifications/(?P<id>\\d+)/read
PUT /notifications/read-all
PUT /notifications/(?P<id>\\d+)/archive
DELETE /notifications/(?P<id>\\d+)/archive
DELETE /notifications/(?P<id>\\d+)
POST /admin/notifications/broadcast
GET /config
'''
want=set()
for line in expected.splitlines():
    line=line.strip()
    if not line: continue
    method, route=line.split(' ',1)
    want.add((method,route))
missing=sorted(want-found)
if missing:
    print('Missing routes:')
    for m,r in missing: print(m,r)
    sys.exit(1)
print(f'Route inventory OK: {len(want)} required endpoints present; {len(found)} total routes registered.')

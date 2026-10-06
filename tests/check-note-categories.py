#!/usr/bin/env python3
"""Notes can be filed under speaker categories or the notes section's own; the choice, the speaker default and
«برگزیده» all flow through publishing. Needs an administrator account: ADMIN_PHONE (default 09120000001).
Run: BASE_URL=http://localhost:8082/wp-json/meydan/v1 MEYDAN_DEV_OTP_CODE=... python3 tests/check-note-categories.py
"""
import json, os, sys, urllib.request, urllib.error

BASE = os.environ.get('BASE_URL', 'http://localhost:8082/wp-json/meydan/v1')
OTP = os.environ.get('MEYDAN_DEV_OTP_CODE', '482193')


def call(method, path, token=None, body=None):
    req = urllib.request.Request(BASE + path, method=method)
    req.add_header('Content-Type', 'application/json')
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(req, json.dumps(body).encode() if body is not None else None) as r:
            return r.status, json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b'{}')


def check(cond, msg):
    if not cond:
        print('FAIL', msg)
        sys.exit(1)
    print('•', msg)


_, ch = call('POST', '/auth/otp/request', body={'phone': os.environ.get('ADMIN_PHONE', '09120000001')})
_, v = call('POST', '/auth/otp/verify', body={'challenge_id': ch['data']['challenge_id'], 'code': ch['data'].get('dev_code', OTP)})
T = v['data']['access_token']

s, c = call('POST', '/admin/note-categories', T, {'name': 'دستهٔ آزمایشی'})
check(s == 201 and c['data']['source'] == 'note', 'a notes-only category can be created')
slug = c['data']['slug']
s, lst = call('GET', '/admin/note-categories', T)
check(any(i['source'] == 'speaker' for i in lst['data']) and any(i['slug'] == slug for i in lst['data']), 'the list holds speaker categories and the new one')
check(call('PATCH', '/admin/note-categories/siyasi', T, {'name': 'x'})[0] == 404, 'speaker categories cannot be renamed here')

_, n = call('POST', '/narratives', T, {'body': 'عنوان\nمتن یادداشت'})
s, r = call('POST', f"/admin/narratives/{n['data']['id']}/content", T, {'content_type': 'speech', 'title': 'یادداشت دسته‌دار', 'format': 'text', 'category': slug, 'featured': True})
check(s == 201 and r['data']['category']['slug'] == slug, 'publishing files the note under the chosen category')
_, hub = call('GET', '/content/hub/notes?t=1', T)
check(any(i['id'] == r['data']['id'] for i in hub['data']['featured']), '«برگزیده» puts it in the featured strip')
check(any(cat['slug'] == slug for cat in hub['data']['categories']), 'the category appears among the chips')

_, n2 = call('POST', '/narratives', T, {'body': 'بدون دسته\nمتن'})
s, r2 = call('POST', f"/admin/narratives/{n2['data']['id']}/content", T, {'content_type': 'speech', 'title': 'بدون دسته', 'format': 'text'})
check(s == 201 and r2['data']['category'] is None, 'no choice and a non-speaker publisher → uncategorised')

check(call('DELETE', f'/admin/note-categories/{slug}', T)[0] == 200, 'an own category can be deleted')
print('Note categories flow passed.')

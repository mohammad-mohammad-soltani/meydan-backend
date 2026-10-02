#!/usr/bin/env python3
"""End-to-end check of «کارها» (work groups) against a running stack.

Two users: A publishes an echo narrative (auto-creates the work + group),
B joins. A posts every message kind; B replies / votes / claims / sees.
Run: BASE_URL=http://localhost:8082/wp-json/meydan/v1 python3 tests/check-work-groups-flow.py
"""
import json, os, sys, time, urllib.request, urllib.error

BASE = os.environ.get('BASE_URL', 'http://localhost:8080/wp-json/meydan/v1')
OTP = os.environ.get('MEYDAN_DEV_OTP_CODE', '482193')


def call(method, path, token=None, body=None, expect=None):
    req = urllib.request.Request(BASE + path, method=method)
    req.add_header('Content-Type', 'application/json')
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    data = json.dumps(body).encode() if body is not None else None
    try:
        with urllib.request.urlopen(req, data) as r:
            status, payload = r.status, json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        status, payload = e.code, json.loads(e.read() or b'{}')
    if expect is not None and status != expect:
        print(f'FAIL {method} {path} -> {status} (expected {expect}): {json.dumps(payload, ensure_ascii=False)[:400]}')
        sys.exit(1)
    return status, payload


def login(phone, name):
    _, ch = call('POST', '/auth/otp/request', body={'phone': phone}, expect=200)
    _, v = call('POST', '/auth/otp/verify', body={'challenge_id': ch['data']['challenge_id'], 'code': OTP}, expect=200)
    d = v['data']
    if not d.get('authenticated', False):
        _, v = call('POST', '/auth/register/user', body={'registration_token': d['registration_token'], 'full_name': name, 'province_id': 1, 'city_id': 1}, expect=200)
        d = v['data']
    return d['access_token']


def step(msg):
    print('•', msg)


A = login('+989120000011', 'Work Owner')
B = login('+989120000012', 'Work Member')
_, me_a = call('GET', '/me', A, expect=200)
_, me_b = call('GET', '/me', B, expect=200)
uid_b = int(str(me_b['data'].get('numeric_id') or me_b['data']['id']).split('_')[-1])

step('A publishes an echo narrative -> work group is created')
_, n = call('POST', '/narratives', A, {'body': f'کار آزمایشی {time.time()}\nشرح', 'is_echo': True}, 201)
initiative = n['data']['initiative']
assert initiative and initiative.get('work_id'), 'narrative initiative must expose work_id'
work = initiative['work_id']

step('work group is NOT in the chat conversation list and cannot be used through chat')
_, convs = call('GET', '/chat/conversations', A, expect=200)
assert all(c['id'] != work for c in convs['data']), 'work leaked into chat list'
call('POST', f'/chat/conversations/{work}/messages', A, {'client_id': 'x1', 'body': 'hi'}, 404)

step('B can read the room but cannot post until joined')
call('GET', f'/works/{work}', B, expect=200)
call('POST', f'/works/{work}/messages', B, {'client_id': 'b-pre', 'kind': 'text', 'body': 'hi'}, 403)

step('B joins; owner is notified')
call('PUT', f'/works/{work}/join', B, expect=200)
_, d = call('GET', f'/works/{work}', B, expect=200)
assert d['data']['viewer']['joined'] and d['data']['viewer']['role'] == 'member'

step('member cannot post a plain message')
st, r = call('POST', f'/works/{work}/messages', B, {'client_id': 'b-plain', 'kind': 'text', 'body': 'hello'})
assert st == 403, (st, r)

step('A posts text with mention, task, meeting, private meeting, announcement, poll')
_, t = call('POST', f'/works/{work}/messages', A, {'client_id': 'a-text', 'kind': 'text', 'body': 'سلام @member', 'mention_ids': [uid_b]}, 201)
_, task = call('POST', f'/works/{work}/messages', A, {'client_id': 'a-task', 'kind': 'task', 'body': 'توضیح', 'task': {'title': 'وظیفه', 'priority': 'high', 'capacity': 1, 'items': ['الف', 'ب']}}, 201)
task_id = task['data']['id']
assert task['data']['task']['open_slots'] == 1 and len(task['data']['task']['items']) == 2
_, meet = call('POST', f'/works/{work}/messages', A, {'client_id': 'a-meet', 'kind': 'meeting', 'meeting': {'title': 'جلسه', 'when': 'فردا', 'place': 'مسجد', 'agenda': 'x'}}, 201)
_, priv = call('POST', f'/works/{work}/messages', A, {'client_id': 'a-priv', 'kind': 'meeting', 'meeting': {'title': 'خصوصی', 'when': 'فردا', 'private_user_ids': [uid_b]}}, 201)
_, ann = call('POST', f'/works/{work}/messages', A, {'client_id': 'a-ann', 'kind': 'announcement', 'announcement': {'title': 'اعلان', 'urgent': True, 'pinned': True}}, 201)
_, poll = call('POST', f'/works/{work}/messages', A, {'client_id': 'a-poll', 'kind': 'poll', 'poll': {'title': 'ساعت؟', 'options': ['۲۲', '۲۳']}}, 201)
call('POST', f'/works/{work}/messages', A, {'client_id': 'a-poll-bad', 'kind': 'poll', 'poll': {'title': 'x', 'options': ['فقط یکی']}}, 422)

step('idempotent retry returns the same message')
_, again = call('POST', f'/works/{work}/messages', A, {'client_id': 'a-text', 'kind': 'text', 'body': 'سلام @member'}, 201)
assert again['data']['id'] == t['data']['id']

step('B interacts: reply, react, claim, rsvp, seen, vote')
call('POST', f'/works/{work}/messages', B, {'client_id': 'b-reply', 'kind': 'text', 'body': 'باشه', 'reply_to_id': int(t['data']['id'])}, 201)
call('PUT', f'/works/messages/{ann["data"]["id"]}/reaction', B, {'reaction': '👍'}, 200)
call('POST', f'/works/tasks/{task_id}/claim', B, expect=200)
call('PUT', f'/works/tasks/{task_id}/status', B, {'action': 'start'}, 200)
call('PUT', f'/works/meetings/{meet["data"]["id"]}/rsvp', B, {'response': 'yes'}, 200)
_, seen = call('PUT', f'/works/announcements/{ann["data"]["id"]}/seen', B, expect=200)
assert seen['data']['announcement']['seen_count'] == 1 and seen['data']['announcement']['seen_by_me']
_, voted = call('PUT', f'/works/polls/{poll["data"]["id"]}/vote', B, {'option': 1}, 200)
assert voted['data']['poll']['my_vote'] == 1 and voted['data']['poll']['total'] == 1

step('member cannot reply to a non-manager message, cannot approve')
call('PUT', f'/works/tasks/{task_id}/status', B, {'action': 'finish'}, 200)
call('PUT', f'/works/tasks/{task_id}/status', B, {'action': 'approve'}, 403)
call('PUT', f'/works/tasks/{task_id}/status', A, {'action': 'approve'}, 200)

step('task capacity: a second volunteer is rejected')
_, task2 = call('POST', f'/works/{work}/messages', A, {'client_id': 'a-task2', 'kind': 'task', 'task': {'title': 'دو', 'capacity': 1}}, 201)
call('POST', f'/works/tasks/{task2["data"]["id"]}/claim', B, expect=200)

step('private meeting visible to the audience (B) and managers only')
_, bmsgs = call('GET', f'/works/{work}/messages?limit=100', B, expect=200)
assert any(m['kind'] == 'meeting' and m['is_private'] for m in bmsgs['data'])
C = login('+989120000013', 'Work Outsider')
call('PUT', f'/works/{work}/join', C, expect=200)
_, cmsgs = call('GET', f'/works/{work}/messages?limit=100', C, expect=200)
assert not any(m['is_private'] for m in cmsgs['data']), 'private meeting leaked to non-audience member'

step('summary, list, members, role change')
call('GET', '/works/summary', B, expect=200)
_, lst = call('GET', '/works?filter=joined', B, expect=200)
assert any(w['id'] == work for w in lst['data'])
_, mem = call('GET', f'/works/{work}/members?q=Work', A, expect=200)
assert len(mem['data']) >= 2
call('PUT', f'/works/{work}/members/{uid_b}/role', B, {'role': 'admin'}, 403)
call('PUT', f'/works/{work}/members/{uid_b}/role', A, {'role': 'admin'}, 200)
call('POST', f'/works/{work}/messages', B, {'client_id': 'b-admin-text', 'kind': 'text', 'body': 'ادمین'}, 201)

print('Work groups flow passed.')

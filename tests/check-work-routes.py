#!/usr/bin/env python3
"""Contract check for «کارها» (work groups): REST surface, login gate and chat isolation."""
import re
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[1] / "wp-content/plugins/meydan-core/src"
routes = (root / "Rest/WorkRoutes.php").read_text(encoding="utf-8")
chat = (root / "Support/ChatRepository.php").read_text(encoding="utf-8")
plugin = (root / "Plugin.php").read_text(encoding="utf-8")
groups = (root / "Domain/WorkGroups.php").read_text(encoding="utf-8")

found = set()
for double, single, method in re.findall(r"self::route\((?:\"([^\"]+)\"|'([^']+)'), '([A-Z]+)'", routes):
    found.add((double or single, method))
found = {(m, p.replace("{$id}", "(?P<id>\\d+)")) for p, m in found}

expected = """
GET /works
GET /works/summary
GET /works/(?P<id>\\d+)
PATCH /works/(?P<id>\\d+)
GET /works/(?P<id>\\d+)/members
PUT /works/(?P<id>\\d+)/members/(?P<user_id>\\d+)/role
PUT /works/(?P<id>\\d+)/members/(?P<user_id>\\d+)/label
DELETE /works/(?P<id>\\d+)
PUT /works/(?P<id>\\d+)/join
DELETE /works/(?P<id>\\d+)/join
PUT /works/(?P<id>\\d+)/read
GET /works/(?P<id>\\d+)/messages
POST /works/(?P<id>\\d+)/messages
GET /works/messages/(?P<id>\\d+)
PATCH /works/messages/(?P<id>\\d+)
DELETE /works/messages/(?P<id>\\d+)
PUT /works/messages/(?P<id>\\d+)/reaction
DELETE /works/messages/(?P<id>\\d+)/reaction
POST /works/tasks/(?P<id>\\d+)/claim
DELETE /works/tasks/(?P<id>\\d+)/claim
PUT /works/tasks/(?P<id>\\d+)/status
PUT /works/tasks/(?P<id>\\d+)/people
POST /works/tasks/(?P<id>\\d+)/nudge
POST /works/tasks/(?P<id>\\d+)/items
PATCH /works/task-items/(?P<id>\\d+)
DELETE /works/task-items/(?P<id>\\d+)
PUT /works/meetings/(?P<id>\\d+)/rsvp
PUT /works/announcements/(?P<id>\\d+)/seen
GET /works/announcements/(?P<id>\\d+)/seen
POST /works/announcements/(?P<id>\\d+)/remind
PUT /works/polls/(?P<id>\\d+)/vote
""".strip().splitlines()

errors = []
for line in expected:
    method, path = line.split(" ", 1)
    if (method, path) not in found:
        errors.append(f"missing route: {line}")

# Every work route is behind a login check, never public.
if "is_user_logged_in()" not in routes or "'permission_callback'" not in routes:
    errors.append("WorkRoutes must gate every route with is_user_logged_in()")
if "__return_true" in routes:
    errors.append("WorkRoutes must not contain public routes")

# Work groups never leak into regular chat.
if chat.count("c.type<>'work'") < 2:
    errors.append("ChatRepository must exclude type='work' from membership and the conversation list")

# Boot wiring + membership sync.
if "WorkRoutes::class" not in plugin or "WorkGroups::register" not in plugin:
    errors.append("Plugin must register WorkRoutes and WorkGroups")
if "save_post_meydan_initiative" not in groups:
    errors.append("Work groups must be created for every initiative")

if errors:
    print("\n".join(errors))
    sys.exit(1)
print(f"Work routes contract OK: {len(expected)} endpoints, login-gated, chat-isolated.")

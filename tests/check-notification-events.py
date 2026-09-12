#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
initiative = (root / "wp-content/plugins/meydan-core/src/Rest/InitiativeController.php").read_text(encoding="utf-8")

assert "NotificationService" in initiative, "InitiativeController must use NotificationService"
assert "initiative_join" in initiative, "joining a good-work initiative must create an initiative_join notification"
assert "get_post_field('post_author'" in initiative or 'get_post_field("post_author"' in initiative, "initiative owner must be the notification recipient"

print("notification event coverage ok")

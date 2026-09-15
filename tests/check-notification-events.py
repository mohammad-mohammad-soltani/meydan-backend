#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
initiative = (root / "wp-content/plugins/meydan-core/src/Rest/InitiativeController.php").read_text(encoding="utf-8")
service = (root / "wp-content/plugins/meydan-core/src/Notifications/NotificationService.php").read_text(encoding="utf-8")
controller = (root / "wp-content/plugins/meydan-core/src/Rest/NotificationController.php").read_text(encoding="utf-8")
settings = (root / "wp-content/plugins/meydan-core/src/Admin/SettingsPage.php").read_text(encoding="utf-8")
plugin = (root / "wp-content/plugins/meydan-core/src/Plugin.php").read_text(encoding="utf-8")
duplicate_settings = root / "wp-content/plugins/meydan-core/src/Admin/NotificationTemplateSettings.php"

assert "NotificationService" in initiative, "InitiativeController must use NotificationService"
assert "initiative_join" in initiative, "joining a good-work initiative must create an initiative_join notification"
assert "get_post_field('post_author'" in initiative or 'get_post_field("post_author"' in initiative, "initiative owner must be the notification recipient"

assert "iconUrl" in service, "notification icons must resolve through NotificationService"
assert "icon_media_id" in service, "notification templates must support uploaded media icons"
assert "icon_url" in controller and "notification(" in controller, "notification API must expose icon_url"
assert 'enctype="multipart/form-data"' in settings, "settings form must accept notification icon uploads"
assert "media_handle_upload" in settings, "notification icon files must be stored through the WordPress media API"
assert "meydan_notification_templates_json" not in settings, "notification templates must not use the old JSON textarea"
assert "NotificationTemplateSettings" not in plugin, "notification templates must be registered only through SettingsPage"
assert not duplicate_settings.exists(), "legacy NotificationTemplateSettings admin-footer editor must be removed"

print("notification event coverage ok")

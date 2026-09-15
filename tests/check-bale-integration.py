#!/usr/bin/env python3
"""Static contract checks for the Meydan Bale bot integration.

The Bale bot is the operator's out-of-band channel: it reports Eitaa sync
failures, project errors, and pending-square approvals. These checks keep the
wiring — and the security of the approval buttons — from silently regressing.
"""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
src = root / "wp-content/plugins/meydan-core/src"


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8") if path.exists() else ""


settings = text(src / "Integrations/Bale/Settings.php")
client = text(src / "Integrations/Bale/BaleClient.php")
notifier = text(src / "Integrations/Bale/Notifier.php")
reporter = text(src / "Integrations/Bale/ErrorReporter.php")
squares = text(src / "Integrations/Bale/SquareApprovalNotifier.php")
webhook = text(src / "Integrations/Bale/WebhookController.php")
events = text(src / "Integrations/Bale/EventSubscriber.php")
plugin = text(src / "Plugin.php")
page = text(src / "Admin/SettingsPage.php")
admin = text(src / "Admin/Admin.php")
eitaa_client = text(src / "Integrations/Eitaa/EitaaServiceClient.php")
eitaa_import = text(src / "Integrations/Eitaa/ImportService.php")

# Configuration lives in the WordPress panel (token + chat id), not in .env.
assert "meydan_bale_settings" in settings, "Bale settings must be stored in an option"
assert "MEYDAN_BALE_BOT_TOKEN" in settings, "deployment constant may override the token"
assert "chat_id" in settings and "isReady" in settings, "chat id must be required for delivery"
assert "webhookSecret" in settings, "webhook secret must be derived and persisted"

# Client: Telegram-compatible Bot API surface.
for method in ["sendMessage", "editMessageText", "answerCallbackQuery", "setWebhook", "getMe"]:
    assert method in client, f"Bale client missing {method}"
assert "https://tapi.bale.ai" in client, "Bale base URL must be the default"
assert "parse_mode" in client, "messages are sent as HTML"

# Requirement 2: project errors reach Bale.
assert "set_error_handler" in reporter, "error handler must be registered"
assert "register_shutdown_function" in reporter, "fatals require a shutdown handler"
assert "error_get_last" in reporter, "shutdown handler must inspect the last error"
assert "E_ERROR" in reporter and "E_USER_ERROR" in reporter, "fatal severities must be covered"
assert "Integrations/Bale/" in reporter, "reporter must not report its own path (loop guard)"

# Alerting must be rate limited and de-duplicated, with both knobs editable.
assert "sendThrottled" in notifier and "set_transient" in notifier, "alerts must be throttled"
assert "rate_limit" in notifier, "the per-minute ceiling must come from settings"
assert "throttle_window" in notifier, "the de-dup window must come from settings"

# Requirement 3: pending squares with two inline buttons.
assert "inline_keyboard" in squares, "approval message must use an inline keyboard"
assert "sq_approve" in squares and "sq_reject" in squares, "approve/reject actions required"
assert "hash_hmac" in squares and "hash_equals" in squares, "callback payload must be signed"
assert "parseCallbackData" in squares, "callback payload must be verified on receipt"
assert "applySquareStatus" in squares, "approval must reuse the shared admin code path"
assert "pending_verification" in squares, "only pending squares are announced"
assert "meydan_bale_pending_sent_at" in squares, "a square must not be announced twice"

# The shared code path must exist and stay capability-free for reuse.
assert "public static function applySquareStatus" in admin, "Admin must expose applySquareStatus"
assert "verify_meydan_squares" in admin, "wp-admin action must still check capability"
assert admin.count("applySquareStatus") >= 2, "wp-admin must delegate to the shared method"

# Requirement 1: Eitaa failures are reported.
assert "reportEitaaFailure" in events, "Eitaa failures need a reporting entry point"
assert "reportEitaaFailure" in eitaa_client, "Eitaa service client must report failures"
assert "reportEitaaFailure" in eitaa_import, "Eitaa import failures must be reported"

# Webhook security.
assert "/integrations/bale/webhook" in webhook, "webhook route must be registered"
assert "X-Telegram-Bot-Api-Secret-Token" in webhook, "webhook must verify the secret header"
assert "hash_equals" in webhook, "secret comparison must be constant time"
assert "chat_not_allowed" in webhook, "webhook must restrict the chat"
assert "callback_query" in webhook, "webhook must handle callback queries"

# Bootstrap wiring.
assert "BaleWebhookController" in plugin, "webhook controller must be imported"
assert "BaleWebhookController::class, 'register'" in plugin, "webhook routes must be registered"
assert "BaleErrorReporter::register" in plugin, "reporter must be armed during boot"
assert "BaleEventSubscriber::register" in plugin, "lifecycle events must be wired"
assert "meydan_bale_pending_square" in plugin, "deferred notification hook must be scheduled"
assert "meydan_bale_pending_square" in events, "cron handler must be registered"

# Admin panel.
assert "پیکربندی ربات بله" in page, "Bale settings section must exist in Persian UI"
assert "bale_token" in page and "bale_chat_id" in page, "token and chat id fields required"
assert "bale_error_reporting" in page and "bale_pending_squares" in page, "toggles required"
assert "bale_test" in page and "webhookUrl" in page, "settings page must offer a connection test"
assert "bale_test" in admin, "settings action must be handled"
assert "setWebhook" in admin, "the test action must register the webhook"

# Everything operational is editable in wp-admin, not only via constants/env.
for field in [
    "enabled", "token", "chat_id", "base_url", "timeout",
    "error_reporting", "report_fatals", "report_warnings", "report_notices",
    "report_eitaa", "pending_squares", "rate_limit", "throttle_window",
    "include_site_label", "webhook_secret",
]:
    assert f"'{field}'" in settings, f"Settings must define the {field} field in DEFAULTS"
    assert f"bale_{field}" in page, f"{field} must be editable in the panel"

assert "sanitize" in settings, "settings must validate admin input"
assert "clear_token" in settings and "clear_token" in page, "the token must be clearable"
assert "rotateWebhookSecret" in settings, "the webhook secret must be rotatable"
for action in ["bale_webhook_info", "bale_webhook_remove", "bale_rotate_secret"]:
    assert action in admin, f"missing admin action {action}"
assert "getWebhookInfo" in admin and "deleteWebhook" in admin, "webhook management must reach Bale"

# Eitaa connection is editable from the panel too, not only via constants.
assert "meydan_eitaa_sync_secret" in page, "Eitaa sync secret must be editable in the panel"
assert "meydan_eitaa_service_url" in page, "Eitaa service URL must be editable in the panel"
assert "meydan_eitaa_sync_secret" in text(src / "Integrations/Eitaa/Auth.php"), \
    "the Eitaa secret must prefer the panel value"
assert "meydan_eitaa_service_url" in eitaa_client, \
    "the Eitaa service URL must prefer the panel value"

# Secrets must be rendered as password inputs, never echoed back as plain text.
assert 'type="password"' in page, "secret fields must use password inputs"
assert "maskedToken" in settings or "hasToken" in page, "the stored token must not be echoed back"

print("bale bot integration contract ok")

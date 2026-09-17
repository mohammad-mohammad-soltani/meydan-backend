#!/usr/bin/env python3
"""Static contract checks for manual square creation and internal user emails."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
src = root / "wp-content/plugins/meydan-core/src"


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8") if path.exists() else ""


manual = text(src / "Admin/ManualSquare.php")
square_service = text(src / "Domain/SquareAdminService.php")
admin = text(src / "Admin/Admin.php")
plugin = text(src / "Plugin.php")
emails = text(src / "Support/UserEmails.php")
auth = text(src / "Rest/AuthController.php")
channels = text(src / "Integrations/Channels/Channels.php")
field = text(src / "Integrations/Eitaa/SquareChannelField.php")
serializer = text(src / "Support/Serializer.php")

# --- manual square page -----------------------------------------------------
assert manual, "manual square admin page must exist"
assert "class ManualSquare" in manual, "manual square page class is missing"
assert "PAGE = 'meydan-square-new'" in manual, "manual square page slug changed"
assert "add_action('admin_post_' . self::ACTION" in manual, "admin-post handler must be registered"
assert "register()" in plugin and "ManualSquare::register" in plugin, "plugin bootstrap must register the page"
assert "ManualSquare::PAGE" in admin and "افزودن میدان و کاربر" in admin, "panel menu must expose the page"

for capability in ["manage_meydan_squares"]:
    assert capability in manual, f"page must be guarded by {capability}"
assert "check_admin_referer" in manual, "form handler must verify its nonce"
assert "wp_nonce_field(self::FORM, self::NONCE)" in manual, "form must render its named nonce"

# One screen must collect both the account and the square object.
creation = manual + "\n" + square_service
for needle in [
    "wp_insert_user",
    "wp_insert_post",
    "meydan_square",
    "meydan_owner_user_id",
    "meydan_square_id",
    "meydan_phone_hash",
    "meydan_phone_ciphertext",
    "meydan_avatar_media_id",
    "meydan_square_geo",
    "province_id",
    "city_id",
    "address",
    "latitude",
    "longitude",
    "meydan_approval_status",
    "set_role('meydan_square')",
    "AuditLogger::log",
]:
    assert needle in creation, f"manual square creation missing {needle}"

# The OTP identity must be reusable, so the phone is normalized like auth does.
assert "OtpService::normalizePhone" in creation, "phone must be normalized like OTP registration"
assert "Crypto::hash" in creation and "Crypto::encrypt" in creation, "phone identity must match the auth storage"
assert "phoneOwner" in creation, "duplicate phone must be rejected"

# Fields rendered on the page.
for field_name in ["full_name", "square_name", "contact_name", "contact_phone", "description", "start_date", "email", "approve"]:
    assert f"'{field_name}'" in manual, f"form field {field_name} is missing"

# --- internal user emails ---------------------------------------------------
assert "class UserEmails" in emails, "internal email support must exist"
assert "add_action('user_register'" in emails, "new accounts must receive an email"
assert "add_filter('pre_user_email'" in emails, "insert-time email fallback is missing"
assert "add_action('user_profile_update_errors'" in emails, "edit-time email repair is missing"
assert "empty_email" in emails, "the empty-email error must be cleared"
assert "email_exists" in emails, "placeholder emails must stay unique"
assert "UserEmails::register()" in plugin, "email rule must be booted by the plugin"
assert "maybeBackfill" in plugin and "meydan_user_email_backfill" in emails, "legacy accounts must be repaired once"
assert "placeholderEmail" in emails, "a pre-insert placeholder generator is required"
assert "is_email" in emails, "placeholder preconditions must be validated with is_email"

# A single-label host is rejected by WordPress, so the placeholder domain needs
# at least two labels even on localhost.
assert "'.local'" in emails, "single-label hosts must gain a second label"

# Public registration must store a valid address from the start.
assert "UserEmails::placeholderEmail" in auth, "registration must set a valid WordPress email"

# --- channels (Eitaa + Bale) ------------------------------------------------
assert "class Channels" in channels, "shared channel helper must exist"
assert "meydan_eitaa_channel" in channels, "Eitaa channel key must be preserved"
assert "meydan_bale_channel" in channels, "Bale channel meta key must exist"
assert "normalizeBale" in channels and "normalizeEitaa" in channels, "both channels need normalizers"
assert "delete_user_meta" in channels, "clearing a channel field must remove that channel"
assert "meydan_bale_channel" in field and "meydan_eitaa_channel" in field, "both channel inputs belong on the square account screen"
assert "Channels::save" in field, "square account screen must save through the shared helper"
assert "Channels::render" in admin and "Channels::formNonce" in admin, "square edit screen must render both channels"
assert "Channels::store" in square_service, "shared square service must store channel values"
assert "Channels::formNonce" in manual, "manual page must render the channel nonce"
assert "Channels::definition" in manual, "manual page must reuse the channel field definitions"
assert "eitaa_channel" in serializer and "bale_channel" in serializer, "API must expose both channels"

# --- page mechanics ---------------------------------------------------------
assert "wp_nonce_field(self::FORM, self::NONCE)" in manual, "the form must render its own named nonce"
assert "check_admin_referer(self::FORM, self::NONCE)" in manual, "the handler must verify that exact nonce"
# wp-admin loads template.php (and its submit_button() helper) from
# admin-header.php, which runs after the page callback; calling it here would be
# a fatal error on the real screen.
assert "echo submit_button(" not in manual and "echo get_submit_button(" not in manual, (
    "the page callback must not call a template.php button helper"
)
assert 'class="button button-primary"' in manual, "the submit control must be plain markup"
assert "'pending_verification'" in manual and "'publish'" in manual, "approval must default to pending"

print("manual square + internal email contract ok")

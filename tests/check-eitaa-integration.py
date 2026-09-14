#!/usr/bin/env python3
"""Static contract checks for the Meydan ↔ Eitaa integration."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
src = root / "wp-content/plugins/meydan-core/src"

def text(path: Path) -> str:
    return path.read_text(encoding="utf-8") if path.exists() else ""

migrations = text(src / "Integrations/Eitaa/Migrations.php")
plugin = text(src / "Plugin.php")
auth = text(src / "Integrations/Eitaa/Auth.php")
bindings = text(src / "Integrations/Eitaa/BindingService.php")
field = text(src / "Integrations/Eitaa/SquareChannelField.php")
importer = text(src / "Integrations/Eitaa/ImportService.php")
controller = text(src / "Integrations/Eitaa/Controller.php")
uploads = text(src / "Integrations/Eitaa/IntegrationUploadService.php")
service = text(src / "Integrations/Eitaa/EitaaServiceClient.php")
page = text(src / "Integrations/Eitaa/AdminPage.php")
compose = text(root / "docker-compose.yml")

assert "meydan_eitaa_imports" in migrations, "Eitaa import mapping table must be migrated"
assert "UNIQUE KEY source_key" in migrations, "source_key must be protected by a DB unique index"
assert "last_success_at" in migrations, "per-square checkpoint must be stored"
assert "meydan_eitaa_channel" in field, "Square profile must expose the Eitaa channel field"
assert "meydan_square" in field, "channel field must be guarded to Square accounts"

for needle in ["X-Meydan-Eitaa-Timestamp", "X-Meydan-Eitaa-Nonce", "X-Meydan-Eitaa-Signature", "hash_equals", "hash_hmac('sha256'"]:
    assert needle in auth, f"integration auth missing {needle}"
assert "300" in auth, "HMAC verifier must enforce roughly five-minute clock skew"
assert "set_transient" in auth and "nonce" in auth.lower(), "nonce replay protection must be persisted"

for route in [
    "/integrations/eitaa/squares",
    "/integrations/eitaa/known",
    "/integrations/eitaa/import",
    "/integrations/eitaa/checkpoint",
    "/integrations/eitaa/uploads",
]:
    assert route in controller, f"missing integration route {route}"

assert "meydan_eitaa_channel" in bindings and "last_success_at" in bindings
assert "post_date_gmt" in importer and "meydan_author_actor_type" in importer
assert "meydan_import_source_key" in importer and "meydan_import_source_hash" in importer
assert "source_key" in importer and "meydan_eitaa_imports" in importer
assert "IntegrationUploadService" in controller and "Auth::verify" in controller
assert "ChunkedUploadService" in uploads or "meydan_uploads" in uploads

assert "همگام‌سازی ایتا" in page, "admin page must exist in Persian UI"
assert "send-code" in service and "verify-code" in service and "status" in service
assert "EITAA_SERVICE_URL" in service, "service client must use EITAA_SERVICE_URL"
assert "EITAA_WORKER_URL" not in service, "legacy worker URL must be removed"
assert "EITAA_SERVICE_URL" in compose and "http://eitaa-api:3000" in compose
assert "meydan_internal" in compose, "WordPress must join the shared Eitaa Docker network"
assert "check_admin_referer" in page and "manage_options" in page
assert "AdminPage::register" in plugin, "plugin bootstrap must register Eitaa admin page"
assert "SquareChannelField::register" in plugin
assert "Migrations::maybeRun" in plugin and "Integrations\\Eitaa\\Migrations" in plugin
assert "Controller::register" in plugin, "REST integration routes must be registered independently"

print("eitaa integration contract ok")

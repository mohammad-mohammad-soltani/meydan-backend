#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
plugin = (root / "wp-content/plugins/meydan-core/src/Plugin.php").read_text(encoding="utf-8")
handler_path = root / "wp-content/plugins/meydan-core/src/Support/RootResponse.php"

assert handler_path.exists(), "RootResponse handler must exist"
handler = handler_path.read_text(encoding="utf-8")
assert "RootResponse::register" in plugin, "Plugin must register the root response handler"
assert "template_redirect" in handler, "root response must use WordPress template_redirect"
assert "application/json" in handler, "root response must declare JSON content type"
assert "Enter the endpoint." in handler, "root response must include the endpoint hint"
assert "wp_json_encode" in handler, "root response must be encoded as JSON"

print("root response contract ok")

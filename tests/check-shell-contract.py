"""/me/shell carries what every page needs; /me/unread is the cheap badge poll; both are private."""
import pathlib
src = pathlib.Path(__file__).resolve().parents[1] / "wp-content/plugins/meydan-core/src"
shell = (src / "Rest/ShellController.php").read_text(encoding="utf-8")
routes = (src / "Rest/Routes.php").read_text(encoding="utf-8")
assert "self::r('/me/shell','GET',[$shell,'shell'])" in routes
assert "self::r('/me/unread','GET',[$shell,'unreadCounts'])" in routes
for key in ("'me' =>", "'unread' =>", "'realtime' =>", "'push' =>"):
    assert key in shell, key
assert shell.count("'private, no-store'") == 2, "viewer data must never be cached publicly"
assert "LIMIT %d" in shell, "unread counts are bounded"
print("ok")

"""Content hub, explore home and comment-like endpoints exist, are public where they must be, and never cache per search text."""
import pathlib, re
src = pathlib.Path(__file__).resolve().parents[1] / "wp-content/plugins/meydan-core/src"
routes = (src / "Rest/Routes.php").read_text(encoding="utf-8")
hub = (src / "Rest/ContentHubController.php").read_text(encoding="utf-8")
explore = (src / "Rest/ExploreController.php").read_text(encoding="utf-8")
comments = (src / "Rest/CommentController.php").read_text(encoding="utf-8")
for route in ("/content/hub/audio", "/content/hub/notes", "/content/hub/producers", "/explore/home"):
    assert f"'{route}'" in routes, route
    assert routes.count(f"'{route}'") >= 2, f"{route} must be registered and listed as public"
assert "/comments/(?P<id>\\d+)/like" in routes
assert "set_transient($key" not in hub and "md5(" not in hub, "searches must not create one cache row per text"
assert "mb_substr(trim((string) $request->get_param('q')), 0, 80)" in hub
assert "viewer_state'] = null" in explore, "the shared explore payload must carry no viewer state"
assert "primeCommentLikes" in comments
print("ok")

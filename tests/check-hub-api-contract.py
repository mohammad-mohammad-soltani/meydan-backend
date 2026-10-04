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
assert "md5(" not in hub, "searches must not create one cache row per text"
assert "$cacheable = $offset % $limit === 0" in hub, "producer pages are cached only at real page starts"
assert "mb_substr(trim((string) $request->get_param('q')), 0, 80)" in hub
assert "viewer_state'] = null" in explore, "the shared explore payload must carry no viewer state"
assert "primeCommentLikes" in comments
print("ok")

# Every route registration is r(route, METHOD, [controller, 'method']): a stray extra string made PHP fatal on every REST request.
import re as _re
_routes = open("wp-content/plugins/meydan-core/src/Rest/Routes.php", encoding="utf-8").read()
_bad = _re.findall(r"self::r\('[^']*','[^']*','(?!GET|POST|PUT|PATCH|DELETE)", _routes)
assert not _bad, f"malformed self::r() calls: {_bad}"
print("routes ok")

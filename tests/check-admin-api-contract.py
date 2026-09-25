#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src'
routes = (root / 'Rest/Routes.php').read_text(encoding='utf-8')
square = (root / 'Rest/AdminSquareController.php').read_text(encoding='utf-8')
service = (root / 'Domain/SquareAdminService.php').read_text(encoding='utf-8')
deletion = (root / 'Domain/SquareDeletionService.php').read_text(encoding='utf-8')
requests = (root / 'Rest/AdminSpeakerRequestController.php').read_text(encoding='utf-8')
creators = (root / 'Rest/CreatorController.php').read_text(encoding='utf-8')

for route in [
    "self::r('/admin/squares','GET'",
    "self::r('/admin/squares','POST'",
    "self::r('/admin/squares/(?P<id>\\d+)/status','POST'",
    "self::r('/admin/speaker-requests/(?P<id>\\d+)','PATCH'",
    "self::r('/admin/initiatives','POST'",
    "self::r('/admin/campaigns/(?P<id>\\d+)','PATCH'",
]:
    assert route in routes, route

for method in ['create', 'update']:
    assert f'function {method}' in service
assert "location_source" in service
assert "Geocoder::reverse($lat, $lng)" in service
assert "($input['location_source'] ?? 'manual')" in service
for method in ['list', 'create', 'get', 'update', 'delete', 'status', 'map']:
    assert f'function {method}' in square
assert "SquareDeletionService::deletePermanently($id)" in square
assert "wp_delete_post($squareId, true)" in deletion
assert "wp_delete_user($ownerId)" in deletion
assert "Admin::applySquareStatus" in square
assert "function update" in requests
assert "AuditLogger::log" in requests
assert "get_post($id)" in creators and "post_type!=='meydan_creator'" in creators
assert "wp_trash_post($id)" in creators
assert "self::isAdministrator()" in routes
print('Admin API contract OK.')


auth = (root / 'Rest/AuthController.php').read_text(encoding='utf-8')
users = (root / 'Rest/AdminUserController.php').read_text(encoding='utf-8')
assert "is_student_or_seminarian" in auth
assert "meydan_is_student_or_seminarian" in auth
assert "is_student_or_seminarian" in users
assert "meydan_is_student_or_seminarian" in users
print('Student/seminarian registration contract OK.')

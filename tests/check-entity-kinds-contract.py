#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src'
routes = (root / 'Rest/Routes.php').read_text(encoding='utf-8')
auth = (root / 'Rest/AuthController.php').read_text(encoding='utf-8')
squares = (root / 'Rest/AdminSquareController.php').read_text(encoding='utf-8')
users = (root / 'Rest/AdminUserController.php').read_text(encoding='utf-8')
regs = (root / 'Domain/Registrations.php').read_text(encoding='utf-8')
kinds = (root / 'Domain/EntityKinds.php').read_text(encoding='utf-8')

assert "self::r('/auth/register/entity','POST',[$auth,'registerEntity'])" in routes
assert "self::r('/auth/register/square','POST',[$auth,'registerSquare'])" in routes  # legacy stays
assert "self::r('/admin/squares/(?P<id>\\d+)/media-link','POST',[$adminSquare,'mediaLink'])" in routes

# Four kinds, three new roles, legacy square role kept as secondary role.
for k in ["'square'", "'collective'", "'media'", "'organization'"]:
    assert k in kinds
for role in ['meydan_collective', 'meydan_media', 'meydan_organization']:
    assert role in kinds
assert 'EntityKinds::ROLE_LABELS' in regs
assert "add_role('meydan_square')" in kinds

# Only a square requires a location; accounts stay pending until an admin approves.
assert "if($type==='square'&&$kind==='square')$required=array_merge" in auth
assert "meydan_approval_status','pending_verification'" in auth
assert 'EntityKinds::createLinkedOutlet' in auth

# Outlet stays draft until approval; links are 1:1 and admin-controlled.
assert "'post_status' => 'draft'" in kinds
assert 'syncOutletStatus' in squares and 'public function mediaLink' in squares
assert "'kind' => EntityKinds::kindOf($id)" in squares
assert 'NOT EXISTS' in squares  # legacy squares without kind meta read as square

# Student / seminarian detail is additive.
assert 'meydan_student_kind' in auth and 'meydan_student_kind' in users
print('entity kinds contract ok')

# Media reflections filed by approved media accounts.
sync = (root / 'Domain/MediaReflectionSync.php').read_text(encoding='utf-8')
quotes = (root / 'Support/Quotes.php').read_text(encoding='utf-8')
narr = (root / 'Rest/NarrativeController.php').read_text(encoding='utf-8')
mig = (root / 'Database/Migrations.php').read_text(encoding='utf-8')
assert "MediaReflectionSync::quote(" in quotes
assert "MediaReflectionSync::repost(" in narr
assert "self::r('/narratives/(?P<id>\\d+)/media-reflections','POST',[$mr,'createMine'])" in routes
assert "'meydan_approval_status', true) !== 'approved'" in sync  # only approved media
assert "source VARCHAR(16)" in mig and "source_narrative_id" in mig
assert "'own_post'" in sync
print('media reflection sync contract ok')

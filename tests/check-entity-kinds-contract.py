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
assert "set_role(self::roleFor($kind))" in kinds  # exactly the kind's role, no secondary square role

# Only a square requires a location; accounts stay pending until an admin approves.
assert "if($type==='square'&&$kind==='square')$required=array_merge" in auth
assert "meydan_approval_status','pending_verification'" in auth
assert 'EntityKinds::createLinkedOutlet' in auth

# Outlet stays draft until approval; links are 1:1 and admin-controlled.
assert "'post_status' => 'draft'" in kinds
assert 'public function mediaLink' in squares
assert "'kind' => EntityKinds::kindOf($id)" in squares
assert 'EntityKinds::postType($kind)' in squares  # each kind is listed from its own post type

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

# Quote opt-out and no self-reflection.
assert "meydan_skip_media_reflection" in narr and "meydan_skip_media_reflection" in sync
assert "self::isOwn($quotedId, $sid)" in sync

# Profile performance: batched reflections, cached count, single-runner migration.
page = (root / 'Rest/ProfileNarrativePage.php').read_text(encoding='utf-8')
ser = (root / 'Support/Serializer.php').read_text(encoding='utf-8')
sq = (root / 'Rest/SquareController.php').read_text(encoding='utf-8')
assert 'Serializer::primeMediaReflections' in page and 'function primeMediaReflections' in ser
assert "meydan_square_reflections_" in sq
assert "add_option($lock" in mig

# Own profile in one request.
me = (root / 'Rest/MeController.php').read_text(encoding='utf-8')
assert "self::r('/me/profile-page','GET',[$me,'profilePage'])" in routes
assert 'public function profilePage(WP_REST_Request $r)' in me
assert 'public static function mediaReflectionTotal' in sq

# Own-profile speed: author-indexed queries instead of postmeta OR joins; one approval path.
adm = (root / 'Admin/Admin.php').read_text(encoding='utf-8')
asc = (root / 'Rest/AdminSquareController.php').read_text(encoding='utf-8')
assert "ProfileNarrativePage::listByAuthor($ownerId, $r, $type . ':' . $id, true)" in me
assert "'relation' => 'OR'" not in me.split('function actorNarratives')[1].split('ProfileNarrativePage::list($meta')[0]
mrt = sq.split('function mediaReflectionTotal')[1].split('set_transient')[0]
assert 'postmeta' not in mrt and 'p.post_author=%d' in mrt
cnt = ser.split('function squareNarrativeCount')[1].split('set_transient')[0]
assert 'WP_Query' not in cnt and 'post_author = %d' in cnt
assert 'EntityKinds::syncOutletStatus($id,$status)' in adm and 'syncOutletStatus' not in asc
assert "'kind' => $kind," in ser
print('own profile perf contract ok')

# Profile lists include the account's reposts (UNION by time, flagged reposted_at).
assert "UNION ALL" in page and "'reposted_at'" in page and "i.action = 'repost'" in page
assert "listByAuthor($ownerId,$r,'square:'.$sid,true)" in sq
print('profile reposts contract ok')

# Separate entities: own post type per kind, one registry, no hard-coded actor-type lists.
assert "'media' => 'meydan_media_acct'" in kinds and "'collective' => 'meydan_collective'" in kinds and "'organization' => 'meydan_organization'" in kinds
for pt in ['meydan_media_acct', 'meydan_collective', 'meydan_organization']:
    assert pt in regs
import re
for path in root.rglob('*.php'):
    text = path.read_text(encoding='utf-8')
    for bad in ["['user', 'square']", "['user','square']", "IN ('user','square')"]:
        assert bad not in text, f"hard-coded actor types in {path.relative_to(root)}: use EntityKinds::actorTypes()"
    assert "'/square/'" not in text.replace("'/square/' . $", "") or path.name == 'EntityMigration.php', f"hand-built /square/ link in {path.relative_to(root)}: use Links::profile()"
print('separate entities contract ok')

# One link format: /{handle}. Resolver, entity API and the reserved-handle list exist.
links = (root / 'Support/Links.php').read_text(encoding='utf-8')
handles = (root / 'Support/Handles.php').read_text(encoding='utf-8')
assert "return '/' . $handle" in links
assert "self::r('/profiles/(?P<handle>[A-Za-z0-9_]+)','GET',[$profiles,'resolve'])" in routes
assert "self::r('/entities/(?P<kind>square|media|collective|organization)/(?P<id>\\d+)','GET',[$entity,'get'])" in routes
for word in ['home', 'explore', 'chat', 'compose', 'posts', 'profile', 'media', 'collective', 'organization', 'square', 'users', 'auth']:
    assert f"'{word}'" in handles.split('RESERVED = [')[1].split('];')[0], word
assert 'EntityMigration::run' in mig and 'migrate_entities' in (root / 'Support/CliCommand.php').read_text(encoding='utf-8')

# Search has a section per kind and never searches owners of unpublished entities.
exp = (root / 'Rest/ExploreController.php').read_text(encoding='utf-8')
for section in ["'media' => []", "'collectives' => []", "'organizations' => []"]:
    assert section in exp
assert "private function searchEntities" in exp and "get_post_status($entityId) !== 'publish'" in exp
print('links and search contract ok')

# Every actor payload carries the speaker flag next to the tick, so no surface can drop it.
chat = (root / 'Support/ChatRepository.php').read_text(encoding='utf-8')
work = (root / 'Domain/WorkUsers.php').read_text(encoding='utf-8')
assert "'verified_speaker'" in chat and "'verified_speaker'" in work and "'verified_speaker'" in me
assert "$kind === EntityKinds::SQUARE ? true : (bool) get_post_meta($id, 'meydan_verified', true)" in ser  # approved media/organizations are ticked
print('badges contract ok')

# Reference-design profile: pinned post, likes/highlights tabs, followed-by, social counts.
extras = (root / 'Domain/ProfileExtras.php').read_text(encoding='utf-8')
actor = (root / 'Rest/ActorController.php').read_text(encoding='utf-8')
for route in ["/likes','GET',[$actor,'likes']", "/highlights','GET',[$actor,'highlights']", "/followed-by','GET',[$actor,'followedBy']", "self::r('/me/pinned-narrative','PUT',[$me,'pinNarrative'])"]:
    assert route in routes, route
assert "(int) $post->post_author !== $ownerUserId" in extras  # only own narratives can be pinned
assert "'pinned' =>" in me and 'ProfileExtras::withPinned(' in actor
assert "'social'=>ProfileExtras::social('user',$id,$id)" in actor
print('profile extras contract ok')

# Share sheet «ذخیره روایت»: narrative bookmarks.
narr2 = (root / 'Rest/NarrativeController.php').read_text(encoding='utf-8')
for route in ["/bookmark','GET',[$n,'bookmarkState']", "/bookmark','PUT',[$n,'bookmark']", "/bookmark','DELETE',[$n,'unbookmark']", "self::r('/me/saved-narratives','GET',[$me,'savedNarratives'])"]:
    assert route in (root / 'Rest/Routes.php').read_text(encoding='utf-8'), route
assert 'INSERT IGNORE INTO' in (root / 'Domain/ProfileExtras.php').read_text(encoding='utf-8')
print('saved narratives contract ok')

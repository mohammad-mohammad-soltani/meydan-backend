#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src'
routes = (root / 'Rest/Routes.php').read_text(encoding='utf-8')
controller = (root / 'Rest/AdminUserController.php').read_text(encoding='utf-8')
service = (root / 'Domain/UserDeletionService.php').read_text(encoding='utf-8')
square = (root / 'Domain/SquareDeletionService.php').read_text(encoding='utf-8')
uploads = (root / 'Uploads/ChunkedUploadService.php').read_text(encoding='utf-8')

assert "self::r('/admin/users/(?P<id>\\d+)','DELETE',[$adminUser,'delete'])" in routes
assert 'public function delete(WP_REST_Request $r)' in controller
assert 'UserDeletionService::deletePermanently($id, get_current_user_id())' in controller

# Safety invariant: a user must be disabled before a hard delete can begin.
assert 'UserAccess::disabled($userId)' in service
assert "'user_must_be_disabled'" in service
assert 'حساب فعلی را نمی‌توان حذف دائمی کرد' in service

# Account-owned actor data and external sync state must be purged.
for token in [
    "Channels::store($userId, 'eitaa', '')",
    "Channels::store($userId, 'bale', '')",
    'NarrativeCleanup::deleteUserNarratives($userId)',
    "'push_subscriptions'",
    "'meydan_idempotency'",
    "'meydan_auth_challenges'",
    "'meydan_chat_participants'",
    "'meydan_chat_messages'",
    "'meydan_chat_reactions'",
    "'meydan_speaker_requests'",
    "'meydan_interactions'",
    "'meydan_actor_affinity'",
    "'meydan_notifications'",
    "'meydan_events'",
    "'meydan_speaker_user_map'",
    "'meydan_speaker_user_id'",
]:
    assert token in service, token

# Square deletion is reused, but it must not recursively delete the same owner.
assert 'SquareDeletionService::deletePermanently($squareId, false)' in service
assert 'bool $deleteOwner = true' in square
assert 'if ($deleteOwner && $ownerLinked && $owner)' in square

# Physical upload cleanup is part of account deletion, not only DB cleanup.
assert 'purgeForUser(int $userId)' in uploads
assert '$this->cleanup($uploadId)' in uploads
assert '(new ChunkedUploadService())->purgeForUser($userId)' in service

# Remaining staff-authored managed posts are preserved rather than destroyed.
assert 'wp_delete_user($userId, $reassignUserId)' in service

print('Permanent user deletion contract OK.')

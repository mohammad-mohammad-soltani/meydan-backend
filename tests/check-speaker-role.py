#!/usr/bin/env python3
"""Guard the speaker model: a user role, never a separate entity."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
src = root / "wp-content/plugins/meydan-core/src"

registrations = (src / "Domain/Registrations.php").read_text(encoding="utf-8")
service = (src / "Domain/SpeakerService.php").read_text(encoding="utf-8")
actor = (src / "Support/Actor.php").read_text(encoding="utf-8")
serializer = (src / "Support/Serializer.php").read_text(encoding="utf-8")

assert "add_role('meydan_speaker'" in registrations, "the meydan_speaker role must be registered"
assert "postType('meydan_speaker'" not in registrations, "meydan_speaker must not be registered as a post type"
assert (
    "SPEAKER_CATEGORY_TAXONOMY, ['meydan_speaker']" not in registrations
), "speaker categories must not be a taxonomy attached to a speaker post"
assert "public const ROLE = 'meydan_speaker';" in service, "SpeakerService must own the role name"
assert "meydan_account_type" in actor and "'speaker'" in actor, "Actor must resolve the speaker account type"
assert "Actor::isSpeaker" in serializer, "Serializer::speaker must be role-backed"

print("speaker role model ok")

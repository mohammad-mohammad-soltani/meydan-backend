#!/usr/bin/env python3
from pathlib import Path

source = (Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src/Timeline/CandidateGenerator.php').read_text(encoding='utf-8')
interaction = source.split('private function interactionGraph', 1)[1].split('private function local', 1)[0]

assert 'SELECT DISTINCT p.ID' in interaction
assert 'meydan_author_actor_type' in interaction
assert 'meydan_author_actor_id' in interaction
assert 'LIMIT %d' in interaction
assert "'meta_query'" not in interaction
print('Authenticated timeline candidate query contract OK.')

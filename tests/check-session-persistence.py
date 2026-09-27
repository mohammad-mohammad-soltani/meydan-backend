#!/usr/bin/env python3
from pathlib import Path

session = (Path(__file__).parents[1] / 'wp-content/plugins/meydan-core/src/Auth/SessionService.php').read_text(encoding='utf-8')

assert 'public const REFRESH_TTL = YEAR_IN_SECONDS;' in session
refresh = session.split('public function refresh', 1)[1].split('public function logoutCurrent', 1)[0]
assert '$newRefresh' not in refresh
assert "'refresh_token_hash' => Crypto::hash($newRefresh)" not in refresh
assert 'self::setRefreshCookie($refreshToken, $persistentDevice);' in refresh
print('Session persistence contract OK.')

#!/usr/bin/env bash
set -euo pipefail
BASE="${BASE_URL:-http://localhost:8080/wp-json/meydan/v1}"
OTP="${MEYDAN_DEV_OTP_CODE:-482193}"
PHONE="${TEST_PHONE:-+989120000001}"
json() { python3 -c "import json,sys; print(json.load(sys.stdin)$1)"; }
echo '[1/8] config'; curl -fsS "$BASE/config" >/dev/null
echo '[2/8] guest timeline'; curl -fsS "$BASE/timeline" >/dev/null
echo '[3/8] guest like must be 401'; code=$(curl -sS -o /tmp/meydan-guest.json -w '%{http_code}' -X PUT "$BASE/narratives/999999/like"); test "$code" = 401
echo '[4/8] request OTP'; challenge=$(curl -fsS -H 'Content-Type: application/json' -d "{\"phone\":\"$PHONE\"}" "$BASE/auth/otp/request" | json "['data']['challenge_id']")
echo '[5/8] verify OTP'; verify=$(curl -fsS -H 'Content-Type: application/json' -d "{\"challenge_id\":\"$challenge\",\"code\":\"$OTP\"}" "$BASE/auth/otp/verify")
auth=$(printf '%s' "$verify" | json "['data'].get('authenticated',False)")
if [ "$auth" = "False" ]; then
  reg=$(printf '%s' "$verify" | json "['data']['registration_token']")
  echo '[6/8] register user'
  access=$(curl -fsS -H 'Content-Type: application/json' -d "{\"registration_token\":\"$reg\",\"full_name\":\"Meydan CI User\",\"province_id\":1,\"city_id\":1}" "$BASE/auth/register/user" | json "['data']['access_token']")
else
  echo '[6/8] existing user session'
  access=$(printf '%s' "$verify" | json "['data']['access_token']")
fi
echo '[7/8] /me'; curl -fsS -H "Authorization: Bearer $access" "$BASE/me" >/dev/null
echo '[8/8] create narrative + timeline'; curl -fsS -H "Authorization: Bearer $access" -H 'Content-Type: application/json' -d '{"body":"CI smoke narrative"}' "$BASE/narratives" >/dev/null; curl -fsS "$BASE/timeline" >/dev/null
echo 'Meydan smoke tests passed.'

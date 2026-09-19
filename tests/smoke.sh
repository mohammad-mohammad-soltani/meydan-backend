#!/usr/bin/env bash
set -euo pipefail
BASE="${BASE_URL:-http://localhost:8080/wp-json/meydan/v1}"
OTP="${MEYDAN_DEV_OTP_CODE:-482193}"
PHONE="${TEST_PHONE:-+989120000001}"
json() { python3 -c "import json,sys; print(json.load(sys.stdin)$1)"; }
echo '[1/8] config'; curl -fsS "$BASE/config" >/dev/null
echo '[2/9] guest timeline keeps the public V1-compatible envelope'; normal=$(curl -fsS "$BASE/timeline?mode=for_you&limit=1"); printf '%s' "$normal" | python3 -c 'import json,sys; body=json.load(sys.stdin); assert isinstance(body.get("data"),list); assert "next_cursor" in body.get("meta",{}); assert "count" in body.get("meta",{}); assert all(not any(key in item for key in ("score","base_score","freshness_multiplier","source_names")) for item in body["data"])'
echo '[3/9] guest like must be 401'; code=$(curl -sS -o /tmp/meydan-guest.json -w '%{http_code}' -X PUT "$BASE/narratives/999999/like"); test "$code" = 401
echo '[4/9] request OTP'; challenge=$(curl -fsS -H 'Content-Type: application/json' -d "{\"phone\":\"$PHONE\"}" "$BASE/auth/otp/request" | json "['data']['challenge_id']")
echo '[5/9] verify OTP'; verify=$(curl -fsS -H 'Content-Type: application/json' -d "{\"challenge_id\":\"$challenge\",\"code\":\"$OTP\"}" "$BASE/auth/otp/verify")
auth=$(printf '%s' "$verify" | json "['data'].get('authenticated',False)")
if [ "$auth" = "False" ]; then
  reg=$(printf '%s' "$verify" | json "['data']['registration_token']")
  echo '[6/9] register user'
  access=$(curl -fsS -H 'Content-Type: application/json' -d "{\"registration_token\":\"$reg\",\"full_name\":\"Meydan CI User\",\"province_id\":1,\"city_id\":1}" "$BASE/auth/register/user" | json "['data']['access_token']")
else
  echo '[6/9] existing user session'
  access=$(printf '%s' "$verify" | json "['data']['access_token']")
fi
echo '[7/9] /me'; curl -fsS -H "Authorization: Bearer $access" "$BASE/me" >/dev/null
echo '[8/9] create narrative + timeline'; curl -fsS -H "Authorization: Bearer $access" -H 'Content-Type: application/json' -d '{"body":"CI smoke narrative"}' "$BASE/narratives" >/dev/null; curl -fsS "$BASE/timeline" >/dev/null
echo 'Meydan smoke tests passed.'

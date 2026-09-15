#!/usr/bin/env bash
set -euo pipefail

SITE="${SITE_URL:-http://localhost:8080}"
headers=$(mktemp)
trap 'rm -f "$headers"' EXIT

body=$(curl -fsS -D "$headers" "$SITE/")
grep -Eqi '^content-type:[[:space:]]*application/json([[:space:]]*;|$)' "$headers"
printf '%s' "$body" | python3 -c 'import json,sys; data=json.load(sys.stdin); assert data == {"message": "Enter the endpoint."}, data'

echo 'root JSON response ok'

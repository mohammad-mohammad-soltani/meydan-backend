#!/usr/bin/env python3
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        print(f"FAIL: {message}", file=sys.stderr)
        raise SystemExit(1)


session = read("wp-content/plugins/meydan-core/src/Auth/SessionService.php")
serializer = read("wp-content/plugins/meydan-core/src/Support/Serializer.php")
timeline = read("wp-content/plugins/meydan-core/src/Rest/TimelineController.php")

require(
    re.search(r"public const ACCESS_TTL\s*=\s*30\s*\*\s*DAY_IN_SECONDS\s*;", session) is not None,
    "access token must remain valid for 30 days",
)
require(
    re.search(r"public const REFRESH_TTL\s*=\s*30\s*\*\s*DAY_IN_SECONDS\s*;", session) is not None,
    "refresh token must remain valid for 30 days",
)
require(
    "'verified' => (bool) get_post_meta($id, 'meydan_verified', true)" in serializer,
    "square serializer must expose the real meydan_verified value",
)
require(
    "TimelineEligibility::filterNarrativeIds" in timeline,
    "all timeline paths must pass narrative ids through the global eligibility filter",
)

helper = read("wp-content/plugins/meydan-core/src/Timeline/TimelineEligibility.php")
require(
    "meydan_author_actor_type" in helper and "meydan_author_actor_id" in helper,
    "timeline eligibility must inspect narrative actor metadata",
)
require(
    "meydan_verified" in helper and "!== 1" in helper,
    "timeline eligibility must reject square narratives unless verified is exactly 1",
)

print("auth/timeline contract: OK")

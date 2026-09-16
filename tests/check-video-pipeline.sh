#!/usr/bin/env bash
# Functional video-pipeline check inside the packaged WordPress image.
set -euo pipefail
cd "$(dirname "$0")/.."

FIXTURE=/tmp/meydan-video-fixture.mp4

echo "ffmpeg in image:"
docker compose exec -T wordpress ffmpeg -hide_banner -version | head -n 1

# Default ffmpeg MP4 output keeps moov at EOF, which is exactly the broken shape.
# The clip must exceed 64 KB or moov at EOF would still fall inside the window.
docker compose exec -T wordpress sh -euc \
  "ffmpeg -y -hide_banner -loglevel error -f lavfi -i testsrc2=size=1280x720:rate=30 -t 6 -c:v mpeg4 -q:v 3 -pix_fmt yuv420p '${FIXTURE}'"
docker compose exec -T wordpress sh -euc "wc -c '${FIXTURE}'"

docker compose cp tests/video-pipeline-check.php wordpress:/tmp/video-pipeline-check.php
docker compose exec -T -e MEYDAN_VIDEO_FIXTURE="${FIXTURE}" wordpress php /tmp/video-pipeline-check.php

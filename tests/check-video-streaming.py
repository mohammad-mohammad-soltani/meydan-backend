#!/usr/bin/env python3
"""Static contract checks for faststart video, upload caching and posters."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
src = root / "wp-content/plugins/meydan-core/src"
plugin_dir = root / "wp-content/plugins/meydan-core"

def text(path: Path) -> str:
    return path.read_text(encoding="utf-8") if path.exists() else ""

processor = text(src / "Uploads/VideoProcessor.php")
uploads = text(src / "Uploads/ChunkedUploadService.php")
cache = text(src / "Uploads/UploadCache.php")
serializer = text(src / "Support/Serializer.php")
chat = text(src / "Support/ChatRepository.php")
cli = text(src / "Support/CliCommand.php")
plugin = text(src / "Plugin.php")
compose = text(root / "docker-compose.yml")
wp_image = text(root / "docker/wordpress/Dockerfile")
cli_image = text(root / "docker/wpcli/Dockerfile")
apache_conf = text(root / "docker/wordpress/meydan-uploads.conf")

# 1) faststart remux, exactly the `-c copy -movflags +faststart` shape.
assert processor, "VideoProcessor must exist"
assert "'-c', 'copy'" in processor, "remux must stream-copy without re-encoding"
assert "'-movflags', '+faststart'" in processor, "remux must move moov to the front"
assert "rename($tmp, $file)" in processor, "remux must replace the original atomically"
assert "MOOV_WINDOW = 65536" in processor, "faststart detection must use the 64 KB window"
assert "isFaststart" in processor and "fseek" in processor, "moov position must be read from the atom table"

# 2) posters and duration/width/height.
assert "scale='min(" in processor and "iw)':-2" in processor, "poster must be capped on the long edge"
assert "POSTER_MAX_EDGE = 720" in processor, "poster long edge must be 720 px"
assert "ffprobe" in processor and "duration" in processor, "duration must come from ffprobe"
assert "poster_url" in processor and "thumbnail_url" in processor, "both still keys must be emitted"

# 3) the upload completion path runs the pass and never rejects on failure.
assert "VideoProcessor::processAttachment" in uploads, "video processing must run on upload completion"
assert "catch (\\Throwable" in uploads or "catch(\\Throwable" in uploads, "video failure must not fail the upload"
assert "$payload+=$video" in uploads, "upload response must carry the poster/duration fields"

# 4) the API payload exposes the fields on every attachment surface.
for key in ["poster_url", "thumbnail_url", "duration", "width", "height"]:
    assert f"'{key}'" in serializer, f"Serializer::attachment must expose {key}"
assert "VideoProcessor::describe" in serializer
assert "enrichAttachment" in chat, "chat attachments must be enriched too"
assert "VideoProcessor::describe" in chat

# 5) immutable caching for uploads.
assert "public, max-age=31536000, immutable" in cache, "uploads cache directive must be immutable and one year"
assert "UploadCache::ensure" in plugin, "the cache rule must be installed on boot"
assert "no-gzip" in cache and "no-gzip" in apache_conf, "media must not be compressed"
assert "Cache-Control" in apache_conf and "public, max-age=31536000, immutable" in apache_conf
assert 'Header unset Accept-Ranges' not in apache_conf, "range handling must stay with the static file handler"
assert '<Directory "/var/www/html/wp-content/uploads">' in apache_conf, "the rule must be scoped to uploads"

# 6) ffmpeg must exist on the upload host and the CLI pass must report counts.
assert "apt-get install -y --no-install-recommends ffmpeg" in wp_image, "web image must install ffmpeg"
assert "a2enmod headers" in wp_image, "headers module is required for the immutable directive"
assert "apk add --no-cache ffmpeg" in cli_image, "the one-off WP-CLI pass needs ffmpeg too"
assert "video_faststart" in cli, "one-off pass must exist as `wp meydan video-faststart`"
assert "already had moov in the first 64 KB" in cli, "the pass must report already-faststart files"
assert "RecursiveDirectoryIterator" in cli, "the pass must walk the uploads tree"

# 7) compose builds the patched images instead of the stock ones.
assert "context: ./docker/wordpress" in compose, "compose must build the patched WordPress image"
assert "context: ./docker/wpcli" in compose, "compose must build the patched WP-CLI image"
assert "image: wordpress:7.1.0-php8.4-apache" not in compose, "stock web image must be replaced"

# 8) the whole pipeline must live in the plugin so it ships with the code.
assert (plugin_dir / "src/Uploads/VideoProcessor.php").exists()
assert (plugin_dir / "src/Uploads/UploadCache.php").exists()

print("video streaming contract ok")

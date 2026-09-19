from pathlib import Path

root = Path(__file__).resolve().parents[1]
plugin = root / "wp-content/plugins/meydan-core"

required = [
    plugin / "src/Storage/StorageInterface.php",
    plugin / "src/Storage/LocalStorage.php",
    plugin / "src/Storage/S3Storage.php",
    plugin / "src/Storage/StorageFactory.php",
    plugin / "src/Storage/MediaPipeline.php",
    plugin / "src/Storage/WordPressMediaHooks.php",
    plugin / "src/Storage/MediaLogger.php",
    plugin / "src/Storage/AttachmentStorage.php",
]
for path in required:
    assert path.exists(), f"missing {path.relative_to(root)}"

interface = required[0].read_text(encoding="utf-8")
for method in ["put(", "delete(", "exists(", "url(", "head("]:
    assert method in interface, f"StorageInterface missing {method}"

s3 = required[2].read_text(encoding="utf-8")
assert "MultipartUploader" in s3
assert "AbortMultipartUpload" in s3 or "abortMultipartUpload" in s3
assert "SourceFile" in s3
assert s3.count("'ACL'") >= 2
assert "putObjectAcl" in s3 and "listObjectsV2" in s3
assert "file_get_contents" not in s3

hooks = required[5].read_text(encoding="utf-8")
chunked = (plugin / "src/Uploads/ChunkedUploadService.php").read_text(encoding="utf-8")
for hook in [
    "add_attachment",
    "wp_generate_attachment_metadata",
    "wp_get_attachment_url",
    "wp_calculate_image_srcset",
    "pre_delete_attachment",
    "delete_attachment",
]:
    assert hook in hooks, f"missing WordPress hook {hook}"
assert "upload_dir" not in hooks, "WordPress upload_dir must not be globally overridden"
for event in ["upload_started", "staging_created", "validation_failed", "s3_upload_started", "s3_upload_completed",
              "head_verification_failed", "rollback_started", "rollback_completed", "attachment_created",
              "s3_delete_started", "s3_delete_failed", "s3_delete_completed"]:
    assert event in hooks or event in (plugin / "src/Uploads/ChunkedUploadService.php").read_text(encoding="utf-8"), f"missing lifecycle event {event}"

for relative in ["Uploads/VideoProcessor.php", "Support/Serializer.php", "Support/ChatRepository.php", "Support/CliCommand.php"]:
    source = (plugin / "src" / relative).read_text(encoding="utf-8")
    assert "AttachmentStorage::localPath" in source, f"{relative} must not treat S3 _wp_attached_file as local"
assert "AttachmentStorage::attachmentIdFromUrl" in (plugin / "src/Support/ChatRepository.php").read_text(encoding="utf-8")
assert "media_make_public" in (plugin / "src/Support/CliCommand.php").read_text(encoding="utf-8")

assert "MediaPipeline" in chunked
assert "StorageFactory" in chunked
assert chunked.index("$canonicalMime =") < chunked.index("validateDeclared("), "canonical MIME must be resolved before pipeline validation"
assert "VideoProcessor::describe" in chunked, "completed video payload must be resolved after S3 metadata is stored"

bootstrap = (plugin / "meydan-core.php").read_text(encoding="utf-8")
assert "vendor/autoload.php" in bootstrap

gitignore = (root / ".gitignore").read_text(encoding="utf-8")
assert "vendor/" in gitignore

print("media storage contract ok")

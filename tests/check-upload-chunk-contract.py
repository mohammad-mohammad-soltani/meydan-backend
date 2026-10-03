"""Chunk index bound must use the size the client really sends, and finishing must be retry-safe."""
import pathlib, re
src = pathlib.Path(__file__).resolve().parents[1].joinpath("wp-content/plugins/meydan-core/src/Uploads/ChunkedUploadService.php").read_text(encoding="utf-8")
assert "WIRE_CHUNK_SIZE = 4194304" in src
assert re.search(r"ceil\(\(\(int\) \$row->size\) / self::WIRE_CHUNK_SIZE\)", src), "index bound must use the 4 MB wire chunk"
assert "'chunk_size' => self::WIRE_CHUNK_SIZE" in src
assert "completedPayload(" in src and "upload_processing" in src, "complete() must be idempotent"
assert "set_time_limit(600)" in src
print("ok")

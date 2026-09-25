from pathlib import Path


controller = Path(
    "wp-content/plugins/meydan-core/src/Rest/AdminSquareController.php"
).read_text(encoding="utf-8")

create_method = controller.split("public function create", 1)[1].split(
    "public function get", 1
)[0]

assert "adminSquare(" not in create_method, (
    "creating a square must return the known creation result directly; "
    "hydrating the square runs unrelated narrative-count and schedule queries "
    "after the write has already succeeded"
)
assert "result['square_id']" in create_method
assert "result['user_id']" in create_method
assert "result['name']" in create_method

print("admin square create response contract ok")

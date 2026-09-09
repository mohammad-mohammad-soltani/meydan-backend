import json
import re
from pathlib import Path

routes = Path('wp-content/plugins/meydan-core/src/Rest/Routes.php').read_text()
items = []
for method, path in re.findall(r"self::r\('([^']+)'\s*,\s*'([A-Z]+)'", routes):
    method, path = path, method
    params = re.findall(r'\(\?P<([a-z_]+)>[^)]+\)', path)
    url_path = re.sub(r'\(\?P<([^>]+)>[^)]+\)', r':\1', path)
    items.append({'name': f'{method} {path}', 'request': {'method': method, 'header': [{'key': 'Authorization', 'value': 'Bearer {{access_token}}', 'type': 'text', 'disabled': True}], 'url': {'raw': '{{base_url}}' + url_path, 'host': ['{{base_url}}'], 'path': url_path.strip('/').split('/') if url_path.strip('/') else []}}})

collection = {
    'info': {'name': 'Meydan API v1', '_postman_id': 'meydan-v1-local', 'schema': 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json', 'description': 'Generated from the registered REST routes. Set base_url to the local WordPress API root.'},
    'variable': [{'key': 'base_url', 'value': 'http://localhost:8080/wp-json/meydan/v1'}, {'key': 'access_token', 'value': ''}],
    'item': items,
}
Path('postman').mkdir(exist_ok=True)
Path('postman/meydan-v1.postman_collection.json').write_text(json.dumps(collection, ensure_ascii=False, indent=2) + '\n')
print(f'generated {len(items)} requests')

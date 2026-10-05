#!/bin/sh
set -eu
cd /var/www/html

until [ -f wp-load.php ]; do
  echo "Waiting for WordPress files..."
  sleep 2
done

attempt=0
until php -r '
$raw = getenv("WORDPRESS_DB_HOST") ?: "db:3306";
$host = $raw;
$port = 3306;
if (strpos($raw, ":") !== false) {
    [$host, $rawPort] = explode(":", $raw, 2);
    $port = (int) $rawPort ?: 3306;
}
$db = @mysqli_connect(
    $host,
    getenv("WORDPRESS_DB_USER") ?: "",
    getenv("WORDPRESS_DB_PASSWORD") ?: "",
    getenv("WORDPRESS_DB_NAME") ?: "",
    $port
);
if (!$db) exit(1);
mysqli_close($db);
' 2>/dev/null; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 60 ]; then
    echo "Database did not become reachable within 120 seconds." >&2
    exit 1
  fi
  echo "Waiting for database..."
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
  wp core install \
    --url="${WP_URL}" \
    --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
fi

wp option update permalink_structure '/%postname%/'
wp rewrite flush --hard || true
wp plugin activate meydan-core

# Persistent object cache (Redis). Best effort: a failure here leaves the site working without it.
if [ -n "${REDIS_HOST:-}" ]; then
  wp plugin is-installed redis-cache >/dev/null 2>&1 || wp plugin install redis-cache --activate || true
  wp plugin is-active redis-cache >/dev/null 2>&1 || wp plugin activate redis-cache || true
  wp redis enable || true
fi
wp meydan migrate
wp meydan seed
wp meydan status

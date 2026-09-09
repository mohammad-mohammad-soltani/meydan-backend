#!/bin/sh
set -eu
cd /var/www/html

until [ -f wp-load.php ]; do
  echo "Waiting for WordPress files..."
  sleep 2
done

until wp db check --quiet 2>/dev/null; do
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
wp meydan migrate
wp meydan status

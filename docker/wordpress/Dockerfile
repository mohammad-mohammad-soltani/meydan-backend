# Meydan WordPress image.
#
# Two changes over the stock `wordpress:*-apache` image:
#   1. ffmpeg/ffprobe, used at upload time to remux videos as MP4 "faststart"
#      and to extract a poster frame plus real duration/dimensions.
#   2. mod_headers, so `/wp-content/uploads/**` can advertise an immutable
#      Cache-Control (the stock image only enables expires/rewrite/deflate).
FROM wordpress:7.1.0-php8.4-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ffmpeg; \
    a2enmod headers expires; \
    rm -rf /var/lib/apt/lists/*

# PhpRedis (faster Redis client) built from the GitHub source tarball. It is optional: if the build fails or
# GitHub is unreachable the image is still produced and the redis-cache plugin falls back to its bundled Predis
# client. The default PHP serializer is kept on purpose: the wpcli/cron containers have no phpredis/igbinary
# and must read the same cache. (ghcr.io is deliberately not used: it is unreachable from some hosts.)
ARG PHPREDIS_VERSION=6.1.0
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS curl ca-certificates; \
    ( curl -fsSL --retry 2 --max-time 90 "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" -o /tmp/phpredis.tgz \
      && mkdir /tmp/phpredis && tar -xzf /tmp/phpredis.tgz -C /tmp/phpredis --strip-components=1 \
      && cd /tmp/phpredis && phpize && ./configure && make -j"$(nproc)" && make install \
      && docker-php-ext-enable redis \
    ) || echo "phpredis was not built; the redis-cache plugin will use Predis"; \
    cd /; rm -rf /tmp/phpredis /tmp/phpredis.tgz; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

COPY meydan-uploads.conf /etc/apache2/conf-available/meydan-uploads.conf
RUN a2enconf meydan-uploads

# Prefork sizing: ~60 MB per busy worker. Default 30 workers (~2 GB worst case); raise MaxRequestWorkers with the RAM given to PHP.
COPY meydan-mpm.conf /etc/apache2/conf-available/meydan-mpm.conf
RUN a2enconf meydan-mpm

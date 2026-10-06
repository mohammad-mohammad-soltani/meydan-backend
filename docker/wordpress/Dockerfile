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

# PhpRedis for the persistent object cache (handled by the installer, no manual pecl). The default PHP
# serializer is kept on purpose: the wpcli/cron containers have no igbinary and must read the same cache.
COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions redis \
    && php -m | grep -E '^redis$'

COPY meydan-uploads.conf /etc/apache2/conf-available/meydan-uploads.conf
RUN a2enconf meydan-uploads

# Prefork sizing: ~60 MB per busy worker. Default 30 workers (~2 GB worst case); raise MaxRequestWorkers with the RAM given to PHP.
COPY meydan-mpm.conf /etc/apache2/conf-available/meydan-mpm.conf
RUN a2enconf meydan-mpm

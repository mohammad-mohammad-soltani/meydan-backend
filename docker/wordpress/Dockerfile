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
    apt-get install -y --no-install-recommends ffmpeg $PHPIZE_DEPS; \
    pecl install igbinary redis; \
    docker-php-ext-enable igbinary redis; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    a2enmod headers expires; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

COPY meydan-uploads.conf /etc/apache2/conf-available/meydan-uploads.conf
RUN a2enconf meydan-uploads

# Prefork sizing: ~60 MB per busy worker, so 80 workers ~ 5 GB worst case. Tune with the RAM given to PHP.
COPY meydan-mpm.conf /etc/apache2/conf-available/meydan-mpm.conf
RUN a2enconf meydan-mpm

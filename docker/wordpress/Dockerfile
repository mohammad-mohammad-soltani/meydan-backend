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

COPY meydan-uploads.conf /etc/apache2/conf-available/meydan-uploads.conf
RUN a2enconf meydan-uploads

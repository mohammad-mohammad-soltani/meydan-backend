# Meydan WP-CLI image.
#
# The one-off video pass (`wp meydan video-faststart`) remuxes existing uploads
# and writes posters, so WP-CLI needs the same ffmpeg/ffprobe the web image has.
FROM wordpress:cli-2.12.0-php8.4

USER root
RUN apk add --no-cache ffmpeg
USER www-data

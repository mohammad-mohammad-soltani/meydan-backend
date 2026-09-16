<?php

declare(strict_types=1);

namespace Meydan\Core\Uploads;

/**
 * Long-lived caching for `/wp-content/uploads/**`.
 *
 * Upload names are unique and their contents never change in place (a "new"
 * upload always gets a new filename), so the whole tree is immutable and can be
 * cached for a year. A `.htaccess` is dropped into the uploads directory so the
 * rule travels with the site even when the web server config is not rebuilt;
 * the packaged image additionally sets the header in Apache directly.
 */
final class UploadCache
{
    public const DIRECTIVE = 'public, max-age=31536000, immutable';

    private const BEGIN = '# BEGIN Meydan Upload Cache';
    private const END = '# END Meydan Upload Cache';

    /** Media that is already compressed and must never be re-compressed. */
    private const INCOMPRESSIBLE = 'mp4|m4v|mov|webm|ogv|mp3|m4a|aac|ogg|oga|opus|flac|wav|jpg|jpeg|png|gif|webp|avif|zip|gz|pdf';

    /** Write the managed cache block when it is missing or out of date. */
    public static function ensure(): void
    {
        try {
            $uploads = wp_get_upload_dir();
            $dir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
            if ($dir === '' || !is_dir($dir)) {
                return;
            }

            $file = trailingslashit($dir) . '.htaccess';
            $block = self::block();
            $existing = is_file($file) ? (string) @file_get_contents($file) : '';
            if ($existing !== '' && str_contains($existing, $block)) {
                return;
            }

            $kept = trim((string) preg_replace(
                '/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\n?/s',
                '',
                $existing
            ));
            $content = ($kept === '' ? '' : $kept . "\n\n") . $block . "\n";

            if ($content !== $existing) {
                @file_put_contents($file, $content, LOCK_EX);
            }
        } catch (\Throwable $e) {
            error_log('Meydan upload cache header update failed: ' . $e->getMessage());
        }
    }

    /** The managed block, including its markers. */
    public static function block(): string
    {
        return implode("\n", [
            self::BEGIN,
            '# Managed by meydan-core. Uploads are content-addressed by filename, so they never change.',
            '<IfModule mod_headers.c>',
            "\tHeader set Cache-Control \"" . self::DIRECTIVE . '"',
            '</IfModule>',
            '<IfModule mod_expires.c>',
            "\tExpiresActive On",
            "\tExpiresByType video/mp4 \"access plus 1 year\"",
            "\tExpiresByType video/webm \"access plus 1 year\"",
            "\tExpiresByType audio/mpeg \"access plus 1 year\"",
            "\tExpiresByType image/jpeg \"access plus 1 year\"",
            "\tExpiresByType image/png \"access plus 1 year\"",
            "\tExpiresByType image/webp \"access plus 1 year\"",
            '</IfModule>',
            '<IfModule mod_deflate.c>',
            "\t# Range requests and already-compressed media stay untouched.",
            "\tSetEnvIfNoCase Request_URI \\.(?:" . self::INCOMPRESSIBLE . ')$ no-gzip dont-vary',
            '</IfModule>',
            self::END,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Bale;

use Throwable;

/**
 * Reports project errors to Bale (requirement 2).
 *
 * Coverage:
 *  - Uncaught exceptions and PHP fatals, captured on shutdown. A shutdown
 *    handler is the only reliable way to catch E_ERROR, because a fatal never
 *    reaches a normal exception handler.
 *  - Warnings/notices, only when WP_DEBUG is on so a production site is not
 *    flooded by notices that do not indicate a real incident.
 *  - Explicit application errors reported via report().
 *
 * All sends are throttled and fingerprint-deduplicated.
 */
final class ErrorReporter
{
    private static bool $registered = false;

    /** Guards against a failing reporter triggering itself. */
    private static bool $reporting = false;

    public static function register(): void
    {
        if (self::$registered || !Settings::getBool('error_reporting')) {
            return;
        }
        self::$registered = true;

        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Report an application-level failure (a WP_Error from an integration, a
     * caught exception, a failed remote call).
     */
    public static function report(string $context, string $message, array $extra = []): void
    {
        if (self::$reporting) {
            return;
        }
        self::$reporting = true;
        try {
            $lines = [
                '🚨 <b>خطای پروژه میدان</b>',
                '<b>بخش:</b> ' . Notifier::esc($context),
                '<b>پیام:</b> ' . Notifier::esc(Notifier::trim($message)),
            ];
            if ($extra) {
                $lines[] = '<b>جزئیات:</b> <code>' . Notifier::esc(Notifier::trim(wp_json_encode($extra, JSON_UNESCAPED_UNICODE) ?: '', 700)) . '</code>';
            }
            $lines[] = Settings::getBool('include_site_label')
                ? '<i>' . Notifier::esc(self::siteLabel()) . ' — ' . Notifier::esc(gmdate('Y-m-d H:i:s') . ' UTC') . '</i>'
                : '<i>' . Notifier::esc(gmdate('Y-m-d H:i:s') . ' UTC') . '</i>';

            Notifier::sendThrottled($context . '|' . $message, implode("\n", $lines));
        } finally {
            self::$reporting = false;
        }
    }

    /** Report a WP_Error, which is the plugin's dominant error type. */
    public static function reportWpError(string $context, \WP_Error $error, array $extra = []): void
    {
        self::report($context, $error->get_error_message(), $extra + ['code' => $error->get_error_code()]);
    }

    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Which severities are worth an alert is an operator decision, not a
        // WP_DEBUG side effect: a production site may want warnings without
        // notices, and a staging site may want everything.
        $reportable = 0;
        if (Settings::getBool('report_fatals')) {
            $reportable |= E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        }
        if (Settings::getBool('report_warnings')) {
            $reportable |= E_WARNING | E_USER_WARNING;
        }
        if (Settings::getBool('report_notices')) {
            $reportable |= E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED;
        }

        if (($severity & $reportable) !== 0 && !self::$reporting && !self::isMutedPath($file)) {
            self::report('PHP ' . self::severityLabel($severity), $message, [
                'file' => self::relativePath($file),
                'line' => $line,
            ]);
        }

        // Return false so WordPress/PHP keep their own handling (error_log, WP_DEBUG_DISPLAY).
        return false;
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (($error['type'] & $fatal) === 0) {
            return;
        }
        if (!Settings::getBool('report_fatals')) {
            return;
        }
        if (self::$reporting || self::isMutedPath((string) $error['file'])) {
            return;
        }

        self::report('خطای مرگبار (Fatal)', (string) $error['message'], [
            'file' => self::relativePath((string) $error['file']),
            'line' => (int) $error['line'],
        ]);

        // A fatal aborts the request before wp_remote_post can finish, so the
        // HTTP call is flushed synchronously here rather than left pending.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }

    /**
     * This plugin's own Bale reporter must never report itself, and reported
     * Bale delivery failures would create an infinite loop.
     */
    private static function isMutedPath(string $file): bool
    {
        return $file !== '' && str_contains($file, '/Integrations/Bale/');
    }

    private static function severityLabel(int $severity): string
    {
        return match (true) {
            (bool) ($severity & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) => 'Fatal',
            (bool) ($severity & (E_WARNING | E_USER_WARNING)) => 'Warning',
            (bool) ($severity & E_PARSE) => 'Parse',
            (bool) ($severity & (E_NOTICE | E_USER_NOTICE)) => 'Notice',
            (bool) ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) => 'Deprecated',
            default => 'Error',
        };
    }

    private static function relativePath(string $file): string
    {
        if ($file === '') {
            return '';
        }
        $root = defined('ABSPATH') ? (string) ABSPATH : '';
        return $root !== '' && str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    private static function siteLabel(): string
    {
        $host = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);
        return $host !== '' ? $host : 'میدان';
    }

    /** Used by the settings page test button. */
    public static function testAlert(): \WP_Error|true
    {
        if (self::$reporting) {
            return new \WP_Error('bale_busy', 'ارسال گزارش در جریان است.');
        }
        self::$reporting = true;
        try {
            $result = Notifier::send("✅ <b>اتصال بله برقرار است.</b>\nگزارش خطاهای پروژه و اعلان میدان‌های در انتظار تأیید از این پس به این چت ارسال می‌شود.");
        } finally {
            self::$reporting = false;
        }
        return is_wp_error($result) ? $result : true;
    }

    /** Convenience wrapper so callers can report caught throwables directly. */
    public static function reportThrowable(string $context, Throwable $throwable): void
    {
        self::report($context, $throwable->getMessage(), [
            'type' => $throwable::class,
            'file' => self::relativePath($throwable->getFile()),
            'line' => $throwable->getLine(),
        ]);
    }
}

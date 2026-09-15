<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Bale;

use WP_Error;

/**
 * Sends operational alerts to the configured Bale chat.
 *
 * Every alert path funnels through here so a broken Bale connection can never
 * recurse into another alert, and so identical, repeating alerts are throttled
 * instead of flooding the chat. Both the per-minute ceiling and the de-dup
 * window are configurable in wp-admin.
 */
final class Notifier
{
    public static function send(string $text, ?array $replyMarkup = null): array|WP_Error
    {
        if (!Settings::isReady()) {
            return new WP_Error('bale_not_configured', 'ربات بله پیکربندی نشده است.');
        }
        return (new BaleClient())->sendMessage(Settings::getString('chat_id'), $text, $replyMarkup);
    }

    /**
     * Send unless an identical alert was already delivered inside the throttle
     * window. Returns true when the message was sent (or deliberately skipped).
     */
    public static function sendThrottled(string $key, string $text, ?int $window = null): bool
    {
        if (!Settings::isReady()) {
            return false;
        }
        if (!self::withinRateLimit()) {
            return false;
        }

        $window = $window ?? Settings::getInt('throttle_window');
        $transientKey = 'meydan_bale_alert_' . md5($key);
        if (get_transient($transientKey) !== false) {
            return false;
        }
        set_transient($transientKey, 1, max(30, $window));

        $result = self::send($text);
        return !is_wp_error($result);
    }

    private static function withinRateLimit(): bool
    {
        $bucket = 'meydan_bale_rate_' . gmdate('YmdHi');
        $count = (int) get_transient($bucket);
        if ($count >= Settings::getInt('rate_limit')) {
            return false;
        }
        set_transient($bucket, $count + 1, 120);
        return true;
    }

    /** HTML-escapes arbitrary text so it can be embedded in a parse_mode=HTML message. */
    public static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Truncates a long value to keep messages inside Bale's 4096-char limit. */
    public static function trim(string $value, int $limit = 900): string
    {
        $value = trim($value);
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) . '…' : $value;
    }
}

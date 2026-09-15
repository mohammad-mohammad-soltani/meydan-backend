<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Bale;

/**
 * Bale bot configuration.
 *
 * Every operational knob is stored in the `meydan_bale_settings` option and
 * edited in wp-admin (میدان → تنظیمات → پیکربندی ربات بله). Constants exist only
 * as optional deployment defaults for a value the panel has not set yet; the
 * panel always wins, so an operator never has to touch code or .env.
 */
final class Settings
{
    public const OPTION = 'meydan_bale_settings';

    /**
     * Defaults for every field. Adding a key here is all that is needed for it
     * to appear with a sane value in the panel.
     *
     * @var array<string,mixed>
     */
    private const DEFAULTS = [
        'enabled' => false,
        'token' => '',
        'chat_id' => '',
        'base_url' => 'https://tapi.bale.ai',
        'timeout' => 15,
        'error_reporting' => true,
        'report_fatals' => true,
        'report_warnings' => true,
        'report_notices' => false,
        'report_eitaa' => true,
        'pending_squares' => true,
        'rate_limit' => 20,
        'throttle_window' => 300,
        'include_site_label' => true,
        'webhook_secret' => '',
    ];

    /** Strings that must be trimmed, and their deployment constant fallbacks. */
    private const CONSTANT_FALLBACKS = [
        'token' => 'MEYDAN_BALE_BOT_TOKEN',
        'chat_id' => 'MEYDAN_BALE_CHAT_ID',
        'base_url' => 'MEYDAN_BALE_BASE_URL',
    ];

    private const BOOL_KEYS = [
        'enabled', 'error_reporting', 'report_fatals', 'report_warnings',
        'report_notices', 'report_eitaa', 'pending_squares', 'include_site_label',
    ];

    private const INT_KEYS = ['timeout', 'rate_limit', 'throttle_window'];

    /** @return array<string,mixed> */
    public static function get(): array
    {
        $stored = (array) get_option(self::OPTION, []);
        $settings = self::DEFAULTS;

        foreach (self::DEFAULTS as $key => $default) {
            $hasStored = array_key_exists($key, $stored);
            if (in_array($key, self::BOOL_KEYS, true)) {
                $settings[$key] = $hasStored ? (bool) $stored[$key] : (bool) $default;
            } elseif (in_array($key, self::INT_KEYS, true)) {
                $settings[$key] = $hasStored ? (int) $stored[$key] : (int) $default;
            } else {
                $settings[$key] = $hasStored ? trim((string) $stored[$key]) : (string) $default;
            }
        }

        // A deployment constant supplies a value only until the panel sets one.
        foreach (self::CONSTANT_FALLBACKS as $key => $constant) {
            if ($settings[$key] === '' && defined($constant)) {
                $settings[$key] = trim((string) constant($constant));
            }
        }

        // Unset means "on" once delivery details exist, which is the intent of
        // a freshly configured bot.
        if (!array_key_exists('enabled', $stored)) {
            $settings['enabled'] = $settings['token'] !== '' && $settings['chat_id'] !== '';
        }

        $settings['timeout'] = max(5, min(60, (int) $settings['timeout']));
        $settings['rate_limit'] = max(1, min(120, (int) $settings['rate_limit']));
        $settings['throttle_window'] = max(30, min(3600, (int) $settings['throttle_window']));

        return $settings;
    }

    public static function getString(string $key): string
    {
        return (string) (self::get()[$key] ?? '');
    }

    public static function getBool(string $key): bool
    {
        return (bool) (self::get()[$key] ?? false);
    }

    public static function getInt(string $key): int
    {
        return (int) (self::get()[$key] ?? 0);
    }

    /** A configured bot can actually deliver messages. */
    public static function isReady(): bool
    {
        $settings = self::get();
        return $settings['enabled'] && $settings['token'] !== '' && $settings['chat_id'] !== '';
    }

    /**
     * Secret token Bale echoes back in the X-Telegram-Bot-Api-Secret-Token
     * header, so the webhook can reject forged callback requests. Generated
     * once and then editable in the panel.
     */
    public static function webhookSecret(): string
    {
        $secret = self::getString('webhook_secret');
        if ($secret !== '') {
            return $secret;
        }
        $secret = wp_hash('meydan_bale_webhook');
        self::update(['webhook_secret' => $secret]);
        return $secret;
    }

    /** Regenerates the webhook secret, invalidating previously issued callbacks. */
    public static function rotateWebhookSecret(): string
    {
        $secret = wp_hash('meydan_bale_webhook_' . wp_generate_password(24, false, false));
        self::update(['webhook_secret' => $secret]);
        return $secret;
    }

    /** @param array<string,mixed> $values */
    public static function update(array $values): void
    {
        $stored = (array) get_option(self::OPTION, []);
        update_option(self::OPTION, array_merge($stored, $values), false);
    }

    /** Masked for display; the raw token is never rendered back into the form. */
    public static function maskedToken(): string
    {
        $token = self::getString('token');
        if ($token === '') {
            return '';
        }
        return str_repeat('•', 12) . substr($token, -4);
    }

    /**
     * Validates and normalises a raw admin submission.
     *
     * @param  array<string,mixed> $input
     * @return array{values:array<string,mixed>,errors:string[]}
     */
    public static function sanitize(array $input): array
    {
        $errors = [];
        $values = [];

        $values['enabled'] = !empty($input['enabled']);
        $values['error_reporting'] = !empty($input['error_reporting']);
        $values['report_fatals'] = !empty($input['report_fatals']);
        $values['report_warnings'] = !empty($input['report_warnings']);
        $values['report_notices'] = !empty($input['report_notices']);
        $values['report_eitaa'] = !empty($input['report_eitaa']);
        $values['pending_squares'] = !empty($input['pending_squares']);
        $values['include_site_label'] = !empty($input['include_site_label']);

        $values['timeout'] = max(5, min(60, (int) ($input['timeout'] ?? 15)));
        $values['rate_limit'] = max(1, min(120, (int) ($input['rate_limit'] ?? 20)));
        $values['throttle_window'] = max(30, min(3600, (int) ($input['throttle_window'] ?? 300)));

        // An empty token field means "keep the stored one", so the value is
        // never echoed back into the HTML.
        $token = trim((string) ($input['token'] ?? ''));
        if (!empty($input['clear_token'])) {
            $values['token'] = '';
        } elseif ($token !== '') {
            if (!preg_match('/^\d+:[A-Za-z0-9_\-]+$/', $token)) {
                $errors[] = 'قالب توکن ربات بله معتبر نیست؛ باید شبیه 123456789:ABCdef... باشد.';
            } else {
                $values['token'] = $token;
            }
        }

        $chatId = trim((string) ($input['chat_id'] ?? ''));
        if ($chatId !== '' && !preg_match('/^-?\d{4,}$/', $chatId)) {
            $errors[] = 'شناسه چت باید یک عدد باشد (برای گروه/کانال معمولاً با - شروع می‌شود).';
        } else {
            $values['chat_id'] = $chatId;
        }

        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        if ($baseUrl !== '' && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'آدرس پایه API بله معتبر نیست.';
        } else {
            $values['base_url'] = $baseUrl !== '' ? untrailingslashit($baseUrl) : 'https://tapi.bale.ai';
        }

        return ['values' => $values, 'errors' => $errors];
    }
}

<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Channels;

/**
 * Outer messaging channels attached to a square account.
 *
 * Both channels are stored as user meta on the square owner, because every
 * import pipeline resolves the source channel through the owner account:
 * `meydan_eitaa_channel` feeds the Eitaa sync, `meydan_bale_channel` stores the
 * Bale channel id.
 */
final class Channels
{
    public const EITAA_META = 'meydan_eitaa_channel';
    public const BALE_META = 'meydan_bale_channel';

    public const EITAA_FIELD = 'meydan_eitaa_channel'; // phpcs:ignore -- user meta key reused as the form field
    public const BALE_FIELD = 'meydan_bale_channel';  // phpcs:ignore
    public const EITAA_NONCE = 'meydan_eitaa_channel_nonce';
    public const BALE_NONCE = 'meydan_bale_channel_nonce';

    private const NONCE_ACTION = 'meydan_square_channels';

    /** @var array<int,array<string,string>> value already applied per account */
    private static array $saved = [];

    /**
     * Nonce field for the channel inputs. Rendered once per form (the page keeps
     * its own form nonce) and verified by `nonceIsValid()` before anything is
     * stored. Emitted as a bare input so a form never gets a second referer.
     */
    public static function formNonce(): string
    {
        return '<input type="hidden" name="' . esc_attr(self::BALE_NONCE) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '">';
    }

    public static function nonceIsValid(int $userId): bool
    {
        $posted = (string) ($_POST[self::BALE_NONCE] ?? '');
        if ($posted === '') {
            return false;
        }

        return (bool) wp_verify_nonce($posted, self::NONCE_ACTION);
    }

    /** @return array{field:string,label:string,placeholder:string,description:string} */
    public static function definition(string $kind): array
    {
        return $kind === 'bale'
            ? [
                'field' => self::BALE_FIELD,
                'label' => 'کانال بله',
                'placeholder' => '@channel یا شناسه عددی',
                'description' => 'شناسه عددی، @username یا لینک کانال بله. فعلاً فقط شناسه ذخیره می‌شود.',
            ]
            : [
                'field' => self::EITAA_FIELD,
                'label' => 'کانال ایتا',
                'placeholder' => '@channel یا شناسه عددی',
                'description' => 'شناسه عددی، @username یا لینک کانال ایتا. خالی بودن این فیلد همگام‌سازی این میدان را غیرفعال می‌کند.',
            ];
    }

    public static function metaKey(string $kind): string
    {
        return $kind === 'bale' ? self::BALE_META : self::EITAA_META;
    }

    public static function value(int $userId, string $kind): string
    {
        return trim((string) get_user_meta($userId, self::metaKey($kind), true));
    }

    /** The square channels that currently have a value, for review screens. */
    public static function summary(int $userId): array
    {
        return [
            'eitaa' => self::value($userId, 'eitaa'),
            'bale' => self::value($userId, 'bale'),
        ];
    }

    /**
     * Renders the channel inputs. `$context` is used for the field ids so the
     * same helper is safe on the profile, the square editor and the manual
     * square page.
     */
    public static function render(int $userId, string $context = 'profile', array $kinds = ['eitaa', 'bale']): void
    {
        foreach ($kinds as $kind) {
            $kind = $kind === 'bale' ? 'bale' : 'eitaa';
            $definition = self::definition($kind);
            $id = $context . '-' . $kind;
            echo '<p class="meydan-channel-field">';
            echo '<label for="' . esc_attr($id) . '"><strong>' . esc_html($definition['label']) . '</strong></label><br>';
            echo '<input id="' . esc_attr($id) . '" name="' . esc_attr($definition['field']) . '" class="regular-text" value="'
                . esc_attr(self::value($userId, $kind)) . '" placeholder="' . esc_attr($definition['placeholder']) . '">';
            echo '<br><span class="description">' . esc_html($definition['description']) . '</span></p>';
        }
    }

    /** A profile-screen form table row for a channel. */
    public static function renderRow(int $userId, string $kind): void
    {
        $kind = $kind === 'bale' ? 'bale' : 'eitaa';
        $definition = self::definition($kind);
        echo '<tr><th><label for="' . esc_attr($definition['field']) . '">' . esc_html($definition['label']) . '</label></th><td>';
        echo '<input id="' . esc_attr($definition['field']) . '" name="' . esc_attr($definition['field']) . '" class="regular-text" value="'
            . esc_attr(self::value($userId, $kind)) . '" placeholder="' . esc_attr($definition['placeholder']) . '">';
        echo '<p class="description">' . esc_html($definition['description']) . '</p></td></tr>';
    }

    /**
     * Saves whichever channel inputs the submitted page rendered.
     *
     * Two forms can legitimately post channels in one request (the built-in
     * profile form and an admin screen doing the user save itself), so the same
     * value is only applied once; a later post with a different value still
     * wins. A field that is absent is never cleared, so a partial form cannot
     * wipe a channel.
     */
    public static function save(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        foreach (['eitaa', 'bale'] as $kind) {
            $field = self::definition($kind)['field'];
            if (!array_key_exists($field, $_POST)) {
                continue;
            }
            $raw = (string) wp_unslash($_POST[$field]);
            if ((self::$saved[$userId][$kind] ?? null) === $raw) {
                continue;
            }
            self::$saved[$userId][$kind] = $raw;
            self::store($userId, $kind, $raw);
        }
    }

    public static function store(int $userId, string $kind, string $raw): string
    {
        $value = self::normalize($kind, $raw);
        if ($value === '') {
            delete_user_meta($userId, self::metaKey($kind));
            return '';
        }
        update_user_meta($userId, self::metaKey($kind), $value);

        return $value;
    }

    public static function normalize(string $kind, string $value): string
    {
        return $kind === 'bale' ? self::normalizeBale($value) : self::normalizeEitaa($value);
    }

    /** Keeps the Eitaa rules: numeric id, @username, or an eitaa.com/eitaa.ir link. */
    public static function normalizeEitaa(string $value): string
    {
        $value = self::stripChannelUrl($value, ['eitaa\.com', 'eitaa\.ir']);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return ltrim($value, '-');
        }
        $value = ltrim($value, '@');

        return preg_match('/^[A-Za-z0-9_]{3,64}$/', $value) ? '@' . $value : '';
    }

    /**
     * Bale accepts the same shapes, but only the channel id is stored for now,
     * so a username or link that carries no id ends up as an empty value.
     */
    public static function normalizeBale(string $value): string
    {
        $value = self::stripChannelUrl($value, ['ble\.ir', 'bale\.ai', 'bale\.ir']);
        if ($value === '') {
            return '';
        }
        $value = ltrim($value, '@');

        return preg_match('/^-?\d+$/', $value) ? ltrim($value, '-') : '';
    }

    private static function stripChannelUrl(string $value, array $domains): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $pattern = '~^(?:https?://)?(?:www\.)?(?:' . implode('|', $domains) . ')/(?:s/)?~i';
        $stripped = preg_replace($pattern, '', $value);
        $value = $stripped ?? $value;
        $value = preg_replace('~[?#].*$~', '', $value) ?? $value;

        return trim($value, "/ \t\n\r\0\x0B");
    }
}

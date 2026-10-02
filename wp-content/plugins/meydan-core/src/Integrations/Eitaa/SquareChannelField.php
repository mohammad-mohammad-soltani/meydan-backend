<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

use Meydan\Core\Integrations\Channels\Channels;

/**
 * Channel inputs on the square account screen (users.php / profile.php).
 *
 * The Eitaa input (`meydan_eitaa_channel`) lives here because the sync pipeline
 * reads it; the Bale input (`meydan_bale_channel`) sits next to it so both
 * channels are edited in one place. Storage and normalization are shared with
 * the rest of the panel through Channels.
 */
final class SquareChannelField
{
    public static function register(): void
    {
        add_action('show_user_profile', [self::class, 'render']);
        add_action('edit_user_profile', [self::class, 'render']);
        add_action('personal_options_update', [self::class, 'save']);
        add_action('edit_user_profile_update', [self::class, 'save']);
    }

    public static function render(\WP_User $user): void
    {
        if (!current_user_can('edit_user', $user->ID) || !\Meydan\Core\Support\Actor::isEntityAccount((int) $user->ID)) {
            return;
        }
        echo '<h2>اتصال کانال‌ها</h2>';
        echo '<p class="description">کانال ایتا مبنای همگام‌سازی محتوای میدان است و شناسه کانال بله در همین حساب ذخیره می‌شود.</p>';
        echo '<table class="form-table">';
        echo Channels::formNonce();
        Channels::renderRow((int) $user->ID, 'eitaa');
        Channels::renderRow((int) $user->ID, 'bale');
        echo '</table>';
    }

    public static function save(int $userId): void
    {
        if (!current_user_can('edit_user', $userId)) {
            return;
        }
        // Deliberately no role check here: a square may be owned by an account
        // that does not hold the square role yet, and the nonce is only ever
        // rendered on a screen whose owner can already edit the account.
        if (!Channels::nonceIsValid($userId)) {
            return;
        }
        Channels::save($userId);
    }

    /** @deprecated Kept for callers that normalized before Channels existed. */
    public static function normalizeChannel(string $value): string
    {
        return Channels::normalizeEitaa($value);
    }
}

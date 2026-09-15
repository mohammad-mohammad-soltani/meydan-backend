<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Bale;

/**
 * Wires WordPress lifecycle events to Bale notifications.
 *
 * Kept separate from the notifiers themselves so the delivery logic stays
 * testable and the hook surface is visible in one place.
 */
final class EventSubscriber
{
    public static function register(): void
    {
        // A new square account is created with approval_status=pending_verification,
        // so this transition is the moment an operator needs to be told.
        add_action('added_post_meta', [self::class, 'onMetaChange'], 10, 4);
        add_action('updated_post_meta', [self::class, 'onMetaChange'], 10, 4);
    }

    /**
     * @param int|string $metaId
     * @param int|string $objectId
     * @param string     $metaKey
     * @param mixed      $metaValue
     */
    public static function onMetaChange($metaId, $objectId, $metaKey, $metaValue): void
    {
        if ($metaKey !== 'meydan_approval_status') {
            return;
        }
        $squareId = (int) $objectId;
        if ($squareId <= 0 || get_post_type($squareId) !== 'meydan_square') {
            return;
        }
        if ((string) $metaValue !== 'pending_verification') {
            return;
        }
        if (!Settings::isReady() || !Settings::getBool('pending_squares')) {
            return;
        }

        // Defer past the current request so the post, its meta and its geo row
        // are all committed before the message is composed.
        wp_schedule_single_event(time() + 1, 'meydan_bale_pending_square', [$squareId]);
    }

    /** Cron handler for the deferred notification. */
    public static function notifyPending(int $squareId): void
    {
        SquareApprovalNotifier::notifyPending($squareId);
    }

    /** Reports an Eitaa integration failure to Bale (requirement 1). */
    public static function reportEitaaFailure(string $context, \WP_Error $error, array $extra = []): void
    {
        if (!Settings::isReady() || !Settings::getBool('report_eitaa')) {
            return;
        }
        ErrorReporter::report('همگام‌سازی ایتا — ' . $context, $error->get_error_message(), $extra + ['code' => $error->get_error_code()]);
    }
}

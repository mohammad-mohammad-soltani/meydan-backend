<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Bale;

use Meydan\Core\Admin\Admin;
use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Notifications\NotificationService;
use Meydan\Core\Support\Serializer;

/**
 * Pending-square approval over Bale (requirement 3).
 *
 * Sends the square link together with two inline buttons. The buttons carry a
 * signed callback payload so a forged callback cannot approve a square, and the
 * actual status change is delegated to Admin::applySquareStatus() — the same
 * code path used by wp-admin, so behaviour cannot drift between the two.
 */
final class SquareApprovalNotifier
{
    public const CALLBACK_APPROVE = 'sq_approve';
    public const CALLBACK_REJECT = 'sq_reject';

    /**
     * Notify about a square awaiting a decision. Called when a square enters
     * `pending_verification`.
     */
    public static function notifyPending(int $squareId): bool
    {
        if (!Settings::isReady() || !Settings::getBool('pending_squares')) {
            return false;
        }
        if ($squareId <= 0 || !\Meydan\Core\Domain\EntityKinds::isEntity($squareId)) {
            return false;
        }
        $status = (string) get_post_meta($squareId, 'meydan_approval_status', true) ?: 'pending_verification';
        if ($status !== 'pending_verification') {
            return false;
        }
        // A square is announced once; re-entering pending is a deliberate new decision.
        $stamp = 'meydan_bale_pending_sent_at';
        if ((string) get_post_meta($squareId, $stamp, true) !== '') {
            return false;
        }

        $text = self::message($squareId);
        $result = Notifier::send($text, self::keyboard($squareId));
        if (is_wp_error($result)) {
            ErrorReporter::reportWpError('اعلان میدان در انتظار تأیید', $result, ['square_id' => $squareId]);
            return false;
        }

        update_post_meta($squareId, $stamp, current_time('mysql', true));
        if (isset($result['message_id'])) {
            update_post_meta($squareId, 'meydan_bale_message_id', (int) $result['message_id']);
        }
        AuditLogger::log('bale_pending_square_notified', 'square', $squareId, null, ['chat_id_hash' => wp_hash(Settings::getString('chat_id'))]);
        return true;
    }

    /** Inline keyboard: [لغو] [تأیید]. */
    public static function keyboard(int $squareId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '❌ لغو', 'callback_data' => self::callbackData(self::CALLBACK_REJECT, $squareId)],
                ['text' => '✅ تأیید', 'callback_data' => self::callbackData(self::CALLBACK_APPROVE, $squareId)],
            ]],
        ];
    }

    /**
     * callback_data must stay under Telegram/Bale's 64-byte limit, so the
     * action and square id are signed into a fixed-width payload rather than
     * carrying the whole record.
     */
    public static function callbackData(string $action, int $squareId): string
    {
        $payload = $action . ':' . $squareId;
        return $payload . ':' . substr(hash_hmac('sha256', $payload, Settings::webhookSecret()), 0, 16);
    }

    /** @return array{action:string,square_id:int}|null */
    public static function parseCallbackData(string $data): ?array
    {
        $parts = explode(':', $data);
        if (count($parts) !== 3) {
            return null;
        }
        [$action, $squareId, $signature] = $parts;
        if (!in_array($action, [self::CALLBACK_APPROVE, self::CALLBACK_REJECT], true)) {
            return null;
        }
        $squareId = (int) $squareId;
        if ($squareId <= 0) {
            return null;
        }
        $expected = substr(hash_hmac('sha256', $action . ':' . $squareId, Settings::webhookSecret()), 0, 16);
        return hash_equals($expected, $signature) ? ['action' => $action, 'square_id' => $squareId] : null;
    }

    /** Applies the decision through the shared admin code path. */
    public static function apply(string $action, int $squareId, string $actorLabel): array
    {
        if (!\Meydan\Core\Domain\EntityKinds::isEntity($squareId)) {
            return ['ok' => false, 'message' => 'میدان موردنظر پیدا نشد.'];
        }

        $status = $action === self::CALLBACK_APPROVE ? 'approved' : 'rejected';
        $current = (string) get_post_meta($squareId, 'meydan_approval_status', true) ?: 'pending_verification';
        if (in_array($current, ['approved', 'rejected'], true)) {
            return ['ok' => false, 'message' => 'این میدان قبلاً بررسی شده است.'];
        }

        $note = sprintf('تصمیم از طریق ربات بله (%s)', $actorLabel);
        Admin::applySquareStatus($squareId, $status, $note);
        AuditLogger::log('bale_square_' . $status, 'square', $squareId, null, ['actor' => $actorLabel]);

        return [
            'ok' => true,
            'message' => $status === 'approved' ? 'میدان تأیید شد.' : 'درخواست میدان لغو شد.',
        ];
    }

    /** Rebuilds the message body after a decision, dropping the buttons. */
    public static function decisionMessage(int $squareId, string $status, string $actorLabel): string
    {
        $title = Notifier::esc((string) get_the_title($squareId));
        $emoji = $status === 'approved' ? '✅' : '❌';
        $label = $status === 'approved' ? 'تأیید شد' : 'لغو شد';
        return $emoji . ' <b>میدان ' . $label . '</b>' . "\n"
            . '🏛 ' . $title . ' (<code>' . $squareId . '</code>)' . "\n"
            . '👤 تصمیمگیرنده: ' . Notifier::esc($actorLabel) . "\n"
            . '<i>' . Notifier::esc(gmdate('Y-m-d H:i:s') . ' UTC') . '</i>';
    }

    private static function message(int $squareId): string
    {
        $title = (string) get_the_title($squareId) ?: ('میدان #' . $squareId);
        $owner = (int) get_post_meta($squareId, 'meydan_owner_user_id', true);
        $link = self::adminLink($squareId);
        $publicLink = (string) get_permalink($squareId) ?: '';

        $lines = [
            '🏛 <b>میدان در انتظار تأیید</b>',
            '<b>نام:</b> ' . Notifier::esc($title),
            '<b>شناسه:</b> <code>' . $squareId . '</code>',
        ];
        if ($owner > 0) {
            $user = get_userdata($owner);
            if ($user) {
                $name = trim((string) $user->display_name) !== '' ? (string) $user->display_name : (string) $user->user_login;
                $lines[] = '<b>مالک:</b> ' . Notifier::esc($name) . ' (<code>' . $owner . '</code>)';
            }
        }

        $square = Serializer::square($squareId);
        $location = (array) ($square['location'] ?? []);
        $address = trim((string) ($location['address'] ?? ''));
        if ($address !== '') {
            $lines[] = '<b>موقعیت:</b> ' . Notifier::esc(Notifier::trim($address, 200));
        }
        $about = trim(wp_strip_all_tags((string) ($square['description'] ?? '')));
        if ($about !== '') {
            $lines[] = '<b>درباره:</b> ' . Notifier::esc(Notifier::trim($about, 300));
        }

        $lines[] = '';
        $lines[] = '🔗 <b>لینک میدان:</b> ' . Notifier::esc($link !== '' ? $link : ('#' . $squareId));
        if ($link !== '') {
            // A plain-text copyable URL, since this is what the operator forwards.
            $lines[] = '<code>' . Notifier::esc($link) . '</code>';
        }
        if ($publicLink !== '') {
            $lines[] = '🌐 ' . Notifier::esc($publicLink);
        }
        $lines[] = '';
        $lines[] = 'برای تصمیم‌گیری از دکمه‌های زیر استفاده کنید.';

        return implode("\n", $lines);
    }

    private static function adminLink(int $squareId): string
    {
        $link = get_edit_post_link($squareId, 'raw');
        return is_string($link) && $link !== '' ? $link : (string) get_permalink($squareId);
    }
}

<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Bale;

use Meydan\Core\Support\Response;
use WP_REST_Request;

/**
 * Bale webhook receiver.
 *
 * Bale pushes updates here; the only update type this endpoint acts on is
 * `callback_query` from the two square-approval buttons.
 *
 * Security: the endpoint is public by necessity (Bale has no way to sign a
 * request as an admin), so it validates the secret token Bale echoes back in
 * X-Telegram-Bot-Api-Secret-Token, restricts the chat to the configured one,
 * and requires a signed callback payload. Without all three, a stranger who
 * guessed the URL could approve squares.
 */
final class WebhookController
{
    private const NS = 'meydan/v1';
    private const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public static function register(): void
    {
        $self = new self();
        register_rest_route(self::NS, '/integrations/bale/webhook', [
            'methods' => 'POST',
            'callback' => [$self, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request): mixed
    {
        $secret = Settings::webhookSecret();
        $provided = trim((string) $request->get_header(self::SECRET_HEADER));
        if ($secret === '' || $provided === '' || !hash_equals($secret, $provided)) {
            return Response::error('bale_webhook_unauthorized', 'درخواست نامعتبر است.', 401);
        }

        $update = json_decode((string) $request->get_body(), true);
        if (!is_array($update)) {
            return Response::error('bale_webhook_invalid', 'بدنه درخواست معتبر نیست.', 400);
        }

        $callback = $update['callback_query'] ?? null;
        if (!is_array($callback)) {
            // Non-callback updates (e.g. plain messages) are acknowledged and ignored.
            return Response::ok(['handled' => false]);
        }

        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        $allowed = Settings::getString('chat_id');
        if ($allowed !== '' && !hash_equals($allowed, $chatId)) {
            return Response::ok(['handled' => false, 'reason' => 'chat_not_allowed']);
        }

        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $parsed = SquareApprovalNotifier::parseCallbackData($data);
        if ($parsed === null) {
            if ($callbackId !== '') {
                (new BaleClient())->answerCallbackQuery($callbackId, 'این دکمه معتبر نیست.', true);
            }
            return Response::ok(['handled' => false, 'reason' => 'bad_payload']);
        }

        $actor = self::actorLabel($callback);
        $result = SquareApprovalNotifier::apply($parsed['action'], $parsed['square_id'], $actor);

        $client = new BaleClient();
        if ($callbackId !== '') {
            $client->answerCallbackQuery($callbackId, (string) $result['message'], !$result['ok']);
        }

        // Replace the message so a decided square cannot be acted on twice.
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        if ($messageId > 0) {
            $status = $parsed['action'] === SquareApprovalNotifier::CALLBACK_APPROVE ? 'approved' : 'rejected';
            $text = $result['ok']
                ? SquareApprovalNotifier::decisionMessage($parsed['square_id'], $status, $actor)
                : "ℹ️ <b>" . Notifier::esc((string) $result['message']) . "</b>\n🏛 " . Notifier::esc((string) get_the_title($parsed['square_id']));
            $client->editMessageText($chatId, $messageId, $text);
        }

        return Response::ok(['handled' => true, 'ok' => $result['ok']]);
    }

    /** Identifies who pressed the button, for the audit log. */
    private static function actorLabel(array $callback): string
    {
        $from = (array) ($callback['from'] ?? []);
        $name = trim((string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($from['username'] ?? '');
        }
        $id = (string) ($from['id'] ?? '');
        return $name !== '' ? $name . ($id !== '' ? ' (' . $id . ')' : '') : ('کاربر بله ' . $id);
    }
}

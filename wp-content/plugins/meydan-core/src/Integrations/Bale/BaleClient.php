<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Bale;

use WP_Error;

/**
 * Minimal Bale Bot API client.
 *
 * Bale's Bot API is a near 1:1 copy of the Telegram Bot API, so the request
 * shape (method path, JSON body, `ok`/`result` envelope) is identical. Only the
 * base URL differs, and file downloads live under /file/bot<token>/.
 */
final class BaleClient
{
    private const DEFAULT_BASE = 'https://tapi.bale.ai';

    /** Bot identity, used by the settings page to prove the token works. */
    public function getMe(): array|WP_Error
    {
        return $this->call('getMe');
    }

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null, bool $disablePreview = true): array|WP_Error
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => $disablePreview,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        return $this->call('sendMessage', $payload);
    }

    public function editMessageText(string $chatId, int $messageId, string $text, ?array $replyMarkup = null): array|WP_Error
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        return $this->call('editMessageText', $payload);
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $alert = false): array|WP_Error
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert,
        ]);
    }

    public function setWebhook(string $url, string $secretToken): array|WP_Error
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    public function deleteWebhook(): array|WP_Error
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function getWebhookInfo(): array|WP_Error
    {
        return $this->call('getWebhookInfo');
    }

    /** @param array<string,mixed> $payload */
    private function call(string $method, array $payload = []): array|WP_Error
    {
        $settings = Settings::get();
        $token = (string) ($settings['token'] ?? '');
        if ($token === '') {
            return new WP_Error('bale_not_configured', 'توکن ربات بله تنظیم نشده است.');
        }

        $response = wp_remote_post($this->baseUrl() . '/bot' . $token . '/' . $method, [
            'timeout' => (int) ($settings['timeout'] ?? 15) ?: 15,
            'redirection' => 0,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('bale_transport_error', 'ارتباط با بله ناموفق بود: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return new WP_Error('bale_bad_response', 'پاسخ بله معتبر نیست (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300 || empty($decoded['ok'])) {
            return new WP_Error(
                'bale_api_error',
                (string) ($decoded['description'] ?? 'درخواست بله ناموفق بود.'),
                ['status' => $status ?: 502, 'bale_error_code' => $decoded['error_code'] ?? null]
            );
        }

        $result = $decoded['result'] ?? [];
        return is_array($result) ? $result : ['value' => $result];
    }

    private function baseUrl(): string
    {
        $configured = Settings::getString('base_url');
        return rtrim($configured !== '' ? $configured : self::DEFAULT_BASE, '/');
    }
}

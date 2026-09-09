<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_REST_Response;

final class Response
{
    public static function ok(mixed $data = null, array $meta = [], int $status = 200): WP_REST_Response
    {
        $meta = array_merge(['request_id' => self::requestId(), 'next_cursor' => null], $meta);
        return new WP_REST_Response(['data' => $data, 'meta' => $meta], $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $fields = []): WP_REST_Response
    {
        return new WP_REST_Response([
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => (object) $fields,
            ],
        ], $status);
    }

    public static function requestId(): string
    {
        static $id;
        return $id ??= 'req_' . substr(Crypto::randomToken(12), 0, 18);
    }

    public static function cache(WP_REST_Response $response, string $policy): WP_REST_Response
    {
        $response->header('Cache-Control', $policy);
        return $response;
    }
}

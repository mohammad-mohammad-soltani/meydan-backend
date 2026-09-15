<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Notifications\NativeWebPush;
use Meydan\Core\Support\Response;
use WP_REST_Request;

final class PushController extends BaseController
{
    public function config()
    {
        if (!is_user_logged_in()) {
            return Response::error('unauthenticated', 'فعال‌سازی اعلان مرورگر نیاز به ورود دارد.', 401);
        }

        return Response::cache(Response::ok([
            'provider' => 'web-push',
            'enabled' => NativeWebPush::isConfigured(),
            'vapid_public_key' => NativeWebPush::publicKey(),
        ]), 'private, no-store');
    }

    public function subscribe(WP_REST_Request $request)
    {
        if (!is_user_logged_in()) {
            return Response::error('unauthenticated', 'فعال‌سازی اعلان مرورگر نیاز به ورود دارد.', 401);
        }

        $payload = $this->json($request);
        $result = NativeWebPush::subscribe(
            get_current_user_id(),
            $payload,
            (string) ($request->get_header('user-agent') ?: ''),
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return Response::ok(['subscribed' => true], [], 201);
    }

    public function unsubscribe(WP_REST_Request $request)
    {
        if (!is_user_logged_in()) {
            return Response::error('unauthenticated', 'غیرفعال‌سازی اعلان مرورگر نیاز به ورود دارد.', 401);
        }

        $payload = $this->json($request);
        $result = NativeWebPush::unsubscribe(
            get_current_user_id(),
            sanitize_text_field((string) ($payload['endpoint'] ?? '')),
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return Response::ok(['unsubscribed' => true]);
    }
}

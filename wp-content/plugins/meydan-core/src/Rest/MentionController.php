<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Support\Mentions;
use Meydan\Core\Support\RateLimiter;
use Meydan\Core\Support\Response;
use WP_REST_Request;

/** The composer's `@` autocomplete. */
final class MentionController extends BaseController
{
    public function suggest(WP_REST_Request $request)
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return Response::error('unauthenticated', 'برای انجام این عملیات باید وارد شوید.', 401);
        }
        $rate = RateLimiter::hit('mention_suggest', 'u' . $viewerId, 120, MINUTE_IN_SECONDS);
        if (!$rate['allowed']) {
            return Response::error('rate_limited', 'تعداد درخواست‌های پیشنهاد منشن بیش از حد مجاز است.', 429);
        }
        $q = substr(trim((string) $request->get_param('q')), 0, 60);
        return Response::ok(['items' => Mentions::suggest($viewerId, $q)]);
    }
}

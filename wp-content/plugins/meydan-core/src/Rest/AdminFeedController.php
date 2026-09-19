<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Feed\FeedService;
use Meydan\Core\Feed\FeedSettings;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Viewer;
use WP_REST_Request;

final class AdminFeedController extends BaseController
{
    public function settings()
    {
        return Response::ok(['settings' => FeedSettings::get(), 'defaults' => FeedSettings::defaults()]);
    }

    public function updateSettings(WP_REST_Request $request)
    {
        $before = FeedSettings::get();
        $input = $this->json($request);
        $result = !empty($input['reset']) ? FeedSettings::reset() : FeedSettings::update($input);
        if ($result instanceof \WP_Error) return $this->error($result);
        AuditLogger::log('feed_settings_updated', 'feed_settings', null, $before, $result);
        return Response::ok(['settings' => $result, 'defaults' => FeedSettings::defaults()]);
    }

    public function preview(WP_REST_Request $request)
    {
        $userId = max(0, (int) $request->get_param('user_id'));
        $limit = min(50, max(1, (int) ($request->get_param('limit') ?: 20)));
        if (!$userId || !get_userdata($userId)) return Response::error('validation_failed', 'کاربر معتبر نیست.', 422, ['user_id' => 'invalid']);
        $viewer = new Viewer('user', (string) $userId, $userId, (int) get_user_meta($userId, 'meydan_province_id', true) ?: null, (int) get_user_meta($userId, 'meydan_city_id', true) ?: null);
        return Response::ok((new FeedService())->forYou($viewer, $limit, true));
    }
}

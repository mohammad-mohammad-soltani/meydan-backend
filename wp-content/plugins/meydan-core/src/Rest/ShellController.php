<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Domain\WorkGroups;
use Meydan\Core\Notifications\NativeWebPush;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\SoketiRealtime;

/**
 * What every page of the app needs on load, in one request: the viewer,
 * unread counters for the navigation badge, realtime and push settings.
 * Before this the app made six requests on each page load (each one a full
 * WordPress boot), two of them whole lists read only to sum a badge.
 */
final class ShellController extends BaseController
{
    /** Badges show «۹۹+»; counting past this bound is wasted work. */
    private const UNREAD_CAP = 999;

    public function shell()
    {
        if (!is_user_logged_in()) {
            return Response::error('unauthenticated', 'برای این بخش باید وارد شوید.', 401);
        }
        $uid = get_current_user_id();
        $me = (new MeController())->me();
        $meData = $me instanceof \WP_REST_Response && $me->get_status() < 400 ? ($me->get_data()['data'] ?? null) : null;

        return Response::cache(Response::ok([
            'me' => $meData,
            'unread' => self::unread($uid),
            'realtime' => [...SoketiRealtime::publicConfig(), 'user_id' => (string) $uid],
            'push' => [
                'provider' => 'web-push',
                'enabled' => NativeWebPush::isConfigured(),
                'vapid_public_key' => NativeWebPush::publicKey(),
            ],
        ]), 'private, no-store');
    }

    /** The badge counters alone: what the 30-second poll and realtime events re-read. */
    public function unreadCounts()
    {
        if (!is_user_logged_in()) {
            return Response::error('unauthenticated', 'برای این بخش باید وارد شوید.', 401);
        }
        return Response::cache(Response::ok(self::unread(get_current_user_id())), 'private, no-store');
    }

    /** @return array{messages:int,notifications:int} */
    public static function unread(int $uid): array
    {
        global $wpdb;
        $cap = self::UNREAD_CAP;
        $m = $wpdb->prefix . 'meydan_chat_messages';
        $p = $wpdb->prefix . 'meydan_chat_participants';
        $c = $wpdb->prefix . 'meydan_chat_conversations';

        // Direct conversations: messages after the viewer's read marker, not their own.
        $direct = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM {$p} pp
                INNER JOIN {$c} cc ON cc.id = pp.conversation_id AND cc.type <> 'work'
                INNER JOIN {$m} mm ON mm.conversation_id = pp.conversation_id AND mm.id > COALESCE(pp.last_read_message_id, 0)
                WHERE pp.user_id = %d AND pp.archived_at IS NULL AND mm.sender_user_id <> %d AND mm.deleted_at IS NULL
                LIMIT %d
            ) unread_direct",
            $uid,
            $uid,
            $cap
        ));

        // Work groups: same rule as the works list (no system lines; private posts only for their audience).
        $audience = WorkGroups::table('message_audience');
        $work = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM {$p} pp
                INNER JOIN {$c} cc ON cc.id = pp.conversation_id AND cc.type = 'work'
                INNER JOIN {$m} mm ON mm.conversation_id = pp.conversation_id AND mm.id > COALESCE(pp.last_read_message_id, 0)
                WHERE pp.user_id = %d AND pp.archived_at IS NULL AND mm.sender_user_id <> %d AND mm.kind <> 'system' AND mm.deleted_at IS NULL
                  AND (pp.role IN ('owner','admin') OR mm.is_private = 0
                       OR EXISTS (SELECT 1 FROM {$audience} au WHERE au.message_id = COALESCE(mm.thread_root_id, mm.id) AND au.user_id = %d))
                LIMIT %d
            ) unread_work",
            $uid,
            $uid,
            $uid,
            $cap
        ));

        $notifications = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}meydan_notifications WHERE recipient_user_id = %d AND read_at IS NULL AND archived_at IS NULL",
            $uid
        ));

        return ['messages' => min($cap, $direct + $work), 'notifications' => $notifications];
    }
}

<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Notifications\AsyncDispatcher;
use Meydan\Core\Support\Cursor;
use WP_Error;

/**
 * Read models for the «کارها» list, summary tiles, a work's detail and its
 * members. Each method runs a fixed number of queries regardless of page size.
 */
final class WorkQueries
{
    public const PAGE = 20;

    private static function t(string $name): string
    {
        return WorkGroups::table($name);
    }

    /** @return array<string,mixed>|null raw conversation row of a work group */
    public static function conversation(int $conversationId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::t('conversations') . " WHERE id=%d AND type='work' LIMIT 1",
            $conversationId
        ), ARRAY_A);
        return $row ?: null;
    }

    /** @param int[] $mediaIds @return array<int,string> */
    private static function avatars(array $mediaIds): array
    {
        $mediaIds = array_values(array_unique(array_filter($mediaIds)));
        if (!$mediaIds) {
            return [];
        }
        if (function_exists('_prime_post_caches')) {
            _prime_post_caches($mediaIds, false, true);
        }
        $out = [];
        foreach ($mediaIds as $id) {
            $url = wp_get_attachment_image_url($id, 'thumbnail') ?: wp_get_attachment_url($id);
            if ($url) {
                $out[$id] = (string) $url;
            }
        }
        return $out;
    }

    /* ------------------------------ list ----------------------------- */

    /**
     * @return array{items:array<int,array<string,mixed>>,next_cursor:?string}
     */
    public static function listWorks(int $userId, string $filter, string $q, string $cursor, int $limit = self::PAGE): array
    {
        global $wpdb;
        $limit = min(50, max(1, $limit));
        $c = self::t('conversations');
        $p = self::t('participants');
        $m = self::t('messages');
        $uid = (int) $userId;

        $join = '';
        $where = ["c.type='work'"];
        $q = trim($q);
        if ($q !== '') {
            $where[] = $wpdb->prepare('c.title LIKE %s', '%' . $wpdb->esc_like($q) . '%');
        }
        $openTask = "m.kind='task' AND m.task_status IN ('todo','doing') AND m.deleted_at IS NULL";
        $tp = self::t('work_task_people');
        $mn = self::t('message_mentions');
        switch ($filter) {
            case 'joined':
                $join = " INNER JOIN {$p} pj ON pj.conversation_id=c.id AND pj.user_id={$uid} AND pj.archived_at IS NULL";
                break;
            case 'my_tasks':
                $where[] = "EXISTS (SELECT 1 FROM {$tp} t INNER JOIN {$m} m ON m.id=t.message_id WHERE t.user_id={$uid} AND m.conversation_id=c.id AND {$openTask})";
                break;
            case 'late':
                $where[] = "EXISTS (SELECT 1 FROM {$tp} t INNER JOIN {$m} m ON m.id=t.message_id WHERE t.user_id={$uid} AND m.conversation_id=c.id AND {$openTask} AND m.due_at IS NOT NULL AND m.due_at<UTC_TIMESTAMP())";
                break;
            case 'mentions':
                $where[] = "EXISTS (SELECT 1 FROM {$mn} x INNER JOIN {$m} m ON m.id=x.message_id INNER JOIN {$p} pp ON pp.conversation_id=m.conversation_id AND pp.user_id={$uid} WHERE x.user_id={$uid} AND m.conversation_id=c.id AND m.id>COALESCE(pp.last_read_message_id,0) AND m.deleted_at IS NULL)";
                break;
        }

        $cur = Cursor::decode($cursor);
        if (!empty($cur['u']) && !empty($cur['i'])) {
            $where[] = $wpdb->prepare('(c.updated_at<%s OR (c.updated_at=%s AND c.id<%d))', $cur['u'], $cur['u'], (int) $cur['i']);
        }

        $rows = $wpdb->get_results(
            "SELECT c.* FROM {$c} c{$join} WHERE " . implode(' AND ', $where) . ' ORDER BY c.updated_at DESC, c.id DESC LIMIT ' . ($limit + 1),
            ARRAY_A
        ) ?: [];
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        if (!$rows) {
            return ['items' => [], 'next_cursor' => null];
        }

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $marks = WorkMessages::marks($ids);

        // Viewer standing per work.
        $viewer = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT conversation_id, role, COALESCE(last_read_message_id,0) AS last_read, notifications_muted FROM {$p} WHERE user_id=%d AND archived_at IS NULL AND conversation_id IN ({$marks})",
            $uid,
            ...$ids
        ), ARRAY_A) ?: [] as $r) {
            $viewer[(int) $r['conversation_id']] = $r;
        }

        // Member counts.
        $members = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT conversation_id, COUNT(*) AS n FROM {$p} WHERE archived_at IS NULL AND conversation_id IN ({$marks}) GROUP BY conversation_id",
            ...$ids
        ), ARRAY_A) ?: [] as $r) {
            $members[(int) $r['conversation_id']] = (int) $r['n'];
        }

        // Task progress.
        $progress = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT conversation_id, COUNT(*) AS total, SUM(task_status IN ('done','ok')) AS done FROM {$m} WHERE kind='task' AND deleted_at IS NULL AND conversation_id IN ({$marks}) GROUP BY conversation_id",
            ...$ids
        ), ARRAY_A) ?: [] as $r) {
            $progress[(int) $r['conversation_id']] = ['total' => (int) $r['total'], 'done' => (int) $r['done']];
        }

        // Last message (never private — private posts do not bump the work).
        $lastIds = array_values(array_filter(array_map(static fn(array $r): int => (int) $r['last_message_id'], $rows)));
        $last = [];
        if ($lastIds) {
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT id,sender_user_id,kind,body,payload_json,created_at,deleted_at FROM ' . $m . ' WHERE id IN (' . WorkMessages::marks($lastIds) . ')',
                ...$lastIds
            )) ?: [] as $r) {
                $last[(int) $r->id] = $r;
            }
        }

        // Unread + unread mentions, only for works the viewer belongs to.
        $joinedIds = array_keys($viewer);
        $unread = [];
        $mentionUnread = [];
        if ($joinedIds) {
            $jm = WorkMessages::marks($joinedIds);
            $audience = self::t('message_audience');
            foreach ($wpdb->get_results($wpdb->prepare(
                "SELECT m.conversation_id, COUNT(*) AS n FROM {$m} m INNER JOIN {$p} pp ON pp.conversation_id=m.conversation_id AND pp.user_id=%d
                 WHERE m.conversation_id IN ({$jm}) AND m.id>COALESCE(pp.last_read_message_id,0) AND m.sender_user_id<>%d AND m.kind<>'system' AND m.deleted_at IS NULL
                 AND (m.is_private=0 OR pp.role IN ('owner','admin') OR EXISTS (SELECT 1 FROM {$audience} au WHERE au.message_id=COALESCE(m.thread_root_id,m.id) AND au.user_id=%d))
                 GROUP BY m.conversation_id",
                ...array_merge([$uid], $joinedIds, [$uid, $uid])
            ), ARRAY_A) ?: [] as $r) {
                $unread[(int) $r['conversation_id']] = (int) $r['n'];
            }
            foreach ($wpdb->get_results($wpdb->prepare(
                "SELECT m.conversation_id, COUNT(*) AS n FROM {$mn} x INNER JOIN {$m} m ON m.id=x.message_id INNER JOIN {$p} pp ON pp.conversation_id=m.conversation_id AND pp.user_id=x.user_id
                 WHERE x.user_id=%d AND m.conversation_id IN ({$jm}) AND m.id>COALESCE(pp.last_read_message_id,0) AND m.deleted_at IS NULL GROUP BY m.conversation_id",
                $uid,
                ...$joinedIds
            ), ARRAY_A) ?: [] as $r) {
                $mentionUnread[(int) $r['conversation_id']] = (int) $r['n'];
            }
        }

        $avatars = self::avatars(array_map(static fn(array $r): int => (int) $r['avatar_media_id'], $rows));
        $senders = WorkUsers::summaries(array_map(static fn($r): int => (int) $r->sender_user_id, $last));

        $items = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $v = $viewer[$id] ?? null;
            $lm = $last[(int) $r['last_message_id']] ?? null;
            $lmPayload = $lm && $lm->payload_json ? (json_decode((string) $lm->payload_json, true) ?: []) : [];
            $items[] = [
                'id' => (string) $id,
                'initiative_id' => $r['initiative_id'] !== null ? (string) $r['initiative_id'] : null,
                'title' => (string) $r['title'],
                'description' => (string) ($r['description'] ?? ''),
                'avatar_url' => $avatars[(int) $r['avatar_media_id']] ?? null,
                'updated_at' => WorkMessages::iso((string) $r['updated_at']),
                'member_count' => $members[$id] ?? 0,
                'progress' => $progress[$id] ?? ['total' => 0, 'done' => 0],
                'viewer' => [
                    'joined' => $v !== null,
                    'role' => $v['role'] ?? null,
                    'muted' => $v ? (bool) $v['notifications_muted'] : false,
                    'unread_count' => $unread[$id] ?? 0,
                    'mention_unread' => $mentionUnread[$id] ?? 0,
                ],
                'last_message' => $lm ? [
                    'id' => (string) $lm->id,
                    'kind' => (string) $lm->kind,
                    'title' => (string) ($lmPayload['title'] ?? ''),
                    'body' => $lm->deleted_at ? '' : mb_substr((string) $lm->body, 0, 120),
                    'sender_name' => (string) ($senders[(int) $lm->sender_user_id]['name'] ?? ''),
                    'created_at' => WorkMessages::iso((string) $lm->created_at),
                ] : null,
            ];
        }

        $tail = end($rows);
        return [
            'items' => $items,
            'next_cursor' => $more ? Cursor::encode(['u' => (string) $tail['updated_at'], 'i' => (int) $tail['id']]) : null,
        ];
    }

    /** @return array{my_tasks:int,late:int,mentions:int} */
    public static function summary(int $userId): array
    {
        global $wpdb;
        $uid = (int) $userId;
        $m = self::t('messages');
        $p = self::t('participants');

        $tasks = $wpdb->get_row(
            'SELECT COUNT(*) AS open_n, COALESCE(SUM(m.due_at IS NOT NULL AND m.due_at<UTC_TIMESTAMP()),0) AS late_n FROM ' . self::t('work_task_people')
            . " t INNER JOIN {$m} m ON m.id=t.message_id INNER JOIN {$p} p ON p.conversation_id=m.conversation_id AND p.user_id=t.user_id AND p.archived_at IS NULL
               WHERE t.user_id={$uid} AND m.kind='task' AND m.task_status IN ('todo','doing') AND m.deleted_at IS NULL",
            ARRAY_A
        );
        $mentions = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::t('message_mentions') . " x INNER JOIN {$m} m ON m.id=x.message_id
             INNER JOIN {$p} p ON p.conversation_id=m.conversation_id AND p.user_id=x.user_id AND p.archived_at IS NULL
             WHERE x.user_id={$uid} AND m.id>COALESCE(p.last_read_message_id,0) AND m.deleted_at IS NULL"
        );

        return [
            'my_tasks' => (int) ($tasks['open_n'] ?? 0),
            'late' => (int) ($tasks['late_n'] ?? 0),
            'mentions' => $mentions,
        ];
    }

    /* ----------------------------- detail ---------------------------- */

    /** @return array<string,mixed>|WP_Error */
    public static function detail(int $conversationId, int $userId): array|WP_Error
    {
        global $wpdb;
        $conv = self::conversation($conversationId);
        if (!$conv) {
            return new WP_Error('work_not_found', 'کار پیدا نشد.', ['status' => 404]);
        }
        $uid = (int) $userId;
        $role = WorkGroups::role($conversationId, $uid);
        $manager = WorkGroups::canManage($role, $uid);
        $m = self::t('messages');
        $visible = WorkMessages::visibleSql('m', $uid, $manager);

        $standing = $wpdb->get_row($wpdb->prepare(
            'SELECT COALESCE(last_read_message_id,0) AS last_read, notifications_muted FROM ' . self::t('participants') . ' WHERE conversation_id=%d AND user_id=%d AND archived_at IS NULL',
            $conversationId,
            $uid
        ), ARRAY_A);

        $memberCount = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::t('participants') . ' WHERE conversation_id=%d AND archived_at IS NULL',
            $conversationId
        ));

        $counts = ['all' => 0, 'text' => 0, 'task' => 0, 'meeting' => 0, 'announcement' => 0, 'poll' => 0];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT m.kind, COUNT(*) AS n FROM {$m} m WHERE m.conversation_id=%d AND m.deleted_at IS NULL AND m.kind<>'system' AND {$visible} GROUP BY m.kind",
            $conversationId
        ), ARRAY_A) ?: [] as $r) {
            $counts[(string) $r['kind']] = (int) $r['n'];
            $counts['all'] += (int) $r['n'];
        }

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(m.task_status IN ('todo','doing')),0) AS open_n,
                    COALESCE(SUM(m.task_status IN ('done','ok')),0) AS done_n,
                    COALESCE(SUM(m.task_status IN ('todo','doing') AND m.due_at IS NOT NULL AND m.due_at<UTC_TIMESTAMP()),0) AS late_n,
                    COALESCE(SUM(t.user_id IS NOT NULL AND m.task_status IN ('todo','doing')),0) AS mine_n
             FROM {$m} m LEFT JOIN " . self::t('work_task_people') . " t ON t.message_id=m.id AND t.user_id=%d
             WHERE m.conversation_id=%d AND m.kind='task' AND m.deleted_at IS NULL AND {$visible}",
            $uid,
            $conversationId
        ), ARRAY_A) ?: [];

        $pinnedId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT m.id FROM {$m} m WHERE m.conversation_id=%d AND m.pinned_at IS NOT NULL AND m.deleted_at IS NULL AND {$visible} ORDER BY m.pinned_at DESC LIMIT 1",
            $conversationId
        ));
        $pinned = $pinnedId ? WorkMessages::one($pinnedId, $uid, $manager) : null;

        $avatars = self::avatars([(int) $conv['avatar_media_id']]);

        return [
            'id' => (string) $conv['id'],
            'initiative_id' => $conv['initiative_id'] !== null ? (string) $conv['initiative_id'] : null,
            'title' => (string) $conv['title'],
            'description' => (string) ($conv['description'] ?? ''),
            'avatar_url' => $avatars[(int) $conv['avatar_media_id']] ?? null,
            'member_count' => $memberCount,
            'viewer' => [
                'joined' => $standing !== null,
                'role' => $role,
                'can_post' => $manager,
                'can_manage' => $manager,
                'can_edit_info' => WorkGroups::canAdminister($role, $uid),
                'muted' => $standing ? (bool) $standing['notifications_muted'] : false,
                'last_read_message_id' => $standing ? (string) $standing['last_read'] : '0',
            ],
            'counts' => $counts,
            'stats' => [
                'open_tasks' => (int) ($stats['open_n'] ?? 0),
                'done_tasks' => (int) ($stats['done_n'] ?? 0),
                'late_tasks' => (int) ($stats['late_n'] ?? 0),
                'my_tasks' => (int) ($stats['mine_n'] ?? 0),
            ],
            'pinned' => $pinned,
        ];
    }

    /* ----------------------------- members --------------------------- */

    /** @return array{items:array<int,array<string,mixed>>,next_cursor:?string} */
    public static function members(int $conversationId, string $q, string $cursor, int $limit = 50): array
    {
        global $wpdb;
        $limit = min(100, max(1, $limit));
        $p = self::t('participants');
        $rank = "(CASE p.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END)";

        $join = '';
        $where = [$wpdb->prepare('p.conversation_id=%d', $conversationId), 'p.archived_at IS NULL'];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like(ltrim($q, '@')) . '%';
            $join = " INNER JOIN {$wpdb->users} u ON u.ID=p.user_id";
            $where[] = $wpdb->prepare('(u.display_name LIKE %s OR u.user_nicename LIKE %s OR u.user_login LIKE %s)', $like, $like, $like);
        }
        $cur = Cursor::decode($cursor);
        if (isset($cur['r'], $cur['u'])) {
            $where[] = $wpdb->prepare("({$rank}>%d OR ({$rank}=%d AND p.user_id>%d))", (int) $cur['r'], (int) $cur['r'], (int) $cur['u']);
        }

        $rows = $wpdb->get_results(
            "SELECT p.user_id, p.role, p.joined_at, {$rank} AS rk FROM {$p} p{$join} WHERE " . implode(' AND ', $where) . " ORDER BY rk ASC, p.user_id ASC LIMIT " . ($limit + 1),
            ARRAY_A
        ) ?: [];
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        if (!$rows) {
            return ['items' => [], 'next_cursor' => null];
        }

        $ids = array_map(static fn(array $r): int => (int) $r['user_id'], $rows);
        $load = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT t.user_id, COALESCE(SUM(m.task_status IN ('todo','doing')),0) AS open_n, COALESCE(SUM(m.task_status IN ('done','ok')),0) AS done_n
             FROM " . self::t('work_task_people') . ' t INNER JOIN ' . self::t('messages') . " m ON m.id=t.message_id
             WHERE m.conversation_id=%d AND m.kind='task' AND m.deleted_at IS NULL AND t.user_id IN (" . WorkMessages::marks($ids) . ') GROUP BY t.user_id',
            $conversationId,
            ...$ids
        ), ARRAY_A) ?: [] as $r) {
            $load[(int) $r['user_id']] = ['open' => (int) $r['open_n'], 'done' => (int) $r['done_n']];
        }

        $users = WorkUsers::summaries($ids);
        $items = [];
        foreach ($rows as $r) {
            $id = (int) $r['user_id'];
            $items[] = [
                'user' => $users[$id] ?? null,
                'role' => (string) $r['role'],
                'joined_at' => WorkMessages::iso((string) $r['joined_at']),
                'open_tasks' => $load[$id]['open'] ?? 0,
                'done_tasks' => $load[$id]['done'] ?? 0,
            ];
        }
        $tail = end($rows);
        return [
            'items' => $items,
            'next_cursor' => $more ? Cursor::encode(['r' => (int) $tail['rk'], 'u' => (int) $tail['user_id']]) : null,
        ];
    }

    /* ------------------------------ writes --------------------------- */

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public static function updateInfo(int $conversationId, int $userId, array $input): array|WP_Error
    {
        $conv = self::conversation($conversationId);
        if (!$conv) {
            return new WP_Error('work_not_found', 'کار پیدا نشد.', ['status' => 404]);
        }
        if (!WorkGroups::canAdminister(WorkGroups::role($conversationId, $userId), $userId)) {
            return new WP_Error('forbidden', 'فقط مدیر کار یا مدیر سایت می‌تواند اطلاعات را ویرایش کند.', ['status' => 403]);
        }

        $update = [];
        if (array_key_exists('title', $input)) {
            $title = mb_substr(sanitize_text_field((string) $input['title']), 0, 190);
            if ($title === '') {
                return new WP_Error('title_required', 'عنوان را بنویسید.', ['status' => 422]);
            }
            $update['title'] = $title;
        }
        if (array_key_exists('description', $input)) {
            $update['description'] = mb_substr(sanitize_textarea_field((string) $input['description']), 0, 2000);
        }
        if (array_key_exists('avatar_media_id', $input)) {
            $media = max(0, (int) $input['avatar_media_id']);
            if ($media > 0 && get_post_type($media) !== 'attachment') {
                return new WP_Error('invalid_media', 'تصویر معتبر نیست.', ['status' => 422]);
            }
            $update['avatar_media_id'] = $media ?: null;
        }
        if ($update) {
            global $wpdb;
            $wpdb->update(self::t('conversations'), $update, ['id' => $conversationId]);
            AsyncDispatcher::queueRealtimeToUsers(WorkMessages::memberIds($conversationId), 'work:updated', ['workId' => (string) $conversationId]);
        }
        return self::detail($conversationId, $userId);
    }

    public static function markRead(int $conversationId, int $userId, int $messageId): true|WP_Error
    {
        if (WorkGroups::role($conversationId, $userId) === null) {
            return new WP_Error('work_join_required', 'اول به این کار بپیوندید.', ['status' => 403]);
        }
        global $wpdb;
        if ($messageId > 0 && !(int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::t('messages') . ' WHERE id=%d AND conversation_id=%d',
            $messageId,
            $conversationId
        ))) {
            return new WP_Error('invalid_message', 'پیام معتبر نیست.', ['status' => 422]);
        }
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::t('participants') . ' SET last_read_message_id=GREATEST(COALESCE(last_read_message_id,0),%d) WHERE conversation_id=%d AND user_id=%d',
            $messageId,
            $conversationId,
            $userId
        ));
        AsyncDispatcher::queueRealtimeToUsers([$userId], 'work:read', ['workId' => (string) $conversationId, 'messageId' => (string) $messageId]);
        return true;
    }
}

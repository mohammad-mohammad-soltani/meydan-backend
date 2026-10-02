<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Notifications\AsyncDispatcher;
use Meydan\Core\Notifications\NotificationService;
use Meydan\Core\Support\Actor;
use WP_Error;

/**
 * Messages of a work group: visibility, batched hydration, creation.
 *
 * A page of N messages is hydrated with a fixed number of queries (reply
 * targets, reactions, mentions, task people, task items, RSVPs, seen counts,
 * poll tallies, reply counts, one user-summary pass) — never per message.
 */
final class WorkMessages
{
    public const KINDS = ['text', 'task', 'meeting', 'announcement', 'poll'];
    public const PRIORITIES = ['high', 'normal', 'low'];

    public static function t(string $name): string
    {
        return WorkGroups::table($name);
    }

    public static function iso(?string $mysqlUtc): ?string
    {
        return $mysqlUtc ? gmdate('c', (int) strtotime($mysqlUtc . ' UTC')) : null;
    }

    /** @param int[] $ids */
    public static function marks(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '%d'));
    }

    /**
     * SQL condition that hides private meetings (and every reply inside such a
     * thread) from non-audience members. Managers see everything.
     */
    public static function visibleSql(string $alias, int $viewerId, bool $manager): string
    {
        if ($manager) {
            return '1=1';
        }
        $audience = self::t('message_audience');
        return "({$alias}.is_private=0 OR EXISTS (SELECT 1 FROM {$audience} au WHERE au.message_id=COALESCE({$alias}.thread_root_id,{$alias}.id) AND au.user_id=" . (int) $viewerId . '))';
    }

    /** @return int[] */
    public static function memberIds(int $conversationId): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT user_id FROM ' . self::t('participants') . ' WHERE conversation_id=%d AND archived_at IS NULL',
            $conversationId
        )) ?: []);
    }

    /** @return array{type:string,id:int} */
    public static function actorRef(int $userId): array
    {
        $actor = Actor::forUser($userId);
        $id = (int) ($actor['numeric_id'] ?? 0);
        if ($id <= 0) {
            $raw = (string) ($actor['id'] ?? ('user_' . $userId));
            $pos = strrpos($raw, '_');
            $id = $pos === false ? $userId : (int) substr($raw, $pos + 1);
        }
        return ['type' => (string) ($actor['type'] ?? 'user'), 'id' => $id > 0 ? $id : $userId];
    }

    /** @return object|null raw message row */
    public static function row(int $messageId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('messages') . ' WHERE id=%d LIMIT 1', $messageId));
        return $row ?: null;
    }

    /* ------------------------------------------------------------------ */
    /* Reading                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array{before_id?:int,after_id?:int,limit?:int,kind?:string,mine?:bool} $opts
     * @return array{items:array<int,array<string,mixed>>,next_cursor:?string}
     */
    public static function page(int $conversationId, int $viewerId, bool $manager, array $opts): array
    {
        global $wpdb;
        $limit = min(100, max(1, (int) ($opts['limit'] ?? 50)));
        $before = max(0, (int) ($opts['before_id'] ?? 0));
        $after = max(0, (int) ($opts['after_id'] ?? 0));
        $kind = (string) ($opts['kind'] ?? '');
        $m = self::t('messages');

        $where = ['m.conversation_id=' . (int) $conversationId, self::visibleSql('m', $viewerId, $manager)];
        if ($before > 0) {
            $where[] = 'm.id<' . $before;
        }
        if ($after > 0) {
            $where[] = 'm.id>' . $after;
        }
        if (in_array($kind, [...self::KINDS, 'system'], true)) {
            $where[] = $wpdb->prepare('m.kind=%s', $kind);
        }
        if (!empty($opts['mine'])) {
            $tp = self::t('work_task_people');
            $mm = self::t('message_mentions');
            $uid = (int) $viewerId;
            $where[] = "m.kind<>'system' AND (EXISTS (SELECT 1 FROM {$tp} tp WHERE tp.message_id=m.id AND tp.user_id={$uid}) OR EXISTS (SELECT 1 FROM {$mm} mn WHERE mn.message_id=m.id AND mn.user_id={$uid}))";
        }

        // Newest-first window (or oldest-first catch-up), one extra row to learn if more exist.
        $order = $after > 0 ? 'ASC' : 'DESC';
        $rows = $wpdb->get_results(
            "SELECT m.* FROM {$m} m WHERE " . implode(' AND ', $where) . " ORDER BY m.id {$order} LIMIT " . ($limit + 1)
        ) ?: [];

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        if ($order === 'DESC') {
            $rows = array_reverse($rows);
        }

        return [
            'items' => self::hydrate($rows, $conversationId, $viewerId),
            // For history paging: cursor is the oldest id on this page. For catch-up: newest.
            'next_cursor' => $hasMore && $rows ? (string) ($after > 0 ? end($rows)->id : $rows[0]->id) : null,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function one(int $messageId, int $viewerId, bool $manager): ?array
    {
        $row = self::row($messageId);
        if (!$row) {
            return null;
        }
        global $wpdb;
        $visible = $wpdb->get_var('SELECT 1 FROM ' . self::t('messages') . ' m WHERE m.id=' . (int) $messageId . ' AND ' . self::visibleSql('m', $viewerId, $manager));
        if (!$visible) {
            return null;
        }
        return self::hydrate([$row], (int) $row->conversation_id, $viewerId)[0] ?? null;
    }

    /**
     * @param object[] $rows
     * @return array<int,array<string,mixed>>
     */
    public static function hydrate(array $rows, int $conversationId, int $viewerId): array
    {
        if (!$rows) {
            return [];
        }
        global $wpdb;
        $viewer = (int) $viewerId;

        $ids = array_map(static fn($r): int => (int) $r->id, $rows);
        $byKind = static function (string $kind) use ($rows): array {
            return array_values(array_map(
                static fn($r): int => (int) $r->id,
                array_filter($rows, static fn($r): bool => $r->kind === $kind)
            ));
        };
        $taskIds = $byKind('task');
        $meetingIds = $byKind('meeting');
        $annIds = $byKind('announcement');
        $pollIds = $byKind('poll');

        // 1. Reply / system targets.
        $refIds = array_values(array_unique(array_filter(array_map(static fn($r): int => (int) $r->reply_to_id, $rows))));
        $refs = [];
        if ($refIds) {
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT id,sender_user_id,kind,body,payload_json,deleted_at FROM ' . self::t('messages') . ' WHERE id IN (' . self::marks($refIds) . ')',
                ...$refIds
            )) ?: [] as $ref) {
                $refs[(int) $ref->id] = $ref;
            }
        }

        // 2. Reactions (grouped) with the viewer's own flag.
        $reactions = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT message_id, reaction, COUNT(*) AS c, SUM(user_id=%d) AS mine FROM ' . self::t('reactions') . ' WHERE message_id IN (' . self::marks($ids) . ') GROUP BY message_id, reaction ORDER BY MIN(created_at) ASC',
            $viewer,
            ...$ids
        )) ?: [] as $r) {
            $reactions[(int) $r->message_id][] = ['emoji' => (string) $r->reaction, 'count' => (int) $r->c, 'mine' => (int) $r->mine > 0];
        }

        // 3. Mentions.
        $mentions = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT message_id,user_id FROM ' . self::t('message_mentions') . ' WHERE message_id IN (' . self::marks($ids) . ')',
            ...$ids
        )) ?: [] as $r) {
            $mentions[(int) $r->message_id][] = (int) $r->user_id;
        }

        // 4-5. Task people + checklist.
        $people = [];
        $items = [];
        if ($taskIds) {
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT message_id,user_id,role FROM ' . self::t('work_task_people') . ' WHERE message_id IN (' . self::marks($taskIds) . ') ORDER BY created_at ASC',
                ...$taskIds
            )) ?: [] as $r) {
                $people[(int) $r->message_id][(string) $r->role][] = (int) $r->user_id;
            }
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT id,message_id,title,done FROM ' . self::t('work_task_items') . ' WHERE message_id IN (' . self::marks($taskIds) . ') ORDER BY sort ASC, id ASC',
                ...$taskIds
            )) ?: [] as $r) {
                $items[(int) $r->message_id][] = ['id' => (string) $r->id, 'title' => (string) $r->title, 'done' => (bool) $r->done];
            }
        }

        // 6. RSVPs.
        $rsvp = [];
        if ($meetingIds) {
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT message_id,response,COUNT(*) AS c,SUM(user_id=%d) AS mine FROM ' . self::t('work_meeting_rsvps') . ' WHERE message_id IN (' . self::marks($meetingIds) . ') GROUP BY message_id,response',
                $viewer,
                ...$meetingIds
            )) ?: [] as $r) {
                $rsvp[(int) $r->message_id][(string) $r->response] = ['c' => (int) $r->c, 'mine' => (int) $r->mine > 0];
            }
        }

        // 6b. Invited people of private meetings (capped per meeting; visible only to those who can see the meeting).
        $invited = [];
        $privateMeetings = array_values(array_map(
            static fn($r): int => (int) $r->id,
            array_filter($rows, static fn($r): bool => $r->kind === 'meeting' && (int) $r->is_private === 1)
        ));
        if ($privateMeetings) {
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT message_id,user_id FROM ' . self::t('message_audience') . ' WHERE message_id IN (' . self::marks($privateMeetings) . ')',
                ...$privateMeetings
            )) ?: [] as $r) {
                $list = &$invited[(int) $r->message_id];
                $list = $list ?? [];
                if (count($list) < 12) {
                    $list[] = (int) $r->user_id;
                }
                unset($list);
            }
        }

        // 7. Seen counts.
        $seen = [];
        $memberTotal = 0;
        if ($annIds) {
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT message_id,COUNT(*) AS c,SUM(user_id=%d) AS mine FROM ' . self::t('work_announcement_seen') . ' WHERE message_id IN (' . self::marks($annIds) . ') GROUP BY message_id',
                $viewer,
                ...$annIds
            )) ?: [] as $r) {
                $seen[(int) $r->message_id] = ['c' => (int) $r->c, 'mine' => (int) $r->mine > 0];
            }
            $memberTotal = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::t('participants') . ' WHERE conversation_id=%d AND archived_at IS NULL',
                $conversationId
            ));
        }

        // 8. Poll tallies.
        $votes = [];
        if ($pollIds) {
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT message_id,option_index,COUNT(*) AS c,SUM(user_id=%d) AS mine FROM ' . self::t('work_poll_votes') . ' WHERE message_id IN (' . self::marks($pollIds) . ') GROUP BY message_id,option_index',
                $viewer,
                ...$pollIds
            )) ?: [] as $r) {
                $votes[(int) $r->message_id][(int) $r->option_index] = ['c' => (int) $r->c, 'mine' => (int) $r->mine > 0];
            }
        }

        // 9. Reply counts (replies by members to manager messages).
        $replyCounts = [];
        $parents = array_values(array_unique(array_merge($taskIds, $meetingIds, $annIds, $pollIds, $byKind('text'))));
        if ($parents) {
            foreach ($wpdb->get_results($wpdb->prepare(
                "SELECT reply_to_id, COUNT(*) AS c FROM " . self::t('messages') . " WHERE reply_to_id IN (" . self::marks($parents) . ") AND kind='text' AND deleted_at IS NULL GROUP BY reply_to_id",
                ...$parents
            )) ?: [] as $r) {
                $replyCounts[(int) $r->reply_to_id] = (int) $r->c;
            }
        }

        // 10. Users — one priming pass for everyone referenced.
        $userIds = [];
        foreach ($rows as $r) {
            $userIds[] = (int) $r->sender_user_id;
            if ($r->kind === 'system') {
                $sys = json_decode((string) $r->payload_json, true);
                $userIds[] = (int) ($sys['target_user_id'] ?? 0);
            }
        }
        foreach ($refs as $ref) {
            $userIds[] = (int) $ref->sender_user_id;
        }
        foreach ($mentions as $list) {
            array_push($userIds, ...$list);
        }
        foreach ($people as $byRole) {
            foreach ($byRole as $list) {
                array_push($userIds, ...$list);
            }
        }
        foreach ($invited as $list) {
            array_push($userIds, ...$list);
        }
        $users = WorkUsers::summaries($userIds, $conversationId);
        $roleIds = array_values(array_unique(array_merge([$viewerId], array_map(static fn($r): int => (int) $r->sender_user_id, $rows))));
        $roles = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT user_id,role FROM ' . self::t('participants') . ' WHERE conversation_id=%d AND archived_at IS NULL AND user_id IN (' . self::marks($roleIds) . ')', $conversationId, ...$roleIds)) ?: [] as $participant) {
            $roles[(int) $participant->user_id] = (string) $participant->role;
        }
        $viewerManager = WorkGroups::canManage($roles[$viewerId] ?? null, $viewerId);
        $user = static fn(int $id): ?array => $users[$id] ?? null;

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            $deleted = $r->deleted_at !== null;
            $payload = $r->payload_json ? (json_decode((string) $r->payload_json, true) ?: []) : [];

            $reply = null;
            if ((int) $r->reply_to_id > 0 && isset($refs[(int) $r->reply_to_id]) && $r->kind !== 'system') {
                $ref = $refs[(int) $r->reply_to_id];
                $refPayload = $ref->payload_json ? (json_decode((string) $ref->payload_json, true) ?: []) : [];
                $reply = [
                    'id' => (string) $ref->id,
                    'kind' => (string) $ref->kind,
                    'title' => (string) ($refPayload['title'] ?? ''),
                    'body' => $ref->deleted_at ? '' : mb_substr((string) $ref->body, 0, 160),
                    'sender_name' => (string) (($user((int) $ref->sender_user_id)['name'] ?? 'کاربر')),
                    'sender_label' => $user((int) $ref->sender_user_id)['work_label'] ?? null,
                ];
            }

            $msg = [
                'id' => (string) $id,
                'conversation_id' => (string) $r->conversation_id,
                'kind' => (string) $r->kind,
                'client_id' => (string) $r->client_id,
                'can_reply' => !$deleted && $r->kind !== 'system' && ($viewerManager || WorkGroups::canManage($roles[(int) $r->sender_user_id] ?? null, (int) $r->sender_user_id)),
                'sender' => $user((int) $r->sender_user_id),
                'sender_role' => $roles[(int) $r->sender_user_id] ?? null,
                'body' => $deleted ? '' : (string) $r->body,
                'created_at' => self::iso((string) $r->created_at),
                'edited_at' => self::iso($r->edited_at),
                'deleted_at' => self::iso($r->deleted_at),
                'is_private' => (bool) $r->is_private,
                'pinned' => $r->pinned_at !== null,
                'reply_to' => $reply,
                'reply_count' => $replyCounts[$id] ?? 0,
                'attachment' => !$deleted && $r->attachment_json ? json_decode((string) $r->attachment_json, true) : null,
                'reactions' => $reactions[$id] ?? [],
                'mentions' => array_values(array_filter(array_map($user, $mentions[$id] ?? []))),
            ];

            if ($r->kind === 'system') {
                $ref = (int) $r->reply_to_id > 0 ? ($refs[(int) $r->reply_to_id] ?? null) : null;
                $refPayload = $ref && $ref->payload_json ? (json_decode((string) $ref->payload_json, true) ?: []) : [];
                $msg['system'] = [
                    'action' => (string) ($payload['action'] ?? ''),
                    'target_user' => $user((int) ($payload['target_user_id'] ?? 0)),
                    'target_message_id' => $ref ? (string) $ref->id : null,
                    'target_title' => $ref ? (string) ($refPayload['title'] ?? mb_substr((string) $ref->body, 0, 60)) : null,
                    'extra' => $payload['extra'] ?? null,
                ];
            } elseif (!$deleted) {
                if ($r->kind === 'task') {
                    $assignees = $people[$id]['assignee'] ?? [];
                    $volunteers = $people[$id]['volunteer'] ?? [];
                    $capacity = (int) ($payload['capacity'] ?? 0);
                    $status = (string) ($r->task_status ?: 'todo');
                    $msg['task'] = [
                        'title' => (string) ($payload['title'] ?? ''),
                        'status' => $status,
                        'priority' => (string) ($payload['priority'] ?? 'normal'),
                        'due_at' => self::iso($r->due_at),
                        'late' => $r->due_at !== null && in_array($status, ['todo', 'doing'], true) && strtotime((string) $r->due_at . ' UTC') < time(),
                        'soon' => $r->due_at !== null && in_array($status, ['todo', 'doing'], true) && ($dueTs = (int) strtotime((string) $r->due_at . ' UTC')) >= time() && $dueTs - time() < 21600,
                        'capacity' => $capacity,
                        'assignees' => array_values(array_filter(array_map($user, $assignees))),
                        'volunteers' => array_values(array_filter(array_map($user, $volunteers))),
                        'open_slots' => $assignees ? 0 : ($capacity > 0 ? max(0, $capacity - count($volunteers)) : null),
                        'items' => $items[$id] ?? [],
                        'viewer_is_responsible' => in_array($viewer, $assignees, true) || in_array($viewer, $volunteers, true),
                    ];
                } elseif ($r->kind === 'meeting') {
                    $msg['meeting'] = [
                        'title' => (string) ($payload['title'] ?? ''),
                        'when' => (string) ($payload['when'] ?? ''),
                        'place' => (string) ($payload['place'] ?? ''),
                        'agenda' => (string) ($payload['agenda'] ?? ''),
                        'going' => (int) ($rsvp[$id]['yes']['c'] ?? 0),
                        'not_going' => (int) ($rsvp[$id]['no']['c'] ?? 0),
                        'invited' => array_values(array_filter(array_map($user, $invited[$id] ?? []))),
                        'my_response' => !empty($rsvp[$id]['yes']['mine']) ? 'yes' : (!empty($rsvp[$id]['no']['mine']) ? 'no' : null),
                    ];
                } elseif ($r->kind === 'announcement') {
                    $msg['announcement'] = [
                        'title' => (string) ($payload['title'] ?? ''),
                        'urgent' => (bool) ($payload['urgent'] ?? false),
                        'important' => (bool) ($payload['important'] ?? false),
                        'seen_count' => (int) ($seen[$id]['c'] ?? 0),
                        'member_total' => $memberTotal,
                        'seen_by_me' => (bool) ($seen[$id]['mine'] ?? false),
                    ];
                } elseif ($r->kind === 'poll') {
                    $options = [];
                    $total = 0;
                    foreach ((array) ($payload['options'] ?? []) as $i => $text) {
                        $c = (int) ($votes[$id][$i]['c'] ?? 0);
                        $total += $c;
                        $options[] = ['text' => (string) $text, 'votes' => $c];
                    }
                    $mine = null;
                    foreach ($votes[$id] ?? [] as $i => $v) {
                        if ($v['mine']) {
                            $mine = (int) $i;
                        }
                    }
                    $msg['poll'] = ['question' => (string) ($payload['title'] ?? ''), 'options' => $options, 'total' => $total, 'my_vote' => $mine];
                }
            }
            $out[] = $msg;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Writing                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Append a service line ("X joined", "X started the task"). It inherits the
     * privacy of the message it refers to so private meetings stay private.
     *
     * @param array<string,mixed> $extra
     */
    public static function system(int $conversationId, int $actorUserId, string $action, int $targetMessageId = 0, int $targetUserId = 0, array $extra = []): void
    {
        global $wpdb;
        $private = 0;
        $root = null;
        if ($targetMessageId > 0) {
            $target = $wpdb->get_row($wpdb->prepare(
                'SELECT id,is_private,thread_root_id FROM ' . self::t('messages') . ' WHERE id=%d LIMIT 1',
                $targetMessageId
            ));
            if ($target) {
                $private = (int) $target->is_private;
                $root = $private ? (int) ($target->thread_root_id ?: $target->id) : null;
            }
        }
        $now = current_time('mysql', true);
        $wpdb->insert(self::t('messages'), [
            'conversation_id' => $conversationId,
            'sender_user_id' => $actorUserId,
            'client_id' => 'sys-' . wp_generate_uuid4(),
            'body' => '',
            'kind' => 'system',
            'reply_to_id' => $targetMessageId > 0 ? $targetMessageId : null,
            'thread_root_id' => $root,
            'is_private' => $private,
            'payload_json' => wp_json_encode(['action' => $action, 'target_user_id' => $targetUserId ?: null, 'extra' => $extra ?: null]),
            'created_at' => $now,
        ]);
        $messageId = (int) $wpdb->insert_id;
        if ($messageId > 0 && !$private) {
            self::touch($conversationId, $messageId, $now);
        }
        self::broadcast($conversationId, 'work:message:created', $messageId, $private ? $root : null);
    }

    public static function touch(int $conversationId, int $messageId, ?string $now = null): void
    {
        global $wpdb;
        $wpdb->update(
            self::t('conversations'),
            ['last_message_id' => $messageId, 'updated_at' => $now ?: current_time('mysql', true)],
            ['id' => $conversationId]
        );
    }

    /**
     * Realtime fan-out to the people who can see the message. The payload only
     * carries ids; clients fetch the message (or catch up with after_id).
     */
    public static function broadcast(int $conversationId, string $event, int $messageId, ?int $privateRootId = null): void
    {
        global $wpdb;
        if ($privateRootId) {
            $audience = array_map('intval', $wpdb->get_col($wpdb->prepare(
                'SELECT user_id FROM ' . self::t('message_audience') . ' WHERE message_id=%d',
                $privateRootId
            )) ?: []);
            $userIds = array_values(array_unique(array_merge($audience, WorkGroups::managerIds($conversationId))));
        } else {
            $userIds = self::memberIds($conversationId);
        }
        AsyncDispatcher::queueRealtimeToUsers($userIds, $event, [
            'workId' => (string) $conversationId,
            'messageId' => (string) $messageId,
        ]);
    }

    /**
     * @param array<string,mixed> $conversation conversation row
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error hydrated message
     */
    public static function create(array $conversation, int $userId, ?string $role, array $input): array|WP_Error
    {
        global $wpdb;
        $conversationId = (int) $conversation['id'];
        $manager = WorkGroups::canManage($role, $userId);
        $kind = sanitize_key((string) ($input['kind'] ?? 'text'));
        if (!in_array($kind, self::KINDS, true)) {
            return new WP_Error('invalid_kind', 'نوع پیام معتبر نیست.', ['status' => 422]);
        }

        $clientId = sanitize_text_field((string) ($input['client_id'] ?? ''));
        if ($clientId === '' || strlen($clientId) > 80) {
            return new WP_Error('invalid_client_id', 'شناسه پیام معتبر نیست.', ['status' => 422]);
        }

        // Idempotent retry: same sender + client id returns the stored message.
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::t('messages') . ' WHERE sender_user_id=%d AND client_id=%s LIMIT 1',
            $userId,
            $clientId
        ));
        if ($existing > 0 && (int) self::row($existing)->conversation_id !== $conversationId) {
            return new WP_Error('client_id_conflict', 'شناسه پیام قبلاً استفاده شده است.', ['status' => 409]);
        }
        if ($existing > 0) {
            return self::one($existing, $userId, $manager) ?? new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]);
        }

        $body = sanitize_textarea_field((string) ($input['body'] ?? ''));
        $attachment = null;
        if (!empty($input['attachment']['id'])) {
            $mediaId = (int) $input['attachment']['id'];
            $media = get_post($mediaId);
            if (!$media || $media->post_type !== 'attachment' || ((int) $media->post_author !== $userId && !WorkGroups::isSiteAdmin($userId))) {
                return new WP_Error('invalid_attachment', 'فایل پیوست معتبر نیست.', ['status' => 422]);
            }
            $attachment = ['id' => (string) $mediaId, 'name' => sanitize_text_field((string) ($input['attachment']['name'] ?? $media->post_title)), 'mime_type' => (string) $media->post_mime_type, 'url' => (string) wp_get_attachment_url($mediaId)];
        }
        $replyTo = max(0, (int) ($input['reply_to_id'] ?? 0));

        // Members can only answer a manager's message.
        $parent = null;
        if ($replyTo > 0) {
            $parent = self::row($replyTo);
            if (!$parent || (int) $parent->conversation_id !== $conversationId || $parent->deleted_at !== null || $parent->kind === 'system') {
                return new WP_Error('invalid_message_reference', 'پیام ارجاع‌شده معتبر نیست.', ['status' => 422]);
            }
            $visible = $wpdb->get_var('SELECT 1 FROM ' . self::t('messages') . ' m WHERE m.id=' . $replyTo . ' AND ' . self::visibleSql('m', $userId, $manager));
            if (!$visible) {
                return new WP_Error('invalid_message_reference', 'پیام ارجاع‌شده معتبر نیست.', ['status' => 422]);
            }
        }
        if (!$manager) {
            if ($kind !== 'text' || !$parent) {
                return new WP_Error('work_read_only', 'در این کار فقط مدیر و ادمین‌ها پیام می‌گذارند؛ شما می‌توانید به پیام‌ها پاسخ بدهید.', ['status' => 403]);
            }
            $parentRole = WorkGroups::role($conversationId, (int) $parent->sender_user_id);
            if (!WorkGroups::isManagerRole($parentRole) && !WorkGroups::isSiteAdmin((int) $parent->sender_user_id)) {
                return new WP_Error('work_reply_manager_only', 'فقط می‌توانید به پیام‌های مدیر پاسخ بدهید.', ['status' => 403]);
            }
        }

        $payload = [];
        $dueAt = null;
        $taskStatus = null;
        $assignees = [];
        $itemTitles = [];
        $audience = [];
        $pinned = false;

        if ($kind === 'text') {
            if ($body === '' && !$attachment) {
                return new WP_Error('empty_message', 'پیام نمی‌تواند خالی باشد.', ['status' => 422]);
            }
        } else {
            $spec = (array) ($input[$kind === 'announcement' ? 'announcement' : $kind] ?? []);
            $title = mb_substr(sanitize_text_field((string) ($spec['title'] ?? $input['title'] ?? '')), 0, 190);
            if ($title === '') {
                return new WP_Error('title_required', $kind === 'poll' ? 'پرسش را بنویسید.' : 'عنوان را بنویسید.', ['status' => 422]);
            }
            $payload['title'] = $title;

            if ($kind === 'task') {
                $priority = sanitize_key((string) ($spec['priority'] ?? 'normal'));
                $payload['priority'] = in_array($priority, self::PRIORITIES, true) ? $priority : 'normal';
                $payload['capacity'] = max(0, (int) ($spec['capacity'] ?? 0));
                $due = trim((string) ($spec['due_at'] ?? ''));
                if ($due !== '') {
                    $ts = strtotime($due);
                    if ($ts === false) {
                        return new WP_Error('invalid_due', 'مهلت معتبر نیست.', ['status' => 422]);
                    }
                    $dueAt = gmdate('Y-m-d H:i:s', $ts);
                }
                $taskStatus = 'todo';
                $assignees = self::membersOnly($conversationId, (array) ($spec['assignee_ids'] ?? []));
                foreach ((array) ($spec['items'] ?? []) as $t) {
                    $t = mb_substr(sanitize_text_field((string) $t), 0, 255);
                    if ($t !== '') {
                        $itemTitles[] = $t;
                    }
                }
            } elseif ($kind === 'meeting') {
                $payload['when'] = mb_substr(sanitize_text_field((string) ($spec['when'] ?? '')), 0, 190);
                $payload['place'] = mb_substr(sanitize_text_field((string) ($spec['place'] ?? '')), 0, 190);
                $payload['agenda'] = mb_substr(sanitize_text_field((string) ($spec['agenda'] ?? '')), 0, 500);
                $audience = self::membersOnly($conversationId, (array) ($spec['private_user_ids'] ?? []));
                if (array_key_exists('private_user_ids', $spec) && (array) $spec['private_user_ids'] && !$audience) {
                    return new WP_Error('invalid_audience', 'افراد جلسه خصوصی باید عضو این کار باشند.', ['status' => 422]);
                }
            } elseif ($kind === 'announcement') {
                $payload['urgent'] = !empty($spec['urgent']);
                $payload['important'] = !empty($spec['important']);
                $pinned = !empty($spec['pinned']);
            } elseif ($kind === 'poll') {
                $provided = array_values((array) ($spec['options'] ?? []));
                if (trim(sanitize_text_field((string) ($provided[0] ?? ''))) === '' || trim(sanitize_text_field((string) ($provided[1] ?? ''))) === '') {
                    return new WP_Error('poll_options', 'گزینه اول و دوم الزامی هستند.', ['status' => 422]);
                }
                $options = [];
                foreach ((array) ($spec['options'] ?? []) as $o) {
                    $o = mb_substr(sanitize_text_field((string) $o), 0, 190);
                    if ($o !== '') {
                        $options[] = $o;
                    }
                }
                if (count($options) < 2) {
                    return new WP_Error('poll_options', 'حداقل دو گزینه لازم است.', ['status' => 422]);
                }
                $payload['options'] = $options;
            }
        }

        $private = $audience ? 1 : 0;
        $root = null;
        if ($parent) {
            if ((int) $parent->is_private === 1) {
                $private = 1;
            }
            $root = (int) ($parent->thread_root_id ?: $parent->id);
        }

        // Mentions: members only; inside a private thread only its audience + managers.
        $mentionIds = self::membersOnly($conversationId, (array) ($input['mention_ids'] ?? []));
        if ($private && $mentionIds) {
            $rootId = $root ?: 0;
            $allowed = $audience;
            if (!$audience && $rootId) {
                $allowed = array_map('intval', $wpdb->get_col($wpdb->prepare(
                    'SELECT user_id FROM ' . self::t('message_audience') . ' WHERE message_id=%d',
                    $rootId
                )) ?: []);
            }
            $allowed = array_merge($allowed, WorkGroups::managerIds($conversationId));
            $mentionIds = array_values(array_intersect($mentionIds, $allowed));
        }

        $now = current_time('mysql', true);
        $ok = $wpdb->insert(self::t('messages'), [
            'conversation_id' => $conversationId,
            'sender_user_id' => $userId,
            'client_id' => $clientId,
            'body' => $body,
            'attachment_json' => $attachment ? wp_json_encode($attachment) : null,
            'kind' => $kind,
            'payload_json' => $payload ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'reply_to_id' => $replyTo ?: null,
            'thread_root_id' => $root,
            'is_private' => $private,
            'due_at' => $dueAt,
            'task_status' => $taskStatus,
            'created_at' => $now,
        ]);
        $messageId = (int) $wpdb->insert_id;
        if (!$ok || $messageId <= 0) {
            // A concurrent retry may have won the unique (sender, client_id) race.
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::t('messages') . ' WHERE sender_user_id=%d AND client_id=%s LIMIT 1',
                $userId,
                $clientId
            ));
            if ($existing > 0) {
                return self::one($existing, $userId, $manager) ?? new WP_Error('message_send_failed', 'ارسال پیام انجام نشد.', ['status' => 500]);
            }
            return new WP_Error('message_send_failed', 'ارسال پیام انجام نشد.', ['status' => 500]);
        }
        // Side tables — bulk inserts, one statement each.
        self::bulkInsert(self::t('message_mentions'), 'message_id,user_id', $messageId, $mentionIds);
        if ($audience) {
            self::bulkInsert(self::t('message_audience'), 'message_id,user_id', $messageId, $audience);
        }
        if ($assignees) {
            $rows = array_map(static fn(int $u): string => '(' . $messageId . ',' . $u . ",'assignee','" . esc_sql($now) . "')", $assignees);
            $wpdb->query('INSERT IGNORE INTO ' . self::t('work_task_people') . ' (message_id,user_id,role,created_at) VALUES ' . implode(',', $rows));
        }
        if ($itemTitles) {
            $rows = [];
            foreach ($itemTitles as $i => $t) {
                $rows[] = $wpdb->prepare('(%d,%s,%d)', $messageId, $t, $i);
            }
            $wpdb->query('INSERT INTO ' . self::t('work_task_items') . ' (message_id,title,sort) VALUES ' . implode(',', $rows));
        }
        if ($pinned) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::t('messages') . ' SET pinned_at=NULL WHERE conversation_id=%d AND pinned_at IS NOT NULL', $conversationId));
            $wpdb->update(self::t('messages'), ['pinned_at' => $now], ['id' => $messageId]);
        }
        if (!$private) {
            self::touch($conversationId, $messageId, $now);
        }

        self::broadcast($conversationId, 'work:message:created', $messageId, $private ? ($root ?: $messageId) : null);
        self::notifyCreated($conversation, $userId, $messageId, $kind, (string) ($payload['title'] ?? ''), $assignees, $audience, $mentionIds, (bool) $private, (bool) ($payload['urgent'] ?? false));

        return self::one($messageId, $userId, $manager) ?? new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]);
    }

    /**
     * @param int[] $ids candidate user ids
     * @return int[] those that are active members (one query)
     */
    public static function membersOnly(int $conversationId, array $ids): array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if (!$ids) {
            return [];
        }
        $ids = array_slice($ids, 0, 200);
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT user_id FROM ' . self::t('participants') . ' WHERE conversation_id=%d AND archived_at IS NULL AND user_id IN (' . self::marks($ids) . ')',
            $conversationId,
            ...$ids
        )) ?: []);
    }

    /** @param int[] $userIds */
    private static function bulkInsert(string $table, string $columns, int $messageId, array $userIds): void
    {
        global $wpdb;
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) {
            return;
        }
        $rows = array_map(static fn(int $u): string => '(' . $messageId . ',' . $u . ')', $userIds);
        $wpdb->query("INSERT IGNORE INTO {$table} ({$columns}) VALUES " . implode(',', $rows));
    }

    /* ------------------------------------------------------------------ */
    /* Notifications                                                      */
    /* ------------------------------------------------------------------ */

    /** @return array{0:string,1:string} [title, body] built from the template; stored text is only a fallback. */
    public static function copy(string $type, int $actorUserId, string $workTitle, string $subject = ''): array
    {
        $tpl = NotificationService::template($type);
        $actor = Actor::forUser($actorUserId);
        $body = str_replace('{actor}', (string) ($actor['display_name'] ?? 'یک کاربر'), $tpl['body']);
        if ($subject !== '') {
            $body .= ' «' . mb_substr($subject, 0, 80) . '»';
        }
        return [$workTitle !== '' ? $workTitle : $tpl['title'], $body];
    }

    /**
     * @param int[] $recipients
     * @param array<string,mixed> $extraPayload
     */
    public static function notify(array $conversation, int $actorUserId, string $type, array $recipients, int $messageId, string $subject = '', array $extraPayload = [], ?string $groupKey = null): void
    {
        if (!$recipients) {
            return;
        }
        $workId = (int) $conversation['id'];
        $workTitle = (string) ($conversation['title'] ?? '');
        [$title, $body] = self::copy($type, $actorUserId, $workTitle, $subject);
        $actor = self::actorRef($actorUserId);
        (new NotificationService())->createMany(
            $recipients,
            $type,
            $actor['type'],
            $actor['id'],
            'work',
            $workId,
            $title,
            $body,
            '/works/' . $workId . ($messageId > 0 ? '?m=' . $messageId : ''),
            $groupKey ?? ('work:' . $type . ':' . $messageId),
            array_merge(['work_id' => $workId, 'work_title' => $workTitle, 'message_id' => $messageId], $extraPayload),
        );
    }

    /**
     * @param int[] $assignees
     * @param int[] $audience private-meeting audience
     * @param int[] $mentions
     * @param array<string,mixed> $conversation
     */
    private static function notifyCreated(array $conversation, int $actorId, int $messageId, string $kind, string $title, array $assignees, array $audience, array $mentions, bool $private, bool $urgent): void
    {
        $conversationId = (int) $conversation['id'];
        $notified = [];

        if ($kind !== 'text') {
            if ($private) {
                $everyone = array_values(array_unique(array_merge($audience, WorkGroups::managerIds($conversationId))));
            } else {
                $everyone = self::memberIds($conversationId);
            }
            $everyone = array_values(array_diff($everyone, [$actorId]));

            $type = match ($kind) {
                'task' => 'work_task_created',
                'meeting' => 'work_meeting_created',
                'announcement' => 'work_announcement',
                default => 'work_poll_created',
            };
            if ($kind === 'task' && $assignees) {
                $assigned = array_values(array_diff($assignees, [$actorId]));
                self::notify($conversation, $actorId, 'work_task_assigned', $assigned, $messageId, $title);
                $everyone = array_values(array_diff($everyone, $assigned));
            }
            self::notify($conversation, $actorId, $type, $everyone, $messageId, $title, $urgent ? ['urgent' => true] : []);
            $notified = array_merge($notified, $everyone, $kind === 'task' ? $assignees : []);
        }

        // Plain admin text only notifies the people it mentions.
        $mentioned = array_values(array_diff($mentions, [$actorId], $notified));
        self::notify($conversation, $actorId, 'work_mention', $mentioned, $messageId);
    }
}

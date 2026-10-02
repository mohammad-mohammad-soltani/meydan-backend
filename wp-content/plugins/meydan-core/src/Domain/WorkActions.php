<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use WP_Error;

/**
 * State changes on work messages: reactions, edits, task lifecycle, meeting
 * RSVPs, announcement "seen", polls and role changes. Every action checks the
 * viewer's role, writes the minimum rows, appends a service line where the
 * mock shows one, notifies and pushes a realtime update.
 */
final class WorkActions
{
    private const REMIND_COOLDOWN = 900;

    /**
     * Load message + work + the viewer's standing in one place.
     *
     * @return array{row:object,conv:array<string,mixed>,role:?string,manager:bool}|WP_Error
     */
    private static function ctx(int $messageId, int $userId, ?string $kind = null): array|WP_Error
    {
        global $wpdb;
        $row = WorkMessages::row($messageId);
        $notFound = new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]);
        if (!$row || $row->kind === 'system' || ($kind !== null && $row->kind !== $kind)) {
            return $notFound;
        }
        $conv = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . WorkGroups::table('conversations') . " WHERE id=%d AND type='work' LIMIT 1",
            (int) $row->conversation_id
        ), ARRAY_A);
        if (!$conv) {
            return $notFound;
        }
        $role = WorkGroups::role((int) $conv['id'], $userId);
        $manager = WorkGroups::canManage($role, $userId);
        $visible = $wpdb->get_var('SELECT 1 FROM ' . WorkGroups::table('messages') . ' m WHERE m.id=' . $messageId . ' AND ' . WorkMessages::visibleSql('m', $userId, $manager));
        if (!$visible) {
            return $notFound;
        }
        return ['row' => $row, 'conv' => $conv, 'role' => $role, 'manager' => $manager];
    }

    private static function requireMember(?string $role, bool $manager): ?WP_Error
    {
        return ($role === null && !$manager)
            ? new WP_Error('work_join_required', 'اول به این کار بپیوندید.', ['status' => 403])
            : null;
    }

    private static function notifyChanged(array $context, int $userId): void
    {
        $row = $context['row'];
        if (!$context['manager'] || $row->kind === 'text') return;
        $recipients = WorkMessages::memberIds((int) $row->conversation_id);
        if ((int) $row->is_private === 1) {
            global $wpdb;
            $audience = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . WorkGroups::table('message_audience') . ' WHERE message_id=%d', (int) ($row->thread_root_id ?: $row->id))) ?: []);
            $recipients = array_unique(array_merge($audience, WorkGroups::managerIds((int) $row->conversation_id)));
        }
        $payload = json_decode((string) $row->payload_json, true) ?: [];
        WorkMessages::notify($context['conv'], $userId, 'work_message_updated', array_diff($recipients, [$userId]), (int) $row->id, (string) ($payload['title'] ?? ''), [], 'work:edit:' . $row->id . ':' . wp_generate_uuid4());
    }

    private static function rootOf(object $row): ?int
    {
        return (int) $row->is_private === 1 ? (int) ($row->thread_root_id ?: $row->id) : null;
    }

    /** @return array<string,mixed>|WP_Error */
    private static function updated(object $row, int $userId, bool $manager): array|WP_Error
    {
        WorkMessages::broadcast((int) $row->conversation_id, 'work:message:updated', (int) $row->id, self::rootOf($row));
        return WorkMessages::one((int) $row->id, $userId, $manager) ?? new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]);
    }

    /** @return array<string,mixed>|WP_Error */
    public static function message(int $messageId, int $userId): array|WP_Error
    {
        $c = self::ctx($messageId, $userId);
        if ($c instanceof WP_Error) {
            return $c;
        }
        return WorkMessages::one($messageId, $userId, $c['manager']) ?? new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string,mixed>|WP_Error */
    public static function react(int $messageId, int $userId, string $emoji, bool $active): array|WP_Error
    {
        $c = self::ctx($messageId, $userId);
        if ($c instanceof WP_Error) {
            return $c;
        }
        if ($err = self::requireMember($c['role'], $c['manager'])) {
            return $err;
        }
        $emoji = mb_substr(sanitize_text_field($emoji), 0, 16);
        if ($emoji === '' || $c['row']->deleted_at !== null) {
            return new WP_Error('invalid_reaction', 'واکنش معتبر نیست.', ['status' => 422]);
        }
        global $wpdb;
        $table = WorkGroups::table('reactions');
        if ($active) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (message_id,user_id,reaction,created_at) VALUES (%d,%d,%s,%s)",
                $messageId,
                $userId,
                $emoji,
                current_time('mysql', true)
            ));
        } else {
            $wpdb->delete($table, ['message_id' => $messageId, 'user_id' => $userId, 'reaction' => $emoji]);
        }
        return self::updated($c['row'], $userId, $c['manager']);
    }

    /** @return array<string,mixed>|WP_Error */
    public static function edit(int $messageId, int $userId, array $input): array|WP_Error
    {
        $c = self::ctx($messageId, $userId);
        if ($c instanceof WP_Error) {
            return $c;
        }
        if ($err = self::requireMember($c['role'], $c['manager'])) return $err;
        $row = $c['row'];
        if ($row->deleted_at !== null || ((int) $row->sender_user_id !== $userId && !$c['manager'])) {
            return new WP_Error('forbidden', 'اجازه ویرایش این پیام را ندارید.', ['status' => 403]);
        }
        global $wpdb;
        $update = ['edited_at' => current_time('mysql', true)];
        if (array_key_exists('body', $input)) {
            $body = sanitize_textarea_field((string) $input['body']);
            if ($body === '' && $row->kind === 'text' && !$row->attachment_json) {
                return new WP_Error('empty_message', 'پیام نمی‌تواند خالی باشد.', ['status' => 422]);
            }
            $update['body'] = $body;
        }
        if (isset($input['title']) && $row->kind !== 'text') {
            $payload = $row->payload_json ? (json_decode((string) $row->payload_json, true) ?: []) : [];
            $title = mb_substr(sanitize_text_field((string) $input['title']), 0, 190);
            if ($title === '') {
                return new WP_Error('title_required', 'عنوان را بنویسید.', ['status' => 422]);
            }
            $payload['title'] = $title;
            $update['payload_json'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        $wpdb->update(WorkGroups::table('messages'), $update, ['id' => $messageId]);
        self::notifyChanged($c, $userId);
        return self::updated(WorkMessages::row($messageId) ?? $row, $userId, $c['manager']);
    }

    public static function delete(int $messageId, int $userId): true|WP_Error
    {
        $c = self::ctx($messageId, $userId);
        if ($c instanceof WP_Error) {
            return $c;
        }
        if ($err = self::requireMember($c['role'], $c['manager'])) return $err;
        $row = $c['row'];
        if ((int) $row->sender_user_id !== $userId && !$c['manager']) {
            return new WP_Error('forbidden', 'اجازه حذف این پیام را ندارید.', ['status' => 403]);
        }
        global $wpdb;
        $wpdb->update(WorkGroups::table('messages'), [
            'body' => '',
            'attachment_json' => null,
            'deleted_at' => current_time('mysql', true),
            'pinned_at' => null,
        ], ['id' => $messageId]);
        self::notifyChanged($c, $userId);
        WorkMessages::broadcast((int) $row->conversation_id, 'work:message:deleted', $messageId, self::rootOf($row));
        return true;
    }

    /* --------------------------- tasks ------------------------------- */

    /** @return array<string,mixed>|WP_Error */
    public static function claim(int $messageId, int $userId, bool $on): array|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'task');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if ($err = self::requireMember($c['role'], $c['manager'])) {
            return $err;
        }
        $row = $c['row'];
        global $wpdb;
        $people = WorkGroups::table('work_task_people');
        $convId = (int) $row->conversation_id;

        if (!$on) {
            $removed = $wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE message_id=%d AND user_id=%d AND role='volunteer'", $messageId, $userId));
            if ($removed) {
                WorkMessages::system($convId, $userId, 'task_dropped', $messageId);
            }
            return self::updated($row, $userId, $c['manager']);
        }

        if (in_array((string) $row->task_status, ['done', 'ok'], true) || $row->deleted_at !== null) {
            return new WP_Error('task_closed', 'این وظیفه بسته شده است.', ['status' => 409]);
        }

        // Capacity must hold under concurrency: serialise on the task row.
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare('SELECT task_status,payload_json,deleted_at FROM ' . WorkGroups::table('messages') . ' WHERE id=%d FOR UPDATE', $messageId));
        if (!$locked || $locked->deleted_at !== null || in_array((string) $locked->task_status, ['done', 'ok'], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('task_closed', 'این وظیفه بسته شده است.', ['status' => 409]);
        }
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(role='assignee') AS a, SUM(role='volunteer') AS v, SUM(user_id=%d) AS me FROM {$people} WHERE message_id=%d",
            $userId,
            $messageId
        ));
        $payload = $locked->payload_json ? (json_decode((string) $locked->payload_json, true) ?: []) : [];
        $capacity = (int) ($payload['capacity'] ?? 0);
        $error = null;
        if ((int) ($counts->me ?? 0) > 0) {
            $error = null; // already on the task: idempotent
        } elseif ((int) ($counts->a ?? 0) > 0) {
            $error = new WP_Error('task_assigned', 'برای این وظیفه مسئول تعیین شده است.', ['status' => 409]);
        } elseif ($capacity > 0 && (int) ($counts->v ?? 0) >= $capacity) {
            $error = new WP_Error('task_full', 'ظرفیت این وظیفه پر شده است.', ['status' => 409]);
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$people} (message_id,user_id,role,created_at) VALUES (%d,%d,'volunteer',%s)",
                $messageId,
                $userId,
                current_time('mysql', true)
            ));
        }
        $wpdb->query($error ? 'ROLLBACK' : 'COMMIT');
        if ($error) {
            return $error;
        }
        if ((int) ($counts->me ?? 0) === 0) {
            WorkMessages::system($convId, $userId, 'task_claimed', $messageId);
        }
        return self::updated($row, $userId, $c['manager']);
    }

    /** @return array<string,mixed>|WP_Error */
    public static function status(int $messageId, int $userId, string $action): array|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'task');
        if ($c instanceof WP_Error) {
            return $c;
        }
        $row = $c['row'];
        $manager = $c['manager'];
        if ($row->deleted_at !== null) {
            return new WP_Error('task_closed', 'این وظیفه حذف شده است.', ['status' => 409]);
        }

        global $wpdb;
        $mine = (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . WorkGroups::table('work_task_people') . ' t INNER JOIN ' . WorkGroups::table('messages') . ' m ON m.id=t.message_id INNER JOIN ' . WorkGroups::table('participants') . ' p ON p.conversation_id=m.conversation_id AND p.user_id=t.user_id AND p.archived_at IS NULL WHERE t.message_id=%d AND t.user_id=%d LIMIT 1',
            $messageId,
            $userId
        ));
        $current = (string) ($row->task_status ?: 'todo');

        $map = [
            'start' => ['to' => 'doing', 'from' => ['todo'], 'who' => 'responsible', 'sys' => 'task_started'],
            'pause' => ['to' => 'todo', 'from' => ['doing'], 'who' => 'responsible', 'sys' => 'task_paused'],
            'finish' => ['to' => 'done', 'from' => ['todo', 'doing'], 'who' => 'responsible', 'sys' => 'task_done'],
            'approve' => ['to' => 'ok', 'from' => ['done'], 'who' => 'manager', 'sys' => 'task_approved'],
            'reopen' => ['to' => 'doing', 'from' => ['done', 'ok'], 'who' => 'manager', 'sys' => 'task_reopened'],
        ];
        if (!isset($map[$action])) {
            return new WP_Error('invalid_action', 'عملیات معتبر نیست.', ['status' => 422]);
        }
        $rule = $map[$action];
        $allowed = $rule['who'] === 'manager' ? $manager : ($mine || $manager);
        if (!$allowed) {
            return new WP_Error('forbidden', 'فقط مسئول وظیفه یا مدیر می‌تواند این کار را بکند.', ['status' => 403]);
        }
        if (!in_array($current, $rule['from'], true)) {
            return new WP_Error('invalid_transition', 'در وضعیت فعلی این عملیات ممکن نیست.', ['status' => 409]);
        }

        $changed = $wpdb->query($wpdb->prepare('UPDATE ' . WorkGroups::table('messages') . ' SET task_status=%s WHERE id=%d AND task_status=%s AND deleted_at IS NULL', $rule['to'], $messageId, $current));
        if ($changed !== 1) return new WP_Error('invalid_transition', 'وضعیت وظیفه تغییر کرده است؛ دوباره تلاش کنید.', ['status' => 409]);
        if ($action === 'finish') {
            $wpdb->query($wpdb->prepare('UPDATE ' . WorkGroups::table('work_task_items') . ' SET done=1, done_by=%d WHERE message_id=%d AND done=0', $userId, $messageId));
        }
        WorkMessages::system((int) $row->conversation_id, $userId, $rule['sys'], $messageId);

        $payload = $row->payload_json ? (json_decode((string) $row->payload_json, true) ?: []) : [];
        $title = (string) ($payload['title'] ?? '');
        $responsible = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . WorkGroups::table('work_task_people') . ' WHERE message_id=%d', $messageId)) ?: []);
        $recipients = array_unique(array_merge($responsible, WorkGroups::managerIds((int) $row->conversation_id)));
        WorkMessages::notify($c['conv'], $userId, 'work_task_status', array_diff($recipients, [$userId]), $messageId, $title, [], 'work:status:' . $messageId . ':' . wp_generate_uuid4());
        return self::updated(WorkMessages::row($messageId) ?? $row, $userId, $manager);
    }

    /**
     * @param int[] $assigneeIds
     * @return array<string,mixed>|WP_Error
     */
    public static function setAssignees(int $messageId, int $userId, array $assigneeIds): array|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'task');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if (!$c['manager']) {
            return new WP_Error('forbidden', 'فقط مدیر می‌تواند مسئول را تغییر دهد.', ['status' => 403]);
        }
        $row = $c['row'];
        $convId = (int) $row->conversation_id;
        global $wpdb;
        $people = WorkGroups::table('work_task_people');

        $next = WorkMessages::membersOnly($convId, $assigneeIds);
        $wpdb->query('START TRANSACTION');
        $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . WorkGroups::table('messages') . ' WHERE id=%d FOR UPDATE', $messageId));
        $prev = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$people} WHERE message_id=%d AND role='assignee'", $messageId)) ?: []);
        $added = array_values(array_diff($next, $prev));
        $removed = array_values(array_diff($prev, $next));

        if ($removed) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE message_id=%d AND role='assignee' AND user_id IN (" . WorkMessages::marks($removed) . ')', $messageId, ...$removed));
        }
        if ($added) {
            $now = esc_sql(current_time('mysql', true));
            // A volunteer promoted to assignee must not keep two rows.
            $wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE message_id=%d AND role='volunteer' AND user_id IN (" . WorkMessages::marks($added) . ')', $messageId, ...$added));
            $rows = array_map(static fn(int $u): string => '(' . $messageId . ',' . $u . ",'assignee','{$now}')", $added);
            $wpdb->query("INSERT IGNORE INTO {$people} (message_id,user_id,role,created_at) VALUES " . implode(',', $rows));
        }
        if ($next) $wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE message_id=%d AND role='volunteer'", $messageId));
        $wpdb->query('COMMIT');
        foreach (array_slice($added, 0, 5) as $u) {
            WorkMessages::system($convId, $userId, 'task_assigned', $messageId, $u);
        }
        foreach (array_slice($removed, 0, 5) as $u) {
            WorkMessages::system($convId, $userId, 'task_unassigned', $messageId, $u);
        }
        $payload = $row->payload_json ? (json_decode((string) $row->payload_json, true) ?: []) : [];
        WorkMessages::notify($c['conv'], $userId, 'work_task_assigned', array_diff($added, [$userId]), $messageId, (string) ($payload['title'] ?? ''));
        return self::updated($row, $userId, true);
    }

    public static function nudge(int $messageId, int $userId): true|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'task');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if (!$c['manager']) {
            return new WP_Error('forbidden', 'فقط مدیر می‌تواند یادآوری بفرستد.', ['status' => 403]);
        }
        $key = 'meydan_work_nudge_' . $messageId;
        if (get_transient($key)) {
            return new WP_Error('remind_too_soon', 'چند دقیقه پیش یادآوری فرستاده‌اید.', ['status' => 429]);
        }
        global $wpdb;
        $row = $c['row'];
        $people = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . WorkGroups::table('work_task_people') . ' WHERE message_id=%d', $messageId)) ?: []);
        if (!$people || !in_array((string) $row->task_status, ['todo', 'doing'], true)) {
            return new WP_Error('nothing_to_remind', 'مسئولی برای یادآوری وجود ندارد.', ['status' => 409]);
        }
        set_transient($key, 1, self::REMIND_COOLDOWN);
        $payload = $row->payload_json ? (json_decode((string) $row->payload_json, true) ?: []) : [];
        WorkMessages::system((int) $row->conversation_id, $userId, 'task_nudged', $messageId);
        WorkMessages::notify($c['conv'], $userId, 'work_task_reminder', array_diff($people, [$userId]), $messageId, (string) ($payload['title'] ?? ''), [], 'work:nudge:' . $messageId . ':' . time());
        return true;
    }

    /** @return array<string,mixed>|WP_Error */
    public static function addItem(int $messageId, int $userId, string $title): array|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'task');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if (!self::canEditChecklist($messageId, $userId, $c['manager'])) {
            return new WP_Error('forbidden', 'فقط مدیر و مسئول‌های وظیفه می‌توانند زیرکار را ویرایش کنند.', ['status' => 403]);
        }
        $title = mb_substr(sanitize_text_field($title), 0, 255);
        if ($title === '') {
            return new WP_Error('title_required', 'عنوان زیرکار را بنویسید.', ['status' => 422]);
        }
        global $wpdb;
        $items = WorkGroups::table('work_task_items');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$items} (message_id,title,sort) SELECT %d,%s,COALESCE(MAX(sort),0)+1 FROM {$items} WHERE message_id=%d",
            $messageId,
            $title,
            $messageId
        ));
        self::notifyChanged($c, $userId);
        return self::updated($c['row'], $userId, $c['manager']);
    }

    /**
     * @param array{done?:bool,title?:string} $changes
     * @return array<string,mixed>|WP_Error
     */
    public static function updateItem(int $itemId, int $userId, array $changes): array|WP_Error
    {
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WorkGroups::table('work_task_items') . ' WHERE id=%d', $itemId));
        if (!$item) {
            return new WP_Error('item_not_found', 'زیرکار پیدا نشد.', ['status' => 404]);
        }
        $c = self::ctx((int) $item->message_id, $userId, 'task');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if (!self::canEditChecklist((int) $item->message_id, $userId, $c['manager'])) {
            return new WP_Error('forbidden', 'فقط مدیر و مسئول‌های وظیفه می‌توانند زیرکار را ویرایش کنند.', ['status' => 403]);
        }
        $update = [];
        if (isset($changes['done'])) {
            $update['done'] = $changes['done'] ? 1 : 0;
            $update['done_by'] = $changes['done'] ? $userId : null;
        }
        if (isset($changes['title'])) {
            $title = mb_substr(sanitize_text_field((string) $changes['title']), 0, 255);
            if ($title !== '') {
                $update['title'] = $title;
            }
        }
        if ($update) {
            $wpdb->update(WorkGroups::table('work_task_items'), $update, ['id' => $itemId]);
        }
        $row = $c['row'];
        // Ticking the first sub-task starts the task, like the mock.
        if (!empty($changes['done']) && (string) $row->task_status === 'todo') {
            $wpdb->update(WorkGroups::table('messages'), ['task_status' => 'doing'], ['id' => (int) $row->id]);
            WorkMessages::system((int) $row->conversation_id, $userId, 'task_started', (int) $row->id);
        }
        self::notifyChanged($c, $userId);
        return self::updated(WorkMessages::row((int) $row->id) ?? $row, $userId, $c['manager']);
    }

    /** @return array<string,mixed>|WP_Error */
    public static function deleteItem(int $itemId, int $userId): array|WP_Error
    {
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WorkGroups::table('work_task_items') . ' WHERE id=%d', $itemId));
        if (!$item) {
            return new WP_Error('item_not_found', 'زیرکار پیدا نشد.', ['status' => 404]);
        }
        $c = self::ctx((int) $item->message_id, $userId, 'task');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if (!self::canEditChecklist((int) $item->message_id, $userId, $c['manager'])) {
            return new WP_Error('forbidden', 'فقط مدیر و مسئول‌های وظیفه می‌توانند زیرکار را ویرایش کنند.', ['status' => 403]);
        }
        $wpdb->delete(WorkGroups::table('work_task_items'), ['id' => $itemId]);
        self::notifyChanged($c, $userId);
        return self::updated($c['row'], $userId, $c['manager']);
    }

    private static function canEditChecklist(int $messageId, int $userId, bool $manager): bool
    {
        if ($manager) {
            return true;
        }
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . WorkGroups::table('work_task_people') . ' t INNER JOIN ' . WorkGroups::table('messages') . ' m ON m.id=t.message_id INNER JOIN ' . WorkGroups::table('participants') . ' p ON p.conversation_id=m.conversation_id AND p.user_id=t.user_id AND p.archived_at IS NULL WHERE t.message_id=%d AND t.user_id=%d LIMIT 1',
            $messageId,
            $userId
        ));
    }

    /* ------------------- meetings / announcements / polls ------------ */

    /** @return array<string,mixed>|WP_Error */
    public static function rsvp(int $messageId, int $userId, ?string $response): array|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'meeting');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if ($err = self::requireMember($c['role'], $c['manager'])) {
            return $err;
        }
        global $wpdb;
        $table = WorkGroups::table('work_meeting_rsvps');
        if ($response === 'yes' || $response === 'no') {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (message_id,user_id,response) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE response=VALUES(response)",
                $messageId,
                $userId,
                $response
            ));
        } else {
            $wpdb->delete($table, ['message_id' => $messageId, 'user_id' => $userId]);
        }
        return self::updated($c['row'], $userId, $c['manager']);
    }

    /** @return array<string,mixed>|WP_Error */
    public static function markSeen(int $messageId, int $userId): array|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'announcement');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if ($err = self::requireMember($c['role'], $c['manager'])) {
            return $err;
        }
        global $wpdb;
        $row = $c['row'];
        $wpdb->query('START TRANSACTION');
        $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . WorkGroups::table('messages') . ' WHERE id=%d FOR UPDATE', $messageId));
        $inserted = (int) $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . WorkGroups::table('work_announcement_seen') . ' (message_id,user_id,seen_at) VALUES (%d,%d,%s)',
            $messageId,
            $userId,
            current_time('mysql', true)
        ));
        if ($inserted > 0 && (int) $row->sender_user_id !== $userId) {
            $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . WorkGroups::table('work_announcement_seen') . ' WHERE message_id=%d', $messageId));
            $payload = $row->payload_json ? (json_decode((string) $row->payload_json, true) ?: []) : [];
            [$title, $body] = WorkMessages::copy('work_announcement_seen', $userId, (string) $c['conv']['title'], (string) ($payload['title'] ?? ''));
            $actor = WorkMessages::actorRef($userId);
            // Aggregate the app row; every acknowledgement still triggers push.
            (new \Meydan\Core\Notifications\NotificationService())->create(
                (int) $row->sender_user_id,
                'work_announcement_seen',
                $actor['type'],
                $actor['id'],
                'work',
                (int) $c['conv']['id'],
                $title,
                $body,
                '/works/' . (int) $c['conv']['id'] . '?m=' . $messageId,
                'work_seen:' . $messageId,
                ['work_id' => (int) $c['conv']['id'], 'work_title' => (string) $c['conv']['title'], 'message_id' => $messageId],
                null,
                null,
                true,
                true,
            );
        }
        $wpdb->query('COMMIT');
        return self::updated($row, $userId, $c['manager']);
    }

    /** @return array{items:array<int,array<string,mixed>>,next_cursor:?string}|WP_Error */
    public static function seenList(int $messageId, int $userId, string $cursor, int $limit): array|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'announcement');
        if ($c instanceof WP_Error) {
            return $c;
        }
        global $wpdb;
        $limit = min(100, max(1, $limit));
        $table = WorkGroups::table('work_announcement_seen');
        $where = $wpdb->prepare('message_id=%d', $messageId);
        if ($cursor !== '' && str_contains($cursor, '|')) {
            [$at, $uid] = explode('|', $cursor, 2);
            $where .= $wpdb->prepare(' AND (seen_at<%s OR (seen_at=%s AND user_id<%d))', $at, $at, (int) $uid);
        }
        $rows = $wpdb->get_results("SELECT user_id,seen_at FROM {$table} WHERE {$where} ORDER BY seen_at DESC, user_id DESC LIMIT " . ($limit + 1)) ?: [];
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        $users = WorkUsers::summaries(array_map(static fn($r): int => (int) $r->user_id, $rows), (int) $c['conv']['id']);
        $items = array_map(static fn($r): array => ['user' => $users[(int) $r->user_id] ?? null, 'seen_at' => WorkMessages::iso((string) $r->seen_at)], $rows);
        $last = $rows ? end($rows) : null;
        return ['items' => $items, 'next_cursor' => $more && $last ? $last->seen_at . '|' . $last->user_id : null];
    }

    public static function remind(int $messageId, int $userId): array|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'announcement');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if (!$c['manager']) {
            return new WP_Error('forbidden', 'فقط مدیر می‌تواند یادآوری بفرستد.', ['status' => 403]);
        }
        $key = 'meydan_work_remind_' . $messageId;
        if (get_transient($key)) {
            return new WP_Error('remind_too_soon', 'چند دقیقه پیش یادآوری فرستاده‌اید.', ['status' => 429]);
        }
        global $wpdb;
        $row = $c['row'];
        $unseen = array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT p.user_id FROM ' . WorkGroups::table('participants') . ' p LEFT JOIN ' . WorkGroups::table('work_announcement_seen')
            . ' s ON s.message_id=%d AND s.user_id=p.user_id WHERE p.conversation_id=%d AND p.archived_at IS NULL AND s.user_id IS NULL AND p.user_id<>%d',
            $messageId,
            (int) $row->conversation_id,
            $userId
        )) ?: []);
        set_transient($key, 1, self::REMIND_COOLDOWN);
        $payload = $row->payload_json ? (json_decode((string) $row->payload_json, true) ?: []) : [];
        WorkMessages::notify($c['conv'], $userId, 'work_announcement_reminder', $unseen, $messageId, (string) ($payload['title'] ?? ''), [], 'work:remind:' . $messageId . ':' . time());
        return ['reminded' => count($unseen)];
    }

    /** @return array<string,mixed>|WP_Error */
    public static function vote(int $messageId, int $userId, int $option): array|WP_Error
    {
        $c = self::ctx($messageId, $userId, 'poll');
        if ($c instanceof WP_Error) {
            return $c;
        }
        if ($err = self::requireMember($c['role'], $c['manager'])) {
            return $err;
        }
        $row = $c['row'];
        $payload = $row->payload_json ? (json_decode((string) $row->payload_json, true) ?: []) : [];
        if ($row->deleted_at !== null || $option < 0 || $option >= count((array) ($payload['options'] ?? []))) {
            return new WP_Error('invalid_option', 'گزینه معتبر نیست.', ['status' => 422]);
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . WorkGroups::table('work_poll_votes') . ' (message_id,user_id,option_index) VALUES (%d,%d,%d) ON DUPLICATE KEY UPDATE option_index=VALUES(option_index)',
            $messageId,
            $userId,
            $option
        ));
        return self::updated($row, $userId, $c['manager']);
    }

    /* ----------------------------- roles ----------------------------- */

    /** Owner / site admin sets a free-text attribute (صفت) shown after a member's name. Empty clears it. */
    public static function setLabel(array $conv, int $actorId, ?string $actorRole, int $targetId, string $label): array|WP_Error
    {
        if (!WorkGroups::canManage($actorRole, $actorId)) {
            return new WP_Error('forbidden', 'فقط مدیر کار می‌تواند صفت تعیین کند.', ['status' => 403]);
        }
        $convId = (int) $conv['id'];
        if (WorkGroups::role($convId, $targetId) === null) {
            return new WP_Error('member_not_found', 'این فرد عضو کار نیست.', ['status' => 404]);
        }
        $label = trim(mb_substr(sanitize_text_field($label), 0, 40));
        global $wpdb;
        $wpdb->update(WorkGroups::table('participants'), ['label' => $label === '' ? null : $label], ['conversation_id' => $convId, 'user_id' => $targetId]);
        \Meydan\Core\Support\SoketiRealtime::publishToUsers(WorkMessages::memberIds($convId), 'work:updated', ['workId' => (string) $convId], false);
        return ['label' => $label === '' ? null : $label];
    }

    /** Permanently delete a work group (owner or site admin). */
    public static function deleteWork(array $conv, int $actorId, ?string $actorRole): true|WP_Error
    {
        if (!WorkGroups::canAdminister($actorRole, $actorId)) {
            return new WP_Error('forbidden', 'فقط مدیر کار یا مدیر سایت می‌تواند کار را حذف کند.', ['status' => 403]);
        }
        $convId = (int) $conv['id'];
        $members = WorkMessages::memberIds($convId);
        WorkGroups::delete($convId, (int) ($conv['initiative_id'] ?? 0));
        \Meydan\Core\Support\SoketiRealtime::publishToUsers($members, 'work:updated', ['workId' => (string) $convId, 'deleted' => true], false);
        return true;
    }

    public static function setRole(array $conv, int $actorId, ?string $actorRole, int $targetId, string $role): true|WP_Error
    {
        if (!WorkGroups::canAdminister($actorRole, $actorId)) {
            return new WP_Error('forbidden', 'فقط مدیر کار می‌تواند ادمین تعیین کند.', ['status' => 403]);
        }
        if (!in_array($role, [WorkGroups::ROLE_ADMIN, WorkGroups::ROLE_MEMBER], true)) {
            return new WP_Error('invalid_role', 'نقش معتبر نیست.', ['status' => 422]);
        }
        $convId = (int) $conv['id'];
        $current = WorkGroups::role($convId, $targetId);
        if ($current === null) {
            return new WP_Error('member_not_found', 'این فرد عضو کار نیست.', ['status' => 404]);
        }
        if ($current === WorkGroups::ROLE_OWNER) {
            return new WP_Error('forbidden', 'نقش مدیر کار قابل تغییر نیست.', ['status' => 403]);
        }
        if ($current === $role) {
            return true;
        }
        global $wpdb;
        $wpdb->update(WorkGroups::table('participants'), ['role' => $role], ['conversation_id' => $convId, 'user_id' => $targetId]);
        WorkMessages::system($convId, $actorId, $role === WorkGroups::ROLE_ADMIN ? 'role_admin' : 'role_member', 0, $targetId);
        WorkMessages::notify($conv, $actorId, 'work_role_changed', [$targetId], 0, '', ['role' => $role], 'work:role:' . $convId . ':' . $targetId . ':' . time());
        \Meydan\Core\Support\SoketiRealtime::publishToUsers([$targetId], 'work:updated', ['workId' => (string) $convId], false);
        return true;
    }
}

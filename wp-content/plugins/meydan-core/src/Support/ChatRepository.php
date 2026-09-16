<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Uploads\VideoProcessor;
use WP_Error;

final class ChatRepository
{
    private function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'meydan_chat_' . $name;
    }

    public function isMember(int $conversationId, int $userId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table('participants')} WHERE conversation_id=%d AND user_id=%d AND archived_at IS NULL LIMIT 1",
            $conversationId,
            $userId
        ));
    }

    public function listConversations(int $userId): array
    {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT c.id FROM {$this->table('conversations')} c INNER JOIN {$this->table('participants')} p ON p.conversation_id=c.id WHERE p.user_id=%d AND p.archived_at IS NULL ORDER BY c.updated_at DESC,c.id DESC LIMIT 100",
            $userId
        )) ?: []);
        $items = [];
        foreach ($ids as $id) {
            $item = $this->conversation($id, $userId);
            if (is_array($item)) $items[] = $item;
        }
        return $items;
    }

    public function conversation(int $conversationId, int $viewerId): array|WP_Error
    {
        if (!$this->isMember($conversationId, $viewerId)) {
            return new WP_Error('chat_not_found', 'گفتگو پیدا نشد.', ['status' => 404]);
        }
        global $wpdb;
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('conversations')} WHERE id=%d LIMIT 1",
            $conversationId
        ));
        if (!$conversation) return new WP_Error('chat_not_found', 'گفتگو پیدا نشد.', ['status' => 404]);

        $participantId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$this->table('participants')} WHERE conversation_id=%d AND user_id<>%d AND archived_at IS NULL ORDER BY user_id ASC LIMIT 1",
            $conversationId,
            $viewerId
        ));
        if (!$participantId) $participantId = $viewerId;

        $lastMessageId = (int) ($conversation->last_message_id ?? 0);
        $last = $lastMessageId ? $wpdb->get_row($wpdb->prepare(
            "SELECT body,attachment_json,deleted_at FROM {$this->table('messages')} WHERE id=%d LIMIT 1",
            $lastMessageId
        )) : null;
        $preview = '';
        if ($last && !$last->deleted_at) {
            $preview = trim((string) $last->body);
            if ($preview === '' && $last->attachment_json) $preview = 'فایل پیوست‌شده';
        }

        $viewerState = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(last_read_message_id,0) AS last_read_message_id, notifications_muted FROM {$this->table('participants')} WHERE conversation_id=%d AND user_id=%d LIMIT 1",
            $conversationId,
            $viewerId
        ));
        $lastRead = (int) ($viewerState->last_read_message_id ?? 0);
        $unread = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('messages')} WHERE conversation_id=%d AND id>%d AND sender_user_id<>%d AND deleted_at IS NULL",
            $conversationId,
            $lastRead,
            $viewerId
        ));

        return [
            'id' => (string) $conversation->id,
            'type' => (string) $conversation->type,
            'participant' => $this->user($participantId),
            'preview' => $preview,
            'updated_at' => gmdate('c', strtotime((string) $conversation->updated_at . ' UTC')),
            'unread_count' => $unread,
            'last_message_id' => $lastMessageId ? (string) $lastMessageId : null,
            'notifications_muted' => (bool) ($viewerState->notifications_muted ?? false),
        ];
    }

    public function createDirect(int $viewerId, int $participantId): array|WP_Error
    {
        if ($participantId <= 0 || $participantId === $viewerId || !get_userdata($participantId)) {
            return new WP_Error('invalid_participant', 'مخاطب گفتگو معتبر نیست.', ['status' => 422]);
        }
        global $wpdb;
        [$a, $b] = $viewerId < $participantId ? [$viewerId, $participantId] : [$participantId, $viewerId];
        $key = $a . ':' . $b;
        $conversations = $this->table('conversations');
        $participants = $this->table('participants');
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$conversations} WHERE direct_key=%s LIMIT 1", $key));
        if ($existing) return $this->conversation($existing, $viewerId);

        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->insert($conversations, [
            'type' => 'direct',
            'direct_key' => $key,
            'created_by' => $viewerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = $ok ? (int) $wpdb->insert_id : (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$conversations} WHERE direct_key=%s LIMIT 1", $key));
        if (!$id) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('chat_create_failed', 'ایجاد گفتگو انجام نشد.', ['status' => 500]);
        }
        foreach ([$viewerId, $participantId] as $userId) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$participants} (conversation_id,user_id,joined_at) VALUES (%d,%d,%s)",
                $id,
                $userId,
                $now
            ));
        }
        $wpdb->query('COMMIT');
        return $this->conversation($id, $viewerId);
    }

    public function messages(int $conversationId, int $viewerId, int $beforeId = 0, int $limit = 50): array|WP_Error
    {
        if (!$this->isMember($conversationId, $viewerId)) return new WP_Error('chat_not_found', 'گفتگو پیدا نشد.', ['status' => 404]);
        global $wpdb;
        $limit = min(100, max(1, $limit));
        $where = $beforeId > 0 ? $wpdb->prepare(' AND id<%d', $beforeId) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('messages')} WHERE conversation_id=%d{$where} ORDER BY id DESC LIMIT %d",
            $conversationId,
            $limit
        )) ?: [];
        return array_values(array_map(fn($row) => $this->serializeMessage($row), array_reverse($rows)));
    }

    public function searchMessages(int $conversationId, int $viewerId, string $query, int $limit = 100): array|WP_Error
    {
        if (!$this->isMember($conversationId, $viewerId)) return new WP_Error('chat_not_found', 'گفتگو پیدا نشد.', ['status' => 404]);
        $query = trim(sanitize_text_field($query));
        if ($query === '') return [];
        global $wpdb;
        $limit = min(100, max(1, $limit));
        $like = '%' . $wpdb->esc_like($query) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('messages')} WHERE conversation_id=%d AND deleted_at IS NULL AND (body LIKE %s OR attachment_json LIKE %s) ORDER BY id DESC LIMIT %d",
            $conversationId,
            $like,
            $like,
            $limit
        )) ?: [];
        return array_values(array_map(fn($row) => $this->serializeMessage($row), $rows));
    }

    public function setMuted(int $conversationId, int $userId, bool $muted): array|WP_Error
    {
        if (!$this->isMember($conversationId, $userId)) return new WP_Error('chat_not_found', 'گفتگو پیدا نشد.', ['status' => 404]);
        global $wpdb;
        $updated = $wpdb->update(
            $this->table('participants'),
            ['notifications_muted' => $muted ? 1 : 0],
            ['conversation_id' => $conversationId, 'user_id' => $userId],
            ['%d'],
            ['%d', '%d']
        );
        if ($updated === false) return new WP_Error('chat_mute_failed', 'تغییر وضعیت اعلان‌ها انجام نشد.', ['status' => 500]);
        return $this->conversation($conversationId, $userId);
    }

    public function send(int $conversationId, int $senderId, array $input): array|WP_Error
    {
        if (!$this->isMember($conversationId, $senderId)) return new WP_Error('chat_not_found', 'گفتگو پیدا نشد.', ['status' => 404]);
        global $wpdb;
        $body = sanitize_textarea_field((string) ($input['body'] ?? ''));
        $clientId = sanitize_text_field((string) ($input['client_id'] ?? ''));
        $attachment = isset($input['attachment']) && is_array($input['attachment']) ? $input['attachment'] : null;
        if ($clientId === '' || strlen($clientId) > 80) return new WP_Error('invalid_client_id', 'شناسه پیام معتبر نیست.', ['status' => 422]);
        if ($body === '' && !$attachment) return new WP_Error('empty_message', 'پیام نمی‌تواند خالی باشد.', ['status' => 422]);
        $replyTo = max(0, (int) ($input['reply_to_id'] ?? 0));
        $forwardedFrom = max(0, (int) ($input['forwarded_from_message_id'] ?? 0));
        foreach ([$replyTo, $forwardedFrom] as $relatedId) {
            if ($relatedId > 0 && !(int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table('messages')} WHERE id=%d AND conversation_id=%d",
                $relatedId,
                $conversationId
            ))) return new WP_Error('invalid_message_reference', 'پیام ارجاع‌شده معتبر نیست.', ['status' => 422]);
        }
        $messages = $this->table('messages');
        $now = current_time('mysql', true);
        $attachmentJson = $attachment ? wp_json_encode($this->sanitizeAttachment($attachment)) : null;
        $sql = $wpdb->prepare(
            "INSERT INTO {$messages} (conversation_id,sender_user_id,client_id,body,reply_to_id,forwarded_from_message_id,attachment_json,created_at) VALUES (%d,%d,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            $conversationId,
            $senderId,
            $clientId,
            $body,
            $replyTo ?: null,
            $forwardedFrom ?: null,
            $attachmentJson,
            $now
        );
        if ($wpdb->query($sql) === false) return new WP_Error('message_send_failed', 'ارسال پیام انجام نشد.', ['status' => 500]);
        $messageId = (int) $wpdb->insert_id;
        if (!$messageId) $messageId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$messages} WHERE sender_user_id=%d AND client_id=%s LIMIT 1", $senderId, $clientId));
        $wpdb->update($this->table('conversations'), ['last_message_id' => $messageId, 'updated_at' => $now], ['id' => $conversationId]);
        return $this->message($messageId, $senderId);
    }

    public function edit(int $messageId, int $userId, string $body): array|WP_Error
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('messages')} WHERE id=%d LIMIT 1", $messageId));
        if (!$row || (int) $row->sender_user_id !== $userId || !$this->isMember((int) $row->conversation_id, $userId)) return new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]);
        $body = sanitize_textarea_field($body);
        if ($body === '') return new WP_Error('empty_message', 'پیام نمی‌تواند خالی باشد.', ['status' => 422]);
        $wpdb->update($this->table('messages'), ['body' => $body, 'edited_at' => current_time('mysql', true)], ['id' => $messageId]);
        return $this->message($messageId, $userId);
    }

    public function delete(int $messageId, int $userId): true|WP_Error
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT conversation_id,sender_user_id FROM {$this->table('messages')} WHERE id=%d LIMIT 1", $messageId));
        if (!$row || (int) $row->sender_user_id !== $userId || !$this->isMember((int) $row->conversation_id, $userId)) return new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]);
        $wpdb->update($this->table('messages'), ['body' => '', 'attachment_json' => null, 'deleted_at' => current_time('mysql', true)], ['id' => $messageId]);
        return true;
    }

    public function react(int $messageId, int $userId, string $reaction, bool $active): array|WP_Error
    {
        global $wpdb;
        $conversationId = (int) $wpdb->get_var($wpdb->prepare("SELECT conversation_id FROM {$this->table('messages')} WHERE id=%d LIMIT 1", $messageId));
        if (!$conversationId || !$this->isMember($conversationId, $userId)) return new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]);
        $reaction = mb_substr(sanitize_text_field($reaction), 0, 16);
        if ($reaction === '') return new WP_Error('invalid_reaction', 'واکنش معتبر نیست.', ['status' => 422]);
        if ($active) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$this->table('reactions')} (message_id,user_id,reaction,created_at) VALUES (%d,%d,%s,%s)",
                $messageId,
                $userId,
                $reaction,
                current_time('mysql', true)
            ));
        } else {
            $wpdb->delete($this->table('reactions'), ['message_id' => $messageId, 'user_id' => $userId, 'reaction' => $reaction]);
        }
        return $this->message($messageId, $userId);
    }

    public function markRead(int $conversationId, int $userId, int $messageId): true|WP_Error
    {
        if (!$this->isMember($conversationId, $userId)) return new WP_Error('chat_not_found', 'گفتگو پیدا نشد.', ['status' => 404]);
        global $wpdb;
        if ($messageId > 0 && !(int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('messages')} WHERE id=%d AND conversation_id=%d", $messageId, $conversationId))) {
            return new WP_Error('invalid_message', 'پیام معتبر نیست.', ['status' => 422]);
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('participants')} SET last_read_message_id=GREATEST(COALESCE(last_read_message_id,0),%d) WHERE conversation_id=%d AND user_id=%d",
            $messageId,
            $conversationId,
            $userId
        ));
        return true;
    }

    public function message(int $messageId, int $viewerId): array|WP_Error
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('messages')} WHERE id=%d LIMIT 1", $messageId));
        if (!$row || !$this->isMember((int) $row->conversation_id, $viewerId)) return new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]);
        return $this->serializeMessage($row);
    }

    private function serializeMessage(object $row): array
    {
        global $wpdb;
        $reactions = array_values(array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT reaction FROM {$this->table('reactions')} WHERE message_id=%d ORDER BY created_at ASC",
            (int) $row->id
        )) ?: []));
        $reply = null;
        if ((int) ($row->reply_to_id ?? 0) > 0) {
            $replyRow = $wpdb->get_row($wpdb->prepare("SELECT id,sender_user_id,body FROM {$this->table('messages')} WHERE id=%d LIMIT 1", (int) $row->reply_to_id));
            if ($replyRow) {
                $replyUser = Actor::forUser((int) $replyRow->sender_user_id);
                $reply = ['id' => (string) $replyRow->id, 'body' => (string) $replyRow->body, 'sender_name' => (string) ($replyUser['display_name'] ?? 'کاربر')];
            }
        }
        $forwardedFrom = null;
        if ((int) ($row->forwarded_from_message_id ?? 0) > 0) {
            $originUserId = (int) $wpdb->get_var($wpdb->prepare("SELECT sender_user_id FROM {$this->table('messages')} WHERE id=%d LIMIT 1", (int) $row->forwarded_from_message_id));
            $originActor = $originUserId ? Actor::forUser($originUserId) : null;
            $forwardedFrom = $originActor ? (string) ($originActor['display_name'] ?? 'پیام فورواردشده') : 'پیام فورواردشده';
        }
        $attachment = null;
        if (!$row->deleted_at && $row->attachment_json) {
            $decoded = json_decode((string) $row->attachment_json, true);
            if (is_array($decoded)) {
                $attachment = $this->enrichAttachment($decoded);
            }
        }

        return [
            'id' => (string) $row->id,
            'conversation_id' => (string) $row->conversation_id,
            'sender_id' => (string) $row->sender_user_id,
            'client_id' => (string) $row->client_id,
            'body' => $row->deleted_at ? '' : (string) $row->body,
            'created_at' => gmdate('c', strtotime((string) $row->created_at . ' UTC')),
            'edited_at' => $row->edited_at ? gmdate('c', strtotime((string) $row->edited_at . ' UTC')) : null,
            'deleted_at' => $row->deleted_at ? gmdate('c', strtotime((string) $row->deleted_at . ' UTC')) : null,
            'attachment' => $attachment,
            'reply_to' => $reply,
            'forwarded_from' => $forwardedFrom,
            'reactions' => $reactions,
        ];
    }

    /**
     * Video chat attachments carry the same still/duration fields as timeline
     * attachments, resolved from the uploaded media the client attached.
     *
     * @param array<string,mixed> $attachment
     * @return array<string,mixed>
     */
    private function enrichAttachment(array $attachment): array
    {
        $mime = (string) ($attachment['mime_type'] ?? '');
        $url = (string) ($attachment['url'] ?? '');
        $looksLikeVideo = str_starts_with($mime, 'video/') || (bool) preg_match('/\.(mp4|m4v|mov|webm|ogv)$/i', (string) parse_url($url, PHP_URL_PATH));
        if (!$looksLikeVideo) {
            return $attachment;
        }

        $mediaId = (int) ($attachment['id'] ?? 0);
        $path = '';
        if ($mediaId > 0 && get_post_type($mediaId) === 'attachment') {
            $path = (string) get_attached_file($mediaId);
        } elseif ($url !== '' && function_exists('attachment_url_to_postid')) {
            $found = (int) attachment_url_to_postid($url);
            if ($found > 0) {
                $mediaId = $found;
                $path = (string) get_attached_file($found);
            }
        }

        $metadata = $mediaId > 0 ? (array) wp_get_attachment_metadata($mediaId) : [];
        $video = VideoProcessor::describe($mediaId, $url, $path, $metadata, $attachment);
        foreach ($video as $key => $value) {
            if ($value !== null) {
                $attachment[$key] = $value;
            }
        }

        return $attachment;
    }

    private function user(int $userId): array
    {
        $user = get_userdata($userId);
        if (!$user) return [
            'id' => (string) $userId,
            'name' => 'کاربر',
            'handle' => '@user' . $userId,
            'avatar_url' => null,
            'verified' => false,
            'profile_type' => 'user',
            'profile_id' => (string) $userId,
        ];

        $actor = Actor::forUser($userId);
        $profileType = (string) ($actor['type'] ?? 'user');
        $profileId = $userId;
        $handle = '@' . (string) $user->user_nicename;
        if ($profileType === 'square') {
            $profileId = (int) get_user_meta($userId, 'meydan_square_id', true);
            $squareHandle = (string) get_post_meta($profileId, 'meydan_handle', true);
            $handle = $squareHandle !== '' ? (str_starts_with($squareHandle, '@') ? $squareHandle : '@' . $squareHandle) : '@square_' . $profileId;
        }

        return [
            'id' => (string) $userId,
            'name' => (string) ($actor['display_name'] ?? $user->display_name),
            'handle' => $handle,
            'avatar_url' => !empty($actor['avatar_url']) ? (string) $actor['avatar_url'] : null,
            'verified' => (bool) ($actor['verified'] ?? false),
            'profile_type' => $profileType === 'square' ? 'square' : 'user',
            'profile_id' => (string) $profileId,
        ];
    }

    private function sanitizeAttachment(array $attachment): array
    {
        return [
            'id' => sanitize_text_field((string) ($attachment['id'] ?? '')),
            'name' => sanitize_file_name((string) ($attachment['name'] ?? '')),
            'mime_type' => sanitize_mime_type((string) ($attachment['mime_type'] ?? 'application/octet-stream')),
            'size' => max(0, (int) ($attachment['size'] ?? 0)),
            'url' => esc_url_raw((string) ($attachment['url'] ?? $attachment['preview_url'] ?? '')),
        ];
    }
}

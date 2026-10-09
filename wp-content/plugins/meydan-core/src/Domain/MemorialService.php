<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Support\Handles;
use Meydan\Core\Support\Serializer;
use Meydan\Core\Support\UserEmails;
use WP_Error;

/**
 * یادبود (memorial) accounts: a biographical profile for a person who has
 * passed away. Unlike squares/collectives/media/organizations, a memorial has
 * no registering owner — it is created and maintained entirely by an
 * administrator — and carries three profile-specific objects on top of the
 * fields every entity already has (handle, avatar, cover, name):
 *
 *  - biography: long-form markdown, stored as the post's `post_content`
 *    (the same field every other entity uses for its description).
 *  - timeline: the person's life from birth to death, stored as a single
 *    JSON-encoded array in `meydan_memorial_timeline` post meta.
 *  - frames ("قاب‌های ماندگار"): a photo gallery, stored as a JSON-encoded
 *    array of WordPress attachment references in `meydan_memorial_frames`
 *    post meta — the same {media_id, order, caption, label} shape the
 *    existing content-attachments picker already uses.
 */
final class MemorialService
{
    public const KIND = EntityKinds::MEMORIAL;
    public const TIMELINE_META = 'meydan_memorial_timeline';
    public const FRAMES_META = 'meydan_memorial_frames';
    /** سمت: the person's post, e.g. «رئیس دانشگاه آزاد اسلامی». */
    public const POSITION_META = 'meydan_memorial_position';
    /** منصب: the person's field/standing, e.g. «فیزیک نظری • کیهان‌شناسی». */
    public const OFFICE_META = 'meydan_memorial_office';
    /** The one line under the name on the public page (the entity subtitle every profile reads). */
    public const TAGLINE_META = 'meydan_subtitle';
    public const POST_STATUSES = ['draft', 'publish'];

    /** @param array<string,mixed> $input @return array{user_id:int,memorial_id:int,name:string}|WP_Error */
    public static function create(array $input): array|WP_Error
    {
        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('validation_failed', 'نام یادبود الزامی است.', ['status' => 422, 'fields' => ['name' => 'required']]);
        }
        $rawHandle = trim((string) ($input['handle'] ?? ''));
        $handle = $rawHandle !== '' ? Handles::validate($rawHandle) : Handles::generate($name);
        if (is_wp_error($handle)) return $handle;
        $postStatus = in_array($input['status'] ?? null, self::POST_STATUSES, true) ? (string) $input['status'] : 'draft';
        $timeline = self::sanitizeTimeline($input['timeline'] ?? []);
        if (is_wp_error($timeline)) return $timeline;
        $frames = self::sanitizeFrames($input['frames'] ?? []);
        if (is_wp_error($frames)) return $frames;

        $userId = wp_insert_user([
            'user_login' => 'meydan_internal_' . strtolower(wp_generate_password(20, false, false)),
            'user_pass' => wp_generate_password(64, true, true),
            'display_name' => $name,
            // No phone exists to derive a canonical address from (a memorial has
            // no registering owner), so a unique placeholder is generated up
            // front: `user_email` is UNIQUE in `wp_users`, and leaving it empty
            // would collide with the next memorial created the same way.
            'user_email' => UserEmails::placeholderEmail(),
            'role' => EntityKinds::roleFor(self::KIND),
        ]);
        if (is_wp_error($userId)) {
            return new WP_Error('internal_error', 'ساخت حساب یادبود ناموفق بود.', ['status' => 500]);
        }
        $userId = (int) $userId;
        update_user_meta($userId, 'meydan_account_type', self::KIND);
        update_user_meta($userId, 'meydan_full_name', $name);
        UserEmails::ensureEmail($userId);

        $memorialId = wp_insert_post([
            'post_type' => EntityKinds::postType(self::KIND),
            'post_status' => $postStatus,
            'post_title' => $name,
            'post_content' => wp_kses_post((string) ($input['biography'] ?? '')),
            'post_author' => $userId,
        ], true);
        if (is_wp_error($memorialId)) {
            wp_delete_user($userId);
            return new WP_Error('internal_error', 'ساخت یادبود ناموفق بود.', ['status' => 500]);
        }
        $memorialId = (int) $memorialId;

        update_post_meta($memorialId, 'meydan_owner_user_id', $userId);
        // Every entity kind resolves its owner account's profile through this user meta.
        update_user_meta($userId, 'meydan_square_id', $memorialId);
        update_post_meta($memorialId, 'meydan_approval_status', 'approved');
        update_post_meta($memorialId, 'meydan_verified', 1);
        update_post_meta($memorialId, 'meydan_birth_date', sanitize_text_field((string) ($input['birth_date'] ?? '')));
        update_post_meta($memorialId, 'meydan_death_date', sanitize_text_field((string) ($input['death_date'] ?? '')));
        update_post_meta($memorialId, self::POSITION_META, sanitize_text_field((string) ($input['position'] ?? '')));
        update_post_meta($memorialId, self::OFFICE_META, sanitize_text_field((string) ($input['office'] ?? '')));
        update_post_meta($memorialId, self::TAGLINE_META, sanitize_text_field((string) ($input['tagline'] ?? '')));
        update_post_meta($memorialId, self::TIMELINE_META, wp_json_encode($timeline, JSON_UNESCAPED_UNICODE));
        update_post_meta($memorialId, self::FRAMES_META, wp_json_encode($frames, JSON_UNESCAPED_UNICODE));

        $avatar = max(0, (int) ($input['avatar_media_id'] ?? 0));
        if ($avatar > 0 && wp_attachment_is_image($avatar)) {
            update_post_meta($memorialId, 'meydan_avatar_media_id', $avatar);
            update_user_meta($userId, 'meydan_avatar_media_id', $avatar);
        }
        $cover = max(0, (int) ($input['cover_media_id'] ?? 0));
        if ($cover > 0 && wp_attachment_is_image($cover)) {
            update_user_meta($userId, 'meydan_cover_media_id', $cover);
        }

        Handles::store($userId, $handle);
        (new \WP_User($userId))->set_role(EntityKinds::roleFor(self::KIND));

        AuditLogger::log('memorial_created', 'memorial', $memorialId, null, ['user_id' => $userId, 'name' => $name]);

        return ['user_id' => $userId, 'memorial_id' => $memorialId, 'name' => $name];
    }

    /** @param array<string,mixed> $input */
    public static function update(int $id, array $input): true|WP_Error
    {
        $post = self::find($id);
        if (!$post) return new WP_Error('not_found', 'یادبود پیدا نشد.', ['status' => 404]);

        $update = ['ID' => $id];
        if (array_key_exists('name', $input)) {
            $name = sanitize_text_field((string) $input['name']);
            if ($name === '') return new WP_Error('validation_failed', 'نام یادبود الزامی است.', ['status' => 422, 'fields' => ['name' => 'required']]);
            $update['post_title'] = $name;
        }
        if (array_key_exists('biography', $input)) {
            $update['post_content'] = wp_kses_post((string) $input['biography']);
        }
        if (array_key_exists('status', $input)) {
            if (!in_array($input['status'], self::POST_STATUSES, true)) {
                return new WP_Error('validation_failed', 'وضعیت انتخاب‌شده معتبر نیست.', ['status' => 422, 'fields' => ['status' => 'invalid']]);
            }
            $update['post_status'] = (string) $input['status'];
        }
        if (count($update) > 1) {
            $result = wp_update_post($update, true);
            if (is_wp_error($result)) return $result;
        }

        $ownerId = (int) get_post_meta($id, 'meydan_owner_user_id', true);
        if (array_key_exists('handle', $input) && trim((string) $input['handle']) !== '') {
            $handle = Handles::validate((string) $input['handle'], $ownerId);
            if (is_wp_error($handle)) return $handle;
            if ($ownerId > 0) Handles::store($ownerId, $handle);
        }
        if (array_key_exists('avatar_media_id', $input)) {
            $avatar = max(0, (int) $input['avatar_media_id']);
            update_post_meta($id, 'meydan_avatar_media_id', $avatar);
            if ($ownerId > 0) update_user_meta($ownerId, 'meydan_avatar_media_id', $avatar);
        }
        if (array_key_exists('cover_media_id', $input) && $ownerId > 0) {
            update_user_meta($ownerId, 'meydan_cover_media_id', max(0, (int) $input['cover_media_id']));
        }
        if (array_key_exists('birth_date', $input)) {
            update_post_meta($id, 'meydan_birth_date', sanitize_text_field((string) $input['birth_date']));
        }
        if (array_key_exists('death_date', $input)) {
            update_post_meta($id, 'meydan_death_date', sanitize_text_field((string) $input['death_date']));
        }
        foreach (['position' => self::POSITION_META, 'office' => self::OFFICE_META, 'tagline' => self::TAGLINE_META] as $field => $metaKey) {
            if (array_key_exists($field, $input)) update_post_meta($id, $metaKey, sanitize_text_field((string) $input[$field]));
        }
        if (array_key_exists('verified', $input)) {
            update_post_meta($id, 'meydan_verified', $input['verified'] ? 1 : 0);
        }

        AuditLogger::log('memorial_updated', 'memorial', $id, null, Serializer::entity($id));
        return true;
    }

    public static function delete(int $id): true|WP_Error
    {
        $post = self::find($id);
        if (!$post) return new WP_Error('not_found', 'یادبود پیدا نشد.', ['status' => 404]);
        $ownerId = (int) get_post_meta($id, 'meydan_owner_user_id', true);
        $before = Serializer::entity($id);

        if (!wp_delete_post($id, true)) {
            return new WP_Error('internal_error', 'حذف یادبود ناموفق بود.', ['status' => 500]);
        }
        if ($ownerId > 0 && get_userdata($ownerId)) {
            // Not loaded on REST requests; without it the account (and its handle) outlives the memorial.
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($ownerId);
        }
        AuditLogger::log('memorial_deleted', 'memorial', $id, $before, ['status' => 'deleted_permanently']);
        return true;
    }

    private static function find(int $id): ?\WP_Post
    {
        $post = $id > 0 ? get_post($id) : null;
        if (!$post || $post->post_type !== EntityKinds::postType(self::KIND) || $post->post_status === 'trash') {
            return null;
        }
        return $post;
    }

    // --- Timeline (life events, birth to death) ---------------------------

    /** @return array<int,array<string,mixed>> */
    public static function timeline(int $id): array
    {
        if (!self::find($id)) return [];
        $raw = json_decode((string) get_post_meta($id, self::TIMELINE_META, true), true);
        return is_array($raw) ? $raw : [];
    }

    /** @param array<int,mixed> $events @return array<int,array<string,mixed>>|WP_Error */
    public static function setTimeline(int $id, array $events): array|WP_Error
    {
        $post = self::find($id);
        if (!$post) return new WP_Error('not_found', 'یادبود پیدا نشد.', ['status' => 404]);
        $sanitized = self::sanitizeTimeline($events);
        if (is_wp_error($sanitized)) return $sanitized;
        update_post_meta($id, self::TIMELINE_META, wp_json_encode($sanitized, JSON_UNESCAPED_UNICODE));
        AuditLogger::log('memorial_timeline_updated', 'memorial', $id, null, ['events' => count($sanitized)]);
        return $sanitized;
    }

    /** @return array<int,array<string,mixed>>|WP_Error */
    private static function sanitizeTimeline(mixed $events): array|WP_Error
    {
        if (!is_array($events)) {
            return new WP_Error('validation_failed', 'تایم‌لاین باید آرایه‌ای از رویدادها باشد.', ['status' => 422, 'fields' => ['timeline' => 'invalid']]);
        }
        $out = [];
        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                return new WP_Error('validation_failed', 'هر رویداد تایم‌لاین باید یک شیء باشد.', ['status' => 422, 'fields' => ['timeline' => 'invalid']]);
            }
            $title = sanitize_text_field((string) ($event['title'] ?? ''));
            if ($title === '') {
                return new WP_Error('validation_failed', 'عنوان هر رویداد تایم‌لاین الزامی است.', ['status' => 422, 'fields' => ['timeline' => 'title_required']]);
            }
            $out[] = [
                'id' => preg_match('/^[a-zA-Z0-9_-]{1,40}$/', (string) ($event['id'] ?? '')) ? (string) $event['id'] : wp_generate_password(12, false, false),
                'date' => sanitize_text_field((string) ($event['date'] ?? '')),
                'title' => $title,
                // The organisation or city under the title («دانشگاه آزاد اسلامی»، «تهران»).
                'place' => sanitize_text_field((string) ($event['place'] ?? '')),
                'description' => wp_kses_post((string) ($event['description'] ?? '')),
                'photo_media_id' => max(0, (int) ($event['photo_media_id'] ?? 0)) ?: null,
                'order' => $index + 1,
            ];
        }
        return $out;
    }

    // --- Frames ("قاب‌های ماندگار" photo gallery) --------------------------

    /** @return array<int,array<string,mixed>> */
    public static function frames(int $id): array
    {
        if (!self::find($id)) return [];
        $raw = json_decode((string) get_post_meta($id, self::FRAMES_META, true), true);
        return is_array($raw) ? $raw : [];
    }

    /** @param array<string,mixed> $frame @return array<int,array<string,mixed>>|WP_Error */
    public static function addFrame(int $id, array $frame): array|WP_Error
    {
        $post = self::find($id);
        if (!$post) return new WP_Error('not_found', 'یادبود پیدا نشد.', ['status' => 404]);
        $mediaId = (int) ($frame['media_id'] ?? 0);
        if ($mediaId <= 0 || !wp_attachment_is_image($mediaId)) {
            return new WP_Error('validation_failed', 'تصویر معتبر نیست.', ['status' => 422, 'fields' => ['media_id' => 'invalid']]);
        }
        $frames = self::frames($id);
        foreach ($frames as $existing) {
            if ((int) $existing['media_id'] === $mediaId) {
                return new WP_Error('validation_failed', 'این تصویر قبلاً به گالری افزوده شده است.', ['status' => 422, 'fields' => ['media_id' => 'duplicate']]);
            }
        }
        $frames[] = [
            'media_id' => $mediaId,
            'order' => count($frames) + 1,
            'caption' => sanitize_text_field((string) ($frame['caption'] ?? '')),
            'label' => sanitize_text_field((string) ($frame['label'] ?? '')),
        ];
        update_post_meta($id, self::FRAMES_META, wp_json_encode($frames, JSON_UNESCAPED_UNICODE));
        AuditLogger::log('memorial_frame_added', 'memorial', $id, null, ['media_id' => $mediaId]);
        return $frames;
    }

    /** @param array<string,mixed> $patch @return array<int,array<string,mixed>>|WP_Error */
    public static function updateFrame(int $id, int $mediaId, array $patch): array|WP_Error
    {
        $post = self::find($id);
        if (!$post) return new WP_Error('not_found', 'یادبود پیدا نشد.', ['status' => 404]);
        $frames = self::frames($id);
        $found = false;
        foreach ($frames as &$existing) {
            if ((int) $existing['media_id'] === $mediaId) {
                if (array_key_exists('caption', $patch)) $existing['caption'] = sanitize_text_field((string) $patch['caption']);
                if (array_key_exists('label', $patch)) $existing['label'] = sanitize_text_field((string) $patch['label']);
                $found = true;
                break;
            }
        }
        unset($existing);
        if (!$found) return new WP_Error('not_found', 'تصویر در گالری پیدا نشد.', ['status' => 404]);
        update_post_meta($id, self::FRAMES_META, wp_json_encode($frames, JSON_UNESCAPED_UNICODE));
        return $frames;
    }

    /** @return array<int,array<string,mixed>>|WP_Error */
    public static function removeFrame(int $id, int $mediaId): array|WP_Error
    {
        $post = self::find($id);
        if (!$post) return new WP_Error('not_found', 'یادبود پیدا نشد.', ['status' => 404]);
        $frames = array_values(array_filter(self::frames($id), static fn (array $frame): bool => (int) $frame['media_id'] !== $mediaId));
        foreach ($frames as $index => &$frame) {
            $frame['order'] = $index + 1;
        }
        unset($frame);
        update_post_meta($id, self::FRAMES_META, wp_json_encode($frames, JSON_UNESCAPED_UNICODE));
        AuditLogger::log('memorial_frame_removed', 'memorial', $id, null, ['media_id' => $mediaId]);
        return $frames;
    }

    /** @param array<int,int> $orderedMediaIds @return array<int,array<string,mixed>>|WP_Error */
    public static function reorderFrames(int $id, array $orderedMediaIds): array|WP_Error
    {
        $post = self::find($id);
        if (!$post) return new WP_Error('not_found', 'یادبود پیدا نشد.', ['status' => 404]);
        $frames = self::frames($id);
        $byMediaId = [];
        foreach ($frames as $frame) {
            $byMediaId[(int) $frame['media_id']] = $frame;
        }
        $orderedMediaIds = array_map('intval', $orderedMediaIds);
        if (count($orderedMediaIds) !== count($byMediaId) || array_diff($orderedMediaIds, array_keys($byMediaId)) !== []) {
            return new WP_Error('validation_failed', 'ترتیب ارسالی با تصاویر گالری مطابقت ندارد.', ['status' => 422, 'fields' => ['media_ids' => 'mismatch']]);
        }
        $reordered = [];
        foreach ($orderedMediaIds as $index => $mediaId) {
            $frame = $byMediaId[$mediaId];
            $frame['order'] = $index + 1;
            $reordered[] = $frame;
        }
        update_post_meta($id, self::FRAMES_META, wp_json_encode($reordered, JSON_UNESCAPED_UNICODE));
        return $reordered;
    }

    /** @return array<int,array<string,mixed>>|WP_Error */
    private static function sanitizeFrames(mixed $frames): array|WP_Error
    {
        if (!is_array($frames)) {
            return new WP_Error('validation_failed', 'قاب‌ها باید آرایه‌ای از تصاویر باشند.', ['status' => 422, 'fields' => ['frames' => 'invalid']]);
        }
        $out = [];
        $seen = [];
        foreach ($frames as $frame) {
            if (!is_array($frame)) continue;
            $mediaId = (int) ($frame['media_id'] ?? 0);
            if ($mediaId <= 0 || !wp_attachment_is_image($mediaId) || isset($seen[$mediaId])) continue;
            $seen[$mediaId] = true;
            $out[] = [
                'media_id' => $mediaId,
                'order' => count($out) + 1,
                'caption' => sanitize_text_field((string) ($frame['caption'] ?? '')),
                'label' => sanitize_text_field((string) ($frame['label'] ?? '')),
            ];
        }
        return $out;
    }
}

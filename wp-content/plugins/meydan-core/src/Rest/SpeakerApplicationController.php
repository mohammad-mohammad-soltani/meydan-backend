<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Auth\OtpService;
use Meydan\Core\Domain\SpeakerService;
use Meydan\Core\Notifications\NotificationService;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\RateLimiter;
use Meydan\Core\Support\Response;
use WP_REST_Request;

/**
 * «ثبت‌نام سخنران»: a signed-in user applies to join the speakers list and an
 * administrator approves (promoting the account, optionally verified) or rejects.
 */
final class SpeakerApplicationController extends BaseController
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

    public function create(WP_REST_Request $r)
    {
        $uid = get_current_user_id();
        if (Actor::isSpeaker($uid)) {
            return Response::error('validation_failed', 'حساب شما از قبل سخنران است.', 422, ['user' => 'already_speaker']);
        }
        if (!SpeakerService::isPromotable($uid)) {
            return Response::error('validation_failed', 'این نوع حساب نمی‌تواند درخواست سخنرانی ثبت کند.', 422, ['user' => 'not_eligible']);
        }
        if ($this->latest($uid, 'pending')) {
            return Response::error('validation_failed', 'درخواست قبلی شما در حال بررسی است.', 422, ['user' => 'already_pending']);
        }
        $limit = RateLimiter::hit('speaker-application', (string) $uid, 5, DAY_IN_SECONDS);
        if (!$limit['allowed']) {
            return Response::error('rate_limited', 'تعداد درخواست‌ها بیش از حد مجاز است.', 429);
        }

        $p = $this->json($r);
        $name = sanitize_text_field((string) ($p['full_name'] ?? ''));
        $city = sanitize_text_field((string) ($p['city'] ?? ''));
        $category = sanitize_key((string) ($p['category'] ?? ''));
        $topics = sanitize_text_field((string) ($p['topics'] ?? ''));
        $phone = OtpService::normalizePhone((string) ($p['phone'] ?? ''));
        $link = trim((string) ($p['link'] ?? ''));
        $about = sanitize_textarea_field((string) ($p['about'] ?? ''));

        $fields = [];
        if ($name === '') $fields['full_name'] = 'required';
        if ($city === '') $fields['city'] = 'required';
        if (!isset(SpeakerService::categoryOptions()[$category])) $fields['category'] = 'invalid';
        if ($topics === '') $fields['topics'] = 'required';
        if ($phone === '') $fields['phone'] = 'invalid';
        if ($link !== '' && (!preg_match('#^https?://#i', $link) || !filter_var($link, FILTER_VALIDATE_URL))) $fields['link'] = 'invalid';
        if (mb_strlen($about) > 2000) $fields['about'] = 'too_long';
        if ($fields) {
            return Response::error('validation_failed', 'اطلاعات واردشده معتبر نیست.', 422, $fields);
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(self::table(), [
            'user_id' => $uid,
            'full_name' => $name,
            'city' => $city,
            'category' => $category,
            'topics' => mb_substr($topics, 0, 500),
            'phone' => $phone,
            'link' => $link !== '' ? esc_url_raw($link) : null,
            'about' => $about !== '' ? $about : null,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) {
            return Response::error('internal_error', 'ثبت درخواست ناموفق بود.', 500);
        }
        $id = (int) $wpdb->insert_id;
        AuditLogger::log('speaker_application_created', 'speaker_application', $id, null, ['user_id' => $uid]);
        return Response::ok($this->row($this->find($id)), [], 201);
    }

    /** The viewer's most recent application, or null. */
    public function mine(WP_REST_Request $r)
    {
        $row = $this->latest(get_current_user_id());
        return Response::ok($row ? $this->row($row) : null);
    }

    public function adminList(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        global $wpdb;
        $status = sanitize_key((string) $r->get_param('status'));
        $sql = 'SELECT * FROM ' . self::table();
        $args = [];
        if (in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE status=%s';
            $args[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        return Response::ok(array_map(fn(array $row): array => $this->row($row), $rows ?: []));
    }

    public function adminDecide(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $row = $this->find((int) $r['id']);
        if (!$row) return Response::error('not_found', 'درخواست پیدا نشد.', 404);
        if ($row['status'] !== 'pending') {
            return Response::error('validation_failed', 'این درخواست قبلاً بررسی شده است.', 422, ['status' => 'already_decided']);
        }

        $p = $this->json($r);
        $status = sanitize_key((string) ($p['status'] ?? ''));
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return Response::error('validation_failed', 'وضعیت انتخاب‌شده معتبر نیست.', 422, ['status' => 'invalid']);
        }
        $verified = $status === 'approved' && $this->bool($p['verified'] ?? false);
        $note = sanitize_textarea_field((string) ($p['admin_note'] ?? ''));
        $userId = (int) $row['user_id'];

        if ($status === 'approved') {
            $promoted = SpeakerService::promote($userId);
            if (is_wp_error($promoted)) return $this->error($promoted);
            $saved = SpeakerService::save([
                'name' => $row['full_name'],
                'bio' => (string) ($row['about'] ?? ''),
                'expertise' => $row['topics'],
                'categories' => [$row['category']],
                'verified' => $verified,
            ], $userId);
            if (is_wp_error($saved)) return $this->error($saved);
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->update(self::table(), [
            'status' => $status,
            'verified' => $verified ? 1 : 0,
            'admin_note' => $note !== '' ? $note : null,
            'decided_by' => get_current_user_id(),
            'decided_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        AuditLogger::log('speaker_application_' . $status, 'speaker_application', (int) $row['id'], ['status' => 'pending'], ['status' => $status, 'verified' => $verified]);
        $this->notify($userId, $status === 'approved', $note);
        return Response::ok($this->row($this->find((int) $row['id'])));
    }

    private function notify(int $userId, bool $approved, string $note): void
    {
        try {
            (new NotificationService())->create(
                $userId,
                'admin_notice',
                null,
                null,
                'speaker_application',
                null,
                $approved ? 'درخواست سخنرانی شما تأیید شد' : 'درخواست سخنرانی شما تأیید نشد',
                $approved
                    ? 'حساب شما اکنون در فهرست سخنرانان میدان قرار دارد.'
                    : ($note !== '' ? $note : 'متأسفانه درخواست شما در این مرحله پذیرفته نشد.'),
                $approved ? '/speakers' : '/speaker-signup',
            );
        } catch (\Throwable) {
            // A notification failure must never undo the decision.
        }
    }

    private function latest(int $userId, ?string $status = null): ?array
    {
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::table() . ' WHERE user_id=%d' . ($status ? ' AND status=%s' : '') . ' ORDER BY id DESC LIMIT 1';
        $row = $wpdb->get_row($status ? $wpdb->prepare($sql, $userId, $status) : $wpdb->prepare($sql, $userId), ARRAY_A);
        return $row ?: null;
    }

    private function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d', $id), ARRAY_A);
        return $row ?: null;
    }

    /** @param array<string,mixed> $row */
    private function row(array $row): array
    {
        $uid = (int) $row['user_id'];
        $options = SpeakerService::categoryOptions();
        return [
            'id' => (int) $row['id'],
            'user_id' => $uid,
            'handle' => (string) get_user_meta($uid, 'meydan_handle', true),
            'full_name' => $row['full_name'],
            'city' => $row['city'],
            'category' => ['slug' => $row['category'], 'name' => $options[$row['category']] ?? $row['category']],
            'topics' => $row['topics'],
            'phone' => $row['phone'],
            'link' => $row['link'],
            'about' => $row['about'],
            'status' => $row['status'],
            'verified' => (bool) $row['verified'],
            'admin_note' => $row['admin_note'],
            'decided_at' => $row['decided_at'] ? mysql_to_rfc3339($row['decided_at']) : null,
            'created_at' => mysql_to_rfc3339($row['created_at']),
        ];
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'meydan_speaker_applications';
    }

    private function allowed(): bool { return current_user_can('manage_meydan_speaker_requests'); }
    private function forbidden() { return Response::error('forbidden', 'دسترسی کافی ندارید.', 403); }
}

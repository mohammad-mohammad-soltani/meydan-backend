<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Support\Response;
use WP_REST_Request;

/** Aggregates report content by its editorial `time` in the Tehran timezone. */
final class ReportDayController extends BaseController
{
    private const START_DATE = '2026-03-01'; // 10 Esfand 1404 in Tehran.
    private const TIMEZONE = 'Asia/Tehran';

    public function list(): \WP_REST_Response
    {
        return Response::cache(Response::ok(array_values($this->eligibleDays())), 'public, max-age=60, stale-while-revalidate=300');
    }

    public function adminList(): \WP_REST_Response
    {
        if (!current_user_can('manage_meydan_content')) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        return Response::cache(Response::ok(array_values($this->allDays())), 'private, no-store');
    }

    public function update(WP_REST_Request $request): \WP_REST_Response
    {
        if (!current_user_can('manage_meydan_content')) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $date = (string) $request['date'];
        if (!$this->validDate($date) || $date < self::START_DATE || $date > $this->today()) {
            return Response::error('validation_failed', 'تاریخ گزارش معتبر نیست.', 422, ['date' => 'invalid']);
        }
        $payload = $this->json($request);
        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $subtitle = sanitize_text_field((string) ($payload['subtitle'] ?? ''));
        $description = sanitize_textarea_field((string) ($payload['description'] ?? ''));
        $text = $this->color($payload['text_color'] ?? null);
        $background = $this->color($payload['background_color'] ?? null);
        if (($payload['text_color'] ?? null) !== null && $text === null) return Response::error('validation_failed', 'رنگ متن معتبر نیست.', 422, ['text_color' => 'invalid']);
        if (($payload['background_color'] ?? null) !== null && $background === null) return Response::error('validation_failed', 'رنگ پس‌زمینه معتبر نیست.', 422, ['background_color' => 'invalid']);
        if (mb_strlen($title) > 255 || mb_strlen($subtitle) > 255) return Response::error('validation_failed', 'عنوان یا زیرعنوان بیش از حد بلند است.', 422, ['title' => 'too_long']);

        global $wpdb;
        $table = $wpdb->prefix . 'meydan_report_days';
        $before = $this->storedByDate($date);
        if ($title === '' && $subtitle === '' && $description === '' && $text === null && $background === null) {
            $wpdb->delete($table, ['report_date' => $date], ['%s']);
        } else {
            $now = current_time('mysql', true);
            $wpdb->replace($table, [
                'report_date' => $date, 'title' => $title ?: null, 'subtitle' => $subtitle ?: null,
                'description' => $description ?: null, 'text_color' => $text, 'background_color' => $background,
                'created_at' => $before['created_at'] ?? $now, 'updated_at' => $now,
            ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        }
        $after = $this->day($date, $this->reportCounts()[$date] ?? 0, $this->storedByDate($date));
        AuditLogger::log('report_day_updated', 'report_day', $date, $before, $after);
        return Response::ok($after);
    }

    private function eligibleDays(): array
    {
        $reports = $this->reportCounts();
        $stored = $this->stored();
        $storedDates = array_keys(array_filter($stored, static fn(array $row): bool => trim((string) ($row['title'] ?? '')) !== ''));
        $dates = array_unique(array_merge(array_keys($reports), $storedDates));
        $dates = array_values(array_filter($dates, fn(string $date): bool => $date >= self::START_DATE && $date <= $this->today()));
        rsort($dates, SORT_STRING);
        return array_map(fn(string $date): array => $this->day($date, $reports[$date] ?? 0, $stored[$date] ?? null), $dates);
    }

    private function allDays(): array
    {
        $reports = $this->reportCounts(); $stored = $this->stored(); $days = [];
        $period = new DatePeriod(new DateTimeImmutable(self::START_DATE, new DateTimeZone(self::TIMEZONE)), new DateInterval('P1D'), (new DateTimeImmutable($this->today(), new DateTimeZone(self::TIMEZONE)))->modify('+1 day'));
        foreach ($period as $date) { $key = $date->format('Y-m-d'); $days[] = $this->day($key, $reports[$key] ?? 0, $stored[$key] ?? null); }
        return array_reverse($days);
    }

    private function reportCounts(): array
    {
        global $wpdb;
        $sql = "SELECT pm.meta_value AS report_time FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id INNER JOIN {$wpdb->postmeta} type ON type.post_id=p.ID AND type.meta_key='meydan_content_type' AND type.meta_value='report' WHERE pm.meta_key='meydan_time' AND p.post_type='meydan_content' AND p.post_status='publish'";
        $counts = [];
        foreach ($wpdb->get_col($sql) ?: [] as $time) {
            try { $date = (new DateTimeImmutable((string) $time, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d'); $counts[$date] = ($counts[$date] ?? 0) + 1; } catch (\Exception) { }
        }
        return $counts;
    }

    private function stored(): array
    {
        global $wpdb; $table = $wpdb->prefix . 'meydan_report_days';
        $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A) ?: [];
        return array_column($rows, null, 'report_date');
    }
    private function storedByDate(string $date): ?array { $all = $this->stored(); return $all[$date] ?? null; }

    private function day(string $date, int $count, ?array $saved): array
    {
        $start = new DateTimeImmutable(self::START_DATE, new DateTimeZone(self::TIMEZONE));
        $current = new DateTimeImmutable($date, new DateTimeZone(self::TIMEZONE));
        return ['date' => $date, 'night_number' => $start->diff($current)->days + 1, 'report_count' => $count,
            'title' => $saved['title'] ?? null, 'subtitle' => $saved['subtitle'] ?? null, 'description' => $saved['description'] ?? null,
            'text_color' => $saved['text_color'] ?? null, 'background_color' => $saved['background_color'] ?? null];
    }
    private function today(): string { return (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d'); }
    private function validDate(string $date): bool { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::TIMEZONE)); return $d && $d->format('Y-m-d') === $date; }
    private function color(mixed $value): ?string { if ($value === null || $value === '') return null; $value = trim((string) $value); return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null; }
}

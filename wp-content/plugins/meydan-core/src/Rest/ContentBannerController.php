<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Support\Response;
use WP_REST_Request;
use WP_REST_Response;

final class ContentBannerController extends BaseController
{
    private const OPTION = 'meydan_content_banners';

    public function list(): WP_REST_Response
    {
        return Response::ok(array_values(array_filter($this->data(), static fn(array $row): bool => $row['enabled'] && $row['image_url'] !== null)));
    }

    public function adminList(): WP_REST_Response
    {
        if (!current_user_can('manage_meydan_content')) return $this->forbidden();
        return Response::ok($this->data());
    }

    public function save(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_meydan_content')) return $this->forbidden();
        $body = $this->json($request);
        $rows = $body['banners'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return Response::error('validation_failed', 'فهرست بنرها معتبر نیست.', 422, ['banners'=>'invalid']);
        }
        $errors = []; $clean = []; $ids = [];
        foreach ($rows as $index => $row) {
            $prefix = 'banners.'.$index.'.';
            if (!is_array($row)) { $errors[$prefix.'id'] = 'invalid'; continue; }
            $id = $row['id'] ?? null;
            $media = $row['media_id'] ?? null;
            $title = is_string($row['title'] ?? null) ? sanitize_text_field($row['title']) : '';
            $href = is_string($row['href'] ?? null) ? trim($row['href']) : '';
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $id) || isset($ids[$id])) $errors[$prefix.'id'] = 'invalid';
            else $ids[$id] = true;
            if (!is_int($media) || $media <= 0 || !wp_attachment_is_image($media) || !wp_get_attachment_url($media)) $errors[$prefix.'media_id'] = 'invalid';
            if ($title === '') $errors[$prefix.'title'] = 'required';
            if (!self::validHref($href)) $errors[$prefix.'href'] = 'invalid';
            if (!is_bool($row['enabled'] ?? null)) $errors[$prefix.'enabled'] = 'invalid';
            $clean[] = ['id'=>$id, 'media_id'=>$media, 'title'=>$title, 'href'=>$href, 'enabled'=>$row['enabled'] ?? false];
        }
        if ($errors) return Response::error('validation_failed', 'اطلاعات بنرهای مشخص‌شده را اصلاح کنید.', 422, $errors);
        // Validate the entire replacement before writing anything, including migration.
        if (!update_option(self::OPTION, $clean, false) && get_option(self::OPTION, null) !== $clean) {
            return Response::error('save_failed', 'ذخیره بنرها ممکن نشد.', 500);
        }
        return Response::ok($this->data());
    }

    private static function validHref(string $href): bool
    {
        if ($href === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $href)) return false;
        if (str_starts_with($href, '/') && !str_starts_with($href, '//')) return true;
        return preg_match('#^https?://#i', $href) === 1 && filter_var($href, FILTER_VALIDATE_URL) !== false;
    }

    private function data(): array
    {
        $rows = get_option(self::OPTION, null);
        if ($rows === null) {
            $legacy = (array)get_option('meydan_content_poster', []);
            $media = (int)($legacy['media_id'] ?? 0);
            $href = (string)($legacy['href'] ?? '');
            $rows = $media > 0 && wp_attachment_is_image($media) ? [[
                'id'=>'legacy-poster', 'media_id'=>$media, 'title'=>'پوستر صفحه محتوا',
                'href'=>self::validHref($href) ? $href : '/content', 'enabled'=>true,
            ]] : [];
            // add_option cannot overwrite a list saved by a concurrent request.
            add_option(self::OPTION, $rows, '', false);
            $rows = get_option(self::OPTION, []);
        }
        return array_map(static function(array $row): array {
            $row['image_url'] = wp_attachment_is_image((int)$row['media_id']) ? (wp_get_attachment_url((int)$row['media_id']) ?: null) : null;
            return $row;
        }, $rows);
    }

    private function forbidden(): WP_REST_Response
    {
        return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
    }
}

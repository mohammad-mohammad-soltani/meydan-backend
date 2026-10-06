<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Domain\NoteCategories;
use Meydan\Core\Support\Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The notes section's own category vocabulary. Speaker categories are listed beside it (read-only here: they are
 * managed with the speakers) because notes can be filed under either.
 */
final class NoteCategoryController extends BaseController
{
    private function allowed(): bool
    {
        return current_user_can('manage_meydan_content');
    }

    public function list(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->allowed()) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $counts = [];
        foreach (NoteCategories::all() as $item) {
            $term = get_term_by('slug', $item['slug'], NoteCategories::TAXONOMY);
            $counts[$item['slug']] = $term instanceof \WP_Term ? (int) $term->count : 0;
        }
        return Response::ok(array_map(static fn(array $i): array => $i + ['count' => $counts[$i['slug']] ?? 0], NoteCategories::all()));
    }

    public function create(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->allowed()) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $name = sanitize_text_field(trim((string) ($this->json($r)['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 60) return Response::error('validation_failed', 'نام دسته‌بندی الزامی است (حداکثر ۶۰ نویسه).', 422, ['name' => 'invalid']);
        foreach (NoteCategories::all() as $item) {
            if (mb_strtolower($item['name']) === mb_strtolower($name)) return Response::error('validation_failed', 'این دسته‌بندی قبلاً وجود دارد.', 422, ['name' => 'duplicate']);
        }
        $own = NoteCategories::own();
        do {
            $slug = 'note-' . strtolower(wp_generate_password(8, false, false));
        } while (NoteCategories::find($slug));
        $own[$slug] = $name;
        update_option(NoteCategories::OPTION, $own, false);
        NoteCategories::flushHub();
        AuditLogger::log('note_category_created', 'note_category', 0, null, ['slug' => $slug, 'name' => $name]);
        return Response::ok(['slug' => $slug, 'name' => $name, 'source' => 'note', 'count' => 0], [], 201);
    }

    public function update(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->allowed()) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $slug = sanitize_key((string) $r['slug']);
        $own = NoteCategories::own();
        if (!isset($own[$slug])) return Response::error('not_found', 'دسته‌بندی پیدا نشد یا مال سخنرانان است.', 404);
        $name = sanitize_text_field(trim((string) ($this->json($r)['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 60) return Response::error('validation_failed', 'نام دسته‌بندی الزامی است (حداکثر ۶۰ نویسه).', 422, ['name' => 'invalid']);
        $before = ['slug' => $slug, 'name' => $own[$slug]];
        $own[$slug] = $name;
        update_option(NoteCategories::OPTION, $own, false);
        NoteCategories::termId($slug); // follows the rename
        NoteCategories::flushHub();
        AuditLogger::log('note_category_updated', 'note_category', 0, $before, ['slug' => $slug, 'name' => $name]);
        return Response::ok(['slug' => $slug, 'name' => $name, 'source' => 'note']);
    }

    public function delete(WP_REST_Request $r): WP_REST_Response
    {
        if (!$this->allowed()) return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
        $slug = sanitize_key((string) $r['slug']);
        $own = NoteCategories::own();
        if (!isset($own[$slug])) return Response::error('not_found', 'دسته‌بندی پیدا نشد یا مال سخنرانان است.', 404);
        $before = ['slug' => $slug, 'name' => $own[$slug]];
        unset($own[$slug]);
        update_option(NoteCategories::OPTION, $own, false);
        $term = get_term_by('slug', $slug, NoteCategories::TAXONOMY);
        if ($term instanceof \WP_Term) wp_delete_term($term->term_id, NoteCategories::TAXONOMY); // its notes become uncategorised
        NoteCategories::flushHub();
        AuditLogger::log('note_category_deleted', 'note_category', 0, $before, null);
        return Response::ok(['deleted' => true]);
    }
}

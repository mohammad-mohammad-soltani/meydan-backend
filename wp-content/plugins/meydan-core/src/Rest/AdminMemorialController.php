<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Domain\EntityKinds;
use Meydan\Core\Domain\MemorialService;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use WP_Query;
use WP_REST_Request;

final class AdminMemorialController extends BaseController
{
    public function list(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $page = max(1, (int) ($r->get_param('page') ?: 1));
        $perPage = min(100, max(1, (int) ($r->get_param('per_page') ?: 20)));
        $args = [
            'post_type' => EntityKinds::postType(EntityKinds::MEMORIAL),
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if ($q = trim((string) $r->get_param('q'))) $args['s'] = $q;
        $query = new WP_Query($args);
        $items = array_map(static function (\WP_Post $post): ?array {
            $row = Serializer::entity($post, false);
            return $row ? $row + ['post_status' => (string) $post->post_status] : null;
        }, $query->posts);
        return Response::ok(array_values(array_filter($items)), ['page' => $page, 'per_page' => $perPage, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages]);
    }

    public function create(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $result = MemorialService::create($this->json($r));
        if (is_wp_error($result)) return $this->error($result);
        return Response::ok(Serializer::memorial((int) $result['memorial_id']), [], 201);
    }

    public function get(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $data = Serializer::memorial((int) $r['id']);
        if (!$data) return Response::error('not_found', 'یادبود پیدا نشد.', 404);
        return Response::ok($data);
    }

    public function update(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $id = (int) $r['id'];
        $saved = MemorialService::update($id, $this->json($r));
        if (is_wp_error($saved)) return $this->error($saved);
        return Response::ok(Serializer::memorial($id));
    }

    public function delete(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $deleted = MemorialService::delete((int) $r['id']);
        if (is_wp_error($deleted)) return $this->error($deleted);
        return Response::ok(['deleted' => true, 'id' => (int) $r['id']]);
    }

    public function getTimeline(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $id = (int) $r['id'];
        if (!Serializer::memorial($id)) return Response::error('not_found', 'یادبود پیدا نشد.', 404);
        return Response::ok(MemorialService::timeline($id));
    }

    public function putTimeline(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $p = $this->json($r);
        $result = MemorialService::setTimeline((int) $r['id'], is_array($p['timeline'] ?? null) ? $p['timeline'] : (is_array($p) ? $p : []));
        if (is_wp_error($result)) return $this->error($result);
        return Response::ok($result);
    }

    public function listFrames(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $id = (int) $r['id'];
        if (!Serializer::memorial($id)) return Response::error('not_found', 'یادبود پیدا نشد.', 404);
        return Response::ok(MemorialService::frames($id));
    }

    public function addFrame(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $result = MemorialService::addFrame((int) $r['id'], $this->json($r));
        if (is_wp_error($result)) return $this->error($result);
        return Response::ok($result, [], 201);
    }

    public function updateFrame(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $result = MemorialService::updateFrame((int) $r['id'], (int) $r['media_id'], $this->json($r));
        if (is_wp_error($result)) return $this->error($result);
        return Response::ok($result);
    }

    public function removeFrame(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $result = MemorialService::removeFrame((int) $r['id'], (int) $r['media_id']);
        if (is_wp_error($result)) return $this->error($result);
        return Response::ok($result);
    }

    public function reorderFrames(WP_REST_Request $r)
    {
        if (!$this->allowed()) return $this->forbidden();
        $p = $this->json($r);
        $ids = is_array($p['media_ids'] ?? null) ? $p['media_ids'] : [];
        $result = MemorialService::reorderFrames((int) $r['id'], $ids);
        if (is_wp_error($result)) return $this->error($result);
        return Response::ok($result);
    }

    private function allowed(): bool
    {
        return current_user_can('manage_meydan_memorials');
    }

    private function forbidden()
    {
        return Response::error('forbidden', 'دسترسی کافی ندارید.', 403);
    }
}

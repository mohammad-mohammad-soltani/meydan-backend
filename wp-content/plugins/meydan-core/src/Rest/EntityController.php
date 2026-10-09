<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Domain\EntityKinds;
use Meydan\Core\Domain\ProfileExtras;
use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Handles;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use Meydan\Core\Support\Tributes;
use WP_REST_Request;

/**
 * Public profile of a media, collective or organization account (and squares
 * by the same shape). Each kind is its own post type; `/squares/*` stays the
 * square-only API with its location and schedule.
 */
final class EntityController extends BaseController
{
    public function get(WP_REST_Request $r)
    {
        $id = $this->visibleId($r);
        if ($id <= 0) return Response::error('not_found', 'مورد پیدا نشد.', 404);
        $data = Serializer::entity($id, !$this->bool($r->get_param('defer_counts')));
        if (!$data) return Response::error('not_found', 'مورد پیدا نشد.', 404);
        return Response::cache(Response::ok($this->enrich($data, $id)), 'public, max-age=60, stale-while-revalidate=300');
    }

    public function narratives(WP_REST_Request $r)
    {
        $id = $this->visibleId($r);
        if ($id <= 0) return Response::error('not_found', 'مورد پیدا نشد.', 404);
        // A memorial has no posts of its own; what its page lists are the tributes paid to it, newest first.
        if (EntityKinds::kindOf($id) === EntityKinds::MEMORIAL) {
            return ProfileNarrativePage::list([['key' => Tributes::META, 'value' => $id, 'type' => 'NUMERIC']], $r, 'memorial:' . $id);
        }
        $ownerId = Actor::squareOwnerUserId($id);
        return ProfileExtras::withPinned(ProfileNarrativePage::listByAuthor($ownerId, $r, EntityKinds::kindOf($id) . ':' . $id, true), $ownerId, $r);
    }

    /** The entity id when it exists, matches the kind in the URL, is published and visible. */
    private function visibleId(WP_REST_Request $r): int
    {
        $kind = (string) $r['kind'];
        $id = (int) $r['id'];
        if (!EntityKinds::valid($kind) || EntityKinds::kindOf($id) !== $kind || !EntityKinds::isEntity($id)) return 0;
        if (get_post_status($id) !== 'publish' || !UserAccess::visibleEntity($id)) return 0;
        return $id;
    }

    private function enrich(array $data, int $id): array
    {
        $ownerId = Actor::squareOwnerUserId($id);
        $post = get_post($id);
        $data['slug'] = $post ? $post->post_name : (string) $id;
        $data['chat_user_id'] = $ownerId ?: null;
        $data['handle'] = $ownerId ? Handles::ofUser($ownerId) : (string) get_post_meta($id, 'meydan_handle', true);
        $data['subtitle'] = (string) get_user_meta($ownerId, 'meydan_headline', true) ?: (string) get_post_meta($id, 'meydan_subtitle', true);
        $data['profile_about'] = (string) get_user_meta($ownerId, 'meydan_about', true) ?: (string) get_post_meta($id, 'meydan_profile_about', true);
        $data['verified'] = (bool) get_post_meta($id, 'meydan_verified', true);
        $data['stats'] = is_array($data['stats'] ?? null) ? $data['stats'] : [];
        $data['social'] = ProfileExtras::social(EntityKinds::kindOf($id), $id, $ownerId);
        $data['stats']['followers'] = $data['social']['followers'];
        if (EntityKinds::kindOf($id) === EntityKinds::MEMORIAL) {
            // The memorial page's own sections: life story, dates, timeline and the photo gallery.
            $memorial = Serializer::memorial($id) ?: [];
            foreach (['biography', 'birth_date', 'death_date', 'position', 'office', 'tagline', 'timeline', 'frames'] as $key) {
                $data[$key] = $memorial[$key] ?? (in_array($key, ['timeline', 'frames'], true) ? [] : '');
            }
            // The public page draws each life event's photo, so hand it a URL rather than an attachment id.
            $data['timeline'] = array_map(static function (array $event): array {
                $photo = (int) ($event['photo_media_id'] ?? 0);
                $event['photo_url'] = $photo > 0 ? (Actor::avatarUrl($photo) ?: null) : null;
                return $event;
            }, is_array($data['timeline']) ? $data['timeline'] : []);
        }
        return $data;
    }
}

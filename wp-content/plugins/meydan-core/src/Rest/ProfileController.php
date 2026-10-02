<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Domain\EntityKinds;
use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Handles;
use Meydan\Core\Support\Response;
use WP_REST_Request;

/**
 * Resolves a public profile address. Every profile lives at `/{handle}`; this
 * tells the app which kind of actor that handle is and which id to load.
 */
final class ProfileController extends BaseController
{
    public function resolve(WP_REST_Request $r)
    {
        $handle = Handles::normalize((string) $r['handle']);
        $owner = Handles::ownerId($handle);
        if ($owner < 0) {
            // Legacy square handle with no migrated owner: the id is the post.
            $postId = -$owner;
            $owner = EntityKinds::isEntity($postId) ? Actor::squareOwnerUserId($postId) : 0;
        }
        $found = $owner > 0 ? $this->payload($owner) : null;
        return $found ? $this->cached($found) : Response::error('not_found', 'پروفایل پیدا نشد.', 404);
    }

    /** Legacy id-based links (`/square/12`, `/users/media/4`, `/7`) are redirected through this. */
    public function byId(WP_REST_Request $r)
    {
        $type = sanitize_key((string) $r['type']);
        $id = (int) $r['id'];
        $owner = in_array($type, EntityKinds::actorTypes(), true) ? Actor::ownerUserId($type, $id) : 0;
        if ($owner > 0 && $type !== 'user' && EntityKinds::kindOf($id) !== $type) $owner = 0;
        $found = $owner > 0 ? $this->payload($owner) : null;
        return $found ? $this->cached($found) : Response::error('not_found', 'پروفایل پیدا نشد.', 404);
    }

    private function cached(array $payload)
    {
        return Response::cache(Response::ok($payload), 'public, max-age=60, stale-while-revalidate=300');
    }

    /** @return array<string,mixed>|null */
    private function payload(int $userId): ?array
    {
        if (!UserAccess::visibleUser($userId)) return null;
        $accountType = Actor::accountType($userId);
        $handle = Handles::ofUser($userId);
        if (EntityKinds::isEntityActorType($accountType)) {
            $entityId = Actor::entityId($userId);
            if ($entityId <= 0 || get_post_status($entityId) !== 'publish' || !UserAccess::visibleEntity($entityId)) return null;
            return ['actor_type' => $accountType, 'kind' => $accountType, 'id' => $entityId, 'user_id' => $userId, 'handle' => $handle];
        }
        return ['actor_type' => 'user', 'kind' => $accountType, 'id' => $userId, 'user_id' => $userId, 'handle' => $handle];
    }
}

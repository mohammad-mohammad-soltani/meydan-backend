<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

use Meydan\Core\Support\Viewer;

/** Immutable request data shared by Feed V2 pipeline stages. */
final class FeedContext
{
    /** @var array<string,true> */
    private array $followingActors = [];

    /**
     * @param array<string,mixed> $settings
     * @param list<string> $followingActors actor keys in `user:12` form
     */
    public function __construct(
        public readonly Viewer $viewer,
        public readonly array $settings,
        array $followingActors,
    ) {
        foreach ($followingActors as $actor) {
            $actor = trim($actor);
            if ($actor !== '') {
                $this->followingActors[$actor] = true;
            }
        }
    }

    public function follows(string $type, int $id): bool
    {
        return isset($this->followingActors[$type . ':' . $id]);
    }

    public function addFollowing(string $type, int $id): void
    {
        if ($id > 0 && in_array($type, ['user', 'square'], true)) {
            $this->followingActors[$type . ':' . $id] = true;
        }
    }
}

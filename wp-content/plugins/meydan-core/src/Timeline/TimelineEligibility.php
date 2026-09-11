<?php

declare(strict_types=1);

namespace Meydan\Core\Timeline;

final class TimelineEligibility
{
    /**
     * @param array<int|string> $ids
     * @return int[]
     */
    public static function filterNarrativeIds(array $ids): array
    {
        $eligible = [];

        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }

            $actorType = (string) get_post_meta($id, 'meydan_author_actor_type', true);
            if ($actorType === 'square') {
                $squareId = (int) get_post_meta($id, 'meydan_author_actor_id', true);
                if ($squareId <= 0 || (int) get_post_meta($squareId, 'meydan_verified', true) !== 1) {
                    continue;
                }
            }

            $eligible[] = $id;
        }

        return $eligible;
    }
}

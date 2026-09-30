<?php

declare(strict_types=1);

namespace Meydan\Core\Timeline;

use Meydan\Core\Support\Affinity;
use Meydan\Core\Support\Viewer;

final class FeatureHydrator
{
    /**
     * Turns raw candidates into ranking feature vectors. Structural,
     * viewer-independent data (actor, locality, media, engagement counts)
     * comes from NarrativeFeatureStore's pre-scored cache in a single query;
     * only the genuinely viewer-specific signals — affinity and
     * recently-served — are queried here, and both are batched across the
     * whole candidate set rather than looped per candidate.
     *
     * @param array<int,array{id:int,source:string}> $candidates
     */
    public function hydrate(Viewer $viewer, array $candidates): array
    {
        if (!$candidates) {
            return [];
        }

        $ids = array_values(array_unique(array_map(
            static fn(array $c): int => (int) $c['id'],
            $candidates,
        )));

        $features = NarrativeFeatureStore::ensureFresh($ids);

        global $wpdb;
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $recentRows = $wpdb->get_col($wpdb->prepare(
            "SELECT narrative_id FROM {$wpdb->prefix}meydan_served_history
             WHERE viewer_type=%s AND viewer_id=%s AND served_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
               AND narrative_id IN ({$marks})",
            $viewer->type,
            $viewer->id,
            ...$ids
        ));
        $recent = array_fill_keys(array_map('intval', $recentRows ?: []), true);

        $affinity = [];
        if ($viewer->isAuthenticated()) {
            $actors = [];
            foreach ($features as $row) {
                $actors[] = ['type' => (string) $row['actor_type'], 'id' => (int) $row['actor_id']];
            }
            $affinity = Affinity::normalizedBatch((int) $viewer->userId, $actors);
        }

        $out = [];
        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            $row = $features[$id] ?? null;
            // Unpublished/deleted since the candidate pool was built.
            if ($row === null) {
                continue;
            }

            $actorType = (string) $row['actor_type'];
            $actorId = (int) $row['actor_id'];
            $age = max(0.0, (time() - strtotime((string) $row['post_date_gmt'] . ' UTC')) / 3600);
            $eng = (int) $row['likes'] + 2 * (int) $row['reposts'] + 2.5 * (int) $row['comments'] + 1.5 * (int) $row['shares'];
            $exposure = max(25.0, (float) $row['views']);
            $city = (int) $row['city_id'];
            $province = (int) $row['province_id'];
            $locality = ($viewer->cityId && $city === $viewer->cityId)
                ? 1.0
                : (($viewer->provinceId && $province === $viewer->provinceId) ? 0.5 : 0.0);

            $out[] = [
                'id' => $id,
                'source' => $candidate['source'],
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'affinity' => $affinity[$actorType . ':' . $actorId] ?? 0.0,
                'recency' => exp(-$age / 18.0),
                'engagement_quality' => min(1.0, $eng / $exposure),
                'locality' => $locality,
                'media_affinity' => ((int) $row['has_media']) ? 0.5 : 0.0,
                'media_reflection_boost' => ((int) $row['media_reflection_boost']) ? 1.0 : 0.0,
                'initiative_boost' => ((int) $row['initiative_boost']) ? 1.0 : 0.0,
                'exploration_boost' => $candidate['source'] === 'exploration' ? 1.0 : 0.0,
                'recently_served' => isset($recent[$id]) ? 1.0 : 0.0,
            ];
        }
        return $out;
    }
}

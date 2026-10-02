<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

use Meydan\Core\Domain\EntityKinds;

/** Retrieves deterministic, age-limited narrative ids only. */
final class CandidateGenerator
{
    // Location combines the existing same_city and same_province sources under one budget.
    /** @return array<int,Candidate> keyed by narrative id */
    public function generate(FeedContext $context, int $requested = 0): array
    {
        $settings = $context->settings;
        $requested = max(1, $requested);
        $pool = (int) $settings['candidate_pool_size'];
        $pool = min($pool, $requested);
        $quotas = (array) ($settings['source_quotas'] ?? []);
        $sourceIds = [
            'following' => $this->followingIds($context, (int) ($quotas['following'] ?? 0)),
            'recent' => $this->ids('', [], $settings, (int) ($quotas['recent'] ?? 0)),
            'speaker' => $this->speakerIds($settings, (int) ($quotas['speaker'] ?? 0)),
            'editorial' => $this->ids("EXISTS (SELECT 1 FROM {$GLOBALS['wpdb']->postmeta} em WHERE em.post_id=p.ID AND em.meta_key='meydan_editorial' AND em.meta_value='1')", [], $settings, (int) ($quotas['editorial'] ?? 0)),
            'good_deed' => $this->ids("EXISTS (SELECT 1 FROM {$GLOBALS['wpdb']->postmeta} gm WHERE gm.post_id=p.ID AND gm.meta_key='meydan_initiative_id' AND CAST(gm.meta_value AS UNSIGNED)>0)", [], $settings, (int) ($quotas['good_deed'] ?? 0)),
            'location' => [],
            'general' => $this->ids('', [], $settings, (int) ($quotas['general'] ?? 0)),
        ];
        if ($context->viewer->cityId) $sourceIds['location'] = $this->metaIds('meydan_city_id', (int) $context->viewer->cityId, $settings, (int) ($quotas['location'] ?? 0));
        if ($context->viewer->provinceId && count($sourceIds['location']) < (int) ($quotas['location'] ?? 0)) {
            $sourceIds['location'] = [...$sourceIds['location'], ...$this->metaIds('meydan_province_id', (int) $context->viewer->provinceId, $settings, (int) ($quotas['location'] ?? 0))];
        }
        $selectedIds = $this->selectBySourceBudgets($sourceIds, $quotas, $pool);
        $candidates = [];
        foreach ($sourceIds as $source => $ids) foreach ($ids as $id) $this->add($candidates, (int) $id, $source);
        $selected = [];
        foreach ($selectedIds as $id) if (isset($candidates[$id])) $selected[$id] = $candidates[$id];
        return $selected;
    }

    /** @param array<string,list<int>> $sourceIds @param array<string,mixed> $quotas @return list<int> */
    public function selectBySourceBudgets(array $sourceIds, array $quotas, int $pool): array
    {
        $offsets = array_fill_keys(array_keys($sourceIds), 0);
        $used = array_fill_keys(array_keys($sourceIds), 0);
        $selected = [];
        do {
            $progress = false;
            foreach ($sourceIds as $source => $ids) {
                $budget = max(0, (int) ($quotas[$source] ?? 0));
                if ($used[$source] >= $budget || !isset($ids[$offsets[$source]])) continue;
                $id = (int) $ids[$offsets[$source]++];
                $used[$source]++;
                $progress = true;
                if (!in_array($id, $selected, true)) $selected[] = $id;
                if (count($selected) >= $pool) return $selected;
            }
        } while ($progress && count($selected) < $pool);
        return $selected;
    }

    /** @param array<int,Candidate> $candidates */
    private function add(array &$candidates, int $id, string $source): void
    {
        if ($id <= 0) return;
        if (isset($candidates[$id])) $candidates[$id]->addSource($source);
        else $candidates[$id] = new Candidate($id, $source);
    }

    /** @return list<int> */
    private function followingIds(FeedContext $context, int $limit): array
    {
        if (!$context->viewer->isAuthenticated()) return [];
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT object_type,object_id FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND action='follow'", $context->viewer->userId), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            if (in_array($row['object_type'], EntityKinds::actorTypes(), true)) {
                $context->addFollowing($row['object_type'], (int) $row['object_id']);
            }
        }
        if (!$rows) return [];
        $where = "EXISTS (SELECT 1 FROM {$wpdb->prefix}meydan_interactions f WHERE f.user_id=%d AND f.action='follow' AND f.object_type=at.meta_value AND f.object_id=CAST(ai.meta_value AS UNSIGNED))";
        return $this->ids($where, [$context->viewer->userId], $context->settings, $limit, true);
    }

    /** @return list<int> */
    private function metaIds(string $key, int $value, array $settings, int $limit): array
    {
        return $this->ids('lm.meta_value=%d', [$value], $settings, $limit, false, $key);
    }

    /** @return list<int> */
    private function speakerIds(array $settings, int $limit): array
    {
        if ($limit <= 0) return [];
        global $wpdb;
        // Match the role on the author account in SQL. Loading every speaker
        // into PHP produced an unbounded IN list on each feed refresh.
        $capabilitiesKey = $wpdb->prefix . 'capabilities';
        $rolePattern = '%"meydan_speaker";b:1%';
        $where = "at.meta_value='user' AND EXISTS (
            SELECT 1 FROM {$wpdb->usermeta} um
            WHERE um.user_id=CAST(ai.meta_value AS UNSIGNED)
              AND um.meta_key=%s AND um.meta_value LIKE %s
        )";
        return $this->ids($where, [$capabilitiesKey, $rolePattern], $settings, $limit, true);
    }

    /** @return list<int> */
    private function ids(string $where, array $args, array $settings, int $limit, bool $actorJoin = false, ?string $locationKey = null): array
    {
        global $wpdb;
        $joins = $actorJoin ? " INNER JOIN {$wpdb->postmeta} at ON at.post_id=p.ID AND at.meta_key='meydan_author_actor_type' INNER JOIN {$wpdb->postmeta} ai ON ai.post_id=p.ID AND ai.meta_key='meydan_author_actor_id'" : '';
        if ($locationKey !== null) $joins .= " INNER JOIN {$wpdb->postmeta} lm ON lm.post_id=p.ID AND lm.meta_key='" . esc_sql($locationKey) . "'";
        $conditions = ["p.post_type='meydan_narrative'", "p.post_status='publish'", 'p.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)'];
        if ($where !== '') $conditions[] = $where;
        $params = [(int)$settings['max_post_age_hours'], ...$args, $limit];
        $sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p{$joins} WHERE ".implode(' AND ', $conditions)." ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT %d";
        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, ...$params)) ?: []);
    }
}

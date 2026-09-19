<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

/** Retrieves deterministic, age-limited narrative ids only. */
final class CandidateGenerator
{
    /** @return array<int,Candidate> keyed by narrative id */
    public function generate(FeedContext $context): array
    {
        $settings = $context->settings;
        $pool = (int) $settings['candidate_pool_size'];
        $candidates = [];
        foreach ($this->followingIds($context, $pool) as $id) $this->add($candidates, $id, 'following');
        foreach ($this->ids('', [], $settings, $pool) as $id) $this->add($candidates, $id, 'recent');
        foreach ($this->ids("EXISTS (SELECT 1 FROM {$GLOBALS['wpdb']->postmeta} em WHERE em.post_id=p.ID AND em.meta_key='meydan_editorial' AND em.meta_value='1')", [], $settings, $pool) as $id) $this->add($candidates, $id, 'editorial');
        foreach ($this->ids("EXISTS (SELECT 1 FROM {$GLOBALS['wpdb']->postmeta} gm WHERE gm.post_id=p.ID AND gm.meta_key='meydan_initiative_id' AND CAST(gm.meta_value AS UNSIGNED)>0)", [], $settings, $pool) as $id) $this->add($candidates, $id, 'good_deed');
        if ($context->viewer->cityId) foreach ($this->metaIds('meydan_city_id', (int) $context->viewer->cityId, $settings, $pool) as $id) $this->add($candidates, $id, 'same_city');
        if ($context->viewer->provinceId) foreach ($this->metaIds('meydan_province_id', (int) $context->viewer->provinceId, $settings, $pool) as $id) $this->add($candidates, $id, 'same_province');
        foreach ($this->ids('', [], $settings, $pool) as $id) $this->add($candidates, $id, 'general');
        return array_slice($candidates, 0, $pool, true);
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
        $rows = $wpdb->get_results($wpdb->prepare("SELECT object_type,object_id FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND action='follow' LIMIT 1000", $context->viewer->userId), ARRAY_A) ?: [];
        $clauses=[]; $args=[];
        foreach ($rows as $row) { if (!in_array($row['object_type'], ['user','square'], true)) continue; $context->addFollowing($row['object_type'], (int)$row['object_id']); $clauses[]='(at.meta_value=%s AND ai.meta_value=%d)'; $args[]=$row['object_type']; $args[]=(int)$row['object_id']; }
        if (!$clauses) return [];
        return $this->ids('('.implode(' OR ', $clauses).')', $args, $context->settings, $limit, true);
    }

    /** @return list<int> */
    private function metaIds(string $key, int $value, array $settings, int $limit): array
    {
        return $this->ids('lm.meta_value=%d', [$value], $settings, $limit, false, $key);
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

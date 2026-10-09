<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Domain\UserAccess;

/**
 * `@handle` mentions typed inline in a narrative or comment body, plus the
 * composer's `@` autocomplete: the viewer's followed accounts first (most
 * followed first), then every account whose handle starts with what was typed.
 */
final class Mentions
{
    private const MAX_MENTIONS = 10;
    private const SUGGEST_LIMIT = 5;

    /**
     * Handles after the start of the text or whitespace/punctuation, so the
     * `@` of an email address (`a@b.com`) is never a mention. Lower-cased,
     * de-duplicated, in first-seen order, capped at 10.
     *
     * @return string[]
     */
    public static function extract(string $body): array
    {
        $pattern = '/(?:^|[\s\x{060C}\x{061B}.,!?؟>()\[\]{}])@([A-Za-z0-9_]{' . Handles::MIN . ',' . Handles::MAX . '})(?![A-Za-z0-9_@])/u';
        if (!preg_match_all($pattern, $body, $matches)) {
            return [];
        }
        $handles = [];
        foreach ($matches[1] as $handle) {
            $handles[strtolower($handle)] = true;
            if (count($handles) >= self::MAX_MENTIONS) {
                break;
            }
        }
        return array_keys($handles);
    }

    /**
     * Account owners for the handles found in `$body`; unknown, legacy
     * (ownerless) and disabled accounts are skipped.
     *
     * @return int[]
     */
    public static function userIds(string $body): array
    {
        $ids = [];
        foreach (self::extract($body) as $handle) {
            $id = Handles::ownerId($handle);
            if ($id > 0 && !UserAccess::disabled($id)) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * @return array<int,array<string,mixed>> up to five accounts for the `@` picker
     */
    public static function suggest(int $viewerId, string $query): array
    {
        global $wpdb;
        $query = Handles::normalize($query);
        if ($query !== '' && !preg_match('/^[a-z0-9_]{1,' . Handles::MAX . '}$/', $query)) {
            return [];
        }

        // 1. Accounts the viewer follows, matching the typed prefix.
        $followed = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_type, object_id FROM {$wpdb->prefix}meydan_interactions WHERE user_id = %d AND action = 'follow' ORDER BY id DESC LIMIT 500",
            $viewerId
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $ownerId = Actor::ownerUserId((string) $row['object_type'], (int) $row['object_id']);
            if ($ownerId <= 0 || $ownerId === $viewerId) {
                continue;
            }
            $handle = Handles::ofUser($ownerId);
            if ($handle === '' || ($query !== '' && !str_starts_with($handle, $query))) {
                continue;
            }
            $followed[$ownerId] = true;
        }
        $followedIds = self::rankByFollowers(array_keys($followed), self::SUGGEST_LIMIT);

        // 2. Everyone else whose handle starts with the typed letters.
        $others = [];
        if ($query !== '' && count($followedIds) < self::SUGGEST_LIMIT) {
            $found = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 40",
                Handles::META,
                $wpdb->esc_like($query) . '%'
            )) ?: [];
            foreach ($found as $id) {
                $id = (int) $id;
                if ($id > 0 && $id !== $viewerId && !isset($followed[$id])) {
                    $others[$id] = true;
                }
            }
            $others = self::rankByFollowers(array_keys($others), self::SUGGEST_LIMIT - count($followedIds));
        }

        $items = [];
        foreach ([[$followedIds, true], [$others, false]] as [$ids, $isFollowed]) {
            foreach ($ids as $id => $followers) {
                if (UserAccess::disabled($id)) {
                    continue;
                }
                $actor = Actor::forUser($id);
                if (($actor['handle'] ?? '') === '') {
                    continue;
                }
                $items[] = [
                    'id' => $id,
                    'handle' => (string) $actor['handle'],
                    'display_name' => (string) ($actor['display_name'] ?? ''),
                    'avatar_url' => $actor['avatar_url'] ?? null,
                    'verified' => (bool) ($actor['verified'] ?? false),
                    'account_type' => (string) ($actor['account_type'] ?? $actor['type'] ?? 'user'),
                    'followed' => $isFollowed,
                    'followers' => $followers,
                ];
            }
        }
        return array_slice($items, 0, self::SUGGEST_LIMIT);
    }

    /**
     * Most-followed first: `[userId => followerCount]`, cut to `$limit`.
     * One grouped query per actor type instead of one per candidate.
     *
     * @param int[] $userIds
     * @return array<int,int>
     */
    private static function rankByFollowers(array $userIds, int $limit): array
    {
        global $wpdb;
        if (!$userIds || $limit <= 0) {
            return [];
        }
        $byType = [];
        $actorOf = [];
        foreach ($userIds as $userId) {
            $type = Actor::actorType($userId);
            $actorId = $type === 'user' ? $userId : Actor::entityId($userId);
            if ($actorId <= 0) {
                continue;
            }
            $byType[$type][] = $actorId;
            $actorOf[$userId] = [$type, $actorId];
        }
        $counts = [];
        foreach ($byType as $type => $ids) {
            $marks = implode(',', array_fill(0, count($ids), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT object_id, COUNT(*) AS n FROM {$wpdb->prefix}meydan_interactions WHERE object_type = %s AND action = 'follow' AND object_id IN ($marks) GROUP BY object_id",
                $type,
                ...$ids
            ), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                $counts[$type . ':' . (int) $row['object_id']] = (int) $row['n'];
            }
        }
        $ranked = [];
        foreach ($actorOf as $userId => [$type, $actorId]) {
            $ranked[$userId] = $counts[$type . ':' . $actorId] ?? 0;
        }
        arsort($ranked);
        return array_slice($ranked, 0, $limit, true);
    }
}

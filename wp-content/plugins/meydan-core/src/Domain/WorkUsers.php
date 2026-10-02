<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Support\Actor;
use Meydan\Core\Support\ChatRepository;

/** Batch user summaries (same shape the chat API uses) with one cache-priming pass. */
final class WorkUsers
{
    /**
     * @param int[] $userIds
     * @return array<int,array<string,mixed>> keyed by user id
     */
    public static function summaries(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if (!$userIds) {
            return [];
        }
        ChatRepository::primeActorCache($userIds);

        $out = [];
        foreach ($userIds as $userId) {
            $out[$userId] = self::summary($userId);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function summary(int $userId): array
    {
        $user = get_userdata($userId);
        if (!$user) {
            return [
                'id' => (string) $userId,
                'name' => 'کاربر',
                'handle' => '@user' . $userId,
                'avatar_url' => null,
                'verified' => false,
                'verified_official' => false,
                'profile_type' => 'user',
                'profile_id' => (string) $userId,
            ];
        }

        $actor = Actor::forUser($userId);
        $profileType = (string) ($actor['type'] ?? 'user');
        $profileId = $userId;
        $handle = '@' . (string) $user->user_nicename;
        if ($profileType === 'square') {
            $profileId = (int) get_user_meta($userId, 'meydan_square_id', true);
            $squareHandle = (string) get_post_meta($profileId, 'meydan_handle', true);
            $handle = $squareHandle !== '' ? (str_starts_with($squareHandle, '@') ? $squareHandle : '@' . $squareHandle) : '@square_' . $profileId;
        }

        return [
            'id' => (string) $userId,
            'name' => (string) ($actor['display_name'] ?? $user->display_name),
            'handle' => $handle,
            'avatar_url' => !empty($actor['avatar_url']) ? (string) $actor['avatar_url'] : null,
            'verified' => (bool) ($actor['verified'] ?? false),
            'verified_official' => (bool) ($actor['verified_official'] ?? false),
            'profile_type' => $profileType === 'square' ? 'square' : 'user',
            'profile_id' => (string) $profileId,
        ];
    }
}

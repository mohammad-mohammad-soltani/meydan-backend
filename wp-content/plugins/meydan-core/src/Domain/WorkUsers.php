<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Handles;
use Meydan\Core\Support\ChatRepository;

/** Batch user summaries (same shape the chat API uses) with one cache-priming pass. */
final class WorkUsers
{
    /**
     * @param int[] $userIds
     * @param int $conversationId when set, each summary carries that work's member label (`work_label`)
     * @return array<int,array<string,mixed>> keyed by user id
     */
    public static function summaries(array $userIds, int $conversationId = 0): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if (!$userIds) {
            return [];
        }
        ChatRepository::primeActorCache($userIds);
        self::primeMedia($userIds);

        $labels = [];
        if ($conversationId > 0) {
            global $wpdb;
            $marks = implode(',', array_fill(0, count($userIds), '%d'));
            foreach ($wpdb->get_results($wpdb->prepare(
                'SELECT user_id,label FROM ' . WorkGroups::table('participants') . " WHERE conversation_id=%d AND label IS NOT NULL AND label<>'' AND user_id IN ({$marks})",
                $conversationId,
                ...$userIds
            )) ?: [] as $row) {
                $labels[(int) $row->user_id] = (string) $row->label;
            }
        }

        $out = [];
        foreach ($userIds as $userId) {
            $out[$userId] = self::summary($userId);
            if ($conversationId > 0) {
                $out[$userId]['work_label'] = $labels[$userId] ?? null;
            }
        }
        return $out;
    }

    /**
     * Avatars cost two queries each (attachment post + meta) when resolved one by one.
     * Collect every avatar id first and prime them in a single batch.
     *
     * @param int[] $userIds
     */
    private static function primeMedia(array $userIds): void
    {
        $mediaIds = [];
        $squareIds = [];
        foreach ($userIds as $userId) {
            $mediaIds[] = (int) get_user_meta($userId, 'meydan_avatar_media_id', true);
            $squareId = Actor::entityId($userId);
            if ($squareId > 0 && Actor::isEntityAccount($userId)) {
                $squareIds[] = $squareId;
            }
        }
        if ($squareIds) {
            $owners = [];
            foreach ($squareIds as $squareId) {
                $mediaIds[] = (int) get_post_meta($squareId, 'meydan_avatar_media_id', true);
                $owner = (int) get_post_meta($squareId, 'meydan_owner_user_id', true);
                if ($owner > 0) {
                    $owners[] = $owner;
                }
            }
            if ($owners) {
                cache_users($owners);
                foreach ($owners as $owner) {
                    $mediaIds[] = (int) get_user_meta($owner, 'meydan_avatar_media_id', true);
                }
            }
            Actor::primeSquareAddresses($squareIds);
        }
        $mediaIds = array_values(array_unique(array_filter($mediaIds)));
        if ($mediaIds && function_exists('_prime_post_caches')) {
            _prime_post_caches($mediaIds, false, true);
        }
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
                'verified_speaker' => false,
                'profile_type' => 'user',
                'profile_id' => (string) $userId,
            ];
        }

        $actor = Actor::forUser($userId);
        $profileType = (string) ($actor['type'] ?? 'user');
        $profileId = $userId;
        if ($profileType !== 'user') $profileId = Actor::entityId($userId);
        $handle = Handles::display($userId);

        return [
            'id' => (string) $userId,
            'name' => (string) ($actor['display_name'] ?? $user->display_name),
            'handle' => $handle,
            'avatar_url' => !empty($actor['avatar_url']) ? (string) $actor['avatar_url'] : null,
            'verified' => (bool) ($actor['verified'] ?? false),
            'verified_official' => (bool) ($actor['verified_official'] ?? false),
            'verified_speaker' => (bool) ($actor['verified_speaker'] ?? false),
            'profile_type' => \Meydan\Core\Domain\EntityKinds::isEntityActorType($profileType) ? $profileType : 'user',
            'profile_id' => (string) $profileId,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Auth\GuestSessionService;

final class Viewer
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly ?int $userId,
        public readonly ?int $provinceId,
        public readonly ?int $cityId,
    ) {}

    public static function current(): self
    {
        $userId = get_current_user_id();
        if ($userId > 0) {
            return new self(
                'user',
                (string) $userId,
                $userId,
                (int) get_user_meta($userId, 'meydan_province_id', true) ?: null,
                (int) get_user_meta($userId, 'meydan_city_id', true) ?: null,
            );
        }
        return new self('guest', GuestSessionService::id(), null, null, null);
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }
}

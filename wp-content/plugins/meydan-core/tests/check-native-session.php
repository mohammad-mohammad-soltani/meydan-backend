<?php

declare(strict_types=1);

namespace {
    const YEAR_IN_SECONDS = 31536000;
    const HOUR_IN_SECONDS = 3600;

    class WP_Error {}

    function get_userdata(int $id): object { return (object) ['ID' => $id]; }
    function current_time(string $format, bool $gmt = false): string { return '2026-09-24 00:00:00'; }
    function sanitize_text_field(string $value): string { return $value; }
    function wp_unslash(string $value): string { return $value; }
    function is_ssl(): bool { return true; }
    function wp_get_environment_type(): string { return 'production'; }
}

namespace Meydan\Core\Domain {
    final class UserAccess { public static function disabled(int $userId): bool { return false; } }
}

namespace Meydan\Core\Support {
    final class Crypto {
        public static function randomToken(int $length, string $prefix): string { return $prefix . str_repeat('a', $length); }
        public static function hash(string $value): string { return hash('sha256', $value); }
    }
}

namespace {
    final class FakeWpdb {
        public string $prefix = 'wp_';
        /** @var array<string,mixed> */
        public array $inserted = [];
        /** @param array<string,mixed> $data */
        public function insert(string $table, array $data): int { $this->inserted = $data; return 1; }
    }

    $wpdb = new FakeWpdb();
    require_once __DIR__ . '/../src/Auth/SessionService.php';

    $service = new \Meydan\Core\Auth\SessionService();
    $session = $service->issue(42, 'Naghshman Android', true);

    if ($session instanceof WP_Error) throw new RuntimeException('A valid user must receive a session.');
    if (($wpdb->inserted['persistent_device'] ?? 0) !== 1) {
        throw new RuntimeException('A native device session must be marked persistent.');
    }
    if (!array_key_exists('refresh_expires_at', $wpdb->inserted) || $wpdb->inserted['refresh_expires_at'] !== null) {
        throw new RuntimeException('A native device session must not have a refresh expiration date.');
    }

    echo "Native session contract OK.\n";
}

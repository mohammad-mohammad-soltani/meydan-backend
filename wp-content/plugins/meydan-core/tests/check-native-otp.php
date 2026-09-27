<?php

declare(strict_types=1);

namespace {
    class WP_Error {}

    final class FakeNativeOtpDb
    {
        public string $prefix = 'wp_';
        public array $updates = [];

        public function prepare(string $query, mixed ...$args): string { return $query; }
        public function get_row(string $query): object
        {
            return (object) [
                'id' => 4,
                'consumed_at' => null,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
                'attempt_count' => 0,
                'code_hash' => password_hash('123456', PASSWORD_DEFAULT),
                'phone_ciphertext' => 'encrypted-phone',
                'phone_hash' => 'phone-hash',
            ];
        }
        public function query(string $query): int { return 1; }
        public function update(string $table, array $values, array $where): int
        {
            $this->updates[] = $values;
            return 1;
        }
    }

    function current_time(string $format, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
    function get_users(array $query): array { return [42]; }
    function get_user_meta(int $userId, string $key, bool $single = false): string { return 'user'; }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
}

namespace Meydan\Core\Support {
    final class Crypto {
        public static function decrypt(string $ciphertext): string { return '+989121234567'; }
        public static function hash(string $value): string { return hash('sha256', $value); }
    }
}

namespace Meydan\Core\Domain {
    final class UserAccess { public static function disabled(int $id): bool { return false; } }
}

namespace Meydan\Core\Auth {
    final class SessionService
    {
        public static array $issued = [];
        public function issue(int $userId, ?string $deviceName = null, bool $persistentDevice = false): array
        {
            self::$issued[] = ['user_id' => $userId, 'persistent_device' => $persistentDevice];
            return [
                'access_token' => 'acc_test_token',
                'expires_in' => 900,
                'refresh_token' => 'ref_1234567890abcdefghijklmnopqrstuv',
            ];
        }
    }
}

namespace {
    $wpdb = new FakeNativeOtpDb();
    require_once __DIR__ . '/../src/Auth/OtpService.php';
    $otp = new \Meydan\Core\Auth\OtpService();

    $native = $otp->verify('otp_test', '123456', true);
    if ($native instanceof WP_Error || ($native['authenticated'] ?? false) !== true) {
        throw new RuntimeException('Native OTP should issue a session.');
    }
    if (($native['refresh_token'] ?? null) !== 'ref_1234567890abcdefghijklmnopqrstuv') {
        throw new RuntimeException('Native clients must receive a refresh credential in JSON.');
    }
    if ((\Meydan\Core\Auth\SessionService::$issued[0]['persistent_device'] ?? null) !== true) {
        throw new RuntimeException('Native OTP must issue a persistent session.');
    }

    $browser = $otp->verify('otp_browser', '123456', false);
    if ($browser instanceof WP_Error || ($browser['authenticated'] ?? false) !== true) {
        throw new RuntimeException('Browser OTP should issue a session.');
    }
    if (array_key_exists('refresh_token', $browser)) {
        throw new RuntimeException('Browser refresh credentials must remain HttpOnly-cookie only.');
    }

    echo "Native OTP session payload OK.\n";
}

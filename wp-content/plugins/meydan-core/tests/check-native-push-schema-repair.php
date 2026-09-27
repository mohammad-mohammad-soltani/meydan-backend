<?php

declare(strict_types=1);

namespace {
    final class FakeNativePushWpdb
    {
        public string $prefix = 'wp_';
        /** @var string[] */
        public array $queries = [];
        public function get_row(string $query): ?object
        {
            if (str_contains($query, "SHOW COLUMNS") && str_contains($query, "receipt_id")) return null;
            if (str_contains($query, "SHOW COLUMNS") && str_contains($query, "receipt_pending_at")) return null;
            if (str_contains($query, 'SHOW INDEX')) return null;
            return null;
        }
        public function query(string $query): int { $this->queries[] = $query; return 1; }
    }
    $wpdb = new FakeNativePushWpdb();
    require_once __DIR__ . '/../src/Database/PushMigrations.php';

    $method = new ReflectionMethod(\Meydan\Core\Database\PushMigrations::class, 'repairNativeTokenSchema');
    $method->setAccessible(true);
    $method->invoke(null);
    $sql = implode("\n", $wpdb->queries);
    foreach (['ADD COLUMN receipt_id', 'ADD COLUMN receipt_pending_at', 'ADD KEY receipt_pending_at'] as $expected) {
        if (!str_contains($sql, $expected)) throw new RuntimeException("Missing schema repair: {$expected}");
    }
    echo "Native push receipt schema repair contract OK.\n";
}

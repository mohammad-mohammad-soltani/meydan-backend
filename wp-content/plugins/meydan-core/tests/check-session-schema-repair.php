<?php

declare(strict_types=1);

final class FakeWpdb
{
    public string $prefix = 'wp_';
    /** @var list<string> */
    public array $queries = [];

    public function get_row(string $query): ?object
    {
        if (str_contains($query, "'refresh_expires_at'")) {
            return (object) ['Null' => 'NO'];
        }
        return null;
    }

    public function query(string $query): void
    {
        $this->queries[] = $query;
    }
}

$wpdb = new FakeWpdb();
require_once __DIR__ . '/../src/Database/Migrations.php';

if (\Meydan\Core\Database\Migrations::VERSION !== '1.4.3') {
    throw new RuntimeException('The session schema repair must run as a new migration version.');
}

$repair = new ReflectionMethod(\Meydan\Core\Database\Migrations::class, 'repairSessionSchema');
$repair->invoke(null);

if (!in_array('ALTER TABLE wp_meydan_sessions MODIFY refresh_expires_at DATETIME NULL', $wpdb->queries, true)) {
    throw new RuntimeException('Legacy session schemas must allow persistent refresh sessions.');
}

if (!in_array('ALTER TABLE wp_meydan_sessions ADD COLUMN persistent_device TINYINT(1) NOT NULL DEFAULT 0', $wpdb->queries, true)) {
    throw new RuntimeException('Legacy session schemas must receive the native-device marker.');
}

echo "Native session schema repair contract OK.\n";

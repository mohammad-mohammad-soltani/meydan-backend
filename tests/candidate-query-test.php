<?php
declare(strict_types=1);

// Query-shape regression checks. WordPress is intentionally not bootstrapped.
final class CandidateQueryDb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $usermeta = 'wp_usermeta';
    public array $queries = [];

    public function prepare(string $sql, ...$args): string
    {
        return vsprintf($sql, array_map(
            static fn ($arg) => is_int($arg) ? $arg : "'" . str_replace("'", "''", (string) $arg) . "'",
            $args,
        ));
    }

    public function get_var(string $sql): int
    {
        $this->queries[] = $sql;
        return 100;
    }

    public function get_col(string $sql): array
    {
        $this->queries[] = $sql;
        if (preg_match('/ID (>=|<) (\\d+)/', $sql, $matches)) {
            $ids = array_filter(
                [2, 95, 98],
                static fn (int $id): bool => $matches[1] === '>='
                    ? $id >= (int) $matches[2]
                    : $id < (int) $matches[2],
            );
            preg_match('/LIMIT (\\d+)/', $sql, $limit);
            return array_slice(array_values($ids), 0, (int) $limit[1]);
        }
        return [17, 18];
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$wpdb = new CandidateQueryDb();
require __DIR__ . '/../wp-content/plugins/meydan-core/src/Feed/CandidateGenerator.php';
require __DIR__ . '/../wp-content/plugins/meydan-core/src/Timeline/CandidateGenerator.php';

$feed = new Meydan\Core\Feed\CandidateGenerator();
$speaker = (new ReflectionMethod($feed, 'speakerIds'))->invoke($feed, ['max_post_age_hours' => 72], 2);
check($speaker === [17, 18], 'Speaker IDs must be limited');
check(str_contains(end($wpdb->queries), 'wp_capabilities'), 'Speaker role must be checked in SQL');
check(str_contains(end($wpdb->queries), 'meydan_speaker'), 'Speaker role missing');

$timeline = new Meydan\Core\Timeline\CandidateGenerator();
$verified = (new ReflectionMethod($timeline, 'verifiedSquares'))->invoke($timeline, 2);
check(count($verified) === 2, 'Verified square results must be limited');
check(str_contains(end($wpdb->queries), "sq.post_type='meydan_square'"), 'Square must be verified in SQL');

$explore = (new ReflectionMethod($timeline, 'exploration'))->invoke($timeline, 3);
$ids = array_column($explore, 'id');
check(count($ids) === 3 && count(array_unique($ids)) === 3, 'Exploration must wrap without duplicates');
check(count(array_filter($wpdb->queries, static fn (string $sql): bool => str_contains($sql, 'RAND('))) === 0, 'Full-table random sort detected');
echo "Candidate query checks OK\n";

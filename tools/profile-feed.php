<?php
// Profile the feed on a real database:  wp eval-file tools/profile-feed.php [user_id]
// Prints SQL query count/time and the slowest statements for a cold guest feed, a warm guest feed,
// an authenticated first page and a cursor page. Needs SAVEQUERIES (defined below before queries run).
if (!defined('SAVEQUERIES')) define('SAVEQUERIES', true);
global $wpdb;
$uid = (int) ($args[0] ?? 0);

function profile_call(string $label, callable $fn): ?array {
    global $wpdb;
    $wpdb->queries = [];
    $t = microtime(true);
    $result = $fn();
    $ms = (microtime(true) - $t) * 1000;
    $sql = 0.0;
    foreach ($wpdb->queries as $q) $sql += (float) $q[1];
    printf("%-28s %7.1f ms | %4d queries | SQL %7.1f ms\n", $label, $ms, count($wpdb->queries), $sql * 1000);
    $top = $wpdb->queries;
    usort($top, fn($a, $b) => $b[1] <=> $a[1]);
    foreach (array_slice($top, 0, 3) as $q) printf("    %6.1f ms  %s\n", $q[1] * 1000, substr(preg_replace('/\s+/', ' ', $q[0]), 0, 140));
    return is_array($result) ? $result : null;
}

function feed(string $query = 'mode=for_you&limit=20'): array {
    parse_str($query, $params);
    $req = new WP_REST_Request('GET', '/meydan/v1/timeline');
    foreach ($params as $k => $v) $req->set_param($k, $v);
    $res = rest_do_request($req);
    return (array) $res->get_data() + ['_status' => $res->get_status()];
}

wp_set_current_user(0);
profile_call('guest first page (cold)', fn() => feed());
profile_call('guest first page (warm)', fn() => feed());
// Video feed: two loads should differ (weighted shuffle) and the second should be cheap (shared pool).
$v1 = profile_call('guest video feed (cold)', fn() => feed('mode=for_you&filter=video&limit=20'));
$v2 = profile_call('guest video feed (warm)', fn() => feed('mode=for_you&filter=video&limit=20'));
$ids = fn($r) => array_map(fn($i) => $i['id'], (array) ($r['data'] ?? []));
printf("    video loads identical: %s | overlap %d/%d\n", $ids($v1) === $ids($v2) ? 'YES (unexpected)' : 'no', count(array_intersect($ids($v1), $ids($v2))), count($ids($v1)));
if ($uid > 0) {
    wp_set_current_user($uid);
    $first = profile_call("user $uid first page", fn() => feed());
    $cursor = $first['meta']['next_cursor'] ?? null;
    if ($cursor) profile_call('user cursor page', fn() => feed('mode=for_you&limit=20&cursor=' . rawurlencode($cursor)));
    profile_call("user $uid video feed", fn() => feed('mode=for_you&filter=video&limit=20'));
}

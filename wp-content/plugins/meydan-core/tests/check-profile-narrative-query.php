<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function profile_query_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$userId = 0;
$squareId = 0;
$narrativeIds = [];
$capturedQueries = [];

try {
    $userId = wp_insert_user([
        'user_login' => 'profile-query-' . wp_generate_uuid4(),
        'user_pass' => wp_generate_password(24),
        'user_email' => wp_generate_uuid4() . '@example.test',
        'role' => 'meydan_square',
    ]);
    profile_query_assert(!is_wp_error($userId), 'Could not create the square owner fixture.');
    $userId = (int) $userId;

    $squareId = wp_insert_post([
        'post_type' => 'meydan_square',
        'post_status' => 'publish',
        'post_title' => 'Profile query fixture',
        'post_author' => $userId,
    ]);
    profile_query_assert(!is_wp_error($squareId), 'Could not create the square fixture.');
    $squareId = (int) $squareId;
    update_user_meta($userId, 'meydan_square_id', $squareId);
    update_post_meta($squareId, 'meydan_owner_user_id', $userId);
    update_post_meta($squareId, 'meydan_approval_status', 'approved');

    foreach ([
        ['type' => 'user', 'id' => $userId, 'date' => gmdate('Y-m-d H:i:s', time() - 7200)],
        ['type' => 'square', 'id' => $squareId, 'date' => gmdate('Y-m-d H:i:s', time() - 3600)],
    ] as $fixture) {
        $narrativeId = wp_insert_post([
            'post_type' => 'meydan_narrative',
            'post_status' => 'publish',
            'post_content' => 'Profile query narrative',
            'post_author' => $userId,
            'post_date_gmt' => $fixture['date'],
            'post_date' => get_date_from_gmt($fixture['date']),
        ]);
        profile_query_assert(!is_wp_error($narrativeId), 'Could not create a narrative fixture.');
        $narrativeId = (int) $narrativeId;
        $narrativeIds[] = $narrativeId;
        update_post_meta($narrativeId, 'meydan_author_actor_type', $fixture['type']);
        update_post_meta($narrativeId, 'meydan_author_actor_id', $fixture['id']);
    }

    $capture = static function (string $sql) use (&$capturedQueries): string {
        if (str_contains($sql, "post_type = 'meydan_narrative'") && !str_contains($sql, 'meydan_interactions') || str_contains($sql, 'meydan_interactions') && str_contains($sql, "'repost'")) {
            $capturedQueries[] = $sql;
        }
        return $sql;
    };
    add_filter('query', $capture);

    $request = new WP_REST_Request('GET', "/meydan/v1/squares/{$squareId}/narratives");
    $request->set_query_params(['limit' => 1]);
    $first = rest_do_request($request);
    profile_query_assert($first->get_status() === 200, 'The first square narrative page must succeed.');
    $firstBody = $first->get_data();
    profile_query_assert(($firstBody['data'][0]['id'] ?? 0) === $narrativeIds[1], 'The newest square narrative must be first.');
    $cursor = (string) ($firstBody['meta']['next_cursor'] ?? '');
    profile_query_assert($cursor !== '', 'The first page must expose a next cursor.');

    $request = new WP_REST_Request('GET', "/meydan/v1/squares/{$squareId}/narratives");
    $request->set_query_params(['limit' => 1, 'cursor' => $cursor]);
    $second = rest_do_request($request);
    profile_query_assert($second->get_status() === 200, 'The second square narrative page must succeed.');
    $secondBody = $second->get_data();
    profile_query_assert(($secondBody['data'][0]['id'] ?? 0) === $narrativeIds[0], 'Legacy owner narratives must remain visible on later pages.');

    remove_filter('query', $capture);
    profile_query_assert($capturedQueries !== [], 'The narrative query must be observable.');
    foreach ($capturedQueries as $sql) {
        profile_query_assert(!str_contains($sql, 'postmeta'), 'Square narrative pagination must use the indexed post author, not postmeta joins.');
    }

    // A post the owner reposted joins the list, newest activity first, flagged with reposted_at.
    $otherId = (int) wp_insert_post([
        'post_type' => 'meydan_narrative',
        'post_status' => 'publish',
        'post_content' => 'Reposted narrative',
        'post_author' => 1,
        'post_date_gmt' => gmdate('Y-m-d H:i:s', time() - 86400),
        'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', time() - 86400)),
    ]);
    profile_query_assert($otherId > 0, 'Could not create the reposted narrative fixture.');
    $narrativeIds[] = $otherId;
    update_post_meta($otherId, 'meydan_author_actor_type', 'user');
    update_post_meta($otherId, 'meydan_author_actor_id', 1);
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'meydan_interactions', ['user_id' => $userId, 'object_type' => 'narrative', 'object_id' => $otherId, 'action' => 'repost', 'created_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
    $repostRowId = (int) $wpdb->insert_id;
    $request = new WP_REST_Request('GET', "/meydan/v1/squares/{$squareId}/narratives");
    $request->set_query_params(['limit' => 10]);
    $all = rest_do_request($request)->get_data()['data'] ?? [];
    $ids = array_map(static fn(array $n): int => (int) $n['id'], $all);
    profile_query_assert($ids === [$otherId, $narrativeIds[1], $narrativeIds[0]], 'A repost must be listed by repost time among the own posts.');
    profile_query_assert(!empty($all[0]['reposted_at']) && empty($all[1]['reposted_at']), 'Only the repost carries reposted_at.');
    $wpdb->delete($wpdb->prefix . 'meydan_interactions', ['id' => $repostRowId]);

    echo "Profile narrative query checks passed.\n";
} finally {
    foreach ($narrativeIds as $narrativeId) {
        wp_delete_post($narrativeId, true);
    }
    if ($squareId > 0) {
        wp_delete_post($squareId, true);
    }
    if ($userId > 0) {
        wp_delete_user($userId);
    }
}

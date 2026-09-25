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
        if (str_contains($sql, "post_type = 'meydan_narrative'")) {
            $capturedQueries[] = $sql;
        }
        return $sql;
    };
    add_filter('posts_request', $capture);

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

    remove_filter('posts_request', $capture);
    profile_query_assert($capturedQueries !== [], 'The narrative query must be observable.');
    foreach ($capturedQueries as $sql) {
        profile_query_assert(!str_contains($sql, 'postmeta'), 'Square narrative pagination must use the indexed post author, not postmeta joins.');
    }

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

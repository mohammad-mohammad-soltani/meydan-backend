<?php

/**
 * Integration regression for the admin square list.
 *
 * Run inside WordPress:
 *   wp eval-file wp-content/plugins/meydan-core/tests/check-admin-square-list-performance.php
 */

function checkAdminSquareList(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
checkAdminSquareList($admins !== [], 'An administrator is required for this integration test');
wp_set_current_user((int) $admins[0]);

$ownerId = 0;
$squareIds = [];

try {
    $ownerId = wp_insert_user([
        'user_login' => 'square_list_perf_' . wp_generate_password(12, false, false),
        'user_pass' => wp_generate_password(32, true, true),
        'display_name' => 'مالک آزمون فهرست میدان',
        'role' => 'meydan_square',
    ]);
    checkAdminSquareList(!is_wp_error($ownerId), 'Could not create square owner');
    $ownerId = (int) $ownerId;
    update_user_meta($ownerId, 'meydan_account_type', 'square');

    global $wpdb;
    for ($i = 1; $i <= 40; $i++) {
        $squareId = wp_insert_post([
            'post_type' => 'meydan_square',
            'post_status' => 'publish',
            'post_title' => sprintf('میدان آزمون صفحه‌بندی %02d', $i),
            'post_author' => $ownerId,
        ], true);
        checkAdminSquareList(!is_wp_error($squareId), 'Could not create test square');
        $squareId = (int) $squareId;
        $squareIds[] = $squareId;
        update_post_meta($squareId, 'meydan_owner_user_id', $ownerId);
        update_post_meta($squareId, 'meydan_approval_status', 'approved');
        update_post_meta($squareId, 'meydan_verified', 1);
    }

    $request = new WP_REST_Request('GET', '/meydan/v1/admin/squares');
    $request->set_param('page', 2);
    $request->set_param('per_page', 20);

    $before = (int) $wpdb->num_queries;
    $response = rest_do_request($request);
    $queries = (int) $wpdb->num_queries - $before;

    checkAdminSquareList($response->get_status() === 200, 'Second page must load successfully');
    $payload = $response->get_data();
    checkAdminSquareList((int) ($payload['meta']['page'] ?? 0) === 2, 'Second-page metadata must be preserved');
    checkAdminSquareList(count($payload['data'] ?? []) === 20, 'Second page must contain 20 rows');
    checkAdminSquareList($queries <= 30, sprintf('Admin square page used %d queries; expected at most 30', $queries));

    foreach ($payload['data'] as $row) {
        checkAdminSquareList(!array_key_exists('schedule', $row), 'List rows must not hydrate square schedules');
        checkAdminSquareList(!array_key_exists('stats', $row), 'List rows must not count narratives');
    }

    echo sprintf("Admin square list performance OK (%d queries)\n", $queries);
} finally {
    foreach ($squareIds as $squareId) {
        wp_delete_post($squareId, true);
    }
    if ($ownerId > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($ownerId);
    }
}

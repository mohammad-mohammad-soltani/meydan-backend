<?php

use Meydan\Core\Auth\SessionService;
use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Support\Serializer;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
check($admins !== [], 'An administrator is required for this integration test');
wp_set_current_user((int) $admins[0]);
$userId = 0;
$squareId = 0;
$narrativeId = 0;
$contentId = 0;
$commentId = 0;
$phone = '+989' . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT);

function request(string $method, string $path, array $body = []): WP_REST_Response
{
    $request = new WP_REST_Request($method, '/meydan/v1' . $path);
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }
    return rest_do_request($request);
}

try {
    $created = request('POST', '/admin/users', ['full_name' => 'آزمون مدیریت کاربران', 'phone' => $phone, 'role' => 'meydan_user']);
    check($created->get_status() === 201, 'User creation: ' . wp_json_encode($created->get_data()));
    $userId = (int) $created->get_data()['data']['id'];
    check((string) $created->get_data()['data']['phone'] === $phone, 'Phone persisted');
    $duplicate = request('POST', '/admin/users', ['full_name' => 'تکراری', 'phone' => $phone, 'role' => 'meydan_user']);
    check($duplicate->get_status() === 422, 'Duplicate phone rejected');
    $listed = request('GET', '/admin/users');
    check($listed->get_status() === 200, 'User list available');
    $searched = new WP_REST_Request('GET', '/meydan/v1/admin/users');
    $searched->set_param('q', $phone);
    check(count(rest_do_request($searched)->get_data()['data']) === 1, 'Phone search');
    wp_set_current_user($userId);
    check(request('GET', '/admin/users')->get_status() === 403, 'Non-admin cannot list users');
    wp_set_current_user((int) $admins[0]);

    $promoted = request('PATCH', "/admin/users/$userId", ['role' => 'meydan_speaker', 'headline' => 'آزمون']);
    check($promoted->get_status() === 200 && $promoted->get_data()['data']['role'] === 'meydan_speaker', 'Speaker promotion');
    update_user_meta($userId, 'meydan_avatar_media_id', 12345);
    request('PATCH', "/admin/users/$userId", ['headline' => 'نام تازه']);
    check((int) get_user_meta($userId, 'meydan_avatar_media_id', true) === 12345, 'Unchanged avatar preserved');

    $narrativeId = wp_insert_post(['post_type' => 'meydan_narrative', 'post_status' => 'publish', 'post_title' => 'آزمون', 'post_author' => $userId]);
    update_post_meta($narrativeId, 'meydan_author_actor_type', 'user');
    update_post_meta($narrativeId, 'meydan_author_actor_id', $userId);
    $contentId = wp_insert_post(['post_type' => 'meydan_content', 'post_status' => 'publish', 'post_title' => 'محتوای آزمایشی']);
    update_post_meta($contentId, 'meydan_source_narrative_id', $narrativeId);
    $commentId = wp_insert_comment(['comment_post_ID' => $narrativeId, 'comment_content' => 'دیدگاه آزمایشی', 'comment_approved' => 1, 'comment_type' => 'meydan_comment', 'user_id' => $userId]);

    $disabled = request('PATCH', "/admin/users/$userId/status", ['disabled' => true]);
    check($disabled->get_status() === 200 && $disabled->get_data()['data']['disabled'] === true, 'Disable user');
    check(is_wp_error((new SessionService())->issue($userId)), 'Disabled session denied');
    UserAccess::resetContext();
    check(Serializer::narrative($narrativeId) === null, 'Public narrative hidden');
    check(Serializer::content($contentId) === null, 'Converted content hidden');
    check(Serializer::comment($commentId) === null, 'Public comment hidden');
    check(request('GET', "/users/$userId")->get_status() === 404, 'Public user hidden');
    check(request('GET', "/admin/users/$userId")->get_status() === 200, 'Administrator can still edit disabled account');

    $enabled = request('PATCH', "/admin/users/$userId/status", ['disabled' => false]);
    check($enabled->get_status() === 200 && !UserAccess::disabled($userId), 'Restore user');
    UserAccess::resetContext();
    check(Serializer::narrative($narrativeId) !== null, 'Narrative restored');
    check(Serializer::content($contentId) !== null, 'Converted content restored');
    $protected = request('PATCH', '/admin/users/' . (int) $admins[0] . '/status', ['disabled' => true]);
    check($protected->get_status() === 422, 'Current administrator protected');

    global $wpdb;
    $geo = $wpdb->get_row("SELECT id, province_id FROM {$wpdb->prefix}meydan_cities WHERE active=1 LIMIT 1", ARRAY_A);
    if ($geo) {
        $square = request('PATCH', "/admin/users/$userId", ['role' => 'meydan_square', 'square' => [
            'name' => 'میدان آزمایشی', 'address' => 'نشانی آزمایشی', 'province_id' => (int) $geo['province_id'],
            'city_id' => (int) $geo['id'], 'latitude' => 35.7, 'longitude' => 51.4,
        ]]);
        check($square->get_status() === 200, 'Square role conversion: ' . wp_json_encode($square->get_data()));
        $squareId = (int) $square->get_data()['data']['square_id'];
        check($squareId > 0 && get_post_type($squareId) === 'meydan_square', 'Square linked to user');
        $demoted = request('PATCH', "/admin/users/$userId", ['role' => 'meydan_user']);
        UserAccess::resetContext();
        check($demoted->get_status() === 200 && !UserAccess::visibleSquare($squareId), 'Square hidden after role conversion');
    }
    echo "Admin users integration OK\n";
} finally {
    if ($narrativeId) wp_delete_post($narrativeId, true);
    if ($contentId) wp_delete_post($contentId, true);
    if ($commentId) wp_delete_comment($commentId, true);
    if ($squareId) wp_delete_post($squareId, true);
    if ($userId) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userId);
    }
}

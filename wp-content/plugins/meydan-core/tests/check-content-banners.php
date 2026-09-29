<?php
// Run against a local WordPress install; all temporary data is restored.
function bannerCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function bannerRequest(string $method, string $path, ?array $body = null): WP_REST_Response {
    $r = new WP_REST_Request($method, '/meydan/v1'.$path);
    if ($body !== null) { $r->set_header('Content-Type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    return rest_do_request($r);
}
$old = get_option('meydan_content_banners', null);
$oldPoster = get_option('meydan_content_poster', null);
$actor = get_current_user_id();
$media = 0; $subscriber = 0;
try {
    $admin = (int)(get_users(['role'=>'administrator','fields'=>'ID'])[0] ?? 0);
    bannerCheck($admin > 0, 'Local administrator required');
    $upload = wp_upload_bits('banner-test-'.wp_generate_password(8,false).'.png', null, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lE8AAAAASUVORK5CYII='));
    bannerCheck(empty($upload['error']), 'Upload test fixture');
    $media = wp_insert_attachment(['post_mime_type'=>'image/png','post_title'=>'Banner test'], $upload['file']);
    update_option('meydan_content_poster', ['media_id'=>$media,'href'=>'/content']);
    delete_option('meydan_content_banners');
    wp_set_current_user(0);
    $migrated = bannerRequest('GET','/content/banners');
    bannerCheck($migrated->get_status()===200, 'Public banner route must exist and allow anonymous reads');
    $first = $migrated->get_data()['data'];
    bannerCheck(count($first)===1 && $first[0]['media_id']===$media, 'Legacy poster migrates');
    bannerCheck(bannerRequest('GET','/content/banners')->get_data()['data'][0]['id']===$first[0]['id'], 'Migration IDs stable');
    bannerCheck(bannerRequest('GET','/admin/content/banners')->get_status()===401, 'Anonymous admin read denied');
    bannerCheck(bannerRequest('PUT','/admin/content/banners',['banners'=>[]])->get_status()===401, 'Anonymous write denied');
    $subscriber = wp_insert_user(['user_login'=>'banner-test-'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(),'role'=>'subscriber']);
    wp_set_current_user($subscriber);
    bannerCheck(bannerRequest('GET','/admin/content/banners')->get_status()===403, 'Non-admin denied');
    bannerCheck(bannerRequest('PUT','/admin/content/banners',['banners'=>[]])->get_status()===403, 'Non-admin write denied');
    wp_set_current_user($admin);
    $denyContent = static function(array $caps): array { $caps['manage_meydan_content'] = false; return $caps; };
    add_filter('user_has_cap', $denyContent);
    bannerCheck(bannerRequest('GET','/admin/content/banners')->get_status()===403, 'Content capability required for read');
    bannerCheck(bannerRequest('PUT','/admin/content/banners',['banners'=>[]])->get_status()===403, 'Content capability required for write');
    remove_filter('user_has_cap', $denyContent);
    $a = ['id'=>'first','media_id'=>$media,'title'=>'اول','href'=>'/content?tab=1','enabled'=>true];
    $b = ['id'=>'second','media_id'=>$media,'title'=>'دوم','href'=>'https://example.org/path','enabled'=>false];
    $saved = bannerRequest('PUT','/admin/content/banners',['banners'=>[$b,$a]]);
    bannerCheck($saved->get_status()===200, 'Save ordered list');
    bannerCheck(array_column($saved->get_data()['data'],'id')===['second','first'], 'Admin order preserved');
    bannerCheck(array_column(bannerRequest('GET','/content/banners')->get_data()['data'],'id')===['first'], 'Disabled filtered');
    $b['enabled']=true;
    bannerRequest('PUT','/admin/content/banners',['banners'=>[$b,$a]]);
    bannerCheck(array_column(bannerRequest('GET','/content/banners')->get_data()['data'],'id')===['second','first'], 'Active order preserved');
    $before = get_option('meydan_content_banners');
    foreach (['javascript:alert(1)','//example.org','/\\example.org',"/\n/evil",'data:text/html,test','ftp://example.org',''] as $url) {
        $bad=$a; $bad['href']=$url;
        $r=bannerRequest('PUT','/admin/content/banners',['banners'=>[$b,$bad]]);
        bannerCheck($r->get_status()===422, 'Unsafe/empty link rejected: '.json_encode($url));
        bannerCheck(isset(((array)$r->get_data()['error']['fields'])['banners.1.href']), 'Error identifies banner field');
        bannerCheck(get_option('meydan_content_banners')===$before, 'Invalid list cannot partially save');
    }
    foreach (['media_id'=>999999999,'enabled'=>'false','title'=>'','id'=>'second'] as $field=>$value) {
        $bad=$a; $bad[$field]=$value;
        bannerCheck(bannerRequest('PUT','/admin/content/banners',['banners'=>[$b,$bad]])->get_status()===422, 'Invalid '.$field.' rejected');
        bannerCheck(get_option('meydan_content_banners')===$before, 'Failed save preserves list');
    }
    bannerCheck(bannerRequest('PUT','/admin/content/banners',[])->get_status()===422, 'Missing list rejected');
    bannerCheck(bannerRequest('PUT','/admin/content/banners',['banners'=>[]])->get_status()===200, 'Empty list accepted');
    bannerCheck(bannerRequest('GET','/content/banners')->get_data()['data']===[], 'Deleted legacy banner stays deleted');
    bannerCheck(bannerRequest('GET','/admin/content/poster')->get_status()===200, 'Legacy endpoint kept');
    delete_option('meydan_content_banners'); delete_option('meydan_content_poster');
    bannerCheck(bannerRequest('GET','/content/banners')->get_data()['data']===[], 'No legacy poster produces empty list');
    echo "Content banners integration checks passed\n";
} finally {
    $old === null ? delete_option('meydan_content_banners') : update_option('meydan_content_banners',$old);
    $oldPoster === null ? delete_option('meydan_content_poster') : update_option('meydan_content_poster',$oldPoster);
    if ($media) wp_delete_attachment($media,true);
    if ($subscriber) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($subscriber); }
    wp_set_current_user($actor);
}

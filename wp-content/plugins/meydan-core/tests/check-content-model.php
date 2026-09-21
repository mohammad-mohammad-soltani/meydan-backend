<?php

use Meydan\Core\Support\Stats;

function contentCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function contentRequest(string $method, string $path, array $body = [], array $params = []): WP_REST_Response {
    $request = new WP_REST_Request($method, '/meydan/v1' . $path);
    foreach ($params as $key => $value) $request->set_param($key, $value);
    if ($body) { $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($body)); }
    return rest_do_request($request);
}

$admin = get_users(['role'=>'administrator','fields'=>'ID'])[0] ?? 0;
contentCheck((int)$admin > 0, 'Administrator required');
wp_set_current_user((int)$admin);
$user = 0; $creator = 0; $narrative = 0; $contentIds = []; $mediaIds = []; $paths = [];
$oldPoster = get_option('meydan_content_poster', null);
try {
    $user = wp_insert_user(['user_login'=>'content-model-'.wp_generate_password(10,false),'user_pass'=>wp_generate_password(),'role'=>'meydan_user']);
    contentCheck(!is_wp_error($user), 'Create user');
    $creatorResponse = contentRequest('POST','/admin/creators',['name'=>'تولیدکننده آزمایشی','verified'=>true]);
    contentCheck($creatorResponse->get_status()===201,'Create creator');
    $creator=(int)$creatorResponse->get_data()['data']['id'];
    contentCheck(contentRequest('GET',"/admin/creators/$creator")->get_data()['data']['verified']===true,'Creator verified');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lE8AAAAASUVORK5CYII=');
    for($i=0;$i<5;$i++){
        $upload=wp_upload_bits('content-model-'.wp_generate_password(8,false).'.png',null,$png);
        contentCheck(empty($upload['error']),'Upload image'); $paths[]=$upload['file'];
        $mediaId=wp_insert_attachment(['post_mime_type'=>'image/png','post_title'=>'فایل '.$i,'post_status'=>'inherit'],$upload['file']);
        contentCheck(!is_wp_error($mediaId),'Create attachment'); $mediaIds[]=(int)$mediaId;
    }
    $attached=array_map(static fn($id,$i)=>['media_id'=>$id,'media_title'=>'عنوان '.$i,'media_subtitle'=>'زیرعنوان '.$i],$mediaIds,array_keys($mediaIds));
    $payload=['title'=>'محتوای آزمایشی','body'=>'## متن مارک‌داون','is_user'=>false,'creator_id'=>$creator,'content_type'=>'speech','attached_media'=>$attached,'media_cover'=>$mediaIds[0]];
    $created=contentRequest('POST','/admin/content',$payload);
    contentCheck($created->get_status()===201,'Create content: '.wp_json_encode($created->get_data()));
    $id=(int)$created->get_data()['data']['id'];$contentIds[]=$id;
    $row=$created->get_data()['data'];
    contentCheck($row['is_user']===false&&$row['creator_id']===$creator&&$row['user_id']===null,'Creator owner');
    contentCheck(count($row['attached_media'])===5&&$row['attached_media'][0]['media_title']==='عنوان 0','Media metadata');
    contentCheck($row['media_cover_url']===wp_get_attachment_url($mediaIds[0]),'Cover URL');
    contentCheck($row['attached_media'][0]['media_mime_type']==='image/png'&&$row['attached_media'][0]['media_size']>0,'Media facts');
    contentCheck($row['time']!==null&&$row['created_at']!==null,'Automatic dates');
    contentCheck(contentRequest('POST','/admin/content',array_merge($payload,['user_id'=>$user,'is_user'=>true]))->get_status()===422,'Two owners rejected');
    contentCheck(contentRequest('POST','/admin/content',array_merge($payload,['creator_id'=>999999999]))->get_status()===422,'Unknown creator rejected');
    contentCheck(contentRequest('POST','/admin/content',array_merge($payload,['content_type'=>'other']))->get_status()===422,'Unknown content type rejected');
    $views=$row['view_counts'];
    contentRequest('GET',"/admin/content/$id");
    contentCheck(Stats::content($id)['views']===$views,'Admin read does not count view');
    contentRequest('GET',"/content/$id");
    contentCheck(Stats::content($id)['views']===$views+1,'Public detail counts view');
    $invalid=contentRequest('PATCH',"/admin/content/$id",['media_cover'=>999999999]);
    contentCheck($invalid->get_status()===422&&(int)get_post_meta($id,'meydan_media_cover',true)===$mediaIds[0],'Invalid cover leaves data intact');
    contentCheck(contentRequest('DELETE',"/admin/creators/$creator")->get_status()===409,'Owned creator protected');
    $reordered=array_reverse($attached);
    $updated=contentRequest('PATCH',"/admin/content/$id",['attached_media'=>$reordered]);
    contentCheck($updated->get_status()===200&&$updated->get_data()['data']['attached_media'][0]['media_id']===$mediaIds[4],'Media order');
    $narrative=wp_insert_post(['post_type'=>'meydan_narrative','post_status'=>'publish','post_author'=>$user,'post_content'=>'روایت آزمایشی']);
    update_post_meta($narrative,'meydan_attachments',[$attached[0],$attached[1]]);
    $published=get_post($narrative)->post_date_gmt;
    wp_set_current_user((int)$user);
    contentCheck(contentRequest('POST',"/admin/narratives/$narrative/content",['content_type'=>'speech'])->get_status()===403,'Only admin may convert');
    wp_set_current_user((int)$admin);
    contentCheck(contentRequest('POST',"/admin/narratives/$narrative/content",['format'=>'text'])->get_status()===422,'Conversion requires type');
    contentCheck(contentRequest('POST',"/admin/narratives/$narrative/content",['content_type'=>'report'])->get_status()===422,'Conversion requires primary attachment');
    contentCheck(contentRequest('POST',"/admin/narratives/$narrative/content",['content_type'=>'report','primary_attachment_id'=>999999999])->get_status()===422,'Primary attachment belongs to narrative');
    $converted=contentRequest('POST',"/admin/narratives/$narrative/content",['content_type'=>'report','format'=>'text','primary_attachment_id'=>$mediaIds[1]]);
    contentCheck($converted->get_status()===201,'Convert narrative');
    $convertedId=(int)$converted->get_data()['data']['id'];$contentIds[]=$convertedId;
    contentCheck($converted->get_data()['data']['user_id']===$user&&$converted->get_data()['data']['is_user']===true,'Publisher is user');
    contentCheck($converted->get_data()['data']['primary_attachment_id']===$mediaIds[1],'Primary attachment saved');
    contentCheck(strtotime($converted->get_data()['data']['time'])===strtotime($published.' UTC'),'Narrative time');
    contentCheck((int)contentRequest('POST',"/admin/narratives/$narrative/content",['content_type'=>'report'])->get_data()['data']['id']===$convertedId,'Idempotent conversion');
    $list=contentRequest('GET','/content',[],['content_type'=>'speech','creator_id'=>$creator]);
    contentCheck(in_array($id,array_column($list->get_data()['data'],'id'),true),'Creator filter');
    $music=contentRequest('POST','/admin/content',array_merge($payload,['title'=>'نمایش آوا و نوا','content_type'=>'music_video']));
    contentCheck($music->get_status()===201,'Create music video');
    $musicId=(int)$music->get_data()['data']['id'];$contentIds[]=$musicId;
    $musicList=contentRequest('GET','/content',[],['content_type'=>'music_video']);
    contentCheck(in_array($musicId,array_column($musicList->get_data()['data'],'id'),true),'Music video filter');
    $poster=contentRequest('PATCH','/admin/content/poster',['media_id'=>$mediaIds[0],'href'=>'/content/'.$id]);
    contentCheck($poster->get_status()===200&&$poster->get_data()['data']['media_id']===$mediaIds[0],'Save poster');
    contentCheck(contentRequest('GET','/config')->get_data()['data']['content_poster']['href']==='/content/'.$id,'Public poster');
    echo "Content model integration OK\n";
} finally {
    foreach($contentIds as $id) wp_delete_post($id,true);
    if($narrative) wp_delete_post($narrative,true);
    foreach($mediaIds as $id) wp_delete_attachment($id,true);
    foreach($paths as $path) if(is_file($path))unlink($path);
    if($creator) wp_delete_post($creator,true);
    if($user && !is_wp_error($user)){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);}
    if($oldPoster===null)delete_option('meydan_content_poster');else update_option('meydan_content_poster',$oldPoster,false);
}

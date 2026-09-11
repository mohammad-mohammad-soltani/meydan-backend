<?php
declare(strict_types=1);namespace Meydan\Core\Rest;
use Meydan\Core\Audit\AuditLogger;use Meydan\Core\Notifications\NotificationService;use Meydan\Core\Support\Actor;use Meydan\Core\Support\Response;use Meydan\Core\Support\Serializer;use WP_REST_Request;
final class MediaReflectionController extends BaseController
{
 public function list(WP_REST_Request $r){return Response::ok(Serializer::mediaReflections((int)$r['id']));}
 public function create(WP_REST_Request $r){if(!current_user_can('manage_meydan_media_reflections'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,0,(int)$r['id']);}
 public function update(WP_REST_Request $r){if(!current_user_can('manage_meydan_media_reflections'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,(int)$r['id'],0);}
 public function delete(WP_REST_Request $r){if(!current_user_can('manage_meydan_media_reflections'))return Response::error('forbidden','دسترسی کافی ندارید.',403);global $wpdb;$id=(int)$r['id'];$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_media_reflections WHERE id=%d",$id),ARRAY_A);if(!$row)return Response::error('not_found','بازنشر رسانه‌ای پیدا نشد.',404);$wpdb->delete($wpdb->prefix.'meydan_media_reflections',['id'=>$id]);AuditLogger::log('media_reflection_deleted','media_reflection',$id,$row,null);return Response::ok(['deleted'=>true]);}
 private function save(WP_REST_Request $r,int $id,int $nid)
 {
  global $wpdb;
  $p=$this->json($r);
  $before=null;
  if($id){
   $before=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_media_reflections WHERE id=%d",$id),ARRAY_A);
   if(!$before)return Response::error('not_found','بازنشر رسانه‌ای پیدا نشد.',404);
   $nid=(int)$before['narrative_id'];
  }
  if(get_post_type($nid)!=='meydan_narrative')return Response::error('not_found','روایت پیدا نشد.',404);
  $outletId=array_key_exists('outlet_id',$p)?(int)$p['outlet_id']:(int)($before['outlet_id']??0);
  $outlet=array_key_exists('outlet',$p)?sanitize_text_field((string)$p['outlet']):(string)($before['outlet']??'');
  if($outletId>0){
   if(get_post_type($outletId)!=='meydan_media_outlet')return Response::error('validation_failed','رسانه انتخابی معتبر نیست.',422);
   $outlet=(string)get_the_title($outletId);
  }
  $title=array_key_exists('title',$p)?sanitize_text_field((string)$p['title']):(string)($before['title']??'');
  $url=array_key_exists('url',$p)?esc_url_raw((string)$p['url']):(string)($before['url']??'');
  if($outlet===''||$title===''||$url==='')return Response::error('validation_failed','رسانه، عنوان و URL الزامی است.',422);
  $data=['narrative_id'=>$nid,'outlet'=>$outlet,'outlet_id'=>$outletId>0?$outletId:null,'title'=>$title,'summary'=>array_key_exists('summary',$p)?sanitize_textarea_field((string)$p['summary']):(string)($before['summary']??''),'url'=>$url,'logo_media_id'=>array_key_exists('logo_media_id',$p)?(int)$p['logo_media_id']:(int)($before['logo_media_id']??0),'published_at'=>!empty($p['published_at'])?gmdate('Y-m-d H:i:s',strtotime((string)$p['published_at'])):((string)($before['published_at']??'')?:current_time('mysql',true)),'status'=>array_key_exists('status',$p)?sanitize_key((string)$p['status']):(string)($before['status']??'published'),'position'=>array_key_exists('position',$p)?(int)$p['position']:(int)($before['position']??0),'updated_at'=>current_time('mysql',true)];
  if($id)$wpdb->update($wpdb->prefix.'meydan_media_reflections',$data,['id'=>$id]);else{$data['created_at']=current_time('mysql',true);$wpdb->insert($wpdb->prefix.'meydan_media_reflections',$data);$id=(int)$wpdb->insert_id;}
  AuditLogger::log($before?'media_reflection_updated':'media_reflection_created','media_reflection',$id,$before,$data);
  if(!$before){
   $author=Actor::fromNarrative($nid);
   $recipient=Actor::ownerUserId($author['type'],$author['type']==='square'?(int)str_replace('sq_','',$author['id']):(int)str_replace('usr_','',$author['id']));
   if($recipient)(new NotificationService())->fromTemplate($recipient,'media_reflection_added','user',get_current_user_id(),'narrative',$nid,'/posts/'.$nid,null,false,['reflection_id'=>$id]);
  }
  return Response::ok(['id'=>$id]+$data,[],$before?200:201);
 }
}

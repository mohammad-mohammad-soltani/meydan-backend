<?php
declare(strict_types=1);namespace Meydan\Core\Rest;
use Meydan\Core\Notifications\EventSubscriber;use Meydan\Core\Domain\UserAccess;use Meydan\Core\Support\Actor;use Meydan\Core\Support\Response;use Meydan\Core\Support\Serializer;use WP_Query;use WP_REST_Request;
final class ActorController extends BaseController
{
 public function user(WP_REST_Request $r){$id=(int)$r['id'];$u=get_userdata($id);if(!$u||UserAccess::disabled($id)||Actor::isEntityAccount($id))return Response::error('not_found','کاربر پیدا نشد.',404);return Response::ok(['id'=>$id,'actor'=>Actor::forUser($id),'profile'=>['full_name'=>(string)get_user_meta($id,'meydan_full_name',true),'headline'=>(string)get_user_meta($id,'meydan_headline',true),'about'=>(string)get_user_meta($id,'meydan_about',true),'skills'=>(array)get_user_meta($id,'meydan_skills',true),'cover_media_id'=>(int)get_user_meta($id,'meydan_cover_media_id',true)?:null,'cover_url'=>Actor::coverUrl($id),'location_label'=>(string)get_user_meta($id,'meydan_location_label',true),'province_id'=>(int)get_user_meta($id,'meydan_province_id',true)?:null,'city_id'=>(int)get_user_meta($id,'meydan_city_id',true)?:null]]);}
 public function userNarratives(WP_REST_Request $r){$id=(int)$r['id'];if(!UserAccess::visibleUser($id))return Response::error('not_found','کاربر پیدا نشد.',404);$meta=[['key'=>'meydan_author_actor_type','value'=>'user'],['key'=>'meydan_author_actor_id','value'=>$id]];return ProfileNarrativePage::list($meta,$r,'user:'.$id,$id,$id);}

 public function replies(WP_REST_Request $r){$type=sanitize_key((string)$r['type']);$id=(int)$r['id'];$ownerId=Actor::ownerUserId($type,$id);if(!$ownerId||UserAccess::disabled($ownerId))return Response::error('not_found','کاربر پیدا نشد.',404);$comments=get_comments(['user_id'=>$ownerId,'type'=>'meydan_comment','status'=>'approve','number'=>50,'orderby'=>'comment_date_gmt','order'=>'DESC']);return Response::ok(array_values(array_filter(array_map([Serializer::class,'comment'],$comments))));}
 public function followStates(WP_REST_Request $r){
  if(!is_user_logged_in())return Response::error('unauthenticated','برای مشاهده وضعیت دنبال‌کردن باید وارد شوید.',401);
  $input=$this->json($r);$actors=$input['actors']??[];
  if(!is_array($actors)||count($actors)>100)return Response::error('validation_failed','حداکثر ۱۰۰ شناسه مجاز است.',422);
  $groups=array_fill_keys(\Meydan\Core\Domain\EntityKinds::actorTypes(),[]);
  foreach($actors as $actor){
   if(!is_array($actor))continue;
   $type=(string)($actor['type']??'');$id=(int)($actor['id']??0);
   if(isset($groups[$type])&&$id>0)$groups[$type][$id]=$id;
  }
  global $wpdb;$table=$wpdb->prefix.'meydan_interactions';$uid=get_current_user_id();
  $hasAny=(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE user_id=%d AND action='follow' LIMIT 1",$uid));
  $where=[];$args=[$uid];
  foreach($groups as $type=>$ids){
   if(!$ids)continue;
   $where[]="(object_type=%s AND object_id IN (".implode(',',array_fill(0,count($ids),'%d'))."))";
   $args[]=$type;array_push($args,...array_values($ids));
  }
  $keys=[];
  if($where){
   $sql="SELECT object_type,object_id FROM {$table} WHERE user_id=%d AND action='follow' AND (".implode(' OR ',$where).")";
   foreach($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A)?:[] as $row)$keys[]=$row['object_type'].':'.$row['object_id'];
  }
  return Response::ok(['following_keys'=>$keys,'has_following'=>$hasAny]);
 }
 public function followState(WP_REST_Request $r){
  if(!is_user_logged_in())return Response::error('unauthenticated','برای مشاهده وضعیت دنبال‌کردن باید وارد شوید.',401);
  $type=sanitize_key((string)$r['type']);$id=(int)$r['id'];
  if(!Actor::parse($type,$id))return Response::error('not_found','Actor پیدا نشد.',404);
  global $wpdb;
  $following=(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND object_type=%s AND object_id=%d AND action='follow' LIMIT 1",get_current_user_id(),$type,$id));
  return Response::ok(['following'=>$following]);
 }
 public function follow(WP_REST_Request $r){return $this->toggle((string)$r['type'],(int)$r['id'],true);}
 public function unfollow(WP_REST_Request $r){return $this->toggle((string)$r['type'],(int)$r['id'],false);}
 public function followers(WP_REST_Request $r){$type=sanitize_key((string)$r['type']);$id=(int)$r['id'];$limit=$this->limit($r);global $wpdb;if(!Actor::parse($type,$id))return Response::error('not_found','Actor پیدا نشد.',404);$uids=$wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}meydan_interactions WHERE object_type=%s AND object_id=%d AND action='follow' ORDER BY created_at DESC LIMIT %d",$type,$id,$limit));return Response::ok(array_values(array_filter(array_map(static fn($uid)=>Actor::parse('user',(int)$uid),$uids?:[]))));}
 public function following(WP_REST_Request $r){$type=sanitize_key((string)$r['type']);$id=(int)$r['id'];$owner=Actor::ownerUserId($type,$id);if(!$owner||!Actor::parse($type,$id))return Response::error('not_found','Actor پیدا نشد.',404);$limit=$this->limit($r);global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT object_type,object_id FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND action='follow' ORDER BY created_at DESC LIMIT %d",$owner,$limit),ARRAY_A);$out=[];foreach($rows?:[] as $row){$a=Actor::parse($row['object_type'],(int)$row['object_id']);if($a)$out[]=$a;}return Response::ok($out);}
 private function toggle(string $type,int $id,bool $on){if(!is_user_logged_in())return Response::error('unauthenticated','برای دنبال‌کردن باید وارد شوید.',401);$type=sanitize_key($type);if(!in_array($type,\Meydan\Core\Domain\EntityKinds::actorTypes(),true)||!Actor::parse($type,$id))return Response::error('not_found','Actor پیدا نشد.',404);$uid=get_current_user_id();if(Actor::ownerUserId($type,$id)===$uid)return Response::error('validation_failed','نمی‌توانید خودتان را دنبال کنید.',422);global $wpdb;$table=$wpdb->prefix.'meydan_interactions';$exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d AND object_type=%s AND object_id=%d AND action='follow'",$uid,$type,$id));if($on&&!$exists){$wpdb->insert($table,['user_id'=>$uid,'object_type'=>$type,'object_id'=>$id,'action'=>'follow','created_at'=>current_time('mysql',true)]);EventSubscriber::follow($type,$id,$uid);}elseif(!$on&&$exists)$wpdb->delete($table,['id'=>$exists]);return Response::ok(['following'=>$on]);}
 private function limit(WP_REST_Request $r):int{return min(1000,max(1,(int)($r->get_param('limit')?:100)));}
}

<?php
declare(strict_types=1);namespace Meydan\Core\Rest;
use Meydan\Core\Support\Geocoder;use Meydan\Core\Support\Response;use Meydan\Core\Support\Serializer;use WP_Query;use WP_REST_Request;
final class MiscController extends BaseController
{
 public function handleCheck(WP_REST_Request $r){
  $rate=\Meydan\Core\Support\RateLimiter::hit('handle-check',\Meydan\Core\Support\RateLimiter::ip(),60,MINUTE_IN_SECONDS);
  if(!$rate['allowed'])return Response::error('rate_limited','تعداد درخواست‌ها زیاد است. کمی بعد دوباره تلاش کنید.',429);
  $except=is_user_logged_in()?get_current_user_id():0;
  $target=(int)$r->get_param('except_user');
  if($target>0&&(current_user_can('manage_options')||current_user_can('manage_meydan_squares')||current_user_can('manage_meydan_speakers')||current_user_can('manage_meydan_users')))$except=$target;
  $name=trim((string)$r->get_param('name'));
  $suggestion=$name!==''?\Meydan\Core\Support\Handles::generate($name,$except):null;
  if(trim((string)$r->get_param('handle'))===''&&$suggestion!==null)return Response::ok(['available'=>false,'reason'=>'required','message'=>'شناسه کاربری الزامی است.','suggestion'=>$suggestion]);
  $v=\Meydan\Core\Support\Handles::validate((string)$r->get_param('handle'),$except);
  if(is_wp_error($v)){$d=$v->get_error_data();return Response::ok(['available'=>false,'reason'=>(string)($d['fields']['handle']??'invalid'),'message'=>$v->get_error_message(),'suggestion'=>$suggestion]);}
  return Response::ok(['available'=>true,'handle'=>$v,'suggestion'=>$suggestion]);
 }
 public function provinces(){global $wpdb;$rows=$wpdb->get_results("SELECT id,name,slug,sort_order FROM {$wpdb->prefix}meydan_provinces WHERE active=1 ORDER BY sort_order,name",ARRAY_A);return Response::cache(Response::ok(array_map(static fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'slug'=>$r['slug']],$rows?:[])),'public, max-age=300');}
 public function cities(WP_REST_Request $r){global $wpdb;$pid=(int)$r->get_param('province_id');$sql="SELECT id,province_id,name,slug,sort_order FROM {$wpdb->prefix}meydan_cities WHERE active=1";$args=[];if($pid){$sql.=' AND province_id=%d';$args[]=$pid;}$sql.=' ORDER BY sort_order,name';if($args)$sql=$wpdb->prepare($sql,...$args);$rows=$wpdb->get_results($sql,ARRAY_A);return Response::cache(Response::ok(array_map(static fn($x)=>['id'=>(int)$x['id'],'province_id'=>(int)$x['province_id'],'name'=>$x['name'],'slug'=>$x['slug']],$rows?:[])),'public, max-age=300');}
 public function reverse(WP_REST_Request $r){$data=Geocoder::reverse((float)$r->get_param('latitude'),(float)$r->get_param('longitude'));return is_wp_error($data)?$this->error($data):Response::ok($data);}
 public function campaigns(){ $q=new WP_Query(['post_type'=>'meydan_campaign','post_status'=>'publish','posts_per_page'=>100,'orderby'=>'date','order'=>'DESC']);$data=array_values(array_filter(array_map([Serializer::class,'campaign'],$q->posts)));usort($data,static fn($a,$b)=>(($a['order']??0)<=>($b['order']??0))?:strcmp((string)($b['starts_at']??''),(string)($a['starts_at']??'')));return Response::cache(Response::ok($data),'public, max-age=60, stale-while-revalidate=300');}
 public function currentCampaign(){ $q=new WP_Query(['post_type'=>'meydan_campaign','post_status'=>'publish','posts_per_page'=>1,'meta_key'=>'meydan_current','meta_value'=>'1','orderby'=>'date','order'=>'DESC']);$d=$q->posts?Serializer::campaign($q->posts[0]):null;return $d?Response::ok($d):Response::ok(null);}
 public function campaign(WP_REST_Request $r){$d=Serializer::campaign((int)$r['id']);return $d?Response::ok($d):Response::error('not_found','کمپین پیدا نشد.',404);}
 public function campaignSchedule(WP_REST_Request $r){$d=Serializer::campaign((int)$r['id']);return $d?Response::ok($d['schedule']??[]):Response::error('not_found','کمپین پیدا نشد.',404);}
 public function config(){return Response::cache(Response::ok(['feature_flags'=>(array)get_option('meydan_feature_flags',[]),'quick_actions'=>(array)get_option('meydan_quick_actions',[]),'content_poster'=>(new ContentController())->posterData()]),'public, max-age=60, stale-while-revalidate=300');}
}

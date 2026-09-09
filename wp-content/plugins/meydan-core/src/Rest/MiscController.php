<?php
declare(strict_types=1);namespace Meydan\Core\Rest;
use Meydan\Core\Support\Response;use Meydan\Core\Support\Serializer;use WP_Query;use WP_REST_Request;
final class MiscController extends BaseController
{
 public function provinces(){global $wpdb;$rows=$wpdb->get_results("SELECT id,name,slug,sort_order FROM {$wpdb->prefix}meydan_provinces WHERE active=1 ORDER BY sort_order,name",ARRAY_A);return Response::cache(Response::ok(array_map(static fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'slug'=>$r['slug']],$rows?:[])),'public, max-age=300');}
 public function cities(WP_REST_Request $r){global $wpdb;$pid=(int)$r->get_param('province_id');$sql="SELECT id,province_id,name,slug,sort_order FROM {$wpdb->prefix}meydan_cities WHERE active=1";$args=[];if($pid){$sql.=' AND province_id=%d';$args[]=$pid;}$sql.=' ORDER BY sort_order,name';if($args)$sql=$wpdb->prepare($sql,...$args);$rows=$wpdb->get_results($sql,ARRAY_A);return Response::cache(Response::ok(array_map(static fn($x)=>['id'=>(int)$x['id'],'province_id'=>(int)$x['province_id'],'name'=>$x['name'],'slug'=>$x['slug']],$rows?:[])),'public, max-age=300');}
 public function currentCampaign(){ $q=new WP_Query(['post_type'=>'meydan_campaign','post_status'=>'publish','posts_per_page'=>1,'meta_key'=>'meydan_current','meta_value'=>'1','orderby'=>'meta_value_num','order'=>'DESC']);$d=$q->posts?Serializer::campaign($q->posts[0]):null;return $d?Response::ok($d):Response::ok(null);}
 public function campaign(WP_REST_Request $r){$d=Serializer::campaign((int)$r['id']);return $d?Response::ok($d):Response::error('not_found','کمپین پیدا نشد.',404);}
 public function campaignSchedule(WP_REST_Request $r){$d=Serializer::campaign((int)$r['id']);return $d?Response::ok($d['schedule']??[]):Response::error('not_found','کمپین پیدا نشد.',404);}
 public function config(){return Response::cache(Response::ok(['feature_flags'=>(array)get_option('meydan_feature_flags',[]),'quick_actions'=>(array)get_option('meydan_quick_actions',[])]),'public, max-age=60, stale-while-revalidate=300');}
}

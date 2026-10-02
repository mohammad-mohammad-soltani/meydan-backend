<?php
declare(strict_types=1);namespace Meydan\Core\Rest;
use Meydan\Core\Domain\InitiativeMembership;
use Meydan\Core\Notifications\NotificationService;use Meydan\Core\Support\Actor;use Meydan\Core\Support\EventLogger;use Meydan\Core\Support\Response;use Meydan\Core\Support\Serializer;use WP_Query;use WP_REST_Request;
final class InitiativeController extends BaseController
{
 public function list(){ $q=new WP_Query(['post_type'=>'meydan_initiative','post_status'=>'publish','posts_per_page'=>100,'orderby'=>'date','order'=>'DESC']);$data=array_values(array_filter(array_map([Serializer::class,'initiative'],$q->posts)));return Response::ok(array_map(fn($item)=>$this->withViewerState($item),$data));}
 public function get(WP_REST_Request $r){$d=Serializer::initiative((int)$r['id']);return $d?Response::ok($this->withViewerState($d)):Response::error('not_found','ابتکار پیدا نشد.',404);}
 public function participants(WP_REST_Request $r){$id=(int)$r['id'];$initiative=Serializer::initiative($id);if(!$initiative)return Response::error('not_found','ابتکار پیدا نشد.',404);global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT user_id,joined_at FROM {$wpdb->prefix}meydan_initiative_members WHERE initiative_id=%d AND status='active' AND user_id IS NOT NULL ORDER BY joined_at DESC,id DESC LIMIT 100",$id),ARRAY_A);$items=[];foreach($rows?:[] as $row){$uid=(int)$row['user_id'];if($uid<=0||!get_userdata($uid))continue;$actor=Actor::forUser($uid);$actor['joined_at']=gmdate(DATE_ATOM,strtotime((string)$row['joined_at'].' UTC'));$items[]=$actor;}return Response::ok(['items'=>$items,'participant_count'=>(int)$initiative['participant_count']]);}
 public function join(WP_REST_Request $r){return $this->toggle((int)$r['id'],true);}
 public function leave(WP_REST_Request $r){return $this->toggle((int)$r['id'],false);}
 private function toggle(int $id,bool $on)
 {
  $initiative=Serializer::initiative($id);
  if(!$initiative)return Response::error('not_found','ابتکار پیدا نشد.',404);
  $v=$this->viewer();
  if(!$v->isAuthenticated()&&!$initiative['allow_guest_join'])return Response::error('unauthenticated','این ابتکار نیاز به ورود دارد.',401);
  if($v->isAuthenticated()){
   $result=InitiativeMembership::setForUser($id,$v->userId,$on);
   return Response::ok(['joined'=>$result['joined'],'participant_count'=>$result['participant_count']]);
  }
  // Guests join the initiative only (no work-group membership).
  global $wpdb;$table=$wpdb->prefix.'meydan_initiative_members';
  $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE initiative_id=%d AND guest_id=%s",$id,$v->id));
  if($on){
   if($existing)$wpdb->update($table,['status'=>'active','joined_at'=>current_time('mysql',true)],['id'=>$existing]);
   else $wpdb->insert($table,['initiative_id'=>$id,'member_type'=>'guest','user_id'=>null,'guest_id'=>$v->id,'joined_at'=>current_time('mysql',true),'status'=>'active']);
   EventLogger::log('initiative_join','initiative',$id);
  }elseif($existing)$wpdb->update($table,['status'=>'left'],['id'=>$existing]);
  return Response::ok(['joined'=>$on,'participant_count'=>Serializer::initiative($id)['participant_count']]);
 }
 private function withViewerState(array $initiative):array{$v=$this->viewer();if($v->isAuthenticated()||empty($initiative['allow_guest_join']))return $initiative;global $wpdb;$initiative['viewer_state']=['joined'=>(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}meydan_initiative_members WHERE initiative_id=%d AND guest_id=%s AND status='active' LIMIT 1",(int)$initiative['id'],$v->id))];return $initiative;}
}

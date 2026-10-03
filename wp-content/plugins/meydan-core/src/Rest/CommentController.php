<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Notifications\EventSubscriber;
use Meydan\Core\Support\Affinity;
use Meydan\Core\Support\Cursor;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use Meydan\Core\Support\Stats;
use WP_REST_Request;

final class CommentController extends BaseController
{
    public function list(WP_REST_Request $r){$nid=(int)$r['id'];if(get_post_type($nid)!=='meydan_narrative'||!UserAccess::visibleNarrative($nid))return Response::error('not_found','روایت پیدا نشد.',404);$offset=$this->cursorOffset((string)$r->get_param('cursor'));$comments=get_comments(['post_id'=>$nid,'parent'=>0,'type'=>'meydan_comment','status'=>'approve','number'=>20,'offset'=>$offset,'orderby'=>'comment_date_gmt','order'=>'DESC']);Serializer::primeCommentLikes(array_map(static fn($c)=>(int)$c->comment_ID,$comments));$data=array_values(array_filter(array_map([Serializer::class,'comment'],$comments)));$next=count($comments)===20?Cursor::encode(['o'=>$offset+20]):null;return Response::ok($data,['next_cursor'=>$next]);}
    public function like(WP_REST_Request $r){return $this->setLike((int)$r['id'],true);}
    public function unlike(WP_REST_Request $r){return $this->setLike((int)$r['id'],false);}
    /** Idempotent: repeating a like (or an unlike) changes nothing and still answers with the current state. */
    private function setLike(int $id,bool $on){
        if(!is_user_logged_in())return Response::error('unauthenticated','برای پسندیدن باید وارد شوید.',401);
        $comment=get_comment($id);
        if(!$comment||$comment->comment_type!=='meydan_comment'||!UserAccess::visibleNarrative((int)$comment->comment_post_ID))return Response::error('not_found','کامنت پیدا نشد.',404);
        global $wpdb;$uid=get_current_user_id();$table=$wpdb->prefix.'meydan_interactions';
        if($on)$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (user_id, object_type, object_id, action, created_at) VALUES (%d, 'comment', %d, 'like', %s)",$uid,$id,current_time('mysql',true)));
        else $wpdb->delete($table,['user_id'=>$uid,'object_type'=>'comment','object_id'=>$id,'action'=>'like']);
        Serializer::forgetCommentLikes($id);
        $data=Serializer::comment($id);
        return Response::ok(['liked'=>$on,'likes'=>(int)($data['likes']??0)]);
    }
    public function replies(WP_REST_Request $r){
        $cid=(int)$r['id'];$root=get_comment($cid);
        if(!$root||$root->comment_type!=='meydan_comment'||!UserAccess::visibleNarrative((int)$root->comment_post_ID))return Response::error('not_found','کامنت پیدا نشد.',404);
        $offset=$this->cursorOffset((string)$r->get_param('cursor'));
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT comment_ID,comment_parent FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_type='meydan_comment' AND comment_approved='1' ORDER BY comment_date_gmt ASC,comment_ID ASC",
            (int)$root->comment_post_ID
        ))?:[];
        $ids=$this->descendants($rows,$cid);
        $pageIds=array_slice($ids,$offset,20);
        $comments=array_values(array_filter(array_map('get_comment',$pageIds)));Serializer::primeCommentLikes($pageIds);
        $next=count($ids)>$offset+20?Cursor::encode(['o'=>$offset+20]):null;
        return Response::ok(array_values(array_filter(array_map([Serializer::class,'comment'],$comments))),['next_cursor'=>$next]);
    }
    public function create(WP_REST_Request $r){if(!is_user_logged_in())return Response::error('unauthenticated','کامنت فقط برای کاربر واردشده مجاز است.',401);$nid=(int)$r['id'];if(get_post_type($nid)!=='meydan_narrative'||!UserAccess::visibleNarrative($nid))return Response::error('not_found','روایت پیدا نشد.',404);if(!$this->rate())return Response::error('rate_limited','تعداد کامنت‌ها بیش از حد مجاز است.',429);$p=$this->json($r);$body=trim((string)($p['body']??''));if($body==='')return Response::error('validation_failed','متن کامنت الزامی است.',422,['body'=>'required']);$parent=(int)($p['parent_id']??0);if($parent){$pc=get_comment($parent);if(!$pc||(int)$pc->comment_post_ID!==$nid||$pc->comment_type!=='meydan_comment')return Response::error('validation_failed','کامنت والد به این روایت تعلق ندارد.',422,['parent_id'=>'invalid']);}$uid=get_current_user_id();$id=wp_insert_comment(['comment_post_ID'=>$nid,'comment_content'=>wp_kses_post($body),'comment_parent'=>$parent,'user_id'=>$uid,'comment_type'=>'meydan_comment','comment_approved'=>1,'comment_author'=>'','comment_author_email'=>'']);if(!$id)return Response::error('internal_error','ثبت کامنت ناموفق بود.',500);Stats::incrementNarrative($nid,'comments',1);$actorType=(string)get_post_meta($nid,'meydan_author_actor_type',true);$actorId=(int)get_post_meta($nid,'meydan_author_actor_id',true);Affinity::bump($uid,$actorType,$actorId,'comment');EventSubscriber::comment($id);AuditLogger::log('comment_created','comment',$id,null,['narrative_id'=>$nid,'parent_id'=>$parent],$uid);return Response::ok(Serializer::comment($id),[],201);}
    public function update(WP_REST_Request $r){$c=get_comment((int)$r['id']);if(!$c||$c->comment_type!=='meydan_comment')return Response::error('not_found','کامنت پیدا نشد.',404);if(!is_user_logged_in()||((int)$c->user_id!==get_current_user_id()&&!current_user_can('moderate_meydan_narratives')))return Response::error('forbidden','اجازه ویرایش کامنت را ندارید.',403);$p=$this->json($r);$body=trim((string)($p['body']??''));if($body==='')return Response::error('validation_failed','متن کامنت الزامی است.',422);$before=Serializer::comment($c);wp_update_comment(['comment_ID'=>$c->comment_ID,'comment_content'=>wp_kses_post($body)]);update_comment_meta($c->comment_ID,'meydan_edited_at',current_time('mysql',true));AuditLogger::log('comment_updated','comment',$c->comment_ID,$before,Serializer::comment($c->comment_ID));return Response::ok(Serializer::comment($c->comment_ID));}
    public function delete(WP_REST_Request $r){$c=get_comment((int)$r['id']);if(!$c||$c->comment_type!=='meydan_comment')return Response::error('not_found','کامنت پیدا نشد.',404);if(!is_user_logged_in()||((int)$c->user_id!==get_current_user_id()&&!current_user_can('moderate_meydan_narratives')))return Response::error('forbidden','اجازه حذف کامنت را ندارید.',403);$before=Serializer::comment($c);wp_trash_comment($c->comment_ID);Stats::incrementNarrative((int)$c->comment_post_ID,'comments',-1);AuditLogger::log('comment_deleted','comment',$c->comment_ID,$before,['status'=>'trash']);return Response::ok(['deleted'=>true]);}
    private function cursorOffset(string $cursor):int{$v=Cursor::decode($cursor);return max(0,(int)($v['o']??0));}
    private function descendants(array $comments,int $root):array{$by=[];foreach($comments as $c)$by[(int)$c->comment_parent][]=(int)$c->comment_ID;$out=[];$walk=function($id)use(&$walk,&$out,$by){foreach($by[$id]??[] as $child){$out[]=$child;$walk($child);}};$walk($root);return $out;}
    private function rate():bool{global $wpdb;$uid=get_current_user_id();$n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id=%d AND comment_type='meydan_comment' AND comment_date_gmt>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)",$uid));return $n<20;}
}

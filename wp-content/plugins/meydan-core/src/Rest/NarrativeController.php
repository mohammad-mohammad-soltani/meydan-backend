<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Notifications\EventSubscriber;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Affinity;
use Meydan\Core\Support\EventLogger;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use Meydan\Core\Support\Stats;
use WP_REST_Request;

final class NarrativeController extends BaseController
{
    public function editorial(WP_REST_Request $r)
    {
        $limit = min(50, max(1, (int) ($r->get_param('limit') ?: 20)));
        $page = max(1, (int) ($r->get_param('page') ?: 1));
        $query = new \WP_Query([
            'post_type' => 'meydan_narrative',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'paged' => $page,
            'meta_key' => 'meydan_editorial',
            'meta_value' => '1',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        return Response::ok([
            'items' => array_values(array_filter(array_map(
                static fn($post) => Serializer::narrative($post),
                $query->posts,
            ))),
            'page' => $page,
            'per_page' => $limit,
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        ]);
    }

    public function markEditorial(WP_REST_Request $r)
    {
        return $this->setEditorial((int) $r['id'], true);
    }

    public function unmarkEditorial(WP_REST_Request $r)
    {
        return $this->setEditorial((int) $r['id'], false);
    }

    private function setEditorial(int $id, bool $editorial)
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'meydan_narrative') {
            return Response::error('not_found', 'روایت پیدا نشد.', 404);
        }

        $before = (bool) get_post_meta($id, 'meydan_editorial', true);
        if ($editorial) {
            update_post_meta($id, 'meydan_editorial', 1);
        } else {
            delete_post_meta($id, 'meydan_editorial');
        }
        AuditLogger::log('narrative_editorial_updated', 'narrative', $id, ['editorial' => $before], ['editorial' => $editorial]);

        return Response::ok([
            'id' => $id,
            'editorial' => $editorial,
        ]);
    }

    public function get(WP_REST_Request $r){$data=Serializer::narrative((int)$r['id']);return $data?Response::cache(Response::ok($data),'public, max-age=30, stale-while-revalidate=120'):Response::error('not_found','روایت پیدا نشد.',404);}
    public function create(WP_REST_Request $r){if(!is_user_logged_in())return Response::error('unauthenticated','برای انتشار روایت باید وارد شوید.',401);$p=$this->json($r);$body=trim((string)($p['body']??''));$attachments=$this->attachments((array)($p['attachments']??[]));if($body===''&&!$attachments)return Response::error('validation_failed','متن یا حداقل یک ضمیمه الزامی است.',422);if(!$this->publishRate())return Response::error('rate_limited','تعداد انتشارها بیش از حد مجاز است.',429);$poll=$this->poll((array)($p['poll']??[]));if(is_wp_error($poll))return $this->error($poll);$scheduled=isset($p['scheduled_at'])?strtotime((string)$p['scheduled_at']):false;$uid=get_current_user_id();$type=Actor::actorType($uid);$actorId=$type==='square'?(int)get_user_meta($uid,'meydan_square_id',true):$uid;$post=['post_type'=>'meydan_narrative','post_status'=>$scheduled&&$scheduled>time()?'future':'publish','post_content'=>wp_kses_post($body),'post_author'=>$uid];if($scheduled&&$scheduled>time()){$post['post_date_gmt']=gmdate('Y-m-d H:i:s',$scheduled);$post['post_date']=get_date_from_gmt($post['post_date_gmt']);}$id=wp_insert_post($post,true);if(is_wp_error($id))return $this->error($id);update_post_meta($id,'meydan_author_actor_type',$type);update_post_meta($id,'meydan_author_actor_id',$actorId);update_post_meta($id,'meydan_attachments',$attachments);update_post_meta($id,'meydan_initiative_id',(int)($p['initiative_id']??0));update_post_meta($id,'meydan_is_echo',(int)!empty($p['is_echo']));if($poll)update_post_meta($id,'meydan_poll',$poll);if(isset($p['location']['province_id']))update_post_meta($id,'meydan_province_id',(int)$p['location']['province_id']);if(isset($p['location']['city_id']))update_post_meta($id,'meydan_city_id',(int)$p['location']['city_id']);if(isset($p['tags']))wp_set_post_terms($id,array_values(array_filter(array_map('sanitize_text_field',(array)$p['tags']))),'meydan_narrative_tag');Stats::incrementNarrative($id,'views',0);AuditLogger::log('narrative_created','narrative',$id,null,['author'=>$type.':'.$actorId,'scheduled_at'=>$scheduled?:null]);return Response::ok(Serializer::narrative($id),[],201);}
    public function update(WP_REST_Request $r){$id=(int)$r['id'];$post=get_post($id);if(!$post||$post->post_type!=='meydan_narrative')return Response::error('not_found','روایت پیدا نشد.',404);if(!$this->canManage($id))return Response::error('forbidden','اجازه ویرایش این روایت را ندارید.',403);$before=Serializer::narrative($id);$p=$this->json($r);if(array_key_exists('body',$p))wp_update_post(['ID'=>$id,'post_content'=>wp_kses_post((string)$p['body'])]);if(isset($p['attachments']))update_post_meta($id,'meydan_attachments',$this->attachments((array)$p['attachments']));if(isset($p['tags']))wp_set_post_terms($id,array_values(array_filter(array_map('sanitize_text_field',(array)$p['tags']))),'meydan_narrative_tag');if(array_key_exists('initiative_id',$p))update_post_meta($id,'meydan_initiative_id',(int)$p['initiative_id']);if(array_key_exists('is_echo',$p))update_post_meta($id,'meydan_is_echo',(int)$this->bool($p['is_echo']));if(isset($p['location']['province_id']))update_post_meta($id,'meydan_province_id',(int)$p['location']['province_id']);if(isset($p['location']['city_id']))update_post_meta($id,'meydan_city_id',(int)$p['location']['city_id']);AuditLogger::log('narrative_updated','narrative',$id,$before,Serializer::narrative($id));return Response::ok(Serializer::narrative($id));}
    public function delete(WP_REST_Request $r){$id=(int)$r['id'];if(get_post_type($id)!=='meydan_narrative')return Response::error('not_found','روایت پیدا نشد.',404);if(!$this->canManage($id))return Response::error('forbidden','اجازه حذف این روایت را ندارید.',403);$before=Serializer::narrative($id);wp_trash_post($id);AuditLogger::log('narrative_deleted','narrative',$id,$before,['status'=>'trash']);return Response::ok(['deleted'=>true]);}
    public function like(WP_REST_Request $r){return $this->interaction((int)$r['id'],'like',true);}
    public function unlike(WP_REST_Request $r){return $this->interaction((int)$r['id'],'like',false);}
    public function repost(WP_REST_Request $r){return $this->interaction((int)$r['id'],'repost',true);}
    public function unrepost(WP_REST_Request $r){return $this->interaction((int)$r['id'],'repost',false);}
    public function share(WP_REST_Request $r){$id=(int)$r['id'];if(get_post_type($id)!=='meydan_narrative')return Response::error('not_found','روایت پیدا نشد.',404);Stats::incrementNarrative($id,'shares',1);EventLogger::log('share','narrative',$id);return Response::ok(['shared'=>true,'shares'=>Stats::narrative($id)['shares']]);}
    private function interaction(int $id,string $action,bool $on){if(!is_user_logged_in())return Response::error('unauthenticated','برای این عملیات باید وارد شوید.',401);if(get_post_type($id)!=='meydan_narrative')return Response::error('not_found','روایت پیدا نشد.',404);global $wpdb;$uid=get_current_user_id();$table=$wpdb->prefix.'meydan_interactions';$exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d AND object_type='narrative' AND object_id=%d AND action=%s",$uid,$id,$action));if($on&&!$exists){$wpdb->insert($table,['user_id'=>$uid,'object_type'=>'narrative','object_id'=>$id,'action'=>$action,'created_at'=>current_time('mysql',true)]);Stats::incrementNarrative($id,$action==='like'?'likes':'reposts',1);$actorType=(string)get_post_meta($id,'meydan_author_actor_type',true);$actorId=(int)get_post_meta($id,'meydan_author_actor_id',true);Affinity::bump($uid,$actorType,$actorId,$action);EventSubscriber::narrativeInteraction($action,$id,$uid);}elseif(!$on&&$exists){$wpdb->delete($table,['id'=>$exists]);Stats::incrementNarrative($id,$action==='like'?'likes':'reposts',-1);}return Response::ok([$action.'d'=>$on,'stats'=>Stats::narrative($id)]);}
    private function canManage(int $id):bool{return is_user_logged_in()&&((int)get_post_field('post_author',$id)===get_current_user_id()||current_user_can('moderate_meydan_narratives')||current_user_can('manage_options'));}
    private function attachments(array $items):array{$out=[];$seen=[];foreach($items as $i=>$item){if(!is_array($item))continue;$mid=(int)($item['media_id']??0);if($mid<=0||get_post_type($mid)!=='attachment'||isset($seen[$mid]))continue;$seen[$mid]=true;$out[]=['media_id'=>$mid,'order'=>(int)($item['order']??$i+1),'caption'=>isset($item['caption'])?sanitize_text_field((string)$item['caption']):null,'label'=>isset($item['label'])?sanitize_text_field((string)$item['label']):null];}usort($out,static fn($a,$b)=>$a['order']<=>$b['order']);return $out;}
    private function poll(array $poll):array|\WP_Error{$options=array_values(array_filter(array_map(static fn($x)=>sanitize_text_field((string)$x),(array)($poll['options']??[]))));if(!$options)return [];if(count($options)<2||count($options)>4)return new \WP_Error('validation_failed','نظرسنجی باید بین دو تا چهار گزینه داشته باشد.',['status'=>422,'fields'=>['poll'=>'invalid']]);return ['options'=>$options,'votes'=>array_fill(0,count($options),0)];}
    private function publishRate():bool{global $wpdb;$uid=get_current_user_id();$n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='meydan_narrative' AND post_author=%d AND post_date_gmt>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)",$uid));return $n<10;}
}

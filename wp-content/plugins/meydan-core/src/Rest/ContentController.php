<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Cursor;
use Meydan\Core\Support\EventLogger;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use Meydan\Core\Support\Stats;
use WP_Query;
use WP_REST_Request;

final class ContentController extends BaseController
{
    public const CONTENT_TYPES = ['placard', 'speech', 'music_video', 'video', 'report', 'note'];

    public function list(WP_REST_Request $r) { return $this->query($r, false); }
    public function adminList(WP_REST_Request $r) { if (!current_user_can('manage_meydan_content')) return Response::error('forbidden','دسترسی کافی ندارید.',403); return $this->query($r, true); }
    public function adminGet(WP_REST_Request $r) { if (!current_user_can('manage_meydan_content')) return Response::error('forbidden','دسترسی کافی ندارید.',403); $id=(int)$r['id']; $data=Serializer::content($id); return $data?Response::ok($this->enrich($data,$id)):Response::error('not_found','محتوا پیدا نشد.',404); }
    public function poster() { if(!current_user_can('manage_meydan_content'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return Response::ok($this->posterData()); }
    public function updatePoster(WP_REST_Request $r) {
        if(!current_user_can('manage_meydan_content'))return Response::error('forbidden','دسترسی کافی ندارید.',403);
        $p=$this->json($r);$id=(int)($p['media_id']??0);$href=trim((string)($p['href']??''));
        if($id>0&&!wp_attachment_is_image($id))return Response::error('validation_failed','پوستر باید تصویر آپلودشده باشد.',422,['media_id'=>'invalid']);
        if($href!==''&&!preg_match('#^/(?!/)#',$href)&&!filter_var($href,FILTER_VALIDATE_URL))return Response::error('validation_failed','نشانی پیوند معتبر نیست.',422,['href'=>'invalid']);
        if(preg_match('#^https?://#i',$href)===0&&$href!==''&&!str_starts_with($href,'/'))return Response::error('validation_failed','نشانی پیوند معتبر نیست.',422,['href'=>'invalid']);
        update_option('meydan_content_poster',['media_id'=>$id,'href'=>esc_url_raw($href)],false);
        return Response::ok($this->posterData());
    }
    public function posterData():array { $p=(array)get_option('meydan_content_poster',[]);$id=(int)($p['media_id']??0);return ['media_id'=>$id?:null,'image_url'=>$id?wp_get_attachment_url($id):null,'href'=>(string)($p['href']??'')]; }

    public function get(WP_REST_Request $r) {
        $id=(int)$r['id']; $data=Serializer::content($id);
        if(!$data)return Response::error('not_found','محتوا پیدا نشد.',404);
        Stats::incrementContent($id,'views',1); EventLogger::log('detail_open','content',$id);
        $data['stats']=Stats::content($id)+['likes'=>$this->likeCount($id)]; $data['view_counts']=$data['stats']['views'];if(is_user_logged_in()){global $wpdb;$data['viewer_state']=($data['viewer_state']??[])+['liked'=>(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND object_type='content' AND object_id=%d AND action='like' LIMIT 1",get_current_user_id(),$id))];}
        return Response::ok($this->enrich($data,$id));
    }
    public function like(WP_REST_Request $r){if(!$this->likeRate())return Response::error('rate_limited','تعداد درخواست‌ها بیش از حد مجاز است.',429);return $this->toggle((int)$r['id'],'like',true);}
    public function unlike(WP_REST_Request $r){if(!$this->likeRate())return Response::error('rate_limited','تعداد درخواست‌ها بیش از حد مجاز است.',429);return $this->toggle((int)$r['id'],'like',false);}
    private function likeRate():bool{return \Meydan\Core\Support\RateLimiter::hit('like','u'.get_current_user_id(),120,MINUTE_IN_SECONDS)['allowed'];}
    public function bookmark(WP_REST_Request $r){return $this->toggle((int)$r['id'],'bookmark',true);}
    public function unbookmark(WP_REST_Request $r){return $this->toggle((int)$r['id'],'bookmark',false);}
    public function share(WP_REST_Request $r){$id=(int)$r['id'];if(!Serializer::content($id))return Response::error('not_found','محتوا پیدا نشد.',404);Stats::incrementContent($id,'shares',1);EventLogger::log('share','content',$id);return Response::ok(['shared'=>true,'shares'=>Stats::content($id)['shares']]);}
    public function download(WP_REST_Request $r){$id=(int)$r['id'];$fid=(int)$r['file_id'];$c=Serializer::content($id);if(!$c)return Response::error('not_found','محتوا پیدا نشد.',404);foreach($c['attachments'] as $a)if((int)$a['id']===$fid){Stats::incrementContent($id,'downloads',1);EventLogger::log('download','content',$id,['file_id'=>$fid]);return Response::ok(['file'=>$a,'downloads'=>Stats::content($id)['downloads']]);}return Response::error('not_found','فایل پیدا نشد.',404);}
    public function adminCreate(WP_REST_Request $r){if(!current_user_can('manage_meydan_content'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,0);}
    public function adminUpdate(WP_REST_Request $r){if(!current_user_can('manage_meydan_content'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,(int)$r['id']);}
    public function adminDelete(WP_REST_Request $r){if(!current_user_can('manage_meydan_content'))return Response::error('forbidden','دسترسی کافی ندارید.',403);$id=(int)$r['id'];$before=Serializer::content($id);if(!$before)return Response::error('not_found','محتوا پیدا نشد.',404);wp_trash_post($id);AuditLogger::log('content_deleted','content',$id,$before,['status'=>'trash']);return Response::ok(['deleted'=>true]);}

    public function convertNarrative(WP_REST_Request $r){
        if(!current_user_can('manage_meydan_content'))return Response::error('forbidden','دسترسی کافی ندارید.',403);
        $n=get_post((int)$r['id']);if(!$n||$n->post_type!=='meydan_narrative'||$n->post_status!=='publish')return Response::error('not_found','روایت پیدا نشد.',404);
        $p=$this->json($r);$type=sanitize_key((string)($p['content_type']??''));if(!in_array($type,self::CONTENT_TYPES,true))return Response::error('validation_failed','نوع محتوا معتبر نیست.',422,['content_type'=>'invalid']);
        $existing=(int)get_post_meta($n->ID,'meydan_content_id',true);if($existing&&get_post_type($existing)==='meydan_content'&&get_post_status($existing)==='publish')return Response::ok($this->enrich(Serializer::content($existing),$existing));if($existing)delete_post_meta($n->ID,'meydan_content_id');
        $userId=(int)$n->post_author;if($userId<=0||!get_userdata($userId))return Response::error('validation_failed','ناشر روایت معتبر نیست.',422,['user_id'=>'invalid']);
        $sourceAttachments=get_post_meta($n->ID,'meydan_attachments',true);$sourceMedia=$this->normalizeAttachedMedia(is_array($sourceAttachments)?$sourceAttachments:[]);if(is_wp_error($sourceMedia))return $this->error($sourceMedia);
        $primaryId=(int)($p['primary_attachment_id']??0);$sourceIds=array_map(static fn(array $item):int=>(int)$item['media_id'],$sourceMedia);
        if($sourceIds&&$primaryId<=0)return Response::error('validation_failed','انتخاب فایل اصلی الزامی است.',422,['primary_attachment_id'=>'required']);
        if(($primaryId>0&&(!in_array($primaryId,$sourceIds,true))))return Response::error('validation_failed','فایل اصلی باید یکی از ضمیمه‌های روایت باشد.',422,['primary_attachment_id'=>'invalid']);
        $title=sanitize_text_field((string)($p['title']??''));if($title==='')return Response::error('validation_failed','عنوان محتوا الزامی است.',422,['title'=>'required']);
        $id=wp_insert_post(['post_type'=>'meydan_content','post_status'=>'publish','post_title'=>$title,'post_content'=>$n->post_content,'post_excerpt'=>''],true);if(is_wp_error($id))return $this->error($id);$id=(int)$id;
        update_post_meta($id,'meydan_content_type',$type);update_post_meta($id,'meydan_format',sanitize_key((string)($p['format']??'mixed'))?:'mixed');$this->storeOwner($id,['is_user'=>true,'user_id'=>$userId,'creator_id'=>0]);update_post_meta($id,'meydan_time',$n->post_date_gmt);update_post_meta($id,'meydan_attachments',$sourceMedia);if($primaryId>0)update_post_meta($id,'meydan_primary_attachment_id',$primaryId);update_post_meta($id,'meydan_source_narrative_id',(int)$n->ID);
        $actorType=(string)get_post_meta($n->ID,'meydan_author_actor_type',true);$actorId=(int)get_post_meta($n->ID,'meydan_author_actor_id',true);if(!in_array($actorType,\Meydan\Core\Domain\EntityKinds::actorTypes(),true)||$actorId<=0){$actorType=Actor::actorType($userId);$actorId=$actorType!=='user'?Actor::entityId($userId):$userId;}update_post_meta($id,'meydan_producer_actor_type',$actorType);update_post_meta($id,'meydan_producer_actor_id',$actorId);
        update_post_meta($n->ID,'meydan_content_id',$id);Stats::incrementContent($id,'views',0);AuditLogger::log('narrative_converted_to_content','content',$id,null,['narrative_id'=>(int)$n->ID,'user_id'=>$userId]);return Response::ok($this->enrich(Serializer::content($id),$id),[],201);
    }
    public function removeNarrativeContent(WP_REST_Request $r){if(!current_user_can('manage_meydan_content'))return Response::error('forbidden','دسترسی کافی ندارید.',403);$nid=(int)$r['id'];$n=get_post($nid);if(!$n||$n->post_type!=='meydan_narrative')return Response::error('not_found','روایت پیدا نشد.',404);$id=(int)get_post_meta($nid,'meydan_content_id',true);$c=get_post($id);if(!$c||$c->post_type!=='meydan_content'||(int)get_post_meta($id,'meydan_source_narrative_id',true)!==$nid){delete_post_meta($nid,'meydan_content_id');return Response::error('not_found','محتوای مرتبط پیدا نشد.',404);}$before=$this->enrich(Serializer::content($id),$id);wp_trash_post($id);delete_post_meta($nid,'meydan_content_id');AuditLogger::log('narrative_content_removed','content',$id,$before,['narrative_id'=>$nid,'status'=>'trash']);return Response::ok(['deleted'=>true,'content_id'=>$id]);}

    private function query(WP_REST_Request $r,bool $admin){$offset=$this->offset((string)$r->get_param('cursor'));$meta=[];$tax=[];if($v=$r->get_param('format'))$meta[]=['key'=>'meydan_format','value'=>sanitize_key((string)$v)];if($v=$r->get_param('content_type')){$types=array_values(array_filter(array_map('sanitize_key',explode(',',(string)$v))));$meta[]=count($types)>1?['key'=>'meydan_content_type','value'=>$types,'compare'=>'IN']:['key'=>'meydan_content_type','value'=>$types[0]??''];}if($this->bool($r->get_param('featured')))$meta[]=['key'=>'meydan_featured','value'=>'1'];if(($id=(int)$r->get_param('user_id'))>0)$meta[]=['key'=>'meydan_user_id','value'=>$id,'type'=>'NUMERIC'];if(($id=(int)$r->get_param('creator_id'))>0)$meta[]=['key'=>'meydan_creator_id','value'=>$id,'type'=>'NUMERIC'];if(($v=sanitize_key((string)$r->get_param('producer_type')))&&($pid=(int)$r->get_param('producer_id'))>0){$meta[]=['key'=>'meydan_producer_actor_type','value'=>$v];$meta[]=['key'=>'meydan_producer_actor_id','value'=>$pid,'type'=>'NUMERIC'];}if(($v=trim((string)$r->get_param('series')))!=='')$meta[]=['key'=>'meydan_series','value'=>sanitize_text_field($v)];if($v=$r->get_param('category'))$tax[]=['taxonomy'=>'meydan_content_category','field'=>is_numeric($v)?'term_id':'slug','terms'=>$v];if($v=$r->get_param('tag'))$tax[]=['taxonomy'=>'meydan_content_tag','field'=>'slug','terms'=>sanitize_title((string)$v)];$args=['post_type'=>'meydan_content','post_status'=>'publish','posts_per_page'=>20,'offset'=>$offset,'orderby'=>'date','order'=>'DESC'];if(($qs=trim((string)$r->get_param('q')))!=='')$args['s']=$qs;if($meta)$args['meta_query']=$meta;if($tax)$args['tax_query']=$tax;$q=new WP_Query($args);Stats::primeContents(wp_list_pluck($q->posts,'ID'));$data=array_values(array_filter(array_map(fn($post)=>$this->enrich(Serializer::content($post),(int)$post->ID),$q->posts)));return Response::cache(Response::ok($data,['next_cursor'=>count($q->posts)===20?Cursor::encode(['o'=>$offset+20]):null]),$admin?'private, no-store':'public, max-age=60, stale-while-revalidate=300');}

    private function save(WP_REST_Request $r,int $id){
        $p=$this->json($r);
        if($id&&get_post_type($id)!=='meydan_content')return Response::error('not_found','محتوا پیدا نشد.',404);
        $before=$id?Serializer::content($id):null;
        $owner=$this->validateOwner($p,$id);
        if(is_wp_error($owner))return $this->error($owner);
        $type=array_key_exists('content_type',$p)?sanitize_key((string)$p['content_type']):($id?(string)get_post_meta($id,'meydan_content_type',true):'');
        if(!in_array($type,self::CONTENT_TYPES,true))return Response::error('validation_failed','نوع محتوا معتبر نیست.',422,['content_type'=>'invalid']);
        $title=sanitize_text_field((string)($p['title']??($id?get_the_title($id):'')));
        if($title==='')return Response::error('validation_failed','عنوان الزامی است.',422,['title'=>'required']);
        $hasMedia=array_key_exists('attached_media',$p)||array_key_exists('attachments',$p);
        $media=$hasMedia?$this->normalizeAttachedMedia($p['attached_media']??$p['attachments']):null;
        if(is_wp_error($media))return $this->error($media);
        $hasCover=array_key_exists('media_cover',$p);
        $cover=$hasCover?(int)$p['media_cover']:0;
        if($hasCover&&$p['media_cover']!==null&&$p['media_cover']!==''&&($cover<=0||!wp_attachment_is_image($cover)))return Response::error('validation_failed','کاور باید تصویر آپلودشده باشد.',422,['media_cover'=>'invalid']);
        $time=array_key_exists('time',$p)?$this->normalizeTime($p['time']):null;
        if(array_key_exists('time',$p)&&$time===null)return Response::error('validation_failed','زمان محتوا معتبر نیست.',422,['time'=>'invalid']);
        $post=['post_type'=>'meydan_content','post_status'=>sanitize_key((string)($p['status']??'publish')),'post_title'=>$title,'post_content'=>wp_kses_post((string)($p['body']??($id?get_post_field('post_content',$id):''))),'post_excerpt'=>sanitize_textarea_field((string)($p['excerpt']??($id?get_post_field('post_excerpt',$id):'')))];
        if($id)$post['ID']=$id;
        $res=$id?wp_update_post($post,true):wp_insert_post($post,true);
        if(is_wp_error($res))return $this->error($res);
        $id=(int)$res;
        update_post_meta($id,'meydan_content_type',$type);
        $this->storeOwner($id,$owner);
        foreach(['format','usage_note','subtitle','badge','location_label','media_duration','series'] as $k)if(array_key_exists($k,$p))update_post_meta($id,'meydan_'.$k,$k==='format'?sanitize_key((string)$p[$k]):sanitize_text_field((string)$p[$k]));
        if(array_key_exists('featured',$p))update_post_meta($id,'meydan_featured',(int)$this->bool($p['featured']));
        if($hasMedia)update_post_meta($id,'meydan_attachments',$media);
        if($hasCover){if($cover>0)update_post_meta($id,'meydan_media_cover',$cover);else delete_post_meta($id,'meydan_media_cover');}
        if($time!==null)update_post_meta($id,'meydan_time',$time);elseif(!get_post_meta($id,'meydan_time',true))update_post_meta($id,'meydan_time',current_time('mysql',true));
        if(isset($p['files']))update_post_meta($id,'meydan_files',(array)$p['files']);
        if(isset($p['tags']))wp_set_post_terms($id,array_map('sanitize_text_field',(array)$p['tags']),'meydan_content_tag');
        if(isset($p['category']))wp_set_post_terms($id,[(int)$p['category']],'meydan_content_category');
        AuditLogger::log($before?'content_updated':'content_created','content',$id,$before,Serializer::content($id));
        return Response::ok($this->enrich(Serializer::content($id),$id),[],$before?200:201);
    }
    private function validateOwner(array $p,int $id):array|\WP_Error{$has=array_key_exists('is_user',$p)||array_key_exists('user_id',$p)||array_key_exists('creator_id',$p);if(!$has&&$id>0){$p=['is_user'=>(bool)get_post_meta($id,'meydan_is_user',true),'user_id'=>(int)get_post_meta($id,'meydan_user_id',true),'creator_id'=>(int)get_post_meta($id,'meydan_creator_id',true)];}elseif(!array_key_exists('is_user',$p))return new \WP_Error('validation_failed','نوع مالک الزامی است.',['status'=>422,'fields'=>['is_user'=>'required']]);$isUser=$this->bool($p['is_user']);$userId=(int)($p['user_id']??($isUser&&$id?(int)get_post_meta($id,'meydan_user_id',true):0));$creatorId=(int)($p['creator_id']??(!$isUser&&$id?(int)get_post_meta($id,'meydan_creator_id',true):0));if($isUser){if($userId<=0||!get_userdata($userId)||$creatorId>0)return new \WP_Error('validation_failed','مالک کاربر معتبر نیست.',['status'=>422,'fields'=>['user_id'=>'invalid']]);return ['is_user'=>true,'user_id'=>$userId,'creator_id'=>0];}if($creatorId<=0||get_post_type($creatorId)!=='meydan_creator'||get_post_status($creatorId)!=='publish'||$userId>0)return new \WP_Error('validation_failed','تولیدکننده معتبر نیست.',['status'=>422,'fields'=>['creator_id'=>'invalid']]);return ['is_user'=>false,'user_id'=>0,'creator_id'=>$creatorId];}
    private function storeOwner(int $id,array $owner):void{update_post_meta($id,'meydan_is_user',$owner['is_user']?1:0);if($owner['user_id']){update_post_meta($id,'meydan_user_id',$owner['user_id']);$type=Actor::actorType($owner['user_id']);$actorId=$type!=='user'?Actor::entityId($owner['user_id']):$owner['user_id'];update_post_meta($id,'meydan_producer_actor_type',$type);update_post_meta($id,'meydan_producer_actor_id',$actorId);}else{delete_post_meta($id,'meydan_user_id');delete_post_meta($id,'meydan_producer_actor_type');delete_post_meta($id,'meydan_producer_actor_id');}if($owner['creator_id']){update_post_meta($id,'meydan_creator_id',$owner['creator_id']);global $wpdb;$table=$wpdb->prefix.'meydan_content_creators';$wpdb->delete($table,['content_id'=>$id]);$wpdb->insert($table,['content_id'=>$id,'creator_id'=>$owner['creator_id'],'position'=>0,'role_label'=>null]);}else{delete_post_meta($id,'meydan_creator_id');global $wpdb;$wpdb->delete($wpdb->prefix.'meydan_content_creators',['content_id'=>$id]);}}
    private function normalizeAttachedMedia(mixed $items):array|\WP_Error{if(!is_array($items))return new \WP_Error('validation_failed','فهرست رسانه معتبر نیست.',['status'=>422,'fields'=>['attached_media'=>'invalid']]);$out=[];$seen=[];foreach($items as $item){if(!is_array($item))return new \WP_Error('validation_failed','رسانه معتبر نیست.',['status'=>422,'fields'=>['attached_media'=>'invalid']]);$id=(int)($item['media_id']??$item['id']??0);if($id<=0||get_post_type($id)!=='attachment'||isset($seen[$id]))return new \WP_Error('validation_failed','رسانه معتبر یا یکتا نیست.',['status'=>422,'fields'=>['attached_media'=>'invalid']]);$seen[$id]=true;$title=sanitize_text_field((string)($item['media_title']??$item['label']??get_the_title($id)));$subtitle=sanitize_text_field((string)($item['media_subtitle']??$item['caption']??''));$out[]=['media_id'=>$id,'order'=>count($out)+1,'media_title'=>$title,'media_subtitle'=>$subtitle,'label'=>$title,'caption'=>$subtitle];}return $out;}
    private function normalizeTime(mixed $time):?string{if(!is_string($time)||trim($time)==='')return null;try{$value=trim($time);$zone=preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i',$value)?new \DateTimeZone('UTC'):new \DateTimeZone('Asia/Tehran');$date=new \DateTimeImmutable($value,$zone);return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(\Exception){return null;}}
    private function enrich(?array $data,int $id):?array{if(!$data)return null;$post=get_post($id);$data['slug']=$post?$post->post_name:(string)$id;$data['subtitle']=(string)get_post_meta($id,'meydan_subtitle',true);$data['badge']=(string)get_post_meta($id,'meydan_badge',true);$data['location_label']=(string)get_post_meta($id,'meydan_location_label',true);$data['media_duration']=(string)get_post_meta($id,'meydan_media_duration',true);$data['series']=(string)get_post_meta($id,'meydan_series',true)?:null;$data['reading_minutes']=trim((string)$data['body'])===''?null:max(1,(int)ceil(count(preg_split('/\s+/u',trim(wp_strip_all_tags((string)$data['body'])),-1,PREG_SPLIT_NO_EMPTY)?:[])/180));$data['primary_attachment_id']=(int)get_post_meta($id,'meydan_primary_attachment_id',true);$data['files']=array_values((array)get_post_meta($id,'meydan_files',true));return $data;}
    private function toggle(int $id,string $action,bool $on){if(!is_user_logged_in())return Response::error('unauthenticated','برای این عملیات باید وارد شوید.',401);if(!Serializer::content($id))return Response::error('not_found','محتوا پیدا نشد.',404);global $wpdb;$uid=get_current_user_id();$table=$wpdb->prefix.'meydan_interactions';$exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d AND object_type='content' AND object_id=%d AND action=%s",$uid,$id,$action));if($on&&!$exists){$wpdb->insert($table,['user_id'=>$uid,'object_type'=>'content','object_id'=>$id,'action'=>$action,'created_at'=>current_time('mysql',true)]);if($action==='bookmark')Stats::incrementContent($id,'bookmarks',1);}elseif(!$on&&$exists){$wpdb->delete($table,['id'=>$exists]);if($action==='bookmark')Stats::incrementContent($id,'bookmarks',-1);}return Response::ok([$action==='like'?'liked':'bookmarked'=>$on,'stats'=>Stats::content($id)+['likes'=>$this->likeCount($id)]]);}
    private function likeCount(int $id):int{global $wpdb;return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}meydan_interactions WHERE object_type='content' AND object_id=%d AND action='like'",$id));}
    private function offset(string $c):int{$v=Cursor::decode($c);return max(0,(int)($v['o']??0));}
}

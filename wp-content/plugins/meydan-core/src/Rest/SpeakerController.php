<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;
use Meydan\Core\Audit\AuditLogger;use Meydan\Core\Domain\SpeakerService;use Meydan\Core\Support\Response;use Meydan\Core\Support\Serializer;use WP_Query;use WP_REST_Request;
/**
 * Speaker profiles (`meydan_speaker`).
 *
 * Response shapes intentionally mirror the pre-1.1.0 creator-backed speaker
 * API so existing clients keep working; only the backing post type changed.
 */
final class SpeakerController extends BaseController
{
    public function list(WP_REST_Request $r){return $this->query($r);}
    public function get(WP_REST_Request $r){$id=(int)$r['id'];$d=$this->enrich(Serializer::speaker($id),$id);return $d?Response::cache(Response::ok($d),'public, max-age=60, stale-while-revalidate=300'):Response::error('not_found','سخنران پیدا نشد.',404);}
    /** Admin: the users selectable as the account behind a speaker. */
    public function linkableUsers(WP_REST_Request $r){if(!current_user_can('manage_meydan_speakers'))return Response::error('forbidden','دسترسی کافی ندارید.',403);$out=[];foreach(SpeakerService::linkableUsers() as $id=>$name)$out[]=['id'=>(int)$id,'name'=>$name];return Response::ok($out);}
    /** Topical categories for the filter UI. Public and cheap. */
    public function categories(WP_REST_Request $r){return Response::cache(Response::ok(SpeakerService::categoryTerms()),'public, max-age=60, stale-while-revalidate=300');}
    public function adminCreate(WP_REST_Request $r){if(!current_user_can('manage_meydan_speakers'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,0);}
    public function adminUpdate(WP_REST_Request $r){if(!current_user_can('manage_meydan_speakers'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,(int)$r['id']);}
    public function adminDelete(WP_REST_Request $r){if(!current_user_can('manage_meydan_speakers'))return Response::error('forbidden','دسترسی کافی ندارید.',403);$id=(int)$r['id'];$before=Serializer::speaker($id);if(!$before)return Response::error('not_found','سخنران پیدا نشد.',404);wp_trash_post($id);AuditLogger::log('speaker_deleted','speaker',$id,$before,['status'=>'trash']);return Response::ok(['deleted'=>true]);}
    private function query(WP_REST_Request $r){$args=['post_type'=>SpeakerService::POST_TYPE,'post_status'=>'publish','posts_per_page'=>50,'orderby'=>'title','order'=>'ASC'];if($q=trim((string)$r->get_param('q')))$args['s']=$q;
// Topical category is the speaker filter axis.
if($sc=sanitize_key((string)$r->get_param('speaker_category')))$args['tax_query']=[['taxonomy'=>SpeakerService::SPEAKER_CATEGORY_TAXONOMY,'field'=>'slug','terms'=>$sc]];
if($this->bool($r->get_param('verified')))$args['meta_query']=[['key'=>'meydan_verified','value'=>'1']];
$wpq=new WP_Query($args);$data=array_values(array_filter(array_map(fn($p)=>$this->enrich(Serializer::speaker($p),(int)$p->ID),$wpq->posts)));if($city=(int)$r->get_param('city_id'))$data=array_values(array_filter($data,static fn($c)=>in_array($city,$c['cities'],true)));return Response::cache(Response::ok($data),'public, max-age=60, stale-while-revalidate=300');}
    private function save(WP_REST_Request $r,int $id){$p=$this->json($r);$before=$id?Serializer::speaker($id):null;if(array_key_exists('verified',$p))$p['verified']=$this->bool($p['verified']);$saved=SpeakerService::save($p,$id);if(is_wp_error($saved))return $this->error($saved);if(!$id)$id=$saved;AuditLogger::log($before?'speaker_updated':'speaker_created','speaker',$id,$before,Serializer::speaker($id));return Response::ok($this->enrich(Serializer::speaker($id),$id),[],$before?200:201);}
    private function enrich(?array $d,int $id):?array{if(!$d)return null;$p=get_post($id);$d['slug']=$p?$p->post_name:(string)$id;$d['handle']=(string)get_post_meta($id,'meydan_handle',true);$d['expertise']=(string)get_post_meta($id,'meydan_expertise',true);$d['initials']=(string)get_post_meta($id,'meydan_initials',true);return $d;}
}

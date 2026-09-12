<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;
use Meydan\Core\Audit\AuditLogger;use Meydan\Core\Domain\CreatorService;use Meydan\Core\Support\Response;use Meydan\Core\Support\Serializer;use WP_Query;use WP_REST_Request;
/**
 * Content producers (تولیدکنندگان) only.
 *
 * The speaker surface moved to SpeakerController when speakers became their own
 * post type; nothing here knows about speakers.
 */
final class CreatorController extends BaseController
{
    public function list(WP_REST_Request $r){return $this->query($r);}
    public function get(WP_REST_Request $r){$id=(int)$r['id'];$d=$this->enrich(Serializer::creator($id),$id);return $d?Response::cache(Response::ok($d),'public, max-age=60, stale-while-revalidate=300'):Response::error('not_found','تولیدکننده پیدا نشد.',404);}
    public function adminCreate(WP_REST_Request $r){if(!current_user_can('manage_meydan_creators'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,0);}
    public function adminUpdate(WP_REST_Request $r){if(!current_user_can('manage_meydan_creators'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,(int)$r['id']);}
    public function adminDelete(WP_REST_Request $r){if(!current_user_can('manage_meydan_creators'))return Response::error('forbidden','دسترسی کافی ندارید.',403);$id=(int)$r['id'];$before=Serializer::creator($id);if(!$before)return Response::error('not_found','تولیدکننده پیدا نشد.',404);wp_trash_post($id);AuditLogger::log('creator_deleted','creator',$id,$before,['status'=>'trash']);return Response::ok(['deleted'=>true]);}
    private function query(WP_REST_Request $r){$args=['post_type'=>'meydan_creator','post_status'=>'publish','posts_per_page'=>50,'orderby'=>'title','order'=>'ASC'];if($q=trim((string)$r->get_param('q')))$args['s']=$q;$tax=[];if($cat=$r->get_param('category'))$tax[]=['taxonomy'=>'meydan_creator_type','field'=>'slug','terms'=>sanitize_key((string)$cat)];if($tax)$args['tax_query']=array_merge(['relation'=>'AND'],$tax);if($this->bool($r->get_param('verified')))$args['meta_query']=[['key'=>'meydan_verified','value'=>'1']];$wpq=new WP_Query($args);$data=array_values(array_filter(array_map(fn($p)=>$this->enrich(Serializer::creator($p),(int)$p->ID),$wpq->posts)));if($city=(int)$r->get_param('city_id'))$data=array_values(array_filter($data,static fn($c)=>in_array($city,$c['cities'],true)));return Response::cache(Response::ok($data),'public, max-age=60, stale-while-revalidate=300');}
    private function save(WP_REST_Request $r,int $id){$p=$this->json($r);$before=$id?Serializer::creator($id):null;if(array_key_exists('verified',$p))$p['verified']=$this->bool($p['verified']);$saved=CreatorService::save($p,$id);if(is_wp_error($saved))return $this->error($saved);if(!$id)$id=$saved;AuditLogger::log($before?'creator_updated':'creator_created','creator',$id,$before,Serializer::creator($id));return Response::ok($this->enrich(Serializer::creator($id),$id),[],$before?200:201);}
    private function enrich(?array $d,int $id):?array{if(!$d)return null;$p=get_post($id);$d['slug']=$p?$p->post_name:(string)$id;$d['handle']=(string)get_post_meta($id,'meydan_handle',true);$d['expertise']=(string)get_post_meta($id,'meydan_expertise',true);$d['initials']=(string)get_post_meta($id,'meydan_initials',true);return $d;}
}

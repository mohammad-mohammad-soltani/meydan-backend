<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;
use Meydan\Core\Audit\AuditLogger;use Meydan\Core\Domain\MediaOutletService;use Meydan\Core\Support\Response;use Meydan\Core\Support\Serializer;use WP_Query;use WP_REST_Request;
final class MediaOutletController extends BaseController
{
    public function list(WP_REST_Request $r){$args=['post_type'=>'meydan_media_outlet','post_status'=>'publish','posts_per_page'=>100,'orderby'=>'title','order'=>'ASC'];if($q=trim((string)$r->get_param('q')))$args['s']=$q;$wpq=new WP_Query($args);$data=array_values(array_filter(array_map([Serializer::class,'mediaOutlet'],$wpq->posts)));return Response::cache(Response::ok($data),'public, max-age=60, stale-while-revalidate=300');}
    public function get(WP_REST_Request $r){$d=Serializer::mediaOutlet((int)$r['id']);return $d?Response::cache(Response::ok($d),'public, max-age=60, stale-while-revalidate=300'):Response::error('not_found','رسانه پیدا نشد.',404);}
    public function adminCreate(WP_REST_Request $r){if(!current_user_can('manage_meydan_media_reflections'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,0);}
    public function adminUpdate(WP_REST_Request $r){if(!current_user_can('manage_meydan_media_reflections'))return Response::error('forbidden','دسترسی کافی ندارید.',403);return $this->save($r,(int)$r['id']);}
    public function adminDelete(WP_REST_Request $r){if(!current_user_can('manage_meydan_media_reflections'))return Response::error('forbidden','دسترسی کافی ندارید.',403);$id=(int)$r['id'];$before=Serializer::mediaOutlet($id);if(!$before)return Response::error('not_found','رسانه پیدا نشد.',404);wp_trash_post($id);AuditLogger::log('media_outlet_deleted','media_outlet',$id,$before,['status'=>'trash']);return Response::ok(['deleted'=>true]);}
    private function save(WP_REST_Request $r,int $id){$p=$this->json($r);$before=$id?Serializer::mediaOutlet($id):null;$saved=MediaOutletService::save($p,$id);if(is_wp_error($saved))return $this->error($saved);if(!$id)$id=$saved;AuditLogger::log($before?'media_outlet_updated':'media_outlet_created','media_outlet',$id,$before,Serializer::mediaOutlet($id));return Response::ok(Serializer::mediaOutlet($id),[],$before?200:201);}
}

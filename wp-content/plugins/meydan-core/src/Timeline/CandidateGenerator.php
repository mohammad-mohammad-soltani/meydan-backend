<?php

declare(strict_types=1);
namespace Meydan\Core\Timeline;

use Meydan\Core\Support\Viewer;
use WP_Query;

final class CandidateGenerator
{
    public function generate(Viewer $viewer):array
    {
        $cfg=(array)get_option('meydan_timeline',[]);
        if(!$viewer->isAuthenticated()){
            $c=array_merge(
                $this->local($viewer,(int)($cfg['pool_local']??200),'guest_local'),
                $this->trending((int)($cfg['pool_trending']??300),'guest_trending'),
                $this->verifiedSquares(200),
                $this->initiatives(100),
                $this->exploration((int)($cfg['pool_exploration']??100))
            );
            return $this->unique($c);
        }
        return $this->unique(array_merge(
            $this->following($viewer->userId,(int)($cfg['pool_following']??400)),
            $this->interactionGraph($viewer->userId,(int)($cfg['pool_interaction']??250)),
            $this->local($viewer,(int)($cfg['pool_local']??200),'local'),
            $this->trending((int)($cfg['pool_trending']??300),'trending'),
            $this->exploration((int)($cfg['pool_exploration']??100))
        ));
    }
    public function followingChronological(int $userId,int $limit=100):array{return array_slice($this->following($userId,max($limit,400)),0,$limit);}
    private function following(int $uid,int $limit):array{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT object_type,object_id FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND action='follow' ORDER BY created_at DESC LIMIT 1000",$uid),ARRAY_A);$meta=['relation'=>'OR'];foreach($rows?:[] as $r){if(!in_array($r['object_type'],['user','square'],true))continue;$meta[]=['relation'=>'AND',['key'=>'meydan_author_actor_type','value'=>$r['object_type']],['key'=>'meydan_author_actor_id','value'=>(int)$r['object_id'],'type'=>'NUMERIC']];}if(count($meta)===1)return [];$q=new WP_Query(['post_type'=>'meydan_narrative','post_status'=>'publish','posts_per_page'=>$limit,'orderby'=>'date','order'=>'DESC','fields'=>'ids','meta_query'=>$meta,'no_found_rows'=>true]);return $this->tag($q->posts,'following');}
    private function interactionGraph(int $uid,int $limit):array{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT target_actor_type,target_actor_id FROM {$wpdb->prefix}meydan_actor_affinity WHERE viewer_user_id=%d ORDER BY score DESC LIMIT 80",$uid),ARRAY_A);if(!$rows)return [];$pairs=[];$args=[];foreach($rows as $r){$type=sanitize_key((string)($r['target_actor_type']??''));$id=(int)($r['target_actor_id']??0);if(!in_array($type,['user','square'],true)||$id<=0)continue;$pairs[]='(mt.meta_value=%s AND mi.meta_value=%d)';$args[]=$type;$args[]=$id;}if(!$pairs)return [];$sql="SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} mt ON mt.post_id=p.ID AND mt.meta_key='meydan_author_actor_type' INNER JOIN {$wpdb->postmeta} mi ON mi.post_id=p.ID AND mi.meta_key='meydan_author_actor_id' WHERE p.post_type='meydan_narrative' AND p.post_status='publish' AND (".implode(' OR ',$pairs).") ORDER BY p.post_date DESC LIMIT %d";$args[]=$limit;$ids=$wpdb->get_col($wpdb->prepare($sql,...$args));return $this->tag($ids?:[],'interaction');}
    private function local(Viewer $v,int $limit,string $source):array{$meta=['relation'=>'OR'];if($v->cityId)$meta[]=['key'=>'meydan_city_id','value'=>$v->cityId,'type'=>'NUMERIC'];if($v->provinceId)$meta[]=['key'=>'meydan_province_id','value'=>$v->provinceId,'type'=>'NUMERIC'];if(count($meta)===1)return [];$q=new WP_Query(['post_type'=>'meydan_narrative','post_status'=>'publish','posts_per_page'=>$limit,'fields'=>'ids','orderby'=>'date','order'=>'DESC','meta_query'=>$meta,'no_found_rows'=>true]);return $this->tag($q->posts,$source);}
    private function trending(int $limit,string $source):array{global $wpdb;$ids=$wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}meydan_narrative_stats s ON s.narrative_id=p.ID WHERE p.post_type='meydan_narrative' AND p.post_status='publish' AND p.post_date_gmt>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) ORDER BY (COALESCE(s.likes,0)+2*COALESCE(s.reposts,0)+2.5*COALESCE(s.comments,0)+1.5*COALESCE(s.shares,0)) DESC,p.post_date_gmt DESC LIMIT %d",$limit));return $this->tag($ids?:[],$source);}
    private function verifiedSquares(int $limit):array{global $wpdb;$sq=$wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='meydan_verified' AND meta_value='1'");if(!$sq)return [];$q=new WP_Query(['post_type'=>'meydan_narrative','post_status'=>'publish','posts_per_page'=>$limit,'fields'=>'ids','meta_query'=>[['key'=>'meydan_author_actor_type','value'=>'square'],['key'=>'meydan_author_actor_id','value'=>array_map('intval',$sq),'compare'=>'IN','type'=>'NUMERIC']],'orderby'=>'date','order'=>'DESC','no_found_rows'=>true]);return $this->tag($q->posts,'guest_verified');}
    private function initiatives(int $limit):array{$q=new WP_Query(['post_type'=>'meydan_narrative','post_status'=>'publish','posts_per_page'=>$limit,'fields'=>'ids','meta_query'=>[['key'=>'meydan_initiative_id','value'=>0,'compare'=>'>','type'=>'NUMERIC']],'orderby'=>'date','order'=>'DESC','no_found_rows'=>true]);return $this->tag($q->posts,'guest_initiative');}
    private function exploration(int $limit):array{$q=new WP_Query(['post_type'=>'meydan_narrative','post_status'=>'publish','posts_per_page'=>$limit,'fields'=>'ids','orderby'=>'rand','no_found_rows'=>true]);return $this->tag($q->posts,'exploration');}
    private function tag(array $ids,string $source):array{return array_map(static fn($id)=>['id'=>(int)$id,'source'=>$source],$ids);}
    private function unique(array $c):array{$seen=[];$o=[];foreach($c as $x){if(isset($seen[$x['id']]))continue;$seen[$x['id']]=1;$o[]=$x;}return $o;}
}

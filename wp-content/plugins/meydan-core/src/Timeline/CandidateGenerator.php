<?php

declare(strict_types=1);
namespace Meydan\Core\Timeline;

use Meydan\Core\Support\Viewer;
use WP_Query;

final class CandidateGenerator
{
    private const POOL_GROUP = 'meydan_pools';
    private const SHARED_TTL = 90;   // pools every viewer shares (trending, verified, initiatives, exploration, per-city local)
    private const VIEWER_TTL = 30;   // per-viewer pools (following, interaction graph)

    public function generate(Viewer $viewer):array
    {
        $cfg=(array)get_option('meydan_timeline',[]);
        $local=(int)($cfg['pool_local']??200);$trend=(int)($cfg['pool_trending']??300);$expl=(int)($cfg['pool_exploration']??100);
        // Where a viewer's local pool depends only on city/province, so it is shared by everyone in that city.
        $loc=(int)$viewer->cityId.'_'.(int)$viewer->provinceId;
        if(!$viewer->isAuthenticated()){
            $c=array_merge(
                $this->shared('local_'.$loc.'_'.$local.'_g',self::SHARED_TTL,fn()=>$this->local($viewer,$local,'guest_local')),
                $this->shared('trending_'.$trend.'_g',self::SHARED_TTL,fn()=>$this->trending($trend,'guest_trending')),
                $this->shared('verified_200',self::SHARED_TTL,fn()=>$this->verifiedSquares(200)),
                $this->shared('initiatives_100',self::SHARED_TTL,fn()=>$this->initiatives(100)),
                $this->shared('exploration_'.$expl,self::SHARED_TTL,fn()=>$this->exploration($expl))
            );
            return $this->unique($c);
        }
        $uid=(int)$viewer->userId;$tok=$this->viewerToken($uid);
        return $this->unique(array_merge(
            $this->shared('following_'.$uid.'_'.$tok,self::VIEWER_TTL,fn()=>$this->following($viewer->userId,(int)($cfg['pool_following']??400))),
            $this->shared('interaction_'.$uid.'_'.$tok,self::VIEWER_TTL,fn()=>$this->interactionGraph($viewer->userId,(int)($cfg['pool_interaction']??250))),
            $this->shared('local_'.$loc.'_'.$local.'_u',self::SHARED_TTL,fn()=>$this->local($viewer,$local,'local')),
            $this->shared('trending_'.$trend.'_u',self::SHARED_TTL,fn()=>$this->trending($trend,'trending')),
            $this->shared('exploration_'.$expl,self::SHARED_TTL,fn()=>$this->exploration($expl))
        ));
    }

    /** A follow/unfollow or new affinity was written: the next feed build must not reuse this viewer's cached pools. */
    public static function forgetViewer(int $userId):void
    {
        if($userId>0)wp_cache_set('tok_'.$userId,(string)microtime(true),self::POOL_GROUP,DAY_IN_SECONDS);
    }

    private function viewerToken(int $uid):string
    {
        $t=wp_cache_get('tok_'.$uid,self::POOL_GROUP);
        return $t===false?'0':(string)$t;
    }

    /**
     * Candidate lists that many viewers share are built once per TTL instead of once per viewer.
     * Only narrative ids are cached; visibility is checked again when the page is serialized, so a
     * hidden author never shows up even if their id is still in a cached pool.
     * A rebuild takes a short lock so a cold cache costs one query set, not one per concurrent request.
     *
     * @param callable():array<int,array{id:int,source:string}> $build
     * @return array<int,array{id:int,source:string}>
     */
    private function shared(string $key,int $ttl,callable $build):array
    {
        $hit=wp_cache_get($key,self::POOL_GROUP);
        if(is_array($hit))return $hit;
        $lock=$key.'_lock';
        if(!wp_cache_add($lock,1,self::POOL_GROUP,10)){
            for($i=0;$i<10;$i++){
                usleep(50000);
                $hit=wp_cache_get($key,self::POOL_GROUP);
                if(is_array($hit))return $hit;
            }
        }
        $value=$build();
        wp_cache_set($key,$value,self::POOL_GROUP,$ttl);
        wp_cache_delete($lock,self::POOL_GROUP);
        return $value;
    }

    public function followingChronological(int $userId,int $limit=100):array
    {
        return $this->following($userId,$limit);
    }

    private function following(int $uid,int $limit):array
    {
        if($uid<=0||$limit<=0)return [];
        global $wpdb;
        // Match follows in SQL rather than expanding up to 1000 actors into a
        // nested WP_Meta_Query. The latter creates a huge OR query on every visit.
        $sql="SELECT p.ID FROM {$wpdb->posts} p
            WHERE p.post_type='meydan_narrative' AND p.post_status='publish'
              AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} at
                INNER JOIN {$wpdb->postmeta} ai
                    ON ai.post_id=at.post_id AND ai.meta_key='meydan_author_actor_id'
                INNER JOIN {$wpdb->prefix}meydan_interactions f
                    ON f.user_id=%d AND f.action='follow'
                    AND f.object_type=at.meta_value
                    AND ai.meta_value=CAST(f.object_id AS CHAR)
                WHERE at.post_id=p.ID AND at.meta_key='meydan_author_actor_type'
                  AND at.meta_value IN ('".implode("','",\Meydan\Core\Domain\EntityKinds::actorTypes())."')
              )
            ORDER BY p.post_date DESC,p.ID DESC LIMIT %d";
        $ids=$wpdb->get_col($wpdb->prepare($sql,$uid,$limit));
        return $this->tag($ids?:[],'following');
    }
    private function interactionGraph(int $uid,int $limit):array{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT target_actor_type,target_actor_id FROM {$wpdb->prefix}meydan_actor_affinity WHERE viewer_user_id=%d ORDER BY score DESC LIMIT 80",$uid),ARRAY_A);if(!$rows)return [];$pairs=[];$args=[];foreach($rows as $r){$type=sanitize_key((string)($r['target_actor_type']??''));$id=(int)($r['target_actor_id']??0);if(!in_array($type,\Meydan\Core\Domain\EntityKinds::actorTypes(),true)||$id<=0)continue;$pairs[]='(mt.meta_value=%s AND mi.meta_value=%d)';$args[]=$type;$args[]=$id;}if(!$pairs)return [];$sql="SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} mt ON mt.post_id=p.ID AND mt.meta_key='meydan_author_actor_type' INNER JOIN {$wpdb->postmeta} mi ON mi.post_id=p.ID AND mi.meta_key='meydan_author_actor_id' WHERE p.post_type='meydan_narrative' AND p.post_status='publish' AND (".implode(' OR ',$pairs).") ORDER BY p.post_date DESC LIMIT %d";$args[]=$limit;$ids=$wpdb->get_col($wpdb->prepare($sql,...$args));return $this->tag($ids?:[],'interaction');}
    private function local(Viewer $v,int $limit,string $source):array{$meta=['relation'=>'OR'];if($v->cityId)$meta[]=['key'=>'meydan_city_id','value'=>$v->cityId,'type'=>'NUMERIC'];if($v->provinceId)$meta[]=['key'=>'meydan_province_id','value'=>$v->provinceId,'type'=>'NUMERIC'];if(count($meta)===1)return [];$q=new WP_Query(['post_type'=>'meydan_narrative','post_status'=>'publish','posts_per_page'=>$limit,'fields'=>'ids','orderby'=>'date','order'=>'DESC','meta_query'=>$meta,'no_found_rows'=>true]);return $this->tag($q->posts,$source);}
    private function trending(int $limit,string $source):array{global $wpdb;$ids=$wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}meydan_narrative_stats s ON s.narrative_id=p.ID WHERE p.post_type='meydan_narrative' AND p.post_status='publish' AND p.post_date_gmt>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) ORDER BY (COALESCE(s.likes,0)+2*COALESCE(s.reposts,0)+2.5*COALESCE(s.comments,0)+1.5*COALESCE(s.shares,0)) DESC,p.post_date_gmt DESC LIMIT %d",$limit));return $this->tag($ids?:[],$source);}
    private function verifiedSquares(int $limit): array
    {
        if ($limit <= 0) return [];
        global $wpdb;
        // Resolve verification against the square post per narrative. This
        // avoids fetching every verified square and expanding a huge meta IN.
        $sql = "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} at
                ON at.post_id=p.ID AND at.meta_key='meydan_author_actor_type' AND at.meta_value IN ('".implode("','",\Meydan\Core\Domain\EntityKinds::KINDS)."')
            INNER JOIN {$wpdb->postmeta} ai
                ON ai.post_id=p.ID AND ai.meta_key='meydan_author_actor_id'
            INNER JOIN {$wpdb->posts} sq
                ON sq.ID=CAST(ai.meta_value AS UNSIGNED)
                AND sq.post_type IN ('".implode("','",\Meydan\Core\Domain\EntityKinds::postTypes())."') AND sq.post_status='publish'
            WHERE p.post_type='meydan_narrative' AND p.post_status='publish'
                AND EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} v
                    WHERE v.post_id=sq.ID AND v.meta_key='meydan_verified' AND v.meta_value='1'
                )
            ORDER BY p.post_date DESC,p.ID DESC LIMIT %d";
        return $this->tag($wpdb->get_col($wpdb->prepare($sql, $limit)) ?: [], 'guest_verified');
    }
    private function initiatives(int $limit):array{$q=new WP_Query(['post_type'=>'meydan_narrative','post_status'=>'publish','posts_per_page'=>$limit,'fields'=>'ids','meta_query'=>[['key'=>'meydan_initiative_id','value'=>0,'compare'=>'>','type'=>'NUMERIC']],'orderby'=>'date','order'=>'DESC','no_found_rows'=>true]);return $this->tag($q->posts,'guest_initiative');}
    private function exploration(int $limit): array
    {
        if ($limit <= 0) return [];
        global $wpdb;
        // A random starting ID yields a rotating, bounded slice without
        // sorting the whole narratives table with ORDER BY RAND().
        $maxId = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='meydan_narrative' AND post_status='publish'");
        if ($maxId <= 0) return [];
        $pivot = random_int(1, $maxId);
        $sql = "SELECT ID FROM {$wpdb->posts}
            WHERE post_type='meydan_narrative' AND post_status='publish' AND ID >= %d
            ORDER BY ID ASC LIMIT %d";
        $ids = $wpdb->get_col($wpdb->prepare($sql, $pivot, $limit)) ?: [];
        if (count($ids) < $limit) {
            $wrap = "SELECT ID FROM {$wpdb->posts}
                WHERE post_type='meydan_narrative' AND post_status='publish' AND ID < %d
                ORDER BY ID ASC LIMIT %d";
            $ids = array_merge($ids, $wpdb->get_col($wpdb->prepare($wrap, $pivot, $limit - count($ids))) ?: []);
        }
        return $this->tag($ids, 'exploration');
    }
    private function tag(array $ids,string $source):array{return array_map(static fn($id)=>['id'=>(int)$id,'source'=>$source],$ids);}
    private function unique(array $c):array{$seen=[];$o=[];foreach($c as $x){if(isset($seen[$x['id']]))continue;$seen[$x['id']]=1;$o[]=$x;}return $o;}
}

<?php

declare(strict_types=1);
namespace Meydan\Core\Timeline;

final class Ranker
{
    public function rank(array $items): array
    {
        $w=(array)get_option('meydan_ranking',[]);
        foreach($items as &$item){
            $item['score']=
                ((float)($w['affinity']??3.0))*$item['affinity']+
                ((float)($w['recency']??2.1))*$item['recency']+
                ((float)($w['engagement_quality']??1.5))*$item['engagement_quality']+
                ((float)($w['locality']??0.9))*$item['locality']+
                ((float)($w['media_affinity']??0.6))*$item['media_affinity']+
                ((float)($w['media_reflection_boost']??0.5))*$item['media_reflection_boost']+
                ((float)($w['initiative_boost']??0.6))*$item['initiative_boost']+
                ((float)($w['exploration_boost']??0.4))*$item['exploration_boost']-
                ((float)($w['recently_served_penalty']??2.0))*$item['recently_served'];
        }
        unset($item);
        usort($items,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);
        return $items;
    }
}

<?php

declare(strict_types=1);
namespace Meydan\Core\Timeline;

final class Mixer
{
    public function mixAndDiversify(array $items,int $limit=100):array
    {
        $cfg=(array)get_option('meydan_timeline',[]);
        $max=(int)($cfg['max_per_actor']??3);
        $isGuest=(bool)array_filter($items,static fn($x)=>str_starts_with((string)$x['source'],'guest_'));
        if($isGuest){
            $mix=['guest_local'=>.35,'guest_trending'=>.25,'guest_verified'=>.20,'guest_initiative'=>.10,'exploration'=>.10];
            $groups=array_fill_keys(array_keys($mix),[]);
            foreach($items as $item){$key=array_key_exists($item['source'],$groups)?$item['source']:'exploration';$groups[$key][]=$item;}
        }else{
            $mix=['following'=>(float)($cfg['mix_following']??.50),'interaction'=>(float)($cfg['mix_interaction']??.25),'local_trending'=>(float)($cfg['mix_local_trending']??.15),'exploration'=>(float)($cfg['mix_exploration']??.10)];
            $groups=array_fill_keys(array_keys($mix),[]);
            foreach($items as $item){$g=match($item['source']){'following'=>'following','interaction'=>'interaction','exploration'=>'exploration',default=>'local_trending'};$groups[$g][]=$item;}
        }
        $selected=[];$used=[];$actors=[];$last=null;
        $tryAdd=function(array $item)use(&$selected,&$used,&$actors,&$last,$max):bool{
            if(isset($used[$item['id']]))return false;
            $actor=$item['actor_type'].':'.$item['actor_id'];
            if(($actors[$actor]??0)>=$max)return false;
            if($last===$actor && count($selected)>0)return false;
            $used[$item['id']]=true;$actors[$actor]=($actors[$actor]??0)+1;$last=$actor;$selected[]=$item;return true;
        };
        foreach($mix as $g=>$ratio){$target=(int)round($limit*$ratio);foreach($groups[$g]??[] as $item){if(count($selected)>=$limit||$target<=0)break;if($tryAdd($item))$target--;}}
        foreach($items as $item){if(count($selected)>=$limit)break;$tryAdd($item);}
        return array_slice($selected,0,$limit);
    }
}

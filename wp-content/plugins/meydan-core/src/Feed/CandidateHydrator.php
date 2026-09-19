<?php

declare(strict_types=1);
namespace Meydan\Core\Feed;

final class CandidateHydrator
{
    /** @param array<int,Candidate> $candidates @return list<array<string,mixed>> */
    public function hydrate(array $candidates, FeedContext $context): array
    {
        if (!$candidates) return [];
        global $wpdb; $ids=array_keys($candidates); $marks=implode(',', array_fill(0,count($ids),'%d'));
        $posts=$wpdb->get_results($wpdb->prepare("SELECT ID,post_date_gmt FROM {$wpdb->posts} WHERE ID IN ($marks) AND post_type='meydan_narrative' AND post_status='publish'", ...$ids), ARRAY_A) ?: [];
        $meta=$wpdb->get_results($wpdb->prepare("SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($marks) AND meta_key IN ('meydan_author_actor_type','meydan_author_actor_id','meydan_city_id','meydan_province_id','meydan_editorial','meydan_initiative_id')", ...$ids), ARRAY_A) ?: [];
        $stats=$wpdb->get_results($wpdb->prepare("SELECT narrative_id,views,likes,comments,reposts,shares FROM {$wpdb->prefix}meydan_narrative_stats WHERE narrative_id IN ($marks)", ...$ids), ARRAY_A) ?: [];
        $byMeta=[]; foreach($meta as $row) $byMeta[(int)$row['post_id']][$row['meta_key']]=$row['meta_value']; $byStats=[]; foreach($stats as $row)$byStats[(int)$row['narrative_id']]=$row;
        $userIds=[];$squareIds=[];foreach($posts as $post){$m=$byMeta[(int)$post['ID']]??[];if(($m['meydan_author_actor_type']??'user')==='user')$userIds[]=(int)($m['meydan_author_actor_id']??0);else $squareIds[]=(int)($m['meydan_author_actor_id']??0);} $owners=[];
        if($squareIds){$sqMarks=implode(',',array_fill(0,count($squareIds),'%d'));$ownerRows=$wpdb->get_results($wpdb->prepare("SELECT post_id,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($sqMarks) AND meta_key='meydan_owner_user_id'",...$squareIds),ARRAY_A)?:[];foreach($ownerRows as $row){$owners[(int)$row['post_id']]=(int)$row['meta_value'];$userIds[]=(int)$row['meta_value'];}}
        $roles=[];
        if($userIds){foreach(get_users(['include'=>array_values(array_filter(array_unique($userIds))),'fields'=>['ID','roles']]) as $user)$roles[(int)$user->ID]=(array)$user->roles;}
        $out=[]; foreach($posts as $post){$id=(int)$post['ID'];$m=$byMeta[$id]??[];$actorId=(int)($m['meydan_author_actor_id']??0);$actorType=(string)($m['meydan_author_actor_type']??'user');$roleUser=$actorType==='square'?($owners[$actorId]??0):$actorId;$out[]=['narrative_id'=>$id,'actor_type'=>$actorType,'actor_id'=>$actorId,'actor_roles'=>$roles[$roleUser]??[],'stats'=>$byStats[$id]??[],'age_hours'=>max(0,(time()-strtotime($post['post_date_gmt'].' UTC'))/3600),'viewer_city_id'=>$context->viewer->cityId,'viewer_province_id'=>$context->viewer->provinceId,'post_city_id'=>(int)($m['meydan_city_id']??0),'post_province_id'=>(int)($m['meydan_province_id']??0),'editorial'=>(bool)($m['meydan_editorial']??false),'good_deed'=>(int)($m['meydan_initiative_id']??0)>0,'following'=>$context->follows($actorType,$actorId),'source_names'=>$candidates[$id]->sourceNames()];}
        return $out;
    }
}

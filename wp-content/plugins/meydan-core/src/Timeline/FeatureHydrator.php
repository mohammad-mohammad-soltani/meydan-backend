<?php

declare(strict_types=1);

namespace Meydan\Core\Timeline;

use Meydan\Core\Support\Affinity;
use Meydan\Core\Support\Stats;
use Meydan\Core\Support\Viewer;

final class FeatureHydrator
{
    /** @param array<int,array{id:int,source:string}> $candidates */
    public function hydrate(Viewer $viewer, array $candidates): array
    {
        global $wpdb;
        $recent = [];
        if ($candidates) {
            $viewerId = $viewer->id;
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT narrative_id FROM {$wpdb->prefix}meydan_served_history WHERE viewer_type=%s AND viewer_id=%s AND served_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)",
                $viewer->type, $viewerId
            ));
            $recent = array_fill_keys(array_map('intval',$rows ?: []), true);
        }
        $out=[];
        foreach ($candidates as $candidate) {
            $post=get_post($candidate['id']);
            if (!$post || $post->post_status !== 'publish') continue;
            $actorType=(string)get_post_meta($post->ID,'meydan_author_actor_type',true);
            $actorId=(int)get_post_meta($post->ID,'meydan_author_actor_id',true);
            $stats=Stats::narrative((int)$post->ID);
            $age=max(0.0,(time()-strtotime($post->post_date_gmt.' UTC'))/3600);
            $eng=$stats['likes']+2*$stats['reposts']+2.5*$stats['comments']+1.5*$stats['shares'];
            $exposure=max(25.0,(float)$stats['views']);
            $city=(int)get_post_meta($post->ID,'meydan_city_id',true);
            $province=(int)get_post_meta($post->ID,'meydan_province_id',true);
            $locality=($viewer->cityId && $city===$viewer->cityId)?1.0:(($viewer->provinceId && $province===$viewer->provinceId)?0.5:0.0);
            $attachments=(array)get_post_meta($post->ID,'meydan_attachments',true);
            $hasMedia=$attachments!==[];
            $out[]=[
                'id'=>(int)$post->ID,'source'=>$candidate['source'],'actor_type'=>$actorType,'actor_id'=>$actorId,
                'affinity'=>$viewer->isAuthenticated()?Affinity::normalized($viewer->userId,$actorType,$actorId):0.0,
                'recency'=>exp(-$age/18.0),
                'engagement_quality'=>min(1.0,$eng/$exposure),
                'locality'=>$locality,
                'media_affinity'=>$hasMedia?0.5:0.0,
                'media_reflection_boost'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}meydan_media_reflections WHERE narrative_id=%d AND status='published'",$post->ID))>0?1.0:0.0,
                'initiative_boost'=>(int)get_post_meta($post->ID,'meydan_initiative_id',true)>0?1.0:0.0,
                'exploration_boost'=>$candidate['source']==='exploration'?1.0:0.0,
                'recently_served'=>isset($recent[(int)$post->ID])?1.0:0.0,
            ];
        }
        return $out;
    }
}

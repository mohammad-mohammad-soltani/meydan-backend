<?php
require __DIR__ . '/../src/Feed/FeedDiversity.php';
require __DIR__ . '/../src/Feed/FeedRanker.php';
require __DIR__ . '/../src/Feed/FeedScorer.php';
require __DIR__ . '/../src/Feed/FeedSettings.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$items=[];
for($i=0;$i<90;$i++) $items[]=['narrative_id'=>$i+1,'actor_type'=>$i<30?'user':'square','actor_id'=>$i<30?$i:($i%15),'actor_roles'=>$i<30?['meydan_speaker']:[],'media_type'=>['text','image','video'][intdiv($i,10)%3],'score'=>100-$i];
$d=new Meydan\Core\Feed\FeedDiversity();
$out=$d->rerank($items, Meydan\Core\Feed\FeedSettings::defaults());
$last=[];$previous=false;
foreach($out as $pos=>$item){$speaker=in_array('meydan_speaker',$item['actor_roles']);check(!($previous&&$speaker),'Adjacent speakers');$previous=$speaker;$key=$item['actor_type'].':'.$item['actor_id'];if(isset($last[$key]))check($pos-$last[$key]>=5,'Actor repeated too soon');$last[$key]=$pos;}
$first=array_slice($out,0,15);check(count(array_unique(array_column($first,'media_type')))===3,'All media formats should surface');
$squares=array_filter(array_slice($out,0,20),fn($i)=>$i['actor_type']==='square');check(count($squares)===count(array_unique(array_column($squares,'actor_id'))),'Repeat squares before unique supply exhausted');
check(count($out)===count(array_unique(array_column($out,'narrative_id'))),'Duplicate narratives');
check(count($d->rerank(array_slice($items,0,30),[]))===1,'Speaker-only supply must not create adjacent speakers');
$r=new Meydan\Core\Feed\FeedRanker();$orders=[];
for($s=0;$s<10;$s++)$orders[]=implode(',',array_column(array_slice($r->rank($items,'seed'.$s),0,10),'narrative_id'));
check(count(array_unique($orders))>=8,'Refresh must vary unequal-score candidates');
$score=(new Meydan\Core\Feed\FeedScorer())->score(['stats'=>[],'age_hours'=>0],Meydan\Core\Feed\FeedSettings::defaults());check($score['score']>0,'New posts need a cold-start score');
$firstId=$out[0]['narrative_id'];
$refreshed=$d->rerank($items,[], $firstId);
check($refreshed[0]['narrative_id']!==$firstId,'Refresh must not repeat the previous first post when alternatives exist');

echo "Feed V2 diversity regression checks passed.\n";

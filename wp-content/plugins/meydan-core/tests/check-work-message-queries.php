<?php
/** Run with wp eval-file; fixtures are rolled back. Compares cold message pages: the query count must stay flat as messages grow
 *  (a few extra queries are tolerated for legacy users whose account meta is self-healed on first read). */
use Meydan\Core\Domain\WorkMessages;
use Meydan\Core\Domain\WorkGroups;
global $wpdb;
$wpdb->query('START TRANSACTION');
try {
    $users = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT 100"));
    if (count($users) < 2) throw new RuntimeException('Need at least two users');
    $wpdb->insert(WorkGroups::table('conversations'), ['type'=>'work','title'=>'Query test','created_by'=>$users[0],'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]);
    $conversation=(int)$wpdb->insert_id;
    for($i=0;$i<100;$i++) {
        $wpdb->insert(WorkGroups::table('messages'), ['conversation_id'=>$conversation,'sender_user_id'=>$users[$i%count($users)],'client_id'=>'query-test-'.wp_generate_uuid4(),'body'=>'fixture','kind'=>'task','task_status'=>'todo','payload_json'=>'{"title":"fixture"}','created_at'=>gmdate('Y-m-d H:i:s')]);
    }
    $counts=[];
    foreach([20,100] as $limit) {
        wp_cache_flush();
        $start=$wpdb->num_queries;
        $page=WorkMessages::page($conversation,$users[0],true,['limit'=>$limit]);
        $counts[$limit]=$wpdb->num_queries-$start;
        if(count($page['items'])!==$limit) throw new RuntimeException('Page size mismatch');
        if($wpdb->last_error) throw new RuntimeException($wpdb->last_error);
    }
    if($counts[100] > $counts[20]+10) throw new RuntimeException('Queries grow with messages: '.json_encode($counts));
    echo 'Cold message page query counts: '.json_encode($counts).PHP_EOL;
} finally {
    $wpdb->query('ROLLBACK');
}

<?php
// Targeted reproduction of the new token incident; synthetic document only.
require dirname(__DIR__).'/mon.php';
$source=(object)['simulation'=>'not_market_data'];
$a=(object)['source_blob_sha'=>str_repeat('a',40),'rendered_at'=>'2026-10-11T02:00:00Z','countries'=>['KR','US','JP'],'important_ids'=>['KR|KOSPI|990001']];
$b=(object)['source_blob_sha'=>str_repeat('a',40),'rendered_at'=>'2026-10-11T11:00:00+09:00','countries'=>['JP','KR','US'],'important_ids'=>['KR|KOSPI|990001','KR|KOSPI|990001']];
$rawSame=MonRun::token($source,$a)===MonRun::token($source,$b);
$normalizedSame=MonRun::token($source,MonRun::context($a))===MonRun::token($source,MonRun::context($b));
$changed=(object)['simulation'=>'changed_source'];
$changedRejected=MonRun::token($changed,MonRun::context($a))!==MonRun::token($source,MonRun::context($a));
echo mon_json(['processor_version'=>MON_VERSION,'synthetic_only'=>true,'raw_tokens_equal'=>$rawSame,'normalized_tokens_equal'=>$normalizedSame,'actual_source_change_invalidates_token'=>$changedRejected],false)."\n";
exit(!$rawSame&&$normalizedSame&&$changedRejected?0:1);

<?php
declare(strict_types=1);
define('MON_GATE_LIBRARY_ONLY',true);require __DIR__.'/mon_completion_gate_test.php';
$source=(string)file_get_contents($argv[1]);$d=mon_decode($source);$c=(object)['source_blob_sha'=>MonRun::gitSha($source),
    'rendered_at'=>MonBudget::iso(microtime(true)),'countries'=>['KR'],'important_ids'=>[],'run_context_id'=>$argv[2]];
$decision=reinforcement_decision($d,$c);$probe=MonDetails::finalize($d,$decision);
echo mon_json(['native_status'=>$probe['status'],'request'=>(object)['source_json'=>$source,'decision'=>$decision,'response_mode'=>$argv[3]??'manifest','publish_mode'=>'manual']],false);

<?php
declare(strict_types=1);
/** Read-only size/staging diagnostic. No NAS, GitHub mutation or orders. */
require dirname(__DIR__).'/mon.php';
$root=dirname(__DIR__);
try {
    $source=file_get_contents($root.'/mon_data.json');
    $mail=file_get_contents($root.'/mon_chatgpt_handoff.json');
    if(!is_string($source)||!is_string($mail)) throw new RuntimeException('source_missing');
    $d=mon_decode($source);
    $q=mon_decode($mail);
    MonRun::validate($d);
    MonChatGPTBridge::validate($q);
    if($q->stage!=='candidates') throw new RuntimeException('expected_candidates');
    $symbols=MonRun::rows($d->watchlist->symbols);
    $pool=MonDiscovery::pool($d);
    $items=[];$unknown=[];
    foreach($q->candidate_ids as $sid) {
        $s=$symbols[$sid]??mon_get($pool,$sid);
        if(!($s instanceof stdClass)) {$unknown[]=$sid;continue;}
        $id='cb.'.substr(hash('sha256',$q->request_id.'|'.$sid),0,48);
        $items[]=(object)['symbol'=>$s,'request_id'=>$id,'wait_seconds'=>120,'force_refresh'=>true];
    }
    $context=(object)['source_blob_sha'=>MonRun::gitSha($source),'rendered_at'=>mon_iso(),'countries'=>['KR','US','JP'],'important_ids'=>$q->candidate_ids];
    $begin=microtime(true);
    $r=MonDetails::registerMany($d,$context,$items);
    $fail=array_values(array_filter($r['requests'],static function($x){return $x['error']!==null;}));
    $stat=[];
    foreach($r['requests'] as $row){$key=$row['status'];$stat[$key]=($stat[$key]??0)+1;}
    $out=['status'=>count($fail)===0?'pass':'partial','contract'=>'MON-CANDIDATE-REGISTER-REAL-SIZE','mon_version'=>MON_VERSION,
        'original_data_bytes'=>strlen($source),'existing_request_count'=>count(get_object_vars(MonDetails::requests($d))),
        'incoming_candidate_count'=>count($q->candidate_ids),'accepted_items'=>count($items),
        'unknown_ids'=>$unknown,'outcome_counts'=>$stat,
        'errors'=>array_map(static function($x){return ['symbol_id'=>$x['symbol_id'],'error'=>$x['error']];},$fail),
        'files'=>array_map(static function($f){return ['path'=>$f['path'],'bytes'=>strlen($f['content'])];},$r['files']),
        'write_required'=>$r['write_required'],'elapsed_seconds'=>round(microtime(true)-$begin,3),
        'peak_memory_bytes'=>memory_get_peak_usage(true)];
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($fail?2:0);
} catch(Throwable $e){
    echo json_encode(['status'=>'error','error'=>get_class($e),'message'=>$e->getMessage(),'peak_memory_bytes'=>memory_get_peak_usage(true)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    exit(1);
}

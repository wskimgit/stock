<?php
declare(strict_types=1);
require dirname(__DIR__).'/mon.php';
try {
  $raw=file_get_contents($argv[1]);
  if(!is_string($raw))throw new RuntimeException('SOURCE_MISSING');
  $data=mon_decode($raw);MonRun::validate($data);
  $q=mon_decode(file_get_contents(dirname(__DIR__).'/mon_chatgpt_handoff.json'));
  $ctx=(object)['source_blob_sha'=>MonRun::gitSha($raw),'rendered_at'=>mon_iso(),
    'countries'=>['KR','US','JP'],'important_ids'=>$q->candidate_ids];
  $input=MonDetails::inputs($data,$ctx);
  $prepared=MonSwing::prepare($input,$ctx);
  $full=$prepared['full_signals'];
  $rows=[]; $counts=['eligible'=>0,'reference_eligible'=>0,'other'=>0];
  foreach($q->candidate_ids as $sid) {
    $s=mon_get($full,$sid);
    if(!($s instanceof stdClass)){
      $rows[]=['id'=>$sid,'state'=>'absent_from_prepared'];$counts['other']++;continue;
    }
    $rc=mon_get($s,'reason_codes',[]);
    $reference=$s->market_strong&&$s->technical_qualified===true&&!$s->eligible&&in_array('latest_comparison_pending',$rc,true)&&$s->eligibility!=='fail';
    if($s->eligible)$counts['eligible']++;
    elseif($reference)$counts['reference_eligible']++;
    else $counts['other']++;
    $rows[]=['id'=>$sid,'name'=>$s->name,'eligibility'=>$s->eligibility,
       'eligible'=>$s->eligible,'reference_eligible'=>$reference,'market_strong'=>$s->market_strong,
       'technical_qualified'=>$s->technical_qualified,
       'rank_hint'=>$s->rank_hint,'reason_codes'=>$rc,
       'bars_as_of'=>$s->bars_as_of,'rs20_pp'=>$s->rs20_pp,'last_close'=>$s->last_close,
       'macd_kind'=>$s->macd_kind,'qualifying_signals'=>$s->qualifying_signals,
       'entry_plans'=>mon_get($s,'entry_plans',[])];
  }
  $out=['status'=>'pass','source_blob_sha'=>$ctx->source_blob_sha,'prepared_fingerprint'=>$prepared['prepared_fingerprint'],
     'state_token'=>$prepared['state_token'],'rendered_at'=>$ctx->rendered_at,
     'counts'=>$counts,'preparation'=>$prepared['preparation'],
     'requested'=>count($q->candidate_ids),'rows'=>$rows,
     'peak_memory_bytes'=>memory_get_peak_usage(true)];
  echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e) {
 echo json_encode(['status'=>'error','class'=>get_class($e),'error'=>$e->getMessage(),
      'peak_memory_bytes'=>memory_get_peak_usage(true)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
 exit(1);
}

<?php
declare(strict_types=1);
/** MON original PHP finalize+publish. Triggered once by an explicit user request.
 * No NAS daemon start and no stock orders. Only verified or marked reference selections.
 */
$root=(string)getenv('MON_SOURCE_ROOT');
if($root===''||!is_file($root.'/mon.php'))throw new RuntimeException('MON_SOURCE_ROOT_INVALID');
require $root.'/mon.php';
$token=(string)getenv('GITHUB_TOKEN');
if(strlen($token)<15)throw new RuntimeException('GITHUB_TOKEN_UNAVAILABLE');
$dir=sys_get_temp_dir().'/mon_finalize_'.bin2hex(random_bytes(5));
if(!mkdir($dir,0700,true))throw new RuntimeException('STATE_DIR_INVALID');
$envFile=$dir.'/config.env';
file_put_contents($envFile,'GITHUB_TOKEN='.$token."\nMON_REPOSITORY=wskimgit/stock\nMON_BRANCH=main\n",LOCK_EX);
chmod($envFile,0600);unset($token);
$store=new MonStore($dir);$cfg=new MonConfig($envFile,$dir);$http=new MonHttp();
$begin=microtime(true);
function finRaw(MonHttp $http,MonConfig $cfg,string $path): string {
  $r=$http->request('GET','https://api.github.com/repos/wskimgit/stock/contents/'.$path.'?ref=main',
      ['Accept: application/vnd.github.raw+json','Authorization: Bearer '.$cfg->get('GITHUB_TOKEN'),
       'X-GitHub-Api-Version: 2022-11-28'],null,microtime(true)+20,6);
  if($r['status']<200||$r['status']>=300)MonHttp::decode($r);
  if(!is_string($r['body'])||strlen($r['body'])>MonRun::MAX_SOURCE)throw new MonFault('MON_FINAL_SOURCE_INVALID');
  return $r['body'];
}
function finHead(MonHttp $http,MonConfig $cfg): string {
  $x=MonHttp::decode($http->request('GET','https://api.github.com/repos/wskimgit/stock/git/ref/heads/main',
     ['Accept: application/vnd.github+json','Authorization: Bearer '.$cfg->get('GITHUB_TOKEN'),
      'X-GitHub-Api-Version: 2022-11-28'],null,microtime(true)+20,6));
  $s=mon_get(mon_get($x,'object'),'sha');
  if(!preg_match('/^[a-f0-9]{40}$/',(string)$s))throw new MonFault('MON_FINAL_HEAD_INVALID');
  return $s;
}
$report=['contract'=>'MON-USER-FINAL-20261014'];
try{
  $raw=finRaw($http,$cfg,'mon_data.json');
  $inbox=mon_decode(finRaw($http,$cfg,'mon_chatgpt_handoff.json'));
  MonChatGPTBridge::validate($inbox);
  if($inbox->stage!=='candidates'||$inbox->request_id!=='mon-20261014-0816-kst-001')throw new MonFault('MON_FINAL_REQUEST_INVALID');
  $d=mon_decode($raw);MonRun::validate($d);
  $c=(object)['source_blob_sha'=>MonRun::gitSha($raw),'rendered_at'=>mon_iso(),
       'countries'=>['KR','US','JP'],'important_ids'=>$inbox->candidate_ids];
  $review=MonDetails::prepare($d,$c);
  $chosen=[
    ['KR|KOSPI|032640','Strong RS20 +13.54pp, completed 2026-10-13 MACD GC, liquid defensive telecom; avoid market-selloff chasing'],
    ['KR|KOSPI|004370','RS20 +11.39pp, completed MACD GC, food-sector diversification; interim corporate filing review remains pending'],
    ['KR|KOSPI|323410','RS20 +7.70pp and completed MACD GC, best KR third candidate under risk-balanced equal ranking'],
    ['JP|TSE|6098','RS20 +16.16pp, confirmed 2026-10-13 MA5 and recent MACD post-GC, liquidity; monitor employment cycle'],
    ['JP|TSE|4502','Confirmed 2026-10-13 MACD GC and lower pullback planned risk than NRI; diversify healthcare'],
    ['JP|TSE|6758','Confirmed MACD post-GC with lower pullback risk than NRI and diversified Sony business risk']
  ];
  $ref=[
    ['US|NYSE|SNOW','Previous verified 2026-10-12 relative strength and MACD-after; Oct13 market comparison missing, reference only'],
    ['US|NYSE|LLY','Previous verified 2026-10-12 MACD-after and diversification; Oct13 target bar missing, reference only'],
    ['US|NASDAQ|WMT','Previous verified 2026-10-12 defensive signal; current detailed history blocked, reference only']
  ];
  $input=MonDetails::inputs($d,$c);$numerical=MonSwing::prepare($input,$c);
  $selected=[];$references=[];$fail=[];
  foreach($chosen as $candidate){
    $sid=$candidate[0];$s=mon_get($numerical['full_signals'],$sid);
    if(!($s instanceof stdClass)||$s->eligible!==true){
      $fail[]=['symbol_id'=>$sid,'eligibility'=>mon_get($s,'eligibility'),'reasons'=>mon_get($s,'reason_codes',[])];continue;
    }
    $selected[]=(object)['symbol_id'=>$sid,'reason'=>$candidate[1]];
  }
  foreach($ref as $candidate) {
    $sid=$candidate[0];$s=mon_get($numerical['full_signals'],$sid);
    $codes=mon_get($s,'reason_codes',[]);
    $reference=$s instanceof stdClass&&$s->eligible!==true&&$s->market_strong===true&&$s->technical_qualified===true
        &&in_array('latest_comparison_pending',$codes,true)&&$s->eligibility!=='fail';
    if(!$reference){$fail[]=['symbol_id'=>$sid,'reference_eligible'=>false,'reasons'=>$codes];continue;}
    $references[]=(object)['symbol_id'=>$sid,'reason'=>$candidate[1]];
  }
  if($fail||count($selected)!==6||count($references)!==3)throw new MonFault('MON_FINAL_SELECTION_REPREPARE_REQUIRED');
  $decision=(object)['view_context'=>$c,'fixed_as_of'=>$c->rendered_at,
      'state_token'=>$review['state_token'],'prepared_fingerprint'=>$review['prepared_fingerprint'],
      'selected'=>$selected,'references'=>$references,
      'live_observations'=>[],'confirmations'=>(object)['new_evidence'=>[],'changes'=>[]]];
  $result=MonDetails::finalize($d,$decision);
  $files=$result['files'];
  if(!$result['write_required'])$files=[
    ['path'=>'mon_data.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)],
    ['path'=>'mon_result.md','content'=>$result['summary'],'blob_sha'=>MonRun::gitSha($result['summary'])]];
  if(count($files)<2)throw new MonFault('MON_FINAL_FILES_MISSING');
  $sourceId=$c->source_blob_sha;$selectionAsOf=$c->rendered_at;
  unset($input,$numerical,$review,$d);
  MonRun::releasePayloadCache();
  if(function_exists('gc_collect_cycles'))gc_collect_cycles();
  $budget=MonExecution::start($store,(object)[
    'run_id'=>'mon-final-20261014-0816-kst-001','mode'=>'auto','workload'=>'mon']);
  $metadata=[
    'run_context_id'=>$budget->run->run_context_id,
    'received_at'=>$budget->run->received_at,
    'countries'=>['KR','US','JP'],
    'decision_sha256'=>MonRun::digest($decision),
    'prepared_fingerprint'=>$decision->prepared_fingerprint
  ];
  $artifact=MonArtifact::create($store,$raw,$files,$metadata);
  $head=finHead($http,$cfg);
  $pub=new MonPublication($store,$http,$cfg);
  MonExecution::event('final_prepared',['final_available'=>true,'new_analysis_complete'=>true,
    'analysis_origin'=>'secured_data_decision','save_status'=>'not_requested','artifact_id'=>$artifact['artifact_id']]);
  $receipt=$pub->enqueue($artifact['artifact_id'],$head,$budget,'chatgpt_user_mon_final');
  MonExecution::event('publication_queued',['save_status'=>$receipt['save_status'],'job_id'=>$receipt['job_id']]);
  $attempt=0;
  do {
    $attempt++;
    $receipt=$pub->process($receipt['job_id'],microtime(true)+25);
    if($receipt['save_status']==='saved')break;
    if(!in_array($receipt['save_status'],['queued','publishing','unknown'],true))break;
    if($attempt<12)usleep(500000);
  }while($attempt<12);
  $current=mon_decode(finRaw($http,$cfg,'mon_data.json'));
  $currentIds=array_map(static function($r){return $r->symbol_id;},$current->analysis->results);
  $verifiedResult=$receipt['save_status']==='saved'&&
      count(array_intersect(array_merge(array_column($chosen,0),array_column($ref,0)),$currentIds))===9;
  $report=array_merge($report,[
     'status'=>$verifiedResult?'remote_saved_verified':'pending_or_conflict',
     'source_blob_sha'=>$sourceId,'decision_time'=>$selectionAsOf,
     'selected'=>array_column($chosen,0),'references'=>array_column($ref,0),
     'save_status'=>$receipt['save_status'],
     'published_commit_sha'=>$receipt['published_commit_sha']??null,
     'last_error'=>$receipt['last_error']??null,
     'analysis_as_of'=>$current->analysis->as_of,'confirmed_results'=>$currentIds,
     'attempts'=>$attempt,'elapsed_seconds'=>round(microtime(true)-$begin,3),
     'peak_memory_bytes'=>memory_get_peak_usage(true)]);
  echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
  exit($verifiedResult?0:2);
}catch(Throwable $e){
  echo json_encode(array_merge($report,[
    'status'=>'failed','error_code'=>$e instanceof MonFault?$e->faultCode:get_class($e),
    'error_detail'=>$e instanceof MonFault?null:$e->getMessage(),
    'elapsed_seconds'=>round(microtime(true)-$begin,3),
    'memory_peak_bytes'=>memory_get_peak_usage(true)]),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
  exit(1);
} finally { @unlink($envFile); }

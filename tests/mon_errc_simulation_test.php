<?php
declare(strict_types=1);
define('MON_GATE_LIBRARY_ONLY',true);require __DIR__.'/mon_completion_gate_test.php';
define('MON_ERRC_LIBRARY_ONLY',true);require __DIR__.'/mon_errc_upgrade_test.php';
$cases=[];$dirs=[];$started=mon_monotonic();$observed=[];$simulation=[];
function es_policy(string $scope='analysis',bool $statistics=false):stdClass{return (object)['scope'=>$scope,'finish_owner'=>'execution','require_statistics_publication'=>$statistics];}
function es_fixture(string $scope='analysis',bool $statistics=false):array{return cg_publication(true,null,false,20,false,es_policy($scope,$statistics));}
function es_health(float $now,array $overrides=[]):stdClass{
 $base=['version'=>MON_VERSION,'running'=>true,'heartbeat_fresh'=>true,'boot_verified'=>true,'restart_required'=>false,'watchlist_loaded'=>true,'watched'=>1,
 'readiness'=>'off_session','monitoring_active'=>false,'heartbeat_at'=>MonBudget::iso($now),
 'detail_progress'=>(object)['as_of'=>MonBudget::iso($now),'publication'=>'stored','source_blob_sha'=>str_repeat('a',40),
 'counts'=>(object)['requests'=>1,'done'=>1,'pending'=>0,'retry_wait'=>0,'invalid'=>0,'serialization_pending'=>0,'blocked'=>0,'conflict'=>0,'current_missing'=>0]]];
 return (object)array_merge($base,$overrides);
}
function es_nas(MonStore $s,object $clock,stdClass $health):MonNasConnection{
 $r=['status'=>200,'headers'=>[],'body'=>mon_json((object)['ok'=>true,'processor_version'=>MON_VERSION,
 'result'=>(object)['contract'=>MonBootstrap::CONTRACT,'processor_version'=>MON_VERSION,'state'=>'needs_data','cards'=>[]],'nas_state'=>$health],false)];
 return new MonNasConnection($s,MonExecution::$active->run->run_context_id,static function()use($r){return $r;},static function()use($clock){return $clock->wall;},static function()use($clock){return $clock->mono;});
}
function es_saveFiles(ReinforcementGit $git,array $files):void{
 $tree=$git->trees[$git->commits[$git->head]['tree']];foreach($files as $f){$git->blobs[$f['blob_sha']]=$f['content'];$tree[$f['path']]=$f['blob_sha'];}
 $ts=sha1(MonRun::json((object)$tree));$git->trees[$ts]=$tree;$next=sha1('simulated-atomic-ref-'.$git->head.$ts);$git->commits[$next]=['tree'=>$ts,'parents'=>[$git->head]];$git->head=$next;
}
function es_pub(MonStore $s,ReinforcementGit $git,object $clock,bool $badStats=false):MonPublication{
 $transport=static function($method,$url,$headers,$body,$timeout)use($git,$badStats){
  if($method==='GET'&&strpos($url,'/contents/mon_execution_stats.json')!==false){parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);$head=$query['ref']??$git->head;
   $tree=$git->trees[$git->commits[$head]['tree']];$sha=$tree['mon_execution_stats.json']??null;
   if(!$sha)return ['status'=>404,'headers'=>[],'body'=>'{}'];$raw=$git->blobs[$sha];if($badStats){$data=mon_decode($raw);$data->samples[0]->elapsed_seconds+=1;$raw=MonRun::json(MonRunStatistics::seal($data));}
   return ['status'=>200,'headers'=>[],'body'=>$raw];}
  return $git->transport($method,$url,$headers,$body,$timeout);
 };
 return new MonPublication($s,new MonHttp(null,$transport),progress_test_config($s->dir),static function()use($clock){return $clock->wall;},static function()use($clock){return $clock->mono;});
}
eu_case('A08 repeated archive compaction preserves older records and moves only new unique history',function(){
 $s=eu_store();MonExecution::$store=$s;$d=reinforcement_fixture(1);$p=MonRun::payload($d);$p->strategy_archive=(object)['history-1'=>(object)['text'=>str_repeat('first',4000),'original_at'=>'2026-10-09T01:00:00Z']];
 $d->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');$a=MonGaps::compact($d,mon_iso());eu_ok($a['archive_entries_moved']===1);
 $p=MonRun::payload($a['data']);$p->strategy_archive->{'history-2'}=(object)['text'=>str_repeat('second',4000)];$a['data']->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
 $b=MonGaps::compact($a['data'],mon_iso());eu_ok($b['archive_entries_moved']===1);$rows=MonGaps::archived($b['data']);eu_ok(isset($rows->{'history-1'},$rows->{'history-2'}));
 $c=MonGaps::compact($b['data'],mon_iso());eu_ok($c['archive_entries_moved']===0&&count($c['dependency_files'])===0);
});
eu_case('A09 archive references reject mismatched SHA and invalid decreasing generation',function(){
 $s=eu_store();MonExecution::$store=$s;$d=reinforcement_fixture(1);$p=MonRun::payload($d);$p->strategy_archive=(object)['first'=>(object)['raw'=>'synthetic']];$d->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
 $r=MonGaps::compact($d,mon_iso());$ref=MonRun::payload($r['data'])->recovery_archive_head;$broken=eu_copy($ref);$broken->blob_sha=str_repeat('0',40);
 eu_fault(static function()use($broken){MonGaps::archiveFile($broken,false);},'MON_GAP_SOURCE_HASH_INVALID');
 $bad=(object)['contract'=>'MON-GAP-ARCHIVE-2.0','generation'=>1,'previous_manifest'=>(object)['generation'=>2],'strategy_archive'=>new stdClass()];$raw=MonRun::json($bad);$sha=hash('sha256',$raw);
 $s->writePrivate('recovery-archive-'.$sha.'.php',(object)['raw_json'=>$raw,'sha256'=>$sha]);$m=(object)['path'=>'mon_recovery_archive_'.$sha.'.json','sha256'=>$sha,'blob_sha'=>MonRun::gitSha($raw),'generation'=>1];
 eu_fault(static function()use($m){MonGaps::archiveFile($m,false);},'MON_GAP_ARCHIVE_CYCLE');
});
eu_case('A09 dependency files are published atomically with the financial pair',function(){
 [$s,$b,$pub,$git,$j,$clock,$base]=es_fixture();$a=MonArtifact::load($s,$j['artifact_id']);$candidate=mon_decode($a->files[0]->content);$p=MonRun::payload($candidate);
 $p->strategy_archive=(object)['synthetic-history'=>(object)['text'=>str_repeat('archive',5000)]];$candidate->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
 $compact=MonGaps::compact($candidate,MonBudget::iso($clock->wall));$artifact=MonArtifact::create($s,MonRun::json($base),$compact['files'],(array)$a->metadata);
 $job=$pub->enqueue($artifact['artifact_id'],$git->head,$b,'synthetic_operator');MonExecution::event('final_prepared',['artifact_id'=>$artifact['artifact_id'],'job_id'=>$job['job_id'],'save_status'=>'queued']);
 $out=$pub->process($job['job_id'],microtime(true)+10);eu_ok($out['publication_complete']);$tree=$git->trees[$git->commits[$git->head]['tree']];
 foreach($compact['files']as $f)eu_ok($tree[$f['path']]===$f['blob_sha']);eu_ok($git->patches===1);
});
eu_case('A09 connector pair without referenced dependency cannot be marked saved',function(){
 [$s,$b,$pub,$git,$j,$clock,$base]=es_fixture();$a=MonArtifact::load($s,$j['artifact_id']);$candidate=mon_decode($a->files[0]->content);$p=MonRun::payload($candidate);
 $p->strategy_archive=(object)['synthetic-history'=>(object)['text'=>str_repeat('archive',5000)]];$candidate->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
 $compact=MonGaps::compact($candidate,MonBudget::iso($clock->wall));$artifact=MonArtifact::create($s,MonRun::json($base),$compact['files'],(array)$a->metadata);$loaded=MonArtifact::load($s,$artifact['artifact_id']);
 $job=$pub->enqueue($artifact['artifact_id'],$git->head,$b,'synthetic_operator');MonExecution::event('final_prepared',['artifact_id'=>$artifact['artifact_id'],'job_id'=>$job['job_id'],'save_status'=>'queued']);
 es_saveFiles($git,array_map(static function($f){return (array)$f;},$loaded->files));$out=$pub->process($job['job_id'],microtime(true)+10);$tree=$git->trees[$git->commits[$git->head]['tree']];
 eu_ok(!$out['publication_complete']||isset($tree[$compact['dependency_files'][0]['path']]),'reported saved while the referenced archive is absent');
});
eu_case('A10 real near-limit data regains the reserve and exact original decisions',function()use(&$observed){
 $s=eu_store();MonExecution::$store=$s;$d=mon_decode(file_get_contents(getenv('MON_ERRC_OBSERVED_SOURCE')));$before=MonRun::storage($d,false);$r=MonGaps::compact($d,mon_iso());$after=$r['storage'];
 eu_ok($after->fits&&$after->collection_reserve_bytes>=655360&&$after->remaining_after_reserve>$before->remaining_after_reserve);
 foreach(['run_id','as_of','selection_fingerprint','input_fingerprint','result_fingerprint','results']as $k)eu_ok(MonRun::json($d->analysis->$k)===MonRun::json($r['data']->analysis->$k));
 eu_ok(MonRun::json($d->collection)===MonRun::json($r['data']->collection));$observed['storage']=['before'=>$before,'after'=>$after,'moved'=>$r['archive_entries_moved']];
});
eu_case('A10 thirty maximum-sized synthetic transport envelopes fit after observed compaction',function(){
 $s=eu_store();MonExecution::$store=$s;$d=mon_decode(file_get_contents(getenv('MON_ERRC_OBSERVED_SOURCE')));$r=MonGaps::compact($d,mon_iso());$d=$r['data'];$d->collection->detail_responses=new stdClass();
 for($i=0;$i<30;$i++)$d->collection->detail_responses->{'capacity-fixture-'.$i}=(object)['contract'=>MonDetails::CONTRACT,'state'=>'done','usable'=>false,'error'=>'SYNTHETIC_CAPACITY_ONLY',
 'payload'=>(object)['encoding'=>'gzip+base64','data'=>str_repeat('a',MonDetails::MAX_PACKED),'uncompressed_bytes'=>0,'sha256'=>str_repeat('0',64)],'metadata'=>str_repeat('m',1000)];
 $space=MonRun::storage($d,false);eu_ok($space->fits,'max response capacity exceeds the main-file limit');
});
eu_case('A11 unchanged bars reuse exact technical calculations while one changed close invalidates',function(){
 MonSwing::resetNumericCache();$d=reinforcement_fixture(1);$bars=$d->_test_snapshots->{$d->watchlist->symbols[0]->symbol_id}->bars;
 MonSwing::resetNumericCache();$a=MonSwing::technical($bars);$one=MonSwing::numericCacheStats();$b=MonSwing::technical($bars);$two=MonSwing::numericCacheStats();
 eu_ok(MonRun::json($a)===MonRun::json($b)&&$two['hits']===$one['hits']+1&&$two['misses']===$one['misses']);$bars[count($bars)-1][4]+=.0000001;
 MonSwing::technical($bars);eu_ok(MonSwing::numericCacheStats()['misses']===$two['misses']+1);
});
eu_case('A11 protected numerical cache survives a new handler and rejects corruption',function(){
 $s=eu_store();MonPreparation::$store=$s;MonSwing::resetNumericCache();$d=reinforcement_fixture(1);$bars=$d->_test_snapshots->{$d->watchlist->symbols[0]->symbol_id}->bars;
 $a=MonSwing::technical($bars);MonSwing::flushTechnicalCache($s);MonSwing::resetNumericCache();$b=MonSwing::technical($bars);eu_ok(MonRun::json($a)===MonRun::json($b)&&MonSwing::numericCacheStats()['hits']===1);
 $cached=$s->readPrivate('technical-cache.php');$cached->sha256=str_repeat('0',64);$s->writePrivate('technical-cache.php',$cached);MonSwing::resetNumericCache();MonSwing::technical($bars);eu_ok(MonSwing::numericCacheStats()['misses']===1);
});
eu_case('A11 snapshot cache preserves source time and invalidates changed tick identity',function(){
 $d=reinforcement_fixture(1);$symbol=$d->watchlist->symbols[0];$s=$d->_test_snapshots->{$symbol->symbol_id};$a=MonDetails::snapshot($symbol,$s->bars,$s->benchmark_bars,$s->benchmark_id,$s->origin,$s->benchmark_origin);
 $b=MonDetails::snapshot($symbol,$s->bars,$s->benchmark_bars,$s->benchmark_id,$s->origin,$s->benchmark_origin);eu_ok(MonRun::json($a)===MonRun::json($b));$a->metrics->MA5=1;
 $c=MonDetails::snapshot($symbol,$s->bars,$s->benchmark_bars,$s->benchmark_id,$s->origin,$s->benchmark_origin);eu_ok($c->metrics->MA5!==1&&$c->history_metadata->checked_at===$s->history_metadata->checked_at);
});
eu_case('A12 latest target changes are visible even for a previously done old request',function(){
 $d=reinforcement_fixture(1);$sid=$d->watchlist->symbols[0]->symbol_id;$d->collection->detail_responses->$sid=progress_response($d,$sid);
 $r=MonProgress::build($d,time());eu_ok($r['counts']['current_missing']===0);$session=end($d->watchlist->calendar->markets[0]->sessions);$session->close_at=mon_iso(time()-1);$session->open_at=mon_iso(time()-3600);
 $r=MonProgress::build($d,time());eu_ok($r['counts']['current_missing']===1);
});
eu_case('A13 saved connector pair is confirmed without a duplicate ref update',function(){
 [$s,$b,$pub,$git,$j]=es_fixture();cg_external_save($git,MonArtifact::load($s,$j['artifact_id']));$out=$pub->process($j['job_id'],microtime(true)+10);eu_ok($out['publication_complete']&&$git->patches===0);
});
eu_case('A13 lost acknowledgement retains one ref intent and later confirms',function(){
 [$s,$b,$pub,$git,$j,$clock]=es_fixture();$git->loseAck=true;$git->failConfirmation=true;$a=$pub->process($j['job_id'],microtime(true)+10);eu_ok(!$a['publication_complete']&&$git->patches===1);
 $git->failConfirmation=false;$clock->wall+=35;$clock->mono+=35;$out=$pub->process($j['job_id'],microtime(true)+10);eu_ok($out['publication_complete']&&$git->patches===1);
});
eu_case('A14 concurrent analysis is protected from overwrite',function(){
 [$s,$b,$pub,$git,$j]=es_fixture();$git->change='analysis';$out=$pub->process($j['job_id'],microtime(true)+10);eu_ok(!$out['publication_complete']&&$git->data()->analysis->concurrent_marker==='do-not-overwrite');
});
eu_case('A15 publisher records success without closing an execution-owned run',function(){
 [$s,$b,$pub,$git,$j]=es_fixture();$out=$pub->process($j['job_id'],microtime(true)+10);eu_ok($out['publication_complete']&&$b->run->finished_at===null);
 MonExecution::report((object)['operation_id'=>'late-source','operation'=>'external/source','seconds'=>4.5,'success'=>true,'units'=>2]);$r=MonExecution::finish();eu_ok($r['finish_accepted']&&$r['run']['operation']['overall_complete']);
});
eu_case('A15 an open external operation blocks finish until its idempotent acknowledgement',function(){
 [$s,$b,$pub,$git,$j]=es_fixture();MonExecution::report((object)['phase'=>'begin','operation_id'=>'source-1','operation'=>'external/source']);$pub->process($j['job_id'],microtime(true)+10);
 $r=MonExecution::finish();eu_ok(!$r['finish_accepted']&&$r['run']['finished_at']===null);$report=(object)['phase'=>'end','operation_id'=>'source-1','operation'=>'external/source','seconds'=>3.2,'success'=>true,'units'=>1];
 MonExecution::report($report);MonExecution::report($report);$r=MonExecution::finish();eu_ok($r['finish_accepted']&&$r['run']['operations']->{'external/source'}->count===1);
});
eu_case('A15 unmeasured external work preserves counts without fabricating zero durations',function(){
 [$s,$b,$pub,$git,$j]=es_fixture();MonExecution::report((object)['operation_id'=>'unknown-duration','operation'=>'external/source','seconds'=>null,'timing_unmeasured'=>true,'success'=>true,'units'=>1]);
 $pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish();eu_ok($r['finish_accepted']&&!isset($r['run']['operations']->{'external/source'}));eu_ok(isset($b->run->unmeasured_operations->{'unknown-duration'}));
});
eu_case('A16 business finish produces its revision before requiring remote statistics',function(){
 [$s,$b,$pub,$git,$j,$clock]=es_fixture('analysis',true);$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish();eu_ok($r['finish_accepted']&&!$r['run']['operation']['overall_complete']);
 $rowSha=MonRun::gitSha(MonRun::json($r['statistics_snapshot']));es_saveFiles($git,[['path'=>'mon_execution_stats.json','content'=>MonRun::json($r['statistics_snapshot']),'blob_sha'=>$rowSha]]);
 $op=MonExecution::verifyStatistics(es_pub($s,$git,$clock),microtime(true)+10);eu_ok($op['overall_complete']&&$op['terminal']&&$op['completion_receipt']->statistics->revision===$b->run->finish_revision);
});
eu_case('A16 failed remote statistics retain the same business id and closed revision',function(){
 [$s,$b,$pub,$git,$j,$clock]=es_fixture('analysis',true);$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish();$received=$b->run->received_at;$finished=$b->run->finished_at;
 try{MonExecution::verifyStatistics(es_pub($s,$git,$clock),microtime(true)+10);}catch(MonFault $e){}
 MonExecution::resume($s,$b->run->run_context_id,static function()use($clock){return $clock->wall;},static function()use($clock){return $clock->mono;});$b=MonExecution::$active;
 eu_ok($b->run->received_at===$received&&$b->run->finished_at===$finished&&!MonExecution::operation($b)['overall_complete']);
});
eu_case('A17 analysis and save success do not normalize an error NAS',function(){
 [$s,$b,$pub,$git,$j,$clock]=es_fixture('analysis_and_nas_normalization');$pub->process($j['job_id'],microtime(true)+10);
 MonExecution::checkNas(es_nas($s,$clock,es_health($clock->wall,['readiness'=>'error'])));$op=MonExecution::operation($b);eu_ok($op['scopes']['analysis']&&$op['scopes']['publication']&&!$op['overall_complete']);
 eu_ok($op['scopes']['telemetry'],'independent telemetry scope must not be failed by the NAS scope');$r=MonExecution::finish();eu_ok(!$r['finish_accepted']&&$r['run']['finished_at']===null);
});
eu_case('A18 verified off-session collection is normal even without monitoring_active',function(){
 [$s,$b,$pub,$git,$j,$clock]=es_fixture('analysis_and_nas_normalization');$pub->process($j['job_id'],microtime(true)+10);
 MonExecution::checkNas(es_nas($s,$clock,es_health($clock->wall)));$r=MonExecution::finish();eu_ok($r['finish_accepted']&&$r['run']['operation']['overall_complete']);
});
eu_case('A18 stale heartbeat and pending serialization prevent normality',function(){
 foreach(['stale','serialization']as $kind){[$s,$b,$pub,$git,$j,$clock]=es_fixture('analysis_and_nas_normalization');$pub->process($j['job_id'],microtime(true)+10);$health=es_health($clock->wall);
  if($kind==='stale')$health->heartbeat_at=MonBudget::iso($clock->wall-31);else $health->detail_progress->counts->serialization_pending=1;
  MonExecution::checkNas(es_nas($s,$clock,$health));eu_ok(!MonExecution::operation($b)['scopes']['nas_collection']);}
});
eu_case('A19 a changed required scope cannot replace the protected start policy',function(){
 [$s,$b,$pub,$git,$j,$clock]=es_fixture('analysis_and_nas_normalization');
 $id=$b->run->run_context_id;$copy=eu_copy($b->run);$copy->operation_policy->require_nas_collection=false;$s->writePrivate('run-'.$id.'.php',$copy);
 eu_fault(static function()use($s,$id){MonExecution::resume($s,$id);},'MON_RUN_CONTEXT_INVALID');
});
eu_case('A19 source statistics with changed contents cannot produce an operational receipt',function(){
 [$s,$b,$pub,$git,$j,$clock]=es_fixture('analysis',true);$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish();$raw=MonRun::json($r['statistics_snapshot']);es_saveFiles($git,[['path'=>'mon_execution_stats.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)]]);
 eu_fault(static function()use($s,$git,$clock){MonExecution::verifyStatistics(es_pub($s,$git,$clock,true),microtime(true)+10);},'MON_STATISTICS_REMOTE_MISMATCH');eu_ok(!MonExecution::operation($b)['overall_complete']);
});
eu_case('A20 final display rejects a business-only completion while telemetry is pending',function(){
 [$s,$b,$pub,$git,$j]=es_fixture('analysis',true);$pub->process($j['job_id'],microtime(true)+10);MonExecution::finish();eu_fault(static function(){MonExecution::event('final_display_reported');},'MON_OPERATION_PENDING');
});
eu_case('legacy finish owner keeps previous financial-completion behavior',function(){
 [$s,$b,$pub,$git,$j]=cg_publication(true);$out=$pub->process($j['job_id'],microtime(true)+10);eu_ok($out['fully_complete']&&$b->run->finished_at!==null&&MonExecution::operation($b)===null);
});
eu_case('A07 cache write failure is an operational fault and does not claim a saved response',function(){
 $s=eu_store();$d=reinforcement_fixture(1);$calls=0;$worker=eu_worker($s,$calls);mkdir($s->path('detail_cache.json'));
 try{eu_fault(static function()use($worker,$d){$worker->processOne($d,microtime(true)+5,[]);},'STATE_RENAME_FAILED');}
 finally{rmdir($s->path('detail_cache.json'));}eu_ok($calls===0);
});
eu_case('A11 shared benchmark persists across bounded workers and invalidates changed dates or corrupt hashes',function(){
 $s=eu_store();$cfg=progress_test_config($s->dir);$http=new MonHttp(null,static function(){throw new MonFault('TEST_UNEXPECTED_NETWORK');});$d=reinforcement_fixture(1);$stock=$d->_test_snapshots->{$d->watchlist->symbols[0]->symbol_id};$identity=MonDetails::benchmark($d->watchlist->symbols[0]);$target=$stock->metrics->bars_as_of;
 $daily=new MonDaily($http,new MonCollector($http,$s,$cfg),$cfg,$s);$save=new ReflectionMethod($daily,'saveBenchmark');$save->setAccessible(true);$save->invoke($daily,$identity,$target,(object)['bars'=>$stock->benchmark_bars,'origin'=>$stock->benchmark_origin]);
 $next=new MonDaily($http,new MonCollector($http,$s,$cfg),$cfg,$s);$read=new ReflectionMethod($next,'benchmarkCached');$read->setAccessible(true);$value=$read->invoke($next,$identity,$target,$stock->bars);
 eu_ok($value instanceof stdClass&&$value->origin->checked_at===$stock->benchmark_origin->checked_at);eu_ok($read->invoke($next,$identity,'2099-01-01',$stock->bars)===null);
 $file='benchmark-'.hash('sha256',$identity['id']).'.php';$cached=$s->readPrivate($file);$cached->sha256=str_repeat('0',64);$s->writePrivate($file,$cached);eu_ok($read->invoke($next,$identity,$target,$stock->bars)===null);
});
eu_case('A08 repeated exact history is suppressed only after checking the retained immutable record',function(){
 $s=eu_store();MonExecution::$store=$s;$d=reinforcement_fixture(1);$p=MonRun::payload($d);$value=(object)['text'=>'unique synthetic history'];$p->strategy_archive=(object)['history'=>$value];$d->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');$a=MonGaps::compact($d,mon_iso());
 $p=MonRun::payload($a['data']);$p->strategy_archive->history=$value;$a['data']->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');$b=MonGaps::compact($a['data'],mon_iso());eu_ok($b['archive_entries_moved']===0&&isset(MonGaps::archived($b['data'])->history));
 $d=reinforcement_fixture(1);$p=MonRun::payload($d);$p->strategy_archive=(object)['history'=>$value];$p->archive_recent_hashes=(object)[hash('sha256','history')=>hash('sha256',MonRun::json($value))];$d->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');$r=MonGaps::compact($d,mon_iso());eu_ok($r['archive_entries_moved']===1,'unproved recent hash discarded unique history');
});
eu_case('A09 transitive referenced archives missing from the tree are restored with the pair',function(){
 [$s,$b,$pub,$git,$j,$clock,$base]=es_fixture();$a=MonArtifact::load($s,$j['artifact_id']);$d=mon_decode($a->files[0]->content);$p=MonRun::payload($d);$p->strategy_archive=(object)['first'=>(object)['text'=>'first']];$d->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');$first=MonGaps::compact($d,MonBudget::iso($clock->wall));
 $p=MonRun::payload($first['data']);$p->strategy_archive->second=(object)['text'=>'second'];$first['data']->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');$second=MonGaps::compact($first['data'],MonBudget::iso($clock->wall));
 $artifact=MonArtifact::create($s,MonRun::json($base),$second['files'],(array)$a->metadata);$job=$pub->enqueue($artifact['artifact_id'],$git->head,$b,'synthetic_operator');MonExecution::event('final_prepared',['artifact_id'=>$artifact['artifact_id'],'job_id'=>$job['job_id'],'save_status'=>'queued']);
 $out=$pub->process($job['job_id'],microtime(true)+10);eu_ok($out['publication_complete']);$tree=$git->trees[$git->commits[$git->head]['tree']];eu_ok(isset($tree[$first['dependency_files'][0]['path']],$tree[$second['dependency_files'][0]['path']]));
});
eu_case('A15 maximum operation ids remain bounded tasks and preserve physical units',function(){
 [$s,$b,$pub,$git,$j]=es_fixture();$id=str_repeat('x',96);MonExecution::report((object)['phase'=>'begin','operation_id'=>$id,'operation'=>'external/source']);$pub->process($j['job_id'],microtime(true)+10);$pending=MonExecution::finish();eu_ok(!$pending['finish_accepted']);
 MonExecution::report((object)['phase'=>'end','operation_id'=>$id,'operation'=>'external/source','seconds'=>1.2,'success'=>true,'units'=>98]);$r=MonExecution::finish();eu_ok($r['finish_accepted']&&$r['run']['counts']->source_http_calls===98&&$r['run']['counts']->reported_external_calls===1);
});
eu_case('A17 old target repair requests actual work rather than only repeating health reads',function(){
 $d=reinforcement_fixture(1);$sid=$d->watchlist->symbols[0]->symbol_id;$d->collection->detail_responses->$sid=progress_response($d,$sid);$session=end($d->watchlist->calendar->markets[0]->sessions);$session->close_at=mon_iso(time()-1);$session->open_at=mon_iso(time()-3600);
 $repair=MonProgress::nextRepair($d,null,time());eu_ok($repair->period_changed&&$repair->next_api==='mon_detail_request'&&$repair->symbol_id===$sid);
});
eu_case('rolling source window accepts only its actual overlap and preserves the full previous hash',function(){
 $d=reinforcement_fixture(1);$s=eu_copy($d->_test_snapshots->{$d->watchlist->symbols[0]->symbol_id});$s->origin->source_url='https://query1.finance.yahoo.com/synthetic';
 $fresh=(object)['bars'=>array_slice($s->bars,7),'origin'=>(object)['source'=>'SPG_STOCKANALYSIS','price_basis'=>MonDetails::BASIS,'complete_source_window'=>true]];
 $p=MonDaily::priorWindowProof($s,$fresh,'KRW');eu_ok($p->verified_prior_close_count===63&&$p->excluded_old_count===7&&$p->previous_bars_sha256===MonRun::digest($s->bars)&&$p->row_merge===false);
});
eu_case('rolling source proof rejects an overlap hole and fewer than sixty matching closes',function(){
 $d=reinforcement_fixture(1);$s=eu_copy($d->_test_snapshots->{$d->watchlist->symbols[0]->symbol_id});$s->origin->source_url='https://query1.finance.yahoo.com/synthetic';
 foreach(['hole','short']as $kind){$bars=array_slice($s->bars,$kind==='short'?11:7);if($kind==='hole')array_splice($bars,20,1);
  $fresh=(object)['bars'=>$bars,'origin'=>(object)['source'=>'SPG_STOCKANALYSIS','price_basis'=>MonDetails::BASIS,'complete_source_window'=>true]];
  eu_fault(static function()use($s,$fresh){MonDaily::priorWindowProof($s,$fresh,'KRW');},'DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');}
});
eu_case('A16 postprocessing ACKs preserve the closed business sample and remain idempotent',function(){
 [$s,$b,$pub,$git,$j,$clock]=es_fixture('analysis',true);$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish();$before=MonRun::json(MonRunStatistics::load($s));$finished=$b->run->finished_at;
 MonExecution::postReport((object)['phase'=>'begin','operation_id'=>'stats-upload-1','operation'=>'statistics_publication']);eu_ok(!MonExecution::operation($b)['scopes']['telemetry']);
 $report=(object)['phase'=>'end','operation_id'=>'stats-upload-1','operation'=>'statistics_publication','seconds'=>7.5,'success'=>true];MonExecution::postReport($report);MonExecution::postReport($report);
 eu_ok(MonRun::json(MonRunStatistics::load($s))===$before&&$b->run->finished_at===$finished&&MonExecution::operation($b)['postprocessing']->{'stats-upload-1'}->seconds===7.5);
 $raw=MonRun::json($r['statistics_snapshot']);es_saveFiles($git,[['path'=>'mon_execution_stats.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)]]);eu_ok(MonExecution::verifyStatistics(es_pub($s,$git,$clock),microtime(true)+10)['overall_complete']);
});
eu_case('A16 postprocessing cannot bypass the business-completion barrier',function(){
 [$s,$b,$pub,$git,$j]=es_fixture('analysis',true);eu_fault(static function(){MonExecution::postReport((object)['phase'=>'begin','operation_id'=>'early','operation'=>'statistics_publication']);},'MON_BUSINESS_COMPLETION_REQUIRED');
});
$failed=count(array_filter($cases,static function($v){return !$v['pass'];}));
echo json_encode(['version'=>MON_VERSION,'cases_total'=>count($cases),'passed'=>count($cases)-$failed,'failed'=>$failed,'seconds'=>mon_monotonic()-$started,
 'peak_memory_bytes'=>memory_get_peak_usage(true),'external_api_calls'=>0,'nas_deployed'=>false,'observed'=>$observed,'cases'=>$cases],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
foreach($dirs as $dir){foreach(glob($dir.'/*')as $file)unlink($file);rmdir($dir);}exit($failed?1:0);

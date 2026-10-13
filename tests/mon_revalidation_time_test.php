<?php
declare(strict_types=1);
require __DIR__.'/mon_reinforcement_fixture.php';

// Synthetic, changed-path checks only. No NAS, live market data or real GitHub writes.
$cases=[];$dirs=[];$start=mon_monotonic();$sourceHash=hash_file('sha256',dirname(__DIR__).'/mon.php');
function rt_assert(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function rt_case(string $name,callable $fn): void {
    global $cases;MonExecution::$active=null;MonExecution::$store=null;MonPreparation::reset();MonRun::releasePayloadCache();
    try{$evidence=$fn();$cases[]=['case'=>$name,'result'=>'PASS','evidence'=>$evidence??[]];}
    catch(Throwable $e){$cases[]=['case'=>$name,'result'=>'FAIL','error'=>$e->getMessage()];}
}
function rt_copy($value) {return mon_decode(MonRun::json($value));}
function rt_context($d,int $advance=0): stdClass {
    $c=reinforcement_context($d);$c->rendered_at=mon_iso(max(time(),(int)MonRun::seconds($d->analysis->as_of))+$advance);return $c;
}
function rt_decision($d,int $advance=0): stdClass {return reinforcement_decision($d,rt_context($d,$advance));}
function rt_output(array $result): stdClass {
    rt_assert($result['write_required']&&count($result['files'])>=2,'finalization must regenerate a publishable data/result pair');
    return mon_decode($result['files'][0]['content']);
}
function rt_first(): array {
    $base=reinforcement_fixture();$decision=rt_decision($base);$result=MonDetails::finalize($base,$decision);
    return [$base,rt_output($result),$result];
}
function rt_fault(callable $fn,string $code): void {
    try{$fn();}catch(MonFault $e){rt_assert($e->faultCode===$code,'unexpected fault '.$e->faultCode);return;}
    throw new RuntimeException('expected '.$code);
}
function rt_dir(): string {global $dirs;$path=sys_get_temp_dir().'/mon-revalidation-'.bin2hex(random_bytes(8));mkdir($path,0700);$dirs[]=$path;return $path;}
function rt_clean(string $dir): void {foreach(scandir($dir)as $name)if($name!=='.'&&$name!=='..'){if(is_dir($dir.'/'.$name))rt_clean($dir.'/'.$name);else unlink($dir.'/'.$name);}rmdir($dir);}
function rt_pair_job(): array {
    [, $base]=rt_first();$decision=rt_decision($base,1);$result=MonDetails::finalize($base,$decision);$candidate=rt_output($result);
    $store=new MonStore(rt_dir());$cfg=progress_test_config($store->dir);
    $budget=MonExecution::start($store,(object)['run_id'=>'synthetic-revalidation-'.bin2hex(random_bytes(5)),'received_at'=>mon_iso()]);
    $manifest=MonArtifact::create($store,MonRun::json($base),$result['files'],['run_context_id'=>$budget->run->run_context_id,
        'received_at'=>$budget->run->received_at,'countries'=>['KR'],'decision_sha256'=>hash('sha256',MonRun::json($decision)),
        'prepared_fingerprint'=>$decision->prepared_fingerprint]);
    $git=new ReinforcementGit($base);$publisher=new MonPublication($store,new MonHttp(null,[$git,'transport']),$cfg);
    $job=$publisher->enqueue($manifest['artifact_id'],$git->head,$budget,'synthetic-operator');
    return [$base,$candidate,$result,$store,$cfg,$budget,$manifest,$git,$publisher,$job];
}

rt_case('unchanged completed revalidation advances analysis time and regenerates the pair',function(){
    [, $base]=rt_first();$oldTime=$base->analysis->as_of;$decision=rt_decision($base,60);$result=MonDetails::finalize($base,$decision);$out=rt_output($result);
    rt_assert(MonRun::seconds($out->analysis->as_of)>MonRun::seconds($oldTime),'analysis completion time did not advance');
    rt_assert($out->analysis->display->revalidated_at===$out->analysis->as_of,'display time is not the actual decision time');
    rt_assert($out->analysis->display->selection_recomputed===true&&$out->analysis->display->analysis_complete===true,'completed selection was not marked');
    rt_assert($result['analysis_origin']==='revalidated_unchanged_decision'&&$result['validation']['meaningful_change']===false,'stable business result was classified as changed');
    rt_assert($result['validation']['selection_changed']===false&&$out->analysis->display->selection_changed===false,'stable recommendation was classified as changed');
    rt_assert($result['save_status']==='pending','new pair must await an actual save receipt');
    rt_assert(strpos($result['summary'],'재검증 완료 · 추천 변경 없음')!==false,'unchanged completion label missing');
    MonRun::validate($out);MonRun::verifyFacts($out,MonRun::payload($out));
    return ['previous_as_of'=>$oldTime,'new_as_of'=>$out->analysis->as_of,'file_paths'=>array_column($result['files'],'path'),'origin'=>$result['analysis_origin']];
});
rt_case('new completion preserves source bars and collection receipt times',function(){
    [, $base]=rt_first();$old=MonRun::payload($base);$decision=rt_decision($base,60);$out=rt_output(MonDetails::finalize($base,$decision));$new=MonRun::payload($out);
    rt_assert(MonRun::json($old->series)===MonRun::json($new->series),'completed OHLCV changed during timestamp refresh');
    rt_assert(MonRun::json($old->history_metadata)===MonRun::json($new->history_metadata),'provider checked_at was fabricated');
    rt_assert(MonRun::json($base->collection)===MonRun::json($out->collection),'collector-owned completion timestamp changed');
    rt_assert($out->analysis->display->completed_bar_dates->KR===$base->analysis->market_summary[0]->completed_bar_date,'completed-bar date was replaced by report date');
    return ['bar_date'=>$out->analysis->display->completed_bar_dates->KR,'collection_preserved'=>true,'history_metadata_preserved'=>true];
});
rt_case('unchanged revalidation preserves verified quote and issuer observation times',function(){
    $d=reinforcement_fixture();$decision=rt_decision($d);$sid=$decision->selected[0]->symbol_id;$snapshot=$d->_test_snapshots->$sid;
    $plan=$snapshot->entry_plans[1];$price=array_sum($plan->entry_zone)/2;$checked=MonRun::utc($decision->fixed_as_of);
    $quote=mon_iso((int)MonRun::seconds($checked)-1);$date=(new DateTimeImmutable($checked))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
    $decision->confirmations=(object)['new_evidence'=>[(object)['id'=>'fixture-confirmation','source_kind'=>'independent','source_url'=>'https://example.invalid/issuer',
        'claim'=>'Synthetic current quote, issuer and trading checks','checked_at'=>$checked]],'changes'=>[(object)['symbol_id'=>$sid,
        'price_observation'=>(object)['symbol_id'=>$sid,'source_kind'=>'independent','currency'=>'KRW','venue'=>'KOSPI','price'=>$price,
            'quote_at'=>$quote,'price_type'=>'last','timestamp_basis'=>'trade','delay_seconds'=>0,'session'=>'regular','market_date'=>$date,'evidence_ids'=>['fixture-confirmation']],
        'risk_review'=>(object)['status'=>'verified','trading_status'=>'verified','scope'=>'synthetic issuer/trading review','pending_items'=>[],
            'applies_as_of'=>$checked,'checked_at'=>$quote,'evidence_ids'=>['fixture-confirmation'],'confirmed_material_adverse'=>false]]]];
    $base=rt_output(MonDetails::finalize($d,$decision));$prior=$base->analysis->results[0];$out=rt_output(MonDetails::finalize($base,rt_decision($base,60)));$row=$out->analysis->results[0];
    foreach(['price','price_as_of','risk_checks','risk_review']as $field)rt_assert(MonRun::json($prior->$field)===MonRun::json($row->$field),'original '.$field.' observation was replaced');
    $observational=['delay_kind','delay_seconds','market_date','source','timestamp_basis'];
    rt_assert(MonRun::json(MonRun::pick($prior->price_checks,$observational))===MonRun::json(MonRun::pick($row->price_checks,$observational)),'quote source or observed trading date changed');
    rt_assert($row->price_checks->age_seconds>$prior->price_checks->age_seconds,'quote age was not recalculated at the new analysis time');
    return ['original_quote_at'=>$row->price_as_of,'new_analysis_at'=>$out->analysis->as_of,'observation_fields_preserved'=>true];
});
rt_case('different selected identity is reported as recommendation change',function(){
    [, $base]=rt_first();$decision=rt_decision($base,1);$decision->selected[0]->symbol_id=$base->watchlist->symbols[1]->symbol_id;
    $result=MonDetails::finalize($base,$decision);$out=rt_output($result);
    rt_assert($result['validation']['selection_changed']===true&&$out->analysis->display->selection_changed===true,'new identity was hidden as unchanged');
    rt_assert(strpos($result['summary'],'추천 변경 있음')!==false,'change label missing');return ['selected_id'=>$out->analysis->results[0]->symbol_id];
});
rt_case('changed entry plan for the same identity is a recommendation change',function(){
    [, $base]=rt_first();$sid=$base->analysis->results[0]->symbol_id;$payload=MonRun::payload($base);$payload->detail_plans->$sid[0]->entry_zone[0]+=1;
    $base->analysis->input_snapshot->lossless_payload=MonRun::pack($payload,'gzip+base64');$result=MonDetails::finalize($base,rt_decision($base,1));$out=rt_output($result);
    rt_assert($result['validation']['selection_changed']===true,'changed price plan was hidden as unchanged');
    return ['entry_plan_changed'=>true,'selected_id'=>$out->analysis->results[0]->symbol_id];
});
rt_case('new primary evidence does not falsely claim changed recommendation',function(){
    [, $base]=rt_first();$decision=rt_decision($base,1);$decision->confirmations=(object)['new_evidence'=>[(object)['id'=>'fixture-new-evidence','source_kind'=>'independent',
        'source_url'=>'https://example.invalid/new-proof','claim'=>'Synthetic new primary evidence without plan or identity change','checked_at'=>$decision->fixed_as_of]],'changes'=>[]];
    $result=MonDetails::finalize($base,$decision);rt_output($result);
    rt_assert($result['validation']['meaningful_change']===true&&$result['validation']['selection_changed']===false,'evidence and recommendation changes were conflated');
    return ['meaningful_change'=>true,'selection_changed'=>false];
});
rt_case('standalone swing selection writes completion metadata',function(){
    $d=reinforcement_fixture();$first=MonSwing::select($d,rt_decision($d));$base=rt_output($first);$result=MonSwing::select($base,rt_decision($base,1));$out=rt_output($result);
    rt_assert($out->analysis->display->selection_recomputed===true&&$out->analysis->display->selection_changed===false,'standalone swing path was not stamped');
    rt_assert(strpos($result['summary'],'재검증 완료 · 추천 변경 없음')!==false,'standalone swing header missing');return ['path'=>'MonSwing::select'];
});
rt_case('human-readable KST heading separates October 13 revalidation from October 12 bars',function(){
    [, $out]=rt_first();$out->analysis->as_of=MonRun::utc('2026-10-13T00:48:00Z');$display=$out->analysis->display;
    $display->revalidated_at=$out->analysis->as_of;$display->analysis_as_of=$out->analysis->as_of;$display->selection_changed=false;
    $display->completed_bar_dates=(object)['KR'=>'2026-10-12'];$display->scope_countries=['KR'];
    $expected='2026년 10월 13일 09:48 한국 재검증 완료 · 추천 변경 없음 · 한국 일봉 10월 12일 기준';
    rt_assert(MonRevalidation::heading($out->analysis)===$expected,'KST date or bar-date heading incorrect');
    rt_assert(strpos(MonRun::render($out,$out->analysis->as_of),$expected)!==false,'generated report omitted completion heading');
    return ['synthetic_heading'=>$expected];
});
rt_case('completion metadata covers requested markets only',function(){
    [$base,$out]=rt_first();$out->analysis->market_summary[]=(object)['country'=>'US','completed_bar_date'=>'2026-10-12'];
    $before=rt_copy($out);MonRevalidation::stamp($base,$out,rt_context($before));$display=$out->analysis->display;
    rt_assert($display->scope_countries===['KR']&&array_keys(get_object_vars($display->completed_bar_dates))===['KR'],'unrequested market got a fresh completion date');
    rt_assert(!property_exists($display->market_revalidations,'US'),'US market completion was fabricated');
    return ['completed_scope'=>$display->scope_countries];
});
rt_case('pending requested-market analysis cannot display revalidation complete',function(){
    [, $out]=rt_first();$out->analysis->pending_analysis=true;$out->analysis->pending_analysis_countries=['KR'];$base=rt_copy($out);
    MonRevalidation::stamp($base,$out,rt_context($base));$header=MonRevalidation::heading($out->analysis);
    rt_assert($out->analysis->display->analysis_complete===false&&$out->analysis->display->revalidated_at===null,'pending analysis was stamped as complete');
    rt_assert(strpos($header,'재검증 완료')===false&&strpos($header,'추가 자료 검토 대기')!==false,'pending heading claimed completion');
    rt_assert($out->analysis->status==='partial','overall partial status was promoted');return ['heading'=>$header];
});
rt_case('unrequested-market pending analysis does not invalidate requested-market completion',function(){
    [, $out]=rt_first();$out->analysis->pending_analysis=true;$out->analysis->pending_analysis_countries=['US'];$base=rt_copy($out);
    MonRevalidation::stamp($base,$out,rt_context($base));rt_assert($out->analysis->display->analysis_complete===true,'US pending incorrectly erased KR completion');
    rt_assert(strpos(MonRevalidation::heading($out->analysis),'한국 재검증 완료')!==false,'KR completion heading missing');return ['completed_scope'=>['KR'],'pending_scope'=>['US']];
});
rt_case('mixed requested scope refreshes completed KR while keeping pending US old',function(){
    [, $out]=rt_first();$oldTime=mon_iso((int)MonRun::seconds($out->analysis->as_of)-60);
    $out->analysis->market_summary[]=(object)['country'=>'US','completed_bar_date'=>'2026-10-12'];
    $out->analysis->display->market_revalidations->US=(object)['completed_at'=>$oldTime,'completed_bar_date'=>'2026-10-09','selection_changed'=>false];
    $out->analysis->pending_analysis=true;$out->analysis->pending_analysis_countries=['US'];$base=rt_copy($out);$c=rt_context($base);$c->countries=['KR','US'];
    MonRevalidation::stamp($base,$out,$c);$display=$out->analysis->display;$header=MonRevalidation::heading($out->analysis);
    rt_assert($display->analysis_complete===false&&$display->revalidated_at===null,'mixed pending was promoted to whole-run completion');
    rt_assert($display->completed_countries===['KR']&&$display->pending_countries===['US'],'per-market completeness is incorrect');
    rt_assert($display->market_revalidations->KR->completed_at===$out->analysis->as_of&&$display->market_revalidations->US->completed_at===$oldTime,'pending US old completion was overwritten');
    rt_assert(strpos($header,'한국 재검증 완료')!==false&&strpos($header,'미국 추가 자료 검토 대기')!==false&&strpos($header,'미국 재검증 완료')===false,'mixed-scope heading is misleading');
    return ['heading'=>$header,'US_previous_completion'=>$oldTime,'KR_current_completion'=>$display->market_revalidations->KR->completed_at];
});
rt_case('legacy display and redisplay do not invent new analysis completion time',function(){
    $d=reinforcement_fixture();$original=MonRun::json($d);$shown=mon_iso(time()+60);$text=MonRun::render($d,$shown);
    rt_assert(strpos($text,'재검증 완료')===false&&strpos($text,'재사용 표시')!==false,'legacy render invented revalidation');
    rt_assert(MonRun::json($d)===$original,'read-only renderer mutated source timestamps');return ['legacy_as_of'=>$d->analysis->as_of,'redisplay_at'=>$shown];
});
rt_case('simple reuse preserves analysis completion and emits no file writes',function(){
    [, $d]=rt_first();$original=MonRun::json($d);$c=rt_context($d,60);$patch=(object)['run_mode'=>'reuse','base_source_blob_sha'=>$c->source_blob_sha,
        'base_analysis_run_id'=>$d->analysis->run_id,'fixed_as_of'=>$d->analysis->as_of,'changes'=>[],'new_evidence'=>[],'pending'=>[],'short_reasons'=>[],
        'view_context'=>$c,'state_token'=>MonRun::token($d,$c)];$result=mon_apply_mon_patch($d,$patch);
    rt_assert(!$result['write_required']&&$result['files']===[]&&$result['validation']['analysis_as_of_preserved'],'reuse path changed analysis time');
    rt_assert(MonRun::json($d)===$original,'reuse mutated data');return ['write_required'=>false,'as_of'=>$d->analysis->as_of];
});
rt_case('invalid decision cannot stamp or publish a fresh completion',function(){
    [, $base]=rt_first();$original=MonRun::json($base);$decision=rt_decision($base,1);$decision->state_token=str_repeat('0',64);
    rt_fault(static function()use($base,$decision){MonDetails::finalize($base,$decision);},'MON_STATE_TOKEN_INVALID');
    rt_assert(MonRun::json($base)===$original,'failed finalize mutated original analysis');return ['fault'=>'MON_STATE_TOKEN_INVALID','source_unchanged'=>true];
});
rt_case('stale completion metadata cannot describe a newer legacy analysis as revalidated',function(){
    [, $out]=rt_first();$out->analysis->as_of=mon_iso((int)MonRun::seconds($out->analysis->as_of)+60);
    rt_assert(strpos(MonRevalidation::heading($out->analysis),'재검증 완료')===false,'old completion marker validated a newer analysis timestamp');
    return ['old_display_time'=>$out->analysis->display->analysis_as_of,'new_analysis_time'=>$out->analysis->as_of];
});
rt_case('persisted unchanged-result pair is published and read back atomically',function(){
    [$base,$candidate,$result,$store,$cfg,$budget,$manifest,$git,$publisher,$job]=rt_pair_job();$receipt=$publisher->process($job['job_id'],microtime(true)+5);
    rt_assert($receipt['save_status']==='saved'&&$git->patches===1&&$git->newCommits===1,'new timestamp pair was not published exactly once');
    $tree=$git->trees[$git->commits[$git->head]['tree']];foreach($result['files']as $f)rt_assert($tree[$f['path']]===$f['blob_sha'],'pair readback blob mismatch');
    rt_assert($git->data()->analysis->as_of===$candidate->analysis->as_of,'saved JSON retained old completion time');
    $savedText=$git->blobs[$tree['mon_result.md']];rt_assert($savedText===$result['summary'],'saved report differs from validated artifact');
    return ['synthetic_save_status'=>$receipt['save_status'],'head_updates'=>$git->patches,'saved_completion'=>$git->data()->analysis->as_of];
});
rt_case('interrupted timestamp-pair publication resumes without exposing a mixed pair',function(){
    [$base,$candidate,$result,$store,$cfg,$budget,$manifest,$git,$publisher,$job]=rt_pair_job();$oldHead=$git->head;$git->crashBlob=true;
    try{$publisher->process($job['job_id'],microtime(true)+5);}catch(MonStop $e){}
    rt_assert($git->head===$oldHead&&$git->patches===0,'partial blob changed public pair');
    $resumed=new MonPublication($store,new MonHttp(null,[$git,'transport']),$cfg);$receipt=$resumed->process($job['job_id'],microtime(true)+5);
    rt_assert($receipt['save_status']==='saved'&&$git->patches===1,'resume did not publish one updated pair');
    rt_assert($git->data()->analysis->as_of===$candidate->analysis->as_of,'resume lost completion timestamp');
    return ['old_pair_until_commit'=>true,'saved_after_resume'=>true,'head_updates'=>$git->patches];
});
rt_case('late detail receipt marks report pending instead of claiming completed revalidation',function(){
    [$base,$candidate,$result,$store,$cfg,$budget,$manifest,$git,$publisher,$job]=rt_pair_job();$git->change='detail';
    $receipt=$publisher->process($job['job_id'],microtime(true)+5);if($receipt['save_status']==='queued')$receipt=$publisher->process($job['job_id'],microtime(true)+5);
    $out=$git->data();rt_assert($receipt['save_status']==='saved'&&$out->analysis->pending_analysis===true,'late detail was not marked pending');
    $tree=$git->trees[$git->commits[$git->head]['tree']];$summary=$git->blobs[$tree['mon_result.md']];
    rt_assert(strpos($summary,'재검증 완료')===false&&strpos($summary,'추가 자료 검토 대기')!==false,'late detail report wrongly claimed current completion');
    return ['save_status'=>$receipt['save_status'],'pending_analysis'=>true,'completed_label_suppressed'=>true];
});
rt_case('exact API rebase block refreshes result text while preserving a third archive dependency',function(){
    [, $data]=rt_first();$result=MonDetails::finalize($data,rt_decision($data,1));$candidate=rt_output($result);$latest=rt_copy($data);
    $sid=$latest->watchlist->symbols[0]->symbol_id;$latest->collection->detail_responses->$sid=progress_response($latest,$sid);
    $latest->collection->detail_responses->$sid->completed_at=mon_iso((int)MonRun::seconds($candidate->analysis->as_of)+1);
    $archive='[]';$dependency=['path'=>'mon_recovery_archive_'.hash('sha256',$archive).'.json','content'=>$archive,'blob_sha'=>MonRun::gitSha($archive)];
    $result['files'][]=$dependency;$request=(object)['latest_source_blob_sha'=>MonRun::gitSha(MonRun::json($latest))];
    $source=file_get_contents(dirname(__DIR__).'/mon.php');
    $needle='$candidate=mon_decode($result[\'files\'][0][\'content\']);$merged=MonRun::rebase($data,$candidate,$latest);';
    $begin=strpos($source,$needle);rt_assert($begin!==false,'actual API rebase block not found');$end=strpos($source,'// Artifact base',$begin);
    rt_assert($end!==false,'actual API rebase block end not found');$snippet=substr($source,$begin,$end-$begin);
    eval($snippet); // Exact current production block, synthetic variables and no transport.
    $out=mon_decode($result['files'][0]['content']);rt_assert($out->analysis->pending_analysis===true,'rebase did not mark new detail pending');
    rt_assert(count($result['files'])===3&&$result['files'][2]===$dependency,'archive dependency was dropped or changed');
    rt_assert(strpos($result['files'][1]['content'],'재검증 완료')===false&&strpos($result['files'][1]['content'],'추가 자료 검토 대기')!==false,'report with dependency retained stale completion label');
    rt_assert($result['files'][1]['blob_sha']===MonRun::gitSha($result['files'][1]['content']),'regenerated report hash is invalid');
    return ['boundary'=>'exact API rebase body only; HTTP handler not exercised','snippet_sha256'=>hash('sha256',$snippet),'dependency_preserved'=>true,'pending_heading_regenerated'=>true];
});

$failed=array_values(array_filter($cases,static function($row){return $row['result']==='FAIL';}));
$report=['kind'=>'actual_modified_php_offline_changed_path_tests','executed_at'=>mon_iso(),'processor_version'=>MON_VERSION,'php_version'=>PHP_VERSION,
    'runtime_binary'=>PHP_BINARY,'source_sha256'=>$sourceHash,'test_sha256'=>hash_file('sha256',__FILE__),
    'fixture_sha256'=>['mon_reinforcement_fixture.php'=>hash_file('sha256',__DIR__.'/mon_reinforcement_fixture.php'),
        'mon_progress_fixture.php'=>hash_file('sha256',__DIR__.'/mon_progress_fixture.php')],
    'baseline_reproduction'=>['version'=>'1.8.6','source_sha256'=>'1b5ba40d41c6aa52615b3dbb8b2ada7c07908f7abe805a009045b3cb3f0e4688',
        'executed_at'=>'2026-10-13T03:21:20Z','method'=>'actual exact Git baseline MonDetails::finalize twice with unchanged reinforcement_fixture; next fixed_as_of advanced one second',
        'previous_as_of'=>'2026-10-13T03:21:20.000000Z','requested_new_as_of'=>'2026-10-13T03:21:21Z',
        'observed'=>['status'=>'no_change','write_required'=>false,'files'=>0,'new_analysis_complete'=>true],'new_contract_assertion'=>'FAIL'],
    'intermediate_runs'=>[
        ['source_sha256'=>'04f17ccbd7bbfed83f49cb7b289d3094cdb10ec365373caacb7b4328ad7d5feb','cases'=>17,'passed'=>16,'failed'=>1,
            'failed_case'=>'quote/issuer observation preservation','reason'=>'test incorrectly required unchanged derived quote age; actual quote age increased 1 to 61 seconds'],
        ['source_sha256'=>'c4d2a99881fd39b9245e6fb5d90c58e94fd688956cd59c1af8559ef67fdec0a6','cases'=>17,'passed'=>16,'failed'=>1,
            'failed_case'=>'quote/issuer observation preservation','reason'=>'diagnostic rerun confirmed age_seconds is the only difference; test corrected to preserve observation fields and require increasing age']],
    'initial_environment_issue'=>['result'=>'NOT_RUN','runtime'=>'/workspace/scratch/180ddd9ad23f/php_runtime/php74','reason'=>'zlib unavailable; fixture raised MON_CODEC_UNAVAILABLE; rerun with existing PHP 7.4.33 zlib build'],
    'case_count'=>count($cases),'passed'=>count($cases)-count($failed),'failed'=>count($failed),'elapsed_seconds'=>mon_monotonic()-$start,
    'external_requests'=>0,'orders'=>0,'nas_actions'=>0,'cases'=>$cases];
file_put_contents(__DIR__.'/mon_revalidation_time_tests.json',mon_json($report));
echo mon_json(['case_count'=>count($cases),'passed'=>$report['passed'],'failed'=>$failed,'source_sha256'=>$sourceHash,'php'=>PHP_VERSION,'elapsed_seconds'=>$report['elapsed_seconds']]);
foreach($dirs as $dir)rt_clean($dir);exit($failed?1:0);

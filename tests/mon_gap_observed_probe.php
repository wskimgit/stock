<?php
declare(strict_types=1);
require dirname(__DIR__).'/mon.php';
// Replays captured public responses through the actual readers. No network or broker credentials.
// Usage: php -n tests/mon_gap_observed_probe.php CAPTURE_ROOT BASE_DATA [--stage]
$root=rtrim($argv[1]??'','/');$base=$argv[2]??'';$stage=in_array('--stage',$argv,true);$started=mon_monotonic();
if(!is_dir($root)||!is_file($base))throw new RuntimeException('capture directory and validated baseline required');
$d=mon_decode((string)file_get_contents($base));MonRun::validate($d);$p=MonRun::payload($d);MonRun::verifyFacts($d,$p);
$runtime=$root.'/mon-gap-validation-runtime';if(!is_dir($runtime))mkdir($runtime,0700);$store=new MonStore($runtime);$config=new MonConfig('',$runtime);$calls=[];
$http=new MonHttp(null,static function($method,$url,$headers,$body,$timeout)use($root,&$calls){
    $path=parse_url($url,PHP_URL_PATH);$host=parse_url($url,PHP_URL_HOST);$file=null;
    if($host==='fred.stlouisfed.org'&&$path==='/data/SP500')$file=$root.'/mon-resolution-fred-followed.txt';
    elseif($host==='api.nasdaq.com'&&strpos($path,'/quote/PLTR/historical')!==false)$file=$root.'/mon-resolution-nasdaq-pltr.json';
    elseif($host==='api.nasdaq.com'&&preg_match('~^/api/quote/([A-Z0-9.-]+)/historical$~',$path,$m))$file=$root.'/mon-gap-nasdaq-sources/'.$m[1].'.json';
    elseif($host==='stockanalysis.com'&&$path==='/stocks/pltr/history/')$file=$root.'/mon-resolution-stockanalysis-pltr.html';
    elseif($host==='stockanalysis.com'&&preg_match('~^/quote/tyo/([0-9A-Z]{4})/history/$~',$path,$m))$file=$root.'/mon-gap-sources/'.$m[1].'.html';
    elseif($host==='stockanalysis.com'&&preg_match('~^/stocks/([a-z0-9.-]+)/history/$~',$path,$m))$file=$root.'/mon-gap-us-sources/'.strtoupper($m[1]).'.html';
    elseif($host==='stockanalysis.com'&&$path==='/quote/krx/373220/history/')$file=$root.'/mon-resolution-stockanalysis-kr373220.html';
    if(!$file||!is_file($file))throw new RuntimeException('uncaptured source route');$calls[]=$url;return ['status'=>200,'headers'=>[],'body'=>file_get_contents($file)];
});
$daily=new MonDaily($http,new MonCollector($http,$store,$config),$config,$store);$settings=['http_timeout_seconds'=>5,'request_spacing_seconds'=>0];$sources=[];$observations=[];
$symbol=null;foreach($d->watchlist->symbols as $row)if($row->symbol_id==='US|NASDAQ|PLTR')$symbol=$row;
if(!$symbol)throw new RuntimeException('existing PLTR identity missing');
$stock=$daily->stockAnalysis($symbol,'2026-10-09',microtime(true)+20,$settings);$stock->origin->checked_at=mon_iso(filemtime($root.'/mon-resolution-stockanalysis-pltr.html'));
$index=$daily->fred($symbol,'2026-10-09',microtime(true)+20,$settings);$index->origin->checked_at=mon_iso(filemtime($root.'/mon-resolution-fred-followed.txt'));
$corroboration=$daily->nasdaqRaw($symbol,'2026-10-09',microtime(true)+20,$settings);$corroboration->origin->checked_at=mon_iso(filemtime($root.'/mon-resolution-nasdaq-pltr.json'));
$proof=MonDaily::wholeWindowProof($stock,$corroboration);$snapshot=MonDetails::snapshot($symbol,$stock->bars,$index->bars,'US|INDEX|^GSPC',$stock->origin,$index->origin);
$sources[]=(object)['symbol_id'=>$symbol->symbol_id,'snapshot'=>MonDetails::pack($snapshot),'corroborating_source'=>MonDetails::pack($corroboration)];$observations[]=(object)['symbol_id'=>$symbol->symbol_id,'status'=>'verified_complete_window','bar_count'=>count($stock->bars),'same_index_sessions'=>21,'closing_prices_corroborated'=>$proof->verified_close_count,'volume_differences'=>$proof->volume_difference_count,'metrics'=>$snapshot->metrics,'source_url'=>$stock->origin->source_url,'source_sha256'=>$stock->origin->source_record_sha256];
$benchmark=$p->series->{'JP|INDEX|TOPIX'};$benchmarkOrigin=(object)['source'=>'verified_saved_data','checked_at'=>$d->analysis->as_of,'complete_source_window'=>true];
$tickRules=MonRun::rows(mon_decode((string)file_get_contents(__DIR__.'/mon_gap_jpx_observed.json'))->symbols);
foreach($d->analysis->discovery_state->retry_queue as $sid){$code=explode('|',$sid)[2];$file=$root.'/mon-gap-sources/'.$code.'.html';$html=file_get_contents($file);
    if(!preg_match('/nameFull:("(?:[^"\\\\]|\\\\.)*")/',$html,$name))throw new RuntimeException('provider identity unavailable '.$sid);
    $symbol=(object)['symbol_id'=>$sid,'country'=>'JP','exchange'=>'TSE','symbol'=>$code,'name'=>json_decode($name[1],true),'provider_name'=>json_decode($name[1],true),'currency'=>'JPY',
        'is_held'=>false,'position'=>null,'tick_size'=>1,'tick_rule'=>'JPX_TICK_RULE_REQUIRES_VERIFICATION','purpose'=>'기존 후보의 대체 일봉 검증','candidate_origin'=>['existing'],
        'source_codes'=>(object)['kis'=>null,'naver'=>null,'yahoo'=>(object)['symbol'=>$code.'.T']]];
    $rule=mon_get($tickRules,$sid);if(!($rule instanceof stdClass))throw new RuntimeException('official tick rule unavailable '.$sid);
    $symbol->tick_rule=$rule->tick_rule;$symbol->tick_rule_evidence=$rule->tick_rule_evidence;
    $daily=new MonDaily($http,new MonCollector($http,$store,$config),$config,$store);$stock=$daily->stockAnalysis($symbol,'2026-10-09',microtime(true)+20,$settings);$stock->origin->checked_at=mon_iso(filemtime($file));
    $snapshot=MonDetails::snapshot($symbol,$stock->bars,$benchmark,'JP|INDEX|TOPIX',$stock->origin,$benchmarkOrigin);$sources[]=(object)['symbol_id'=>$sid,'snapshot'=>MonDetails::pack($snapshot)];
    $observations[]=(object)['symbol_id'=>$sid,'status'=>'verified_same_period_numeric_inputs','bar_count'=>count($stock->bars),'same_index_sessions'=>21,'bars_as_of'=>$snapshot->metrics->bars_as_of,
        'rs20_pp'=>$snapshot->metrics->RS20_pp,'technical_signals'=>$snapshot->technical->qualifying_signals,'tick_rule_status'=>'official_rules_checked_with_latest_published_membership','tick_rule'=>$symbol->tick_rule,'tick_rule_valid_until'=>$symbol->tick_rule_evidence->valid_until,'entry_plan_count'=>count($snapshot->entry_plans),'source_url'=>$stock->origin->source_url,'source_sha256'=>$stock->origin->source_record_sha256];
}
$errors=[];
foreach($d->analysis->candidate_audit as $candidate){$sid=$candidate->symbol_id;$parts=explode('|',$sid);if($parts[0]!=='US'||$sid==='US|NASDAQ|PLTR')continue;$file=$root.'/mon-gap-us-sources/'.$parts[2].'.html';
    try{$html=file_get_contents($file);if(!preg_match('/nameFull:("(?:[^"\\\\]|\\\\.)*")/',$html,$name))throw new RuntimeException('provider name missing');
        $symbol=(object)['symbol_id'=>$sid,'country'=>'US','exchange'=>$parts[1],'symbol'=>$parts[2],'name'=>json_decode($name[1],true),'currency'=>'USD','is_held'=>false,'position'=>null,'purpose'=>'기존 후보의 대체 일봉 검증','tick_size'=>.01,'tick_rule'=>'US_NMS_0.01_CURRENT','candidate_origin'=>['existing'],'source_codes'=>(object)['kis'=>null,'yahoo'=>(object)['symbol'=>$parts[2]]]];
        $daily=new MonDaily($http,new MonCollector($http,$store,$config),$config,$store);$stock=$daily->stockAnalysis($symbol,'2026-10-09',microtime(true)+20,$settings);$stock->origin->checked_at=mon_iso(filemtime($file));
        $snapshot=MonDetails::snapshot($symbol,$stock->bars,$index->bars,'US|INDEX|^GSPC',$stock->origin,$index->origin);$source=(object)['symbol_id'=>$sid,'snapshot'=>MonDetails::pack($snapshot)];
        if(is_file($root.'/mon-gap-nasdaq-sources/'.$parts[2].'.json')){$raw=$daily->nasdaqRaw($symbol,'2026-10-09',microtime(true)+20,$settings);$raw->origin->checked_at=mon_iso(filemtime($root.'/mon-gap-nasdaq-sources/'.$parts[2].'.json'));$source->corroborating_source=MonDetails::pack($raw);}
        $sources[]=$source;
        $observations[]=(object)['symbol_id'=>$sid,'status'=>'verified_same_period_numeric_inputs','bar_count'=>count($stock->bars),'same_index_sessions'=>21,'bars_as_of'=>$snapshot->metrics->bars_as_of,'rs20_pp'=>$snapshot->metrics->RS20_pp,'source_url'=>$stock->origin->source_url,'source_sha256'=>$stock->origin->source_record_sha256];
    }catch(Throwable $e){$errors[]=['symbol_id'=>$sid,'error'=>$e->getMessage()];}
}
$sid='KR|KOSPI|373220';$file=$root.'/mon-resolution-stockanalysis-kr373220.html';$symbol=$d->watchlist->detail_requests->$sid->symbol;
$daily=new MonDaily($http,new MonCollector($http,$store,$config),$config,$store);$stock=$daily->stockAnalysis($symbol,'2026-10-08',microtime(true)+20,$settings);$stock->origin->checked_at=mon_iso(filemtime($file));
$snapshot=MonDetails::snapshot($symbol,$stock->bars,$p->series->{'KR|INDEX|^KS11'},'KR|INDEX|^KS11',$stock->origin,$benchmarkOrigin);$sources[]=(object)['symbol_id'=>$sid,'snapshot'=>MonDetails::pack($snapshot)];
$observations[]=(object)['symbol_id'=>$sid,'status'=>'verified_same_period_numeric_inputs','bar_count'=>count($stock->bars),'same_index_sessions'=>21,'bars_as_of'=>$snapshot->metrics->bars_as_of,'rs20_pp'=>$snapshot->metrics->RS20_pp,'source_url'=>$stock->origin->source_url,'source_sha256'=>$stock->origin->source_record_sha256];
unset($p,$daily,$stock,$snapshot,$html,$raw,$corroboration,$source);
$at=mon_iso();$bundle=MonGaps::stage($d,$sources,$at);$staged=$bundle['data'];$context=(object)['source_blob_sha'=>MonRun::gitSha(MonRun::json($staged)),'rendered_at'=>$at,'countries'=>['KR','US','JP'],'important_ids'=>[]];
$rejected=MonRun::rows($bundle['validation']['rejected']);foreach($observations as $observation)if(isset($rejected[$observation->symbol_id])){$observation->status='quarantined_needs_additional_source_proof';$observation->source_validation_error=$rejected[$observation->symbol_id]->error;}
$originalAsOf=$d->analysis->as_of;unset($d);
$input=MonDetails::inputs($staged,$context);$material=MonRun::payload($input);$resolved=0;
foreach($sources as $source){$sid=$source->symbol_id;if(count($material->series->$sid)>=21&&mon_get($material->detail_imports->$sid,'conflict')===false)$resolved++;}
unset($material,$sources);$prepared=MonSwing::prepare($input,$context);
$report=['kind'=>'captured_public_response_native_reader_and_preparation_validation','processor_version'=>MON_VERSION,'php_version'=>PHP_VERSION,'checked_at'=>$at,'native_validation_seconds'=>round(mon_monotonic()-$started,6),
    'replayed_source_count'=>count($calls),'live_requests_from_php_probe'=>0,'orders'=>0,'resolved_numeric_input_count'=>$resolved,'captured_candidate_count'=>count($observations),'staged_source_count'=>$bundle['validation']['staged_count'],
    'analysis_as_of'=>$originalAsOf,'new_selection_performed'=>false,'market_execution_statistics_modified'=>false,'validation'=>$bundle['validation'],'prepared_counts'=>$prepared['counts'],
    'source_errors'=>$errors,'actual_sources'=>$observations,'peak_memory_bytes'=>memory_get_peak_usage(true),'official_JP_tick_rule_count'=>count($tickRules),'remaining'=>['NAS_v1.8.3_deployment','historical_new_identity_goal_20_per_country_not_backfilled','current_entry_price_trading_and_issuer_risk','JP_tick_rules_recheck_before_2026-10-30']];
if($stage)foreach($bundle['files']as $file)if(file_put_contents(dirname(__DIR__).'/'.$file['path'],$file['content'])!==strlen($file['content']))throw new RuntimeException('stage file write failed');
file_put_contents(__DIR__.'/mon_gap_observed.json',mon_json($report));echo mon_json(['staged'=>$stage,'source_count'=>count($observations),'resolved_numeric_input_count'=>$resolved,'validation'=>$bundle['validation'],'source_errors'=>$errors,'prepared_counts'=>$prepared['counts'],'peak_memory_bytes'=>$report['peak_memory_bytes'],'native_validation_seconds'=>$report['native_validation_seconds']]);

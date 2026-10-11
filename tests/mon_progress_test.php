<?php
declare(strict_types=1);
require __DIR__.'/mon_progress_fixture.php';
$cases=[];$dirs=[];$measure=[];
function check_progress(string $name,bool $pass): void {global $cases;$cases[]=['case'=>$name,'pass'=>$pass];if(!$pass)throw new RuntimeException($name);}
function progress_dir(): string {global $dirs;$p=sys_get_temp_dir().'/mon-progress-'.bin2hex(random_bytes(6));mkdir($p,0700);$dirs[]=$p;return $p;}
function progress_clean(string $p): void {foreach(scandir($p)as $f)if($f!=='.'&&$f!=='..'){if(is_dir($p.'/'.$f))progress_clean($p.'/'.$f);else unlink($p.'/'.$f);}rmdir($p);}
function worker_progress($d,MonStore $store,MonConfig $cfg,?callable $transport=null): MonDetailWorker {
    $http=new MonHttp(null,$transport?:static function(){throw new RuntimeException('unexpected provider request');});
    return new MonDetailWorker(new MonDaily($http,new MonCollector($http,$store,$cfg),$cfg),$store);
}
try {
    $d=progress_fixture();$sid=$d->watchlist->symbols[0]->symbol_id;MonData::validate($d);$settings=MonData::settings($d->watchlist);
    $ctx=(object)['source_blob_sha'=>str_repeat('a',40),'rendered_at'=>mon_iso(),'countries'=>['KR']];$items=[];
    foreach($d->watchlist->symbols as $i=>$symbol)$items[]=(object)['symbol'=>$symbol,'request_id'=>'bulk-'.$i,'wait_seconds'=>100];
    $bulk=MonDetails::registerMany($d,$ctx,[$items[0],(object)['request_id'=>'bad'],$items[1],$items[0]]);
    check_progress('bulk transport preserves two valid independent requests despite invalid item',$bulk['write_required']&&count($bulk['files'])===1&&$bulk['requests'][0]['status']==='queued'&&$bulk['requests'][1]['status']==='rejected'&&$bulk['requests'][2]['status']==='queued');
    check_progress('duplicate symbol within bulk call is rejected without undoing valid items',$bulk['requests'][3]['error']==='DETAIL_SYMBOL_DUPLICATE'&&$bulk['validation']['collection_preserved']&&$bulk['validation']['analysis_preserved']);
    $registered=mon_decode($bulk['files'][0]['content']);$bulkReuse=MonDetails::registerMany($registered,$ctx,[$items[0],$items[1]]);
    check_progress('bulk re-transmission reuses IDs and returns no repeated full file',!$bulkReuse['write_required']&&$bulkReuse['files']===[]);
    check_progress('PHP 7.4-compatible default caps are bounded',MON_VERSION==='1.5.0'&&$settings['detail_symbol_budget_seconds']===8.0&&$settings['detail_jobs_per_tick']===3);
    $settings['request_spacing_seconds']=0;$dir=progress_dir();$store=new MonStore($dir);$cfg=progress_test_config($dir);
    $before=MonRun::json($d->analysis);$trace=[];$clock=microtime(true);
    $work=worker_progress($d,$store,$cfg)->processAvailable($d,microtime(true)+5,$settings,static function($current,$job)use(&$trace){$trace[]=MonProgress::build($current,time())['counts'];});
    $measure['three_cached_jobs_seconds']=microtime(true)-$clock;
    check_progress('three cached symbols finish in one slice without provider calls',count($work['jobs'])===3&&$measure['three_cached_jobs_seconds']<1);
    check_progress('first symbol is observable before remaining two complete',$trace[0]['done']===1&&$trace[0]['pending']===2&&$trace[2]['done']===3);
    check_progress('cached source verification time is preserved',$work['jobs'][0]['response']->source_checked_at===$d->_test_snapshots->$sid->history_metadata->checked_at);
    check_progress('worker does not change ChatGPT analysis',MonRun::json($work['data']->analysis)===$before);
    $s=$work['data']->_test_snapshots->$sid;$r=$work['jobs'][0]['response'];
    $verified=MonDetails::checkedSnapshot($d->watchlist->detail_requests->$sid,$r);
    check_progress('numeric snapshot and MACD input remain unchanged',MonRun::json($verified->metrics)===MonRun::json($s->metrics)&&MonRun::json($verified->technical)===MonRun::json($s->technical)&&MonRun::json($verified->bars)===MonRun::json($s->bars));
    $empty=worker_progress($d,$store,$cfg)->processAvailable($d,microtime(true)+5,$settings);
    check_progress('already completed requests are not processed again',count($empty['jobs'])===0);
    $fresh=progress_fixture();$limited=$settings;$limited['detail_jobs_per_tick']=2;
    $part=worker_progress($fresh,$store,$cfg)->processAvailable($fresh,microtime(true)+5,$limited);
    check_progress('job cap leaves an independent unfinished request',count($part['jobs'])===2&&MonProgress::build($part['data'],time())['counts']['pending']===1);
    $resume=worker_progress($part['data'],$store,$cfg)->processAvailable($part['data'],microtime(true)+5,$settings);
    check_progress('next slice resumes only the remaining request',count($resume['jobs'])===1);
    $slow=progress_fixture();$slow->watchlist->detail_requests->$sid->force_refresh=true;$slow->watchlist->detail_requests->$sid->request_hash=MonDetails::requestHash($slow->watchlist->detail_requests->$sid);
    $timeouts=[];$soft=$settings;$soft['detail_symbol_budget_seconds']=1;
    $transport=static function($method,$url,$headers,$body,$timeout)use(&$timeouts){$timeouts[]=$timeout;usleep(75000);throw new MonFault('HTTP_TRANSPORT_28');};
    $mixed=worker_progress($slow,$store,$cfg,$transport)->processAvailable($slow,microtime(true)+4,$soft);
    check_progress('slow first symbol does not block later cached symbols',count($mixed['jobs'])===3&&$mixed['jobs'][0]['response']->state==='retry_wait'&&$mixed['jobs'][2]['response']->data_status==='reused_current');
    check_progress('provider timeout is limited by per-symbol budget',count($timeouts)>0&&max($timeouts)<=1.01);
    check_progress('failed fresh fetch retains previous verified data and its time',$mixed['jobs'][0]['response']->data_status==='previous_verified'&&$mixed['jobs'][0]['response']->source_checked_at===$s->history_metadata->checked_at);
    $attempted=$mixed['jobs'][0]['response']->attempts;$again=worker_progress($slow,$store,$cfg,$transport)->processAvailable($slow,microtime(true)+4,$soft);
    check_progress('retry wait does not repeat failed source immediately',!$again['changed']&&$slow->collection->detail_responses->$sid->attempts===$attempted);
    $request=$slow->watchlist->detail_requests->$sid;$request->deadline_at=mon_iso();$request->request_hash=MonDetails::requestHash($request);
    $old=$slow->collection->detail_responses->$sid;$old->request_hash=$request->request_hash;$old->retry_after=time()+100;
    $done=worker_progress($slow,$store,$cfg)->processOne($slow,microtime(true)+2,$settings);
    check_progress('expired retry becomes done without waiting for retry clock',$done['response']->state==='done'&&$done['response']->error==='DETAIL_WAIT_DEADLINE'&&$done['response']->attempts===$attempted);
    $slow->collection->detail_responses=$done['responses'];$g=MonProgress::build($slow,time());
    check_progress('completed timeout reports collection completion with limitation',$g['phase']==='collection_complete'&&$g['counts']['timed_out']===1&&$g['final_decision_complete']===false);
    $g0=MonProgress::build($work['data'],time());$g1=MonProgress::build($work['data'],time()+1,$g0['snapshot_token']);
    check_progress('unchanged progress returns counts with no repeated card body',!$g1['changed']&&$g1['cards']===[]&&$g0['snapshot_token']===$g1['snapshot_token']);
    $noise=clone $work['data'];$noise->collection=clone $noise->collection;$noise->collection->collection_id='new-quote-tick';
    check_progress('quote-only change does not retrigger detail cards',MonProgress::build($noise,time())['snapshot_token']===$g0['snapshot_token']);
    $bad=progress_fixture();$bad->watchlist->detail_requests->$sid->request_hash=str_repeat('0',64);
    check_progress('bad request is isolated in progress',MonProgress::build($bad,time())['counts']['invalid']===1);
    $stale=progress_fixture();$stale->collection->detail_responses->$sid=progress_response($stale,$sid);$stale->collection->detail_responses->$sid->request_id='obsolete';
    check_progress('old request response is not counted as completed',MonProgress::build($stale,time())['counts']['done']===0);
    $corrupt=progress_fixture();$corrupt->analysis->input_snapshot->lossless_payload=(object)['sha256'=>'broken'];
    check_progress('thin progress does not decode full history or claim validated signals',MonProgress::build($corrupt,time())['full_snapshot_validation_required']===true);
    $meta=progress_fixture();$meta->collection->detail_responses->$sid=progress_response($meta,$sid);$meta->collection->detail_responses->$sid->payload->sha256=str_repeat('0',64);
    $ctx=(object)['source_blob_sha'=>str_repeat('a',40),'rendered_at'=>mon_iso(),'countries'=>['KR']];$view=MonDetails::view($meta,$ctx);
    check_progress('final detail view still rejects damaged snapshot despite producer progress',$view['cards'][0]->state==='invalid_response'&&$view['cards'][0]->usable===false);
    $cached=progress_fixture();$cached->_mon_cache=(object)['blob_sha'=>str_repeat('a',40),'fetched_at'=>mon_iso()];$pending=(object)['collection'=>(object)['detail_responses'=>(object)[$sid=>progress_response($cached,$sid)]]];
    $serialized=MonRun::json($cached);$local=MonProgress::cached($cached,$pending);
    check_progress('locally checkpointed result is visible before GitHub publication',$local['counts']['done']===1&&$local['publication']==='pending'&&$local['cards'][0]->publication==='pending');
    check_progress('reading local progress does not mutate remote cache',MonRun::json($cached)===$serialized);
    $base=progress_fixture();$proposal=(object)['_detail_only'=>true,'detail_responses'=>(object)[$sid=>progress_response($base,$sid)]];$calls=[];$published=null;
    $http=new MonHttp(null,static function($method,$url,$headers,$body)use(&$calls,&$published,$base){$calls[]=$method;if($method==='GET')return ['status'=>200,'headers'=>[],'body'=>mon_json($base,false)];$published=mon_decode(base64_decode(mon_decode($body)->content));return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['content'=>(object)['sha'=>MonRun::gitSha(mon_json($published,false))]])];});
    $gh=new MonGitHub($http,$cfg);$known=['sha'=>MonRun::gitSha(mon_json($base,false)),'data'=>$base];$baseText=MonRun::json($base);
    $saved=$gh->publish($proposal,MonData::signature($base->watchlist),microtime(true)+3,$settings,$known);
    check_progress('known exact blob permits one optimistic PUT without another full GET',$calls===['PUT']&&$saved['written']);
    check_progress('optimistic write preserves MON-owned watchlist and analysis',MonRun::json($published->watchlist)===MonRun::json($base->watchlist)&&MonRun::json($published->analysis)===MonRun::json($base->analysis));
    check_progress('known source stays immutable after write',MonRun::json($base)===$baseText);
    $calls=[];$savedAgain=$gh->publish($proposal,MonData::signature($published->watchlist),microtime(true)+3,$settings,['sha'=>$saved['sha'],'data'=>$published]);
    check_progress('identical publication is skipped without a commit or full read',$calls===[]&&!$savedAgain['written']);
    $new=mon_decode(MonRun::json($base));$new->watchlist->detail_requests->$sid->request_id='replacement';$new->watchlist->detail_requests->$sid->request_hash=MonDetails::requestHash($new->watchlist->detail_requests->$sid);
    $new->analysis->concurrent_marker='preserve';$new->collection->completed_at=mon_iso(time()+1);$new->collection->quotes=[(object)['symbol_id'=>$sid,'marker'=>'newer quote']];
    $withQuote=clone $proposal;$withQuote->quote_collection=clone $base->collection;$putCount=0;$getCount=0;$lastWrite=null;
    $conflictHttp=new MonHttp(null,static function($method,$url,$headers,$body)use($new,&$putCount,&$getCount,&$lastWrite){
        if($method==='GET'){$getCount++;return ['status'=>200,'headers'=>[],'body'=>mon_json($new,false)];}
        $putCount++;if($putCount===1)return ['status'=>409,'headers'=>[],'body'=>'{}'];$lastWrite=mon_decode(base64_decode(mon_decode($body)->content));return ['status'=>200,'headers'=>[],'body'=>'{"content":{"sha":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}}'];
    });
    (new MonGitHub($conflictHttp,$cfg))->publish($withQuote,MonData::signature($base->watchlist),microtime(true)+3,$settings,$known);
    check_progress('conflict re-reads once and re-merges original detail proposal',$putCount===1&&$getCount===1&&$lastWrite===null);
    // Replacement made the proposal obsolete; it is now a no-op, preserving the newer quote and analysis.
    $new2=mon_decode(MonRun::json($new));$other=$base->watchlist->symbols[1]->symbol_id;$proposal->detail_responses->$other=progress_response($base,$other);$putCount=0;$getCount=0;
    (new MonGitHub($conflictHttp,$cfg))->publish($proposal,MonData::signature($base->watchlist),microtime(true)+3,$settings,$known);
    check_progress('conflict retains current response but drops replaced request',$putCount===2&&$getCount===1&&isset($lastWrite->collection->detail_responses->$other)&&!isset($lastWrite->collection->detail_responses->$sid));
    check_progress('conflict preserves newer quote and concurrent ChatGPT decision',MonRun::json($lastWrite->collection->quotes)===MonRun::json($new->collection->quotes)&&$lastWrite->analysis->concurrent_marker==='preserve');
    $large=progress_fixture();$large->analysis->fixture_large_archive=str_repeat('x',1200000);$largeProposal=(object)['_detail_only'=>true,'detail_responses'=>(object)[$sid=>progress_response($large,$sid)]];$largeMethods=[];$largeBytes=0;
    $largeHttp=new MonHttp(null,static function($method,$url,$headers,$body)use(&$largeMethods,&$largeBytes){$largeMethods[]=$method;$largeBytes=strlen(base64_decode(mon_decode($body)->content));return ['status'=>200,'headers'=>[],'body'=>'{"content":{"sha":"cccccccccccccccccccccccccccccccccccccccc"}}'];});
    $largeSaved=(new MonGitHub($largeHttp,$cfg))->publish($largeProposal,MonData::signature($large->watchlist),microtime(true)+3,$settings,['sha'=>MonRun::gitSha(mon_json($large,false)),'data'=>$large]);
    check_progress('above-1-MiB source still uses one PUT and preserves complete archive',$largeBytes>1048576&&$largeMethods===['PUT']&&strlen($largeSaved['data']->analysis->fixture_large_archive)===1200000);
    $small=MonProgress::build($large,time());$measure['large_source_bytes']=$largeBytes;$measure['thin_progress_bytes']=strlen(mon_json($small,false));
    check_progress('thin progress excludes Base64 and archive and stays below 12 KiB',$measure['thin_progress_bytes']<12288&&strpos(mon_json($small,false),'gzip+base64')===false);
    $daemonStore=new MonStore(progress_dir());$daemonCfg=progress_test_config($daemonStore->dir);$remote=progress_fixture();$daemonCalls=[];
    $daemonHttp=new MonHttp(null,static function($method,$url,$headers,$body)use(&$remote,&$daemonCalls){
        $daemonCalls[]=$method;if(strpos($url,'api.github.com')===false)throw new RuntimeException('no provider call expected');
        if($method==='GET')return ['status'=>200,'headers'=>[],'body'=>mon_json($remote,false)];
        $remote=mon_decode(base64_decode(mon_decode($body)->content));return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['content'=>(object)['sha'=>MonRun::gitSha(mon_json($remote,false))]])];
    });
    $daemon=new MonDaemon($daemonStore,$daemonStore->dir.'/fixture.env',str_repeat('a',24));$rc=new ReflectionClass($daemon);$prop=$rc->getProperty('http');$prop->setAccessible(true);$prop->setValue($daemon,$daemonHttp);$tick=$rc->getMethod('tick');$tick->setAccessible(true);$tick->invoke($daemon);
    $status=$daemonStore->read('status.json');$measure['slice_http_calls']=$daemonCalls;
    check_progress('integrated slice finishes three jobs with one GET and two grouped PUTs',$status->detail_counts->done===3&&$daemonCalls===['GET','PUT','PUT']);
    check_progress('integrated slice exposes separate measured stages',isset($status->stage_seconds->source_read,$status->stage_seconds->detail_work,$status->stage_seconds->detail_publish));
    check_progress('integrated checkpoint is cleared only after successful publication',!is_file($daemonStore->path('detail_pending.json'))&&MonProgress::cached($daemonStore->read('remote_cache.json'),null)['publication']==='stored');
    $failStore=new MonStore(progress_dir());progress_test_config($failStore->dir);$failSource=progress_fixture();$failPuts=0;
    $failHttp=new MonHttp(null,static function($method,$url,$headers,$body)use($failSource,&$failPuts){if($method==='GET')return ['status'=>200,'headers'=>[],'body'=>mon_json($failSource,false)];$failPuts++;throw new MonFault('HTTP_TRANSPORT_28');});
    $failDaemon=new MonDaemon($failStore,$failStore->dir.'/fixture.env',str_repeat('b',24));$prop->setValue($failDaemon,$failHttp);$tick->invoke($failDaemon);
    $failedPending=$failStore->read('detail_pending.json');$failedProgress=MonProgress::cached($failStore->read('remote_cache.json'),$failedPending);
    check_progress('publication failure preserves all three local symbol checkpoints',$failedProgress['counts']['done']===3&&$failedProgress['publication']==='pending'&&$failStore->read('status.json')->activity_state==='detail_publish_pending');
    check_progress('failed publication is not hammered again within the same slice',$failPuts===1);
    $missing=progress_fixture(1);$missing->analysis->input_snapshot->lossless_payload=MonRun::pack(new stdClass(),'gzip+base64');$missing->watchlist->detail_requests->$sid->deadline_at=mon_iso();$missing->watchlist->detail_requests->$sid->request_hash=MonDetails::requestHash($missing->watchlist->detail_requests->$sid);
    $noData=worker_progress($missing,new MonStore(progress_dir()),$cfg)->processAvailable($missing,microtime(true)+2,$settings);
    check_progress('expired request without prior data returns an explicit result',count($noData['jobs'])===1&&$noData['jobs'][0]['response']->data_status==='no_data'&&$noData['jobs'][0]['response']->usable===false);
    echo mon_json(['php_version'=>PHP_VERSION,'cases_total'=>count($cases),'passed'=>count($cases),'failed'=>0,'external_API_calls'=>false,'measurements'=>$measure,'cases'=>$cases]);
} finally {foreach($dirs as $dir)progress_clean($dir);}

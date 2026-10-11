<?php
declare(strict_types=1);
require __DIR__.'/mon_reinforcement_fixture.php';
// New recovery paths only. Synthetic identities/prices/credentials; no external requests or orders.
$cases=[];$dirs=[];$metrics=[];$start=mon_monotonic();
function rc_assert(bool $ok,string $why='assertion'): void {if(!$ok)throw new RuntimeException($why);}
function rc_fault(callable $fn,string $code): void {try{$fn();}catch(MonFault $e){rc_assert($e->faultCode===$code,$e->faultCode);return;}throw new RuntimeException('missing '.$code);}
function rc_case(string $name,callable $fn): void {global $cases;MonExecution::$active=null;MonExecution::$store=null;MonPreparation::reset();
    try{$fn();$cases[]=['case'=>$name,'pass'=>true];}catch(Throwable $e){$cases[]=['case'=>$name,'pass'=>false,'error'=>$e->getMessage()];}}
function rc_dir(): string {global $dirs;$dir=sys_get_temp_dir().'/mon-recovery-'.bin2hex(random_bytes(8));mkdir($dir,0700);$dirs[]=$dir;return $dir;}
function rc_clean(string $dir): void {foreach(scandir($dir)as $f)if($f!=='.'&&$f!=='..'){if(is_dir($dir.'/'.$f))rc_clean($dir.'/'.$f);else unlink($dir.'/'.$f);}rmdir($dir);}
function rc_saved(int $count=1): stdClass {
    $d=reinforcement_fixture($count);$c=reinforcement_context($d);$decision=reinforcement_decision($d,$c);
    return mon_decode(MonDetails::finalize($d,$decision)['files'][0]['content']);
}
function rc_chart($d,string $ticker,bool $index): array {
    $s=$d->watchlist->symbols[0];$snapshot=$d->_test_snapshots->{$s->symbol_id};$bars=$index?$snapshot->benchmark_bars:$snapshot->bars;
    $q=['open'=>[],'high'=>[],'low'=>[],'close'=>[],'volume'=>[]];$timestamps=[];
    foreach($bars as $bar){$timestamps[]=(new DateTimeImmutable($bar[0].'T12:00:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();
        foreach(['open','high','low','close','volume']as $i=>$field)$q[$field][]=$bar[$i+1];}
    return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['chart'=>(object)['error'=>null,'result'=>[(object)[
        'meta'=>(object)['symbol'=>$ticker,'exchangeTimezoneName'=>'Asia/Seoul','exchangeName'=>'KSC','currency'=>'KRW'],
        'timestamp'=>$timestamps,'indicators'=>(object)['quote'=>[(object)$q]]]]]],false)];
}
function rc_daily($d,MonStore $store,callable $transport): MonDaily {
    $cfg=progress_test_config($store->dir);$http=new MonHttp(null,$transport);
    return new MonDaily($http,new MonCollector($http,$store,$cfg),$cfg,$store);
}

rc_case('initial view needs no lossless payload decoding or indicator preparation',function()use(&$metrics){
    $d=rc_saved();$asOf=$d->analysis->as_of;$d->analysis->input_snapshot->lossless_payload->data='deliberately-unreadable';MonPreparation::reset();
    $t=mon_monotonic();$v=MonBootstrap::build($d,mon_iso());$metrics['initial_projection_ms']=round((mon_monotonic()-$t)*1000,3);
    rc_assert(count($v['cards'])===1&&MonPreparation::$builds===0);rc_assert($v['header']['analysis_as_of']===$asOf&&!$v['header']['new_analysis_complete']&&!$v['header']['write_required']);
    rc_assert($v['cards'][0]->readiness!=='ready');
});
rc_case('missing source still returns a final needs-data result',function(){
    $v=MonBootstrap::build(null,mon_iso());rc_assert($v['header']['state']==='needs_data'&&$v['cards']===[]);rc_assert(strpos(MonBootstrap::summary($v,'MON_DECISION_DEADLINE'),'자료 부족')!==false);
});
rc_case('initial GET projection does not modify its cache or create private state',function(){
    $store=new MonStore(rc_dir());$d=rc_saved();$store->write('remote_cache.json',$d);$before=file_get_contents($store->path('remote_cache.json'));$files=scandir($store->dir);
    $v=MonBootstrap::fromStore($store);rc_assert(count($v['cards'])===1);rc_assert(file_get_contents($store->path('remote_cache.json'))===$before&&scandir($store->dir)===$files);
});
rc_case('corrupted saved source is reported without a guessed selection',function(){
    $d=rc_saved();$d->analysis->result_fingerprint='invalid';$v=MonBootstrap::build($d,mon_iso());rc_assert(!$v['cards']&&$v['header']['source_error']==='MON_FACT_HASH_INVALID');
});
rc_case('initial pages keep all 30 old rows under the response limit',function()use(&$metrics){
    $d=reinforcement_fixture(30);$first=rc_saved()->analysis->results[0];$d->analysis->results=[];
    foreach($d->watchlist->symbols as $s){$r=clone $first;$r->symbol_id=$s->symbol_id;$r->name=$s->name;$r->action='REFERENCE_REVIEW';$d->analysis->results[]=$r;}
    $at=mon_iso();$v=MonBootstrap::build($d,$at);$token=$v['header']['snapshot_token'];$seen=[];$n=0;
    do{rc_assert(strlen(mon_json(['ok'=>true,'result'=>$v],false))<=12288,'page too large');foreach($v['cards']as $r)$seen[]=$r->symbol_id;$n++;$next=$v['page']['next_cursor'];if($next!==null)$v=MonBootstrap::build($d,$at,null,$next,$token);}while($next!==null);
    rc_assert(count($seen)===30&&count(array_unique($seen))===30,'rows='.count($seen).' unique='.count(array_unique($seen)));$metrics['initial_pages_30_rows']=$n;
});
rc_case('initial pagination rejects a mixed snapshot',function(){
    $d=rc_saved();rc_fault(static function()use($d){MonBootstrap::build($d,mon_iso(),null,1,str_repeat('0',64));},'MON_BOOTSTRAP_CURSOR_INVALID');
});
rc_case('old replay plus no-change never means new analysis completed',function(){
    $store=new MonStore(rc_dir());$b=MonExecution::start($store,(object)['run_id'=>'old-replay','received_at'=>mon_iso()]);
    MonExecution::event('fallback',['final_available'=>true,'save_status'=>'no_change','analysis_origin'=>'previous_saved_result']);
    $v=MonExecution::view($b);rc_assert(!$v['new_analysis_complete']&&!$v['fully_complete']);
});
rc_case('startup after 259 seconds keeps the original expired budget and old result',function(){
    $store=new MonStore(rc_dir());$d=rc_saved();$b=MonExecution::start($store,(object)['run_id'=>'delayed','received_at'=>mon_iso(time()-259)]);
    $v=MonExecution::view($b);rc_assert($v['timing']['deadline_missed']&&$v['next_action']==='display_secured_result'&&$v['remaining_seconds']['decision']===0.0);
    $old=MonBootstrap::build($d,mon_iso());rc_assert(count($old['cards'])===1&&$old['header']['analysis_as_of']===$d->analysis->as_of);
});
rc_case('direct fixed prepare cannot bypass a expired execution budget',function(){
    $d=reinforcement_fixture();$c=reinforcement_context($d);MonExecution::start(new MonStore(rc_dir()),(object)['run_id'=>'direct-prepare','received_at'=>mon_iso(time()-111)]);
    rc_fault(static function()use($d,$c){MonDetails::prepare($d,$c);},'MON_DECISION_DEADLINE');rc_assert(MonPreparation::$builds===0);
});
rc_case('direct fixed finalize cannot bypass a expired execution budget',function(){
    $d=reinforcement_fixture();$decision=reinforcement_decision($d,reinforcement_context($d));MonExecution::start(new MonStore(rc_dir()),(object)['run_id'=>'direct-finalize','received_at'=>mon_iso(time()-111)]);
    rc_fault(static function()use($d,$decision){MonDetails::finalize($d,$decision);},'MON_DECISION_DEADLINE');
});
rc_case('known analysis completion remains separate from pending publication',function(){
    $b=MonExecution::start(new MonStore(rc_dir()),(object)['run_id'=>'fresh','received_at'=>mon_iso()]);
    MonExecution::event('prepared',['new_analysis_complete'=>true,'final_available'=>true,'save_status'=>'queued']);rc_assert(!MonExecution::view($b)['fully_complete']);
    MonExecution::event('published',['save_status'=>'saved']);rc_assert(MonExecution::view($b)['fully_complete']);
});
rc_case('network failure skips same provider but permits a different origin',function(){
    $calls=[];$http=new MonHttp(null,static function($m,$url,$h,$b,$t)use(&$calls){$calls[]=$url;if(strpos($url,'query1.')!==false)throw new MonFault('HTTP_TRANSPORT_7');return ['status'=>200,'headers'=>[],'body'=>'{}'];});
    rc_fault(static function()use($http){$http->request('GET','https://query1.finance.yahoo.com/a',[],null,microtime(true)+5);},'HTTP_TRANSPORT_7');
    rc_fault(static function()use($http){$http->request('GET','https://query1.finance.yahoo.com/b',[],null,microtime(true)+5,5,10);},'HTTP_CIRCUIT_OPEN');
    $http->request('GET','https://m.stock.naver.com/a',[],null,microtime(true)+5);rc_assert(count($calls)===2);
});
rc_case('a single slow symbol does not suppress another healthy symbol at the same provider',function(){
    $calls=[];$http=new MonHttp(null,static function($m,$url)use(&$calls){$calls[]=$url;if(substr($url,-2)==='/a')throw new MonFault('HTTP_TRANSPORT_28');return ['status'=>200,'headers'=>[],'body'=>'{}'];});
    rc_fault(static function()use($http){$http->request('GET','https://query1.finance.yahoo.com/a',[],null,microtime(true)+5);},'HTTP_TRANSPORT_28');
    rc_fault(static function()use($http){$http->request('GET','https://query1.finance.yahoo.com/a',[],null,microtime(true)+5);},'HTTP_CIRCUIT_OPEN');
    $http->request('GET','https://query1.finance.yahoo.com/b',[],null,microtime(true)+5);rc_assert(count($calls)===2);
});
rc_case('distinct timeout evidence opens a shared provider cooldown',function(){
    $calls=0;$http=new MonHttp(null,static function()use(&$calls){$calls++;throw new MonFault('HTTP_TRANSPORT_28');});
    foreach(['a','b']as $path)rc_fault(static function()use($http,$path){$http->request('GET','https://query1.finance.yahoo.com/'.$path,[],null,microtime(true)+5);},'HTTP_TRANSPORT_28');
    rc_fault(static function()use($http){$http->request('GET','https://query1.finance.yahoo.com/c',[],null,microtime(true)+5);},'HTTP_CIRCUIT_OPEN');rc_assert($calls===2);
});
rc_case('cooldown expires without sleeping or extending the request deadline',function(){
    $wall=microtime(true);$mono=10.0;$calls=0;$http=new MonHttp(null,static function()use(&$calls){$calls++;if($calls===1)throw new MonFault('HTTP_TRANSPORT_7');return ['status'=>200,'headers'=>[],'body'=>'{}'];},static function()use(&$wall){return $wall;},static function()use(&$mono){return $mono;});
    rc_fault(static function()use($http,$wall){$http->request('GET','https://query1.finance.yahoo.com/a',[],null,$wall+5);},'HTTP_TRANSPORT_7');
    $wall+=31;$mono+=31;$http->request('GET','https://query1.finance.yahoo.com/a',[],null,$wall+5);rc_assert($calls===2);
});
rc_case('HTTP 404 for one symbol never opens a provider-wide breaker',function(){
    $calls=0;$http=new MonHttp(null,static function()use(&$calls){$calls++;return ['status'=>404,'headers'=>[],'body'=>'{}'];});
    $http->request('GET','https://query1.finance.yahoo.com/missing',[],null,microtime(true)+5);$http->request('GET','https://query1.finance.yahoo.com/other',[],null,microtime(true)+5);rc_assert($calls===2);
});
rc_case('Retry-After is honored by the provider cooldown',function(){
    $http=new MonHttp(null,static function(){return ['status'=>429,'headers'=>['retry-after'=>'90'],'body'=>'{}'];});
    $http->request('GET','https://query1.finance.yahoo.com/a',[],null,microtime(true)+5);
    try{$http->request('GET','https://query1.finance.yahoo.com/b',[],null,microtime(true)+5);}catch(MonFault $e){rc_assert($e->faultCode==='HTTP_CIRCUIT_OPEN'&&$e->retryAfter>=89);return;}throw new RuntimeException('missing circuit');
});
rc_case('Git confirmation requests are not blocked by the market-data breaker',function(){
    $calls=0;$http=new MonHttp(null,static function()use(&$calls){$calls++;throw new MonFault('HTTP_TRANSPORT_28');});
    for($i=0;$i<2;$i++)rc_fault(static function()use($http){$http->request('GET','https://api.github.com/repos/test/a',[],null,microtime(true)+5);},'HTTP_TRANSPORT_28');rc_assert($calls===2);
});
rc_case('KIS timeout leaves time for Yahoo stock and the exact benchmark',function()use(&$metrics){
    $d=reinforcement_fixture();$store=new MonStore(rc_dir());$calls=[];
    $daily=rc_daily($d,$store,static function($m,$url,$h,$b,$timeout)use($d,&$calls){$calls[]=[$url,$timeout];if(strpos($url,'koreainvestment')!==false)throw new MonFault('HTTP_TRANSPORT_28');$ticker=rawurldecode(basename(parse_url($url,PHP_URL_PATH)));return rc_chart($d,$ticker,$ticker==='^KS11');});
    $r=$d->watchlist->detail_requests->{$d->watchlist->symbols[0]->symbol_id};$result=$daily->fetch($r,null,microtime(true)+8,MonData::settings($d->watchlist));
    rc_assert(count($calls)===3&&$calls[0][1]<2.5&&$calls[1][1]<=5.2&&$calls[2][1]>0);rc_assert($result->benchmark_id==='KR|INDEX|^KS11');$metrics['provider_timeouts_seconds']=array_column($calls,1);
});
rc_case('benchmark failure preserves stock and resumes without downloading it again',function(){
    $d=reinforcement_fixture();$store=new MonStore(rc_dir());$r=$d->watchlist->detail_requests->{$d->watchlist->symbols[0]->symbol_id};$stockCalls=0;
    $first=rc_daily($d,$store,static function($m,$url)use($d,&$stockCalls){if(strpos($url,'koreainvestment')!==false)throw new MonFault('HTTP_TRANSPORT_28');$ticker=rawurldecode(basename(parse_url($url,PHP_URL_PATH)));if($ticker==='^KS11')return ['status'=>503,'headers'=>[],'body'=>'{}'];$stockCalls++;return rc_chart($d,$ticker,false);});
    rc_fault(static function()use($first,$r,$d){$first->fetch($r,null,microtime(true)+8,MonData::settings($d->watchlist));},'HTTP_503');
    rc_assert($first->partialFields->recovery->stock_checkpoint_saved&&!$first->partialFields->stock->current_signal_verified);$checked=$first->partialFields->stock->source_checked_at;
    $second=rc_daily($d,$store,static function($m,$url)use($d,&$stockCalls){if(strpos($url,'koreainvestment')!==false)throw new MonFault('HTTP_TRANSPORT_28');$ticker=rawurldecode(basename(parse_url($url,PHP_URL_PATH)));if($ticker!=='^KS11')$stockCalls++;return rc_chart($d,$ticker,$ticker==='^KS11');});
    $s=$second->fetch($r,null,microtime(true)+8,MonData::settings($d->watchlist));rc_assert($stockCalls===1&&$second->partialFields->recovery->stock_checkpoint_reused&&$s->origin->checked_at===$checked);
});
foreach(['hash','request','expiry']as $variant)rc_case('checkpoint '.$variant.' mismatch triggers fresh stock verification',function()use($variant){
    $d=reinforcement_fixture();$store=new MonStore(rc_dir());$r=$d->watchlist->detail_requests->{$d->watchlist->symbols[0]->symbol_id};$calls=0;
    $transport=static function($m,$url)use($d,&$calls){if(strpos($url,'koreainvestment')!==false)throw new MonFault('HTTP_TRANSPORT_28');$ticker=rawurldecode(basename(parse_url($url,PHP_URL_PATH)));if($ticker!=='^KS11')$calls++;return rc_chart($d,$ticker,$ticker==='^KS11');};
    rc_daily($d,$store,$transport)->fetch($r,null,microtime(true)+8,MonData::settings($d->watchlist));$name='stock-checkpoint-'.hash('sha256',$r->symbol_id).'.php';$row=$store->readPrivate($name);
    if($variant==='hash')$row->sha256=str_repeat('0',64);elseif($variant==='request')$row->request_hash=str_repeat('0',64);else $row->expires_at=mon_iso(time()-1);$store->writePrivate($name,$row);
    rc_daily($d,$store,$transport)->fetch($r,null,microtime(true)+8,MonData::settings($d->watchlist));rc_assert($calls===2);
});
foreach(['DETAIL_BENCHMARK_DATE_MISSING','DETAIL_PRICE_BASIS_CONFLICT','YAHOO_MAPPING_MISSING','DETAIL_COMPARISON_DATES_MISMATCH']as $code)
    rc_case('nontransient '.$code.' does not poll unchanged data',static function()use($code){rc_assert(!MonDaily::retryable($code));});
rc_case('worker preserves old analysis and terminates a missing mapping without retry',function(){
    $d=reinforcement_fixture();$sid=$d->watchlist->symbols[0]->symbol_id;$r=$d->watchlist->detail_requests->$sid;$r->force_refresh=true;unset($r->symbol->source_codes->yahoo);$r->request_hash=MonDetails::requestHash($r);$before=MonRun::json($d->analysis);
    $store=new MonStore(rc_dir());$daily=rc_daily($d,$store,static function(){throw new MonFault('HTTP_TRANSPORT_28');});$worker=new MonDetailWorker($daily,$store);
    $out=$worker->processOne($d,microtime(true)+8,MonData::settings($d->watchlist));rc_assert($out['response']->state==='done'&&$out['response']->error==='YAHOO_MAPPING_MISSING'&&$out['response']->data_status==='previous_verified');rc_assert($out['response']->retry_after===null&&MonRun::json($d->analysis)===$before);
});
rc_case('fresh finalization labels newly completed analysis explicitly',function(){
    $d=reinforcement_fixture();$r=MonDetails::finalize($d,reinforcement_decision($d,reinforcement_context($d)));rc_assert($r['new_analysis_complete']&&$r['analysis_origin']==='secured_data_decision');
});
rc_case('saved exact benchmark is reused even when its paired stock is older',function(){
    $d=reinforcement_fixture();$symbol=$d->watchlist->symbols[0];$r=$d->watchlist->detail_requests->{$symbol->symbol_id};$snap=$d->_test_snapshots->{$symbol->symbol_id};
    $older=MonDetails::snapshot($symbol,array_slice($snap->bars,0,-1),$snap->benchmark_bars,$snap->benchmark_id,$snap->origin,$snap->benchmark_origin);
    $stockCalls=0;$daily=rc_daily($d,new MonStore(rc_dir()),static function($m,$url)use($d,&$stockCalls){
        if(strpos($url,'koreainvestment')!==false)throw new MonFault('HTTP_TRANSPORT_28');$ticker=rawurldecode(basename(parse_url($url,PHP_URL_PATH)));
        rc_assert($ticker!=='^KS11','unnecessary benchmark download');$stockCalls++;return rc_chart($d,$ticker,false);});
    $s=$daily->fetch($r,$older,microtime(true)+8,MonData::settings($d->watchlist));rc_assert($stockCalls===1&&$s->metrics->bars_as_of===$r->target_completed_date&&$s->benchmark_origin->checked_at===$older->benchmark_origin->checked_at);
});
rc_case('a Retry-After beyond the remaining request budget ends with previous data',function(){
    $d=reinforcement_fixture();$sid=$d->watchlist->symbols[0]->symbol_id;$r=$d->watchlist->detail_requests->$sid;$r->force_refresh=true;$r->deadline_at=mon_iso(time()+50);$r->request_hash=MonDetails::requestHash($r);
    $store=new MonStore(rc_dir());$daily=rc_daily($d,$store,static function($m,$url){if(strpos($url,'koreainvestment')!==false)throw new MonFault('HTTP_TRANSPORT_28');return ['status'=>429,'headers'=>['retry-after'=>'90'],'body'=>'{}'];});
    $response=(new MonDetailWorker($daily,$store))->processOne($d,microtime(true)+8,MonData::settings($d->watchlist))['response'];
    rc_assert($response->state==='done'&&$response->retry_after===null&&$response->data_status==='previous_verified'&&$response->usable);
});
$failed=count(array_filter($cases,static function($row){return !$row['pass'];}));
echo mon_json(['suite'=>'MON recovery 1.6.1','runtime'=>PHP_VERSION,'duration_seconds'=>mon_monotonic()-$start,'pass'=>count($cases)-$failed,'fail'=>$failed,'cases'=>$cases,'metrics'=>$metrics,'external_requests'=>0,'orders'=>0]);
foreach($dirs as $dir)rc_clean($dir);exit($failed?1:0);

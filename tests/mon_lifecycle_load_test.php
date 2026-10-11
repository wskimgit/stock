<?php
declare(strict_types=1);
require_once __DIR__.'/mon_reinforcement_fixture.php';
// All identities, clocks, credentials and HTTP responses below are synthetic.
$cases=[];$dirs=[];$metrics=[];$started=mon_monotonic();
function ll_assert(bool $ok,string $why='assertion failed'): void {if(!$ok)throw new RuntimeException($why);}
function ll_case(string $name,callable $fn): void {global $cases;MonExecution::$active=null;MonExecution::$store=null;MonPreparation::reset();$t=mon_monotonic();try{$fn();$cases[]=['name'=>$name,'pass'=>true,'seconds'=>round(mon_monotonic()-$t,6)];}catch(Throwable $e){$cases[]=['name'=>$name,'pass'=>false,'error'=>$e->getMessage()];}}
function ll_fault(callable $fn,string $code): void {try{$fn();}catch(MonFault $e){ll_assert($e->faultCode===$code,$e->faultCode);return;}throw new RuntimeException('expected '.$code);}
function ll_store(): MonStore {global $dirs;$p=sys_get_temp_dir().'/mon-load-test-'.bin2hex(random_bytes(6));$dirs[]=$p;return new MonStore($p);}
function ll_gate(MonStore $s,string &$boot,int &$now): MonManualStart {return new MonManualStart($s,static function()use(&$boot){return $boot;},static function()use(&$now){return $now;});}
function ll_property($object,string $name,$value=null,bool $write=false){$r=new ReflectionProperty($object,$name);$r->setAccessible(true);if($write){$r->setValue($object,$value);return;}return $r->getValue($object);}
function ll_method($object,string $name,array $args=[]){$r=new ReflectionMethod($object,$name);$r->setAccessible(true);return $r->invokeArgs($object,$args);}
function ll_response($data,array $headers=[]): array {return ['status'=>200,'headers'=>$headers,'body'=>mon_json($data,false)];}
function ll_cfg(MonStore $s): MonConfig {$c=new MonConfig('',$s->dir);$c->values['GITHUB_TOKEN']='synthetic-test-token';return $c;}
function ll_normal(): array {return ['cores'=>1,'load1'=>.2,'memory_total_bytes'=>256*1048576,'memory_available_bytes'=>120*1048576];}
function ll_source(int $n=5): stdClass {$d=progress_fixture($n);$d->watchlist->detail_requests=new stdClass();unset($d->_test_snapshots);return $d;}
function ll_daemon(MonStore $s,$d,array $sample,array &$calls,bool $ticket=true): MonDaemon {
    $s->writePrivate('settings.php',(object)['GITHUB_TOKEN'=>'synthetic-test-token']);
    $instance=bin2hex(random_bytes(12));$permit=$ticket?(new MonManualStart($s))->issue($instance):'';
    $daemon=new MonDaemon($s,'',$instance,$permit);
    ll_property($daemon,'http',new MonHttp(null,static function($method,$url)use($d,&$calls){$calls[]=[$method,$url];return ll_response($d,['etag'=>'"synthetic-source"']);}),true);
    ll_property($daemon,'load',new MonDaemonLoad(static function()use($sample){return $sample;}),true);return $daemon;
}

ll_case('manual web permit is bound to this boot and consumed once',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$id=str_repeat('a',24);$p=$g->issue($id);$g->validate($id,$p);
    ll_assert($g->consume($id,$p)===$boot);ll_fault(static function()use($g,$id,$p){$g->consume($id,$p);},'MANUAL_START_REQUIRED');
    ll_assert($s->readPrivate('manual_start.php')->state==='active');
});
ll_case('previous boot permit cannot launch after NAS reboot',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$id=str_repeat('b',24);$p=$g->issue($id);$boot='boot-B';
    ll_fault(static function()use($g,$id,$p){$g->consume($id,$p);},'MANUAL_START_REQUIRED');
});
ll_case('new manual start works after reboot with a new permit',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$g->issue(str_repeat('c',24));$boot='boot-B';$id=str_repeat('d',24);$p=$g->issue($id);ll_assert($g->consume($id,$p)==='boot-B');
});
ll_case('expired permit cannot be reused by a supervisor',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$id=str_repeat('e',24);$p=$g->issue($id);$now+=15;
    ll_fault(static function()use($g,$id,$p){$g->consume($id,$p);},'MANUAL_START_REQUIRED');
});
ll_case('clock rewind cannot extend a start permit',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$id=str_repeat('f',24);$p=$g->issue($id);$now--;
    ll_fault(static function()use($g,$id,$p){$g->consume($id,$p);},'MANUAL_START_REQUIRED');
});
ll_case('wrong process instance cannot consume a permit',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$p=$g->issue(str_repeat('a',24));ll_fault(static function()use($g,$p){$g->consume(str_repeat('b',24),$p);},'MANUAL_START_REQUIRED');
});
ll_case('wrong token cannot consume a permit',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$id=str_repeat('a',24);$g->issue($id);ll_fault(static function()use($g,$id){$g->consume($id,str_repeat('0',64));},'MANUAL_START_REQUIRED');
});
ll_case('missing boot identity prevents issuing a permit',function(){
    $s=ll_store();$g=new MonManualStart($s,static function(){return null;});ll_fault(static function()use($g){$g->issue(str_repeat('a',24));},'BOOT_ID_UNAVAILABLE');ll_assert(!is_file($s->path('manual_start.php')));
});
ll_case('stop cancels a child that has not started yet',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$id=str_repeat('a',24);$p=$g->issue($id);$g->revoke();
    ll_fault(static function()use($g,$id,$p){$g->consume($id,$p);},'MANUAL_START_REQUIRED');ll_assert($s->read('stop.json')->instance_id===$id);
});
ll_case('stop during the consumed-before-heartbeat race uses the active instance',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$id=str_repeat('a',24);$p=$g->issue($id);$g->consume($id,$p);ll_assert($g->revoke(str_repeat('b',24))===$id);
});
ll_case('start state stores a hash instead of a launch token',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$p=$g->issue(str_repeat('a',24));$raw=file_get_contents($s->path('manual_start.php'));
    ll_assert(strpos($raw,MON_SETTINGS_PREFIX)===0&&strpos($raw,$p)===false&&strpos($raw,hash('sha256',$p))!==false);
});
ll_case('concurrent gate use returns busy without overwriting a permit',function(){
    $s=ll_store();$boot='boot-A';$now=1000;$g=ll_gate($s,$boot,$now);$p=$g->issue(str_repeat('a',24));$h=fopen($s->path('start_gate.lock'),'c+');flock($h,LOCK_EX);
    try{ll_fault(static function()use($g){$g->issue(str_repeat('b',24));},'WEB_START_BUSY');}finally{flock($h,LOCK_UN);fclose($h);}$g->validate(str_repeat('a',24),$p);
});
ll_case('an old status PID does not count as a running daemon',function(){
    $s=ll_store();$s->write('status.json',(object)['version'=>MON_VERSION,'boot_id'=>'previous-boot','pid'=>getmypid(),'heartbeat_at'=>mon_iso()]);$before=scandir($s->dir);$v=mon_status($s);
    ll_assert(!$v['running']&&!$v['boot_verified']&&scandir($s->dir)===$before);
});
ll_case('daemon itself rejects an unpermitted run before HTTP or status writes',function(){
    $s=ll_store();$calls=[];$d=ll_daemon($s,ll_source(1),ll_normal(),$calls,false);ll_fault(static function()use($d){$d->run(1);},'MANUAL_START_REQUIRED');
    ll_assert($calls===[]&&!is_file($s->path('status.json'))&&!mon_status($s)['running']);
});
ll_case('actual one-cycle manual run records the current boot and prevents replay',function()use(&$metrics){
    $s=ll_store();$calls=[];$d=ll_daemon($s,ll_source(1),ll_normal(),$calls);ll_assert($d->run(1)===0);$status=$s->read('status.json');
    ll_assert($status->boot_id===MonManualStart::bootId()&&$status->start_policy==='manual_web_only'&&$status->state==='stopped'&&count($calls)===1);
    ll_assert($status->resources->priority_applied&&$status->resources->memory_limit_applied);$metrics['local_daemon_priority_applied']=true;$metrics['local_memory_limit']=ini_get('memory_limit');
    ll_fault(static function()use($d){$d->run(1);},'MANUAL_START_REQUIRED');ll_assert(count($calls)===1&&!mon_status($s)['running']);
});

ll_case('normal system admits bounded collection',function(){
    $g=new MonDaemonLoad(static function(){return ll_normal();});$v=$g->sample();ll_assert($v['state']==='normal'&&$v['wait_seconds']===0&&$v['measurement_available']);
});
ll_case('load threshold scales with CPU cores',function(){
    $s=ll_normal();$s['cores']=2;$s['load1']=2.1;$g=new MonDaemonLoad(static function()use(&$s){return $s;});ll_assert($g->sample()['state']==='normal');$s['load1']=2.6;ll_assert($g->sample()['reason']==='load');
});
ll_case('low available memory pauses collection',function(){
    $s=ll_normal();$s['memory_available_bytes']=20*1048576;$g=new MonDaemonLoad(static function()use($s){return $s;});$v=$g->sample();ll_assert($v['state']==='deferred'&&$v['reason']==='memory'&&$v['wait_seconds']===30);
});
ll_case('resource backoff is bounded at 30, 60, 120 seconds',function()use(&$metrics){
    $s=ll_normal();$s['load1']=4;$g=new MonDaemonLoad(static function()use($s){return $s;});$waits=[];for($i=0;$i<7;$i++)$waits[]=$g->sample()['wait_seconds'];ll_assert($waits===[30,60,120,120,120,120,120]);$metrics['pressure_backoff_seconds']=$waits;
});
ll_case('two healthy samples are required before resuming',function(){
    $s=ll_normal();$s['load1']=4;$g=new MonDaemonLoad(static function()use(&$s){return $s;});$g->sample();$s['load1']=.7;
    ll_assert($g->sample()['reason']==='recovering'&&$g->sample()['state']==='normal');
});
ll_case('near-threshold pressure does not cause repeated stop/start oscillation',function(){
    $s=ll_normal();$s['load1']=4;$g=new MonDaemonLoad(static function()use(&$s){return $s;});$g->sample();$s['load1']=1.1;ll_assert($g->sample()['state']==='deferred'&&$g->sample()['state']==='deferred');
});
ll_case('unavailable measurements are disclosed and fixed caps stay active',function(){
    $g=new MonDaemonLoad(static function(){return ['cores'=>1,'load1'=>null,'memory_available_bytes'=>null];});$v=$g->sample();ll_assert(!$v['measurement_available']&&$v['state']==='normal');$s=MonDaemonLoad::settings(MonData::settings(ll_source(1)->watchlist));ll_assert($s['detail_jobs_per_tick']===1&&$s['quote_symbols_per_tick']===2);
});
ll_case('daemon caps do not change foreground analysis or watchlist settings',function(){
    $d=ll_source(1);$before=mon_json($d);$base=MonData::settings($d->watchlist);$s=MonDaemonLoad::settings($base);
    ll_assert($base['detail_jobs_per_tick']===3&&$base['tick_budget_seconds']===55&&$s['tick_budget_seconds']===25&&$s['detail_budget_seconds']===8&&$s['collection_budget_seconds']===6&&$s['request_poll_seconds']===30&&mon_json($d)===$before);
});
ll_case('CPU duty waiting adds idle time after an overrun instead of spinning',function(){
    ll_assert(MonDaemonLoad::waitSeconds(5,20,1.5)===13.5&&MonDaemonLoad::waitSeconds(30,2,.1)===28.0&&MonDaemonLoad::waitSeconds(5,20,null)===5.0);
});
ll_case('high load daemon cycle makes zero external transport calls and preserves checkpoints',function(){
    $s=ll_store();$s->write('pending.json',(object)['unchanged'=>true]);$before=file_get_contents($s->path('pending.json'));$calls=[];$sample=ll_normal();$sample['load1']=5;$d=ll_daemon($s,ll_source(1),$sample,$calls);
    ll_assert($d->run(1)===0&&$calls===[]&&file_get_contents($s->path('pending.json'))===$before);$v=$s->read('status.json');ll_assert($v->resources->state==='deferred'&&!is_file($s->path('remote_cache.json')));
});
ll_case('resource wait is visible on the real web state model',function(){
    $s=ll_store();$h=fopen($s->path('daemon.lock'),'c+');flock($h,LOCK_EX);$s->write('status.json',(object)['version'=>MON_VERSION,'instance_id'=>str_repeat('a',24),'boot_id'=>MonManualStart::bootId(),'heartbeat_at'=>mon_iso(),'activity_state'=>'resource_paused']);
    try{$v=mon_web_state($s,ll_cfg($s));ll_assert($v['readiness']==='resource_paused'&&!$v['monitoring_active']&&!$v['auto_start']&&in_array('SYSTEM_RESOURCE_PRESSURE',$v['setup_blockers'],true));}finally{flock($h,LOCK_UN);fclose($h);}
});

ll_case('unchanged source uses ETag and reuses the exact validated bytes',function()use(&$metrics){
    $s=ll_store();$d=ll_source(1);$seen=[];$bytes=0;$http=new MonHttp(null,static function($method,$url,$headers)use($d,&$seen,&$bytes){$seen[]=$headers;if(count($seen)===1){$r=ll_response($d,['etag'=>'"source-1"']);$bytes+=strlen($r['body']);return $r;}return ['status'=>304,'headers'=>[],'body'=>''];});
    $gh=new MonGitHub($http,ll_cfg($s));$first=$gh->read(microtime(true)+2);$next=$gh->read(microtime(true)+2,3,$first);
    ll_assert($next['unchanged']&&$next['sha']===$first['sha']&&$next['data']===$first['data']&&in_array('If-None-Match: "source-1"',$seen[1],true));$metrics['unchanged_source_body_bytes']=0;
});
ll_case('changed source replaces the cached SHA and data',function(){
    $s=ll_store();$d=ll_source(1);$count=0;$http=new MonHttp(null,static function()use($d,&$count){$v=clone $d;$v->collection=clone $d->collection;$v->collection->collection_id='batch-'.++$count;return ll_response($v,['etag'=>'"source-'.$count.'"']);});
    $gh=new MonGitHub($http,ll_cfg($s));$a=$gh->read(microtime(true)+2);$b=$gh->read(microtime(true)+2,3,$a);ll_assert(!$b['unchanged']&&$a['sha']!==$b['sha']&&$b['data']->collection->collection_id==='batch-2');
});
ll_case('unsolicited 304 is rejected instead of fabricating a source',function(){
    $gh=new MonGitHub(new MonHttp(null,static function(){return ['status'=>304,'headers'=>[],'body'=>''];}),ll_cfg(ll_store()));ll_fault(static function()use($gh){$gh->read(microtime(true)+2);},'GITHUB_UNEXPECTED_NOT_MODIFIED');
});
ll_case('unsafe ETag is never sent as a request header',function(){
    $seen=[];$d=ll_source(1);$gh=new MonGitHub(new MonHttp(null,static function($m,$u,$h)use(&$seen,$d){$seen=$h;return ll_response($d);}),ll_cfg(ll_store()));$gh->read(microtime(true)+2,3,['etag'=>"\"x\"\r\nInjected: x",'sha'=>str_repeat('a',40),'data'=>$d]);
    foreach($seen as $h)ll_assert(strpos($h,'If-None-Match:')!==0);
});
ll_case('unchanged daemon cache skips an atomic rewrite',function(){
    $s=ll_store();$d=new MonDaemon($s,'',str_repeat('a',24));$cfg=ll_cfg($s);$source=ll_source(1);$sha=MonRun::gitSha(mon_json($source,false));ll_method($d,'cacheRemote',[$source,$cfg,$sha]);
    $p=$s->path('remote_cache.json');touch($p,1000);$before=file_get_contents($p);ll_method($d,'cacheRemote',[$source,$cfg,$sha]);clearstatcache(true,$p);ll_assert(filemtime($p)===1000&&file_get_contents($p)===$before);
});
ll_case('waiting heartbeat is throttled while stop state is immediate',function(){
    $s=ll_store();$d=new MonDaemon($s,'',str_repeat('a',24));ll_method($d,'heartbeat',['waiting']);$p=$s->path('status.json');touch($p,1000);ll_method($d,'heartbeat',['waiting']);clearstatcache(true,$p);ll_assert(filemtime($p)===1000);
    ll_method($d,'heartbeat',['stopped']);clearstatcache(true,$p);ll_assert(filemtime($p)>1000&&$s->read('status.json')->state==='stopped');
});
ll_case('market closure makes no quote calls and no new quote checkpoint',function(){
    $s=ll_store();$calls=[];$d=ll_daemon($s,ll_source(1),ll_normal(),$calls);$d->run(1);ll_assert(count($calls)===1&&!is_file($s->path('pending.json'))&&ll_property($d,'stageSeconds')['detail_work']===0.0);
});
ll_case('retry-wait data is not decoded before the retry is due',function(){
    $d=progress_fixture(1);$sid=$d->watchlist->symbols[0]->symbol_id;$r=progress_response($d,$sid,'retry_wait');$r->retry_after=time()+30;$d->collection->detail_responses->$sid=$r;
    ll_assert(!MonDaemon::detailWorkDue($d,time())&&MonDaemon::detailWorkDue($d,time()+31));$r->state='done';ll_assert(!MonDaemon::detailWorkDue($d,time()+60));
});

ll_case('two-symbol limit rotates through every symbol and preserves unvisited prices',function()use(&$metrics){
    $s=ll_store();$d=ll_source(5);$now=time();$date=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
    $d->watchlist->calendar->markets[0]->sessions=[(object)['market_date'=>$date,'open_at'=>mon_iso($now-3600),'close_at'=>mon_iso($now+3600)]];
    $cfg=ll_cfg($s);$symbols=[];
    // Yahoo chart uses its ticker in the path, so inspect it without any external call.
    $http=new MonHttp(null,static function($m,$u)use(&$symbols,$now){
        if(strpos($u,'query1.finance.yahoo.com')===false)throw new MonFault('KIS_CREDENTIALS_MISSING');$ticker=rawurldecode(basename((string)parse_url($u,PHP_URL_PATH)));$symbols[]=$ticker;
        return ll_response((object)['chart'=>(object)['error'=>null,'result'=>[(object)['meta'=>(object)['symbol'=>$ticker,'currency'=>'KRW','exchangeName'=>'KSC','exchangeTimezoneName'=>'Asia/Seoul','regularMarketPrice'=>101,'regularMarketTime'=>$now-1,'exchangeDataDelayedBy'=>0,'currentTradingPeriod'=>(object)['regular'=>(object)['start'=>$now-3600,'end'=>$now+3600]]]]]]]);
    });
    $c=new MonCollector($http,$s,$cfg,null,static function()use($now){return $now;});$settings=MonDaemonLoad::settings(MonData::settings($d->watchlist));$settings['request_spacing_seconds']=0;$previous=$d->collection;$cursors=[];
    for($i=0;$i<3;$i++){$b=$c->collect($d,$previous,microtime(true)+6,$settings);ll_assert($c->attempted===2&&count($b->quotes)===5);$cursors[]=$b->next_cursor;if($i===0)ll_assert($b->quotes[2]->point===null);$previous=$b;}
    ll_assert($cursors===[2,4,1]&&count(array_unique($symbols))===5);$metrics['rotation_cursors_5_symbols']=$cursors;
});
ll_case('a repeatedly timed-out quote cannot starve later symbols',function(){
    $s=ll_store();$d=ll_source(3);$now=time();$date=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');$d->watchlist->calendar->markets[0]->sessions=[(object)['market_date'=>$date,'open_at'=>mon_iso($now-3600),'close_at'=>mon_iso($now+3600)]];
    $http=new MonHttp(null,static function(){throw new MonFault('BUDGET_EXHAUSTED');});$c=new MonCollector($http,$s,ll_cfg($s),null,static function()use($now){return $now;});$settings=MonDaemonLoad::settings(MonData::settings($d->watchlist));$settings['request_spacing_seconds']=0;
    $a=$c->collect($d,$d->collection,microtime(true)+6,$settings);$b=$c->collect($d,$a,microtime(true)+6,$settings);ll_assert($a->next_cursor===2&&$b->next_cursor===1&&$b->quotes[2]->fetch_status==='error');
});
ll_case('stop remains responsive while resource waiting is active',function(){
    $s=ll_store();$id=str_repeat('a',24);$s->write('stop.json',(object)['instance_id'=>$id]);$ctl=new MonControl($s,$id);$t=mon_monotonic();try{$ctl->wait(120);}catch(MonStop $e){ll_assert(mon_monotonic()-$t<.1);return;}throw new RuntimeException('stop ignored');
});

// MonPublication's existing artifact/queue fixture is small and entirely local.
function ll_publication(MonStore $s,float &$now,array &$calls): array {
    $budget=MonExecution::start($s,(object)['run_id'=>'load-publication-'.bin2hex(random_bytes(4)),'received_at'=>MonBudget::iso($now)],static function()use(&$now){return $now;},static function(){return 0.0;});
    $data=reinforcement_fixture(1);$ctx=reinforcement_context($data);$fixed=MonDetails::finalize($data,reinforcement_decision($data,$ctx));
    $source=MonRun::json($data);$decision=str_repeat('e',64);$manifest=MonArtifact::create($s,$source,$fixed['files'],['run_context_id'=>$budget->run->run_context_id,'received_at'=>$budget->run->received_at,'decision_sha256'=>$decision]);
    $p=new MonPublication($s,new MonHttp(null,static function()use(&$calls){$calls[]='unexpected';throw new RuntimeException('external call');}),ll_cfg($s),static function()use(&$now){return $now;});
    $r=$p->enqueue($manifest['artifact_id'],str_repeat('a',40),$budget,'synthetic-operator');return [$p,$r,$manifest];
}
ll_case('pressure deferral preserves queue expiry and does not load the large artifact',function(){
    $s=ll_store();$now=microtime(true);$calls=[];[$p,$r,$m]=ll_publication($s,$now,$calls);$expiry=$r['expires_at'];unlink($s->path('artifact-'.$m['artifact_id'].'.php'));$v=$p->processNext(microtime(true)-1);
    ll_assert($calls===[]&&$v['save_status']==='queued'&&$v['expires_at']===$expiry);
});
ll_case('pressure does not postpone original publication expiration',function(){
    $s=ll_store();$now=microtime(true);$calls=[];[$p,$r,$m]=ll_publication($s,$now,$calls);$now+=301;$v=$p->processNext(microtime(true)-1);ll_assert($calls===[]&&$v['save_status']==='expired'&&$v['expires_at']===$r['expires_at']);
});
ll_case('normal publication budget still verifies artifact integrity before proceeding',function(){
    $s=ll_store();$now=microtime(true);$calls=[];[$p,$r,$m]=ll_publication($s,$now,$calls);unlink($s->path('artifact-'.$m['artifact_id'].'.php'));$v=$p->process($r['job_id'],microtime(true)+2);
    ll_assert($calls===[]&&$v['save_status']==='failed'&&$v['last_error']==='MON_ARTIFACT_INVALID');
});
ll_case('expired publication with a possible ref write stays unknown',function(){
    $s=ll_store();$now=microtime(true);$calls=[];[$p,$r,$m]=ll_publication($s,$now,$calls);$name='publish-'.$r['job_id'].'.php';$j=$s->readPrivate($name);$j->ref_attempted=true;unset($j->journal_sha256);$j->journal_sha256=hash('sha256',MonRun::json($j));$s->writePrivate($name,$j);
    $now+=301;$v=$p->processNext(microtime(true)-1);ll_assert($calls===[]&&$v['save_status']==='unknown');
});

$failed=count(array_filter($cases,static function($c){return !$c['pass'];}));
echo mon_json(['suite'=>'MON manual lifecycle and daemon load 1.6.2','runtime'=>PHP_VERSION,'duration_seconds'=>round(mon_monotonic()-$started,6),'pass'=>count($cases)-$failed,'fail'=>$failed,'cases'=>$cases,'metrics'=>$metrics,'external_requests'=>0,'orders'=>0,'nas_reboot_performed'=>false]);
foreach($dirs as $dir){foreach(glob($dir.'/*')?:[]as $p)if(is_file($p))unlink($p);rmdir($dir);}exit($failed?1:0);

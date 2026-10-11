<?php
declare(strict_types=1);
require __DIR__.'/mon_progress_fixture.php';
// Producer progress only: synthetic requests, local files, and mocked UI/network.
$cases=[];$dirs=[];$started=mon_monotonic();
function st_assert(bool $ok,string $why='assertion'): void {if(!$ok)throw new RuntimeException($why);}
function st_case(string $name,callable $f): void {global $cases;try{$f();$cases[]=['case'=>$name,'pass'=>true];}catch(Throwable $e){$cases[]=['case'=>$name,'pass'=>false,'error'=>$e->getMessage()];}}
function st_dir(): string {global $dirs;$dir=sys_get_temp_dir().'/mon-step-'.bin2hex(random_bytes(8));mkdir($dir,0700);$dirs[]=$dir;return $dir;}
function st_request(): stdClass {$d=progress_fixture(1);return reset($d->watchlist->detail_requests);}
function st_web(MonStore $store,array $get,string $method='GET'): array {
    $prior=getenv('MON_STATE_DIR');$oldGet=$_GET;$oldMethod=$_SERVER['REQUEST_METHOD']??null;$level=ob_get_level();putenv('MON_STATE_DIR='.$store->dir);$_GET=$get;$_SERVER['REQUEST_METHOD']=$method;http_response_code(200);ob_start();
    try{$code=mon_web();$raw=ob_get_clean();return [$code,http_response_code(),mon_decode($raw),$raw];}
    finally{while(ob_get_level()>$level)ob_end_clean();$_GET=$oldGet;if($oldMethod===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$oldMethod;if($prior===false)putenv('MON_STATE_DIR');else putenv('MON_STATE_DIR='.$prior);}
}
st_case('stage history retains six recent transitions in protected bounded storage',function(){
    $store=new MonStore(st_dir());$r=st_request();foreach(['fetch','source_proof','stock_saved','benchmark','indicators','calculated','checkpoint_saved']as $stage)MonDetailStep::mark($store,$r,$stage);
    $view=MonDetailStep::view($store,time());$path=$store->path(MonDetailStep::FILE);$raw=file_get_contents($path);
    st_assert(count($view['recent'])===6&&$view['current']->stage==='checkpoint_saved'&&$view['current']->label!=='GitHub 반영 확인');
    st_assert(strpos($raw,MON_SETTINGS_PREFIX)===0&&strlen($raw)<=MonDetailStep::MAX_BYTES&&strlen(mon_json($view,false))<4000);
    st_assert((fileperms($path)&0777)===0600);
});
st_case('repeated same stage is coalesced and unchanged polling omits history',function(){
    $store=new MonStore(st_dir());$r=st_request();MonDetailStep::mark($store,$r,'benchmark');$path=$store->path(MonDetailStep::FILE);$before=file_get_contents($path);MonDetailStep::mark($store,$r,'benchmark');
    $view=MonDetailStep::view($store,time());$same=MonDetailStep::view($store,time(),$view['snapshot_token']);st_assert(file_get_contents($path)===$before&&!$same['changed']&&$same['recent']===[]&&$same['current']->stage==='benchmark');
});
st_case('read-only view leaves stage and operational files unchanged',function(){
    $store=new MonStore(st_dir());MonDetailStep::mark($store,st_request(),'retry_wait','','HTTP_429');$store->write('status.json',(object)['detail_counts'=>(object)['retry_wait'=>1,'pending'=>0]]);
    $before=[];foreach(scandir($store->dir)as $file)if(is_file($store->dir.'/'.$file))$before[$file]=hash_file('sha256',$store->dir.'/'.$file);
    $view=MonDetailStep::view($store,time());st_assert(!$view['active']&&$view['work_pending']&&$view['current']->error==='HTTP_429');
    foreach($before as $file=>$sha)st_assert(hash_file('sha256',$store->dir.'/'.$file)===$sha);
});
st_case('unknown stages or nonrequest records cannot introduce arbitrary content',function(){
    $store=new MonStore(st_dir());$r=st_request();MonDetailStep::mark($store,$r,'unknown');$r->request_hash='bad';MonDetailStep::mark($store,$r,'fetch');st_assert(MonDetailStep::view($store,time())['current']===null);
});
st_case('invalid or oversized stage state is ignored without reading market payload',function(){
    $store=new MonStore(st_dir());file_put_contents($store->path(MonDetailStep::FILE),str_repeat('x',MonDetailStep::MAX_BYTES+1));$store->write('remote_cache.json','not a market document');st_assert(MonDetailStep::view($store,time())['current']===null);
    $store->writePrivate(MonDetailStep::FILE,(object)['contract'=>MonDetailStep::CONTRACT,'recent'=>[],'current'=>(object)['secret'=>'forbidden']]);st_assert(MonDetailStep::view($store,time())['current']===null);
});
st_case('stale producer step is never presented as active work',function(){
    $store=new MonStore(st_dir());MonDetailStep::mark($store,st_request(),'fetch');$s=$store->readPrivate(MonDetailStep::FILE);$s->current->at=mon_iso(time()-120);$s->recent[0]->at=$s->current->at;$store->writePrivate(MonDetailStep::FILE,$s);st_assert(!MonDetailStep::view($store,time())['active']);
});
st_case('GitHub stage requires a matching confirmed response',function(){
    $store=new MonStore(st_dir());$d=progress_fixture(1);$r=reset($d->watchlist->detail_requests);MonDetailStep::mark($store,$r,'checkpoint_saved');MonDetailStep::published($store,$d);st_assert(MonDetailStep::view($store,time())['current']->stage==='checkpoint_saved');
    $d->collection->detail_responses->{$r->symbol_id}=progress_response($d,$r->symbol_id);MonDetailStep::published($store,$d);st_assert(MonDetailStep::view($store,time())['current']->stage==='published');
});
st_case('fast HTTP stage endpoint succeeds with deliberately corrupt full caches',function(){
    $store=new MonStore(st_dir());MonDetailStep::mark($store,st_request(),'benchmark');file_put_contents($store->path('remote_cache.json'),'{');file_put_contents($store->path('detail_pending.json'),'{');
    [$code,$status,$body,$raw]=st_web($store,['view'=>'progress','stage'=>'1']);st_assert($code===0&&$status===200&&$body->step->current->stage==='benchmark'&&strlen($raw)<4000);
});
st_case('fast HTTP endpoint remains GET-only and rejects invalid query tokens',function(){
    $store=new MonStore(st_dir());[$code,$status,$body]=st_web($store,['view'=>'progress','stage'=>'1'],'POST');st_assert($code===1&&$status===405&&$body->error_code==='MON_METHOD_INVALID');
    [$code,$status,$body]=st_web($store,['view'=>'progress','stage'=>'../../other']);st_assert($code===1&&$status===400&&$body->error_code==='MON_STEP_QUERY_INVALID');
    [$code,$status,$body]=st_web($store,['view'=>'progress','stage'=>'1','since'=>'bad']);st_assert($code===1&&$body->error_code==='MON_STEP_TOKEN_INVALID');
});
st_case('light cached progress preserves original cache and detects pending metadata',function(){
    $d=progress_fixture(3);foreach($d->watchlist->detail_requests as $sid=>$r)$d->collection->detail_responses->$sid=progress_response($d,$sid);
    $before=mon_json($d);$sid=$d->watchlist->symbols[0]->symbol_id;$response=mon_decode(mon_json($d->collection->detail_responses->$sid));$response->attempts++;
    $pending=(object)['collection'=>(object)['detail_responses'=>(object)[$sid=>$response]]];$pendingBefore=mon_json($pending);$view=MonProgress::cached($d,$pending);st_assert(mon_json($d)===$before&&mon_json($pending)===$pendingBefore);
    $found=false;foreach($view['cards']as $row)if($row->symbol_id===$sid){$found=true;st_assert($row->publication==='pending');}st_assert($found);
});
st_case('light progress preserves exact encoded payload difference detection',function(){
    $d=progress_fixture(1);$sid=$d->watchlist->symbols[0]->symbol_id;$d->collection->detail_responses->$sid=progress_response($d,$sid);$response=mon_decode(mon_json($d->collection->detail_responses->$sid));$response->payload->data.='AAAA';
    $view=MonProgress::cached($d,(object)['collection'=>(object)['detail_responses'=>(object)[$sid=>$response]]]);st_assert($view['cards'][0]->publication==='pending'&&$view['full_snapshot_validation_required']);
});
st_case('existing four KiB progress pagination and unchanged token behavior remain intact',function(){
    $d=progress_fixture(30);$view=MonProgress::cached($d,new stdClass());$pages=1;$token=$view['snapshot_token'];st_assert(strlen(mon_json($view,false))<=4000);
    while($view['page']['next_cursor']!==null){$view=MonProgress::cached($d,new stdClass(),$token,$view['page']['next_cursor']);$pages++;st_assert(strlen(mon_json($view,false))<=4000);}st_assert($pages>1);
    $same=MonProgress::cached($d,new stdClass(),$token);st_assert(!$same['changed']&&$same['cards']===[]);
});
st_case('reused verified result emits calculated stage before any publication claim',function(){
    $store=new MonStore(st_dir());$d=progress_fixture(1);$cfg=progress_test_config($store->dir);$http=new MonHttp(null,static function(){throw new RuntimeException('unexpected network');});
    $daily=new MonDaily($http,new MonCollector($http,$store,$cfg),$cfg,$store);$job=(new MonDetailWorker($daily,$store))->processOne($d,microtime(true)+5,['detail_symbol_budget_seconds'=>8],MonRun::payload($d));
    st_assert($job['changed']&&$job['response']->data_status==='reused_current');$view=MonDetailStep::view($store,time());st_assert($view['current']->stage==='calculated');
    st_assert(array_column($view['recent'],'stage')===['reuse','calculated']);
});
st_case('fallback HTTP and final calculation expose actual per-symbol stages',function(){
    $store=new MonStore(st_dir());$d=progress_fixture(1);$cfg=progress_test_config($store->dir);
    $symbol=(object)['symbol_id'=>'US|NASDAQ|FIX','country'=>'US','exchange'=>'NASDAQ','symbol'=>'FIX','name'=>'Synthetic issuer','currency'=>'USD','is_held'=>false,'tick_size'=>.01,'tick_rule'=>'US_NMS_0.01_CURRENT','source_codes'=>(object)['kis'=>null,'yahoo'=>'FIX']];
    $r=(object)['contract'=>MonDetails::CONTRACT,'request_id'=>'synthetic-step-fetch','symbol_id'=>$symbol->symbol_id,'symbol'=>$symbol,'criteria_version'=>MonSwing::CRITERIA,'requested_at'=>mon_iso(),'deadline_at'=>mon_iso(time()+120),'target_completed_date'=>'2026-10-09','target_verified'=>true,'force_refresh'=>false];$r->request_hash=MonDetails::requestHash($r);$d->watchlist->detail_requests=(object)[$r->symbol_id=>$r];
    $store->writePrivate('daily-route-'.hash('sha256',$r->symbol_id).'.php',(object)['target_completed_date'=>'2026-10-09','provider'=>'SPG_STOCKANALYSIS']);
    $rows=[];$lines=[];for($day=new DateTimeImmutable('2026-04-09');$day->format('Y-m-d')<='2026-10-09';$day=$day->modify('+1 day'))if((int)$day->format('N')<=5){$date=$day->format('Y-m-d');$rows[]='{t:"'.$date.'",o:100,h:102,l:99,c:101,v:1000,a:77,ch:.5}';$lines[]='#'.$date.'|100';}
    $html='<html>past prices are adjusted for stock splits ex:"NASDAQ" curr:{main:"USD",price:"USD"} data:{id:1,symbol:"FIX",source:"spg",updated:"synthetic",data:['.implode(',',$rows).']}</html>';
    $fred='<td>SP500</td>S&amp;P Dow Jones Indices<table id="data-table-observations"></table><div id="extra-rows">'.implode("\n",$lines).'</div>';$observed=[];
    $http=new MonHttp(null,static function($method,$url)use($store,$html,$fred,&$observed){$observed[]=MonDetailStep::view($store,time())['current']->stage;
        if(strpos($url,'stockanalysis.com')!==false)return ['status'=>200,'headers'=>[],'body'=>$html];if(strpos($url,'fred.stlouisfed.org')!==false)return ['status'=>200,'headers'=>[],'body'=>$fred];return ['status'=>429,'headers'=>[],'body'=>'{}'];});
    $daily=new MonDaily($http,new MonCollector($http,$store,$cfg),$cfg,$store);$job=(new MonDetailWorker($daily,$store))->processOne($d,microtime(true)+10,['detail_symbol_budget_seconds'=>8,'http_timeout_seconds'=>5,'request_spacing_seconds'=>0],MonRun::payload($d));
    st_assert($job['response']->usable&&$job['response']->data_status==='latest');st_assert($observed===['fetch','benchmark','benchmark']);$view=MonDetailStep::view($store,time());
    st_assert(array_column($view['recent'],'stage')===['fetch','source_proof','stock_saved','benchmark','indicators','calculated']);
});
st_case('unwritable stage target cannot interrupt otherwise valid data processing',function(){
    $store=new MonStore(st_dir());mkdir($store->path(MonDetailStep::FILE));$d=progress_fixture(1);$cfg=progress_test_config($store->dir);$http=new MonHttp(null,static function(){throw new RuntimeException('unexpected network');});
    $daily=new MonDaily($http,new MonCollector($http,$store,$cfg),$cfg,$store);$job=(new MonDetailWorker($daily,$store))->processOne($d,microtime(true)+5,['detail_symbol_budget_seconds'=>8],MonRun::payload($d));
    st_assert($job['response']->usable&&$job['response']->data_status==='reused_current');
});
st_case('visible UI polls small stages at three seconds and full status at ten seconds with one flight',function(){
    $store=new MonStore(st_dir());$r=st_request();MonDetailStep::mark($store,$r,'benchmark');$step=MonDetailStep::view($store,time());
    $state=['label'=>'synthetic','message'=>'synthetic','watchlist_loaded'=>true,'watched'=>1,'fresh_quotes'=>0,'running'=>true,'readiness'=>'details','monitoring_active'=>false,'heartbeat_fresh'=>true,'connection'=>['github'=>true,'kis'=>false],
        'detail_step'=>$step,'detail_progress'=>['snapshot_token'=>str_repeat('a',64),'cards'=>[],'page'=>['next_cursor'=>1],'counts'=>['requests'=>1,'done'=>0,'pending'=>1,'retry_wait'=>0,'previous'=>0,'invalid'=>0],'publication'=>'stored']];
    ob_start();mon_web_html($state,'',true,'');$html=ob_get_clean();st_assert(strpos($html,'aria-label="최근 개별 처리 단계"')!==false);preg_match('~<script>(.*?)</script>~s',$html,$match);st_assert(isset($match[1]));
    file_put_contents($store->dir.'/ui.js',$match[1]);file_put_contents($store->dir.'/ui-data.json',mon_json((object)['state'=>$state,'step'=>$step]));
    $harness=<<<'JS'
const fs=require('fs'),vm=require('vm');const folder=process.argv[2],data=JSON.parse(fs.readFileSync(folder+'/ui-data.json','utf8'));
let now=0,nextId=0,inflight=0,maxFlight=0;const timers=new Map(),nodes=new Map(),calls=[];
function node(id){if(!nodes.has(id))nodes.set(id,{textContent:'',hidden:false,disabled:false,className:'',children:[],listeners:{},appendChild(x){this.children.push(x)},replaceChildren(){this.children=[]},addEventListener(e,f){this.listeners[e]=f}});return nodes.get(id)}
const doc={hidden:false,getElementById:node,querySelectorAll(){return[]},createElement(){return node('item-'+Math.random())},listeners:{},addEventListener(e,f){this.listeners[e]=f}};
class ClockDate extends Date{static now(){return now}}
const ctx={document:doc,location:{pathname:'/mon.php'},sessionStorage:{getItem(){return null},setItem(){}},Date:ClockDate,AbortController,URLSearchParams,console,
setTimeout(fn,ms){const id=++nextId;timers.set(id,{at:now+ms,fn});return id},clearTimeout(id){timers.delete(id)},
async fetch(url){inflight++;maxFlight=Math.max(maxFlight,inflight);calls.push({at:now,url});node('detail-more').listeners.click();await Promise.resolve();inflight--;return{ok:true,headers:{get(){return null}},async json(){return url.includes('stage=1')?{ok:true,step:data.step}:{ok:true,data:data.state}}}}};
vm.createContext(ctx);vm.runInContext(fs.readFileSync(folder+'/ui.js','utf8'),ctx);
async function next(){const pair=[...timers].sort((a,b)=>a[1].at-b[1].at)[0];if(!pair)throw Error('missing timer');timers.delete(pair[0]);now=pair[1].at;pair[1].fn();await new Promise(setImmediate)}
(async()=>{for(let i=0;i<4;i++)await next();if(maxFlight!==1||calls.length!==4)throw Error('multiple or missing flights');
if(calls.slice(0,3).some((c,i)=>c.at!==(i+1)*3000||!c.url.includes('stage=1'))||calls[3].at!==10000||!calls[3].url.includes('view=status'))throw Error('incorrect polling schedule '+JSON.stringify(calls));
if(!node('detail-step').textContent.includes('동일 기간 지수 확인'))throw Error('stage not rendered');doc.hidden=true;doc.listeners.visibilitychange();if(timers.size)throw Error('hidden page kept polling');
process.stdout.write(JSON.stringify({pass:true,calls,maxFlight,hiddenPolling:false}));})().catch(e=>{process.stderr.write(e.message);process.exit(1)});
JS;
    file_put_contents($store->dir.'/ui-test.js',$harness);$node=getenv('MON_TEST_NODE')?:'/opt/codex/runtimes/codex-primary-runtime/dependencies/node/bin/node';st_assert(is_executable($node),'node runtime unavailable');
    $lines=[];$exit=0;exec(escapeshellarg($node).' '.escapeshellarg($store->dir.'/ui-test.js').' '.escapeshellarg($store->dir).' 2>&1',$lines,$exit);st_assert($exit===0,implode("\n",$lines));$result=mon_decode(implode("\n",$lines));st_assert($result->pass&&$result->maxFlight===1);
});
st_case('old matching remote response cannot confirm a newly checkpointed step',function(){
    $store=new MonStore(st_dir());$d=progress_fixture(1);$r=reset($d->watchlist->detail_requests);$sid=$r->symbol_id;$response=progress_response($d,$sid);$response->completed_at=mon_iso(time()-10);$d->collection->detail_responses->$sid=$response;
    MonDetailStep::mark($store,$r,'checkpoint_saved');MonDetailStep::published($store,$d);st_assert(MonDetailStep::view($store,time())['current']->stage==='checkpoint_saved');
    $response->completed_at=mon_iso();MonDetailStep::published($store,$d);st_assert(MonDetailStep::view($store,time())['current']->stage==='published');
});
$bad=array_values(array_filter($cases,static function($r){return !$r['pass'];}));$report=['kind'=>'targeted_small_stage_progress_tests','processor_version'=>MON_VERSION,'php_version'=>PHP_VERSION,'case_count'=>count($cases),'passed'=>count($cases)-count($bad),'failed'=>count($bad),'elapsed_seconds'=>round(mon_monotonic()-$started,6),'external_requests'=>0,'orders'=>0,'cases'=>$cases];
file_put_contents(__DIR__.'/mon_step_tests.json',mon_json($report));echo mon_json(['case_count'=>count($cases),'passed'=>$report['passed'],'failed'=>$bad,'elapsed_seconds'=>$report['elapsed_seconds']]);
foreach($dirs as $dir){foreach(scandir($dir)as $file)if($file!=='.'&&$file!=='..'){if(is_dir($dir.'/'.$file))rmdir($dir.'/'.$file);else unlink($dir.'/'.$file);}rmdir($dir);}exit($bad?1:0);

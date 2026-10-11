<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/mon.php';
$cases=[];$dirs=[];$simulation=[];$started=mon_monotonic();
function nc_ok($condition,string $why='assertion failed'): void {if(!$condition)throw new RuntimeException($why);}
function nc_case(string $name,callable $f): void {global $cases;try{$f();$cases[]=['case'=>$name,'pass'=>true];}catch(Throwable $e){$cases[]=['case'=>$name,'pass'=>false,'error'=>$e->getMessage()];}}
function nc_store(): MonStore {global $dirs;$dir=sys_get_temp_dir().'/mon-nas-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);$dirs[]=$dir;return new MonStore($dir);}
function nc_client(MonStore $s,string $id,float &$wall,float &$mono,callable $transport,?callable $cancel=null): MonNasConnection {
    return new MonNasConnection($s,$id,$transport,static function()use(&$wall){return $wall;},static function()use(&$mono){return $mono;},$cancel);
}
function nc_good(): array {return ['status'=>200,'headers'=>[],'body'=>mon_json(['ok'=>true,'processor_version'=>'1.8.1','result'=>['header'=>['contract'=>'MON-BOOTSTRAP-1.0','processor_version'=>'1.8.1','state'=>'needs_data','new_analysis_complete'=>false],'cards'=>[]]],false)];}
function nc_legacy(): array {return ['status'=>200,'headers'=>[],'body'=>mon_json(['ok'=>true,'data'=>['version'=>'1.5.0','running'=>true,'heartbeat_fresh'=>true,'KIS_APP_SECRET'=>'never-persist-this','readiness'=>'off_session']],false)];}
function nc_child(string $code,array $args=[]): array {
    $p=proc_open(array_merge([PHP_BINARY,'-n','-r',$code],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    nc_ok(is_resource($p),'child not started');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);return [$exit,$out,$err];
}
nc_case('late connection succeeds after 6.42 seconds within the initial 10/20 second socket',function(){
    $s=nc_store();$wall=1700000000.;$mono=100.;$calls=0;
    $n=nc_client($s,'late',$wall,$mono,function($method,$url,$headers,$connect,$total)use(&$wall,&$mono,&$calls){$calls++;nc_ok($method==='GET'&&$url===MonNasConnection::ENDPOINT&&$headers===['Accept: application/json']);nc_ok($connect===10&&$total===20);$wall+=6.42;$mono+=6.42;return nc_good();});
    $r=$n->poll();nc_ok($r['connected']&&$r['compatible']&&$calls===1&&abs($r['total_seconds']-6.42)<.001);$n->poll();nc_ok($calls===1,'connected run must not probe again');
});
nc_case('three timeouts grow socket waits and backoff; the fourth attempt recovers beyond 120 seconds',function(){
    global $simulation;$s=nc_store();$wall=1700000000.;$mono=0.;$start=$wall;$plans=[];$calls=0;
    $n=nc_client($s,'growth',$wall,$mono,function($method,$url,$headers,$connect,$total)use(&$wall,&$mono,&$plans,&$calls){$plans[]=[$connect,$total];$calls++;$sec=$calls<4?$total:6.;$wall+=$sec;$mono+=$sec;if($calls<4)throw new MonFault('HTTP_TRANSPORT_28');return nc_good();});
    $delays=[];for($i=0;$i<4;$i++){$r=$n->poll();if($i<3){$delays[]=$r['retry_in_seconds'];$wall+=$r['retry_in_seconds'];$mono+=$r['retry_in_seconds'];}}
    nc_ok($plans===[[10,20],[20,40],[40,80],[60,120]]&&$delays===[30.0,60.0,120.0]);nc_ok($r['connected']&&$r['recoveries']===1&&$r['timeouts']===3&&$wall-$start>120);
    $simulation=['scope'=>'synthetic responses and virtual elapsed time','socket_plans'=>$plans,'retry_delays_seconds'=>$delays,'virtual_total_seconds'=>$wall-$start,'attempts'=>$r['attempts'],'state'=>$r['state']];
});
nc_case('106 attempts continue without a count cap; bounded history preserves cumulative counts',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$calls=0;$n=nc_client($s,'many',$wall,$mono,function()use(&$calls,&$wall,&$mono){$calls++;$wall+=.25;$mono+=.25;return $calls<=105?['status'=>503,'headers'=>[],'body'=>'']:nc_good();});
    for($i=0;$i<106;$i++){$r=$n->poll();if(!$r['connected']){$wall+=$r['retry_in_seconds'];$mono+=$r['retry_in_seconds'];}}
    nc_ok($r['connected']&&$r['attempts']===106&&$r['failures']===105&&count($r['history'])===20&&$r['max_attempts']===null&&$r['recoveries']===1);
});
nc_case('early polling and reopening a run preserve cooldown, counters and original receipt',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$calls=0;$f=function()use(&$calls){$calls++;throw new MonFault('HTTP_TRANSPORT_7');};$n=nc_client($s,'resume',$wall,$mono,$f);$a=$n->poll();$n->poll();
    $wall+=5;$mono+=5;$n=nc_client($s,'resume',$wall,$mono,$f);$b=$n->poll();nc_ok($calls===1&&$b['attempts']===1&&$b['retry_in_seconds']===25.&&$b['received_at']===$a['received_at']);
    $wall+=25;$mono+=25;$c=$n->poll();nc_ok($calls===2&&$c['next_connect_seconds']===40);
});
nc_case('one client lock prevents overlapping probes across different run IDs',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$calls=0;$lock=fopen($s->path('nas-connection.lock'),'c+');flock($lock,LOCK_EX);
    $n=nc_client($s,'lock',$wall,$mono,function()use(&$calls){$calls++;return nc_good();});$r=$n->poll();nc_ok($r['state']==='client_busy'&&$calls===0);flock($lock,LOCK_UN);fclose($lock);nc_ok($n->poll()['connected']&&$calls===1);
});
nc_case('Retry-After seconds exceed the usual 300 second backoff without premature retries',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$calls=0;$n=nc_client($s,'after-seconds',$wall,$mono,function()use(&$calls){$calls++;return ['status'=>429,'headers'=>['Retry-After'=>'900'],'body'=>''];});$r=$n->poll();nc_ok($r['retry_in_seconds']===900.);$wall+=300;$n->poll();nc_ok($calls===1);
});
nc_case('Retry-After HTTP date is respected for 503',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$date=gmdate('D, d M Y H:i:s',1700000600).' GMT';$n=nc_client($s,'after-date',$wall,$mono,static function()use($date){return ['status'=>503,'headers'=>['retry-after'=>$date],'body'=>''];});nc_ok($n->poll()['retry_in_seconds']===600.);
});
nc_case('DNS, connection, TLS and transient server faults remain resumable',function(){
    foreach(['HTTP_TRANSPORT_5','HTTP_TRANSPORT_6','HTTP_TRANSPORT_7','HTTP_TRANSPORT_35','HTTP_TRANSPORT_60','HTTP_TRANSPORT_52','HTTP_TRANSPORT_56','HTTP_408','HTTP_429','HTTP_503']as $code){$s=nc_store();$wall=1700000000.;$mono=0.;$n=nc_client($s,$code,$wall,$mono,static function()use($code){throw new MonFault($code);});$r=$n->poll();nc_ok($r['state']==='retry_wait'&&!$r['connected']&&$r['retry_in_seconds']>=30.);}
});
nc_case('legacy JSON is a successful connection but requires a compatible processor',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$calls=0;$n=nc_client($s,'legacy-json',$wall,$mono,function()use(&$calls){$calls++;return nc_legacy();});$r=$n->poll();nc_ok($r['connected']&&!$r['compatible']&&$r['state']==='connected_legacy'&&$r['processor_version']==='1.5.0');$n->poll();nc_ok($calls===1);
});
nc_case('known legacy MON HTML is reachable; login HTML is not a successful MON API',function(){
    $wall=1700000000.;$mono=0.;$n=nc_client(nc_store(),'html-mon',$wall,$mono,static function(){return ['status'=>200,'body'=>'<title>mon 모니터</title><header><h1>mon 모니터</h1><span>v1.5.0</span></header>'];});nc_ok($n->poll()['state']==='connected_legacy');
    $n=nc_client(nc_store(),'html-login',$wall,$mono,static function(){return ['status'=>200,'body'=>'<html><title>Sign in</title></html>'];});$r=$n->poll();nc_ok(!$r['connected']&&$r['state']==='blocked'&&$r['last_code']==='NAS_RESPONSE_CONTRACT_INVALID');
});
nc_case('HTTP 200 malformed or unrelated JSON never becomes normal completion',function(){
    $wall=1700000000.;$mono=0.;foreach(['{"ok":true}','not json','{"ok":true,"data":{"version":"1.5.0"}}']as $i=>$body){$n=nc_client(nc_store(),'wrong-'.$i,$wall,$mono,static function()use($body){return ['status'=>200,'body'=>$body];});$r=$n->poll();nc_ok(!$r['connected']&&$r['state']==='blocked');}
});
nc_case('a mismatched bootstrap version is rejected',function(){
    $wall=1700000000.;$mono=0.;$r=nc_good();$j=mon_decode($r['body']);$j->result->header->processor_version='1.5.0';$r['body']=mon_json($j,false);$n=nc_client(nc_store(),'version-mismatch',$wall,$mono,static function()use($r){return $r;});nc_ok(!$n->poll()['connected']);
});
nc_case('valid bootstrap with no market data means connected, not analysis complete',function(){
    $wall=1700000000.;$mono=0.;$n=nc_client(nc_store(),'needs-data',$wall,$mono,static function(){return nc_good();});$r=$n->poll();nc_ok($r['connected']&&$r['compatible']&&!isset($r['fully_complete'])&&!isset($r['new_analysis_complete']));
});
nc_case('401/403 require corrective action; explicit resume retains the same run and counts',function(){
    foreach([401,403]as $http){$s=nc_store();$wall=1700000000.;$mono=0.;$calls=0;$allowed=false;$n=nc_client($s,'auth-'.$http,$wall,$mono,function()use(&$calls,&$allowed,$http){$calls++;return $allowed?nc_good():['status'=>$http,'body'=>''];});$a=$n->poll();$wall+=10000;$n->poll();nc_ok($a['state']==='blocked'&&$calls===1);$allowed=true;$n->resume();$b=$n->poll();nc_ok($b['connected']&&$b['attempts']===2&&$b['received_at']===$a['received_at']);}
});
nc_case('corrupt journal is reported and never silently resets retry state',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$n=nc_client($s,'corrupt',$wall,$mono,static function(){return nc_good();});$n->poll();$name='nas-'.hash('sha256','corrupt').'.php';$j=$s->readPrivate($name);$j->state->attempts=0;$s->writePrivate($name,$j);
    try{$n->poll();throw new RuntimeException('corruption accepted');}catch(MonFault $e){nc_ok($e->faultCode==='NAS_STATE_INVALID');}
});
nc_case('process loss preserves the attempt and resumes only after a new cooldown',function(){
    $s=nc_store();$code='require '.var_export(dirname(__DIR__).'/mon.php',true).';$n=new MonNasConnection(new MonStore($argv[1]),"lost",static function(){exit(0);},static function(){return 1700000000.;});$n->poll();';$child=nc_child($code,[$s->dir]);nc_ok($child[0]===0);
    $wall=1700000010.;$mono=0.;$calls=0;$n=nc_client($s,'lost',$wall,$mono,function()use(&$calls){$calls++;return nc_good();});$r=$n->poll();nc_ok($r['attempts']===1&&$r['state']==='retry_wait'&&$r['last_code']==='NAS_REQUEST_UNCONFIRMED'&&$r['history'][0]->seconds===null&&$calls===0);
    $wall+=30;$mono+=30;$r=$n->poll();nc_ok($r['connected']&&$r['attempts']===2&&$calls===1);
});
nc_case('explicit stop interrupts once and is not automatically retried',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$calls=0;$n=nc_client($s,'stop',$wall,$mono,function()use(&$calls,&$wall,&$mono){$calls++;$wall+=2;$mono+=2;throw new MonStop('requested');});
    try{$n->poll();throw new RuntimeException('stop ignored');}catch(MonStop $e){}$r=$n->status();nc_ok($r['state']==='interrupted'&&$r['total_seconds']===2.&&$r['history'][0]->code==='INTERRUPTED');$n->poll();nc_ok($calls===1);
});
nc_case('successful elapsed statistics increase the initial socket for the next run',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$n=nc_client($s,'learn-1',$wall,$mono,function()use(&$wall,&$mono){$wall+=9.5;$mono+=9.5;return nc_good();});$n->poll();$n=nc_client($s,'learn-2',$wall,$mono,static function(){return nc_good();});$r=$n->status();nc_ok($r['next_connect_seconds']===15&&$r['next_request_seconds']===30&&$r['learned_p90_seconds']===9.5);
});
nc_case('journals contain only metadata, stay PHP-protected, and do not create launch grants',function(){
    $s=nc_store();$wall=1700000000.;$mono=0.;$n=nc_client($s,'private',$wall,$mono,static function(){return nc_legacy();});$n->poll();$files=glob($s->dir.'/*');foreach($files as $path){$raw=file_get_contents($path);nc_ok(strpos($raw,'never-persist-this')===false&&strpos($raw,'KIS_APP_SECRET')===false);if(substr($path,-4)==='.php')nc_ok(strpos($raw,MON_SETTINGS_PREFIX)===0);}nc_ok(!glob($s->dir.'/*launch*')&&!is_file($s->path('status.json'))&&!is_file($s->path('stop')));
});
nc_case('missing curl transports are an environment blocker, not a network failure or fake success',function(){
    $s=nc_store();$code='require '.var_export(dirname(__DIR__).'/mon.php',true).';echo mon_json((new MonNasConnection(new MonStore($argv[1]),"no-transport"))->poll(),false);';
    $p=proc_open([PHP_BINARY,'-n','-d','disable_functions=proc_open','-r',$code,$s->dir],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);nc_ok($exit===0,$err);$r=mon_decode($raw);nc_ok($r->state==='blocked'&&$r->last_code==='NAS_CLIENT_TRANSPORT_REQUIRED'&&$r->attempts===0&&!$r->connected);
});
$failed=count(array_filter($cases,static function($c){return !$c['pass'];}));
echo mon_json(['processor_version'=>MON_VERSION,'php'=>PHP_VERSION,'scope'=>'new NAS recovery behavior only; injected responses and virtual clock except isolated native process checks; no live requests',
    'cases_total'=>count($cases),'passed'=>count($cases)-$failed,'failed'=>$failed,'test_seconds'=>round(mon_monotonic()-$started,6),'simulation'=>$simulation,'cases'=>$cases]);
foreach($dirs as $dir){foreach(glob($dir.'/*')as $p)@unlink($p);@rmdir($dir);}exit($failed?1:0);

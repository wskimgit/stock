<?php
declare(strict_types=1);
/* ERR-C D2.2 changed-boundary synthetic regression; no real network/orders. */
require __DIR__.'/mon_reinforcement_fixture.php';
$cases=[];$dirs=[];
function d22_assert($v,string $m):void{if(!$v)throw new RuntimeException($m);}
function d22_dir():string{global $dirs;$d=sys_get_temp_dir().'/mon-d22-'.bin2hex(random_bytes(6));mkdir($d,0700);$dirs[]=$d;return $d;}
function d22_case(string $name,callable $f):void{
 global $cases;
 MonExecution::$active=null;MonExecution::$store=null;MonPreparation::reset();
 try{$f();$cases[]=['name'=>$name,'pass'=>true];}
 catch(Throwable $e){$cases[]=['name'=>$name,'pass'=>false,'error'=>get_class($e).': '.$e->getMessage()];}
}
class D22Git {
 public $git;public $mail=[];public $mailSha=[];public $putData=0;public $putResponse=0;
 public $loseDataAck=false;public $loseResponseAck=false;public $dropDataBeforeWrite=false;
 public function __construct($data){$this->git=new ReinforcementGit($data);}
 public function transport($method,$url,$headers,$body,$timeout):array {
  $path=(string)parse_url($url,PHP_URL_PATH);
  $prefix='/repos/wskimgit/stock';
  if(strpos($path,$prefix)!==0)throw new RuntimeException('unexpected host path');
  $path=substr($path,strlen($prefix));
  if(preg_match('~^/contents/(mon_chatgpt_handoff\.json|mon_chatgpt_response\.json)$~',$path,$m)){
   $key=$m[1];
   if($method==='GET')return ['status'=>isset($this->mail[$key])?200:404,'headers'=>[],'body'=>$this->mail[$key]??'{}'];
   if($method==='PUT'){
    if($key===MonChatGPTBridge::OUTBOX)$this->putResponse++;
    $p=mon_decode($body);
    if(mon_get($p,'sha')!==($this->mailSha[$key]??null))return ['status'=>409,'headers'=>[],'body'=>'{}'];
    $v=base64_decode($p->content,true);if(!is_string($v))throw new RuntimeException('bad mailbox base64');
    $sha=MonRun::gitSha($v);$this->mail[$key]=$v;$this->mailSha[$key]=$sha;
    if($this->loseResponseAck&&$key===MonChatGPTBridge::OUTBOX){$this->loseResponseAck=false;throw new MonFault('HTTP_TRANSPORT_28');}
    return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['content'=>(object)['sha'=>$sha]])];
   }
  }
  if($method==='PUT'&&$path==='/contents/mon_data.json'){
   $this->putData++;
   if($this->dropDataBeforeWrite)throw new MonFault('HTTP_TRANSPORT_28');
   $p=mon_decode($body);
   $current=MonRun::gitSha(MonRun::json($this->git->data()));
   if($p->sha!==$current)return ['status'=>409,'headers'=>[],'body'=>'{}'];
   $raw=base64_decode($p->content,true);
   if(!is_string($raw))throw new RuntimeException('bad source base64');
   $this->git->setSource(mon_decode($raw));
   // GitHub stores the exact transmitted bytes, not canonicalized fixture JSON.
   $sha=MonRun::gitSha($raw);
   $this->git->blobs[$sha]=$raw;
   $tree=$this->git->commits[$this->git->head]['tree'];
   $this->git->trees[$tree]['mon_data.json']=$sha;
   if($this->loseDataAck){$this->loseDataAck=false;throw new MonFault('HTTP_TRANSPORT_28');}
   return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['content'=>(object)['sha'=>MonRun::gitSha($raw)]])];
  }
  return $this->git->transport($method,$url,$headers,$body,$timeout);
 }
}
d22_case('S1 read and write timeout independently bounded',function(){
 $d=reinforcement_fixture(1);$p=MonData::settings($d->watchlist);
 d22_assert($p['github_http_timeout_seconds']>=5&&$p['github_write_timeout_seconds']===12,'configured GET/PUT timeout mismatch');
});
d22_case('S1 GitHub PUT committed but ACK lost: one PUT, SHA readback',function(){
 $d=reinforcement_fixture(1);$fake=new D22Git($d);$fake->loseDataAck=true;
 $dir=d22_dir();$cfg=progress_test_config($dir);$http=new MonHttp(null,[$fake,'transport']);$gh=new MonGitHub($http,$cfg);
 $latest=$gh->read(microtime(true)+30);
 $sid=$d->watchlist->symbols[0]->symbol_id;
 $batch=(object)['_detail_only'=>true,'detail_responses'=>(object)[$sid=>progress_response($d,$sid)]];
 $res=$gh->publish($batch,MonData::signature($d->watchlist),microtime(true)+30,MonData::settings($d->watchlist),$latest);
 d22_assert($res['written']===true&&($res['recovered_ack']??false)===true,'lost ack was not recovered');
 d22_assert($fake->putData===1,'ambiguous write caused duplicate PUT');
 $tree=$fake->git->commits[$fake->git->head]['tree'];
 d22_assert($res['sha']===$fake->git->trees[$tree]['mon_data.json'],'remote hash mismatch');
});
d22_case('S1 GitHub PUT failed before commit: preserve pending for later, no duplicate PUT',function(){
 $d=reinforcement_fixture(1);$fake=new D22Git($d);$fake->dropDataBeforeWrite=true;
 $dir=d22_dir();$cfg=progress_test_config($dir);$http=new MonHttp(null,[$fake,'transport']);$gh=new MonGitHub($http,$cfg);
 $source=$gh->read(microtime(true)+30);$sid=$d->watchlist->symbols[0]->symbol_id;
 $batch=(object)['_detail_only'=>true,'detail_responses'=>(object)[$sid=>progress_response($d,$sid)]];
 try{$gh->publish($batch,MonData::signature($d->watchlist),microtime(true)+30,MonData::settings($d->watchlist),$source);throw new RuntimeException('false success');}
 catch(MonFault $e){d22_assert($e->faultCode==='HTTP_TRANSPORT_28','wrong failure');}
 d22_assert($fake->putData===1,'unexpected second PUT');
 d22_assert($source['sha']===MonRun::gitSha(MonRun::json($fake->git->data())),'remote data changed');
});
d22_case('S2 real CB requests override obsolete conflicting journal and do not write mon_data',function(){
 $d=reinforcement_fixture(2);
 $ids=array_map(static function($x){return $x->symbol_id;},$d->watchlist->symbols);
 $q=(object)['contract'=>MonChatGPTBridge::CONTRACT,'stage'=>'candidates','request_id'=>'d22-synthetic-1','candidate_ids'=>$ids];
 $source=reinforcement_context($d);$items=[];
 foreach($d->watchlist->symbols as $s)$items[]=(object)['symbol'=>$s,
   'request_id'=>'cb.'.substr(hash('sha256',$q->request_id.'|'.$s->symbol_id),0,48),'force_refresh'=>true,'wait_seconds'=>120];
 $reg=MonDetails::registerMany($d,$source,$items);
 $registered=mon_decode($reg['files'][0]['content']);$fake=new D22Git($registered);
 $msg=MonRun::json($q);$fake->mail[MonChatGPTBridge::INBOX]=$msg;
 $fake->mailSha[MonChatGPTBridge::INBOX]=MonRun::gitSha($msg);
 $dir=d22_dir();$store=new MonStore($dir);$cfg=progress_test_config($dir);
 $store->writePrivate(MonChatGPTBridge::STATE,(object)[
   'pending_candidate_request_sha'=>$fake->mailSha[MonChatGPTBridge::INBOX],
   'pending_candidate_job_id'=>str_repeat('f',64)
 ]);
 $http=new MonHttp(null,[$fake,'transport']);$gh=new MonGitHub($http,$cfg);
 $latest=$gh->read(microtime(true)+30);
 $next=MonChatGPTBridge::poll($store,$http,$cfg,$gh,$latest,microtime(true)+30);
 $out=mon_decode($fake->mail[MonChatGPTBridge::OUTBOX]);
 d22_assert($out->status==='registered_existing'&&$out->registered_count===2,'source identity was not authoritative');
 d22_assert($next['sha']===$latest['sha']&&$fake->putData===0,'already registered re-posted data');
 $state=$store->readPrivate(MonChatGPTBridge::STATE);
 d22_assert(!isset($state->pending_candidate_job_id)&&$state->candidate_request_sha===$fake->mailSha[MonChatGPTBridge::INBOX],'stale job still locks request');
});
d22_case('S4 repeated details from quote-only source do not create new mailbox commit',function(){
 $d=reinforcement_fixture(2);
 $ids=array_map(static function($s){return $s->symbol_id;},$d->watchlist->symbols);
 $q=(object)['contract'=>MonChatGPTBridge::CONTRACT,'stage'=>'candidates','request_id'=>'d22-synthetic-quote','candidate_ids'=>$ids];
 $msg=MonRun::json($q);$fake=new D22Git($d);$fake->mail[MonChatGPTBridge::INBOX]=$msg;
 $fake->mailSha[MonChatGPTBridge::INBOX]=MonRun::gitSha($msg);
 $dir=d22_dir();$store=new MonStore($dir);$cfg=progress_test_config($dir);
 $store->writePrivate(MonChatGPTBridge::STATE,(object)['candidate_request_sha'=>$fake->mailSha[MonChatGPTBridge::INBOX]]);
 $http=new MonHttp(null,[$fake,'transport']);$sha=MonRun::gitSha(MonRun::json($d));
 MonChatGPTBridge::export($store,$http,$cfg,$d,$sha,microtime(true)+30);
 d22_assert($fake->putResponse===1,'first report missing');
 $latest=mon_decode(MonRun::json($d));$latest->collection->collection_id='synthetic-quote-only-change';
 $fresh=MonRun::gitSha(MonRun::json($latest));
 d22_assert($fresh!==$sha,'test source not changed');
 MonChatGPTBridge::export($store,$http,$cfg,$latest,$fresh,microtime(true)+30);
 d22_assert($fake->putResponse===1,'quote-only source republished identical detail facts');
 $state=$store->readPrivate(MonChatGPTBridge::STATE);
 d22_assert($state->last_detail_source_sha===$sha&&preg_match('/^[a-f0-9]{40}$/',$state->last_detail_receipt_sha),'original receipt not retained');
});
d22_case('S2 lost mailbox response ACK is recovered from exact hash',function(){
 $d=reinforcement_fixture(1);$fake=new D22Git($d);$fake->loseResponseAck=true;
 $sid=$d->watchlist->symbols[0]->symbol_id;
 $q=(object)['contract'=>MonChatGPTBridge::CONTRACT,'stage'=>'candidates','request_id'=>'d22-lost-mailbox','candidate_ids'=>[$sid]];
 $msg=MonRun::json($q);$fake->mail[MonChatGPTBridge::INBOX]=$msg;$fake->mailSha[MonChatGPTBridge::INBOX]=MonRun::gitSha($msg);
 $dir=d22_dir();$store=new MonStore($dir);$cfg=progress_test_config($dir);$http=new MonHttp(null,[$fake,'transport']);
 $gh=new MonGitHub($http,$cfg);$latest=$gh->read(microtime(true)+30);
 $result=MonChatGPTBridge::poll($store,$http,$cfg,$gh,$latest,microtime(true)+30);
 d22_assert($fake->putResponse===1,'ambiguous mailbox ACK created duplicate response');
 d22_assert(isset($fake->mail[MonChatGPTBridge::OUTBOX]),'mailbox receipt missing');
});
d22_case('S3 provider 404/missing history retries other routes, then checkpoint-blocks',function(){
 $d=reinforcement_fixture(1);$req=$d->watchlist->detail_requests->{$d->watchlist->symbols[0]->symbol_id};
 $req=mon_decode(MonRun::json($req));$req->symbol->source_codes->kis=null;
 $dir=d22_dir();$cfg=progress_test_config($dir);$store=new MonStore($dir);
 $calls=0;
 $http=new MonHttp(null,static function($method,$url,$headers,$body,$timeout)use(&$calls){$calls++;
     return ['status'=>404,'headers'=>[],'body'=>'{}'];});
 $collector=new MonCollector($http,$store,$cfg);$daily=new MonDaily($http,$collector,$cfg,$store);
 $settings=['http_timeout_seconds'=>2,'request_spacing_seconds'=>0,'detail_symbol_budget_seconds'=>8];
 $expected=['DETAIL_ALTERNATIVE_SOURCE_PENDING','DETAIL_ALTERNATIVE_SOURCE_PENDING','DETAIL_PROVIDER_UNAVAILABLE'];
 foreach($expected as $i=>$wanted){try{$daily->fetch($req,null,microtime(true)+20,$settings);throw new RuntimeException('unexpected source success');}
     catch(MonFault $e){d22_assert($e->faultCode===$wanted,'route '.$i.' error '.$e->faultCode);}}
 d22_assert(MonDaily::retryable($expected[0])&&!MonDaily::retryable($expected[2]),'retry/block policy wrong');
 d22_assert($calls>=3,'alternatives not queried');
});
$passed=count(array_filter($cases,static function($c){return $c['pass'];}));
echo json_encode(['suite'=>'MON-ERRC-D2.2','passed'=>$passed,'failed'=>count($cases)-$passed,'cases'=>$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
foreach($dirs as $dir){
 $files=glob($dir.'/*');foreach($files?:[] as $file)if(is_file($file))@unlink($file);@rmdir($dir);
}
exit($passed===count($cases)?0:1);

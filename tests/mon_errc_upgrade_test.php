<?php
declare(strict_types=1);
require_once __DIR__.'/mon_reinforcement_fixture.php';
$cases=[];$dirs=[];$observed=[];$started=mon_monotonic();
function eu_ok($v,string $message=''):void{if(!$v)throw new RuntimeException($message?:'assertion failed');}
function eu_fault(callable $f,string $code):void{try{$f();}catch(MonFault $e){eu_ok($e->faultCode===$code,$e->faultCode);return;}throw new RuntimeException('missing '.$code);}
function eu_case(string $name,callable $f):void{global $cases;MonExecution::$active=null;MonExecution::$store=null;MonPreparation::reset();
 try{$f();$cases[]=['case'=>$name,'pass'=>true];}catch(Throwable $e){$cases[]=['case'=>$name,'pass'=>false,'error'=>$e->getMessage()];}}
function eu_store():MonStore{global $dirs;$dir=sys_get_temp_dir().'/mon-errc185-'.bin2hex(random_bytes(6));mkdir($dir,0700);$dirs[]=$dir;return new MonStore($dir);}
function eu_transport($p){return mon_decode(gzdecode(base64_decode($p->data,true)));}
function eu_copy($v){return mon_decode(MonRun::json($v));}
function eu_snapshot():stdClass{
 $d=reinforcement_fixture(1);$s=$d->_test_snapshots->{$d->watchlist->symbols[0]->symbol_id};
 $records=(object)['2026-10-09'=>(object)['values'=>[1.00000001,2,3,4,5],'source'=>'SYNTHETIC_ONLY']];
 $s->history_metadata->raw_records=$records;$s->origin->raw_records=$records;$s->origin->history_metadata=eu_copy($s->history_metadata);return $s;
}
function eu_worker(MonStore $store,int &$calls):MonDetailWorker{
 $cfg=progress_test_config($store->dir);$http=new MonHttp(null,static function()use(&$calls){$calls++;throw new MonFault('TEST_UNEXPECTED_NETWORK');});
 return new MonDetailWorker(new MonDaily($http,new MonCollector($http,$store,$cfg),$cfg,$store),$store);
}
if(defined('MON_ERRC_LIBRARY_ONLY')&&MON_ERRC_LIBRARY_ONLY)return;
eu_case('A01 exact snapshot roundtrip removes both duplicate locations',function(){
 $s=eu_snapshot();$before=MonRun::json($s);$packed=MonDetails::pack($s);$transport=eu_transport($packed);
 eu_ok(count($transport->transport_raw_records_deduplicated->restore_paths)===2);
 eu_ok(MonRun::json(MonDetails::unpack($packed))===$before&&MonRun::json($s)===$before);
});
eu_case('A02 legacy marker true and marker-free payloads are readable',function(){
 $s=eu_snapshot();$old=eu_copy($s);unset($old->origin->raw_records);$old->transport_raw_records_deduplicated=true;
 eu_ok(MonRun::json(MonDetails::unpack(MonRun::pack($old,'gzip+base64')))===MonRun::json($s));
 eu_ok(MonRun::json(MonDetails::unpack(MonRun::pack($s,'gzip+base64')))===MonRun::json($s));
});
eu_case('A03 small raw value differences remain distinct without rounding',function(){
 $s=eu_snapshot();$s->origin->history_metadata->raw_records->{'2026-10-09'}->values[0]=1.00000002;
 $p=MonDetails::pack($s);$v=eu_transport($p);eu_ok($v->transport_raw_records_deduplicated->restore_paths===['origin.raw_records']);
 eu_ok(MonRun::json(MonDetails::unpack($p))===MonRun::json($s));
});
eu_case('A04 altered raw values with repacked outer hash fail exact reconstruction',function(){
 $v=eu_transport(MonDetails::pack(eu_snapshot()));$v->history_metadata->raw_records->{'2026-10-09'}->values[0]+=0.00000001;
 eu_fault(static function()use($v){MonDetails::unpack(MonRun::pack($v,'gzip+base64'));},'DETAIL_PAYLOAD_INVALID');
});
eu_case('A04 arbitrary restore paths and occupied destinations are rejected',function(){
 $v=eu_transport(MonDetails::pack(eu_snapshot()));$v->transport_raw_records_deduplicated->restore_paths=['watchlist.settings'];
 eu_fault(static function()use($v){MonDetails::unpack(MonRun::pack($v,'gzip+base64'));},'DETAIL_PAYLOAD_INVALID');
 $v=eu_transport(MonDetails::pack(eu_snapshot()));$v->origin->raw_records=null;
 eu_fault(static function()use($v){MonDetails::unpack(MonRun::pack($v,'gzip+base64'));},'DETAIL_PAYLOAD_INVALID');
});
eu_case('A04 modified nonraw snapshot fields fail the restored snapshot hash',function(){
 $v=eu_transport(MonDetails::pack(eu_snapshot()));$v->metrics->MA5+=1;
 eu_fault(static function()use($v){MonDetails::unpack(MonRun::pack($v,'gzip+base64'));},'DETAIL_PAYLOAD_HASH_INVALID');
});
eu_case('A04 oversized restored input does not bypass the original cap',function(){
 $s=eu_snapshot();$s->extra=str_repeat('x',262145);eu_fault(static function()use($s){MonDetails::pack($s);},'DETAIL_PAYLOAD_TOO_LARGE');
});
function eu_bad_batch():stdClass{
 $d=reinforcement_fixture(2);$p=MonRun::payload($d);$sid=$d->watchlist->symbols[0]->symbol_id;$text='';
 for($i=0;$i<700;$i++)$text.=hash('sha256','synthetic-uncompressible-'.$i);
 $p->history_metadata->$sid->raw_records=(object)['opaque_synthetic_fixture'=>$text];$d->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');return $d;
}
eu_case('A05 failed first symbol checkpoints and the next symbol packs once',function(){
 $s=eu_store();$d=eu_bad_batch();$calls=0;$worker=eu_worker($s,$calls);
 $a=$worker->processOne($d,microtime(true)+5,[]);$d->collection->detail_responses=$a['responses'];
 eu_ok($a['response']->state==='serialization_pending'&&!$a['response']->usable&&$a['response']->payload===null);
 eu_ok(is_file($s->path('detail-serialization-'.$a['response']->request_hash.'.php')));
 $b=$worker->processOne($d,microtime(true)+5,[]);eu_ok($b['response']->symbol_id!==$a['response']->symbol_id&&$b['response']->state==='done');
 eu_ok($b['response']->serialization_attempts===1&&$b['response']->attempts===0&&$calls===0);
 $d->collection->detail_responses=$b['responses'];$progress=MonProgress::build($d,time());eu_ok($progress['counts']['serialization_pending']===1&&$progress['counts']['done']===1);
 eu_ok(!$worker->processOne($d,microtime(true)+5,[])['changed']);
});
eu_case('A06 changed source repairs the same request without fetching the failed raw again',function(){
 $s=eu_store();$d=eu_bad_batch();$calls=0;$worker=eu_worker($s,$calls);$a=$worker->processOne($d,microtime(true)+5,[]);$d->collection->detail_responses=$a['responses'];
 $id=$a['response']->request_id;$sid=$a['response']->symbol_id;$received=$d->watchlist->detail_requests->$sid->requested_at;
 $p=MonRun::payload($d);unset($p->history_metadata->$sid->raw_records);$d->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
 $b=$worker->processOne($d,microtime(true)+5,[]);eu_ok($b['response']->state==='done'&&$b['response']->request_id===$id&&$calls===0);
 eu_ok($d->watchlist->detail_requests->$sid->requested_at===$received&&$b['response']->serialization_attempts===2);
});
eu_case('A07 blocked and conflicting symbols remain pending without immediate network retries',function(){
 $d=reinforcement_fixture(2);$sid=$d->watchlist->symbols[0]->symbol_id;$r=$d->watchlist->detail_requests->$sid;
 foreach(['blocked','conflict']as $state){$v=progress_response($d,$sid);$v->state=$state;$v->recovery_signature=MonDetails::recoverySignature($d,$r);
 eu_ok(MonDetails::retryPending($v)&&!MonDetails::workDue($d,$r,$v,time()+100000));}
});
eu_case('A01 observed five saved snapshots fit the cap and preserve every field',function()use(&$observed){
 $path=getenv('MON_ERRC_OBSERVED_SOURCE');if(!$path)throw new RuntimeException('MON_ERRC_OBSERVED_SOURCE required');
 $raw=file_get_contents($path);$d=mon_decode($raw);$p=MonRun::payload($d);$requests=MonDetails::requests($d);
 $reference=json_decode(file_get_contents(__DIR__.'/mon_errc_observed_fixture.json'),true);eu_ok(MonRun::gitSha($raw)===$reference['source_blob_sha']);
 foreach(['JP|TSE|6098','KR|KOSPI|006400','KR|KOSPI|051910','KR|KOSPI|096770','US|NASDAQ|WMT']as $sid){
  $r=mon_get($requests,$sid);eu_ok($r instanceof stdClass,'missing observed symbol '.$sid);$s=MonDetails::cached($d,$r->symbol,$p);eu_ok($s instanceof stdClass);
  $packed=MonDetails::pack($s);$same=MonRun::json($s)===MonRun::json(MonDetails::unpack($packed));eu_ok($same&&strlen($packed->data)<=MonDetails::MAX_PACKED,$sid);
  eu_ok(hash('sha256',MonRun::json($s))===$reference['snapshot_sha256'][$sid],'numeric or original source fields changed from native 1.8.4: '.$sid);
  $observed[]=['symbol_id'=>$sid,'packed_base64_bytes'=>strlen($packed->data),'snapshot_sha256'=>hash('sha256',MonRun::json($s)),'roundtrip_exact'=>$same];
 }
});
eu_case('A02 the actual 1.8.4 reader safely rejects the new object marker',function(){
 $baseline=getenv('MON_ERRC_BASELINE_CODE');eu_ok(is_string($baseline)&&is_file($baseline),'MON_ERRC_BASELINE_CODE required');
 $program='require $argv[1];try{MonDetails::unpack(mon_decode(stream_get_contents(STDIN)));echo "accepted";}catch(MonFault $e){echo $e->faultCode;}';
 $pipes=[];$process=proc_open([PHP_BINARY,'-r',$program,$baseline],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);eu_ok(is_resource($process));
 fwrite($pipes[0],MonRun::json(MonDetails::pack(eu_snapshot())));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
 eu_ok($exit===0&&$out==='DETAIL_PAYLOAD_INVALID'&&$err==='');
});
eu_case('A04 malformed marker field types produce typed faults',function(){
 $v=eu_transport(MonDetails::pack(eu_snapshot()));$v->transport_raw_records_deduplicated->restore_paths=[new stdClass()];
 eu_fault(static function()use($v){MonDetails::unpack(MonRun::pack($v,'gzip+base64'));},'DETAIL_PAYLOAD_INVALID');
});
if(defined('MON_ERRC_LIBRARY_ONLY')&&MON_ERRC_LIBRARY_ONLY)return;
$failed=count(array_filter($cases,static function($v){return !$v['pass'];}));
echo json_encode(['version'=>MON_VERSION,'cases_total'=>count($cases),'passed'=>count($cases)-$failed,'failed'=>$failed,'seconds'=>mon_monotonic()-$started,
 'peak_memory_bytes'=>memory_get_peak_usage(true),'external_api_calls'=>0,'nas_deployed'=>false,'observed'=>$observed,'cases'=>$cases],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
foreach($dirs as $dir){foreach(glob($dir.'/*')as $file)unlink($file);rmdir($dir);}exit($failed?1:0);

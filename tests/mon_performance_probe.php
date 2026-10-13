<?php
declare(strict_types=1);
require $argv[1];
$start=mon_monotonic();$mode=$argv[2]??'foreign';$temp=sys_get_temp_dir().'/mon-perf-'.bin2hex(random_bytes(6));mkdir($temp,0700);$store=new MonStore($temp);
try{
 if($mode==='foreign'){
  $date=new DateTimeImmutable('2026-10-09');$days=[];for($i=0;$i<70;$i++){array_unshift($days,$date->format('Y-m-d'));do{$date=$date->modify('-1 day');}while((int)$date->format('N')>5);}$bars=[];foreach($days as $i=>$day)$bars[]=[$day,100.0+$i,102.0+$i,98.0+$i,100.0+$i,1000.0];
  $cfg=new MonConfig('',$temp);$cfg->values['KIS_APP_KEY']='synthetic-key';$cfg->values['KIS_APP_SECRET']='synthetic-secret';$cfg->values['GITHUB_TOKEN']='synthetic-token';$calls=[];$network=0.;$http=new MonHttp(null,static function($method,$url)use($bars,&$calls,&$network){$host=parse_url($url,PHP_URL_HOST);$calls[]=[$method,$host];$network+=$method==='POST'?.5:($host==='openapi.koreainvestment.com'?2.0:1.0);
   if($method==='POST')return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['access_token'=>'synthetic-token','expires_in'=>7200])];
   if($host==='openapi.koreainvestment.com'){$rows=[];foreach(array_reverse($bars)as $bar)$rows[]=(object)['xymd'=>str_replace('-','',$bar[0]),'open'=>$bar[1],'high'=>$bar[2],'low'=>$bar[3],'clos'=>$bar[4],'tvol'=>$bar[5]];return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['rt_cd'=>'0','output2'=>$rows])];}
   if($host!=='query1.finance.yahoo.com')throw new RuntimeException('unexpected synthetic provider');$ticker=rawurldecode(basename((string)parse_url($url,PHP_URL_PATH)));$q=new stdClass();foreach(['open'=>1,'high'=>2,'low'=>3,'close'=>4,'volume'=>5]as $key=>$col)$q->$key=array_column($bars,$col);$times=[];foreach($bars as $bar)$times[]=(new DateTimeImmutable($bar[0].'T16:00:00Z'))->getTimestamp();
   return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['chart'=>(object)['error'=>null,'result'=>[(object)['meta'=>(object)['symbol'=>$ticker,'exchangeTimezoneName'=>'America/New_York','currency'=>'USD','exchangeName'=>'NMS'],'timestamp'=>$times,'indicators'=>(object)['quote'=>[$q]]]]]])];
  });$daily=new MonDaily($http,new MonCollector($http,$store,$cfg),$cfg,$store);$hashes=[];$snapshots=[];
  for($i=0;$i<80;$i++){$ticker='PF'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$sid='US|NASDAQ|'.$ticker;$symbol=(object)['symbol_id'=>$sid,'country'=>'US','exchange'=>'NASDAQ','symbol'=>$ticker,'name'=>$ticker,'currency'=>'USD','is_held'=>false,'position'=>null,'tick_size'=>.01,'source_codes'=>(object)['kis'=>(object)['exchange_code'=>'NAS','symbol'=>$ticker],'yahoo'=>$ticker]];
   $request=(object)['symbol'=>$symbol,'symbol_id'=>$sid,'request_id'=>'probe-'.$i,'target_completed_date'=>'2026-10-09','deadline_at'=>mon_iso(time()+10000)];$request->request_hash=MonDetails::requestHash($request);$s=$daily->fetch($request,null,microtime(true)+10000,['http_timeout_seconds'=>5,'request_spacing_seconds'=>0]);$stable=MonRun::pick($s,['bars','benchmark_bars','benchmark_id','metrics','technical_signal','entry_plans']);$hashes[$sid]=MonRun::digest($stable);$snapshots[$sid]=$s->origin->source;
  }
  $output=['code'=>MON_VERSION,'mode'=>$mode,'symbols'=>80,'calls'=>count($calls),'kis_calls'=>count(array_filter($calls,static function($r){return $r[1]==='openapi.koreainvestment.com';})),'modeled_network_seconds'=>$network,'result_sha256'=>MonRun::digest($hashes),'providers'=>array_values(array_unique($snapshots))];
 }else{
  $actualStore=new MonStore($argv[3]);$id=$argv[4];$artifact=MonArtifact::load($actualStore,$id);$file=$artifact->files[0];$ranges=[];for($i=0;$i<8;$i++)$ranges[]=(object)['path'=>$file->path,'offset'=>$i*65536,'length'=>65536];unset($artifact);$rounds=3;$dataHash=hash_init('sha256');$validations=0;$seconds=[];
  for($round=0;$round<$rounds;$round++){$t=mon_monotonic();if(method_exists(MonArtifact::class,'batch')){$response=MonArtifact::batch($actualStore,(object)['artifact_id'=>$id,'ranges'=>$ranges]);$chunks=$response['chunks'];$validations++;}else{$chunks=[];foreach($ranges as $r){$chunks[]=MonArtifact::chunk($actualStore,(object)['artifact_id'=>$id,'path'=>$r->path,'offset'=>$r->offset,'length'=>$r->length]);$validations++;}}
   foreach($chunks as $c){$raw=base64_decode($c['data'],true);if(!hash_equals($c['chunk_sha256'],hash('sha256',$raw)))throw new RuntimeException('chunk mismatch');hash_update($dataHash,$raw);}$seconds[]=mon_monotonic()-$t;unset($chunks,$response,$raw);
  }
  $output=['code'=>MON_VERSION,'mode'=>$mode,'rounds'=>$rounds,'chunks'=>24,'artifact_validations'=>$validations,'requests_for8_chunks'=>method_exists(MonArtifact::class,'batch')?1:8,'result_sha256'=>hash_final($dataHash),'seconds_by_round'=>$seconds,'modeled_request_wait_seconds'=>method_exists(MonArtifact::class,'batch')?6:48];
 }
 $output['seconds']=mon_monotonic()-$start;$output['peak_memory_bytes']=memory_get_peak_usage(true);$output['external_requests']=0;$output['orders']=0;echo mon_json($output);
}finally{foreach(glob($temp.'/*')as $p)@unlink($p);@rmdir($temp);}

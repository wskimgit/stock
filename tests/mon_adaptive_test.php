<?php
declare(strict_types=1);
require __DIR__.'/mon_reinforcement_fixture.php';
// New adaptive paths only. Synthetic data/credentials and injected HTTP; no NAS, providers or orders.
$cases=[];$dirs=[];$sim=[];$started=mon_monotonic();
function ad_ok(bool $v,string $m='assertion'): void {if(!$v)throw new RuntimeException($m);}
function ad_fault(callable $f,string $code): void {try{$f();}catch(MonFault $e){ad_ok($e->faultCode===$code,$e->faultCode);return;}throw new RuntimeException('missing '.$code);}
function ad_case(string $name,callable $f): void {global $cases;MonExecution::$active=null;MonExecution::$store=null;MonPreparation::reset();
    try{$f();$cases[]=['case'=>$name,'pass'=>true];}catch(Throwable $e){$cases[]=['case'=>$name,'pass'=>false,'error'=>$e->getMessage()];}}
function ad_store(): MonStore {global $dirs;$dir=sys_get_temp_dir().'/mon-adaptive-'.bin2hex(random_bytes(8));mkdir($dir,0700);$dirs[]=$dir;return new MonStore($dir);}
function ad_op(int $count,int $success,int $retry=0,int $recovered=0,float $seconds=1,int $timeouts=0): stdClass {
    return (object)['measurement'=>'server','count'=>$count,'successes'=>$success,'failures'=>$count-$success,'timeouts'=>$timeouts,'retries'=>$retry,'retry_successes'=>$recovered,'units'=>$count,'seconds'=>array_fill(0,min(20,$count),$seconds)];
}
function ad_sample(int $id,float $seconds=80,int $limit=120,bool $complete=true,?float $now=null): stdClass {
    $now=$now??microtime(true);return (object)['run_context_id'=>hash('sha256','adaptive-synthetic-'.$id),'workload'=>'mon','received_at'=>MonBudget::iso($now-$seconds),
        'finished_at'=>MonBudget::iso($now),'elapsed_seconds'=>$seconds,'total_limit_seconds'=>$limit,'measurement'=>'server','partial_timing'=>false,'censored'=>false,
        'deadline_missed'=>$seconds>$limit,'new_analysis_complete'=>$complete,'fully_complete'=>$complete,'outcome'=>$complete?'complete':'incomplete',
        'operations'=>new stdClass(),'counts'=>new stdClass(),'unfinished'=>[]];
}
function ad_stats(array $rows): stdClass {return MonRunStatistics::seal((object)['contract'=>MonRunStatistics::CONTRACT,'generation'=>count($rows),'samples'=>$rows]);}
function ad_history(int $count,float $seconds,int $limit=120,bool $complete=true): stdClass {
    $rows=[];$now=microtime(true);for($i=0;$i<$count;$i++)$rows[]=ad_sample($i,$seconds,$limit,$complete,$now-$count+$i);return ad_stats($rows);
}
function ad_start(MonStore $store,string $id,float &$wall,float &$mono): MonBudget {
    return MonExecution::start($store,(object)['run_id'=>$id,'received_at'=>MonBudget::iso($wall)],static function()use(&$wall){return $wall;},static function()use(&$mono){return $mono;});
}
function ad_worker(MonStore $store,stdClass $d,float &$now): MonDetailWorker {
    $cfg=progress_test_config($store->dir);$http=new MonHttp(null,static function(){return ['status'=>429,'headers'=>['retry-after'=>'30'],'body'=>'{}'];});
    return new MonDetailWorker(new MonDaily($http,new MonCollector($http,$store,$cfg),$cfg,$store),$store,static function()use(&$now){return $now;});
}

ad_case('nearest-rank p50/p90 have known boundaries',function(){ad_ok(MonRunStatistics::percentile([5,1,4,2,3],.5)===3.0);ad_ok(MonRunStatistics::percentile([], .9)===null);ad_ok(MonRunStatistics::percentile(range(1,10),.9)===9.0);});
ad_case('cold auto and explicit legacy modes retain original floors',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'cold',$w,$m);ad_ok($b->run->mode==='auto'&&$b->end('collection')-$w===100.0);
    $n=MonExecution::start($s,(object)['run_id'=>'normal','received_at'=>MonBudget::iso($w),'mode'=>'normal']);ad_ok($n->timing()['limits_seconds']['publication']===120.0);
    $x=MonExecution::start($s,(object)['run_id'=>'extended','received_at'=>MonBudget::iso($w),'mode'=>'extended','extension_reason'=>'synthetic extra work']);ad_ok($x->timing()['limits_seconds']['publication']===300.0);
});
ad_case('reported 183-second lower bound raises next total to 230 without a fake p90',function()use(&$sim){
    $r=ad_sample(900,183,120,false);$r->censored=true;$r->measurement='operator_reported';$p=MonRunStatistics::recommend(ad_stats([$r]),'mon',microtime(true));
    ad_ok($p->limits->publication===230&&$p->limits->collection===210);ad_ok(MonRunStatistics::summary(ad_stats([$r]),'mon',microtime(true))['p90_seconds']===null);
    $sim['single_observed_timeout']=['elapsed_lower_bound'=>183,'old_total'=>120,'next_total'=>$p->limits->publication,'reason'=>$p->reason];
});
ad_case('statistics changes cannot move a resumed run deadline',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'frozen',$w,$m);$id=$b->run->run_context_id;$end=$b->end('publication');
    MonRunStatistics::save($s,ad_history(5,250,120,false));$m=10;$w-=20;$b=MonExecution::resume($s,$id,static function()use(&$w){return $w;},static function()use(&$m){return $m;});
    ad_ok($b->end('publication')===$end);ad_fault(static function()use($s){MonExecution::start($s,(object)['run_id'=>'frozen','mode'=>'extended','extension_reason'=>'late']);},'MON_RUN_START_IMMUTABLE');
});
ad_case('backward wall time cannot increase remaining time in a live budget',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'rewind',$w,$m);$m=25;$w-=100;ad_ok(abs($b->elapsed()-25)<.001&&$b->remaining('publication')<=95.001);
});
ad_case('manual workloads, stale and future samples do not contaminate MON',function(){
    $now=microtime(true);$r=ad_sample(1,299,300,false,$now-31*86400);$r2=ad_sample(2,299,300,false,$now+20);$r3=ad_sample(3,299,300,false,$now);$r3->workload='maintenance';
    $p=MonRunStatistics::recommend(ad_stats([$r,$r2,$r3]),'mon',$now);ad_ok($p->sample_runs===0&&$p->limits->publication===120);
});
ad_case('partial and interrupted short runs cannot lower the limit',function(){
    $rows=[];for($i=0;$i<6;$i++){$r=ad_sample($i,1,240,false);$r->partial_timing=$i<3;$r->outcome='interrupted';$rows[]=$r;}
    ad_ok(MonRunStatistics::recommend(ad_stats($rows),'mon',microtime(true))->limits->publication===240);
});
ad_case('five complete runs decrease only ten seconds and keep the 120 floor',function()use(&$sim){
    $p=MonRunStatistics::recommend(ad_history(5,40,240),'mon',microtime(true));ad_ok($p->limits->publication===230);
    ad_ok(MonRunStatistics::recommend(ad_history(5,40,120),'mon',microtime(true))->limits->publication===120);
    $sim['stable_fast_runs']=['completed'=>5,'old_total'=>240,'next_total'=>230,'reason'=>$p->reason];
});
ad_case('one slow sample cannot immediately shrink and reference growth remains gradual without a stop cap',function(){
    ad_ok(MonRunStatistics::recommend(ad_history(1,40,240),'mon',microtime(true))->limits->publication===240);
    $p=MonRunStatistics::recommend(ad_history(5,700,120,false),'mon',microtime(true));ad_ok($p->limits->publication===240);
    ad_ok(MonRunStatistics::recommend(ad_history(5,700,300,false),'mon',microtime(true))->limits->publication===600);
});
ad_case('retry outcomes choose one or three attempts as advisory escalation references',function()use(&$sim){
    $bad=ad_history(5,160,180,false);$good=ad_history(5,160,180,true);
    foreach($bad->samples as $r)$r->operations->{'detail/fetch'}=ad_op(2,0,1,0,.2,2);
    foreach($good->samples as $r)$r->operations->{'detail/fetch'}=ad_op(2,1,1,1,1);
    MonRunStatistics::seal($bad);MonRunStatistics::seal($good);$a=MonRunStatistics::recommend($bad,'mon',microtime(true));$b=MonRunStatistics::recommend($good,'mon',microtime(true));
    ad_ok($a->detail_attempts===1&&$b->detail_attempts===3);ad_ok($b->detail_symbol_seconds<=8);
    $sim['retry_effectiveness']=['five_wasted_retries'=>$a->detail_attempts,'five_recovered_retries'=>$b->detail_attempts];
});
ad_case('HTTP timeout tuning respects one-second floor and provider caps',function(){
    $s=ad_history(5,70);foreach($s->samples as $r){$r->operations->{'http/api.github.com'}=ad_op(1,1,0,0,.05);$r->operations->{'http/query1.finance.yahoo.com'}=ad_op(1,0,0,0,4,1);}MonRunStatistics::seal($s);
    $p=MonRunStatistics::recommend($s,'mon',microtime(true));ad_ok($p->http_timeouts->{'api.github.com'}===1.0&&$p->http_timeouts->{'query1.finance.yahoo.com'}===5.0);
});
ad_case('call-count statistics raise the advisory reference beyond sixty calls',function(){
    $s=ad_history(5,100);foreach($s->samples as $r){$r->counts->http_calls=50;$r->counts->http_limit_hits=1;}MonRunStatistics::seal($s);
    $p=MonRunStatistics::recommend($s,'mon',microtime(true));ad_ok($p->http_calls===75&&$p->publication_call_reserve===12);
});
ad_case('phase reserves respond to measured finalization and publication times',function(){
    $s=ad_history(5,100);foreach($s->samples as $r){$r->operations->{'handler/mon_detail_finalize'}=ad_op(1,1,0,0,12);$r->operations->{'handler/mon_publish_process'}=ad_op(1,1,0,0,16);}MonRunStatistics::seal($s);
    $p=MonRunStatistics::recommend($s,'mon',microtime(true));ad_ok($p->limits->publication-$p->limits->decision===20&&$p->limits->decision-$p->limits->collection===15);
});
ad_case('same sample reimport is idempotent and contradictory duplicate is rejected',function(){
    $s=ad_store();$a=ad_history(2,80);$one=MonRunStatistics::save($s,$a);$two=MonRunStatistics::save($s,$a);ad_ok($one->sha256===$two->sha256&&count($two->samples)===2);
    $a->samples[0]->elapsed_seconds=81;MonRunStatistics::seal($a);ad_fault(static function()use($s,$a){MonRunStatistics::save($s,$a);},'MON_STATS_SAMPLE_CONFLICT');
});
ad_case('corrupted checksums and unrecognized secret fields are rejected',function(){
    $s=ad_history(1,70);$s->samples[0]->elapsed_seconds=71;ad_fault(static function()use($s){MonRunStatistics::validate($s);},'MON_STATS_INVALID');
    $s=ad_history(1,70);$s->samples[0]->token='synthetic forbidden field';MonRunStatistics::seal($s);ad_fault(static function()use($s){MonRunStatistics::validate($s);},'MON_STATS_INVALID');
});
ad_case('merged rolling history has at most sixty unique recent rows',function(){
    $a=[];$b=[];$now=microtime(true);for($i=0;$i<100;$i++){if($i<50)$a[]=ad_sample($i,70,120,true,$now-100+$i);else $b[]=ad_sample($i,70,120,true,$now-100+$i);}
    $s=MonRunStatistics::merge(ad_stats($a),ad_stats($b));ad_ok(count($s->samples)===60&&strlen(MonRun::json($s))<=MonRunStatistics::MAX_BYTES);
});
ad_case('protected persistence does not create stock data or a daemon start grant',function(){
    $s=ad_store();MonRunStatistics::save($s,ad_history(1,80));$raw=file_get_contents($s->path('run-stats.php'));ad_ok(strpos($raw,MON_SETTINGS_PREFIX)===0);
    ad_ok(!is_file($s->path('manual_start.php'))&&!is_file($s->dir.'/mon_data.json')&&!is_file($s->dir.'/mon_result.md'));
});
ad_case('actual transport latencies and failures are recorded with injected clocks',function(){
    $s=ad_store();$w=microtime(true);$m=0.;ad_start($s,'http-timing',$w,$m);$http=new MonHttp(null,static function()use(&$w,&$m){$m+=2;$w+=2;throw new MonFault('HTTP_TRANSPORT_28');},static function()use(&$w){return $w;},static function()use(&$m){return $m;});
    ad_fault(static function()use($http,$w){$http->request('GET','https://query1.finance.yahoo.com/a',[],null,$w+10);},'HTTP_TRANSPORT_28');
    MonExecution::event('measured');$r=MonExecution::finish('failed');$o=$r['statistics']['operations']['http/query1.finance.yahoo.com'];ad_ok($o['count']===1&&$o['timeouts']===1&&$o['p90_seconds']===2.0);
});
ad_case('open circuits are skips rather than repeated attempts',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'circuit-count',$w,$m);$http=new MonHttp(null,static function(){throw new MonFault('HTTP_TRANSPORT_7');});
    for($i=0;$i<2;$i++)ad_fault(static function()use($http){$http->request('GET','https://query1.finance.yahoo.com/a',[],null,microtime(true)+5);},$i===0?'HTTP_TRANSPORT_7':'HTTP_CIRCUIT_OPEN');
    MonExecution::event('measured');ad_ok($b->run->counts->http_calls===1&&$b->run->operations->{'http/query1.finance.yahoo.com'}->count===1);
});
ad_case('data-call references record excess sends without suppressing them',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'call-reference',$w,$m);$calls=0;$http=new MonHttp(null,static function()use(&$calls){$calls++;return ['status'=>200,'headers'=>[],'body'=>'{}'];});
    for($i=0;$i<40;$i++)$http->request('GET','https://query1.finance.yahoo.com/a'.$i,[],null,microtime(true)+5);
    $v=MonExecution::finish();ad_ok($calls===40&&$v['run']['counts']->http_calls===40&&$v['run']['count_excess']->http_calls===10&&$v['next_policy']->http_calls===62);
});
ad_case('reported connector operations are idempotent and retain their provenance',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'report',$w,$m);$report=(object)['operation_id'=>'query-1','operation'=>'external/search','success'=>false,'code'=>'HTTP_TRANSPORT_28','seconds'=>12.,'units'=>3];
    MonExecution::report($report);MonExecution::report($report);ad_ok($b->run->counts->search_batches===1&&$b->run->operations->{'external/search'}->count===1);
    ad_ok($b->run->operations->{'external/search'}->measurement==='operator_reported'&&$b->run->operations->{'external/search'}->units===3);
});
ad_case('nonfinite or negative reported time cannot alter policy',function(){
    $s=ad_store();$w=microtime(true);$m=0.;ad_start($s,'bad-report',$w,$m);$r=(object)['operation_id'=>'bad','operation'=>'external/search','success'=>true,'seconds'=>-1];
    ad_fault(static function()use($r){MonExecution::report($r);},'MON_MEASUREMENT_INVALID');$r->seconds=INF;ad_fault(static function()use($r){MonExecution::report($r);},'MON_MEASUREMENT_INVALID');
});
ad_case('display reports count once and never prove a new analysis',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'display',$w,$m);MonExecution::event('initial_display_reported');MonExecution::event('initial_display_reported');MonExecution::event('final_display_reported');
    ad_ok($b->run->counts->displays_reported===2&&!MonExecution::view($b)['fully_complete']);
});
ad_case('finish retry preserves receipt and checkpoints ongoing work and records a single incomplete sample',function(){
    $s=ad_store();$w=microtime(true);$m=0.;ad_start($s,'finish-idempotent',$w,$m);$m=135;$w+=135;
    $a=MonExecution::finish('deadline',[(object)['task'=>'US_INDEX','code'=>'DETAIL_BENCHMARK_DATE_MISSING']]);$m+=5;$w+=5;$b=MonExecution::finish();
    ad_ok($a['run']['finished_at']===null&&$b['run']['finished_at']===null&&$b['statistics']['last']->elapsed_seconds>=$a['statistics']['last']->elapsed_seconds);ad_ok(count($b['statistics_snapshot']->samples)===1&&!$b['run']['fully_complete']&&count($b['run']['unfinished'])===1);
});
ad_case('later confirmed publication updates the same sample rather than adding a run',function(){
    $s=ad_store();$w=microtime(true);$m=0.;ad_start($s,'late-save',$w,$m);MonExecution::event('prepared',['final_available'=>true,'new_analysis_complete'=>true,'save_status'=>'queued']);
    $a=MonExecution::finish();ad_ok(!$a['run']['fully_complete']);$m=20;$w+=20;MonExecution::event('publication_observed',['save_status'=>'saved']);$b=MonExecution::finish();
    ad_ok(count($b['statistics_snapshot']->samples)===1&&$b['run']['fully_complete']&&$b['statistics_snapshot']->samples[0]->elapsed_seconds===20.0);
});
ad_case('pending run is checkpointed without expiry before the next policy freezes',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$old=ad_start($s,'abandoned',$w,$m);$w+=301;$m+=301;$new=ad_start($s,'after-abandoned',$w,$m);
    $stats=MonRunStatistics::load($s);ad_ok(count($stats->samples)===1&&$stats->samples[0]->censored&&$stats->samples[0]->outcome==='incomplete'&&$stats->samples[0]->terminal===false);
    ad_ok($new->run->adaptive_policy->limits->publication===240&&MonRunStatistics::summary($stats,'mon',$w)['measured_samples']===0);
});
ad_case('busy statistics storage preserves finish journal for an idempotent retry',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'stats-busy',$w,$m);$lock=fopen($s->path('run-stats.lock'),'c+');flock($lock,LOCK_EX);
    ad_fault(static function(){MonExecution::finish();},'MON_STATS_BUSY');ad_ok($b->run->checkpoint_at!==null&&$b->run->finished_at===null&&$b->run->statistics_status==='MON_STATS_BUSY');flock($lock,LOCK_UN);fclose($lock);
    ad_ok(count(MonExecution::finish()['statistics_snapshot']->samples)===1);
});
ad_case('auto detail request freezes deadline and attempt policy in its hash',function(){
    $s=ad_store();$w=microtime(true);$m=0.;MonRunStatistics::save($s,ad_history(1,183,120,false));$b=ad_start($s,'detail-policy',$w,$m);$d=reinforcement_fixture(1);$c=reinforcement_context($d);
    $q=MonDetails::register($d,$c,(object)['request_id'=>'adaptive-req','symbol'=>$d->watchlist->symbols[0],'wait_seconds'=>300,'force_refresh'=>true]);$r=$q['request'];
    ad_ok($r->run_mode==='auto'&&$r->execution_limits->collection_seconds===210&&MonRun::seconds($r->deadline_at)>$b->end('collection'));$r->execution_limits->attempts=99;
    ad_fault(static function()use($r){MonDetails::validRequest($r,$r->symbol_id);},'DETAIL_RUN_DEADLINE_INVALID');
});
ad_case('ineffective retry reference increases backoff without a terminal attempt limit',function(){
    $s=ad_store();$hist=ad_history(5,160,180,false);foreach($hist->samples as $r)$r->operations->{'detail/fetch'}=ad_op(2,0,1,0,.1,2);MonRunStatistics::seal($hist);MonRunStatistics::save($s,$hist);
    $w=microtime(true);$m=0.;ad_start($s,'worker-one',$w,$m);$d=reinforcement_fixture(1);$c=reinforcement_context($d);$q=MonDetails::register($d,$c,(object)['request_id'=>'worker-one','symbol'=>$d->watchlist->symbols[0],'wait_seconds'=>300,'force_refresh'=>true]);$d=mon_decode($q['files'][0]['content']);
    $now=microtime(true);$settings=MonData::settings($d);$settings['request_spacing_seconds']=0;$worker=ad_worker($s,$d,$now);$one=$worker->processOne($d,microtime(true)+8,$settings);
    ad_ok($one['response']->attempts===1&&$one['response']->state==='retry_wait');$d->collection->detail_responses=$one['responses'];$now=$one['response']->retry_after+1;
    $two=$worker->processOne($d,microtime(true)+8,$settings);ad_ok($two['response']->attempts===2&&$two['response']->state==='retry_wait'&&$two['response']->attempt_excess===1);
});
ad_case('useful retry reference allows fourth transient attempt and preserves cooldown',function(){
    $s=ad_store();$hist=ad_history(5,160,180,true);foreach($hist->samples as $r)$r->operations->{'detail/fetch'}=ad_op(2,1,1,1,1);MonRunStatistics::seal($hist);MonRunStatistics::save($s,$hist);
    $w=microtime(true);$m=0.;ad_start($s,'worker-three',$w,$m);$d=reinforcement_fixture(1);$c=reinforcement_context($d);$q=MonDetails::register($d,$c,(object)['request_id'=>'worker-three','symbol'=>$d->watchlist->symbols[0],'wait_seconds'=>300,'force_refresh'=>true]);$d=mon_decode($q['files'][0]['content']);
    $now=microtime(true);$settings=MonData::settings($d);$settings['request_spacing_seconds']=0;
    for($i=1;$i<=4;$i++){$job=ad_worker($s,$d,$now)->processOne($d,microtime(true)+8,$settings);ad_ok($job['response']->attempts===$i&&$job['response']->state==='retry_wait');$d->collection->detail_responses=$job['responses'];if($i<4)$now=$job['response']->retry_after+1;}
    ad_ok(!ad_worker($s,$d,$now)->processOne($d,microtime(true)+8,$settings)['changed']);
});
ad_case('queued publication is measured and revises one terminal sample after confirmed save',function(){
    $d=reinforcement_fixture(1);$source=MonRun::json($d);$decision=reinforcement_decision($d,reinforcement_context($d));$final=MonDetails::finalize($d,$decision);
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'publication-meter',$w,$m);$cfg=progress_test_config($s->dir);
    $a=MonArtifact::create($s,$source,$final['files'],['decision_sha256'=>hash('sha256',MonRun::json($decision)),'run_context_id'=>$b->run->run_context_id,'received_at'=>$b->run->received_at]);
    $head=str_repeat('a',40);$tree=str_repeat('b',40);$commit=str_repeat('c',40);$calls=0;
    $http=new MonHttp(null,static function($method,$url,$headers,$body)use(&$head,$tree,$commit,&$calls,$source){$calls++;$path=parse_url($url,PHP_URL_PATH);$in=$body===null?null:mon_decode($body);
        if(substr($path,-19)==='/git/ref/heads/main')$value=(object)['object'=>(object)['sha'=>$head]];
        elseif(strpos($path,'/git/commits/')!==false)$value=(object)['tree'=>(object)['sha'=>$tree]];
        elseif(strpos($path,'/git/trees/')!==false)$value=(object)['truncated'=>false,'tree'=>[(object)['path'=>'mon_data.json','sha'=>MonRun::gitSha($source)],(object)['path'=>'mon_result.md','sha'=>str_repeat('d',40)]]];
        elseif(substr($path,-10)==='/git/blobs')$value=(object)['sha'=>MonRun::gitSha($in->content)];
        elseif(substr($path,-10)==='/git/trees')$value=(object)['sha'=>str_repeat('e',40)];
        elseif(substr($path,-12)==='/git/commits')$value=(object)['sha'=>$commit];
        elseif($method==='PATCH'){$head=$in->sha;$value=(object)['object'=>(object)['sha'=>$head]];}
        else throw new MonFault('SYNTHETIC_UNEXPECTED_PATH');
        return ['status'=>200,'headers'=>[],'body'=>mon_json($value,false)];});
    $pub=new MonPublication($s,$http,$cfg);$receipt=$pub->enqueue($a['artifact_id'],$head,$b,'synthetic_operator');
    MonExecution::event('prepared',['new_analysis_complete'=>true,'final_available'=>true,'save_status'=>'queued','job_id'=>$receipt['job_id'],'artifact_id'=>$a['artifact_id']]);MonExecution::finish();
    MonExecution::$active=null;MonExecution::$store=null;$saved=$pub->processNext(microtime(true)+10);
    ad_ok($saved['save_status']==='saved',mon_json($saved,false));$stats=MonRunStatistics::load($s);ad_ok(count($stats->samples)===1&&$stats->samples[0]->fully_complete);
    ad_ok($stats->samples[0]->counts->http_calls===$calls&&$stats->samples[0]->operations->{'http/api.github.com'}->count===$calls&&$stats->samples[0]->revision===2);
    ad_ok(MonExecution::$active===null&&MonExecution::$store===null);
});
ad_case('invalid display timing cannot discard buffered operation measurements',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'display-rollback',$w,$m);MonExecution::measure('handler/preparation',2);
    ad_fault(static function()use($w){MonExecution::event('initial_display_reported',['reported_at'=>MonBudget::iso($w-1)]);},'MON_DISPLAY_TIME_INVALID');
    $v=MonExecution::finish();ad_ok($v['statistics']['operations']['handler/preparation']['count']===1&&mon_get($b->run->counts,'displays_reported',0)===0);
});
ad_case('archived receipt cannot be restarted with a fresh adaptive deadline',function(){
    $s=ad_store();$w=microtime(true);$m=0.;$b=ad_start($s,'archived',$w,$m);MonExecution::finish();unlink($s->path('run-'.$b->run->run_context_id.'.php'));
    ad_fault(static function()use($s,$w){MonExecution::start($s,(object)['run_id'=>'archived','received_at'=>MonBudget::iso($w)]);},'MON_RUN_ALREADY_RECORDED');
});
ad_case('sequential simulated runs adapt next limits while each current limit stays fixed',function()use(&$sim){
    $s=ad_store();$w=microtime(true);$m=0.;$rows=[];
    foreach([80,85,90,75,95,183,175,90,85,80,75,70]as $i=>$seconds){$b=ad_start($s,'sequence-'.$i,$w,$m);$limit=(int)($b->end('publication')-$w);$end=$b->end('publication');$w+=$seconds;$m+=$seconds;
        if($seconds<=$limit)MonExecution::event('synthetic_confirmed',['new_analysis_complete'=>true,'final_available'=>true,'save_status'=>'no_change']);
        $done=MonExecution::finish($seconds>$limit?'deadline':'finished');ad_ok($b->end('publication')===$end&&$done['next_policy']->limits->publication>=120&&$done['next_policy']->maximum_total_seconds===null);
        $rows[]=['run'=>$i+1,'synthetic_elapsed'=>$seconds,'current_limit'=>$limit,'next_limit'=>$done['next_policy']->limits->publication,'outcome'=>$done['statistics_snapshot']->samples[count($done['statistics_snapshot']->samples)-1]->outcome];$w+=1;$m+=1;
    }ad_ok($rows[5]['outcome']==='incomplete'&&$rows[5]['next_limit']===230&&$rows[6]['current_limit']===230);$sim['sequence']=$rows;
});
ad_case('portable observed file validates and selects the documented next limit',function(){
    $s=MonRunStatistics::validate(mon_decode(file_get_contents(__DIR__.'/mon_completion_observed_fixture.json')));$now=MonRun::seconds($s->samples[0]->finished_at)+60;
    ad_ok(count($s->samples)===2&&$s->samples[0]->measurement==='operator_reported'&&$s->samples[0]->censored);ad_ok(MonRunStatistics::recommend($s,'mon',$now)->limits->publication===230);
});

function ad_clean(string $p): void {foreach(scandir($p)as $f)if($f!=='.'&&$f!=='..'){if(is_dir($p.'/'.$f))ad_clean($p.'/'.$f);else unlink($p.'/'.$f);}rmdir($p);}
foreach($dirs as $dir)ad_clean($dir);$failed=count(array_filter($cases,static function($r){return !$r['pass'];}));
echo mon_json(['version'=>MON_VERSION,'php'=>PHP_VERSION,'tests'=>count($cases),'passed'=>count($cases)-$failed,'failed'=>$failed,'wall_seconds'=>round(mon_monotonic()-$started,6),'simulation'=>$sim,'external_requests'=>0,'orders'=>0,'cases'=>$cases]);exit($failed?1:0);

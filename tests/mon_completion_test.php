<?php
declare(strict_types=1);
require_once __DIR__.'/mon_reinforcement_fixture.php';
$cases=[];$dirs=[];$started=mon_monotonic();$simulation=[];
function cc_ok($condition,string $detail=''): void {if(!$condition)throw new RuntimeException($detail?:'assertion failed');}
function cc_fault(callable $f,string $code): void {try{$f();}catch(MonFault $e){cc_ok($e->faultCode===$code,$e->faultCode);return;}throw new RuntimeException('missing '.$code);}
function cc_case(string $name,callable $f): void {global $cases;MonExecution::$active=null;MonExecution::$store=null;MonPreparation::reset();
    try{$f();$cases[]=['case'=>$name,'pass'=>true];}catch(Throwable $e){$cases[]=['case'=>$name,'pass'=>false,'error'=>$e->getMessage()];}}
function cc_store(): MonStore {global $dirs;$dir=sys_get_temp_dir().'/mon-completion-'.bin2hex(random_bytes(8));mkdir($dir,0700);$dirs[]=$dir;return new MonStore($dir);}
function cc_start(MonStore $s,string $id,float &$wall,float &$mono,float $age=0): MonBudget {
    return MonExecution::start($s,(object)['run_id'=>$id,'received_at'=>MonBudget::iso($wall-$age)],static function()use(&$wall){return $wall;},static function()use(&$mono){return $mono;});
}
function cc_request(MonStore $s,stdClass $d,string $id): stdClass {
    $c=reinforcement_context($d);$q=MonDetails::register($d,$c,(object)['request_id'=>$id,'symbol'=>$d->watchlist->symbols[0],'wait_seconds'=>0,'force_refresh'=>true]);return mon_decode($q['files'][0]['content']);
}
function cc_worker(MonStore $s,callable $transport,float &$now): MonDetailWorker {
    $cfg=progress_test_config($s->dir);$http=new MonHttp(null,$transport);
    return new MonDetailWorker(new MonDaily($http,new MonCollector($http,$s,$cfg),$cfg,$s),$s,static function()use(&$now){return $now;});
}
function cc_chart($d): array {
    $s=$d->watchlist->symbols[0];$bars=$d->_test_snapshots->{$s->symbol_id}->bars;$q=new stdClass();
    foreach(['open','high','low','close','volume']as $i=>$field)$q->$field=array_column($bars,$i+1);
    $row=(object)['meta'=>(object)['symbol'=>$s->source_codes->yahoo,'exchangeTimezoneName'=>'Asia/Seoul','exchangeName'=>'KSC','currency'=>'KRW'],
        'timestamp'=>array_map(static function($r){return (new DateTimeImmutable($r[0].' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();},$bars),'indicators'=>(object)['quote'=>[$q]]];
    return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['chart'=>(object)['result'=>[$row],'error'=>null]],false)];
}
function cc_publication(MonStore $s,MonBudget $b,float &$wall,float &$mono,?callable $wrapper=null): array {
    $d=reinforcement_fixture(1);$c=reinforcement_context($d);$c->run_context_id=$b->run->run_context_id;$dec=reinforcement_decision($d,$c);$final=MonDetails::finalize($d,$dec);
    $a=MonArtifact::create($s,MonRun::json($d),$final['files'],['decision_sha256'=>hash('sha256',MonRun::json($dec)),'run_context_id'=>$b->run->run_context_id,'received_at'=>$b->run->received_at]);
    $git=new ReinforcementGit($d);$transport=$wrapper?$wrapper($git):[$git,'transport'];$http=new MonHttp(null,$transport);
    $pub=new MonPublication($s,$http,progress_test_config($s->dir),static function()use(&$wall){return $wall;},static function()use(&$mono){return $mono;});
    $r=$pub->enqueue($a['artifact_id'],$git->head,$b,'synthetic_operator');
    MonExecution::event('final_prepared',['new_analysis_complete'=>true,'final_available'=>true,'analysis_origin'=>'secured_data_decision','save_status'=>'queued','artifact_id'=>$a['artifact_id'],'job_id'=>$r['job_id']]);
    return [$pub,$git,$r,$d,$dec];
}
cc_case('all four reference times may be exceeded without cancelling the run',function()use(&$simulation){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'late-all',$w,$m,700);foreach(['collection','decision','publication','recovery']as $phase){$b->guard($phase);$b->guard($phase);}
    $v=MonExecution::view($b);cc_ok($v['next_action']==='continue_pending_work'&&!$v['stop_on_threshold']&&count($v['events'])===4);
    cc_ok($b->deadline('publication')>microtime(true)+9&&$v['timing']['time_excess_seconds']->publication>579);
    $simulation['late_start']=['elapsed'=>700,'reference'=>120,'continues'=>true];
});
cc_case('prepare and immutable final decision work after the original decision reference',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'late-final',$w,$m,700);$d=reinforcement_fixture(1);$c=reinforcement_context($d);$c->run_context_id=$b->run->run_context_id;
    $dec=reinforcement_decision($d,$c);$final=MonDetails::finalize($d,$dec);cc_ok($final['new_analysis_complete']&&count($final['files'])===2);
    cc_ok(MonRun::json($d->collection)===MonRun::json(mon_decode($final['files'][0]['content'])->collection));
});
cc_case('ninety HTTP sends exceed data and total references without losing any send',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'many-http',$w,$m);$sent=0;$http=new MonHttp(null,static function()use(&$sent){$sent++;return ['status'=>200,'headers'=>[],'body'=>'{}'];});
    for($i=0;$i<90;$i++)$http->request('GET','https://query1.finance.yahoo.com/fixture-'.$i,[],null,microtime(true)+5);
    MonExecution::event('measured');$v=MonExecution::finish();cc_ok($sent===90&&$v['run']['counts']->http_calls===90&&$v['run']['count_excess']->http_calls===60);
    cc_ok($v['next_policy']->http_calls>=125&&$v['run']['finished_at']===null);
});
cc_case('more than one hundred external reports remain idempotent and counted',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'many-reports',$w,$m);
    for($i=0;$i<105;$i++){ $r=(object)['operation_id'=>'search-'.$i,'operation'=>'external/search','success'=>true,'seconds'=>.01,'units'=>1];MonExecution::report($r);MonExecution::report($r); }
    $v=MonExecution::finish();cc_ok($v['run']['counts']->search_batches===105&&$v['run']['operations']->{'external/search'}->count===105&&$v['run']['count_excess']->search_batches===103);
    cc_ok($v['next_policy']->external_search_batches>4);
});
cc_case('an external duration over three hundred seconds is preserved exactly',function(){
    $s=cc_store();$w=microtime(true);$m=0.;cc_start($s,'long-operation',$w,$m,720);MonExecution::report((object)['operation_id'=>'connector-long','operation'=>'connector/github','success'=>true,'seconds'=>640.5,'units'=>1]);
    $v=MonExecution::finish();cc_ok($v['run']['operations']->{'connector/github'}->seconds===[640.5]);
});
cc_case('deadline finish checkpoints pending work instead of expiring it',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'checkpoint',$w,$m,700);$v=MonExecution::finish('deadline',[(object)['task'=>'DETAIL','code'=>'HTTP_429']]);
    cc_ok($v['run']['finished_at']===null&&!$v['run']['fully_complete']&&$v['statistics_snapshot']->samples[0]->terminal===false);
    cc_ok($v['statistics']['pending']===1&&$v['statistics']['p90_seconds']===null&&$v['statistics_snapshot']->samples[0]->elapsed_seconds>=700);
    MonExecution::report((object)['operation_id'=>'recovered','operation'=>'external/nas','success'=>true,'seconds'=>.1,'units'=>1]);$b->guard('decision');
});
cc_case('confirmed completion after seven hundred seconds records completion and overtime',function()use(&$simulation){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'late-saved',$w,$m,700);[$pub,$git,$r]=cc_publication($s,$b,$w,$m);
    $saved=$pub->process($r['job_id'],microtime(true)+10);cc_ok($saved['save_status']==='saved'&&$git->patches===1);
    $stats=MonRunStatistics::load($s);$row=$stats->samples[0];cc_ok($row->fully_complete&&$row->deadline_missed&&$row->terminal&&$row->time_excess_seconds->publication>=580);
    $next=MonRunStatistics::recommend($stats,'mon',$w);cc_ok($next->maximum_total_seconds===null&&$next->limits->publication===880);
    $simulation['late_complete']=['elapsed'=>$row->elapsed_seconds,'reference'=>$row->total_limit_seconds,'outcome'=>$row->outcome,'overtime'=>$row->time_excess_seconds->publication];
});
cc_case('repeated final finish keeps one sample, one timestamp and one revision',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'stable-finish',$w,$m,700);[$pub,$git,$r]=cc_publication($s,$b,$w,$m);$pub->process($r['job_id'],microtime(true)+10);
    MonExecution::resume($s,$b->run->run_context_id);$one=MonExecution::finish();$two=MonExecution::finish();cc_ok($one['statistics_snapshot']->sha256===$two['statistics_snapshot']->sha256&&$one['run']['finished_at']===$two['run']['finished_at']);
});
cc_case('four detail attempts recover without a deadline or attempt-limit terminal state',function()use(&$simulation){
    $s=cc_store();$w=microtime(true);$m=0.;cc_start($s,'detail-recovery',$w,$m,700);$d=cc_request($s,reinforcement_fixture(1),'detail-recovery');$now=microtime(true);$settings=MonData::settings($d);$settings['request_spacing_seconds']=0;$states=[];
    for($i=1;$i<=4;$i++){
        $transport=$i<4?static function(){return ['status'=>429,'headers'=>['retry-after'=>'30'],'body'=>'{}'];}:static function($method,$url)use($d){return strpos($url,'query1.finance.yahoo.com')!==false?cc_chart($d):['status'=>401,'headers'=>[],'body'=>'{}'];};
        $job=cc_worker($s,$transport,$now)->processOne($d,microtime(true)+8,$settings);cc_ok($job['changed']&&$job['response']->attempts===$i,mon_json($job,false));
        $states[]=$job['response']->state;$d->collection->detail_responses=$job['responses'];$now=max($now+1,(float)mon_get($job['response'],'retry_after',0)+1);
    }cc_ok($states===['retry_wait','retry_wait','retry_wait','done'],json_encode($states));cc_ok($job['response']->usable&&$job['response']->attempt_excess===2);
    MonDetails::checkedSnapshot($d->watchlist->detail_requests->{$job['processed_symbol_id']},$job['response']);$simulation['detail_recovery']=['attempts'=>4,'states'=>$states,'attempt_excess'=>2];
});
cc_case('old attempt-limit detail responses become resumable after upgrade',function(){
    $s=cc_store();$w=microtime(true);$m=0.;cc_start($s,'old-detail',$w,$m,700);$d=cc_request($s,reinforcement_fixture(1),'old-detail');$sid=$d->watchlist->symbols[0]->symbol_id;
    $old=progress_response($d,$sid);$old->state='done';$old->error='DETAIL_ATTEMPT_LIMIT';$old->attempts=3;$old->retry_after=null;$d->collection->detail_responses->$sid=$old;
    cc_ok(MonDaemon::detailWorkDue($d,time()));$now=microtime(true);$settings=MonData::settings($d);$settings['request_spacing_seconds']=0;
    $job=cc_worker($s,static function(){return ['status'=>429,'headers'=>['retry-after'=>'30'],'body'=>'{}'];},$now)->processOne($d,microtime(true)+8,$settings);
    cc_ok($job['response']->attempts===4&&$job['response']->state==='retry_wait');
});
cc_case('an expired local work slice preserves the request and reports no attempt',function(){
    $s=cc_store();$w=microtime(true);$m=0.;cc_start($s,'yield-detail',$w,$m);$d=cc_request($s,reinforcement_fixture(1),'yield-detail');$now=microtime(true);$sent=0;
    $job=cc_worker($s,static function()use(&$sent){$sent++;return ['status'=>429,'headers'=>[],'body'=>'{}'];},$now)->processOne($d,microtime(true)-1,MonData::settings($d));
    cc_ok(!$job['changed']&&$sent===0&&count(get_object_vars(MonDetails::requests($d)))===1);
});
cc_case('progress remains collecting after the reference and pagination tokens ignore clock-only changes',function(){
    $d=reinforcement_fixture(6);$a=MonProgress::build($d,time()+1000);$b=MonProgress::build($d,time()+1001,$a['snapshot_token']);
    cc_ok($a['phase']==='collecting'&&$a['counts']['threshold_exceeded']===6&&$a['snapshot_token']===$b['snapshot_token']);
    if($a['page']['next_cursor']!==null){$page=MonProgress::build($d,time()+1002,$a['snapshot_token'],[],$a['page']['next_cursor']);cc_ok($page['snapshot_token']===$a['snapshot_token']);}
});
cc_case('a transient publication failure remains queued through four failures and then saves',function()use(&$simulation){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'pub-recovery',$w,$m,700);$failures=4;
    [$pub,$git,$r]=cc_publication($s,$b,$w,$m,static function($git)use(&$failures){return static function($method,$url,$headers,$body,$timeout)use($git,&$failures){if($failures>0){$failures--;throw new MonFault('HTTP_TRANSPORT_28');}return $git->transport($method,$url,$headers,$body,$timeout);};});
    for($i=1;$i<=4;$i++){$out=$pub->process($r['job_id'],microtime(true)+10);cc_ok($out['save_status']==='queued'&&$out['safe_failures']===$i,mon_json($out,false));$w=$pub->load($r['job_id'])->retry_after+1;$m+=1;}
    $out=$pub->process($r['job_id'],microtime(true)+10);cc_ok($out['save_status']==='saved'&&$git->patches===1&&$git->newCommits===1);$simulation['publication_recovery']=['failures'=>4,'final'=>$out['save_status'],'public_ref_updates'=>$git->patches];
});
cc_case('lost ref acknowledgement and failed confirmation resume with reads and no duplicate ref mutation',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'pub-uncertain',$w,$m,700);[$pub,$git,$r]=cc_publication($s,$b,$w,$m);$git->loseAck=true;$git->failConfirmation=true;
    $out=$pub->process($r['job_id'],microtime(true)+10);cc_ok($out['save_status']==='publishing'&&$git->patches===1);$w=$pub->load($r['job_id'])->retry_after+1;
    $out=$pub->process($r['job_id'],microtime(true)+10);cc_ok($out['save_status']==='publishing'&&$git->patches===1);$w=$pub->load($r['job_id'])->retry_after+1;$git->failConfirmation=false;
    $out=$pub->process($r['job_id'],microtime(true)+10);cc_ok($out['save_status']==='saved'&&$git->patches===1);
});
cc_case('more than one concurrent collection rebase preserves the fixed decision',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'many-rebases',$w,$m,700);$changes=2;
    [$pub,$git,$r,$d,$decision]=cc_publication($s,$b,$w,$m,static function($git)use(&$changes){return static function($method,$url,$headers,$body,$timeout)use($git,&$changes){if($method==='PATCH'&&$changes>0){$changes--;$next=$git->data();$next->collection->collection_id='collection-revision-'.$changes;$git->setSource($next);}return $git->transport($method,$url,$headers,$body,$timeout);};});
    for($i=0;$i<3;$i++)$out=$pub->process($r['job_id'],microtime(true)+10);
    cc_ok($out['save_status']==='saved'&&$out['rebase_count']===2,mon_json($out,false));cc_ok($git->data()->analysis->as_of===MonRun::utc($decision->fixed_as_of)&&$git->data()->collection->collection_id==='collection-revision-0');
});
cc_case('old expired publication journals resume instead of expiring again',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'legacy-pub',$w,$m,700);[$pub,$git,$r]=cc_publication($s,$b,$w,$m);$j=$pub->load($r['job_id']);$j->status='expired';$j->last_error='MON_PUBLICATION_DEADLINE';
    $copy=clone $j;unset($copy->journal_sha256);$j->journal_sha256=hash('sha256',MonRun::json($copy));$s->writePrivate('publish-'.$j->job_id.'.php',$j);
    cc_ok($pub->processNext(microtime(true)+10)['save_status']==='saved'&&$git->patches===1);
});
cc_case('resource deferral neither expires publication nor creates a public mutation',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'yield-pub',$w,$m,700);[$pub,$git,$r]=cc_publication($s,$b,$w,$m);
    $out=$pub->processNext(microtime(true)-1);cc_ok($out['save_status']==='queued'&&count($git->calls)===0&&$git->patches===0);
});
cc_case('a new run checkpoints an old pending run without marking it abandoned or terminal',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'old-pending',$w,$m,700);$id=$b->run->run_context_id;cc_start($s,'new-request',$w,$m);
    $old=$s->readPrivate('run-'.$id.'.php');cc_ok($old->finished_at===null&&$old->statistics_status==='checkpoint_recorded'&&!$old->terminal&&$old->elapsed_seconds>=700);
    cc_ok($old->unfinished[0]->code==='MON_RESUME_REQUIRED');
});
cc_case('next references grow beyond three hundred seconds from observed overtime while the current reference remains fixed',function(){
    $s=MonRunStatistics::validate(mon_decode(file_get_contents(__DIR__.'/mon_completion_observed_fixture.json')));$now=MonRun::seconds(end($s->samples)->finished_at)+1;$p=MonRunStatistics::recommend($s,'mon',$now);
    cc_ok($p->limits->publication===370&&$p->limit_behavior==='observe_and_continue'&&$p->maximum_total_seconds===null);
    $store=cc_store();MonRunStatistics::save($store,$s);$w=$now;$m=0.;$b=cc_start($store,'frozen-reference',$w,$m);$end=$b->end('publication');$w+=900;$m+=900;$b->guard('decision');$v=MonExecution::finish('deadline');
    cc_ok($b->end('publication')===$end&&$v['next_policy']->limits->publication>370);
});
cc_case('explicit user interruption still stops work and cannot be relabeled complete',function(){
    $s=cc_store();$w=microtime(true);$m=0.;$b=cc_start($s,'user-stop',$w,$m,700);$v=MonExecution::finish('interrupted',[(object)['task'=>'USER','code'=>'USER_STOP']]);
    cc_ok(!$v['run']['fully_complete']&&$v['statistics_snapshot']->samples[0]->outcome==='interrupted');cc_fault(static function()use($b){$b->guard('decision');},'MON_RUN_INTERRUPTED');
});
cc_case('per-request socket timeouts and daemon load limits remain independent of advisory totals',function(){
    $s=cc_store();$w=microtime(true);$m=0.;cc_start($s,'transport-cap',$w,$m,700);$actual=null;$http=new MonHttp(null,static function($method,$url,$headers,$body,$timeout)use(&$actual){$actual=$timeout;throw new MonFault('HTTP_TRANSPORT_28');});
    cc_fault(static function()use($http){$http->request('GET','https://query1.finance.yahoo.com/fixture',[],null,microtime(true)+8,5);},'HTTP_TRANSPORT_28');cc_ok($actual<=5);
    $settings=MonDaemonLoad::settings(MonData::settings(reinforcement_fixture(1)));cc_ok($settings['detail_jobs_per_tick']===1&&$settings['detail_symbol_budget_seconds']<=8&&$settings['tick_budget_seconds']<=25);
    cc_ok(!is_file($s->path('manual_start.php'))&&!is_file($s->path('daemon.lock')));
});
cc_case('a hundred distinct stage metrics aggregate without stopping work or losing counts',function(){
    $s=cc_store();$w=microtime(true);$m=0.;cc_start($s,'many-stage-metrics',$w,$m);
    for($i=0;$i<100;$i++)MonExecution::measure('handler/synthetic_stage_'.$i,1.0);
    $v=MonExecution::finish();$sum=0;foreach($v['run']['operations']as $o)$sum+=$o->count;
    cc_ok($sum===100&&count(get_object_vars($v['run']['operations']))<=64&&$v['run']['operations']->{'handler/other'}->count===42);
});
function cc_clean(string $p): void {foreach(scandir($p)as $f)if($f!=='.'&&$f!=='..'){if(is_dir($p.'/'.$f))cc_clean($p.'/'.$f);else unlink($p.'/'.$f);}rmdir($p);}
foreach($dirs as $dir)cc_clean($dir);$failed=count(array_filter($cases,static function($r){return !$r['pass'];}));
echo mon_json(['version'=>MON_VERSION,'php'=>PHP_VERSION,'tests'=>count($cases),'passed'=>count($cases)-$failed,'failed'=>$failed,'wall_seconds'=>round(mon_monotonic()-$started,6),'simulation'=>$simulation,'external_requests'=>0,'orders'=>0,'cases'=>$cases]);exit($failed?1:0);

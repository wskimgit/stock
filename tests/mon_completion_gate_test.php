<?php
declare(strict_types=1);
require_once __DIR__.'/mon_reinforcement_fixture.php';
$cases=[];$dirs=[];$started=mon_monotonic();
function cg_ok($v,string $message=''): void {if(!$v)throw new RuntimeException($message?:'assertion failed');}
function cg_fault(callable $f,string $code): void {try{$f();}catch(MonFault $e){cg_ok($e->faultCode===$code,$e->faultCode);return;}throw new RuntimeException('missing '.$code);}
function cg_case(string $name,callable $f): void {global $cases;MonExecution::$active=null;MonExecution::$store=null;MonPreparation::reset();
    try{$f();$cases[]=['case'=>$name,'pass'=>true];}catch(Throwable $e){$cases[]=['case'=>$name,'pass'=>false,'error'=>$e->getMessage()];}}
function cg_store(): MonStore {global $dirs;$dir=sys_get_temp_dir().'/mon-gate-'.bin2hex(random_bytes(8));mkdir($dir,0700);$dirs[]=$dir;return new MonStore($dir);}
function cg_start(MonStore $s,string $id,float &$wall,float &$mono,$policy=null): MonBudget {
    $request=(object)(['run_id'=>$id,'received_at'=>MonBudget::iso($wall-700)]+($policy===null?[]:['operation_policy'=>$policy]));
    return MonExecution::start($s,$request,static function()use(&$wall){return $wall;},static function()use(&$mono){return $mono;});}
function cg_patch(stdClass $d,string $runId,string $at,bool $risk=true,?float $price=null): stdClass {
    $c=(object)['source_blob_sha'=>MonRun::gitSha(MonRun::json($d)),'rendered_at'=>$at,'countries'=>['KR'],'important_ids'=>[],'run_context_id'=>$runId];
    $sid=$d->analysis->results[0]->symbol_id;$r=MonRun::payload($d)->final_results_details->$sid;
    if($price===null){$zone=$r->entry_plans[0]->entry_zone;$price=($zone[0]+$zone[1])/2;}
    $eids=['CG-Q-'.str_replace('.','',substr($at,11)),'CG-R-'.str_replace('.','',substr($at,11))];
    $evidence=[];foreach($eids as $eid)$evidence[]=(object)['id'=>$eid,'source_kind'=>'independent','source_url'=>'https://example.invalid/synthetic/'.$eid,'claim'=>'Synthetic fixture only: price or bounded primary issuer and trading review','checked_at'=>$at];
    $date=(new DateTimeImmutable($at))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
    $q=(object)['symbol_id'=>$sid,'country'=>'KR','venue'=>'KOSPI','currency'=>'KRW','price'=>$price,'quote_at'=>$at,'price_type'=>'last','timestamp_basis'=>'trade',
        'delay_seconds'=>0,'market_date'=>$date,'session'=>'regular','source_kind'=>'independent','evidence_ids'=>[$eids[0]]];
    $change=(object)['symbol_id'=>$sid,'price_observation'=>$q];
    if($risk)$change->risk_review=(object)['status'=>'verified','trading_status'=>'verified','checked_at'=>$at,'applies_as_of'=>$at,
        'scope'=>'Synthetic latest financial filing, subsequent material events and known pending items','pending_items'=>[],'confirmed_material_adverse'=>false,'evidence_ids'=>[$eids[1]]];
    return (object)['run_mode'=>'evaluate','base_source_blob_sha'=>$c->source_blob_sha,'base_analysis_run_id'=>$d->analysis->run_id,'fixed_as_of'=>$at,
        'changes'=>[$change],'new_evidence'=>$evidence,'pending'=>[],'short_reasons'=>['Synthetic missing checks repaired'],'view_context'=>$c,'state_token'=>MonRun::token($d,$c)];
}
function cg_publication(bool $confirmed=false,?float $price=null,bool $beforeOpen=false,int $identityCount=20,bool $missingPlan=false,$policy=null): array {
    $s=cg_store();$wall=microtime(true);$mono=0.;$b=cg_start($s,'gate-'.bin2hex(random_bytes(5)),$wall,$mono,$policy);
    $d=reinforcement_fixture(1);
    if($beforeOpen){$sessions=$d->watchlist->calendar->markets[0]->sessions;$sessions[1]->open_at=mon_iso(time()+3600);$sessions[1]->close_at=mon_iso(time()+7200);}
    // Complete the declared discovery goal through the existing native identity path.
    $items=[];$evidence=[];for($i=1;$i<=$identityCount;$i++){
        $sym=clone $d->watchlist->symbols[0];$sym->symbol=str_pad((string)(900000+$i),6,'0',STR_PAD_LEFT);$sym->symbol_id='KR|KOSPI|'.$sym->symbol;$sym->name='Synthetic identity '.$i;
        $eid='CG-LISTING-'.$i;$sym->identity_evidence_ids=[$eid];$sym->source_codes=(object)['yahoo'=>$sym->symbol.'.KS'];
        $items[]=(object)['symbol'=>$sym];$evidence[]=(object)['id'=>$eid,'symbol_id'=>$sym->symbol_id,'source_kind'=>'independent',
            'source_url'=>'https://kind.krx.co.kr/synthetic-test-only','checked_at'=>mon_iso(),'claim'=>'Synthetic primary listing fixture; no external observation',
            'identity'=>(object)['name'=>$sym->name,'country'=>'KR','symbol'=>$sym->symbol,'venue'=>'KOSPI','currency'=>'KRW']];
    }
    if($identityCount){$acquired=MonDiscovery::acquire($d,reinforcement_context($d),$items,$evidence);cg_ok($acquired['registered']===$identityCount);$d=mon_decode($acquired['files'][0]['content']);}
    if($missingPlan){$p=MonRun::payload($d);$p->detail_plans->{$d->watchlist->symbols[0]->symbol_id}=[];$d->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');}
    $c=reinforcement_context($d);$c->run_context_id=$b->run->run_context_id;$decision=reinforcement_decision($d,$c);$f=MonDetails::finalize($d,$decision);
    if($confirmed){$candidate=mon_decode($f['files'][0]['content']);$f=mon_apply_mon_patch($candidate,cg_patch($candidate,$b->run->run_context_id,MonBudget::iso($wall+.1),true,$price));$wall+=.2;}
    $a=MonArtifact::create($s,MonRun::json($d),$f['files'],['decision_sha256'=>hash('sha256',MonRun::json($decision)),'run_context_id'=>$b->run->run_context_id,'received_at'=>$b->run->received_at]);
    $git=new ReinforcementGit($d);
    // Clock closures own references after return through this object.
    $clock=(object)['wall'=>$wall,'mono'=>$mono];$pub=new MonPublication($s,new MonHttp(null,[$git,'transport']),progress_test_config($s->dir),static function()use($clock){return $clock->wall;},static function()use($clock){return $clock->mono;});
    MonExecution::resume($s,$b->run->run_context_id,static function()use($clock){return $clock->wall;},static function()use($clock){return $clock->mono;});$b=MonExecution::$active;
    $j=$pub->enqueue($a['artifact_id'],$git->head,$b,'synthetic_operator');
    MonExecution::event('final_prepared',['new_analysis_complete'=>true,'final_available'=>true,'analysis_origin'=>'secured_data_decision','save_status'=>'queued','artifact_id'=>$a['artifact_id'],'job_id'=>$j['job_id']]);
    return [$s,$b,$pub,$git,$j,$clock,$d];
}
function cg_external_save(ReinforcementGit $git,$artifact): void {
    $tree=$git->trees[$git->commits[$git->head]['tree']];foreach($artifact->files as $f){$git->blobs[$f->blob_sha]=$f->content;$tree[$f->path]=$f->blob_sha;}
    $sha=sha1(MonRun::json((object)$tree));$git->trees[$sha]=$tree;$next=sha1('external-connector-'.$git->head.$sha);$git->commits[$next]=['tree'=>$sha,'parents'=>[$git->head]];$git->head=$next;
}
if(defined('MON_GATE_LIBRARY_ONLY')&&MON_GATE_LIBRARY_ONLY)return;
cg_case('caller success flags and an empty unfinished list cannot complete an unverified run',function(){
    $s=cg_store();$w=microtime(true);$m=0.;$b=cg_start($s,'forged-flags',$w,$m);
    MonExecution::event('caller_claim',['new_analysis_complete'=>true,'final_available'=>true,'save_status'=>'saved']);$r=MonExecution::finish('finished',[]);
    cg_ok(!$r['finish_accepted']&&!$r['run']['fully_complete']&&$r['run']['finished_at']===null);
    cg_ok($r['run']['completion']['required_checks_pending']>=2&&$r['statistics_snapshot']->samples[0]->outcome==='incomplete');
});
cg_case('a saved file pair with missing price and issuer review remains resumable',function(){
    [$s,$b,$pub,$git,$j]=cg_publication();$out=$pub->process($j['job_id'],microtime(true)+10);
    cg_ok($out['publication_complete']&&$out['save_status']==='saved'&&!$out['fully_complete']);$r=MonExecution::finish('finished',[]);
    cg_ok(!$r['finish_accepted']&&$r['run']['next_action']==='continue_issuer_review'&&$r['run']['finished_at']===null);
    cg_ok(in_array('issuer_review',array_column($r['run']['completion']['pending_jobs'],'kind'),true));
    cg_ok(in_array('current_entry',array_column($r['run']['completion']['pending_jobs'],'kind'),true));
});
cg_case('repeated finish and resume cannot erase server required jobs or reset the receipt clock',function(){
    [$s,$b,$pub,$git,$j]=cg_publication();$pub->process($j['job_id'],microtime(true)+10);$one=MonExecution::finish();$received=$b->run->received_at;
    MonExecution::resume($s,$b->run->run_context_id);$two=MonExecution::finish('finished',[]);
    cg_ok(!$two['run']['fully_complete']&&$two['run']['completion']['required_checks_pending']>0&&$b->run->received_at===$received);
    cg_ok(count($two['statistics_snapshot']->samples)===1);
});
cg_case('fresh independent price trading and issuer evidence plus remote proof permit completion',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);$out=$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish();
    cg_ok($out['fully_complete']&&$r['finish_accepted']&&$r['run']['fully_complete'],mon_json($r['run']['completion'],false));
    cg_ok($r['run']['completion']['outcomes'][0]->state==='ENTRY_CONFIRMED');
    cg_ok($r['statistics_snapshot']->samples[0]->completion_contract===MonCompletion::CONTRACT&&$r['statistics_snapshot']->samples[0]->required_checks_pending===0);
    cg_ok(is_file($s->path('completion-receipt-'.$b->run->run_context_id.'.php')));
});
cg_case('a verified price outside entry zones completes as waiting and never becomes a buy',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true,200.);$out=$pub->process($j['job_id'],microtime(true)+10);
    cg_ok($out['fully_complete']&&$out['completion']['outcomes'][0]->state==='WAIT_ENTRY'&&!$out['completion']['outcomes'][0]->current_entry_confirmed,mon_json($out,false));
});
cg_case('freshness expiring before finish reopens the required checks despite saved publication',function(){
    [$s,$b,$pub,$git,$j,$clock]=cg_publication(true);$pub->process($j['job_id'],microtime(true)+10);$clock->wall+=301;$clock->mono+=301;
    $out=$pub->receipt($pub->load($j['job_id']));cg_ok($out['publication_complete']&&!$out['fully_complete']);$r=MonExecution::finish();
    cg_ok(!$r['run']['fully_complete']&&$r['run']['finished_at']===null&&$r['run']['next_action']==='continue_current_entry');
});
cg_case('a lost remote acknowledgement does not produce a completion receipt',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);$git->loseAck=true;$git->failConfirmation=true;$out=$pub->process($j['job_id'],microtime(true)+10);
    cg_ok(!$out['fully_complete']&&!$out['publication_complete']&&$git->patches===1);
    cg_ok(!is_file($s->path('completion-receipt-'.$b->run->run_context_id.'.php')));
});
cg_case('final-display reporting is refused while required checks are pending',function(){
    [$s,$b,$pub,$git,$j]=cg_publication();$pub->process($j['job_id'],microtime(true)+10);
    cg_fault(static function(){MonExecution::event('final_display_reported');},'MON_COMPLETION_PENDING');
});
cg_case('a tampered compact verification snapshot blocks completion without trusting flags',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);$pub->process($j['job_id'],microtime(true)+10);$path='completion-snapshot-'.$b->run->artifact_id.'.php';$v=$s->readPrivate($path);$v->scope->results[0]->price=1.;$s->writePrivate($path,$v);
    $view=MonExecution::view($b);cg_ok(!$view['fully_complete']&&$view['next_action']==='repair_verification_binding');
});
cg_case('proofs are bound to the original run rather than a copied success flag',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);$pub->process($j['job_id'],microtime(true)+10);$other=(object)['run_context_id'=>str_repeat('f',64),'received_at'=>$b->run->received_at,
        'new_analysis_complete'=>true,'final_available'=>true,'artifact_id'=>$b->run->artifact_id,'job_id'=>$j['job_id'],'save_status'=>'saved'];
    $gate=MonCompletion::assess($s,$other,mon_iso());cg_ok(!$gate['fully_complete']&&!$gate['publication_verified']);
});
cg_case('new statistics reject complete rows carrying pending checks and preserve older samples',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish();$bad=mon_decode(MonRun::json($r['statistics_snapshot']));$bad->samples[0]->required_checks_pending=1;
    $bad=MonRunStatistics::seal($bad);cg_fault(static function()use($bad){MonRunStatistics::validate($bad);},'MON_STATS_INVALID');
    $legacy=mon_decode(MonRun::json($r['statistics_snapshot']));foreach(['completion_contract','required_checks_pending','publication_verified','completion_receipt_id']as $k)unset($legacy->samples[0]->$k);
    cg_ok(MonRunStatistics::validate(MonRunStatistics::seal($legacy))instanceof stdClass);
});
cg_case('user interruption remains terminal and cannot be reopened by recovery',function(){
    [$s,$b,$pub,$git,$j]=cg_publication();$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish('interrupted');
    cg_ok(!$r['run']['fully_complete']&&$r['run']['next_action']==='interrupted');cg_fault(static function(){MonExecution::recoverFinal('confirm');},'MON_RUN_INTERRUPTED');
});
cg_case('closed-session reference plans complete as waiting after bounded issuer review',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true,null,true);$out=$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish();
    cg_ok($r['run']['fully_complete']&&$out['completion']['outcomes'][0]->state==='WAIT_SESSION',mon_json($out['completion'],false));
    cg_ok(!$out['completion']['outcomes'][0]->current_entry_confirmed&&count($out['completion']['deferred_checks'])===1);
});
cg_case('a closed market does not excuse the independently available issuer review',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(false,null,true);$out=$pub->process($j['job_id'],microtime(true)+10);
    cg_ok(!$out['fully_complete']&&in_array('issuer_review',array_column($out['completion']['pending_jobs'],'kind'),true));
    cg_ok(!in_array('current_entry',array_column($out['completion']['pending_jobs'],'kind'),true));
});
cg_case('supplemental confirmations repair only missing checks and complete the same original run',function(){
    [$s,$b,$pub,$git,$j,$clock]=cg_publication();$pub->process($j['job_id'],microtime(true)+10);MonExecution::finish();$received=$b->run->received_at;$oldId=$b->run->artifact_id;
    $clock->wall+=1;$clock->mono+=1;$data=$git->data();$patch=cg_patch($data,$b->run->run_context_id,MonBudget::iso($clock->wall));
    $request=(object)['action'=>'confirm','context'=>$patch->view_context,'source_json'=>MonRun::json($data),'decision_patch'=>$patch];
    $result=mon_recover_completion($s,$request,$b);cg_ok($result['manifest']['artifact_id']!==$oldId&&$b->run->received_at===$received);
    cg_ok($b->run->final_revisions[0]->artifact_id===$oldId&&!MonExecution::view($b)['fully_complete']);
    $new=$pub->enqueue($result['manifest']['artifact_id'],$git->head,$b,'synthetic_operator');
    MonExecution::event('publication_queued',['job_id'=>$new['job_id'],'save_status'=>'queued']);$out=$pub->process($new['job_id'],microtime(true)+10);
    $done=MonExecution::finish();cg_ok($out['fully_complete']&&$done['run']['fully_complete'],mon_json($done['run']['completion'],false));
    cg_ok(count($done['statistics_snapshot']->samples)===1&&$done['statistics_snapshot']->samples[0]->revision>1);
});
cg_case('recovery preserves a different concurrent analysis and cannot bypass source ownership',function(){
    [$s,$b,$pub,$git,$j,$clock]=cg_publication();$pub->process($j['job_id'],microtime(true)+10);$data=$git->data();$data->analysis->concurrent_marker='different-owner';$clock->wall+=1;
    $patch=cg_patch($data,$b->run->run_context_id,MonBudget::iso($clock->wall));$request=(object)['action'=>'confirm','context'=>$patch->view_context,'source_json'=>MonRun::json($data),'decision_patch'=>$patch];
    $before=$b->run->artifact_id;cg_fault(static function()use($s,$request,$b){mon_recover_completion($s,$request,$b);},'MON_ANALYSIS_SOURCE_CONFLICT');cg_ok($b->run->artifact_id===$before);
});
cg_case('recovery cannot revise an uncertain unpublished file pair',function(){
    [$s,$b,$pub,$git,$j,$clock]=cg_publication();$a=MonArtifact::load($s,$b->run->artifact_id);$data=mon_decode($a->files[0]->content);$clock->wall+=1;
    $patch=cg_patch($data,$b->run->run_context_id,MonBudget::iso($clock->wall));$request=(object)['action'=>'confirm','context'=>$patch->view_context,'source_json'=>MonRun::json($data),'decision_patch'=>$patch];
    cg_fault(static function()use($s,$request,$b){mon_recover_completion($s,$request,$b);},'MON_RECOVERY_PUBLICATION_REQUIRED');
});
cg_case('explicit recovery reopens preparation while retaining clock and immutable previous artifacts',function(){
    [$s,$b,$pub,$git,$j,$clock]=cg_publication();$pub->process($j['job_id'],microtime(true)+10);$oldId=$b->run->artifact_id;$received=$b->run->received_at;
    $data=$git->data();$context=reinforcement_context($data);$context->run_context_id=$b->run->run_context_id;
    $result=mon_recover_completion($s,(object)['action'=>'reprepare','context'=>$context,'source_json'=>MonRun::json($data)],$b);
    cg_ok($result['next_action']==='mon_detail_prepare_then_finalize'&&!$b->run->new_analysis_complete&&!property_exists($b->run,'artifact_id'));
    cg_ok($b->run->received_at===$received&&MonArtifact::load($s,$oldId)->artifact_id===$oldId&&!MonExecution::view($b)['fully_complete']);
    $decision=reinforcement_decision($data,$context);$final=MonDetails::finalize($data,$decision);cg_ok($final['new_analysis_complete']);
});
cg_case('identical validated content in different runs has separate protected artifact bindings',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);$one=MonArtifact::load($s,$b->run->artifact_id);$metadata=(array)$one->metadata;$metadata['run_context_id']=str_repeat('e',64);
    $files=array_map(static function($f){return ['path'=>$f->path,'content'=>$f->content,'blob_sha'=>$f->blob_sha];},$one->files);
    $two=MonArtifact::create($s,$one->source_json,$files,$metadata);cg_ok($two['artifact_id']!==$one->artifact_id&&MonArtifact::load($s,$one->artifact_id)->metadata->run_context_id===$b->run->run_context_id);
});
cg_case('connector-saved file pairs are verified with reads and no second public mutation',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);cg_external_save($git,MonArtifact::load($s,$b->run->artifact_id));$out=$pub->process($j['job_id'],microtime(true)+10);
    cg_ok($out['fully_complete']&&$git->patches===0&&$git->newCommits===0,mon_json($out,false));
    cg_ok(!array_filter($git->calls,static function($v){return $v[0]!=='GET';}));
});
cg_case('readback accepts later NAS collection without changing the validated decision or prices',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);$original=MonArtifact::load($s,$b->run->artifact_id);cg_external_save($git,$original);
    $latest=$git->data();$latest->collection->collection_id='later-nas-quote';$git->setSource($latest);$out=$pub->process($j['job_id'],microtime(true)+10);
    cg_ok($out['fully_complete']&&$git->patches===0,mon_json($out,false));$actual=MonArtifact::load($s,$out['artifact_id']);$candidate=mon_decode($actual->files[0]->content);
    cg_ok($candidate->collection->collection_id==='later-nas-quote'&&$candidate->analysis->as_of===$latest->analysis->as_of);
});
cg_case('public readback works without a write token and never sends authorization or a mutation',function(){
    [$s,$b,$old,$git,$j,$clock]=cg_publication(true);cg_external_save($git,MonArtifact::load($s,$b->run->artifact_id));
    $prior=getenv('GITHUB_TOKEN');putenv('GITHUB_TOKEN');file_put_contents($s->dir.'/readonly.env',"GITHUB_TOKEN=\n");
    try{$cfg=new MonConfig($s->dir.'/readonly.env',$s->dir);cg_ok($cfg->get('GITHUB_TOKEN')==='');
        $http=new MonHttp(null,static function($method,$url,$headers,$body,$timeout)use($git){cg_ok($method==='GET');cg_ok(!array_filter($headers,static function($h){return strpos($h,'Authorization:')===0;}));return $git->transport($method,$url,$headers,$body,$timeout);});
        $pub=new MonPublication($s,$http,$cfg,static function()use($clock){return $clock->wall;},static function()use($clock){return $clock->mono;});
        cg_ok($pub->process($j['job_id'],microtime(true)+10)['fully_complete']&&$git->patches===0);
    }finally{if($prior===false)putenv('GITHUB_TOKEN');else putenv('GITHUB_TOKEN='.$prior);}
});
cg_case('corrected authorization resumes the protected job and original clock after a blocked publication',function(){
    [$s,$b,$old,$git,$j,$clock]=cg_publication(true);$blocked=true;$http=new MonHttp(null,static function($method,$url,$headers,$body,$timeout)use($git,&$blocked){if($blocked)return $git->response(new stdClass(),401);return $git->transport($method,$url,$headers,$body,$timeout);});
    $pub=new MonPublication($s,$http,progress_test_config($s->dir),static function()use($clock){return $clock->wall;},static function()use($clock){return $clock->mono;});
    $received=$b->run->received_at;$out=$pub->process($j['job_id'],microtime(true)+10);cg_ok($out['save_status']==='failed'&&!$out['fully_complete']);
    $blocked=false;$pub->resume($j['job_id']);$out=$pub->process($j['job_id'],microtime(true)+10);
    cg_ok($out['fully_complete']&&$out['job_id']===$j['job_id']&&$b->run->received_at===$received&&$git->patches===1,mon_json($out,false));
});
cg_case('a declared identity quota shortfall remains required even after live checks and saving',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true,null,false,0);$out=$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish('finished',[]);
    cg_ok($out['publication_complete']&&!$out['fully_complete']&&!$r['finish_accepted']&&$r['run']['next_action']==='continue_discovery');
    cg_ok(in_array('new_identity_quota',array_column($r['run']['completion']['pending_jobs'],'kind'),true));
});
cg_case('a genuine completed run may report its actual final display after receiving its receipt',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);$pub->process($j['job_id'],microtime(true)+10);$done=MonExecution::finish();cg_ok($done['finish_accepted']);
    MonExecution::event('final_display_reported');cg_ok($b->run->counts->displays_reported===1&&MonExecution::view($b)['fully_complete']);
});
cg_case('candidate order changes cannot invalidate verified facts after serialization',function(){
    $d=reinforcement_fixture(2);$d->analysis->candidate_audit=array_reverse($d->analysis->candidate_audit);$c=reinforcement_context($d);$f=MonDetails::finalize($d,reinforcement_decision($d,$c));
    $saved=mon_decode($f['files'][0]['content']);$payload=MonRun::payload($saved);MonRun::verifyFacts($saved,$payload);
    $keys=array_keys(get_object_vars($payload->swing_signals));$sorted=$keys;sort($sorted,SORT_STRING);cg_ok($keys===$sorted);
});
cg_case('legacy stored-completion statistics are distinguished from verified completion receipts',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true);$pub->process($j['job_id'],microtime(true)+10);$done=MonExecution::finish();$legacy=mon_decode(MonRun::json($done['statistics_snapshot']));
    foreach(['completion_contract','required_checks_pending','publication_verified','completion_receipt_id']as $key)unset($legacy->samples[0]->$key);
    $summary=MonRunStatistics::summary(MonRunStatistics::seal($legacy),'mon',microtime(true)+1);cg_ok($summary['legacy_completed']===1&&$summary['verified_completed']===0);
});
cg_case('a missing entry plan directs recovery to preparation rather than repeated quote requests',function(){
    [$s,$b,$pub,$git,$j]=cg_publication(true,130.,false,20,true);$out=$pub->process($j['job_id'],microtime(true)+10);$r=MonExecution::finish();
    cg_ok($out['publication_complete']&&!$out['fully_complete']&&$r['run']['next_action']==='continue_preparation',mon_json($r['run']['completion'],false));
    cg_ok(in_array('MON_ENTRY_PLAN_PREPARATION_REQUIRED',array_column($r['run']['completion']['pending_jobs'],'code'),true));
});
function cg_clean(string $p): void {foreach(scandir($p)as $f)if($f!=='.'&&$f!=='..'){if(is_dir($p.'/'.$f))cg_clean($p.'/'.$f);else unlink($p.'/'.$f);}rmdir($p);}
foreach($dirs as $dir)cg_clean($dir);$failed=count(array_filter($cases,static function($r){return !$r['pass'];}));
echo mon_json(['version'=>MON_VERSION,'php'=>PHP_VERSION,'tests'=>count($cases),'passed'=>count($cases)-$failed,'failed'=>$failed,'wall_seconds'=>round(mon_monotonic()-$started,6),'peak_memory_bytes'=>memory_get_peak_usage(true),'external_requests'=>0,'orders'=>0,'cases'=>$cases]);exit($failed?1:0);

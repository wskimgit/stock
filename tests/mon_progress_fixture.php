<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/mon.php';
// Synthetic prices, identities, credentials and calendar. Never read live broker settings.
function progress_fixture(int $count=3): stdClass {
    $now=time();$date=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Seoul'))->modify('-1 day');
    while((int)$date->format('N')>5)$date=$date->modify('-1 day');$last=$date->format('Y-m-d');$dates=[];
    for($i=0;$i<70;$i++){$dates[]=$date->format('Y-m-d');do{$date=$date->modify('-1 day');}while((int)$date->format('N')>5);}sort($dates);
    $symbols=[];$series=new stdClass();$metrics=new stdClass();$metadata=new stdClass();$snapshots=new stdClass();$requests=new stdClass();$bench=[];
    foreach($dates as $day)$bench[]=[$day,null,null,null,100.0,null];$series->{'KR|INDEX|^KS11'}=$bench;
    for($i=1;$i<=$count;$i++){
        $code=str_pad((string)$i,6,'0',STR_PAD_LEFT);$sid='KR|KOSPI|'.$code;
        $symbol=(object)['symbol_id'=>$sid,'country'=>'KR','exchange'=>'KOSPI','symbol'=>$code,'name'=>'Fixture '.$i,'currency'=>'KRW','purpose'=>'fixture',
            'candidate_origin'=>['new_in_mon'],'is_held'=>false,'position'=>null,'tick_size'=>1,'source_codes'=>(object)['kis'=>(object)['market_code'=>'J','symbol'=>$code],'yahoo'=>$code.'.KS']];
        $bars=[];foreach($dates as $n=>$day){$close=100+$i+$n*.2;$bars[]=[$day,$close-.5,$close+2,$close-2,$close,1000.0];}
        $origin=(object)['source'=>'FIXTURE','source_url'=>'https://example.invalid/fixture','checked_at'=>mon_iso($now-600),'complete_source_window'=>true];
        $s=MonDetails::snapshot($symbol,$bars,$bench,'KR|INDEX|^KS11',$origin,$origin);$symbols[]=$symbol;$snapshots->$sid=$s;
        $series->$sid=$s->bars;$metrics->$sid=$s->metrics;$metadata->$sid=$s->history_metadata;
        $r=(object)['contract'=>MonDetails::CONTRACT,'request_id'=>'fixture-'.$i,'symbol_id'=>$sid,'symbol'=>$symbol,'criteria_version'=>MonSwing::CRITERIA,
            'requested_at'=>mon_iso($now-1),'deadline_at'=>mon_iso($now+120),'target_completed_date'=>$last,'target_verified'=>true,'force_refresh'=>false];
        $r->request_hash=MonDetails::requestHash($r);$requests->$sid=$r;
    }
    $payload=(object)['series'=>$series,'candidate_metrics'=>$metrics,'history_metadata'=>$metadata];
    $empty=hash('sha256','fixture');
    return (object)['schema_version'=>3,'watchlist'=>(object)['schema_version'=>3,'criteria_version'=>MonSwing::CRITERIA,'watchlist_version'=>1,'updated_at'=>mon_iso($now-1),'run_id'=>'fixture',
            'settings'=>(object)['enabled'=>true,'investment_style'=>'swing','swing_profile'=>MonSwing::rules()],
            'calendar'=>(object)['valid_until'=>mon_iso($now+86400),'markets'=>[(object)['country'=>'KR','timezone'=>'Asia/Seoul','checked_at'=>mon_iso($now-60),'sessions'=>[]]]],
            'symbols'=>$symbols,'detail_requests'=>$requests],
        'collection'=>(object)['schema_version'=>3,'collection_id'=>'fixture','watchlist_version'=>1,'started_at'=>mon_iso($now-1),'completed_at'=>mon_iso($now-1),'status'=>'partial','next_cursor'=>0,'quotes'=>[],'detail_responses'=>new stdClass()],
        'analysis'=>(object)['schema_version'=>3,'criteria_version'=>MonSwing::CRITERIA,'run_id'=>'fixture','as_of'=>mon_iso($now-600),'analyzed_at'=>mon_iso($now-600),'watchlist_version'=>1,
            'collection_id'=>'fixture','status'=>'partial','selection_fingerprint'=>$empty,'input_fingerprint'=>$empty,'result_fingerprint'=>$empty,'coverage'=>[],'independent_results'=>[],'results'=>[],
            'candidate_audit'=>[],'candidate_history'=>[],'changes'=>[],'evidence'=>[],'input_snapshot'=>(object)['lossless_payload'=>MonRun::pack($payload,'gzip+base64')]],
        '_test_snapshots'=>$snapshots];
}
function progress_response($d,string $sid,string $state='done',string $status='reused_current'): stdClass {
    $r=$d->watchlist->detail_requests->$sid;$s=$d->_test_snapshots->$sid;
    return (object)['contract'=>MonDetails::CONTRACT,'request_id'=>$r->request_id,'request_hash'=>$r->request_hash,'symbol_id'=>$sid,'state'=>$state,'data_status'=>$status,
        'attempts'=>0,'completed_at'=>mon_iso(),'retry_after'=>$state==='retry_wait'?time()+30:null,'error'=>null,'conflict'=>false,'usable'=>true,
        'bars_as_of'=>$s->metrics->bars_as_of,'source_checked_at'=>$s->history_metadata->checked_at,'partial_fields'=>null,'payload'=>MonDetails::pack($s)];
}
function progress_test_config(string $dir): MonConfig {
    file_put_contents($dir.'/fixture.env',"GITHUB_TOKEN=fixture-token\nKIS_APP_KEY=fixture-app\nKIS_APP_SECRET=fixture-secret\n");
    return new MonConfig($dir.'/fixture.env',$dir);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo mon_json(progress_fixture());

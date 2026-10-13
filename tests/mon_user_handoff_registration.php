<?php
declare(strict_types=1);
/** One-shot MON candidate handoff recovery, user-directed, no daemon, no orders.
 * Uses only released MonDetails, MonArtifact and MonPublication primitives.
 * The publishing journal + Git tree transaction preserve concurrent collector updates.
 */
require dirname(__DIR__).'/mon.php';
$token=(string)getenv('GITHUB_TOKEN');
if(strlen($token)<15){fwrite(STDERR,"MON_RECOVERY_AUTH_UNAVAILABLE\n");exit(4);}
$dir=sys_get_temp_dir().'/mon_bridge_registration_'.bin2hex(random_bytes(6));
if(!@mkdir($dir,0700,true))throw new RuntimeException('state_dir_unavailable');
$env=$dir.'/mon-operation.env';
if(file_put_contents($env,"GITHUB_TOKEN=".$token."\nMON_REPOSITORY=wskimgit/stock\nMON_BRANCH=main\n",LOCK_EX)===false)throw new RuntimeException('config_unavailable');
@chmod($env,0600);
unset($token);
$store=new MonStore($dir);
$cfg=new MonConfig($env,$dir);
$http=new MonHttp();
$failed=null;$receipt=null;$elapsed=microtime(true);
function rec_fetch_raw(MonHttp $http,MonConfig $cfg,string $path,float $deadline): string {
    $url='https://api.github.com/repos/wskimgit/stock/contents/'.$path.'?ref=main';
    $headers=['Accept: application/vnd.github.raw+json',
        'Authorization: Bearer '.$cfg->get('GITHUB_TOKEN'),
        'X-GitHub-Api-Version: 2022-11-28'];
    $r=$http->request('GET',$url,$headers,null,$deadline,5);
    if($r['status']<200||$r['status']>=300)MonHttp::decode($r);
    if(!is_string($r['body'])||strlen($r['body'])>MonRun::MAX_SOURCE)
        throw new MonFault('MON_RECOVERY_SOURCE_INVALID');
    return $r['body'];
}
function rec_head(MonHttp $http,MonConfig $cfg,float $deadline): string {
    $r=MonHttp::decode($http->request('GET','https://api.github.com/repos/wskimgit/stock/git/ref/heads/main',
        ['Accept: application/vnd.github+json',
         'Authorization: Bearer '.$cfg->get('GITHUB_TOKEN'),
         'X-GitHub-Api-Version: 2022-11-28'],null,$deadline,5));
    $sha=mon_get(mon_get($r,'object'),'sha');
    if(!preg_match('/^[a-f0-9]{40}$/',(string)$sha))throw new MonFault('MON_RECOVERY_HEAD_INVALID');
    return $sha;
}
try {
    $deadline=microtime(true)+120;
    $source=rec_fetch_raw($http,$cfg,'mon_data.json',$deadline);
    $inbox=rec_fetch_raw($http,$cfg,'mon_chatgpt_handoff.json',$deadline);
    $data=mon_decode($source);$q=mon_decode($inbox);
    MonRun::validate($data);MonChatGPTBridge::validate($q);
    if($q->stage!=='candidates'||$q->request_id!=='mon-20261014-0816-kst-001')
        throw new MonFault('MON_RECOVERY_REQUEST_CHANGED');
    $ids=$q->candidate_ids;$sourceSha=MonRun::gitSha($source);
    $watch=MonRun::rows($data->watchlist->symbols);$pool=MonDiscovery::pool($data);
    $registered=MonDetails::requests($data);
    $all=true;foreach($ids as $sid){
        $expected='cb.'.substr(hash('sha256',$q->request_id.'|'.$sid),0,48);
        if(mon_get(mon_get($registered,$sid),'request_id')!==$expected){$all=false;break;}
    }
    if($all){
        echo json_encode(['state'=>'already_registered','request_id'=>$q->request_id,
            'source_blob_sha'=>$sourceSha,'matched_request_count'=>count($ids)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        exit(0);
    }
    $items=[];$unknown=[];
    foreach($ids as $sid) {
        $s=$watch[$sid]??mon_get($pool,$sid);
        if(!($s instanceof stdClass)){$unknown[]=$sid;continue;}
        $items[]=(object)['symbol'=>$s,'request_id'=>'cb.'.substr(hash('sha256',$q->request_id.'|'.$sid),0,48),
            'wait_seconds'=>120,'force_refresh'=>true];
    }
    if(!$items)throw new MonFault('MON_RECOVERY_NO_VALID_CANDIDATES');
    $context=(object)['source_blob_sha'=>$sourceSha,'rendered_at'=>mon_iso(),
        'countries'=>['KR','US','JP'],'important_ids'=>$ids];
    $prepared=MonDetails::registerMany($data,$context,$items);
    $errors=array_values(array_filter($prepared['requests'],static function($x){return $x['error']!==null;}));
    if(!$prepared['write_required'])throw new MonFault('MON_RECOVERY_NO_CHANGE');
    if(count($prepared['files'])<2)throw new MonFault('MON_RECOVERY_UNEXPECTED_OUTPUT');
    $files=$prepared['files'];
    $outputTypes=array_map(static function($f){return $f['path'];},$files);
    unset($prepared,$data,$watch,$pool,$items);
    MonRun::releasePayloadCache();if(function_exists('gc_collect_cycles'))gc_collect_cycles();
    $budget=MonExecution::start($store,(object)['run_id'=>'chatbridge-intake-mon-20261014-0816-kst-001',
        'mode'=>'auto','workload'=>'mon_handoff']);
    $meta=['decision_sha256'=>hash('sha256',MonRun::json((object)[
        'contract'=>MonChatGPTBridge::CONTRACT,'stage'=>'candidates',
        'candidate_request_sha'=>MonRun::gitSha($inbox)])),
        'run_context_id'=>$budget->run->run_context_id,
        'received_at'=>$budget->run->received_at,
        'countries'=>['KR','US','JP']];
    $manifest=MonArtifact::create($store,$source,$files,$meta);
    $head=rec_head($http,$cfg,microtime(true)+30);
    $publisher=new MonPublication($store,$http,$cfg);
    $receipt=$publisher->enqueue($manifest['artifact_id'],$head,$budget,'chatgpt_user_requested_mon');
    $tries=0;
    do {
        $tries++;
        $receipt=$publisher->process($receipt['job_id'],microtime(true)+20);
        if($receipt['save_status']==='saved')break;
        if(!in_array($receipt['save_status'],['queued','publishing','unknown'],true))break;
        if($receipt['save_status']==='unknown'&&$receipt['last_error']===null)break;
        if($tries<12)usleep(500000);
    }while($tries<12);
    $actual=rec_fetch_raw($http,$cfg,'mon_data.json',microtime(true)+20);
    $saved=mon_decode($actual);$savedRequests=MonDetails::requests($saved);
    $matched=0;
    foreach($ids as $sid) {
        $expected='cb.'.substr(hash('sha256',$q->request_id.'|'.$sid),0,48);
        if(mon_get(mon_get($savedRequests,$sid),'request_id')===$expected)$matched++;
    }
    $report=['contract'=>'MON-HANDOFF-REGISTER-RECOVERY-1.0','request_id'=>$q->request_id,
        'status'=>$receipt['save_status']==='saved'&&$matched===count($ids)?'registered_saved':'partial_unverified',
        'submitted'=>count($ids),'matched_saved_request_count'=>$matched,'unknown_ids'=>$unknown,
        'registration_errors'=>array_map(static function($x){return ['id'=>$x['symbol_id'],'error'=>$x['error']];},$errors),
        'file_paths'=>$outputTypes,'published_commit_sha'=>$receipt['published_commit_sha']??null,
        'remote_mon_data_sha'=>MonRun::gitSha($actual),'save_status'=>$receipt['save_status'],
        'last_error'=>$receipt['last_error'],'attempts'=>$tries,'elapsed_seconds'=>round(microtime(true)-$elapsed,3),
        'memory_peak_bytes'=>memory_get_peak_usage(true)];
    echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    exit($report['status']==='registered_saved'?0:2);
} catch(Throwable $e) {
    $failed=$e instanceof MonFault?$e->faultCode:get_class($e);
    echo json_encode(['status'=>'failed','error_code'=>$failed,'detail'=>$e instanceof MonFault?null:$e->getMessage(),
        'save_status'=>$receipt['save_status']??null,
        'elapsed_seconds'=>round(microtime(true)-$elapsed,3),
        'memory_peak_bytes'=>memory_get_peak_usage(true)],JSON_PRETTY_PRINT)."\n";
    exit(1);
} finally {
    @unlink($env);
}

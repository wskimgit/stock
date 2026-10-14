<?php
declare(strict_types=1);
/* Read-only production snapshot, synthetic remote Git; never contact a provider. */
require __DIR__.'/mon_reinforcement_fixture.php';
function cc_ok(bool $good,string $message):void{if(!$good)throw new RuntimeException($message);}
class CompactionFakeGit extends ReinforcementGit {
    public $mail=[];
    public $mailSha=[];
    public function transport($method,$url,$headers,$body,$timeout):array {
        $path=(string)parse_url($url,PHP_URL_PATH);
        $path=substr($path,strlen('/repos/wskimgit/stock'));
        if(preg_match('~^/contents/(mon_chatgpt_handoff\\.json|mon_chatgpt_response\\.json)$~',$path,$m)){
            $key=$m[1];
            if($method==='GET')return ['status'=>isset($this->mail[$key])?200:404,'headers'=>[],'body'=>$this->mail[$key]??'{}'];
            if($method==='PUT'){
                $r=mon_decode($body);$expected=mon_get($r,'sha');
                if($expected!==($this->mailSha[$key]??null))return ['status'=>409,'headers'=>[],'body'=>'{}'];
                $raw=base64_decode($r->content,true);if(!is_string($raw))throw new RuntimeException('bad base64');
                $sha=MonRun::gitSha($raw);$this->mail[$key]=$raw;$this->mailSha[$key]=$sha;
                return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['content'=>(object)['sha'=>$sha]])];
            }
        }
        return parent::transport($method,$url,$headers,$body,$timeout);
    }
}
// Frozen pre-compaction source makes the multi-file transaction deterministic.
$observed=getenv('MON_D22_OBSERVED_SOURCE');
$pathSource=is_string($observed)&&$observed!==''?$observed:dirname(__DIR__).'/mon_data.json';
$raw=file_get_contents($pathSource);
if(!is_string($raw))throw new RuntimeException('SOURCE_READ_FAILED');
$d=mon_decode($raw);MonRun::validate($d);
$git=new CompactionFakeGit($d);
$path=sys_get_temp_dir().'/mon-bridge-compact-'.bin2hex(random_bytes(5));mkdir($path,0700);
try{
    $store=new MonStore($path);$cfg=progress_test_config($path);$http=new MonHttp(null,[$git,'transport']);$gh=new MonGitHub($http,$cfg);
    $symbols=array_slice(array_values(array_filter($d->watchlist->symbols,static function($s){return $s->is_held===false;})),0,3);
    $ids=array_map(static function($s){return $s->symbol_id;},$symbols);
    $q=(object)['contract'=>MonChatGPTBridge::CONTRACT,'stage'=>'candidates',
        'request_id'=>'real-capacity-intake','candidate_ids'=>$ids];
    $inbox=mon_json($q,false);$git->mail[MonChatGPTBridge::INBOX]=$inbox;
    $git->mailSha[MonChatGPTBridge::INBOX]=MonRun::gitSha($inbox);
    $latest=$gh->read(microtime(true)+40);
    MonChatGPTBridge::poll($store,$http,$cfg,$gh,$latest,microtime(true)+40);
    $s=$store->readPrivate(MonChatGPTBridge::STATE);
    cc_ok(is_string(mon_get($s,'pending_candidate_job_id')),'multi-file intake did not queue');
    $queue=mon_decode($git->mail[MonChatGPTBridge::OUTBOX]);
    cc_ok($queue->status==='registration_publishing','not visibly pending');
    $pub=new MonPublication($store,$http,$cfg);
    $receipt=$pub->process($s->pending_candidate_job_id,microtime(true)+40);
    cc_ok($receipt['save_status']==='saved','multi-file publication did not finish: '.(string)$receipt['last_error']);
    $tree=$git->trees[$git->commits[$git->head]['tree']];
    cc_ok(isset($tree['mon_data.json'],$tree['mon_result.md']),'financial pair not atomic');
    $archive=array_filter(array_keys($tree),static function($p){return strpos($p,'mon_recovery_archive_')===0;});
    cc_ok(count($archive)>=1,'archive was not included');
    $s=$store->readPrivate(MonChatGPTBridge::STATE);$s->next_poll_at=0;$store->writePrivate(MonChatGPTBridge::STATE,$s);
    MonChatGPTBridge::poll($store,$http,$cfg,$gh,$gh->read(microtime(true)+40),microtime(true)+40);
    $out=mon_decode($git->mail[MonChatGPTBridge::OUTBOX]);
    cc_ok(in_array($out->status,['registered','registered_partial'],true),'no successful receipt');
    cc_ok((int)$out->registered_count>=1,'no registered candidate survived');
    echo json_encode(['case'=>'real_data_3file_atomic_candidate_intake',
        'status'=>'PASS','archive_count'=>count($archive),'registered'=>$out->registered_count,
        'peak_memory_bytes'=>memory_get_peak_usage(true),'publication_commit'=>$receipt['published_commit_sha']!==null,
        'source_bytes'=>strlen($raw)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
}finally{
    foreach(scandir($path)as $p)if($p!=='.'&&$p!=='..'&&is_file($path.'/'.$p))unlink($path.'/'.$p);
    rmdir($path);
}

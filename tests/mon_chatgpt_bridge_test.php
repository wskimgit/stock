<?php
declare(strict_types=1);
/* Synthetic GitHub mailbox + MON integration: no remote network, no credentials, no orders. */
require __DIR__.'/mon_reinforcement_fixture.php';

function bh_assert($v,string $m):void {if(!$v)throw new RuntimeException($m);}
function bh_fault(callable $f,string $expected):void {try{$f();}catch(MonFault $e){bh_assert($e->faultCode===$expected,'wrong fault '.$e->faultCode);return;}throw new RuntimeException('expected '.$expected);}
function bh_room():string {$path=sys_get_temp_dir().'/mon-bridge-'.bin2hex(random_bytes(6));mkdir($path,0700);return $path;}
function bh_clean(string $path):void {foreach(scandir($path)as $v)if($v!=='.'&&$v!=='..'){if(is_dir($path.'/'.$v))bh_clean($path.'/'.$v);else unlink($path.'/'.$v);}rmdir($path);}
final class BridgeFakeGit {
    public $git;
    public $calls=[];
    public function __construct($d){$this->git=new ReinforcementGit($d);}
    public function sha(string $path):?string {
        $tree=$this->git->trees[$this->git->commits[$this->git->head]['tree']];
        return $tree[$path]??null;
    }
    public function raw(string $path):?string {$sha=$this->sha($path);return $sha===null?null:$this->git->blobs[$sha];}
    public function update(string $path,string $raw,?string $sha=null):string {
        if($sha!==null)bh_assert($sha===$this->sha($path),'optimistic conflict');
        $blob=MonRun::gitSha($raw);$this->git->blobs[$blob]=$raw;
        $tree=$this->git->trees[$this->git->commits[$this->git->head]['tree']];
        $tree[$path]=$blob;ksort($tree,SORT_STRING);
        $treeSha=sha1(MonRun::json((object)$tree));$this->git->trees[$treeSha]=$tree;
        $commit=sha1('bridge.'.(string)count($this->calls).'|'.$this->git->head.'|'.$treeSha);
        $this->git->commits[$commit]=['tree'=>$treeSha,'parents'=>[$this->git->head]];
        $this->git->head=$commit;return $blob;
    }
    public function transport($method,$url,$headers,$body,$timeout):array {
        $path=(string)parse_url($url,PHP_URL_PATH);
        $path=substr($path,strlen('/repos/wskimgit/stock'));
        $this->calls[]=[$method,$path];
        if(preg_match('~^/contents/(mon_chatgpt_handoff\\.json|mon_chatgpt_response\\.json|mon_data\\.json)$~',$path,$match)){
            $name=$match[1];
            if($method==='GET'){
                $raw=$this->raw($name);
                return ['status'=>$raw===null?404:200,'headers'=>[],'body'=>$raw??'{}'];
            }
            if($method==='PUT'){
                $q=mon_decode($body);$expected=mon_get($q,'sha');
                if($expected!==null&&$expected!==$this->sha($name))
                    return ['status'=>409,'headers'=>[],'body'=>'{}'];
                if($expected===null&&$this->sha($name)!==null)
                    return ['status'=>422,'headers'=>[],'body'=>'{}'];
                $decoded=base64_decode($q->content,true);
                if(!is_string($decoded))return ['status'=>400,'headers'=>[],'body'=>'{}'];
                $sha=$this->update($name,$decoded,$expected);
                return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['content'=>(object)['sha'=>$sha]])];
            }
        }
        return $this->git->transport($method,$url,$headers,$body,$timeout);
    }
}
$cases=[];$fail=[];$dir=bh_room();
function bh_case(string $name,callable $f):void {global $cases,$fail;try{$f();$cases[]=['case'=>$name,'status'=>'PASS'];}catch(Throwable $e){$cases[]=['case'=>$name,'status'=>'FAIL','error'=>get_class($e).': '.$e->getMessage()];$fail[]=$name;}}
try {
    $d=reinforcement_fixture(2);
    $snapshots=$d->_test_snapshots;unset($d->_test_snapshots);
    $fake=new BridgeFakeGit($d);
    $store=new MonStore($dir);$cfg=progress_test_config($dir);
    $http=new MonHttp(null,[$fake,'transport']);
    $gh=new MonGitHub($http,$cfg);
    $ids=array_map(static function($s){return $s->symbol_id;},$d->watchlist->symbols);
    $requestId='integration-bridge-1';
    $candidate=(object)['contract'=>MonChatGPTBridge::CONTRACT,'stage'=>'candidates',
      'request_id'=>$requestId,'candidate_ids'=>$ids];
    $candidateRaw=mon_json($candidate,false);
    $candidateSha=$fake->update(MonChatGPTBridge::INBOX,$candidateRaw);
    bh_case('strictly reject duplicate candidate identities',static function()use($ids,$requestId){
       $bad=(object)['contract'=>MonChatGPTBridge::CONTRACT,'stage'=>'candidates','request_id'=>$requestId,'candidate_ids'=>[$ids[0],$ids[0]]];
       bh_fault(static function()use($bad){MonChatGPTBridge::validate($bad);},'MON_BRIDGE_DUPLICATE_CANDIDATE');
    });
    bh_case('first-stage ChatGPT candidate request registered in real mon_data structure',function()use($gh,$store,$http,$cfg,$fake,$ids,$candidateSha){
       $latest=$gh->read(microtime(true)+30);
       $out=MonChatGPTBridge::poll($store,$http,$cfg,$gh,$latest,microtime(true)+30);
       bh_assert($out['sha']===$fake->sha('mon_data.json'),'mon_data readback mismatch');
       foreach($ids as $sid)bh_assert(strpos($out['data']->watchlist->detail_requests->$sid->request_id,'cb.')===0,'missing candidate '.$sid);
       $r=mon_decode((string)$fake->raw(MonChatGPTBridge::OUTBOX));
       bh_assert($r->request_sha===$candidateSha&&in_array($r->status,['registered','registered_partial'],true),'candidate receipt not returned');
    });
    bh_case('duplicate poll is idempotent without reminting request ids',function()use($gh,$store,$http,$cfg,$fake,$ids){
       $prior=MonRun::json($gh->read(microtime(true)+30)['data']->watchlist->detail_requests);
       $state=$store->readPrivate(MonChatGPTBridge::STATE);$state->next_poll_at=0;$store->writePrivate(MonChatGPTBridge::STATE,$state);
       $out=MonChatGPTBridge::poll($store,$http,$cfg,$gh,$gh->read(microtime(true)+30),microtime(true)+30);
       bh_assert(MonRun::json($out['data']->watchlist->detail_requests)===$prior,'duplicate changed requests');
    });
    bh_case('incomplete collection returns explicit pending, never fake data',function()use($gh,$store,$http,$cfg,$fake){
       $latest=$gh->read(microtime(true)+30);
       MonChatGPTBridge::export($store,$http,$cfg,$latest['data'],$latest['sha'],microtime(true)+30);
       $r=mon_decode((string)$fake->raw(MonChatGPTBridge::OUTBOX));
       bh_assert($r->stage==='details'&&$r->status==='collection_pending'&&count($r->rows)===2,'pending report incorrect');
       foreach($r->rows as $row)bh_assert($row->usable===false,'pending marked usable');
    });
    bh_case('PHP validated snapshots returned to ChatGPT with exact source and receipt hash',function()use($gh,$store,$http,$cfg,$fake,$ids,$snapshots){
       $fresh=$gh->read(microtime(true)+30)['data'];
       $fresh->_test_snapshots=$snapshots;
       foreach($ids as $sid)$fresh->collection->detail_responses->$sid=progress_response($fresh,$sid,'done','latest');
       unset($fresh->_test_snapshots);
       $fake->update('mon_data.json',MonRun::json($fresh));
       $latest=$gh->read(microtime(true)+30);
       MonChatGPTBridge::export($store,$http,$cfg,$latest['data'],$latest['sha'],microtime(true)+30);
       $r=mon_decode((string)$fake->raw(MonChatGPTBridge::OUTBOX));
       bh_assert($r->stage==='details'&&$r->status==='collected'&&count($r->rows)===2,'collection incomplete');
       foreach($r->rows as $row)bh_assert($row->usable===true&&$row->facts->metrics->last_close>0,'validated facts absent');
       $s=$store->readPrivate(MonChatGPTBridge::STATE);
       bh_assert($s->last_detail_source_sha===$fake->sha('mon_data.json')&&$s->last_detail_receipt_sha===$fake->sha(MonChatGPTBridge::OUTBOX),'response SHA not persisted');
    });
    bh_case('decision missing returned detail receipt is rejected',function()use($ids,$requestId,$candidateSha,$fake){
       $bad=(object)['contract'=>MonChatGPTBridge::CONTRACT,'stage'=>'decision','request_id'=>$requestId,
           'candidate_ids'=>$ids,'source_blob_sha'=>$fake->sha('mon_data.json'),
           'candidate_request_sha'=>$candidateSha,'selected'=>[],'references'=>[]];
       bh_fault(static function()use($bad){MonChatGPTBridge::validate($bad);},'MON_BRIDGE_DECISION_INVALID');
    });
    bh_case('ChatGPT decision is accepted then atomically published',function()use($gh,$store,$http,$cfg,$fake,$ids,$requestId,$candidateSha){
       $decision=(object)['contract'=>MonChatGPTBridge::CONTRACT,'stage'=>'decision','request_id'=>$requestId,
           'candidate_ids'=>$ids,'candidate_request_sha'=>$candidateSha,
           'details_receipt_sha'=>$fake->sha(MonChatGPTBridge::OUTBOX),
           'source_blob_sha'=>$fake->sha('mon_data.json'),
           'selected'=>[(object)['symbol_id'=>$ids[0],'reason'=>'Synthetic ChatGPT selection after comparing all returned facts']],
           'references'=>[]];
       $fake->update(MonChatGPTBridge::INBOX,mon_json($decision,false),$fake->sha(MonChatGPTBridge::INBOX));
       $st=$store->readPrivate(MonChatGPTBridge::STATE);$st->next_poll_at=0;$store->writePrivate(MonChatGPTBridge::STATE,$st);
       MonChatGPTBridge::poll($store,$http,$cfg,$gh,$gh->read(microtime(true)+30),microtime(true)+30);
       $st=$store->readPrivate(MonChatGPTBridge::STATE);
       bh_assert(is_string(mon_get($st,'publication_job_id')),'publication not enqueued');
       $out=mon_decode((string)$fake->raw(MonChatGPTBridge::OUTBOX));
       bh_assert($out->stage==='decision'&&$out->status==='publication_pending','incorrect decision report');
       $pub=new MonPublication($store,$http,$cfg);$receipt=$pub->process($st->publication_job_id,microtime(true)+25);
       bh_assert($receipt['save_status']==='saved','publication not saved');
       $data=mon_decode((string)$fake->raw('mon_data.json'));MonRun::validate($data);
       bh_assert(count($data->analysis->results)>=1,'financial selection absent');
       bh_assert($data->analysis->results[0]->autonomous_decision->selector==='ChatGPT','PHP self selected');
       bh_assert($fake->sha('mon_result.md')!==null,'financial result pair not committed');
       $st=$store->readPrivate(MonChatGPTBridge::STATE);$st->next_poll_at=0;$store->writePrivate(MonChatGPTBridge::STATE,$st);
       MonChatGPTBridge::poll($store,$http,$cfg,$gh,$gh->read(microtime(true)+30),microtime(true)+30);
       $out=mon_decode((string)$fake->raw(MonChatGPTBridge::OUTBOX));
       bh_assert($out->status==='saved'&&$out->published_commit_sha===$fake->git->head,'no read-back receipt');
    });
} finally {bh_clean($dir);}
echo json_encode(['suite'=>'MON-CHATGPT-BRIDGE-1.0','cases'=>$cases,'passed'=>count($cases)-count($fail),'failed'=>count($fail),'failure_names'=>$fail],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
exit($fail?1:0);

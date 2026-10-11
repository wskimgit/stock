<?php
declare(strict_types=1);
require_once __DIR__.'/mon_progress_fixture.php';
function reinforcement_fixture(int $count=3): stdClass {
    $d=progress_fixture($count);$now=time();$p=MonRun::payload($d);$p->detail_plans=new stdClass();$p->swing_signals=new stdClass();
    $d->analysis->candidate_audit=[];$d->analysis->coverage=[];$last=null;
    foreach($d->watchlist->symbols as $s){
        $bars=$p->series->{$s->symbol_id};foreach($bars as $i=>&$row){$close=$i<55?100+$i*.3:116.2-($i-54)*.3;if($i===69)$close=130;
            $row=[$row[0],$close-.5,$close+1,$close-1,$close,1000.0];}unset($row);
        $origin=(object)['source'=>'FIXTURE','source_url'=>'https://example.invalid/ohlcv','checked_at'=>mon_iso($now-600),'complete_source_window'=>true,'price_basis'=>MonDetails::BASIS];
        $snapshot=MonDetails::snapshot($s,$bars,$p->series->{'KR|INDEX|^KS11'},'KR|INDEX|^KS11',$origin,$origin);
        $d->_test_snapshots->{$s->symbol_id}=$snapshot;$p->series->{$s->symbol_id}=$snapshot->bars;$p->candidate_metrics->{$s->symbol_id}=$snapshot->metrics;
        $p->history_metadata->{$s->symbol_id}=$snapshot->history_metadata;$p->detail_plans->{$s->symbol_id}=$snapshot->entry_plans;$last=$snapshot->metrics->bars_as_of;
        $d->analysis->candidate_audit[]=(object)['symbol_id'=>$s->symbol_id,'eligibility'=>'unverified','origin'=>['new_in_mon'],'failed_checks'=>[],'evidence_ids'=>[],'rank'=>null];
    }
    $zone=new DateTimeZone('Asia/Seoul');$date=(new DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Y-m-d');
    $d->watchlist->calendar->markets[0]->sessions=[(object)['market_date'=>$last,'open_at'=>$last.'T00:00:00Z','close_at'=>$last.'T06:30:00Z'],
        (object)['market_date'=>$date,'open_at'=>mon_iso($now-3600),'close_at'=>mon_iso($now+3600)]];
    $d->analysis->market_summary=[(object)['country'=>'KR','completed_bar_date'=>$last,'target_completed_bar_date'=>$last,'comparison_status'=>'verified']];
    $d->analysis->coverage=[(object)['country'=>'KR','observed_count'=>$count,'buy_review_count'=>0,'ready_count'=>0,'price_verified_count'=>0]];
    $p->selection_facts=(object)['criteria_version'=>MonSwing::CRITERIA,'rules'=>MonSwing::rules(),'candidates_ref'=>'swing_signals',
        'candidate_facts_sha256'=>MonRun::digest([]),'selected'=>[],'positions'=>[],'corporate_risk'=>new stdClass()];
    $p->input_facts=(object)['criteria_version'=>MonSwing::CRITERIA,'as_of'=>$d->analysis->as_of,'selection_fingerprint'=>MonRun::digest($p->selection_facts),
        'collection_id'=>$d->collection->collection_id,'collection_status'=>'partial','used_prices'=>[]];
    $p->final_results_details=new stdClass();$p->independent_results=new stdClass();$p->evidence_archive=new stdClass();$p->independent_price_observations=[];
    $d->analysis->selection_fingerprint=MonRun::digest($p->selection_facts);$d->analysis->input_fingerprint=MonRun::digest($p->input_facts);$d->analysis->result_fingerprint=MonRun::digest([]);
    $d->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');MonRun::validate($d);MonRun::verifyFacts($d,$p);return $d;
}
function reinforcement_context($d): stdClass {return (object)['source_blob_sha'=>MonRun::gitSha(MonRun::json($d)),'rendered_at'=>mon_iso(),'countries'=>['KR'],'important_ids'=>[]];}
function reinforcement_decision($d,$c): stdClass {
    $p=MonDetails::prepare($d,$c);$sid=$d->watchlist->symbols[0]->symbol_id;
    return (object)['view_context'=>$c,'state_token'=>$p['state_token'],'prepared_fingerprint'=>$p['prepared_fingerprint'],'fixed_as_of'=>$c->rendered_at,
        'selected'=>[(object)['symbol_id'=>$sid,'reason'=>'synthetic positive RS and MA5 reclaim']],'references'=>[],'live_observations'=>[]];
}
class ReinforcementGit {
    public $head;public $base;public $trees=[];public $commits=[];public $blobs=[];public $calls=[];public $patches=0;public $newCommits=0;
    public $loseAck=false;public $failConfirmation=false;public $crashBlob=false;public $change=null;public $changeDone=false;
    public function __construct($base){$this->base=$base;$raw=MonRun::json($base);$dataSha=MonRun::gitSha($raw);$result='old validated result';$resultSha=MonRun::gitSha($result);
        $this->blobs[$dataSha]=$raw;$this->blobs[$resultSha]=$result;$tree=['mon_data.json'=>$dataSha,'mon_result.md'=>$resultSha];$treeSha=sha1(MonRun::json((object)$tree));
        $this->trees[$treeSha]=$tree;$this->head=str_repeat('a',40);$this->commits[$this->head]=['tree'=>$treeSha,'parents'=>[]];}
    public function response($v,int $status=200): array {return ['status'=>$status,'headers'=>[],'body'=>is_string($v)?$v:mon_json($v,false)];}
    public function setSource($d): void {$raw=MonRun::json($d);$sha=MonRun::gitSha($raw);$this->blobs[$sha]=$raw;$tree=$this->trees[$this->commits[$this->head]['tree']];$tree['mon_data.json']=$sha;
        $ts=sha1(MonRun::json((object)$tree));$this->trees[$ts]=$tree;$head=sha1('concurrent'.$this->head.$ts);$this->commits[$head]=['tree'=>$ts,'parents'=>[$this->head]];$this->head=$head;}
    public function data(){return mon_decode($this->blobs[$this->trees[$this->commits[$this->head]['tree']]['mon_data.json']]);}
    public function transport($method,$url,$headers,$body,$timeout): array {
        $path=parse_url($url,PHP_URL_PATH);$path=substr($path,strlen('/repos/wskimgit/stock'));$this->calls[]=[$method,$path,$timeout];$b=$body===null?null:mon_decode($body);
        if($method==='GET'&&$path==='/git/ref/heads/main'){
            if($this->failConfirmation&&$this->patches>0)throw new MonFault('HTTP_TRANSPORT_28');return $this->response((object)['object'=>(object)['sha'=>$this->head]]);}
        if($method==='GET'&&strpos($path,'/git/commits/')===0){$id=basename($path);return $this->response((object)['tree'=>(object)['sha'=>$this->commits[$id]['tree']]]);}
        if($method==='GET'&&strpos($path,'/git/trees/')===0){$tree=[];foreach($this->trees[basename($path)]as $p=>$sha)$tree[]=(object)['path'=>$p,'sha'=>$sha,'type'=>'blob','mode'=>'100644'];return $this->response((object)['tree'=>$tree,'truncated'=>false]);}
        if($method==='GET'&&$path==='/contents/mon_data.json'){parse_str((string)parse_url($url,PHP_URL_QUERY),$q);$head=($q['ref']??'main')==='main'?$this->head:$q['ref'];return $this->response($this->blobs[$this->trees[$this->commits[$head]['tree']]['mon_data.json']]);}
        if($method==='POST'&&$path==='/git/blobs'){$sha=MonRun::gitSha($b->content);$this->blobs[$sha]=$b->content;if($this->crashBlob){$this->crashBlob=false;throw new MonStop();}return $this->response((object)['sha'=>$sha]);}
        if($method==='POST'&&$path==='/git/trees'){$tree=$this->trees[$b->base_tree];foreach($b->tree as $e)$tree[$e->path]=$e->sha;ksort($tree);$sha=sha1(MonRun::json((object)$tree));$this->trees[$sha]=$tree;return $this->response((object)['sha'=>$sha]);}
        if($method==='POST'&&$path==='/git/commits'){$sha=sha1(MonRun::json($b));if(!isset($this->commits[$sha]))$this->newCommits++;$this->commits[$sha]=['tree'=>$b->tree,'parents'=>$b->parents];return $this->response((object)['sha'=>$sha]);}
        if($method==='PATCH'&&$path==='/git/refs/heads/main'){
            $this->patches++;if($this->change&&!$this->changeDone){$this->changeDone=true;$d=mon_decode(MonRun::json($this->base));if($this->change==='collection'){$d->collection->collection_id='concurrent-new-quote';$d->collection->completed_at=mon_iso();}
                elseif($this->change==='analysis')$d->analysis->concurrent_marker='do-not-overwrite';elseif($this->change==='detail'){$sid=$d->watchlist->symbols[0]->symbol_id;$d->collection->detail_responses->$sid=progress_response($d,$sid);$d->collection->detail_responses->$sid->completed_at=mon_iso(time()+1);}
                $this->setSource($d);}
            if($b->force!==false||!in_array($this->head,$this->commits[$b->sha]['parents'],true))return $this->response(new stdClass(),409);
            $this->head=$b->sha;if($this->loseAck){$this->loseAck=false;throw new MonFault('HTTP_TRANSPORT_28');}return $this->response((object)['object'=>(object)['sha'=>$this->head]]);
        }
        throw new RuntimeException('unexpected fake Git route '.$method.' '.$path);
    }
}

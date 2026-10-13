<?php
/**
 * MON-CHATGPT-BRIDGE-1.0. GitHub mailbox adapter for the existing manual-start MON daemon.
 * No orders, no new daemon, no external callbacks and no secret-bearing public files.
 * Loaded by mon.php. All market calculations and decision validation remain in mon.php.
 */
declare(strict_types=1);

final class MonChatGPTBridge {
    const CONTRACT = 'MON-CHATGPT-BRIDGE-1.0';
    const INBOX = 'mon_chatgpt_handoff.json';
    const OUTBOX = 'mon_chatgpt_response.json';
    const STATE = 'chatgpt_bridge_state.php';
    const MAX_IDS = 30;

    private static function base(MonConfig $cfg): string {
        if ($cfg->get('MON_REPOSITORY', 'wskimgit/stock') !== 'wskimgit/stock' ||
            $cfg->get('MON_BRANCH', 'main') !== 'main') throw new MonFault('MON_BRIDGE_REPOSITORY_INVALID');
        return 'https://api.github.com/repos/wskimgit/stock/';
    }
    private static function headers(MonConfig $cfg, bool $raw = false): array {
        $headers = ['Accept: '.($raw?'application/vnd.github.raw+json':'application/vnd.github+json'),
            'Content-Type: application/json', 'X-GitHub-Api-Version: 2022-11-28'];
        $token = $cfg->get('GITHUB_TOKEN');
        if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;
        return $headers;
    }
    private static function get(MonHttp $http, MonConfig $cfg, string $path, float $deadline): ?array {
        $response = $http->request('GET', self::base($cfg).'contents/'.$path.'?ref=main', self::headers($cfg, true), null, $deadline, 3);
        if ($response['status'] === 404) return null;
        if ($response['status'] < 200 || $response['status'] >= 300) MonHttp::decode($response);
        $raw = $response['body'];
        if (!is_string($raw) || strlen($raw) > 65536) throw new MonFault('MON_BRIDGE_BODY_INVALID');
        $value = mon_decode($raw);
        if (!($value instanceof stdClass)) throw new MonFault('MON_BRIDGE_BODY_INVALID');
        return ['value'=>$value,'sha'=>MonRun::gitSha($raw),'raw'=>$raw];
    }
    private static function put(MonHttp $http, MonConfig $cfg, string $path, string $raw, ?string $oldSha, float $deadline): string {
        if ($cfg->get('GITHUB_TOKEN') === '') throw new MonFault('GITHUB_TOKEN_MISSING');
        $body = ['message'=>'mon: ChatGPT bridge '.($path === 'mon_data.json'?'register requests':'status'),
            'content'=>base64_encode($raw),'branch'=>'main'];
        if ($oldSha !== null) $body['sha'] = $oldSha;
        $response = MonHttp::decode($http->request('PUT', self::base($cfg).'contents/'.$path,
            self::headers($cfg), mon_json($body, false), $deadline, 3));
        $sha = mon_get(mon_get($response, 'content'), 'sha');
        if (!is_string($sha) || !hash_equals(MonRun::gitSha($raw), $sha)) throw new MonFault('MON_BRIDGE_WRITE_UNCONFIRMED');
        return $sha;
    }
    public static function validate($q): void {
        if (!($q instanceof stdClass) || mon_get($q, 'contract') !== self::CONTRACT ||
            !in_array(mon_get($q,'stage'), ['candidates','decision'], true) ||
            !preg_match('/^[A-Za-z0-9._:-]{1,72}$/', (string)mon_get($q,'request_id','')) ||
            !is_array(mon_get($q,'candidate_ids')) || count($q->candidate_ids)<1 || count($q->candidate_ids)>self::MAX_IDS)
            throw new MonFault('MON_BRIDGE_REQUEST_INVALID');
        $seen=[];
        foreach ($q->candidate_ids as $sid) {
            MonRun::identity($sid);
            if (isset($seen[$sid])) throw new MonFault('MON_BRIDGE_DUPLICATE_CANDIDATE');
            $seen[$sid]=true;
        }
        if ($q->stage === 'decision') {
            if (!preg_match('/^[a-f0-9]{40}$/', (string)mon_get($q,'source_blob_sha','')) ||
                !preg_match('/^[a-f0-9]{40}$/', (string)mon_get($q,'candidate_request_sha','')) ||
                !preg_match('/^[a-f0-9]{40}$/', (string)mon_get($q,'details_receipt_sha','')) ||
                !is_array(mon_get($q,'selected')) || count($q->selected)>9 ||
                !is_array(mon_get($q,'references',[]))) throw new MonFault('MON_BRIDGE_DECISION_INVALID');
            $perCountry=[]; $all=[];
            foreach (array_merge($q->selected, mon_get($q,'references',[])) as $row) {
                $sid=mon_get($row,'symbol_id');
                if (!is_string($sid) || !isset($seen[$sid]) || isset($all[$sid]) ||
                    !is_string(mon_get($row,'reason')) || trim($row->reason)==='' || strlen($row->reason)>1000)
                    throw new MonFault('MON_BRIDGE_DECISION_INVALID');
                $all[$sid]=true;$country=explode('|',$sid)[0];$perCountry[$country]=($perCountry[$country]??0)+1;
                if ($perCountry[$country]>3) throw new MonFault('MON_BRIDGE_RESULT_LIMIT');
            }
        }
    }
    private static function report(MonStore $store, MonHttp $http, MonConfig $cfg, $report, float $deadline): string {
        $report=(object)$report;
        // Do not turn each poll timestamp into a new Git commit.
        $raw=mon_json($report,false);$id=hash('sha256',$raw);
        $prior=$store->readPrivate(self::STATE);
        if (mon_get($prior,'outbox_digest')===$id) return MonRun::gitSha($raw);
        $current=self::get($http,$cfg,self::OUTBOX,$deadline);
        if ($current && $current['raw']===$raw) {
            $prior=$prior instanceof stdClass?$prior:new stdClass();$prior->outbox_digest=$id;
            $store->writePrivate(self::STATE,$prior);return MonRun::gitSha($raw);
        }
        self::put($http,$cfg,self::OUTBOX,$raw,$current?$current['sha']:null,$deadline);
        $readback=self::get($http,$cfg,self::OUTBOX,$deadline);
        if(!$readback||!hash_equals(MonRun::gitSha($raw),$readback['sha'])) throw new MonFault('MON_BRIDGE_OUTBOX_UNCONFIRMED');
        $prior=$prior instanceof stdClass?$prior:new stdClass();$prior->outbox_digest=$id;
        $store->writePrivate(self::STATE,$prior);
        return MonRun::gitSha($raw);
    }
    // Multi-file compaction is delegated to the MON publication journal. Its
    // git tree/ref transaction commits the financial pair + archived dependency
    // together, with SHA readback and resumable failed acknowledgement.
    private static function enqueueCompacted(
        MonStore $store, MonHttp $http, MonConfig $cfg, array $files,
        string $sourceSha, $q, string $inboxSha, float $deadline
    ): string {
        $r=$http->request('GET',self::base($cfg).'contents/mon_data.json?ref=main',
            self::headers($cfg,true),null,$deadline,3);
        if($r['status']<200||$r['status']>=300)MonHttp::decode($r);
        $raw=$r['body'];
        if(!is_string($raw)||strlen($raw)>MonRun::MAX_SOURCE||
            !hash_equals(MonRun::gitSha($raw),$sourceSha))
            throw new MonFault('MON_BRIDGE_SOURCE_CHANGED');
        $budget=MonExecution::start($store,(object)[
            'run_id'=>'chatbridge-intake-'.$q->request_id,
            'mode'=>'auto','workload'=>'mon']);
        try {
            $metadata=['decision_sha256'=>hash('sha256',mon_json((object)[
                    'contract'=>self::CONTRACT,'stage'=>'candidates',
                    'candidate_request_sha'=>$inboxSha],false)),
                'run_context_id'=>$budget->run->run_context_id,
                'received_at'=>$budget->run->received_at,
                'countries'=>['KR','US','JP']];
            $manifest=MonArtifact::create($store,$raw,$files,$metadata);
            $head=MonHttp::decode($http->request('GET',self::base($cfg).'git/ref/heads/main',
                self::headers($cfg),null,$deadline,3));
            $headSha=mon_get(mon_get($head,'object'),'sha');
            if(!preg_match('/^[a-f0-9]{40}$/',(string)$headSha))
                throw new MonFault('MON_BRIDGE_HEAD_INVALID');
            $pub=new MonPublication($store,$http,$cfg);
            $receipt=$pub->enqueue($manifest['artifact_id'],$headSha,$budget,'chatgpt_github_intake');
            $st=$store->readPrivate(self::STATE);
            $st=$st instanceof stdClass?$st:new stdClass();
            $st->pending_candidate_request_sha=$inboxSha;
            $st->pending_candidate_job_id=$receipt['job_id'];
            $store->writePrivate(self::STATE,$st);
            return $receipt['job_id'];
        } finally {
            MonExecution::$active=null;MonExecution::$store=null;MonPreparation::$store=null;
        }
    }
    private static function resumeCompacted(
        MonStore $store,MonHttp $http,MonConfig $cfg,MonGitHub $gh,
        array $latest,$q,string $inboxSha,float $deadline
    ): ?array {
        $st=$store->readPrivate(self::STATE);
        if(mon_get($st,'pending_candidate_request_sha')!==$inboxSha ||
            !is_string(mon_get($st,'pending_candidate_job_id')))return null;
        $pub=new MonPublication($store,$http,$cfg);
        $receipt=$pub->receipt($pub->load($st->pending_candidate_job_id));
        if($receipt['save_status']!=='saved'){
            self::report($store,$http,$cfg,[
                'contract'=>self::CONTRACT,'stage'=>'candidates',
                'request_id'=>$q->request_id,'request_sha'=>$inboxSha,
                'status'=>in_array($receipt['save_status'],['failed','conflict'],true)?
                    'registration_blocked':'registration_publishing',
                'registration_job_id'=>$st->pending_candidate_job_id,
                'error_code'=>$receipt['last_error'],'save_status'=>$receipt['save_status'],
                'source_blob_sha'=>$latest['sha']],$deadline);
            return $latest;
        }
        $next=$gh->read($deadline,3);
        $actual=MonDetails::requests($next['data']);$accepted=0;$unknown=[];
        foreach($q->candidate_ids as $sid){
            $row=mon_get($actual,$sid);
            $expected='cb.'.substr(hash('sha256',$q->request_id.'|'.$sid),0,48);
            if($row instanceof stdClass&&mon_get($row,'request_id')===$expected)$accepted++;
            else $unknown[]=$sid;
        }
        $st->candidate_request_id=$q->request_id;
        $st->candidate_request_sha=$inboxSha;
        $st->candidate_ids_sha256=hash('sha256',mon_json($q->candidate_ids,false));
        $st->last_detail_receipt_sha=null;$st->last_detail_source_sha=null;
        unset($st->pending_candidate_request_sha,$st->pending_candidate_job_id);
        $store->writePrivate(self::STATE,$st);
        self::report($store,$http,$cfg,[
            'contract'=>self::CONTRACT,'stage'=>'candidates',
            'request_id'=>$q->request_id,'request_sha'=>$inboxSha,
            'status'=>$accepted===count($q->candidate_ids)?'registered':'registered_partial',
            'registered_count'=>$accepted,'unknown_ids'=>$unknown,
            'source_blob_sha'=>$next['sha'],
            'registration_commit_sha'=>$receipt['published_commit_sha']],$deadline);
        return $next;
    }
    private static function candidates(MonStore $store, MonHttp $http, MonConfig $cfg, MonGitHub $gh, array $latest, $q, string $inboxSha, float $deadline): array {
        $resumed=self::resumeCompacted($store,$http,$cfg,$gh,$latest,$q,$inboxSha,$deadline);
        if($resumed!==null)return $resumed;
        $data=$latest['data'];$sourceSha=$latest['sha'];
        $symbols=MonRun::rows($data->watchlist->symbols);
        $pool=MonDiscovery::pool($data);
        $items=[];$unknown=[];
        foreach ($q->candidate_ids as $sid) {
            $symbol=$symbols[$sid]??mon_get($pool,$sid);
            if (!$symbol instanceof stdClass) { $unknown[]=$sid;continue; }
            $id='cb.'.substr(hash('sha256',$q->request_id.'|'.$sid),0,48);
            $items[]=(object)['symbol'=>$symbol,'request_id'=>$id,'wait_seconds'=>120,'force_refresh'=>true];
        }
        // Missing identities cannot cause healthy peers to be abandoned.
        if(!$items){
            self::report($store,$http,$cfg,['contract'=>self::CONTRACT,'stage'=>'candidates',
                'request_id'=>$q->request_id,'request_sha'=>$inboxSha,'status'=>'identity_registration_required',
                'unknown_ids'=>$unknown,'source_blob_sha'=>$sourceSha],$deadline);
            return $latest;
        }
        $context=(object)['source_blob_sha'=>$sourceSha,'rendered_at'=>mon_iso(),
            'countries'=>['KR','US','JP'],'important_ids'=>$q->candidate_ids];
        $output=MonDetails::registerMany($data,$context,$items);
        $errors=array_values(array_filter($output['requests'],static function($r){return $r['error']!==null;}));
        if ($output['write_required']) {
            if(count($output['files'])>1) {
                // Keep only the publish files while MonArtifact revalidates
                // the large payload; avoid retaining the unpacked numerical cache.
                $files=$output['files'];
                unset($output,$errors,$data,$pool,$symbols,$items,$context);
                MonRun::releasePayloadCache();
                if(function_exists('gc_collect_cycles'))gc_collect_cycles();
                // registerMany may create two financial files and a lossless
                // recovery archive; it is invalid to PUT one file alone.
                $job=self::enqueueCompacted($store,$http,$cfg,
                    $files,$sourceSha,$q,$inboxSha,$deadline);
                self::report($store,$http,$cfg,[
                    'contract'=>self::CONTRACT,'stage'=>'candidates',
                    'request_id'=>$q->request_id,'request_sha'=>$inboxSha,
                    'status'=>'registration_publishing','registration_job_id'=>$job,
                    'registered_count'=>0,'source_blob_sha'=>$sourceSha],$deadline);
                return $latest;
            }
            if(count($output['files'])!==1 || $output['files'][0]['path']!=='mon_data.json')
                throw new MonFault('MON_BRIDGE_ARTIFACT_INVALID');
            $raw=$output['files'][0]['content'];
            self::put($http,$cfg,'mon_data.json',$raw,$sourceSha,$deadline);
            $next=$gh->read($deadline,3);
            if (!hash_equals(MonRun::gitSha($raw),$next['sha'])) throw new MonFault('MON_BRIDGE_READBACK_PENDING');
            $latest=$next;$sourceSha=$next['sha'];
        }
        // Link the actual first-stage request and accepted candidate identities to
        // the eventual ChatGPT decision; source updates alone cannot forge them.
        $state=$store->readPrivate(self::STATE);
        $state=$state instanceof stdClass?$state:new stdClass();
        if(mon_get($state,'candidate_request_sha')!==$inboxSha){
            $state->last_detail_receipt_sha=null;
            $state->last_detail_source_sha=null;
        }
        $state->candidate_request_sha=$inboxSha;
        $state->candidate_request_id=$q->request_id;
        $state->candidate_ids_sha256=hash('sha256',mon_json($q->candidate_ids,false));
        $store->writePrivate(self::STATE,$state);
        if ($output['write_required'] || $errors || $unknown) self::report($store,$http,$cfg,['contract'=>self::CONTRACT,'stage'=>'candidates',
            'request_id'=>$q->request_id,'request_sha'=>$inboxSha,
            'status'=>($errors||$unknown)?'registered_partial':'registered',
            'registered_count'=>count($items)-count($errors),'unknown_ids'=>$unknown,
            'request_errors'=>array_map(static function($r){return ['symbol_id'=>$r['symbol_id'],'error'=>$r['error']];},$errors),
            'source_blob_sha'=>$sourceSha],$deadline);
        return $latest;
    }
    private static function decision(MonStore $store, MonHttp $http, MonConfig $cfg, array $latest, $q, string $inboxSha, float $deadline): void {
        $old=$store->readPrivate(self::STATE);
        if (mon_get($old,'decision_request_sha')===$inboxSha && is_string(mon_get($old,'publication_job_id'))) {
            $publication=new MonPublication($store,$http,$cfg);
            $receipt=$publication->receipt($publication->load($old->publication_job_id));
            self::report($store,$http,$cfg,['contract'=>self::CONTRACT,'stage'=>'decision',
                'request_id'=>$q->request_id,'request_sha'=>$inboxSha,'source_blob_sha'=>$q->source_blob_sha,
                'status'=>$receipt['save_status']==='saved'?'saved':'publication_pending',
                'save_status'=>$receipt['save_status'],'published_commit_sha'=>$receipt['published_commit_sha']??null,
                'artifact_id'=>$receipt['artifact_id']??null],$deadline);
            return;
        }
        // This is a two-way handoff, not a PHP self-selection: require proof that
        // this exact candidate request produced a readable, hash-bound detail report.
        if(mon_get($old,'candidate_request_id')!==$q->request_id ||
            mon_get($old,'candidate_request_sha')!==$q->candidate_request_sha ||
            !hash_equals((string)mon_get($old,'candidate_ids_sha256',''),hash('sha256',mon_json($q->candidate_ids,false))) ||
            mon_get($old,'last_detail_receipt_sha')!==$q->details_receipt_sha ||
            mon_get($old,'last_detail_source_sha')!==$q->source_blob_sha)
            throw new MonFault('MON_BRIDGE_HANDOFF_PROOF_REQUIRED');
        if (!hash_equals($q->source_blob_sha,$latest['sha'])) throw new MonFault('MON_BRIDGE_STALE_SOURCE');
        $data=$latest['data'];$req=MonDetails::requests($data);$responses=mon_get($data->collection,'detail_responses',new stdClass());
        foreach ($q->selected as $r) {
            $x=mon_get($req,$r->symbol_id);$y=mon_get($responses,$r->symbol_id);
            if (!($x instanceof stdClass) || !MonDetails::matching($x,$y) || mon_get($y,'state')!=='done' || mon_get($y,'usable')!==true)
                throw new MonFault('MON_BRIDGE_DETAIL_PENDING');
        }
        $budget=MonExecution::start($store,(object)['run_id'=>'chatbridge-'.$q->request_id,'mode'=>'auto',
            'operation_policy'=>(object)['scope'=>'analysis_and_nas_normalization','finish_owner'=>'execution','require_statistics_publication'=>true]]);
        $ctx=(object)['source_blob_sha'=>$latest['sha'],'rendered_at'=>mon_iso(),
            'countries'=>['KR','US','JP'],'important_ids'=>$q->candidate_ids,
            'run_context_id'=>$budget->run->run_context_id];
        $result=null;
        try {
            $prepared=MonDetails::prepare($data,$ctx);
            $decision=(object)['view_context'=>$ctx,'fixed_as_of'=>$ctx->rendered_at,
                'state_token'=>$prepared['state_token'],'prepared_fingerprint'=>$prepared['prepared_fingerprint'],
                'selected'=>$q->selected,'references'=>mon_get($q,'references',[]),
                'confirmations'=>mon_get($q,'confirmations')];
            $result=MonDetails::finalize($data,$decision);
            // The exact source bytes are required for source/hash ownership checks.
            $source=$http->request('GET',self::base($cfg).'contents/mon_data.json?ref=main',self::headers($cfg,true),null,$deadline,3);
            if ($source['status']<200||$source['status']>=300) MonHttp::decode($source);
            $sourceRaw=$source['body'];
            if (!is_string($sourceRaw)||!hash_equals($latest['sha'],MonRun::gitSha($sourceRaw))) throw new MonFault('MON_BRIDGE_SOURCE_CHANGED');
            if (!$result['write_required']) $result['files']=[
                ['path'=>'mon_data.json','content'=>$sourceRaw,'blob_sha'=>MonRun::gitSha($sourceRaw)],
                ['path'=>'mon_result.md','content'=>$result['summary'],'blob_sha'=>MonRun::gitSha($result['summary'])]];
            if (count($result['files'])<2) throw new MonFault('MON_BRIDGE_ARTIFACT_INVALID');
            $manifest=MonArtifact::create($store,$sourceRaw,$result['files'],[
                'decision_sha256'=>MonRun::digest($decision),'run_context_id'=>$budget->run->run_context_id,
                'received_at'=>$budget->run->received_at,'countries'=>$ctx->countries,
                'prepared_fingerprint'=>$prepared['prepared_fingerprint']]);
            $headResponse=MonHttp::decode($http->request('GET',self::base($cfg).'git/ref/heads/main',self::headers($cfg),null,$deadline,3));
            $head=mon_get(mon_get($headResponse,'object'),'sha');
            if (!preg_match('/^[a-f0-9]{40}$/',(string)$head)) throw new MonFault('MON_BRIDGE_HEAD_INVALID');
            $publisher=new MonPublication($store,$http,$cfg);
            MonExecution::event('final_prepared',['final_available'=>true,'new_analysis_complete'=>true,
                'analysis_origin'=>'secured_data_decision','save_status'=>'not_requested',
                'artifact_id'=>$manifest['artifact_id']]);
            $receipt=$publisher->enqueue($manifest['artifact_id'],$head,$budget,'chatgpt_github_handoff');
            MonExecution::event('publication_queued',['save_status'=>$receipt['save_status'],
                'job_id'=>$receipt['job_id']]);
            $saved=$old instanceof stdClass?$old:new stdClass();
            $saved->decision_request_sha=$inboxSha;$saved->publication_job_id=$receipt['job_id'];
            $store->writePrivate(self::STATE,$saved);
            self::report($store,$http,$cfg,['contract'=>self::CONTRACT,'stage'=>'decision',
                'request_id'=>$q->request_id,'request_sha'=>$inboxSha,
                'source_blob_sha'=>$q->source_blob_sha,'status'=>'publication_pending',
                'artifact_id'=>$manifest['artifact_id'],'save_status'=>$receipt['save_status']],$deadline);
        } finally { MonExecution::$active=null;MonExecution::$store=null;MonPreparation::$store=null; }
    }
    public static function poll(MonStore $store, MonHttp $http, MonConfig $cfg, MonGitHub $gh, array $latest, float $deadline): array {
        if ($cfg->get('GITHUB_TOKEN')==='') return $latest;
        $prior=$store->readPrivate(self::STATE);
        $now=time();
        if (is_int(mon_get($prior,'next_poll_at')) && $prior->next_poll_at>$now) return $latest;
        // A status/transport failure is scoped to the bridge: ordinary market collection continues.
        $inbox=null;
        try {
            $inbox=self::get($http,$cfg,self::INBOX,$deadline);
            if (!$inbox) return $latest;
            self::validate($inbox['value']);$q=$inbox['value'];
            if ($q->stage==='candidates') return self::candidates($store,$http,$cfg,$gh,$latest,$q,$inbox['sha'],$deadline);
            self::decision($store,$http,$cfg,$latest,$q,$inbox['sha'],$deadline);
        } catch (MonFault $e) {
            $store->log('chatgpt_bridge_pending',['code'=>$e->faultCode]);
            // The caller needs an observable next action, not just a private NAS log.
            if($inbox && mon_get($inbox['value'],'request_id')!==null)try {
                self::report($store,$http,$cfg,['contract'=>self::CONTRACT,
                    'stage'=>mon_get($inbox['value'],'stage','unknown'),
                    'request_id'=>$inbox['value']->request_id,'request_sha'=>$inbox['sha'],
                    'status'=>in_array($e->faultCode,['MON_BRIDGE_STALE_SOURCE','MON_BRIDGE_SOURCE_CHANGED'],true)?'refresh_source':
                        ($e->faultCode==='MON_BRIDGE_DETAIL_PENDING'?'collection_pending':'recovery_pending'),
                    'error_code'=>$e->faultCode,'source_blob_sha'=>$latest['sha']],$deadline);
            }catch(MonFault $reportFailure){$store->log('chatgpt_bridge_pending',['code'=>$reportFailure->faultCode]);}
        } finally {
            $s=$store->readPrivate(self::STATE);
            $s=$s instanceof stdClass?$s:new stdClass();$s->next_poll_at=$now+60;
            $store->writePrivate(self::STATE,$s);
        }
        return $latest;
    }
    public static function export(MonStore $store, MonHttp $http, MonConfig $cfg, $data, string $sourceSha, float $deadline): void {
        if ($cfg->get('GITHUB_TOKEN')==='') return;
        $inbox=self::get($http,$cfg,self::INBOX,$deadline);
        if (!$inbox || mon_get($inbox['value'],'stage')!=='candidates') return;
        self::validate($inbox['value']);$q=$inbox['value'];
        $state=$store->readPrivate(self::STATE);
        if(mon_get($state,'candidate_request_sha')!==$inbox['sha'])return;
        $req=MonDetails::requests($data);$responses=mon_get($data->collection,'detail_responses',new stdClass());
        $rows=[];$complete=true;
        foreach ($q->candidate_ids as $sid) {
            $request=mon_get($req,$sid);$response=mon_get($responses,$sid);
            $valid=$request instanceof stdClass&&MonDetails::matching($request,$response);
            $state=$valid?mon_get($response,'state'):'pending';$error=$valid?mon_get($response,'error'):null;
            $row=['symbol_id'=>$sid,'state'=>$state,'error'=>$error,
                'bars_as_of'=>$valid?mon_get($response,'bars_as_of'):null,
                'data_status'=>$valid?mon_get($response,'data_status'):null,
                'usable'=>$valid&&mon_get($response,'usable')===true];
            if ($valid&&$row['usable']&&$state==='done') {
                try { $snapshot=MonDetails::checkedSnapshot($request,$response);
                    $row['facts']=['metrics'=>MonRun::pick($snapshot->metrics,['last_close','bars_as_of','RS20_pp','R20','ADV20','MA5','MA10','MA20','ATR14','S','R']),
                        'signals'=>MonRun::pick($snapshot->technical,['qualifying_signals','macd_kind','macd_cross_date','histogram','ma5_reclaim_close']),
                        'entry_plans'=>$snapshot->entry_plans];
                } catch (MonFault $e) { $row['usable']=false;$row['error']=$e->faultCode; }
            }
            if (!$row['usable'] || $state!=='done') $complete=false;
            $rows[]=$row;
        }
        $receipt=self::report($store,$http,$cfg,['contract'=>self::CONTRACT,'stage'=>'details',
            'request_id'=>$q->request_id,'request_sha'=>$inbox['sha'],
            'status'=>$complete?'collected':'collection_pending',
            'source_blob_sha'=>$sourceSha,'rows'=>$rows],$deadline);
        // Store receipt only after remote write/readback succeeded.
        $state=$store->readPrivate(self::STATE);
        $state=$state instanceof stdClass?$state:new stdClass();
        $state->candidate_request_id=$q->request_id;
        $state->candidate_request_sha=$inbox['sha'];
        $state->candidate_ids_sha256=hash('sha256',mon_json($q->candidate_ids,false));
        $state->last_detail_receipt_sha=$receipt;
        $state->last_detail_source_sha=$sourceSha;
        $store->writePrivate(self::STATE,$state);
    }
}

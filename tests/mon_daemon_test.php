<?php
declare(strict_types=1);
require __DIR__.'/../mon.php';

$tests=[]; $temps=[];
function check(string $name, bool $ok): void { global $tests; $tests[]=['case'=>$name,'pass'=>$ok]; if (!$ok) throw new RuntimeException($name); }
function fault(callable $fn, string $code): bool { try {$fn();return false;} catch(MonFault $e){return $e->faultCode===$code;} }
function fixture() {return mon_decode(file_get_contents(__DIR__.'/../mon_data.json'));}
function tempdir(): string {global $temps;$p=sys_get_temp_dir().'/mon-test-'.bin2hex(random_bytes(6));mkdir($p,0700);$temps[]=$p;return $p;}
function clearDir(string $p): void {foreach(scandir($p) as $f){if($f==='.'||$f==='..')continue;$q=$p.'/'.$f;if(is_dir($q))clearDir($q);else unlink($q);}rmdir($p);}
function config(array $extra=[]): MonConfig {
    $p=tempdir().'/test.env'; $v=array_merge(['GITHUB_TOKEN'=>'fixture-token','KIS_APP_KEY'=>'fixture-key','KIS_APP_SECRET'=>'fixture-secret','KIS_BAR_TIME_BASIS_KR'=>'end','KIS_DELAY_SECONDS_KR'=>'0'],$extra);
    file_put_contents($p,implode("\n",array_map(static function($k,$v){return $k.'='.$v;},array_keys($v),array_values($v))));return new MonConfig($p);
}
function symbol(string $code='005930') {
    return (object)['symbol_id'=>'KR|KRX|'.$code,'country'=>'KR','exchange'=>'KRX','symbol'=>$code,'name'=>'fixture','currency'=>'KRW','purpose'=>'test','candidate_origin'=>['new'],'is_held'=>false,'position'=>null,'tick_size'=>100,'source_codes'=>(object)['kis'=>(object)['market_code'=>'J','symbol'=>$code],'naver'=>(object)['symbol'=>$code],'yahoo'=>(object)['symbol'=>$code.'.KS']]];
}
function enabled() {
    $d=fixture();$d->watchlist->settings->enabled=true;$d->watchlist->symbols=[symbol()];$d->watchlist->watchlist_version=1;return $d;
}
function response($obj,int $status=200,array $headers=[]): array {return ['status'=>$status,'headers'=>$headers,'body'=>mon_json($obj)];}
function githubResponse($d,string $sha='blob-a'): array {return response((object)['encoding'=>'base64','sha'=>$sha,'content'=>base64_encode(mon_json($d))]);}
function settings($d): array {$s=MonData::settings($d->watchlist);$s['request_spacing_seconds']=0;return $s;}
$now=(new DateTimeImmutable('2026-10-08T10:00:30+09:00'))->getTimestamp();
function kisRows(): array {return [(object)['stck_bsop_date'=>'20261008','stck_cntg_hour'=>'100100','stck_prpr'=>'99999','cntg_vol'=>'999'],(object)['stck_bsop_date'=>'20261008','stck_cntg_hour'=>'100000','stck_prpr'=>'70000','cntg_vol'=>'100'],(object)['stck_bsop_date'=>'20261008','stck_cntg_hour'=>'095900','stck_prpr'=>'69900','cntg_vol'=>'80']];}
function kisHttp(array &$calls,?callable $special=null): MonHttp {
    return new MonHttp(null,static function($method,$url,$headers,$body)use(&$calls,$special){
        $calls[]=$url;
        if($special){$r=$special($method,$url,$headers,$body);if($r!==null)return $r;}
        if(strpos($url,'/oauth2/tokenP')!==false)return response((object)['access_token'=>'fixture-access-token','expires_in'=>86400]);
        return response((object)['rt_cd'=>'0','output2'=>kisRows()]);
    });
}
// Make test values authoritative only within this test process, never inherit user secrets.
foreach(['GITHUB_TOKEN','KIS_APP_KEY','KIS_APP_SECRET','KIS_BAR_TIME_BASIS_KR','KIS_DELAY_SECONDS_KR','YAHOO_DELAY_SECONDS_KR','NAVER_DELAY_SECONDS_KR']as $k)putenv($k);
try {
    $d=fixture();MonData::validate($d);check('schema 3 empty operating template accepted',true);
    $bad=fixture();$bad->schema_version=2;check('unknown schema is rejected',fault(static function()use($bad){MonData::validate($bad);},'DATA_SCHEMA_INVALID'));
    $bad=enabled();$bad->watchlist->symbols[]=symbol();check('duplicate identifiers rejected',fault(static function()use($bad){MonData::validate($bad);},'SYMBOL_ID_INVALID'));
    $bad=fixture();unset($bad->analysis->evidence);check('missing fields do not initialize document',fault(static function()use($bad){MonData::validate($bad);},'DATA_FIELD_MISSING'));
    $d=enabled();$s=settings($d);$calls=[];$store=new MonStore(tempdir());$collector=new MonCollector(kisHttp($calls),$store,config(),null,static function()use($now){return $now;});
    $batch=$collector->collect($d,$d->collection,microtime(true)+5,$s);
    check('only two completed minute bars retained',count($batch->quotes[0]->point?(array)$batch->quotes:[])===1&&$batch->quotes[0]->point->price===70000.0&&$batch->quotes[0]->previous_point->price===69900.0);
    check('unfinished future minute excluded',$batch->quotes[0]->point->price!==99999.0);
    check('minute amount and original quote clock preserved',$batch->quotes[0]->point->volume===100.0&&$batch->quotes[0]->point->quote_at===mon_iso($now-30));
    check('no calendar means unknown session',$batch->quotes[0]->point->session==='unknown');
    check('KIS successful response avoids fallback',count($calls)===2&&strpos($calls[1],'FID_INPUT_ISCD=005930')!==false);
    $collector->collect($d,$batch,microtime(true)+5,$s);check('cached token reused',count(array_filter($calls,static function($u){return strpos($u,'/oauth2/tokenP')!==false;}))===1);
    check('token cache permissions private',(fileperms($store->path('kis_token.json'))&0777)===0600);
    $collector->configure(config(['KIS_BAR_TIME_BASIS_KR'=>'unknown']));$b=$collector->collect($d,$batch,microtime(true)+5,$s);
    check('unverified bar semantics are not fabricated',$b->quotes[0]->point->quote_at===null&&$b->quotes[0]->quality_status==='unknown'&&$b->quotes[0]->point->provider_bar_at!==null);
    $collector->configure(config(['KIS_BAR_TIME_BASIS_KR'=>'start']));$b=$collector->collect($d,$batch,microtime(true)+5,$s);
    check('verified start time shifts one minute',$b->quotes[0]->point->price===69900.0&&$b->quotes[0]->point->quote_at===mon_iso($now-30));
    $calls=[];$yahoo=static function($method,$url)use($now){
        if(strpos($url,'koreainvestment.com')!==false)return response((object)['rt_cd'=>'1','msg_cd'=>'EGW00201']);
        if(strpos($url,'m.stock.naver.com')!==false)return response((object)['itemCode'=>'999999','closePrice'=>'1']);
        return response((object)['chart'=>(object)['error'=>null,'result'=>[(object)['meta'=>(object)['symbol'=>'005930.KS','currency'=>'KRW','regularMarketPrice'=>70100,'regularMarketTime'=>$now-10,'exchangeDataDelayedBy'=>0,'currentTradingPeriod'=>(object)['regular'=>(object)['start'=>$now-3600,'end'=>$now+3600]]]]]]]);
    };
    $fallback=new MonCollector(kisHttp($calls,$yahoo),new MonStore(tempdir()),config(),null,static function()use($now){return $now;});
    // Pre-cache auth so the fixture's deliberate KIS error applies to the quote request.
    $cacheKey=hash('sha256',"fixture-key\0fixture-secret");
    $ref=new ReflectionClass($fallback);$prop=$ref->getProperty('store');$prop->setAccessible(true);$fallbackStore=$prop->getValue($fallback);
    $fallbackStore->write('kis_token.json',(object)['signature'=>$cacheKey,'access_token'=>'fixture-access-token','expires_at'=>$now+86400]);
    $b=$fallback->collect($d,$batch,microtime(true)+5,$s);check('KIS error and Naver identity mismatch fall back to Yahoo',$b->quotes[0]->point->source==='YAHOO'&&$b->quotes[0]->point->price===70100.0);
    $positiveDelay=new MonHttp(null,static function()use($now){return response((object)['chart'=>(object)['error'=>null,'result'=>[(object)['meta'=>(object)['symbol'=>'005930.KS','currency'=>'KRW','regularMarketPrice'=>70100,'regularMarketTime'=>$now-10,'exchangeDataDelayedBy'=>15]]]]]);});
    $p=(new MonCollector($positiveDelay,new MonStore(tempdir()),config(),null,static function()use($now){return $now;}))->yahoo(symbol(),$d->watchlist,microtime(true)+5,$s)[0];
    check('undocumented positive delay units not treated as seconds',$p->delay_seconds===null&&MonData::quality($p,$now,$s)==='unknown');
    $failHttp=new MonHttp(null,static function(){return ['status'=>503,'headers'=>[],'body'=>'not-json'];});
    $failed=(new MonCollector($failHttp,new MonStore(tempdir()),config(),null,static function()use($now){return $now;}))->collect($d,$batch,microtime(true)+5,$s);
    check('all sources failed preserves last known price and time',$failed->quotes[0]->fetch_status==='error'&&$failed->quotes[0]->point->price===70000.0&&$failed->quotes[0]->point->quote_at===mon_iso($now-30));
    check('failed batch is partial',$failed->status==='partial');
    $d2=enabled();$d2->watchlist->watchlist_version=2;$empty=(new MonCollector($failHttp,new MonStore(tempdir()),config(),null,static function()use($now){return $now;}))->collect($d2,$batch,microtime(true)+5,$s);
    check('version changed does not reuse prior batch prices',$empty->quotes[0]->point===null);
    $d3=enabled();$d3->watchlist->symbols=[symbol('005930'),symbol('000660'),symbol('035420')];$countCalls=0;
    $budgetHttp=new MonHttp(null,static function($method,$url)use(&$countCalls){if(strpos($url,'/oauth2/tokenP')!==false)return response((object)['access_token'=>'fixture','expires_in'=>86400]);$countCalls++;if($countCalls===2)throw new MonFault('BUDGET_EXHAUSTED');return response((object)['rt_cd'=>'0','output2'=>kisRows()]);});
    $budgetCollector=new MonCollector($budgetHttp,new MonStore(tempdir()),config(),null,static function()use($now){return $now;});$b=$budgetCollector->collect($d3,$d3->collection,microtime(true)+5,$s);
    check('partial batch preserves resume cursor',$b->status==='partial'&&$b->next_cursor===1&&$b->quotes[2]->fetch_status==='pending');
    $next=$budgetCollector->collect($d3,$b,microtime(true)+5,$s);check('resume starts from unfinished symbol',$next->quotes[1]->fetch_status==='ok');
    $example=enabled();$example->watchlist->symbols[0]->example_only=true;$n=0;
    $skip=new MonCollector(new MonHttp(null,static function()use(&$n){$n++;throw new RuntimeException('must not call');}),new MonStore(tempdir()),config(),null,static function()use($now){return $now;});$skip->collect($example,$example->collection,microtime(true)+5,$s);check('example operating symbols never call quote API',$n===0);
    $holiday=enabled();$holiday->watchlist->calendar=(object)['valid_until'=>mon_iso($now+86400),'markets'=>[(object)['country'=>'KR','timezone'=>'Asia/Seoul','checked_at'=>mon_iso($now-3600),'sessions'=>[]]]];
    check('verified holiday does not collect',MonMarket::state($holiday->watchlist,'KR',$now)['collect']===false);
    $jpLunch=(new DateTimeImmutable('2026-10-08T12:00:00+09:00'))->getTimestamp();$jp=fixture();
    check('Japan standard lunch window is skipped',MonMarket::state($jp->watchlist,'JP',$jpLunch)['collect']===false);
    $early=enabled();$early->watchlist->calendar=(object)['valid_until'=>mon_iso($now+86400),'markets'=>[(object)['country'=>'KR','timezone'=>'Asia/Seoul','checked_at'=>mon_iso($now-3600),'sessions'=>[(object)['market_date'=>'2026-10-08','open_at'=>'2026-10-08T09:00:00+09:00','close_at'=>'2026-10-08T09:30:00+09:00']]]]];
    check('official early close overrides standard window',MonMarket::state($early->watchlist,'KR',$now)['session']==='closed');
    foreach(['2026-10-08T09:59:30-04:00','2026-01-08T09:59:30-05:00'] as $dt)check('NY slot follows timezone '.$dt,MonDaemon::forceMirror($d->watchlist,(new DateTimeImmutable($dt))->getTimestamp(),$s));
    $putCount=0;$readCount=0;$published=null;$d->analysis->extra=(object)[];$latest=mon_decode(mon_json($d));$latest->analysis->run_id='concurrent-ai';$latest->watchlist->settings->operator_note='preserve';
    $ghHttp=new MonHttp(null,static function($method,$url,$headers,$body)use(&$putCount,&$readCount,&$published,$d,$latest){
        if($method==='GET')return githubResponse(++$readCount===1?$d:$latest,'blob-'.$readCount);
        $putCount++;if($putCount===1)return ['status'=>409,'headers'=>[],'body'=>'{}'];$r=mon_decode($body);$published=mon_decode(base64_decode($r->content));return response((object)['content'=>(object)['sha'=>'saved-sha']]);
    });
    $gh=new MonGitHub($ghHttp,config());$gh->publish($batch,MonData::signature($d->watchlist),microtime(true)+5,$s);
    check('SHA conflict retries with latest AI fields',$putCount===2&&$published->analysis->run_id==='concurrent-ai'&&$published->watchlist->settings->operator_note==='preserve');
    check('empty JSON object preserved across PHP update',$published->analysis->extra instanceof stdClass);
    check('collector only replaces collection',$published->collection->collection_id===$batch->collection_id&&$published->watchlist->watchlist_version===1);
    $changed=mon_decode(mon_json($d));$changed->watchlist->watchlist_version=2;$writes=0;
    $changedGh=new MonGitHub(new MonHttp(null,static function($method)use($changed,&$writes){if($method==='PUT')$writes++;return githubResponse($changed);}),config());
    check('changed watchlist blocks stale publication',fault(static function()use($changedGh,$batch,$d,$s){$changedGh->publish($batch,MonData::signature($d->watchlist),microtime(true)+5,$s);},'WATCHLIST_CHANGED')&&$writes===0);
    $paused=mon_decode(mon_json($d));$paused->watchlist->settings->enabled=false;
    $pausedGh=new MonGitHub(new MonHttp(null,static function()use($paused){return githubResponse($paused);}),config());
    check('pause prevents late in-flight batch publication',fault(static function()use($pausedGh,$batch,$d,$s){$pausedGh->publish($batch,MonData::signature($d->watchlist),microtime(true)+5,$s);},'WATCHLIST_CHANGED'));
    $damaged=new MonGitHub(new MonHttp(null,static function(){return response((object)['encoding'=>'base64','sha'=>'a','content'=>base64_encode('{bad')]);}),config());
    check('corrupt remote JSON is never overwritten',fault(static function()use($damaged){$damaged->read(microtime(true)+5);},'DATA_JSON_INVALID'));
    $n=0;$exhausted=new MonGitHub(new MonHttp(null,static function($method)use($d,&$n){if($method==='GET')return githubResponse($d);$n++;return ['status'=>409,'headers'=>[],'body'=>'{}'];}),config());
    check('conflict retries are bounded',fault(static function()use($exhausted,$batch,$d,$s){$exhausted->publish($batch,MonData::signature($d->watchlist),microtime(true)+5,$s);},'HTTP_409')&&$n===3);
    check('HTTP rate limit produces bounded backoff',fault(static function(){MonHttp::decode(['status'=>429,'headers'=>['retry-after'=>'30'],'body'=>'']);},'HTTP_429'));
    $ctlStore=new MonStore(tempdir());$ctl=new MonControl($ctlStore,'abc');$ctlStore->write('stop.json',(object)['instance_id'=>'other']);$ctl->check();check('stale stop request cannot stop another instance',!$ctl->stop);
    $ctlStore->write('stop.json',(object)['instance_id'=>'abc']);$stopped=false;try{$ctl->check();}catch(MonStop $e){$stopped=true;}check('matching stop request interrupts wait',$stopped);
    $private=new MonStore(tempdir());$private->write('pending.json',(object)['collection'=>$batch]);check('pending cache survives independent store reopen',(new MonStore($private->dir))->read('pending.json')->collection->collection_id===$batch->collection_id);
    $private->log('fixture',['code'=>'safe','secret'=>'do-not-store']);check('logs exclude raw secrets',strpos(file_get_contents($private->path('mon.log')),'do-not-store')===false);
    $tight=fixture();$tight->watchlist->settings->request_spacing_seconds=0.1;check('KIS request spacing respects configured minimum',MonData::settings($tight->watchlist)['request_spacing_seconds']===1.25);
    $result=['cases_total'=>count($tests),'passed'=>count(array_filter($tests,static function($t){return $t['pass'];})),'failed'=>0,'type'=>'mock_API_and_storage_tests','live_broker_test'=>false,'cases'=>$tests];
    echo mon_json($result);
} finally { foreach($temps as $p) if(is_dir($p))clearDir($p); }

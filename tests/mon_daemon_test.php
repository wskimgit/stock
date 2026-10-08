<?php
declare(strict_types=1);
require __DIR__.'/../mon.php';

$tests=[]; $temps=[];
function check(string $name, bool $ok): void { global $tests; $tests[]=['case'=>$name,'pass'=>$ok]; if (!$ok) throw new RuntimeException($name); }
function fault(callable $fn, string $code): bool { try {$fn();return false;} catch(MonFault $e){return $e->faultCode===$code;} }
function fixture() {
    $d=mon_decode(file_get_contents(__DIR__.'/../mon_data.json'));
    $d->watchlist->symbols=[];$d->watchlist->watchlist_version=0;$d->watchlist->settings->enabled=false;
    $d->watchlist->calendar=(object)['valid_until'=>null,'markets'=>[]];
    $d->collection=(object)['schema_version'=>3,'collection_id'=>null,'watchlist_version'=>null,'started_at'=>null,'completed_at'=>null,'status'=>'not_started','next_cursor'=>0,'quotes'=>[]];
    $d->analysis=(object)['schema_version'=>3,'criteria_version'=>'MON-P2.0','run_id'=>null,'as_of'=>null,'analyzed_at'=>null,'watchlist_version'=>0,'collection_id'=>null,'status'=>'not_started','selection_fingerprint'=>null,'input_fingerprint'=>null,'result_fingerprint'=>null,'coverage'=>[],'independent_results'=>[],'results'=>[],'candidate_audit'=>[],'candidate_history'=>[],'changes'=>[],'evidence'=>[]];
    return $d;
}
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
function githubResponse($d,string $sha='blob-a'): array {return response((object)['encoding'=>'base64','sha'=>$sha,'content'=>base64_encode(mon_json($d,false))]);}
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
foreach(['GITHUB_TOKEN','KIS_APP_KEY','KIS_APP_SECRET','KIS_BAR_TIME_BASIS_KR','KIS_DELAY_SECONDS_KR','YAHOO_DELAY_SECONDS_KR','YAHOO_DELAY_SECONDS_US','YAHOO_DELAY_SECONDS_JP','NAVER_DELAY_SECONDS_KR']as $k)putenv($k);
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
    check('token cache stays in memory without an extra credential file',!file_exists($store->path('kis_token.json')));
    $collector->configure(config(['KIS_BAR_TIME_BASIS_KR'=>'unknown']));$b=$collector->collect($d,$batch,microtime(true)+5,$s);
    check('unverified bar semantics are not fabricated',$b->quotes[0]->point->quote_at===null&&$b->quotes[0]->quality_status==='unknown'&&$b->quotes[0]->point->provider_bar_at!==null);
    $collector->configure(config(['KIS_BAR_TIME_BASIS_KR'=>'start']));$b=$collector->collect($d,$batch,microtime(true)+5,$s);
    check('verified start time shifts one minute',$b->quotes[0]->point->price===69900.0&&$b->quotes[0]->point->quote_at===mon_iso($now-30));
    $calls=[];$yahoo=static function($method,$url)use($now){
        if(strpos($url,'koreainvestment.com')!==false)return response((object)['rt_cd'=>'1','msg_cd'=>'EGW00201']);
        if(strpos($url,'m.stock.naver.com')!==false)return response((object)['itemCode'=>'999999','closePrice'=>'1']);
        return response((object)['chart'=>(object)['error'=>null,'result'=>[(object)['meta'=>(object)['symbol'=>'005930.KS','currency'=>'KRW','exchangeName'=>'KSC','exchangeTimezoneName'=>'Asia/Seoul','regularMarketPrice'=>70100,'regularMarketTime'=>$now-10,'exchangeDataDelayedBy'=>0,'currentTradingPeriod'=>(object)['regular'=>(object)['start'=>$now-3600,'end'=>$now+3600]]]]]]]);
    };
    $fallback=new MonCollector(kisHttp($calls,$yahoo),new MonStore(tempdir()),config(),null,static function()use($now){return $now;});
    // Pre-cache auth so the fixture's deliberate KIS error applies to the quote request.
    $cacheKey=hash('sha256',"fixture-key\0fixture-secret");
    $ref=new ReflectionClass($fallback);$prop=$ref->getProperty('tokenCache');$prop->setAccessible(true);
    $prop->setValue($fallback,(object)['signature'=>$cacheKey,'access_token'=>'fixture-access-token','expires_at'=>$now+86400]);
    $b=$fallback->collect($d,$batch,microtime(true)+5,$s);check('KIS error and Naver identity mismatch fall back to Yahoo',$b->quotes[0]->point->source==='YAHOO'&&$b->quotes[0]->point->price===70100.0);
    $positiveDelay=new MonHttp(null,static function()use($now){return response((object)['chart'=>(object)['error'=>null,'result'=>[(object)['meta'=>(object)['symbol'=>'005930.KS','currency'=>'KRW','exchangeName'=>'KSC','exchangeTimezoneName'=>'Asia/Seoul','regularMarketPrice'=>70100,'regularMarketTime'=>$now-10,'exchangeDataDelayedBy'=>15]]]]]);});
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

    // Regression: the actual populated analysis used to overflow when publish pretty-printed it.
    $large=mon_decode(file_get_contents(__DIR__.'/../mon_data.json'));MonData::validate($large);
    $large->watchlist->settings->enabled=true;$largeSettings=settings($large);$analysisBefore=mon_json($large->analysis,false);
    $largeBatch=(object)['schema_version'=>3,'collection_id'=>'size-regression','watchlist_version'=>$large->watchlist->watchlist_version,'started_at'=>mon_iso($now),'completed_at'=>mon_iso($now),'status'=>'complete','next_cursor'=>0,'quotes'=>[]];
    foreach($large->watchlist->symbols as $row){
        $point=(object)['price'=>123456.789,'change_pct'=>1.23,'volume'=>1234567.0,'volume_basis'=>'minute','currency'=>$row->currency,'venue'=>$row->exchange,'source'=>'YAHOO','provider_symbol'=>mon_get($row->source_codes->yahoo,'symbol'),'price_type'=>'last','timestamp_basis'=>'trade','quote_at'=>mon_iso($now-20),'fetched_at'=>mon_iso($now),'market_date'=>'2026-10-08','session'=>'regular','delay_kind'=>'realtime','delay_seconds'=>0.0,'bar_time_basis_verified'=>false,'provider_venue'=>'fixture-venue','delay_basis'=>'verified_exchange_policy','delay_policy_url'=>'https://help.yahoo.com/kb/finance/article-exchanges-data-delays-sln2310.html','delay_policy_verified_at'=>mon_iso($now-60)];
        $previous=clone $point;$previous->quote_at=mon_iso($now-80);
        $largeBatch->quotes[]=(object)['symbol_id'=>$row->symbol_id,'fetch_status'=>'ok','attempted_at'=>mon_iso($now),'quality_status'=>'normal','error'=>null,'point'=>$point,'previous_point'=>$previous];
    }
    $rawPublished=null;$putN=0;
    $largeGh=new MonGitHub(new MonHttp(null,static function($method,$url,$headers,$body)use($large,&$rawPublished,&$putN){
        if($method==='GET')return githubResponse($large);
        $putN++;$rawPublished=base64_decode(mon_decode($body)->content,true);return response((object)['content'=>(object)['sha'=>'compact-saved']]);
    }),config());
    $largeGh->publish($largeBatch,MonData::signature($large->watchlist),microtime(true)+5,$largeSettings);
    check('populated analysis plus 30 two-point rows publishes below 512 KiB',$putN===1&&strlen($rawPublished)<=524288&&count(mon_decode($rawPublished)->collection->quotes)===30);
    check('publication uses compact JSON although pretty output exceeds cap',strpos($rawPublished,"\n")===strlen($rawPublished)-1&&strlen(mon_json(mon_decode($rawPublished)))>524288);
    check('large publication preserves analysis and fingerprints exactly',mon_json(mon_decode($rawPublished)->analysis,false)===$analysisBefore);
    $reserve=max(65536,4096+2048*count($large->watchlist->symbols));
    check('analysis leaves the configured collection reserve',strlen(mon_json($large,false))<=524288-$reserve);
    $tooLarge=clone $largeBatch;$tooLarge->fixture_padding=str_repeat('x',524288);$putN=0;
    check('true size overflow blocks PUT without truncating analysis',fault(static function()use($largeGh,$tooLarge,$large,$largeSettings){$largeGh->publish($tooLarge,MonData::signature($large->watchlist),microtime(true)+5,$largeSettings);},'DATA_SIZE_EXCEEDED')&&$putN===0);

    $krSymbol=symbol();$krSymbol->exchange='KOSPI';$krSymbol->symbol_id='KR|KOSPI|005930';
    $naverReply=(object)['itemCode'=>'005930','closePrice'=>'70,100','localTradedAt'=>mon_iso($now-10),'marketStatus'=>'OPEN','marketSessionType'=>'REGULAR','stockExchangeType'=>(object)['code'=>'KS','zoneId'=>'Asia/Seoul']];
    $naverHttp=new MonHttp(null,static function()use(&$naverReply){return response($naverReply);});
    $naverCollector=new MonCollector($naverHttp,new MonStore(tempdir()),config(['NAVER_DELAY_SECONDS_KR'=>'0']),null,static function()use($now){return $now;});
    $np=$naverCollector->naver($krSymbol,$d->watchlist,microtime(true)+5,$s)[0];
    check('Naver verifies KS identity and preserves listing-market basis',$np->provider_symbol==='005930'&&$np->provider_venue==='KS'&&$np->venue_basis==='listing_market'&&$np->price===70100.0&&$np->session==='regular');
    $naverReply->stockExchangeType->code='KQ';check('Naver wrong listing market rejected',fault(static function()use($naverCollector,$krSymbol,$d,$s){$naverCollector->naver($krSymbol,$d->watchlist,microtime(true)+5,$s);},'NAVER_VENUE_MISMATCH'));
    $naverReply->stockExchangeType->code='KS';$naverReply->stockExchangeType->zoneId='America/New_York';check('Naver wrong timezone rejected',fault(static function()use($naverCollector,$krSymbol,$d,$s){$naverCollector->naver($krSymbol,$d->watchlist,microtime(true)+5,$s);},'NAVER_VENUE_MISMATCH'));
    $naverReply->stockExchangeType->zoneId='Asia/Seoul';$naverReply->localTradedAt='2026-10-08T20:34:33+09:00';$naverReply->marketStatus='CLOSE';$naverReply->marketSessionType='AFTER_MARKET';
    $np=$naverCollector->naver($krSymbol,$d->watchlist,microtime(true)+5,$s)[0];check('Naver extended-session price is not relabeled as a KRX regular close',$np->session==='unknown'&&$np->price_type==='last'&&$np->provider_session==='AFTER_MARKET');

    $usNow=(new DateTimeImmutable('2026-10-08T10:10:00-04:00'))->getTimestamp();
    $usSymbol=(object)['symbol_id'=>'US|NASDAQ|NTAP','country'=>'US','exchange'=>'NASDAQ','symbol'=>'NTAP','currency'=>'USD','source_codes'=>(object)['yahoo'=>(object)['symbol'=>'NTAP']]];
    $usDoc=fixture();$usDoc->watchlist->settings->quote_delay_policies=(object)['YAHOO'=>(object)['US|NASDAQ'=>(object)['delay_seconds'=>0,'provider_venues'=>['NMS','NGM','NCM'],'source_url'=>'https://help.yahoo.com/kb/finance/article-exchanges-data-delays-sln2310.html','verified_at'=>mon_iso($usNow-60)]]];
    $usSettings=settings($usDoc);
    $meta=(object)['symbol'=>'NTAP','currency'=>'USD','exchangeName'=>'NMS','exchangeTimezoneName'=>'America/New_York','regularMarketPrice'=>235.49,'regularMarketTime'=>$usNow-20,'currentTradingPeriod'=>(object)['regular'=>(object)['start'=>$usNow-2400,'end'=>$usNow+21600]]];
    $yahooHttp=new MonHttp(null,static function()use(&$meta){return response((object)['chart'=>(object)['error'=>null,'result'=>[(object)['meta'=>$meta]]]]);});
    $usCollector=new MonCollector($yahooHttp,new MonStore(tempdir()),config(),null,static function()use($usNow){return $usNow;});
    $up=$usCollector->yahoo($usSymbol,$usDoc->watchlist,microtime(true)+5,$usSettings)[0];
    check('Yahoo absent delay field can use fresh verified Nasdaq policy',$up->delay_seconds===0.0&&$up->delay_basis==='verified_exchange_policy'&&$up->provider_venue==='NMS'&&$up->session==='regular');
    $meta->exchangeName='NYQ';check('Yahoo wrong returned venue rejected',fault(static function()use($usCollector,$usSymbol,$usDoc,$usSettings){$usCollector->yahoo($usSymbol,$usDoc->watchlist,microtime(true)+5,$usSettings);},'YAHOO_VENUE_MISMATCH'));
    $meta->exchangeName='NMS';$meta->exchangeTimezoneName='Asia/Tokyo';check('Yahoo wrong returned timezone rejected',fault(static function()use($usCollector,$usSymbol,$usDoc,$usSettings){$usCollector->yahoo($usSymbol,$usDoc->watchlist,microtime(true)+5,$usSettings);},'YAHOO_VENUE_MISMATCH'));
    $meta->exchangeTimezoneName='America/New_York';$meta->regularMarketTime=$usNow-86400;
    $up=$usCollector->yahoo($usSymbol,$usDoc->watchlist,microtime(true)+5,$usSettings)[0];check('open session does not relabel yesterday quote as regular',$up->session==='unknown');
    $meta->regularMarketTime=$usNow-20;$policy=$usDoc->watchlist->settings->quote_delay_policies->YAHOO->{'US|NASDAQ'};
    $policy->verified_at=mon_iso($usNow-1209601);$up=$usCollector->yahoo($usSymbol,$usDoc->watchlist,microtime(true)+5,$usSettings)[0];check('expired Yahoo policy remains unknown',$up->delay_seconds===null&&$up->delay_basis==='unknown');
    $policy->verified_at=mon_iso($usNow-60);$policy->provider_venues=['NYQ'];$up=$usCollector->yahoo($usSymbol,$usDoc->watchlist,microtime(true)+5,$usSettings)[0];check('delay policy for a different venue is not reused',$up->delay_seconds===null);
    $policy->provider_venues=['NMS'];$policy->source_url='https://example.invalid/yahoo-delay';$up=$usCollector->yahoo($usSymbol,$usDoc->watchlist,microtime(true)+5,$usSettings)[0];check('non-provider delay policy is ignored',$up->delay_seconds===null);
    $meta->exchangeDataDelayedBy=0;$up=$usCollector->yahoo($usSymbol,$usDoc->watchlist,microtime(true)+5,$usSettings)[0];check('explicit provider zero does not need a policy override',$up->delay_seconds===0.0&&$up->delay_basis==='provider_zero_field');
    unset($meta->exchangeDataDelayedBy);$usSymbol->exchange='NYSE';$usSymbol->symbol_id='US|NYSE|NTAP';$meta->exchangeName='NYQ';$up=$usCollector->yahoo($usSymbol,$usDoc->watchlist,microtime(true)+5,$usSettings)[0];check('Nasdaq policy does not assume NYSE realtime',$up->delay_seconds===null);

    $result=['cases_total'=>count($tests),'passed'=>count(array_filter($tests,static function($t){return $t['pass'];})),'failed'=>0,'type'=>'mock_API_and_storage_tests','live_broker_test'=>false,'cases'=>$tests];
    echo mon_json($result);
} finally { foreach($temps as $p) if(is_dir($p))clearDir($p); }

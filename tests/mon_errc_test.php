<?php
declare(strict_types=1);
require __DIR__.'/../mon.php';
$cases=[];$dirs=[];$handles=[];
function ok(string $name,bool $value):void{global $cases;$cases[]=['case'=>$name,'pass'=>$value];if(!$value)throw new RuntimeException($name);}
function folder():string{global $dirs;$p=sys_get_temp_dir().'/mon-errc-'.bin2hex(random_bytes(6));mkdir($p);$dirs[]=$p;return $p;}
function clean(string $p):void{foreach(scandir($p)as$f)if($f!=='.'&&$f!=='..'){if(is_dir($p.'/'.$f))clean($p.'/'.$f);else unlink($p.'/'.$f);}rmdir($p);}
function copyData($d){return mon_decode(mon_json($d,false));}
function cfg(MonStore $s,array $values=[]):MonConfig{if($values)$s->write('settings.php',(object)$values,MON_SETTINGS_PREFIX);return new MonConfig(null,$s->dir);}
function method($object,string $name,array $args=[]){$r=new ReflectionMethod($object,$name);$r->setAccessible(true);return $r->invokeArgs($object,$args);}
function property($object,string $name,$value):void{$r=new ReflectionProperty($object,$name);$r->setAccessible(true);$r->setValue($object,$value);}
function api($d):array{return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['encoding'=>'base64','sha'=>'fixture-sha','content'=>base64_encode(mon_json($d,false))])];}
function view($d,array $status,array $values=[],$pending=null):array{
    global $handles;
    $store=new MonStore(folder());$store->write('remote_cache.json',$d);if($pending)$store->write('pending.json',$pending);
    $status=array_merge(['version'=>MON_VERSION,'state'=>'waiting','activity_state'=>'collected','heartbeat_at'=>mon_iso(),'instance_id'=>'fixture','last_attempted'=>1,'last_succeeded'=>1,'last_published_at'=>null,'error_code'=>null],$status);
    $store->write('status.json',(object)$status);$h=fopen($store->path('daemon.lock'),'c+');flock($h,LOCK_EX);$handles[]=$h;
    return mon_web_state($store,cfg($store,$values));
}
foreach(['GITHUB_TOKEN','KIS_APP_KEY','KIS_APP_SECRET','PHP_CLI','MON_REPOSITORY','MON_BRANCH']as$k)putenv($k);
try{
    $base=mon_decode(file_get_contents(__DIR__.'/../mon_data.json'));
    $d=copyData($base);$d->watchlist->symbols=[$d->watchlist->symbols[20]];$symbol=$d->watchlist->symbols[0];
    $d->watchlist->settings->enabled=true;$now=time();$date=(new DateTimeImmutable('@'.$now))->setTimezone(MonMarket::timezone($symbol->country))->format('Y-m-d');
    $d->watchlist->calendar=(object)['valid_until'=>mon_iso($now+86400),'markets'=>[(object)['country'=>$symbol->country,'timezone'=>MonMarket::timezone($symbol->country)->getName(),'checked_at'=>mon_iso($now-1),'sessions'=>[(object)['market_date'=>$date,'open_at'=>mon_iso($now-3600),'close_at'=>mon_iso($now+3600)]]]]];
    $d->collection=(object)['schema_version'=>3,'collection_id'=>'fixture','watchlist_version'=>$d->watchlist->watchlist_version,'started_at'=>mon_iso($now),'completed_at'=>mon_iso($now),'status'=>'complete','next_cursor'=>0,'quotes'=>[(object)['symbol_id'=>$symbol->symbol_id,'fetch_status'=>'ok','attempted_at'=>mon_iso($now),'quality_status'=>'normal','error'=>null,'point'=>(object)['price'=>100,'currency'=>$symbol->currency,'venue'=>$symbol->exchange,'quote_at'=>mon_iso($now-2),'fetched_at'=>mon_iso(),'timestamp_basis'=>'trade','session'=>'regular','delay_seconds'=>0],'previous_point'=>null]]];
    $store=new MonStore(folder());$conf=cfg($store);$calls=[];
    $http=new MonHttp(null,static function($m,$u,$h,$b)use(&$calls,$d){$calls[]=[$m,$h];return api($d);});$gh=new MonGitHub($http,$conf);
    $read=$gh->read(microtime(true)+2);ok('public GET without token reads the existing schema document',$read['data']->watchlist->watchlist_version===$d->watchlist->watchlist_version);
    ok('anonymous GET sends no empty authorization header',!array_filter($calls[0][1],static function($s){return stripos($s,'authorization:')===0;}));
    $before=count($calls);$blocked=false;try{$gh->publish($d->collection,MonData::signature($d->watchlist),microtime(true)+2,MonData::settings($d->watchlist));}catch(MonFault $e){$blocked=$e->faultCode==='GITHUB_TOKEN_MISSING';}
    ok('write without token is blocked before any HTTP request',$blocked&&count($calls)===$before);
    putenv('GITHUB_TOKEN=');putenv('KIS_APP_KEY=old-env');putenv('PHP_CLI=/old/php');
    mon_web_save($store,$conf,['GITHUB_TOKEN'=>'saved-fixture','KIS_APP_KEY'=>'saved-app','PHP_CLI'=>'']);$saved=new MonConfig(null,$store->dir);
    ok('explicit saved token overrides an empty inherited environment',$saved->get('GITHUB_TOKEN')==='saved-fixture');
    ok('explicit saved app key overrides an older inherited environment',$saved->get('KIS_APP_KEY')==='saved-app');
    ok('explicit empty PHP path restores automatic runtime detection',$saved->get('PHP_CLI')==='');
    mon_web_save($store,$saved,['GITHUB_TOKEN'=>'','KIS_APP_KEY'=>'']);$saved=new MonConfig(null,$store->dir);
    ok('blank credential inputs preserve the prior browser values',$saved->get('GITHUB_TOKEN')==='saved-fixture'&&$saved->get('KIS_APP_KEY')==='saved-app');
    putenv('MON_IDLE_POLL_SECONDS=87');ok('non-browser environment options retain their precedence',$saved->get('MON_IDLE_POLL_SECONDS')==='87');putenv('MON_IDLE_POLL_SECONDS');
    $stamp=mon_settings_stamp($store,'');$t=filemtime($store->path('settings.php'));
    mon_web_save($store,$saved,['GITHUB_TOKEN'=>'other-fixture']);touch($store->path('settings.php'),$t);
    ok('configuration reload detects same-size changes even with unchanged mtime',mon_settings_stamp($store,'')!==$stamp);
    foreach(['GITHUB_TOKEN','KIS_APP_KEY','PHP_CLI']as$k)putenv($k);
    $cs=new MonStore(folder());$cc=cfg($cs);$daemon=new MonDaemon($cs,'','unit');$calls=[];
    $http=new MonHttp(null,static function($m,$u,$h,$b)use(&$calls,$d){$calls[]=$m;return api($d);});$gh=new MonGitHub($http,$cc);
    $old=copyData($d);$original=mon_json($old,false);method($daemon,'cacheRemote',[$old,$cc]);
    ok('local cache metadata does not mutate the shared source objects',mon_json($old,false)===$original);
    $cache=$cs->read('remote_cache.json');$cache->_mon_cache->fetched_at=mon_iso(time()-60);$cs->write('remote_cache.json',$cache);
    $r=method($daemon,'readRemote',[$gh,$cc,microtime(true)+2]);ok('fresh public cache avoids repeated anonymous GETs',$r['cached']===true&&count($calls)===0);
    ok('using a public cache does not renew its original fetched time',$cs->read('remote_cache.json')->_mon_cache->fetched_at===$cache->_mon_cache->fetched_at);
    ok('local metadata is removed before data is used by the collector',!property_exists($r['data'],'_mon_cache'));
    $cache->_mon_cache->fetched_at=mon_iso(time()-300);$cs->write('remote_cache.json',$cache);$r=method($daemon,'readRemote',[$gh,$cc,microtime(true)+2]);
    ok('expired public cache triggers a new verified GET',$r['cached']===false&&count($calls)===1);
    $cache->_mon_cache->fetched_at=mon_iso();$cache->_mon_cache->repository='other/repo';$cs->write('remote_cache.json',$cache);method($daemon,'readRemote',[$gh,$cc,microtime(true)+2]);
    ok('cache from another repository is not reused',count($calls)===2);
    $cs->write('remote_cache.json',null);method($daemon,'readRemote',[$gh,$cc,microtime(true)+2]);ok('invalid local cache falls back to verified remote data',count($calls)===3);
    property($daemon,'http',$http);$exit=$daemon->run(1);$stat=$cs->read('status.json');
    ok('missing write key keeps a non-collecting setup state without provider calls',$exit===0&&$stat->last_attempted===0&&$stat->last_succeeded===0&&count($calls)===4);
    method($daemon,'heartbeat',['needs_setup','GITHUB_TOKEN_MISSING']);method($daemon,'heartbeat',['waiting']);$stat=$cs->read('status.json');
    ok('wait heartbeats preserve the actual setup failure stage',$stat->state==='waiting'&&$stat->activity_state==='needs_setup'&&$stat->error_code==='GITHUB_TOKEN_MISSING');
    $v=view($d,['error_code'=>'GITHUB_TOKEN_MISSING','activity_state'=>'needs_setup']);
    ok('live PID with missing write key is setup-needed rather than green collecting',$v['running']&&$v['heartbeat_fresh']&&$v['label']==='설정 필요'&&!$v['monitoring_active']);
    $values=['GITHUB_TOKEN'=>'fixture-view'];$off=copyData($d);$off->watchlist->settings->enabled=false;
    $v=view($off,[],$values);ok('disabled collection is clearly distinguished from a stopped process',$v['running']&&$v['readiness']==='paused'&&!$v['monitoring_active']);
    $closed=copyData($d);$closed->watchlist->calendar->markets[0]->sessions[0]->open_at=mon_iso($now+3600);$closed->watchlist->calendar->markets[0]->sessions[0]->close_at=mon_iso($now+7200);
    $v=view($closed,[],$values);ok('closed market is shown as off-session waiting',$v['readiness']==='off_session'&&!$v['monitoring_active']);
    $v=view($d,[],$values);ok('normal collecting requires fresh correctly identified prices',$v['readiness']==='collecting'&&$v['fresh_quotes']===1&&$v['monitoring_active']);
    ob_start();mon_web_html($v,'',true,'');$html=ob_get_clean();file_put_contents(__DIR__.'/../test_ui.html',$html);
    $stale=copyData($d);$stale->collection->quotes[0]->point->quote_at=mon_iso($now-301);$v=view($stale,[],$values);
    ok('expired stored price is not counted as a latest price',$v['collected']===1&&$v['fresh_quotes']===0&&!$v['monitoring_active']);
    $v=view($d,['last_attempted'=>2,'last_succeeded'=>1],$values);ok('valid partial collection remains useful and is explicitly labelled',$v['readiness']==='partial'&&$v['monitoring_active']);
    $v=view($d,['error_code'=>'QUOTE_FETCH_FAILED','activity_state'=>'quote_failed'],$values);ok('all quote failures are not reported as normal collection',$v['readiness']==='error'&&!$v['monitoring_active']);
    $v=view($d,['heartbeat_at'=>mon_iso($now-40)],$values);ok('stale heartbeat does not confirm current monitoring',$v['readiness']==='unknown'&&!$v['monitoring_active']);
    $v=view($d,['heartbeat_at'=>mon_iso(time()+10)],$values);ok('future heartbeat beyond five seconds is unverified',!$v['heartbeat_fresh']);
    $v=view($d,['version'=>'1.2.1'],$values);ok('old running worker after a file upgrade is marked restart-required',$v['restart_required']&&$v['readiness']==='restart_required'&&!$v['monitoring_active']);
    foreach(['currency','venue','timestamp_basis','price','delay_seconds']as$k){
        $bad=copyData($d);$bad->collection->quotes[0]->point->$k=['currency'=>'JPY','venue'=>'NYSE','timestamp_basis'=>null,'price'=>null,'delay_seconds'=>1200][$k];
        $v=view($bad,[],$values);ok('latest-price counter rejects invalid '.$k,$v['fresh_quotes']===0);
    }
    $bad=copyData($d);$bad->collection->watchlist_version++;$v=view($bad,[],$values);ok('price batch for an earlier watchlist is not counted as current',$v['fresh_quotes']===0);
    $configured=new MonConfig(null,$store->dir);$seen=null;
    $batch=copyData($base->collection);$batch->status='partial';$enabled=copyData($base);$enabled->watchlist->settings->enabled=true;
    $writeHttp=new MonHttp(null,static function($m,$u,$h,$b)use(&$seen,$enabled){if($m==='GET')return api($enabled);$seen=mon_decode(base64_decode(mon_decode($b)->content));return ['status'=>200,'headers'=>[],'body'=>'{"content":{"sha":"saved"}}'];});
    (new MonGitHub($writeHttp,$configured))->publish($batch,MonData::signature($enabled->watchlist),microtime(true)+3,MonData::settings($enabled->watchlist));
    ok('authenticated publishing preserves analysis and watchlist ownership',mon_json($seen->analysis,false)===mon_json($base->analysis,false)&&mon_json($seen->watchlist,false)===mon_json($enabled->watchlist,false));
    ok('local-only cache metadata never reaches the repository',!property_exists($seen,'_mon_cache'));
    echo mon_json(['cases_total'=>count($cases),'passed'=>count($cases),'failed'=>0,'live_api_calls'=>false,'cases'=>$cases]);
}finally{foreach($handles as$h){flock($h,LOCK_UN);fclose($h);}foreach(array_reverse($dirs)as$p)clean($p);}

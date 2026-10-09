<?php
declare(strict_types=1);
// Existing broker returned-array integration; all keys and responses are fixtures.
$root=dirname(__DIR__);$app=sys_get_temp_dir().'/mon-broker-'.bin2hex(random_bytes(6));
mkdir($app);copy($root.'/mon.php',$app.'/mon.php');require $app.'/mon.php';
$cases=[];$lock=null;$previous=[];
foreach(['GITHUB_TOKEN','KIS_APP_KEY','KIS_APP_SECRET','MON_REPOSITORY','MON_BRANCH','BROKER_MODE','KIS_BASE_URL'] as $k){$previous[$k]=getenv($k);putenv($k);}
function check(string $name,bool $pass):void {global $cases;$cases[]=['case'=>$name,'pass'=>$pass];if(!$pass)throw new RuntimeException($name);}
function removeFolder(string $path):void {foreach(scandir($path) as $n)if($n!=='.'&&$n!=='..'){is_dir($path.'/'.$n)?removeFolder($path.'/'.$n):unlink($path.'/'.$n);}rmdir($path);}
function broker(array $values,bool $noisy=false):void {
    $guard="if(PHP_SAPI!=='cli' && basename((string)(\$_SERVER['SCRIPT_FILENAME']??''))===basename(__FILE__)){http_response_code(404);exit;}\n";
    file_put_contents(MON_BROKER_CONFIG,'<?php '.$guard.($noisy?"echo 'fixture-output-must-not-escape';\n":'').'return '.var_export($values,true).';');
}
try {
    $store=new MonStore($app);$cfg=new MonConfig(null,$app);
    check('broker file is resolved beside mon.php in the web root',MON_BROKER_CONFIG===$app.'/broker_config.local.php');
    check('absent optional broker configuration preserves unconfigured mode',$cfg->kisCredentialsSource()==='missing'&&$cfg->kisConfigError()===null);
    file_put_contents(MON_PRIVATE_SYNC_CONFIG,"<?php return ['github_token'=>'fixture-github'];");
    $store->write('settings.php',(object)['KIS_APP_KEY'=>'fixture-web-key','KIS_APP_SECRET'=>'fixture-web-secret'],MON_SETTINGS_PREFIX);
    putenv('KIS_APP_KEY=fixture-env-key');putenv('KIS_APP_SECRET=fixture-env-secret');
    $values=['app_key'=>'fixture-broker-A','app_secret'=>'fixture-secret-A','mode'=>'paper','repo'=>'wskimgit/trade','branch'=>'candidate','auto_trade_enabled'=>true,'allow_buy'=>true,'KIS_BASE_URL'=>'https://fixture.invalid'];
    broker($values,true);$before=file_get_contents(MON_BROKER_CONFIG);ob_start();$cfg=new MonConfig(null,$app);$output=ob_get_clean();
    check('returned app_key and app_secret override prior web and environment pairs',$cfg->get('KIS_APP_KEY')==='fixture-broker-A'&&$cfg->get('KIS_APP_SECRET')==='fixture-secret-A');
    check('including guarded noisy configuration emits no response bytes',$output==='');
    check('broker configuration cannot redirect stock main or the SIS GitHub credential',$cfg->get('MON_REPOSITORY','wskimgit/stock')==='wskimgit/stock'&&$cfg->get('MON_BRANCH','main')==='main'&&$cfg->get('GITHUB_TOKEN')==='fixture-github');
    check('broker execution mode and API base are not imported',$cfg->get('BROKER_MODE','unset')==='unset'&&$cfg->get('KIS_BASE_URL','unset')==='unset');
    $state=mon_web_state($store,$cfg);ob_start();mon_web_html($state,'',true,'');$html=ob_get_clean();
    check('status reports the broker source and configured credential pair',$state['connection']['kis']&&$state['kis_credentials_source']==='broker_config.local.php'&&$state['kis_config_error']===null);
    check('HTML and status expose no broker credential values',strpos($html,'fixture-broker-A')===false&&strpos($html,'fixture-secret-A')===false&&strpos(mon_json($state,false),'fixture-broker-A')===false&&strpos(mon_json($state,false),'fixture-secret-A')===false);
    check('HTML identifies automatic reuse of the existing broker file',strpos($html,'broker_config.local.php에서 자동 참조')!==false&&strpos($html,'기존 설정 파일 사용')!==false);
    file_put_contents($root.'/test_broker_ui.html',$html);
    unlink($store->path('settings.php'));$cfg=new MonConfig(null,$app);mon_web_save($store,$cfg,['PHP_CLI'=>'']);
    $saved=mon_decode(substr(file_get_contents($store->path('settings.php')),strlen(MON_SETTINGS_PREFIX)));
    check('saving other web settings never copies the broker key or secret',!property_exists($saved,'KIS_APP_KEY')&&!property_exists($saved,'KIS_APP_SECRET'));
    check('the original broker file remains byte-for-byte unchanged',file_get_contents(MON_BROKER_CONFIG)===$before);
    $calls=[];$http=new MonHttp(null,static function($method,$url,$headers,$body)use(&$calls){
        $calls[]=[$method,$url,$headers,$body];
        return ['status'=>200,'headers'=>[],'body'=>$method==='POST'?'{"access_token":"fixture-access-token","expires_in":86400}':'{"rt_cd":"0","output2":[]}'];
    });
    $collector=new MonCollector($http,$store,$cfg);$token=new ReflectionMethod(MonCollector::class,'token');$token->setAccessible(true);
    $settings=['http_timeout_seconds'=>2,'request_spacing_seconds'=>0];$token->invoke($collector,microtime(true)+2,$settings);$body=mon_decode($calls[0][3]);
    check('OAuth receives exactly the broker pair through the existing production endpoint',$body->appkey==='fixture-broker-A'&&$body->appsecret==='fixture-secret-A'&&$calls[0][1]==='https://openapi.koreainvestment.com:9443/oauth2/tokenP');
    $token->invoke($collector,microtime(true)+2,$settings);
    check('unchanged credentials reuse the in-memory access token',count($calls)===1);
    $stamp=mon_settings_stamp($store,'');$mtime=filemtime(MON_BROKER_CONFIG);$size=filesize(MON_BROKER_CONFIG);
    $values['app_key']='fixture-broker-B';$values['app_secret']='fixture-secret-B';broker($values,true);touch(MON_BROKER_CONFIG,$mtime);clearstatcache(true,MON_BROKER_CONFIG);
    check('same-size same-mtime credential rotation changes the reload stamp',filesize(MON_BROKER_CONFIG)===$size&&mon_settings_stamp($store,'')!==$stamp);
    $cfg=new MonConfig(null,$app);$collector->configure($cfg);$token->invoke($collector,microtime(true)+2,$settings);$body=mon_decode($calls[1][3]);
    check('rotation invalidates the old token and authenticates with both new values',count($calls)===2&&$body->appkey==='fixture-broker-B'&&$body->appsecret==='fixture-secret-B');
    $data=mon_decode(file_get_contents($root.'/mon_data.json'));$data->watchlist->settings->enabled=false;
    $symbol=(object)['symbol_id'=>'US|NASDAQ|AAPL','country'=>'US','exchange'=>'NASDAQ','currency'=>'USD','source_codes'=>(object)['kis'=>(object)['symbol'=>'AAPL','exchange_code'=>'NAS']]];
    try{$collector->kis($symbol,$data->watchlist,microtime(true)+2,$settings);}catch(MonFault $e){if($e->faultCode!=='KIS_NO_VALID_COMPLETED_BAR')throw $e;}
    $quote=end($calls);
    check('quote requests receive the rotated appkey and appsecret headers',$quote[0]==='GET'&&in_array('appkey: fixture-broker-B',$quote[2],true)&&in_array('appsecret: fixture-secret-B',$quote[2],true));
    broker(['app_key'=>'fixture-partial']);$cfg=new MonConfig(null,$app);
    check('an incomplete broker pair never mixes with fallback credentials',$cfg->get('KIS_APP_KEY')==='fixture-env-key'&&$cfg->get('KIS_APP_SECRET')==='fixture-env-secret'&&$cfg->kisCredentialsSource()==='web_or_environment');
    putenv('KIS_APP_KEY');putenv('KIS_APP_SECRET');
    foreach(['absent'=>[],'empty'=>['app_key'=>'','app_secret'=>'fixture-secret'],'wrong_type'=>['app_key'=>[],'app_secret'=>'fixture-secret'],'newline'=>['app_key'=>"fixture\r\nInjected: true",'app_secret'=>'fixture-secret']] as $name=>$v){
        broker($v);$cfg=new MonConfig(null,$app);$error=in_array($name,['absent','empty'],true)?'BROKER_CREDENTIALS_MISSING':'BROKER_CREDENTIALS_INVALID';
        check('broker '.$name.' is reported without raw config or key exposure',$cfg->kisCredentialsSource()==='missing'&&$cfg->kisConfigError()===$error);
    }
    broker(['KIS_APP_KEY'=>'fixture-uppercase-key','KIS_APP_SECRET'=>'fixture-uppercase-secret']);$cfg=new MonConfig(null,$app);
    check('uppercase returned-array aliases are also accepted',$cfg->get('KIS_APP_KEY')==='fixture-uppercase-key'&&$cfg->get('KIS_APP_SECRET')==='fixture-uppercase-secret');
    file_put_contents(MON_BROKER_CONFIG,"<?php return ['app_key'=> ;");ob_start();$cfg=new MonConfig(null,$app);$output=ob_get_clean();
    check('PHP syntax errors return a safe optional KIS diagnostic',$output===''&&$cfg->kisConfigError()==='BROKER_CONFIG_INVALID');
    $store->write('remote_cache.json',$data);$store->write('status.json',(object)['version'=>MON_VERSION,'instance_id'=>'fixture','state'=>'waiting','activity_state'=>'paused','heartbeat_at'=>mon_iso()]);
    $lock=fopen($store->path('daemon.lock'),'c+');flock($lock,LOCK_EX);$state=mon_web_state($store,$cfg);
    check('invalid optional KIS configuration leaves GitHub and process control readable',$state['running']&&$state['connection']['github']&&$state['readiness']==='paused'&&!in_array('BROKER_CONFIG_INVALID',$state['setup_blockers'],true));
    unlink(MON_BROKER_CONFIG);$cfg=new MonConfig(null,$app);
    check('file removal is detected and returns to normal optional-KIS fallback',$cfg->kisCredentialsSource()==='missing'&&$cfg->kisConfigError()===null&&mon_settings_stamp($store,'')!==$stamp);
    echo mon_json(['cases_total'=>count($cases),'passed'=>count($cases),'failed'=>0,'external_API_calls'=>false,'cases'=>$cases]);
} finally {
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    foreach($previous as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    removeFolder($app);
}

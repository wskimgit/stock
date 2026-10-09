<?php
declare(strict_types=1);
// Credential-source integration tests. All tokens and API responses are fixtures.
$root = dirname(__DIR__);
$app = sys_get_temp_dir().'/mon-private-sync-'.bin2hex(random_bytes(6));
mkdir($app); copy($root.'/mon.php', $app.'/mon.php');
require $app.'/mon.php';
$cases = []; $lock = null;
$keys = ['GITHUB_TOKEN','KIS_APP_KEY','KIS_APP_SECRET','MON_REPOSITORY','MON_BRANCH'];
$previous = []; foreach ($keys as $key) { $previous[$key] = getenv($key); putenv($key); }
function check(string $name, bool $pass): void {
    global $cases; $cases[] = ['case'=>$name,'pass'=>$pass];
    if (!$pass) throw new RuntimeException($name);
}
function removeFolder(string $path): void {
    foreach (scandir($path) as $name) if ($name!=='.' && $name!=='..') {
        $p=$path.'/'.$name; if (is_dir($p)) removeFolder($p); else unlink($p);
    }
    rmdir($path);
}
function shared(array $values, bool $echo=false): void {
    $guard="if(PHP_SAPI!=='cli' && basename((string)(\$_SERVER['SCRIPT_FILENAME']??''))===basename(__FILE__)){http_response_code(404);exit;}\n";
    file_put_contents(MON_PRIVATE_SYNC_CONFIG, '<?php '.$guard.($echo?"echo 'fixture-output-must-not-escape';\n":'').'return '.var_export($values,true).';');
}
function api($data): array {
    return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['encoding'=>'base64','sha'=>'fixture','content'=>base64_encode(mon_json($data,false))])];
}
try {
    $store=new MonStore($app); $cfg=new MonConfig(null,$app);
    check('absent shared file preserves unconfigured public-read mode', $cfg->get('GITHUB_TOKEN')==='' && $cfg->githubTokenError()==='GITHUB_TOKEN_MISSING');
    shared(['github_token'=>'fixture-shared-A','repo'=>'wskimgit/SIS','branch'=>'candidate','api_base'=>'https://fixture.invalid']);
    $bytes=file_get_contents(MON_PRIVATE_SYNC_CONFIG); $cfg=new MonConfig(null,$app);
    check('existing returned github_token is read without browser entry', $cfg->get('GITHUB_TOKEN')==='fixture-shared-A');
    check('configured source reports only the existing filename', $cfg->githubTokenSource()==='sis_private_sync_config.php' && $cfg->githubTokenError()==='');
    check('SIS repository and branch do not redirect the MON collector', $cfg->get('MON_REPOSITORY','wskimgit/stock')==='wskimgit/stock' && $cfg->get('MON_BRANCH','main')==='main');
    $data=mon_decode(file_get_contents($root.'/mon_data.json')); $data->watchlist->settings->enabled=true;
    $calls=[]; $written=null;
    $http=new MonHttp(null,static function($m,$u,$h,$b)use(&$calls,&$written,$data){
        $calls[]=[$m,$u,$h,$b];
        if($m==='GET')return api($data);
        $written=mon_decode(base64_decode(mon_decode($b)->content));
        return ['status'=>200,'headers'=>[],'body'=>'{"content":{"sha":"fixture-saved"}}'];
    });
    $gh=new MonGitHub($http,$cfg); $gh->read(microtime(true)+3);
    check('GitHub GET receives the shared credential in the request header', in_array('Authorization: Bearer fixture-shared-A',$calls[0][2],true));
    check('authenticated GET still targets stock and main', strpos($calls[0][1],'/repos/wskimgit/stock/contents/mon_data.json?ref=main')!==false);
    $batch=mon_decode(mon_json($data->collection,false)); $batch->status='partial';
    $gh->publish($batch,MonData::signature($data->watchlist),microtime(true)+3,MonData::settings($data->watchlist));
    $put=end($calls);
    check('shared credential supports publishing to stock rather than SIS', $put[0]==='PUT' && strpos($put[1],'/repos/wskimgit/stock/')!==false && in_array('Authorization: Bearer fixture-shared-A',$put[2],true) && mon_decode($put[3])->branch==='main');
    check('publishing with the shared key preserves analysis and watchlist', mon_json($written->analysis,false)===mon_json($data->analysis,false) && mon_json($written->watchlist,false)===mon_json($data->watchlist,false));
    mon_web_save($store,$cfg,['KIS_APP_KEY'=>'fixture-app']);
    $saved=mon_decode(substr(file_get_contents($store->path('settings.php')),strlen(MON_SETTINGS_PREFIX)));
    check('saving other web settings does not copy the shared GitHub key', !property_exists($saved,'GITHUB_TOKEN'));
    check('the original SIS configuration remains byte-for-byte unchanged', file_get_contents(MON_PRIVATE_SYNC_CONFIG)===$bytes);
    mon_web_save($store,$cfg,['GITHUB_TOKEN'=>'fixture-web']); putenv('GITHUB_TOKEN=fixture-env');
    $cfg=new MonConfig(null,$app);
    check('requested shared-file priority takes precedence over separate saved and environment tokens', $cfg->get('GITHUB_TOKEN')==='fixture-shared-A');
    $stamp=mon_settings_stamp($store,''); $mtime=filemtime(MON_PRIVATE_SYNC_CONFIG); $length=filesize(MON_PRIVATE_SYNC_CONFIG);
    shared(['github_token'=>'fixture-shared-B','repo'=>'wskimgit/SIS','branch'=>'candidate','api_base'=>'https://fixture.invalid']);
    touch(MON_PRIVATE_SYNC_CONFIG,$mtime); clearstatcache(true,MON_PRIVATE_SYNC_CONFIG);
    check('shared-key rotation is detected despite unchanged length and modification time', filesize(MON_PRIVATE_SYNC_CONFIG)===$length && mon_settings_stamp($store,'')!==$stamp);
    $cfg=new MonConfig(null,$app);
    check('next configuration load uses the rotated shared key', $cfg->get('GITHUB_TOKEN')==='fixture-shared-B');
    unlink(MON_PRIVATE_SYNC_CONFIG); $cfg=new MonConfig(null,$app);
    check('shared-file removal falls back to the original browser settings', $cfg->get('GITHUB_TOKEN')==='fixture-web' && $cfg->githubTokenSource()==='mon_settings.php');
    unlink($store->path('settings.php')); $cfg=new MonConfig(null,$app);
    check('environment fallback remains available when no saved settings exist', $cfg->get('GITHUB_TOKEN')==='fixture-env' && $cfg->githubTokenSource()==='environment'); putenv('GITHUB_TOKEN');
    shared(['GITHUB_TOKEN'=>'fixture-alias']); $cfg=new MonConfig(null,$app);
    check('existing uppercase GitHub-token alias is accepted', $cfg->get('GITHUB_TOKEN')==='fixture-alias');
    shared(['SIS_GITHUB_TOKEN'=>'fixture-sis-alias']); $cfg=new MonConfig(null,$app);
    check('existing SIS GitHub-token alias is accepted', $cfg->get('GITHUB_TOKEN')==='fixture-sis-alias');
    shared(['github_token'=>'fixture-shared-C'],true); ob_start(); $cfg=new MonConfig(null,$app); $output=ob_get_clean();
    check('including a noisy PHP configuration emits no response bytes', $output==='' && $cfg->get('GITHUB_TOKEN')==='fixture-shared-C');
    $store->write('remote_cache.json',$data);
    $state=mon_web_state($store,$cfg); ob_start(); mon_web_html($state,'',true,''); $html=ob_get_clean();
    check('HTML shows the existing-key source without showing the key value', strpos($html,'기존 설정 파일 사용')!==false && strpos($html,'fixture-shared-C')===false);
    check('status JSON reports the source and excludes the shared credential', $state['connection']['github'] && $state['github_token_source']==='sis_private_sync_config.php' && strpos(mon_json($state,false),'fixture-shared-C')===false);
    file_put_contents($root.'/test_private_ui.html',$html);
    foreach (['empty'=>['github_token'=>''],'absent'=>[],'wrong_type'=>['github_token'=>[]],'header_injection'=>['github_token'=>"fixture\r\nInjected: true"]] as $name=>$values) {
        shared($values); $cfg=new MonConfig(null,$app);
        $expected=in_array($name,['empty','absent'],true)?'PRIVATE_SYNC_TOKEN_MISSING':'PRIVATE_SYNC_TOKEN_INVALID';
        check('shared configuration '.$name.' is rejected without exposing its contents', $cfg->get('GITHUB_TOKEN')==='' && $cfg->githubTokenError()===$expected);
    }
    file_put_contents(MON_PRIVATE_SYNC_CONFIG,"<?php return ['github_token' => ;"); ob_start(); $cfg=new MonConfig(null,$app); $output=ob_get_clean();
    check('PHP syntax errors become a safe diagnostic rather than a fatal web error', $output==='' && $cfg->githubTokenError()==='PRIVATE_SYNC_CONFIG_INVALID');
    $store->write('status.json',(object)['version'=>MON_VERSION,'instance_id'=>'fixture','state'=>'waiting','activity_state'=>'needs_setup','heartbeat_at'=>mon_iso()]);
    $lock=fopen($store->path('daemon.lock'),'c+');flock($lock,LOCK_EX);
    $state=mon_web_state($store,$cfg);
    check('bad shared configuration is visible as setup-needed while process state remains readable', $state['running'] && $state['readiness']==='needs_setup' && in_array('PRIVATE_SYNC_CONFIG_INVALID',$state['setup_blockers'],true));
    echo mon_json(['cases_total'=>count($cases),'passed'=>count($cases),'failed'=>0,'external_API_calls'=>false,'cases'=>$cases]);
} finally {
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    foreach($previous as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    removeFolder($app);
}

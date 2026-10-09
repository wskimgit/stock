"""Real HTTP + detached worker, with only a local mock GitHub transport."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.parse, urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('MON_TEST_PHP','php')
cases=[]
def check(name,value):
    cases.append({'case':name,'pass':bool(value)})
    if not value:raise AssertionError(name)
def wait(fn,seconds=8):
    end=time.monotonic()+seconds
    while time.monotonic()<end:
        value=fn()
        if value:return value
        time.sleep(.1)
    raise AssertionError('timed out awaiting worker state')
with tempfile.TemporaryDirectory(prefix='mon-errc-http-') as tmp:
    folder=pathlib.Path(tmp);web=folder/'web';web.mkdir()
    source=(ROOT/'mon.php').read_text()
    original='$this->http=new MonHttp($this->control);'
    assert source.count(original)==1
    mock='''$this->http=new MonHttp($this->control,static function($method,$url,$headers,$body)use($store){
        file_put_contents($store->path('test_calls.jsonl'),json_encode(['method'=>$method,'at'=>microtime(true)])."\\n",FILE_APPEND);
        if(strpos($url,'https://api.github.com/')!==0)throw new MonFault('TEST_PROVIDER_NOT_ALLOWED');
        $d=mon_decode(file_get_contents($store->path('test_snapshot.json')));
        if($method==='GET')return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['encoding'=>'base64','sha'=>'test-sha','content'=>base64_encode(mon_json($d,false))])];
        throw new MonFault('TEST_UNEXPECTED_WRITE');
    });'''
    (web/'mon.php').write_text(source.replace(original,mock))
    data=json.loads((ROOT/'mon_data.json').read_text());data['watchlist']['settings']['enabled']=False
    data['watchlist']['symbols']=data['watchlist']['symbols'][:1]
    (web/'mon_test_snapshot.json').write_text(json.dumps(data,ensure_ascii=False))
    env=dict(os.environ)
    env.update(GITHUB_TOKEN='',KIS_APP_KEY='',KIS_APP_SECRET='',MON_STATE_DIR='',MON_ENV_FILE='',MON_IDLE_POLL_SECONDS='60')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    url=f'http://127.0.0.1:{port}/mon.php'
    log=open(folder/'server.log','w')
    server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(web)],env=env,stdout=log,stderr=log)
    def request(form=None,html=False):
        req=urllib.request.Request(url+('' if form or html else '?view=status'),data=urllib.parse.urlencode(form).encode() if form else None,headers={} if html else {'Accept':'application/json'})
        with urllib.request.urlopen(req,timeout=12) as response:
            raw=response.read().decode()
            return raw if html else json.loads(raw)
    def state():return request()['data']
    try:
        def ready():
            try:return request()
            except OSError:return None
        wait(ready)
        check('GET status does not launch a worker',not state()['running'])
        start=request({'action':'start'});check('web Start launches the actual detached worker',start['ok'] and start['data']['running'])
        s=wait(lambda:state() if state()['readiness']=='needs_setup' else None)
        check('missing key displays setup-needed while the worker is alive',s['running'] and s['heartbeat_fresh'] and not s['monitoring_active'])
        check('public list is loaded even without the mirror key',s['watchlist_loaded'] and s['watched']==1)
        check('no quotes or GitHub writes are fabricated',s['fresh_quotes']==0 and s['last_mirrored_at'] is None)
        calls=(web/'mon_test_calls.jsonl').read_text().splitlines()
        check('only one anonymous list GET is made before setup',len(calls)==1 and json.loads(calls[0])['method']=='GET')
        begin=time.monotonic();saved=request({'action':'save_settings','GITHUB_TOKEN':'fixture-browser-key','KIS_APP_KEY':'fixture-app','KIS_APP_SECRET':'fixture-secret','PHP_CLI':''})
        check('browser-saved configuration overrides blank inherited variables',saved['data']['connection']=={'github':True,'kis':True})
        s=wait(lambda:state() if state()['readiness']=='paused' else None)
        elapsed=time.monotonic()-begin
        check('configuration changes wake a 300-second setup wait within one five-second slice',elapsed<7)
        check('disabled shared collection remains disabled after saving keys',s['readiness']=='paused' and s['setup_blockers']==['COLLECTION_DISABLED'] and not s['monitoring_active'])
        check('wait heartbeat retains the paused activity stage',s['status']['activity_state']=='paused')
        html=request(html=True)
        check('HTML renders version and latest-price counter with no credential values','v1.2.3' in html and '최신 가격' in html and 'fixture-browser-key' not in html and 'fixture-secret' not in html)
        allcalls=[json.loads(line) for line in (web/'mon_test_calls.jsonl').read_text().splitlines()]
        check('setup and disabled collection perform no provider or publishing calls',all(c['method']=='GET' for c in allcalls))
        stop=request({'action':'stop'});check('web Stop releases the worker after setup reload',stop['ok'] and not stop['data']['running'])
        check('web state keeps process stopped distinct from disabled collection',state()['readiness']=='stopped')
        check('no application subfolders are created',not any(p.is_dir() for p in web.iterdir()))
    finally:
        try:
            if server.poll() is None:request({'action':'stop'})
        finally:server.terminate();server.wait(timeout=6);log.close()
print(json.dumps({'cases_total':len(cases),'passed':sum(c['pass'] for c in cases),'failed':sum(not c['pass'] for c in cases),'real_HTTP_and_detached_worker':True,'external_API_calls':False,'cases':cases},ensure_ascii=False))

"""Real web control and worker reload; GitHub transport and all tokens are fixtures."""
import hashlib, json, os, pathlib, socket, subprocess, tempfile, time, urllib.parse, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('MON_TEST_PHP', 'php')
cases = []

def check(name, value):
    cases.append({'case': name, 'pass': bool(value)})
    if not value:
        raise AssertionError(name)

def wait(fn, seconds=8):
    end = time.monotonic() + seconds
    while time.monotonic() < end:
        result = fn()
        if result:
            return result
        time.sleep(.1)
    raise AssertionError('worker did not apply the shared configuration in time')

with tempfile.TemporaryDirectory(prefix='mon-shared-web-') as temp:
    folder = pathlib.Path(temp)
    web = folder / 'web'
    web.mkdir()
    source = (ROOT / 'mon.php').read_text()
    original = '$this->http=new MonHttp($this->control);'
    assert source.count(original) == 1
    mock = '''$this->http=new MonHttp($this->control,static function($method,$url,$headers,$body)use($store){
        $token='';foreach($headers as $header)if(strpos($header,'Authorization: Bearer ')===0)$token=substr($header,22);
        file_put_contents($store->path('test_calls.jsonl'),json_encode(['method'=>$method,'url'=>$url,'token_hash'=>hash('sha256',$token),'at'=>microtime(true)])."\\n",FILE_APPEND);
        if(strpos($url,'https://api.github.com/repos/wskimgit/stock/contents/mon_data.json')!==0)throw new MonFault('TEST_WRONG_REPOSITORY');
        if($method!=='GET')throw new MonFault('TEST_UNEXPECTED_WRITE');
        $d=mon_decode(file_get_contents($store->path('test_snapshot.json')));
        return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['encoding'=>'base64','sha'=>'fixture','content'=>base64_encode(mon_json($d,false))])];
    });'''
    (web / 'mon.php').write_text(source.replace(original, mock))
    data = json.loads((ROOT / 'mon_data.json').read_text())
    data['watchlist']['settings']['enabled'] = False
    data['watchlist']['symbols'] = data['watchlist']['symbols'][:1]
    (web / 'mon_test_snapshot.json').write_text(json.dumps(data, ensure_ascii=False))
    shared_file = web / 'sis_private_sync_config.php'
    def write_shared(token, noisy=False):
        guard = "if(PHP_SAPI!=='cli' && basename((string)($_SERVER['SCRIPT_FILENAME']??''))===basename(__FILE__)){http_response_code(404);exit;}"
        shared_file.write_text("<?php " + guard + ("echo 'fixture-output-must-not-escape';" if noisy else '') + "return ['repo'=>'wskimgit/SIS','branch'=>'candidate','github_token'=>'" + token + "'];")
    def calls():
        path = web / 'mon_test_calls.jsonl'
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []
    def has_token(token):
        digest = hashlib.sha256(token.encode()).hexdigest()
        return any(item['token_hash'] == digest for item in calls())
    env = dict(os.environ)
    env.update(GITHUB_TOKEN='', KIS_APP_KEY='', KIS_APP_SECRET='', MON_STATE_DIR='', MON_ENV_FILE='', MON_IDLE_POLL_SECONDS='60')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    url = f'http://127.0.0.1:{port}/mon.php'
    log = open(folder / 'server.log', 'w')
    server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(web)], env=env, stdout=log, stderr=log)
    def request(form=None, html=False):
        req = urllib.request.Request(url + ('' if form or html else '?view=status'), data=urllib.parse.urlencode(form).encode() if form else None, headers={} if html else {'Accept': 'application/json'})
        with urllib.request.urlopen(req, timeout=12) as response:
            raw = response.read().decode()
            return raw if html else json.loads(raw)
    try:
        def ready():
            try:
                return request()
            except OSError:
                return None
        wait(ready)
        started = request({'action': 'start'})
        wait(lambda: request()['data']['readiness'] == 'needs_setup')
        check('actual worker starts in setup wait before the shared file exists', started['ok'] and request()['data']['running'])
        begin = time.monotonic()
        write_shared('fixture-shared-A', noisy=True)
        wait(lambda: has_token('fixture-shared-A'))
        state = request()['data']
        check('creating the existing shared configuration wakes the 300-second wait', time.monotonic()-begin < 7)
        check('web guard permits inclusion and stdout does not corrupt JSON', state['github_token_source'] == 'sis_private_sync_config.php' and state['connection']['github'])
        check('worker becomes paused rather than claiming collection while enabled is false', state['readiness'] == 'paused' and state['setup_blockers'] == ['COLLECTION_DISABLED'] and not state['monitoring_active'])
        before = shared_file.read_bytes()
        saved = request({'action': 'save_settings', 'KIS_APP_KEY': 'fixture-app'})
        check('saving another setting retains shared-file priority', saved['ok'] and saved['data']['github_token_source'] == 'sis_private_sync_config.php')
        check('web save neither edits the SIS file nor copies its token', shared_file.read_bytes() == before and 'fixture-shared-A' not in (web / 'mon_settings.php').read_text())
        stamp = shared_file.stat()
        write_shared('fixture-shared-B', noisy=True)
        os.utime(shared_file, ns=(stamp.st_atime_ns, stamp.st_mtime_ns))
        begin = time.monotonic()
        wait(lambda: has_token('fixture-shared-B'))
        check('running worker adopts a same-size and same-mtime key rotation within five-second slices', time.monotonic()-begin < 7 and shared_file.stat().st_size == stamp.st_size)
        allcalls = calls()
        check('both old and rotated requests target stock main rather than configured SIS candidate', all(item['method'] == 'GET' and '/repos/wskimgit/stock/' in item['url'] and 'ref=main' in item['url'] for item in allcalls))
        html = request(html=True)
        check('web page identifies shared-key use and excludes fixture credentials', '기존 설정 파일 사용' in html and 'fixture-shared-A' not in html and 'fixture-shared-B' not in html and 'fixture-output-must-not-escape' not in html)
        check('collector does not fabricate prices or writes with collection disabled', request()['data']['fresh_quotes'] == 0 and request()['data']['last_mirrored_at'] is None)
        shared_file.write_text("<?php return ['github_token' => ;")
        bad = request()
        check('malformed shared file returns readable setup diagnostics', bad['ok'] and 'PRIVATE_SYNC_CONFIG_INVALID' in bad['data']['setup_blockers'])
        stopped = request({'action': 'stop'})
        check('web Stop still works when the shared PHP file is malformed', stopped['ok'] and not stopped['data']['running'])
    finally:
        try:
            if server.poll() is None:
                request({'action': 'stop'})
        finally:
            server.terminate()
            server.wait(timeout=6)
            log.close()
print(json.dumps({'cases_total': len(cases), 'passed': sum(c['pass'] for c in cases), 'failed': sum(not c['pass'] for c in cases), 'real_HTTP_and_detached_worker': True, 'external_API_calls': False, 'cases': cases}, ensure_ascii=False))

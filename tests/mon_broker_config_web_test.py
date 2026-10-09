"""Actual web control/worker reload; all broker keys and API responses are fixtures."""
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
    raise AssertionError('worker did not reload broker configuration in time')

with tempfile.TemporaryDirectory(prefix='mon-broker-web-') as temp:
    folder = pathlib.Path(temp)
    web = folder / 'web'
    web.mkdir()
    source = (ROOT / 'mon.php').read_text()
    original = '$this->http=new MonHttp($this->control);'
    assert source.count(original) == 1
    mock = '''$this->http=new MonHttp($this->control,static function($method,$url,$headers,$body)use($store){
        $cfg=new MonConfig(null,$store->dir);
        file_put_contents($store->path('test_calls.jsonl'),json_encode(['method'=>$method,'url'=>$url,'pair_hash'=>hash('sha256',$cfg->get('KIS_APP_KEY')."\\0".$cfg->get('KIS_APP_SECRET'))])."\\n",FILE_APPEND);
        if($method!=='GET'||strpos($url,'https://api.github.com/repos/wskimgit/stock/contents/mon_data.json')!==0)throw new MonFault('TEST_UNEXPECTED_REQUEST');
        $d=mon_decode(file_get_contents($store->path('test_snapshot.json')));
        return ['status'=>200,'headers'=>[],'body'=>mon_json((object)['encoding'=>'base64','sha'=>'fixture','content'=>base64_encode(mon_json($d,false))])];
    });'''
    (web / 'mon.php').write_text(source.replace(original, mock))
    data = json.loads((ROOT / 'mon_data.json').read_text())
    data['watchlist']['settings']['enabled'] = False
    data['watchlist']['symbols'] = data['watchlist']['symbols'][:1]
    (web / 'mon_test_snapshot.json').write_text(json.dumps(data, ensure_ascii=False))
    (web / 'sis_private_sync_config.php').write_text("<?php return ['github_token'=>'fixture-github'];")
    broker_file = web / 'broker_config.local.php'
    def write_broker(suffix):
        guard = "if(PHP_SAPI!=='cli' && basename((string)($_SERVER['SCRIPT_FILENAME']??''))===basename(__FILE__)){http_response_code(404);exit;}"
        broker_file.write_text("<?php " + guard + "echo 'fixture-output-must-not-escape';return ['app_key'=>'fixture-broker-" + suffix + "','app_secret'=>'fixture-secret-" + suffix + "','mode'=>'paper','repo'=>'wskimgit/trade','branch'=>'candidate'];")
    def calls():
        path = web / 'mon_test_calls.jsonl'
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []
    def has_pair(suffix):
        digest = hashlib.sha256(('fixture-broker-' + suffix + '\0fixture-secret-' + suffix).encode()).hexdigest()
        return any(item['pair_hash'] == digest for item in calls())
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
        wait(lambda: request()['data']['readiness'] == 'paused')
        check('web Start works with GitHub while optional broker file is absent', started['ok'] and request()['data']['running'] and not request()['data']['connection']['kis'])
        begin = time.monotonic()
        write_broker('A')
        wait(lambda: has_pair('A'))
        state = request()['data']
        check('file creation wakes the running worker within five-second slices', time.monotonic()-begin < 7)
        check('web status reports broker source without leaking file output or keys', state['connection']['kis'] and state['kis_credentials_source'] == 'broker_config.local.php' and 'fixture-broker-A' not in json.dumps(state))
        before = broker_file.read_bytes()
        saved = request({'action': 'save_settings', 'PHP_CLI': ''})
        check('saving another web setting preserves the original broker file and avoids key copies', saved['ok'] and broker_file.read_bytes() == before and 'KIS_APP_KEY' not in (web / 'mon_settings.php').read_text() and 'KIS_APP_SECRET' not in (web / 'mon_settings.php').read_text())
        stamp = broker_file.stat()
        write_broker('B')
        os.utime(broker_file, ns=(stamp.st_atime_ns, stamp.st_mtime_ns))
        begin = time.monotonic()
        wait(lambda: has_pair('B'))
        check('same-size same-mtime rotation is loaded by the existing worker', time.monotonic()-begin < 7 and broker_file.stat().st_size == stamp.st_size)
        html = request(html=True)
        check('web HTML identifies automatic reuse without displaying any credential or output', 'broker_config.local.php에서 자동 참조' in html and all(v not in html for v in ['fixture-broker-A','fixture-secret-A','fixture-broker-B','fixture-secret-B','fixture-output-must-not-escape']))
        check('broker repo fields do not redirect collection or trigger writes', all(item['method'] == 'GET' and '/repos/wskimgit/stock/' in item['url'] and 'ref=main' in item['url'] for item in calls()))
        broker_file.write_text("<?php return ['app_key'=> ;")
        bad = request()['data']
        check('malformed optional broker file reports a KIS error without disabling GitHub', bad['kis_config_error'] == 'BROKER_CONFIG_INVALID' and bad['connection']['github'] and bad['running'] and 'BROKER_CONFIG_INVALID' not in bad['setup_blockers'])
        stopped = request({'action': 'stop'})
        check('web Stop remains functional with a malformed broker file', stopped['ok'] and not stopped['data']['running'])
    finally:
        try:
            if server.poll() is None:
                request({'action': 'stop'})
        finally:
            server.terminate()
            server.wait(timeout=6)
            log.close()
print(json.dumps({'cases_total': len(cases), 'passed': sum(c['pass'] for c in cases), 'failed': sum(not c['pass'] for c in cases), 'real_HTTP_and_detached_worker': True, 'external_API_calls': False, 'cases': cases}, ensure_ascii=False))

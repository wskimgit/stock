"""Real HTTP progress/UI tests with local synthetic cache, no daemon or external APIs."""
import hashlib, json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.request
ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('MON_TEST_PHP', 'php')
cases = []
def check(name, value):
    cases.append({'case': name, 'pass': bool(value)})
    if not value:
        raise AssertionError(name)
raw = subprocess.check_output([PHP, '-n', str(ROOT/'tests/mon_progress_fixture.php')])
seed = json.loads(raw)
with tempfile.TemporaryDirectory(prefix='mon-progress-http-') as tmp:
    web = pathlib.Path(tmp)
    shutil.copyfile(ROOT/'mon.php', web/'mon.php')
    env = dict(os.environ)
    env.update(MON_STATE_DIR=str(web), MON_ENV_FILE='', GITHUB_TOKEN='', KIS_APP_KEY='', KIS_APP_SECRET='')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    url = f'http://127.0.0.1:{port}/mon.php'
    log = open(web/'server.log', 'w')
    server = subprocess.Popen([PHP, '-n', '-S', f'127.0.0.1:{port}', '-t', str(web)], env=env, stdout=log, stderr=log)
    def request(query='', data=None, html=False, json_body=False):
        headers = {} if html else {'Accept': 'application/json'}
        if json_body:
            headers['Content-Type'] = 'application/json'
        req = urllib.request.Request(url+query, data=data, headers=headers)
        try:
            response = urllib.request.urlopen(req, timeout=5)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            body = response.read().decode()
            return response.status, body if html else json.loads(body)
    try:
        end = time.monotonic()+5
        while True:
            try:
                status, empty = request('?view=progress')
                break
            except OSError:
                if time.monotonic() > end:
                    raise
                time.sleep(.05)
        check('progress GET without keys or source returns explicit idle result', status == 200 and empty['progress']['phase'] == 'idle')
        seed['_mon_cache'] = {'blob_sha': 'a'*40, 'fetched_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'repository': 'wskimgit/stock', 'branch': 'main'}
        (web/'mon_remote_cache.json').write_text(json.dumps(seed))
        status, first = request('?view=progress')
        check('thin GET shows three pending independent symbols', status == 200 and first['progress']['counts']['pending'] == 3 and len(first['progress']['cards']) == 3)
        token = first['progress']['snapshot_token']
        status, unchanged = request('?view=progress&since='+token)
        check('unchanged token suppresses repeated card body over real HTTP', not unchanged['progress']['changed'] and unchanged['progress']['cards'] == [])
        check('thin response contains no compressed history or broker key', 'gzip+base64' not in json.dumps(first) and 'fixture-secret' not in json.dumps(first) and len(json.dumps(first)) < 12288)
        text = json.dumps(seed)
        sha = hashlib.sha1(f'blob {len(text.encode())}\0'.encode()+text.encode()).hexdigest()
        items = [{'symbol': symbol, 'request_id': f'http-bulk-{i}', 'wait_seconds': 100} for i, symbol in enumerate(seed['watchlist']['symbols'][:2])]
        items.insert(1, {'request_id': 'invalid-http-item'})
        body = {'source_json': text, 'context': {'source_blob_sha': sha, 'rendered_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'countries': ['KR']}, 'items': items}
        status, bulk = request('?api=mon_detail_requests', data=json.dumps(body).encode(), json_body=True)
        check('HTTP bulk registration returns one file for independent valid requests', status == 200 and bulk['ok'] and len(bulk['result']['files']) == 1 and bulk['result']['requests'][1]['status'] == 'rejected')
        check('HTTP bulk registration preserves analysis and never writes remotely', bulk['result']['validation']['analysis_preserved'] and bulk['result']['validation']['collection_preserved'] and not (web/'mon_status.json').exists())
        sid = seed['watchlist']['symbols'][0]['symbol_id']
        r = seed['watchlist']['detail_requests'][sid]
        snapshot = seed['_test_snapshots'][sid]
        # Progress intentionally reads producer metadata, not a trading-qualified snapshot.
        response = {'contract': r['contract'], 'request_id': r['request_id'], 'request_hash': r['request_hash'], 'symbol_id': sid, 'state': 'done', 'data_status': 'previous_verified',
                    'attempts': 1, 'completed_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'retry_after': None, 'error': 'HTTP_TRANSPORT_28', 'usable': True,
                    'bars_as_of': snapshot['metrics']['bars_as_of'], 'source_checked_at': snapshot['history_metadata']['checked_at'], 'payload': {'sha256': 'b'*64}}
        (web/'mon_detail_pending.json').write_text(json.dumps({'collection': {'detail_responses': {sid: response}}}))
        status, next_result = request('?view=progress&since='+token)
        check('new local result is immediately visible while two symbols wait', next_result['progress']['changed'] and next_result['progress']['counts']['done'] == 1 and next_result['progress']['counts']['pending'] == 2)
        check('unsent result is clearly marked publication pending', next_result['progress']['publication'] == 'pending' and next_result['progress']['cards'][0]['publication'] == 'pending')
        check('old verified source clock is retained over HTTP', next_result['progress']['cards'][0]['source_checked_at'] == snapshot['history_metadata']['checked_at'])
        check('progress completion never claims autonomous final selection', next_result['progress']['auto_selected'] is False and next_result['progress']['final_decision_complete'] is False and next_result['progress']['full_snapshot_validation_required'])
        status, bad = request('?view=progress&since=bad')
        check('invalid change token is rejected', status == 400 and bad['error_code'] == 'MON_PROGRESS_TOKEN_INVALID')
        status, method = request('?view=progress', data=b'x=1')
        check('progress is read-only GET', status == 405 and method['error_code'] == 'MON_METHOD_INVALID')
        status, state = request('?view=status')
        check('ordinary web status includes progressive counts without starting a process', status == 200 and state['data']['detail_progress']['counts']['done'] == 1 and not state['data']['running'])
        status, html = request(html=True)
        check('HTML contains per-symbol list and version with no credentials', status == 200 and 'v1.5.0' in html and 'id="detail-cards"' in html and 'fixture-secret' not in html)
        html_path = ROOT/'tests/progress_ui_fixture.html'
        html_path.write_text(html)
        check('progress access creates no daemon status or publication file', not (web/'mon_status.json').exists() and not (web/'mon.log').exists())
        (web/'mon_remote_cache.json').write_text('{"watchlist":null,"collection":null}')
        status, broken = request('?view=progress')
        check('damaged local cache returns a bounded explicit error', status == 400 and broken['error_code'] == 'MON_PROGRESS_CACHE_INVALID')
    finally:
        server.terminate()
        server.wait(timeout=5)
        log.close()
print(json.dumps({'cases_total': len(cases), 'passed': len(cases), 'failed': 0, 'real_HTTP': True, 'external_API_calls': False, 'cases': cases}, ensure_ascii=False))

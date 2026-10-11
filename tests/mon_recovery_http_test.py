#!/usr/bin/env python3
"""New 1.6.1 recovery endpoints against real local PHP 7.4; no daemon/network providers."""
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('MON_TEST_PHP', 'php')
cases = []

def check(name, condition):
    cases.append({'case': name, 'pass': bool(condition)})
    if not condition:
        raise AssertionError(name)

def iso(offset=0):
    return (datetime.now(timezone.utc)+timedelta(seconds=offset)).strftime('%Y-%m-%dT%H:%M:%S.%fZ')

def sha(raw):
    return hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()

def main():
    started = time.monotonic()
    raw = subprocess.check_output([PHP, '-n', '-r', 'require "tests/mon_reinforcement_fixture.php"; $d=reinforcement_fixture(); $r=MonDetails::finalize($d,reinforcement_decision($d,reinforcement_context($d))); echo $r["files"][0]["content"];'], cwd=ROOT)
    source = raw.decode()
    data = json.loads(raw)
    source_sha = sha(raw)
    token = 'synthetic-recovery-test-only'
    with tempfile.TemporaryDirectory(prefix='mon-recovery-http-') as tmp:
        store = Path(tmp)
        (store/'mon_remote_cache.json').write_bytes(raw)
        (store/'fixture.env').write_text('MON_OPERATOR_TOKEN='+token+'\n')
        env = dict(os.environ, MON_STATE_DIR=tmp, MON_ENV_FILE=str(store/'fixture.env'))
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        url = f'http://127.0.0.1:{port}/mon.php?'
        with open(store/'server.log', 'wb') as log:
            server = subprocess.Popen([PHP, '-n', '-S', f'127.0.0.1:{port}', '-t', str(ROOT)], env=env, stdout=log, stderr=log)

            def req(query, body=None, auth=True):
                headers = {'Accept': 'application/json'}
                if auth:
                    headers['X-Mon-Operator-Token'] = token
                payload = None
                if body is not None:
                    headers['Content-Type'] = 'application/json'
                    payload = json.dumps(body, ensure_ascii=False).encode()
                request = urllib.request.Request(url+query, data=payload, headers=headers)
                try:
                    response = urllib.request.urlopen(request, timeout=5)
                except urllib.error.HTTPError as e:
                    response = e
                with response:
                    content = response.read()
                    return response.status, json.loads(content), len(content)

            try:
                for _ in range(100):
                    try:
                        status, initial, initial_bytes = req('view=bootstrap', auth=False)
                        break
                    except urllib.error.URLError:
                        time.sleep(.02)
                else:
                    raise RuntimeError('server start failed')
                check('read-only initial endpoint returns an old card without operator login', status==200 and len(initial['result']['cards'])==1)
                check('initial source date and previous-origin state never claim new analysis or saving', initial['result']['header']['analysis_as_of']==data['analysis']['as_of'] and initial['result']['header']['new_analysis_complete'] is False and initial['result']['header']['analysis_origin']=='previous_saved_result' and initial['result']['header']['save_status']=='not_requested')
                check('initial response is below 12 KiB without source JSON', initial_bytes<=12288 and 'source_json' not in initial and 'files' not in initial)
                check('initial read leaves shared data unchanged', (store/'mon_remote_cache.json').read_bytes()==raw)
                status, j, _ = req('view=bootstrap', {}, auth=False)
                check('initial endpoint rejects a write method', status==405 and j['error_code']=='MON_METHOD_INVALID')
                status, j, _ = req('view=bootstrap&at='+urllib.parse.quote(iso(-61)), auth=False)
                check('initial endpoint rejects a backdated display timestamp', status==400 and j['error_code']=='MON_BOOTSTRAP_TIME_INVALID')

                context = {'source_blob_sha': source_sha, 'rendered_at': iso(), 'countries': ['KR'], 'important_ids': []}
                status, j, _ = req('api=mon_bootstrap', {'context': context, 'source_json': source}, auth=False)
                check('fixed connector-input bootstrap works without NAS operator credentials', status==200 and j['result']['header']['source_blob_sha']==source_sha)

                request = {'run_id': 'expired-start', 'received_at': iso(-259)}
                status, j, _ = req('api=mon_run_start', request)
                expired = j['result']['run_context_id']
                check('expired start includes an immediate previous result', status==200 and len(j['result']['initial_result']['cards'])==1)
                check('expired start preserves missed deadline and refuses a new analysis claim', j['result']['timing']['deadline_missed'] and j['result']['next_action']=='display_secured_result' and not j['result']['fully_complete'])
                expired_context = dict(context, run_context_id=expired, rendered_at=iso())
                status, j, _ = req('api=mon_detail_prepare', {'context': expired_context, 'source_json': source})
                check('expired prepare returns a structured secured fallback', status==400 and j['error_code']=='MON_DECISION_DEADLINE' and len(j['fallback_result']['cards'])==1)
                check('deadline fallback writes nothing and keeps the old analysis time', j['write_required'] is False and j['remote_written'] is False and j['fallback_result']['header']['analysis_as_of']==data['analysis']['as_of'])

                status, j, _ = req('api=mon_run_start', {'run_id':'live-start','received_at':iso()})
                run = j['result']['run_context_id']
                check('live start includes the first result and phase-specific remaining budget', status==200 and j['result']['remaining_seconds']['collection']>0 and len(j['result']['initial_result']['cards'])==1)
                status, j, _ = req('api=mon_run_observe', {'run_context_id':run,'event':'final_display_reported','fields':{'new_analysis_complete':True,'final_available':True,'save_status':'saved'}})
                check('a display report cannot claim server-side analysis or publication completion', status==200 and not j['result']['new_analysis_complete'] and not j['result']['final_available'] and not j['result']['fully_complete'])

                context = dict(context, run_context_id=run, rendered_at=iso())
                status, j, _ = req('api=mon_detail_prepare', {'context':context,'source_json':source})
                if status!=200:
                    raise AssertionError('secured preparation returned '+str(status)+' '+str(j.get('error_code')))
                check('secured numerical preparation covers the full stored pool within the original budget', status==200 and j['result']['candidate_count']==len(data['watchlist']['symbols']))
                prepared = j['result']
                decision = {'view_context':context,'state_token':prepared['state_token'],'prepared_fingerprint':prepared['prepared_fingerprint'],'fixed_as_of':context['rendered_at'],
                            'selected':[{'symbol_id':data['watchlist']['symbols'][0]['symbol_id'],'reason':'synthetic recovered decision'}], 'references':[], 'live_observations':[]}
                status, j, _ = req('api=mon_detail_finalize', {'decision':decision,'source_json':source,'response_mode':'inline'})
                check('inline finalization marks new analysis independently from remote saving', status==200 and j['result']['new_analysis_complete'] and len(j['result']['files'])==2)
                status, j, _ = req('api=mon_run_status', {'run_context_id':run})
                check('completed analysis without publication is still not fully complete', status==200 and j['result']['new_analysis_complete'] and j['result']['save_status']=='not_requested' and not j['result']['fully_complete'])
                check('all recovery operations preserve production input cache', (store/'mon_remote_cache.json').read_bytes()==raw)
            finally:
                server.terminate()
                server.wait(timeout=5)
        result = {'suite':'MON recovery HTTP 1.6.1','duration_seconds':time.monotonic()-started,'pass':sum(c['pass'] for c in cases),'fail':sum(not c['pass'] for c in cases),
                  'cases':cases,'initial_response_bytes':initial_bytes,'external_requests':0,'daemon_starts':0,'orders':0}
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0 if result['fail']==0 else 1

if __name__=='__main__':
    raise SystemExit(main())

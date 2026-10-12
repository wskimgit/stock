#!/usr/bin/env python3
"""New trading-only recovery through real HTTP. Synthetic fixtures, no network providers."""
import json, os, pathlib, shutil, socket, subprocess, time, urllib.request, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('MON_TEST_PHP',shutil.which('php') or 'php')
CMD=[PHP,'-d','memory_limit=96M']; started=time.monotonic(); cases=[]
def check(name,condition,detail=''):
    cases.append({'case':name,'pass':bool(condition),**({'error':detail} if not condition else {})})
meta=json.loads(subprocess.check_output(CMD+[str(ROOT/'tests/mon_recovery_http_fixture.php')],text=True))
state=pathlib.Path(meta['state_dir'])
with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
env={k:v for k,v in os.environ.items() if k in ['PATH','LANG','LC_ALL','TMPDIR']}; env.update(MON_STATE_DIR=str(state),MON_ENV_FILE=str(state/'fixture.env'))
log=open(state/'http-server.log','w'); server=subprocess.Popen(CMD+['-S',f'127.0.0.1:{port}','-t',str(ROOT)],env=env,stdout=log,stderr=log)
def request(api,payload):
    req=urllib.request.Request(f'http://127.0.0.1:{port}/mon.php?api={api}',data=json.dumps(payload).encode(),headers={'Accept':'application/json','Content-Type':'application/json','X-Mon-Operator-Token':'synthetic-gate-operator-token-123456'},method='POST')
    try:
        with urllib.request.urlopen(req,timeout=15) as response: return response.status,json.load(response)
    except urllib.error.HTTPError as error: return error.code,json.loads(error.read())
try:
    for _ in range(60):
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2): break
        except OSError: time.sleep(.05)
    status,body=request('mon_run_status',{'run_context_id':meta['run_id']}); run=body.get('result',{})
    check('HTTP detects expired trading without discarding a same-day issuer proof',status==200 and not run.get('fully_complete') and 'issuer_review' not in [x.get('kind') for x in run.get('completion',{}).get('pending_jobs',[])],repr(body)[:600])
    for mode in ['stale','missing']:
        payload=json.loads(subprocess.check_output(CMD+[str(ROOT/'tests/mon_recovery_http_patch_fixture.php'),str(state/'http-source.json'),meta['run_id'],mode],text=True))
        status,body=request('mon_run_recover',payload)
        check('HTTP rejects '+mode+' price paired with verified trading',status==400 and body.get('error_code')=='MON_TRADING_OBSERVATION_REQUIRED',repr(body)[:600])
        status,body=request('mon_run_status',{'run_context_id':meta['run_id']})
        check('HTTP rejection preserves the original artifact '+mode,body.get('result',{}).get('artifact_id')==meta['artifact_id'])
    payload=json.loads(subprocess.check_output(CMD+[str(ROOT/'tests/mon_recovery_http_patch_fixture.php'),str(state/'http-source.json'),meta['run_id'],'valid'],text=True))
    status,body=request('mon_run_recover',payload); result=body.get('result',{}); run=result.get('run',{})
    check('HTTP accepts fresh trading-only evidence in the same execution',status==200 and result.get('status')=='recovery_confirmation_prepared' and run.get('run_context_id')==meta['run_id'] and run.get('timing',{}).get('received_at')==meta['received_at'],repr(body)[:600])
    files=result.get('files',[]); data=json.loads(files[0]['content']) if files else {}; import base64,gzip
    packed=data.get('analysis',{}).get('input_snapshot',{}).get('lossless_payload',{}); full=json.loads(gzip.decompress(base64.b64decode(packed['data']))) if packed else {}
    review=full.get('final_results_details',{}).get(meta['symbol_id'],{}).get('risk_review',{})
    check('HTTP preserves issuer time scope and evidence while refreshing only trading',all(review.get(k)==meta['issuer_review'].get(k) for k in ['status','checked_at','scope','evidence_ids']) and review.get('trading_checked_at')==payload['decision_patch']['fixed_as_of'],repr(review)[:600])
    pending=run.get('completion',{}).get('pending_jobs',[])
    check('HTTP repaired evidence still requires verified publication',not run.get('fully_complete') and not run.get('completion',{}).get('publication_verified') and any(x.get('kind')=='publication' for x in pending) and run.get('completion',{}).get('outcomes',[{}])[0].get('state')=='ENTRY_CONFIRMED',repr(run.get('completion'))[:1600])
    check('HTTP trading-only recovery preserves a separate identity quota blocker',any(x.get('kind')=='new_identity_quota' for x in pending))
    status,body=request('mon_run_finish',{'run_context_id':meta['run_id'],'reason':'finished','unfinished':[]})
    check('HTTP cannot finish an unpublished recovery revision',status==200 and not body.get('result',{}).get('finish_accepted') and not body.get('result',{}).get('run',{}).get('fully_complete'),repr(body)[:600])
finally:
    server.terminate()
    try: server.wait(timeout=5)
    except subprocess.TimeoutExpired: server.kill();server.wait(timeout=5)
    log.close();shutil.rmtree(state)
failed=sum(not c['pass'] for c in cases); result={'code':'1.8.6','tests':len(cases),'passed':len(cases)-failed,'failed':failed,'wall_seconds':time.monotonic()-started,'server':'PHP '+meta['php_version']+' localhost','memory_limit':'96M','external_requests':0,'orders':0,'cases':cases}
(ROOT/'tests/mon_recovery_http_tests.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps(result,ensure_ascii=False,indent=2));raise SystemExit(1 if failed else 0)

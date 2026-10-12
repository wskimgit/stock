#!/usr/bin/env python3
"""New completion endpoints only; isolated PHP 7.4 server and synthetic protected state."""
import json, os, pathlib, shutil, socket, subprocess, time, urllib.request, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('MON_TEST_PHP',shutil.which('php') or 'php')
started=time.monotonic(); cases=[]
def check(name, condition, detail=''):
    cases.append({'case':name,'pass':bool(condition),**({'error':detail} if not condition else {})})
meta=json.loads(subprocess.check_output([PHP,str(ROOT/'tests/mon_completion_gate_http_fixture.php')],text=True))
state=pathlib.Path(meta['state_dir'])
with socket.socket() as s: s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
env={k:v for k,v in os.environ.items() if k in ['PATH','LANG','LC_ALL','TMPDIR']}
env.update(MON_STATE_DIR=str(state),MON_ENV_FILE=str(state/'fixture.env'))
log=open(state/'http-server.log','w')
server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT)],env=env,stdout=log,stderr=log)
base=f'http://127.0.0.1:{port}/mon.php'
def request(api=None,payload=None,view=None):
    url=base+('?'+'api='+api if api else '?view='+view)
    headers={'Accept':'application/json','X-Mon-Operator-Token':'synthetic-gate-operator-token-123456'}
    body=None
    if payload is not None: body=json.dumps(payload,ensure_ascii=False).encode();headers['Content-Type']='application/json'
    req=urllib.request.Request(url,data=body,headers=headers,method='POST' if body is not None else 'GET')
    try:
        with urllib.request.urlopen(req,timeout=15) as r: return r.status,json.load(r)
    except urllib.error.HTTPError as e: return e.code,json.loads(e.read())
try:
    for _ in range(60):
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2): break
        except OSError: time.sleep(.05)
    status,body=request(view='status')
    pub=body.get('data',{}).get('final_publication',{})
    check('status revalidates a cached false completion claim',status==200 and pub.get('save_status')=='saved' and pub.get('fully_complete') is False,repr(body)[:500])
    status,body=request('mon_run_status',{'run_context_id':meta['run_id']})
    run=body.get('result',{})
    check('authenticated run status returns a concrete recovery job',status==200 and not run.get('fully_complete') and run.get('next_required_job',{}).get('kind')=='issuer_review',repr(body)[:500])
    status,body=request('mon_run_finish',{'run_context_id':meta['run_id'],'reason':'finished','unfinished':[],'export':True})
    result=body.get('result',{})
    check('finish refuses completion and checkpoints rather than discarding work',status==200 and result.get('finish_accepted') is False and result.get('code')=='MON_COMPLETION_PENDING' and result.get('run',{}).get('finished_at') is None,repr(body)[:500])
    check('new protected statistics preserve pending required checks',result.get('statistics_snapshot',{}).get('samples',[{}])[0].get('required_checks_pending',0)>0 and result.get('statistics_snapshot',{}).get('samples',[{}])[0].get('fully_complete') is False)
    status,body=request('mon_run_observe',{'run_context_id':meta['run_id'],'event':'final_display_reported','fields':{'fully_complete':True}})
    check('final-complete display is rejected over actual HTTP',status==409 and body.get('error_code')=='MON_COMPLETION_PENDING',repr(body)[:500])
    status,body=request('mon_run_recover',{'run_context_id':meta['run_id'],'action':'confirm','publish_mode':'invalid'})
    check('invalid recovery mode is refused before revision mutation',status==400 and body.get('error_code')=='MON_PUBLICATION_MODE_INVALID',repr(body)[:500])
    status,body=request('mon_run_status',{'run_context_id':meta['run_id']})
    check('a refused recovery preserves the original artifact and receipt clock',body.get('result',{}).get('artifact_id')==meta['artifact_id'] and body.get('result',{}).get('timing',{}).get('received_at')==meta['received_at'])
    payload=json.loads(subprocess.check_output([PHP,str(ROOT/'tests/mon_completion_gate_patch_fixture.php'),str(state/'http-source.json'),meta['run_id']],text=True))
    status,body=request('mon_run_recover',payload)
    result=body.get('result',{});run=result.get('run',{})
    check('independent supplemental proof creates a new revision in the same run',status==200 and result.get('status')=='recovery_confirmation_prepared' and result.get('manifest',{}).get('artifact_id')!=meta['artifact_id'] and run.get('timing',{}).get('received_at')==meta['received_at'],repr(body)[:700])
    check('repaired evidence still waits for actual remote publication',run.get('fully_complete') is False and run.get('next_action')=='continue_publication' and run.get('completion',{}).get('required_checks_pending')==1,repr(run.get('completion'))[:500])
    status,body=request(view='status');pub=body.get('data',{}).get('final_publication',{})
    check('status tracks the active recovery revision rather than an old saved draft',status==200 and pub.get('fully_complete') is False and pub.get('completion',{}).get('required_checks_pending')==1,repr(pub)[:500])
    for mode in ['manifest','inline']:
        status,body=request('mon_run_start',{'run_id':'http-no-change-'+mode,'mode':'auto'})
        new_id=body.get('result',{}).get('run_context_id')
        packet=json.loads(subprocess.check_output([PHP,str(ROOT/'tests/mon_completion_gate_finalize_fixture.php'),str(state/'http-source.json'),new_id,mode],text=True))
        check('fixture reaches the real native no-change path '+mode,packet.get('native_status')=='no_change',repr(packet.get('native_status')))
        status,body=request('mon_detail_finalize',packet['request']);result=body.get('result',{})
        check('no-change '+mode+' requires a server artifact and publication readback',status==200 and result.get('status')=='verification_pending' and result.get('manifest',{}).get('artifact_id') and result.get('save_status')=='not_requested',repr(body)[:700])
        status,body=request('mon_run_finish',{'run_context_id':new_id,'reason':'finished','unfinished':[]})
        check('no-change '+mode+' cannot bypass required evidence and remote proof',status==200 and body.get('result',{}).get('finish_accepted') is False and body.get('result',{}).get('run',{}).get('fully_complete') is False,repr(body)[:500])
finally:
    server.terminate()
    try: server.wait(timeout=5)
    except subprocess.TimeoutExpired: server.kill();server.wait(timeout=5)
    log.close();shutil.rmtree(state)
failed=sum(not c['pass'] for c in cases)
result={'version':subprocess.check_output([PHP,'-r','require $argv[1];echo MON_VERSION;',str(ROOT/'mon.php')],text=True),'tests':len(cases),'passed':len(cases)-failed,'failed':failed,'wall_seconds':round(time.monotonic()-started,6),'server':'PHP '+meta['php_version']+' localhost','external_requests':0,'orders':0,'cases':cases}
print(json.dumps(result,ensure_ascii=False,indent=2))
raise SystemExit(1 if failed else 0)

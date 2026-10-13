#!/usr/bin/env python3
"""Real local HTTP batch contract and operator authorization; synthetic files only."""
import base64,hashlib,json,os,pathlib,shutil,socket,subprocess,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('MON_TEST_PHP',shutil.which('php') or 'php');CMD=[PHP,'-d','memory_limit=96M'];started=time.monotonic();cases=[]
meta=json.loads(subprocess.check_output(CMD+[str(ROOT/'tests/mon_performance_http_fixture.php')],text=True));state=pathlib.Path(meta['state_dir'])
with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
env={k:v for k,v in os.environ.items() if k in ['PATH','LANG','LC_ALL','TMPDIR']};env.update(MON_STATE_DIR=str(state),MON_ENV_FILE=str(state/'fixture.env'));log=open(state/'http.log','w');server=subprocess.Popen(CMD+['-S',f'127.0.0.1:{port}','-t',str(ROOT)],env=env,stdout=log,stderr=log)
def check(name,condition,detail=''):cases.append({'case':name,'pass':bool(condition),**({'error':detail} if not condition else {})})
def request(body=None,authorized=True):
    headers={'Accept':'application/json'}
    if authorized:headers['X-Mon-Operator-Token']='synthetic-performance-operator-token-123456'
    if body is not None:headers['Content-Type']='application/json'
    url=f'http://127.0.0.1:{port}/mon.php?'+('api=mon_artifact_read' if body is not None else 'view=bootstrap');req=urllib.request.Request(url,data=json.dumps(body).encode() if body is not None else None,headers=headers)
    try:
        with urllib.request.urlopen(req,timeout=10) as r:return r.status,json.load(r)
    except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
try:
    for _ in range(60):
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2):break
        except OSError:time.sleep(.05)
    status,body=request();check('bootstrap advertises the installed batch capability',status==200 and body.get('processor_version')=='1.8.7' and body.get('capabilities',{}).get('artifact_chunk_batch')=='MON-CHUNKS-1.0')
    one={'artifact_id':meta['artifact_id'],'path':'mon_data.json','offset':0,'length':65536};status,body=request(one);single=body.get('result',{});check('single chunk HTTP contract remains intact',status==200 and single.get('chunk_bytes')==65536)
    ranges=[{'path':'mon_data.json','offset':i*65536,'length':65536} for i in range(8)];status,body=request({'artifact_id':meta['artifact_id'],'ranges':ranges});batch=body.get('result',{});chunks=batch.get('chunks',[])
    check('HTTP accepts eight bounded chunks in one protected read',status==200 and len(chunks)==8 and batch.get('chunk_bytes')==524288 and batch.get('artifact_validation_count')==1)
    check('batch first chunk exactly matches the original endpoint',chunks and chunks[0]==single)
    check('every returned chunk has valid bytes and SHA',len(chunks)==8 and all(hashlib.sha256(base64.b64decode(c['data'])).hexdigest()==c['chunk_sha256'] and len(base64.b64decode(c['data']))==c['chunk_bytes'] for c in chunks))
    status,body=request({'artifact_id':meta['artifact_id'],'ranges':ranges},False);check('batch preserves operator authorization',status==403 and 'result' not in body)
    for label,value in [('nine',ranges+[ranges[0]]),('oversize',[{'path':'mon_data.json','offset':0,'length':65537}]),('private_path',[{'path':'broker_config.local.php'}])]:
        status,body=request({'artifact_id':meta['artifact_id'],'ranges':value});check('HTTP rejects '+label,status==400 and body.get('error_code')=='MON_ARTIFACT_RANGE_INVALID',repr(body)[:300])
finally:
    server.terminate()
    try:server.wait(timeout=5)
    except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)
    log.close();shutil.rmtree(state)
failed=sum(not c['pass'] for c in cases);result={'code':'1.8.7','php':meta['php'],'memory_limit':'96M','tests':len(cases),'passed':len(cases)-failed,'failed':failed,'seconds':time.monotonic()-started,'external_requests':0,'orders':0,'cases':cases};(ROOT/'tests/mon_performance_http_tests.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps(result,ensure_ascii=False,indent=2));raise SystemExit(bool(failed))

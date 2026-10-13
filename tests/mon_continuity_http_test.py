import json,os,pathlib,shutil,socket,subprocess,time,urllib.request,urllib.error,hashlib
ROOT=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ['MON_TEST_PHP'];CMD=[PHP,'-d','memory_limit=96M'];cases=[];begin=time.monotonic()
meta=json.loads(subprocess.check_output(CMD+[str(ROOT/'tests/mon_continuity_http_fixture.php')],text=True));state=pathlib.Path(meta['state_dir'])
with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
env={k:v for k,v in os.environ.items() if k in ['PATH','LANG','TMPDIR']};env.update(MON_STATE_DIR=str(state),MON_ENV_FILE=str(state/'fixture.env'));log=open(state/'http.log','w');server=subprocess.Popen(CMD+['-S',f'127.0.0.1:{port}','-t',str(ROOT)],env=env,stdout=log,stderr=log)
def check(name,value):cases.append({'case':name,'result':'PASS' if value else 'FAIL'})
def req(query,method='GET'):
 r=urllib.request.Request(f'http://127.0.0.1:{port}/mon.php?{query}',method=method,headers={'Accept':'application/json'})
 try:
  with urllib.request.urlopen(r,timeout=10) as resp:return resp.status,json.load(resp)
 except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
try:
 for _ in range(50):
  try:
   with socket.create_connection(('127.0.0.1',port),timeout=.2):break
  except OSError:time.sleep(.05)
 status,b=req('view=continuity');r=b.get('result',{});check('read-only continuity endpoint returns saved result',status==200 and r.get('report_complete') and r.get('revision')==meta['revision']);check('saved status never pretends final recommendation is complete',not r.get('analysis_complete') and not r.get('overall_complete'))
 count=len(r['pending_jobs']);cursor=r['page']['next_cursor'];pages=1
 while cursor is not None:
  status,b=req(f'view=continuity&cursor={cursor}&since={meta["revision"]}');r=b['result'];count+=len(r['pending_jobs']);cursor=r['page']['next_cursor'];pages+=1
 check('every repair task survives real HTTP pagination',count==meta['jobs'] and pages>1)
 status,b=req('view=continuity&cursor=8&since='+'b'*64);check('changed revision refuses stale pagination',status==400 and b.get('error_code')=='MON_CONTINUITY_CURSOR_INVALID')
 status,b=req('view=continuity&cursor=-1');check('invalid cursor is rejected',status==400)
 status,b=req('view=continuity','POST');check('status endpoint cannot mutate or start daemon',status==405)
 status,b=req('view=bootstrap');check('bootstrap includes continuity capability and summary',status==200 and b.get('capabilities',{}).get('continuity')=='MON-CONTINUITY-1.0' and b.get('continuity',{}).get('revision')==meta['revision'])
 status,b=req('view=status');check('web status includes same durable analysis handoff',status==200 and b.get('data',{}).get('continuity',{}).get('next_analysis_api')=='mon_detail_prepare')
 check('read-only GETs preserve journal bytes and revision',json.loads((state/'mon_continuity.php').read_text().split('\n',1)[1])['revision']==meta['revision'])
finally:
 server.terminate();server.wait(timeout=5);log.close();shutil.rmtree(state)
failed=sum(c['result']=='FAIL' for c in cases);out={'code':'1.8.8','php':meta['php'],'memory_limit':'96M','source_sha256':hashlib.sha256((ROOT/'mon.php').read_bytes()).hexdigest(),'tests':len(cases),'passed':len(cases)-failed,'failed':failed,'seconds':time.monotonic()-begin,'cases':cases,'external_requests':0,'orders':0};(ROOT/'tests/mon_continuity_http_tests.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n');print(json.dumps(out,ensure_ascii=False,indent=2));raise SystemExit(bool(failed))

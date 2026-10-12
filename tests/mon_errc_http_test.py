"""Real local PHP HTTP; no daemon start, market request, order or external write."""
import hashlib,json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.error,urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('MON_PHP_BINARY','php')
cases=[]
def case(name,fn):
 try:fn();cases.append({'case':name,'pass':True})
 except Exception as e:cases.append({'case':name,'pass':False,'error':str(e)})
def check(v,msg='assertion failed'):
 if not v:raise AssertionError(msg)
def git_sha(raw):
 b=raw.encode();return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
with tempfile.TemporaryDirectory(prefix='mon-errc-http185-') as tmp:
 folder=pathlib.Path(tmp);web=folder/'web';web.mkdir();state=folder/'state';state.mkdir()
 shutil.copy2(ROOT/'mon.php',web/'mon.php')
 fixture=subprocess.check_output([PHP,'-r',f"require '{ROOT}/tests/mon_reinforcement_fixture.php';echo MonRun::json(reinforcement_fixture(1));"],text=True)
 (state/'mon_remote_cache.json').write_text(fixture)
 env=dict(os.environ,MON_STATE_DIR=str(state),MON_OPERATOR_TOKEN='fixture-operator-185-32-character-token',GITHUB_TOKEN='',KIS_APP_KEY='',KIS_APP_SECRET='',MON_ENV_FILE='')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(folder/'server.log','w');server=subprocess.Popen([PHP,'-d','memory_limit=96M','-d','error_reporting=32767','-S',f'127.0.0.1:{port}','-t',str(web)],env=env,stdout=log,stderr=log)
 url=f'http://127.0.0.1:{port}/mon.php'
 def request(path='',body=None,auth=True):
  headers={'Accept':'application/json'}
  if body is not None:headers['Content-Type']='application/json'
  if auth:headers['X-Mon-Operator-Token']=env['MON_OPERATOR_TOKEN']
  req=urllib.request.Request(url+path,headers=headers,data=None if body is None else json.dumps(body,ensure_ascii=False).encode())
  try:
   with urllib.request.urlopen(req,timeout=15) as response:return response.status,json.loads(response.read())
  except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
 context={};run={};compact={}
 try:
  end=time.monotonic()+8
  while True:
   try:request('?view=status');break
   except OSError:
    if time.monotonic()>end:raise
    time.sleep(.05)
  def bootstrap():
   status,r=request('?view=bootstrap');check(status==200 and r['ok'],f"bootstrap {status}: {r.get('error_code')}")
   check(r['capabilities']['detail_codec']=='MON-DETAIL-RAWREF-2.0');check(r['nas_state']['running'] is False,'GET launched a daemon')
  case('A20 real bootstrap advertises codecs and actual stopped health without launching a worker',bootstrap)
  def start():
   status,r=request('?api=mon_run_start',{'run_id':'http-errc185','operation_policy':{'scope':'analysis','finish_owner':'execution','require_statistics_publication':True}})
   check(status==200 and r['ok']);run.update(r['result']);context.update({'source_blob_sha':git_sha(fixture),'rendered_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'countries':['KR'],'important_ids':[],'run_context_id':run['run_context_id']})
   check(run['operation']['overall_complete'] is False)
  case('A19 operation scope is created through authenticated HTTP and remains protected',start)
  def begin():
   status,r=request('?api=mon_run_report',{'run_context_id':run['run_context_id'],'report':{'phase':'begin','operation_id':'http-source','operation':'external/source'}})
   check(status==200 and any(x['kind']=='telemetry_flush' for x in r['result']['operation']['business_pending_jobs']))
  case('A15 actual HTTP begin report creates a durable external ACK requirement',begin)
  def finish():
   status,r=request('?api=mon_run_finish',{'run_context_id':run['run_context_id'],'unfinished':[],'export':True})
   check(status==200 and not r['result']['finish_accepted'] and r['result']['run']['finished_at'] is None)
   check(r['result']['run']['run_context_id']==run['run_context_id'])
  case('A19 HTTP empty unfinished list cannot complete the required work',finish)
  def report():
   body={'run_context_id':run['run_context_id'],'report':{'phase':'end','operation_id':'http-source','operation':'external/source','seconds':None,'timing_unmeasured':True,'success':False,'code':'HTTP_429','units':1}}
   status,r=request('?api=mon_run_report',body);check(status==200 and not r['result']['operation']['business_pending_jobs'])
  case('A15 HTTP accepts truthful unmeasured timing without a fabricated zero duration',report)
  def storage():
   status,r=request('?api=mon_storage_compact',{'run_context_id':run['run_context_id'],'source_json':fixture,'context':context})
   check(status==200 and r['ok'],r.get('error_code','storage failed'));compact.update(r['result']);check(compact['storage']['collection_reserve_bytes']>=655360)
  case('A10 authenticated HTTP exposes the same lossless compaction and reserve check',storage)
  def archive():
   data=compact['files'][0]['content'];c=dict(context,source_blob_sha=git_sha(data))
   status,r=request('?api=mon_archive_page',{'run_context_id':run['run_context_id'],'source_json':data,'context':c})
   check(status==200 and r['result']['complete'] and not r['result']['entries'])
  case('A08 HTTP archive paging handles an empty archive without invented records',archive)
  def authorization():
   for api in ['mon_operation_nas_check','mon_operation_verify_statistics','mon_storage_compact','mon_archive_lookup']:
    status,r=request('?api='+api,{'run_context_id':run['run_context_id']},False);check(status in [401,403] and r.get('error_code')=='MON_OPERATOR_AUTH_REQUIRED',str((api,status,r.get('error_code'))))
  case('A19 all new controls enforce operator authentication before any service call',authorization)
  def pending_stats():
   status,r=request('?api=mon_operation_verify_statistics',{'run_context_id':run['run_context_id']})
   check(status in [400,409] and r['error_code']=='MON_BUSINESS_COMPLETION_REQUIRED')
  case('A16 HTTP statistics verification cannot run before the business revision exists',pending_stats)
 finally:
  server.terminate();server.wait(timeout=8);log.close()
 print(json.dumps({'cases_total':len(cases),'passed':sum(x['pass'] for x in cases),'failed':sum(not x['pass'] for x in cases),'real_local_HTTP':True,'external_API_calls':0,'daemon_started':False,'cases':cases},ensure_ascii=False,indent=2))
 raise SystemExit(1 if any(not x['pass'] for x in cases) else 0)

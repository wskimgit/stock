'use strict';
const fs=require('fs'),path=require('path'),vm=require('vm'),{spawnSync}=require('child_process');
const php=process.env.MON_PHP_BINARY||'php',cases=[],started=performance.now();
const initial={version:'1.8.1',running:true,stopping:false,heartbeat_fresh:true,monitoring_active:false,readiness:'off_session',label:'장외 대기',message:'다음 거래시간 대기',watchlist_loaded:true,watched:3,fresh_quotes:0,connection:{github:true,kis:true},last_mirrored_at:null,
 detail_progress:{snapshot_token:'original',counts:{requests:3,done:1,pending:2,retry_wait:0,previous:1,invalid:0},cards:[{name:'검증 종목',state:'done',data_status:'previous_verified'}],page:{next_cursor:1}}};
const phpSource='require '+JSON.stringify(path.join(__dirname,'..','mon.php'))+';mon_web_html(json_decode('+JSON.stringify(JSON.stringify(initial))+',true),"",true,"");';
const rendered=spawnSync(php,['-n','-r',phpSource],{encoding:'utf8'});
if(rendered.status!==0)throw new Error(rendered.stderr);
const script=rendered.stdout.match(/<script>([\s\S]*?)<\/script>/)[1];
// Only expose private closures in the test VM. Execute the actual PHP-rendered production script.
const instrumented=script.replace(/\}\)\(\);\s*$/,'globalThis.monTest={refresh:refresh,read:read,draw:draw,state:function(){return {recovery:Object.assign({},recovery),readJob:!!readJob,busy:busy,mutationUnconfirmed:mutationUnconfirmed,detailToken:detailToken,detailCursor:detailCursor};}};})();');
function ok(value,why='assertion failed'){if(!value)throw new Error(why);}
async function drain(){for(let i=0;i<12;i++)await Promise.resolve();}
function harness(options={}){
 let now=1700000000000,id=0,active=0,maxActive=0;const timers=new Map(),elements={},docEvents={},calls=[],storage=options.storage||new Map();
 function elem(name){if(!elements[name])elements[name]={id:name,textContent:'',className:'',disabled:false,hidden:false,value:'',children:[],events:{},addEventListener(type,fn){this.events[type]=fn;},replaceChildren(){this.children=[];},appendChild(c){this.children.push(c);}};return elements[name];}
 const forms=['start','stop','save_settings'].map(action=>({entries:[['action',action]],events:{},addEventListener(type,fn){this.events[type]=fn;},querySelector(){return elem(action==='save_settings'?'save':action);}}));
 class ClockDate extends Date{constructor(...args){super(...(args.length?args:[now]));}static now(){return now;}}
 const doc={hidden:false,getElementById:elem,querySelectorAll:()=>forms,createElement:()=>({textContent:''}),addEventListener:(type,fn)=>{docEvents[type]=fn;}};
 const context={document:doc,location:{pathname:'/mon.php'},Date:ClockDate,URLSearchParams,AbortController,Promise,Object,Number,JSON,Error,
  FormData:class{constructor(form){return form.entries;}},
  sessionStorage:{getItem(key){if(options.storageThrows)throw new Error('denied');return storage.get(key)||null;},setItem(key,value){if(options.storageThrows)throw new Error('denied');storage.set(key,value);}},
  setTimeout(fn,ms){const key=++id;timers.set(key,{fn,at:now+Number(ms),ms:Number(ms)});return key;},clearTimeout(key){timers.delete(key);},
  setInterval(){throw new Error('overlapping interval polling is prohibited');},
  fetch(url,opts){active++;maxActive=Math.max(active,maxActive);let resolve,reject,done=false;const promise=new Promise((a,b)=>{resolve=a;reject=b;});
   const finish=(fn,value)=>{if(done)return;done=true;active--;fn(value);};
   const call={url,opts,method:opts.method||'GET',resolve(value){finish(resolve,value);},reject(error=new Error('offline')){finish(reject,error);},get done(){return done;}};calls.push(call);
   opts.signal.addEventListener('abort',()=>call.reject(Object.assign(new Error('aborted'),{name:'AbortError'})),{once:true});return promise;
  }};
 vm.runInNewContext(instrumented,context,{timeout:1000});
 async function advance(ms){const end=now+ms;let loops=0;while(true){const row=[...timers.entries()].filter(([,t])=>t.at<=end).sort((a,b)=>a[1].at-b[1].at)[0];if(!row)break;
   if(++loops>1000)throw new Error('busy polling');now=row[1].at;timers.delete(row[0]);row[1].fn();await drain();}now=end;await drain();}
 function response(status=200,data={ok:true,data:structuredClone(initial)},headers={}){return {ok:status>=200&&status<300,status,headers:{get(name){return Object.entries(headers).find(([key])=>key.toLowerCase()===name.toLowerCase())?.[1]||null;}},json:async()=>data};}
 return {context,doc,elem,forms,calls,storage,advance,response,state:()=>context.monTest.state(),now:()=>now,maxActive:()=>maxActive,
  async answer(status=200,data,headers){const c=calls.find(c=>!c.done);ok(c,'no pending request');c.resolve(response(status,data,headers));await drain();},
  async fail(){const c=calls.find(c=>!c.done);ok(c,'no pending request');c.reject();await drain();},
  async hidden(value){doc.hidden=value;docEvents.visibilitychange();await drain();},
  async click(name){const p=elem(name).events.click.call(elem(name));await drain();return p;},
  async submit(action){const form=forms.find(f=>f.entries[0][1]===action);const p=form.events.submit({preventDefault(){}});await drain();return {promise:p};}};
}
async function test(name,fn){try{await fn();cases.push({case:name,pass:true});}catch(e){cases.push({case:name,pass:false,error:e.message});}}
(async()=>{
 await test('slow status fetch never overlaps another status or detail request; polling begins after completion',async()=>{
  const h=harness();await h.advance(10000);ok(h.calls.length===1);await h.context.monTest.refresh();await h.context.monTest.refresh();await h.click('detail-more');await h.advance(5000);ok(h.calls.length===1&&h.maxActive()===1);await h.answer();await h.advance(9999);ok(h.calls.length===1);await h.advance(1);ok(h.calls.length===2&&h.maxActive()===1);ok(h.calls[0].opts.headers.Accept==='application/json'&&h.calls[0].opts.redirect==='error');
 });
 await test('20/40 second aborts and 30/60 second cooldowns grow, then success restores ten second polling',async()=>{
  const h=harness();await h.advance(10000);await h.advance(20000);ok(h.state().recovery.failures===1&&h.state().recovery.nextAt-h.now()===30000);ok(h.elem('state').textContent==='상태 확인 필요'&&h.elem('detail-cards').children.length===1);
  await h.advance(29999);ok(h.calls.length===1);await h.advance(1);await h.advance(40000);ok(h.state().recovery.failures===2&&h.state().recovery.nextAt-h.now()===60000);await h.advance(60000);await h.answer();ok(h.state().recovery.failures===0&&h.state().recovery.nextAt-h.now()===10000);
 });
 await test('seven failures keep retrying at 300 second intervals and eventually recover',async()=>{
  const h=harness();await h.advance(10000);const delays=[];for(let i=0;i<7;i++){await h.fail();delays.push((h.state().recovery.nextAt-h.now())/1000);await h.advance(delays[i]*1000);}ok(JSON.stringify(delays)===JSON.stringify([30,60,120,240,300,300,300]));await h.answer();ok(h.state().recovery.failures===0&&h.maxActive()===1);
 });
 await test('429 Retry-After seconds larger than the normal backoff are respected',async()=>{
  const h=harness();await h.advance(10000);await h.answer(429,{ok:false},{'Retry-After':'900'});ok(h.state().recovery.nextAt-h.now()===900000);await h.advance(899999);ok(h.calls.length===1);await h.advance(1);ok(h.calls.length===2);
 });
 await test('503 Retry-After HTTP date is respected',async()=>{
  const h=harness();await h.advance(10000);const date=new Date(h.now()+600000).toUTCString();await h.answer(503,{ok:false},{'Retry-After':date});ok(h.state().recovery.nextAt-h.now()===600000);
 });
 await test('hidden pages pause requests and showing the page preserves the remaining cooldown',async()=>{
  const h=harness();await h.advance(10000);await h.fail();await h.hidden(true);await h.advance(10000);await h.hidden(false);await h.advance(19999);ok(h.calls.length===1);await h.advance(1);ok(h.calls.length===2);
 });
 await test('showing a page after its cooldown initiates only one overdue read',async()=>{
  const h=harness();await h.advance(10000);await h.fail();await h.hidden(true);await h.advance(600000);ok(h.calls.length===1);await h.hidden(false);await h.advance(0);ok(h.calls.length===2&&h.maxActive()===1);
 });
 await test('detail pagination shares the read guard and appends only the original snapshot',async()=>{
  const h=harness();await h.advance(10000);await h.answer();
  const promise=h.context.monTest.read('detail');await drain();ok(h.calls.length===2&&h.calls.at(-1).url.includes('since=original&cursor=1'));await h.context.monTest.refresh();ok(h.maxActive()===1);
  await h.answer(200,{ok:true,progress:{snapshot_token:'original',cards:[{name:'두 번째',state:'pending'}],page:{next_cursor:null}}});await promise;ok(h.elem('detail-cards').children.length===2&&h.state().detailCursor===null);
 });
 await test('stale detail responses cannot append cards or overwrite a newer cursor',async()=>{
  const h=harness();await h.hidden(true);await h.advance(10000);h.doc.hidden=false;const promise=h.context.monTest.read('detail');await drain();
  const newer=structuredClone(initial);newer.detail_progress.snapshot_token='newer';newer.detail_progress.cards=[{name:'새 자료',state:'done'}];h.context.monTest.draw(newer);
  await h.answer(200,{ok:true,progress:{snapshot_token:'original',cards:[{name:'오래된 자료',state:'pending'}],page:{next_cursor:null}}});await promise;ok(h.elem('detail-cards').children.length===1&&h.elem('detail-cards').children[0].textContent.includes('새 자료')&&h.state().detailCursor===1);
 });
 await test('a detail outage preserves existing cards and cursor while entering recovery',async()=>{
  const h=harness();await h.hidden(true);await h.advance(10000);h.doc.hidden=false;const p=h.context.monTest.read('detail');await drain();await h.answer(503,{ok:false});await p;ok(h.state().detailCursor===1&&h.elem('detail-cards').children.length===1&&h.state().recovery.failures===1&&h.state().recovery.nextAt-h.now()===30000);
 });
 await test('an ambiguous POST is sent once; subsequent automatic recovery sends only status GETs',async()=>{
  const h=harness();h.context.monTest.draw({...structuredClone(initial),running:false});const action=await h.submit('start');ok(h.calls[0].method==='POST');await h.advance(20000);await action.promise;ok(h.state().mutationUnconfirmed&&h.elem('start').disabled);await h.advance(0);
  for(let i=0;i<5;i++){ok(h.calls.at(-1).method==='GET');await h.fail();await h.advance(h.state().recovery.nextAt-h.now());}await h.answer();ok(h.calls.filter(c=>c.method==='POST').length===1&&!h.state().mutationUnconfirmed&&h.maxActive()===1);
 });
 await test('manual POST cancels and finishes an active read before sending; cancellation is not a failed connection',async()=>{
  const h=harness();await h.advance(10000);const action=await h.submit('stop');ok(h.calls[0].done&&h.calls[1].method==='POST'&&h.state().recovery.failures===0&&h.maxActive()===1);await h.answer();await action.promise;await h.advance(0);ok(h.calls[2].method==='GET'&&h.maxActive()===1);
 });
 await test('401 waits for corrective action instead of repeatedly submitting unchanged credentials',async()=>{
  const h=harness();await h.advance(10000);await h.answer(401,{ok:false});await h.advance(600000);ok(h.calls.length===1&&h.state().recovery.needsAttention);await h.click('connection-retry');await h.advance(0);ok(h.calls.length===2);await h.answer();ok(!h.state().recovery.needsAttention);
 });
 await test('HTTP 200 login HTML is an invalid response, never a healthy monitor',async()=>{
  const h=harness();await h.advance(10000);h.calls[0].resolve({ok:true,status:200,headers:{get:()=>null},json:async()=>{throw new SyntaxError('HTML');}});await drain();ok(h.state().recovery.needsAttention&&h.elem('state').textContent==='상태 확인 필요');await h.advance(600000);ok(h.calls.length===1);
 });
 await test('session storage errors do not break rendering or recovery',async()=>{
  const h=harness({storageThrows:true});await h.advance(10000);await h.fail();await h.advance(30000);await h.answer();ok(h.state().recovery.failures===0&&h.calls.length===2);
 });
 await test('reopening the page retains the failure count and remaining cooldown',async()=>{
  const a=harness();await a.advance(10000);await a.fail();const b=harness({storage:a.storage});await b.advance(39999);ok(b.calls.length===0&&b.state().recovery.failures===1);await b.advance(1);ok(b.calls.length===1);
 });
 await test('very long Retry-After is split into timer chunks without a premature network retry',async()=>{
  const h=harness();await h.advance(10000);await h.answer(503,{ok:false},{'Retry-After':'3000000'});await h.advance(2147483647);ok(h.calls.length===1&&h.state().recovery.nextAt>h.now());await h.advance(3000000000-2147483647);ok(h.calls.length===2);
 });
 await test('the total timeout includes response-body decoding; late success cannot replace unverified state',async()=>{
  const h=harness();await h.advance(10000);let finish;h.calls[0].resolve({ok:true,status:200,headers:{get:()=>null},json:()=>new Promise(resolve=>{finish=resolve;})});await drain();await h.advance(20000);finish({ok:true,data:structuredClone(initial)});await drain();ok(h.state().recovery.failures===1&&h.elem('state').textContent==='상태 확인 필요'&&h.state().recovery.nextAt-h.now()===30000);
 });
 const failed=cases.filter(c=>!c.pass).length;
 console.log(JSON.stringify({processor_version:'1.8.1',scope:'actual PHP-rendered production JavaScript; simulated DOM/fetch/timers only; no live requests',cases_total:cases.length,passed:cases.length-failed,failed,test_seconds:Number(((performance.now()-started)/1000).toFixed(6)),cases},null,2));process.exitCode=failed?1:0;
})().catch(e=>{console.error(e);process.exitCode=1;});

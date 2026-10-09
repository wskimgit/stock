const fs=require('fs'),vm=require('vm');
const html=fs.readFileSync(process.argv[2],'utf8');
const script=html.match(/<script>([\s\S]*?)<\/script>/)[1];
const elements={},cases=[];
function check(name,value){cases.push({case:name,pass:!!value});if(!value)throw new Error(name);}
const element=id=>elements[id]||(elements[id]={textContent:'',className:'',disabled:false});
let refresh;
const context={document:{getElementById:element,querySelectorAll:()=>[]},setInterval:fn=>{refresh=fn;},fetch:async()=>{throw new Error('offline');},location:{pathname:'/mon.php'},Date,URLSearchParams,Object};
vm.runInNewContext(script,context);
check('fresh collecting snapshot lights the operational indicator',element('dot').className==='dot on');
(async()=>{
 await refresh();
 check('failed status request clears a previously green indicator',element('dot').className==='dot');
 check('failed status request is clearly labelled unverified',element('state').textContent==='상태 확인 필요');
 check('lost status does not claim that the process was stopped',element('stop').disabled===false);
 context.fetch=async()=>({json:async()=>({ok:false,message:'temporary failure'})});
 await refresh();check('server error response also leaves operational status unverified',element('dot').className==='dot');
 console.log(JSON.stringify({cases_total:cases.length,passed:cases.length,failed:0,cases}));
})().catch(e=>{console.error(e);process.exit(1);});

const fs = require('fs'), vm = require('vm');
const html = fs.readFileSync(process.argv[2], 'utf8');
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const elements = {}, cases = [];
function element(id) { return elements[id] || (elements[id] = { textContent: '', className: '', disabled: false, children: [], replaceChildren() { this.children = []; }, appendChild(c) { this.children.push(c); } }); }
function check(name, value) { cases.push({case:name, pass:!!value}); if (!value) throw new Error(name); }
let refresh;
const context = {document:{ getElementById:element, querySelectorAll:()=>[], createElement:()=>({textContent:''}) }, setInterval:fn=>{refresh=fn;},
    fetch:async()=>{throw new Error('offline');}, location:{pathname:'/mon.php'}, Date, URLSearchParams, Object};
vm.runInNewContext(script, context);
check('UI shows first completed result while two symbols wait', element('detail-progress').textContent.includes('완료 1/3') && element('detail-progress').textContent.includes('대기 2'));
check('UI distinguishes previous verified data and unsent publication', element('detail-progress').textContent.includes('이전자료 1') && element('detail-progress').textContent.includes('저장 대기'));
check('UI renders independent per-symbol rows', element('detail-cards').children.length===3 && element('detail-cards').children[0].textContent.includes('이전 검증자료'));
check('UI does not call collection completion a final decision', element('detail-progress').textContent.includes('ChatGPT 최종 판단 별도'));
(async()=>{
    await refresh();
    check('lost status is clearly unverified', element('state').textContent==='상태 확인 필요' && element('dot').className==='dot');
    check('existing result rows are retained without repeated rendering', element('detail-cards').children.length===3);
    console.log(JSON.stringify({cases_total:cases.length, passed:cases.length, failed:0, cases}));
})().catch(e=>{console.error(e);process.exit(1);});

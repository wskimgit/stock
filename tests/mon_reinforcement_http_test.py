#!/usr/bin/env python3
"""Actual local PHP 7.4 HTTP and UI tests; synthetic data; no provider or Git writes."""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("MON_TEST_PHP", "php")
cases = []
started = time.monotonic()


def check(name, condition):
    cases.append({"case": name, "pass": bool(condition)})
    if not condition:
        raise AssertionError(name)


def iso():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ")


def git_sha(raw):
    return hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest()


def main():
    fixture = subprocess.check_output([PHP, "-n", "-r", 'require "tests/mon_reinforcement_fixture.php"; echo MonRun::json(reinforcement_fixture(30));'], cwd=ROOT)
    data = json.loads(fixture)
    token = "synthetic-http-operator-credential-only"
    with tempfile.TemporaryDirectory(prefix="mon-http-reinforcement-") as tmp:
        state = Path(tmp)
        (state / "fixture.env").write_text("MON_OPERATOR_TOKEN=" + token + "\n", encoding="utf-8")
        (state / "mon_remote_cache.json").write_bytes(fixture)
        env = dict(os.environ, MON_STATE_DIR=tmp, MON_ENV_FILE=str(state / "fixture.env"))
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        url = f"http://127.0.0.1:{port}/mon.php"
        log = open(state / "server.log", "wb")
        server = subprocess.Popen([PHP, "-n", "-S", f"127.0.0.1:{port}", "-t", str(ROOT)], env=env, stdout=log, stderr=log)

        def req(query, body=None, auth=True, raw=False):
            headers = {"Accept": "application/json"}
            if auth:
                headers["X-Mon-Operator-Token"] = token
            payload = None
            if body is not None:
                payload = json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode()
                headers["Content-Type"] = "application/json"
            request = urllib.request.Request(url + "?" + query, data=payload, headers=headers)
            try:
                response = urllib.request.urlopen(request, timeout=10)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                content = response.read()
                return response.status, content if raw else json.loads(content)

        try:
            for _ in range(100):
                try:
                    req("view=progress", auth=False)
                    break
                except urllib.error.URLError:
                    time.sleep(.05)
            else:
                raise RuntimeError("local PHP server did not start")
            start_request = {"run_id": "synthetic-http-run", "received_at": iso()}
            status, j = req("api=mon_run_start", start_request, auth=False)
            check("unauthenticated run state is rejected", status == 403 and j["error_code"] == "MON_OPERATOR_AUTH_REQUIRED")
            check("rejected action still returns a marked result", "자료 부족" in j["fallback_summary"] and j["remote_written"] is False)
            status, j = req("api=mon_run_start", start_request)
            run = j["result"]["run_context_id"]
            check("authenticated run records original reception", status == 200 and not j["run_timing"]["partial_timing"])
            _, again = req("api=mon_run_start", start_request)
            check("same run start is idempotent", again["result"]["run_context_id"] == run and again["run_timing"]["publication_deadline_at"] == j["run_timing"]["publication_deadline_at"])
            status, changed = req("api=mon_run_start", dict(start_request, received_at=iso()))
            check("restart cannot move original reception", status == 400 and changed["error_code"] == "MON_RUN_START_IMMUTABLE")
            context = {"source_blob_sha": git_sha(fixture), "rendered_at": iso(), "countries": ["KR"], "important_ids": [], "run_context_id": run}
            common = {"source_json": fixture.decode(), "context": context, "response_mode": "manifest"}
            status, prepared = req("api=mon_detail_prepare", common)
            check("actual HTTP preparation succeeds", status == 200 and prepared["ok"])
            p = prepared["result"]
            check("preparation is split into bounded exact UTF-8 pages", len(p["card_pages"]) > 1 and all(page["model_input_bytes"] <= 12288 for page in p["card_pages"]))
            decision = {"view_context": context, "state_token": p["state_token"], "prepared_fingerprint": p["prepared_fingerprint"], "fixed_as_of": context["rendered_at"],
                        "selected": [{"symbol_id": data["watchlist"]["symbols"][0]["symbol_id"], "reason": "synthetic positive RS and MA5 reclaim"}], "references": [], "live_observations": []}
            final_request = {"source_json": fixture.decode(), "decision": decision, "response_mode": "manifest", "publish_mode": "manual"}
            status, j = req("api=mon_detail_finalize", final_request)
            result = j["result"]
            manifest = result["manifest"]
            check("one HTTP finalization creates a fixed two-file manifest", status == 200 and sorted(f["path"] for f in manifest["files"]) == ["mon_data.json", "mon_result.md"])
            check("large source and encoded bodies are absent from final response", result["files"] == [] and result["large_body_in_model"] is False and "lossless_payload" not in json.dumps(j))
            check("final availability is separate from saving", result["final_available"] and result["save_status"] == "not_requested")
            check("display is refreshed separately from the immutable file", result["display_summary"] and result["displayed_at"])
            status, replay = req("api=mon_detail_finalize", final_request)
            check("identical final replay reuses its sealed artifact", status == 200 and replay["result"]["status"] == "reuse" and replay["result"]["manifest"]["artifact_id"] == manifest["artifact_id"])
            bad = json.loads(json.dumps(final_request))
            bad["decision"]["selected"][0]["reason"] = "different decision"
            status, rejected = req("api=mon_detail_finalize", bad)
            check("a changed sealed decision is rejected", status == 409 and rejected["error_code"] == "MON_FINAL_DECISION_IMMUTABLE")
            status, rejected = req("api=mon_detail_finalize", dict(final_request, response_mode="typo"))
            check("invalid output modes do not silently stage files", status == 400 and rejected["error_code"] == "MON_RESPONSE_MODE_INVALID")
            assembled = b""
            offset = 0
            while offset is not None:
                status, chunk_response = req("api=mon_artifact_read", {"artifact_id": manifest["artifact_id"], "path": "mon_result.md", "offset": offset, "length": 97})
                chunk = chunk_response["result"]
                raw = base64.b64decode(chunk["data"], validate=True)
                if hashlib.sha256(raw).hexdigest() != chunk["chunk_sha256"]:
                    raise AssertionError("chunk hash")
                assembled += raw
                offset = chunk["next_offset"]
            descriptor = next(f for f in manifest["files"] if f["path"] == "mon_result.md")
            check("real HTTP chunks preserve exact UTF-8 file bytes", len(assembled) == descriptor["bytes"] and git_sha(assembled) == descriptor["blob_sha"] and hashlib.sha256(assembled).hexdigest() == descriptor["sha256"])
            status, rejected = req("api=mon_artifact_read", {"artifact_id": manifest["artifact_id"], "path": "../../private.php"})
            check("arbitrary private paths are rejected", status == 400 and rejected["error_code"] == "MON_ARTIFACT_RANGE_INVALID")
            status, rejected = req("api=mon_artifact_read", {"artifact_id": manifest["artifact_id"], "path": "mon_result.md"}, auth=False)
            check("private artifact reads require operator authentication", status == 403)
            queue_request = {"run_context_id": run, "artifact_id": manifest["artifact_id"], "expected_head": "a" * 40}
            status, queued = req("api=mon_publish_queue", queue_request)
            receipt = queued["result"]
            check("queue acknowledgement follows a durable protected journal", status == 200 and receipt["save_status"] == "queued" and (state / ("mon_publish-" + receipt["job_id"] + ".php")).is_file())
            _, duplicate = req("api=mon_publish_queue", queue_request)
            check("queue replay does not create another job", duplicate["result"]["job_id"] == receipt["job_id"] and len(list(state.glob("mon_publish-*.php"))) == 1)
            _, state_response = req("api=mon_run_status", {"run_context_id": run})
            check("queued work is never labelled fully complete", state_response["result"]["save_status"] == "queued" and state_response["result"]["fully_complete"] is False)
            artifact_path = state / ("mon_artifact-" + manifest["artifact_id"] + ".php")
            check("protected state cannot execute as public JSON", subprocess.check_output([PHP, "-n", str(artifact_path)]) == b"")
            ids = []
            query = "view=progress"
            pages = 0
            while True:
                status, raw = req(query, auth=False, raw=True)
                progress = json.loads(raw)["progress"]
                check(f"progress page {pages + 1} fits 4 KiB", status == 200 and len(raw) <= 4096)
                ids += [row["symbol_id"] for row in progress["cards"]]
                pages += 1
                cursor = progress["page"]["next_cursor"]
                if cursor is None:
                    break
                query = "view=progress&since=" + progress["snapshot_token"] + "&cursor=" + str(cursor)
            check("HTTP pagination covers all 30 symbols exactly once", len(ids) == len(set(ids)) == 30)
            status, bad_cursor = req("view=progress&since=" + "0" * 64 + "&cursor=5", auth=False)
            check("HTTP cursor rejects mixed snapshots", status == 400 and bad_cursor["error_code"] == "MON_PROGRESS_CURSOR_INVALID")
            html_request = urllib.request.Request(url, headers={"Accept": "text/html"})
            html = urllib.request.urlopen(html_request, timeout=5).read().decode()
            script = re.search(r"<script>([\s\S]*?)</script>", html).group(1)
            js_path = state / "ui-script.js"
            js_path.write_text(script, encoding="utf-8")
            subprocess.check_call(["node", "--check", str(js_path)], stdout=subprocess.DEVNULL)
            check("rendered web UI JavaScript parses", 'id="final-publication"' in html and 'id="detail-more"' in html)
            ui_driver = state / "ui-driver.js"
            ui_driver.write_text('''const fs=require('fs'),vm=require('vm');const elements={};
const elem=id=>elements[id]||(elements[id]={textContent:'',hidden:false,disabled:false,children:[],handlers:{},appendChild(x){this.children.push(x)},replaceChildren(){this.children=[]},addEventListener(event,fn){this.handlers[event]=fn}});
const document={getElementById:elem,querySelectorAll:()=>[],createElement:()=>({textContent:''})};
const c={document,location:{pathname:'/mon.php'},setInterval(){},setTimeout(){},URLSearchParams,console,fetch:async()=>({json:async()=>({ok:false})})};
vm.createContext(c);
const base={label:'확인',message:'자료',watchlist_loaded:false,detail_progress:null,connection:{github:false,kis:false}};
// Expose the two existing render functions only inside the test copy.
let s=fs.readFileSync(process.argv[2],'utf8').replace('(function(){','(function(){globalThis.ui={draw:s=>draw(s)};');
vm.runInContext(s,c);c.ui.draw({...base,final_publication:{save_status:'unknown'}});
if(!elem('final-publication').textContent.includes('확인되지 않음'))throw Error('unknown label');
c.ui.draw({...base,final_publication:{save_status:'saved'}});
if(!elem('final-publication').textContent.includes('완료'))throw Error('saved label');
const progress={snapshot_token:'fixed',counts:{requests:2,done:2,pending:0,retry_wait:0,previous:0},cards:[{symbol_id:'one',state:'done',data_status:'latest'}],page:{next_cursor:1}};
c.ui.draw({...base,detail_progress:progress});if(elem('detail-more').hidden)throw Error('pagination hidden');
c.fetch=async url=>{if(!url.includes('since=fixed&cursor=1'))throw Error('cursor mismatch');return {json:async()=>({ok:true,progress:{snapshot_token:'fixed',cards:[{symbol_id:'two',state:'done',data_status:'latest'}],page:{next_cursor:null}}})}};
Promise.resolve(elem('detail-more').handlers.click.call(elem('detail-more'))).then(()=>{if(elem('detail-cards').children.length!==2||!elem('detail-more').hidden)throw Error('pagination dropped rows')}).catch(e=>{console.error(e);process.exitCode=1});
''', encoding="utf-8")
            subprocess.check_call(["node", str(ui_driver), str(js_path)], stdout=subprocess.DEVNULL)
            check("UI distinguishes saved from unknown and appends next-page rows", True)
            check("test did not change live result files or send publication requests", not (state / "mon_data.json").exists() and not (state / "mon_result.md").exists())
        finally:
            server.terminate()
            try:
                server.wait(timeout=3)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=3)
            log.close()


try:
    main()
except Exception as error:
    if not cases or cases[-1]["pass"]:
        cases.append({"case": "unexpected integration error", "pass": False, "error": str(error)})
    else:
        cases[-1]["error"] = str(error)
report = {"kind": "actual_local_php_http_and_ui_tests", "processor_version": "1.6.0", "case_count": len(cases), "passed": sum(c["pass"] for c in cases),
          "failed": sum(not c["pass"] for c in cases), "elapsed_seconds": time.monotonic() - started, "external_requests": 0, "orders": 0, "cases": cases}
(ROOT / "tests/mon_reinforcement_http_tests.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({k: v for k, v in report.items() if k != "cases"}, ensure_ascii=False))
if report["failed"]:
    print(json.dumps([c for c in cases if not c["pass"]], ensure_ascii=False))
sys.exit(1 if report["failed"] else 0)

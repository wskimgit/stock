"""Completion-first API regression on real local PHP 7.4 HTTP; synthetic data only."""
from pathlib import Path
from datetime import datetime, timezone, timedelta
import base64
import hashlib
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("MON_TEST_PHP", "/workspace/scratch/2cf573c7ed8f/php74-reinforcement-build/sapi/cli/php")
TOKEN = "synthetic-completion-operator-token-0000"
cases = []
started = time.monotonic()


def check(name, condition):
    cases.append({"case": name, "pass": bool(condition)})
    if not condition:
        raise AssertionError(name)


def iso(offset=0):
    return (datetime.now(timezone.utc) + timedelta(seconds=offset)).strftime("%Y-%m-%dT%H:%M:%S.%fZ")


with tempfile.TemporaryDirectory(prefix="mon-completion-http-") as temp:
    root = Path(temp)
    web, state = root / "web", root / "state"
    web.mkdir()
    state.mkdir()
    shutil.copy2(ROOT / "mon.php", web / "mon.php")
    env = {k: v for k, v in os.environ.items() if not k.startswith(("MON_", "KIS_", "GITHUB_"))}
    env.update(MON_STATE_DIR=str(state), MON_OPERATOR_TOKEN=TOKEN)
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    with (root / "http.log").open("w") as log:
        server = subprocess.Popen([PHP, "-n", "-S", f"127.0.0.1:{port}", "-t", str(web)], env=env, stdout=log, stderr=log)

        def request(api, body=None, auth=True):
            req = urllib.request.Request(f"http://127.0.0.1:{port}/mon.php?api={api}", data=json.dumps(body or {}, ensure_ascii=False).encode(), method="POST")
            req.add_header("Content-Type", "application/json")
            if auth:
                req.add_header("X-Mon-Operator-Token", TOKEN)
            try:
                with urllib.request.urlopen(req, timeout=5) as response:
                    return response.status, json.loads(response.read())
            except urllib.error.HTTPError as exc:
                return exc.code, json.loads(exc.read())

        try:
            for _ in range(60):
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.02)
            status, out = request("mon_run_start", {"run_id": "forbidden", "received_at": iso(-700)}, auth=False)
            check("advisory mode still requires operator authentication", status == 403 and out["error_code"] == "MON_OPERATOR_AUTH_REQUIRED")

            snapshot = json.loads((ROOT / "tests/mon_completion_observed_fixture.json").read_text())
            # Freeze the two migration observations independently of changing live history;
            # relocate their timestamps so the real endpoint's thirty-day window is testable.
            newest = max(datetime.fromisoformat(row["finished_at"].replace("Z", "+00:00")) for row in snapshot["samples"])
            offset = datetime.now(timezone.utc) - timedelta(seconds=10) - newest
            for row in snapshot["samples"]:
                for key in ("received_at", "finished_at"):
                    row[key] = (datetime.fromisoformat(row[key].replace("Z", "+00:00")) + offset).strftime("%Y-%m-%dT%H:%M:%S.%fZ")
            snapshot = json.loads(subprocess.check_output([PHP, "-n", "-r", f"require '{ROOT}/mon.php'; echo MonRun::json(MonRunStatistics::seal(json_decode(stream_get_contents(STDIN))));"], input=json.dumps(snapshot).encode(), env=env))
            status, out = request("mon_run_statistics", {"statistics_snapshot": snapshot, "export": True})
            check("historical 183 and 290 second observations import without rewriting old outcomes", status == 200 and len(out["result"]["statistics_snapshot"]["samples"]) == 2)
            check("observed overtime recommends 350/360/370 advisory references without a total stop cap", out["result"]["next_policy"]["limits"] == {"collection": 350, "decision": 360, "publication": 370, "recovery": 370} and out["result"]["next_policy"]["maximum_total_seconds"] is None)

            received = iso(-700)
            status, out = request("mon_run_start", {"run_id": "http-completion", "received_at": received})
            result = out["result"]
            run_id = result["run_context_id"]
            frozen = result["adaptive_policy"]
            check("late start remains active rather than expired", status == 200 and not result["terminal"] and not result["stop_on_threshold"] and result["next_action"] == "continue_pending_work")
            check("receipt and exceeded references remain honest", result["timing"]["received_at"] == received and result["timing"]["elapsed_seconds"] >= 700 and result["timing"]["time_excess_seconds"]["publication"] >= 330)

            for index in range(105):
                report = {"operation_id": f"search-{index}", "operation": "external/search", "seconds": .01, "units": 1, "success": True}
                status, out = request("mon_run_report", {"run_context_id": run_id, "report": report})
                if status != 200:
                    raise AssertionError(f"report {index}: {out}")
            check("one hundred and five actual report requests do not trigger a report or search count stop", out["result"]["counts"]["search_batches"] == 105 and out["result"]["count_excess"]["search_batches"] == 103)
            status, out = request("mon_run_report", {"run_context_id": run_id, "report": report})
            check("duplicate report remains idempotent beyond the former hundred report ceiling", status == 200 and out["result"]["counts"]["search_batches"] == 105)
            status, out = request("mon_run_report", {"run_context_id": run_id, "report": {"operation_id": "long-time", "operation": "connector/github", "success": True, "seconds": 640.5}})
            check("duration above three hundred seconds is accepted and retained", status == 200 and out["result"]["operations"]["connector/github"]["seconds"] == [640.5])

            status, out = request("mon_run_finish", {"run_context_id": run_id, "reason": "deadline", "unfinished": [{"task": "DETAIL", "code": "HTTP_429"}], "export": True})
            checkpoint = out["result"]
            check("deadline finish is a nonterminal checkpoint", status == 200 and checkpoint["run"]["finished_at"] is None and checkpoint["run"]["statistics_status"] == "checkpoint_recorded")
            current = next(row for row in checkpoint["statistics_snapshot"]["samples"] if row["run_context_id"] == run_id)
            check("checkpoint statistics preserve elapsed time, overtime, excess count and pending state", not current["terminal"] and current["elapsed_seconds"] >= 700 and current["time_excess_seconds"]["publication"] >= 330 and current["count_excess"]["search_batches"] == 103 and not current["fully_complete"])

            source = subprocess.check_output([PHP, "-n", "-r", f"require '{ROOT}/tests/mon_reinforcement_fixture.php'; echo MonRun::json(reinforcement_fixture(1));"], env=env).decode()
            data = json.loads(source)
            source_sha = hashlib.sha1(b"blob " + str(len(source.encode())).encode() + b"\0" + source.encode()).hexdigest()
            context = {"source_blob_sha": source_sha, "rendered_at": iso(), "countries": ["KR"], "important_ids": [], "run_context_id": run_id}
            symbol = data["watchlist"]["symbols"][0]
            status, out = request("mon_detail_request", {"source_json": source, "context": context, "item": {"request_id": "late-request", "symbol": symbol, "wait_seconds": 120}})
            check("a new independent request may register after the collection reference", status == 200 and out["result"]["status"] == "queued")
            status, out = request("mon_detail_prepare", {"source_json": source, "context": context})
            prepared = out.get("result", {})
            check("real prepare endpoint succeeds after decision reference and pending checkpoint", status == 200 and prepared.get("prepared_fingerprint") and prepared.get("state_token"))
            decision = {"view_context": context, "state_token": prepared["state_token"], "prepared_fingerprint": prepared["prepared_fingerprint"], "fixed_as_of": context["rendered_at"], "selected": [{"symbol_id": symbol["symbol_id"], "reason": "Synthetic positive RS and MA5 reclaim after a reference overrun"}], "references": [], "live_observations": []}
            body = {"source_json": source, "decision": decision, "response_mode": "manifest", "publish_mode": "manual"}
            status, out = request("mon_detail_finalize", body)
            final = out.get("result", {})
            check("real finalize endpoint verifies a new decision after the original reference", status == 200 and final.get("new_analysis_complete") and final.get("final_available"))
            manifest = final["manifest"]
            check("final artifact contains the atomic data/result pair while remote save is still unconfirmed", {row["path"] for row in manifest["files"]} == {"mon_data.json", "mon_result.md"} and final["save_status"] == "not_requested")
            for row in manifest["files"]:
                status, out = request("mon_artifact_read", {"artifact_id": manifest["artifact_id"], "path": row["path"], "offset": 0, "length": 65536})
                chunk = out["result"]
                raw = base64.b64decode(chunk["data"])
                check(f"late artifact {row['path']} is readable and hash verified", status == 200 and hashlib.sha256(raw).hexdigest() == chunk["chunk_sha256"] and chunk["blob_sha"] == row["blob_sha"])
            status, out = request("mon_detail_finalize", body)
            check("identical late finalization reuses the artifact instead of rebuilding", status == 200 and out["result"]["status"] == "reuse" and out["result"]["manifest"]["artifact_id"] == manifest["artifact_id"])
            changed = json.loads(json.dumps(body))
            changed["decision"]["selected"][0]["reason"] = "different decision"
            status, out = request("mon_detail_finalize", changed)
            check("time overrun does not weaken immutable decision protection", status in (400, 409) and out.get("error_code") == "MON_FINAL_DECISION_IMMUTABLE")

            status, out = request("mon_run_observe", {"run_context_id": run_id, "event": "final_display_reported", "fields": {"save_status": "saved", "new_analysis_complete": True, "reported_at": iso()}})
            check("client display cannot forge successful remote save", status == 200 and out["result"]["save_status"] == "not_requested" and not out["result"]["fully_complete"])
            status, out = request("mon_run_finish", {"run_context_id": run_id, "export": True})
            check("unfinished remote publication stays pending despite verified analysis and elapsed references", status == 200 and out["result"]["run"]["finished_at"] is None and not out["result"]["run"]["fully_complete"])
            check("next execution references learn time and search overruns", out["result"]["next_policy"]["limits"]["publication"] > 370 and out["result"]["next_policy"]["external_search_batches"] > 4)
            status, out = request("mon_run_status", {"run_context_id": run_id})
            check("recording overtime does not rewrite this execution's original reference", status == 200 and out["result"]["adaptive_policy"] == frozen)
            status, out = request("mon_run_finish", {"run_context_id": run_id, "reason": "interrupted", "unfinished": [{"task": "USER", "code": "USER_STOP"}], "export": True})
            check("explicit user stop remains a terminal interruption rather than a false success", status == 200 and out["result"]["run"]["terminal"] and not out["result"]["run"]["fully_complete"])
            status, out = request("mon_detail_prepare", {"source_json": source, "context": context})
            check("explicit user interruption is respected by subsequent processing", status == 400 and out["error_code"] == "MON_RUN_INTERRUPTED")
            check("completion endpoints never start a NAS daemon or write stock outputs without confirmed publication", not (state / "mon_manual_start.php").exists() and not (state / "mon_daemon.lock").exists() and not (web / "mon_data.json").exists() and not (web / "mon_result.md").exists())
        finally:
            server.terminate()
            server.wait(timeout=5)

failed = sum(not case["pass"] for case in cases)
print(json.dumps({"version": "1.8.0", "tests": len(cases), "passed": len(cases) - failed, "failed": failed, "wall_seconds": round(time.monotonic() - started, 6), "external_requests": 0, "orders": 0, "cases": cases}, ensure_ascii=False, indent=2))
raise SystemExit(bool(failed))

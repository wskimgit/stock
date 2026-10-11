"""New adaptive endpoints on a real local PHP 7.4 server; no daemon/provider/order."""
from pathlib import Path
from datetime import datetime, timezone, timedelta
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
TOKEN = "synthetic-adaptive-operator-token-0000"
cases = []
started = time.monotonic()


def check(name, condition):
    cases.append({"case": name, "pass": bool(condition)})
    if not condition:
        raise AssertionError(name)


def iso(offset=0):
    return (datetime.now(timezone.utc) + timedelta(seconds=offset)).strftime("%Y-%m-%dT%H:%M:%S.%fZ")


with tempfile.TemporaryDirectory(prefix="mon-adaptive-http-") as temp:
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

        def request(api, body=None, auth=True, method="POST"):
            raw = json.dumps(body or {}, ensure_ascii=False).encode() if method == "POST" else None
            req = urllib.request.Request(f"http://127.0.0.1:{port}/mon.php?api={api}", data=raw, method=method)
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
            status, out = request("mon_run_statistics", auth=False)
            check("statistics requires the existing operator authentication", status == 403 and out["error_code"] == "MON_OPERATOR_AUTH_REQUIRED")
            status, out = request("mon_run_statistics", method="GET")
            check("statistics control endpoint requires POST", status == 405 and out["error_code"] == "MON_METHOD_INVALID")
            check("unauthorized reads create no run or start grant", not list(state.glob("mon_run-*.php")) and not (state / "mon_manual_start.php").exists())

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
            check("validated observed history imports and proposes 370 advisory seconds", status == 200 and out["result"]["next_policy"]["limits"]["publication"] == 370)
            first_sha = out["result"]["statistics_snapshot"]["sha256"]
            status, out = request("mon_run_statistics", {"statistics_snapshot": snapshot, "export": True})
            check("repeated import preserves sample and checksum", status == 200 and out["result"]["statistics_snapshot"]["sha256"] == first_sha)

            received = iso()
            status, out = request("mon_run_start", {"run_id": "http-adaptive", "received_at": received})
            result = out["result"]
            run_id = result["run_context_id"]
            frozen = result["adaptive_policy"]
            check("auto start freezes 350/360/370 references and source generation", status == 200 and frozen["limits"] == {"collection": 350, "decision": 360, "publication": 370, "recovery": 370} and frozen["statistics_generation"] >= 1)
            check("start measures its handler and returns a small fallback", result["operations"]["handler/control"]["count"] >= 1 and result["initial_result"]["header"]["state"] == "needs_data")
            status, out = request("mon_run_start", {"run_id": "http-adaptive", "received_at": received, "mode": "extended", "extension_reason": "late"})
            check("same run cannot change frozen mode", status == 400 and out["error_code"] == "MON_RUN_START_IMMUTABLE")

            report = {"operation_id": "search-1", "operation": "external/search", "seconds": .4, "units": 3, "success": True}
            status, out = request("mon_run_report", {"run_context_id": run_id, "report": report})
            status2, out2 = request("mon_run_report", {"run_context_id": run_id, "report": report})
            op = out2["result"]["operations"]["external/search"]
            check("actual batch report is idempotent and marks caller provenance", status == status2 == 200 and op["count"] == 1 and op["units"] == 3 and op["measurement"] == "operator_reported")
            for index in (2, 3):
                report["operation_id"] = f"search-{index}"
                status, out = request("mon_run_report", {"run_context_id": run_id, "report": report})
            check("over-limit performed calls remain counted with a breach event", status == 200 and out["result"]["counts"]["search_batches"] == 3 and out["result"]["counts"]["search_limit_hits"] == 1 and out["result"]["remaining_counts"]["search_batches"] == 0)

            event = {"run_context_id": run_id, "event": "initial_display_reported", "fields": {"reported_at": iso(), "new_analysis_complete": True, "save_status": "saved", "final_available": True}}
            status, out = request("mon_run_observe", event)
            status2, out2 = request("mon_run_observe", event)
            check("duplicate display report counts once and cannot forge completion", status == status2 == 200 and out2["result"]["counts"]["displays_reported"] == 1 and not out2["result"]["new_analysis_complete"] and not out2["result"]["fully_complete"])
            status, out = request("mon_run_report", {"run_context_id": run_id, "report": {"operation_id": "bad-time", "operation": "external/nas", "seconds": -1, "success": False}})
            check("invalid reported duration fails without changing fixed deadlines", status == 400 and out["error_code"] == "MON_MEASUREMENT_INVALID")

            status, out = request("mon_run_finish", {"run_context_id": run_id, "reason": "interrupted", "unfinished": [{"task": "US_INDEX", "code": "DETAIL_BENCHMARK_DATE_MISSING"}], "export": True, "new_analysis_complete": True})
            terminal = out["result"]
            check("finish records interruption and outstanding work without claimed success", status == 200 and not terminal["run"]["fully_complete"] and terminal["run"]["statistics_status"] == "recorded" and len(terminal["run"]["unfinished"]) == 1)
            observed = [row for row in terminal["statistics_snapshot"]["samples"] if row["run_context_id"] == run_id][0]
            check("server finish preserves receipt and measures first reported display", observed["received_at"] == received and observed["outcome"] == "interrupted" and observed["first_display_seconds"] is not None and observed["measurement"] == "server")
            ended = terminal["run"]["finished_at"]
            digest = terminal["statistics_snapshot"]["sha256"]
            status, out = request("mon_run_finish", {"run_context_id": run_id, "export": True})
            check("repeated finish creates no extra sample and no new ending time", status == 200 and out["result"]["run"]["finished_at"] == ended and out["result"]["statistics_snapshot"]["sha256"] == digest)
            status, out = request("mon_run_status", {"run_context_id": run_id})
            check("frozen policy survives finishing and new statistics", status == 200 and out["result"]["adaptive_policy"] == frozen)

            bad = dict(snapshot)
            bad["sha256"] = "0" * 64
            status, out = request("mon_run_statistics", {"statistics_snapshot": bad})
            check("corrupt imported statistics are rejected", status == 400 and out["error_code"] == "MON_STATS_INVALID")
            status, out = request("mon_run_statistics", {"export": True})
            check("invalid import leaves valid stored statistics intact", status == 200 and out["result"]["statistics_snapshot"]["sha256"] == digest)
            status, out = request("mon_run_start", {"run_id": "http-next", "received_at": iso()})
            check("subsequent run chooses a new policy from persisted statistics", status == 200 and out["result"]["adaptive_policy"]["statistics_sha256"] == digest and out["result"]["adaptive_policy"]["limits"]["publication"] == 370)
            check("all adaptive operations leave manual start grants and live stock files absent", not (state / "mon_manual_start.php").exists() and not (state / "mon_daemon.lock").exists() and not (web / "mon_data.json").exists() and not (web / "mon_result.md").exists())
        finally:
            server.terminate()
            server.wait(timeout=5)

failed = sum(not case["pass"] for case in cases)
print(json.dumps({"version": "1.8.0", "tests": len(cases), "passed": len(cases) - failed, "failed": failed, "wall_seconds": round(time.monotonic() - started, 6), "external_requests": 0, "orders": 0, "cases": cases}, ensure_ascii=False, indent=2))
raise SystemExit(bool(failed))

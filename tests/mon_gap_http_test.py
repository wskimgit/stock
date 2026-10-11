"""New read-only recovery HTTP routes against native PHP 7.4, with no external calls."""
import base64, datetime, gzip, hashlib, json, os, pathlib, socket, subprocess, tempfile, time, urllib.error, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ["MON_TEST_PHP"]
cases = []
started = time.monotonic()
raw = (ROOT / "mon_data.json").read_text()
source = json.loads(raw)

def blob(text):
    b = text.encode()
    return hashlib.sha1(b"blob " + str(len(b)).encode() + b"\0" + b).hexdigest()

def context(text):
    return {"source_blob_sha": blob(text), "rendered_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ"), "countries": ["KR", "US", "JP"], "important_ids": []}

with tempfile.TemporaryDirectory(prefix="mon-gap-http-") as directory:
    path = pathlib.Path(directory)
    router = path / "router.php"
    router.write_text("<?php require " + json.dumps(str(ROOT / "mon.php")) + "; mon_main([]);", encoding="utf-8")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, MON_STATE_DIR=directory, MON_ENV_FILE=str(path / "absent.env"))
    server = subprocess.Popen([PHP, "-n", "-d", "memory_limit=96M", "-S", f"127.0.0.1:{port}", str(router)], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1", port), .1): break
            except OSError: time.sleep(.02)
        def request(api, text=raw, method="POST", content_type="application/json", extra=None):
            body = {"source_json": text, "context": context(text)}
            if extra: body.update(extra)
            data = None if method == "GET" else json.dumps(body, ensure_ascii=False).encode()
            req = urllib.request.Request(f"http://127.0.0.1:{port}/?api={api}", data=data, method=method, headers={"Content-Type": content_type})
            try:
                with urllib.request.urlopen(req, timeout=30) as response: return response.status, json.load(response)
            except urllib.error.HTTPError as error: return error.code, json.load(error)
        def case(name, fn):
            try: fn(); cases.append({"case": name, "pass": True})
            except Exception as exc: cases.append({"case": name, "pass": False, "error": str(exc)})
        def check(ok, why="assertion"):
            if not ok: raise AssertionError(why)

        case("gap API rejects GET", lambda: check(request("mon_gap_plan", method="GET")[1]["error_code"] == "MON_METHOD_INVALID"))
        case("gap API rejects incorrect content type", lambda: check(request("mon_gap_plan", content_type="text/plain")[1]["error_code"] == "MON_CONTENT_TYPE_INVALID"))
        status, result = request("mon_gap_plan")
        case("actual staged source gap API returns success", lambda: check(status == 200 and result["ok"]))
        pages = result["result"]["pages"]
        jobs = [row for page in pages for row in page["cards"]]
        case("gap pages distinguish corporate review and closed-session checks", lambda: check(any(row["kind"] == "issuer_risk_review" and not row["depends_on_regular_session"] for row in jobs) and any(row["kind"] == "current_entry_checks" and row["state"] == "waiting_regular_session" for row in jobs)))
        case("official rules resolve the 19 Japanese tick-rule gaps", lambda: check(sum(row["kind"] == "tick_rule" for row in jobs) == 0 and len(json.loads((ROOT / "tests" / "mon_gap_jpx_observed.json").read_text())["symbols"]) == 19))
        case("CRWD near-price proof resolves its numeric history", lambda: check(not any(row.get("symbol_id") == "US|NASDAQ|CRWD" and row["kind"] in ["source_basis_conflict","completed_period_missing","history_shortfall"] for row in jobs)))
        case("all 60 official identities remove the current acquisition shortage", lambda: check(not any(row["kind"] == "identity_discovery" for row in jobs) and all(row["next_session_queued_count"] == 20 for row in source["analysis"]["discovery_state"]["countries"])))
        case("gap API remains read only", lambda: check(all(not page["header"]["write_required"] and not page["header"]["new_analysis_complete"] and not page["header"]["auto_selected"] for page in pages)))
        bad = json.loads(raw)
        packed = bad["analysis"]["input_snapshot"]["lossless_payload"]
        payload = json.loads(gzip.decompress(base64.b64decode(packed["data"])))
        payload["recovery_source_manifest"]["path"] = "../../untrusted"
        uncompressed = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode()
        packed.update(data=base64.b64encode(gzip.compress(uncompressed)).decode(), sha256=hashlib.sha256(uncompressed).hexdigest(), uncompressed_bytes=len(uncompressed))
        bad_raw = json.dumps(bad, ensure_ascii=False, separators=(",", ":"))
        case("invalid source manifest is rejected before any lookup", lambda: check(request("mon_gap_plan", text=bad_raw)[1]["error_code"] == "MON_GAP_SOURCE_MANIFEST_INVALID"))
        status, prepared = request("mon_detail_prepare")
        case("normal native preparation uses recovered inputs under 96 MiB", lambda: check(status == 200 and prepared["ok"] and prepared["result"]["counts"]["US"].get("unverified", 0) == 0 and prepared["result"]["counts"]["JP"].get("unverified", 0) == 0))
        case("read-only requests create no daemon or market statistics", lambda: check(not (path / "mon_daemon.json").exists() and not (path / "mon_execution_stats.json").exists() and (ROOT / "mon_data.json").read_text() == raw))
    finally:
        server.terminate()
        server.wait(timeout=5)

report = {"kind": "actual_local_php74_readonly_gap_http_tests", "case_count": len(cases), "passed": sum(row["pass"] for row in cases), "failed": sum(not row["pass"] for row in cases), "elapsed_seconds": round(time.monotonic()-started, 6), "external_requests": 0, "orders": 0, "cases": cases}
(ROOT / "tests/mon_gap_http_tests.json").write_text(json.dumps(report, ensure_ascii=False, indent=2)+"\n")
print(json.dumps({key: report[key] for key in ["case_count", "passed", "failed", "elapsed_seconds"]} | {"failures": [row for row in cases if not row["pass"]]}, ensure_ascii=False))
raise SystemExit(1 if report["failed"] else 0)

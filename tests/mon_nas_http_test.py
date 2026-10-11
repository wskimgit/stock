"""Focused native PHP 7.4 HTTP/CLI checks for NAS-recovery changes; no live network."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

PHP = os.environ.get("MON_PHP_BINARY", "php")
ROOT = Path(__file__).resolve().parents[1]
cases = []
started = time.monotonic()


def check(name, test):
    try:
        test()
        cases.append({"case": name, "pass": True})
    except Exception as exc:
        cases.append({"case": name, "pass": False, "error": str(exc)})


def expect(value, detail="assertion failed"):
    if not value:
        raise AssertionError(detail)


with tempfile.TemporaryDirectory(prefix="mon-nas-http-") as directory:
    state = Path(directory)
    shutil.copyfile(ROOT / "mon.php", state / "mon.php")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    env = os.environ.copy()
    env["MON_STATE_DIR"] = directory
    env["MON_ENV_FILE"] = ""
    server = subprocess.Popen([PHP, "-n", "-S", f"127.0.0.1:{port}", "-t", directory], env=env,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    url = f"http://127.0.0.1:{port}/"
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def request(suffix, method="GET"):
        req = urllib.request.Request(url + suffix, headers={"Accept": "application/json"}, method=method)
        try:
            response = opener.open(req, timeout=5)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            return response.status, response.read()

    def cli(*args):
        return subprocess.run([PHP, "-n", str(state / "mon.php"), *args], env=env,
                              text=True, capture_output=True, timeout=5)

    try:
        for _ in range(100):
            if server.poll() is not None:
                raise RuntimeError("local PHP server exited")
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=.1):
                    break
            except OSError:
                time.sleep(.02)

        def bootstrap():
            status, raw = request("mon.php?view=bootstrap")
            result = json.loads(raw)
            expect(status == 200 and result["processor_version"] == "1.8.1")
            header = result["result"]["header"]
            expect(header["contract"] == "MON-BOOTSTRAP-1.0" and not header["new_analysis_complete"])
            expect(header["state"] == "needs_data" and result["result"]["cards"] == [])
        check("GET bootstrap uses the new version and missing data is not a completed analysis", bootstrap)

        def status_view():
            status, raw = request("mon.php?view=status")
            data = json.loads(raw)["data"]
            expect(status == 200 and data["version"] == "1.8.1" and not data["running"])
            expect(data["start_policy"] == "manual_web_only" and not data["auto_start"])
        check("status reads keep manual start and do not launch the daemon", status_view)

        def progress_view():
            status, raw = request("mon.php?view=progress")
            result = json.loads(raw)
            expect(status == 200 and result["ok"] and len(raw) <= 4096)
            expect(result["progress"]["snapshot_token"] and result["progress"]["page"]["next_cursor"] is None)
        check("read-only progress still uses a small stable snapshot page", progress_view)

        def html_view():
            req = urllib.request.Request(url + "mon.php", headers={"Accept": "text/html"})
            with opener.open(req, timeout=5) as response:
                html = response.read().decode()
            expect("v1.8.1" in html and 'id="connection-recovery"' in html and 'id="connection-retry"' in html)
            expect("setInterval(" not in html and "AbortController" in html)
        check("rendered web page includes recovery feedback and serialized polling", html_view)

        def reject_bootstrap_post():
            status, raw = request("mon.php?view=bootstrap", "POST")
            expect(status == 405 and json.loads(raw)["error_code"] == "MON_METHOD_INVALID")
        check("POST bootstrap is rejected without authorizing a daemon start", reject_bootstrap_post)

        def invalid_cursor():
            status, raw = request("mon.php?view=bootstrap&cursor=bad")
            expect(status == 400 and json.loads(raw)["error_code"] == "MON_BOOTSTRAP_CURSOR_INVALID")
        check("invalid bootstrap cursors return an input error without retry side effects", invalid_cursor)

        # Create a connected journal through the production helper with a synthetic legacy reply.
        setup = ('require ' + json.dumps(str(state / "mon.php")) + ';'
                 '$s=new MonStore($argv[1]);$n=new MonNasConnection($s,"cached-cli",static function(){'
                 'return ["status"=>200,"body"=>mon_json(["ok"=>true,"data"=>["version"=>"1.5.0","running"=>true,"heartbeat_fresh"=>true]],false)];});'
                 '$n->poll();echo basename($s->path("nas-".hash("sha256","cached-cli").".php"));')
        made = subprocess.run([PHP, "-n", "-r", setup, directory], capture_output=True, text=True, timeout=5)
        expect(made.returncode == 0, made.stderr)

        def protected_journal():
            status, raw = request(made.stdout)
            expect(status == 200 and raw == b"")
        check("persisted connection journals are unreadable through PHP HTTP", protected_journal)

        def cached_cli():
            process = cli("--nas-check", "--run-id=cached-cli")
            result = json.loads(process.stdout)
            expect(process.returncode == 0 and result["connected"] and not result["compatible"])
            expect(result["attempts"] == 1 and result["state"] == "connected_legacy")
        check("fixed CLI recovery resumes a saved run without another network request", cached_cli)

        def missing_run():
            process = cli("--nas-check")
            expect(process.returncode == 1 and "NAS_RUN_ID_REQUIRED" in process.stderr)
        check("CLI recovery requires the original logical run ID", missing_run)

        def manual_only():
            for option in ("--daemon", "--once", "--run"):
                process = cli(option)
                expect(process.returncode == 1 and "MANUAL_START_REQUIRED" in process.stderr)
            expect(not (state / "mon_status.json").exists())
            expect(not list(state.glob("*launch*")))
        check("all tested reads leave the daemon stopped and automatic launch modes forbidden", manual_only)
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()

failed = sum(not case["pass"] for case in cases)
print(json.dumps({"processor_version": "1.8.1", "scope": "new local native PHP HTTP/CLI paths; synthetic stored legacy reply; no live NAS/provider requests",
                  "cases_total": len(cases), "passed": len(cases) - failed, "failed": failed,
                  "test_seconds": round(time.monotonic() - started, 6), "cases": cases}, ensure_ascii=False, indent=2))
raise SystemExit(1 if failed else 0)

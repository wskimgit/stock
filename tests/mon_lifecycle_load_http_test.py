"""Focused PHP 7.4 HTTP/CLI integration. Temporary local state; no broker/Git calls."""
from pathlib import Path
import concurrent.futures
import hashlib
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("MON_TEST_PHP", "/workspace/scratch/2cf573c7ed8f/php74-reinforcement-build/sapi/cli/php")
PREFIX = "<?php exit; ?>\n"
cases = []
started = time.monotonic()


def check(name, fn):
    try:
        fn()
        cases.append({"name": name, "pass": True})
    except Exception as exc:
        cases.append({"name": name, "pass": False, "error": str(exc)})


def expect(condition, message="assertion failed"):
    if not condition:
        raise AssertionError(message)


with tempfile.TemporaryDirectory(prefix="mon-load-http-") as temp:
    root = Path(temp)
    web = root / "web"
    state = root / "state"
    web.mkdir()
    state.mkdir()
    shutil.copy2(ROOT / "mon.php", web / "mon.php")
    code = web / "mon.php"
    env = {k: v for k, v in os.environ.items() if not k.startswith(("MON_", "KIS_", "GITHUB_"))}
    env.update(MON_STATE_DIR=str(state))
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    log = root / "http.log"
    with log.open("w") as output:
        server = subprocess.Popen([PHP, "-n", "-S", f"127.0.0.1:{port}", "-t", str(web)], env=env, stdout=output, stderr=output)

        def request(path="?view=status", data=None):
            body = None if data is None else urllib.parse.urlencode(data).encode()
            req = urllib.request.Request(f"http://127.0.0.1:{port}/mon.php{path}", data=body)
            if path != "":
                req.add_header("Accept", "application/json")
            try:
                with urllib.request.urlopen(req, timeout=8) as response:
                    return response.status, response.read()
            except urllib.error.HTTPError as exc:
                return exc.code, exc.read()

        def cli(options):
            return subprocess.run([PHP, "-n", str(code), f"--state-dir={state}", "--max-ticks=1", *options], env=env, capture_output=True, text=True, timeout=3)

        def no_worker():
            expect(not (state / "mon_status.json").exists(), "daemon heartbeat unexpectedly created")
            expect(not (state / "mon_launcher.log").exists(), "worker unexpectedly launched")

        try:
            deadline = time.monotonic() + 4
            while True:
                try:
                    status, body = request()
                    if status == 200:
                        break
                except (OSError, urllib.error.URLError):
                    pass
                if time.monotonic() >= deadline:
                    raise RuntimeError("local PHP server did not start")
                time.sleep(.02)

            def first_status():
                status, body = request()
                data = json.loads(body)["data"]
                expect(status == 200 and data["auto_start"] is False and data["running"] is False and data["start_policy"] == "manual_web_only")
                no_worker()
                expect(not (state / "mon_manual_start.php").exists())

            check("status GET does not issue a start permit or launch", first_status)

            def html():
                status, body = request("")
                text = body.decode()
                expect(status == 200 and "setInterval(function(){if(!document.hidden)refresh();},10000)" in text)
                expect("NAS 부팅" in text)
                no_worker()

            check("HTML shows manual boot start and reduces hidden-tab polling", html)

            def query_start():
                status, body = request("?view=status&action=start")
                expect(status == 200 and json.loads(body)["data"]["running"] is False)
                expect(not (state / "mon_manual_start.php").exists())
                no_worker()

            check("GET action=start cannot start a daemon", query_start)

            def read_only(path):
                before = sorted(p.name for p in state.iterdir())
                status, body = request(path)
                expect(status == 200 and json.loads(body)["ok"] is True)
                expect(sorted(p.name for p in state.iterdir()) == before)
                no_worker()

            check("bootstrap GET has no lifecycle writes", lambda: read_only("?view=bootstrap"))
            check("progress GET has no lifecycle writes", lambda: read_only("?view=progress"))

            def default_cli():
                result = cli([])
                expect(result.returncode == 0 and json.loads(result.stdout)["running"] is False)
                expect(not (state / "mon_manual_start.php").exists())
                no_worker()

            check("optionless CLI is read-only status instead of an auto-start", default_cli)

            def denied(options):
                result = cli(options)
                expect(result.returncode == 1 and result.stderr.strip() == "MANUAL_START_REQUIRED", result.stderr)
                no_worker()

            check("legacy cron --daemon is blocked", lambda: denied(["--daemon"]))
            check("direct --run is blocked", lambda: denied(["--run"]))
            check("direct --once is blocked", lambda: denied(["--once"]))
            check("invented launch token is blocked", lambda: denied(["--run", "--instance=" + "a" * 24, "--launch-ticket=" + "0" * 64]))

            def save_settings():
                status, body = request(data={"action": "save_settings", "PHP_CLI": PHP})
                expect(status == 200 and json.loads(body)["ok"] is True)
                expect((state / "mon_settings.php").exists() and not (state / "mon_manual_start.php").exists())
                no_worker()

            check("settings save does not grant launch permission", save_settings)

            def unavailable_runtime():
                status, body = request(data={"action": "start"})
                data = json.loads(body)
                expect(status == 503 and data["error_code"] == "WEB_PHP_CLI_CURL_MISSING", str(data.get("error_code")))
                expect(not (state / "mon_manual_start.php").exists())
                no_worker()

            check("manual start with unusable PHP fails without a permit or worker", unavailable_runtime)

            def setup_permit():
                script = 'require $argv[1];$s=new MonStore($argv[2]);echo (new MonManualStart($s))->issue($argv[3]);'
                proc = subprocess.run([PHP, "-n", "-r", script, str(code), str(state), "b" * 24], env=env, text=True, capture_output=True, timeout=3)
                expect(proc.returncode == 0 and len(proc.stdout) == 64, proc.stderr)
                return proc.stdout

            def stop_pending():
                token = setup_permit()
                status, body = request(data={"action": "stop"})
                record = json.loads((state / "mon_manual_start.php").read_text()[len(PREFIX):])
                expect(status == 200 and json.loads(body)["ok"] and record["state"] == "revoked")
                denied(["--run", "--instance=" + "b" * 24, "--launch-ticket=" + token])

            check("HTTP Stop revokes a not-yet-running worker permit", stop_pending)

            def stale_pid():
                stale = {"version": "1.6.2", "boot_id": "previous-boot", "pid": server.pid, "instance_id": "a" * 24, "heartbeat_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "state": "waiting"}
                (state / "mon_status.json").write_text(json.dumps(stale))
                status, body = request()
                data = json.loads(body)["data"]
                expect(status == 200 and data["running"] is False and data["boot_verified"] is False and data["monitoring_active"] is False)
                expect(not (state / "mon_launcher.log").exists())
                (state / "mon_status.json").unlink()

            check("previous-boot status and live unrelated PID do not imply a daemon", stale_pid)

            def concurrent_consume():
                token = setup_permit()
                script = 'require $argv[1];try{echo (new MonManualStart(new MonStore($argv[2])))->consume($argv[3],$argv[4]);}catch(MonFault $e){fwrite(STDERR,$e->faultCode);exit(1);}'
                args = [PHP, "-n", "-r", script, str(code), str(state), "b" * 24, token]
                def consume():
                    return subprocess.run(args, env=env, capture_output=True, text=True, timeout=3)
                with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
                    outcomes = list(executor.map(lambda _: consume(), range(2)))
                expect(sum(p.returncode == 0 for p in outcomes) == 1)
                expect(all(p.returncode == 0 or p.stderr in {"MANUAL_START_REQUIRED", "WEB_START_BUSY"} for p in outcomes))
                replay = consume()
                expect(replay.returncode == 1 and replay.stderr == "MANUAL_START_REQUIRED")
                no_worker()

            check("two real CLI processes cannot consume one permit twice", concurrent_consume)

            def no_timeout_log():
                expect("Fatal error" not in log.read_text())
                no_worker()

            check("integration leaves no launched worker or fatal server error", no_timeout_log)
        finally:
            server.terminate()
            try:
                server.wait(timeout=3)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=3)

failed = sum(not case["pass"] for case in cases)
result = {"suite": "MON lifecycle HTTP and CLI 1.6.2", "runtime": subprocess.run([PHP, "-n", "-r", "echo PHP_VERSION;"], capture_output=True, text=True, check=True).stdout, "pass": len(cases)-failed, "fail": failed, "duration_seconds": round(time.monotonic()-started, 6), "cases": cases, "external_requests": 0, "orders": 0, "nas_reboot_performed": False}
(ROOT / "tests/mon_lifecycle_load_http_tests.json").write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
print(json.dumps({k: result[k] for k in ("runtime", "pass", "fail", "duration_seconds")}, ensure_ascii=False))
for case in cases:
    if not case["pass"]:
        print(json.dumps(case, ensure_ascii=False))
raise SystemExit(bool(failed))

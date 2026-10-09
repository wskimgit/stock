"""Actual HTTP/web control tests. No live broker/GitHub API requests."""
import fcntl
import datetime as dt
import json
import os
import pathlib
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get("MON_TEST_PHP", "php")
CASES = []


def check(name, ok):
    CASES.append({"case": name, "pass": bool(ok)})
    if not ok:
        raise AssertionError(name)


def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class Site:
    def __init__(self, base, ini=None):
        self.web = base / "web folder"
        self.web.mkdir()
        shutil.copyfile(ROOT / "mon.php", self.web / "mon.php")
        # Public-read support must not turn isolated lifecycle tests into live API calls.
        cache = json.loads((ROOT / "mon_data.json").read_text())
        cache["_mon_cache"] = {"repository": "wskimgit/stock", "branch": "main", "fetched_at": dt.datetime.now(dt.timezone.utc).isoformat()}
        (self.web / "mon_remote_cache.json").write_text(json.dumps(cache, ensure_ascii=False))
        self.env = dict(os.environ)
        for key in ("GITHUB_TOKEN", "KIS_APP_KEY", "KIS_APP_SECRET", "PHP_CLI"):
            self.env.pop(key, None)
        self.env.update(MON_ENV_FILE="", MON_STATE_DIR="", MON_IDLE_POLL_SECONDS="1")
        if ini:
            self.env["PHPRC"] = str(ini)
        self.port = free_port()
        self.url = f"http://127.0.0.1:{self.port}/mon.php"
        self.server = None
        self.log = open(base / "http.log", "a")
        self.start()

    def start(self):
        self.server = subprocess.Popen(
            [PHP, "-S", f"127.0.0.1:{self.port}", "-t", str(self.web)],
            env=self.env, stdout=self.log, stderr=self.log,
        )
        end = time.monotonic() + 5
        while time.monotonic() < end:
            try:
                self.request("?view=status")
                return
            except (OSError, urllib.error.URLError):
                if self.server.poll() is not None:
                    self.log.flush()
                    log = pathlib.Path(self.log.name).read_text()[-1400:]
                    raise AssertionError("HTTP server exited: " + log)
                time.sleep(0.05)
        raise AssertionError("HTTP server not ready")

    def request(self, suffix="", form=None, json_accept=False):
        data = None if form is None else urllib.parse.urlencode(form).encode()
        headers = {"Accept": "application/json"} if json_accept else {}
        request = urllib.request.Request(self.url + suffix, data=data, headers=headers)
        try:
            response = urllib.request.urlopen(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, dict(response.headers), response.read().decode()

    def action(self, name, **values):
        code, headers, body = self.request(form=dict(action=name, **values), json_accept=True)
        return code, json.loads(body)

    def state(self):
        _, _, body = self.request("?view=status", json_accept=True)
        return json.loads(body)

    def lock_held(self):
        path = self.web / "mon_daemon.lock"
        if not path.exists():
            return False
        with path.open("a") as f:
            try:
                fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return True
            fcntl.flock(f, fcntl.LOCK_UN)
            return False

    def stop_server(self):
        if self.server and self.server.poll() is None:
            self.server.terminate()
            self.server.wait(timeout=5)

    def close(self):
        # Stop only the test daemon; no PID from outside this private test site.
        settings = self.web / "mon_settings.php"
        if settings.exists():
            settings.unlink()
        try:
            if self.server and self.server.poll() is None:
                self.action("stop")
        except Exception:
            pass
        if self.lock_held():
            status = json.loads((self.web / "mon_status.json").read_text())
            (self.web / "mon_stop.json").write_text(json.dumps({"instance_id": status["instance_id"]}))
            end = time.monotonic() + 8
            while time.monotonic() < end and self.lock_held():
                time.sleep(0.05)
        self.stop_server()
        self.log.close()
        if self.lock_held():
            raise AssertionError("test daemon did not stop")


with tempfile.TemporaryDirectory(prefix="mon-web-tests-") as td:
    base = pathlib.Path(td)
    first = base / "normal"
    first.mkdir()
    site = Site(first)
    try:
        code, headers, html = site.request()
        check("browser GET renders Korean controls instead of CLI-only denial", code == 200 and "mon 모니터" in html and 'id="start"' in html and 'id="stop"' in html)
        check("mobile layout and accessible live status included", 'name="viewport"' in html and 'aria-live="polite"' in html)
        check("opening page does not start a daemon", not site.state()["data"]["running"])
        check("status endpoint returns JSON with no-store", "no-store" in headers.get("Cache-Control", "") and site.state()["ok"])
        code, result = site.action("start")
        first_status = result["data"]["status"]
        check("HTTP Start launches and verifies a real detached daemon", code == 200 and result["ok"] and result["data"]["running"] and first_status["instance_id"] and site.lock_held())
        code, duplicate = site.action("start")
        check("repeated browser Start keeps the same daemon PID", code == 200 and duplicate["data"]["status"]["pid"] == first_status["pid"])
        time.sleep(1.1)
        state = site.state()["data"]
        check("missing credentials keep process alive and appear as setup message", state["running"] and state["status"]["error_code"] == "GITHUB_TOKEN_MISSING" and "GitHub" in state["message"])
        code, _, running_html = site.request()
        check("running page shows correct Start and Stop button availability", code == 200 and 'id="start" disabled' in running_html and 'id="stop" class="stop" disabled' not in running_html)
        code, stopped = site.action("stop")
        check("HTTP Stop releases the real daemon lock", code == 200 and stopped["ok"] and not stopped["data"]["running"] and not site.lock_held())
        code, restarted = site.action("start")
        check("HTTP Start restarts with a new instance", code == 200 and restarted["data"]["status"]["instance_id"] != first_status["instance_id"])
        site.stop_server()
        time.sleep(0.4)
        check("daemon survives web-server exit with no connected browser", site.lock_held())
        site.start()
        check("new web request finds existing detached daemon", site.state()["data"]["running"])
        site.action("stop")
        check("browser stops the daemon after HTTP-server restart", not site.lock_held())
        code, saved = site.action("save_settings", GITHUB_TOKEN="fixture-web-token", KIS_APP_KEY="fixture-web-key", KIS_APP_SECRET="fixture-web-secret", PHP_CLI="")
        check("browser saves API settings without manual config creation", code == 200 and saved["ok"] and saved["data"]["connection"] == {"github": True, "kis": True})
        settings = site.web / "mon_settings.php"
        check("settings persist next to mon.php as an automatically created file", settings.is_file() and settings.read_text().startswith("<?php exit; ?>\n"))
        _, _, body = site.request("?view=status", json_accept=True)
        _, _, configured_html = site.request()
        check("status JSON and HTML never echo saved API credential values", "fixture-web-" not in body and "fixture-web-" not in configured_html)
        with urllib.request.urlopen(site.url.replace("mon.php", "mon_settings.php"), timeout=5) as response:
            check("direct settings URL does not return credential data", response.status == 200 and response.read() == b"")
        code, blank = site.action("save_settings", GITHUB_TOKEN="", KIS_APP_KEY="", KIS_APP_SECRET="", PHP_CLI="")
        check("blank browser fields preserve saved API credentials", code == 200 and blank["data"]["connection"] == {"github": True, "kis": True})
        old_settings = settings.read_text()
        code, rejected = site.action("save_settings", GITHUB_TOKEN="invalid\r\nheader")
        check("invalid settings input is rejected without altering saved data", code == 400 and not rejected["ok"] and settings.read_text() == old_settings)
        site.action("save_settings", PHP_CLI="/does/not/exist/php")
        code, failed = site.action("start")
        check("unavailable PHP reports failure without false running status", code == 503 and not failed["ok"] and failed["error_code"] == "WEB_PHP_CLI_NOT_FOUND" and not site.lock_held())
        code, failed = site.action("unknown")
        check("unsupported web actions return a clear request error", code == 400 and failed["error_code"] == "WEB_ACTION_INVALID")
        check("web operation creates no application subdirectories", not any(p.is_dir() for p in site.web.iterdir()))
    finally:
        site.close()

    # Optional process-launch paths are separate real HTTP server configurations.
    original_ini = pathlib.Path(os.environ["PHPRC"]).read_text() if os.environ.get("PHPRC") else ""
    for variant, disabled in (("blocked", "proc_open,exec,shell_exec,popen"), ("exec_only", "proc_open")):
        folder = base / variant
        folder.mkdir()
        ini = folder / "php.ini"
        ini.write_text(original_ini + "\ndisable_functions=" + disabled + "\n")
        site = Site(folder, ini)
        try:
            code, result = site.action("start")
            if variant == "blocked":
                check("disabled process launch still serves the web interface", site.request()[0] == 200)
                check("blocked launch is reported rather than claiming daemon success", code == 503 and result["error_code"] == "WEB_PROCESS_LAUNCH_DISABLED" and not site.lock_held())
            else:
                check("HTTP Start works through exec when proc_open is disabled", code == 200 and result["ok"] and result["data"]["running"] and site.lock_held())
                code, result = site.action("stop")
                check("exec-launched web daemon stops through the browser", code == 200 and not result["data"]["running"] and not site.lock_held())
        finally:
            site.close()

print(json.dumps({"cases_total": len(CASES), "passed": sum(x["pass"] for x in CASES), "failed": sum(not x["pass"] for x in CASES), "type": "real_HTTP_and_detached_process_tests", "live_broker_test": False, "cases": CASES}, ensure_ascii=False, indent=2))

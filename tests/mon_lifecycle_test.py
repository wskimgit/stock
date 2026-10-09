"""Real CLI/process tests; no broker credentials or outbound API calls."""
import json
import datetime as dt
import os
import pathlib
import shutil
import signal
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('MON_TEST_PHP', 'php')
CASES = []

def check(name, ok):
    CASES.append({'case': name, 'pass': bool(ok)})
    if not ok:
        raise AssertionError(name)

def await_stopped(command, env):
    end = time.monotonic() + 8
    while time.monotonic() < end:
        status = subprocess.run(command + ['--status'], env=env, text=True, capture_output=True, timeout=5)
        if status.returncode == 0 and not json.loads(status.stdout)['running']:
            return True
        time.sleep(0.1)
    return False

with tempfile.TemporaryDirectory(prefix='mon-lifecycle-') as td:
    base = pathlib.Path(td)
    web = base / 'web folder'
    web.mkdir(mode=0o755)
    script = web / 'mon.php'
    shutil.copyfile(ROOT / 'mon.php', script)
    # Missing write credentials now permit cached public-list preview, without live APIs.
    cache=json.loads((ROOT/'mon_data.json').read_text())
    cache['_mon_cache']={'repository':'wskimgit/stock','branch':'main','fetched_at':dt.datetime.now(dt.timezone.utc).isoformat()}
    (web/'mon_remote_cache.json').write_text(json.dumps(cache,ensure_ascii=False))
    original_mode = web.stat().st_mode & 0o777
    (web / 'status.json').write_text('{"other_application":true}')
    env = dict(os.environ)
    env.update(GITHUB_TOKEN='', KIS_APP_KEY='', KIS_APP_SECRET='', MON_IDLE_POLL_SECONDS='1', MON_ENV_FILE='', MON_STATE_DIR='')
    command = [PHP, str(script)]

    def run(*args):
        return subprocess.run(command + list(args), env=env, text=True, capture_output=True, timeout=10)

    process = None
    try:
        result = run('--help')
        check('CLI help completes', result.returncode == 0 and '--daemon' in result.stdout)
        result = run('--check')
        configuration = json.loads(result.stdout)
        check('configuration check reports PHP/curl without disclosing keys', result.returncode == 0 and configuration['curl'] and not configuration['github_token_present'])
        inline = base / 'inline.php'
        code = script.read_text()
        for key, value in [('GITHUB_TOKEN','inline-fixture-token'),('KIS_APP_KEY','inline-fixture-key'),('KIS_APP_SECRET','inline-fixture-secret')]:
            code = code.replace("'" + key + "' => ''", "'" + key + "' => '" + value + "'", 1)
        inline.write_text(code)
        inline_env = dict(env)
        for key in ('GITHUB_TOKEN','KIS_APP_KEY','KIS_APP_SECRET'):
            inline_env.pop(key, None)
        result = subprocess.run([PHP, str(inline), '--check'], env=inline_env, text=True, capture_output=True, timeout=10)
        cfg = json.loads(result.stdout)
        check('inline API settings work without an env file and values are omitted from status', result.returncode == 0 and cfg['github_token_present'] and cfg['kis_credentials_present'] and 'inline-fixture-' not in result.stdout)
        started = run()
        check('no-argument launch returns while daemon keeps running', started.returncode == 0 and 'PID=' in started.stdout)
        first = json.loads(run('--status').stdout)
        check('flock and process heartbeat confirm running', first['running'] and first['status']['instance_id'])
        check('state files stay beside mon.php with no application subdirectory', configuration['state_directory'] == str(web) and (web / 'mon_status.json').is_file() and not any(p.is_dir() for p in web.iterdir()))
        check('existing web folder permissions stay unchanged', web.stat().st_mode & 0o777 == original_mode)
        check('existing application status.json is preserved', (web / 'status.json').read_text() == '{"other_application":true}')
        duplicate = run()
        second = json.loads(run('--status').stdout)
        check('duplicate launch keeps one existing PID', duplicate.returncode == 0 and first['status']['pid'] == second['status']['pid'])
        time.sleep(1.1)
        current = json.loads(run('--status').stdout)
        check('missing credentials keep daemon alive with error status', current['running'] and current['status']['error_code'] == 'GITHUB_TOKEN_MISSING')
        check('stop request is accepted', run('--stop').returncode == 0)
        check('daemon stops and releases lock', await_stopped(command, env))
        restarted = run('--daemon')
        third = json.loads(run('--status').stdout)
        check('restart obtains a fresh instance', restarted.returncode == 0 and third['running'] and third['status']['instance_id'] != first['status']['instance_id'])
        run('--stop')
        check('restarted daemon also stops', await_stopped(command, env))
        once = run('--once')
        check('single-cycle setup wait exits normally and releases lock', once.returncode == 0 and not json.loads(run('--status').stdout)['running'])
        process = subprocess.Popen(command + ['--run'], env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        end = time.monotonic() + 5
        while time.monotonic() < end and not json.loads(run('--status').stdout)['running']:
            time.sleep(0.1)
        process.send_signal(signal.SIGTERM)
        out, err = process.communicate(timeout=8)
        check('SIGTERM gracefully exits foreground daemon', process.returncode == 0 and not json.loads(run('--status').stdout)['running'])
    finally:
        run('--stop')
        await_stopped(command, env)
        if process and process.poll() is None:
            process.terminate()
            process.wait(timeout=8)

print(json.dumps({'cases_total': len(CASES), 'passed': sum(x['pass'] for x in CASES), 'failed': sum(not x['pass'] for x in CASES), 'type': 'real_process_lifecycle_tests', 'live_broker_test': False, 'cases': CASES}, ensure_ascii=False, indent=2))

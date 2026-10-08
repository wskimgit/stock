"""Real CLI/process tests; no broker credentials or outbound API calls."""
import json
import os
import pathlib
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
    envfile = base / 'private test.env'
    envfile.write_text('GITHUB_TOKEN=\nKIS_APP_KEY=\nKIS_APP_SECRET=\nMON_IDLE_POLL_SECONDS=1\n')
    os.chmod(envfile, 0o600)
    env = dict(os.environ)
    env.update(GITHUB_TOKEN='', KIS_APP_KEY='', KIS_APP_SECRET='', MON_IDLE_POLL_SECONDS='1')
    command = [PHP, str(ROOT / 'mon.php'), '--env=' + str(envfile), '--state-dir=' + str(base / 'state')]

    def run(*args):
        return subprocess.run(command + list(args), env=env, text=True, capture_output=True, timeout=10)

    process = None
    try:
        result = run('--help')
        check('CLI help completes', result.returncode == 0 and '--daemon' in result.stdout)
        result = run('--check')
        configuration = json.loads(result.stdout)
        check('configuration check reports PHP/curl without disclosing keys', result.returncode == 0 and configuration['curl'] and not configuration['github_token_present'])
        started = run('--daemon')
        check('daemon start returns while child keeps running', started.returncode == 0 and 'PID=' in started.stdout)
        first = json.loads(run('--status').stdout)
        check('flock and process heartbeat confirm running', first['running'] and first['status']['instance_id'])
        duplicate = run('--daemon')
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
        check('single-cycle failure exits and releases lock', once.returncode == 1 and not json.loads(run('--status').stdout)['running'])
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

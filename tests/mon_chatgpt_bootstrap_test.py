#!/usr/bin/env python3
"""Live localhost HTTP bootstrap checks; no network provider, no NAS or credentials."""
import json,os,shutil,socket,subprocess,tempfile,time,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get("MON_TEST_PHP","php")
checks=[]
def check(name, condition):
    checks.append({"case":name,"pass":bool(condition)})
    if not condition:raise AssertionError(name)
with tempfile.TemporaryDirectory(prefix="mon-chatgpt-http-") as tmp:
    path=Path(tmp)
    shutil.copy2(ROOT/"mon.php",path/"mon.php")
    shutil.copy2(ROOT/"mon_chatgpt_bridge.php",path/"mon_chatgpt_bridge.php")
    with socket.socket() as s:s.bind(("127.0.0.1",0));port=s.getsockname()[1]
    env=dict(os.environ,MON_STATE_DIR=tmp,MON_ENV_FILE="")
    for key in ("GITHUB_TOKEN","KIS_APP_KEY","KIS_APP_SECRET"):env.pop(key,None)
    server=subprocess.Popen([PHP,"-n","-S",f"127.0.0.1:{port}","-t",tmp],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        url=f"http://127.0.0.1:{port}/mon.php"
        for _ in range(100):
            try:
                with urllib.request.urlopen(url+"?view=bootstrap",timeout=3) as response:payload=json.load(response)
                break
            except Exception:time.sleep(.04)
        else:raise RuntimeError("local server not available")
        check("bridge capability shown only with deployed module",
              payload.get("ok") is True and payload.get("processor_version")=="1.8.7" and
              payload.get("capabilities",{}).get("chatgpt_bridge")=="MON-CHATGPT-BRIDGE-1.0")
        check("read-only GET is not a daemon auto-launch",
              payload.get("nas_state",{}).get("running") is False)
        (path/"mon_chatgpt_bridge.php").unlink()
        with urllib.request.urlopen(url+"?view=bootstrap",timeout=3) as response:legacy=json.load(response)
        check("missing optional bridge module safely reports null",
              legacy.get("ok") is True and legacy.get("capabilities",{}).get("chatgpt_bridge") is None)
        print(json.dumps({"suite":"MON-CHATGPT-BOOTSTRAP-HTTP","cases":checks,"passed":len(checks)},ensure_ascii=False))
    finally:
        server.terminate()
        try:server.wait(timeout=3)
        except subprocess.TimeoutExpired:server.kill()

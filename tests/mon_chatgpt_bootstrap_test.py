#!/usr/bin/env python3
"""Live localhost HTTP bootstrap checks; no network provider, no NAS or credentials."""
import json,os,shutil,socket,subprocess,tempfile,time,urllib.error,urllib.request
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
    # Mirror actual previously observed MON data, without loading secrets or making network calls.
    cached=json.loads((ROOT/"mon_data.json").read_text())
    cached["_mon_cache"]={"repository":"wskimgit/stock","branch":"main","fetched_at":"2026-10-13T16:24:00Z"}
    (path/"mon_remote_cache.json").write_text(json.dumps(cached,ensure_ascii=False))
    with socket.socket() as s:s.bind(("127.0.0.1",0));port=s.getsockname()[1]
    env=dict(os.environ,MON_STATE_DIR=tmp,MON_ENV_FILE="")
    for key in ("GITHUB_TOKEN","KIS_APP_KEY","KIS_APP_SECRET"):env.pop(key,None)
    log=open(path/"server.log","wb")
    server=subprocess.Popen([PHP,"-S",f"127.0.0.1:{port}","-t",tmp],env=env,stdout=log,stderr=log)
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        url=f"http://127.0.0.1:{port}/mon.php"
        for _ in range(100):
            try:
                with opener.open(url+"?view=bootstrap",timeout=3) as response:payload=json.load(response)
                break
            except urllib.error.HTTPError as exc:
                raise RuntimeError(f"bootstrap HTTP {exc.code}: "+exc.read().decode(errors="replace")) from exc
            except Exception:time.sleep(.04)
        else:raise RuntimeError("local server not available: "+(path/"server.log").read_text(errors="replace")[-1200:])
        check("bridge capability shown only with deployed module",
              payload.get("ok") is True and payload.get("processor_version")=="1.8.7" and
              payload.get("capabilities",{}).get("chatgpt_bridge")=="MON-CHATGPT-BRIDGE-1.0")
        check("read-only GET is not a daemon auto-launch",
              payload.get("nas_state",{}).get("running") is False)
        (path/"mon_chatgpt_bridge.php").unlink()
        with opener.open(url+"?view=bootstrap",timeout=3) as response:legacy=json.load(response)
        check("missing optional bridge module safely reports null",
              legacy.get("ok") is True and legacy.get("capabilities",{}).get("chatgpt_bridge") is None)
        print(json.dumps({"suite":"MON-CHATGPT-BOOTSTRAP-HTTP","cases":checks,"passed":len(checks)},ensure_ascii=False))
    finally:
        server.terminate()
        try:server.wait(timeout=3)
        except subprocess.TimeoutExpired:server.kill()
        log.close()

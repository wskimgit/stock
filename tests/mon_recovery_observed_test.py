#!/usr/bin/env python3
"""Compare a frozen source and its local recovery dependency with two native PHP versions."""
import argparse, json, os, pathlib, shutil, subprocess
ROOT=pathlib.Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('source');parser.add_argument('baseline_code');args=parser.parse_args()
php=os.environ.get('MON_TEST_PHP',shutil.which('php') or 'php'); reports={}
for label,code in [('baseline',args.baseline_code),('current',str(ROOT/'mon.php'))]:
    reports[label]=json.loads(subprocess.check_output([php,'-d','memory_limit=96M',str(ROOT/'tests/mon_recovery_observed_fixture.php'),args.source,code],text=True))
    (ROOT/f'tests/mon_recovery_observed_{label}.json').write_text(json.dumps(reports[label],ensure_ascii=False,indent=2)+'\n')
a,b=reports['baseline'],reports['current'];cases=[
    {'case':'real source metrics and full series remain exact','pass':all(a[k]==b[k] for k in ['metrics_sha256','series_sha256'])},
    {'case':'five native verified snapshots remain exact','pass':a['snapshot_sha256']==b['snapshot_sha256'] and len(b['snapshot_sha256'])==5},
    {'case':'real selection and collection remain exact','pass':all(a[k]==b[k] for k in ['collection_sha256','decision_sha256'])},
    {'case':'native preparation fingerprint is unchanged','pass':a['prepared_fingerprint']==b['prepared_fingerprint']},
    {'case':'frozen input is unchanged and both versions fit96MiB','pass':a['source_unchanged'] and b['source_unchanged'] and a['source_blob_sha']==b['source_blob_sha'] and max(a['peak_memory_bytes'],b['peak_memory_bytes'])<=96*1048576}
]
failed=sum(not c['pass'] for c in cases);result={'code':b['code'],'tests':len(cases),'passed':len(cases)-failed,'failed':failed,'external_requests':0,'orders':0,'nas_deployed':False,'baseline':a,'current':b,'cases':cases}
(ROOT/'tests/mon_recovery_observed_tests.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps(result,ensure_ascii=False,indent=2));raise SystemExit(1 if failed else 0)

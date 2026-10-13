<?php
declare(strict_types=1);
require __DIR__.'/mon_reinforcement_fixture.php';
$dir=sys_get_temp_dir().'/mon-cont-http-'.bin2hex(random_bytes(6));mkdir($dir,0700);$s=new MonStore($dir);$d=reinforcement_fixture(30);
$d->_mon_cache=(object)['repository'=>'wskimgit/stock','branch'=>'main','fetched_at'=>mon_iso(),'blob_sha'=>str_repeat('a',40)];$s->write('remote_cache.json',$d);file_put_contents($dir.'/fixture.env',"GITHUB_TOKEN=synthetic-token\n");$r=MonContinuity::checkpoint($s,time());
echo mon_json(['state_dir'=>$dir,'revision'=>$r->revision,'jobs'=>count($r->pending_jobs),'php'=>PHP_VERSION]);

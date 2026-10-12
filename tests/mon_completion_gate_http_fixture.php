<?php
declare(strict_types=1);
define('MON_GATE_LIBRARY_ONLY',true);
require __DIR__.'/mon_completion_gate_test.php';
[$s,$b,$pub,$git,$j]=cg_publication();$pub->process($j['job_id'],microtime(true)+10);
file_put_contents($s->dir.'/fixture.env',"GITHUB_TOKEN=fixture-token\nMON_OPERATOR_TOKEN=synthetic-gate-operator-token-123456\n");
$cached=$s->readPrivate('publication-receipt.php');$cached->fully_complete=true;$s->writePrivate('publication-receipt.php',$cached);
file_put_contents($s->dir.'/http-source.json',MonRun::json($git->data()));
echo mon_json(['php_version'=>PHP_VERSION,'state_dir'=>$s->dir,'run_id'=>$b->run->run_context_id,'received_at'=>$b->run->received_at,'artifact_id'=>$b->run->artifact_id,'job_id'=>$j['job_id']],false);

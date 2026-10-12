<?php
declare(strict_types=1);
define('MON_GATE_LIBRARY_ONLY',true);require __DIR__.'/mon_completion_gate_test.php';
[$s,$b,$pub,$git,$queued,$clock,$base]=cg_publication(false);MonExecution::recoverFinal('confirm');
$at=MonBudget::iso($clock->wall-400);$base->watchlist->calendar->markets[0]->checked_at=MonBudget::iso($clock->wall-460);$git->setSource($base);$c=reinforcement_context($base);$c->rendered_at=$at;$c->run_context_id=$b->run->run_context_id;$decision=reinforcement_decision($base,$c);$f=MonDetails::finalize($base,$decision);$candidate=mon_decode($f['files'][0]['content']);$f=mon_apply_mon_patch($candidate,cg_patch($candidate,$b->run->run_context_id,MonBudget::iso($clock->wall-399),true));
$artifact=MonArtifact::create($s,MonRun::json($base),$f['files'],['decision_sha256'=>MonRun::digest($decision),'run_context_id'=>$b->run->run_context_id,'received_at'=>$b->run->received_at]);$job=$pub->enqueue($artifact['artifact_id'],$git->head,$b,'synthetic_operator');MonExecution::event('final_prepared',['new_analysis_complete'=>true,'final_available'=>true,'analysis_origin'=>'secured_data_decision','save_status'=>'queued','artifact_id'=>$artifact['artifact_id'],'job_id'=>$job['job_id']]);$out=$pub->process($job['job_id'],microtime(true)+10);
if(!$out['publication_complete']||$out['fully_complete'])throw new RuntimeException('fixture must publish a saved draft with expired trading');
file_put_contents($s->dir.'/fixture.env',"GITHUB_TOKEN=fixture-token\nMON_OPERATOR_TOKEN=synthetic-gate-operator-token-123456\n");$data=$git->data();$sid=$data->analysis->results[0]->symbol_id;$issuer=MonRun::payload($data)->final_results_details->$sid->risk_review;
file_put_contents($s->dir.'/http-source.json',MonRun::json($data));
echo mon_json(['php_version'=>PHP_VERSION,'state_dir'=>$s->dir,'run_id'=>$b->run->run_context_id,'received_at'=>$b->run->received_at,'artifact_id'=>$artifact['artifact_id'],'issuer_review'=>$issuer,'symbol_id'=>$sid],false);

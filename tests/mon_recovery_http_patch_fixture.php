<?php
declare(strict_types=1);
define('MON_GATE_LIBRARY_ONLY',true);require __DIR__.'/mon_completion_gate_test.php';
$source=(string)file_get_contents($argv[1]);$data=mon_decode($source);$at=MonBudget::iso(microtime(true));$patch=cg_patch($data,$argv[2],$at,false);$patch->new_evidence=[$patch->new_evidence[0]];$eid=$patch->new_evidence[0]->id;
$patch->changes[0]->trading_review=(object)['status'=>'verified','checked_at'=>$at,'applies_as_of'=>$at,'evidence_ids'=>[$eid]];
if(($argv[3]??'')==='stale')$patch->changes[0]->price_observation->quote_at=mon_iso(time()-301);
if(($argv[3]??'')==='missing')unset($patch->changes[0]->price_observation);
echo mon_json((object)['run_context_id'=>$argv[2],'action'=>'confirm','context'=>$patch->view_context,'source_json'=>$source,'decision_patch'=>$patch,'publish_mode'=>'manual','response_mode'=>'inline'],false);

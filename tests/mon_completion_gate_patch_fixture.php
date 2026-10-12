<?php
declare(strict_types=1);
define('MON_GATE_LIBRARY_ONLY',true);
require __DIR__.'/mon_completion_gate_test.php';
$source=(string)file_get_contents($argv[1]);$d=mon_decode($source);$patch=cg_patch($d,$argv[2],MonBudget::iso(microtime(true)));
echo mon_json((object)['run_context_id'=>$argv[2],'action'=>'confirm','context'=>$patch->view_context,'source_json'=>$source,'decision_patch'=>$patch,'publish_mode'=>'manual'],false);

<?php
declare(strict_types=1);
require dirname(__DIR__).'/mon.php';
$raw=(string)file_get_contents($argv[1]);$summary=(string)file_get_contents($argv[2]);$data=mon_decode($raw);$wall=MonRun::seconds($data->analysis->as_of)+1;$mono=0.;
$dir=sys_get_temp_dir().'/mon-observed-gate-'.bin2hex(random_bytes(6));mkdir($dir,0700);$store=new MonStore($dir);
$budget=MonExecution::start($store,(object)['run_id'=>'observed-payload-code-replay','received_at'=>$data->analysis->as_of,'workload'=>'code_replay'],static function()use(&$wall){return $wall;},static function()use(&$mono){return $mono;});
$files=[['path'=>'mon_data.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)],['path'=>'mon_result.md','content'=>$summary,'blob_sha'=>MonRun::gitSha($summary)]];
$manifest=MonArtifact::create($store,$raw,$files,['decision_sha256'=>$data->analysis->selection_fingerprint,'run_context_id'=>$budget->run->run_context_id,'received_at'=>$budget->run->received_at]);
$transport=static function($method,$url)use($files){if($method!=='GET')throw new RuntimeException('unexpected mutation');$path=parse_url($url,PHP_URL_PATH);
    if(strpos($path,'/git/ref/')!==false)$v=(object)['object'=>(object)['sha'=>str_repeat('a',40)]];
    elseif(strpos($path,'/git/commits/')!==false)$v=(object)['tree'=>(object)['sha'=>str_repeat('b',40)]];
    else{$tree=[];foreach($files as $f)$tree[]=(object)['path'=>$f['path'],'sha'=>$f['blob_sha'],'type'=>'blob'];$v=(object)['tree'=>$tree,'truncated'=>false];}
    return ['status'=>200,'headers'=>[],'body'=>mon_json($v,false)];};
$publication=new MonPublication($store,new MonHttp(null,$transport),new MonConfig('',$dir),static function()use(&$wall){return $wall;},static function()use(&$mono){return $mono;});
$job=$publication->enqueue($manifest['artifact_id'],str_repeat('a',40),$budget,'synthetic_readback');
MonExecution::event('final_prepared',['new_analysis_complete'=>true,'final_available'=>true,'artifact_id'=>$manifest['artifact_id'],'job_id'=>$job['job_id'],'save_status'=>'queued']);
$saved=$publication->process($job['job_id'],microtime(true)+10);$gate=$saved['completion'];
$pass=$saved['publication_complete']&&!$saved['fully_complete']&&$gate['required_checks_pending']>0&&MonRun::gitSha((string)file_get_contents($argv[1]))===MonRun::gitSha($raw);
$result=['version'=>MON_VERSION,'php'=>PHP_VERSION,'tests'=>1,'passed'=>(int)$pass,'failed'=>(int)!$pass,'test_scope'=>'observed 2026-10-12 payload at original analysis time; simulated remote readback; no market run',
    'source_blob_sha'=>MonRun::gitSha($raw),'selected_count'=>count($data->analysis->results),'publication_complete'=>$saved['publication_complete'],'fully_complete'=>$saved['fully_complete'],
    'required_checks_pending'=>$gate['required_checks_pending'],'pending_kinds'=>array_count_values(array_column($gate['pending_jobs'],'kind')),'peak_memory_bytes'=>memory_get_peak_usage(true),'external_requests'=>0,'orders'=>0];
foreach(scandir($dir)as $f)if($f!=='.'&&$f!=='..')unlink($dir.'/'.$f);rmdir($dir);echo mon_json($result);exit($pass?0:1);

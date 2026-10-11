<?php
declare(strict_types=1);
require dirname(__DIR__).'/mon.php';
// Exercises real staged inputs with a hypothetical empty decision, without publishing it.
// The output is validation metadata only; the current market decision is never replaced.
$raw=(string)file_get_contents(dirname(__DIR__).'/mon_data.json');$d=mon_decode($raw);$before=MonRun::gitSha($raw);$started=mon_monotonic();
$context=(object)['source_blob_sha'=>$before,'rendered_at'=>mon_iso(),'countries'=>['KR','US','JP'],'important_ids'=>[]];
$prepared=MonDetails::prepare($d,$context);$prepareSeconds=mon_monotonic()-$started;$preparePeak=memory_get_peak_usage(true);
$maxSelected=[];$perCountry=[];foreach($prepared['card_pages']as $page)foreach($page['cards']as $card){$country=$card->country;if(($perCountry[$country]??0)<3){$maxSelected[]=(object)['symbol_id'=>$card->symbol_id,'reason'=>'Unpublished maximum-size storage simulation; no actual investment decision'];$perCountry[$country]=($perCountry[$country]??0)+1;}}
$decision=(object)['view_context'=>$context,'state_token'=>$prepared['state_token'],'prepared_fingerprint'=>$prepared['prepared_fingerprint'],'fixed_as_of'=>$context->rendered_at,
    'selected'=>[],'references'=>[],'live_observations'=>[]];unset($prepared);
$simulation=MonDetails::finalize($d,$decision);$after=mon_decode($simulation['files'][0]['content']);
$emptyValidation=$simulation['validation'];$emptyRecovery=$after->analysis->source_recovery->state;$emptyPending=$after->analysis->pending_analysis;
$offDay=array_map(static function($r){return MonRun::pick($r,['country','discovery_date','completed_bar_date','goal_status','new_ids_identified_today','daily_goal_met','quota_identified_count','next_session_queued_count','next_trading_date']);},$after->analysis->discovery_state->countries);unset($simulation,$after);
$decision->selected=$maxSelected;$maxSimulation=MonDetails::finalize($d,$decision);$maxAfter=mon_decode($maxSimulation['files'][0]['content']);
$report=['kind'=>'native_staged_input_preparation_and_unpublished_finalization_simulation','processor_version'=>MON_VERSION,'php_version'=>PHP_VERSION,'checked_at'=>$context->rendered_at,
    'prepare_seconds'=>round($prepareSeconds,6),'prepare_peak_memory_bytes'=>$preparePeak,'total_seconds'=>round(mon_monotonic()-$started,6),'total_peak_memory_bytes'=>memory_get_peak_usage(true),
    'hypothetical_case_counts'=>['empty'=>0,'maximum_per_country'=>count($maxSelected)],'new_market_selection_performed'=>false,'remote_requests'=>0,'remote_written'=>false,'orders'=>0,'market_execution_statistics_modified'=>false,
    'validation'=>$emptyValidation,'maximum_case_validation'=>$maxSimulation['validation'],'maximum_case_counts'=>$perCountry,'maximum_case_results'=>count($maxAfter->analysis->results),
    'simulation_pending_analysis'=>$emptyPending,'simulation_source_recovery_state'=>$emptyRecovery,'simulation_discovery'=>$offDay,
    'current_data_unchanged'=>MonRun::gitSha((string)file_get_contents(dirname(__DIR__).'/mon_data.json'))===$before];
if(!$report['current_data_unchanged']||!$emptyValidation['storage']->fits||!$maxSimulation['validation']['storage']->fits||$emptyPending||$emptyRecovery!=='verified_inputs_used_in_autonomous_selection'||count($maxSelected)!==9||count($maxAfter->analysis->results)!==9)throw new RuntimeException('persistence simulation failed');
file_put_contents(__DIR__.'/mon_gap_persistence_observed.json',mon_json($report));echo mon_json($report);

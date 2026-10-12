<?php
declare(strict_types=1);
// Read-only comparison of one frozen real source. No credentials, journals, API or orders.
require $argv[2];
$inputPath=dirname($argv[1]).'/mon_recovery_inputs.json';if(is_file($inputPath))MonGaps::$sourceBundle=(string)file_get_contents($inputPath);
$start=mon_monotonic();$raw=(string)file_get_contents($argv[1]);$sourceSha=MonRun::gitSha($raw);$d=mon_decode($raw);MonRun::validate($d);$p=MonRun::payload($d);MonRun::verifyFacts($d,$p);
if(mon_get($p,'recovery_source_manifest')instanceof stdClass)MonGaps::dependencyRaw($p->recovery_source_manifest,false); // Fail locally before prepare can seek a missing immutable dependency.
$before=MonRun::digest($d);$collection=MonRun::digest($d->collection);$selection=MonRun::digest($d->analysis->results);$metrics=MonRun::digest($p->candidate_metrics);$series=MonRun::digest($p->series);$snapshots=[];
$requests=MonDetails::requests($d);foreach(['JP|TSE|6098','KR|KOSPI|006400','KR|KOSPI|051910','KR|KOSPI|096770','US|NASDAQ|WMT']as $sid){$request=mon_get($requests,$sid);if(!($request instanceof stdClass))throw new RuntimeException('frozen source lacks '.$sid);$snapshot=MonDetails::cached($d,$request->symbol,$p);if(!($snapshot instanceof stdClass))throw new RuntimeException('missing verified snapshot '.$sid);$snapshots[$sid]=MonRun::digest($snapshot);}
unset($p,$snapshot);MonRun::releasePayloadCache();$context=(object)['source_blob_sha'=>$sourceSha,'rendered_at'=>$d->analysis->as_of,'countries'=>['KR','US','JP'],'important_ids'=>[]];$prepared=MonDetails::prepare($d,$context);
$same=MonRun::digest($d)===$before&&MonRun::digest($d->collection)===$collection&&MonRun::digest($d->analysis->results)===$selection&&MonRun::gitSha((string)file_get_contents($argv[1]))===$sourceSha;
echo mon_json(['code'=>MON_VERSION,'php'=>PHP_VERSION,'memory_limit'=>ini_get('memory_limit'),'source_blob_sha'=>$sourceSha,'source_bytes'=>strlen($raw),'source_sha256'=>hash('sha256',$raw),'collection_sha256'=>$collection,'decision_sha256'=>$selection,'metrics_sha256'=>$metrics,'series_sha256'=>$series,'snapshot_sha256'=>$snapshots,'prepared_fingerprint'=>$prepared['prepared_fingerprint'],'source_unchanged'=>$same,'seconds'=>mon_monotonic()-$start,'peak_memory_bytes'=>memory_get_peak_usage(true),'external_requests'=>0,'orders'=>0]);
exit($same?0:1);

<?php
declare(strict_types=1);
/* Read-only capacity probe using repository's published MON data. */
require dirname(__DIR__).'/mon.php';
$raw=file_get_contents(dirname(__DIR__).'/mon_data.json');
if(!is_string($raw))throw new RuntimeException('MON_DATA_READ_FAILED');
$d=mon_decode($raw);MonRun::validate($d);
$symbols=array_values(array_filter($d->watchlist->symbols,static function($s){return mon_get($s,'is_held')===false;}));
$symbols=array_slice($symbols,0,3);
if(!$symbols)throw new RuntimeException('NO_SYMBOL_FOR_PROBE');
$ctx=(object)['source_blob_sha'=>MonRun::gitSha($raw),'rendered_at'=>mon_iso(),'countries'=>['KR','US','JP'],'important_ids'=>array_map(static function($s){return $s->symbol_id;},$symbols)];
$rows=[];
foreach($symbols as $s){$rows[]=(object)['symbol'=>$s,'request_id'=>'capacity-'.substr(hash('sha256',$s->symbol_id),0,40),'force_refresh'=>true,'wait_seconds'=>120];}
$r=MonDetails::registerMany($d,$ctx,$rows);
if(!$r['write_required'])throw new RuntimeException('NO_NEW_REGISTRATION');
$error=array_filter($r['requests'],static function($e){return $e['error']!==null;});
echo json_encode(['mon_source_bytes'=>strlen($raw),'requests'=>count($r['requests']),'errors'=>array_values($error),
    'files'=>array_map(static function($f){return ['path'=>$f['path'],'bytes'=>strlen($f['content'])];},$r['files']),
    'peak_memory_bytes'=>memory_get_peak_usage(true),'version'=>MON_VERSION],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
if($error)exit(2);

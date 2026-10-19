<?php
/**
 * MON 2.2.3 Lite — clean-start, single-file, fixed-runtime, completed-daily swing monitor.
 * PHP 7.4+ / manual web start only / no cron / no orders.
 * Runtime: /web/mon_runtime with a small fixed file set.
 */
declare(strict_types=1);

const MON_LITE_VERSION = '2.2.3';
const MON_LITE_BUILD = 'MON-LITE-2.2.3-20261020-r15';
const MON_LITE_POLICY = 'MON-LITE-OPS-2.2.3';
const MON_LITE_RUNTIME = __DIR__ . '/mon_runtime';
const MON_LITE_GITHUB_REPO = 'wskimgit/stock';
const MON_LITE_GITHUB_BRANCH = 'main';
const MON_LITE_PREFIX = "<?php exit; ?>\n";
define('MON_LITE_SOURCE_SHA256',hash_file('sha256',__FILE__));

class LiteMonFault extends RuntimeException {
    public $faultCode;
    public function __construct(string $code) {
        $this->faultCode = preg_replace('/[^A-Z0-9_.-]/i', '_', $code);
        parent::__construct($this->faultCode);
    }
}
class LiteMonStop extends RuntimeException {}

function lm_get($o, $k, $default=null) {
    if (is_array($o)) return array_key_exists($k,$o)?$o[$k]:$default;
    if (is_object($o)) {$key=(string)$k;return property_exists($o,$key)?$o->$key:$default;}
    return $default;
}
function lm_num($v): ?float {
    if (is_int($v)||is_float($v)) return is_finite((float)$v)?(float)$v:null;
    if (is_string($v) && is_numeric(trim($v))) { $n=(float)$v; return is_finite($n)?$n:null; }
    return null;
}
function lm_json($v): string {
    $s=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    if(!is_string($s)) throw new LiteMonFault('JSON_ENCODE_FAILED');
    return $s;
}
function lm_decode(string $s) {
    $v=json_decode($s); if(json_last_error()!==JSON_ERROR_NONE) throw new LiteMonFault('JSON_DECODE_FAILED'); return $v;
}
function lm_iso(?int $ts=null): string { return gmdate('Y-m-d\TH:i:s\Z',$ts??time()); }
function lm_hash($v): string { return hash('sha256',is_string($v)?$v:lm_json($v)); }
function lm_git_blob_sha(string $bytes): string {$h=hash_init('sha1');hash_update($h,'blob '.strlen($bytes)."\0");hash_update($h,$bytes);return hash_final($h);}
function lm_round6($v): ?float { return $v===null?null:(float)number_format((float)$v,6,'.',''); }
function lm_array($v) {if($v instanceof stdClass)$v=(array)$v;if(is_array($v)){foreach($v as $k=>$x)$v[$k]=lm_array($x);}return $v;}

final class LiteRuntime {
    const MAX_FILE = 16777216;
    const MAX_FILES = 11;           // guard + 6 data + log/current-old + lock + transient temp
    const MAX_TOTAL = 67108864;
    const MAX_LOG = 262144;
    const MIN_FREE = 33554432;
    const FILES = ['state','cache','request','result','evidence','settings'];
    private static $readyDir=null;
    private static function rawFileCount(string $dir): int {
        $count=0;$it=@opendir($dir);if(!$it)return 0;
        try{while(false!==($n=readdir($it))){if($n==='.'||$n==='..')continue;if(is_file($dir.'/'.$n))$count++;}}finally{closedir($it);}return $count;
    }
    public static function ready(): string {
        if(self::$readyDir!==null&&is_dir(self::$readyDir))return self::$readyDir;
        $d=MON_LITE_RUNTIME;if(is_link($d))throw new LiteMonFault('RUNTIME_SYMLINK_REJECTED');
        if(!is_dir($d)&&!@mkdir($d,0700,true))throw new LiteMonFault('RUNTIME_DIRECTORY_UNWRITABLE');
        if(!is_writable($d))throw new LiteMonFault('RUNTIME_DIRECTORY_UNWRITABLE');@chmod($d,0700);
        $guard=$d.'/.mon-lite-runtime';
        if(!is_file($guard)){if(self::rawFileCount($d)>=self::MAX_FILES)throw new LiteMonFault('RUNTIME_FILE_COUNT_LIMIT');if(@file_put_contents($guard,MON_LITE_BUILD."\n",LOCK_EX)===false)throw new LiteMonFault('RUNTIME_GUARD_WRITE_FAILED');@chmod($guard,0600);}
        else{$g=@file_get_contents($guard);if(!is_string($g)||trim($g)!==MON_LITE_BUILD){if(@file_put_contents($guard,MON_LITE_BUILD."\n",LOCK_EX)===false)throw new LiteMonFault('RUNTIME_GUARD_WRITE_FAILED');@chmod($guard,0600);}}
        self::$readyDir=$d;return $d;
    }
    public static function path(string $name): string {
        if(!preg_match('/^[A-Za-z0-9_.-]{1,80}$/',$name))throw new LiteMonFault('STATE_NAME_INVALID');return self::ready().'/'.$name;
    }
    public static function read(string $key) {
        if(!in_array($key,self::FILES,true))throw new LiteMonFault('STATE_NAME_INVALID');$p=self::path($key.'.php');if(!is_file($p))return new stdClass();
        $n=@filesize($p);if($n===false||$n>self::MAX_FILE)throw new LiteMonFault('RUNTIME_FILE_SIZE_LIMIT');$h=@fopen($p,'rb');if(!$h)throw new LiteMonFault('RUNTIME_READ_FAILED');
        try{$prefix=fread($h,strlen(MON_LITE_PREFIX));if($prefix!==MON_LITE_PREFIX)rewind($h);$raw=stream_get_contents($h);if(!is_string($raw))throw new LiteMonFault('RUNTIME_READ_FAILED');return lm_decode($raw);}finally{fclose($h);}
    }
    public static function write(string $key,$value,?callable $merge=null): bool {
        if(!in_array($key,self::FILES,true))throw new LiteMonFault('STATE_NAME_INVALID');
        // Serialize the entire shared-temp replacement; compare/read back using bounded streaming hashes.
        $guard=@fopen(self::path('.mon-lite-runtime'),'r+');if(!$guard||!flock($guard,LOCK_EX)){if($guard)fclose($guard);throw new LiteMonFault('RUNTIME_WRITE_LOCK_FAILED');}
        try{if($merge!==null)$value=$merge(self::read($key),$value);$json=lm_json($value);$length=strlen($json)+strlen(MON_LITE_PREFIX);if($length>self::MAX_FILE)throw new LiteMonFault('RUNTIME_FILE_SIZE_LIMIT');$hash=hash_init('sha256');hash_update($hash,MON_LITE_PREFIX);hash_update($hash,$json);$expected=hash_final($hash);$p=self::path($key.'.php');clearstatcache(true,$p);if(is_file($p)&&@filesize($p)===$length){$current=@hash_file('sha256',$p);if(is_string($current)&&hash_equals($current,$expected))return false;}
            self::capacity($length);$tmp=self::path('.write.tmp');self::allowNewFile($tmp);$h=@fopen($tmp,'wb');if(!$h)throw new LiteMonFault('RUNTIME_WRITE_FAILED');
            try{foreach([MON_LITE_PREFIX,$json] as $part){$offset=0;$partLength=strlen($part);while($offset<$partLength){$written=@fwrite($h,substr($part,$offset,65536));if($written===false||$written===0)throw new LiteMonFault('RUNTIME_WRITE_FAILED');$offset+=$written;}}if(!@fflush($h))throw new LiteMonFault('RUNTIME_FLUSH_FAILED');if(function_exists('fsync')&&!@fsync($h))throw new LiteMonFault('RUNTIME_FLUSH_FAILED');}finally{fclose($h);}
            @chmod($tmp,0600);if(!@rename($tmp,$p)){@unlink($tmp);throw new LiteMonFault('RUNTIME_RENAME_FAILED');}@chmod($p,0600);$check=@hash_file('sha256',$p);if(!is_string($check)||!hash_equals($expected,$check))throw new LiteMonFault('RUNTIME_READBACK_MISMATCH');return true;
        }finally{flock($guard,LOCK_UN);fclose($guard);}
    }
    public static function log(string $event,array $fields=[]): void {
        $p=self::path('mon.log.php');$old=self::path('mon.log.old.php');$row=lm_json(['at'=>lm_iso(),'event'=>$event,'fields'=>$fields])."\n";$new=!is_file($p);self::capacity(strlen($row)+($new?strlen(MON_LITE_PREFIX):0));
        if(is_file($p)&&@filesize($p)>self::MAX_LOG){@unlink($old);@rename($p,$old);$new=true;}self::allowNewFile($p);if($new&&@file_put_contents($p,MON_LITE_PREFIX,LOCK_EX)!==strlen(MON_LITE_PREFIX))throw new LiteMonFault('RUNTIME_LOG_WRITE_FAILED');if(@file_put_contents($p,$row,FILE_APPEND|LOCK_EX)!==strlen($row))throw new LiteMonFault('RUNTIME_LOG_WRITE_FAILED');@chmod($p,0600);
    }
    public static function lock() {$p=self::path('mon.lock');self::allowNewFile($p);$h=@fopen($p,'c+');if(!$h)throw new LiteMonFault('RUNTIME_LOCK_FAILED');@chmod($p,0600);if(!flock($h,LOCK_EX|LOCK_NB)){fclose($h);throw new LiteMonFault('ALREADY_RUNNING');}return $h;}
    public static function lockHeld(): bool {$p=self::path('mon.lock');if(!is_file($p))return false;$h=@fopen($p,'r+');if(!$h)return true;$ok=@flock($h,LOCK_EX|LOCK_NB);if($ok){@flock($h,LOCK_UN);fclose($h);return false;}fclose($h);return true;}
    private static function usage(): array {
        $d=self::ready();$count=0;$bytes=0;$it=@opendir($d);if(!$it)throw new LiteMonFault('RUNTIME_SCAN_FAILED');
        try{while(false!==($n=readdir($it))){if($n==='.'||$n==='..')continue;$p=$d.'/'.$n;if(!is_file($p))continue;$count++;$z=@filesize($p);if($z!==false)$bytes+=(int)$z;}}finally{closedir($it);}return ['files'=>$count,'bytes'=>$bytes];
    }
    private static function allowNewFile(string $path): void {if(is_file($path))return;$u=self::usage();if($u['files']>=self::MAX_FILES)throw new LiteMonFault('RUNTIME_FILE_COUNT_LIMIT');}
    public static function capacity(int $incoming=0): void {$d=self::ready();$free=@disk_free_space($d);if(is_float($free)&&$free<self::MIN_FREE)throw new LiteMonFault('RUNTIME_DISK_RESERVE');$u=self::usage();if($u['files']>self::MAX_FILES)throw new LiteMonFault('RUNTIME_FILE_COUNT_LIMIT');if($u['bytes']+$incoming>self::MAX_TOTAL)throw new LiteMonFault('RUNTIME_CAPACITY_LIMIT');}
    public static function cleanup(): array {$removed=0;$p=self::path('.write.tmp');if(is_file($p)&&time()-(int)@filemtime($p)>3600){@unlink($p);$removed++;}$u=self::usage();return ['removed'=>$removed,'file_count'=>$u['files'],'max_files'=>self::MAX_FILES,'bytes'=>$u['bytes'],'max_bytes'=>self::MAX_TOTAL];}
    public static function view(): array {$d=self::ready();$files=[];$bytes=0;$it=@opendir($d);if($it){try{while(false!==($n=readdir($it))){if($n==='.'||$n==='..')continue;$p=$d.'/'.$n;if(is_file($p)){$z=(int)@filesize($p);$files[]=['name'=>$n,'bytes'=>$z];$bytes+=$z;}}}finally{closedir($it);}}return ['path'=>$d,'file_count'=>count($files),'max_files'=>self::MAX_FILES,'bytes'=>$bytes,'max_bytes'=>self::MAX_TOTAL,'files'=>$files];}
}

final class LiteResource {
    private static function memKb(string $mem,string $key): ?int {return preg_match('/^'.preg_quote($key,'/').':\s+(\d+)\s+kB/m',$mem,$m)?(int)$m[1]:null;}
    public static function memoryInfo(string $mem): array {
        $totalKb=self::memKb($mem,'MemTotal');$availKb=self::memKb($mem,'MemAvailable');$source='MemAvailable';
        if($availKb===null){$free=self::memKb($mem,'MemFree');$buffers=self::memKb($mem,'Buffers')??0;$cached=self::memKb($mem,'Cached')??0;$reclaim=self::memKb($mem,'SReclaimable')??0;$shmem=self::memKb($mem,'Shmem')??0;if($free!==null){$availKb=max(0,$free+$buffers+$cached+$reclaim-$shmem);$source='reclaimable_estimate';}else $source='unknown';}
        return ['total_bytes'=>$totalKb===null?null:$totalKb*1024,'available_bytes'=>$availKb===null?null:$availKb*1024,'source'=>$source];
    }
    public static function limitBytes(string $value): ?int {
        $v=trim($value);if($v===''||$v==='-1'||$v==='0')return null;if(!preg_match('/^(\d+)\s*([KMG]?)$/i',$v,$m))return null;$factor=[''=>1,'K'=>1024,'M'=>1048576,'G'=>1073741824][strtoupper($m[2])];$bytes=(float)$m[1]*$factor;return $bytes>PHP_INT_MAX?PHP_INT_MAX:(int)$bytes;
    }
    private static $previousCpu=null,$cpuObservedAt=null,$lastCpu=null,$previousOwn=null;
    public static function cpuInfo(string $stat): ?array {
        if(!preg_match('/^cpu\s+([^\r\n]+)$/m',$stat,$m))return null;$parts=preg_split('/\s+/',trim($m[1]));if(count($parts)<4)return null;$ticks=[];for($i=0;$i<8;$i++){$v=$parts[$i]??'0';if(!preg_match('/^\d+$/',$v))return null;$ticks[]=(float)$v;}return ['ticks'=>$ticks,'total'=>array_sum($ticks),'idle'=>$ticks[3],'iowait'=>$ticks[4]];
    }
    public static function cpuDelta(?array $before,?array $after,float $seconds): array {
        $r=['cpu_busy_pct'=>null,'cpu_iowait_pct'=>null,'cpu_sample_seconds'=>$seconds];if(!$before||!$after||$seconds<0.1||$seconds>90)return $r;for($i=0;$i<8;$i++)if(!isset($before['ticks'][$i],$after['ticks'][$i])||$after['ticks'][$i]<$before['ticks'][$i])return $r;$total=$after['total']-$before['total'];if($total<=0)return $r;$idle=$after['idle']-$before['idle'];$wait=$after['iowait']-$before['iowait'];$r['cpu_busy_pct']=round(max(0,min(100,100*($total-$idle-$wait)/$total)),2);$r['cpu_iowait_pct']=round(max(0,min(100,100*$wait/$total)),2);return $r;
    }
    public static function cpuPolicy(?float $load,int $cores,?float $busy,?float $age): array {
        $r=['cpu_mode'=>'defer','cpu_batch_limit'=>0,'cpu_reason'=>'CPU_MEASUREMENT_UNKNOWN'];$cores=max(1,$cores);$fresh=$busy!==null&&$busy>=0&&$busy<=100&&$age!==null&&$age>=0&&$age<=90;if($load===null||$load<0)return $r;if($load>=3*$cores){$r['cpu_reason']='CPU_HARD_LOAD';return $r;}if($fresh&&$busy>=95){$r['cpu_reason']='CPU_BUSY';return $r;}if($load<=1.5*$cores)return ['cpu_mode'=>'normal','cpu_batch_limit'=>null,'cpu_reason'=>null];if($fresh&&$busy<=85)return ['cpu_mode'=>'limited','cpu_batch_limit'=>1,'cpu_reason'=>'CPU_SOFT_LOAD'];$r['cpu_reason']=$fresh?'CPU_BUSY':'CPU_MEASUREMENT_UNKNOWN';return $r;
    }
    public static function sample(): array {
        $load=function_exists('sys_getloadavg')?@sys_getloadavg():false;$load1=is_array($load)?(float)$load[0]:null;$stat=(string)@file_get_contents('/proc/stat');$cores=max(1,(int)preg_match_all('/^cpu\d+\s/m',$stat,$m));$now=microtime(true);$cpu=self::cpuInfo($stat);$seconds=self::$previousCpu===null?0:$now-self::$previousCpu['at'];$delta=self::cpuDelta(self::$previousCpu['value']??null,$cpu,$seconds);
        if($delta['cpu_busy_pct']===null&&$load1!==null&&$load1>1.5*$cores&&$load1<3*$cores&&$cpu!==null&&(self::$previousCpu===null||$seconds>90)){$before=$cpu;$start=$now;usleep(100000);$stat=(string)@file_get_contents('/proc/stat');$cpu=self::cpuInfo($stat);$now=microtime(true);$delta=self::cpuDelta($before,$cpu,$now-$start);}
        if($delta['cpu_busy_pct']===null&&($cpu===null||$seconds>=0.1)){self::$lastCpu=null;self::$cpuObservedAt=null;}if($delta['cpu_busy_pct']!==null){self::$lastCpu=$delta;self::$cpuObservedAt=$now;}if($cpu!==null&&(self::$previousCpu===null||$now-self::$previousCpu['at']>=0.1))self::$previousCpu=['value'=>$cpu,'at'=>$now];$age=self::$cpuObservedAt===null?null:max(0,$now-self::$cpuObservedAt);$cpuMetrics=self::$lastCpu??$delta;$cpuMetrics['cpu_sample_age_seconds']=$age;$cpuMetrics['cpu_sample_observed_at']=self::$cpuObservedAt===null?null:lm_iso((int)self::$cpuObservedAt);$policy=self::cpuPolicy($load1,$cores,$cpuMetrics['cpu_busy_pct'],$age);
        $usage=function_exists('getrusage')?@getrusage():false;$own=is_array($usage)?((float)($usage['ru_utime.tv_sec']??0)+(float)($usage['ru_stime.tv_sec']??0)+((float)($usage['ru_utime.tv_usec']??0)+(float)($usage['ru_stime.tv_usec']??0))/1000000):null;$ownPct=null;if($own!==null&&self::$previousOwn!==null&&$now>self::$previousOwn['at'])$ownPct=max(0,100*($own-self::$previousOwn['value'])/($now-self::$previousOwn['at']));if($own!==null)self::$previousOwn=['value'=>$own,'at'=>$now];$rss=null;if(preg_match('/^VmRSS:\s+(\d+)\s+kB/m',(string)@file_get_contents('/proc/self/status'),$rm))$rss=(int)$rm[1]*1024;
        $mi=self::memoryInfo((string)@file_get_contents('/proc/meminfo'));$total=$mi['total_bytes'];$avail=$mi['available_bytes'];$limit=self::limitBytes((string)ini_get('memory_limit'));$used=memory_get_usage(true);$headroom=$limit===null?null:max(0,$limit-$used);$effective=$avail===null?$headroom:($headroom===null?$avail:min($avail,$headroom));$phpOk=$headroom===null||$headroom>=24*1048576;$floor=max(32*1048576,(int)(($total??0)*.06));$memOk=$avail===null||$avail>=$floor;
        return array_merge(['observed_at'=>lm_iso(),'process_sapi'=>PHP_SAPI,'pid'=>getmypid(),'cores'=>$cores,'load1'=>$load1,'own_cpu_seconds'=>$own,'own_cpu_pct'=>$ownPct,'process_rss_bytes'=>$rss,'memory_available_bytes'=>$avail,'memory_available_source'=>$mi['source'],'memory_floor_bytes'=>$floor,'php_memory_limit_bytes'=>$limit,'php_memory_used_bytes'=>$used,'php_memory_usage_bytes'=>memory_get_usage(false),'php_memory_peak_bytes'=>memory_get_peak_usage(true),'php_memory_headroom_bytes'=>$headroom,'effective_memory_available_bytes'=>$effective,'memory_mode'=>!$phpOk||($avail!==null&&$avail<48*1048576)?'low':'normal','heavy_allowed'=>$policy['cpu_mode']==='normal'&&$memOk&&$phpOk,'reason'=>$policy['cpu_mode']!=='normal'?'load':(!$memOk||!$phpOk?'memory':null)],$cpuMetrics,$policy);
    }

}

final class LiteConfig {
    public $githubToken=''; public $phpCli='';
    private static function cliCandidates(): array {
        $x=[];$env=getenv('MON_PHP_CLI');if(is_string($env)&&trim($env)!=='')$x[]=trim($env);
        if(defined('PHP_BINARY')&&is_string(PHP_BINARY)&&PHP_BINARY!==''&&stripos(basename(PHP_BINARY),'fpm')===false)$x[]=PHP_BINARY;
        foreach(['/usr/local/bin/php74','/usr/local/bin/php','/usr/bin/php74','/usr/bin/php','/bin/php','/var/packages/PHP7.4/target/usr/local/bin/php74'] as $p)$x[]=$p;
        return array_values(array_unique($x));
    }
    private static function discoverCli(): string {$c=self::cliCandidates();$path=getenv('PATH');if(is_string($path))foreach(explode(PATH_SEPARATOR,$path) as $d)if($d!=='')foreach(['php74','php'] as $n)$c[]=rtrim($d,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$n;foreach(array_values(array_unique($c)) as $p)if(is_string($p)&&$p!==''&&is_file($p)&&is_executable($p))return $p;return '';}
    public function __construct(){
        $p=__DIR__.'/sis_private_sync_config.php';if(is_file($p)&&is_readable($p)){$x=(static function($f){return @include $f;})($p);if(is_array($x)){if(isset($x['github_token'])&&is_string($x['github_token']))$this->githubToken=trim($x['github_token']);if(isset($x['GITHUB_TOKEN'])&&is_string($x['GITHUB_TOKEN']))$this->githubToken=trim($x['GITHUB_TOKEN']);}}
        try{$s=LiteRuntime::read('settings');}catch(Throwable $ignore){$s=new stdClass();}$v=lm_get($s,'github_token');if(is_string($v)&&trim($v)!=='')$this->githubToken=trim($v);$v=lm_get($s,'php_cli');if(is_string($v)&&trim($v)!=='')$this->phpCli=trim($v);if($this->phpCli==='')$this->phpCli=self::discoverCli();
    }
}

final class LiteHealth {
    private static function execUsable(): bool {
        if(!function_exists('exec'))return false;$disabled=array_filter(array_map('trim',explode(',',(string)ini_get('disable_functions'))));return !in_array('exec',$disabled,true);
    }
    private static function cliProbe(string $php): array {
        $r=['checked'=>true,'ok'=>false,'version_id'=>null,'curl'=>null,'sapi'=>null,'error'=>null];
        if($php===''||!is_file($php)||!is_executable($php)){$r['error']='PHP_CLI_NOT_FOUND';return $r;}
        if(!self::execUsable()){$r['error']='WEB_PROCESS_LAUNCH_DISABLED';return $r;}
        $probe='echo PHP_VERSION_ID,"|",(extension_loaded("curl")?"1":"0"),"|",PHP_SAPI;';
        $out=[];$rc=1;@exec(escapeshellarg($php).' -r '.escapeshellarg($probe).' 2>/dev/null',$out,$rc);
        if($rc!==0){$r['error']='PHP_CLI_PROBE_FAILED';return $r;}$p=explode('|',trim(implode("\n",$out)));if(count($p)!==3||!ctype_digit($p[0])){$r['error']='PHP_CLI_PROBE_INVALID';return $r;}
        $r['version_id']=(int)$p[0];$r['curl']=$p[1]==='1';$r['sapi']=$p[2];$r['ok']=$r['version_id']>=70400&&$r['curl']&&$r['sapi']==='cli';if(!$r['ok'])$r['error']=$r['version_id']<70400?'CLI_PHP_VERSION_UNSUPPORTED':(!$r['curl']?'CLI_PHP_CURL_REQUIRED':'CLI_PHP_SAPI_INVALID');return $r;
    }
    private static function networkProbe(LiteConfig $cfg): array {
        $r=['checked'=>true,'github_read'=>false,'yahoo_read'=>false,'errors'=>[]];
        if(!extension_loaded('curl')){$r['errors'][]='PHP_CURL_REQUIRED';return $r;}
        try{$http=new LiteHttp($cfg);$g=$http->githubGet('mon_chatgpt_handoff.json',true);if(!$g)throw new LiteMonFault('GITHUB_HANDOFF_MISSING');$q=lm_decode($g['content']);if(lm_get($q,'contract')!=='MON-LITE-BRIDGE-1.0'||lm_get($q,'policy')!==MON_LITE_POLICY)throw new LiteMonFault('GITHUB_HANDOFF_CONTRACT_MISMATCH');$r['github_read']=true;}catch(Throwable $e){$r['errors'][]=$e instanceof LiteMonFault?$e->faultCode:'GITHUB_READ_FAILED';}
        try{if(!isset($http))$http=new LiteHttp($cfg);$raw=$http->get('https://query1.finance.yahoo.com/v8/finance/chart/%5EGSPC?range=5d&interval=1d',[],8);$j=lm_decode($raw);$x=lm_get(lm_get(lm_get($j,'chart'),'result'),0);if(!$x)throw new LiteMonFault('YAHOO_EMPTY');$r['yahoo_read']=true;}catch(Throwable $e){$r['errors'][]=$e instanceof LiteMonFault?$e->faultCode:'YAHOO_READ_FAILED';}
        return $r;
    }
    public static function local(LiteConfig $cfg,bool $deep=false): array {
        $blockers=[];$warnings=[];$execOk=self::execUsable();
        if(PHP_VERSION_ID<70400)$blockers[]='PHP_VERSION_UNSUPPORTED';
        if(!extension_loaded('curl'))$blockers[]='PHP_CURL_REQUIRED';
        if($cfg->githubToken==='')$blockers[]='GITHUB_TOKEN_MISSING';
        if($cfg->phpCli===''||!is_file($cfg->phpCli)||!is_executable($cfg->phpCli))$blockers[]='PHP_CLI_NOT_FOUND';
        if(!$execOk)$blockers[]='WEB_PROCESS_LAUNCH_DISABLED';
        try{$runtime=LiteRuntime::view();LiteRuntime::capacity(0);}catch(Throwable $e){$runtime=['path'=>MON_LITE_RUNTIME];$blockers[]=$e instanceof LiteMonFault?$e->faultCode:'RUNTIME_HEALTH_FAILED';}
        $resource=LiteResource::sample();if(!$resource['heavy_allowed'])$warnings[]='RESOURCE_PRESSURE_'.strtoupper((string)$resource['reason']);
        $cli=['checked'=>false,'ok'=>null,'error'=>null];if($deep&&$cfg->phpCli!==''&&$execOk){$cli=self::cliProbe($cfg->phpCli);if(!$cli['ok'])$blockers[]=$cli['error'];}
        $network=['checked'=>false,'github_read'=>null,'yahoo_read'=>null,'errors'=>[]];if($deep&&$cfg->githubToken!==''&&extension_loaded('curl')){$network=self::networkProbe($cfg);foreach($network['errors'] as $e)$warnings[]='NETWORK_'.$e;}
        return ['contract'=>'MON-LITE-NAS-HEALTH-1.1','policy'=>MON_LITE_POLICY,'version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'installed_identity'=>LiteMonitor::installedIdentity(),'deep_checked'=>$deep,'php_version'=>PHP_VERSION,'php_sapi'=>PHP_SAPI,'curl'=>extension_loaded('curl'),'exec'=>$execOk,'php_cli'=>$cfg->phpCli,'cli_probe'=>$cli,'github_configured'=>$cfg->githubToken!=='','network'=>$network,'runtime'=>$runtime,'resource'=>$resource,'blockers'=>array_values(array_unique(array_filter($blockers))),'warnings'=>array_values(array_unique(array_filter($warnings))),'ready_to_start'=>count(array_filter($blockers))===0];
    }
}

final class LiteHttp {
    const MAX_CACHE_BODY=262144;
    const MAX_CACHE_TOTAL=524288;
    const MAX_CACHE_FILES=4;
    const MAX_RESPONSE_BYTES=25165824;
    private $cfg;
    private $handles=[];
    private $githubTurn=[];
    private $githubKnown=[];
    private $stats=[];
    public function __construct(LiteConfig $cfg){$this->cfg=$cfg;$this->resetStats();}
    public function __destruct(){foreach($this->handles as $ch)if($ch)@curl_close($ch);$this->handles=[];}
    public function clearRequestCache(): void {$this->githubTurn=[];}
    public function releasePayloads(): void {$this->githubTurn=[];$total=0;foreach($this->githubKnown as $key=>$v){if(is_array($v)&&strlen($v['content'])>self::MAX_CACHE_BODY)unset($this->githubKnown[$key]);elseif(is_array($v))$total+=strlen($v['content']);}while(count($this->githubKnown)>self::MAX_CACHE_FILES||$total>self::MAX_CACHE_TOTAL){$key=array_key_first($this->githubKnown);$v=$this->githubKnown[$key];if(is_array($v))$total-=strlen($v['content']);unset($this->githubKnown[$key]);}foreach($this->handles as $ch)if($ch)@curl_reset($ch);}
    private function remember(string $path,?array $item): void {unset($this->githubKnown[$path],$this->githubTurn[$path]);if($item!==null&&strlen($item['content'])>self::MAX_CACHE_BODY)return;$this->githubKnown[$path]=$item;$this->githubTurn[$path]=$item;$total=0;foreach($this->githubKnown as $v)if(is_array($v))$total+=strlen($v['content']);while(count($this->githubKnown)>self::MAX_CACHE_FILES||$total>self::MAX_CACHE_TOTAL){$key=array_key_first($this->githubKnown);$v=$this->githubKnown[$key];if(is_array($v))$total-=strlen($v['content']);unset($this->githubKnown[$key],$this->githubTurn[$key]);}}
    public static function responseLimit(): int {$limit=LiteResource::limitBytes((string)ini_get('memory_limit'));if($limit===null)return self::MAX_RESPONSE_BYTES;$room=max(0,$limit-memory_get_usage(true));return min(self::MAX_RESPONSE_BYTES,(int)floor($room/4));}
    public function resetStats(): void {$this->stats=['http_requests'=>0,'http_retries'=>0,'http_304'=>0,'handle_reuses'=>0,'new_connections'=>0,'github_body_cache_hits'=>0,'wire_bytes_received'=>0,'decoded_body_bytes'=>0];}
    public function stats(): array {return $this->stats;}
    private function headers(bool $json=false): array {$h=['Accept: application/vnd.github+json','Authorization: Bearer '.$this->cfg->githubToken];if($json)$h[]='Content-Type: application/json';return $h;}
    private function handle(string $url) {
        if(!extension_loaded('curl'))throw new LiteMonFault('PHP_CURL_REQUIRED');$host=(string)parse_url($url,PHP_URL_HOST);if($host==='')throw new LiteMonFault('HTTP_URL_INVALID');
        if(isset($this->handles[$host])){$this->stats['handle_reuses']++;$ch=$this->handles[$host];@curl_reset($ch);return $ch;}
        $ch=curl_init();if($ch===false)throw new LiteMonFault('HTTP_INIT_FAILED');$this->handles[$host]=$ch;return $ch;
    }
    private static function transientTransport(int $errno): bool {return in_array($errno,[5,6,7,16,18,28,35,52,55,56,92],true);}
    private static function transientStatus(int $status): bool {return in_array($status,[429,502,503,504],true);}
    private static function headerValue(array $headers,string $name): ?string {$k=strtolower($name);return isset($headers[$k])&&$headers[$k]!==''?$headers[$k]:null;}
    private function request(string $method,string $url,array $headers=[],?string $body=null,int $timeout=8,int $maxRetries=1,array $allowStatuses=[]): array {
        $attempt=0;
        while(true){$attempt++;$responseHeaders=[];$raw='';$tooLarge=false;$responseLimit=self::responseLimit();$ch=$this->handle($url);$opts=[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_CONNECTTIMEOUT=>min(4,$timeout),CURLOPT_TIMEOUT=>$timeout,CURLOPT_USERAGENT=>'MON-Lite/'.MON_LITE_VERSION,CURLOPT_ENCODING=>''];
            if(defined('CURLOPT_TCP_KEEPALIVE'))$opts[CURLOPT_TCP_KEEPALIVE]=1;if(defined('CURL_HTTP_VERSION_2TLS'))$opts[CURLOPT_HTTP_VERSION]=CURL_HTTP_VERSION_2TLS;
            if($method!=='GET')$opts[CURLOPT_CUSTOMREQUEST]=$method;if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;if($headers)$opts[CURLOPT_HTTPHEADER]=$headers;
            $opts[CURLOPT_WRITEFUNCTION]=static function($ch,string $chunk)use(&$raw,&$tooLarge,$responseLimit){$n=strlen($chunk);if(strlen($raw)+$n>$responseLimit){$tooLarge=true;return 0;}$raw.=$chunk;return $n;};
            $opts[CURLOPT_HEADERFUNCTION]=static function($ch,string $line)use(&$responseHeaders){$n=strlen($line);$p=strpos($line,':');if($p!==false){$k=strtolower(trim(substr($line,0,$p)));$v=trim(substr($line,$p+1));if($k!=='')$responseHeaders[$k]=$v;}return $n;};curl_setopt_array($ch,$opts);
            $ok=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$wire=(float)curl_getinfo($ch,CURLINFO_SIZE_DOWNLOAD);$newConnections=defined('CURLINFO_NUM_CONNECTS')?(int)curl_getinfo($ch,CURLINFO_NUM_CONNECTS):0;$this->stats['http_requests']++;$this->stats['wire_bytes_received']+=(int)round($wire);$this->stats['new_connections']+=$newConnections;$this->stats['decoded_body_bytes']+=strlen($raw);curl_reset($ch);unset($opts);if($tooLarge){$host=(string)parse_url($url,PHP_URL_HOST);curl_close($ch);unset($this->handles[$host]);throw new LiteMonFault('HTTP_RESPONSE_TOO_LARGE');}if($errno!==0){$host=(string)parse_url($url,PHP_URL_HOST);curl_close($ch);unset($this->handles[$host]);}
            $retry=($errno!==0&&self::transientTransport($errno))||($errno===0&&self::transientStatus($status));
            if($retry&&$attempt<=$maxRetries){$this->stats['http_retries']++;$retryAfter=(int)(self::headerValue($responseHeaders,'retry-after')??0);$delay=$retryAfter>0?min(2.0,(float)$retryAfter):(0.20*$attempt);usleep((int)($delay*1000000));continue;}
            if($errno!==0)throw new LiteMonFault('HTTP_TRANSPORT_'.$errno);if($status===304||in_array($status,$allowStatuses,true))return ['status'=>$status,'body'=>is_string($raw)?$raw:'','headers'=>$responseHeaders];if($status<200||$status>=300)throw new LiteMonFault('HTTP_'.$status);if(!is_string($raw))throw new LiteMonFault('HTTP_EMPTY');return ['status'=>$status,'body'=>$raw,'headers'=>$responseHeaders];
        }
    }
    public function get(string $url,array $headers=[],int $timeout=8): string {$r=$this->request('GET',$url,$headers,null,$timeout,1);return $r['body'];}
    public function githubGet(string $path,bool $force=false): ?array {
        if($this->cfg->githubToken==='')return null;if(!$force&&array_key_exists($path,$this->githubTurn)){$this->stats['github_body_cache_hits']++;return $this->githubTurn[$path];}
        $url='https://api.github.com/repos/'.MON_LITE_GITHUB_REPO.'/contents/'.$path.'?ref='.MON_LITE_GITHUB_BRANCH;$h=$this->headers();$known=$this->githubKnown[$path]??null;$etag=is_array($known)?($known['etag']??null):null;if(!$force&&is_string($etag)&&$etag!=='')$h[]='If-None-Match: '.$etag;
        try{$r=$this->request('GET',$url,$h,null,8,1,[304,404]);}catch(LiteMonFault $e){throw $e;}
        if($r['status']===404){$this->remember($path,null);return null;}
        if($r['status']===304){$this->stats['http_304']++;if(!is_array($known)||!isset($known['content']))throw new LiteMonFault('GITHUB_304_WITHOUT_CACHE');$this->githubTurn[$path]=$known;return $known;}
        $j=lm_decode($r['body']);$sha=(string)lm_get($j,'sha','');$content=lm_get($j,'content');$b=is_string($content)&&$content!==''?base64_decode(str_replace(["\r","\n"],'',$content),true):false;
        if(!is_string($b)){$rawHeaders=['Accept: application/vnd.github.raw+json','Authorization: Bearer '.$this->cfg->githubToken];$b=$this->get($url,$rawHeaders,12);}
        $etag=self::headerValue($r['headers'],'etag');unset($r,$j,$content);$item=['content'=>$b,'sha'=>$sha,'commit_sha'=>null,'etag'=>$etag];$this->remember($path,$item);return $item;
    }
    public function githubPut(string $path,string $content,string $message,?string $oldSha=null): array {
        if($this->cfg->githubToken==='')throw new LiteMonFault('GITHUB_TOKEN_MISSING');$url='https://api.github.com/repos/'.MON_LITE_GITHUB_REPO.'/contents/'.$path;$payload=['message'=>$message,'content'=>base64_encode($content),'branch'=>MON_LITE_GITHUB_BRANCH];if($oldSha!==null)$payload['sha']=$oldSha;
        // PUT is intentionally not auto-retried: a transport failure can occur after GitHub committed the write.
        try{$r=$this->request('PUT',$url,$this->headers(true),lm_json($payload),12,0);}catch(LiteMonFault $e){if(strpos($e->faultCode,'HTTP_')===0&&strpos($e->faultCode,'HTTP_TRANSPORT_')!==0)throw new LiteMonFault('GITHUB_WRITE_'.substr($e->faultCode,5));throw $e;}
        $j=lm_decode($r['body']);$blob=(string)lm_get(lm_get($j,'content'),'sha','');$commit=(string)lm_get(lm_get($j,'commit'),'sha','');if($blob===''||!hash_equals(lm_git_blob_sha($content),$blob))throw new LiteMonFault('GITHUB_BLOB_VERIFY_FAILED');
        // Keep the verified body but discard an old validator so the next forced readback must contact GitHub for fresh representation metadata.
        $item=['content'=>$content,'sha'=>$blob,'commit_sha'=>$commit,'etag'=>null];$this->remember($path,$item);return ['blob_sha'=>$blob,'commit_sha'=>$commit,'unchanged'=>false];
    }
}

final class LiteSwing {
    public static function bars($rows): array {
        if(!is_array($rows)||count($rows)>5000)throw new LiteMonFault('BARS_INVALID');$out=[];$last='';
        foreach($rows as $r){if(!is_array($r)||count($r)<6||!is_string($r[0])||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$r[0])||$r[0]<=$last)throw new LiteMonFault('BAR_ORDER_INVALID');$v=[];for($i=1;$i<6;$i++){$n=lm_num($r[$i]);if($n===null||($i<5?$n<=0:$n<0))throw new LiteMonFault('BAR_VALUE_INVALID');$v[]=$n;}if($v[1]<$v[2]||$v[0]<$v[2]||$v[0]>$v[1]||$v[3]<$v[2]||$v[3]>$v[1])throw new LiteMonFault('BAR_RANGE_INVALID');$out[]=[$r[0],$v[0],$v[1],$v[2],$v[3],$v[4]];$last=$r[0];}return $out;
    }
    private static function macdState(array $closes): array {
        $n=count($closes);$hist=array_fill(0,$n,null);$fast=null;$slow=null;$signal=null;$fastSeed=0.0;$slowSeed=0.0;$signalSeed=0.0;$signalCount=0;$af=2/13;$as=2/27;$ag=2/10;$bf=1-$af;$bs=1-$as;$bg=1-$ag;$lastMacd=null;
        foreach($closes as $i=>$v){$v=(float)$v;if($fast===null){$fastSeed+=$v;if($i===11)$fast=$fastSeed/12;}else $fast=$af*$v+$bf*$fast;if($slow===null){$slowSeed+=$v;if($i===25)$slow=$slowSeed/26;}else $slow=$as*$v+$bs*$slow;if($fast===null||$slow===null)continue;$lastMacd=$fast-$slow;if($signal===null){$signalSeed+=$lastMacd;$signalCount++;if($signalCount<9)continue;$signal=$signalSeed/9;}else $signal=$ag*$lastMacd+$bg*$signal;$hist[$i]=$lastMacd-$signal;}
        return ['macd'=>$lastMacd,'signal'=>$signal,'hist'=>$hist];
    }
    public static function classifyMacd(array $hist,float $atr): array {
        $n=count($hist);if($n<2||$hist[$n-1]===null||$hist[$n-2]===null)return ['kind'=>null,'cross_index'=>null];$last=$hist[$n-1];if($hist[$n-2]<=0&&$last>0)return ['kind'=>'MACD_GOLD','cross_index'=>$n-1];if($last>0)for($i=$n-2;$i>=max(1,$n-4);$i--){if($hist[$i]===null||$hist[$i-1]===null)continue;if($hist[$i-1]<=0&&$hist[$i]>0){$positive=true;for($j=$i;$j<$n;$j++)if($hist[$j]===null||$hist[$j]<=0)$positive=false;if($positive)return ['kind'=>'MACD_AFTER','cross_index'=>$i];}}if($n>=3&&$atr>0&&$last<=0&&$hist[$n-3]!==null&&$hist[$n-2]!==null&&$hist[$n-3]<$hist[$n-2]&&$hist[$n-2]<$last&&abs($last)/$atr<=0.10)return ['kind'=>'MACD_BEFORE','cross_index'=>null];return ['kind'=>null,'cross_index'=>null];
    }
    private static function technicalBars(array $b): array {
        $n=count($b);if($n<15)throw new LiteMonFault('MA_HISTORY_MISSING');$closes=array_column($b,4);$ma=array_fill(0,$n,null);$sum5=0.0;
        foreach($closes as $i=>$v){$sum5+=(float)$v;if($i>=5)$sum5-=(float)$closes[$i-5];if($i>=4)$ma[$i]=$sum5/5;}
        $below=0;for($i=$n-2;$i>=4&&$closes[$i]<$ma[$i];$i--)$below++;$reclaim=$below>=10&&$closes[$n-1]>$ma[$n-1];$atrSum=0.0;$atrStart=max(1,$n-14);for($i=$atrStart;$i<$n;$i++)$atrSum+=max($b[$i][2]-$b[$i][3],abs($b[$i][2]-$closes[$i-1]),abs($b[$i][3]-$closes[$i-1]));$atr=$n-1>=14?$atrSum/14:0.0;$m=self::macdState($closes);$hist=$m['hist'];$kind=self::classifyMacd($hist,$atr);$qualified=[];if($reclaim)$qualified[]='MA5_RECLAIM_CLOSE';if($kind['kind']!==null)$qualified[]=$kind['kind'];$lastHist=$hist[$n-1];return ['formula_version'=>'MON-SWING-1.1','bars_as_of'=>$b[$n-1][0],'bar_count'=>$n,'qualifying_signals'=>$qualified,'technical_qualified'=>count($qualified)>0,'ma5'=>lm_round6($ma[$n-1]),'ma5_below_days_before_last'=>$below,'ma5_reclaim_close'=>$reclaim,'last_close'=>$closes[$n-1],'macd'=>lm_round6($m['macd']),'signal'=>lm_round6($m['signal']),'histogram'=>lm_round6($lastHist),'macd_kind'=>$kind['kind'],'macd_cross_date'=>$kind['cross_index']===null?null:$b[$kind['cross_index']][0],'macd_cross_age_days'=>$kind['cross_index']===null?null:$n-1-$kind['cross_index'],'atr14'=>lm_round6($atr),'macd_gap_atr'=>$atr>0&&$lastHist!==null?lm_round6(abs($lastHist)/$atr):null];
    }
    public static function technical(array $input): array {return self::technicalBars(self::bars($input));}
    private static function tick(array $s,float $value): ?float {
        $country=$s['country'];if($country==='US')return $value>=1?0.01:0.0001;if($country==='KR'){foreach([[2000,1],[5000,5],[20000,10],[50000,50],[200000,100],[500000,500]]as $band)if($value<$band[0])return(float)$band[1];return 1000.0;}if($country==='JP'){foreach([[1000,.1],[3000,.5],[10000,1],[30000,5],[100000,10],[300000,50],[1000000,100],[3000000,500],[10000000,1000],[30000000,5000]] as $band)if($value<=$band[0])return (float)$band[1];return 10000.0;}return null;
    }
    private static function roundTick(array $s,float $value,bool $up): ?float {$step=self::tick($s,$value);if($step===null)return null;for($i=0;$i<4;$i++){$x=($up?ceil($value/$step-1e-10):floor($value/$step+1e-10))*$step;$next=self::tick($s,$x);if($next===$step)return lm_round6($x);$step=$next;}return null;}
    public static function benchmarkMap(array $benchmark): array {$bm=[];foreach($benchmark as $r){if(!is_array($r)||count($r)<5||!is_string($r[0]))throw new LiteMonFault('BENCHMARK_INVALID');$v=lm_num($r[4]);if($v===null||$v<=0)throw new LiteMonFault('BENCHMARK_INVALID');$bm[$r[0]]=$v;}return $bm;}
    public static function ms7FromAnalysis(array $a): array {
        $labels=['M0'=>'관찰','M1'=>'매입 검토','M2'=>'분할매입','W'=>'대기','S1'=>'매도 준비','S2'=>'분할매도','S3'=>'대부분 매도'];
        $m=$a['metrics']??[];$t=$a['technical']??[];$rs=lm_num($m['RS20_pp']??null);$last=lm_num($m['last_close']??null);$ma5=lm_num($m['MA5']??null);$ma20=lm_num($m['MA20']??null);$hist=lm_num($t['histogram']??null);$tech=($t['technical_qualified']??false)===true;
        if($rs===null||$last===null||$ma5===null||$ma20===null)return ['code'=>'W','label'=>$labels['W'],'confidence'=>'PROVISIONAL','reason_codes'=>['data_pending']];
        if($rs<=-15&&$last<$ma20&&($hist===null||$hist<0)){$code='S3';$why=['severe_relative_weakness','below_ma20'];}
        elseif($rs<=-10&&$last<$ma20){$code='S2';$why=['relative_weakness','below_ma20'];}
        elseif($rs<=-5&&$last<$ma20){$code='S1';$why=['weak_relative_strength','below_ma20'];}
        elseif($tech&&$rs>0&&$last>=$ma20){$code='M2';$why=['swing_signal','positive_rs20','above_ma20'];}
        elseif(($tech&&$rs>-2)||($rs>=3&&$last>=$ma5)){$code='M1';$why=['improving_structure'];}
        elseif($rs>0||$last>=$ma5){$code='M0';$why=['relative_or_price_support'];}
        else{$code='W';$why=['signal_not_ready'];}
        return ['code'=>$code,'label'=>$labels[$code],'confidence'=>'CONFIRMED','reason_codes'=>$why];
    }
    public static function analyzeMapped(array $symbol,array $bars,array $bm): array {
        $b=self::bars($bars);$n=count($b);if($n<60)throw new LiteMonFault('HISTORY_MISSING');$start=$n-21;$sum10=0.0;$sum20=0.0;$adv=0.0;$low5=INF;$resistance=-INF;$firstDate=$b[$start][0];$lastDate=$b[$n-1][0];
        for($i=$start;$i<$n;$i++){if(!isset($bm[$b[$i][0]]))throw new LiteMonFault('BENCHMARK_DATE_MISSING');if($i>=$n-20){$sum20+=$b[$i][4];$adv+=$b[$i][4]*$b[$i][5];}if($i>=$n-10)$sum10+=$b[$i][4];if($i>=$n-5)$low5=min($low5,$b[$i][3]);if($i<$n-1)$resistance=max($resistance,$b[$i][2]);}
        $tech=self::technicalBars($b);$last=$b[$n-1][4];$ma10=$sum10/10;$ma20=$sum20/20;$support=max(array_filter([$tech['ma5'],$ma10,$ma20,$low5],function($x)use($last){return $x<=$last;}));$atr=$tech['atr14'];$plans=[];if($atr>0)foreach([['pullback',$support-$atr,$support+$atr,min($low5,$support)-.5*$atr],['breakout',$resistance,$resistance+$atr,$resistance-$atr]]as $q){$lo=self::roundTick($symbol,$q[1],true);$hi=self::roundTick($symbol,$q[2],false);$stop=self::roundTick($symbol,$q[3],false);if($lo!==null&&$hi!==null&&$stop!==null&&$stop>0&&$stop<$lo&&$lo<=$hi)$plans[]=['mode'=>$q[0],'entry_zone'=>[$lo,$hi],'invalidation'=>$stop,'planned_risk_pct'=>lm_round6(100*($hi-$stop)/$hi)];}$stockR=$last/$b[$start][4]-1;$marketR=$bm[$lastDate]/$bm[$firstDate]-1;$metrics=['last_close'=>$last,'bars_as_of'=>$lastDate,'R20'=>lm_round6($stockR),'RS20_pp'=>lm_round6(100*($stockR-$marketR)),'ADV20'=>lm_round6($adv/20),'MA5'=>$tech['ma5'],'MA10'=>lm_round6($ma10),'MA20'=>lm_round6($ma20),'ATR14'=>$atr,'S'=>lm_round6($support),'R'=>lm_round6($resistance)];$eligible=$metrics['RS20_pp']>0&&$tech['technical_qualified'];$a=['symbol_id'=>$symbol['symbol_id'],'country'=>$symbol['country'],'name'=>$symbol['name'],'currency'=>$symbol['currency'],'bars_as_of'=>$lastDate,'metrics'=>$metrics,'technical'=>$tech,'entry_plans'=>$plans,'eligible'=>$eligible,'status'=>$eligible?'pass':'near','reason_codes'=>array_values(array_filter([$metrics['RS20_pp']<=0?'not_stronger_than_market':null,!$tech['technical_qualified']?'no_swing_signal':null]))];$a['ms7']=self::ms7FromAnalysis($a);return $a;
    }
}

final class LiteYahoo {
    const RANGE_CACHE_BARS=40;
    const RANGE_CACHE_SYMBOLS=48;
    private $http;private $rangeCache=[];private $stats=[];private $benchmarkSources=[];private $recoveries=[];
    public function __construct(LiteHttp $http){$this->http=$http;$this->beginTurn();}
    public function beginTurn(): void {$this->rangeCache=[];$this->recoveries=[];$this->stats=['yahoo_range_requests'=>0,'yahoo_range_cache_hits'=>0,'yahoo_rows_dropped'=>0,'yahoo_rows_clamped'=>0,'benchmark_fallbacks'=>0,'jp_benchmark_source'=>null,'yahoo_envelope_recovery_requests'=>0,'yahoo_envelope_recoveries'=>0];}
    public function stats(): array {return $this->stats;}
    public function releasePayloads(): void {$this->rangeCache=[];}
    public static function benchmarkCandidates(string $country,?string $exchange=null): array {
        if($country==='KR')return [$exchange==='KOSDAQ'?'^KQ11':'^KS11'];if($country==='US')return ['^GSPC'];if($country==='JP')return ['^TOPX','1306.T','^N225'];throw new LiteMonFault('BENCHMARK_COUNTRY_INVALID');
    }
    public static function benchmarkFallbackFault(string $code): bool {return in_array($code,['HTTP_404','YAHOO_EMPTY','YAHOO_ROWS_INVALID','YAHOO_CURRENCY_MISMATCH','YAHOO_INVALID_BAR_BURST','YAHOO_LATEST_BAR_INVALID'],true);}
    public static function sanitizeProviderRows(array $rows): array {
        $out=[];$dropped=0;$clamped=0;$n=count($rows);$limit=max(2,(int)ceil(max(1,$n)*0.01));
        foreach($rows as $i=>$r){$bad=false;if(!is_array($r)||count($r)<6||!is_string($r[0])||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$r[0]))$bad=true;$v=[];if(!$bad)for($k=1;$k<6;$k++){$x=lm_num($r[$k]??null);if($x===null||($k<5?$x<=0:$x<0)){$bad=true;break;}$v[$k]=$x;}
            if(!$bad){$o=$v[1];$h=$v[2];$l=$v[3];$c=$v[4];$top=max($o,$c);$bottom=min($o,$c);$tol=max(1e-8,max(abs($o),abs($h),abs($l),abs($c),1.0)*1e-9);if($h+$tol<$l||$h+$tol<$top||$l-$tol>$bottom)$bad=true;else{if($h<$top){$h=$top;$clamped++;}if($l>$bottom){$l=$bottom;$clamped++;}$r[1]=$o;$r[2]=$h;$r[3]=$l;$r[4]=$c;$r[5]=$v[5];}}
            if($bad){if($i===$n-1)throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');$dropped++;if($dropped>$limit)throw new LiteMonFault('YAHOO_INVALID_BAR_BURST');continue;}$out[]=$r;
        }
        return ['rows'=>$out,'dropped'=>$dropped,'clamped'=>$clamped];
    }

    public static function ticker(array $s): string {if(isset($s['raw_ticker']))return (string)$s['raw_ticker'];if($s['country']==='KR')return $s['symbol'].($s['exchange']==='KOSDAQ'?'.KQ':'.KS');if($s['country']==='JP')return $s['symbol'].'.T';return str_replace('.','-',$s['symbol']);}
    // Yahoo daily OHLC/Close is already split-adjusted; never apply split events a second time.
    private static function completed(array $rows,string $country): array {
        if(!$rows)return $rows;$tz=new DateTimeZone(['KR'=>'Asia/Seoul','JP'=>'Asia/Tokyo','US'=>'America/New_York'][$country]);$now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone($tz);$today=$now->format('Y-m-d');$cut=['KR'=>'16:00','JP'=>'16:00','US'=>'16:30'][$country];$last=end($rows);if($last[0]===$today&&$now->format('H:i')<$cut)array_pop($rows);return $rows;
    }
    public function recoveryFor(string $symbolId): ?array {return $this->recoveries[$symbolId]??null;}
    private function recoverEnvelope(array $s,array $rows): array {
        if($s['country']!=='US'||!$rows)throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');$i=count($rows)-1;$daily=$rows[$i];for($k=1;$k<=5;$k++){if(lm_num($daily[$k]??null)===null||($k<5?$daily[$k]<=0:$daily[$k]<0))throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');}$badHigh=$daily[2]<max($daily[1],$daily[4]);$badLow=$daily[3]>min($daily[1],$daily[4]);if(!$badHigh&&!$badLow||$daily[2]<$daily[3])throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');$date=(string)$daily[0];$tz=new DateTimeZone('America/New_York');$start=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' 09:30',$tz);if(!$start||(int)$start->format('N')>5)throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');$end=$start->modify('+390 minutes')->getTimestamp();if($end>time())throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');$p1=$start->getTimestamp();$ticker=rawurlencode(self::ticker($s));$url='https://query1.finance.yahoo.com/v8/finance/chart/'.$ticker.'?period1='.$p1.'&period2='.$end.'&interval=5m&includePrePost=false&includeAdjustedClose=false';$this->stats['yahoo_envelope_recovery_requests']++;
        $j=lm_decode($this->http->get($url,[],8));$r=lm_get(lm_get(lm_get($j,'chart'),'result'),0);if(!$r||lm_get(lm_get($r,'meta'),'currency')!==$s['currency'])throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');$ts=lm_get($r,'timestamp',[]);$q=lm_get(lm_get(lm_get($r,'indicators'),'quote'),0);if(!is_array($ts)||!is_object($q))throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');$slots=[];foreach($ts as $original=>$stamp)if((int)$stamp>=$p1&&(int)$stamp<$end)$slots[]=[(int)$stamp,$original];if(count($slots)!==78)throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');$high=0.0;$low=INF;$first=null;$last=null;
        foreach($slots as $n=>$slot){[$stamp,$original]=$slot;if($stamp!==$p1+$n*300)throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');$o=lm_num(lm_get($q,'open',[])[$original]??null);$h=lm_num(lm_get($q,'high',[])[$original]??null);$l=lm_num(lm_get($q,'low',[])[$original]??null);$c=lm_num(lm_get($q,'close',[])[$original]??null);if($o===null||$h===null||$l===null||$c===null||min($o,$h,$l,$c)<=0||$h<max($o,$c)||$l>min($o,$c)||$h<$l)throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');if($n===0)$first=$o;$last=$c;$high=max($high,$h);$low=min($low,$l);}
        if(abs($first-$daily[1])/$daily[1]>0.001||abs($last-$daily[4])/$daily[4]>0.001||abs($high-$daily[2])/$daily[2]>0.001||abs($low-$daily[3])/$daily[3]>0.001)throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');if($badHigh)$rows[$i][2]=$high;if($badLow)$rows[$i][3]=$low;$clean=self::sanitizeProviderRows($rows);$note=['method'=>'completed_regular_5m_envelope','date'=>$date,'checked_at'=>lm_iso(),'regular_bar_count'=>78,'daily_open_close_volume_preserved'=>true,'source_url'=>$url];$this->recoveries[$s['symbol_id']]=$note;while(count($this->recoveries)>self::RANGE_CACHE_SYMBOLS)unset($this->recoveries[array_key_first($this->recoveries)]);$this->stats['yahoo_envelope_recoveries']++;return $clean;
    }
    private function fetchRange(array $s,int $p1,int $p2): array {
        $tickerRaw=self::ticker($s);$p2Day=gmdate('Y-m-d',$p2);$cached=$this->rangeCache[$tickerRaw]??null;
        if(is_array($cached)&&$cached['p2_day']===$p2Day&&$cached['p1']<=$p1){$this->stats['yahoo_range_cache_hits']++;$from=gmdate('Y-m-d',$p1);return array_values(array_filter($cached['rows'],static function($r)use($from){return $r[0]>=$from;}));}
        $ticker=rawurlencode($tickerRaw);$url='https://query1.finance.yahoo.com/v8/finance/chart/'.$ticker.'?period1='.$p1.'&period2='.$p2.'&interval=1d&events=splits&includeAdjustedClose=false';$this->stats['yahoo_range_requests']++;$j=lm_decode($this->http->get($url,[],8));$r=lm_get(lm_get(lm_get($j,'chart'),'result'),0);if(!$r)throw new LiteMonFault('YAHOO_EMPTY');$m=lm_get($r,'meta');$cur=(string)lm_get($m,'currency');if($cur!==$s['currency'])throw new LiteMonFault('YAHOO_CURRENCY_MISMATCH');$ts=lm_get($r,'timestamp',[]);$q=lm_get(lm_get(lm_get($r,'indicators'),'quote'),0);if(!is_array($ts)||!is_object($q))throw new LiteMonFault('YAHOO_ROWS_INVALID');$rows=[];foreach($ts as $i=>$t){$o=lm_num(lm_get($q,'open',[])[$i]??null);$h=lm_num(lm_get($q,'high',[])[$i]??null);$l=lm_num(lm_get($q,'low',[])[$i]??null);$c=lm_num(lm_get($q,'close',[])[$i]??null);$v=lm_num(lm_get($q,'volume',[])[$i]??null);if($o===null||$h===null||$l===null||$c===null||$v===null)continue;$date=gmdate('Y-m-d',(int)$t);$rows[]=['_ts'=>(int)$t,0=>$date,1=>$o,2=>$h,3=>$l,4=>$c,5=>$v];}$rows=self::completed($rows,$s['country']);try{$clean=self::sanitizeProviderRows($rows);}catch(LiteMonFault $e){if($e->faultCode!=='YAHOO_LATEST_BAR_INVALID'||$s['country']!=='US')throw $e;try{$clean=$this->recoverEnvelope($s,$rows);}catch(Throwable $ignore){throw $e;}}$this->stats['yahoo_rows_dropped']+=$clean['dropped'];$this->stats['yahoo_rows_clamped']+=$clean['clamped'];$rows=LiteSwing::bars($clean['rows']);
        $cacheRows=array_slice($rows,-self::RANGE_CACHE_BARS);$cacheP1=$cacheRows?strtotime($cacheRows[0][0].' 00:00:00 UTC'):$p1;if($cacheP1===false)$cacheP1=$p1;if(!is_array($cached)||$cacheP1<$cached['p1'])$this->rangeCache[$tickerRaw]=['p1'=>$cacheP1,'p2_day'=>$p2Day,'rows'=>$cacheRows];while(count($this->rangeCache)>self::RANGE_CACHE_SYMBOLS)unset($this->rangeCache[array_key_first($this->rangeCache)]);return $rows;
    }
    public function daily(array $s,int $days=220): array {
        $p2=time()+86400;$calendarDays=(int)ceil($days*1.5)+45;$p1=$p2-$calendarDays*86400;return array_slice($this->fetchRange($s,$p1,$p2),-$days);
    }
    public function recent(array $s,string $lastDate,int $overlapDays=21): array {
        $t=strtotime($lastDate.' 00:00:00 UTC');if($t===false)throw new LiteMonFault('INCREMENTAL_DATE_INVALID');$p1=$t-max(7,$overlapDays)*86400;$p2=time()+86400;return $this->fetchRange($s,$p1,$p2);
    }
    public function benchmarkSource(string $country,?string $exchange=null): ?string {return $this->benchmarkSources[$country.'|'.($exchange??'')]??null;}
    public function benchmark(string $country,?string $exchange=null,int $days=220,?string $preferred=null,array $exclude=[]): array {
        $tickers=self::benchmarkCandidates($country,$exchange);if($preferred!==null&&in_array($preferred,$tickers,true))$tickers=array_merge([$preferred],array_values(array_diff($tickers,[$preferred])));$tickers=array_values(array_diff($tickers,$exclude));if(!$tickers)throw new LiteMonFault('BENCHMARK_UNAVAILABLE');$last=null;foreach($tickers as $i=>$ticker){$s=['symbol_id'=>$country.'|INDEX|'.$ticker,'symbol'=>$ticker,'raw_ticker'=>$ticker,'country'=>$country,'exchange'=>$country==='JP'?'TSE':($country==='KR'?($exchange==='KOSDAQ'?'KOSDAQ':'KOSPI'):'NYSE'),'currency'=>['KR'=>'KRW','US'=>'USD','JP'=>'JPY'][$country],'name'=>$ticker];try{$rows=$this->daily($s,$days);$this->benchmarkSources[$country.'|'.($exchange??'')]=$ticker;if($country==='JP')$this->stats['jp_benchmark_source']=$ticker;if($i>0)$this->stats['benchmark_fallbacks']++;return $rows;}catch(LiteMonFault $e){$last=$e;if($country!=='JP'||$i===count($tickers)-1||!self::benchmarkFallbackFault($e->faultCode))throw $e;}}if($last)throw $last;throw new LiteMonFault('BENCHMARK_UNAVAILABLE');
    }
}

final class LiteQuality {
    public static function compare(?array $old,array $new): array {
        if(!$old)return ['state'=>'new','accept'=>true,'reason'=>null];if($old===$new)return ['state'=>'exact','accept'=>true,'reason'=>null];$a=[];foreach($old as $r)$a[$r[0]]=$r;$diff=0;$maxRel=0.0;$last20=array_slice($new,-20);foreach($last20 as $r){if(!isset($a[$r[0]]))continue;$o=$a[$r[0]];for($i=1;$i<=4;$i++){$rel=abs($o[$i]-$r[$i])/max(abs($o[$i]),1e-9);$maxRel=max($maxRel,$rel);if($rel>1e-9)$diff++;}}if($diff===0)return ['state'=>'exact','accept'=>true,'reason'=>null];$oldTech=LiteSwing::technical($old);$newTech=LiteSwing::technical($new);$same=lm_hash([$oldTech['qualifying_signals'],$oldTech['macd_kind'],$oldTech['ma5_reclaim_close']])===lm_hash([$newTech['qualifying_signals'],$newTech['macd_kind'],$newTech['ma5_reclaim_close']]);if($same&&$maxRel<=0.001)return ['state'=>'minor_no_signal_change','accept'=>true,'reason'=>'MINOR_SOURCE_DIFFERENCE_NO_SIGNAL_CHANGE'];return ['state'=>'needs_review','accept'=>false,'reason'=>'MATERIAL_SOURCE_DIFFERENCE'];
    }
}

final class LiteMonitor {
    private $cfg,$http,$yahoo;
    const LIGHT_SECONDS=1800;
    const CHECKPOINT_EVERY=5;
    const FULL_RESYNC_SECONDS=604800;
    const INCREMENTAL_OVERLAP_DAYS=21;
    const FINAL_RECHECK_PER_COUNTRY=3;
    const BENCHMARK_RETRY_TTL=14400;
    const MAILBOX_SECONDS=300;
    const FINAL_RECHECK_TTL=14400;
    const LOW_MEMORY_HARD_FLOOR=20971520;
    const LOW_MEMORY_RETRY_SECONDS=120;
    const CALCULATION_VERSION='MON-LITE-CALC-2.2.3-r15';
    public function __construct(){LiteRuntime::ready();if(function_exists('proc_nice'))@proc_nice(10);$limit=LiteResource::limitBytes((string)ini_get('memory_limit'));if($limit===null||$limit>96*1048576)@ini_set('memory_limit','96M');$this->cfg=new LiteConfig();$this->http=new LiteHttp($this->cfg);$this->yahoo=new LiteYahoo($this->http);}
    public static function nextFullAt(?int $now=null): string {$now=$now??time();$tz=new DateTimeZone('Asia/Seoul');$d=(new DateTimeImmutable('@'.$now))->setTimezone($tz);$target=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$d->format('Y-m-d').' 16:40',$tz);if(!$target)throw new LiteMonFault('SCHEDULE_INVALID');if($target->getTimestamp()<=$now)$target=$target->modify('+1 day');while((int)$target->format('N')>5)$target=$target->modify('+1 day');return lm_iso($target->getTimestamp());}
    public static function defaultState(): stdClass {return (object)['contract'=>'MON-LITE-STATE-1.0','policy'=>MON_LITE_POLICY,'version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'running'=>false,'stop_requested'=>false,'heartbeat_at'=>null,'phase'=>'IDLE','last_light_check'=>null,'last_full_scan'=>null,'next_light_check'=>null,'next_full_scan'=>null,'last_mailbox_check'=>null,'next_mailbox_check'=>null,'last_mailbox_error'=>null,'publication_pending'=>false,'publication_fingerprint'=>null,'last_publication_error'=>null,'last_error'=>null,'progress'=>null,'last_publish_fingerprint'=>null,'last_scan_mode'=>null,'last_network_stats'=>null,'last_memory'=>null,'reference_publication_status'=>null,'run_identity'=>null,'scan_request_seq'=>0,'scan_handled_seq'=>0,'resource_wait'=>null,'historical_error'=>null];}
    public function state(): stdClass {$s=LiteRuntime::read('state');$d=self::defaultState();if(lm_get($s,'contract')!=='MON-LITE-STATE-1.0'||lm_get($s,'policy')!==MON_LITE_POLICY)return $d;foreach(get_object_vars($d) as $k=>$v)if(!property_exists($s,$k))$s->$k=$v;if($s->historical_error===null&&is_string($s->last_error)&&$s->last_error!=='')$s->historical_error=(object)['code'=>$s->last_error,'at'=>$s->heartbeat_at,'phase'=>$s->phase,'legacy'=>true];return $s;}
    public static function installedIdentity(): array {return ['version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'policy'=>MON_LITE_POLICY,'source_sha256'=>MON_LITE_SOURCE_SHA256];}
    private function startState(): stdClass {$s=$this->state();$s->version=MON_LITE_VERSION;$s->build=MON_LITE_BUILD;$s->policy=MON_LITE_POLICY;$s->running=true;$s->stop_requested=false;$s->last_error=null;$s->resource_wait=null;$s->phase='WAIT';$s->heartbeat_at=lm_iso();$s->run_identity=(object)array_merge(self::installedIdentity(),['pid'=>getmypid(),'started_at'=>lm_iso(),'started_unix'=>microtime(true),'sapi'=>PHP_SAPI]);$this->saveState($s,true);return $s;}
    private function idleHeartbeat(): void {$s=$this->state();$at=strtotime((string)lm_get($s,'heartbeat_at',''));if($at===false||time()-$at>=60){$s->heartbeat_at=lm_iso();if($s->running&&!in_array($s->phase,['ERROR','SAVE_PENDING'],true))$s->phase='WAIT';$this->saveState($s);}}
    public function daemonLiveness(): array {$s=$this->state();$held=LiteRuntime::lockHeld();$at=strtotime((string)$s->heartbeat_at);$age=$at===false?null:max(0,time()-$at);$identity=$s->run_identity;$known=is_object($identity)&&is_string(lm_get($identity,'source_sha256'))&&strlen(lm_get($identity,'source_sha256'))===64;$fresh=$age!==null&&$age<=180;$status=!$held?'stopped':(!$fresh?'stale':($known?'confirmed':'alive_identity_unknown'));return ['status'=>$status,'lock_held'=>$held,'heartbeat_age_seconds'=>$age,'heartbeat_fresh'=>$fresh,'identity_known'=>$known,'installed_source_matches'=>$known?hash_equals(MON_LITE_SOURCE_SHA256,(string)lm_get($identity,'source_sha256')):null,'stored_running'=>$s->running];}
    public function finalSaveStatus(): array {try{$r=LiteRuntime::read('result');$status=(string)lm_get($r,'save_status','none');$verified=$status==='saved'&&lm_get($r,'pair_readback_verified')===true&&lm_get($r,'receipt_readback_verified')===true;return ['status'=>$status,'last_local_receipt_verified'=>$verified,'scope'=>'last_local_receipt','saved_at'=>lm_get($r,'saved_at'),'decision_id'=>lm_get($r,'decision_id'),'request_id'=>lm_get($r,'request_id'),'receipt_blob_sha'=>lm_get($r,'receipt_blob_sha')];}catch(Throwable $e){return ['status'=>'unknown','last_local_receipt_verified'=>false,'scope'=>'last_local_receipt'];}}
    public function saveState($s,bool $allowStopReset=false): void {
        LiteRuntime::write('state',$s,static function($disk,$next)use($allowStopReset){if(!$allowStopReset&&lm_get($disk,'stop_requested')===true)$next->stop_requested=true;$next->scan_request_seq=max((int)lm_get($disk,'scan_request_seq',0),(int)lm_get($next,'scan_request_seq',0));$next->scan_handled_seq=max((int)lm_get($disk,'scan_handled_seq',0),(int)lm_get($next,'scan_handled_seq',0));if($next->scan_request_seq>$next->scan_handled_seq){$pending=lm_get($disk,'next_full_scan');if(is_string($pending)&&strtotime($pending)!==false&&(!is_string(lm_get($next,'next_full_scan'))||strtotime($pending)<strtotime($next->next_full_scan)))$next->next_full_scan=$pending;}return $next;});
    }
    public function requestAction(string $action): void {
        if(!in_array($action,['stop','scan'],true))throw new LiteMonFault('WEB_ACTION_INVALID');LiteRuntime::write('state',new stdClass(),static function($disk)use($action){$s=lm_get($disk,'contract')==='MON-LITE-STATE-1.0'&&lm_get($disk,'policy')===MON_LITE_POLICY?$disk:self::defaultState();if($action==='stop')$s->stop_requested=true;else{$s->scan_request_seq=(int)lm_get($s,'scan_request_seq',0)+1;$s->next_full_scan=lm_iso(time()-1);}return $s;});
    }
    private function heartbeat($s,string $phase,?string $err=null): void {$s->heartbeat_at=lm_iso();$s->phase=$phase;$s->last_error=$err;if($err!==null)$s->historical_error=(object)['code'=>$err,'at'=>lm_iso(),'phase'=>$phase];$this->saveState($s);}
    private function cachedWatchlist(?stdClass $c=null): array {$c=$c??$this->cacheObj();$rows=[];foreach((array)lm_get($c,'watchlist',[]) as $x){$a=(array)$x;if(isset($a['symbol_id']))$rows[]=$a;}return $rows;}
    private static function symbolFromId(string $sid,$src=null): ?array {$sid=trim($sid);$p=explode('|',$sid);if(count($p)!==3||!in_array($p[0],['KR','US','JP'],true)||$p[1]===''||$p[2]==='')return null;$ex=['KR'=>['KOSPI','KOSDAQ'],'US'=>['NASDAQ','NYSE','AMEX'],'JP'=>['TSE']];if(!in_array($p[1],$ex[$p[0]],true))return null;$cc=['KR'=>'KRW','US'=>'USD','JP'=>'JPY'];return ['symbol_id'=>$sid,'country'=>$p[0],'exchange'=>$p[1],'symbol'=>$p[2],'name'=>(string)lm_get($src,'name',$p[2]),'currency'=>(string)lm_get($src,'currency',$cc[$p[0]])];}
    private function handoffWatchlist(): array {$m=$this->http->githubGet('mon_chatgpt_handoff.json');if(!$m||trim((string)$m['content'])==='')return [];$q=lm_decode($m['content']);if(lm_get($q,'contract')!=='MON-LITE-BRIDGE-1.0'||lm_get($q,'policy')!==MON_LITE_POLICY||lm_get($q,'stage')!=='candidates')return [];$ids=lm_get($q,'candidate_ids',[]);$rows=[];if(is_array($ids))foreach($ids as $sid){if(!is_string($sid))continue;$a=self::symbolFromId($sid);if($a)$rows[$sid]=$a;}return array_values($rows);}
    private function loadRemoteWatchlist(bool $preferCache=false): array {
        if($preferCache){$cached=$this->cachedWatchlist();if($cached)return $cached;}
        $rows=[];$g=$this->http->githubGet('mon_data.json');if($g&&trim((string)$g['content'])!==''){try{$d=lm_decode($g['content']);if(lm_get($d,'contract')==='MON-LITE-DATA-2.2'&&lm_get($d,'policy')===MON_LITE_POLICY){$w=lm_get(lm_get($d,'watchlist'),'symbols',[]);if(is_array($w))foreach($w as $x){$sid=(string)lm_get($x,'symbol_id');$a=self::symbolFromId($sid,$x);if($a)$rows[$sid]=$a;}}}catch(LiteMonFault $ignore){}}
        if(!$rows)foreach($this->cachedWatchlist() as $x)$rows[$x['symbol_id']]=$x;if(!$rows)foreach($this->handoffWatchlist() as $x)$rows[$x['symbol_id']]=$x;if(!$rows)foreach(self::fallbackCandidates() as $fallbacks)foreach($fallbacks as $f){$a=self::symbolFromId($f['symbol_id'],$f);if($a)$rows[$a['symbol_id']]=$a;}if(!$rows)throw new LiteMonFault('WATCHLIST_FALLBACK_UNAVAILABLE');return array_values($rows);
    }
    private function cacheObj(): stdClass {$c=LiteRuntime::read('cache');if(!in_array(lm_get($c,'contract'),['MON-LITE-CACHE-1.0','MON-LITE-CACHE-1.1','MON-LITE-CACHE-1.2'],true))$c=(object)['contract'=>'MON-LITE-CACHE-1.2','watchlist'=>[],'symbols'=>new stdClass(),'benchmarks'=>new stdClass(),'benchmark_meta'=>new stdClass(),'last_full_resync_at'=>null,'full_resync_pending'=>false,'updated_at'=>null];$c->contract='MON-LITE-CACHE-1.2';if(!property_exists($c,'symbols')||!is_object($c->symbols))$c->symbols=new stdClass();foreach(get_object_vars($c->symbols) as $sid=>$row)if($row instanceof stdClass&&lm_get($row,'analysis') instanceof stdClass)$row->analysis=lm_array($row->analysis);if(!property_exists($c,'benchmarks')||!is_object($c->benchmarks))$c->benchmarks=new stdClass();if(!property_exists($c,'last_full_resync_at'))$c->last_full_resync_at=null;if(!property_exists($c,'full_resync_pending'))$c->full_resync_pending=false;if(!property_exists($c,'benchmark_meta')||!is_object($c->benchmark_meta))$c->benchmark_meta=new stdClass();return $c;}
    private function networkStats(array $stats): array {return array_merge($stats,$this->http->stats(),$this->yahoo->stats());}
    private function pendingCount(stdClass $c): int {$ids=[];$active=[];foreach($this->cachedWatchlist($c) as $sym){$sid=$sym['symbol_id'];$active[$sid]=true;if(!$this->rowCurrent($c,$sym,lm_get($c->symbols,$sid)))$ids[$sid]=true;}foreach((array)lm_get($c,'scan_queue',[]) as $sid)if(is_string($sid)&&isset($active[$sid]))$ids[$sid]=true;return count($ids);}
    private function benchmarkKey(array $s): string {return $s['country']==='KR'?'KR-'.$s['exchange']:$s['country'];}
    private function benchmarkSpec(string $key): array {if($key==='KR-KOSPI')return ['KR','KOSPI'];if($key==='KR-KOSDAQ')return ['KR','KOSDAQ'];if($key==='US')return ['US',null];if($key==='JP')return ['JP',null];throw new LiteMonFault('BENCHMARK_KEY_INVALID');}
    private function loadBenchmark(stdClass $c,string $key,bool $forceFull,bool $retryOnly,array &$stats): array {
        [$country,$exchange]=$this->benchmarkSpec($key);$candidates=LiteYahoo::benchmarkCandidates($country,$exchange);$cached=lm_get($c->benchmarks,$key);$meta=lm_get($c->benchmark_meta,$key,new stdClass());$source=(string)lm_get($meta,'source_ticker','');$known=in_array($source,$candidates,true);$goodAt=strtotime((string)lm_get($meta,'last_good_at',lm_get($meta,'checked_at','')));$fresh=$retryOnly&&$known&&lm_get($meta,'status')==='ok'&&is_array($cached)&&count($cached)>=60&&$goodAt!==false&&time()-$goodAt<self::BENCHMARK_RETRY_TTL;
        try{if($fresh){$rows=$cached;$stats['benchmark_cache_hits']++;}else{$stats['benchmark_downloads']++;$full=$forceFull||!$known||!is_array($cached)||count($cached)<60;if(!$full){$spec=['symbol_id'=>$country.'|INDEX|'.$key,'symbol'=>$source,'raw_ticker'=>$source,'country'=>$country,'exchange'=>$exchange??($country==='US'?'NYSE':'TSE'),'currency'=>['KR'=>'KRW','US'=>'USD','JP'=>'JPY'][$country],'name'=>$key];try{$rows=$this->fetchSeries($spec,$cached,false,$stats);}catch(LiteMonFault $e){if($country!=='JP'||!LiteYahoo::benchmarkFallbackFault($e->faultCode))throw $e;$rows=$this->yahoo->benchmark($country,$exchange,220,null,[$source]);$source=method_exists($this->yahoo,'benchmarkSource')?(string)$this->yahoo->benchmarkSource($country,$exchange):$candidates[0];$full=true;}}
                else{$rows=$this->yahoo->benchmark($country,$exchange,220,$known?$source:null);$source=method_exists($this->yahoo,'benchmarkSource')?(string)$this->yahoo->benchmarkSource($country,$exchange):$candidates[0];}if(!in_array($source,$candidates,true))throw new LiteMonFault('BENCHMARK_SOURCE_UNKNOWN');$rows=LiteSwing::bars($rows);if(count($rows)<60)throw new LiteMonFault('BENCHMARK_TOO_SHORT');}
            $map=LiteSwing::benchmarkMap($rows);$fingerprint=lm_hash([$source,$map]);$last=$rows[count($rows)-1];$c->benchmarks->{$key}=$rows;$c->benchmark_meta->{$key}=(object)['source_ticker'=>$source,'checked_at'=>lm_iso(),'last_good_at'=>$fresh?lm_get($meta,'last_good_at',lm_get($meta,'checked_at')):lm_iso(),'last_date'=>$last[0],'map_fingerprint'=>$fingerprint,'status'=>'ok','last_error'=>null];return ['rows'=>$rows,'map'=>$map,'source_ticker'=>$source,'map_fingerprint'=>$fingerprint];
        }catch(Throwable $e){$m=lm_array($meta);if(!is_array($m))$m=[];$m['status']='unavailable';$m['checked_at']=lm_iso();$m['last_error']=$e instanceof LiteMonFault?$e->faultCode:'BENCHMARK_FAILED';$c->benchmark_meta->{$key}=(object)$m;throw $e;}
    }
    private function rowCurrent(stdClass $c,array $sym,$row): bool {
        if(!$row||lm_get($row,'status')!=='ok'||!is_array(lm_get($row,'analysis'))||lm_get($row,'calculation_version')!==self::CALCULATION_VERSION)return false;$meta=lm_get($c->benchmark_meta,$this->benchmarkKey($sym));$fingerprint=lm_get($meta,'map_fingerprint');return lm_get($meta,'status')==='ok'&&is_string($fingerprint)&&$fingerprint!==''&&lm_get($row,'benchmark_fingerprint')===$fingerprint;
    }
    private function buildCurrentResults(stdClass $c,array $watch): array {
        $results=[];$errors=[];$done=0;$pending=0;$queued=array_fill_keys((array)lm_get($c,'scan_queue',[]),true);
        foreach($watch as $sym){$r=lm_get($c->symbols,$sym['symbol_id']);if(!isset($queued[$sym['symbol_id']])&&$this->rowCurrent($c,$sym,$r)){$a=$r->analysis;if(!isset($a['ms7'])||!is_array($a['ms7']))$a['ms7']=LiteSwing::ms7FromAnalysis($a);$fc=lm_get($r,'final_check');if($fc instanceof stdClass)$a['final_check']=(array)$fc;$a['selection_ready']=lm_get($fc,'status')!=='needs_review'&&($a['selection_ready']??true)!==false;$results[]=$a;$done++;}else{$pending++;$errors[]=['symbol_id'=>$sym['symbol_id'],'error'=>isset($queued[$sym['symbol_id']])?'QUEUED_REFRESH':($r?(string)lm_get($r,'last_error','ANALYSIS_REFRESH_REQUIRED'):'PENDING')];}}
        usort($results,function($a,$b){return $b['metrics']['RS20_pp']<=>$a['metrics']['RS20_pp']?:$b['metrics']['R20']<=>$a['metrics']['R20']?:$b['metrics']['ADV20']<=>$a['metrics']['ADV20']?:strcmp($a['symbol_id'],$b['symbol_id']);});
        $rank=1;foreach($results as &$r){$r['rank_hint']=$rank++;}unset($r);return [$results,$errors,$done,$pending];
    }
    private static function ms7Priority(string $code): int {$m=['M2'=>0,'M1'=>1,'M0'=>2,'W'=>3,'S1'=>4,'S2'=>5,'S3'=>6];return $m[$code]??9;}
    private static function fallbackCandidates(): array {return [
        'KR'=>[['symbol_id'=>'KR|KOSPI|005930','name'=>'삼성전자'],['symbol_id'=>'KR|KOSPI|000660','name'=>'SK하이닉스'],['symbol_id'=>'KR|KOSPI|005380','name'=>'현대차']],
        'US'=>[['symbol_id'=>'US|NASDAQ|MSFT','name'=>'Microsoft'],['symbol_id'=>'US|NASDAQ|NVDA','name'=>'NVIDIA'],['symbol_id'=>'US|NASDAQ|GOOGL','name'=>'Alphabet A']],
        'JP'=>[['symbol_id'=>'JP|TSE|7203','name'=>'Toyota'],['symbol_id'=>'JP|TSE|6758','name'=>'Sony'],['symbol_id'=>'JP|TSE|8306','name'=>'MUFG']]
    ];}
    public static function recommendThree(array $rows,array $candidateIds=[]): array {
        $countries=['KR','US','JP'];$out=['KR'=>[],'US'=>[],'JP'=>[]];$used=[];$evaluated=[];
        foreach($rows as $r){if(!is_array($r))continue;$sid=(string)($r['symbol_id']??'');$p=explode('|',$sid);$country=$r['country']??($p[0]??'');if(!in_array($country,$countries,true)||!isset($r['metrics']))continue;if(!isset($r['ms7'])||!is_array($r['ms7']))$r['ms7']=LiteSwing::ms7FromAnalysis($r);$evaluated[]=$r;}
        usort($evaluated,function($a,$b){$pa=self::ms7Priority((string)($a['ms7']['code']??'W'));$pb=self::ms7Priority((string)($b['ms7']['code']??'W'));if($pa!==$pb)return $pa<=>$pb;$ra=(float)($a['metrics']['RS20_pp']??-999);$rb=(float)($b['metrics']['RS20_pp']??-999);if($ra!==$rb)return $rb<=>$ra;$xa=(float)($a['metrics']['R20']??-999);$xb=(float)($b['metrics']['R20']??-999);return $xb<=>$xa?:strcmp((string)$a['symbol_id'],(string)$b['symbol_id']);});
        foreach($evaluated as $r){$country=$r['country'];if(count($out[$country])>=3)continue;$sid=$r['symbol_id'];if(isset($used[$sid]))continue;$out[$country][]=['symbol_id'=>$sid,'country'=>$country,'name'=>$r['name']??explode('|',$sid)[2],'bars_as_of'=>$r['bars_as_of']??null,'last_close'=>$r['metrics']['last_close']??null,'ms7'=>$r['ms7'],'data_status'=>'evaluated','reason_codes'=>$r['reason_codes']??[]];$used[$sid]=true;}
        foreach($candidateIds as $sid){if(!is_string($sid)||isset($used[$sid]))continue;$p=explode('|',$sid);$country=$p[0]??'';if(!in_array($country,$countries,true)||count($out[$country])>=3)continue;$out[$country][]=['symbol_id'=>$sid,'country'=>$country,'name'=>$p[2]??$sid,'bars_as_of'=>null,'last_close'=>null,'ms7'=>['code'=>'W','label'=>'대기','confidence'=>'PROVISIONAL','reason_codes'=>['data_pending']],'data_status'=>'pending','reason_codes'=>['data_pending']];$used[$sid]=true;}
        foreach(self::fallbackCandidates() as $country=>$fallbacks)foreach($fallbacks as $f){if(count($out[$country])>=3)break;$sid=$f['symbol_id'];if(isset($used[$sid]))continue;$out[$country][]=['symbol_id'=>$sid,'country'=>$country,'name'=>$f['name'],'bars_as_of'=>null,'last_close'=>null,'ms7'=>['code'=>'W','label'=>'대기','confidence'=>'PROVISIONAL','reason_codes'=>['fallback_no_data']],'data_status'=>'fallback','reason_codes'=>['fallback_no_data']];$used[$sid]=true;}
        return $out;
    }
    public static function normalizeChatgptDecision(array $selected): array {
        $labels=['M0'=>'관찰','M1'=>'매입 검토','M2'=>'분할매입','W'=>'대기','S1'=>'매도 준비','S2'=>'분할매도','S3'=>'대부분 매도'];$per=['KR'=>0,'US'=>0,'JP'=>0];$out=[];$seen=[];
        foreach($selected as $x){if(!is_array($x))throw new LiteMonFault('DECISION_ITEM_INVALID');$sid=trim((string)($x['symbol_id']??''));$sym=self::symbolFromId($sid);if(!$sym)throw new LiteMonFault('DECISION_SYMBOL_INVALID');if(isset($seen[$sid]))throw new LiteMonFault('DECISION_DUPLICATE_SYMBOL');$seen[$sid]=true;$country=$sym['country'];if(isset($x['country'])&&$x['country']!==$country)throw new LiteMonFault('DECISION_COUNTRY_MISMATCH');$per[$country]++;if($per[$country]>3)throw new LiteMonFault('DECISION_LIMIT_INVALID');
            $name=trim((string)($x['name']??''));if($name===''||strcasecmp($name,(string)$sym['symbol'])===0)throw new LiteMonFault('DECISION_NAME_REQUIRED');$reason=trim((string)($x['reason']??''));$risk=trim((string)($x['risk_note']??''));if(strlen($reason)<8||strlen($risk)<4)throw new LiteMonFault('DECISION_ANALYSIS_TOO_THIN');
            $ms=$x['ms7']??null;if(!is_array($ms))throw new LiteMonFault('DECISION_MS7_REQUIRED');$code=(string)($ms['code']??'');if(!isset($labels[$code]))throw new LiteMonFault('DECISION_MS7_INVALID');
            $y=$x;$y['symbol_id']=$sid;$y['country']=$country;$y['name']=$name;$y['reason']=$reason;$y['risk_note']=$risk;$y['ms7']=['code'=>$code,'label'=>$labels[$code],'confidence'=>'CHATGPT_FINAL','reason_codes'=>(array)($ms['reason_codes']??[])];$y['authority']='chatgpt_final';$out[]=$y;
        }
        return $out;
    }
    private function stopRequested(): bool {return lm_get($this->state(),'stop_requested')===true;}
    private function resyncDue(stdClass $c): bool {$t=lm_get($c,'last_full_resync_at');return !is_string($t)||strtotime($t)===false||time()-(int)strtotime($t)>=self::FULL_RESYNC_SECONDS;}
    private function mergeBars(array $old,array $recent,int $limit=220): array {
        if(!$old)return array_slice(LiteSwing::bars($recent),-$limit);$map=[];foreach(LiteSwing::bars($old) as $r)$map[$r[0]]=$r;foreach(LiteSwing::bars($recent) as $r)$map[$r[0]]=$r;ksort($map,SORT_STRING);return array_slice(array_values($map),-$limit);
    }
    private function fetchSeries(array $sym,?array $oldBars,bool $forceFull,array &$stats): array {
        if($forceFull||!$oldBars||count($oldBars)<60){$stats['full_downloads']++;return $this->yahoo->daily($sym,220);}
        $last=$oldBars[count($oldBars)-1][0];$stats['incremental_downloads']++;$recent=$this->yahoo->recent($sym,$last,self::INCREMENTAL_OVERLAP_DAYS);if(!$recent)return $oldBars;$merged=$this->mergeBars($oldBars,$recent,220);
        $overlapOld=[];foreach($oldBars as $r)$overlapOld[$r[0]]=$r;$changed=false;foreach($recent as $r)if(isset($overlapOld[$r[0]])&&$overlapOld[$r[0]]!==$r){$changed=true;break;}
        if($changed){$q=LiteQuality::compare($oldBars,$merged);if(!$q['accept']){$stats['fallback_full_downloads']++;return $this->yahoo->daily($sym,220);}}return $merged;
    }
    private function analysisReusable($old,array $bars,?string $benchmarkFingerprint=null): bool {return $benchmarkFingerprint!==null&&lm_get($old,'benchmark_fingerprint')===$benchmarkFingerprint&&lm_get($old,'calculation_version')===self::CALCULATION_VERSION&&$old&&lm_get($old,'status')==='ok'&&is_array(lm_get($old,'bars'))&&$old->bars===$bars&&is_array(lm_get($old,'analysis'));}
    private function shortlist(array $results): array {
        $by=[];foreach($results as $r)if(($r['eligible']??false)===true)$by[$r['country']][]=$r;foreach($by as &$rows){usort($rows,function($a,$b){return $a['rank_hint']<=>$b['rank_hint'];});$rows=array_slice($rows,0,self::FINAL_RECHECK_PER_COUNTRY);}unset($rows);return $by;
    }
    private function finalRecheck(stdClass $c,array $results,array &$stats): array {
        $short=$this->shortlist($results);$checked=[];
        foreach($short as $rows)foreach($rows as $a){
            $sid=$a['symbol_id'];$row=lm_get($c->symbols,$sid);if(!$row||!is_array(lm_get($row,'bars')))continue;$sym=(array)$row->symbol;$oldBars=$row->bars;$last=$oldBars[count($oldBars)-1];$prior=lm_get($row,'final_check');$priorAt=$prior instanceof stdClass?strtotime((string)lm_get($prior,'checked_at','')):false;
            if($prior instanceof stdClass&&lm_get($prior,'status')==='confirmed'&&lm_get($prior,'date')===$last[0]&&abs((float)lm_get($prior,'close',0)-(float)$last[4])/max(abs((float)$last[4]),1e-9)<=0.001&&$priorAt!==false&&time()-$priorAt<self::FINAL_RECHECK_TTL){$stats['final_recheck_cache_hits']++;$row->analysis['final_check']=(array)$prior;$row->analysis['selection_ready']=true;$c->symbols->{$sid}=$row;$checked[$sid]=true;continue;}
            try{$stats['final_rechecks']++;$recent=$this->yahoo->recent($sym,$last[0],10);if(!$recent)throw new LiteMonFault('FINAL_RECHECK_EMPTY');$newLast=$recent[count($recent)-1];$ok=$newLast[0]===$last[0]&&abs($newLast[4]-$last[4])/max(abs($last[4]),1e-9)<=0.001;$row->final_check=(object)['status'=>$ok?'confirmed':'needs_review','checked_at'=>lm_iso(),'date'=>$newLast[0],'close'=>$newLast[4]];$row->analysis['final_check']=(array)$row->final_check;$row->analysis['selection_ready']=$ok;if(!$ok){$row->last_error='FINAL_RECHECK_MISMATCH';}$c->symbols->{$sid}=$row;$checked[$sid]=$ok;}
            catch(Throwable $e){$code=$e instanceof LiteMonFault?$e->faultCode:'FINAL_RECHECK_FAILED';$row->final_check=(object)['status'=>'unavailable','checked_at'=>lm_iso(),'error'=>$code];$row->analysis['final_check']=(array)$row->final_check;$row->analysis['selection_ready']=true;$row->last_error=$code;$c->symbols->{$sid}=$row;$checked[$sid]=null;}
        }
        return $checked;
    }
    private function mailboxCheck(): void {
        $error=null;try{$this->processMailbox();}catch(Throwable $e){$error=$e instanceof LiteMonFault?$e->faultCode:'MAILBOX_FAILED';if($this->decisionPending())$this->recordDecisionError($e);try{LiteRuntime::log('mailbox_error',['code'=>$error]);}catch(Throwable $ignore){}}
        $s=$this->state();$s->last_mailbox_check=lm_iso();$s->next_mailbox_check=lm_iso(time()+self::MAILBOX_SECONDS);$s->last_mailbox_error=$error;$s->heartbeat_at=lm_iso();$this->saveState($s);
    }
    private function decisionPending($result=null): bool {$r=$result??LiteRuntime::read('result');return in_array(lm_get($r,'save_status'),['pending_remote','pending_receipt'],true)||(lm_get($r,'save_status')==='saved'&&lm_get($r,'authority')==='chatgpt_final'&&lm_get($r,'receipt_readback_verified')!==true);}
    private function retryPublication(): void {
        // The durable final outbox always has priority over reference publication.
        if($this->decisionPending()){
            try{$this->resumeDecision();}catch(Throwable $e){$this->recordDecisionError($e);return;}
        }
        $s=$this->state();if(lm_get($s,'publication_pending')!==true)return;$e=LiteRuntime::read('evidence');$summary=lm_array(lm_get($e,'latest_scan'));unset($e);if(is_array($summary)&&(int)lm_get(lm_get($summary,'network_stats'),'queue_remaining',0)>0)return;if(!is_array($summary)){$s->last_publication_error='PUBLICATION_SUMMARY_MISSING';$this->saveState($s);return;}$watch=$this->cachedWatchlist();if(!$watch){$s->last_publication_error='PUBLICATION_WATCHLIST_MISSING';$this->saveState($s);return;}
        try{if($this->publishData($summary,$watch)){$s=$this->state();$s->last_publish_fingerprint=(string)lm_get($s,'publication_fingerprint','');$s->publication_pending=false;$s->last_publication_error=null;$this->saveState($s);}}
        catch(Throwable $e){$s=$this->state();$s->publication_pending=true;$s->last_publication_error=$e instanceof LiteMonFault?$e->faultCode:'PUBLICATION_FAILED';$this->saveState($s);try{LiteRuntime::log('publication_retry_pending',['code'=>$s->last_publication_error]);}catch(Throwable $ignore){}}
    }
    private function recordDecisionError(Throwable $e): void {
        $code=$e instanceof LiteMonFault?$e->faultCode:'FINAL_DECISION_RETRY_FAILED';if($code==='CONCURRENT_DECISION_CONFLICT'&&$this->supersedeVerifiedDecision())return;$r=LiteRuntime::read('result');if($this->decisionPending($r)){$r->last_error=$code;LiteRuntime::write('result',$r);}$s=$this->state();$s->publication_pending=true;$s->last_publication_error=$code;$s->phase='SAVE_PENDING';$s->next_mailbox_check=lm_iso(time()+self::MAILBOX_SECONDS);$this->saveState($s);
    }
    public static function lowMemoryBatch(array $res): int {
        $avail=lm_num($res['memory_available_bytes']??null);$room=lm_num($res['php_memory_headroom_bytes']??null);if(($avail!==null&&$avail<self::LOW_MEMORY_HARD_FLOOR)||($room!==null&&$room<8*1048576))return 0;$host=$avail!==null&&$avail<24*1048576?1:($avail!==null&&$avail<30*1048576?2:3);$php=$room!==null&&$room<12*1048576?1:($room!==null&&$room<20*1048576?2:3);return min($host,$php);
    }
    private function endTurn(): void {if(method_exists($this->http,'releasePayloads'))$this->http->releasePayloads();if(method_exists($this->yahoo,'releasePayloads'))$this->yahoo->releasePayloads();self::releaseMemory();}
    private static function releaseMemory(): void {if(function_exists('gc_collect_cycles'))@gc_collect_cycles();if(function_exists('gc_mem_caches'))@gc_mem_caches();}
    private function resourcePause(stdClass $s,array $resource,string $reason): void {
        $prior=lm_get($s,'resource_wait');$s->resource_wait=(object)['reason'=>$reason,'since'=>lm_get($prior,'reason')===$reason?lm_get($prior,'since',lm_iso()):lm_iso(),'checked_at'=>lm_iso(),'resource'=>$resource];$s->last_error=null;$s->scan_handled_seq=max((int)lm_get($s,'scan_handled_seq',0),(int)lm_get($s,'scan_request_seq',0));$s->next_light_check=lm_iso(time()+self::LOW_MEMORY_RETRY_SECONDS);$s->next_full_scan=lm_iso(time()+self::LOW_MEMORY_RETRY_SECONDS);$this->heartbeat($s,'WAIT');$e=LiteRuntime::read('evidence');if(!$e instanceof stdClass)$e=new stdClass();$e->contract='MON-LITE-EVIDENCE-1.1';$e->resource=$resource;$e->observed_at=lm_iso();LiteRuntime::write('evidence',$e);
    }
    private static function cpuMode(array $res): string {return (string)($res['cpu_mode']??(($res['reason']??null)==='load'?'defer':'normal'));}
    public function fullScan(bool $retryOnly=false): array {
        $this->endTurn();$this->http->clearRequestCache();$this->http->resetStats();$this->yahoo->beginTurn();if($this->decisionPending())$this->resumeDecision();$s=$this->state();if(!is_string($s->last_full_scan)){$this->mailboxCheck();$s=$this->state();}$res=LiteResource::sample();$cpuMode=self::cpuMode($res);$memoryBatch=self::lowMemoryBatch($res);$lowMemory=($res['memory_mode']??'normal')==='low'||(!$res['heavy_allowed']&&($res['reason']??null)==='memory');$cpuLimited=$cpuMode==='limited';$bounded=$lowMemory||$cpuLimited;$batch=$bounded?min($memoryBatch,$cpuLimited?1:3):PHP_INT_MAX;
        if($cpuMode==='defer'||$memoryBatch===0){$reason=$cpuMode==='defer'?(string)($res['cpu_reason']??'CPU_PRESSURE'):'MEMORY_HARD_FLOOR';$this->resourcePause($s,$res,$reason);$this->endTurn();return ['deferred'=>true,'resource'=>$res,'reason'=>$reason];}
        $scanSeq=(int)lm_get($s,'scan_request_seq',0);$s->resource_wait=null;$this->heartbeat($s,'SCAN');$watch=$this->loadRemoteWatchlist($retryOnly);$c=$this->cacheObj();$c->watchlist=array_map(static function($x){return(object)$x;},$watch);$forceFull=(!$retryOnly&&$this->resyncDue($c))||lm_get($c,'full_resync_pending')===true;if($forceFull)$c->full_resync_pending=true;$baseMode=$forceFull?'full_resync':($retryOnly?'retry_incremental':'incremental');$mode=($cpuLimited?'cpu_limited_':($lowMemory?'low_memory_':'')).$baseMode;
        $stats=['mode'=>$mode,'low_memory_active'=>$lowMemory,'low_memory_batch_size'=>$lowMemory?$batch:0,'cpu_limited_active'=>$cpuLimited,'cpu_early_stop'=>false,'resource_before'=>$res,'memory_early_stop'=>false,'full_downloads'=>0,'incremental_downloads'=>0,'fallback_full_downloads'=>0,'analysis_reused'=>0,'analysis_recomputed'=>0,'benchmark_downloads'=>0,'benchmark_cache_hits'=>0,'benchmark_blocked_symbols'=>0,'benchmark_sources'=>[],'final_rechecks'=>0,'final_recheck_cache_hits'=>0];
        $watchIds=[];foreach($watch as $sym)$watchIds[$sym['symbol_id']]=true;$queuedIds=[];foreach((array)lm_get($c,'scan_queue',[]) as $sid)if(is_string($sid)&&isset($watchIds[$sid]))$queuedIds[$sid]=true;$cleanQueue=array_keys($queuedIds);if((array)lm_get($c,'scan_queue',[])!==$cleanQueue){$c->scan_queue=$cleanQueue;LiteRuntime::write('cache',$c);}unset($watchIds,$cleanQueue);$targets=[];foreach($watch as $sym){$old=lm_get($c->symbols,$sym['symbol_id']);if(($retryOnly&&$queuedIds)?isset($queuedIds[$sym['symbol_id']]):(!$retryOnly||!$this->rowCurrent($c,$sym,$old)))$targets[]=$sym;}unset($old);
        if(!$targets){$stats=$this->networkStats($stats);$count=count($watch);unset($c,$targets,$queuedIds);$this->lightCheck();return ['at'=>lm_iso(),'done'=>$count,'pending'=>0,'candidate_count'=>$count,'eligible_count'=>0,'results'=>[],'errors'=>[],'network_stats'=>$stats];}
        if($bounded)usort($targets,static function($a,$b)use($c){$ta=strtotime((string)lm_get(lm_get($c->symbols,$a['symbol_id']),'checked_at',''))?:0;$tb=strtotime((string)lm_get(lm_get($c->symbols,$b['symbol_id']),'checked_at',''))?:0;return $ta<=>$tb?:strcmp($a['symbol_id'],$b['symbol_id']);});
        $queuedTargets=count($targets);$c->scan_queue=array_map(static function($x){return $x['symbol_id'];},$targets);$queuedIds=array_fill_keys($c->scan_queue,true);$loadedRes=LiteResource::sample();if(($loadedRes['memory_mode']??'normal')==='low'){$lowMemory=true;$bounded=true;$batch=min($batch,self::lowMemoryBatch($loadedRes));}if(self::cpuMode($loadedRes)==='limited'){$cpuLimited=true;$bounded=true;$batch=min($batch,1);}if(self::cpuMode($loadedRes)==='defer'){$batch=0;$bounded=true;}$stats['low_memory_active']=$lowMemory;$stats['low_memory_batch_size']=$lowMemory?$batch:0;$stats['cpu_limited_active']=$cpuLimited;$stats['mode']=($cpuLimited?'cpu_limited_':($lowMemory?'low_memory_':'')).$baseMode;if($bounded)$targets=array_slice($targets,0,$batch);
        $needed=[];foreach($targets as $sym)$needed[$this->benchmarkKey($sym)]=true;$bench=[];$benchMap=[];$benchFingerprint=[];$benchSource=[];$benchErrors=[];$blocked=[];
        foreach(array_keys($needed) as $key){try{$loaded=$this->loadBenchmark($c,$key,$forceFull,$retryOnly,$stats);$bench[$key]=$loaded['rows'];$benchMap[$key]=$loaded['map'];$benchFingerprint[$key]=$loaded['map_fingerprint'];$benchSource[$key]=$loaded['source_ticker'];$stats['benchmark_sources'][$key]=$loaded['source_ticker'];}catch(Throwable $e){$blocked[$key]=true;$benchErrors[]=['scope'=>'benchmark','country'=>$key,'error'=>$e instanceof LiteMonFault?$e->faultCode:'BENCHMARK_FAILED'];}}
        foreach($watch as $sym){$key=$this->benchmarkKey($sym);$sid=$sym['symbol_id'];$old=lm_get($c->symbols,$sid);if(!isset($benchFingerprint[$key])||!$old||lm_get($old,'status')!=='ok'||$this->rowCurrent($c,$sym,$old))continue;$old->status='pending';$old->last_error='BENCHMARK_REFRESH_REQUIRED';if($retryOnly&&!$forceFull&&is_array(lm_get($old,'bars'))){$stockLast=$old->bars[count($old->bars)-1][0]??'';$benchmarkLast=$bench[$key][count($bench[$key])-1][0]??'';$old->recalculate_only=$stockLast!==''&&$benchmarkLast!==''&&strcmp($stockLast,$benchmarkLast)>=0;}$c->symbols->{$sid}=$old;$queuedIds[$sid]=true;}$c->scan_queue=array_keys($queuedIds);unset($old);
        // A shared benchmark failure is handled once before any individual market download.
        foreach($watch as $sym){$sid=$sym['symbol_id'];if(!isset($blocked[$this->benchmarkKey($sym)])||!isset($queuedIds[$sid]))continue;$old=lm_get($c->symbols,$sid,new stdClass());$old->status='pending';$old->symbol=(object)$sym;$old->last_error='BENCHMARK_UNAVAILABLE';$old->checked_at=lm_iso();$c->symbols->{$sid}=$old;unset($queuedIds[$sid]);$stats['benchmark_blocked_symbols']++;}$c->scan_queue=array_keys($queuedIds);unset($old);
        $processed=0;$lastCheckpoint=0;$checkpointEvery=$bounded?1:self::CHECKPOINT_EVERY;$loadedCpu=self::cpuMode($loadedRes);$stopResource=$loadedCpu==='defer'||self::lowMemoryBatch($loadedRes)===0?$loadedRes:null;$stopReason=$stopResource===null?null:($loadedCpu==='defer'?(string)($loadedRes['cpu_reason']??'CPU_PRESSURE'):'MEMORY_HARD_FLOOR');$stats['cpu_early_stop']=$loadedCpu==='defer';$stats['memory_early_stop']=self::lowMemoryBatch($loadedRes)===0;
        foreach($targets as $sym){$sid=$sym['symbol_id'];if(!isset($queuedIds[$sid]))continue;if($this->stopRequested())throw new LiteMonStop();$nowRes=LiteResource::sample();$cpuNow=self::cpuMode($nowRes);$budget=self::lowMemoryBatch($nowRes);if($cpuNow==='defer'||$budget===0){$stopResource=$nowRes;$stopReason=$cpuNow==='defer'?(string)($nowRes['cpu_reason']??'CPU_PRESSURE'):'MEMORY_HARD_FLOOR';$stats['cpu_early_stop']=$cpuNow==='defer';$stats['memory_early_stop']=$budget===0;break;}if($cpuNow==='limited'){$cpuLimited=true;$bounded=true;$batch=min($batch,1);$checkpointEvery=1;}if(($nowRes['memory_mode']??'normal')==='low'){$lowMemory=true;$bounded=true;$batch=min($batch,$budget);$checkpointEvery=1;}if($bounded&&$processed>=$batch){$stopResource=$nowRes;$stopReason=$cpuLimited?'CPU_SOFT_LOAD':'MEMORY_BATCH';break;}
            $old=lm_get($c->symbols,$sid);try{$oldBars=$old&&is_array(lm_get($old,'bars'))?$old->bars:null;$benchKey=$this->benchmarkKey($sym);$stockLast=$oldBars?$oldBars[count($oldBars)-1][0]:'';$benchmarkLast=$bench[$benchKey][count($bench[$benchKey])-1][0]??'';$bars=$retryOnly&&!$forceFull&&lm_get($old,'recalculate_only')===true&&$oldBars&&$benchmarkLast!==''&&strcmp($stockLast,$benchmarkLast)>=0?$oldBars:$this->fetchSeries($sym,$oldBars,$forceFull,$stats);$q=LiteQuality::compare($oldBars,$bars);if(!$q['accept'])throw new LiteMonFault($q['reason']);if($this->analysisReusable($old,$bars,$benchFingerprint[$benchKey])){$a=$old->analysis;$stats['analysis_reused']++;}else{$a=LiteSwing::analyzeMapped($sym,$bars,$benchMap[$benchKey]);$stats['analysis_recomputed']++;}if(!isset($a['ms7'])||!is_array($a['ms7']))$a['ms7']=LiteSwing::ms7FromAnalysis($a);if(method_exists($this->yahoo,'recoveryFor')){$recovery=$this->yahoo->recoveryFor($sid);if($recovery!==null)$a['provider_recovery']=$recovery;}$row=(object)['status'=>'ok','symbol'=>(object)$sym,'bars'=>$bars,'analysis'=>$a,'quality'=>$q,'checked_at'=>lm_iso(),'benchmark_source_ticker'=>$benchSource[$benchKey],'benchmark_fingerprint'=>$benchFingerprint[$benchKey],'calculation_version'=>self::CALCULATION_VERSION];if($oldBars===$bars&&lm_get($old,'final_check') instanceof stdClass)$row->final_check=$old->final_check;$c->symbols->{$sid}=$row;}catch(Throwable $e){$code=$e instanceof LiteMonFault?$e->faultCode:'SCAN_FAILED';$prev=$old?:new stdClass();unset($prev->recalculate_only);$prev->status='pending';$prev->last_error=$code;$prev->checked_at=lm_iso();$prev->symbol=(object)$sym;$c->symbols->{$sid}=$prev;}$processed++;unset($queuedIds[$sid]);$c->scan_queue=array_keys($queuedIds);
            if($processed-$lastCheckpoint>=$checkpointEvery||$processed===count($targets)){$c->updated_at=lm_iso();LiteRuntime::write('cache',$c);$fresh=$this->state();if($fresh->stop_requested)throw new LiteMonStop();$fresh->phase='SCAN';$fresh->heartbeat_at=lm_iso();$fresh->progress=['state'=>'scanning','index'=>$processed,'batch_total'=>count($targets),'queue_total'=>$queuedTargets,'symbol_id'=>$sid,'retry_only'=>$retryOnly,'mode'=>$stats['mode']];$this->saveState($fresh);$lastCheckpoint=$processed;}unset($old,$oldBars,$bars,$a,$q,$row);if($bounded){self::releaseMemory();usleep(400000);}else usleep(150000);
        }
        $remainingQueue=count($queuedIds);if($remainingQueue>0&&$stopResource===null&&$bounded){$stopResource=$loadedRes;$stopReason=$cpuLimited?'CPU_SOFT_LOAD':'MEMORY_BATCH';}$c->scan_queue=array_keys($queuedIds);[$results,$errors,$done,$pending]=$this->buildCurrentResults($c,$watch);if($forceFull&&$remainingQueue===0&&$pending===0){$c->last_full_resync_at=lm_iso();$c->full_resync_pending=false;}if($done>0&&!$bounded&&$stopResource===null){$this->finalRecheck($c,$results,$stats);[$results,$errors,$done,$pending]=$this->buildCurrentResults($c,$watch);}$c->updated_at=lm_iso();LiteRuntime::write('cache',$c);unset($c,$bench,$benchMap,$benchFingerprint,$benchSource,$loaded,$targets,$queuedIds,$needed,$sym);if(method_exists($this->yahoo,'releasePayloads'))$this->yahoo->releasePayloads();self::releaseMemory();$errors=array_merge($benchErrors,$errors);$stats['queue_remaining']=$remainingQueue;$stats['low_memory_active']=$lowMemory;$stats['cpu_limited_active']=$cpuLimited;$stats['resource_after']=LiteResource::sample();$stats=$this->networkStats($stats);$candidateIds=array_map(static function($x){return $x['symbol_id'];},$watch);$analysisReference=self::recommendThree($results,$candidateIds);$coverage=['KR'=>['done'=>0,'pending'=>0],'US'=>['done'=>0,'pending'=>0],'JP'=>['done'=>0,'pending'=>0]];foreach($results as $r)$coverage[$r['country']]['done']++;foreach($watch as $sym)$coverage[$sym['country']]['pending']++;foreach($coverage as &$cv)$cv['pending']-=$cv['done'];unset($cv);
        $summary=['scan_id'=>'scan-'.gmdate('Ymd-His'),'at'=>lm_iso(),'mode'=>$stats['mode'],'done'=>$done,'pending'=>$pending,'candidate_count'=>count($watch),'eligible_count'=>count(array_filter($results,static function($x){return $x['eligible'];})),'coverage'=>$coverage,'analysis_reference'=>$analysisReference,'results'=>$results,'errors'=>$errors,'network_stats'=>$stats];LiteRuntime::write('evidence',(object)['contract'=>'MON-LITE-EVIDENCE-1.1','latest_scan'=>$summary,'error_summary'=>$this->compressErrors($errors)]);
        $s=$this->state();$s->scan_handled_seq=max((int)lm_get($s,'scan_handled_seq',0),$scanSeq);$s->last_full_scan=lm_iso();if($pending===0&&$remainingQueue===0)$s->last_completed_scan_at=lm_iso();$s->last_scan_mode=$stats['mode'];$s->last_network_stats=$stats;$s->next_full_scan=self::nextFullAt();$s->next_light_check=lm_iso(time()+($pending>0||$remainingQueue>0?self::LOW_MEMORY_RETRY_SECONDS:self::LIGHT_SECONDS));$s->progress=['state'=>$pending>0||$remainingQueue>0?'partial':'complete','done'=>$done,'total'=>count($watch),'pending'=>$pending,'queue_remaining'=>$remainingQueue,'coverage'=>$coverage,'next_retry_seconds'=>$pending>0||$remainingQueue>0?self::LOW_MEMORY_RETRY_SECONDS:null];$s->phase=$pending>0||$remainingQueue>0?'WAIT':'RESULT';$s->heartbeat_at=lm_iso();$s->last_error=null;$s->resource_wait=$stopResource===null?null:(object)['reason'=>$stopReason,'since'=>lm_iso(),'checked_at'=>lm_iso(),'resource'=>$stopResource];$shouldPublish=$remainingQueue===0;$fingerprint=lm_hash([$summary['done'],$summary['pending'],$summary['results'],$summary['errors'],$watch]);if($shouldPublish){$s->publication_fingerprint=$fingerprint;$s->publication_pending=$fingerprint!==lm_get($s,'last_publish_fingerprint');$s->last_publication_error=null;}$this->saveState($s);
        if($shouldPublish&&$s->publication_pending){try{if($this->publishData($summary,$watch)){$s=$this->state();$s->last_publish_fingerprint=$fingerprint;$s->publication_pending=false;$s->last_publication_error=null;$this->saveState($s);}}catch(Throwable $e){$s=$this->state();$s->publication_pending=true;$s->last_publication_error=$e instanceof LiteMonFault?$e->faultCode:'PUBLICATION_FAILED';$this->saveState($s);try{LiteRuntime::log('publication_pending',['code'=>$s->last_publication_error]);}catch(Throwable $ignore){}}}unset($results,$errors,$analysisReference,$candidateIds,$watch);$this->endTurn();$this->mailboxCheck();$this->endTurn();$summary['publication_pending']=lm_get($this->state(),'publication_pending')===true;return $summary;
    }
    private function compressErrors(array $errors): array {$x=[];$now=lm_iso();foreach($errors as $e){$k=($e['error']??'UNKNOWN').'|'.($e['symbol_id']??$e['country']??'');if(!isset($x[$k]))$x[$k]=['error'=>$e['error']??'UNKNOWN','subject'=>$e['symbol_id']??$e['country']??'','count'=>0,'first_at'=>$now,'last_at'=>$now];$x[$k]['count']++;$x[$k]['last_at']=$now;}return array_values($x);}
    public function lightCheck(): array {$this->http->clearRequestCache();$this->http->resetStats();$this->yahoo->beginTurn();$s=$this->state();$s->last_light_check=lm_iso();$s->next_light_check=lm_iso(time()+self::LIGHT_SECONDS);$s->heartbeat_at=lm_iso();$s->phase='WAIT';$s->last_error=null;$this->saveState($s);$c=$this->cacheObj();$pending=$this->pendingCount($c);unset($c);$this->retryPublication();$this->mailboxCheck();LiteRuntime::cleanup();$this->endTurn();return ['at'=>lm_iso(),'pending'=>$pending,'next_light_check'=>$this->state()->next_light_check];}
    private function putVerified(string $path,string $content,string $message,bool $readback=false,?string $expectedOldSha=null): array {
        $old=$this->http->githubGet($path,$readback);if($expectedOldSha!==null&&($old?(string)$old['sha']:'')!==$expectedOldSha)throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');$expected=lm_git_blob_sha($content);
        if($old&&hash_equals($old['content'],$content)&&hash_equals($expected,(string)$old['sha']))return ['blob_sha'=>$old['sha'],'commit_sha'=>$old['commit_sha']??null,'unchanged'=>true];
        $put=$this->http->githubPut($path,$content,$message,$old?$old['sha']:null);
        if($readback){$check=$this->http->githubGet($path,true);if(!$check||!hash_equals($check['content'],$content)||!hash_equals($expected,(string)$check['sha']))throw new LiteMonFault('GITHUB_READBACK_MISMATCH');}
        return $put;
    }
    private function publishData(array $summary,array $watch): bool {
        if($this->cfg->githubToken==='')return false;if($this->decisionPending())throw new LiteMonFault('FINAL_DECISION_PUBLICATION_PENDING');
        $ids=array_map(function($x){return $x['symbol_id'];},$watch);$ref=$summary['analysis_reference']??self::recommendThree($summary['results']??[],$ids);
        $data=['contract'=>'MON-LITE-DATA-2.2','policy'=>MON_LITE_POLICY,'version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'updated_at'=>lm_iso(),'watchlist'=>['symbols'=>$watch],'scan'=>['scan_id'=>$summary['scan_id'],'at'=>$summary['at'],'done'=>$summary['done'],'pending'=>$summary['pending'],'candidate_count'=>$summary['candidate_count'],'eligible_count'=>$summary['eligible_count'],'mode'=>$summary['mode']??null,'coverage'=>$summary['coverage']??null,'network_stats'=>$summary['network_stats']??null,'decision_status'=>'awaiting_chatgpt'],'analysis_reference'=>$ref,'results'=>$summary['results'],'errors'=>$summary['errors']];
        // Preserve the last confirmed final decision across subsequent reference scans.
        // A scan must never silently downgrade a saved decision to awaiting_chatgpt.
        $prior=$this->http->githubGet('mon_data.json',true);$referenceBase=$this->http->githubGet('mon_result.md',true);
        if(!$prior || trim((string)$prior['content'])==='') throw new LiteMonFault('PRIOR_FINAL_STATUS_UNVERIFIED');
        if($prior&&trim((string)$prior['content'])!==''){
            try{
                $previous=lm_decode($prior['content']);
                $final=lm_get($previous,'chatgpt_final',lm_get($previous,'previous_chatgpt_final'));
                if($final&&lm_get($final,'authority')==='chatgpt_final'){$finalAt=strtotime((string)lm_get($final,'analysis_at',''));$scanAt=strtotime((string)($summary['at']??''));if($finalAt===false||$scanAt===false||$finalAt>=$scanAt){$st=$this->state();$st->reference_publication_status='superseded_by_final';$this->saveState($st);return true;}}
                if($final&&lm_get($final,'authority')==='chatgpt_final'){
                    // Archive only: historical picks are never active recommendations for a new scan.
                    $data['previous_chatgpt_final']=lm_array($final);
                    $data['recommendations']=['KR'=>[],'US'=>[],'JP'=>[]];
                    $data['scan']['decision_status']='independent_chatgpt_required';
                    $data['scan']['decision_note']='New independent ChatGPT analysis required; archived final is historical only.';
                }
            }catch(Throwable $ignored){throw new LiteMonFault('PRIOR_FINAL_STATUS_UNVERIFIED');}
        }
        $lines=["MON 2.2.3 Lite · 보조 참고분석 · ".$summary['at'],"",'- 아래 3×3은 PHP/MON 참고분석이며 최종 추천이 아닙니다.','- 최종 결정 권한: ChatGPT 독립 분석','- 평가 '.$summary['done'].' / '.$summary['candidate_count'].' · 미확인 '.$summary['pending'].' · 정밀 적격 '.$summary['eligible_count']];
        $heads=['KR'=>'한국','US'=>'미국','JP'=>'일본'];foreach(['KR','US','JP'] as $country){$lines[]='';$lines[]='## '.$heads[$country].' 참고';foreach($ref[$country]??[] as $r){$ms=$r['ms7']??['code'=>'W','label'=>'대기','confidence'=>'PROVISIONAL'];$price=$r['last_close']===null?'가격 미확인':(string)$r['last_close'];$lines[]='- '.($r['symbol_id']??'').' · 참고 MS7 '.($ms['code']??'W').' '.($ms['label']??'대기').' · '.$price.' · '.($ms['confidence']??'PROVISIONAL');}}
        if(($data['scan']['decision_status']??'')==='independent_chatgpt_required'){
            $lines[]='';
            $lines[]='## 이전 ChatGPT 최종 결정 (새 분석 아님)';
            $lines[]='- 새 스캔에서 확정 결정을 생성하지 않았으므로 마지막 저장 결정을 보존합니다. 현재 매입 신호로 간주하지 마세요.';
            foreach((lm_array(lm_get($data['previous_chatgpt_final'],'recommendations'))??[]) as $cc=>$items)foreach($items as $item){
                $lines[]='- '.($item['name']??''). ' ('.($item['symbol_id']??'').') · '.($item['ms7']['code']??'W').' · '.($item['reason']??'');
            }
        }
        // Keep a durable local snapshot before attempting either remote write.
        $markdown=implode("\n",$lines)."\n";
        LiteRuntime::write('evidence',(object)['latest_scan'=>$summary,'publication_snapshot'=>(object)['data'=>$data,'markdown'=>$markdown,'at'=>lm_iso()]]);
        $this->putVerified('mon_data.json',lm_json($data),'MON Lite: analysis reference update',true,(string)$prior['sha']);
        // A reference scan never overwrites the last authoritative final document.
        if(!isset($data['previous_chatgpt_final']))$this->putVerified('mon_result.md',$markdown,'MON Lite: reference analysis',true,$referenceBase?(string)$referenceBase['sha']:'');$st=$this->state();$st->reference_publication_status='published_readback';$this->saveState($st);return true;
    }
    private function processMailbox(): void {
        if($this->decisionPending()){$this->resumeDecision();return;}
        if($this->cfg->githubToken==='')return;$m=$this->http->githubGet('mon_chatgpt_handoff.json');if(!$m||trim((string)$m['content'])==='')return;$q=lm_array(lm_decode($m['content']));if(lm_get($q,'contract')!=='MON-LITE-BRIDGE-1.0')return;$stage=(string)lm_get($q,'stage');if($stage==='idle')return;$rid=(string)lm_get($q,'request_id');if($rid===''||!in_array($stage,['candidates','decision'],true))return;$req=LiteRuntime::read('request');if($stage==='decision'&&(!is_string(lm_get($req,'request_id'))||lm_get($req,'request_id')==='')){throw new LiteMonFault('DECISION_LOCAL_PROOF_MISSING');}if(lm_get($q,'policy')!==MON_LITE_POLICY)throw new LiteMonFault('BRIDGE_POLICY_MISMATCH');
        if($stage==='candidates'){$c=$this->cacheObj();$rawIds=lm_get($q,'candidate_ids',[]);if(!is_array($rawIds))return;$ids=[];$rejected=[];$seen=[];foreach($rawIds as $raw){if(!is_string($raw)){$rejected[]='<non-string>';continue;}$sid=trim($raw);if($sid===''||isset($seen[$sid]))continue;$seen[$sid]=true;if(!self::symbolFromId($sid)){$rejected[]=$sid;continue;}$ids[]=$sid;}$newRequest=lm_get($req,'candidate_request_sha')!==$m['sha'];$changed=false;if($newRequest){$nextWatch=[];$nextSymbols=new stdClass();foreach($ids as $sid){$row=lm_get($c->symbols,$sid);if(!$row){$sym=self::symbolFromId($sid);$row=(object)['status'=>'pending','symbol'=>(object)$sym,'last_error'=>'CANDIDATE_NOT_COLLECTED','checked_at'=>lm_iso()];}$row->status='pending';$row->last_error='CANDIDATE_REFRESH_REQUIRED';$row->candidate_request_sha=$m['sha'];$row->checked_at=lm_iso();$nextWatch[]=$row->symbol;$nextSymbols->{$sid}=$row;}$c->watchlist=$nextWatch;$c->symbols=$nextSymbols;$c->scan_queue=[];$changed=true;}if($changed){$c->updated_at=lm_iso();LiteRuntime::write('cache',$c);}if($newRequest&&$ids){$wake=$this->state();$wake->next_light_check=lm_iso(time()+30);$wake->heartbeat_at=lm_iso();$this->saveState($wake);}$rows=[];$usable=[];$unresolved=[];$detailQueue=array_fill_keys((array)lm_get($c,'scan_queue',[]),true);foreach($ids as $sid){$r=lm_get($c->symbols,$sid);$a=$r?lm_get($r,'analysis'):null;if(!isset($detailQueue[$sid])&&$this->rowCurrent($c,self::symbolFromId($sid),$r)){$rows[]=$a;$usable[]=$sid;}else{$rows[]=['symbol_id'=>$sid,'status'=>'pending','error'=>$r?lm_get($r,'last_error'):null];$unresolved[]=$sid;}}foreach($rejected as $sid)$rows[]=['symbol_id'=>$sid,'status'=>'rejected','error'=>'INVALID_SYMBOL_ID'];foreach($rows as &$rr)if(is_array($rr)&&isset($rr['metrics'])&&(!isset($rr['ms7'])||!is_array($rr['ms7'])))$rr['ms7']=LiteSwing::ms7FromAnalysis($rr);unset($rr);$analysisReference=self::recommendThree($rows,$ids);$status='ready_for_chatgpt';$evaluationId=lm_hash([$rid,$m['sha'],$usable,$rejected,$analysisReference,array_map(function($r){return lm_hash($r);},$rows)]);if(lm_get($req,'candidate_request_sha')===$m['sha']&&lm_get($req,'evaluation_id')===$evaluationId&&is_string(lm_get($req,'details_blob_sha')))return;$response=['contract'=>'MON-LITE-BRIDGE-1.0','policy'=>MON_LITE_POLICY,'stage'=>'details','request_id'=>$rid,'candidate_request_sha'=>$m['sha'],'status'=>$status,'evaluation_id'=>$evaluationId,'evaluated_ids'=>$usable,'unresolved_ids'=>$unresolved,'rejected_ids'=>$rejected,'analysis_reference'=>$analysisReference,'rows'=>$rows,'as_of'=>lm_iso()];$put=$this->putVerified('mon_chatgpt_response.json',lm_json($response),'MON Lite: evidence package for ChatGPT',false);LiteRuntime::write('request',(object)['request_id'=>$rid,'stage'=>'details','candidate_request_sha'=>$m['sha'],'details_blob_sha'=>$put['blob_sha'],'evaluation_id'=>$evaluationId,'evaluated_ids'=>$usable,'unresolved_ids'=>$unresolved,'rejected_ids'=>$rejected,'analysis_reference'=>$analysisReference,'updated_at'=>lm_iso()]);return;}
        if(lm_get($q,'authority')!=='chatgpt_final'||lm_get($q,'independent_analysis')!==true)throw new LiteMonFault('DECISION_AUTHORITY_REQUIRED');
        $saved=LiteRuntime::read('result');if(lm_get($saved,'handoff_sha')===$m['sha']&&lm_get($saved,'request_id')===$rid&&in_array(lm_get($saved,'save_status'),['saved','superseded'],true))return;
        $views=lm_get($q,'market_view',[]);if(!is_array($views))throw new LiteMonFault('DECISION_MARKET_VIEW_REQUIRED');foreach(['KR','US','JP'] as $cc){$v=trim((string)($views[$cc]??''));if(strlen($v)<8)throw new LiteMonFault('DECISION_MARKET_VIEW_TOO_THIN');$views[$cc]=$v;}
        $outcome=(string)lm_get($q,'outcome','completed');if(!in_array($outcome,['completed','limited_evidence','no_candidates'],true))throw new LiteMonFault('DECISION_OUTCOME_INVALID');$conclusion=trim((string)lm_get($q,'conclusion',''));if(strlen($conclusion)<8)throw new LiteMonFault('DECISION_CONCLUSION_REQUIRED');
        $analysisAt=(string)lm_get($q,'analysis_at','');$at=strtotime($analysisAt);if($at===false||$at>time()+300||time()-$at>86400)throw new LiteMonFault('DECISION_FRESH_ANALYSIS_REQUIRED');
        $selected=lm_get($q,'selected',[]);if(!is_array($selected))throw new LiteMonFault('DECISION_ITEMS_REQUIRED');if(!$selected&&$outcome==='completed')throw new LiteMonFault('DECISION_EMPTY_OUTCOME_REQUIRED');
        $sources=lm_get($q,'independent_sources',[]);if(!is_array($sources)||($selected&&count($sources)<2))throw new LiteMonFault('DECISION_INDEPENDENT_SOURCES_REQUIRED');$sourceUrls=[];
        foreach($sources as $source){$url=is_array($source)?trim((string)($source['url']??'')):'';$checked=is_array($source)?strtotime((string)($source['checked_at']??'')):false;$parts=parse_url($url);if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['https','http'],true)||empty($parts['host'])||$checked===false||$checked>time()+300||time()-$checked>86400)throw new LiteMonFault('DECISION_SOURCE_EVIDENCE_REQUIRED');$sourceUrls[$url]=true;}
        if($selected&&count($sourceUrls)<2)throw new LiteMonFault('DECISION_INDEPENDENT_SOURCES_REQUIRED');
        if(lm_get($req,'request_id')!==$rid||lm_get($q,'candidate_request_sha')!==lm_get($req,'candidate_request_sha')||lm_get($q,'details_receipt_sha')!==lm_get($req,'details_blob_sha')||lm_get($q,'evaluation_id')!==lm_get($req,'evaluation_id'))throw new LiteMonFault('DECISION_PROOF_MISMATCH');
        $normalized=self::normalizeChatgptDecision($selected);$this->saveDecision($rid,$normalized,(array)lm_get($req,'unresolved_ids',[]),(string)$req->evaluation_id,$views,$m['sha'],['outcome'=>$outcome,'conclusion'=>$conclusion,'analysis_at'=>$analysisAt,'independent_sources'=>$sources]);
    }
    private function saveDecision(string $rid,array $selected,array $unresolved,string $evaluationId,array $marketView,string $handoffSha,array $context=[]): void {
        $old=LiteRuntime::read('result');if($this->decisionPending($old)){if(lm_get($old,'request_id')!==$rid||lm_get($old,'handoff_sha')!==$handoffSha)throw new LiteMonFault('PENDING_DECISION_MUST_FINISH');if(lm_get($old,'outbox')){$this->resumeDecision();return;}}
        $now=is_string($context['saved_at']??null)?$context['saved_at']:lm_iso();$selected=self::normalizeChatgptDecision(lm_array($selected));$grouped=['KR'=>[],'US'=>[],'JP'=>[]];foreach($selected as $x)$grouped[$x['country']][]=$x;
        $result=['contract'=>'MON-LITE-RESULT-2.1','policy'=>MON_LITE_POLICY,'authority'=>'chatgpt_final','independent_analysis'=>true,'request_id'=>$rid,'evaluation_id'=>$evaluationId,'handoff_sha'=>$handoffSha,'outcome'=>$context['outcome']??($selected?'completed':'limited_evidence'),'conclusion'=>$context['conclusion']??'ChatGPT independent final decision.','analysis_at'=>array_key_exists('analysis_at',$context)?$context['analysis_at']:$now,'analysis_status'=>$context['analysis_status']??'current_independent_analysis','received_at'=>$context['received_at']??$now,'independent_sources'=>$context['independent_sources']??[],'market_view'=>$marketView,'selected'=>$selected,'recommendations'=>$grouped,'unresolved_ids'=>$unresolved,'saved_at'=>$now,'save_status'=>'pending_remote','pair_readback_verified'=>false,'receipt_readback_verified'=>false];
        $result['decision_id']=lm_hash($result);$lines=["MON 2.2.3 Lite · ChatGPT 최종 결정 · ".($result['analysis_at']??'원 분석시각 미확인'),"",'- 최종 추천 권한: ChatGPT 독립 분석','- MON/PHP 분석은 보조 참고자료로만 사용','- 결정 ID: '.$result['decision_id'],'- 요청 ID: '.$rid,'- 평가 ID: '.$evaluationId,'- 분석 시각: '.($result['analysis_at']??'미확인'),'- 결론: '.$result['conclusion'],""];$heads=['KR'=>'한국','US'=>'미국','JP'=>'일본'];foreach(['KR','US','JP'] as $cc){$lines[]='## '.$heads[$cc].' · '.($marketView[$cc]??'자료 미확인');if(!$grouped[$cc])$lines[]='- 신규 추천 없음';foreach($grouped[$cc] as $x){$ms=$x['ms7'];$price=array_key_exists('reference_price',$x)&&$x['reference_price']!==null?' · 참고가 '.(string)$x['reference_price']:'';$lines[]='- '.$x['name'].' ('.$x['symbol_id'].') · MS7 '.$ms['code'].' '.$ms['label'].$price.' · '.$x['reason'].' · 위험: '.$x['risk_note'];}$lines[]='';}
        $lines[]='- 판단 유형: '.$result['outcome'];foreach($result['independent_sources'] as $source)$lines[]='- 확인 출처: '.(string)($source['url']??'').' · '.(string)($source['checked_at']??'');if($unresolved)$lines[]='- MON 미확인 자료 '.count($unresolved).'개는 보조자료 미확인으로 기록합니다.';$markdown=implode("\n",$lines)."\n";
        $result['outbox']=['contract'=>'MON-LITE-OUTBOX-1.0','markdown'=>$markdown,'result_blob_sha'=>lm_git_blob_sha($markdown),'data_json'=>null,'response_json'=>null,'result_commit_sha'=>null,'data_commit_sha'=>null];
        // Never expose remote writes before the complete, normalized final decision is durable.
        LiteRuntime::write('result',(object)$result);try{$this->resumeDecision();}catch(Throwable $e){$this->recordDecisionError($e);throw $e;}
    }
    private static function finalIdentity($f): ?string {
        if(!$f||lm_get($f,'authority')!=='chatgpt_final')return null;$id=(string)lm_get($f,'decision_id','');if($id!=='')return 'decision:'.$id;
        return 'legacy:'.lm_hash([lm_get($f,'request_id'),lm_get($f,'evaluation_id'),lm_get($f,'handoff_sha'),lm_get($f,'analysis_at'),lm_get($f,'saved_at')]);
    }
    private function decisionRemote(array $r,array $o): ?array {
        $remote=$this->http->githubGet('mon_data.json',true);if(!$remote||trim((string)$remote['content'])===''){if(array_key_exists('base_final_id',$o)&&$o['base_final_id']!==null)throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');return $remote;}
        $d=lm_decode($remote['content']);$f=lm_get($d,'chatgpt_final',lm_get($d,'previous_chatgpt_final'));$current=self::finalIdentity($f);$own='decision:'.$r['decision_id'];
        if($current!==null&&$current!==$own){$remoteAt=strtotime((string)lm_get($f,'analysis_at',lm_get($f,'saved_at','')));$localAt=strtotime((string)($r['analysis_at']??$r['received_at']??$r['saved_at']));if($remoteAt!==false&&$localAt!==false&&$remoteAt>$localAt)throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');if(array_key_exists('base_final_id',$o)&&$current!==$o['base_final_id'])throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');}
        if(array_key_exists('base_final_id',$o)&&$current===null&&$o['base_final_id']!==null)throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');return $remote;
    }
    private function supersedeVerifiedDecision(): bool {
        // Retire an older outbox only after independently reading the complete newer final and its receipt.
        try{$r=lm_array(LiteRuntime::read('result'));if(!is_array($r))return false;$data=$this->http->githubGet('mon_data.json',true);$receipt=$this->http->githubGet('mon_chatgpt_response.json',true);$markdown=$this->http->githubGet('mon_result.md',true);if(!$data||!$receipt||!$markdown)return false;$d=lm_decode($data['content']);$f=lm_get($d,'chatgpt_final');$q=lm_decode($receipt['content']);$id=(string)lm_get($f,'decision_id','');$remoteAt=strtotime((string)lm_get($f,'analysis_at',''));$localAt=strtotime((string)($r['analysis_at']??$r['received_at']??$r['saved_at']??''));
            if(lm_get($d,'contract')!=='MON-LITE-DATA-2.2'||lm_get($d,'policy')!==MON_LITE_POLICY||lm_get($f,'policy')!==MON_LITE_POLICY||lm_get($f,'independent_analysis')!==true||lm_get($q,'contract')!=='MON-LITE-BRIDGE-1.0'||lm_get($q,'policy')!==MON_LITE_POLICY||lm_get($q,'stage')!=='decision'||$id===''||$id===($r['decision_id']??'')||lm_get($f,'authority')!=='chatgpt_final'||$remoteAt===false||$localAt===false||$remoteAt<=$localAt||lm_get($q,'status')!=='saved'||lm_get($q,'authority')!=='chatgpt_final'||lm_get($q,'decision_id')!==$id||lm_get($q,'request_id')!==lm_get($f,'request_id')||lm_get($q,'evaluation_id')!==lm_get($f,'evaluation_id')||lm_get($q,'pair_readback_verified')!==true)return false;
            foreach([[$data,lm_get($q,'data_blob_sha')],[$markdown,lm_get($q,'result_blob_sha')],[$receipt,$receipt['sha']]] as $item)if(!is_string($item[1])||!hash_equals((string)$item[0]['sha'],$item[1])||!hash_equals(lm_git_blob_sha($item[0]['content']),(string)$item[0]['sha']))return false;
            foreach(['mon_data.json'=>$data,'mon_result.md'=>$markdown,'mon_chatgpt_response.json'=>$receipt] as $path=>$expected){$check=$this->http->githubGet($path,true);if(!$check||!hash_equals((string)$check['sha'],(string)$expected['sha'])||!hash_equals($check['content'],$expected['content']))return false;}
            $r['save_status']='superseded';$r['last_error']=null;$r['superseded_by']=['decision_id'=>$id,'request_id'=>lm_get($f,'request_id'),'analysis_at'=>lm_get($f,'analysis_at'),'result_blob_sha'=>$markdown['sha'],'data_blob_sha'=>$data['sha'],'receipt_blob_sha'=>$receipt['sha'],'verified_at'=>lm_iso()];LiteRuntime::write('result',(object)$r);$s=$this->state();$s->publication_pending=false;$s->last_publication_error=null;$s->last_error=null;$s->phase='WAIT';$this->saveState($s);return true;
        }catch(Throwable $ignore){return false;}
    }
    private function decisionPath(string $path,string $ownSha,string $baseSha): string {
        $v=$this->http->githubGet($path,true);$current=$v?(string)$v['sha']:'';if($current!==$ownSha&&$current!==$baseSha)throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');return $current;
    }
    private function verifyDecisionPair(array $o): void {
        foreach(['mon_result.md'=>[$o['markdown'],$o['result_blob_sha']],'mon_data.json'=>[$o['data_json'],$o['data_blob_sha']]] as $path=>$expected){$v=$this->http->githubGet($path,true);if(!$v||!hash_equals($expected[0],$v['content'])||!hash_equals($expected[1],(string)$v['sha']))throw new LiteMonFault('DECISION_PAIR_READBACK_MISMATCH');}
    }
    private function resumeDecision(): void {
        $r=lm_array(LiteRuntime::read('result'));if(!is_array($r)||!$this->decisionPending($r))return;
        if(!isset($r['outbox'])||!is_array($r['outbox'])){ // Recover the r12 pending format once, preserving the original decision.
            $this->saveDecision((string)($r['request_id']??''),lm_array($r['selected']??[]),lm_array($r['unresolved_ids']??[]),(string)($r['evaluation_id']??''),lm_array($r['market_view']??[]),(string)($r['handoff_sha']??''),['saved_at'=>$r['saved_at']??$r['retry_at']??lm_iso(),'analysis_at'=>$r['analysis_at']??null,'analysis_status'=>isset($r['analysis_at'])?'current_independent_analysis':'legacy_source_metadata_unavailable','received_at'=>$r['received_at']??$r['saved_at']??$r['retry_at']??lm_iso(),'independent_sources'=>$r['independent_sources']??[],'outcome'=>$r['outcome']??(empty($r['selected'])?'limited_evidence':'completed'),'conclusion'=>$r['conclusion']??'Previously accepted ChatGPT decision; original source metadata unavailable.']);return;
        }
        $o=$r['outbox'];if(($o['contract']??'')!=='MON-LITE-OUTBOX-1.0'||!is_string($o['markdown']??null)||!hash_equals((string)($o['result_blob_sha']??''),lm_git_blob_sha($o['markdown'])))throw new LiteMonFault('DECISION_OUTBOX_INVALID');
        if(!is_string($o['data_json']??null)){
            $remote=$this->decisionRemote($r,$o);$data=(object)['contract'=>'MON-LITE-DATA-2.2','watchlist'=>(object)['symbols'=>[]],'scan'=>(object)['state'=>'decision_only'],'results'=>[],'errors'=>[]];
            if($remote&&trim((string)$remote['content'])!==''){$candidate=lm_decode($remote['content']);if(lm_get($candidate,'contract')!=='MON-LITE-DATA-2.2'||lm_get($candidate,'policy')!==MON_LITE_POLICY)throw new LiteMonFault('DECISION_DATA_CONTRACT_MISMATCH');$data=$candidate;}$o['base_final_id']=self::finalIdentity(lm_get($data,'chatgpt_final',lm_get($data,'previous_chatgpt_final')));$baseResult=$this->http->githubGet('mon_result.md',true);$baseResponse=$this->http->githubGet('mon_chatgpt_response.json',true);$o['base_result_blob_sha']=$baseResult?(string)$baseResult['sha']:'';$o['base_response_blob_sha']=$baseResponse?(string)$baseResponse['sha']:'';
            $final=$r;unset($final['outbox']);$final['save_status']='receipt_required';$final['result_blob_sha']=$o['result_blob_sha'];$data->policy=MON_LITE_POLICY;$data->version=MON_LITE_VERSION;$data->build=MON_LITE_BUILD;$data->updated_at=$r['saved_at'];if(!is_object($data->scan??null))$data->scan=new stdClass();$data->scan->decision_status='chatgpt_final';$data->recommendations=(object)$r['recommendations'];$data->chatgpt_final=(object)$final;unset($data->previous_chatgpt_final);$o['data_json']=lm_json($data);$o['data_blob_sha']=lm_git_blob_sha($o['data_json']);$r['outbox']=$o;LiteRuntime::write('result',(object)$r);
        }
        if(!hash_equals((string)($o['data_blob_sha']??''),lm_git_blob_sha($o['data_json'])))throw new LiteMonFault('DECISION_OUTBOX_INVALID');
        $this->decisionRemote($r,$o);$safeResult=$this->decisionPath('mon_result.md',$o['result_blob_sha'],(string)($o['base_result_blob_sha']??''));$putR=$this->putVerified('mon_result.md',$o['markdown'],'MON Lite: ChatGPT authoritative final decision',true,$safeResult);if(!empty($putR['commit_sha']))$o['result_commit_sha']=$putR['commit_sha'];$r['outbox']=$o;LiteRuntime::write('result',(object)$r);
        $safeRemote=$this->decisionRemote($r,$o);$putD=$this->putVerified('mon_data.json',$o['data_json'],'MON Lite: ChatGPT final recommendation record',true,$safeRemote?(string)$safeRemote['sha']:'');if(!empty($putD['commit_sha']))$o['data_commit_sha']=$putD['commit_sha'];$r['outbox']=$o;LiteRuntime::write('result',(object)$r);
        // Verify the pair together after both writes, including unchanged/read-after-timeout paths.
        $this->verifyDecisionPair($o);
        $r['pair_readback_verified']=true;$r['save_status']='pending_receipt';$r['result_blob_sha']=$o['result_blob_sha'];$r['data_blob_sha']=$o['data_blob_sha'];$r['result_commit_sha']=$o['result_commit_sha'];$r['data_commit_sha']=$o['data_commit_sha'];
        if(!is_string($o['response_json']??null)){$response=['contract'=>'MON-LITE-BRIDGE-1.0','policy'=>MON_LITE_POLICY,'stage'=>'decision','authority'=>'chatgpt_final','request_id'=>$r['request_id'],'status'=>'saved','decision_id'=>$r['decision_id'],'evaluation_id'=>$r['evaluation_id'],'result_blob_sha'=>$o['result_blob_sha'],'data_blob_sha'=>$o['data_blob_sha'],'result_commit_sha'=>$o['result_commit_sha'],'data_commit_sha'=>$o['data_commit_sha'],'pair_readback_verified'=>true,'selected_count'=>count($r['selected']),'unresolved_count'=>count($r['unresolved_ids']),'outcome'=>$r['outcome'],'saved_at'=>$r['saved_at']];$o['response_json']=lm_json($response);$o['response_blob_sha']=lm_git_blob_sha($o['response_json']);}
        if(!hash_equals((string)($o['response_blob_sha']??''),lm_git_blob_sha($o['response_json'])))throw new LiteMonFault('DECISION_OUTBOX_INVALID');$r['outbox']=$o;LiteRuntime::write('result',(object)$r);
        $safeResponse=$this->decisionPath('mon_chatgpt_response.json',$o['response_blob_sha'],(string)($o['base_response_blob_sha']??''));$receipt=$this->putVerified('mon_chatgpt_response.json',$o['response_json'],'MON Lite: ChatGPT final decision saved receipt',true,$safeResponse);$this->verifyDecisionPair($o);$r['receipt_blob_sha']=$receipt['blob_sha'];$r['receipt_readback_verified']=true;$r['save_status']='saved';$r['last_error']=null;LiteRuntime::write('result',(object)$r);
        $s=$this->state();$s->publication_pending=false;$s->last_publication_error=null;$s->last_publish_fingerprint=$s->publication_fingerprint;$this->saveState($s);
    }
    public function loop(): void {
        $lock=LiteRuntime::lock();$s=$this->startState();try{while(true){$s=$this->state();if($s->stop_requested)break;try{$dueFull=!is_string($s->last_full_scan)||!is_string($s->next_full_scan)||strtotime($s->next_full_scan)<=time();$dueMailbox=!is_string($s->next_mailbox_check)||strtotime($s->next_mailbox_check)<=time();$dueLight=!is_string($s->next_light_check)||strtotime($s->next_light_check)<=time();if($this->decisionPending()){if($dueMailbox)$this->retryPublication();}elseif($dueMailbox){$this->retryPublication();if(!$this->decisionPending())$this->mailboxCheck();}elseif($dueFull)$this->fullScan(false);elseif($dueLight){$c=$this->cacheObj();$hasPending=$this->pendingCount($c)>0;unset($c);if($hasPending)$this->fullScan(true);else $this->lightCheck();}}catch(LiteMonStop $e){break;}catch(Throwable $e){$code=$e instanceof LiteMonFault?$e->faultCode:'LOOP_ERROR';$s=$this->state();if($code==='WATCHLIST_EMPTY_WAITING_FOR_CANDIDATES'){$s->last_error=null;$s->phase='WAIT';$s->progress=['state'=>'waiting_for_candidates','policy'=>MON_LITE_POLICY];$s->next_light_check=lm_iso(time()+self::LIGHT_SECONDS);$s->heartbeat_at=lm_iso();$this->saveState($s);$this->mailboxCheck();}else{try{LiteRuntime::log('cycle_error',['code'=>$code]);}catch(Throwable $ignore){}$s->last_error=$code;$s->historical_error=(object)['code'=>$code,'at'=>lm_iso(),'phase'=>'ERROR'];$s->phase='ERROR';$s->scan_handled_seq=max((int)lm_get($s,'scan_handled_seq',0),(int)lm_get($s,'scan_request_seq',0));$s->next_full_scan=lm_iso(time()+self::LOW_MEMORY_RETRY_SECONDS);$s->next_light_check=lm_iso(time()+self::LOW_MEMORY_RETRY_SECONDS);$this->saveState($s);}}
            $this->endTurn();$s=$this->state();$s->last_memory=LiteResource::sample();if($s->running&&!in_array($s->phase,['ERROR','SAVE_PENDING'],true))$s->phase='WAIT';$s->heartbeat_at=lm_iso();$this->saveState($s);for($i=1;$i<=60;$i++){sleep(30);$this->idleHeartbeat();$s=$this->state();if($s->stop_requested)break 2;$nf=is_string($s->next_full_scan)?strtotime($s->next_full_scan):false;$nl=is_string($s->next_light_check)?strtotime($s->next_light_check):false;$nm=is_string($s->next_mailbox_check)?strtotime($s->next_mailbox_check):false;if(($nf!==false&&$nf<=time())||($nl!==false&&$nl<=time())||($nm!==false&&$nm<=time()))break;}
        }}finally{$s=$this->state();$s->running=false;$s->phase='IDLE';$s->heartbeat_at=lm_iso();$this->saveState($s);flock($lock,LOCK_UN);fclose($lock);}
    }
}

function lm_web(): int {
    $cfg=new LiteConfig();$view=(string)($_GET['view']??'');$notice='';$ok=true;
    if($view==='health'){$health=LiteHealth::local($cfg,true);header('Content-Type: application/json; charset=utf-8');echo lm_json(['ok'=>true,'health'=>$health]);return 0;}
    $health=LiteHealth::local($cfg,false);$mon=null;$state=LiteMonitor::defaultState();
    try{$mon=new LiteMonitor();$state=$mon->state();}catch(Throwable $e){$ok=false;$notice=$e instanceof LiteMonFault?$e->faultCode:'MON_INIT_FAILED';}
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){$a=$_POST['action']??'';try{
        if($a==='start'){$health=LiteHealth::local($cfg,true);if(!$health['ready_to_start'])throw new LiteMonFault((string)($health['blockers'][0]??'NAS_PREFLIGHT_FAILED'));if($mon===null){$mon=new LiteMonitor();$state=$mon->state();}if(LiteRuntime::lockHeld())throw new LiteMonFault('ALREADY_RUNNING');$launchAt=microtime(true);$php=$cfg->phpCli;$cmd=escapeshellarg($php).' '.escapeshellarg(__FILE__).' --run >/dev/null 2>&1 &';@exec($cmd,$o,$rc);if($rc!==0)throw new LiteMonFault('WEB_PROCESS_LAUNCH_FAILED');$confirmed=false;for($i=0;$i<40;$i++){usleep(200000);clearstatcache();$fresh=$mon->state();if(lm_get($fresh,'running')===true&&LiteRuntime::lockHeld()&&lm_get(lm_get($fresh,'run_identity'),'source_sha256')===MON_LITE_SOURCE_SHA256&&(float)lm_get(lm_get($fresh,'run_identity'),'started_unix',0)>=$launchAt){$confirmed=true;break;}}if(!$confirmed)throw new LiteMonFault('DAEMON_START_NOT_CONFIRMED');$notice='시작 확인 완료';}
        elseif($a==='stop'){if($mon===null)throw new LiteMonFault('MON_RUNTIME_UNAVAILABLE');$mon->requestAction('stop');$notice='중지 요청을 기록했습니다.';}
        elseif($a==='scan'){if($mon===null)throw new LiteMonFault('MON_RUNTIME_UNAVAILABLE');$mon->requestAction('scan');$notice='다음 주기에 정밀 스캔을 요청했습니다.';}
        else throw new LiteMonFault('WEB_ACTION_INVALID');
    }catch(Throwable $e){$ok=false;$notice=$e instanceof LiteMonFault?$e->faultCode:'WEB_ERROR';}}
    if($mon!==null)$state=$mon->state();$runtime=is_array(lm_get($health,'runtime'))?$health['runtime']:['path'=>MON_LITE_RUNTIME,'file_count'=>0,'bytes'=>0];
    if($view==='status'){header('Content-Type: application/json; charset=utf-8');echo lm_json(['ok'=>$ok,'version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'policy'=>MON_LITE_POLICY,'installed_identity'=>LiteMonitor::installedIdentity(),'daemon_identity'=>lm_get($state,'run_identity'),'daemon_liveness'=>$mon?$mon->daemonLiveness():['status'=>'unknown'],'final_save'=>$mon?$mon->finalSaveStatus():['status'=>'unknown','last_local_receipt_verified'=>false],'state'=>$state,'runtime'=>$runtime,'health'=>$health,'message'=>$notice]);return $ok?0:1;}
    header('Content-Type: text/html; charset=utf-8');$h=function($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');};$nas=$health['ready_to_start']?'LOCAL PASS · 시작 시 CLI/통신 재검사':'BLOCK '.implode(',',(array)$health['blockers']);$final=$mon?$mon->finalSaveStatus():['status'=>'unknown','last_local_receipt_verified'=>false];$finalText=$final['last_local_receipt_verified']?'마지막 저장 영수증 검증 · '.(string)($final['saved_at']??''):((string)($final['status']??'unknown'));$alive=$mon?$mon->daemonLiveness():['status'=>'unknown'];$identity=lm_get($state,'run_identity');$daemonText=$identity?((string)lm_get($identity,'build','UNKNOWN').' · PID '.(string)lm_get($identity,'pid','UNKNOWN').' · 시작 '.(string)lm_get($identity,'started_at','UNKNOWN')):'UNKNOWN · 새 데몬 시작 후 확인';$mem=lm_get($state,'last_memory');$memText='관측 없음';if($mem){$room=lm_get($mem,'php_memory_headroom_bytes');$memText=(string)lm_get($mem,'observed_at','시각 미확인').' / '.(string)lm_get($mem,'process_sapi','미확인').' · 사용 '.round((int)lm_get($mem,'php_memory_used_bytes',0)/1048576,1).' MiB · 최대 '.round((int)lm_get($mem,'php_memory_peak_bytes',0)/1048576,1).' MiB · 여유 '.($room===null?'제한 없음':round((int)$room/1048576,1).' MiB');}
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MON 2.2.3 Lite</title><style>body{font-family:system-ui;margin:24px;max-width:760px}section{border:1px solid #ddd;border-radius:12px;padding:18px;margin:14px 0}button{padding:10px 18px;margin-right:8px}.bad{color:#a00}.ok{color:#073}</style><h1>MON 2.2.3 Lite</h1><section><b>상태:</b> '.$h($state->phase).' / '.$h($alive['status']).' <br><b>설치 Build:</b> '.$h(MON_LITE_BUILD).' <br><b>실행 데몬:</b> '.$h($daemonText).' <br><b>최근 응답:</b> '.$h($state->heartbeat_at).' <br><b>최근 정밀 스캔:</b> '.$h($state->last_full_scan).' <br><b>다음 경량 확인:</b> '.$h($state->next_light_check).' <br><b>다음 정밀 스캔:</b> '.$h($state->next_full_scan).' <br><b>현재 오류:</b> '.$h($state->last_error).' <br><b>자원 대기:</b> '.$h(lm_json($state->resource_wait)).' <br><b>이전 오류:</b> '.$h(lm_json($state->historical_error)).' <br><b>진행:</b> '.$h(lm_json($state->progress)).' <br><b>PHP CLI:</b> '.$h($cfg->phpCli).' <br><b>NAS 준비:</b> '.$h($nas).' <br><b>Mailbox:</b> '.$h($state->last_mailbox_check).' / 오류 '.$h($state->last_mailbox_error).' <br><b>데몬 메모리 관측:</b> '.$h($memText).' <br><b>참고자료 게시:</b> '.($state->publication_pending?'재시도 대기':'대기 없음').' / '.$h($state->last_publication_error).' <br><b>최종 결과 저장:</b> '.$h($finalText).' <br><b>저장:</b> '.$h(lm_get($runtime,'path',MON_LITE_RUNTIME)).' · '.$h(lm_get($runtime,'file_count',0)).' files · '.round((int)lm_get($runtime,'bytes',0)/1048576,2).' MiB</section><form method="post"><button name="action" value="start">시작</button><button name="action" value="stop">중지</button><button name="action" value="scan">정밀 스캔 요청</button></form><p class="'.($ok?'ok':'bad').'">'.$h($notice).'</p><section><b>운영 원칙</b><br>5분: mailbox 확인<br>30분: 경량 확인·실패 종목 재시도<br>정밀 스캔: 평일 16:40 KST · 증분 기본 / 7일마다 220봉 전체 재동기화<br>모든 부속파일: /web/mon_runtime 고정파일<br>운영 규격: '.$h(MON_LITE_POLICY).'</section>';
    return 0;
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){try{if(PHP_SAPI==='cli'){if(in_array('--run',$argv??[],true)){(new LiteMonitor())->loop();exit(0);}echo lm_json(['version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'policy'=>MON_LITE_POLICY,'runtime'=>LiteRuntime::view()])."\n";exit(0);}exit(lm_web());}catch(Throwable $e){$code=$e instanceof LiteMonFault?$e->faultCode:'MON_LITE_FATAL';try{LiteRuntime::log('fatal',['code'=>$code]);}catch(Throwable $ignore){}if(PHP_SAPI==='cli'){fwrite(STDERR,$code."\n");exit(1);}http_response_code(500);echo htmlspecialchars($code,ENT_QUOTES,'UTF-8');}}

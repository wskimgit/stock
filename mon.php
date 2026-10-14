<?php
/**
 * mon.php 1.8.7 -- measured throttling / KRX source recovery / independent trading refresh.
 * Repository: wskimgit/stock; data interface: mon_data.json schema 3.
 * MON owns analysis and result. The same daemon may publish an authenticated, immutable MON file pair.
 */
declare(strict_types=1);

// Put mon.php in /volume1/web, open /mon.php in a browser, then press Start.
// Reuse the existing SIS token and broker credential file in the same folder.
const MON_VERSION = '1.8.7';
const MON_PRIVATE_SYNC_CONFIG = __DIR__ . '/sis_private_sync_config.php';
const MON_BROKER_CONFIG = __DIR__ . '/broker_config.local.php';
const MON_CONFIG = [
    'GITHUB_TOKEN' => '',
    'KIS_APP_KEY' => '',
    'KIS_APP_SECRET' => '',
    'PHP_CLI' => '',
    'MON_OPERATOR_TOKEN' => '',
    'MON_OPERATOR_USER' => ''
];
const MON_SETTINGS_PREFIX = "<?php exit; ?>\n";

function mon_get($o, string $key, $default = null) {
    if (is_object($o) && property_exists($o, $key)) return $o->$key;
    if (is_array($o) && array_key_exists($key, $o)) return $o[$key];
    return $default;
}
function mon_iso(?int $ts = null): string { return gmdate('Y-m-d\TH:i:s\Z', $ts === null ? time() : $ts); }
function mon_monotonic(): float { return hrtime(true) / 1000000000; }
function mon_number($value): ?float {
    if ($value === null || is_bool($value)) return null;
    if (is_string($value)) $value = str_replace(',', '', trim($value));
    if (!is_numeric($value)) return null;
    $n = (float)$value;
    return is_finite($n) ? $n : null;
}
function mon_json($value, bool $pretty = true): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    if ($pretty) $flags |= JSON_PRETTY_PRINT;
    $s = json_encode($value, $flags);
    return $s . "\n";
}
function mon_decode(string $s) {
    return json_decode($s, false, 128, JSON_THROW_ON_ERROR);
}
function mon_time($s): ?int {
    if (!is_string($s) || !preg_match('/(?:Z|[+-]\d\d:\d\d)$/', $s)) return null;
    try { return (new DateTimeImmutable($s))->getTimestamp(); } catch (Throwable $e) { return null; }
}
class MonFault extends RuntimeException {
    public $faultCode;
    public $retryAfter;
    public function __construct(string $code, int $retryAfter = 0) {
        $this->faultCode = preg_replace('/[^A-Z0-9_.-]/i', '_', $code);
        $this->retryAfter = max(0, $retryAfter);
        parent::__construct($this->faultCode);
    }
}
class MonStop extends RuntimeException {}

final class MonStore {
    public $dir;
    public function __construct(string $dir) {
        $this->dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0777, true)) throw new MonFault('STATE_DIRECTORY_UNWRITABLE');
    }
    public function path(string $name): string {
        // Avoid collisions with existing status.json and other NAS web applications.
        if (strpos($name, 'mon') !== 0) $name = 'mon_' . $name;
        return $this->dir . DIRECTORY_SEPARATOR . $name;
    }
    public function read(string $name) {
        $p = $this->path($name);
        if (!is_file($p)) return null;
        $s = @file_get_contents($p);
        if ($s === false) throw new MonFault('STATE_READ_FAILED');
        try { return mon_decode($s); } catch (Throwable $e) { throw new MonFault('STATE_JSON_INVALID'); }
    }
    public function write(string $name, $value, string $prefix = ''): void {
        $tmp = @tempnam($this->dir, 'mon_');
        if ($tmp === false) throw new MonFault('STATE_WRITE_FAILED');
        try {
            $raw = $prefix . mon_json($value);
            if (@file_put_contents($tmp, $raw, LOCK_EX) !== strlen($raw)) throw new MonFault('STATE_WRITE_FAILED');
            @chmod($tmp, 0600);
            if (!@rename($tmp, $this->path($name))) throw new MonFault('STATE_RENAME_FAILED');
        } finally { if (is_file($tmp)) @unlink($tmp); }
    }
    public function readPrivate(string $name) {
        if (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $name)) throw new MonFault('STATE_NAME_INVALID');
        $path = $this->path($name); if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || strpos($raw, MON_SETTINGS_PREFIX) !== 0) throw new MonFault('PRIVATE_STATE_INVALID');
        try { return mon_decode(substr($raw, strlen(MON_SETTINGS_PREFIX))); }
        catch (Throwable $e) { throw new MonFault('PRIVATE_STATE_INVALID'); }
    }
    public function writePrivate(string $name, $value): void {
        if (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $name)) throw new MonFault('STATE_NAME_INVALID');
        $this->write($name, $value, MON_SETTINGS_PREFIX);
    }
    public function log(string $event, array $fields = []): void {
        // Never accept raw HTTP bodies, credentials or exception traces here.
        $safe = ['at' => mon_iso(), 'event' => $event];
        foreach (['code', 'symbol_id', 'status', 'quotes', 'attempts'] as $k) if (isset($fields[$k])) $safe[$k] = $fields[$k];
        $p = $this->path('mon.log');
        if (is_file($p) && filesize($p) > 2097152) { @unlink($p . '.1'); @rename($p, $p . '.1'); }
        @file_put_contents($p, json_encode($safe, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
}

final class MonConfig {
    public $values;
    public $webValues = [];
    private $privateSyncToken = '';
    private $privateSyncError = null;
    private $brokerCredentials = [];
    private $brokerError = null;
    public function __construct(?string $envFile = null, ?string $stateDir = null) {
        $values = [];
        $settingsPath = rtrim($stateDir ?? __DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mon_settings.php';
        if (is_file($settingsPath)) {
            $raw = @file_get_contents($settingsPath);
            if ($raw === false || strpos($raw, MON_SETTINGS_PREFIX) !== 0) throw new MonFault('WEB_SETTINGS_INVALID');
            try { $saved = mon_decode(substr($raw, strlen(MON_SETTINGS_PREFIX))); }
            catch (Throwable $e) { throw new MonFault('WEB_SETTINGS_INVALID'); }
            if (!($saved instanceof stdClass)) throw new MonFault('WEB_SETTINGS_INVALID');
            foreach ((array)$saved as $key => $value) {
                if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || !is_string($value) || strlen($value) > 8192 || preg_match('/[\r\n\x00]/', $value)) throw new MonFault('WEB_SETTINGS_INVALID');
                $values[$key] = $value;
            }
            $this->webValues = $values;
        }
        if ($envFile !== null && is_file($envFile)) {
            $lines = @file($envFile, FILE_IGNORE_NEW_LINES);
            if ($lines === false) throw new MonFault('ENV_READ_FAILED');
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) throw new MonFault('ENV_FORMAT_INVALID');
                $v = trim($m[2]);
                if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) $v = substr($v, 1, -1);
                $values[$m[1]] = $v; // Literal values; no eval, shell expansion or putenv.
            }
        }
        $this->values = $values;
        $this->loadPrivateSync();
        $this->loadBroker();
    }
    private function loadPrivateSync(): void {
        $path = MON_PRIVATE_SYNC_CONFIG;
        clearstatcache(true, $path);
        if (!is_file($path)) return;
        if (!is_readable($path)) { $this->privateSyncError = 'PRIVATE_SYNC_CONFIG_UNREADABLE'; return; }
        // Include the user's existing PHP array in an isolated scope. Never
        // import its SIS repository/branch, copy its key or emit its output.
        $level = ob_get_level(); ob_start();
        try {
            if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
            $shared = (static function(string $file) { return @include $file; })($path);
        } catch (Throwable $e) {
            $this->privateSyncError = 'PRIVATE_SYNC_CONFIG_INVALID'; return;
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
        }
        if (!is_array($shared)) { $this->privateSyncError = 'PRIVATE_SYNC_CONFIG_INVALID'; return; }
        foreach (['github_token', 'GITHUB_TOKEN', 'SIS_GITHUB_TOKEN'] as $key) {
            if (!array_key_exists($key, $shared)) continue;
            $token = $shared[$key];
            if (!is_string($token) || strlen($token) > 8192 || preg_match('/[\r\n\x00]/', $token)) {
                $this->privateSyncError = 'PRIVATE_SYNC_TOKEN_INVALID'; return;
            }
            $token = trim($token);
            if ($token === '') continue;
            if (preg_match('/\s/', $token)) { $this->privateSyncError = 'PRIVATE_SYNC_TOKEN_INVALID'; return; }
            $this->privateSyncToken = $token; return;
        }
        $this->privateSyncError = 'PRIVATE_SYNC_TOKEN_MISSING';
    }
    private function loadBroker(): void {
        $path = MON_BROKER_CONFIG; clearstatcache(true, $path);
        if (!is_file($path)) return;
        if (!is_readable($path)) { $this->brokerError = 'BROKER_CONFIG_UNREADABLE'; return; }
        $level = ob_get_level(); ob_start();
        try {
            // Read the user's existing returned array in an isolated scope.
            // Import only the credential pair, not broker modes or order settings.
            if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
            $shared = (static function(string $file) { return @include $file; })($path);
        } catch (Throwable $e) {
            $this->brokerError = 'BROKER_CONFIG_INVALID'; return;
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
        }
        if (!is_array($shared)) { $this->brokerError = 'BROKER_CONFIG_INVALID'; return; }
        $pair = ['KIS_APP_KEY'=>$shared['app_key']??$shared['KIS_APP_KEY']??'',
                 'KIS_APP_SECRET'=>$shared['app_secret']??$shared['KIS_APP_SECRET']??''];
        foreach ($pair as &$value) {
            if (!is_string($value) || strlen($value)>8192 || preg_match('/[\r\n\x00]/',$value)) {
                $this->brokerError = 'BROKER_CREDENTIALS_INVALID'; return;
            }
            $value = trim($value);
            if ($value === '') { $this->brokerError = 'BROKER_CREDENTIALS_MISSING'; return; }
            if (preg_match('/\s/',$value)) { $this->brokerError = 'BROKER_CREDENTIALS_INVALID'; return; }
        }
        unset($value);
        $this->brokerCredentials = $pair; // Apply both values together; never mix credential sources.
    }
    public function get(string $key, string $default = ''): string {
        if ($key === 'GITHUB_TOKEN' && $this->privateSyncToken !== '') return $this->privateSyncToken;
        if (array_key_exists($key,$this->brokerCredentials)) return $this->brokerCredentials[$key];
        // An explicit browser save must take effect even when the NAS inherited
        // an empty or older environment value. Other environment settings retain precedence.
        if (in_array($key,['GITHUB_TOKEN','KIS_APP_KEY','KIS_APP_SECRET','PHP_CLI'],true) && array_key_exists($key,$this->webValues)) return $this->webValues[$key];
        $v = getenv($key);
        return $v !== false ? $v : ($this->values[$key] ?? MON_CONFIG[$key] ?? $default);
    }
    public function githubTokenSource(): string {
        if ($this->privateSyncToken !== '') return 'sis_private_sync_config.php';
        if ($this->get('GITHUB_TOKEN') === '') return 'missing';
        if (array_key_exists('GITHUB_TOKEN', $this->webValues)) return 'mon_settings.php';
        return getenv('GITHUB_TOKEN') !== false ? 'environment' : 'env_or_MON_CONFIG';
    }
    public function githubTokenError(): string {
        return $this->get('GITHUB_TOKEN') !== '' ? '' : ($this->privateSyncError ?? 'GITHUB_TOKEN_MISSING');
    }
    public function kisCredentialsSource(): string {
        if ($this->brokerCredentials) return 'broker_config.local.php';
        if ($this->get('KIS_APP_KEY')==='' || $this->get('KIS_APP_SECRET')==='') return 'missing';
        return 'web_or_environment';
    }
    public function kisConfigError(): ?string {
        return $this->kisCredentialsSource() === 'missing' ? $this->brokerError : null;
    }
    public function int(string $key, int $default, int $min, int $max): int {
        $s = $this->get($key, (string)$default);
        if (!preg_match('/^\d+$/', $s)) throw new MonFault('ENV_NUMBER_INVALID');
        return max($min, min($max, (int)$s));
    }
}

function mon_settings_stamp(MonStore $store,string $envFile): string {
    $parts=[];$paths=[$store->path('settings.php'),MON_PRIVATE_SYNC_CONFIG,MON_BROKER_CONFIG];if($envFile!=='')$paths[]=$envFile;
    foreach($paths as $path){
        clearstatcache(true,$path);
        $raw=is_file($path)?@file_get_contents($path):'';
        $parts[]=hash('sha256',$path."\0".($raw===false?'unreadable':$raw));
    }
    return hash('sha256',implode('|',$parts));
}

// A previous boot's files, CLI/cron invocation or browser GET cannot grant a start.
final class MonManualStart {
    private $store;private $boot;private $clock;
    public function __construct(MonStore $store,?callable $boot=null,?callable $clock=null){
        $this->store=$store;$this->boot=$boot??static function(){return self::bootId();};$this->clock=$clock??static function(){return time();};
    }
    public static function bootId(): ?string {
        $id=trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
        if(preg_match('/^[a-f0-9-]{36}$/i',$id))return strtolower($id);
        $stat=(string)@file_get_contents('/proc/stat');
        if(preg_match('/^btime\s+(\d+)$/m',$stat,$m))return 'btime:'.$m[1];
        return null; // Fail closed when the current boot cannot be verified.
    }
    private function guarded(callable $fn){
        $h=@fopen($this->store->path('start_gate.lock'),'c+');
        if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if($h)fclose($h);throw new MonFault('WEB_START_BUSY');}
        try{return $fn();}finally{flock($h,LOCK_UN);fclose($h);}
    }
    public function issue(string $instance): string {
        return $this->guarded(function()use($instance){
            $boot=($this->boot)();if(!is_string($boot)||$boot==='')throw new MonFault('BOOT_ID_UNAVAILABLE');
            if(!preg_match('/^[a-f0-9]{24}$/',$instance))throw new MonFault('MANUAL_START_REQUIRED');
            $ticket=bin2hex(random_bytes(32));$now=(int)($this->clock)();
            $this->store->writePrivate('manual_start.php',(object)['boot_id'=>$boot,'instance_id'=>$instance,'state'=>'issued',
                'issued_at'=>$now,'expires_at'=>$now+15,'ticket_sha256'=>hash('sha256',$ticket)]);
            return $ticket;
        });
    }
    private function checked(string $instance,string $ticket){
        $r=$this->store->readPrivate('manual_start.php');$boot=($this->boot)();$now=(int)($this->clock)();
        if(!is_string($boot)||$boot===''||!preg_match('/^[a-f0-9]{64}$/',$ticket)||mon_get($r,'state')!=='issued'
            ||mon_get($r,'boot_id')!==$boot||mon_get($r,'instance_id')!==$instance||!is_int(mon_get($r,'issued_at'))
            ||!is_int(mon_get($r,'expires_at'))||$r->issued_at>$now||$r->expires_at<=$now||$r->expires_at-$r->issued_at!==15
            ||!hash_equals((string)mon_get($r,'ticket_sha256',''),hash('sha256',$ticket)))throw new MonFault('MANUAL_START_REQUIRED');
        return $r;
    }
    public function validate(string $instance,string $ticket): void {$this->guarded(function()use($instance,$ticket){$this->checked($instance,$ticket);});}
    public function consume(string $instance,string $ticket): string {
        return $this->guarded(function()use($instance,$ticket){$r=$this->checked($instance,$ticket);$r->state='active';
            unset($r->ticket_sha256);$this->store->writePrivate('manual_start.php',$r);return $r->boot_id;});
    }
    public function revoke(?string $fallbackInstance=null): ?string {
        return $this->guarded(function()use($fallbackInstance){
            $r=$this->store->readPrivate('manual_start.php');$boot=($this->boot)();$id=$fallbackInstance;
            if(is_string($boot)&&mon_get($r,'boot_id')===$boot&&in_array(mon_get($r,'state'),['issued','active'],true))$id=mon_get($r,'instance_id',$id);
            $this->store->writePrivate('manual_start.php',(object)['boot_id'=>$boot,'instance_id'=>$id,'state'=>'revoked']);
            if(is_string($id))$this->store->write('stop.json',(object)['instance_id'=>$id,'requested_at'=>mon_iso()]);
            return $id;
        });
    }
}

final class MonDaemonLoad {
    const HEARTBEAT_SECONDS=20;
    private $read;private $pauses=0;private $recoveries=0;private $cpuPrevious=null;private $pauseReason=null;
    public $priorityApplied=false;public $memoryLimitApplied=false;
    public function __construct(?callable $read=null){$this->read=$read??static function(){return self::system();};}
    public static function system(): array {
        $stat=(string)@file_get_contents('/proc/stat');$cores=preg_match_all('/^cpu\d+\s/m',$stat,$m);$cores=max(1,(int)$cores);
        $load=function_exists('sys_getloadavg')?@sys_getloadavg():false;
        $mem=(string)@file_get_contents('/proc/meminfo');$total=null;$available=null;
        if(preg_match('/^MemTotal:\s+(\d+)\s+kB/m',$mem,$m))$total=(int)$m[1]*1024;
        if(preg_match('/^MemAvailable:\s+(\d+)\s+kB/m',$mem,$m))$available=(int)$m[1]*1024;
        // Older NAS kernels lack MemAvailable. Do not mistake filesystem cache for occupied RAM.
        elseif(preg_match_all('/^(MemFree|Buffers|Cached|SReclaimable|Shmem):\s+(\d+)\s+kB/m',$mem,$rows,PREG_SET_ORDER)){
            $parts=[];foreach($rows as $row)$parts[$row[1]]=(int)$row[2]*1024;
            if(isset($parts['MemFree'],$parts['Buffers'],$parts['Cached']))$available=max(0,$parts['MemFree']+$parts['Buffers']+$parts['Cached']+($parts['SReclaimable']??0)-($parts['Shmem']??0));
        }
        $ticks=null;$busy=null;
        if(preg_match('/^cpu\s+([^\r\n]+)/m',$stat,$m)){
            $v=preg_split('/\s+/',trim($m[1]));
            if(count($v)>=4&&count(array_filter(array_slice($v,0,8),'is_numeric'))===min(8,count($v))){
                $v=array_map('floatval',array_slice($v,0,8));$ticks=array_sum($v);$busy=$ticks-($v[3]??0)-($v[4]??0);
            }
        }
        return ['cores'=>$cores,'load1'=>is_array($load)?$load[0]:null,'memory_total_bytes'=>$total,'memory_available_bytes'=>$available,
            'cpu_total_ticks'=>$ticks,'cpu_busy_ticks'=>$busy];
    }
    public function apply(): void {
        if(mon_function_available('proc_nice'))$this->priorityApplied=@proc_nice(10);
        $limit=trim((string)ini_get('memory_limit'));$bytes=-1;
        if(preg_match('/^(\d+)\s*([KMG]?)$/i',$limit,$m))$bytes=(int)$m[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($m[2])]));
        if($bytes<0||$bytes>96*1048576)$this->memoryLimitApplied=@ini_set('memory_limit','96M')!==false;
        else $this->memoryLimitApplied=true; // Keep a stricter pre-existing limit.
    }
    public function sample(): array {
        $s=($this->read)();$cores=max(1,(int)mon_get($s,'cores',1));$load=mon_number(mon_get($s,'load1'));
        $total=mon_number(mon_get($s,'memory_total_bytes'));$available=mon_number(mon_get($s,'memory_available_bytes'));
        $pct=mon_number(mon_get($s,'cpu_busy_percent'));$ticks=mon_number(mon_get($s,'cpu_total_ticks'));$busy=mon_number(mon_get($s,'cpu_busy_ticks'));
        if($pct!==null&&($pct<0||$pct>100))$pct=null;
        if($pct===null&&$ticks!==null&&$busy!==null&&$ticks>=0&&$busy>=0&&$busy<=$ticks&&$this->cpuPrevious!==null){
            $dt=$ticks-$this->cpuPrevious[0];$db=$busy-$this->cpuPrevious[1];
            if($dt>0&&$db>=0&&$db<=$dt)$pct=round(100*$db/$dt,3);
        }
        $this->cpuPrevious=$ticks!==null&&$busy!==null&&$ticks>=0&&$busy>=0&&$busy<=$ticks?[$ticks,$busy]:null;
        $floor=max(32*1048576,($total??0)*.10);$hard=$available!==null&&$available<$floor?'memory':($pct!==null&&$pct>=95?'cpu':null);
        $soft=$available!==null&&$available<$floor*1.25?'memory_headroom':($pct!==null&&$pct>=80?'cpu':($load!==null&&$load>1.25*$cores?'load':null));
        if($load===null&&$available===null&&$pct===null)$soft='measurement_unavailable';
        $reason=$hard;$wait=0;$state=$soft===null?'normal':'throttled';
        if($hard!==null){$this->pauses++;$this->pauseReason=$hard;$this->recoveries=0;$state='deferred';$wait=min(120,30*(2**min(2,$this->pauses-1)));}
        elseif($this->pauses){
            $memorySafe=$available===null?$this->pauseReason!=='memory':$available>=$floor*1.25;
            $safe=($pct===null?($this->pauseReason==='memory'||$load!==null&&$load<=.9*$cores):$pct<80)&&$memorySafe;
            $this->recoveries=$safe?$this->recoveries+1:0;
            if($this->recoveries>=2){$this->pauses=0;$this->recoveries=0;$this->pauseReason=null;$reason=$soft;}
            else{$state='deferred';$reason='recovering';$wait=$safe?5:min(120,30*(2**min(2,$this->pauses-1)));}
        }else $reason=$soft;
        if($state==='throttled')$wait=30;
        return ['policy'=>'MON-RESOURCE-1.1','state'=>$state,'reason'=>$reason,'wait_seconds'=>$wait,'cores'=>$cores,'load1'=>$load,
            'cpu_busy_percent'=>$pct,'cpu_measurement_available'=>$pct!==null,'work_allowed'=>$state!=='deferred',
            'memory_available_bytes'=>$available,'memory_floor_bytes'=>$floor,'measurement_available'=>$load!==null||$available!==null||$pct!==null,
            'priority_applied'=>$this->priorityApplied,'memory_limit_applied'=>$this->memoryLimitApplied];
    }
    public static function settings(array $s,?array $resource=null): array {
        $s['tick_budget_seconds']=min(25,$s['tick_budget_seconds']);$s['detail_jobs_per_tick']=min(1,$s['detail_jobs_per_tick']);
        $s['detail_budget_seconds']=min(8,$s['detail_budget_seconds']);$s['detail_symbol_budget_seconds']=min(8,$s['detail_symbol_budget_seconds']);
        $s['collection_budget_seconds']=min(6,$s['collection_budget_seconds']);$s['quote_symbols_per_tick']=2;$s['quote_symbol_budget_seconds']=3;
        $s['publish_reserve_seconds']=min(5,$s['publish_reserve_seconds']);$s['request_poll_seconds']=max(30,$s['request_poll_seconds']);
        if(mon_get($resource,'state')==='throttled'){
            $s['tick_budget_seconds']=min(20,$s['tick_budget_seconds']);$s['quote_symbols_per_tick']=1;$s['collection_budget_seconds']=min(3,$s['collection_budget_seconds']);
            $s['publish_reserve_seconds']=min(3,$s['publish_reserve_seconds']);$s['request_poll_seconds']=max(60,$s['request_poll_seconds']);
            if(mon_get($resource,'reason')==='memory_headroom')$s['detail_jobs_per_tick']=0;
        }
        return $s;
    }
    public static function cpuSeconds(): ?float {
        $r=mon_function_available('getrusage')?@getrusage():false;if(!is_array($r))return null;
        return ($r['ru_utime.tv_sec']??0)+($r['ru_utime.tv_usec']??0)/1e6+($r['ru_stime.tv_sec']??0)+($r['ru_stime.tv_usec']??0)/1e6;
    }
    public static function waitSeconds(float $poll,float $elapsed,?float $cpuUsed): float {
        // After each cycle allow at least five idle seconds and 9x actual CPU time (10% duty target).
        return max(5,$poll-$elapsed,$cpuUsed===null?0:max(0,$cpuUsed)*9);
    }
}

final class MonControl {
    public $store;
    public $instance;
    public $stop = false;
    public function __construct(MonStore $store, string $instance) { $this->store = $store; $this->instance = $instance; }
    public function check(): void {
        if (!$this->stop && is_file($this->store->path('stop.json'))) {
            $r = $this->store->read('stop.json');
            if (mon_get($r, 'instance_id') === $this->instance) $this->stop = true;
        }
        if ($this->stop) throw new MonStop();
    }
    public function wait(float $seconds): void {
        $end = mon_monotonic() + max(0, $seconds);
        do { $this->check(); $left = $end - mon_monotonic(); if ($left > 0) usleep((int)(min($left, 0.2) * 1000000)); } while ($left > 0);
    }
}

class MonHttp {
    private $control;
    private $transport;
    private $lastQuoteStart = null;
    private $deadlineMap=[];private $wall;private $mono;private $circuit=[];private $outageEvidence=[];
    public function __construct(?MonControl $control = null, ?callable $transport = null,?callable $wall=null,?callable $mono=null) {
        $this->control=$control;$this->transport=$transport;$this->wall=$wall?:static function(){return microtime(true);};$this->mono=$mono?:static function(){return mon_monotonic();};
    }
    public function rememberDeadline(float $deadline): float {
        $key=sprintf('%.6F',$deadline);$now=(float)($this->mono)();
        if(!isset($this->deadlineMap[$key])){
            if(count($this->deadlineMap)>128)foreach($this->deadlineMap as $old=>$end)if($end<$now-60)unset($this->deadlineMap[$old]);
            $this->deadlineMap[$key]=$now+max(0,$deadline-(float)($this->wall)());
        }return $this->deadlineMap[$key];
    }
    public function remaining(float $deadline): float {return max(0,min($deadline-(float)($this->wall)(),$this->rememberDeadline($deadline)-(float)($this->mono)()));}
    // Only common provider outages are shared. A missing symbol never blocks its peers.
    private function circuitFailure(string $host,string $code,int $retryAfter=0,string $resource=''): void {
        if(!in_array($host,['openapi.koreainvestment.com','m.stock.naver.com','query1.finance.yahoo.com','fred.stlouisfed.org','stockanalysis.com','api.nasdaq.com'],true))return;
        $network=preg_match('/^HTTP_TRANSPORT_(5|6|7|28|35|52|55|56|60)$/',$code)===1;
        if(!$network&&$code!=='HTTP_429'&&preg_match('/^HTTP_5\d\d$/',$code)!==1)return;
        $now=(float)($this->mono)();foreach($this->circuit as $key=>$row)if($row['until']<=$now)unset($this->circuit[$key]);
        $seconds=$code==='HTTP_429'?min(300,max(30,$retryAfter)):30;
        // A slow/broken single resource is insufficient evidence that the whole provider is down.
        if(($code==='HTTP_TRANSPORT_28'||preg_match('/^HTTP_5\d\d$/',$code)===1)&&$resource!==''){
            $this->circuit[$resource]=['until'=>$now+$seconds,'code'=>$code];
            $proof=$this->outageEvidence[$host]??[];foreach($proof as $key=>$until)if($until<=$now)unset($proof[$key]);
            $proof[$resource]=$now+$seconds;$this->outageEvidence[$host]=$proof;if(count($proof)<2)return;
        }
        $this->circuit[$host]=['until'=>$now+$seconds,'code'=>$code];
    }
    private function response(string $host,array $r,float $end,string $resource): array {
        $r['_deadline_missed']=(float)($this->mono)()>$end;
        $status=(int)mon_get($r,'status',0);$after=mon_get(mon_get($r,'headers',[]),'retry-after','0');
        if($status===429||$status>=500)$this->circuitFailure($host,'HTTP_'.$status,ctype_digit((string)$after)?(int)$after:0,$resource);
        elseif($status>=200&&$status<300){unset($this->circuit[$resource],$this->outageEvidence[$host][$resource]);}
        return $r;
    }
    public function request(string $method, string $url, array $headers, ?string $body, float $deadline, float $timeout = 5.0, float $spacing = 0.0): array {
        $host=(string)parse_url($url,PHP_URL_HOST);$begin=(float)($this->mono)();$code=null;$sent=false;
        try {
            if(MonExecution::$active){
                if(parse_url($url,PHP_URL_SCHEME)!=='https'||!in_array($host,['api.github.com','openapi.koreainvestment.com','m.stock.naver.com','query1.finance.yahoo.com','fred.stlouisfed.org','stockanalysis.com','api.nasdaq.com'],true))throw new MonFault('HTTP_HOST_NOT_ALLOWED');
                $timeout=min($timeout,MonExecution::httpTimeout($host,$timeout));
            }
            $r=$this->perform($method,$url,$headers,$body,$deadline,$timeout,$spacing,$sent);
            $status=(int)mon_get($r,'status');$code=mon_get($r,'_deadline_missed',false)?'HTTP_LATE_RESPONSE':(($status>=200&&$status<300)||$status===304?null:'HTTP_'.$status);
            return $r;
        }catch(MonFault $e){$code=$e->faultCode;throw $e;}
        catch(MonStop $e){$code='INTERRUPTED';throw $e;}
        catch(Throwable $e){$code='HTTP_INTERNAL_ERROR';throw $e;}
        finally{if($sent)MonExecution::measure('http/'.$host,max(0,(float)($this->mono)()-$begin),$code,1,'server');}
    }
    private function perform(string $method, string $url, array $headers, ?string $body, float $deadline, float $timeout, float $spacing,bool &$sent): array {
        if ($this->control) $this->control->check();
        $end=$this->rememberDeadline($deadline);
        if($this->remaining($deadline)<.05)throw new MonFault('BUDGET_EXHAUSTED');
        $host=parse_url($url,PHP_URL_HOST);
        if(parse_url($url,PHP_URL_SCHEME)!=='https'||!in_array($host,['api.github.com','openapi.koreainvestment.com','m.stock.naver.com','query1.finance.yahoo.com','fred.stlouisfed.org','stockanalysis.com','api.nasdaq.com'],true))throw new MonFault('HTTP_HOST_NOT_ALLOWED');
        $resource=$host.'|'.hash('sha256',$method.'|'.$url);
        foreach([$host,$resource]as $key)if(isset($this->circuit[$key])&&$this->circuit[$key]['until']>(float)($this->mono)())throw new MonFault('HTTP_CIRCUIT_OPEN',max(1,(int)ceil($this->circuit[$key]['until']-(float)($this->mono)())));
        if ($spacing > 0) {
            $wait = $this->lastQuoteStart===null?0:$this->lastQuoteStart+$spacing-(float)($this->mono)();
            if ($wait > 0) {
                if ($wait >= $this->remaining($deadline)) throw new MonFault('BUDGET_EXHAUSTED');
                if ($this->control) $this->control->wait($wait); else usleep((int)($wait * 1000000));
            }
            $this->lastQuoteStart = (float)($this->mono)();
        }
        $left = $this->remaining($deadline);
        if ($left < 0.05) throw new MonFault('BUDGET_EXHAUSTED');
        $timeout = min($timeout, $left);
        // A blocked circuit/expired deadline is a skip, not a network attempt.
        if(!$this->transport&&!extension_loaded('curl'))throw new MonFault('PHP_CURL_REQUIRED');
        if(MonExecution::$active)MonExecution::consumeHttp($host);$sent=true;
        if ($this->transport){
            try{return $this->response($host,($this->transport)($method,$url,$headers,$body,$timeout),$end,$resource);}
            catch(MonFault $e){$this->circuitFailure($host,$e->faultCode,$e->retryAfter,$resource);throw $e;}
        }
        $responseHeaders = []; $responseBody = ''; $tooLarge = false;
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => (int)(min(2.0, $timeout) * 1000),
            CURLOPT_TIMEOUT_MS => max(1, (int)($timeout * 1000)),
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'mon.php/'.MON_VERSION,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
                $p = strpos($line, ':'); if ($p !== false) $responseHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$responseBody, &$tooLarge) {
                if (strlen($responseBody) + strlen($chunk) > 3145728) { $tooLarge = true; return 0; }
                $responseBody .= $chunk; return strlen($chunk);
            }
        ];
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
        if ($this->control) {
            $control = $this->control;
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_XFERINFOFUNCTION] = static function () use ($control) {
                try { $control->check(); return 0; } catch (MonStop $e) { return 1; }
            };
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $errno = curl_errno($ch); curl_close($ch);
        if ($this->control) $this->control->check();
        if ($ok === false){$code=$tooLarge?'HTTP_RESPONSE_TOO_LARGE':'HTTP_TRANSPORT_'.$errno;$this->circuitFailure($host,$code,0,$resource);throw new MonFault($code);}
        return $this->response($host,['status'=>$status,'headers'=>$responseHeaders,'body'=>$responseBody],$end,$resource);
    }
    public static function decode(array $r) {
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $after = mon_get($r['headers'], 'retry-after', '');
            $delay = preg_match('/^\d+$/', (string)$after) ? (int)$after : 0;
            if (mon_get($r['headers'], 'x-ratelimit-remaining') === '0') $delay = max($delay, (int)mon_get($r['headers'], 'x-ratelimit-reset', time()) - time());
            throw new MonFault('HTTP_' . $r['status'], min(3600, max(0, $delay)));
        }
        try { return mon_decode($r['body']); } catch (Throwable $e) { throw new MonFault('HTTP_JSON_INVALID'); }
    }
}

/* Read-only NAS client. One poll sends at most one GET; the caller continues other work until retry_at.
 * This is separate from the daemon's short provider sockets. It never launches/restarts the daemon.
 */
final class MonNasConnection {
    const CONTRACT='MON-NAS-CONNECTION-1.0';
    const ENDPOINT='https://k-bizpost.myds.me/mon.php?view=bootstrap';
    private $store;private $runId;private $name;private $transport;private $wall;private $mono;private $cancel;
    public function __construct(MonStore $store,string $runId,?callable $transport=null,?callable $wall=null,?callable $mono=null,?callable $cancel=null){
        if(!preg_match('/^[A-Za-z0-9._:-]{1,96}$/',$runId))throw new MonFault('NAS_RUN_ID_INVALID');
        $this->store=$store;$this->runId=$runId;$this->name='nas-'.hash('sha256',$runId).'.php';$this->transport=$transport;
        $this->wall=$wall?:static function(){return microtime(true);};$this->mono=$mono?:static function(){return mon_monotonic();};$this->cancel=$cancel;
    }
    public static function policy(): array {
        return ['contract'=>self::CONTRACT,'connect_seconds'=>[10,20,40,60],'request_seconds'=>[20,40,80,120],
            'retry_delays_seconds'=>[30,60,120,240,300],'max_attempts'=>null,'stop_on_threshold'=>false,
            'minimum_processor_version'=>'1.8.0','healthy_refresh_seconds'=>10];
    }
    private function checked(string $name){
        $e=$this->store->readPrivate($name);if($e===null)return null;$s=mon_get($e,'state');
        if(!($s instanceof stdClass)||mon_get($s,'contract')!==self::CONTRACT||
            !is_string(mon_get($e,'sha256'))||!hash_equals($e->sha256,hash('sha256',mon_json($s,false))))throw new MonFault('NAS_STATE_INVALID');
        return $s;
    }
    private function save(string $name,stdClass $s): void {
        $this->store->writePrivate($name,(object)['state'=>$s,'sha256'=>hash('sha256',mon_json($s,false))]);
    }
    private function state(): stdClass {
        $s=$this->checked($this->name);if($s!==null){if(mon_get($s,'run_id')!==$this->runId)throw new MonFault('NAS_STATE_INVALID');return $s;}
        $stats=$this->checked('nas-connection-stats.php');$samples=mon_get($stats,'success_seconds',[]);sort($samples,SORT_NUMERIC);
        $p90=$samples?$samples[(int)ceil(count($samples)*.9)-1]:null;
        $connect=$p90===null?10:min(60,max(10,(int)ceil($p90*1.5)));
        return (object)['contract'=>self::CONTRACT,'run_id'=>$this->runId,'received_at'=>mon_iso((int)($this->wall)()),'state'=>'pending',
            'connected'=>false,'compatible'=>false,'processor_version'=>null,'attempts'=>0,'failures'=>0,'timeouts'=>0,'recoveries'=>0,
            'consecutive_failures'=>0,'total_seconds'=>0.0,'max_seconds'=>0.0,'retry_at'=>0.0,'pending_attempt'=>null,
            'initial_connect_seconds'=>$connect,'initial_request_seconds'=>max(20,$connect*2),'learned_p90_seconds'=>$p90,'history'=>[]];
    }
    private function view(stdClass $s): array {
        $p=self::policy();$i=min(3,(int)$s->consecutive_failures);
        return array_merge((array)$s,['next_connect_seconds'=>max($s->initial_connect_seconds,$p['connect_seconds'][$i]),
            'next_request_seconds'=>max($s->initial_request_seconds,$p['request_seconds'][$i]),
            'retry_in_seconds'=>max(0,(float)$s->retry_at-(float)($this->wall)()),'max_attempts'=>null,'stop_on_threshold'=>false]);
    }
    public function status(): array {return $this->view($this->state());}
    private function locked(callable $fn): array {
        // Across run IDs as well: one NAS request on this client, no overlap or busy waiting.
        $lock=@fopen($this->store->path('nas-connection.lock'),'c+');
        if(!$lock)throw new MonFault('NAS_LOCK_UNAVAILABLE');@chmod($this->store->path('nas-connection.lock'),0600);
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['contract'=>self::CONTRACT,'state'=>'client_busy','connected'=>false,'retry_in_seconds'=>10];}
        try{return $fn();}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function resume(): array {
        // Explicit corrective action only. Reloading/status reads do not erase backoff or counters.
        return $this->locked(function(){
            $s=$this->state();if(in_array($s->state,['blocked','interrupted'],true)){$s->state='retry_wait';$s->retry_at=(float)($this->wall)();$this->save($this->name,$s);}
            return $this->view($s);
        });
    }
    private function checkCancel(): void {if($this->cancel)($this->cancel)();}
    private static function retryAfter(array $headers,float $now): float {
        $value='';foreach($headers as $k=>$v)if(strtolower((string)$k)==='retry-after')$value=trim((string)$v);
        if(preg_match('/^\d+$/',$value))return (float)$value;
        $date=$value!==''?strtotime($value):false;return $date===false?0:max(0,$date-$now);
    }
    private static function outcome(array $r): array {
        $status=(int)mon_get($r,'status',0);$code=mon_get($r,'error_code');
        if($code!==null)return ['state'=>$code==='INTERRUPTED'?'interrupted':($code==='NAS_REQUEST_UNCONFIRMED'||
            preg_match('/^(HTTP_TRANSPORT_(5|6|7|28|35|52|55|56|60)|HTTP_(408|425|429|5\d\d))$/',(string)$code)?'retry_wait':'blocked'),'code'=>$code];
        if($status===408||$status===425||$status===429||$status>=500&&$status<=599)return ['state'=>'retry_wait','code'=>'HTTP_'.$status];
        if($status!==200)return ['state'=>'blocked','code'=>'HTTP_'.$status];
        $body=(string)mon_get($r,'body','');
        try{$j=mon_decode($body);}catch(Throwable $e){$j=null;}
        if($j instanceof stdClass&&mon_get($j,'ok')===true){
            $result=mon_get($j,'result');$header=mon_get($result,'header',$result);$v=mon_get($j,'processor_version',mon_get($header,'processor_version'));
            if(mon_get($header,'contract')==='MON-BOOTSTRAP-1.0'&&is_string($v)&&preg_match('/^\d+\.\d+\.\d+$/',$v)&&
                mon_get($header,'processor_version',$v)===$v&&is_string(mon_get($header,'state'))&&is_array(mon_get($result,'cards')))
                return ['state'=>version_compare($v,'1.8.0','>=')?'connected':'connected_legacy','code'=>null,'version'=>$v];
            $data=mon_get($j,'data');$v=mon_get($data,'version');
            if($data instanceof stdClass&&is_string($v)&&preg_match('/^\d+\.\d+\.\d+$/',$v)&&is_bool(mon_get($data,'running'))&&is_bool(mon_get($data,'heartbeat_fresh')))
                return ['state'=>'connected_legacy','code'=>null,'version'=>$v];
        }
        // A login/error page or arbitrary HTTP 200 must not be reported as a working MON API.
        if(strpos($body,'<title>mon 모니터</title>')!==false&&preg_match('/<header>.*?<h1>mon 모니터<\/h1>.*?<span>v(\d+\.\d+\.\d+)<\/span>/s',$body,$m))
            return ['state'=>'connected_legacy','code'=>null,'version'=>$m[1]];
        return ['state'=>'blocked','code'=>'NAS_RESPONSE_CONTRACT_INVALID'];
    }
    private function settle(stdClass $s,array $r,?float $seconds): void {
        $o=self::outcome($r);$s->pending_attempt=null;$s->state=$o['state'];$s->last_code=$o['code'];$s->last_http_status=(int)mon_get($r,'status',0);
        $s->connected=in_array($s->state,['connected','connected_legacy'],true);$s->compatible=$s->state==='connected';$s->processor_version=$o['version']??null;
        $row=(object)['attempt'=>$s->attempts,'at'=>mon_iso((int)($this->wall)()),'state'=>$s->state,'code'=>$o['code'],'http_status'=>$s->last_http_status,
            'seconds'=>$seconds===null?null:round($seconds,6),'connect_seconds'=>$s->last_connect_seconds,'request_seconds'=>$s->last_request_seconds];
        if($seconds!==null){$s->total_seconds+=$seconds;$s->max_seconds=max($s->max_seconds,$seconds);}
        $s->history=array_slice(array_merge($s->history,[$row]),-20);$s->retry_at=0.0;
        if($s->connected){
            $s->recoveries+=(int)($s->consecutive_failures>0);$s->consecutive_failures=0;
            if($seconds!==null){$stats=$this->checked('nas-connection-stats.php')??(object)['contract'=>self::CONTRACT,'success_seconds'=>[],'successful_connections'=>0];
                $stats->successful_connections++;$stats->success_seconds=array_slice(array_merge($stats->success_seconds,[round($seconds,6)]),-60);$this->save('nas-connection-stats.php',$stats);}
        }else{
            $s->failures++;$s->consecutive_failures++;$s->timeouts+=(int)in_array($o['code'],['HTTP_TRANSPORT_28','HTTP_408'],true);
            if($s->state==='retry_wait'){$p=self::policy();$delay=max($p['retry_delays_seconds'][min(4,$s->consecutive_failures-1)],self::retryAfter(mon_get($r,'headers',[]),(float)($this->wall)()));$s->retry_at=(float)($this->wall)()+$delay;}
        }
        $s->observation=null;
        if($s->connected&&isset($r['body']))try{
            $body=mon_decode($r['body']);$health=mon_get($body,'nas_state');
            if($health instanceof stdClass)$s->observation=(object)['source'=>self::ENDPOINT,'observed_at'=>MonBudget::iso((float)($this->wall)()),
                'body_sha256'=>hash('sha256',$r['body']),'processor_version'=>$s->processor_version,'health'=>$health];
        }catch(Throwable $e){}
        $this->save($this->name,$s);
    }
    public function health(): array {
        $this->locked(function(){ $s=$this->state();$at=mon_get(mon_get($s,'observation'),'observed_at');$age=$at===null?PHP_INT_MAX:(float)($this->wall)()-MonRun::seconds($at);
            if(in_array($s->state,['connected','connected_legacy'],true)&&$age>=self::policy()['healthy_refresh_seconds']){$s->state='pending';$this->save($this->name,$s);}return $this->view($s);});
        return $this->poll();
    }
    public function poll(): array {
        return $this->locked(function(){
            $s=$this->state();$this->checkCancel();
            // A killed read-only GET has an unknown outcome. Preserve its count; recover with backoff.
            if($s->pending_attempt!==null)$this->settle($s,['error_code'=>'NAS_REQUEST_UNCONFIRMED'],null);
            if(in_array($s->state,['connected','connected_legacy','blocked','interrupted'],true)||$s->retry_at>(float)($this->wall)())return $this->view($s);
            if(!$this->transport&&!extension_loaded('curl')&&self::curlExecutable()===null){$s->state='blocked';$s->last_code='NAS_CLIENT_TRANSPORT_REQUIRED';$this->save($this->name,$s);return $this->view($s);}
            $plan=$this->view($s);$s->attempts++;$s->last_connect_seconds=$plan['next_connect_seconds'];$s->last_request_seconds=$plan['next_request_seconds'];
            $s->pending_attempt=(object)['attempt'=>$s->attempts,'started_at'=>mon_iso((int)($this->wall)())];$s->state='connecting';$this->save($this->name,$s);
            $start=(float)($this->mono)();$stop=null;
            try{$r=$this->transport?($this->transport)('GET',self::ENDPOINT,['Accept: application/json'],$s->last_connect_seconds,$s->last_request_seconds):$this->request($s->last_connect_seconds,$s->last_request_seconds);
                if(!is_array($r))throw new MonFault('NAS_TRANSPORT_RESULT_INVALID');$this->checkCancel();
            }catch(MonStop $e){$stop=$e;$r=['error_code'=>'INTERRUPTED'];}
            catch(MonFault $e){$r=['error_code'=>$e->faultCode,'headers'=>['retry-after'=>(string)$e->retryAfter]];}
            catch(Throwable $e){$r=['error_code'=>'NAS_CLIENT_INTERNAL_ERROR'];}
            $seconds=max(0,(float)($this->mono)()-$start);$this->settle($s,$r,$seconds);
            if(MonExecution::$active){MonExecution::measure('external/nas',$seconds,$s->last_code,1,'server',$s->attempts>1);MonExecution::event('nas_connection',['code'=>$s->last_code??($s->compatible?'NAS_CONNECTED':'NAS_CONNECTED_LEGACY')]);}
            if($stop!==null)throw $stop;
            return $this->view($s);
        });
    }
    private static function curlExecutable(): ?string {
        if(PHP_SAPI!=='cli'||!function_exists('proc_open'))return null;
        foreach(['/usr/bin/curl','/bin/curl','/usr/local/bin/curl']as $path)if(is_executable($path))return $path;
        return null;
    }
    private function request(int $connect,int $total): array {
        if(!extension_loaded('curl'))return $this->commandRequest($connect,$total);
        $headers=[];$body='';$tooLarge=false;$ch=curl_init(self::ENDPOINT);
        curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>$connect,CURLOPT_TIMEOUT=>$total,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'mon.php/'.MON_VERSION,
            CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){if(preg_match('/^HTTP\//',$line))$headers=[];$p=strpos($line,':');if($p!==false)$headers[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$body,&$tooLarge){if(strlen($body)+strlen($chunk)>262144){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);},
            CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>function(){try{$this->checkCancel();return 0;}catch(MonStop $e){return 1;}}]);
        try{$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);$this->checkCancel();
            if($ok===false)throw new MonFault($tooLarge?'HTTP_RESPONSE_TOO_LARGE':'HTTP_TRANSPORT_'.$errno);
            return ['status'=>$status,'headers'=>$headers,'body'=>$body];
        }finally{curl_close($ch);}
    }
    private function commandRequest(int $connect,int $total): array {
        // Native PHP clients without the curl extension use the fixed system curl. No shell, keys or daemon.
        $exe=self::curlExecutable();if($exe===null)throw new MonFault('NAS_CLIENT_TRANSPORT_REQUIRED');$paths=[];$proc=null;$pipes=[];
        try{
            for($i=0;$i<3;$i++){$p=tempnam(sys_get_temp_dir(),'mon_nas_');if($p===false)throw new MonFault('NAS_CLIENT_TEMP_FAILED');chmod($p,0600);$paths[]=$p;}
            $args=[$exe,'--silent','--show-error','--request','GET','--proto','=https','--connect-timeout',(string)$connect,'--max-time',(string)$total,
                '--max-filesize','262144','--header','Accept: application/json','--output',$paths[0],'--dump-header',$paths[1],'--write-out','%{http_code}',self::ENDPOINT];
            $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$paths[2],'w']],$pipes,null,null,['bypass_shell'=>true]);
            if(!is_resource($proc))throw new MonFault('NAS_CLIENT_EXEC_FAILED');fclose($pipes[0]);unset($pipes[0]);stream_set_blocking($pipes[1],false);
            $begin=mon_monotonic();$statusText='';$exit=-1;
            do{$this->checkCancel();$info=proc_get_status($proc);$statusText.=stream_get_contents($pipes[1],16);
                if(!$info['running']){$exit=$info['exitcode'];break;}
                if(mon_monotonic()-$begin>$total+2){proc_terminate($proc);throw new MonFault('HTTP_TRANSPORT_28');}
                usleep(100000);
            }while(true);
            $statusText.=stream_get_contents($pipes[1],16);fclose($pipes[1]);unset($pipes[1]);$closed=proc_close($proc);$proc=null;if($exit<0)$exit=$closed;
            if($exit!==0)throw new MonFault(in_array($exit,[23,63],true)?'HTTP_RESPONSE_TOO_LARGE':'HTTP_TRANSPORT_'.$exit);
            if(filesize($paths[0])>262144||filesize($paths[1])>32768)throw new MonFault('HTTP_RESPONSE_TOO_LARGE');
            $headers=[];foreach(explode("\n",(string)file_get_contents($paths[1]))as $line){if(preg_match('/^HTTP\//',$line))$headers=[];$p=strpos($line,':');if($p!==false)$headers[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));}
            return ['status'=>(int)$statusText,'headers'=>$headers,'body'=>(string)file_get_contents($paths[0])];
        }finally{if(is_resource($proc)){proc_terminate($proc);foreach($pipes as $p)if(is_resource($p))fclose($p);proc_close($proc);}foreach($paths as $p)@unlink($p);}
    }
}

final class MonData {
    public static function validate($d): void {
        if (!($d instanceof stdClass) || mon_get($d, 'schema_version') !== 3) throw new MonFault('DATA_SCHEMA_INVALID');
        foreach (['watchlist', 'collection', 'analysis'] as $k) if (!(mon_get($d, $k) instanceof stdClass) || mon_get($d->$k, 'schema_version') !== 3) throw new MonFault('DATA_OBJECT_INVALID');
        $w = $d->watchlist;
        $required = ['watchlist'=>['criteria_version','watchlist_version','updated_at','run_id','settings','calendar','symbols'], 'collection'=>['collection_id','watchlist_version','started_at','completed_at','status','next_cursor','quotes'], 'analysis'=>['criteria_version','run_id','as_of','analyzed_at','watchlist_version','collection_id','status','selection_fingerprint','input_fingerprint','result_fingerprint','coverage','independent_results','results','candidate_audit','candidate_history','changes','evidence']];
        foreach ($required as $k=>$fields) foreach ($fields as $field) if (!property_exists($d->$k,$field)) throw new MonFault('DATA_FIELD_MISSING');
        if (!in_array($d->analysis->criteria_version,['MON-P2.0','MON-P3.0','MON-P3.1'],true) || !($w->calendar instanceof stdClass)) throw new MonFault('DATA_CRITERIA_INVALID');
        if (mon_get($w, 'criteria_version') !== $d->analysis->criteria_version || !is_int(mon_get($w, 'watchlist_version')) || $w->watchlist_version < 0 || !(mon_get($w, 'settings') instanceof stdClass) || !is_bool(mon_get($w->settings, 'enabled')) || !is_array(mon_get($w, 'symbols'))) throw new MonFault('WATCHLIST_INVALID');
        if (!is_array(mon_get($d->collection, 'quotes'))) throw new MonFault('COLLECTION_INVALID');
        if (in_array($d->analysis->criteria_version,['MON-P3.0','MON-P3.1'],true)) {
            if (mon_get($w->settings,'investment_style') !== 'swing' || MonRun::json(mon_get($w->settings,'swing_profile')) !== MonRun::json(MonSwing::rules($d->analysis->criteria_version))) throw new MonFault('MON_SWING_PROFILE_INVALID');
            foreach (mon_get($d->analysis,'results',[]) as $r) if (mon_get($r,'action') === 'BUY_REVIEW' && !MonSwing::active($r,$d->analysis->criteria_version)) throw new MonFault('MON_SWING_RECOMMENDATION_INVALID');
        }
        $ids = [];
        foreach ($w->symbols as $s) {
            foreach (['name','purpose','candidate_origin','is_held','position','tick_size','source_codes'] as $field) if (!property_exists($s,$field)) throw new MonFault('SYMBOL_FIELD_MISSING');
            if (!is_bool($s->is_held) || !is_array($s->candidate_origin)) throw new MonFault('SYMBOL_TYPE_INVALID');
            foreach (['symbol_id', 'country', 'exchange', 'symbol', 'currency'] as $k) if (!is_string(mon_get($s, $k)) || $s->$k === '') throw new MonFault('SYMBOL_INVALID');
            if (!in_array($s->country, ['KR', 'US', 'JP'], true) || $s->symbol_id !== $s->country . '|' . $s->exchange . '|' . $s->symbol || isset($ids[$s->symbol_id]) || !(mon_get($s, 'source_codes') instanceof stdClass)) throw new MonFault('SYMBOL_ID_INVALID');
            if ($s->currency !== ['KR'=>'KRW','US'=>'USD','JP'=>'JPY'][$s->country]) throw new MonFault('SYMBOL_CURRENCY_INVALID');
            $ids[$s->symbol_id] = true;
        }
    }
    public static function settings($w): array {
        $defaults = ['request_poll_seconds'=>10, 'detail_budget_seconds'=>25, 'detail_symbol_budget_seconds'=>8, 'detail_jobs_per_tick'=>3, 'detail_publish_batch_size'=>3, 'detail_publish_max_delay_seconds'=>3, 'poll_seconds'=>60, 'mirror_seconds'=>180, 'force_mirror_before_analysis_seconds'=>60, 'collection_budget_seconds'=>45, 'tick_budget_seconds'=>55, 'publish_reserve_seconds'=>10, 'http_timeout_seconds'=>5, 'github_http_timeout_seconds'=>5, 'github_write_timeout_seconds'=>12, 'request_spacing_seconds'=>1.25, 'github_conflict_retries'=>2, 'auxiliary_max_age_seconds'=>900, 'action_price_max_age_seconds'=>300, 'future_clock_tolerance_seconds'=>5];
        foreach ($defaults as $k => $v) {
            $n = mon_number(mon_get($w->settings, $k, $v));
            if ($n === null || $n < 0) throw new MonFault('SETTINGS_INVALID');
            $defaults[$k] = $n;
        }
        $defaults['poll_seconds'] = max(10, $defaults['poll_seconds']);
        $defaults['request_poll_seconds'] = min(60, max(5, $defaults['request_poll_seconds']));
        $defaults['detail_budget_seconds'] = min(25, max(1, $defaults['detail_budget_seconds']));
        $defaults['detail_symbol_budget_seconds'] = min($defaults['detail_budget_seconds'], max(1, $defaults['detail_symbol_budget_seconds']));
        $defaults['detail_jobs_per_tick'] = min(6, max(1, (int)$defaults['detail_jobs_per_tick']));
        $defaults['detail_publish_batch_size'] = min(6, max(1, (int)$defaults['detail_publish_batch_size']));
        $defaults['detail_publish_max_delay_seconds'] = min(10, max(0, $defaults['detail_publish_max_delay_seconds']));
        $defaults['http_timeout_seconds'] = min(5, max(0.1, $defaults['http_timeout_seconds']));
        $defaults['github_http_timeout_seconds'] = min(8, max(2, $defaults['github_http_timeout_seconds']));
        // GitHub can need longer to ingest a 1.5MiB source file than to read it.
        // This is an individual HTTP cap, NOT a longer daemon tick or run deadline.
        $defaults['github_write_timeout_seconds'] = min(12, max(3, $defaults['github_write_timeout_seconds']));
        $defaults['request_spacing_seconds'] = max(1.25, $defaults['request_spacing_seconds']);
        $defaults['tick_budget_seconds'] = min(55, max(1, $defaults['tick_budget_seconds']));
        $defaults['publish_reserve_seconds'] = min(10, max(1, $defaults['publish_reserve_seconds']));
        $defaults['collection_budget_seconds'] = min(45, max(0, $defaults['collection_budget_seconds']));
        $defaults['github_conflict_retries'] = min(2, (int)$defaults['github_conflict_retries']);
        return $defaults;
    }
    public static function signature($w): string {
        $s = [];
        foreach ($w->symbols as $row) $s[$row->symbol_id] = ['country'=>$row->country, 'exchange'=>$row->exchange, 'symbol'=>$row->symbol, 'currency'=>$row->currency, 'source_codes'=>$row->source_codes];
        ksort($s, SORT_STRING);
        return hash('sha256', mon_json(['version'=>$w->watchlist_version, 'enabled'=>$w->settings->enabled, 'symbols'=>$s]));
    }
    public static function quality($p, int $now, array $settings): string {
        if (!$p) return 'pending';
        $at = mon_time(mon_get($p, 'quote_at'));
        $delay = mon_number(mon_get($p, 'delay_seconds'));
        if ($at === null || $delay === null || $delay < 0 || mon_get($p, 'timestamp_basis') === 'unknown') return 'unknown';
        if ($at - $now > $settings['future_clock_tolerance_seconds']) return 'conflict';
        if ($now - $at > $settings['auxiliary_max_age_seconds']) return 'stale';
        return ($delay > 0 || $now - $at > $settings['action_price_max_age_seconds']) ? 'delayed' : 'normal';
    }
}

final class MonMarket {
    public static function timezone(string $country): DateTimeZone { return new DateTimeZone(['KR'=>'Asia/Seoul','US'=>'America/New_York','JP'=>'Asia/Tokyo'][$country]); }
    public static function state($w, string $country, int $now): array {
        $tz = self::timezone($country); $dt = (new DateTimeImmutable('@' . $now))->setTimezone($tz); $date = $dt->format('Y-m-d');
        $cal = mon_get($w, 'calendar'); $until = mon_time(mon_get($cal, 'valid_until'));
        if ($until !== null && $until >= $now && is_array(mon_get($cal, 'markets'))) foreach ($cal->markets as $m) {
            $checked = mon_time(mon_get($m, 'checked_at'));
            if (mon_get($m, 'country') !== $country || mon_get($m, 'timezone') !== $tz->getName() || $checked === null || $checked > $now || $until - $checked > 15 * 86400 || !is_array(mon_get($m, 'sessions'))) continue;
            $today = []; $invalid = false;
            foreach ($m->sessions as $s) {
                if (mon_get($s, 'market_date') !== $date) continue;
                $open = mon_time(mon_get($s, 'open_at')); $close = mon_time(mon_get($s, 'close_at'));
                if ($open === null || $close === null || $open >= $close) { $invalid = true; break; }
                $today[] = [$open, $close];
            }
            if ($invalid) break;
            usort($today, static function ($a,$b) { return $a[0] <=> $b[0]; });
            foreach ($today as $s) if ($now >= $s[0] && $now < $s[1]) return ['session'=>'regular','collect'=>true,'date'=>$date];
            if (count($today) > 1 && $now >= $today[0][1] && $now < $today[count($today)-1][0]) return ['session'=>'break','collect'=>false,'date'=>$date];
            return ['session'=>'closed','collect'=>false,'date'=>$date];
        }
        // A standard-time window is only a collection window, never a verified session.
        $weekday = (int)$dt->format('N'); $hhmm = $dt->format('Hi');
        $windows = ['KR'=>[['0900','1530']], 'US'=>[['0930','1600']], 'JP'=>[['0900','1130'],['1230','1530']]][$country];
        $inside = false; foreach ($windows as $v) if ($weekday <= 5 && $hhmm >= $v[0] && $hhmm < $v[1]) $inside = true;
        return ['session'=>'unknown','collect'=>$inside,'date'=>$date];
    }
}

final class MonGitHub {
    private $http;
    private $config;
    private $url;
    public function __construct(MonHttp $http, MonConfig $config) {
        $this->http=$http; $this->config=$config;
        $repo=$config->get('MON_REPOSITORY','wskimgit/stock'); $branch=$config->get('MON_BRANCH','main');
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/',$repo) || !preg_match('/^[A-Za-z0-9_\/. -]+$/',$branch)) throw new MonFault('REPOSITORY_CONFIG_INVALID');
        $this->url='https://api.github.com/repos/'.$repo.'/contents/mon_data.json';
    }
    private function headers(bool $writing=false,bool $raw=false): array {
        $token=$this->config->get('GITHUB_TOKEN'); if ($writing && $token==='') throw new MonFault('GITHUB_TOKEN_MISSING');
        $headers=['Accept: '.($raw?'application/vnd.github.raw+json':'application/vnd.github+json'),'Content-Type: application/json','X-GitHub-Api-Version: 2022-11-28'];
        if($token!=='')$headers[]='Authorization: Bearer '.$token;
        return $headers;
    }
    public function read(float $deadline, float $timeout=3,?array $known=null): array {
        $url=$this->url.'?'.http_build_query(['ref'=>$this->config->get('MON_BRANCH','main')]);
        // The Contents API omits base64 content above 1 MiB. Raw media returns
        // the same file bytes at every supported size, without an extra request.
        $headers=$this->headers(false,true);$etag=$known['etag']??null;
        $conditional=is_string($etag)&&preg_match('/^(W\/)?"[^"\r\n]{1,180}"$/',$etag)&&isset($known['sha'],$known['data']);
        if($conditional)$headers[]='If-None-Match: '.$etag;
        $r=$this->http->request('GET',$url,$headers,null,$deadline,$timeout);
        if($r['status']===304){if(!$conditional)throw new MonFault('GITHUB_UNEXPECTED_NOT_MODIFIED');$known['unchanged']=true;return $known;}
        if ($r['status']<200 || $r['status']>=300) MonHttp::decode($r);
        $raw=$r['body'];
        if (!is_string($raw) || strlen($raw)>MonRun::MAX_SOURCE) throw new MonFault('DATA_SIZE_INVALID');
        try { $data=mon_decode($raw); } catch (Throwable $e) { throw new MonFault('DATA_JSON_INVALID'); }
        // The Git blob hash uses the exact downloaded bytes, including whitespace.
        // PUT still uses this SHA for conflict detection and preserves MON-owned data.
        MonData::validate($data);$etag=mon_get($r['headers'],'etag');
        if(!is_string($etag)||!preg_match('/^(W\/)?"[^"\r\n]{1,180}"$/',$etag))$etag=null;
        return ['sha'=>MonRun::gitSha($raw),'data'=>$data,'etag'=>$etag,'unchanged'=>false];
    }
    public function publish($batch, string $signature, float $deadline, array $settings, ?array $knownLatest = null): array {
        $headers=$this->headers(true); // Fail before any network work if writes are not configured.
        $proposal=$batch;
        for ($attempt=0; $attempt <= $settings['github_conflict_retries']; $attempt++) {
            // Reuse a verified exact blob SHA for the first optimistic PUT. A conflict
            // still requires a fresh read and re-merge of the ORIGINAL proposal.
            $latest=$attempt===0&&isset($knownLatest['sha'],$knownLatest['data'])?$knownLatest:$this->read($deadline,$settings['github_http_timeout_seconds']);
            $latest['data']=mon_decode(mon_json($latest['data'],false));$batch=$proposal;
            if (!$latest['data']->watchlist->settings->enabled || MonData::signature($latest['data']->watchlist)!==$signature) throw new MonFault('WATCHLIST_CHANGED');
            $before=mon_json($latest['data']->collection,false);
            // Each request may change without changing the quote watchlist version.
            // Merge only matching responses; preserve concurrent newer responses.
            $details=MonDetails::mergedResponses($latest['data'],$batch);
            if(mon_get($batch,'_detail_only')===true){
                $quotes=mon_get($batch,'quote_collection');$current=$latest['data']->collection;
                if($quotes instanceof stdClass&&mon_get($quotes,'watchlist_version')===$latest['data']->watchlist->watchlist_version&&(mon_time(mon_get($quotes,'completed_at'))??0)>=(mon_time(mon_get($current,'completed_at'))??0))$current=$quotes;
                $batch=mon_decode(mon_json($current));$batch->detail_responses=$details;
            }
            else{$batch=mon_decode(mon_json($batch));$batch->detail_responses=$details;}
            if(mon_json($batch,false)===$before)return ['sha'=>$latest['sha'],'data'=>$latest['data'],'written'=>false];
            $latest['data']->collection=$batch; // Preserve MON-owned instructions and decisions.
            $raw=mon_json($latest['data'],false); if (strlen($raw)>MonRun::MAX_SOURCE) throw new MonFault('DATA_SIZE_EXCEEDED');
            $body=mon_json(['message'=>'mon: mirror collection '.$batch->collection_id,'content'=>base64_encode($raw),'sha'=>$latest['sha'],'branch'=>$this->config->get('MON_BRANCH','main')]);
            try {
                $r=$this->http->request('PUT',$this->url,$headers,$body,$deadline,$settings['github_write_timeout_seconds']);
            } catch (MonFault $e) {
                // An ACK can be lost after GitHub has already committed the blob.
                // Do not issue a second PUT until the remote SHA has been observed.
                if(in_array($e->faultCode,['HTTP_TRANSPORT_28','HTTP_LATE_RESPONSE','HTTP_TRANSPORT_52','HTTP_TRANSPORT_56'],true)
                   && $this->http->remaining($deadline)>=0.1){
                    try {
                        $confirmed=$this->read($deadline,$settings['github_http_timeout_seconds']);
                        if(hash_equals(MonRun::gitSha($raw),$confirmed['sha']))
                            return ['sha'=>$confirmed['sha'],'data'=>$confirmed['data'],'written'=>true,'recovered_ack'=>true];
                    } catch (MonFault $confirmationError) {
                        // Preserve local pending.json/detail_pending.json for next tick.
                    }
                }
                throw $e;
            }
            if (in_array($r['status'],[409,422],true) && $attempt < $settings['github_conflict_retries']) continue;
            $saved=MonHttp::decode($r);
            if (!is_string(mon_get(mon_get($saved,'content'),'sha'))) throw new MonFault('GITHUB_WRITE_UNCONFIRMED');
            return ['sha'=>$saved->content->sha,'data'=>$latest['data'],'written'=>true];
        }
        throw new MonFault('GITHUB_CONFLICT');
    }
}

final class MonCollector {
    public $attempted = 0;
    public $succeeded = 0;
    private $http;
    private $store;
    private $config;
    private $control;
    private $clock;
    private $providerCooldown=[];
    private $tokenCache=null;
    public function __construct(MonHttp $http, MonStore $store, MonConfig $config, ?MonControl $control=null, ?callable $clock=null) {
        $this->http=$http; $this->store=$store; $this->config=$config; $this->control=$control; $this->clock=$clock ?: static function(){return time();};
    }
    private function now(): int { return (int)($this->clock)(); }
    public function configure(MonConfig $config): void { $this->config=$config; }
    public function token(float $deadline, array $settings): string {
        $key=$this->config->get('KIS_APP_KEY'); $secret=$this->config->get('KIS_APP_SECRET');
        if ($key==='' || $secret==='') throw new MonFault('KIS_CREDENTIALS_MISSING');
        $signature=hash('sha256',$key."\0".$secret); $cached=$this->tokenCache;
        if (mon_get($cached,'signature')===$signature && (int)mon_get($cached,'expires_at',0)>$this->now()+300 && is_string(mon_get($cached,'access_token'))) return $cached->access_token;
        $r=MonHttp::decode($this->http->request('POST','https://openapi.koreainvestment.com:9443/oauth2/tokenP',['Content-Type: application/json'],mon_json(['grant_type'=>'client_credentials','appkey'=>$key,'appsecret'=>$secret]),$deadline,$settings['http_timeout_seconds'],$settings['request_spacing_seconds']));
        $token=mon_get($r,'access_token'); $seconds=mon_number(mon_get($r,'expires_in'));
        if (!is_string($token) || $token==='' || $seconds===null || $seconds<=300) throw new MonFault('KIS_TOKEN_INVALID',60);
        $expires=$this->now()+(int)$seconds;
        $providerExpiry=mon_get($r,'access_token_token_expired');
        if (is_string($providerExpiry)) {
            $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$providerExpiry,new DateTimeZone('Asia/Seoul'));
            if ($dt!==false && $dt->format('Y-m-d H:i:s')===$providerExpiry) $expires=min($expires,$dt->getTimestamp());
        }
        $this->tokenCache=(object)['signature'=>$signature,'access_token'=>$token,'expires_at'=>$expires]; return $token;
    }
    private function point($s,string $source,string $providerSymbol,float $price,$volume,?int $at,?string $date,string $session,string $type,string $basis,?float $delay,bool $verified): stdClass {
        return (object)['price'=>$price,'change_pct'=>null,'volume'=>$volume,'volume_basis'=>$type==='minute_close'?'minute':'unknown','currency'=>$s->currency,'venue'=>$s->exchange,'source'=>$source,'provider_symbol'=>$providerSymbol,'price_type'=>$type,'timestamp_basis'=>$basis,'quote_at'=>$at===null?null:mon_iso($at),'fetched_at'=>mon_iso($this->now()),'market_date'=>$date,'session'=>$session,'delay_kind'=>$delay===null?'unknown':($delay>0?'delayed':'realtime'),'delay_seconds'=>$delay,'bar_time_basis_verified'=>$verified];
    }
    public function kis($s,$w,float $deadline,array $settings): array {
        $map=mon_get($s->source_codes,'kis'); if (!($map instanceof stdClass)) throw new MonFault('KIS_MAPPING_MISSING');
        $symbol=mon_get($map,'symbol'); if (!is_string($symbol)||!preg_match('/^[A-Za-z0-9._-]+$/',$symbol)) throw new MonFault('KIS_MAPPING_INVALID');
        $token=$this->token($deadline,$settings); $tz=MonMarket::timezone($s->country); $now=$this->now(); $market=MonMarket::state($w,$s->country,$now);
        if ($s->country==='KR') {
            $code=mon_get($map,'market_code','J'); if ($code!=='J') throw new MonFault('KIS_VENUE_UNSUPPORTED');
            $path='/uapi/domestic-stock/v1/quotations/inquire-time-itemchartprice'; $tr='FHKST03010200';
            $q=['FID_COND_MRKT_DIV_CODE'=>$code,'FID_INPUT_ISCD'=>$symbol,'FID_INPUT_HOUR_1'=>(new DateTimeImmutable('@'.$now))->setTimezone($tz)->format('His'),'FID_PW_DATA_INCU_YN'=>'Y','FID_ETC_CLS_CODE'=>''];
        } else {
            $exchange=mon_get($map,'exchange_code',mon_get($map,'excd'));
            if (!is_string($exchange)) $exchange=['NASDAQ'=>'NAS','NAS'=>'NAS','NYSE'=>'NYS','NYS'=>'NYS','AMEX'=>'AMS','AMS'=>'AMS','TSE'=>'TSE','TYO'=>'TSE','JPX'=>'TSE'][$s->exchange] ?? null;
            if (!in_array($exchange,$s->country==='JP'?['TSE']:['NAS','NYS','AMS'],true)) throw new MonFault('KIS_EXCHANGE_INVALID');
            $path='/uapi/overseas-price/v1/quotations/inquire-time-itemchartprice'; $tr='HHDFS76950200';
            $q=['AUTH'=>'','EXCD'=>$exchange,'SYMB'=>$symbol,'NMIN'=>'1','PINC'=>'0','NEXT'=>'','NREC'=>'3','FILL'=>'','KEYB'=>''];
        }
        $headers=['Content-Type: application/json','authorization: Bearer '.$token,'appkey: '.$this->config->get('KIS_APP_KEY'),'appsecret: '.$this->config->get('KIS_APP_SECRET'),'tr_id: '.$tr,'custtype: P'];
        $r=MonHttp::decode($this->http->request('GET','https://openapi.koreainvestment.com:9443'.$path.'?'.http_build_query($q),$headers,null,$deadline,$settings['http_timeout_seconds'],$settings['request_spacing_seconds']));
        if ((string)mon_get($r,'rt_cd','')!=='0') {
            $code=(string)mon_get($r,'msg_cd','ERROR');
            if (in_array($code,['EGW00121','EGW00123'],true)) $this->tokenCache=null;
            throw new MonFault('KIS_'.$code,$code==='EGW00201'?60:30);
        }
        $rows=mon_get($r,'output2'); if (!is_array($rows)) throw new MonFault('KIS_ROWS_INVALID');
        $timeBasis=$this->config->get('KIS_BAR_TIME_BASIS_'.$s->country,'unknown');
        if (!in_array($timeBasis,['start','end','unknown'],true)) throw new MonFault('KIS_BAR_TIME_BASIS_INVALID');
        $delay=mon_number($this->config->get('KIS_DELAY_SECONDS_'.$s->country,'')); if ($delay!==null && $delay<0) throw new MonFault('KIS_DELAY_INVALID');
        $points=[];
        foreach ($rows as $row) {
            $ymd=(string)mon_get($row,$s->country==='KR'?'stck_bsop_date':'xymd',''); $hms=(string)mon_get($row,$s->country==='KR'?'stck_cntg_hour':'xhms','');
            if (!preg_match('/^\d{8}$/',$ymd)||!preg_match('/^\d{6}$/',$hms)) continue;
            $dt=DateTimeImmutable::createFromFormat('!YmdHis',$ymd.$hms,$tz);
            if ($dt===false||$dt->format('YmdHis')!==$ymd.$hms||$dt->format('Y-m-d')!==$market['date']) continue;
            $rawAt=$dt->getTimestamp();
            // With unknown semantics, conservatively exclude the newest possible unfinished bar.
            $end=$rawAt+($timeBasis==='end'?0:60); if ($end>$now) continue;
            $price=mon_number(mon_get($row,$s->country==='KR'?'stck_prpr':'last')); if ($price===null||$price<=0) continue;
            $volume=mon_number(mon_get($row,$s->country==='KR'?'cntg_vol':'evol')); if ($volume!==null&&$volume<0) $volume=null;
            $p=$this->point($s,'KIS',$symbol,$price,$volume,$timeBasis==='unknown'?null:$end,$dt->format('Y-m-d'),$market['session'],'minute_close',$timeBasis==='unknown'?'unknown':'bar_end',$delay,$timeBasis!=='unknown');
            $p->provider_bar_at=$dt->format(DateTimeInterface::ATOM); $points[$rawAt]=$p;
        }
        krsort($points,SORT_NUMERIC); if (!$points) throw new MonFault('KIS_NO_VALID_COMPLETED_BAR'); return array_slice(array_values($points),0,2);
    }
    public function naver($s,$w,float $deadline,array $settings): array {
        if ($s->country!=='KR') throw new MonFault('NAVER_COUNTRY_UNSUPPORTED');
        $map=mon_get($s->source_codes,'naver'); $symbol=is_string($map)?$map:mon_get($map,'symbol');
        if (!is_string($symbol)||!preg_match('/^\d{6}$/',$symbol)) throw new MonFault('NAVER_MAPPING_MISSING');
        $r=MonHttp::decode($this->http->request('GET','https://m.stock.naver.com/api/stock/'.$symbol.'/basic',['Accept: application/json'],null,$deadline,$settings['http_timeout_seconds'],$settings['request_spacing_seconds']));
        if ((string)mon_get($r,'itemCode')!==$symbol) throw new MonFault('NAVER_ID_MISMATCH');
        $price=mon_number(mon_get($r,'closePrice')); if ($price===null||$price<=0) throw new MonFault('NAVER_PRICE_INVALID');
        $at=mon_time(mon_get($r,'localTradedAt')); $market=MonMarket::state($w,$s->country,$this->now());
        $date=$at===null?null:(new DateTimeImmutable('@'.$at))->setTimezone(MonMarket::timezone('KR'))->format('Y-m-d');
        $providerExchange=mon_get(mon_get($r,'stockExchangeType'),'code');
        $expectedExchange=['KOSPI'=>['KS','KOSPI'],'KOSDAQ'=>['KQ','KOSDAQ'],'KRX'=>['KS','KQ','KOSPI','KOSDAQ']][$s->exchange]??[];
        if (!$expectedExchange || !in_array($providerExchange,$expectedExchange,true) || mon_get(mon_get($r,'stockExchangeType'),'zoneId')!=='Asia/Seoul') throw new MonFault('NAVER_VENUE_MISMATCH');
        // Listing-market metadata does not prove that an extended-session price is a KRX regular price.
        $quoteLocal=$at===null?null:(new DateTimeImmutable('@'.$at))->setTimezone(MonMarket::timezone('KR'));
        $quoteInRegular=$quoteLocal!==null && (int)$quoteLocal->format('N')<=5 && $quoteLocal->format('His')>='090000' && $quoteLocal->format('His')<='153000';
        $quoteMarket=$at===null?null:MonMarket::state($w,'KR',$at);
        $providerStatus=mon_get($r,'marketStatus'); $session='unknown';
        if ($providerStatus==='OPEN' && $market['collect'] && $quoteInRegular && $quoteMarket['collect'] && $date===$market['date']) $session='regular';
        elseif ($providerStatus==='CLOSE' && $quoteInRegular) $session='closed';
        $delay=mon_number($this->config->get('NAVER_DELAY_SECONDS_KR',''));
        if ($delay!==null && $delay<0) throw new MonFault('NAVER_DELAY_INVALID');
        $p=$this->point($s,'NAVER',$symbol,$price,null,$at,$date,$session,$session==='closed'?'close':'last',$at===null?'unknown':($session==='closed'?'close':'trade'),$delay,false);
        $p->change_pct=mon_number(mon_get($r,'fluctuationsRatio'));
        $p->provider_venue=$providerExchange;$p->venue_basis='listing_market';$p->provider_session=mon_get($r,'marketSessionType');
        return [$p];
    }
    public function yahoo($s,$w,float $deadline,array $settings): array {
        $map=mon_get($s->source_codes,'yahoo'); $symbol=is_string($map)?$map:mon_get($map,'symbol');
        if (!is_string($symbol)||!preg_match('/^[A-Za-z0-9._-]+$/',$symbol)) throw new MonFault('YAHOO_MAPPING_MISSING');
        $url='https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol).'?'.http_build_query(['interval'=>'1m','range'=>'1d']);
        $r=MonHttp::decode($this->http->request('GET',$url,['Accept: application/json'],null,$deadline,$settings['http_timeout_seconds'],$settings['request_spacing_seconds']));
        $chart=mon_get($r,'chart'); $rows=mon_get($chart,'result');
        if (mon_get($chart,'error')!==null||!is_array($rows)||!isset($rows[0])) throw new MonFault('YAHOO_RESPONSE_INVALID');
        $meta=mon_get($rows[0],'meta');
        if (strtoupper((string)mon_get($meta,'symbol'))!==strtoupper($symbol)||mon_get($meta,'currency')!==$s->currency) throw new MonFault('YAHOO_ID_OR_CURRENCY_MISMATCH');
        $venues=['KOSPI'=>['KSC'],'KOSDAQ'=>['KOE'],'NASDAQ'=>['NMS','NGM','NCM'],'NYSE'=>['NYQ'],'AMEX'=>['ASE'],'TSE'=>['JPX']];
        if ($s->exchange==='KRX') $expected=substr($symbol,-3)==='.KS'?['KSC']:(substr($symbol,-3)==='.KQ'?['KOE']:[]);
        else $expected=$venues[$s->exchange]??[];
        $providerVenue=mon_get($meta,'exchangeName');
        if (!$expected || !in_array($providerVenue,$expected,true) || mon_get($meta,'exchangeTimezoneName')!==MonMarket::timezone($s->country)->getName()) throw new MonFault('YAHOO_VENUE_MISMATCH');
        $price=mon_number(mon_get($meta,'regularMarketPrice')); $rawAt=mon_get($meta,'regularMarketTime');
        if ($price===null||$price<=0||!is_int($rawAt)||$rawAt<=0) throw new MonFault('YAHOO_QUOTE_INVALID');
        $tz=MonMarket::timezone($s->country); $date=(new DateTimeImmutable('@'.$rawAt))->setTimezone($tz)->format('Y-m-d');
        $market=MonMarket::state($w,$s->country,$this->now()); $session=$market['session'];
        $regular=mon_get(mon_get($meta,'currentTradingPeriod'),'regular'); $start=mon_get($regular,'start'); $end=mon_get($regular,'end');
        if (is_int($start)&&is_int($end)&&$start<$end) {
            $open=$this->now()>=$start&&$this->now()<$end;
            $quoteInSession=$rawAt>=$start&&$rawAt<$end;
            $session=$open?($quoteInSession?'regular':'unknown'):'closed';
        } elseif ($session==='regular') $session='unknown';
        // Zero has an unambiguous meaning. Positive undocumented units are not guessed.
        $providerDelay=mon_number(mon_get($meta,'exchangeDataDelayedBy'));
        $delay=$providerDelay===0.0?0.0:mon_number($this->config->get('YAHOO_DELAY_SECONDS_'.$s->country,''));
        $delayBasis=$providerDelay===0.0?'provider_zero_field':($delay===null?'unknown':'configured_seconds');
        if ($delay===null) {
            $policies=mon_get($w->settings,'quote_delay_policies');
            $policy=mon_get(mon_get($policies,'YAHOO'),$s->country.'|'.$s->exchange);
            $known=mon_number(mon_get($policy,'delay_seconds'));$verified=mon_get($policy,'verified_at');$url=mon_get($policy,'source_url');
            $verifiedAt=mon_time($verified);
            $host=is_string($url)?parse_url($url,PHP_URL_HOST):null;
            $policyVenues=mon_get($policy,'provider_venues',[mon_get($policy,'provider_venue')]);
            if ($known!==null&&$known>=0&&$verifiedAt!==null&&$verifiedAt<=$this->now()+$settings['future_clock_tolerance_seconds']&&$this->now()-$verifiedAt<=1209600&&is_string($host)&&preg_match('/(^|\.)help\.yahoo\.com$/',$host)&&is_array($policyVenues)&&in_array($providerVenue,$policyVenues,true)) {
                $delay=$known;$delayBasis='verified_exchange_policy';
            }
        }
        if ($delay!==null&&$delay<0) throw new MonFault('YAHOO_DELAY_INVALID');
        $p=$this->point($s,'YAHOO',$symbol,$price,null,$rawAt,$date,$session,'last','trade',$delay,false);
        $p->provider_venue=$providerVenue;$p->delay_basis=$delayBasis;
        if ($delayBasis==='verified_exchange_policy') {$p->delay_policy_url=$url;$p->delay_policy_verified_at=$verified;}
        return [$p];
    }
    private function fetch($s,$w,float $deadline,array $settings): array {
        $sources=$s->country==='KR'?['kis','naver','yahoo']:['kis','yahoo']; $best=null; $bestScore=-1; $lastError='NO_SOURCE_AVAILABLE';
        foreach ($sources as $source) {
            if (($this->providerCooldown[$source]??0)>$this->now()) { $lastError=strtoupper($source).'_COOLDOWN'; continue; }
            try {
                $points=$this->$source($s,$w,$deadline,$settings); $p=$points[0];
                $at=mon_time($p->quote_at); $now=$this->now();
                if ($p->currency!==$s->currency||$p->venue!==$s->exchange||($at!==null&&$at>$now+$settings['future_clock_tolerance_seconds'])) throw new MonFault('QUOTE_ID_OR_TIME_INVALID');
                if ($at!==null) {
                    $date=(new DateTimeImmutable('@'.$at))->setTimezone(MonMarket::timezone($s->country))->format('Y-m-d');
                    $today=(new DateTimeImmutable('@'.$now))->setTimezone(MonMarket::timezone($s->country))->format('Y-m-d');
                    if ($p->market_date!==$date||$date!==$today) throw new MonFault('QUOTE_MARKET_DATE_INVALID');
                }
                $quality=MonData::quality($p,$now,$settings); $score=['normal'=>4,'delayed'=>3,'unknown'=>2,'stale'=>1,'conflict'=>0][$quality]??0;
                if ($score>$bestScore) { $best=$points; $bestScore=$score; }
                if ($quality==='normal'||($quality==='delayed'&&$at!==null&&$now-$at<=300&&$p->delay_seconds!==null&&$p->delay_seconds<=300)) return $points;
            } catch (MonFault $e) {
                $lastError=$e->faultCode;
                if ($e->faultCode==='BUDGET_EXHAUSTED') { if ($best!==null) return $best; throw $e; }
                if ($e->retryAfter>0||in_array($e->faultCode,['HTTP_429','HTTP_401','HTTP_403','KIS_CREDENTIALS_MISSING'],true)) $this->providerCooldown[$source]=$this->now()+max(30,$e->retryAfter);
            }
        }
        if ($best!==null) return $best; throw new MonFault($lastError);
    }
    public function collect($data, $previous, float $deadline, array $settings): stdClass {
        $this->attempted=0;$this->succeeded=0;
        $w=$data->watchlist; $symbols=$w->symbols; $count=count($symbols); $now=$this->now();
        $seed=[]; $versionMatches=mon_get($previous,'watchlist_version')===$w->watchlist_version;
        if ($versionMatches && is_array(mon_get($previous,'quotes'))) foreach ($previous->quotes as $r) $seed[mon_get($r,'symbol_id','')]=$r;
        $quotes=[]; foreach ($symbols as $s) {
            $r=isset($seed[$s->symbol_id])?mon_decode(mon_json($seed[$s->symbol_id])):(object)['symbol_id'=>$s->symbol_id,'fetch_status'=>'pending','attempted_at'=>null,'quality_status'=>'pending','error'=>null,'point'=>null,'previous_point'=>null];
            $r->quality_status=MonData::quality($r->point,$now,$settings);
            $quotes[$s->symbol_id]=$r;
        }
        $cursor=$versionMatches?(int)mon_get($previous,'next_cursor',0):0; if ($count>0) $cursor=(($cursor%$count)+$count)%$count;
        $visited=0;
        $attemptLimit=(int)($settings['quote_symbols_per_tick']??PHP_INT_MAX);
        while ($visited<$count && $this->attempted<$attemptLimit && microtime(true)<$deadline) {
            if ($this->control) { try { $this->control->check(); } catch (MonStop $e) { break; } }
            $i=($cursor+$visited)%$count; $s=$symbols[$i]; $r=$quotes[$s->symbol_id];
            if (mon_get($s,'example_only')===true||preg_match('/(^|[|_])SIM([_|]|$)/',$s->symbol_id)) {
                $r->fetch_status='pending'; $r->quality_status='pending'; $r->error=(object)['code'=>'EXAMPLE_SKIPPED','message'=>'설명용 종목은 수집하지 않음','source'=>null]; $visited++; continue;
            }
            $market=MonMarket::state($w,$s->country,$now);
            if (!$market['collect']) { $r->quality_status=MonData::quality($r->point,$now,$settings); $visited++; continue; }
            $r->attempted_at=mon_iso($this->now());
            $this->attempted++;
            try {
                $symbolDeadline=isset($settings['quote_symbol_budget_seconds'])?min($deadline,microtime(true)+$settings['quote_symbol_budget_seconds']):$deadline;
                $points=$this->fetch($s,$w,$symbolDeadline,$settings); $new=$points[0]; $older=$points[1]??null;
                $newAt=mon_time($new->quote_at); $oldAt=mon_time(mon_get($r->point,'quote_at'));
                if ($older===null&&$newAt!==null&&$oldAt!==null&&$oldAt<$newAt) $older=$r->point;
                if ($older===null&&$newAt!==null&&$oldAt===$newAt) $older=$r->previous_point;
                $r->point=$new; $r->previous_point=$older; $r->fetch_status='ok'; $r->error=null;
                $r->quality_status=MonData::quality($new,$this->now(),$settings);
                $this->succeeded++;
            } catch (MonStop $e) { break; }
            catch (MonFault $e) {
                if ($e->faultCode==='BUDGET_EXHAUSTED'&&!isset($settings['quote_symbol_budget_seconds'])) break;
                $r->fetch_status='error'; $r->quality_status=$r->point?MonData::quality($r->point,$this->now(),$settings):'failed';
                $r->error=(object)['code'=>$e->faultCode,'message'=>'이번 시세 조회 실패; 마지막 확인 가격은 원래 시각으로 보존','source'=>null];
            }
            $visited++;
        }
        $allGood=$visited===$count;
        foreach ($quotes as $r) if ($r->fetch_status!=='ok'||!in_array($r->quality_status,['normal','delayed'],true)) $allGood=false;
        return (object)['schema_version'=>3,'collection_id'=>'mon-'.gmdate('Ymd-His',$now).'-'.bin2hex(random_bytes(4)),'watchlist_version'=>$w->watchlist_version,'started_at'=>mon_iso($now),'completed_at'=>mon_iso($this->now()),'status'=>$allGood?'complete':'partial','next_cursor'=>$count>0?($cursor+$visited)%$count:0,'quotes'=>array_values($quotes)];
    }
}

final class MonDaemon {
    private $store;
    private $control;
    private $envFile;
    private $lock;
    private $http;
    private $collector;
    private $credentialSignature='';
    private $ticks=0;
    private $started;
    private $lastPublished=0;
    private $lastQuoteTick=0.0;
    private $detailCounts=[];
    private $stageSeconds=[];
    private $failures=0;
    private $poll=60.0;
    private $lastError=null;
    private $activity='starting';
    private $lastAttempted=0;
    private $lastSucceeded=0;
    private $launchTicket;private $bootId;private $load;private $resources=[];private $lastHeartbeat=0.0;
    private $heartbeatState='';private $remoteLatest=null;private $remoteScope='';private $lastCacheSha=null;
    public function __construct(MonStore $store,string $envFile,string $instance,string $launchTicket='') {
        $this->store=$store; $this->envFile=$envFile; $this->control=new MonControl($store,$instance); $this->started=mon_iso();
        $this->http=new MonHttp($this->control);$this->launchTicket=$launchTicket;$this->load=new MonDaemonLoad();
    }
    private function heartbeat(string $state,?string $error=null): void {
        if($state!=='waiting')$this->activity=$state;
        if ($error!==null) $this->lastError=$error;
        elseif (in_array($state,['paused','empty_watchlist','mirrored','collected','market_closed','detail_ready','detail_retry','detail_collecting','resource_paused','stopped'],true)) $this->lastError=null;
        $now=mon_monotonic();$signature=$state.'|'.$this->activity.'|'.$this->lastError;
        if($state==='waiting'&&$signature===$this->heartbeatState&&$now-$this->lastHeartbeat<MonDaemonLoad::HEARTBEAT_SECONDS)return;
        $this->store->write('status.json',(object)['version'=>MON_VERSION,'boot_id'=>$this->bootId,'start_policy'=>'manual_web_only','instance_id'=>$this->control->instance,'pid'=>getmypid(),'started_at'=>$this->started,'heartbeat_at'=>mon_iso(),'state'=>$state,'activity_state'=>$this->activity,'ticks'=>$this->ticks,'last_published_at'=>$this->lastPublished?mon_iso($this->lastPublished):null,'last_attempted'=>$this->lastAttempted,'last_succeeded'=>$this->lastSucceeded,'detail_counts'=>$this->detailCounts,'stage_seconds'=>$this->stageSeconds,'resources'=>$this->resources,'error_code'=>$this->lastError]);
        $this->lastHeartbeat=$now;$this->heartbeatState=$signature;
    }
    private function cacheRemote($data,MonConfig $cfg,?string $sha=null): void {
        if($sha!==null&&$this->lastCacheSha===$sha)return;
        $local=clone $data;
        $local->_mon_cache=(object)['repository'=>$cfg->get('MON_REPOSITORY','wskimgit/stock'),'branch'=>$cfg->get('MON_BRANCH','main'),'fetched_at'=>mon_iso(),'blob_sha'=>$sha];
        $this->store->write('remote_cache.json',$local);
        $this->lastCacheSha=$sha;
    }
    private function readRemote(MonGitHub $gh,MonConfig $cfg,float $deadline): array {
        $scope=hash('sha256',$cfg->get('MON_REPOSITORY','wskimgit/stock').'|'.$cfg->get('MON_BRANCH','main').'|'.$cfg->get('GITHUB_TOKEN'));
        if($scope!==$this->remoteScope){$this->remoteLatest=null;$this->lastCacheSha=null;$this->remoteScope=$scope;}
        if($cfg->get('GITHUB_TOKEN')===''){
            try{
                $cached=$this->store->read('remote_cache.json');$meta=mon_get($cached,'_mon_cache');$at=mon_time(mon_get($meta,'fetched_at'));
                if($at!==null && $at<=time()+5 && time()-$at<300 && mon_get($meta,'repository')===$cfg->get('MON_REPOSITORY','wskimgit/stock') && mon_get($meta,'branch')===$cfg->get('MON_BRANCH','main')){
                    MonData::validate($cached);unset($cached->_mon_cache);
                    return ['sha'=>null,'data'=>$cached,'cached'=>true];
                }
            }catch(MonFault $e){/* An invalid local cache is replaced only by a verified remote read. */}
        }
        $latest=$gh->read($deadline,3,$this->remoteLatest);$latest['cached']=false;$this->remoteLatest=$latest;return $latest;
    }
    public static function forceMirror($w,int $now,array $settings): bool {
        $slots=mon_get($w->settings,'analysis_slots',[]); if (!is_array($slots)) return false;
        foreach ($slots as $slot) {
            try { $tz=new DateTimeZone((string)mon_get($slot,'timezone')); } catch (Throwable $e) { continue; }
            $hm=mon_get($slot,'time'); if (!is_string($hm)||!preg_match('/^\d\d:\d\d$/',$hm)) continue;
            $day=(new DateTimeImmutable('@'.$now))->setTimezone($tz); if ((int)$day->format('N')>5) continue;
            $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$day->format('Y-m-d').' '.$hm,$tz);
            if ($at && $at->getTimestamp()-$now>=0 && $at->getTimestamp()-$now<=$settings['force_mirror_before_analysis_seconds']) return true;
        }
        return false;
    }
    public static function detailWorkDue($data,int $now): bool {
        foreach(MonDetails::requests($data)as $sid=>$request){
            try{MonDetails::validRequest($request,$sid);}catch(MonFault $e){continue;}
            $response=mon_get(mon_get($data->collection,'detail_responses'),$sid);
            if(MonDetails::workDue($data,$request,$response,$now))return true;
        }return false;
    }
    private function tick(): void {
        $tickMono=mon_monotonic();$this->stageSeconds=[];$cfg=new MonConfig($this->envFile,$this->store->dir);
        $deadline=static function(float $offset)use($tickMono){return microtime(true)+max(0,$offset-(mon_monotonic()-$tickMono));};
        $this->poll=$cfg->int('MON_IDLE_POLL_SECONDS',60,30,3600);$this->lastAttempted=0;$this->lastSucceeded=0;
        $this->resources=$this->load->sample();$publication=new MonPublication($this->store,$this->http,$cfg);
        if($this->resources['state']==='deferred'){
            // Hard pressure must not start a large publication; protected jobs remain resumable.
            $this->poll=$this->resources['wait_seconds'];$this->heartbeat('resource_paused');return;
        }
        $published=$publication->processNext($deadline($this->resources['state']==='throttled'?4:8));
        $this->stageSeconds['final_publication']=round(mon_monotonic()-$tickMono,6);$sourceStart=mon_monotonic();
        if($published&&in_array($published['save_status'],['queued','publishing'],true))$this->poll=min($this->poll,5);
        $gh=new MonGitHub($this->http,$cfg);$latest=$this->readRemote($gh,$cfg,$deadline(18));$knownLatest=$latest;
        $data=clone $latest['data'];$data->collection=mon_decode(mon_json($data->collection,false));$w=$data->watchlist;$settings=MonDaemonLoad::settings(MonData::settings($w),$this->resources);
        $this->stageSeconds['source_read']=round(mon_monotonic()-$sourceStart,6);
        $this->poll=$settings['request_poll_seconds'];if(!$latest['cached'])$this->cacheRemote($data,$cfg,$latest['sha']);
        if($published&&in_array($published['save_status'],['queued','publishing'],true))$this->poll=min($this->poll,5);
        if($cfg->get('GITHUB_TOKEN')===''){$this->poll=300;$this->heartbeat('needs_setup',$cfg->githubTokenError());return;}
        if(!$w->settings->enabled){$this->heartbeat('paused');return;}
        // MON-CHATGPT-BRIDGE-1.0: receive an explicit, authenticated-by-GitHub
        // ChatGPT candidate/decision handoff. Never start a daemon or place orders.
        if(class_exists('MonChatGPTBridge'))try {
            $next=MonChatGPTBridge::poll($this->store,$this->http,$cfg,$gh,$latest,
                $deadline(max(18,$settings['tick_budget_seconds']-16)));
            if(isset($next['sha'],$next['data'])&&$next['sha']!==$latest['sha']){
                $latest=$next;$knownLatest=$next;$data=clone $next['data'];
                $data->collection=mon_decode(mon_json($data->collection,false));
                $w=$data->watchlist;$this->cacheRemote($data,$cfg,$next['sha']);
            }
        }catch(MonFault $e){$this->store->log('chatgpt_bridge_pending',['code'=>$e->faultCode]);}
        $signature=MonData::signature($w);$pending=$this->store->read('pending.json');
        $previous=mon_get($pending,'signature')===$signature?mon_get($pending,'collection'):$data->collection;
        $cred=hash('sha256',$cfg->get('KIS_APP_KEY')."\0".$cfg->get('KIS_APP_SECRET'));
        if(!$this->collector||$cred!==$this->credentialSignature){$this->collector=new MonCollector($this->http,$this->store,$cfg,$this->control);$this->credentialSignature=$cred;}
        else $this->collector->configure($cfg);
        $tickDeadline=$deadline($settings['tick_budget_seconds']);$this->http->rememberDeadline($tickDeadline);$detailPending=$this->store->read('detail_pending.json');
        $queued=mon_get($detailPending,'collection',new stdClass());$merged=MonDetails::mergedResponses($data,$queued);
        $data->collection->detail_responses=$merged;
        // Jobs run during market closure too. One failure cannot block another symbol.
        $daily=new MonDaily($this->http,$this->collector,$cfg,$this->store);$worker=new MonDetailWorker($daily,$this->store);
        $hasPending=MonRun::json($merged)!==MonRun::json(mon_get($latest['data']->collection,'detail_responses',new stdClass()));
        $detailState=null;$publishError=null;$unflushed=0;$lastFlush=mon_monotonic();
        $firstResponse=MonProgress::build($latest['data'],time())['counts']['done']===0;
        $checkpoint=function($current)use(&$pending,$signature,&$previous){
            $batch=(object)['_detail_only'=>true,'detail_responses'=>mon_get($current->collection,'detail_responses',new stdClass())];
            if(mon_get($pending,'signature')===$signature&&mon_get($pending,'dirty')===true)$batch->quote_collection=$previous;
            $this->store->write('detail_pending.json',(object)['collection'=>$batch]);
            return $batch;
        };
        $flush=function($current)use($gh,$cfg,$signature,$tickDeadline,$settings,$checkpoint,&$knownLatest,&$pending,&$previous,&$publishError,&$unflushed,&$lastFlush){
            if($publishError!==null)return $current;$batch=$checkpoint($current);$at=mon_monotonic();
            try{$saved=$gh->publish($batch,$signature,$tickDeadline,$settings,$knownLatest);$knownLatest=$saved;
                $next=clone $saved['data'];$next->collection=mon_decode(mon_json($next->collection,false));
                if($saved['written'])$this->lastPublished=time();$this->cacheRemote($next,$cfg,$saved['sha']);@unlink($this->store->path('detail_pending.json'));
                MonDetailStep::published($this->store,$next);
                if(property_exists($batch,'quote_collection')){@unlink($this->store->path('pending.json'));$pending=null;$previous=$next->collection;}
                $unflushed=0;$lastFlush=mon_monotonic();return $next;
            }catch(MonFault $e){$publishError=$e->faultCode;return $current;}
            finally{$this->stageSeconds['detail_publish']=round(($this->stageSeconds['detail_publish']??0)+mon_monotonic()-$at,6);}
        };
        if($hasPending)$data=$flush($data);
        $publishBefore=$this->stageSeconds['detail_publish']??0;
        $work=$settings['detail_jobs_per_tick']>0&&self::detailWorkDue($data,time())?$worker->processAvailable($data,microtime(true)+max(0,min($settings['detail_budget_seconds'],$this->http->remaining($tickDeadline)-$settings['publish_reserve_seconds'])),$settings,
            function($current,$job)use($checkpoint,$flush,$settings,&$detailState,&$unflushed,&$lastFlush,&$firstResponse){
                $checkpoint($current);MonDetailStep::mark($this->store,mon_get(MonDetails::requests($current),$job['processed_symbol_id']),'checkpoint_saved','',mon_get($job['response'],'error'));
                $unflushed++;$this->detailCounts=MonProgress::build($current,time())['counts'];$this->heartbeat('detail_collecting');
                $detailState=MonDetails::retryPending($job['response'])?'detail_retry':'detail_ready';
                $due=$firstResponse||$unflushed>=$settings['detail_publish_batch_size']||mon_monotonic()-$lastFlush>=$settings['detail_publish_max_delay_seconds'];$firstResponse=false;
                return $due?$flush($current):$current;
            }):['data'=>$data,'handler_seconds'=>0];
        $data=$work['data'];$this->stageSeconds['detail_work']=round(max(0,$work['handler_seconds']-(($this->stageSeconds['detail_publish']??0)-$publishBefore)),6);
        if($unflushed>0)$data=$flush($data);$w=$data->watchlist;
        $this->detailCounts=MonProgress::build($data,time())['counts'];
        // Return only data actually published and verified by MonGitHub::publish.
        if(class_exists('MonChatGPTBridge')&&isset($knownLatest['sha'],$knownLatest['data']))
            try{MonChatGPTBridge::export($this->store,$this->http,$cfg,$knownLatest['data'],
                $knownLatest['sha'],min($tickDeadline,microtime(true)+8));}
            catch(MonFault $e){$this->store->log('chatgpt_bridge_pending',['code'=>$e->faultCode]);}
        if($this->detailCounts['pending']>0)$this->poll=min($this->poll,5);
        if($publishError!==null){$this->heartbeat('detail_publish_pending',$publishError);return;}
        // The faster job poll must not turn the 60-second quote cadence into 10 seconds.
        if(mon_monotonic()-$this->lastQuoteTick<$settings['poll_seconds']||$this->http->remaining($tickDeadline)<=$settings['publish_reserve_seconds']){
            $this->heartbeat($detailState?:'market_closed');return;
        }
        $this->lastQuoteTick=mon_monotonic();
        if(count($w->symbols)===0){$this->heartbeat($detailState?:'empty_watchlist');return;}
        $collectDeadline=microtime(true)+max(0,min($settings['collection_budget_seconds'],$this->http->remaining($tickDeadline)-$settings['publish_reserve_seconds']));
        $collectStart=mon_monotonic();$batch=$this->collector->collect($data,$previous,$collectDeadline,$settings);
        $this->stageSeconds['quotes']=round(mon_monotonic()-$collectStart,6);
        $batch->detail_responses=MonDetails::mergedResponses($data,$previous);
        $this->lastAttempted=$this->collector->attempted;$this->lastSucceeded=$this->collector->succeeded;
        $dirty=$this->collector->attempted>0||(mon_get($pending,'signature')===$signature&&mon_get($pending,'dirty',false));
        if($this->lastAttempted>0)$this->store->write('pending.json',(object)['signature'=>$signature,'collection'=>$batch,'dirty'=>$dirty]);
        $due=($dirty&&time()-$this->lastPublished>=$settings['mirror_seconds'])||self::forceMirror($w,time(),$settings);
        if($due){try{$at=mon_monotonic();$saved=$gh->publish($batch,$signature,$tickDeadline,$settings,$knownLatest);if($saved['written'])$this->lastPublished=time();$this->cacheRemote($saved['data'],$cfg,$saved['sha']);$this->stageSeconds['quote_publish']=round(mon_monotonic()-$at,6);@unlink($this->store->path('pending.json'));}
            catch(MonFault $e){if($e->faultCode==='WATCHLIST_CHANGED'){@unlink($this->store->path('pending.json'));$this->heartbeat('watchlist_changed',$e->faultCode);return;}throw $e;}}
        if($this->lastAttempted>0&&$this->lastSucceeded===0)$this->heartbeat('quote_failed','QUOTE_FETCH_FAILED');
        else $this->heartbeat($detailState?:($this->lastAttempted===0?'market_closed':($due?'mirrored':'collected')));
    }
    public function run(int $maxTicks=0): int {
        $this->lock=@fopen($this->store->path('daemon.lock'),'c+');
        if (!$this->lock||!flock($this->lock,LOCK_EX|LOCK_NB)) { if ($this->lock) fclose($this->lock); throw new MonFault('ALREADY_RUNNING'); }
        try{$this->bootId=(new MonManualStart($this->store))->consume($this->control->instance,$this->launchTicket);$this->launchTicket='';$this->load->apply();}
        catch(Throwable $e){flock($this->lock,LOCK_UN);fclose($this->lock);throw $e;}
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true); $ctl=$this->control;
            pcntl_signal(SIGTERM,static function() use($ctl){$ctl->stop=true;}); pcntl_signal(SIGINT,static function() use($ctl){$ctl->stop=true;});
        }
        $exit=0; $this->heartbeat('starting'); $this->store->log('daemon_started');
        try {
            while (!$maxTicks || $this->ticks<$maxTicks) {
                $this->control->check(); $start=mon_monotonic();$cpuStart=MonDaemonLoad::cpuSeconds(); $stamp=mon_settings_stamp($this->store,$this->envFile);$this->ticks++;
                try { $this->tick(); $this->failures=0; }
                catch (MonStop $e) { throw $e; }
                catch (MonFault $e) {
                    $this->failures++; $exit=1; $this->store->log('tick_error',['code'=>$e->faultCode]); $this->heartbeat('retrying',$e->faultCode);
                    $this->poll=max($this->poll,min(300,5*(2**min($this->failures-1,6))),$e->retryAfter);
                } catch (Throwable $e) {
                    $this->failures++; $exit=1; $this->store->log('tick_error',['code'=>'INTERNAL_ERROR']); $this->heartbeat('retrying','INTERNAL_ERROR'); $this->poll=max($this->poll,30);
                }
                if ($maxTicks && $this->ticks>=$maxTicks) break;
                if(function_exists('gc_collect_cycles'))gc_collect_cycles();$cpuEnd=MonDaemonLoad::cpuSeconds();
                $remaining=MonDaemonLoad::waitSeconds($this->poll,mon_monotonic()-$start,$cpuStart!==null&&$cpuEnd!==null?$cpuEnd-$cpuStart:null);
                $settingsCheck=mon_monotonic();
                while ($remaining>0) {
                    $slice=min(5,$remaining); $this->control->wait($slice); $this->heartbeat('waiting'); $remaining-=$slice;
                    if(mon_monotonic()-$settingsCheck>=MonDaemonLoad::HEARTBEAT_SECONDS){$settingsCheck=mon_monotonic();if(mon_settings_stamp($this->store,$this->envFile)!==$stamp)break;}
                }
            }
        } catch (MonStop $e) { $exit=0; }
        finally {
            $this->heartbeat('stopped'); $this->store->log('daemon_stopped');
            $request=$this->store->read('stop.json'); if (mon_get($request,'instance_id')===$this->control->instance) @unlink($this->store->path('stop.json'));
            flock($this->lock,LOCK_UN); fclose($this->lock);
        }
        return $exit;
    }
}

function mon_status(MonStore $store): array {
    $status=$store->read('status.json');$boot=MonManualStart::bootId();$verified=is_string($boot)&&mon_get($status,'boot_id')===$boot;
    if(!is_file($store->path('daemon.lock')))return ['running'=>false,'status'=>$status,'boot_verified'=>$verified,'start_policy'=>'manual_web_only'];
    $h=@fopen($store->path('daemon.lock'),'c+'); if (!$h) throw new MonFault('LOCK_READ_FAILED');
    $free=flock($h,LOCK_EX|LOCK_NB); if ($free) flock($h,LOCK_UN); fclose($h);
    return ['running'=>!$free,'status'=>$status,'boot_verified'=>$verified,'start_policy'=>'manual_web_only'];
}
function mon_function_available(string $name): bool {
    $disabled=array_map('trim',explode(',',(string)ini_get('disable_functions')));
    return function_exists($name) && !in_array($name,$disabled,true);
}
function mon_web_error(string $code): string {
    $messages=[
        'GITHUB_TOKEN_MISSING'=>'같은 폴더의 sis_private_sync_config.php에 GitHub 키가 있으면 자동으로 읽습니다. 키가 없으면 연결 설정에 저장하세요.',
        'PRIVATE_SYNC_CONFIG_UNREADABLE'=>'sis_private_sync_config.php를 읽지 못했습니다. 같은 web 폴더의 파일 읽기 권한을 확인하세요.',
        'PRIVATE_SYNC_CONFIG_INVALID'=>'sis_private_sync_config.php의 PHP 문법과 반환 배열을 확인하세요. 기존 파일은 수정하지 않았습니다.',
        'PRIVATE_SYNC_TOKEN_INVALID'=>'sis_private_sync_config.php의 github_token 값 형식을 확인하세요.',
        'PRIVATE_SYNC_TOKEN_MISSING'=>'sis_private_sync_config.php의 github_token이 비어 있습니다. 기존 키를 확인하거나 연결 설정에 저장하세요.',
        'BROKER_CONFIG_UNREADABLE'=>'broker_config.local.php의 읽기 권한을 확인하세요. 한국투자증권 키를 읽지 못해 보완 원천을 사용합니다.',
        'BROKER_CONFIG_INVALID'=>'broker_config.local.php의 반환 배열에 있는 앱키·앱시크릿을 확인하세요. 보완 원천을 사용합니다.',
        'BROKER_CREDENTIALS_INVALID'=>'broker_config.local.php의 앱키·앱시크릿 형식을 확인하세요. 보완 원천을 사용합니다.',
        'BROKER_CREDENTIALS_MISSING'=>'broker_config.local.php에 앱키와 앱시크릿이 모두 있어야 합니다. 보완 원천을 사용합니다.',
        'QUOTE_FETCH_FAILED'=>'이번 시세 조회가 모두 실패했습니다. 이전 가격을 현재가로 표시하지 않습니다.',
        'PHP_CURL_REQUIRED'=>'PHP의 cURL 확장이 필요합니다.',
        'WEB_PROCESS_LAUNCH_DISABLED'=>'웹 서버에서 백그라운드 실행이 차단되어 데몬을 시작하지 못했습니다.',
        'WEB_PHP_CLI_NOT_FOUND'=>'서버의 PHP 실행 환경을 찾지 못했습니다. PHP 패키지 설치 상태를 확인하세요.',
        'WEB_PHP_CLI_CURL_MISSING'=>'서버의 PHP 실행 환경에 cURL 확장이 없어 시작하지 못했습니다.',
        'DAEMON_START_NOT_CONFIRMED'=>'데몬이 시작된 것을 확인하지 못했습니다. 서버의 PHP 설정과 파일 쓰기 권한을 확인하세요.',
        'WEB_START_BUSY'=>'다른 시작 요청을 처리 중입니다. 잠시 후 상태를 확인하세요.',
        'WEB_SETTINGS_INVALID'=>'저장된 연결 설정을 읽지 못했습니다.',
        'WEB_INPUT_INVALID'=>'연결 설정에 사용할 수 없는 값이 있습니다.',
        'WEB_ACTION_INVALID'=>'지원하지 않는 요청입니다.',
        'STATE_DIRECTORY_UNWRITABLE'=>'mon.php가 있는 폴더에 상태 파일을 쓸 수 없습니다.',
        'STATE_WRITE_FAILED'=>'상태 파일을 저장하지 못했습니다.',
        'STATE_RENAME_FAILED'=>'상태 파일을 갱신하지 못했습니다.',
        'LOCK_READ_FAILED'=>'데몬 상태 파일을 열 수 없습니다.',
        'HTTP_401'=>'서비스 연결키 인증을 확인하세요.',
        'HTTP_403'=>'서비스 접근 권한 또는 호출 한도를 확인하세요.',
        'HTTP_429'=>'호출 제한으로 기다리는 중입니다. 자동으로 다시 시도합니다.',
        'DATA_SCHEMA_INVALID'=>'GitHub 데이터 규격이 맞지 않습니다.',
        'MANUAL_START_REQUIRED'=>'자동 실행은 허용하지 않습니다. 웹 시작 버튼을 눌러 실행하세요.',
        'BOOT_ID_UNAVAILABLE'=>'현재 NAS 부팅 정보를 확인하지 못해 시작을 보류했습니다.',
        'WEB_UNIX_REQUIRED'=>'현재 서버에서 백그라운드 데몬을 시작할 수 없습니다.',
        'WEB_INTERNAL_ERROR'=>'요청을 완료하지 못했습니다. 잠시 후 상태를 확인하세요.'
    ];
    return $messages[$code] ?? ('처리 확인 필요: '.$code);
}
function mon_web_descriptors(array $stdout): array {
    $spec=[0=>['file','/dev/null','r'],1=>$stdout,2=>['file','/dev/null','a']];
    // A web child must not retain the caller's listener/client sockets.
    $files=@glob('/proc/self/fd/*');
    $fds=$files===false || !$files?range(3,63):array_map(static function($p){return (int)basename($p);},$files);
    foreach ($fds as $fd) if ($fd>=3) $spec[$fd]=['file','/dev/null','r'];
    return $spec;
}
function mon_web_detach_command(string $command): string {
    // Also release common inherited descriptors in the exec-only launch path.
    return 'exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; '.$command;
}
function mon_web_probe(string $binary, MonStore $store, float $deadline): ?array {
    $code='echo json_encode(["mon_probe"=>true,"sapi"=>PHP_SAPI,"version"=>PHP_VERSION,"version_id"=>PHP_VERSION_ID,"curl"=>extension_loaded("curl")]);';
    $output='';
    $procReady=mon_function_available('proc_open') && mon_function_available('proc_get_status') && mon_function_available('proc_close') && mon_function_available('proc_terminate');
    if ($procReady) {
        $pipes=[];
        $p=@proc_open([$binary,'-r',$code],mon_web_descriptors(['pipe','w']),$pipes,$store->dir);
        if (!is_resource($p)) return null;
        stream_set_blocking($pipes[1],false);
        $end=min($deadline,microtime(true)+1.0);
        try {
            do {
                $output.=stream_get_contents($pipes[1],4096);
                $s=proc_get_status($p);
                if (!$s['running']) break;
                if (microtime(true)>=$end) { @proc_terminate($p,9); break; }
                usleep(10000);
            } while (strlen($output)<8192);
            $output.=stream_get_contents($pipes[1],4096);
        } finally { fclose($pipes[1]); @proc_close($p); }
    } elseif (mon_function_available('exec')) {
        // A short-lived background probe avoids blocking the web request.
        $path=$store->path('probe_'.bin2hex(random_bytes(6)).'.json');
        $cmd=escapeshellarg($binary).' -r '.escapeshellarg($code).' > '.escapeshellarg($path).' 2>/dev/null < /dev/null &';
        @exec($cmd);
        $end=min($deadline,microtime(true)+0.35);
        do {
            clearstatcache(true,$path);
            $output=is_file($path)?(string)@file_get_contents($path):'';
            $probe=json_decode($output,true);
            if (is_array($probe) && ($probe['mon_probe']??false)) break;
            if (is_file($path) && filesize($path)===0) { usleep(20000); } else usleep(10000);
        } while (microtime(true)<$end);
        @unlink($path);
    } else throw new MonFault('WEB_PROCESS_LAUNCH_DISABLED');
    $data=json_decode(trim($output),true);
    if (!is_array($data) || !($data['mon_probe']??false) || ($data['sapi']??'')!=='cli' || (int)($data['version_id']??0)<70400) return null;
    return ['path'=>$binary,'version'=>$data['version'],'curl'=>(bool)($data['curl']??false)];
}
function mon_web_runtime(MonStore $store, MonConfig $cfg): array {
    if (!mon_function_available('proc_open') && !mon_function_available('exec')) throw new MonFault('WEB_PROCESS_LAUNCH_DISABLED');
    $manual=trim($cfg->get('PHP_CLI'));
    $cache=$store->read('php_runtime.json');
    $paths=$manual!==''?[$manual]:array_filter([
        mon_get($cache,'path'),PHP_BINARY,'/usr/local/bin/php74','php74',
        '/var/packages/PHP7.4/target/usr/local/bin/php74',
        '/volume1/@appstore/PHP7.4/usr/local/bin/php74',
        '/usr/local/bin/php83','/usr/local/bin/php82','/usr/local/bin/php81','/usr/local/bin/php80',
        '/usr/local/bin/php','/usr/bin/php','php'
    ]);
    $deadline=microtime(true)+4.0; $missingCurl=false;
    foreach (array_unique($paths) as $path) {
        if (microtime(true)>=$deadline) break;
        if (!is_string($path) || strlen($path)>4096 || preg_match('/[\r\n\x00]/',$path)) continue;
        $runtime=mon_web_probe($path,$store,$deadline);
        if ($runtime===null) continue;
        if (!$runtime['curl']) { $missingCurl=true; continue; }
        $store->write('php_runtime.json',(object)$runtime); return $runtime;
    }
    throw new MonFault($missingCurl?'WEB_PHP_CLI_CURL_MISSING':'WEB_PHP_CLI_NOT_FOUND');
}
function mon_web_start(MonStore $store, MonConfig $cfg): string {
    $initial=mon_status($store); if ($initial['running']) return '이미 실행 중입니다.';
    if (DIRECTORY_SEPARATOR!=='/') throw new MonFault('WEB_UNIX_REQUIRED');
    $lock=@fopen($store->path('web_launch.lock'),'c+');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); throw new MonFault('WEB_START_BUSY'); }
    try {
        if (mon_status($store)['running']) return '이미 실행 중입니다.';
        $runtime=mon_web_runtime($store,$cfg);
        $instance=bin2hex(random_bytes(12)); $envFile=getenv('MON_ENV_FILE')?:'';
        $gate=new MonManualStart($store);$ticket=$gate->issue($instance);
        $command=mon_web_detach_command('nohup '.escapeshellarg($runtime['path']).' '.escapeshellarg(__FILE__).' --run --env='.escapeshellarg($envFile).' --state-dir='.escapeshellarg($store->dir).' --instance='.escapeshellarg($instance).' --launch-ticket='.escapeshellarg($ticket).' > '.escapeshellarg($store->path('launcher.log')).' 2>&1 < /dev/null &');
        if (mon_function_available('proc_open') && mon_function_available('proc_close')) {
            $pipes=[];
            $p=@proc_open(['/bin/sh','-c',$command],mon_web_descriptors(['file','/dev/null','a']),$pipes,$store->dir);
            if (!is_resource($p)) throw new MonFault('DAEMON_START_NOT_CONFIRMED');
            @proc_close($p);
        } elseif (mon_function_available('exec')) @exec($command);
        else throw new MonFault('WEB_PROCESS_LAUNCH_DISABLED');
        $end=microtime(true)+5;
        do {
            $s=mon_status($store);
            if ($s['running'] && mon_get($s['status'],'instance_id')===$instance) {
                $store->log('web_started'); return '데몬을 시작했습니다. 브라우저를 닫아도 계속 실행됩니다.';
            }
            usleep(100000);
        } while (microtime(true)<$end);
        $gate->revoke($instance);
        throw new MonFault('DAEMON_START_NOT_CONFIRMED');
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function mon_web_stop(MonStore $store): string {
    $s=mon_status($store);$id=(new MonManualStart($store))->revoke(mon_get($s['status'],'instance_id'));
    if (!$s['running']) return '데몬이 중지되어 있습니다. 대기 중인 시작 권한도 취소했습니다.';
    if (!is_string($id)) throw new MonFault('DAEMON_STARTING_RETRY_STOP');
    $end=microtime(true)+3;
    do { if (!mon_status($store)['running']) return '데몬을 중지했습니다.'; usleep(100000); } while (microtime(true)<$end);
    return '중지를 요청했습니다. 현재 처리를 마치고 종료합니다.';
}
function mon_web_save(MonStore $store, MonConfig $cfg, array $input): string {
    $saved=$cfg->webValues;
    foreach (['GITHUB_TOKEN','KIS_APP_KEY','KIS_APP_SECRET','PHP_CLI'] as $key) {
        if (!array_key_exists($key,$input)) continue;
        $value=$input[$key];
        if (!is_string($value) || strlen($value)>8192 || preg_match('/[\r\n\x00]/',$value)) throw new MonFault('WEB_INPUT_INVALID');
        $value=trim($value);
        if ($value!=='' || $key==='PHP_CLI') $saved[$key]=$value;
    }
    $store->write('settings.php',(object)$saved,MON_SETTINGS_PREFIX);
    return '연결 설정을 저장했습니다. 실행 중이면 다음 수집에 적용됩니다.';
}
function mon_web_state(MonStore $store, MonConfig $cfg): array {
    $s=mon_status($store); $status=$s['status'];
    $now=time();$at=mon_time(mon_get($status,'heartbeat_at')); $fresh=$at!==null && $at<=$now+5 && $now-$at<=30;
    $stop=$store->read('stop.json'); $stopping=$s['running'] && mon_get($stop,'instance_id')===mon_get($status,'instance_id');
    $cache=$store->read('remote_cache.json'); $pending=$store->read('pending.json');
    $collection=mon_get($pending,'collection',mon_get($cache,'collection'));
    $w=mon_get($cache,'watchlist');$symbols=mon_get($w,'symbols',[]); $quotes=mon_get($collection,'quotes',[]);
    $loaded=is_array($symbols)&&$w!==null;$enabled=mon_get(mon_get($w,'settings'),'enabled');
    $detailProgress=MonProgress::cached($cache,$store->read('detail_pending.json'));
    $freshLimit=max(30,min(90,(float)mon_get(mon_get($w,'settings'),'tick_budget_seconds',55)+10));$fresh=$at!==null&&$at<=$now+5&&$now-$at<=$freshLimit;
    if(mon_get($status,'version')===MON_VERSION&&!$s['boot_verified'])$fresh=false;
    $configured=$cfg->get('GITHUB_TOKEN')!=='';$error=mon_get($status,'error_code');$blockers=[];$markets=[];$freshQuotes=0;
    $restart=$s['running']&&is_string(mon_get($status,'version'))&&mon_get($status,'version')!==MON_VERSION;
    if(!$configured)$blockers[]=$cfg->githubTokenError();
    if($loaded&&$enabled===false)$blockers[]='COLLECTION_DISABLED';
    if($loaded&&count($symbols)===0)$blockers[]='EMPTY_WATCHLIST';
    if($restart)$blockers[]='DAEMON_RESTART_REQUIRED';
    if($s['running']&&!$fresh)$blockers[]='HEARTBEAT_STALE';
    if($s['running']&&mon_get($status,'activity_state')==='resource_paused')$blockers[]='SYSTEM_RESOURCE_PRESSURE';
    $ids=[];
    if($loaded){
        $settings=MonData::settings($w);
        foreach($symbols as $symbol){$ids[$symbol->symbol_id]=$symbol;$markets[$symbol->country]=MonMarket::state($w,$symbol->country,$now);}
        if(mon_get($collection,'watchlist_version')===mon_get($w,'watchlist_version')&&is_array($quotes))foreach($quotes as $q){
            $p=mon_get($q,'point');$id=mon_get($q,'symbol_id');$symbol=is_string($id)?($ids[$id]??null):null;$qa=mon_time(mon_get($p,'quote_at'));$delay=mon_number(mon_get($p,'delay_seconds'));$price=mon_number(mon_get($p,'price'));
            if($symbol&&$price!==null&&$price>0&&$qa!==null&&$qa<=$now+$settings['future_clock_tolerance_seconds']&&$now-$qa<=$settings['action_price_max_age_seconds']&&$delay!==null&&$delay>=0&&$delay<=$settings['action_price_max_age_seconds']&&in_array(mon_get($p,'timestamp_basis'),['trade','bar_end','provider_regularMarketTime'],true)&&mon_get($p,'session')==='regular'&&mon_get($p,'currency')===$symbol->currency&&mon_get($p,'venue')===$symbol->exchange&&mon_get($q,'fetch_status')==='ok')$freshQuotes++;
        }
    }
    $open=false;foreach($markets as $m)if($m['collect'])$open=true;
    $activity=mon_get($status,'activity_state',mon_get($status,'state','starting'));
    $readiness='stopped';$label='중지됨';$message='NAS 부팅·예기치 않은 종료 뒤에는 자동 시작하지 않습니다. 시작 버튼을 눌러 실행하세요.';
    if($s['running']){
        if($stopping){$readiness='stopping';$label='중지 중';$message='중지 요청을 처리하고 있습니다.';}
        elseif(!$fresh){$readiness='unknown';$label='응답 확인 필요';$message='프로세스 잠금은 유지되지만 최신 상태 응답을 확인하지 못했습니다.';}
        elseif($restart){$readiness='restart_required';$label='재시작 필요';$message='새 코드가 저장되었습니다. 중지 후 시작하면 v'.MON_VERSION.'이 적용됩니다.';}
        elseif(!$configured){$readiness='needs_setup';$label='설정 필요';$message=mon_web_error($cfg->githubTokenError());if($enabled===false)$message.=' 관찰목록의 수집 설정도 꺼져 있습니다.';}
        elseif($activity==='resource_paused'){$readiness='resource_paused';$recovering=mon_get(mon_get($status,'resources'),'reason')==='recovering';$label=$recovering?'회복 확인':'시스템 보호 대기';$message=$recovering?'안전한 자원 상태가 이어지는지 확인하고 있습니다. 확인 뒤 보존한 작업을 재개합니다.':'심한 CPU 포화 또는 여유 메모리 부족으로 새 수집·큰 게시를 쉬고 있습니다. 작업은 보존하며 회복 확인 뒤 이어 처리합니다.';}
        elseif($error){$readiness='error';$label=$error==='QUOTE_FETCH_FAILED'?'시세 조회 실패':'연결 확인 필요';$message=mon_web_error($error);}
        elseif($enabled===false){$readiness='paused';$label='수집 꺼짐';$message='관찰목록의 수집 설정이 꺼져 있어 대기 중입니다.';}
        elseif(!$loaded){$readiness='loading';$label='목록 확인 중';$message='GitHub 관찰목록을 확인하고 있습니다.';}
        elseif(count($symbols)===0){$readiness='empty';$label='종목 없음';$message='관찰종목 등록을 기다리고 있습니다.';}
        elseif(array_sum(array_map(static function($key)use($detailProgress){return (int)mon_get(mon_get($detailProgress,'counts'),$key,0);},['serialization_pending','blocked','conflict']))>0){$readiness='error';$label='자료 복구 필요';$message='미해결 종목은 보존하고 다른 종목 처리를 계속합니다. 상세 진행에서 복구 원인을 확인하세요.';}
        elseif(in_array($activity,['detail_ready','detail_retry','detail_collecting'],true)){$readiness='details';$label='종목별 자료 처리';$message='먼저 완료한 종목부터 표시하고 빠른 종목은 연속 처리합니다. 최종 선정은 ChatGPT가 확정합니다.';}
        elseif(!$open){$readiness='off_session';$label='장외 대기';$message='현재 수집 시간에 해당하는 시장이 없습니다. 다음 정규장에 수집합니다.';}
        elseif(in_array($activity,['collected','mirrored'],true)){
            $partial=(int)mon_get($status,'last_succeeded',0)<(int)mon_get($status,'last_attempted',0);
            $readiness=$partial?'partial':'collecting';$label=$partial?'부분 수집':'수집 중';$message='정규장 시세를 수집하고 GitHub에 반영합니다.';
        }else{$readiness='loading';$label='수집 확인 중';$message='데몬은 실행 중입니다. 첫 시세 수집 결과를 기다립니다.';}
    }
    if($s['running']&&$fresh&&mon_get(mon_get($status,'resources'),'state')==='throttled'&&in_array($readiness,['collecting','partial','details','loading'],true)){
        $readiness='throttled';$label='감속 처리';$message='NAS 부하에 맞춰 한 회차의 작업량을 줄여 수집·저장을 계속합니다. 미해결 종목은 별도로 유지합니다.';
    }
    $active=$s['running']&&$fresh&&in_array($readiness,['collecting','partial','throttled'],true)&&$freshQuotes>0;
    return [
        'version'=>MON_VERSION,'running'=>$s['running'],'stopping'=>$stopping,'heartbeat_fresh'=>$fresh,'start_policy'=>'manual_web_only','auto_start'=>false,'boot_verified'=>$s['boot_verified'],'resources'=>mon_get($status,'resources',new stdClass()),
        'readiness'=>$readiness,'monitoring_active'=>$active,'restart_required'=>$restart,'setup_blockers'=>$blockers,'detail_counts'=>mon_get($status,'detail_counts',new stdClass()),'detail_progress'=>$detailProgress,'detail_step'=>MonDetailStep::view($store,$now),'market_states'=>$markets,'watchlist_loaded'=>$loaded,'fresh_quotes'=>$freshQuotes,
        'label'=>$label,
        'message'=>$message,'status'=>$status,
        'watched'=>is_array($symbols)?count($symbols):0,'collected'=>is_array($quotes)?count(array_filter($quotes,static function($q){return mon_get($q,'point')!==null;})):0,
        'last_mirrored_at'=>mon_get($status,'last_published_at'),
        'connection'=>['github'=>$configured,'kis'=>$cfg->get('KIS_APP_KEY')!==''&&$cfg->get('KIS_APP_SECRET')!==''],
        'github_token_source'=>$cfg->githubTokenSource(),
        'kis_credentials_source'=>$cfg->kisCredentialsSource(),'kis_config_error'=>$cfg->kisConfigError(),
        'final_publication'=>MonCompletion::publicationView($store),
        'publication_operator_configured'=>MonOperator::configured($cfg)
    ];
}
function mon_web_html(array $data, string $notice, bool $ok, string $phpPath): void {
    $h=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};
    $initial=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    $nasPolicy=json_encode(MonNasConnection::policy(),JSON_THROW_ON_ERROR);
    $label=$h($data['label']); $message=$h($data['message']); $notice=$h($notice); $phpPath=$h($phpPath);
    $count=$data['watchlist_loaded']?(int)$data['watched']:'—'; $collected=(int)$data['fresh_quotes'];$version=$h(MON_VERSION);
    $startDisabled=$data['running']?' disabled':''; $stopDisabled=$data['running']?'':' disabled';
    $github=($data['github_token_source']??'')==='sis_private_sync_config.php'?'기존 설정 파일 사용':($data['connection']['github']?'설정됨':'미설정');
    $kis=($data['kis_credentials_source']??'')==='broker_config.local.php'?'기존 설정 파일 사용':($data['connection']['kis']?'설정됨':'미설정');
    $kisError=$h(!empty($data['kis_config_error'])?mon_web_error($data['kis_config_error']):'');
    $open=$data['connection']['github']?'':' open'; $tone=$ok?'ok':'bad';
    echo <<<HTML
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>mon 모니터</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f8;color:#1b2734;font-family:system-ui,-apple-system,"Malgun Gothic",sans-serif;font-size:16px;line-height:1.55}
main{max-width:680px;margin:36px auto;padding:0 18px}header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}h1{font-size:26px;margin:0}header span,.sub{color:#607080;font-size:14px}
.panel{background:#fff;border:1px solid #dce3ea;border-radius:16px;padding:24px;margin-bottom:16px}.state{display:flex;gap:10px;align-items:center;font-size:24px;font-weight:700}.dot{width:12px;height:12px;border-radius:50%;background:#8995a1}.dot.on{background:#16895a}.dot.warn{background:#b67b12}
p{margin:12px 0}.metrics{display:flex;gap:28px;padding:16px 0;border-top:1px solid #edf0f3;margin-top:20px}.metrics span{display:block;font-size:13px;color:#607080}.metrics b{font-size:19px}
.buttons{display:flex;gap:10px}.buttons form{flex:1}button{width:100%;min-height:48px;border:0;border-radius:10px;font:inherit;font-weight:600;background:#1764c0;color:white;cursor:pointer}button.stop{background:#e9edf2;color:#29394b}button:disabled{opacity:.45;cursor:default}button:focus-visible,input:focus-visible,summary:focus-visible{outline:3px solid #92c4ff;outline-offset:2px}
#notice{border-radius:10px;padding:12px;margin-bottom:16px}#notice:empty{display:none}.ok{background:#e5f4ed;color:#1b6845}.bad{background:#fff0ed;color:#8b3026}
summary{cursor:pointer;font-weight:650}label{display:block;margin:16px 0 5px;font-size:14px;font-weight:600}input{width:100%;padding:11px;border:1px solid #c9d3de;border-radius:8px;font:inherit}small{display:block;color:#607080;margin:8px 0 16px}.settings-status{font-size:14px;color:#607080}.env{margin:18px 0;font-size:14px}.env summary{font-weight:500}.foot{font-size:13px;color:#607080;margin-top:18px}.settings button{margin-top:16px}
@media(max-width:480px){main{margin-top:20px}.panel{padding:20px}.metrics{gap:20px}h1{font-size:24px}}
</style></head><body><main>
<header><h1>mon 모니터</h1><span>v$version</span></header>
<div id="notice" class="$tone" role="status" aria-live="polite">$notice</div>
<section class="panel" aria-label="데몬 실행 상태">
<div class="state"><span id="dot" class="dot"></span><span id="state">$label</span></div>
<p id="message">$message</p>
<div class="buttons">
<form class="action-form" method="post"><input type="hidden" name="action" value="start"><button id="start"$startDisabled>시작</button></form>
<form class="action-form" method="post"><input type="hidden" name="action" value="stop"><button id="stop" class="stop"$stopDisabled>중지</button></form>
</div>
<div class="metrics"><div><span>관찰종목</span><b id="count">$count</b></div><div><span>최신 가격</span><b id="collected">$collected</b></div><div><span>최근 GitHub 반영</span><b id="mirror">—</b></div></div>
<div class="sub">수집 60초 · GitHub 반영 180초 기본 주기</div>
<p id="detail-progress" class="sub" role="status" aria-live="polite">종목별 자료 상태 확인 중</p>
<p id="detail-step" class="sub" role="status" aria-live="polite">개별 처리 단계 대기</p>
<ol id="detail-steps" class="sub" aria-label="최근 개별 처리 단계"></ol>
<p id="connection-recovery" class="sub" role="status" aria-live="polite"></p>
<button id="connection-retry" type="button" hidden>연결 다시 확인</button>
<ul id="detail-cards" class="sub" aria-label="종목별 자료 진행"></ul>
<button id="detail-more" type="button" hidden>다음 종목 보기</button>
<p id="final-publication" class="sub" role="status" aria-live="polite">최종 결과 저장 요청 없음</p>
</section>
<section class="panel settings">
<details$open><summary>연결 설정</summary>
<p id="settings-status" class="settings-status">GitHub $github · 한국투자증권 $kis</p>
<p id="kis-config-error" class="settings-status">$kisError</p>
<form class="action-form" method="post" autocomplete="off">
<input type="hidden" name="action" value="save_settings">
<label for="github">GitHub 키 — 직접 입력은 선택</label><input id="github" name="GITHUB_TOKEN" type="text" spellcheck="false" placeholder="sis_private_sync_config.php의 기존 키를 자동 참조">
<label for="kis-key">한국투자증권 앱키 — 직접 입력은 선택</label><input id="kis-key" name="KIS_APP_KEY" type="text" spellcheck="false" placeholder="broker_config.local.php에서 자동 참조">
<label for="kis-secret">한국투자증권 앱시크릿 — 직접 입력은 선택</label><input id="kis-secret" name="KIS_APP_SECRET" type="text" spellcheck="false" placeholder="broker_config.local.php에서 자동 참조">
<small>같은 폴더의 sis_private_sync_config.php와 broker_config.local.php에서 기존 키를 우선 읽습니다. GitHub 키에는 stock 저장소의 Contents 쓰기 권한이 필요합니다. 빈 연결값은 기존 설정을 유지합니다.</small>
<details class="env"><summary>실행 환경 — 자동으로 찾습니다</summary>
<label for="php-path">PHP 실행 파일 위치</label><input id="php-path" name="PHP_CLI" value="$phpPath" placeholder="비워 두면 자동 찾기" spellcheck="false">
<small>자동 찾기에 실패했을 때만 설치된 PHP 실행 파일 위치를 지정합니다.</small></details>
<button>설정 저장</button>
</form></details></section>
<p class="foot">브라우저를 닫아도 시작한 데몬은 계속 동작합니다. 서버 재부팅 후에는 이 화면에서 다시 시작합니다.</p>
</main><script>
(function(){
'use strict';
var current=$initial,busy=false,detailToken='',detailCursor=null,mutationUnconfirmed=false,stepToken='',stagePending=false,lastStatusAt=Date.now();
var nasPolicy=$nasPolicy,recovery={failures:0,nextAt:0,needsAttention:false},timer=null,readJob=null;
var recoveryKey='mon-nas-recovery:'+location.pathname;
try{var saved=JSON.parse(sessionStorage.getItem(recoveryKey));
 if(saved&&Number.isSafeInteger(saved.failures)&&saved.failures>=0&&Number.isFinite(saved.nextAt)&&saved.nextAt>=0&&typeof saved.needsAttention==='boolean')recovery=saved;
}catch(ignore){}
function text(id,value){document.getElementById(id).textContent=value;}
function draw(s){
 current=s;text('state',s.label);text('message',s.message);text('count',s.watchlist_loaded?s.watched:'—');text('collected',s.fresh_quotes||0);
 var pub=s.final_publication;
 text('final-publication',pub?'결과 저장 '+({queued:'대기',publishing:'처리 중',saved:'완료',conflict:'동시 변경으로 보류',failed:'실패',expired:'이전 버전 만료 · 재개 확인',unknown:'확인 재개 대기'}[pub.save_status]||'확인 필요')+(pub.fully_complete?' · 필수 확인 완료':' · 필수 확인/복구 진행 중')+(pub.completion&&pub.completion.required_checks_pending?' · 남은 작업 '+pub.completion.required_checks_pending+'건':'')+(pub.time_excess_seconds>0?' · 기준시간 초과, 계속 처리':'')+(pub.pending_analysis?' · 새 자료는 후속 판단 필요':''):'최종 결과 저장 요청 없음');
 document.getElementById('dot').className='dot'+(['partial','throttled'].includes(s.readiness)&&s.monitoring_active?' warn':s.monitoring_active?' on':s.running&&s.heartbeat_fresh?' warn':'');
 document.getElementById('start').disabled=busy||s.running||mutationUnconfirmed;document.getElementById('stop').disabled=busy||(!s.running&&!mutationUnconfirmed);
 var stamp=s.last_mirrored_at;
 text('mirror',stamp?new Date(stamp).toLocaleTimeString('ko-KR',{timeZone:'Asia/Seoul',hour:'2-digit',minute:'2-digit'}):'—');
 text('settings-status','GitHub '+(s.github_token_source==='sis_private_sync_config.php'?'기존 설정 파일 사용':s.connection.github?'설정됨':'미설정')+' · 한국투자증권 '+(s.kis_credentials_source==='broker_config.local.php'?'기존 설정 파일 사용':s.connection.kis?'설정됨':'미설정'));
 text('kis-config-error',s.kis_config_error?({BROKER_CONFIG_UNREADABLE:'broker_config.local.php의 읽기 권한을 확인하세요. 보완 원천을 사용합니다.',BROKER_CONFIG_INVALID:'broker_config.local.php의 앱키 설정 형식을 확인하세요. 보완 원천을 사용합니다.',BROKER_CREDENTIALS_INVALID:'broker_config.local.php의 앱키·앱시크릿 형식을 확인하세요. 보완 원천을 사용합니다.',BROKER_CREDENTIALS_MISSING:'broker_config.local.php에 앱키와 앱시크릿이 모두 있어야 합니다. 보완 원천을 사용합니다.'}[s.kis_config_error]||'한국투자증권 설정을 확인하세요.'):'');
 var progress=s.detail_progress,c=progress&&progress.counts;
 drawStep(s.detail_step);stagePending=stagePending||!!(c&&(c.pending||c.retry_wait));
 text('detail-progress',c&&c.requests?'완료 '+c.done+'/'+c.requests+' · 대기 '+c.pending+' · 재시도 '+c.retry_wait+' · 이전자료 '+c.previous+(c.invalid?' · 오류 '+c.invalid:'')+(progress.publication==='pending'?' · 저장 대기':'')+' · ChatGPT 최종 판단 별도':'상세 요청 없음');
 if(progress&&progress.snapshot_token!==detailToken){
  detailToken=progress.snapshot_token;var list=document.getElementById('detail-cards');list.replaceChildren();
  detailRows(progress.cards);detailCursor=progress.page?progress.page.next_cursor:null;document.getElementById('detail-more').hidden=detailCursor===null;
 }
}
function drawStep(step){
 if(!step)return;stagePending=!!step.work_pending;
 var row=step.current;text('detail-step',row?(row.name||row.symbol_id)+' · '+row.label+(row.provider?' · '+row.provider:'')+(row.error?' · '+row.error:''):'개별 처리 단계 대기');
 if(step.snapshot_token!==stepToken){stepToken=step.snapshot_token;var list=document.getElementById('detail-steps');list.replaceChildren();
  (step.recent||[]).forEach(function(r){var item=document.createElement('li');item.textContent=(r.name||r.symbol_id)+' · '+r.label+(r.provider?' · '+r.provider:'')+(r.error?' · '+r.error:'');list.appendChild(item);});
 }
}
function detailRows(rows){var list=document.getElementById('detail-cards');
  (rows||[]).forEach(function(row){var item=document.createElement('li');
   var stage={pending:'수집 대기',retry_wait:'복구 대기',serialization_pending:'자료 포장 복구 대기',blocked:'설정·권한 확인 필요',conflict:'원문 확인 필요',deadline:'기준시간 초과 · 재개 대상',invalid:'요청 오류',done:'자료 처리 완료'}[row.state]||row.state;
   var basis={latest:'최신 완료봉',reused_current:'완료봉 재사용',previous_verified:'이전 검증자료',no_data:'자료 부족'}[row.data_status]||'';
   item.textContent=(row.name||row.symbol_id)+' · '+stage+(basis?' · '+basis:'')+(row.bars_as_of?' ('+row.bars_as_of+')':'')+(row.publication==='pending'?' · 저장 대기':'');list.appendChild(item);
  });
}

function notice(message,ok){var e=document.getElementById('notice');e.textContent=message;e.className=ok?'ok':'bad';}
function unverified(message){
 draw(Object.assign({},current,{heartbeat_fresh:false,monitoring_active:false,label:'상태 확인 필요',message:message}));notice(message,false);
}
function persistRecovery(){try{sessionStorage.setItem(recoveryKey,JSON.stringify(recovery));}catch(ignore){}}
function clearTimer(){if(timer!==null)clearTimeout(timer);timer=null;}
function connectionText(){
 var seconds=Math.max(0,Math.ceil((recovery.nextAt-Date.now())/1000));
 text('connection-recovery',recovery.needsAttention?'접속 권한·서버 응답을 확인한 뒤 연결을 다시 확인하세요.':recovery.failures?'연결 복구 '+recovery.failures+'회 · '+seconds+'초 후 다시 확인합니다.':'');
 document.getElementById('connection-retry').hidden=!recovery.failures&&!recovery.needsAttention;
}
function scheduleAt(at){
 clearTimer();recovery.nextAt=at;persistRecovery();connectionText();
 if(!document.hidden&&!busy&&!readJob&&!recovery.needsAttention)timer=setTimeout(function(){timer=null;refresh();},Math.min(2147483647,Math.max(0,at-Date.now())));
}
function waitSeconds(headers){
 var value=headers&&headers.get?headers.get('Retry-After'):null;
 if(value&&/^\d+$/.test(value.trim())&&Number.isFinite(Number(value)))return Number(value);
 var stamp=value?Date.parse(value):NaN;return Number.isFinite(stamp)?Math.max(0,(stamp-Date.now())/1000):0;
}
function healthy(){var recovered=recovery.failures>0;recovery.failures=0;recovery.needsAttention=false;persistRecovery();connectionText();if(recovered)notice('서버 연결을 다시 확인했습니다.',true);}
function readFailure(headers,needsAttention){
 recovery.failures++;recovery.needsAttention=!!needsAttention;
 var delays=nasPolicy.retry_delays_seconds,delay=Math.max(delays[Math.min(delays.length-1,recovery.failures-1)],waitSeconds(headers));
 recovery.nextAt=Date.now()+delay*1000;persistRecovery();
 unverified(needsAttention?'서버 응답이나 접속 권한을 확인해야 합니다.':'서버 연결을 확인하지 못했습니다. 대기 시간을 늘려 계속 다시 확인합니다.');connectionText();
}
function validStatus(j){return j&&j.data&&typeof j.data.running==='boolean'&&typeof j.data.heartbeat_fresh==='boolean'&&typeof j.data.label==='string'&&typeof j.data.message==='string'&&j.data.connection&&typeof j.data.connection.github==='boolean'&&typeof j.data.connection.kis==='boolean';}
function read(kind){
 if(busy||readJob||document.hidden||recovery.needsAttention)return Promise.resolve();
 if(recovery.failures&&Date.now()<recovery.nextAt){scheduleAt(recovery.nextAt);return Promise.resolve();}
 if(kind==='detail'&&detailCursor===null)return Promise.resolve();
 clearTimer();var token=detailToken,cursor=detailCursor,controller=new AbortController();
 var job={controller:controller,reason:null,promise:null};readJob=job;document.getElementById('detail-more').disabled=true;
 var timeout=setTimeout(function(){job.reason='timeout';controller.abort();},nasPolicy.request_seconds[Math.min(3,recovery.failures)]*1000);
 job.promise=(async function(){var next=nasPolicy.healthy_refresh_seconds*1000;
  try{
   var url=location.pathname+(kind==='detail'?'?view=progress&since='+encodeURIComponent(token)+'&cursor='+cursor:kind==='stage'?'?view=progress&stage=1&since='+encodeURIComponent(stepToken):'?view=status');
   var r=await fetch(url,{cache:'no-store',redirect:'error',headers:{Accept:'application/json'},signal:controller.signal});
   if(job.reason==='mutation')return;if(job.reason==='timeout')throw new Error('timeout');
   if(!r.ok){readFailure(r.headers,!(r.status===408||r.status===425||r.status===429||r.status>=500));return;}
   var j;try{j=await r.json();}catch(e){if(job.reason!=='mutation')readFailure(r.headers,job.reason!=='timeout');return;}
   if(job.reason==='mutation')return;if(job.reason==='timeout')throw new Error('timeout');
   if(kind==='detail'){
    if(j.ok&&j.progress&&j.progress.snapshot_token===token&&detailToken===token&&detailCursor===cursor){detailRows(j.progress.cards);detailCursor=j.progress.page.next_cursor;document.getElementById('detail-more').hidden=detailCursor===null;healthy();}
    else if(j.ok&&j.progress){next=0;}
    else{readFailure(r.headers,false);return;}
   }else if(kind==='stage'&&j.ok&&j.step&&j.step.contract==='MON-STEP-1.0'&&typeof j.step.work_pending==='boolean'){
    drawStep(j.step);healthy();var untilStatus=Math.max(0,nasPolicy.healthy_refresh_seconds*1000-(Date.now()-lastStatusAt));next=current.running&&stagePending?Math.min(3000,untilStatus):untilStatus;
   }
   else if(kind!=='stage'&&j.ok&&validStatus(j)){mutationUnconfirmed=false;lastStatusAt=Date.now();draw(j.data);healthy();next=current.running&&stagePending?3000:next;}
   else{readFailure(r.headers,!!j.ok||r.status===401||r.status===403);return;}
  }catch(e){if(job.reason!=='mutation')readFailure(null,false);}
  finally{clearTimeout(timeout);if(readJob===job)readJob=null;document.getElementById('detail-more').disabled=false;
   if(job.reason!=='mutation'&&!busy)scheduleAt(recovery.failures?recovery.nextAt:Date.now()+next);
  }
 })();return job.promise;
}
function refresh(){return read(current.running&&stagePending&&Date.now()-lastStatusAt<nasPolicy.healthy_refresh_seconds*1000?'stage':'status');}
document.querySelectorAll('.action-form').forEach(function(form){
 form.addEventListener('submit',async function(e){
  e.preventDefault();if(busy)return;busy=true;clearTimer();draw(current);
  var button=form.querySelector('button');button.disabled=true;
  if(readJob){readJob.reason='mutation';readJob.controller.abort();await readJob.promise;}
  var controller=new AbortController(),timeout=setTimeout(function(){controller.abort();},nasPolicy.request_seconds[Math.min(3,recovery.failures)]*1000);
  try{
   var body=new URLSearchParams(new FormData(form));
   var r=await fetch(location.pathname,{method:'POST',redirect:'error',headers:{Accept:'application/json'},body:body,signal:controller.signal});var j=await r.json();
   notice(j.message,j.ok);if(validStatus(j)){mutationUnconfirmed=false;draw(j.data);}else throw new Error('unconfirmed');
   if(r.ok&&j.ok)healthy();
   if(j.ok&&body.get('action')==='save_settings')['github','kis-key','kis-secret'].forEach(function(id){document.getElementById(id).value='';});
  }catch(error){mutationUnconfirmed=true;recovery.needsAttention=false;unverified('요청 결과를 확인하지 못했습니다. 상태를 조회해 확인합니다.');}
  finally{clearTimeout(timeout);busy=false;button.disabled=false;draw(current);scheduleAt(recovery.failures?Math.max(Date.now(),recovery.nextAt):Date.now());}
 });
});
draw(current);scheduleAt(recovery.failures||recovery.needsAttention?recovery.nextAt:Date.now()+(current.running&&stagePending?3000:nasPolicy.healthy_refresh_seconds*1000));
document.getElementById('detail-more').addEventListener('click',function(){return read('detail');});
document.getElementById('connection-retry').addEventListener('click',function(){
 if(recovery.needsAttention){recovery.needsAttention=false;recovery.nextAt=Date.now();}scheduleAt(recovery.nextAt);
});
document.addEventListener('visibilitychange',function(){
 if(document.hidden)clearTimer();else scheduleAt(recovery.nextAt);
});
})();
</script></body></html>
HTML;
}
// MON-RUN-1.1: normalized fixed-source adapters. Explicit authenticated publication is separate.
final class MonRun {
    const CONTRACT = 'MON-RUN-1.1';
    const MAX_SOURCE = 2097152;
    const MAX_BODY = 6291456;
    const MAX_PAYLOAD = 8388608;
    const VIEW_BYTES = 12288;
    const BLOCKERS = ['identity_needs_check', 'quote_time_unknown', 'current_price_needs_check', 'quote_delay_needs_check', 'quote_basis_needs_check', 'price_invalid', 'market_date_needs_check', 'regular_session_needs_check', 'outside_entry_zone', 'entry_plan_needs_data', 'plan_expired', 'latest_comparison_pending', 'corporate_risk_needs_check', 'trading_status_needs_check', 'confirmed_material_adverse', 'swing_signal_invalid', 'swing_price_confirmation_required'];
    private static $payloadCache = [];
    public static function signalMap(stdClass $signals): stdClass {
        $rows=get_object_vars($signals);ksort($rows,SORT_STRING);return (object)$rows;
    }

    public static function json($v): string {
        if ($v instanceof stdClass) {
            $keys = array_keys(get_object_vars($v)); sort($keys, SORT_STRING); $o = new stdClass();
            foreach ($keys as $k) $o->$k = self::ordered($v->$k);
            $v = $o;
        } else $v = self::ordered($v);
        $precision = ini_get('serialize_precision'); ini_set('serialize_precision', '-1');
        try { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
        finally { ini_set('serialize_precision', (string)$precision); }
    }
    private static function ordered($v) {
        if ($v instanceof stdClass) {
            $keys = array_keys(get_object_vars($v)); sort($keys, SORT_STRING); $o = new stdClass();
            foreach ($keys as $k) $o->$k = self::ordered($v->$k); return $o;
        }
        if (is_array($v)) return array_map([self::class, 'ordered'], $v);
        return $v;
    }
    public static function decimal6($v): string {
        if (!is_int($v) && !is_float($v) && !is_string($v)) throw new MonFault('MON_NUMBER_INVALID');
        if (is_float($v)) {
            if (!is_finite($v)) throw new MonFault('MON_NUMBER_INVALID');
            $precision = ini_get('serialize_precision'); ini_set('serialize_precision', '-1');
            try { $s = json_encode($v, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
            finally { ini_set('serialize_precision', (string)$precision); }
        } else $s = (string)$v;
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $s, $m)) throw new MonFault('MON_NUMBER_INVALID');
        $exp = isset($m[4]) ? (int)$m[4] : 0;
        if (abs($exp) > 324 || strlen($s) > 400) throw new MonFault('MON_NUMBER_INVALID');
        $digits = $m[2] . ($m[3] ?? ''); $cut = strlen($m[2]) + $exp + 6;
        if ($cut < 0) $out = '0';
        elseif ($cut === 0) $out = $digits[0] >= '5' ? '1' : '0';
        else {
            $out = substr($digits . str_repeat('0', max(0, $cut - strlen($digits))), 0, $cut);
            if (strlen($digits) > $cut && $digits[$cut] >= '5') {
                $carry = 1;
                for ($i = strlen($out) - 1; $i >= 0 && $carry; $i--) {
                    $n = (int)$out[$i] + 1; $out[$i] = (string)($n % 10); $carry = $n === 10 ? 1 : 0;
                }
                if ($carry) $out = '1' . $out;
            }
        }
        $out = ltrim($out, '0'); if ($out === '') $out = '0'; $out = str_pad($out, 7, '0', STR_PAD_LEFT);
        return ($m[1] === '-' && trim($out, '0') !== '' ? '-' : '') . substr($out, 0, -6) . '.' . substr($out, -6);
    }
    public static function utc($s): string {
        if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $s)) throw new MonFault('MON_TIME_INVALID');
        try {
            $d = new DateTimeImmutable($s); $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new MonFault('MON_TIME_INVALID');
            return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable $e) { throw new MonFault('MON_TIME_INVALID'); }
    }
    public static function seconds($s): float {
        $d = new DateTimeImmutable(self::utc($s)); return $d->getTimestamp() + (int)$d->format('u') / 1000000;
    }
    private static function canonical($v) {
        if (is_int($v) || is_float($v)) return self::decimal6($v);
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $v)) return self::utc($v);
        if ($v instanceof stdClass) {
            $o = new stdClass(); foreach ($v as $k => $x) $o->$k = self::canonical($x); return $o;
        }
        if (is_array($v)) return array_map([self::class, 'canonical'], $v);
        return $v;
    }
    public static function validate($d): void {
        MonData::validate($d);
        foreach (['coverage', 'independent_results', 'results', 'candidate_audit', 'candidate_history', 'changes', 'evidence'] as $field) if (!is_array(mon_get($d->analysis, $field))) throw new MonFault('MON_ROWS_INVALID');
        self::utc($d->analysis->as_of);
        foreach (['selection_fingerprint', 'input_fingerprint', 'result_fingerprint'] as $field) if (!is_string($d->analysis->$field) || !preg_match('/^[a-f0-9]{64}$/', $d->analysis->$field)) throw new MonFault('MON_FACT_HASH_INVALID');
    }
    public static function digest($v): string { return hash('sha256', self::json(self::canonical($v))); }
    public static function gitSha(string $s): string { return sha1('blob ' . strlen($s) . "\0" . $s); }
    public static function pick($v, array $fields): stdClass {
        $o = new stdClass(); foreach ($fields as $k) if (is_object($v) && property_exists($v, $k)) $o->$k = $v->$k; return $o;
    }
    public static function context($c): stdClass {
        if (!($c instanceof stdClass) || !preg_match('/^[a-f0-9]{40}$/', (string)mon_get($c, 'source_blob_sha', ''))) throw new MonFault('MON_SOURCE_SHA_INVALID');
        $o = self::pick($c, ['source_blob_sha', 'rendered_at', 'countries', 'important_ids']);
        $o->rendered_at = self::utc(mon_get($c, 'rendered_at'));
        $countries = mon_get($c, 'countries', ['KR', 'US', 'JP']);
        if (!is_array($countries) || !$countries || count($countries) !== count(array_unique($countries, SORT_REGULAR))) throw new MonFault('MON_SCOPE_INVALID');
        foreach ($countries as $country) if (!is_string($country) || !in_array($country, ['KR', 'US', 'JP'], true)) throw new MonFault('MON_SCOPE_INVALID');
        sort($countries, SORT_STRING); $o->countries = $countries;
        $ids = mon_get($c, 'important_ids', []);
        if (!is_array($ids) || count($ids) > 1000) throw new MonFault('MON_SCOPE_INVALID');
        foreach ($ids as $sid) self::identity($sid);
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING); $o->important_ids = $ids;
        if (property_exists($c, 'run_context_id')) {
            MonExecution::id($c->run_context_id); $o->run_context_id = $c->run_context_id;
        }
        return $o;
    }
    public static function identity($sid): void {
        if (!is_string($sid) || !preg_match('/^(KR|US|JP)\|[A-Z0-9._-]+\|[A-Za-z0-9._-]+$/', $sid)) throw new MonFault('MON_SYMBOL_INVALID');
    }
    public static function rows($rows): array {
        if (!is_array($rows)) throw new MonFault('MON_ROWS_INVALID'); $map = [];
        foreach ($rows as $r) {
            if (!($r instanceof stdClass)) throw new MonFault('MON_ROWS_INVALID');
            $sid = mon_get($r, 'symbol_id'); self::identity($sid);
            if (isset($map[$sid])) throw new MonFault('MON_SYMBOL_DUPLICATE'); $map[$sid] = $r;
        }
        return $map;
    }
    public static function periods($d, string $at): array {
        $now = (int)floor(self::seconds($at)); $out = [];
        foreach (mon_get($d->analysis, 'market_summary', []) as $m) {
            $country = mon_get($m, 'country'); if (!in_array($country, ['KR', 'US', 'JP'], true)) throw new MonFault('MON_PERIOD_INVALID');
            $verified = mon_get($m, 'completed_bar_date'); $target = mon_get($m, 'target_completed_bar_date', $verified);
            foreach ([$verified, $target] as $date) if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new MonFault('MON_PERIOD_INVALID');
            $state = MonMarket::state($d->watchlist, $country, $now);
            if ($state['session'] !== 'unknown') foreach (mon_get($d->watchlist->calendar, 'markets', []) as $cal) {
                if (mon_get($cal, 'country') !== $country || mon_get($cal, 'timezone') !== MonMarket::timezone($country)->getName()) continue;
                $checked = mon_time(mon_get($cal, 'checked_at')); $until = mon_time(mon_get($d->watchlist->calendar, 'valid_until'));
                if ($checked === null || $checked > $now || $until === null || $until < $now || $until - $checked > 15 * 86400) continue;
                $closes = [];
                foreach (mon_get($cal, 'sessions', []) as $s) {
                    $date = mon_get($s, 'market_date'); $open = mon_time(mon_get($s, 'open_at')); $close = mon_time(mon_get($s, 'close_at'));
                    if (!is_string($date) || $open === null || $close === null || $open >= $close) continue;
                    // A Japanese morning close is not a completed daily bar.
                    $closes[$date] = max($closes[$date] ?? 0, $close);
                }
                foreach ($closes as $date => $close) if ($close <= $now && $date > $target) $target = $date;
            }
            $pendingAnalysis=in_array($country,mon_get($d->analysis,'pending_analysis_countries',[]),true);
            $current = $verified === $target && mon_get($m, 'comparison_status') !== 'pending_benchmark' && !$pendingAnalysis;
            $out[$country] = (object)['country' => $country, 'verified_completed_date' => $verified, 'target_completed_date' => $target, 'comparison_status' => $pendingAnalysis?'pending_analysis':($current ? mon_get($m, 'comparison_status', 'needs_check') : ($verified === $target ? 'pending_benchmark' : 'preparation_required')), 'current_period' => $current, 'session' => $state['session'], 'preparation_running' => false];
        }
        return $out;
    }
    public static function token($d, $context): string {
        return hash('sha256', self::CONTRACT . '|' . MON_VERSION . '|' . self::json(self::context($context)) . '|' . self::json($d));
    }
    public static function auxiliary($d, string $at): array {
        $watch = self::rows($d->watchlist->symbols); $settings = MonData::settings($d->watchlist);
        $settings['auxiliary_max_age_seconds'] = min(900, $settings['auxiliary_max_age_seconds']);
        $settings['action_price_max_age_seconds'] = min(300, $settings['action_price_max_age_seconds']);
        $settings['future_clock_tolerance_seconds'] = min(5, $settings['future_clock_tolerance_seconds']);
        $now = (int)floor(self::seconds($at)); $counts = []; $usable = [];
        $matched = $d->collection->watchlist_version === $d->watchlist->watchlist_version;
        foreach (self::rows($d->collection->quotes) as $sid => $row) {
            $p = mon_get($row, 'point'); $quality = MonData::quality($p, $now, $settings);
            $counts[$quality] = ($counts[$quality] ?? 0) + 1;
            if (!$matched || !isset($watch[$sid]) || !in_array($quality, ['normal', 'delayed'], true) || mon_get($row, 'fetch_status') !== 'ok') continue;
            $w = $watch[$sid]; $price = mon_number(mon_get($p, 'price'));
            if (mon_get($p, 'currency') !== $w->currency || mon_get($p, 'venue') !== $w->exchange || $price === null || $price <= 0 || !in_array(mon_get($p, 'timestamp_basis'), ['trade', 'bar_end', 'close'], true)) continue;
            $source = mon_get($p, 'source'); if (!is_string($source)) continue;
            $sourceKey = ['KIS' => 'kis', 'NAVER' => 'naver', 'YAHOO' => 'yahoo'][$source] ?? null;
            if ($sourceKey === null || mon_get($p, 'provider_symbol') !== mon_get(mon_get($w->source_codes, $sourceKey), 'symbol')) continue;
            $fetched = mon_time(mon_get($p, 'fetched_at')); if ($fetched === null || $fetched > $now + 5) continue;
            $quoteAt = mon_get($p, 'quote_at'); $date = (new DateTimeImmutable($quoteAt))->setTimezone(MonMarket::timezone($w->country))->format('Y-m-d');
            if (mon_get($p, 'market_date') !== $date) continue;
            $usable[$sid] = (object)['source' => mon_get($p, 'source'), 'price' => $price, 'quote_at' => $quoteAt, 'age_seconds' => (float)self::decimal6(self::seconds($at) - self::seconds($quoteAt)), 'delay_seconds' => mon_get($p, 'delay_seconds'), 'session' => mon_get($p, 'session'), 'quality' => $quality, 'purpose' => 'auxiliary_only'];
        }
        return ['counts' => $counts, 'watchlist_version_match' => $matched, 'usable' => $usable];
    }
    public static function xzBinary(): ?string {
        if (!function_exists('proc_open')) return null;
        foreach (['/usr/bin/xz', '/bin/xz', '/usr/syno/bin/xz', '/opt/bin/xz'] as $p) if (is_file($p) && is_executable($p)) return $p;
        return null;
    }
    private static function xz(string $input, bool $decode): string {
        $binary = self::xzBinary(); if ($binary === null) throw new MonFault('MON_XZ_UNAVAILABLE');
        $in = tmpfile(); $out = tmpfile(); $err = tmpfile();
        if ($in === false || $out === false || $err === false) {
            foreach ([$in, $out, $err] as $f) if (is_resource($f)) fclose($f);
            throw new MonFault('MON_CODEC_TEMP_FAILED');
        }
        $proc = null;
        try {
            if (fwrite($in, $input) !== strlen($input)) throw new MonFault('MON_CODEC_TEMP_FAILED'); rewind($in);
            // Fixed executable/arguments; no shell or user-provided command. Small dictionary keeps memory bounded.
            $args = $decode ? [$binary, '--format=xz', '--decompress', '--stdout', '--memlimit-decompress=128MiB'] : [$binary, '--format=xz', '--stdout', '-9', '--lzma2=dict=2MiB', '--threads=1'];
            $pipes = []; $proc = @proc_open($args, [0 => $in, 1 => $out, 2 => $err], $pipes);
            if (!is_resource($proc)) throw new MonFault('MON_XZ_UNAVAILABLE');
            $end = microtime(true) + 10; $code = -1; $limit = $decode ? self::MAX_PAYLOAD : self::MAX_SOURCE;
            do {
                $stat = proc_get_status($proc); $size = fstat($out);
                if ($size !== false && $size['size'] > $limit) throw new MonFault('MON_PAYLOAD_TOO_LARGE');
                if (!$stat['running']) { $code = $stat['exitcode']; break; }
                if (microtime(true) >= $end) throw new MonFault('MON_CODEC_TIMEOUT');
                usleep(10000);
            } while (true);
            $closed = proc_close($proc); $proc = null;
            if ($code !== 0 && $closed !== 0) throw new MonFault('MON_PAYLOAD_INVALID');
            rewind($out); $result = stream_get_contents($out, $limit + 1);
            if ($result === false || strlen($result) > $limit) throw new MonFault('MON_PAYLOAD_TOO_LARGE'); return $result;
        } finally {
            if (is_resource($proc)) { proc_terminate($proc, 9); proc_close($proc); }
            fclose($in); fclose($out); fclose($err);
        }
    }
    public static function payload($d): stdClass {
        $packed = mon_get(mon_get($d->analysis, 'input_snapshot'), 'lossless_payload');
        if (!($packed instanceof stdClass) || !in_array(mon_get($packed, 'format'), ['json', 'UTF-8 JSON'], true) || !preg_match('/^[a-f0-9]{64}$/', (string)mon_get($packed, 'sha256', ''))) throw new MonFault('MON_PAYLOAD_INVALID');
        $bytes = mon_get($packed, 'uncompressed_bytes');
        if (!is_int($bytes) || $bytes < 1 || $bytes > self::MAX_PAYLOAD) throw new MonFault('MON_PAYLOAD_TOO_LARGE');
        $encoded = mon_get($packed, 'data');
        if (!is_string($encoded) || strlen($encoded) > self::MAX_SOURCE) throw new MonFault('MON_PAYLOAD_INVALID');
        $key = hash('sha256', self::json($packed));
        if (isset(self::$payloadCache[$key])) return mon_decode(self::$payloadCache[$key]);
        $binary = base64_decode($encoded, true); if ($binary === false) throw new MonFault('MON_PAYLOAD_INVALID');
        if ($packed->encoding === 'xz+base64') $raw = self::xz($binary, true);
        elseif ($packed->encoding === 'gzip+base64' && function_exists('gzdecode')) $raw = @gzdecode($binary, self::MAX_PAYLOAD + 1);
        else throw new MonFault('MON_CODEC_UNAVAILABLE');
        if (!is_string($raw) || strlen($raw) !== $bytes || !hash_equals($packed->sha256, hash('sha256', $raw))) throw new MonFault('MON_PAYLOAD_HASH_INVALID');
        try { $v = mon_decode($raw); } catch (Throwable $e) { throw new MonFault('MON_PAYLOAD_INVALID'); }
        if (!($v instanceof stdClass)) throw new MonFault('MON_PAYLOAD_INVALID');
        // A bounded per-request cache, keyed by compressed bytes and metadata, never a model-context copy.
        if (count(self::$payloadCache) >= 2) self::$payloadCache = [];
        self::$payloadCache[$key] = $raw; return $v;
    }
    public static function releasePayloadCache(): void {self::$payloadCache=[];}
    public static function pack($payload, string $encoding): stdClass {
        $raw = self::json($payload); if (strlen($raw) > self::MAX_PAYLOAD) throw new MonFault('MON_PAYLOAD_TOO_LARGE');
        if ($encoding === 'xz+base64') $binary = self::xz($raw, false);
        elseif ($encoding === 'gzip+base64' && function_exists('gzencode')) $binary = gzencode($raw, 9);
        else throw new MonFault('MON_CODEC_UNAVAILABLE');
        if (!is_string($binary)) throw new MonFault('MON_PAYLOAD_INVALID');
        return (object)['data' => base64_encode($binary), 'encoding' => $encoding, 'format' => 'UTF-8 JSON', 'sha256' => hash('sha256', $raw), 'uncompressed_bytes' => strlen($raw)];
    }
    public static function business($map): array {
        if (!($map instanceof stdClass)) throw new MonFault('MON_RESULT_INVALID'); $rows = [];
        foreach ($map as $sid => $r) {
            if (!($r instanceof stdClass) || mon_get($r, 'symbol_id') !== $sid) throw new MonFault('MON_RESULT_INVALID'); self::identity($sid);
            $row = new stdClass();
            foreach (['symbol_id', 'rank', 'action', 'readiness', 'entry_plans', 'active_plan', 'entry_zone', 'invalidation'] as $k) {
                if (!property_exists($r, $k)) throw new MonFault('MON_RESULT_INVALID'); $row->$k = $r->$k;
            }
            if (property_exists($r,'swing_signal')) $row->swing_signal=$r->swing_signal;
            if (property_exists($r,'autonomous_decision')) $row->autonomous_decision=$r->autonomous_decision;
            foreach(['data_status','signal_as_of']as $k)if(property_exists($r,$k))$row->$k=$r->$k;
            $rows[$sid] = $row;
        }
        ksort($rows, SORT_STRING); return array_values($rows);
    }
    public static function verifyFacts($d, $payload): void {
        if (in_array($d->analysis->criteria_version,['MON-P3.0','MON-P3.1'],true)) {
            if (!(mon_get($payload,'swing_signals') instanceof stdClass) || mon_get(mon_get($payload,'selection_facts'),'candidates_ref') !== 'swing_signals' ||
                !hash_equals((string)mon_get($payload->selection_facts,'candidate_facts_sha256',''),self::digest(array_values(get_object_vars($payload->swing_signals))))) throw new MonFault('MON_SWING_FACT_HASH_INVALID');
        }
        foreach (['selection_facts', 'input_facts', 'final_results_details', 'independent_results'] as $k) if (!(mon_get($payload, $k) instanceof stdClass)) throw new MonFault('MON_PAYLOAD_FACTS_MISSING');
        foreach (['selection_fingerprint' => $payload->selection_facts, 'input_fingerprint' => $payload->input_facts, 'result_fingerprint' => self::business($payload->final_results_details)] as $k => $v) {
            if (!is_string(mon_get($d->analysis, $k)) || !hash_equals($d->analysis->$k, self::digest($v))) throw new MonFault('MON_FACT_HASH_INVALID');
        }
        if (mon_get($payload->input_facts, 'selection_fingerprint') !== $d->analysis->selection_fingerprint || self::utc(mon_get($payload->input_facts, 'as_of')) !== self::utc($d->analysis->as_of)) throw new MonFault('MON_FACT_SOURCE_CONFLICT');
        $plain = self::rows($d->analysis->results); $full = $payload->final_results_details;
        if (count($plain) !== count(get_object_vars($full))) throw new MonFault('MON_RESULT_SOURCE_CONFLICT');
        foreach ($plain as $sid => $r) {
            if (!property_exists($full, $sid)) throw new MonFault('MON_RESULT_SOURCE_CONFLICT');
            foreach (['rank', 'action', 'readiness', 'entry_plans', 'active_plan', 'entry_zone', 'invalidation', 'price', 'price_as_of'] as $k) {
                if (property_exists($r, $k) && self::json($r->$k) !== self::json(mon_get($full->$sid, $k))) throw new MonFault('MON_RESULT_SOURCE_CONFLICT');
            }
        }
    }
    public static function quoteState($r, $w, string $asOf): stdClass {
        $checks = mon_get($r, 'price_checks', new stdClass()); $at = mon_get($r, 'price_as_of'); $country = mon_get($r, 'country');
        $state = (object)['valid' => false, 'age_seconds' => null, 'delay_seconds' => mon_get($checks, 'delay_seconds'), 'session' => 'unknown', 'blockers' => []];
        if (!in_array($country, ['KR', 'US', 'JP'], true)) { $state->blockers[] = 'identity_needs_check'; return $state; }
        $now = self::seconds($asOf); $market = MonMarket::state($w, $country, (int)floor($now)); $state->session = $market['session'];
        try { $age = $now - self::seconds($at); $state->age_seconds = (float)self::decimal6($age); }
        catch (MonFault $e) { $state->blockers[] = 'quote_time_unknown'; $age = null; }
        $delay = mon_number($state->delay_seconds); $settings = $w->settings;
        $ageMax = min(300, max(0, (int)mon_get($settings, 'action_price_max_age_seconds', 300)));
        $delayMax = min(300, max(0, (int)mon_get($settings, 'action_known_delay_max_seconds', 300)));
        $future = min(5, max(0, (int)mon_get($settings, 'future_clock_tolerance_seconds', 5)));
        if ($age === null || $age < -$future || $age > $ageMax) $state->blockers[] = 'current_price_needs_check';
        if ($delay === null || $delay < 0 || $delay > $delayMax) $state->blockers[] = 'quote_delay_needs_check';
        if (!in_array(mon_get($checks, 'timestamp_basis'), ['trade', 'bar_end'], true) || !in_array(mon_get($r, 'price_type'), ['last', 'minute_close'], true)) $state->blockers[] = 'quote_basis_needs_check';
        $price = mon_number(mon_get($r, 'price')); if ($price === null || $price <= 0) $state->blockers[] = 'price_invalid';
        $zone = MonMarket::timezone($country); $date = (new DateTimeImmutable($asOf))->setTimezone($zone)->format('Y-m-d');
        $quoteDate = $age === null ? null : (new DateTimeImmutable($at))->setTimezone($zone)->format('Y-m-d');
        if ($quoteDate !== $date || mon_get($checks, 'market_date') !== $date) $state->blockers[] = 'market_date_needs_check';
        if ($state->session !== 'regular' || mon_get($checks, 'session_at_analysis') !== 'regular') $state->blockers[] = 'regular_session_needs_check';
        $state->blockers = array_values(array_unique($state->blockers)); $state->valid = !$state->blockers; return $state;
    }
    public static function current($r, $w, string $at, bool $comparisonReady = true): stdClass {
        $q = self::quoteState($r, $w, $at); $risk = mon_get($r, 'risk_checks', new stdClass());
        $blockers = $q->blockers; $plans = mon_get($r, 'entry_plans'); $matching = [];
        $price = mon_number(mon_get($r, 'price'));
        foreach (is_array($plans) ? $plans : [] as $p) {
            $z = mon_get($p, 'entry_zone');
            if (is_array($z) && count($z) === 2 && $price !== null && mon_number($z[0]) !== null && mon_number($z[1]) !== null && $price >= mon_number($z[0]) && $price <= mon_number($z[1])) $matching[] = mon_get($p, 'mode');
        }
        $expiry = mon_get($r, 'plan_valid_until');
        try { $planCurrent = self::seconds($at) <= self::seconds($expiry); } catch (MonFault $e) { $planCurrent = false; }
        if (!$matching) $blockers[] = $plans ? 'outside_entry_zone' : 'entry_plan_needs_data';
        if (!$planCurrent) $blockers[] = 'plan_expired';
        if (!$comparisonReady) $blockers[] = 'latest_comparison_pending';
        // Saved risk checks are historical. An old ready never becomes a current ready on redisplay.
        $review = mon_get($r, 'risk_review', new stdClass());$reviewState=MonRiskState::at($review,$r->country,$at);
        if (!$reviewState->corporate_verified || mon_get($risk, 'current_corporate_risk_verified') !== true) $blockers[] = 'corporate_risk_needs_check';
        if (!$reviewState->trading_verified || mon_get($risk, 'current_trading_status_verified') !== true) $blockers[] = 'trading_status_needs_check';
        if (mon_get($risk, 'confirmed_material_adverse') === true) $blockers[] = 'confirmed_material_adverse';
        if (in_array(mon_get($w,'criteria_version'),['MON-P3.0','MON-P3.1'],true) && mon_get($r,'action') === 'BUY_REVIEW') {
            if (!MonSwing::active($r,mon_get($w,'criteria_version'))) $blockers[]='swing_signal_invalid';
            else {
                $s=$r->swing_signal; $liveOK=false; $hist=mon_get($s,'histogram'); $kind=mon_get($s,'macd_kind');
                if ($q->valid && $price!==null) {
                    $four=mon_get($s,'prior_four_closes',[]);
                    if (is_array($four) && count($four)===4 && $price>(array_sum($four)+$price)/5 &&
                        (mon_get($s,'ma5_reclaim_close')===true || mon_get($s,'ma5_reclaim_live')===true)) $liveOK=true;
                    $ef=mon_number(mon_get($s,'ema_fast_last')); $es=mon_number(mon_get($s,'ema_slow_last')); $sig=mon_number(mon_get($s,'signal'));
                    if ($ef!==null && $es!==null && $sig!==null && in_array($kind,['MACD_GOLD','MACD_AFTER','MACD_BEFORE'],true)) {
                        $m=(2/13*$price+11/13*$ef)-(2/27*$price+25/27*$es); $alpha=2/(MonSwing::rules(mon_get($w,'criteria_version'))->macd_signal+1); $h=$m-($alpha*$m+(1-$alpha)*$sig);
                        if ($kind==='MACD_BEFORE' && $h>=(float)$hist) $liveOK=true;
                        elseif (in_array($kind,['MACD_GOLD','MACD_AFTER'],true) && $h>0) $liveOK=true;
                    }
                }
                if (!$liveOK) $blockers[]='swing_price_confirmation_required';
            }
        }
        return (object)['quote' => $q, 'readiness' => mon_get($r, 'action') === 'BUY_REVIEW' && !$blockers ? 'ready' : ($plans && $planCurrent && $comparisonReady ? 'conditional' : 'needs_data'), 'matching_plans' => $matching, 'blockers' => array_values(array_unique($blockers))];
    }
    public static function storage($d, bool $enforce): stdClass {
        $policy = mon_get($d->watchlist->settings, 'analysis_storage_policy', new stdClass());
        $max = min(self::MAX_SOURCE, max(1, (int)mon_get($policy, 'max_bytes', self::MAX_SOURCE)));
        $reserve = max(65536, (int)mon_get($policy, 'collection_reserve_min_bytes', 65536), 4096 + 2048 * count($d->watchlist->symbols));
        $copy = clone $d; $copy->collection = (object)[]; $bytes = strlen(self::json($d)); $owned = strlen(self::json($copy));
        $s = (object)['bytes' => $bytes, 'max_bytes' => $max, 'bytes_without_collection' => $owned, 'collection_reserve_bytes' => $reserve, 'remaining_after_reserve' => $max - $owned - $reserve, 'fits' => $bytes <= $max && $owned + $reserve <= $max];
        if ($enforce && !$s->fits) throw new MonFault('MON_STORAGE_LIMIT'); return $s;
    }
    public static function evidence($d, $payload): array {
        $map = [];
        foreach ($d->analysis->evidence as $entry) {
            if (!($entry instanceof stdClass) || !is_string(mon_get($entry, 'id'))) throw new MonFault('MON_EVIDENCE_INVALID');
            $id = $entry->id;
            if (property_exists($entry, 'evidence_ref')) {
                if ($entry->evidence_ref !== 'mon_evidence_index:' . $id || !(mon_get(mon_get($payload, 'mon_evidence_index'), $id) instanceof stdClass)) throw new MonFault('MON_EVIDENCE_INVALID');
                $entry = $payload->mon_evidence_index->$id;
                if (mon_get($entry, 'id') !== $id) throw new MonFault('MON_EVIDENCE_INVALID');
            }
            if (isset($map[$id])) throw new MonFault('MON_EVIDENCE_ID_CONFLICT'); $map[$id] = $entry;
        }
        return $map;
    }
    public static function compactEvidence($d, $payload): void {
        $full = self::evidence($d, $payload);
        if (!(mon_get($payload, 'mon_evidence_index') instanceof stdClass)) $payload->mon_evidence_index = new stdClass();
        $index = [];
        foreach ($full as $id => $entry) { $payload->mon_evidence_index->$id = $entry; $index[] = (object)['id' => $id, 'evidence_ref' => 'mon_evidence_index:' . $id]; }
        $d->analysis->evidence = $index;
        $d->analysis->input_snapshot->compressed_fields = array_keys(get_object_vars($payload)); sort($d->analysis->input_snapshot->compressed_fields, SORT_STRING);
    }
    public static function render($d, string $at, array $reasons = []): string {
        $a = $d->analysis; $watch = self::rows($d->watchlist->symbols); $rows = []; $periods = self::periods($d, $at);
        foreach ($a->results as $r) if (in_array(mon_get($r, 'action'), ['BUY_REVIEW', 'SELL_REVIEW', 'REFERENCE_REVIEW'], true) || mon_get($watch[mon_get($r, 'symbol_id')] ?? null, 'is_held') === true) $rows[] = $r;
        $known = self::rows($a->results);
        foreach ($watch as $sid => $wr) if ($wr->is_held && !isset($known[$sid])) $rows[] = (object)['symbol_id' => $sid, 'country' => $wr->country, 'name' => $wr->name, 'currency' => $wr->currency, 'action' => 'HOLD', 'entry_plans' => null, 'reason' => '등록 보유분의 분석 준비 필요'];
        usort($rows, static function ($a, $b) { return strcmp($a->country, $b->country) ?: ((int)mon_get($a, 'rank', 9999) <=> (int)mon_get($b, 'rank', 9999)) ?: strcmp($a->symbol_id, $b->symbol_id); });
        $safe = static function ($s) { return str_replace(['|', "\r", "\n", '<', '>'], ['／', ' ', ' ', '＜', '＞'], (string)$s); };
        $num = static function ($n) { if (mon_number($n) === null) return '미확인'; return rtrim(rtrim(MonRun::decimal6($n), '0'), '.'); };
        $same = self::utc($a->as_of) === self::utc($at);
        $text = (in_array($a->criteria_version,['MON-P3.0','MON-P3.1'],true) ? 'MON 스윙 · ' . $a->criteria_version . ' · MACD(12,26,' . MonSwing::rules($a->criteria_version)->macd_signal . ') · ' : '') . '기준 ' . $a->as_of . ' · ' . $a->status . ($same ? '' : ' · 재사용 표시 ' . $at) . "\n\n| 국가 | 종목 | 판단 | 자료 기준 | 가격 조건 | 핵심 이유 |\n|---|---|---|---|---|---|\n";
        foreach ($rows as $r) {
            $conditions = [];
            if (in_array($a->criteria_version,['MON-P3.0','MON-P3.1'],true)) {
                $s=mon_get($r,'swing_signal'); $labels=['MA5_RECLAIM_CLOSE'=>'MA5 종가 재돌파','MA5_RECLAIM_LIVE'=>'MA5 장중 재돌파(잠정)','MACD_GOLD'=>'MACD 골든','MACD_AFTER'=>'MACD 골든 후','MACD_BEFORE'=>'MACD 골든 직전(근접)'];
                $signals=[]; foreach(mon_get($s,'qualifying_signals',[]) as $key) $signals[]=$labels[$key]??$key;
                if ($signals) $conditions[]=implode('·',$signals);
            }
            foreach (is_array(mon_get($r, 'entry_plans')) ? $r->entry_plans : [] as $p) {
                $z = mon_get($p, 'entry_zone');
                if (is_array($z) && count($z) === 2) $conditions[] = (mon_get($p, 'mode') === 'breakout' ? '돌파 ' : '눌림 ') . $num($z[0]) . '~' . $num($z[1]) . ' / 무효 ' . $num(mon_get($p, 'invalidation'));
            }
            $comparisonReady = mon_get($periods[$r->country] ?? null, 'current_period', false);
            $now = self::current($r, $d->watchlist, $at, $comparisonReady); $state = $now->readiness;
            if ($r->action === 'SELL_REVIEW') $label = '매도 검토';
            elseif($r->action==='REFERENCE_REVIEW')$label='이전자료 참고 · 재확인';
            elseif ($r->action === 'BUY_REVIEW') $label = ($comparisonReady ? '매입 검토 · ' : '이전 후보 참고 · ') . (['conditional'=>'조건부','ready'=>'진입 확인','needs_data'=>'자료 필요'][$state]??$state);
            else $label = '보유 관찰';
            $date=mon_get($r,'signal_as_of',mon_get(mon_get($r,'swing_signal'),'bars_as_of','미확인'));
            $status=mon_get($r,'data_status','verified_saved_data');$basis=$date.' '.(['latest'=>'최신 완료봉','reused_current'=>'완료봉 재사용','previous_verified'=>'이전자료','previous_verified_reference'=>'과거 신호','verified_saved_data'=>'검증 기록'][$status]??$status);
            if(in_array('confirmed_material_adverse',$now->blockers,true)&&$r->action==='BUY_REVIEW')$label='중대 위험 · 진입 보류';
            $verifiedName=mon_get($watch[$r->symbol_id]??null,'name',explode('|',$r->symbol_id)[2].' · 이름 미확인');
            $text .= '| ' . $safe($r->country) . ' | ' . $safe($verifiedName) . ' (' . $safe(explode('|', $r->symbol_id)[2]) . ') | ' . $label . ' | ' . $safe($basis) . ' | ' . $safe($conditions ? implode('; ', $conditions) . ' ' . mon_get($r, 'currency', '') : '계획 확인 필요') . ' | ' . $safe(mon_get($r, 'reason', '확인 필요')) . " |\n";
        }
        if (!$rows) $text .= '| 한·미·일 | — | 추천 확정 대기 | 미확인 | — | ' . (in_array($a->criteria_version,['MON-P3.0','MON-P3.1'],true) ? 'MA5/MACD 신호 검증 후 ChatGPT 자율 선정' : '자료 확인 필요') . " |\n";
        $notes = [];
        if ($reasons) $notes[] = implode('; ', array_values(array_unique($reasons)));
        if (!$same) $notes[] = '이전 검증 결과 재사용. 현재 가격·거래·기업 확인은 새로 완료한 것이 아닙니다.';
        $limits = mon_get($a, 'limitations', []); $riskNotes = [];
        $corporate = mon_get(mon_get($a, 'input_snapshot'), 'corporate_risk');
        foreach (['confirmed_current_material_adverse' => '확인된 중대 위험', 'pending_items' => '후속 확인 필요'] as $kind => $label) foreach (mon_get($corporate, $kind, []) as $event) {
            $sid = mon_get($event, 'symbol_id'); if (!is_string($sid) || (!isset($known[$sid]) && mon_get($watch[$sid]??null,'is_held')!==true)) continue;
            $riskNotes[] = $label . ': ' . mon_get($watch[$sid] ?? null, 'name', $sid);
        }
        if ($limits || $riskNotes) $notes[] = '기록 기준 ' . mon_get($a, 'limitations_as_of', $a->as_of) . ': ' . implode('; ', array_values(array_unique(array_merge($limits, $riskNotes))));
        foreach (array_slice($notes,0,3) as $note) $text .= "\n- " . $safe($note);
        $discovery=[];foreach(mon_get(mon_get($a,'discovery_state'),'countries',[])as $row){
            $found=(int)mon_get($row,'quota_identified_count',mon_get($row,'new_ids_identified_today',0));$goal=(int)mon_get($row,'daily_target',20);$status=mon_get($row,'goal_status');$today=(int)mon_get($row,'new_ids_identified_today',0);$queued=(int)mon_get($row,'next_session_queued_count',0);
            $discovery[]=$row->country.' '.mon_get($row,'discovery_date',mon_get($row,'market_date')).' · 실제 신규 '.$today.'개 · '.(strpos((string)$status,'not_due')===0?'비거래일; '.mon_get($row,'next_trading_date').' 대기 '.$queued.'/'.$goal:('목표 '.$found.'/'.$goal.' · '.($found>=$goal?'충족':'미달 '.max(0,$goal-$found).'개')));
        }
        if($discovery)$text.="\n- 신규 신원 확인: ".$safe(implode('; ',$discovery)).'. 상세 확보·수치 적격은 별도 집계.';
        return $text . "\n";
    }
    public static function rebase($base, $candidate, $latest): stdClass {
        self::validate($latest); $b = clone $base; $l = clone $latest; unset($b->collection, $l->collection);
        if (self::json($b) !== self::json($l)) throw new MonFault('MON_ANALYSIS_SOURCE_CONFLICT');
        $out = mon_decode(self::json($candidate)); $out->collection = $latest->collection;
        $affected=[];
        foreach(mon_get($latest->collection,'detail_responses',new stdClass())as $sid=>$response){
            $old=mon_get(mon_get($base->collection,'detail_responses'),$sid);$request=mon_get(mon_get($latest->watchlist,'detail_requests'),$sid);
            if(MonDetails::matching($request,$response)&&self::json($response)!==self::json($old)&&
                (mon_time(mon_get($response,'completed_at'))??0)>=(int)self::seconds($candidate->analysis->as_of))$affected[explode('|',$sid)[0]]=true;
        }
        if($affected){$out->analysis->pending_analysis=true;$out->analysis->pending_analysis_countries=array_keys($affected);
            if(!(mon_get($out->analysis->input_snapshot,'mon_input_state')instanceof stdClass))$out->analysis->input_snapshot->mon_input_state=new stdClass();
            $out->analysis->input_snapshot->mon_input_state->late_detail_status='pending_analysis_not_used_in_fixed_decision';}
        self::storage($out, true); return $out;
    }
}

/* MON-SWING-1.1: numerical eligibility support; final selections belong to MON/ChatGPT. */
final class MonSwing {
    private static $technicalMemo=[];private static $technicalLoaded=false;private static $technicalHits=0;private static $technicalMisses=0;
    const CRITERIA = 'MON-P3.1';
    const FORMULA = 'MON-SWING-1.1';
    public static function rules(?string $criteria=null): stdClass {
        $legacy=$criteria==='MON-P3.0';
        $r=(object)['strategy'=>'swing','formula_version'=>$legacy?'MON-SWING-1.0':self::FORMULA,'ma_period'=>5,'min_below_days'=>10,
            'macd_fast'=>12,'macd_slow'=>26,'macd_signal'=>$legacy?19:9,'macd_after_days'=>3,
            'macd_before_hist_bars'=>3,'macd_before_gap_atr'=>0.10,'rs20_min_pp'=>0,'rs20_operator'=>'>',
            'ema_seed'=>'SMA_of_first_N_valid_closes','ema_alpha'=>'2/(N+1)',
            'final_selector'=>'ChatGPT','reference_limit_per_country'=>10,'buy_limit_per_country'=>3];
        if(!$legacy)$r->history_policy='full_provider_window_no_truncation';
        return $r;
    }
    public static function bars($rows): array {
        if (!is_array($rows) || count($rows)>20000) throw new MonFault('MON_SWING_BARS_INVALID');
        $last=''; $out=[];
        foreach($rows as $r) {
            if(!is_array($r) || count($r)<6 || !is_string($r[0]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$r[0]) || $r[0]<=$last) throw new MonFault('MON_SWING_BAR_ORDER_INVALID');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$r[0]);
            if(!$date || $date->format('Y-m-d')!==$r[0]) throw new MonFault('MON_SWING_BAR_DATE_INVALID');
            $v=[]; for($i=1;$i<6;$i++){ $n=mon_number($r[$i]); if($n===null || !is_finite($n) || ($i<5?$n<=0:$n<0)) throw new MonFault('MON_SWING_BAR_PRICE_INVALID'); $v[]=$n; }
            if($v[1]<$v[2] || $v[0]<$v[2] || $v[0]>$v[1] || $v[3]<$v[2] || $v[3]>$v[1]) throw new MonFault('MON_SWING_BAR_RANGE_INVALID');
            $last=$r[0]; $out[]=[$r[0],$v[0],$v[1],$v[2],$v[3],$v[4]];
        }
        return $out;
    }
    public static function ema(array $values,int $period): array {
        $result=array_fill(0,count($values),null); $seed=[]; $previous=null; $alpha=2/($period+1);
        foreach($values as $i=>$value) {
            if($value===null) continue;
            if($previous===null){$seed[]=$value;if(count($seed)<$period)continue;$previous=array_sum($seed)/$period;}
            else $previous=$alpha*$value+(1-$alpha)*$previous;
            $result[$i]=$previous;
        }
        return $result;
    }
    public static function benchmarkCloses($rows): array {
        if(!is_array($rows))throw new MonFault('MON_SWING_BENCHMARK_MISSING');
        $map=[];$previous='';
        foreach($rows as $r){
            if(!is_array($r)||count($r)<5||!is_string($r[0])||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$r[0])||$r[0]<=$previous)throw new MonFault('MON_SWING_BENCHMARK_ORDER_INVALID');
            $v=mon_number($r[4]);if($v===null||!is_finite($v)||$v<=0)throw new MonFault('MON_SWING_BENCHMARK_PRICE_INVALID');
            $previous=$r[0];$map[$r[0]]=$v;
        }
        return $map;
    }
    public static function classifyMacd(array $hist,float $atr): array {
        $n=count($hist); if($n<2 || $hist[$n-1]===null || $hist[$n-2]===null) return ['kind'=>null,'cross_index'=>null];
        $last=$hist[$n-1];
        if($hist[$n-2]<=0 && $last>0) return ['kind'=>'MACD_GOLD','cross_index'=>$n-1];
        if($last>0) for($i=$n-2;$i>=max(1,$n-4);$i--) {
            if($hist[$i]===null || $hist[$i-1]===null) continue;
            if($hist[$i-1]<=0 && $hist[$i]>0) {
                $positive=true; for($j=$i;$j<$n;$j++) if($hist[$j]===null || $hist[$j]<=0)$positive=false;
                if($positive)return ['kind'=>'MACD_AFTER','cross_index'=>$i];
            }
        }
        if($n>=3 && $atr>0 && $last<=0 && $hist[$n-3]!==null && $hist[$n-2]!==null &&
            $hist[$n-3]<$hist[$n-2] && $hist[$n-2]<$last && abs($last)/$atr<=0.10)
            return ['kind'=>'MACD_BEFORE','cross_index'=>null];
        return ['kind'=>null,'cross_index'=>null];
    }
    public static function technical(array $input,$live=null): stdClass {
        self::loadTechnicalCache();$memoKey=hash('sha256',MonRun::json((object)['version'=>MON_VERSION,'rules'=>self::rules(),'bars'=>$input,'live'=>$live]));
        if(isset(self::$technicalMemo[$memoKey])){self::$technicalHits++;return mon_decode(self::$technicalMemo[$memoKey]);}self::$technicalMisses++;

        $b=self::bars($input); $n=count($b); if($n<14 || ($n<15 && !($live instanceof stdClass))) throw new MonFault('MON_SWING_MA_HISTORY_MISSING');
        $closes=array_column($b,4); $ma=[];
        foreach($closes as $i=>$v)$ma[$i]=$i>=4?array_sum(array_slice($closes,$i-4,5))/5:null;
        $below=0; for($i=$n-2;$i>=4 && $closes[$i]<$ma[$i];$i--)$below++;
        $closeReclaim=$below>=10 && $closes[$n-1]>$ma[$n-1];
        $trs=[];for($i=1;$i<$n;$i++)$trs[]=max($b[$i][2]-$b[$i][3],abs($b[$i][2]-$closes[$i-1]),abs($b[$i][3]-$closes[$i-1]));
        $atr=count($trs)>=14?array_sum(array_slice($trs,-14))/14:0.0;
        $fast=self::ema($closes,12);$slow=self::ema($closes,26);$macd=[];
        foreach($closes as $i=>$unused)$macd[$i]=$fast[$i]!==null && $slow[$i]!==null?$fast[$i]-$slow[$i]:null;
        $signal=self::ema($macd,9);$hist=[];
        foreach($closes as $i=>$unused)$hist[$i]=$macd[$i]!==null && $signal[$i]!==null?$macd[$i]-$signal[$i]:null;
        $kind=self::classifyMacd($hist,$atr);$qualified=[];
        if($closeReclaim)$qualified[]='MA5_RECLAIM_CLOSE';if($kind['kind']!==null)$qualified[]=$kind['kind'];
        $maEndBelow=0;for($i=$n-1;$i>=4 && $closes[$i]<$ma[$i];$i--)$maEndBelow++;
        $liveReclaim=false;$projected=null;
        if($live instanceof stdClass && mon_get($live,'validated')===true && mon_number(mon_get($live,'price'))>0 && mon_get($live,'market_date')>$b[$n-1][0]) {
            $projected=(array_sum(array_slice($closes,-4))+mon_number($live->price))/5;
            $liveReclaim=$maEndBelow>=10 && $live->price>$projected;
            if($liveReclaim)$qualified[]='MA5_RECLAIM_LIVE';
        }
        $round=static function($x){return $x===null?null:(float)MonRun::decimal6($x);};
        $result=(object)['formula_version'=>self::FORMULA,'macd_parameters'=>[12,26,9],'bars_as_of'=>$b[$n-1][0],'bar_count'=>$n,
            'qualifying_signals'=>$qualified,'technical_qualified'=>count($qualified)>0,
            'ma5'=> $round($ma[$n-1]),'ma5_below_days_before_last'=>$below,'ma5_below_days_through_last'=>$maEndBelow,
            'ma5_reclaim_close'=>$closeReclaim,'ma5_reclaim_live'=>$liveReclaim,'ma5_projected_live'=>$round($projected),
            'live_observation'=>$liveReclaim?$live:null,'last_close'=>$closes[$n-1],'prior_four_closes'=>array_slice($closes,-4),
            'macd'=> $round($macd[$n-1]),'signal'=> $round($signal[$n-1]),'histogram'=> $round($hist[$n-1]),
            'histogram_last_three'=>array_map($round,array_slice($hist,-3)),
            'macd_kind'=>$kind['kind'],'macd_cross_date'=>$kind['cross_index']===null?null:$b[$kind['cross_index']][0],
            'macd_cross_age_days'=>$kind['cross_index']===null?null:$n-1-$kind['cross_index'],
            'macd_pre_is_confirmed'=>false,'ema_fast_last'=>$round($fast[$n-1]),'ema_slow_last'=>$round($slow[$n-1]),
            'atr14'=>$round($atr),'macd_gap_atr'=>$atr>0 && $hist[$n-1]!==null?$round(abs($hist[$n-1])/$atr):null,
            'macd_status'=>$signal[$n-1]===null?'needs_history':'calculated','price_basis'=>'split_adjusted_completed_daily'];
    
        self::$technicalMemo[$memoKey]=MonRun::json($result);if(count(self::$technicalMemo)>256)array_shift(self::$technicalMemo);return $result;
    }
    
    private static function loadTechnicalCache(): void {
        if(self::$technicalLoaded)return;self::$technicalLoaded=true;if(!MonPreparation::$store)return;
        try{$c=MonPreparation::$store->readPrivate('technical-cache.php');if(mon_get($c,'version')!==MON_VERSION||!is_array(mon_get($c,'entries'))||count($c->entries)>256||
            !hash_equals((string)mon_get($c,'sha256'),hash('sha256',MonRun::json($c->entries))))return;foreach($c->entries as $row){
            if(!preg_match('/^[a-f0-9]{64}$/',(string)mon_get($row,'key'))||!is_string(mon_get($row,'value'))||strlen($row->value)>8192)return;
            self::$technicalMemo[$row->key]=$row->value;}}
        catch(Throwable $e){self::$technicalMemo=[];}
    }
    public static function flushTechnicalCache(?MonStore $store=null): void {
        $store=$store??MonPreparation::$store;if(!$store)return;$rows=[];foreach(self::$technicalMemo as $key=>$value)$rows[]=(object)['key'=>$key,'value'=>$value];
        try{$store->writePrivate('technical-cache.php',(object)['version'=>MON_VERSION,'entries'=>$rows,'sha256'=>hash('sha256',MonRun::json($rows))]);}
        catch(MonFault $e){$store->log('technical_cache_unavailable',['code'=>$e->faultCode]);}
    }
    public static function numericCacheStats(): array {return ['hits'=>self::$technicalHits,'misses'=>self::$technicalMisses,'entries'=>count(self::$technicalMemo)];}
    public static function resetNumericCache(): void {self::$technicalMemo=[];self::$technicalLoaded=false;self::$technicalHits=0;self::$technicalMisses=0;}
    public static function active($r,?string $criteria=null): bool {
        $s=mon_get($r,'swing_signal');
        $formula=$criteria==='MON-P3.0'?'MON-SWING-1.0':self::FORMULA;
        return $s instanceof stdClass && mon_get($s,'formula_version')===$formula && mon_get($s,'eligible')===true &&
            mon_get($s,'market_strong')===true && is_array(mon_get($s,'qualifying_signals')) && count($s->qualifying_signals)>0;
    }
    public static function live($r,$w,$at): ?stdClass {
        if(!($r instanceof stdClass) || mon_get($r,'source_kind')!=='independent')return null;
        $parts=explode('|',(string)mon_get($r,'symbol_id',''));$currency=['KR'=>'KRW','US'=>'USD','JP'=>'JPY'];
        if(count($parts)!==3 || mon_get($r,'country')!==$parts[0] || mon_get($r,'venue')!==$parts[1] ||
            mon_get($r,'currency')!==($currency[$parts[0]]??null))return null;
        $evidence=mon_get($r,'evidence_ids');if(!is_array($evidence)||!$evidence)return null;
        foreach($evidence as $id)if(!is_string($id)||trim($id)==='')return null;
        $checks=MonRun::quoteState($r,$w,$at);if(!$checks->valid)return null;
        return (object)['validated'=>true,'price'=>$r->price,'market_date'=>$r->price_checks->market_date,
            'quote_at'=>$r->price_as_of,'symbol_id'=>$r->symbol_id,'source_kind'=>'independent',
            'country'=>$r->country,'currency'=>$r->currency,'venue'=>$r->venue,'price_type'=>$r->price_type,
            'price_checks'=>$r->price_checks,'evidence_ids'=>$evidence];
    }
    public static function prepare($d,$context,array $liveRows=[]): array {
        MonRun::validate($d);$c=MonRun::context($context);MonRun::verifyFacts($d,MonRun::payload($d));
        $key=MonPreparation::key($d,$c,$liveRows);$cached=MonPreparation::get($key);$reused=$cached!==null;
        if(!$reused){$cached=self::buildPrepared($d,$c,$liveRows);MonPreparation::put($key,$cached);}
        $cached['state_token']=MonRun::token($d,$c);$cached['as_of']=$c->rendered_at;
        $cached['cache_key']=$key;$cached['prepare_reused']=$reused;return $cached;
    }
    private static function buildPrepared($d,$context,array $liveRows=[]): array {
        MonRun::validate($d);$c=MonRun::context($context);$p=MonRun::payload($d);MonRun::verifyFacts($d,$p);
        $audit=MonRun::rows($d->analysis->candidate_audit);$watch=MonRun::rows($d->watchlist->symbols);$periods=MonRun::periods($d,$c->rendered_at);
        $previousSelection=[];foreach(mon_get($p->selection_facts,'candidates',[]) as $row)$previousSelection[$row->symbol_id]=$row;
        if(mon_get($p->selection_facts,'candidates_ref')==='swing_signals')foreach($p->swing_signals as $sid=>$row)$previousSelection[$sid]=$row;
        $liveMap=MonRun::rows($liveRows);$rows=new stdClass();$counts=[];$rank=[];$full=[];
        foreach($audit as $sid=>$old) {
            $country=explode('|',$sid)[0];$m=mon_get($p->candidate_metrics,$sid);$bars=mon_get($p->series,$sid);
            $r=(object)['symbol_id'=>$sid,'name'=>mon_get($watch[$sid]??null,'name',explode('|',$sid)[2]),'country'=>$country,
                'formula_version'=>self::FORMULA,'eligible'=>false,'market_strong'=>false,'qualifying_signals'=>[],
                'eligibility'=>'unverified','reason_codes'=>[],'bar_input_sha256'=>null,'rank_hint'=>null,'rs20_pp'=>null,
                'R20'=>null,'ADV20'=>mon_get($m,'ADV20'),'evidence_ids'=>mon_get($old,'evidence_ids',[])];
            try {
                if(mon_get($old,'eligibility')==='fail'){ $r->eligibility='fail';$r->reason_codes[]='confirmed_material_adverse'; }
                else {
                    if(!($m instanceof stdClass) || !is_array($bars))throw new MonFault('MON_SWING_SERIES_MISSING');
                    $b=self::bars($bars);$n=count($b);if($n<21)throw new MonFault('MON_SWING_RS_HISTORY_MISSING');
                    $dates=array_column(array_slice($b,-21),0);$last=$dates[20];
                    if(mon_get($m,'bars_as_of')!==$last || mon_get($m,'comparison_end')!==$last)throw new MonFault('MON_SWING_PERIOD_MISMATCH');
                    $benchId=mon_get($m,'benchmark');$bb=mon_get($p->series,$benchId);if(!is_array($bb))throw new MonFault('MON_SWING_BENCHMARK_MISSING');
                    $bm=self::benchmarkCloses($bb);foreach($dates as $date)if(!isset($bm[$date]))throw new MonFault('MON_SWING_BENCHMARK_DATE_MISSING');
                    $expected=array_values(array_filter(array_keys($bm),static function($date)use($last){return $date<=$last;}));
                    if(array_slice($expected,-21)!==$dates)throw new MonFault('MON_SWING_COMPARISON_DATES_MISMATCH');
                    $stockReturn=$b[$n-1][4]/$b[$n-21][4]-1;$marketReturn=$bm[$last]/$bm[$dates[0]]-1;
                    $rawRs=100*($stockReturn-$marketReturn);$r->R20=(float)MonRun::decimal6($stockReturn);$r->rs20_pp=(float)MonRun::decimal6($rawRs);
                    $r->market_strong=$rawRs>0;$r->benchmark=$benchId;$r->comparison_dates=$dates;
                    $live=self::live($liveMap[$sid]??null,$d->watchlist,$c->rendered_at);
                    $s=self::technical($b,$live);
                    $history=mon_get(mon_get($p,'history_metadata'),$sid);
                    $complete=$history instanceof stdClass && mon_get($history,'complete_source_window')===true &&
                        mon_get($history,'bar_count')===$n && mon_get($history,'first_date')===$b[0][0] &&
                        mon_get($history,'last_date')===$last && mon_get($history,'price_basis')===mon_get($m,'adjustment') &&
                        hash_equals((string)mon_get($history,'bars_sha256',''),MonRun::digest($b));
                    $s->history_status=$complete?'complete_source_window':'unverified_window';
                    if(!$complete && $s->signal!==null){
                        $s->macd_status='needs_full_history';$s->macd_raw_kind=$s->macd_kind;
                        $s->macd_kind=null;$s->macd_cross_date=null;$s->macd_cross_age_days=null;
                        $s->qualifying_signals=array_values(array_filter($s->qualifying_signals,static function($key){return strpos($key,'MACD_')!==0;}));
                        $s->technical_qualified=count($s->qualifying_signals)>0;
                    }
                    foreach($s as $k=>$v)$r->$k=$v;
                    $r->bar_input_sha256=hash('sha256',MonRun::json((object)['bars'=>$bars,'benchmark_id'=>$benchId,'benchmark'=>$bb,'rules'=>self::rules()]));
                    $r->comparison_current=mon_get($periods[$country]??null,'current_period')===true && mon_get($periods[$country]??null,'verified_completed_date')===$last;
                    if(mon_get(mon_get(mon_get($p,'detail_imports'),$sid),'conflict')===true){$r->comparison_current=false;$r->reason_codes[]='detail_price_conflict';}
                    if(!$r->market_strong)$r->reason_codes[]='not_stronger_than_market';
                    if(!$r->technical_qualified)$r->reason_codes[]=in_array($r->macd_status,['needs_history','needs_full_history'],true)?'macd_history_missing_no_ma_reclaim':'no_swing_signal';
                    if(!$r->comparison_current)$r->reason_codes[]='latest_comparison_pending';
                    $r->eligible=$r->market_strong && $r->technical_qualified && $r->comparison_current;
                    $missingSignal=$r->market_strong && !$r->technical_qualified && in_array($r->macd_status,['needs_history','needs_full_history'],true);
                    $r->eligibility=$r->eligible?'pass':($r->comparison_current && !$missingSignal?'near':'unverified');
                }
            }catch(MonFault $e){$r->reason_codes[]=$e->getMessage();}
            $r->entry_plans=mon_get(mon_get($p,'detail_plans'),$sid,mon_get($previousSelection[$sid]??null,'entry_plans',mon_get(mon_get($p->final_results_details,$sid),'entry_plans')));
            $rows->$sid=$r;$full[$sid]=$r;$counts[$country][$r->eligibility]=($counts[$country][$r->eligibility]??0)+1;
            if($r->eligible)$rank[$country][]=$sid;
        }
        foreach($rank as $country=>&$ids){usort($ids,static function($x,$y)use($full){$a=$full[$x];$b=$full[$y];return $b->rs20_pp<=>$a->rs20_pp ?: $b->R20<=>$a->R20 ?: (mon_number($b->ADV20)??-1)<=> (mon_number($a->ADV20)??-1) ?: strcmp($x,$y);});foreach($ids as $i=>$sid)$rows->$sid->rank_hint=$i+1;}unset($ids);
        $cards=[];foreach($c->countries as $country)foreach(($rank[$country]??[])as $sid){$r=$rows->$sid;$cards[]=MonRun::pick($r,['symbol_id','name','country','eligibility','rank_hint','rs20_pp','R20','ADV20','qualifying_signals','bars_as_of','ma5_below_days_before_last','ma5','last_close','ma5_reclaim_live','ma5_projected_live','live_observation','macd_kind','macd_cross_date','macd_cross_age_days','histogram','macd_gap_atr','entry_plans','evidence_ids']);}
        ksort($full,SORT_STRING);$fingerprint=MonRun::digest((object)['criteria_version'=>self::CRITERIA,'rules'=>self::rules(),'candidates'=>array_values($full)]);
        return ['formula_version'=>self::FORMULA,'criteria_version'=>self::CRITERIA,'state_token'=>MonRun::token($d,$c),'prepared_fingerprint'=>$fingerprint,
            'as_of'=>$c->rendered_at,'candidate_count'=>count($audit),'counts'=>$counts,'rank_hint'=>$rank,'cards'=>$cards,
            'full_signals'=>$rows,'preparation'=>$periods,'auto_selected'=>false,'final_selector'=>'ChatGPT'];
    }
    public static function prepareView(array $prepared): array {
        $out=$prepared;unset($out['full_signals'],$out['cards']);$pages=MonPaging::pages($out,$prepared['cards']);
        $out['cards']=$pages['pages'][0]['cards'];$out['additional_card_pages']=array_map(static function($p){return $p['cards'];},array_slice($pages['pages'],1));
        $out['card_pages']=$pages['pages'];$out['artifact_cards']=$pages['artifact_cards'];
        $out['paging_note']='Read every card page and hash-verified artifact reference; same prepared_fingerprint and state_token';return $out;
    }
    public static function archive($d,$p): void {
        if(!(mon_get($p,'strategy_archive') instanceof stdClass))$p->strategy_archive=new stdClass();
        $key=$d->analysis->criteria_version.':'.$d->analysis->run_id;
        if(!property_exists($p->strategy_archive,$key))$p->strategy_archive->$key=(object)[
            'analysis'=>MonRun::pick($d->analysis,['criteria_version','run_id','as_of','selection_fingerprint','input_fingerprint','result_fingerprint','results','independent_results','candidate_audit','coverage','limitations','changes']),
            'selection_facts'=>$p->selection_facts,'input_facts'=>$p->input_facts,
            'final_results_details'=>$p->final_results_details,'independent_results'=>$p->independent_results,
            'swing_signals'=>mon_get($p,'swing_signals'),'swing_rules'=>mon_get($d->analysis->input_snapshot,'swing_rules')];
    }
    public static function finalize($base,$out,$p,$context,array $reasons): array {
        $a=$out->analysis;$a->selection_fingerprint=MonRun::digest($p->selection_facts);
        $p->input_facts->selection_fingerprint=$a->selection_fingerprint;$a->input_fingerprint=MonRun::digest($p->input_facts);
        $a->result_fingerprint=MonRun::digest(MonRun::business($p->final_results_details));
        $a->input_snapshot->compressed_fields=array_keys(get_object_vars($p));sort($a->input_snapshot->compressed_fields,SORT_STRING);
        // P3 uses PHP-native gzip so the NAS web path does not require an xz command.
        MonRun::releasePayloadCache();
        $a->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
        if(!MonRun::storage($out,false)->fits){MonRun::compactEvidence($out,$p);$a->input_snapshot->compressed_fields=array_keys(get_object_vars($p));sort($a->input_snapshot->compressed_fields,SORT_STRING);$a->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');}
        $extras=[];if(!MonRun::storage($out,false)->fits){$compact=MonGaps::compact($out,$out->analysis->as_of);$out=$compact['data'];$extras=$compact['dependency_files'];$p=MonRun::payload($out);$a=$out->analysis;}MonRun::validate($out);MonRun::verifyFacts($out,$p);$storage=MonRun::storage($out,true);
        if(MonRun::json($base->collection)!==MonRun::json($out->collection))throw new MonFault('MON_OWNERSHIP_INVALID');
        $text=MonRun::json($out);$summary=MonRun::render($out,$out->analysis->as_of,$reasons);
        return ['status'=>'changed','write_required'=>true,'files'=>array_merge([['path'=>'mon_data.json','content'=>$text,'blob_sha'=>MonRun::gitSha($text)],['path'=>'mon_result.md','content'=>$summary,'blob_sha'=>MonRun::gitSha($summary)]],$extras),
            'summary'=>$summary,'validation'=>['criteria_version'=>self::CRITERIA,'collection_preserved'=>true,'payload_hash_verified'=>true,'selection_fingerprint'=>$a->selection_fingerprint,'input_fingerprint'=>$a->input_fingerprint,'result_fingerprint'=>$a->result_fingerprint,'storage'=>$storage]];
    }
    public static function restoreHistory($p,$updates,$context): void {
        if($updates===null)return;
        if(!($updates instanceof stdClass) || count(get_object_vars($updates))>3000)throw new MonFault('MON_HISTORY_UPDATE_INVALID');
        if(!(mon_get($p,'history_metadata') instanceof stdClass))$p->history_metadata=new stdClass();
        foreach($updates as $sid=>$u){
            MonRun::identity($sid);$m=mon_get($p->candidate_metrics,$sid);$old=self::bars(mon_get($p->series,$sid,[]));
            if(!($m instanceof stdClass) || !$old || !($u instanceof stdClass) || mon_get($u,'price_basis')!==mon_get($m,'adjustment') ||
                !is_string(mon_get($u,'source_url')) || !preg_match('~^https://[^\s]+$~',$u->source_url) ||
                !preg_match('/^[a-f0-9]{64}$/',(string)mon_get($u,'source_record_sha256','')) || mon_get($u,'provider_window')!=='6mo')throw new MonFault('MON_HISTORY_UPDATE_INVALID');
            $checked=MonRun::utc(mon_get($u,'checked_at'));
            if(MonRun::seconds($checked)>MonRun::seconds($context->rendered_at)+5)throw new MonFault('MON_HISTORY_TIME_INVALID');
            $bars=self::bars(mon_get($u,'bars',[]));$n=count($bars);
            if($n<count($old) || !$bars || $bars[0][0]>$old[0][0] || $bars[$n-1][0]!==$old[count($old)-1][0])throw new MonFault('MON_HISTORY_WINDOW_INVALID');
            $map=[];foreach($bars as $bar)$map[$bar[0]]=$bar;
            foreach($old as $bar)if(!isset($map[$bar[0]]) || MonRun::json($map[$bar[0]])!==MonRun::json($bar))throw new MonFault('MON_HISTORY_SOURCE_CONFLICT');
            $p->series->$sid=$bars;$m->bars_count=$n;
            $p->history_metadata->$sid=(object)['complete_source_window'=>true,'provider_window'=>'6mo_plus_verified_tail',
                'first_date'=>$bars[0][0],'last_date'=>$bars[$n-1][0],'bar_count'=>$n,'bars_sha256'=>MonRun::digest($bars),
                'price_basis'=>$u->price_basis,'source_url'=>$u->source_url,'checked_at'=>$checked,
                'source_record_sha256'=>$u->source_record_sha256,'original_first_date'=>$old[0][0],'original_bar_count'=>count($old)];
        }
    }
    public static function migrate($d,$context,$historyUpdates=null): array {
        if($historyUpdates!==null && !($historyUpdates instanceof stdClass))throw new MonFault('MON_HISTORY_UPDATE_INVALID');
        $c=MonRun::context($context);MonRun::validate($d);$p=MonRun::payload($d);MonRun::verifyFacts($d,$p);
        if($d->analysis->criteria_version===self::CRITERIA && ($historyUpdates===null || count(get_object_vars($historyUpdates))===0))
            return ['status'=>'no_change','write_required'=>false,'files'=>[],'prepared'=>self::prepareView(self::prepare($d,$context))];
        self::archive($d,$p);self::restoreHistory($p,$historyUpdates,$c);
        $working=mon_decode(MonRun::json($d));$working->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
        $prepared=self::prepare($working,$context);$out=mon_decode(MonRun::json($d));$a=$out->analysis;
        $out->watchlist->criteria_version=self::CRITERIA;$out->watchlist->settings->investment_style='swing';$out->watchlist->settings->swing_profile=self::rules();
        if(!(mon_get($out->watchlist->settings,'analysis_storage_policy')instanceof stdClass))$out->watchlist->settings->analysis_storage_policy=new stdClass();
        $out->watchlist->settings->analysis_storage_policy->max_bytes=MonRun::MAX_SOURCE;
        $out->watchlist->watchlist_version++;$out->watchlist->updated_at=$c->rendered_at;$out->watchlist->run_id='mon-swing-migrate-'.gmdate('YmdHis',(int)MonRun::seconds($c->rendered_at));
        foreach($out->watchlist->symbols as $row)if(!$row->is_held)$row->purpose='스윙 후보 관찰';
        $a->criteria_version=self::CRITERIA;$a->as_of=$c->rendered_at;$a->analyzed_at=$c->rendered_at;$a->run_id=$out->watchlist->run_id;
        $a->watchlist_version=$out->watchlist->watchlist_version;$a->collection_id=$d->collection->collection_id;$a->status='partial';
        $oldFull=$p->final_results_details;$p->swing_signals=MonRun::signalMap($prepared['full_signals']);$p->final_results_details=new stdClass();$p->independent_results=new stdClass();
        $a->results=[];$a->independent_results=[];$audit=[];
        foreach($out->watchlist->symbols as $wr)if($wr->is_held && property_exists($oldFull,$wr->symbol_id)){
            $held=mon_decode(MonRun::json($oldFull->{$wr->symbol_id}));if($held->action==='BUY_REVIEW')$held->action='HOLD';
            $p->final_results_details->{$wr->symbol_id}=$held;$a->results[]=$held;
        }
        foreach($a->candidate_audit as $row){$s=$p->swing_signals->{$row->symbol_id};$row->previous_eligibility=$row->eligibility;$row->eligibility=$s->eligibility;$row->rank=$s->rank_hint;$row->failed_checks=$s->reason_codes;$row->swing_signal_ref='swing_signals:'.$row->symbol_id;$audit[]=$row;}$a->candidate_audit=$audit;
        $a->swing_preparation=(object)['formula_version'=>self::FORMULA,'prepared_at'=>$c->rendered_at,'prepared_fingerprint'=>$prepared['prepared_fingerprint'],'candidate_count'=>$prepared['candidate_count'],'counts'=>$prepared['counts'],'final_selector'=>'ChatGPT','selection_status'=>'awaiting_autonomous_selection'];
        foreach($a->coverage as $row){$row->buy_review_count=0;$row->ready_count=0;$row->observed_count=0;$row->price_verified_count=0;$row->pass_count=$prepared['counts'][$row->country]['pass']??0;$row->near_count=$prepared['counts'][$row->country]['near']??0;$row->unverified_count=$prepared['counts'][$row->country]['unverified']??0;$row->fail_count=$prepared['counts'][$row->country]['fail']??0;$row->reason=self::CRITERIA.' MACD(12,26,9) 계산; ChatGPT 자율 최종선정 대기';}
        $p->selection_facts=(object)['criteria_version'=>self::CRITERIA,'rules'=>self::rules(),'candidates_ref'=>'swing_signals','candidate_facts_sha256'=>MonRun::digest(array_values(get_object_vars($p->swing_signals))),'selected'=>[], 'positions'=>mon_get($p->selection_facts,'positions',[]),'corporate_risk'=>mon_get($p->selection_facts,'corporate_risk',new stdClass())];
        $p->input_facts=(object)['criteria_version'=>self::CRITERIA,'as_of'=>$a->as_of,'selection_fingerprint'=>'','collection_id'=>$a->collection_id,'collection_status'=>$d->collection->status,'used_prices'=>[],'corporate_risk_status'=>'needs_check','trading_status_check'=>(object)['status'=>'needs_check']];
        $a->input_snapshot->swing_rules=self::rules();$a->input_snapshot->mon_input_state=(object)['run_contract'=>MonRun::CONTRACT,'run_mode'=>'swing_migration','preparation_running'=>false,'selection_status'=>'awaiting_autonomous_selection'];
        $a->limitations=['새 기준의 수치 자격을 계산했으며 ChatGPT의 최종 자율 선정은 대기 중','실시간 가격·거래 가능 여부·개별 기업 위험 확인 필요','미국10/9 최신 지수 비교 미완료; 이전 추천은 새 추천으로 승계하지 않음'];$a->limitations_as_of=$a->as_of;
        $a->changes[]=(object)['at'=>$a->as_of,'kind'=>'swing_criteria_migration','from'=>$d->analysis->criteria_version,'to'=>self::CRITERIA,'macd'=>[12,26,9],'old_buy_reviews_archived'=>true,'restored_history_count'=>count(get_object_vars(mon_get($p,'history_metadata',new stdClass()))),'watchlist_version_before'=>$d->watchlist->watchlist_version,'watchlist_version_after'=>$out->watchlist->watchlist_version];
        $a->display=(object)['analysis_as_of'=>$a->as_of,'rendered_at'=>$a->as_of,'analysis_recomputed'=>true,'selection_recomputed'=>false];
        return self::finalize($d,$out,$p,$c,['MACD(12,26,9) 적용; 이전 추천은 이력으로 보존하고 새 추천 확정 대기']);
    }
    public static function selectData($d,$decision,?array $prepared=null): array {
        if(!($decision instanceof stdClass))throw new MonFault('MON_SWING_DECISION_INVALID');
        $c=MonRun::context(mon_get($decision,'view_context'));$fixed=MonRun::utc(mon_get($decision,'fixed_as_of'));
        if(MonRun::seconds($fixed)<MonRun::seconds($c->rendered_at) || MonRun::seconds($fixed)<MonRun::seconds($d->analysis->as_of))throw new MonFault('MON_PATCH_AS_OF_INVALID');
        if($d->analysis->criteria_version!==self::CRITERIA)throw new MonFault('MON_SWING_MIGRATION_REQUIRED');
        if($prepared===null)$prepared=self::prepare($d,$decision->view_context,mon_get($decision,'live_observations',[]));
        elseif(!hash_equals((string)mon_get($prepared,'cache_key'),MonPreparation::key($d,$c,mon_get($decision,'live_observations',[]))))throw new MonFault('MON_PREPARED_SOURCE_INVALID');
        if(!hash_equals($prepared['state_token'],(string)mon_get($decision,'state_token','')) || !hash_equals($prepared['prepared_fingerprint'],(string)mon_get($decision,'prepared_fingerprint','')))throw new MonFault('MON_STATE_TOKEN_INVALID');
        $selected=mon_get($decision,'selected');if(!is_array($selected))throw new MonFault('MON_SWING_DECISION_INVALID');
        $ids=[];$perCountry=[];
        foreach($selected as $r){$sid=mon_get($r,'symbol_id');MonRun::identity($sid);$s=mon_get($prepared['full_signals'],$sid);
            if(isset($ids[$sid]) || !($s instanceof stdClass) || !$s->eligible || !is_string(mon_get($r,'reason')) || trim($r->reason)==='')throw new MonFault('MON_SWING_SELECTION_NOT_ELIGIBLE');
            $country=explode('|',$sid)[0];if(!in_array($country,$c->countries,true))throw new MonFault('MON_SELECTION_SCOPE_INVALID');
            $before=MonDetails::target($d->watchlist,$country,(int)MonRun::seconds($c->rendered_at));$after=MonDetails::target($d->watchlist,$country,(int)MonRun::seconds($fixed));
            if($before!==$after)throw new MonFault('MON_PERIOD_CHANGED_PREPARATION_REQUIRED');
            $perCountry[$country]=($perCountry[$country]??0)+1;if($perCountry[$country]>3)throw new MonFault('MON_RESULT_LIMIT_INVALID');$ids[$sid]=$r;}
        $p=MonRun::payload($d);self::archive($d,$p);$out=mon_decode(MonRun::json($d));$a=$out->analysis;$watch=MonRun::rows($out->watchlist->symbols);$oldFull=$p->final_results_details;
        $p->swing_signals=MonRun::signalMap($prepared['full_signals']);$p->final_results_details=new stdClass();$p->independent_results=new stdClass();$a->results=[];$a->independent_results=[];
        // Preserve all registered holdings, whether or not they qualify for a new entry.
        foreach($watch as $sid=>$wr)if($wr->is_held && property_exists($oldFull,$sid)){$p->final_results_details->$sid=$oldFull->$sid;$a->results[]=$oldFull->$sid;}
        $selectedFacts=[];$usedPrices=[];$positions=mon_get($p->selection_facts,'positions',[]);$corporate=mon_get($p->selection_facts,'corporate_risk',new stdClass());
        foreach($ids as $sid=>$decisionRow){$s=$p->swing_signals->$sid;if(isset($watch[$sid]) && $watch[$sid]->is_held)throw new MonFault('MON_HELD_DUPLICATE_ENTRY');
            if(!isset($watch[$sid])){
                $metadata=mon_get($decisionRow,'watch_symbol');
                if(!($metadata instanceof stdClass)||mon_get($metadata,'symbol_id')!==$sid||mon_get($metadata,'is_held')!==false)throw new MonFault('MON_SWING_SYMBOL_MAPPING_PREPARATION_REQUIRED');
                $metadata=mon_decode(MonRun::json($metadata));$metadata->purpose='스윙 매입 검토';$out->watchlist->symbols[]=$metadata;$watch[$sid]=$metadata;
            }
            $wr=$watch[$sid];$r=(object)['symbol_id'=>$sid,'country'=>$wr->country,'name'=>$wr->name,'currency'=>$wr->currency,'rank'=>$s->rank_hint,
                'action'=>'BUY_REVIEW','base_action'=>'BUY_REVIEW','readiness'=>'conditional','eligibility'=>'pass','swing_signal'=>$s,
                'entry_plans'=>$s->entry_plans,'active_plan'=>null,'entry_zone'=>null,'invalidation'=>null,
                'price'=>$s->last_close,'price_type'=>'close','price_as_of'=>self::closeTime($out,$wr->country,$s->bars_as_of),
                'price_checks'=>(object)['action_price_valid'=>false,'delay_seconds'=>null,'market_date'=>$s->bars_as_of,'timestamp_basis'=>'close','session_at_analysis'=>'closed'],
                'risk_checks'=>(object)['current_corporate_risk_verified'=>false,'current_trading_status_verified'=>false],
                'plan_valid_until'=>self::nextClose($out,$wr->country,$c->rendered_at),'reason'=>$decisionRow->reason,
                'evidence_ids'=>$s->evidence_ids,'autonomous_decision'=>(object)['selector'=>'ChatGPT','decided_at'=>$fixed,'reason'=>$decisionRow->reason],
                'metrics'=>(object)['RS20_pp'=>$s->rs20_pp,'R20'=>$s->R20],'next_check'=>'현재 가격·스윙 신호 유지·거래·기업 위험 확인'];
            if($s->ma5_reclaim_live && $s->live_observation instanceof stdClass){
                $live=$s->live_observation;$r->price=$live->price;$r->price_type=$live->price_type;$r->price_as_of=$live->quote_at;
                $r->price_checks=mon_decode(MonRun::json($live->price_checks));$r->price_checks->action_price_valid=MonRun::quoteState($r,$out->watchlist,$fixed)->valid;
                $r->evidence_ids=array_values(array_unique(array_merge($r->evidence_ids,$live->evidence_ids)));
            }
            $usedPrices[]=MonRun::pick($r,['symbol_id','price','price_type','price_as_of','price_checks','risk_checks','evidence_ids']);
            $p->final_results_details->$sid=$r;$p->independent_results->$sid=$r;$a->results[]=$r;$a->independent_results[]=(object)['symbol_id'=>$sid,'rank'=>$r->rank,'action'=>$r->action,'readiness'=>$r->readiness,'result_ref'=>'independent_results:'.$sid];
            $wr->purpose='스윙 매입 검토';$selectedFacts[]=(object)['symbol_id'=>$sid,'reason'=>$decisionRow->reason,'selector'=>'ChatGPT'];
        }
        $a->as_of=$fixed;$a->analyzed_at=$fixed;$a->run_id='mon-swing-'.gmdate('YmdHis',(int)MonRun::seconds($fixed));$a->status='partial';$a->collection_id=$d->collection->collection_id;
        foreach($out->watchlist->symbols as $wr)if(!$wr->is_held && !isset($ids[$wr->symbol_id]))$wr->purpose='스윙 후보 관찰';
        $out->watchlist->updated_at=$fixed;$out->watchlist->run_id=$a->run_id;$out->watchlist->watchlist_version++;$a->watchlist_version=$out->watchlist->watchlist_version;
        $ordered=$out->watchlist->symbols;usort($ordered,static function($x,$y)use($ids){return (isset($ids[$y->symbol_id])<=>isset($ids[$x->symbol_id])) ?: strcmp($x->symbol_id,$y->symbol_id);});
        $observed=[];$kept=[];foreach($ordered as $wr){$country=$wr->country;if($wr->is_held){$kept[]=$wr;continue;}if(($observed[$country]??0)<10){$kept[]=$wr;$observed[$country]=($observed[$country]??0)+1;}}
        $out->watchlist->symbols=$kept;
        $p->selection_facts=(object)['criteria_version'=>self::CRITERIA,'rules'=>self::rules(),'candidates_ref'=>'swing_signals','candidate_facts_sha256'=>MonRun::digest(array_values(get_object_vars($p->swing_signals))),'selected'=>$selectedFacts,'positions'=>$positions,'corporate_risk'=>$corporate];
        $p->input_facts=(object)['criteria_version'=>self::CRITERIA,'as_of'=>$fixed,'selection_fingerprint'=>'','collection_id'=>$a->collection_id,'collection_status'=>$d->collection->status,'used_prices'=>$usedPrices,'corporate_risk_status'=>'needs_check','trading_status_check'=>(object)['status'=>'needs_check']];
        foreach($a->candidate_audit as $row){$s=$p->swing_signals->{$row->symbol_id};$row->eligibility=$s->eligibility;$row->rank=$s->rank_hint;$row->failed_checks=$s->reason_codes;$row->swing_signal_ref='swing_signals:'.$row->symbol_id;}
        foreach($a->coverage as $r){$r->buy_review_count=$perCountry[$r->country]??0;$r->ready_count=0;
            foreach(['pass','near','unverified','fail']as $status)$r->{$status.'_count'}=$prepared['counts'][$r->country][$status]??0;
            $r->observed_count=$observed[$r->country]??0;$r->price_verified_count=0;
            foreach($a->results as $row)if($row->country===$r->country && mon_get(mon_get($row,'price_checks'),'action_price_valid')===true)$r->price_verified_count++;
            $r->reason='ChatGPT 자율 스윙 선정; 현재 진입 확인은 별도';}
        $a->swing_preparation=(object)['formula_version'=>self::FORMULA,'prepared_at'=>$c->rendered_at,'prepared_fingerprint'=>$prepared['prepared_fingerprint'],'candidate_count'=>$prepared['candidate_count'],'counts'=>$prepared['counts'],'selection_status'=>'autonomous_selection_completed','selector'=>'ChatGPT'];
        $a->limitations=['현재 가격·거래·기업 위험 미확인으로 조건부','MACD 골든 직전은 확정 골든크로스가 아닌 근접 신호'];$a->limitations_as_of=$fixed;
        $a->input_snapshot->mon_input_state=(object)['run_contract'=>MonRun::CONTRACT,'run_mode'=>'swing_select','preparation_running'=>false];
        $a->changes[]=(object)['at'=>$fixed,'kind'=>'autonomous_swing_selection','selected_symbol_ids'=>array_keys($ids),'selector'=>'ChatGPT'];
        return ['data'=>$out,'payload'=>$p,'context'=>$c,'prepared'=>$prepared];
    }
    public static function select($d,$decision): array {
        $bundle=self::selectData($d,$decision);
        return self::finalize($d,$bundle['data'],$bundle['payload'],$bundle['context'],['ChatGPT 자율 스윙 선정; 현재 진입 확인은 별도']);
    }
    public static function closeTime($d,string $country,string $date): ?string {
        $closes=[];foreach(mon_get($d->watchlist->calendar,'markets',[])as $m)if(mon_get($m,'country')===$country)foreach(mon_get($m,'sessions',[])as $s)if(mon_get($s,'market_date')===$date)$closes[]=mon_get($s,'close_at');
        if(!$closes)return null;sort($closes,SORT_STRING);return MonRun::utc(end($closes));
    }
    public static function nextClose($d,string $country,string $at): ?string {
        $days=[];foreach(mon_get($d->watchlist->calendar,'markets',[])as $m)if(mon_get($m,'country')===$country)foreach(mon_get($m,'sessions',[])as $s){$date=mon_get($s,'market_date');$close=mon_get($s,'close_at');if(is_string($date)&&is_string($close))$days[$date]=max($days[$date]??0,MonRun::seconds($close));}
        asort($days,SORT_NUMERIC);foreach($days as $close)if($close>=MonRun::seconds($at))return mon_iso((int)$close);return null;
    }
}


function mon_project_for_mon($data, $context): array {
    MonRun::validate($data); $c = MonRun::context($context); $a = $data->analysis;
    $audit = MonRun::rows($a->candidate_audit); $results = MonRun::rows($a->results); $watch = MonRun::rows($data->watchlist->symbols);
    $coverage = []; $markets = MonRun::periods($data, $c->rendered_at); $discovery = []; $pool = []; $events = []; $aux = MonRun::auxiliary($data, $c->rendered_at);
    $corporate = mon_get(mon_get($a, 'input_snapshot'), 'corporate_risk');
    foreach (['confirmed_current_material_adverse', 'pending_items'] as $kind) foreach (mon_get($corporate, $kind, []) as $r) {
        $sid = mon_get($r, 'symbol_id'); MonRun::identity($sid); $events[$sid][] = MonRun::pick($r, ['risk_kind', 'event_date', 'company_primary_confirmation', 'production_recovery_verified']);
    }
    foreach ($a->coverage as $r) $coverage[mon_get($r, 'country', '')] = $r;
    foreach (mon_get(mon_get($a, 'discovery_state'), 'countries', []) as $r) $discovery[] = MonRun::pick($r, ['country', 'market_date', 'completed_bar_date', 'daily_target', 'new_ids_identified_today', 'daily_goal_met']);
    foreach ($audit as $sid => $r) {
        $country = explode('|', $sid)[0];
        if (!isset($pool[$country])) $pool[$country] = ['total' => 0, 'pass' => 0, 'near' => 0, 'unverified' => 0, 'fail' => 0, 'other' => 0];
        $pool[$country]['total']++; $eligibility = mon_get($r, 'eligibility', 'other');
        if (!in_array($eligibility, ['pass', 'near', 'unverified', 'fail'], true)) $eligibility = 'other'; $pool[$country][$eligibility]++;
    }
    $cards = []; $counts = []; $pending = []; $prep = []; $competition = [];
    foreach ($c->countries as $country) {
        $m = $markets[$country] ?? new stdClass(); $v = $coverage[$country] ?? new stdClass();
        $prep[] = $m;
        $comp = MonRun::pick($v, ['country', 'comparable_count', 'previous_comparable_count', 'status']);
        if (mon_get($m, 'current_period') !== true) { $comp->previous_comparable_count = max((int)mon_get($comp, 'previous_comparable_count', 0), (int)mon_get($comp, 'comparable_count', 0)); $comp->comparable_count = 0; }
        $comp->pool = $pool[$country] ?? ['total' => 0]; $competition[] = $comp;
        if (mon_get($m, 'current_period') !== true) $pending[] = (object)['country' => $country, 'kind' => mon_get($m, 'comparison_status') === 'pending_analysis' ? 'analysis' : (mon_get($m, 'comparison_status') === 'pending_benchmark' ? 'benchmark' : 'completed_period'), 'target_date' => mon_get($m, 'target_completed_date'), 'status' => 'preparation_required', 'ranking_scope' => 'previous_reference'];
    }
    foreach ($results as $sid => $r) {
        $country = explode('|', $sid)[0]; $held = mon_get($watch[$sid] ?? null, 'is_held') === true; $action = mon_get($r, 'action');
        $event = isset($events[$sid]) || mon_get(mon_get($r, 'risk_checks'), 'confirmed_material_adverse') === true || in_array($sid, $c->important_ids, true);
        $include = $held || $action === 'SELL_REVIEW' || $event || ($action === 'BUY_REVIEW' && in_array($country, $c->countries, true));
        if (!$include) continue;
        if (!$held && $action === 'BUY_REVIEW') { $counts[$country] = ($counts[$country] ?? 0) + 1; if ($counts[$country] > 3) throw new MonFault('MON_RESULT_LIMIT_INVALID'); }
        $card = MonRun::pick($r, ['symbol_id', 'country', 'name', 'currency', 'rank', 'action', 'eligibility', 'entry_plans', 'active_plan', 'plan_valid_until', 'price', 'price_as_of', 'price_type', 'risk_checks', 'details_ref', 'swing_signal', 'autonomous_decision']);
        $card->name=mon_get($watch[$sid]??null,'name',explode('|',$sid)[2].' · 이름 미확인');
        $card->evidence_count = count(mon_get($r, 'evidence_ids', []));
        $card->metrics = MonRun::pick(mon_get($r, 'metrics'), ['R20', 'RS20_pp']);
        $card->readiness_at_analysis = mon_get($r, 'readiness'); $card->held = $held;
        if (isset($aux['usable'][$sid])) $card->php_auxiliary = $aux['usable'][$sid];
        if ($held) $card->position = mon_get($watch[$sid], 'position');
        if (isset($events[$sid])) $card->events = $events[$sid];
        $card->ranking_scope = mon_get($markets[$country] ?? null, 'current_period') === true ? 'stored_completed_period' : 'previous_reference';
        $now = MonRun::current($r, $data->watchlist, $c->rendered_at, $card->ranking_scope !== 'previous_reference');
        $codes = []; foreach ($now->blockers as $blocker) { $code = array_search($blocker, MonRun::BLOCKERS, true); if ($code === false) throw new MonFault('MON_BLOCKER_INVALID'); $codes[] = $code; }
        $card->current = (object)['readiness' => $now->readiness, 'price_valid' => $now->quote->valid, 'age_seconds' => $now->quote->age_seconds, 'delay_seconds' => $now->quote->delay_seconds, 'session' => $now->quote->session, 'blocker_ids' => $codes]; $cards[$sid] = $card;
    }
    // Do not hide a held position or important failed candidate merely because it has no result row.
    foreach ($watch as $sid => $r) if (mon_get($r, 'is_held') === true && !isset($cards[$sid])) $cards[$sid] = (object)['symbol_id' => $sid, 'country' => $r->country, 'name' => $r->name, 'held' => true, 'position' => $r->position, 'status' => 'held_analysis_missing'];
    foreach ($audit as $sid => $r) if (!isset($cards[$sid]) && (isset($events[$sid]) || in_array($sid, $c->important_ids, true) || mon_get($r, 'eligibility') === 'fail')) $cards[$sid] = (object)['symbol_id' => $sid, 'eligibility' => mon_get($r, 'eligibility'), 'evidence_ids' => mon_get($r, 'evidence_ids', []), 'events' => $events[$sid] ?? [], 'details_ref' => 'candidate_audit:' . $sid, 'status' => 'candidate_needs_review'];
    foreach ($events as $sid => $items) if (!isset($cards[$sid])) $cards[$sid] = (object)['symbol_id' => $sid, 'events' => $items, 'status' => 'event_analysis_missing', 'preparation_required' => true];
    foreach ($c->important_ids as $sid) if (!isset($cards[$sid])) $cards[$sid] = (object)['symbol_id' => $sid, 'status' => 'candidate_not_in_source', 'preparation_required' => true];
    ksort($cards, SORT_STRING); $cards = array_values($cards); $token = MonRun::token($data, $c);
    $view = ['header' => ['run_contract' => MonRun::CONTRACT, 'processor_version' => MON_VERSION, 'schema_version' => 3, 'criteria_version' => $a->criteria_version, 'source_blob_sha' => $c->source_blob_sha, 'source_run_id' => $a->run_id, 'analysis_as_of' => $a->as_of, 'rendered_at' => $c->rendered_at, 'selection_fingerprint' => $a->selection_fingerprint, 'input_fingerprint' => $a->input_fingerprint, 'result_fingerprint' => $a->result_fingerprint, 'blocker_keys' => MonRun::BLOCKERS],
        'preparation' => $prep, 'competition' => ['full_pool_count' => count($audit), 'audit_sha256' => hash('sha256', MonRun::json($a->candidate_audit)), 'payload_hash_verified' => false, 'ranking_recomputed' => false, 'countries' => $competition],
        'cards' => [], 'changes' => ['last_change_ref' => 'analysis.changes:' . max(0, count($a->changes) - 1)], 'discovery' => $discovery,
        'pending' => ['groups' => $pending, 'limitations' => mon_get($a, 'limitations', []), 'retry_queue_count' => count(mon_get(mon_get($a, 'discovery_state'), 'retry_queue', [])), 'retry_queue_ref' => 'analysis.discovery_state.retry_queue'],
        'persistence' => ['collection_id' => $data->collection->collection_id, 'collection_watchlist_version' => $data->collection->watchlist_version, 'analysis_used_collection_id' => $a->collection_id, 'watchlist_version' => $data->watchlist->watchlist_version, 'php_auxiliary_quality' => $aux['counts'], 'php_auxiliary_watchlist_match' => $aux['watchlist_version_match'], 'write_required' => false, 'legacy_xz_available' => MonRun::xzBinary() !== null],
        'page' => ['cursor' => 0, 'next_cursor' => null, 'total_cards' => count($cards), 'remaining_cards' => count($cards)]];
    $cursor = mon_get($context, 'cursor', 0);
    if (!is_int($cursor) || $cursor < 0 || $cursor > count($cards)) throw new MonFault('MON_CURSOR_INVALID');
    if ($cursor > 0 && !hash_equals($token, (string)mon_get($context, 'state_token', ''))) throw new MonFault('MON_STATE_TOKEN_INVALID');
    $view['page']['cursor'] = $cursor; $i = $cursor;
    if ($cursor > 0) $view = ['header' => ['run_contract' => MonRun::CONTRACT, 'processor_version' => MON_VERSION, 'source_blob_sha' => $c->source_blob_sha, 'state_token' => $token, 'shared_fields_ref' => 'first_page'], 'cards' => [], 'page' => $view['page']];
    $artifacts=new stdClass();
    if(strlen(MonRun::json((object)$view))>MonRun::VIEW_BYTES-1024){
        $raw=MonRun::json((object)$view);$sha=hash('sha256',$raw);$artifacts->$sha=(object)$view;
        $view=['header'=>['run_contract'=>MonRun::CONTRACT,'processor_version'=>MON_VERSION,'source_blob_sha'=>$c->source_blob_sha,'state_token'=>$token,'shared_fields_artifact_sha256'=>$sha],
            'cards'=>[],'page'=>$view['page']];
    }
    for (; $i < count($cards); $i++) {
        $card=$cards[$i];$test=$view;$test['cards'][]=$card;
        if(strlen(MonRun::json((object)$test))>MonRun::VIEW_BYTES-256){
            if($view['cards'])break;
            $raw=MonRun::json($card);$sha=hash('sha256',$raw);$artifacts->$sha=$card;
            $card=(object)['symbol_id'=>mon_get($card,'symbol_id'),'card_kind'=>'artifact_reference','artifact_sha256'=>$sha,'bytes'=>strlen($raw),'content_ref'=>'artifact_cards:'.$sha];
        }
        $view['cards'][] = $card; $view['page']['remaining_cards'] = count($cards) - $i - 1; $view['page']['next_cursor'] = $i + 1 < count($cards) ? $i + 1 : null;
    }
    $view['page']['remaining_cards'] = count($cards) - $i; $view['page']['next_cursor'] = $i < count($cards) ? $i : null;
    $bytes = strlen(MonRun::json((object)$view));
    if ($bytes > MonRun::VIEW_BYTES || (!$view['cards'] && $i < count($cards))) throw new MonFault('MON_VIEW_ITEM_TOO_LARGE');
    return ['state_token' => $token, 'model_input_bytes' => $bytes, 'view' => $view,'artifact_cards'=>$artifacts];
}

function mon_apply_mon_patch($data, $patch): array {
    MonRun::validate($data);
    if (!($patch instanceof stdClass)) throw new MonFault('MON_PATCH_INVALID');
    foreach (['run_mode', 'base_source_blob_sha', 'base_analysis_run_id', 'fixed_as_of', 'changes', 'new_evidence', 'pending', 'short_reasons', 'view_context', 'state_token'] as $k) if (!property_exists($patch, $k)) throw new MonFault('MON_PATCH_FIELD_MISSING');
    $allowed = ['run_mode', 'base_source_blob_sha', 'base_analysis_run_id', 'fixed_as_of', 'changes', 'new_evidence', 'pending', 'short_reasons', 'view_context', 'state_token'];
    foreach ($patch as $k => $v) if (!in_array($k, $allowed, true)) throw new MonFault('MON_PATCH_FIELD_INVALID');
    $c = MonRun::context($patch->view_context);
    if ($patch->base_source_blob_sha !== $c->source_blob_sha || $patch->base_analysis_run_id !== $data->analysis->run_id) throw new MonFault('MON_ANALYSIS_SOURCE_CONFLICT');
    if (!is_string($patch->state_token) || !hash_equals(MonRun::token($data, $c), $patch->state_token)) throw new MonFault('MON_STATE_TOKEN_INVALID');
    foreach (['changes', 'new_evidence', 'pending', 'short_reasons'] as $k) if (!is_array($patch->$k)) throw new MonFault('MON_PATCH_INVALID');
    if (count($patch->changes) > 1000 || count($patch->new_evidence) > 1000 || count($patch->pending) > 1000 || count($patch->short_reasons) > 3) throw new MonFault('MON_PATCH_INVALID');
    foreach ($patch->short_reasons as $reason) if (!is_string($reason) || strlen($reason) > 1000) throw new MonFault('MON_PATCH_INVALID');
    $fixed = MonRun::utc($patch->fixed_as_of);
    if ($patch->run_mode === 'reuse') {
        if ($fixed !== MonRun::utc($data->analysis->as_of) || $patch->changes || $patch->new_evidence || $patch->pending || $patch->short_reasons) throw new MonFault('MON_REUSE_HAS_CHANGES');
        return ['status' => 'no_change', 'write_required' => false, 'files' => [], 'summary' => MonRun::render($data, $c->rendered_at), 'validation' => ['analysis_as_of_preserved' => true, 'collection_preserved' => true, 'payload_unchanged' => true, 'payload_hash_verified' => false, 'selection_reused' => true]];
    }
    if ($patch->run_mode !== 'evaluate') throw new MonFault('MON_PATCH_MODE_INVALID');
    if (MonRun::seconds($fixed) <= MonRun::seconds($data->analysis->as_of) || MonRun::seconds($fixed) < MonRun::seconds($c->rendered_at)) throw new MonFault('MON_PATCH_AS_OF_INVALID');
    $payload = MonRun::payload($data); MonRun::verifyFacts($data, $payload);
    $out = mon_decode(MonRun::json($data)); $a = $out->analysis; $watch = MonRun::rows($out->watchlist->symbols);
    $evidence = MonRun::evidence($data, $payload);
    if (!(mon_get($payload, 'evidence_archive') instanceof stdClass)) throw new MonFault('MON_PAYLOAD_FACTS_MISSING');
    foreach ($payload->evidence_archive as $id => $e) if (!isset($evidence[$id])) $evidence[$id] = $e;
    $newEvidence = [];
    foreach ($patch->new_evidence as $e) {
        $id = mon_get($e, 'id'); $url = mon_get($e, 'source_url');
        if (!($e instanceof stdClass) || !is_string($id) || !preg_match('/^[A-Za-z0-9._:-]{1,180}$/', $id) || !is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || !parse_url($url, PHP_URL_HOST) || mon_get($e, 'source_kind') !== 'independent' || !is_string(mon_get($e, 'claim')) || trim($e->claim) === '') throw new MonFault('MON_EVIDENCE_INVALID');
        $checked = MonRun::seconds(mon_get($e, 'checked_at')); $observed = MonRun::seconds($fixed);
        if ($checked > $observed + 5 || $checked < MonRun::seconds($data->analysis->as_of)) throw new MonFault('MON_EVIDENCE_TIME_INVALID');
        if (isset($evidence[$id]) && MonRun::json($evidence[$id]) !== MonRun::json($e)) throw new MonFault('MON_EVIDENCE_ID_CONFLICT');
        if (isset($newEvidence[$id])) throw new MonFault('MON_EVIDENCE_ID_CONFLICT'); $newEvidence[$id] = $e;
    }
    $requireNewEvidence = static function ($ids) use ($newEvidence) {
        if (!is_array($ids) || !$ids) throw new MonFault('MON_EVIDENCE_MISSING');
        foreach ($ids as $id) if (!is_string($id) || !isset($newEvidence[$id])) throw new MonFault('MON_EVIDENCE_MISSING');
    };
    $changeIds = []; $archive = []; $full = $payload->final_results_details;
    foreach ($patch->changes as $change) {
        if (!($change instanceof stdClass)) throw new MonFault('MON_PATCH_INVALID');
        $sid = mon_get($change, 'symbol_id'); MonRun::identity($sid);
        if (isset($changeIds[$sid])) throw new MonFault('MON_SYMBOL_DUPLICATE'); $changeIds[$sid] = true;
        if (!isset($watch[$sid]) || !property_exists($full, $sid)) throw new MonFault('MON_PATCH_PREPARATION_REQUIRED');
        foreach ($change as $k => $v) if (!in_array($k, ['symbol_id', 'price_observation', 'risk_review', 'trading_review', 'reason', 'next_check'], true)) throw new MonFault('MON_PATCH_PREPARATION_REQUIRED');
        $r = $full->$sid; $wr = $watch[$sid]; $archive[$sid] = MonRun::pick($r, ['price', 'price_as_of', 'price_checks', 'risk_checks', 'risk_review', 'reason', 'next_check']);
        if (property_exists($change, 'price_observation')) {
            $p = $change->price_observation; $requireNewEvidence(mon_get($p, 'evidence_ids'));
            if (!($p instanceof stdClass) || mon_get($p, 'symbol_id') !== $sid || mon_get($p, 'currency') !== $wr->currency || mon_get($p, 'venue') !== $wr->exchange || mon_get($p, 'source_kind') !== 'independent') throw new MonFault('MON_PRICE_IDENTITY_INVALID');
            $price = mon_number(mon_get($p, 'price')); if ($price === null || $price <= 0) throw new MonFault('MON_PRICE_INVALID');
            $quoteAt = MonRun::utc(mon_get($p, 'quote_at')); $type = mon_get($p, 'price_type'); $basis = mon_get($p, 'timestamp_basis');
            if (!in_array($type, ['last', 'minute_close', 'close'], true) || !in_array($basis, ['trade', 'bar_end', 'close', 'unknown'], true)) throw new MonFault('MON_PRICE_INVALID');
            $delay = mon_get($p, 'delay_seconds'); if ($delay !== null && (mon_number($delay) === null || mon_number($delay) < 0)) throw new MonFault('MON_PRICE_INVALID');
            $r->price = (float)MonRun::decimal6($price); $r->price_as_of = $quoteAt; $r->price_type = $type;
            $r->price_checks = (object)['timestamp_basis' => $basis, 'delay_seconds' => $delay, 'delay_kind' => $delay === null ? 'unknown' : (mon_number($delay) === 0.0 ? 'realtime' : 'delayed'), 'session_at_analysis' => mon_get($p, 'session', 'unknown'), 'market_date' => mon_get($p, 'market_date'), 'source' => mon_get($p, 'source', 'independent')];
            $r->evidence_ids = array_values(array_unique(array_merge(mon_get($r, 'evidence_ids', []), $p->evidence_ids)));
            $payload->independent_price_observations[] = $p;
        }
        if (property_exists($change, 'risk_review')) {
            $review = $change->risk_review; $requireNewEvidence(mon_get($review, 'evidence_ids'));
            if (!($review instanceof stdClass) || !in_array(mon_get($review, 'status'), ['verified', 'needs_check'], true) || !in_array(mon_get($review, 'trading_status'), ['verified', 'needs_check', 'halted'], true) || !is_string(mon_get($review, 'scope')) || trim($review->scope) === '' || !is_array(mon_get($review, 'pending_items'))) throw new MonFault('MON_RISK_REVIEW_INVALID');
            if (MonRun::utc(mon_get($review, 'applies_as_of')) !== $fixed || MonRun::seconds(mon_get($review, 'checked_at')) > MonRun::seconds($fixed) + 5 || MonRun::seconds($review->checked_at) < MonRun::seconds($data->analysis->as_of)) throw new MonFault('MON_RISK_REVIEW_TIME_INVALID');
            if ($review->status === 'verified' && $review->pending_items) throw new MonFault('MON_RISK_REVIEW_INVALID');
            if ($review->trading_status === 'halted' || mon_get($review, 'confirmed_material_adverse') === true) throw new MonFault('MON_PATCH_PREPARATION_REQUIRED');
            $review->applies_as_of = $fixed; $r->risk_review = $review;
            $r->evidence_ids = array_values(array_unique(array_merge(mon_get($r, 'evidence_ids', []), $review->evidence_ids)));
        }
        MonTradingReview::apply($r,$data,$change,$fixed,$requireNewEvidence);
        foreach (['reason', 'next_check'] as $k) if (property_exists($change, $k)) {
            if (!is_string($change->$k) || strlen($change->$k) > 1000 || trim($change->$k) === '') throw new MonFault('MON_PATCH_INVALID'); $r->$k = $change->$k;
        }
    }
    if ($archive) {
        if (!(mon_get($payload, 'mon_run_archive') instanceof stdClass)) $payload->mon_run_archive = new stdClass();
        $payload->mon_run_archive->{MonRun::utc($data->analysis->as_of)} = (object)$archive;
    }
    foreach ($newEvidence as $id => $e) { $payload->evidence_archive->$id = $e; $a->evidence[] = $e; }
    $independent = new stdClass(); $slim = []; $used = []; $readyCount = []; $plainBefore = MonRun::rows($data->analysis->results); $periods = MonRun::periods($data, $fixed);
    $keys = array_keys(get_object_vars($full)); sort($keys, SORT_STRING);
    foreach ($keys as $sid) {
        $r = $full->$sid; $review = mon_get($r, 'risk_review', new stdClass());
        if (!isset($watch[$sid]) || mon_get($r, 'country') !== $watch[$sid]->country || mon_get($r, 'currency') !== $watch[$sid]->currency || !(mon_get($r, 'price_checks') instanceof stdClass)) throw new MonFault('MON_RESULT_IDENTITY_INVALID');
        MonRiskState::updateChecks($r,$fixed);
        $now = MonRun::current($r, $out->watchlist, $fixed, mon_get($periods[$r->country] ?? null, 'current_period', false)); $r->price_checks->action_price_valid = $now->quote->valid;
        $r->price_checks->age_seconds = $now->quote->age_seconds; $r->price_checks->session_at_analysis = $now->quote->session;
        $r->price_checks->readiness_blockers = $now->blockers; $r->readiness = $now->readiness;
        $r->verification_status = $r->readiness === 'ready' ? 'verified' : 'needs_check';
        $r->active_plan = $r->readiness === 'ready' ? ($now->matching_plans[0] ?? null) : null;
        if ($r->active_plan !== null) foreach ($r->entry_plans as $plan) if ($plan->mode === $r->active_plan) { $r->entry_zone = $plan->entry_zone; $r->invalidation = $plan->invalidation; break; }
        $qTime = new DateTimeImmutable(MonRun::utc($r->price_as_of)); $r->quote_valid_until = $qTime->modify('+300 seconds')->format('Y-m-d\TH:i:s.u\Z');
        // PHP auxiliary observations do not get promoted into independent facts.
        $r->collection_check = (object)['status' => 'not_used_this_evaluation', 'used_for_current_price' => false, 'collection_id' => $data->collection->collection_id]; $r->wiki_effect = 'unused';
        $independent->$sid = mon_decode(MonRun::json($r));
        $row = clone $plainBefore[$sid];
        foreach (['price', 'price_as_of', 'price_type', 'quote_valid_until', 'active_plan', 'entry_zone', 'invalidation', 'readiness', 'verification_status', 'evidence_ids', 'reason', 'next_check'] as $k) if (property_exists($row, $k)) $row->$k = mon_get($r, $k);
        if (property_exists($row, 'price_checks')) $row->price_checks = MonRun::pick($r->price_checks, ['action_price_valid', 'age_seconds', 'delay_kind', 'delay_seconds', 'market_date', 'session_at_analysis', 'timestamp_basis', 'reason']);
        if (property_exists($row, 'risk_checks')) $row->risk_checks = MonRun::pick($r->risk_checks, ['current_corporate_risk_verified', 'current_trading_status_verified', 'confirmed_material_adverse', 'verified_as_of']);
        if (property_exists($row, 'collection_check')) $row->collection_check = $r->collection_check;
        $slim[] = $row;
        $used[] = MonRun::pick($r, ['symbol_id', 'price', 'price_type', 'price_as_of', 'price_checks', 'risk_checks', 'risk_review', 'plan_valid_until', 'collection_check']);
        if ($r->action === 'BUY_REVIEW' && $r->readiness === 'ready') $readyCount[$r->country] = ($readyCount[$r->country] ?? 0) + 1;
    }
    $payload->independent_results = $independent; $facts = $payload->input_facts;
    $facts->as_of = $fixed; $facts->used_prices = $used; $facts->collection_id = $data->collection->collection_id; $facts->collection_status = $data->collection->status;
    $facts->corporate_risk_status = 'needs_check'; $facts->trading_status_check = (object)['status' => 'needs_check', 'feed_current_and_count_verified' => false];
    $a->as_of = $fixed; $a->analyzed_at = $fixed; $a->run_id = 'mon-' . (new DateTimeImmutable($fixed))->format('Ymd\THisu\Z');
    $a->collection_id = $data->collection->collection_id; $a->watchlist_version = $data->watchlist->watchlist_version;
    $a->input_fingerprint = MonRun::digest($facts); $a->result_fingerprint = MonRun::digest(MonRun::business($full)); $a->results = $slim;
    $a->independent_results = [];
    foreach ($independent as $sid => $r) $a->independent_results[] = (object)['symbol_id' => $sid, 'rank' => $r->rank, 'action' => $r->action, 'readiness' => $r->readiness, 'result_ref' => 'independent_results:' . $sid];
    // Pending references are durable facts, not a claim that any background job has started.
    if (!(mon_get($payload, 'mon_run_metadata_archive') instanceof stdClass)) $payload->mon_run_metadata_archive = new stdClass();
    $payload->mon_run_metadata_archive->{MonRun::utc($data->analysis->as_of)} = MonRun::pick($data->analysis, ['display', 'limitations', 'maintenance']);
    $payload->mon_run_metadata_archive->{MonRun::utc($data->analysis->as_of)}->input_snapshot = MonRun::pick($data->analysis->input_snapshot, ['cache_reuse', 'mon_input_state', 'trading_status_check']);
    $a->input_snapshot->mon_input_state = (object)['run_contract' => MonRun::CONTRACT, 'run_mode' => 'evaluate', 'pending' => $patch->pending, 'preparation_running' => false];
    $a->input_snapshot->trading_status_check = $facts->trading_status_check;
    $a->input_snapshot->cache_reuse = (object)['run_contract' => MonRun::CONTRACT, 'run_mode' => 'evaluate', 'previous_run_id' => $data->analysis->run_id, 'used_at' => $fixed, 'reused_candidate_count' => count($a->candidate_audit), 'refreshed_candidate_count' => 0, 'changed_symbol_count' => count($changeIds), 'model_input_bytes' => null, 'tool_round_trips' => null, 'elapsed_seconds' => null, 'stage_seconds' => null, 'timing_basis' => 'caller_end_to_end_timing_not_measured_by_stateless_handler'];
    $a->input_snapshot->compressed_fields = array_keys(get_object_vars($payload)); sort($a->input_snapshot->compressed_fields, SORT_STRING);
    $a->input_snapshot->lossless_payload = MonRun::pack($payload, $data->analysis->input_snapshot->lossless_payload->encoding);
    foreach ($a->coverage as $r) { $r->ready_count = $readyCount[$r->country] ?? 0; $r->price_verified_count = 0; foreach ($full as $row) if ($row->country === $r->country && $row->price_checks->action_price_valid) $r->price_verified_count++; }
    $a->status = 'partial'; $a->display = (object)['analysis_as_of' => $fixed, 'rendered_at' => $fixed, 'analysis_recomputed' => true, 'selection_recomputed' => false];
    $a->limitations_as_of = mon_get($data->analysis, 'limitations_as_of', $data->analysis->as_of);
    $a->changes[] = (object)['at' => $fixed, 'kind' => 'mon_run_patch', 'changed_symbol_ids' => array_keys($changeIds), 'reason' => implode('; ', $patch->short_reasons), 'watchlist_version_before' => $data->watchlist->watchlist_version, 'watchlist_version_after' => $data->watchlist->watchlist_version];
    MonRun::validate($out); MonRun::verifyFacts($out, $payload);
    if (!MonRun::storage($out, false)->fits) {
        // Only compact on an actual capacity failure; retain every full evidence record and original archive.
        MonRun::compactEvidence($out, $payload);
        $out->analysis->input_snapshot->lossless_payload = MonRun::pack($payload, $data->analysis->input_snapshot->lossless_payload->encoding);
        MonRun::evidence($out, $payload); MonRun::verifyFacts($out, $payload);
    }
    $storage = MonRun::storage($out, true);
    if (MonRun::json($out->collection) !== MonRun::json($data->collection) || MonRun::json($out->watchlist) !== MonRun::json($data->watchlist)) throw new MonFault('MON_OWNERSHIP_INVALID');
    $dataText = MonRun::json($out); $resultText = MonRun::render($out, $fixed, $patch->short_reasons);
    return ['status' => 'changed', 'write_required' => true, 'files' => [['path' => 'mon_data.json', 'content' => $dataText, 'blob_sha' => MonRun::gitSha($dataText)], ['path' => 'mon_result.md', 'content' => $resultText, 'blob_sha' => MonRun::gitSha($resultText)]], 'summary' => $resultText,
        'validation' => ['schema_version' => 3, 'criteria_version' => $a->criteria_version, 'base_source_blob_sha' => $c->source_blob_sha, 'analysis_as_of' => $fixed, 'payload_hash_verified' => true, 'selection_reused' => true, 'selection_fingerprint' => $a->selection_fingerprint, 'input_fingerprint' => $a->input_fingerprint, 'result_fingerprint' => $a->result_fingerprint, 'collection_preserved' => true, 'watchlist_preserved' => true, 'changed_symbols' => count($changeIds), 'storage' => $storage]];
}

function mon_run_source($request, string $field, string $sha): stdClass {
    $raw = mon_get($request, $field);
    if (!is_string($raw) || strlen($raw) > MonRun::MAX_SOURCE || !preg_match('/^[a-f0-9]{40}$/', $sha)) throw new MonFault('MON_SOURCE_INVALID');
    if (!hash_equals($sha, MonRun::gitSha($raw))) throw new MonFault('MON_SOURCE_SHA_MISMATCH');
    try { $d = mon_decode($raw); } catch (Throwable $e) { throw new MonFault('MON_SOURCE_JSON_INVALID'); }
    MonRun::validate($d); return $d;
}

/* MON-DETAIL-1.0: per-symbol requests. PHP supplies facts; ChatGPT selects. */
final class MonDetails {
    const CONTRACT='MON-DETAIL-1.0';
    const BASIS='split_adjusted_price_return_cash_dividend_unadjusted';
    const MAX_REQUESTS=30;
    const MAX_PACKED=20480;
    const CODEC='MON-DETAIL-RAWREF-2.0';
    private static $snapshotMemo=[];
    public static function requests($d): stdClass {
        $r=mon_get($d->watchlist,'detail_requests',new stdClass());
        if(!($r instanceof stdClass)||count(get_object_vars($r))>self::MAX_REQUESTS)throw new MonFault('DETAIL_REQUESTS_INVALID');
        return $r;
    }
    public static function symbol($s): stdClass {
        if(!($s instanceof stdClass))throw new MonFault('DETAIL_SYMBOL_INVALID');
        MonRun::identity(mon_get($s,'symbol_id'));$parts=explode('|',$s->symbol_id);
        if(mon_get($s,'country')!==$parts[0]||mon_get($s,'exchange')!==$parts[1]||mon_get($s,'symbol')!==$parts[2]||
            mon_get($s,'currency')!==['KR'=>'KRW','US'=>'USD','JP'=>'JPY'][$parts[0]]||!is_string(mon_get($s,'name'))||
            !($s->source_codes instanceof stdClass)||mon_number(mon_get($s,'tick_size'))<=0)throw new MonFault('DETAIL_SYMBOL_INVALID');
        if(mon_get($s,'is_held')!==false)throw new MonFault('DETAIL_ENTRY_SYMBOL_INVALID');
        $o=mon_decode(MonRun::json($s));$o->purpose='스윙 후보 상세자료';$o->candidate_origin=mon_get($o,'candidate_origin',['new_in_mon']);$o->position=null;
        return $o;
    }
    public static function target($w,string $country,int $now): array {
        $until=mon_time(mon_get($w->calendar,'valid_until'));$dates=[];
        if($until!==null&&$until>=$now)foreach(mon_get($w->calendar,'markets',[]) as $m){
            $checked=mon_time(mon_get($m,'checked_at'));
            if(mon_get($m,'country')!==$country||mon_get($m,'timezone')!==MonMarket::timezone($country)->getName()||$checked===null||$checked>$now||$until-$checked>15*86400)continue;
            $ends=[];foreach(mon_get($m,'sessions',[])as $s){$date=mon_get($s,'market_date');$open=mon_time(mon_get($s,'open_at'));$close=mon_time(mon_get($s,'close_at'));
                if(is_string($date)&&$open!==null&&$close!==null&&$open<$close)$ends[$date]=max($ends[$date]??0,$close);}
            foreach($ends as $date=>$end)if($end<=$now)$dates[]=$date;
        }
        if($dates){sort($dates,SORT_STRING);return ['date'=>end($dates),'verified'=>true];}
        $local=(new DateTimeImmutable('@'.$now))->setTimezone(MonMarket::timezone($country));
        $close=$country==='US'?'160000':'153000';if($local->format('His')<$close)$local=$local->modify('-1 day');
        while((int)$local->format('N')>5)$local=$local->modify('-1 day');
        return ['date'=>$local->format('Y-m-d'),'verified'=>false];
    }
    public static function requestHash($r): string {return MonRun::digest(MonRun::pick($r,['contract','request_id','symbol_id','symbol','requested_at','deadline_at','target_completed_date','target_verified','criteria_version','force_refresh','run_context_id','run_received_at','run_mode','execution_limits']));}
    public static function retryPending($response): bool {
        $code=(string)mon_get($response,'error','');
        return in_array(mon_get($response,'state'),['retry_wait','serialization_pending','blocked','conflict'],true)||
            (mon_get($response,'state')==='done'&&(MonDaily::retryable($code)||in_array($code,['DETAIL_WAIT_DEADLINE','DETAIL_ATTEMPT_LIMIT','DETAIL_LATE_RESPONSE'],true)));
    }
    public static function recoverySignature($d,$r): string {
        return MonRun::digest((object)['codec'=>self::CODEC,'version'=>MON_VERSION,'request_hash'=>mon_get($r,'request_hash'),
            'input_sha256'=>mon_get(mon_get(mon_get($d->analysis,'input_snapshot'),'lossless_payload'),'sha256')]);
    }
    public static function workDue($d,$r,$response,int $now): bool {
        if(!self::matching($r,$response))return true;
        if(in_array(mon_get($response,'state'),['serialization_pending','blocked','conflict'],true))
            return mon_get($response,'recovery_signature')!==self::recoverySignature($d,$r);
        return self::retryPending($response)&&(int)mon_get($response,'retry_after',0)<=$now;
    }
    public static function validRequest($r,string $sid): void {
        if(!($r instanceof stdClass)||mon_get($r,'contract')!==self::CONTRACT||mon_get($r,'symbol_id')!==$sid||
            !preg_match('/^[A-Za-z0-9._-]{1,96}$/',(string)mon_get($r,'request_id'))||mon_get($r,'criteria_version')!==MonSwing::CRITERIA||
            !is_bool(mon_get($r,'target_verified'))||!is_bool(mon_get($r,'force_refresh')))throw new MonFault('DETAIL_REQUEST_INVALID');
        self::symbol($r->symbol);if($r->symbol->symbol_id!==$sid)throw new MonFault('DETAIL_REQUEST_INVALID');
        $start=MonRun::seconds($r->requested_at);$end=MonRun::seconds($r->deadline_at);
        if(property_exists($r,'run_context_id')){
            MonExecution::id($r->run_context_id);$mode=mon_get($r,'run_mode');
            $limits=mon_get($r,'execution_limits');$seconds=$mode==='extended'?280:100;
            if($limits!==null){if(!($limits instanceof stdClass)||!is_int(mon_get($limits,'collection_seconds'))||$limits->collection_seconds<60||$limits->collection_seconds>2592000||
                !is_int(mon_get($limits,'attempts'))||$limits->attempts<1||$limits->attempts>3||!is_numeric(mon_get($limits,'symbol_seconds'))||$limits->symbol_seconds<1||$limits->symbol_seconds>8)throw new MonFault('DETAIL_RUN_DEADLINE_INVALID');
                $seconds=$limits->collection_seconds;}
            if(!in_array($mode,['auto','normal','extended'],true)||($mode==='auto'&&$limits===null))throw new MonFault('DETAIL_RUN_DEADLINE_INVALID');
            MonRun::utc(mon_get($r,'run_received_at'));
        }
        if($end<$start||$end-$start>300||!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$r->target_completed_date)||
            !hash_equals(self::requestHash($r),(string)mon_get($r,'request_hash')))throw new MonFault('DETAIL_REQUEST_INVALID');
    }
    private static function queue($d,$c,$item,stdClass $requests,stdClass $pool): array {
        if(!($item instanceof stdClass))throw new MonFault('DETAIL_REQUEST_INVALID');$symbol=self::symbol(mon_get($item,'symbol'));
        if(!in_array($symbol->country,$c->countries,true))throw new MonFault('MON_SELECTION_SCOPE_INVALID');
        $sid=$symbol->symbol_id;$id=mon_get($item,'request_id');$target=self::target($d->watchlist,$symbol->country,(int)MonRun::seconds($c->rendered_at));
        $old=mon_get($requests,$sid);
        $known=MonRun::rows($d->watchlist->symbols)+MonRun::rows($d->analysis->candidate_audit);$prior=mon_get($old,'symbol',mon_get($pool,$sid,$known[$sid]??null));
        if(mon_get($prior,'first_seen_at')!==null){$symbol->first_seen_at=$prior->first_seen_at;$symbol->candidate_group=mon_get($prior,'candidate_group','previous');}
        elseif(!isset($known[$sid])&&!($old instanceof stdClass)){$symbol->first_seen_at=$c->rendered_at;$symbol->candidate_group='new';}
        else{unset($symbol->first_seen_at);$symbol->candidate_group='previous';}
        if($old instanceof stdClass&&mon_get($old,'request_id')===$id)foreach(['first_seen_at','candidate_group']as $field){if(property_exists($old->symbol,$field))$symbol->$field=$old->symbol->$field;else unset($symbol->$field);}
        $wait=max(0,min(300,(int)mon_get($item,'wait_seconds',120)));$force=mon_get($item,'force_refresh',false);
        if(MonExecution::$active)MonExecution::$active->guard('collection');
        if(!is_bool($force))throw new MonFault('DETAIL_REQUEST_INVALID');
        $r=(object)['contract'=>self::CONTRACT,'request_id'=>$id,'symbol_id'=>$sid,'symbol'=>$symbol,'criteria_version'=>MonSwing::CRITERIA,
            'requested_at'=>$c->rendered_at,'deadline_at'=>MonRun::utc(gmdate('Y-m-d\TH:i:s\Z',(int)MonRun::seconds($c->rendered_at)+$wait)),
            'target_completed_date'=>$target['date'],'target_verified'=>$target['verified'],'force_refresh'=>$force];
        if(MonExecution::$active){$b=MonExecution::$active;$p=mon_get($b->run,'adaptive_policy');$r->run_context_id=$b->run->run_context_id;$r->run_received_at=$b->run->received_at;$r->run_mode=$b->run->mode;
            if($p)$r->execution_limits=(object)['collection_seconds'=>(int)($b->end('collection')-MonRun::seconds($b->run->received_at)),'attempts'=>$p->detail_attempts,'symbol_seconds'=>$p->detail_symbol_seconds];}
        $r->request_hash=self::requestHash($r);self::validRequest($r,$sid);
        if($old instanceof stdClass&&mon_get($old,'request_id')===$id){
            if(MonRun::json($old->symbol)!==MonRun::json($symbol)||mon_get($old,'force_refresh')!==$force)throw new MonFault('DETAIL_REQUEST_ID_REUSED');
            return ['status'=>'reuse','write_required'=>false,'request'=>$old,'files'=>[]];
        }
        if(!property_exists($requests,$sid)&&count(get_object_vars($requests))>=self::MAX_REQUESTS){
            $expired=[];foreach($requests as $key=>$saved){$response=mon_get(mon_get($d->collection,'detail_responses'),$key);if(self::matching($saved,$response)&&mon_get($response,'state')==='done'&&!self::retryPending($response))$expired[$key]=$saved->requested_at;}
            asort($expired,SORT_STRING);if(!$expired)throw new MonFault('DETAIL_REQUEST_LIMIT');unset($requests->{array_key_first($expired)});
        }
        $requests->$sid=$r;return ['status'=>'queued','write_required'=>true,'request'=>$r,'files'=>[]];
    }
    public static function register($d,$context,$item): array {
        MonRun::validate($d);$c=MonRun::context($context);$requests=mon_decode(MonRun::json(self::requests($d)));
        $r=self::queue($d,$c,$item,$requests,MonDiscovery::pool($d));if(!$r['write_required'])return $r;
        $out=clone $d;$out->watchlist=clone $d->watchlist;$out->watchlist->detail_requests=$requests;
        $extras=[];if(!MonRun::storage($out,false)->fits){$compact=MonGaps::compact($out,$c->rendered_at);$out=$compact['data'];$extras=array_slice($compact['files'],1);}MonData::validate($out);MonRun::storage($out,true);$raw=MonRun::json($out);
        $r['files']=array_merge([['path'=>'mon_data.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)]],$extras);
        $r['validation']=['collection_preserved'=>true,'analysis_preserved'=>true];return $r;
    }
    public static function unpack($packed): stdClass {
        if(!($packed instanceof stdClass)||mon_get($packed,'encoding')!=='gzip+base64'||!is_string(mon_get($packed,'data'))||strlen($packed->data)>self::MAX_PACKED||
            !is_string(mon_get($packed,'sha256'))||!is_int(mon_get($packed,'uncompressed_bytes')))throw new MonFault('DETAIL_PAYLOAD_INVALID');
        $binary=base64_decode($packed->data,true);$raw=$binary===false?false:@gzdecode($binary,262145);
        if(!is_string($raw)||strlen($raw)!==mon_get($packed,'uncompressed_bytes')||strlen($raw)>262144||!hash_equals((string)mon_get($packed,'sha256'),hash('sha256',$raw)))throw new MonFault('DETAIL_PAYLOAD_HASH_INVALID');
        try{$s=mon_decode($raw);}catch(Throwable $e){throw new MonFault('DETAIL_PAYLOAD_INVALID');}
        if(!($s instanceof stdClass))throw new MonFault('DETAIL_PAYLOAD_INVALID');
        if(property_exists($s,'transport_raw_records_deduplicated')){
            $marker=$s->transport_raw_records_deduplicated;$records=mon_get(mon_get($s,'history_metadata'),'raw_records');
            if(!($records instanceof stdClass)||!(mon_get($s,'origin')instanceof stdClass))throw new MonFault('DETAIL_PAYLOAD_INVALID');
            if($marker===true){
                if(property_exists($s->origin,'raw_records'))throw new MonFault('DETAIL_PAYLOAD_INVALID');
                $s->origin->raw_records=$records;
            }elseif($marker instanceof stdClass){
                if(!is_array(mon_get($marker,'restore_paths'))||!is_string(mon_get($marker,'raw_records_sha256'))||!is_string(mon_get($marker,'snapshot_sha256')))throw new MonFault('DETAIL_PAYLOAD_INVALID');
                foreach($marker->restore_paths as $path)if(!is_string($path))throw new MonFault('DETAIL_PAYLOAD_INVALID');
                if(mon_get($marker,'version')!==2||mon_get($marker,'source_path')!=='history_metadata.raw_records'||
                    !is_array(mon_get($marker,'restore_paths'))||!$marker->restore_paths||count($marker->restore_paths)>2||
                    count(array_unique($marker->restore_paths))!==count($marker->restore_paths)||
                    !hash_equals((string)mon_get($marker,'raw_records_sha256'),hash('sha256',MonRun::json($records))))throw new MonFault('DETAIL_PAYLOAD_INVALID');
                foreach($marker->restore_paths as $path){
                    if($path==='origin.raw_records')$parent=$s->origin;
                    elseif($path==='origin.history_metadata.raw_records'&&mon_get($s->origin,'history_metadata')instanceof stdClass)$parent=$s->origin->history_metadata;
                    else throw new MonFault('DETAIL_PAYLOAD_INVALID');
                    if(property_exists($parent,'raw_records'))throw new MonFault('DETAIL_PAYLOAD_INVALID');
                    $parent->raw_records=$records;
                }
            }else throw new MonFault('DETAIL_PAYLOAD_INVALID');
            unset($s->transport_raw_records_deduplicated);
            if($marker instanceof stdClass&&!hash_equals((string)mon_get($marker,'snapshot_sha256'),hash('sha256',MonRun::json($s))))throw new MonFault('DETAIL_PAYLOAD_HASH_INVALID');
            if(strlen(MonRun::json($s))>262144)throw new MonFault('DETAIL_PAYLOAD_TOO_LARGE');
        }
        return $s;
    }
    public static function registerMany($d,$context,$items): array {
        if(!is_array($items)||count($items)<1||count($items)>self::MAX_REQUESTS)throw new MonFault('DETAIL_REQUESTS_INVALID');
        MonRun::validate($d);$c=MonRun::context($context);$requests=mon_decode(MonRun::json(self::requests($d)));$pool=MonDiscovery::pool($d);$rows=[];$seen=[];$changed=false;
        $out=clone $d;$out->watchlist=clone $d->watchlist;$out->watchlist->detail_requests=$requests;$size=MonRun::storage($out,false);
        $requestSize=static function($map){return strlen(MonRun::json((object)['watchlist'=>(object)['detail_requests'=>$map]]));};$mapBytes=$requestSize($requests);$extras=[];if(!$size->fits||$size->remaining_after_reserve<65536){$compact=MonGaps::compact($out,$c->rendered_at);$out=$compact['data'];$extras=array_slice($compact['files'],1);$size=MonRun::storage($out,false);}
        foreach($items as $item){$sid=mon_get(mon_get($item,'symbol'),'symbol_id');try{
            if(is_string($sid)&&isset($seen[$sid]))throw new MonFault('DETAIL_SYMBOL_DUPLICATE');
            $trial=clone $requests;$r=self::queue($d,$c,$item,$trial,$pool);$nextBytes=$requestSize($trial);$delta=$nextBytes-$mapBytes;
            if($r['write_required']&&($size->bytes+$delta>$size->max_bytes||$size->bytes_without_collection+$delta+$size->collection_reserve_bytes>$size->max_bytes))throw new MonFault('MON_STORAGE_LIMIT');
            $requests=$trial;$mapBytes=$nextBytes;$size->bytes+=$delta;$size->bytes_without_collection+=$delta;if(is_string($sid))$seen[$sid]=true;
            $rows[]=['symbol_id'=>$sid,'status'=>$r['status'],'request'=>$r['request'],'error'=>null];$changed=$changed||$r['write_required'];
        }catch(MonFault $e){$rows[]=['symbol_id'=>$sid,'status'=>'rejected','request'=>null,'error'=>$e->faultCode];}}
        $raw=null;if($changed){$out->watchlist->detail_requests=$requests;MonData::validate($out);MonRun::storage($out,true);$raw=MonRun::json($out);}
        return ['contract'=>self::CONTRACT,'requests'=>$rows,'write_required'=>$changed,'completion_policy'=>'per_symbol_no_batch_barrier',
            'files'=>$changed?array_merge([['path'=>'mon_data.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)]],$extras):[],
            'validation'=>['collection_preserved'=>true,'analysis_preserved'=>true]];
    }
    public static function pack($s): stdClass {
        $at=mon_monotonic();$error=null;
        try{
            if(!($s instanceof stdClass)||property_exists($s,'transport_raw_records_deduplicated'))throw new MonFault('DETAIL_PAYLOAD_INVALID');
            $original=MonRun::json($s);if(strlen($original)>262144)throw new MonFault('DETAIL_PAYLOAD_TOO_LARGE');
            $copy=$s;$records=mon_get(mon_get($s,'history_metadata'),'raw_records');$origin=mon_get($s,'origin');$paths=[];
            if($records instanceof stdClass&&$origin instanceof stdClass){
                $recordJson=MonRun::json($records);
                if(mon_get($origin,'raw_records')instanceof stdClass&&MonRun::json($origin->raw_records)===$recordJson)$paths[]='origin.raw_records';
                $nested=mon_get($origin,'history_metadata');
                if($nested instanceof stdClass&&mon_get($nested,'raw_records')instanceof stdClass&&MonRun::json($nested->raw_records)===$recordJson)$paths[]='origin.history_metadata.raw_records';
                if($paths){
                    $copy=mon_decode($original);
                    foreach($paths as $path){if($path==='origin.raw_records')unset($copy->origin->raw_records);else unset($copy->origin->history_metadata->raw_records);}
                    $copy->transport_raw_records_deduplicated=(object)['version'=>2,'source_path'=>'history_metadata.raw_records','restore_paths'=>$paths,
                        'raw_records_sha256'=>hash('sha256',$recordJson),'snapshot_sha256'=>hash('sha256',$original)];
                }
            }
            $packed=MonRun::pack($copy,'gzip+base64');if(strlen($packed->data)>self::MAX_PACKED)throw new MonFault('DETAIL_PAYLOAD_TOO_LARGE');return $packed;
        }catch(MonFault $e){$error=$e->faultCode;throw $e;}
        catch(Throwable $e){$error='DETAIL_PAYLOAD_INVALID';throw new MonFault($error);}
        finally{MonExecution::measure('detail/serialize',mon_monotonic()-$at,$error);}
    }
    public static function checkedSnapshot($request,$response): stdClass {
        $s=self::unpack($response->payload);$bm=self::benchmark($request->symbol);
        if(mon_get($s,'symbol_id')!==$request->symbol_id||mon_get($s,'criteria_version')!==MonSwing::CRITERIA||mon_get($s,'benchmark_id')!==$bm['id']||mon_get(mon_get($s,'history_metadata'),'price_basis')!==self::BASIS||!is_array(mon_get($s,'bars'))||!is_array(mon_get($s,'benchmark_bars'))||!(mon_get($s,'metrics')instanceof stdClass)||!(mon_get($s,'origin')instanceof stdClass)||!(mon_get($s,'benchmark_origin')instanceof stdClass))throw new MonFault('DETAIL_RESPONSE_ID_INVALID');
        $verified=self::snapshot($request->symbol,$s->bars,$s->benchmark_bars,$s->benchmark_id,$s->origin,$s->benchmark_origin);
        if(MonRun::json($verified->metrics)!==MonRun::json($s->metrics)||MonRun::json($verified->technical)!==MonRun::json($s->technical)||
            MonRun::json($verified->entry_plans)!==MonRun::json($s->entry_plans)||MonRun::json($verified->history_metadata)!==MonRun::json($s->history_metadata)||
            $s->metrics->bars_as_of>$request->target_completed_date||mon_time($s->history_metadata->checked_at)>mon_time($response->completed_at)+5)throw new MonFault('DETAIL_RESPONSE_FACT_INVALID');return $s;
    }
    public static function benchmark($symbol): array {
        if($symbol->country==='KR')return $symbol->exchange==='KOSDAQ'?['id'=>'KR|INDEX|^KQ11','ticker'=>'^KQ11','kis'=>'1001']:['id'=>'KR|INDEX|^KS11','ticker'=>'^KS11','kis'=>'0001'];
        return $symbol->country==='US'?['id'=>'US|INDEX|^GSPC','ticker'=>'^GSPC','kis'=>null]:['id'=>'JP|INDEX|TOPIX','ticker'=>'^TOPX','kis'=>null];
    }
    public static function cached($d,$symbol,?stdClass $sourcePayload=null): ?stdClass {
        try{$p=$sourcePayload??MonRun::payload($d);$b=mon_get(mon_get($p,'series'),$symbol->symbol_id);$m=mon_get(mon_get($p,'candidate_metrics'),$symbol->symbol_id);$h=mon_get(mon_get($p,'history_metadata'),$symbol->symbol_id);
            $bench=self::benchmark($symbol);$bb=mon_get(mon_get($p,'series'),$bench['id']);
            if(!is_array($b)||!is_array($bb)||!($m instanceof stdClass)||mon_get($m,'adjustment')!==self::BASIS)return null;
            $complete=$h instanceof stdClass&&mon_get($h,'complete_source_window')===true&&hash_equals((string)mon_get($h,'bars_sha256'),MonRun::digest(MonSwing::bars($b)));
            $checked=mon_get($h,'checked_at',mon_get($h,'original_checked_at',null));if($checked===null)$checked=mon_get($m,'checked_at',$d->analysis->as_of);
            return self::snapshot($symbol,$b,$bb,$bench['id'],(object)['source'=>'verified_saved_data','source_url'=>mon_get($h,'source_url'),'checked_at'=>$checked,'complete_source_window'=>$complete,'history_metadata'=>$h,
                'raw_records'=>mon_get($h,'raw_records'),'raw_records_source_ref'=>mon_get($h,'raw_records_source_ref'),'display_precision'=>mon_get($h,'display_precision'),'original_provider'=>mon_get($h,'original_provider')],
                (object)['source'=>'verified_saved_data','checked_at'=>$checked]);
        }catch(MonFault $e){return null;}
    }
    public static function tickRuleUsable($s,string $marketDate): bool {
        if(mon_get($s,'country')!=='JP')return true;
        $rule=mon_get($s,'tick_rule');if(!in_array($rule,['JPX_TOPIX500_CURRENT','JPX_OTHER_CURRENT'],true))return false;
        $proof=mon_get($s,'tick_rule_evidence');if($rule==='JPX_OTHER_CURRENT'&&!($proof instanceof stdClass))return false;
        if($proof instanceof stdClass){
            $from=(string)mon_get($proof,'valid_from');$until=(string)mon_get($proof,'valid_until');
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$until)||$marketDate<$from||$marketDate>$until)return false;
            if(mon_get($proof,'rules_url')!=='https://www.jpx.co.jp/english/equities/trading/domestic/07.html'||
                mon_get($proof,'membership_url')!=='https://www.jpx.co.jp/automation/markets/indices/topix/files/topixweight_j.csv'||
                !preg_match('/^[a-f0-9]{64}$/',(string)mon_get($proof,'membership_sha256'))||!preg_match('/^[a-f0-9]{64}$/',(string)mon_get($proof,'rules_sha256')))return false;
            $narrow=in_array(mon_get($proof,'classification'),['TOPIX Core30','TOPIX Large70','TOPIX Mid400'],true);
            if(($rule==='JPX_TOPIX500_CURRENT')!==$narrow||(!$narrow&&!in_array(mon_get($proof,'classification'),['TOPIX Small 1','TOPIX Small 2'],true)))return false;
        }
        return true;
    }
    private static function tick($s,float $value): ?float {
        if($s->country==='US')return $value>=1?0.01:0.0001;
        if($s->country==='KR'){foreach([[2000,1],[5000,5],[20000,10],[50000,50],[200000,100],[500000,500]]as $band)if($value<$band[0])return(float)$band[1];return 1000.0;}
        $rule=mon_get($s,'tick_rule');
        if($rule==='JPX_OTHER_CURRENT'){foreach([[3000,1],[5000,5],[30000,10],[50000,50],[300000,100],[500000,500],[3000000,1000],[5000000,5000],[30000000,10000],[50000000,50000]]as $band)if($value<=$band[0])return(float)$band[1];return 100000.0;}
        if($rule!=='JPX_TOPIX500_CURRENT')return null;
        foreach([[1000,.1],[3000,.5],[10000,1],[30000,5],[100000,10],[300000,50],[1000000,100],[3000000,500],[10000000,1000],[30000000,5000]]as $band)if($value<=$band[0])return(float)$band[1];return 10000.0;
    }
    private static function roundTick($s,float $value,bool $up): ?float {
        $step=self::tick($s,$value);if($step===null)return null;
        for($i=0;$i<4;$i++){$x=($up?ceil($value/$step-1e-10):floor($value/$step+1e-10))*$step;$next=self::tick($s,$x);if($next===$step)return(float)MonRun::decimal6($x);$step=$next;}return null;
    }
    public static function snapshot($symbol,array $bars,array $bench,string $benchId,$origin,$benchOrigin): stdClass {
        $memoKey=hash('sha256',MonRun::json((object)['code'=>MON_VERSION,'symbol'=>$symbol,'bars'=>$bars,'benchmark'=>$bench,'benchmark_id'=>$benchId,'origin'=>$origin,'benchmark_origin'=>$benchOrigin]));
        if(isset(self::$snapshotMemo[$memoKey]))return mon_decode(self::$snapshotMemo[$memoKey]);

        $b=MonSwing::bars($bars);$n=count($b);if($n<21)throw new MonFault('DETAIL_RS_HISTORY_MISSING');$bm=MonSwing::benchmarkCloses($bench);$dates=array_column(array_slice($b,-21),0);
        foreach($dates as $date)if(!isset($bm[$date]))throw new MonFault('DETAIL_BENCHMARK_DATE_MISSING');
        $available=array_values(array_filter(array_keys($bm),static function($d)use($dates){return $d<=$dates[20];}));if(array_slice($available,-21)!==$dates)throw new MonFault('DETAIL_COMPARISON_DATES_MISMATCH');
        $tech=MonSwing::technical($b);$closes=array_column($b,4);$last=end($closes);$vol=array_map(static function($r){return $r[4]*$r[5];},array_slice($b,-20));
        $ma10=array_sum(array_slice($closes,-10))/10;$ma20=array_sum(array_slice($closes,-20))/20;$low5=min(array_column(array_slice($b,-5),3));
        $support=max(array_values(array_filter([$tech->ma5,$ma10,$ma20,$low5],static function($x)use($last){return $x<=$last;})));$resistance=max(array_column(array_slice($b,-21,20),2));$atr=$tech->atr14;
        $planDate=$dates[20];if($symbol->country==='JP')$planDate=max($planDate,(new DateTimeImmutable(MonRun::utc(mon_get($origin,'checked_at'))))->setTimezone(MonMarket::timezone('JP'))->format('Y-m-d'));
        $plans=[];if($atr>0&&self::tickRuleUsable($symbol,$planDate))foreach([['pullback',$support-$atr,$support+$atr,min($low5,$support)-.5*$atr],['breakout',$resistance,$resistance+$atr,$resistance-$atr]]as $q){
            $lo=self::roundTick($symbol,$q[1],true);$hi=self::roundTick($symbol,$q[2],false);$stop=self::roundTick($symbol,$q[3],false);
            if($lo!==null&&$hi!==null&&$stop!==null&&$stop>0&&$stop<$lo&&$lo<=$hi)$plans[]=(object)['mode'=>$q[0],'entry_zone'=>[$lo,$hi],'invalidation'=>$stop,'planned_risk_pct'=>(float)MonRun::decimal6(100*($hi-$stop)/$hi)];
        }
        $stockR=$last/$b[$n-21][4]-1;$marketR=$bm[$dates[20]]/$bm[$dates[0]]-1;
        $m=(object)['bars_as_of'=>$dates[20],'bars_count'=>$n,'comparison_start'=>$dates[0],'comparison_end'=>$dates[20],'benchmark'=>$benchId,'adjustment'=>self::BASIS,
            'last_close'=>$last,'R20'=>(float)MonRun::decimal6($stockR),'RS20_pp'=>(float)MonRun::decimal6(100*($stockR-$marketR)),
            'ADV20'=>(float)MonRun::decimal6(array_sum($vol)/20),'MA5'=>$tech->ma5,'MA10'=>$ma10,'MA20'=>$ma20,'ATR14'=>$atr,'S'=>$support,'R'=>$resistance,'low5'=>$low5,'previous10_low'=>min(array_column(array_slice($b,-11,10),3))];
        $complete=mon_get($origin,'complete_source_window')===true;
        $h=(object)['complete_source_window'=>$complete,'provider_window'=>'6mo_plus_verified_tail','first_date'=>$b[0][0],'last_date'=>$dates[20],'bar_count'=>$n,'bars_sha256'=>MonRun::digest($b),'price_basis'=>self::BASIS,
            'source_url'=>mon_get($origin,'source_url'),'checked_at'=>MonRun::utc(mon_get($origin,'checked_at')),'source_kind'=>'php_auxiliary'];
        foreach(['raw_records','raw_records_source_ref','display_precision','original_provider','history_resolution']as $key)if(mon_get($origin,$key)!==null)$h->$key=$origin->$key;
        if(!$complete){$tech->macd_status='needs_full_history';$tech->macd_kind=null;$tech->qualifying_signals=array_values(array_filter($tech->qualifying_signals,static function($k){return strpos($k,'MACD_')!==0;}));$tech->technical_qualified=count($tech->qualifying_signals)>0;}
        $result=(object)['symbol_id'=>$symbol->symbol_id,'symbol'=>$symbol,'criteria_version'=>MonSwing::CRITERIA,'bars'=>$b,'benchmark_id'=>$benchId,'benchmark_bars'=>$bench,'history_metadata'=>$h,
            'origin'=>$origin,'benchmark_origin'=>$benchOrigin,'metrics'=>$m,'technical'=>$tech,'entry_plans'=>$plans,'source_kind'=>'php_auxiliary'];
    
        $raw=MonRun::json($result);if(strlen($raw)<=262144){self::$snapshotMemo[$memoKey]=$raw;if(count(self::$snapshotMemo)>2)array_shift(self::$snapshotMemo);}return $result;
    }
    public static function matching($request,$response): bool {
        if(!($request instanceof stdClass)||!is_string(mon_get($request,'request_hash'))||!preg_match('/^[a-f0-9]{64}$/',$request->request_hash))return false;
        return $response instanceof stdClass&&mon_get($response,'request_id')===mon_get($request,'request_id')&&mon_get($response,'symbol_id')===mon_get($request,'symbol_id')&&
            hash_equals($request->request_hash,(string)mon_get($response,'request_hash',''))&&mon_get($response,'contract')===self::CONTRACT;
    }
    public static function mergedResponses($d,$batch): stdClass {
        $out=mon_get($d->collection,'detail_responses',new stdClass());if(!($out instanceof stdClass))$out=new stdClass();$requests=self::requests($d);
        foreach(mon_get($batch,'detail_responses',new stdClass())as $sid=>$row){$r=mon_get($requests,$sid);$old=mon_get($out,$sid);if($r instanceof stdClass&&self::matching($r,$row)&&(!self::matching($r,$old)||(mon_time(mon_get($row,'completed_at'))??0)>=(mon_time(mon_get($old,'completed_at'))??0)))$out->$sid=$row;}
        foreach(get_object_vars($out)as $sid=>$row){$r=mon_get($requests,$sid);if(!($r instanceof stdClass)||!self::matching($r,$row))unset($out->$sid);}return $out;
    }
    public static function view($d,$context): array {
        $c=MonRun::context($context);$rows=[];$responses=mon_get($d->collection,'detail_responses',new stdClass());$sourcePayload=null;
        foreach(self::requests($d)as $sid=>$request){try{self::validRequest($request,$sid);}catch(MonFault $e){$rows[]=(object)['symbol_id'=>$sid,'state'=>'invalid','usable'=>false,'error'=>$e->faultCode];continue;}$response=mon_get($responses,$sid);$matched=self::matching($request,$response);$cached=null;
            if(!$matched){if($sourcePayload===null)try{$sourcePayload=MonRun::payload($d);}catch(MonFault $e){$sourcePayload=new stdClass();}$cached=self::cached($d,$request->symbol,$sourcePayload);}
            $card=(object)['symbol_id'=>$sid,'name'=>$request->symbol->name,'request_id'=>$request->request_id,'deadline_at'=>$request->deadline_at,
                'state'=>$matched?$response->state:'pending','data_status'=>$matched?$response->data_status:($cached?'saved_reference':'no_data'),
                'target_completed_date'=>$request->target_completed_date,'target_verified'=>$request->target_verified,'usable'=>$matched?mon_get($response,'usable',false):$cached!==null,
                'source_checked_at'=>$matched?mon_get($response,'source_checked_at'):($cached?$cached->history_metadata->checked_at:null),'bars_as_of'=>$matched?mon_get($response,'bars_as_of'):($cached?$cached->metrics->bars_as_of:null),
                'error'=>$matched?mon_get($response,'error'):null,'attempts'=>$matched?mon_get($response,'attempts'):0,
                'partial_fields'=>$matched?mon_get($response,'partial_fields'):null];
            try{$snapshot=$matched&&mon_get($response,'usable')===true&&mon_get($response,'payload')instanceof stdClass?self::checkedSnapshot($request,$response):$cached;}
            catch(MonFault $e){$snapshot=null;$card->usable=false;$card->error=$e->faultCode;$card->state='invalid_response';}
            if($snapshot instanceof stdClass)$card->facts=(object)['metrics'=>MonRun::pick($snapshot->metrics,['bars_as_of','last_close','RS20_pp','R20','ADV20','MA5','MA10','MA20','ATR14','S','R']),
                'signals'=>MonRun::pick($snapshot->technical,['qualifying_signals','macd_kind','macd_cross_date','macd_cross_age_days','histogram','ma5_below_days_before_last','macd_status']),'entry_plans'=>$snapshot->entry_plans,'source_kind'=>'php_auxiliary'];
            $rows[]=$card;
        }
        usort($rows,static function($a,$b){return strcmp($a->symbol_id,$b->symbol_id);});
        $progress=MonProgress::build($d,(int)MonRun::seconds($c->rendered_at));unset($progress['cards']);
        $header=['contract'=>self::CONTRACT,'as_of'=>$c->rendered_at,'source_blob_sha'=>$c->source_blob_sha,'state_token'=>MonRun::token($d,$c),'card_kind'=>'detail_status'];
        $pages=MonPaging::pages($header,$rows);
        return ['contract'=>self::CONTRACT,'as_of'=>$c->rendered_at,'cards'=>$pages['pages'][0]['cards'],'additional_card_pages'=>array_map(static function($p){return $p['cards'];},array_slice($pages['pages'],1)),
            'card_pages'=>$pages['pages'],'artifact_cards'=>$pages['artifact_cards'],'progress'=>$progress,'completion_policy'=>'per_symbol_no_batch_barrier','final_selector'=>'ChatGPT','auto_selected'=>false];
    }
    public static function inputs($d,$context): stdClass {
        $c=MonRun::context($context);$out=mon_decode(MonRun::json($d));$p=MonRun::payload($out);$responses=mon_get($out->collection,'detail_responses',new stdClass());$audit=MonRun::rows($out->analysis->candidate_audit);$watch=MonRun::rows($out->watchlist->symbols);$changed=false;
        foreach($out->analysis->market_summary as $market){$target=self::target($out->watchlist,$market->country,(int)MonRun::seconds($c->rendered_at));$market->target_completed_bar_date=$target['date'];if(!$target['verified'])$market->comparison_status='pending_benchmark';}
        foreach(self::requests($out)as $sid=>$request){try{self::validRequest($request,$sid);}catch(MonFault $e){continue;}$r=mon_get($responses,$sid);
            if(!self::matching($request,$r))continue;
            if(mon_get($r,'conflict')===true){if(!(mon_get($p,'detail_imports')instanceof stdClass))$p->detail_imports=new stdClass();$p->detail_imports->$sid=(object)['conflict'=>true,'data_status'=>'previous_conflict_reference','error'=>mon_get($r,'error')];$changed=true;continue;}
            if(mon_get($r,'usable')!==true||!(mon_get($r,'payload')instanceof stdClass))continue;
            try{$s=self::checkedSnapshot($request,$r);
            if(!isset($watch[$sid])){$out->watchlist->symbols[]=$request->symbol;$watch[$sid]=$request->symbol;}
            if(!isset($audit[$sid])){$row=(object)['symbol_id'=>$sid,'eligibility'=>'unverified','origin'=>['new_in_mon'],'failed_checks'=>[],'evidence_ids'=>[],'rank'=>null,'metrics_ref'=>'candidate_metrics:'.$sid];$out->analysis->candidate_audit[]=$row;$audit[$sid]=$row;}
            $eid='PHP-DETAIL-'.$request->request_id.'-'.substr(hash('sha256',$sid),0,10);$audit[$sid]->evidence_ids=array_values(array_unique(array_merge($audit[$sid]->evidence_ids,[$eid])));
            if(!(mon_get($p,'history_metadata')instanceof stdClass))$p->history_metadata=new stdClass();
            $p->series->$sid=$s->bars;$p->candidate_metrics->$sid=$s->metrics;$p->history_metadata->$sid=$s->history_metadata;
            $old=mon_get($p->series,$s->benchmark_id,[]);if(!$old||end($old)[0]<=end($s->benchmark_bars)[0])$p->series->{$s->benchmark_id}=$s->benchmark_bars;
            if(!(mon_get($p,'detail_imports')instanceof stdClass))$p->detail_imports=new stdClass();$p->detail_imports->$sid=MonRun::pick($r,['request_id','request_hash','data_status','bars_as_of','source_checked_at','completed_at','error']);
            if(!(mon_get($p,'detail_plans')instanceof stdClass))$p->detail_plans=new stdClass();$p->detail_plans->$sid=$s->entry_plans;
            $target=self::target($out->watchlist,$request->symbol->country,(int)MonRun::seconds($c->rendered_at));
            if($target['verified']&&$s->metrics->bars_as_of===$target['date'])foreach($out->analysis->market_summary as $market)if($market->country===$request->symbol->country){$market->completed_bar_date=$target['date'];$market->target_completed_bar_date=$target['date'];$market->comparison_status='php_detail_verified';}
            $changed=true;
            }catch(MonFault $e){if(!(mon_get($p,'detail_imports')instanceof stdClass))$p->detail_imports=new stdClass();$p->detail_imports->$sid=(object)['conflict'=>true,'data_status'=>'previous_conflict_reference','error'=>$e->faultCode];$changed=true;}
        }
        if(MonGaps::inputs($out,$p,$c->rendered_at))$changed=true;
        foreach($out->watchlist->symbols as $symbol)if($symbol->country==='JP'&&!self::tickRuleUsable($symbol,MonDiscovery::day($out->watchlist,'JP',$c->rendered_at)['date'])&&mon_get(mon_get($p,'detail_plans'),$symbol->symbol_id)!==null){$p->detail_plans->{$symbol->symbol_id}=[];$changed=true;}
        if($changed)$out->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');return $out;
    }
    public static function prepare($d,$context,array $liveRows=[]): array {
        if(MonExecution::$active)MonExecution::$active->guard('decision');
        $input=self::inputs($d,$context);$p=MonRun::payload($input);$imports=mon_get($p,'detail_imports',new stdClass());unset($p);
        $prepared=MonSwing::prepare($input,$context,$liveRows);$refs=[];
        foreach($prepared['full_signals']as $sid=>$s){
            $s->data_status=mon_get(mon_get($imports,$sid),'data_status','verified_saved_data');
            $s->reference_eligible=$s->market_strong&&mon_get($s,'technical_qualified')===true&&!$s->eligible&&in_array('latest_comparison_pending',$s->reason_codes,true)&&$s->eligibility!=='fail';
            if($s->reference_eligible&&in_array($s->country,MonRun::context($context)->countries,true))$refs[]=MonRun::pick($s,['symbol_id','name','country','bars_as_of','rs20_pp','qualifying_signals','macd_kind','macd_cross_date','macd_cross_age_days','last_close','data_status','reference_eligible','reason_codes']);
        }
        // The selection token/fingerprint remain those of the fixed numerical handler.
        $view=MonSwing::prepareView($prepared);
        $pages=MonPaging::pages(['state_token'=>$prepared['state_token'],'prepared_fingerprint'=>$prepared['prepared_fingerprint'],'card_kind'=>'previous_reference'],$refs);
        $view['reference_cards']=$pages['pages'][0]['cards'];$view['reference_card_pages']=$pages['pages'];
        foreach($pages['artifact_cards']as $sha=>$card)$view['artifact_cards']->$sha=$card;
        $view['detail_status']=self::view($d,$context);if(MonExecution::$active)MonExecution::$active->guard('decision');return $view;
    }
    public static function selectData($d,$decision): array {
        $context=mon_get($decision,'view_context');$input=self::inputs($d,$context);$prepared=MonSwing::prepare($input,$context,mon_get($decision,'live_observations',[]));$references=mon_get($decision,'references',[]);
        if(!is_array($references))throw new MonFault('DETAIL_REFERENCE_SELECTION_INVALID');$counts=[];$ids=[];
        foreach(mon_get($decision,'selected',[])as $r){$sid=mon_get($r,'symbol_id');$ids[$sid]=true;$country=explode('|',$sid)[0];$counts[$country]=($counts[$country]??0)+1;}
        foreach($references as $r){$sid=mon_get($r,'symbol_id');MonRun::identity($sid);$s=mon_get($prepared['full_signals'],$sid);
            if(isset($ids[$sid])||!($s instanceof stdClass)||$s->eligible||!$s->market_strong||mon_get($s,'technical_qualified')!==true||!in_array('latest_comparison_pending',$s->reason_codes,true)||$s->eligibility==='fail'||!is_string(mon_get($r,'reason'))||trim($r->reason)==='')throw new MonFault('DETAIL_REFERENCE_SELECTION_INVALID');
            $country=explode('|',$sid)[0];if(!in_array($country,MonRun::context($context)->countries,true))throw new MonFault('MON_SELECTION_SCOPE_INVALID');
            $counts[$country]=($counts[$country]??0)+1;if($counts[$country]>3)throw new MonFault('MON_RESULT_LIMIT_INVALID');$ids[$sid]=true;
        }
        $bundle=MonSwing::selectData($input,$decision,$prepared);$out=$bundle['data'];$p=$bundle['payload'];$watch=MonRun::rows($input->watchlist->symbols);$current=MonRun::rows($out->watchlist->symbols);$refFacts=[];
        foreach($references as $dr){$sid=$dr->symbol_id;$s=$prepared['full_signals']->$sid;$wr=$watch[$sid]??null;if(!$wr||$wr->is_held)throw new MonFault('DETAIL_REFERENCE_SYMBOL_INVALID');
            if(!isset($current[$sid])){$out->watchlist->symbols[]=$wr;$current[$sid]=$wr;}$wr->purpose='이전 검증자료 조건부 참고';
            $plans=mon_get(mon_get($p,'detail_plans'),$sid,$s->entry_plans);
            $r=(object)['symbol_id'=>$sid,'country'=>$wr->country,'name'=>$wr->name,'currency'=>$wr->currency,'rank'=>9999,'action'=>'REFERENCE_REVIEW','base_action'=>'REFERENCE_REVIEW','readiness'=>'reference','eligibility'=>'unverified',
                'swing_signal'=>$s,'entry_plans'=>$plans,'active_plan'=>null,'entry_zone'=>null,'invalidation'=>null,'price'=>$s->last_close,'price_type'=>'close','price_as_of'=>MonSwing::closeTime($out,$wr->country,$s->bars_as_of),
                'price_checks'=>(object)['action_price_valid'=>false,'delay_seconds'=>null,'market_date'=>$s->bars_as_of,'timestamp_basis'=>'close','session_at_analysis'=>'closed'],
                'risk_checks'=>(object)['current_corporate_risk_verified'=>false,'current_trading_status_verified'=>false],'plan_valid_until'=>null,'reason'=>$dr->reason,'evidence_ids'=>$s->evidence_ids,
                'autonomous_decision'=>(object)['selector'=>'ChatGPT','decided_at'=>$decision->fixed_as_of,'reason'=>$dr->reason],'metrics'=>(object)['RS20_pp'=>$s->rs20_pp,'R20'=>$s->R20],
                'data_status'=>'previous_verified_reference','signal_as_of'=>$s->bars_as_of,'next_check'=>'누락 완료봉·최신 시장 강도·현재 신호 확인'];
            $out->analysis->results[]=$r;$p->final_results_details->$sid=$r;$p->independent_results->$sid=$r;$out->analysis->independent_results[]=(object)['symbol_id'=>$sid,'rank'=>9999,'action'=>$r->action,'readiness'=>'reference','result_ref'=>'independent_results:'.$sid];
            $refFacts[]=(object)['symbol_id'=>$sid,'reason'=>$dr->reason,'signal_as_of'=>$s->bars_as_of,'selector'=>'ChatGPT'];
        }
        $p->selection_facts->reference_selected=$refFacts;$out->analysis->detail_protocol=self::CONTRACT;
        foreach($out->analysis->results as $r){if(!property_exists($r,'data_status'))$r->data_status=mon_get(mon_get(mon_get($p,'detail_imports'),$r->symbol_id),'data_status','verified_saved_data');$r->signal_as_of=mon_get(mon_get($r,'swing_signal'),'bars_as_of');$p->final_results_details->{$r->symbol_id}=$r;if(property_exists($p->independent_results,$r->symbol_id))$p->independent_results->{$r->symbol_id}=$r;}
        $out->analysis->limitations[]='이전자료 참고는 과거 신호이며 현재 매입 가능 확인과 구분';
        $ordered=$out->watchlist->symbols;usort($ordered,static function($a,$b)use($ids){return (isset($ids[$b->symbol_id])<=>isset($ids[$a->symbol_id]))?:strcmp($a->symbol_id,$b->symbol_id);});$kept=[];$per=[];
        foreach($ordered as $wr)if($wr->is_held||($per[$wr->country]??0)<10){$kept[]=$wr;if(!$wr->is_held)$per[$wr->country]=($per[$wr->country]??0)+1;}$out->watchlist->symbols=$kept;
        foreach($out->analysis->coverage as $row){$row->observed_count=$per[$row->country]??0;$row->reference_review_count=0;foreach($references as $ref)if(explode('|',$ref->symbol_id)[0]===$row->country)$row->reference_review_count++;}
        return ['data'=>$out,'payload'=>$p,'context'=>MonRun::context($context),'prepared'=>$prepared];
    }
    public static function finalize($d,$decision): array {
        if(MonExecution::$active)MonExecution::$active->guard('decision');
        $bundle=self::selectData($d,$decision);$out=$bundle['data'];$p=$bundle['payload'];
        MonConfirmation::apply($d,$out,$p,mon_get($decision,'confirmations'),$out->analysis->as_of);
        $out->analysis->discovery_state=MonDiscovery::counts($out,$p,$bundle['prepared'],$out->analysis->as_of);
        MonDiscovery::coverage($out);
        if(mon_get($out->analysis,'source_recovery')instanceof stdClass&&!mon_get($out->analysis,'pending_analysis',false)){
            $out->analysis->source_recovery->state='verified_inputs_used_in_autonomous_selection';$out->analysis->source_recovery->used_at=$out->analysis->as_of;
        }
        if(MonExecution::$active)MonExecution::$active->guard('decision');
        if(MonMeaning::same($d,$out,$p)){
            return ['status'=>'no_change','write_required'=>false,'files'=>[],'summary'=>MonRun::render($d,$bundle['context']->rendered_at),
                'final_available'=>true,'new_analysis_complete'=>true,'analysis_origin'=>'revalidated_unchanged_decision','save_status'=>'no_change','validation'=>['analysis_as_of_preserved'=>true,'collection_preserved'=>true,'meaningful_change'=>false,'prepare_reused'=>$bundle['prepared']['prepare_reused']]];
        }
        $out->analysis->input_snapshot->mon_input_state->final_available=true;
        $out->analysis->input_snapshot->mon_input_state->save_status='pending';
        if(MonExecution::$active)$out->analysis->input_snapshot->mon_input_state->timing=(object)MonExecution::$active->timing();
        $result=MonSwing::finalize($d,$out,$p,$bundle['context'],['종목별 자율 판단·근거를 한 번 확정; 현재·과거 신호와 저장 상태 구분']);
        if(MonExecution::$active)MonExecution::$active->guard('decision');
        $result['final_available']=true;$result['new_analysis_complete']=true;$result['analysis_origin']='secured_data_decision';$result['save_status']='pending';
        $result['validation']['prepare_reused']=$bundle['prepared']['prepare_reused'];$result['validation']['cache_key']=$bundle['prepared']['cache_key'];
        return $result;
    }
    public static function select($d,$decision): array {return self::finalize($d,$decision);}
}

final class MonClose {
    // Corroboration uses half a displayed unit; indicators and immutable hashes keep raw values.
    public static function tolerance(string $currency,float $a,float $b): float {
        if($currency==='USD')return min($a,$b)<1?.00005:.005;
        if(in_array($currency,['KRW','JPY'],true))return .5;
        throw new MonFault('DETAIL_CURRENCY_INVALID');
    }
    public static function matches(float $a,float $b,string $currency='USD'): bool {
        return is_finite($a)&&is_finite($b)&&$a>0&&$b>0&&abs($a-$b)<=self::tolerance($currency,$a,$b)+1e-9;
    }
}
/* Small local producer stages. Progress failures never interrupt data work. */
final class MonDetailStep {
    const CONTRACT='MON-STEP-1.0';const FILE='detail_stage.php';const MAX_BYTES=8192;
    const LABELS=['fetch'=>'종목 자료 조회','reuse'=>'검증 자료 재사용','source_proof'=>'출처·종가 근접 확인','stock_saved'=>'종목 자료 보관','benchmark'=>'동일 기간 지수 확인','indicators'=>'지표·조건 계산','calculated'=>'자료 계산 완료 · 저장 확인 대기','checkpoint_saved'=>'중간 결과 보관 · GitHub 반영 대기','published'=>'GitHub 반영 확인','retry_wait'=>'재시도 대기','error'=>'자료 확인 필요'];
    private static function read(MonStore $store) {
        $path=$store->path(self::FILE);clearstatcache(true,$path);if(!is_file($path)||filesize($path)>self::MAX_BYTES)return null;
        try{$s=$store->readPrivate(self::FILE);if(mon_get($s,'contract')!==self::CONTRACT||!is_array(mon_get($s,'recent'))||count($s->recent)>6)return null;
            $rows=$s->recent;$rows[]=mon_get($s,'current');foreach($rows as $r){
                if(!($r instanceof stdClass)||array_diff(array_keys((array)$r),['symbol_id','name','request_hash','stage','label','at','provider','error'])||
                    !preg_match('/^(KR|US|JP)\|[A-Z0-9._-]+\|[A-Za-z0-9._-]+$/',(string)mon_get($r,'symbol_id'))||
                    !preg_match('/^[a-f0-9]{64}$/',(string)mon_get($r,'request_hash'))||!isset(self::LABELS[mon_get($r,'stage','')])||
                    mon_get($r,'label')!==self::LABELS[$r->stage]||!is_string(mon_get($r,'name'))||!preg_match('/^.{0,60}$/us',$r->name)||
                    mon_time(mon_get($r,'at'))===null||strlen(mon_json($r,false))>1200)return null;
                foreach(['provider'=>32,'error'=>64]as $field=>$length)if(mon_get($r,$field)!==null&&!preg_match('/^[A-Za-z0-9_.-]{1,'.$length.'}$/',(string)$r->$field))return null;
            }return $s;
        }catch(Throwable $e){return null;}
    }
    public static function mark(?MonStore $store,$request,string $stage,string $provider='',?string $error=null): void {
        if(!$store||!isset(self::LABELS[$stage]))return;
        try{
            $sid=mon_get($request,'symbol_id');MonRun::identity($sid);$hash=(string)mon_get($request,'request_hash');if(!preg_match('/^[a-f0-9]{64}$/',$hash))return;
            $name=(string)mon_get(mon_get($request,'symbol'),'name',$sid);preg_match('/^.{0,60}/us',$name,$match);$name=$match[0]??$sid;
            $row=(object)['symbol_id'=>$sid,'name'=>$name,'request_hash'=>$hash,'stage'=>$stage,'label'=>self::LABELS[$stage],'at'=>mon_iso(),
                'provider'=>preg_match('/^[A-Za-z0-9_.-]{1,32}$/',$provider)?$provider:null,'error'=>$error!==null&&preg_match('/^[A-Za-z0-9_.-]{1,64}$/',$error)?$error:null];
            $old=self::read($store);$last=mon_get($old,'current');
            if($last instanceof stdClass&&$last->request_hash===$hash&&$last->stage===$stage&&mon_get($last,'provider')===$row->provider&&mon_get($last,'error')===$row->error)return;
            $recent=mon_get($old,'recent',[]);$recent[]=$row;$state=(object)['contract'=>self::CONTRACT,'current'=>$row,'recent'=>array_slice($recent,-6)];
            while(strlen(mon_json($state))+strlen(MON_SETTINGS_PREFIX)>self::MAX_BYTES&&count($state->recent)>1)array_shift($state->recent);
            $store->writePrivate(self::FILE,$state);
        }catch(Throwable $e){}
    }
    public static function published(MonStore $store,$data): void {
        $state=self::read($store);$row=mon_get($state,'current');if(!($row instanceof stdClass))return;
        try{$request=mon_get(MonDetails::requests($data),$row->symbol_id);$response=mon_get(mon_get($data->collection,'detail_responses'),$row->symbol_id);
            if($request instanceof stdClass&&$request->request_hash===$row->request_hash&&MonDetails::matching($request,$response)&&mon_time(mon_get($response,'completed_at'))>=mon_time($row->at))self::mark($store,$request,'published');
        }catch(Throwable $e){}
    }
    public static function view(MonStore $store,int $now,string $since=''): array {
        if($since!==''&&!preg_match('/^[a-f0-9]{64}$/',$since))throw new MonFault('MON_STEP_TOKEN_INVALID');
        $state=self::read($store);$row=mon_get($state,'current');$recent=mon_get($state,'recent',[]);
        // The fast endpoint reads only two small local files, never the market payload.
        $statusPath=$store->path('status.json');clearstatcache(true,$statusPath);$status=null;if(is_file($statusPath)&&filesize($statusPath)<=8192)try{$status=$store->read('status.json');}catch(Throwable $e){}
        $counts=mon_get($status,'detail_counts',new stdClass());$active=$row instanceof stdClass&&isset(self::LABELS[mon_get($row,'stage','')])&&
            !in_array($row->stage,['published','retry_wait','error'],true)&&($at=mon_time(mon_get($row,'at')))!==null&&$now-$at>=-5&&$now-$at<=90;
        $token=hash('sha256',mon_json((object)['current'=>$row,'recent'=>$recent],false));
        $out=['contract'=>self::CONTRACT,'version'=>MON_VERSION,'as_of'=>mon_iso($now),'snapshot_token'=>$token,'changed'=>$since!==$token,'active'=>$active,
            'work_pending'=>$active||(int)mon_get($counts,'pending',0)+(int)mon_get($counts,'retry_wait',0)>0,'current'=>$row,'recent'=>$since===$token?[]:$recent];
        while(strlen(mon_json($out,false))>3900&&count($out['recent'])>0)array_shift($out['recent']);return $out;
    }
}

final class MonDaily {
    private $http;private $collector;private $config;private $memo=[];private $store;
    public $partialFields=null;public $conflictDiagnostics=null;public $conflictArtifact=null;
    public function __construct(MonHttp $http,MonCollector $collector,MonConfig $config,?MonStore $store=null){$this->http=$http;$this->collector=$collector;$this->config=$config;$this->store=$store;}
    public static function retryable(string $code): bool {
        return preg_match('/^HTTP_TRANSPORT_(5|6|7|28|35|52|55|56|60)$/',$code)===1||
            in_array($code,['HTTP_CIRCUIT_OPEN','HTTP_408','HTTP_429','BUDGET_EXHAUSTED','HTTP_LATE_RESPONSE','DETAIL_LATE_RESPONSE','DETAIL_KR_REFERENCE_PENDING','DETAIL_ALTERNATIVE_SOURCE_PENDING'],true)||preg_match('/^HTTP_5\d\d$/',$code)===1;
    }
    private function checkpoint($request,?stdClass $source=null): ?stdClass {
        if(!$this->store)return null;
        $name='stock-checkpoint-'.hash('sha256',$request->symbol_id).'.php';
        try{
            if($source!==null){
                $bars=MonSwing::bars($source->bars);$last=end($bars);
                if($last[0]!==$request->target_completed_date)return null;
                $row=(object)['contract'=>'MON-STOCK-CHECKPOINT-1.0','request_hash'=>$request->request_hash,'symbol_id'=>$request->symbol_id,
                    'target_completed_date'=>$request->target_completed_date,'expires_at'=>$request->deadline_at,'source'=>$source,'sha256'=>MonRun::digest($source)];
                $this->store->writePrivate($name,$row);return $source;
            }
            $row=$this->store->readPrivate($name);
            if(mon_get($row,'contract')!=='MON-STOCK-CHECKPOINT-1.0'||mon_get($row,'request_hash')!==$request->request_hash||
                mon_get($row,'symbol_id')!==$request->symbol_id||mon_get($row,'target_completed_date')!==$request->target_completed_date||
                !hash_equals((string)mon_get($row,'sha256',''),MonRun::digest(mon_get($row,'source'))))return null;
            $saved=$row->source;$bars=MonSwing::bars($saved->bars);$last=end($bars);
            if(count($bars)<21||$last[0]!==$request->target_completed_date||mon_get($saved->origin,'price_basis')!==MonDetails::BASIS)return null;
            MonRun::utc(mon_get($saved->origin,'checked_at'));return $saved;
        }catch(Throwable $e){return null;}
    }
    
    private function benchmarkCached(array $identity,string $target,array $stock): ?stdClass {
        $key=$identity['id'].'|'.$target.'|'.MonDetails::BASIS.'|'.MON_VERSION;$file='benchmark-'.hash('sha256',$identity['id']).'.php';
        try{$c=$this->store?$this->store->readPrivate($file):null;$v=mon_get($c,'value');
            if(mon_get($c,'key')!==$key||!($v instanceof stdClass)||!hash_equals((string)mon_get($c,'sha256'),hash('sha256',MonRun::json($v)))||
                !is_array(mon_get($v,'bars'))||!$v->bars||end($v->bars)[0]!==$target||!self::comparableBenchmark($stock,$v))return null;return $v;
        }catch(Throwable $e){return null;}
    }
    private function saveBenchmark(array $identity,string $target,$benchmark): void {
        if(!$this->store||!is_array(mon_get($benchmark,'bars'))||!$benchmark->bars||end($benchmark->bars)[0]!==$target)return;
        $key=$identity['id'].'|'.$target.'|'.MonDetails::BASIS.'|'.MON_VERSION;$file='benchmark-'.hash('sha256',$identity['id']).'.php';
        $value=(object)['bars'=>$benchmark->bars,'origin'=>$benchmark->origin];$sha=hash('sha256',MonRun::json($value));
        $old=$this->store->readPrivate($file);if(mon_get($old,'key')===$key&&mon_get($old,'sha256')===$sha)return;
        $this->store->writePrivate($file,(object)['key'=>$key,'sha256'=>$sha,'value'=>$value]);
    }
    private static function comparableBenchmark(array $stock,$benchmark): bool {
        try{
            $dates=array_column(array_slice(MonSwing::bars($stock),-21),0);if(count($dates)!==21)return false;
            $values=MonSwing::benchmarkCloses($benchmark->bars);$end=end($dates);
            $available=array_values(array_filter(array_keys($values),static function($date)use($end){return $date<=$end;}));
            return array_slice($available,-21)===$dates;
        }catch(Throwable $e){return false;}
    }
    private function kisHeaders(string $tr,float $deadline,array $settings): array {
        return ['Content-Type: application/json','authorization: Bearer '.$this->collector->token($deadline,$settings),'appkey: '.$this->config->get('KIS_APP_KEY'),'appsecret: '.$this->config->get('KIS_APP_SECRET'),'tr_id: '.$tr,'custtype: P'];
    }
    private static function records($symbol,array $bars,string $provider): stdClass {
        $records=new stdClass();
        foreach($bars as $row){$raw=MonRun::json((object)['symbol_id'=>$symbol->symbol_id,'date'=>$row[0],'values'=>array_slice($row,1),'price_basis'=>MonDetails::BASIS,'provider'=>$provider]);
            $records->{$row[0]}=(object)['raw_json'=>$raw,'record_sha256'=>hash('sha256',$raw)];}
        return $records;
    }
    private function text(string $url,float $deadline,array $settings): string {
        $r=$this->http->request('GET',$url,['Accept: text/html, application/json'],null,$deadline,$settings['http_timeout_seconds'],$settings['request_spacing_seconds']);
        if(mon_get($r,'_deadline_missed')===true)throw new MonFault('HTTP_LATE_RESPONSE');
        if((int)$r['status']<200||(int)$r['status']>=300)MonHttp::decode($r);
        return (string)$r['body'];
    }
    public function fred($symbol,string $target,float $deadline,array $settings): stdClass {
        if($symbol->country!=='US'||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$target))throw new MonFault('DETAIL_FRED_ID_INVALID');
        $key='FRED|SP500|'.$target;if(isset($this->memo[$key]))return $this->memo[$key];
        // The CSV download can fail independently; the official table requires no API key.
        $url='https://fred.stlouisfed.org/data/SP500';$html=$this->text($url,$deadline,$settings);
        if(!preg_match('~<td>\s*SP500\s*</td>~',$html)||strpos($html,'data-table-observations')===false||strpos($html,'S&amp;P Dow Jones Indices')===false)throw new MonFault('DETAIL_FRED_RESPONSE_INVALID');
        if(!preg_match('~<div[^>]+id=["\x27]extra-rows["\x27][^>]*>(.*?)</div>~s',$html,$table))throw new MonFault('DETAIL_FRED_RESPONSE_INVALID');
        preg_match_all('/#(\d{4}-\d{2}-\d{2})\|([^#\r\n<]*)/',$table[1],$matches,PREG_SET_ORDER);$rows=[];$start=(new DateTimeImmutable($target))->modify('-6 months')->format('Y-m-d');
        foreach($matches as $v){$date=$v[1];if($date<$start||$date>$target)continue;
            if(isset($rows[$date]))throw new MonFault('DETAIL_FRED_DUPLICATE_DATE');$value=trim($v[2]);if($value==='.'||$value==='')continue;
            if(!preg_match('/^\d+(?:\.\d+)?$/',$value)||(new DateTimeImmutable($date))->format('Y-m-d')!==$date)throw new MonFault('DETAIL_FRED_VALUE_INVALID');
            $close=mon_number($value);if($close===null||$close<=0)throw new MonFault('DETAIL_FRED_VALUE_INVALID');$rows[$date]=[$date,null,null,null,$close,null];
        }
        ksort($rows,SORT_STRING);$bars=array_values($rows);if(count($bars)<21||end($bars)[0]!==$target)throw new MonFault('DETAIL_BENCHMARK_DATE_MISSING');MonSwing::benchmarkCloses($bars);
        return $this->memo[$key]=(object)['bars'=>$bars,'origin'=>(object)['source'=>'FRED_SP500','source_url'=>$url,'checked_at'=>mon_iso(),'complete_source_window'=>true,
            'benchmark_id'=>'US|INDEX|^GSPC','price_basis'=>'price_return_cash_dividend_unadjusted','source_record_sha256'=>hash('sha256',$html)]];
    }
    public function stockAnalysis($symbol,string $target,float $deadline,array $settings): stdClass {
        $us=$symbol->country==='US'&&$symbol->currency==='USD'&&in_array($symbol->exchange,['NASDAQ','NYSE','AMEX'],true)&&preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/',$symbol->symbol);
        $jp=$symbol->country==='JP'&&$symbol->currency==='JPY'&&$symbol->exchange==='TSE'&&preg_match('/^[0-9][0-9A-Z]{3}$/',$symbol->symbol);
        $kr=$symbol->country==='KR'&&$symbol->currency==='KRW'&&in_array($symbol->exchange,['KOSPI','KOSDAQ'],true)&&preg_match('/^\d{6}$/',$symbol->symbol);
        if(!$us&&!$jp&&!$kr)throw new MonFault('DETAIL_ALTERNATIVE_ID_INVALID');
        $code=$jp?'tyo':'krx';$key='SPG|'.$symbol->symbol_id.'|'.$target;if(isset($this->memo[$key]))return $this->memo[$key];$url='https://stockanalysis.com/'.($us?'stocks/'.strtolower($symbol->symbol):'quote/'.$code.'/'.strtolower($symbol->symbol)).'/history/';$html=$this->text($url,$deadline,$settings);
        $ticker=preg_quote($us?$symbol->symbol:strtoupper($code).'-'.$symbol->symbol,'~');$venue=$us?'ex:"'.preg_quote($symbol->exchange,'~').'"':'exchange_code:"'.strtoupper($code).'"';$currency=preg_quote($symbol->currency,'~');
        if(strpos($html,'past prices are adjusted for stock splits')===false||!preg_match('~'.$venue.'~',$html)||!preg_match('~curr:\{main:"'.$currency.'",price:"'.$currency.'"~',$html)||
            !preg_match('~data:\{id:\d+,symbol:"'.$ticker.'",source:"spg",[^\[{}]*data:\[(.*?)\]~s',$html,$match))throw new MonFault('DETAIL_ALTERNATIVE_RESPONSE_INVALID');
        preg_match_all('/\{([^{}]*)\}/',$match[1],$records);if(count($records[1])>1500)throw new MonFault('DETAIL_ALTERNATIVE_RESPONSE_INVALID');$rows=[];
        foreach($records[1]as $raw){$v=[];foreach(explode(',',$raw)as $part){if(!preg_match('/^\s*(t|o|h|l|c|v|a|ch|div):("\d{4}-\d{2}-\d{2}"|-?(?:\d+(?:\.\d*)?|\.\d+))\s*$/',$part,$field)||isset($v[$field[1]]))throw new MonFault('DETAIL_ALTERNATIVE_ROW_INVALID');$v[$field[1]]=trim($field[2],'"');}
            foreach(['t','o','h','l','c','v','a','ch']as $field)if(!isset($v[$field]))throw new MonFault('DETAIL_ALTERNATIVE_ROW_INVALID');
            if((count($v)!==8&&count($v)!==9)||(isset($v['div'])&&mon_number($v['div'])<0)||(new DateTimeImmutable($v['t']))->format('Y-m-d')!==$v['t'])throw new MonFault('DETAIL_ALTERNATIVE_ROW_INVALID');$date=$v['t'];if($date>$target)continue;
            if(isset($rows[$date]))throw new MonFault('DETAIL_ALTERNATIVE_DUPLICATE_DATE');$row=[$date];foreach(['o','h','l','c','v']as $field){$value=mon_number($v[$field]);if($value===null)throw new MonFault('DETAIL_ALTERNATIVE_ROW_INVALID');$row[]=$value;}$rows[$date]=$row;
        }
        ksort($rows,SORT_STRING);$bars=MonSwing::bars(array_values($rows));if(count($bars)<21||end($bars)[0]!==$target)throw new MonFault('DETAIL_RS_HISTORY_MISSING');
        $complete=count($bars)>=60&&$bars[0][0]<=(new DateTimeImmutable($target))->modify('-5 months')->format('Y-m-d');
        return $this->memo[$key]=(object)['bars'=>$bars,'origin'=>(object)['source'=>'SPG_STOCKANALYSIS','source_url'=>$url,'checked_at'=>mon_iso(),'complete_source_window'=>$complete,'price_basis'=>MonDetails::BASIS,
            'basis_evidence'=>'provider_explicit_split_adjusted_history_close_column_not_adj_close','source_record_sha256'=>hash('sha256',$html),'raw_records'=>self::records($symbol,$bars,'SPG_STOCKANALYSIS'),'original_provider'=>'SPG_STOCKANALYSIS']];
    }
    public function nasdaqRaw($symbol,string $target,float $deadline,array $settings): stdClass {
        if($symbol->country!=='US'||!preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/',$symbol->symbol))throw new MonFault('DETAIL_NASDAQ_ID_INVALID');
        $key='NASDAQ_RAW|'.$symbol->symbol.'|'.$target;if(isset($this->memo[$key]))return $this->memo[$key];
        $url='https://api.nasdaq.com/api/quote/'.rawurlencode($symbol->symbol).'/historical?'.http_build_query(['assetclass'=>'stocks','fromdate'=>(new DateTimeImmutable($target))->modify('-6 months')->format('Y-m-d'),'todate'=>$target,'limit'=>200]);
        $body=$this->text($url,$deadline,$settings);try{$r=mon_decode($body);}catch(Throwable $e){throw new MonFault('DETAIL_NASDAQ_RESPONSE_INVALID');}$data=mon_get($r,'data');$values=mon_get(mon_get($data,'tradesTable'),'rows');
        if(mon_get(mon_get($r,'status'),'rCode')!==200||mon_get($data,'symbol')!==$symbol->symbol||!is_array($values)||count($values)!==(int)mon_get($data,'totalRecords')||count($values)>200)throw new MonFault('DETAIL_NASDAQ_RESPONSE_INVALID');$rows=[];
        foreach($values as $value){$date=DateTimeImmutable::createFromFormat('!m/d/Y',(string)mon_get($value,'date'));if(!$date||$date->format('m/d/Y')!==mon_get($value,'date'))throw new MonFault('DETAIL_NASDAQ_ROW_INVALID');$day=$date->format('Y-m-d');if($day>$target)continue;
            if(isset($rows[$day]))throw new MonFault('DETAIL_NASDAQ_DUPLICATE_DATE');$row=[$day];foreach(['open','high','low','close','volume']as $field){$v=(string)mon_get($value,$field);if(!preg_match('/^\$?\d[\d,]*(?:\.\d+)?$/',$v))throw new MonFault('DETAIL_NASDAQ_ROW_INVALID');$n=mon_number(str_replace('$','',$v));if($n===null)throw new MonFault('DETAIL_NASDAQ_ROW_INVALID');$row[]=$n;}$rows[$day]=$row;
        }
        ksort($rows,SORT_STRING);$bars=MonSwing::bars(array_values($rows));if(count($bars)<21||end($bars)[0]!==$target)throw new MonFault('DETAIL_RS_HISTORY_MISSING');
        return $this->memo[$key]=(object)['bars'=>$bars,'origin'=>(object)['source'=>'NASDAQ_RAW','source_url'=>$url,'checked_at'=>mon_iso(),'price_basis'=>'provider_basis_unverified','source_record_sha256'=>hash('sha256',$body)]];
    }
    public static function wholeWindowProof($stock,$independent,string $currency='USD'): stdClass {
        return self::windowProof($stock,$independent,$currency,'NASDAQ_RAW');
    }
    private static function windowProof($stock,$independent,string $currency,string $referenceSource): stdClass {
        if(!($stock instanceof stdClass)||!($independent instanceof stdClass))throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');
        if(mon_get($stock->origin,'source')!=='SPG_STOCKANALYSIS'||mon_get($stock->origin,'price_basis')!==MonDetails::BASIS||mon_get($stock->origin,'complete_source_window')!==true||mon_get($independent->origin,'source')!==$referenceSource)throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');
        $a=MonSwing::bars($stock->bars);$b=MonSwing::bars($independent->bars);if(count($a)<60||array_column($a,0)!==array_column($b,0))throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');$volumes=0;$near=0;$delta=0.0;
        foreach($a as $i=>$bar){if(!MonClose::matches($bar[4],$b[$i][4],$currency))throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');$diff=abs($bar[4]-$b[$i][4]);if($diff>0)$near++;$delta=max($delta,$diff);if($bar[5]!==$b[$i][5])$volumes++;}
        return(object)['policy'=>'whole_new_provider_window_preserve_old_no_row_merge','verified_close_count'=>count($a),'stock_bars_sha256'=>MonRun::digest($a),
            'close_comparison'=>'half_display_unit','currency'=>$currency,'approximate_close_count'=>$near,'maximum_close_difference'=>$delta,'raw_prices_preserved'=>true,
            'corroborating_source_url'=>$independent->origin->source_url,'corroborating_record_sha256'=>$independent->origin->source_record_sha256,
            'volume_difference_count'=>$volumes,'volume_basis'=>'use_new_provider_entire_window_differences_preserved','checked_at'=>mon_iso()];
    }
    public static function krWindowProof($symbol,$stock,$reference): stdClass {
        $o=mon_get($reference,'origin');$url=mon_get($o,'source_url');$query=[];parse_str((string)parse_url((string)$url,PHP_URL_QUERY),$query);
        if(mon_get($symbol,'country')!=='KR'||mon_get($symbol,'currency')!=='KRW'||!in_array(mon_get($symbol,'exchange'),['KOSPI','KOSDAQ'],true)||
            !preg_match('/^\d{6}$/',(string)mon_get($symbol,'symbol'))||mon_get($o,'symbol_id')!==$symbol->symbol_id||mon_get($o,'market_code')!=='J'||mon_get($o,'requested_adjustment')!=='0'||
            parse_url((string)$url,PHP_URL_SCHEME)!=='https'||parse_url((string)$url,PHP_URL_HOST)!=='openapi.koreainvestment.com'||parse_url((string)$url,PHP_URL_PORT)!==9443||parse_url((string)$url,PHP_URL_PATH)!=='/uapi/domestic-stock/v1/quotations/inquire-daily-itemchartprice'||
            ($query['FID_COND_MRKT_DIV_CODE']??null)!=='J'||($query['FID_INPUT_ISCD']??null)!==$symbol->symbol||($query['FID_ORG_ADJ_PRC']??null)!=='0'||
            mon_get($o,'complete_source_window')!==true||!preg_match('/^[a-f0-9]{64}$/',(string)mon_get($o,'source_record_sha256'))||
            mon_get($stock->origin,'source_url')!=='https://stockanalysis.com/quote/krx/'.$symbol->symbol.'/history/')throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');
        $proof=self::windowProof($stock,$reference,'KRW','KIS_KRX_REFERENCE');$proof->symbol_id=$symbol->symbol_id;$proof->reference_market='KRX';$proof->reference_adjustment='0';$proof->corroborating_source='KIS_KRX_REFERENCE';return $proof;
    }
    public function krReference($symbol,$stock,float $deadline,array $settings): stdClass {
        if(mon_get($symbol,'country')!=='KR'||mon_get($symbol,'currency')!=='KRW'||!in_array(mon_get($symbol,'exchange'),['KOSPI','KOSDAQ'],true)||
            !preg_match('/^\d{6}$/',(string)mon_get($symbol,'symbol'))||mon_get($stock->origin,'source')!=='SPG_STOCKANALYSIS'||mon_get($stock->origin,'price_basis')!==MonDetails::BASIS||mon_get($stock->origin,'complete_source_window')!==true)throw new MonFault('DETAIL_KR_REFERENCE_ID_INVALID');
        $bars=MonSwing::bars($stock->bars);if(count($bars)<60)throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');$begin=$bars[0][0];$target=end($bars)[0];$stockSha=MonRun::digest($bars);
        $binding=MonRun::digest([$symbol->symbol_id,$symbol->exchange,'KRW',$begin,$target,$stockSha,'J','0']);$name='kr-reference-'.hash('sha256',$symbol->symbol_id).'.php';$saved=$this->store?$this->store->readPrivate($name):null;$state=mon_get($saved,'state');
        if($state instanceof stdClass&&!hash_equals((string)mon_get($saved,'sha256'),MonRun::digest($state)))throw new MonFault('DETAIL_KR_REFERENCE_CHECKPOINT_INVALID');
        if(!($state instanceof stdClass)||mon_get($state,'binding')!==$binding)$state=(object)['binding'=>$binding,'symbol_id'=>$symbol->symbol_id,'stock_bars_sha256'=>$stockSha,'begin'=>$begin,'target'=>$target,'next_end'=>$target,'rows'=>new stdClass(),'record_hashes'=>[],'pages'=>0,'complete'=>false,'source_url'=>null,'checked_at'=>null];
        if(!($state->rows instanceof stdClass)||!is_array($state->record_hashes)||!is_int($state->pages)||$state->pages<0||!is_bool($state->complete)||count(get_object_vars($state->rows))>20000||
            mon_get($state,'symbol_id')!==$symbol->symbol_id||mon_get($state,'stock_bars_sha256')!==$stockSha||mon_get($state,'begin')!==$begin||mon_get($state,'target')!==$target||
            !is_string(mon_get($state,'next_end'))||$state->next_end<$begin||$state->next_end>$target||count($state->record_hashes)!==$state->pages)throw new MonFault('DETAIL_KR_REFERENCE_CHECKPOINT_INVALID');
        $pageBudget=max(1,min(3,(int)($settings['kr_reference_pages_per_tick']??1)));$used=0;
        while(!$state->complete){
            if($this->http->remaining($deadline)<.05)throw new MonFault('DETAIL_KR_REFERENCE_PENDING',30);
            $end=$state->next_end;$query=['FID_COND_MRKT_DIV_CODE'=>'J','FID_INPUT_ISCD'=>$symbol->symbol,'FID_INPUT_DATE_1'=>str_replace('-','',$begin),'FID_INPUT_DATE_2'=>str_replace('-','',$end),'FID_PERIOD_DIV_CODE'=>'D','FID_ORG_ADJ_PRC'=>'0'];
            $url='https://openapi.koreainvestment.com:9443/uapi/domestic-stock/v1/quotations/inquire-daily-itemchartprice?'.http_build_query($query);
            $r=MonHttp::decode($this->http->request('GET',$url,$this->kisHeaders('FHKST03010100',$deadline,$settings),null,$deadline,$settings['http_timeout_seconds'],$settings['request_spacing_seconds']));
            if((string)mon_get($r,'rt_cd')!=='0')throw new MonFault('KIS_'.(string)mon_get($r,'msg_cd','ERROR'));$list=mon_get($r,'output2');if(!is_array($list)||!$list||count($list)>100)throw new MonFault('DETAIL_KR_REFERENCE_ROWS_INVALID');
            $identity=mon_get(mon_get($r,'output1'),'stck_shrn_iscd');if($identity!==null&&$identity!==$symbol->symbol)throw new MonFault('DETAIL_KR_REFERENCE_ID_INVALID');$earliest=null;$page=[];
            foreach($list as $v){$ymd=(string)mon_get($v,'stck_bsop_date');if(!preg_match('/^\d{8}$/',$ymd))throw new MonFault('DETAIL_KR_REFERENCE_DATE_INVALID');$date=substr($ymd,0,4).'-'.substr($ymd,4,2).'-'.substr($ymd,6,2);
                if($date<$begin||$date>$end||isset($page[$date]))throw new MonFault('DETAIL_KR_REFERENCE_DATE_INVALID');$row=[$date];foreach(['stck_oprc','stck_hgpr','stck_lwpr','stck_clpr','acml_vol']as $field){$n=mon_number(mon_get($v,$field));if($n===null)throw new MonFault('DETAIL_KR_REFERENCE_ROWS_INVALID');$row[]=$n;}
                MonSwing::bars([$row]);$old=mon_get($state->rows,$date);if($old!==null&&MonRun::json($old)!==MonRun::json($row))throw new MonFault('DETAIL_PRICE_BASIS_CONFLICT');$page[$date]=$row;$earliest=$earliest===null?$date:min($earliest,$date);
            }
            if($earliest===null||$earliest>$end)throw new MonFault('DETAIL_KR_REFERENCE_DATE_INVALID');foreach($page as $date=>$row)$state->rows->$date=$row;
            $state->pages++;$state->record_hashes[]=MonRun::digest($r);$state->source_url=$url;$state->checked_at=mon_iso();$state->complete=$earliest<=$begin||count($list)<100;
            if(!$state->complete)$state->next_end=(new DateTimeImmutable($earliest))->modify('-1 day')->format('Y-m-d');
            if($this->store)$this->store->writePrivate($name,(object)['state'=>$state,'sha256'=>MonRun::digest($state)]);
            $this->partialFields=(object)['reference'=>(object)['source'=>'KIS_KRX_REFERENCE','symbol_id'=>$symbol->symbol_id,'pages'=>$state->pages,'rows'=>count(get_object_vars($state->rows)),'complete'=>$state->complete,'next_end'=>$state->complete?null:$state->next_end]];
            if(!$state->complete&&$this->store&&++$used>=$pageBudget)throw new MonFault('DETAIL_KR_REFERENCE_PENDING',30);
        }
        $rows=get_object_vars($state->rows);ksort($rows,SORT_STRING);$reference=(object)['bars'=>MonSwing::bars(array_values($rows)),'origin'=>(object)['source'=>'KIS_KRX_REFERENCE','symbol_id'=>$symbol->symbol_id,'market_code'=>'J','requested_adjustment'=>'0',
            'price_basis'=>'provider_basis_unverified','complete_source_window'=>true,'source_url'=>$state->source_url,'checked_at'=>$state->checked_at,'source_record_sha256'=>MonRun::digest($state->record_hashes),'reference_pages'=>$state->pages]];
        if(array_column($reference->bars,0)!==array_column($bars,0))throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');return $reference;
    }
    public static function priorWindowProof($cached,$stock,string $currency='USD'): stdClass {
        if(!($cached instanceof stdClass)||!($stock instanceof stdClass)||mon_get($stock->origin,'source')!=='SPG_STOCKANALYSIS'||mon_get($stock->origin,'price_basis')!==MonDetails::BASIS||mon_get($stock->origin,'complete_source_window')!==true||mon_get($cached->history_metadata,'price_basis')!==MonDetails::BASIS||parse_url((string)mon_get($cached->origin,'source_url'),PHP_URL_HOST)!=='query1.finance.yahoo.com')throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');
        $fresh=MonSwing::bars($stock->bars);$old=MonSwing::bars($cached->bars);$by=[];foreach($fresh as $bar)$by[$bar[0]]=$bar;$count=0;$volumes=0;$near=0;$delta=0.0;
        $excluded=[];$first=$fresh[0][0];foreach($old as $bar){if($bar[0]<$first){$excluded[]=$bar[0];continue;}
            if(!isset($by[$bar[0]])||!MonClose::matches($by[$bar[0]][4],$bar[4],$currency))throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');$diff=abs($by[$bar[0]][4]-$bar[4]);if($diff>0)$near++;$delta=max($delta,$diff);$count++;if($by[$bar[0]][5]!==$bar[5])$volumes++;}
        if($count<60||end($old)[0]>end($fresh)[0])throw new MonFault('DETAIL_SOURCE_REPLACEMENT_UNVERIFIED');
        return(object)['policy'=>'whole_new_provider_window_prior_closes_agree','stock_bars_sha256'=>MonRun::digest($fresh),'previous_bars_sha256'=>MonRun::digest($old),
            'close_comparison'=>'half_display_unit','currency'=>$currency,'approximate_close_count'=>$near,'maximum_close_difference'=>$delta,'raw_prices_preserved'=>true,
            'verified_prior_close_count'=>$count,'prior_window_comparison'=>'actual_overlap_only_min60','overlap_start'=>$first,'overlap_end'=>end($old)[0],
            'excluded_old_dates'=>$excluded,'excluded_old_count'=>count($excluded),'prior_source_url'=>$cached->origin->source_url,'prior_checked_at'=>$cached->history_metadata->checked_at,
            'new_closes_from_verified_new_provider_only'=>count($fresh)-$count,'latest_close_independently_corroborated'=>false,'volume_difference_count'=>$volumes,'row_merge'=>false,'checked_at'=>mon_iso()];
    }
    public function yahoo($symbol,string $ticker,string $target,float $deadline,array $settings,bool $index=false): stdClass {
        $key=$ticker.'|'.$target;if(isset($this->memo[$key]))return $this->memo[$key];
        $url='https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($ticker).'?'.http_build_query(['interval'=>'1d','range'=>'6mo','events'=>'div,splits','includeAdjustedClose'=>'true']);
        $r=MonHttp::decode($this->http->request('GET',$url,['Accept: application/json'],null,$deadline,$settings['http_timeout_seconds'],$settings['request_spacing_seconds']));
        $result=mon_get(mon_get($r,'chart'),'result');if(mon_get(mon_get($r,'chart'),'error')!==null||!is_array($result)||!isset($result[0]))throw new MonFault('DETAIL_YAHOO_RESPONSE_INVALID');
        $data=$result[0];$meta=mon_get($data,'meta');if(strtoupper((string)mon_get($meta,'symbol'))!==strtoupper($ticker)||mon_get($meta,'exchangeTimezoneName')!==MonMarket::timezone($symbol->country)->getName())throw new MonFault('DETAIL_YAHOO_ID_INVALID');
        if(!$index){$venues=['KOSPI'=>['KSC'],'KOSDAQ'=>['KOE'],'NASDAQ'=>['NMS','NGM','NCM'],'NYSE'=>['NYQ'],'AMEX'=>['ASE'],'TSE'=>['JPX']];
            if(mon_get($meta,'currency')!==$symbol->currency||!in_array(mon_get($meta,'exchangeName'),$venues[$symbol->exchange]??[],true))throw new MonFault('DETAIL_YAHOO_ID_INVALID');}
        $timestamps=mon_get($data,'timestamp');$quotes=mon_get(mon_get($data,'indicators'),'quote');if(!is_array($timestamps)||!is_array($quotes)||!isset($quotes[0]))throw new MonFault('DETAIL_YAHOO_ROWS_INVALID');$q=$quotes[0];$rows=[];
        foreach($timestamps as $i=>$ts){if(!is_int($ts))throw new MonFault('DETAIL_YAHOO_DATE_INVALID');$date=(new DateTimeImmutable('@'.$ts))->setTimezone(MonMarket::timezone($symbol->country))->format('Y-m-d');if($date>$target)continue;
            $close=mon_number(mon_get($q,'close',[])[$i]??null);if($close===null)throw new MonFault('DETAIL_YAHOO_GAP');
            if($index)$row=[$date,null,null,null,$close,null];else{$row=[$date];foreach(['open','high','low','close','volume']as $field){$n=mon_number(mon_get($q,$field,[])[$i]??null);if($n===null)throw new MonFault('DETAIL_YAHOO_GAP');$row[]=$n;}}
            if(isset($rows[$date]))throw new MonFault('DETAIL_YAHOO_DUPLICATE_DATE');$rows[$date]=$row;
        }
        ksort($rows,SORT_STRING);$bars=array_values($rows);if(count($bars)<21)throw new MonFault('DETAIL_RS_HISTORY_MISSING');if($index)MonSwing::benchmarkCloses($bars);else MonSwing::bars($bars);
        $origin=(object)['source'=>'YAHOO','source_url'=>$url,'checked_at'=>mon_iso(),'complete_source_window'=>true,'price_basis'=>MonDetails::BASIS];
        if(!$index){$origin->raw_records=self::records($symbol,$bars,'YAHOO');$origin->original_provider='YAHOO';}
        return $this->memo[$key]=(object)['bars'=>$bars,'origin'=>$origin];
    }
    public function kisStock($symbol,string $target,float $deadline,array $settings,$cached): stdClass {
        $map=mon_get($symbol->source_codes,'kis');if(!($map instanceof stdClass))throw new MonFault('KIS_MAPPING_MISSING');$begin=(new DateTimeImmutable($target))->modify('-6 months')->format('Y-m-d');$end=$target;$rows=[];$basisKnown=true;$lastUrl='';
        for($page=0;$page<3;$page++){
            if($symbol->country==='KR'){$path='/uapi/domestic-stock/v1/quotations/inquire-daily-itemchartprice';$tr='FHKST03010100';$query=['FID_COND_MRKT_DIV_CODE'=>'J','FID_INPUT_ISCD'=>$symbol->symbol,'FID_INPUT_DATE_1'=>str_replace('-','',$begin),'FID_INPUT_DATE_2'=>str_replace('-','',$end),'FID_PERIOD_DIV_CODE'=>'D','FID_ORG_ADJ_PRC'=>'1'];}
            else{$path='/uapi/overseas-price/v1/quotations/dailyprice';$tr='HHDFS76240000';$query=['AUTH'=>'','EXCD'=>mon_get($map,'exchange_code'),'SYMB'=>mon_get($map,'symbol'),'GUBN'=>'0','BYMD'=>str_replace('-','',$end),'MODP'=>'0'];}
            $url='https://openapi.koreainvestment.com:9443'.$path.'?'.http_build_query($query);$lastUrl=$url;
            $r=MonHttp::decode($this->http->request('GET',$url,$this->kisHeaders($tr,$deadline,$settings),null,$deadline,$settings['http_timeout_seconds'],$settings['request_spacing_seconds']));
            if((string)mon_get($r,'rt_cd')!=='0')throw new MonFault('KIS_'.(string)mon_get($r,'msg_cd','ERROR'));$list=mon_get($r,'output2');if(!is_array($list)||!$list)throw new MonFault('DETAIL_KIS_ROWS_INVALID');$earliest=null;
            foreach($list as $v){$ymd=(string)mon_get($v,$symbol->country==='KR'?'stck_bsop_date':'xymd');if(!preg_match('/^\d{8}$/',$ymd))throw new MonFault('DETAIL_KIS_DATE_INVALID');$date=substr($ymd,0,4).'-'.substr($ymd,4,2).'-'.substr($ymd,6,2);if($date>$target)continue;$earliest=$earliest===null?$date:min($date,$earliest);if($date<$begin)continue;
                $fields=$symbol->country==='KR'?['stck_oprc','stck_hgpr','stck_lwpr','stck_clpr','acml_vol']:['open','high','low','clos','tvol'];$row=[$date];foreach($fields as $field){$n=mon_number(mon_get($v,$field));if($n===null)throw new MonFault('DETAIL_KIS_ROW_INVALID');$row[]=$n;}$rows[$date]=$row;
                if($symbol->country!=='KR'||!property_exists($v,'prtt_rate')||mon_number($v->prtt_rate)!==0.0||!in_array((string)mon_get($v,'revl_issu_reas','0'),['','0','00'],true))$basisKnown=false;
            }
            if($earliest===null)throw new MonFault('DETAIL_KIS_ROWS_INVALID');if($earliest<=$begin||count($list)<100)break;
            $end=(new DateTimeImmutable($earliest))->modify('-1 day')->format('Y-m-d');if($page===2)throw new MonFault('DETAIL_KIS_WINDOW_INCOMPLETE');
        }
        if(!$basisKnown)throw new MonFault('DETAIL_KIS_SPLIT_BASIS_UNVERIFIED');ksort($rows,SORT_STRING);$bars=MonSwing::bars(array_values($rows));if(count($bars)<21)throw new MonFault('DETAIL_RS_HISTORY_MISSING');
        // Raw broker prices are used only where split fields explicitly establish the basis.
        return (object)['bars'=>$bars,'origin'=>(object)['source'=>'KIS','source_url'=>$lastUrl,'checked_at'=>mon_iso(),'complete_source_window'=>true,'price_basis'=>MonDetails::BASIS,'raw_records'=>self::records($symbol,$bars,'KIS'),'original_provider'=>'KIS']];
    }
    public function kisIndex($symbol,string $target,string $code,float $deadline,array $settings): stdClass {
        $begin=(new DateTimeImmutable($target))->modify('-60 days')->format('Ymd');$url='https://openapi.koreainvestment.com:9443/uapi/domestic-stock/v1/quotations/inquire-daily-indexchartprice?'.http_build_query(['FID_COND_MRKT_DIV_CODE'=>'U','FID_INPUT_ISCD'=>$code,'FID_INPUT_DATE_1'=>$begin,'FID_INPUT_DATE_2'=>str_replace('-','',$target),'FID_PERIOD_DIV_CODE'=>'D']);
        $r=MonHttp::decode($this->http->request('GET',$url,$this->kisHeaders('FHKUP03500100',$deadline,$settings),null,$deadline,$settings['http_timeout_seconds'],$settings['request_spacing_seconds']));if((string)mon_get($r,'rt_cd')!=='0')throw new MonFault('KIS_INDEX_FAILED');$rows=[];
        foreach(mon_get($r,'output2',[])as $v){$ymd=(string)mon_get($v,'stck_bsop_date');if(!preg_match('/^\d{8}$/',$ymd))continue;$date=substr($ymd,0,4).'-'.substr($ymd,4,2).'-'.substr($ymd,6,2);$close=mon_number(mon_get($v,'bstp_nmix_prpr'));if($date<=$target&&$close>0)$rows[$date]=[$date,null,null,null,$close,null];}
        ksort($rows,SORT_STRING);$bars=array_values($rows);if(count($bars)<21)throw new MonFault('DETAIL_BENCHMARK_DATE_MISSING');return(object)['bars'=>$bars,'origin'=>(object)['source'=>'KIS','source_url'=>$url,'checked_at'=>mon_iso(),'complete_source_window'=>true]];
    }
    public function fetch($request,$cached,float $deadline,array $settings): stdClass {
        $this->partialFields=null;$this->conflictDiagnostics=null;$this->conflictArtifact=null;$symbol=$request->symbol;$target=$request->target_completed_date;$errors=[];$bench=null;$bm=MonDetails::benchmark($symbol);
        MonDetailStep::mark($this->store,$request,'fetch');
        $stock=$this->checkpoint($request);$resumed=$stock!==null;$left=$this->http->remaining($deadline);
        if($left<.05)throw new MonFault('BUDGET_EXHAUSTED');
        // Reserve both the stock alternative and benchmark; the first provider cannot spend the whole symbol budget.
        $stockDeadline=$resumed?$deadline:microtime(true)+max(.05,$left-min(3.0,max(.1,$left*.35)));
        $kisDeadline=microtime(true)+max(.02,$this->http->remaining($stockDeadline)*.45);
        $routeName='daily-route-'.hash('sha256',$request->symbol_id).'.php';$route=$this->store?$this->store->readPrivate($routeName):null;
        $prefer=mon_get($route,'target_completed_date')===$target&&mon_get($route,'provider')==='SPG_STOCKANALYSIS';
        if($stock===null&&!$prefer)try{MonDetailStep::mark($this->store,$request,'fetch','KIS');$stock=$this->kisStock($symbol,$target,min($stockDeadline,$kisDeadline),$settings,$cached);}catch(MonFault $e){$errors[]=$e->faultCode;}
        if($stock===null&&!$prefer){$map=mon_get($symbol->source_codes,'yahoo');$ticker=is_string($map)?$map:mon_get($map,'symbol');if(!is_string($ticker))throw new MonFault('YAHOO_MAPPING_MISSING');
            try{MonDetailStep::mark($this->store,$request,'fetch','YAHOO');$stock=$this->yahoo($symbol,$ticker,$target,$stockDeadline,$settings);}catch(MonFault $e){
                $errors[]=$e->faultCode;
                if($this->store)$this->store->writePrivate($routeName,(object)['target_completed_date'=>$target,'provider'=>'SPG_STOCKANALYSIS','reason'=>$e->faultCode,'alternative_failures'=>(int)mon_get($route,'alternative_failures',0)]);$prefer=true;
            }}
        if($stock===null){
            MonDetailStep::mark($this->store,$request,'fetch','SPG_STOCKANALYSIS');
            try{$stock=$this->stockAnalysis($symbol,$target,$stockDeadline,$settings);}
            catch(MonFault $e){
                // A provider-level 404 or truncated 21-day history is NOT a
                // verified identity failure and must not permanently block
                // another valid provider's existing code/symbol mapping.
                if(!in_array($e->faultCode,['HTTP_404','DETAIL_RS_HISTORY_MISSING'],true))throw $e;
                $priorRoute=$this->store?$this->store->readPrivate($routeName):null;
                $failures=max(1,(int)mon_get($priorRoute,'alternative_failures',0)+1);
                if($this->store)$this->store->writePrivate($routeName,(object)[
                    'target_completed_date'=>$target,'provider'=>'KIS',
                    'reason'=>$e->faultCode,'alternative_failures'=>$failures,
                    'retry_after'=>mon_iso(time()+300)]);
                if($failures>=3)throw new MonFault('DETAIL_PROVIDER_UNAVAILABLE');
                throw new MonFault('DETAIL_ALTERNATIVE_SOURCE_PENDING',300);
            }
        }
        $this->checkpoint($request,$stock);
        MonDetailStep::mark($this->store,$request,'source_proof',(string)mon_get($stock->origin,'source',''));
        if($cached instanceof stdClass){$comparison=MonBarConflict::compare($cached,$stock,$symbol);$this->conflictDiagnostics=$comparison['diagnostics'];
            if($comparison['conflict']){
                $this->conflictArtifact=(object)['symbol_id'=>$symbol->symbol_id,'target_completed_date'=>$target,'previous_verified_snapshot'=>$cached,'conflicting_stock_source'=>$stock,'diagnostics'=>$comparison['diagnostics']];
                // A verified complete replacement is explicit; never loosen the old row-conflict rule.
                if(mon_get($stock->origin,'source')!=='SPG_STOCKANALYSIS'){
                    if($this->store)$this->store->writePrivate($routeName,(object)['target_completed_date'=>$target,'provider'=>'SPG_STOCKANALYSIS','reason'=>'DETAIL_PRICE_BASIS_CONFLICT']);
                    $stock=$this->stockAnalysis($symbol,$target,$deadline,$settings);$this->checkpoint($request,$stock);
                }
                $proof=mon_get($stock->origin,'history_resolution');
                if(!($proof instanceof stdClass)||!in_array(mon_get($proof,'policy'),['whole_new_provider_window_preserve_old_no_row_merge','whole_new_provider_window_prior_closes_agree'],true)||!hash_equals((string)mon_get($proof,'stock_bars_sha256'),MonRun::digest($stock->bars))){
                    try{$proof=self::priorWindowProof($cached,$stock,$symbol->currency);}
                    catch(MonFault $e){if(!in_array($symbol->country,['US','KR'],true))throw new MonFault('DETAIL_PRICE_BASIS_CONFLICT');
                        try{if($symbol->country==='KR'){$reference=$this->krReference($symbol,$stock,$deadline,$settings);$this->conflictArtifact->independent_reference=$reference;$proof=self::krWindowProof($symbol,$stock,$reference);}
                            else $proof=self::wholeWindowProof($stock,$this->nasdaqRaw($symbol,$target,$deadline,$settings),$symbol->currency);}
                    catch(MonFault $e){$this->conflictArtifact->reference_error_code=$e->faultCode;if($e->faultCode==='DETAIL_SOURCE_REPLACEMENT_UNVERIFIED')throw new MonFault('DETAIL_PRICE_BASIS_CONFLICT');throw $e;}
                    }
                    $stock->origin->history_resolution=$proof;$this->checkpoint($request,$stock);
                }
                $this->conflictArtifact->resolution=$proof;$this->conflictArtifact->accepted_new_source=$stock;
            }else $stock->bars=$comparison['bars'];}
        $saved=$this->checkpoint($request,$stock)!==null;
        if($saved)MonDetailStep::mark($this->store,$request,'stock_saved');
        $last=end($stock->bars);$this->partialFields=(object)['stock'=> (object)['last_close'=>$last[4],'bars_as_of'=>$last[0],'bar_count'=>count($stock->bars),'source_checked_at'=>$stock->origin->checked_at,'source'=>$stock->origin->source,'benchmark_status'=>'pending','current_signal_verified'=>false],
            'recovery'=>(object)['stock_checkpoint_saved'=>$saved,'stock_checkpoint_reused'=>$resumed,'resume_stage'=>'benchmark','missing_fields'=>['benchmark_same_21_sessions'],'provider_attempt_errors'=>$errors]];
        MonDetailStep::mark($this->store,$request,'benchmark');
        if($cached instanceof stdClass&&mon_get($cached,'benchmark_id')===$bm['id']){
            $candidate=(object)['bars'=>$cached->benchmark_bars,'origin'=>$cached->benchmark_origin];
            if(self::comparableBenchmark($stock->bars,$candidate))$bench=$candidate;
        }
        if($bench===null)$bench=$this->benchmarkCached($bm,$target,$stock->bars);
        if($bench===null&&$bm['kis']!==null){$kisDeadline=microtime(true)+max(.02,$this->http->remaining($deadline)*.45);
            try{$bench=$this->kisIndex($symbol,$target,$bm['kis'],min($deadline,$kisDeadline),$settings);}catch(MonFault $e){$errors[]=$e->faultCode;}}
        if($bench===null)try{$bench=$this->yahoo($symbol,$bm['ticker'],$target,$deadline,$settings,true);}catch(MonFault $e){
            $errors[]=$e->faultCode;$this->partialFields->recovery->provider_attempt_errors=$errors;if($symbol->country!=='US')throw $e;
            $bench=$this->fred($symbol,$target,$deadline,$settings);
        }
        MonDetailStep::mark($this->store,$request,'indicators');
        $this->saveBenchmark($bm,$target,$bench);
        $s=MonDetails::snapshot($symbol,$stock->bars,$bench->bars,$bm['id'],$stock->origin,$bench->origin);$s->provider_attempt_errors=$errors;
        $this->partialFields->stock->benchmark_status='verified';$this->partialFields->recovery->missing_fields=[];$this->partialFields->recovery->resume_stage='complete';return $s;
    }
}

final class MonDetailWorker {
    private $daily;private $store;private $clock;
    public function __construct(MonDaily $daily,MonStore $store,?callable $clock=null){$this->daily=$daily;$this->store=$store;$this->clock=$clock?:static function(){return time();};}
    public function processOne($d,float $deadline,array $settings,?stdClass $sourcePayload=null): array {
        $now=(int)($this->clock)();$requests=MonDetails::requests($d);$responses=mon_get($d->collection,'detail_responses',new stdClass());
        $cache=$this->store->read('detail_cache.json');if(!($cache instanceof stdClass))$cache=new stdClass();
        if(microtime(true)>=$deadline-.05)return ['changed'=>false,'responses'=>$responses,'processed_symbol_id'=>null];
        $list=[];foreach($requests as $sid=>$r)try{MonDetails::validRequest($r,$sid);$list[]=$r;}catch(MonFault $e){}
        foreach(MonDiscovery::schedule($list)as $r){
            $previous=mon_get($responses,$r->symbol_id);if(!MonDetails::workDue($d,$r,$previous,$now))continue;
            $match=MonDetails::matching($r,$previous);$attempts=$match?(int)mon_get($previous,'attempts',0):0;
            $cached=null;$snapshot=null;$packed=null;$error=null;$conflict=false;$state='done';$status='no_data';$conflictRef=null;$retryDelay=30;
            $serialized=0;$counted=false;$attemptStart=mon_monotonic();$oldActive=MonExecution::$active;$oldStore=MonExecution::$store;
            $this->daily->partialFields=null;$this->daily->conflictDiagnostics=null;$this->daily->conflictArtifact=null;
            $execution=mon_get($r,'execution_limits');$attemptLimit=(int)mon_get($execution,'attempts',2);
            $symbolSeconds=min((float)($settings['detail_symbol_budget_seconds']??8),(float)mon_get($execution,'symbol_seconds',8));
            try{
                $cached=MonDetails::cached($d,$r->symbol,$sourcePayload);$local=mon_get($cache,$r->symbol_id);
                if($local instanceof stdClass)try{
                    $candidate=MonDetails::unpack($local);
                    if($candidate->symbol_id!==$r->symbol_id||$candidate->criteria_version!==MonSwing::CRITERIA||$candidate->history_metadata->price_basis!==MonDetails::BASIS||
                        !hash_equals($candidate->history_metadata->bars_sha256,MonRun::digest(MonSwing::bars($candidate->bars))))throw new MonFault('DETAIL_CACHE_INVALID');
                    if($cached===null||$candidate->metrics->bars_as_of>$cached->metrics->bars_as_of)$cached=$candidate;
                }catch(Throwable $e){}
                $reuse=$cached instanceof stdClass&&$cached->metrics->bars_as_of===$r->target_completed_date&&$r->target_verified&&!$r->force_refresh;
                $repair=$this->store->readPrivate('detail-serialization-'.$r->request_hash.'.php');
                if($repair instanceof stdClass&&mon_get($repair,'input_sha256')===mon_get(mon_get(mon_get($d->analysis,'input_snapshot'),'lossless_payload'),'sha256')&&
                    hash_equals((string)mon_get($repair,'snapshot_sha256'),hash('sha256',MonRun::json(mon_get($repair,'snapshot'))))&&
                    mon_get(mon_get($repair,'snapshot'),'symbol_id')===$r->symbol_id&&mon_get(mon_get($repair->snapshot,'metrics'),'bars_as_of')===$r->target_completed_date){
                    $snapshot=$repair->snapshot;$status=$r->target_verified?'latest':'previous_verified';MonDetailStep::mark($this->store,$r,'reuse');
                }elseif($reuse){$snapshot=$cached;$status='reused_current';MonDetailStep::mark($this->store,$r,'reuse');}
                else{
                    $rid=mon_get($r,'run_context_id');
                    if(is_string($rid)&&is_file($this->store->path('run-'.$rid.'.php'))){MonExecution::resume($this->store,$rid);$b=MonExecution::$active;
                        if($b->run->received_at!==$r->run_received_at||$b->run->mode!==$r->run_mode)throw new MonFault('DETAIL_RUN_CONTEXT_INVALID');MonExecution::continueWork();$b->guard('collection');}
                    $attempts++;$counted=true;$snapshot=$this->daily->fetch($r,$cached,min($deadline,microtime(true)+$symbolSeconds),$settings);
                    $status=$snapshot->metrics->bars_as_of===$r->target_completed_date&&$r->target_verified?'latest':'previous_verified';
                }
                $serialized++;$packed=MonDetails::pack($snapshot);
                $trial=clone $cache;$trial->{$r->symbol_id}=$packed;
                foreach(get_object_vars($trial)as $sid=>$unused)if(!property_exists($requests,$sid))unset($trial->$sid);
                if(MonRun::json($trial)!==MonRun::json($cache))$this->store->write('detail_cache.json',$trial);
                $cache=$trial;
            }catch(MonStop $e){throw $e;}
            catch(MonFault $e){
                if(strpos($e->faultCode,'STATE_')===0||$e->faultCode==='MON_RUN_INTERRUPTED')throw $e;
                $error=$e->faultCode;$conflict=$error==='DETAIL_PRICE_BASIS_CONFLICT';$packed=null;
                $status=$cached?'previous_verified':'no_data';
                $retryDelay=max(min(300,30*(2**min(4,max(0,$attempts-$attemptLimit)))),$e->retryAfter);
                if(in_array($error,['DETAIL_PAYLOAD_TOO_LARGE','DETAIL_PAYLOAD_INVALID','DETAIL_PAYLOAD_HASH_INVALID'],true)){
                    $state='serialization_pending';
                    if($snapshot instanceof stdClass)$this->store->writePrivate('detail-serialization-'.$r->request_hash.'.php',
                        (object)['request_hash'=>$r->request_hash,'snapshot_sha256'=>hash('sha256',MonRun::json($snapshot)),'snapshot'=>$snapshot,'codec'=>MonDetails::CODEC,
                            'input_sha256'=>mon_get(mon_get(mon_get($d->analysis,'input_snapshot'),'lossless_payload'),'sha256')]);
                }elseif($conflict)$state='conflict';
                elseif(MonDaily::retryable($error))$state='retry_wait';
                else $state='blocked';
            }catch(Throwable $e){
                $error='DETAIL_PROCESSING_FAILED';$state='blocked';$packed=null;$snapshot=null;$status=$cached?'previous_verified':'no_data';
                $this->store->log('detail_processing_failed',['symbol_id'=>$r->symbol_id,'code'=>$error]);
            }finally{
                try{if($counted){MonExecution::measure('detail/fetch',mon_monotonic()-$attemptStart,$error,1,'server',$attempts>1);
                    MonExecution::event('detail_attempt',['symbol_id'=>$r->symbol_id,'attempts'=>$attempts,'code'=>$error,'previous_data'=>$cached!==null]);}}
                finally{if($oldActive&&MonExecution::$active&&$oldActive->run->run_context_id===MonExecution::$active->run->run_context_id)$oldActive->run=MonExecution::$active->run;
                    MonExecution::$active=$oldActive;MonExecution::$store=$oldStore;}
            }
            $now=(int)($this->clock)();
            if($this->daily->conflictArtifact instanceof stdClass){$raw=MonRun::json($this->daily->conflictArtifact);$sha=hash('sha256',$raw);
                try{MonArtifact::card($this->store,$sha,$this->daily->conflictArtifact);$conflictRef=(object)['sha256'=>$sha,'bytes'=>strlen($raw),'content_ref'=>'mon_card_read','diagnostic_count'=>count($this->daily->conflictDiagnostics)];}
                catch(MonFault $e){$this->store->log('conflict_artifact_unavailable',['symbol_id'=>$r->symbol_id,'code'=>$e->faultCode]);}}
            $response=(object)['contract'=>MonDetails::CONTRACT,'request_id'=>$r->request_id,'request_hash'=>$r->request_hash,'symbol_id'=>$r->symbol_id,'state'=>$state,'data_status'=>$status,
                'attempts'=>$attempts,'serialization_attempts'=>($match?(int)mon_get($previous,'serialization_attempts',0):0)+$serialized,
                'completed_at'=>mon_iso($now),'retry_after'=>$state==='retry_wait'?$now+$retryDelay:null,'error'=>$error,'conflict'=>$conflict,'usable'=>$packed!==null&&!$conflict,
                'previous_verified_available'=>$cached!==null,'recovery_signature'=>MonDetails::recoverySignature($d,$r),
                'limit_behavior'=>'observe_and_continue','time_excess_seconds'=>max(0,$now-MonRun::seconds($r->deadline_at)),'attempt_excess'=>max(0,$attempts-$attemptLimit),
                'bars_as_of'=>$snapshot?$snapshot->metrics->bars_as_of:null,'source_checked_at'=>$snapshot?$snapshot->history_metadata->checked_at:null,'partial_fields'=>$this->daily->partialFields,
                'conflict_diagnostics'=>$this->daily->conflictDiagnostics===null?null:array_slice($this->daily->conflictDiagnostics,0,1),'conflict_artifact'=>$conflictRef,'payload'=>$packed];
            MonDetailStep::mark($this->store,$r,MonDetails::retryPending($response)?'retry_wait':($error!==null||$snapshot===null?'error':'calculated'),'',$error??($snapshot===null?'DETAIL_RESULT_NO_DATA':null));
            $responses->{$r->symbol_id}=$response;return ['changed'=>true,'responses'=>$responses,'processed_symbol_id'=>$r->symbol_id,'response'=>$response];
        }
        return ['changed'=>false,'responses'=>$responses,'processed_symbol_id'=>null];
    }
    public function processAvailable($d,float $deadline,array $settings,?callable $onResponse=null): array {
        $start=mon_monotonic();$monoEnd=$start+max(0,$deadline-microtime(true));$jobs=[];$payload=null;$payloadHash=null;
        for($i=0;$i<($settings['detail_jobs_per_tick']??3)&&microtime(true)<$deadline-.05&&mon_monotonic()<$monoEnd-.05;$i++){
            $hash=mon_get(mon_get(mon_get($d->analysis,'input_snapshot'),'lossless_payload'),'sha256');
            if($payload===null||$hash!==$payloadHash){try{$payload=MonRun::payload($d);}catch(MonFault $e){$payload=new stdClass();}$payloadHash=$hash;}
            $job=$this->processOne($d,min($deadline,microtime(true)+max(0,$monoEnd-mon_monotonic())),$settings,$payload);if(!$job['changed'])break;
            $d->collection->detail_responses=$job['responses'];$jobs[]=$job;
            // The callback checkpoints every symbol before the next bounded job;
            // it may return the conflict-merged latest source after publication.
            if($onResponse){$next=$onResponse($d,$job);if($next instanceof stdClass)$d=$next;}
        }
        return ['changed'=>count($jobs)>0,'data'=>$d,'jobs'=>$jobs,'handler_seconds'=>mon_monotonic()-$start];
    }
}

final class MonProgress {
    const CONTRACT='MON-PROGRESS-1.0';
    public static function build($d,int $now,string $since='',array $unpublished=[],int $cursor=0): array {
        if($since!==''&&!preg_match('/^[a-f0-9]{64}$/',$since))throw new MonFault('MON_PROGRESS_TOKEN_INVALID');
        $counts=['requests'=>0,'done'=>0,'pending'=>0,'retry_wait'=>0,'serialization_pending'=>0,'blocked'=>0,'conflict'=>0,'current_missing'=>0,'latest'=>0,'reused'=>0,'previous'=>0,'no_data'=>0,'invalid'=>0,'timed_out'=>0,'threshold_exceeded'=>0];$rows=[];$first=null;$last=null;
        foreach(MonDetails::requests($d)as $sid=>$r){
            $counts['requests']++;$row=(object)['symbol_id'=>$sid,'name'=>mon_get(mon_get($r,'symbol'),'name',$sid),'country'=>explode('|',$sid)[0],
                'request_id'=>mon_get($r,'request_id'),'request_hash'=>mon_get($r,'request_hash'),'state'=>'pending','data_status'=>'no_response',
                'deadline_at'=>mon_get($r,'deadline_at'),'bars_as_of'=>null,'source_checked_at'=>null,'completed_at'=>null,'attempts'=>0,'error'=>null,'payload_sha256'=>null,'publication'=>'stored'];
            try{MonDetails::validRequest($r,$sid);$response=mon_get(mon_get($d->collection,'detail_responses'),$sid);
                if(MonDetails::matching($r,$response)){
                    foreach(['state','data_status','bars_as_of','source_checked_at','completed_at','attempts','error','retry_after']as $key)if(property_exists($response,$key))$row->$key=$response->$key;
                    $row->payload_sha256=mon_get(mon_get($response,'payload'),'sha256');
                    // Progress reports producer metadata only; full snapshot checks
                    // and ChatGPT competition still occur before final selection.
                    $row->usable_reported=mon_get($response,'usable')===true;
                    if(!in_array($row->state,['done','retry_wait','serialization_pending','blocked','conflict'],true))throw new MonFault('DETAIL_RESPONSE_STATE_INVALID');
                    $at=mon_time($row->completed_at);if($at!==null){$first=$first===null?$at:min($first,$at);$last=$last===null?$at:max($last,$at);}
                }
                if($row->state==='done'&&MonDetails::retryPending($response))$row->state='retry_wait';
                $row->time_excess_seconds=max(0,$now-MonRun::seconds($r->deadline_at));$row->limit_behavior='observe_and_continue';
            }catch(MonFault $e){$row->state='invalid';$row->error=$e->faultCode;}
            if(in_array($row->state,['done','retry_wait','serialization_pending','blocked','conflict','invalid'],true))$counts[$row->state]++;
            else $counts['pending']++;
            if(mon_get($row,'time_excess_seconds',0)>0)$counts['threshold_exceeded']++;
            if(in_array($row->error,['HTTP_TRANSPORT_28','HTTP_408','HTTP_LATE_RESPONSE','DETAIL_LATE_RESPONSE'],true))$counts['timed_out']++;
            if($row->data_status==='latest')$counts['latest']++;
            elseif($row->data_status==='reused_current')$counts['reused']++;
            elseif(in_array($row->data_status,['previous_verified','saved_reference'],true))$counts['previous']++;
            elseif($row->state==='done'&&$row->data_status==='no_data')$counts['no_data']++;
            $target=MonDetails::target($d->watchlist,explode('|',$sid)[0],$now);$row->target_completed_date=$target['date'];if(!$target['verified']||$row->state!=='done'||!mon_get($row,'usable_reported',false)||$row->bars_as_of!==$target['date'])$counts['current_missing']++;
            if(isset($unpublished[$sid]))$row->publication='pending';$rows[]=$row;
        }
        usort($rows,static function($a,$b){return strcmp($a->symbol_id,$b->symbol_id);});
        $phase=$counts['requests']===0?'idle':($counts['done']+$counts['invalid']===$counts['requests']?'collection_complete':'collecting');
        $tokenRows=array_map(static function($row){$copy=clone $row;unset($copy->time_excess_seconds);return $copy;},$rows);
        $token=MonRun::digest((object)['contract'=>self::CONTRACT,'cards'=>$tokenRows]);$meta=mon_get($d,'_mon_cache');
        if($cursor<0||$cursor>count($rows)||($cursor>0&&$since!==$token))throw new MonFault('MON_PROGRESS_CURSOR_INVALID');
        $out=['contract'=>self::CONTRACT,'version'=>MON_VERSION,'criteria_version'=>MonSwing::CRITERIA,'as_of'=>mon_iso($now),'phase'=>$phase,'counts'=>$counts,
            'snapshot_token'=>$token,'changed'=>$since!==$token,'cards'=>[],'first_response_at'=>$first===null?null:mon_iso($first),'last_response_at'=>$last===null?null:mon_iso($last),
            'source_blob_sha'=>mon_get($meta,'blob_sha'),'source_fetched_at'=>mon_get($meta,'fetched_at'),'publication'=>count($unpublished)>0?'pending':'stored',
            'final_selector'=>'ChatGPT','auto_selected'=>false,'final_decision_complete'=>false,'full_snapshot_validation_required'=>true,
            'page'=>['cursor'=>$cursor,'next_cursor'=>null,'total_cards'=>count($rows)]];
        if($since===$token&&$cursor===0)return $out;
        $i=$cursor;for(;$i<count($rows);$i++){
            $row=$rows[$i];$test=$out;$test['cards'][]=$row;
            if(strlen(MonRun::json((object)$test))>3900){
                if($out['cards'])break;
                $row=(object)['symbol_id'=>$row->symbol_id,'card_kind'=>'source_reference','metadata_sha256'=>hash('sha256',MonRun::json($row)),
                    'state'=>$row->state,'data_status'=>$row->data_status,'source_ref'=>'watchlist.detail_requests/collection.detail_responses','full_snapshot_validation_required'=>true];
            }$out['cards'][]=$row;
        }
        $out['page']['next_cursor']=$i<count($rows)?$i:null;
        if(strlen(MonRun::json((object)$out))>4000)throw new MonFault('MON_PROGRESS_PAGE_TOO_LARGE');return $out;
    }
    public static function nextRepair($cache,$pending,int $now): ?stdClass {
        if(!($cache instanceof stdClass)||!(mon_get($cache,'watchlist')instanceof stdClass))return null;
        $batch=mon_get($pending,'collection',new stdClass());$responses=MonDetails::mergedResponses($cache,$batch);$jobs=[];
        foreach(MonDetails::requests($cache)as $sid=>$r)try{
            MonDetails::validRequest($r,$sid);$target=MonDetails::target($cache->watchlist,$r->symbol->country,$now);$response=mon_get($responses,$sid);
            $changed=$target['verified']&&$r->target_completed_date!==$target['date'];$state=MonDetails::matching($r,$response)?(string)mon_get($response,'state','pending'):'pending';
            if(!$changed&&$state==='done'&&mon_get($response,'usable')===true&&mon_get($response,'error')===null&&mon_get($response,'bars_as_of')===$target['date'])continue;
            $action=$changed?'register_current_period':($state==='serialization_pending'?'acquire_verified_alternative_source':($state==='blocked'?'correct_access_then_resume':($state==='conflict'?'verify_alternative_source':'continue_collector')));
            $api=in_array($action,['acquire_verified_alternative_source','verify_alternative_source'],true)?'mon_gap_plan':($action==='continue_collector'?'mon_detail_view':'mon_detail_request');
            $jobs[]=(object)['symbol_id'=>$sid,'request_id'=>$r->request_id,'state'=>$state,'error'=>mon_get($response,'error'),
                'target_completed_date'=>$target['date'],'registered_target'=>$r->target_completed_date,'period_changed'=>$changed,'action'=>$action,'next_api'=>$api];
        }catch(MonFault $e){}
        usort($jobs,static function($a,$b){return (($a->period_changed?0:1)<=>($b->period_changed?0:1))?:strcmp($a->symbol_id,$b->symbol_id);});return $jobs[0]??null;
    }
    public static function cached($cache,$pending,string $since='',int $cursor=0): array {
        if(!($cache instanceof stdClass))return self::build((object)['watchlist'=>new stdClass(),'collection'=>new stdClass()],time(),$since);
        if(!(mon_get($cache,'watchlist')instanceof stdClass)||!(mon_get($cache,'collection')instanceof stdClass))throw new MonFault('MON_PROGRESS_CACHE_INVALID');
        $d=clone $cache;$d->collection=clone $cache->collection;$responses=mon_get($cache->collection,'detail_responses');
        $d->collection->detail_responses=$responses instanceof stdClass?clone $responses:new stdClass();$batch=mon_get($pending,'collection',new stdClass());$unpublished=[];
        $merged=MonDetails::mergedResponses($d,$batch);
        foreach($merged as $sid=>$response){$old=mon_get(mon_get($cache->collection,'detail_responses'),$sid);$meta=clone $response;$oldMeta=$old instanceof stdClass?clone $old:null;
            $payload=mon_get($meta,'payload');unset($meta->payload);$oldPayload=mon_get($oldMeta,'payload');if($oldMeta)unset($oldMeta->payload);
            $payloadMeta=$payload instanceof stdClass?clone $payload:null;$oldPayloadMeta=$oldPayload instanceof stdClass?clone $oldPayload:null;
            if($payloadMeta)unset($payloadMeta->data);if($oldPayloadMeta)unset($oldPayloadMeta->data);
            if(MonRun::json($meta)!==MonRun::json($oldMeta)||MonRun::json($payloadMeta)!==MonRun::json($oldPayloadMeta)||
                mon_get($payload,'data')!==mon_get($oldPayload,'data'))$unpublished[$sid]=true;
        }
        $d->collection->detail_responses=$merged;return self::build($d,time(),$since,$unpublished,$cursor);
    }
}

/* MON reinforcement D1.1. Protected state stays in the existing web directory. */
/* Small rolling operational statistics. No prices, HTTP bodies, tokens or synthetic success. */
final class MonRunStatistics {
    const CONTRACT='MON-STATS-1.0'; const POLICY='MON-ADAPTIVE-1.1';
    const WINDOW=60; const MAX_BYTES=262144;
    public static function empty(): stdClass {return (object)['contract'=>self::CONTRACT,'generation'=>0,'samples'=>[]];}
    public static function seal(stdClass $s): stdClass {$s->sha256=MonRun::digest(MonRun::pick($s,['contract','generation','samples']));return $s;}
    public static function validate($s): stdClass {
        if(!($s instanceof stdClass)||mon_get($s,'contract')!==self::CONTRACT||!is_int(mon_get($s,'generation'))||$s->generation<0||
            !is_array(mon_get($s,'samples'))||count($s->samples)>self::WINDOW||strlen(MonRun::json($s))>self::MAX_BYTES||
            !hash_equals((string)mon_get($s,'sha256',''),MonRun::digest(MonRun::pick($s,['contract','generation','samples']))))throw new MonFault('MON_STATS_INVALID');
        if(array_diff(array_keys((array)$s),['contract','generation','samples','sha256']))throw new MonFault('MON_STATS_INVALID');
        $ids=[];foreach($s->samples as $r){
            if(!($r instanceof stdClass)||!preg_match('/^[a-f0-9]{64}$/',(string)mon_get($r,'run_context_id'))||isset($ids[$r->run_context_id])||
                !preg_match('/^[A-Za-z0-9._:-]{1,32}$/',(string)mon_get($r,'workload'))||
                !in_array(mon_get($r,'outcome'),['complete','incomplete','interrupted','failed','expired'],true)||
                !is_bool(mon_get($r,'partial_timing'))||!is_bool(mon_get($r,'censored'))||!is_bool(mon_get($r,'deadline_missed'))||
                !is_bool(mon_get($r,'new_analysis_complete'))||!is_bool(mon_get($r,'fully_complete'))||
                !in_array(mon_get($r,'measurement'),['server','operator_reported'],true)||
                !is_numeric(mon_get($r,'elapsed_seconds'))||!is_finite((float)$r->elapsed_seconds)||$r->elapsed_seconds<0||$r->elapsed_seconds>2592000||
                !is_int(mon_get($r,'total_limit_seconds'))||$r->total_limit_seconds<120||$r->total_limit_seconds>2592000||
                !(mon_get($r,'operations') instanceof stdClass)||count(get_object_vars($r->operations))>64||
                !(mon_get($r,'counts') instanceof stdClass)||count(get_object_vars($r->counts))>32||!is_array(mon_get($r,'unfinished'))||count($r->unfinished)>30)throw new MonFault('MON_STATS_INVALID');
            if(array_diff(array_keys((array)$r),['run_context_id','revision','workload','received_at','finished_at','elapsed_seconds','first_control_seconds','first_display_seconds','total_limit_seconds','measurement','partial_timing','censored','deadline_missed','new_analysis_complete','fully_complete','outcome','operations','counts','unfinished','terminal','limit_behavior','time_excess_seconds','count_excess','completion_contract','required_checks_pending','publication_verified','completion_receipt_id']))throw new MonFault('MON_STATS_INVALID');
            if(property_exists($r,'completion_contract')){
                if($r->completion_contract!=='MON-COMPLETION-1.0'||!is_int(mon_get($r,'required_checks_pending'))||$r->required_checks_pending<0||!is_bool(mon_get($r,'publication_verified')))throw new MonFault('MON_STATS_INVALID');
                if($r->fully_complete&&($r->required_checks_pending!==0||!$r->publication_verified||!preg_match('/^[a-f0-9]{64}$/',(string)mon_get($r,'completion_receipt_id'))))throw new MonFault('MON_STATS_INVALID');
            }
            if(property_exists($r,'terminal')&&!is_bool($r->terminal))throw new MonFault('MON_STATS_INVALID');
            if(property_exists($r,'limit_behavior')&&$r->limit_behavior!=='observe_and_continue')throw new MonFault('MON_STATS_INVALID');
            foreach(['time_excess_seconds','count_excess']as $key)if(property_exists($r,$key)){
                if(!($r->$key instanceof stdClass)||count(get_object_vars($r->$key))>8)throw new MonFault('MON_STATS_INVALID');
                foreach($r->$key as $name=>$value)if(!preg_match('/^[a-z_]{1,40}$/',$name)||!is_numeric($value)||!is_finite((float)$value)||$value<0)throw new MonFault('MON_STATS_INVALID');
            }
            if(!is_int(mon_get($r,'revision',0))||mon_get($r,'revision',0)<0||mon_get($r,'revision',0)>1000000)throw new MonFault('MON_STATS_INVALID');
            foreach(['first_control_seconds','first_display_seconds']as $key){$v=mon_get($r,$key);if($v!==null&&(!is_numeric($v)||!is_finite((float)$v)||$v<0||$v>2592000))throw new MonFault('MON_STATS_INVALID');}
            MonRun::utc($r->received_at);MonRun::utc($r->finished_at);$ids[$r->run_context_id]=true;
            if($r->fully_complete&&(!$r->new_analysis_complete||$r->outcome!=='complete'||mon_get($r,'terminal',true)!==true))throw new MonFault('MON_STATS_INVALID');
            foreach($r->counts as $k=>$v)if(!preg_match('/^[a-z_]{1,40}$/',$k)||!is_int($v)||$v<0)throw new MonFault('MON_STATS_INVALID');
            foreach($r->operations as $key=>$o){
                if(!preg_match('/^(http|handler|detail|external|connector)\/[A-Za-z0-9._-]{1,64}$/',$key)||!($o instanceof stdClass)||
                    !in_array(mon_get($o,'measurement'),['server','operator_reported'],true)||!is_array(mon_get($o,'seconds'))||count($o->seconds)>20)throw new MonFault('MON_STATS_INVALID');
                if(array_diff(array_keys((array)$o),['measurement','count','successes','timeouts','failures','retries','retry_successes','units','seconds']))throw new MonFault('MON_STATS_INVALID');
                foreach(['count','successes','timeouts','failures','retries','retry_successes','units']as $k)if(!is_int(mon_get($o,$k))||$o->$k<0)throw new MonFault('MON_STATS_INVALID');
                if($o->successes+$o->failures!==$o->count||$o->timeouts>$o->failures||$o->retry_successes>$o->retries||$o->retries>$o->count)throw new MonFault('MON_STATS_INVALID');
                foreach($o->seconds as $v)if(!is_numeric($v)||!is_finite((float)$v)||$v<0||$v>2592000)throw new MonFault('MON_STATS_INVALID');
            }
            foreach($r->unfinished as $item)if(!($item instanceof stdClass)||!preg_match('/^[A-Za-z0-9._: -]{1,96}$/',(string)mon_get($item,'task'))||
                !preg_match('/^[A-Z0-9_.-]{1,96}$/',(string)mon_get($item,'code'))||array_diff(array_keys((array)$item),['task','code']))throw new MonFault('MON_STATS_INVALID');
        }return $s;
    }
    public static function load(MonStore $store): stdClass {
        $s=$store->readPrivate('run-stats.php');
        if($s===null){$path=$store->dir.'/mon_execution_stats.json';if(is_file($path)){
            $raw=@file_get_contents($path,false,null,0,self::MAX_BYTES+1);
            try{$s=is_string($raw)&&strlen($raw)<=self::MAX_BYTES?mon_decode($raw):null;}catch(Throwable $e){throw new MonFault('MON_STATS_INVALID');}
            if($s===null)throw new MonFault('MON_STATS_INVALID');
        }else $s=self::seal(self::empty());}return self::validate($s);
    }
    public static function merge(stdClass $a,stdClass $b): stdClass {
        self::validate($a);self::validate($b);$rows=[];
        foreach(array_merge($a->samples,$b->samples)as $r){$id=$r->run_context_id;
            if(!isset($rows[$id])||mon_get($r,'revision',0)>mon_get($rows[$id],'revision',0)||
                (mon_get($r,'revision',0)===mon_get($rows[$id],'revision',0)&&MonRun::seconds($r->finished_at)>MonRun::seconds($rows[$id]->finished_at)))$rows[$id]=$r;
            elseif(mon_get($r,'revision',0)===mon_get($rows[$id],'revision',0)&&MonRun::seconds($r->finished_at)===MonRun::seconds($rows[$id]->finished_at)&&MonRun::digest($r)!==MonRun::digest($rows[$id]))throw new MonFault('MON_STATS_SAMPLE_CONFLICT');
        }
        uasort($rows,static function($x,$y){return strcmp($x->finished_at,$y->finished_at)?:strcmp($x->run_context_id,$y->run_context_id);});
        $out=(object)['contract'=>self::CONTRACT,'generation'=>max($a->generation,$b->generation)+1,'samples'=>array_values(array_slice($rows,-self::WINDOW,null,true))];
        while(count($out->samples)>1&&strlen(MonRun::json(self::seal($out)))>self::MAX_BYTES)array_shift($out->samples);
        return self::validate(self::seal($out));
    }
    public static function save(MonStore $store,stdClass $incoming): stdClass {
        self::validate($incoming);$lock=@fopen($store->path('run-stats.lock'),'c+');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new MonFault('MON_STATS_BUSY');}
        try{$old=self::load($store);$merged=self::merge($old,$incoming);
            if(MonRun::digest($old->samples)!==MonRun::digest($merged->samples))$store->writePrivate('run-stats.php',$merged);else $merged=$old;
            return $merged;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function percentile(array $values,float $q): ?float {
        if(!$values)return null;sort($values,SORT_NUMERIC);return (float)$values[max(0,(int)ceil($q*count($values))-1)];
    }
    public static function summary(stdClass $s,string $workload,float $now): array {
        self::validate($s);$all=[];$dur=[];$complete=0;$verifiedComplete=0;$legacyComplete=0;$missed=0;$operations=[];$counts=[];$firstControl=[];$firstDisplay=[];$excess=[];$countExcess=[];$pending=0;
        foreach($s->samples as $r){if($r->workload!==$workload||MonRun::seconds($r->finished_at)<$now-30*86400||MonRun::seconds($r->finished_at)>$now+5)continue;
            $all[]=$r;if($r->fully_complete){$complete++;if(mon_get($r,'completion_contract')==='MON-COMPLETION-1.0')$verifiedComplete++;else $legacyComplete++;}if($r->deadline_missed)$missed++;
            if(mon_get($r,'terminal',true)===false)$pending++;
            $excess[]=max(0,$r->elapsed_seconds-$r->total_limit_seconds);
            foreach(mon_get($r,'count_excess',new stdClass())as $key=>$value)$countExcess[$key]=($countExcess[$key]??0)+$value;
            if(!$r->partial_timing&&!$r->censored&&mon_get($r,'terminal',true))$dur[]=$r->elapsed_seconds;
            if(!$r->partial_timing&&mon_get($r,'first_control_seconds')!==null)$firstControl[]=$r->first_control_seconds;
            if(!$r->partial_timing&&mon_get($r,'first_display_seconds')!==null)$firstDisplay[]=$r->first_display_seconds;
            foreach($r->counts as $key=>$v)$counts[$key][]=$v;
            foreach($r->operations as $key=>$o){if(!isset($operations[$key]))$operations[$key]=['count'=>0,'successes'=>0,'timeouts'=>0,'failures'=>0,'retries'=>0,'retry_successes'=>0,'seconds'=>[],'per_run_counts'=>[],'measurement'=>$o->measurement];
                foreach(['count','successes','timeouts','failures','retries','retry_successes']as $field)$operations[$key][$field]+=$o->$field;
                $operations[$key]['seconds']=array_merge($operations[$key]['seconds'],$o->seconds);$operations[$key]['per_run_counts'][]=$o->count;
            }
        }
        foreach($operations as &$o){$o['p50_seconds']=self::percentile($o['seconds'],.5);$o['p90_seconds']=self::percentile($o['seconds'],.9);
            $o['p90_count']=self::percentile($o['per_run_counts'],.9);$o['sample_runs']=count($o['per_run_counts']);unset($o['seconds'],$o['per_run_counts']);}unset($o);
        $countStats=[];foreach($counts as $key=>$values)$countStats[$key]=['samples'=>count($values),'p50'=>self::percentile($values,.5),'p90'=>self::percentile($values,.9)];
        return ['generation'=>$s->generation,'samples'=>count($all),'measured_samples'=>count($dur),'completed'=>$complete,'verified_completed'=>$verifiedComplete,'legacy_completed'=>$legacyComplete,'incomplete'=>count($all)-$complete,
            'deadline_miss_rate'=>count($all)?$missed/count($all):null,'p50_seconds'=>self::percentile($dur,.5),'p90_seconds'=>self::percentile($dur,.9),
            'pending'=>$pending,'time_excess_total_seconds'=>array_sum($excess),'time_excess_p90_seconds'=>self::percentile($excess,.9),'count_excess_totals'=>$countExcess,
            'first_control_p90_seconds'=>self::percentile($firstControl,.9),'first_display_p90_seconds'=>self::percentile($firstDisplay,.9),'display_timing_measurement'=>'operator_reported_or_unmeasured',
            'operations'=>$operations,'counts'=>$countStats,'last'=>$all?end($all):null];
    }
    public static function recommend(stdClass $s,string $workload,float $now): stdClass {
        $a=self::summary($s,$workload,$now);$last=$a['last'];$previous=$last?(int)$last->total_limit_seconds:120;
        $total=$previous;$why=$last?'retain_until_enough_samples':'cold_start';
        // Short censored/partial observations never reduce a full-run limit.
        $recent=array_values(array_filter($s->samples,static function($r)use($workload,$now){return $r->workload===$workload&&MonRun::seconds($r->finished_at)>=$now-30*86400&&MonRun::seconds($r->finished_at)<=$now+5;}));
        $tail=array_slice($recent,-5);$pressure=[];$completePressure=[];
        foreach($tail as $r)if(!$r->partial_timing&&($r->deadline_missed||$r->censored)){
            $pressure[]=max($r->elapsed_seconds,$r->total_limit_seconds);
            if($r->fully_complete&&!$r->censored)$completePressure[]=$r->elapsed_seconds;
        }
        if($pressure){$target=(int)(ceil(max($pressure)*1.25/10)*10);$total=max($previous,min($previous*2,$target));$why='threshold_pressure';
            if($completePressure)$total=max($total,(int)(ceil(max($completePressure)*1.25/10)*10));}
        elseif($a['measured_samples']>=5){$target=max(120,(int)(ceil($a['p90_seconds']*1.25/10)*10));
            $fiveGood=count($tail)===5;foreach($tail as $r)if(!$r->fully_complete||$r->censored||$r->partial_timing||mon_get($r,'completion_contract')!=='MON-COMPLETION-1.0')$fiveGood=false;
            if($target>=$previous){$total=min($target,$previous+30);$why='p90_with_margin';}
            elseif($fiveGood){$total=max($target,$previous-10);$why='five_complete_runs_slow_decrease';}
        }
        $httpCalls=30;$attempts=2;$symbolSeconds=8.0;$searches=2;$http=new stdClass();
        $httpCount=$a['counts']['http_calls']??null;
        if($httpCount&&$httpCount['samples']>=5)$httpCalls=max(20,(int)ceil($httpCount['p90']*1.25)+12);
        if($last&&mon_get($last->counts,'http_limit_hits',0)>0)$httpCalls=max($httpCalls,(int)ceil(mon_get($last->counts,'http_calls',30)*1.25)+12);
        foreach($a['operations']as $key=>$o){
            if(strpos($key,'http/')===0){$host=substr($key,5);$cap=$host==='api.github.com'?3.0:5.0;$v=$cap;
                if($o['count']>=5&&$o['p90_seconds']!==null){$v=max(1.0,min($cap,ceil(($o['p90_seconds']*1.5+.25)*10)/10));if($o['timeouts']/$o['count']>=.2)$v=$cap;}$http->$host=$v;
            }elseif($key==='detail/fetch'&&$o['retries']>=5){$rate=$o['retry_successes']/$o['retries'];
                if($rate<.1)$attempts=1;elseif($rate>=.4&&$total>=180)$attempts=3;
                if($o['count']>=5&&$o['p90_seconds']!==null)$symbolSeconds=max(3,min(8,ceil($o['p90_seconds']*1.5+.5)));
            }elseif($key==='external/search'&&$o['sample_runs']>=5&&$o['successes']>0)$searches=max(2,(int)ceil($o['p90_count']*1.25));
        }
        if($last&&mon_get($last->counts,'search_limit_hits',0)>0)$searches=max($searches,(int)ceil(mon_get($last->counts,'search_batches',2)*1.25));
        $pub=10;$dec=10;
        foreach(['handler/mon_detail_finalize'=>&$dec,'handler/mon_publish_process'=>&$pub]as $key=>&$reserve){$o=$a['operations'][$key]??null;
            if($o&&$o['count']>=5&&$o['p90_seconds']!==null)$reserve=(int)max(10,min(30,ceil($o['p90_seconds']*1.25)));}unset($reserve);
        return (object)['contract'=>self::POLICY,'statistics_sha256'=>$s->sha256,'statistics_generation'=>$s->generation,'sample_runs'=>$a['samples'],
            'workload'=>$workload,'reason'=>$why,'limit_behavior'=>'observe_and_continue','limits'=>(object)['collection'=>$total-$pub-$dec,'decision'=>$total-$pub,'publication'=>$total,'recovery'=>max(300,$total)],
            'http_calls'=>$httpCalls,'publication_call_reserve'=>12,'detail_attempts'=>$attempts,'detail_symbol_seconds'=>(float)$symbolSeconds,
            'external_search_batches'=>$searches,'http_timeouts'=>$http,'maximum_total_seconds'=>null];
    }
}

final class MonBudget {
    public $run; private $wall; private $mono; private $wallStart; private $monoStart;
    public function __construct($run, ?callable $wall=null, ?callable $mono=null) {
        $this->run=$run; $this->wall=$wall?:static function(){return microtime(true);};
        $this->mono=$mono?:static function(){return mon_monotonic();};
        $this->wallStart=max((float)($this->wall)(), MonRun::seconds(mon_get($run,'last_seen_at',$run->first_control_at)));
        $this->monoStart=(float)($this->mono)();
    }
    public static function iso(float $seconds): string {
        $date=DateTimeImmutable::createFromFormat('U.u',sprintf('%.6F',$seconds));
        if(!$date)throw new MonFault('MON_TIME_INVALID');
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
    public function now(): float {return max((float)($this->wall)(),$this->wallStart+max(0,(float)($this->mono)()-$this->monoStart));}
    public function elapsed(): float {return max(0,$this->now()-MonRun::seconds($this->run->received_at));}
    public function end(string $phase): float {
        $normal=['collection'=>100,'decision'=>110,'publication'=>120,'recovery'=>300];
        $extended=['collection'=>280,'decision'=>290,'publication'=>300,'recovery'=>300];
        $limits=$this->run->mode==='extended'?$extended:$normal;
        if($this->run->mode==='auto')$limits=(array)$this->run->adaptive_policy->limits;
        if(!isset($limits[$phase]))throw new MonFault('MON_PHASE_INVALID');
        return MonRun::seconds($this->run->received_at)+$limits[$phase];
    }
    public function remaining(string $phase): float {return max(0,$this->end($phase)-$this->now());}
    public function deadline(string $phase): float {return microtime(true)+max(10,$this->remaining($phase));}
    public function guard(string $phase): void {
        $this->end($phase);
        if(mon_get($this->run,'finish_reason')==='interrupted'&&mon_get($this->run,'finished_at')!==null)throw new MonFault('MON_RUN_INTERRUPTED');
        if($this->remaining($phase)<.05)MonExecution::observeThreshold($phase);
    }
    public function excess(): stdClass {
        $out=new stdClass();foreach(['collection','decision','publication','recovery']as $phase)$out->$phase=round(max(0,$this->now()-$this->end($phase)),6);return $out;
    }
    public function timing(): array {
        return ['received_at'=>$this->run->received_at,'first_control_at'=>$this->run->first_control_at,
            'elapsed_seconds'=>round($this->elapsed(),6),'partial_timing'=>$this->run->partial_timing,
            'collection_deadline_at'=>self::iso($this->end('collection')),'decision_deadline_at'=>self::iso($this->end('decision')),
            'publication_deadline_at'=>self::iso($this->end('publication')),'recovery_deadline_at'=>self::iso($this->end('recovery')),
            'deadline_missed'=>$this->now()>$this->end('publication'),'mode'=>$this->run->mode,'limit_behavior'=>'observe_and_continue','time_excess_seconds'=>$this->excess(),
            'limits_seconds'=>['collection'=>$this->end('collection')-MonRun::seconds($this->run->received_at),'decision'=>$this->end('decision')-MonRun::seconds($this->run->received_at),
                'publication'=>$this->end('publication')-MonRun::seconds($this->run->received_at),'recovery'=>$this->end('recovery')-MonRun::seconds($this->run->received_at)]];
    }
}
/* Completion is derived from a protected, validated file pair, never caller flags. */
final class MonCompletion {
    const CONTRACT='MON-COMPLETION-1.0';
    const MAX_BYTES=196608;
    public static function bind(MonStore $store,$data,string $artifactId,array $files,array $metadata): void {
        $runId=mon_get($metadata,'run_context_id');if(!is_string($runId))return;MonExecution::id($runId);
        $p=MonRun::payload($data);$rows=[];
        foreach($data->analysis->results as $plain){$full=mon_get($p->final_results_details,$plain->symbol_id,$plain);
            $rows[]=MonRun::pick($full,['symbol_id','country','action','price','price_as_of','price_type','price_checks','risk_checks','risk_review','entry_plans','plan_valid_until','swing_signal']);}
        $scope=(object)['watchlist'=>MonRun::pick($data->watchlist,['criteria_version','settings','calendar']),
            'analysis'=>MonRun::pick($data->analysis,['as_of','market_summary','pending_analysis','pending_analysis_countries','discovery_state']),'results'=>$rows,
            'countries'=>mon_get($metadata,'countries',array_map(static function($m){return $m->country;},$data->analysis->market_summary))];
        $snapshot=(object)['contract'=>self::CONTRACT,'artifact_id'=>$artifactId,'run_context_id'=>$runId,
            'received_at'=>mon_get($metadata,'received_at'),'decision_sha256'=>mon_get($metadata,'decision_sha256'),
            'files'=>array_map(static function($f){return MonRun::pick($f,['path','blob_sha']);},$files),'scope'=>$scope];
        $snapshot->sha256=MonRun::digest($snapshot);if(strlen(MonRun::json($snapshot))>self::MAX_BYTES)throw new MonFault('MON_COMPLETION_SCOPE_TOO_LARGE');
        $store->writePrivate('completion-snapshot-'.$artifactId.'.php',$snapshot);
    }
    private static function snapshot(MonStore $store,string $id): stdClass {
        MonExecution::id($id);$s=$store->readPrivate('completion-snapshot-'.$id.'.php');$copy=$s instanceof stdClass?clone $s:new stdClass();unset($copy->sha256);
        if(!($s instanceof stdClass)||mon_get($s,'contract')!==self::CONTRACT||mon_get($s,'artifact_id')!==$id||
            !hash_equals((string)mon_get($s,'sha256'),MonRun::digest($copy)))throw new MonFault('MON_COMPLETION_SNAPSHOT_INVALID');return $s;
    }
    public static function publicationView(MonStore $store): ?stdClass {
        $receipt=$store->readPrivate('publication-receipt.php');if(!($receipt instanceof stdClass))return null;
        $receipt->publication_complete=mon_get($receipt,'save_status')==='saved';$receipt->fully_complete=false;
        try{$run=$store->readPrivate('run-'.(string)mon_get($receipt,'run_context_id').'.php');
            if($run instanceof stdClass){$gate=self::assess($store,$run,mon_iso());$receipt->completion=(object)$gate;$receipt->fully_complete=$gate['fully_complete'];$receipt->current_artifact_id=mon_get($run,'artifact_id');}
        }catch(MonFault $e){$receipt->completion=(object)['fully_complete'=>false,'code'=>$e->faultCode,'next_action'=>'repair_verification_binding'];}
        return $receipt;
    }
    public static function assess(MonStore $store,$run,string $at,?string $jobId=null): array {
        $jobs=[];$outcomes=[];$deferred=[];$artifactId=mon_get($run,'artifact_id');$publicationVerified=false;$commit=null;$snapshot=null;$job=null;
        $add=static function(string $sid,string $kind,string $code,array $checks=[])use(&$jobs,$run){
            $task=$kind.($sid!==''?':'.str_replace('|',':',$sid):'');
            $jobs[]=(object)['task_id'=>hash('sha256',self::CONTRACT.'|'.$run->run_context_id.'|'.$task),'task'=>$task,'symbol_id'=>$sid,
                'kind'=>$kind,'code'=>$code,'state'=>'pending','resolved'=>false,'required_checks'=>$checks];};
        $jobId=$jobId??mon_get($run,'job_id');
        try{
            if(is_string($jobId)){$job=MonPublication::journal($store,$jobId);
                if($job->run_context_id!==$run->run_context_id||$job->received_at!==$run->received_at)throw new MonFault('MON_PUBLICATION_RUN_MISMATCH');
                if(is_string(mon_get($run,'job_id'))&&$run->job_id!==$jobId)throw new MonFault('MON_PUBLICATION_RUN_MISMATCH');
                if(is_string($artifactId)&&$artifactId!==$job->artifact_id&&$artifactId!==mon_get($job,'original_artifact_id'))throw new MonFault('MON_PUBLICATION_RUN_MISMATCH');
                $artifactId=$job->artifact_id;$commit=mon_get($job,'published_commit_sha');
                $publicationVerified=$job->status==='saved'&&$job->stage==='complete'&&preg_match('/^[a-f0-9]{40}$/',(string)$commit)===1;
            }
            if(is_string($artifactId)){$snapshot=self::snapshot($store,$artifactId);
                if($snapshot->run_context_id!==$run->run_context_id||$snapshot->received_at!==$run->received_at||
                    ($job&&$snapshot->decision_sha256!==$job->decision_sha256))throw new MonFault('MON_COMPLETION_BINDING_INVALID');
            }
        }catch(MonFault $e){$snapshot=null;$publicationVerified=false;$add('','verification_binding',$e->faultCode);}
        if(mon_get($run,'new_analysis_complete')!==true||mon_get($run,'final_available')!==true)$add('','analysis','MON_ANALYSIS_VALIDATION_PENDING');
        if(!$snapshot)$add('','verified_artifact','MON_COMPLETION_ARTIFACT_REQUIRED');
        if($snapshot){
            $d=$snapshot->scope;$periods=MonRun::periods($d,$at);
            if(mon_get($d->analysis,'pending_analysis',false)||($job&&mon_get($job,'pending_analysis',false)))$add('','analysis_reprepare','MON_NEW_DETAIL_REVIEW_REQUIRED');
            $missingPeriods=[];foreach($periods as $country=>$period)if(in_array($country,$d->countries,true)&&!$period->current_period)$missingPeriods[]=$country;
            if($missingPeriods)$add('','analysis_reprepare','MON_PERIOD_CHANGED_PREPARATION_REQUIRED',$missingPeriods);
            foreach(mon_get(mon_get($d->analysis,'discovery_state'),'countries',[])as $quota)if(in_array($quota->country,$d->countries,true)&&
                (mon_get($quota,'goal_status')==='shortfall'||(mon_get($quota,'target_verified')===true&&mon_get($quota,'shortfall',0)>0)))
                $add($quota->country,'new_identity_quota','MON_NEW_IDENTITY_QUOTA_PENDING',['primary_listing_unique_ids','current_trading_day_or_verified_next_session_credit']);
            foreach($d->results as $r){$sid=$r->symbol_id;$country=$r->country;$action=mon_get($r,'action');
                if($action==='REFERENCE_REVIEW'){$outcomes[]=(object)['symbol_id'=>$sid,'state'=>'REFERENCE','current_entry_confirmed'=>false];continue;}
                $review=mon_get($r,'risk_review',new stdClass());$reviewState=MonRiskState::at($review,$country,$at);
                $riskVerified=$reviewState->corporate_verified;$adverse=$reviewState->issuer_same_day&&mon_get($review,'confirmed_material_adverse')===true;$halted=$reviewState->halted;
                if($adverse||$halted){$outcomes[]=(object)['symbol_id'=>$sid,'state'=>'EXCLUDE','current_entry_confirmed'=>false,
                    'reason'=>$halted?'verified_trading_halt':'confirmed_material_adverse'];continue;}
                if(!$riskVerified)$add($sid,'issuer_review','MON_ISSUER_REVIEW_PENDING',['latest_financial_filing','material_events_since_filing','known_pending_items']);
                $period=$periods[$country]??null;
                if(!mon_get($period,'current_period',false))continue;
                $session=MonMarket::state($d->watchlist,$country,(int)MonRun::seconds($at));
                if($session['session']==='unknown'){$add($sid,'calendar','MON_MARKET_CALENDAR_PENDING');continue;}
                if($session['session']!=='regular'){
                    $day=MonDiscovery::day($d->watchlist,$country,$at);$deferred[]=(object)['symbol_id'=>$sid,'state'=>'WAIT_SESSION','next_trading_date'=>$day['next_trading_date'],'current_entry_confirmed'=>false];
                    $outcomes[]=end($deferred);continue;
                }
                $current=clone $r;$current->risk_checks=clone mon_get($r,'risk_checks',new stdClass());
                MonRiskState::updateChecks($current,$at);
                $state=MonRun::current($current,$d->watchlist,$at,true);
                $blocking=array_values(array_diff($state->blockers,['outside_entry_zone','swing_price_confirmation_required','corporate_risk_needs_check','confirmed_material_adverse']));
                if($action!=='BUY_REVIEW')$blocking=array_values(array_diff($blocking,['entry_plan_needs_data','plan_expired','swing_signal_invalid']));
                $prepareNeeded=array_intersect($blocking,['entry_plan_needs_data','plan_expired','swing_signal_invalid','latest_comparison_pending']);
                if($blocking)$add($sid,$prepareNeeded?'analysis_reprepare':'current_entry',$prepareNeeded?'MON_ENTRY_PLAN_PREPARATION_REQUIRED':'MON_CURRENT_ENTRY_CHECKS_PENDING',$blocking);
                if(!$riskVerified||$blocking)continue;
                $waiting=$action==='BUY_REVIEW'&&($state->readiness!=='ready');
                $outcomes[]=(object)['symbol_id'=>$sid,'state'=>$waiting?'WAIT_ENTRY':($action==='BUY_REVIEW'?'ENTRY_CONFIRMED':$action),
                    'current_entry_confirmed'=>$action==='BUY_REVIEW'&&!$waiting,'reason_codes'=>$waiting?$state->blockers:[]];
            }
        }
        if(!$publicationVerified)$add('','publication','MON_REMOTE_SAVE_VERIFICATION_PENDING');
        foreach(mon_get($run,'reported_unfinished',[])as $row)$jobs[]=(object)['task_id'=>hash('sha256',self::CONTRACT.'|'.$run->run_context_id.'|'.$row->task),
            'task'=>$row->task,'symbol_id'=>'','kind'=>'reported_work','code'=>$row->code,'state'=>'pending','resolved'=>false,'required_checks'=>[]];
        $priority=['analysis'=>0,'verified_artifact'=>0,'verification_binding'=>0,'analysis_reprepare'=>1,'calendar'=>1,'new_identity_quota'=>1,'issuer_review'=>2,'reported_work'=>2,'current_entry'=>3,'publication'=>4];
        usort($jobs,static function($a,$b)use($priority){return (($priority[$a->kind]??2)<=>($priority[$b->kind]??2))?:strcmp($a->task,$b->task);});
        foreach($jobs as $required)if($required->kind==='publication'){$required->artifact_id=$artifactId;$required->publication_job_id=$jobId;
            $required->next_api=is_string($jobId)?'mon_publish_process':'mon_publish_queue';}
        $fully=!$jobs&&$snapshot!==null&&$publicationVerified;
        if(mon_get($run,'finish_reason')==='interrupted'||mon_get($run,'finish_reason')==='failed')$fully=false;
        $receipt=$fully?(object)['contract'=>self::CONTRACT,'run_context_id'=>$run->run_context_id,'artifact_id'=>$artifactId,'published_commit_sha'=>$commit,
            'checked_at'=>$at,'snapshot_sha256'=>$snapshot->sha256,'files'=>$snapshot->files,'outcomes'=>$outcomes,'required_checks_pending'=>0]:null;
        if($receipt){$identity=MonRun::pick($receipt,['contract','run_context_id','artifact_id','published_commit_sha','snapshot_sha256','outcomes']);$receipt->receipt_id=MonRun::digest($identity);}
        return ['contract'=>self::CONTRACT,'checked_at'=>$at,'fully_complete'=>$fully,'publication_verified'=>$publicationVerified,'required_checks_pending'=>count($jobs),
            'pending_jobs'=>$jobs,'deferred_checks'=>$deferred,'outcomes'=>$outcomes,'completion_receipt'=>$receipt,
            'code'=>$fully?null:'MON_COMPLETION_PENDING','next_action'=>$fully?'complete':($jobs[0]->kind??'continue_pending_work')];
    }
}
final class MonExecution {
    const CONTRACT='MON-EXECUTION-1.0';
    public static $active=null; public static $store=null; private static $pending=[];
    private static function immutable($run): stdClass {
        $keys=['contract','run_context_id','received_at','first_control_at','mode','extension_reason','partial_timing','timing_basis'];
        if(property_exists($run,'adaptive_policy'))$keys=array_merge($keys,['adaptive_policy','workload']);
        if(property_exists($run,'operation_policy'))$keys[]='operation_policy';
        return MonRun::pick($run,$keys);
    }
    public static function id($id): void {if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/',$id))throw new MonFault('MON_RUN_ID_INVALID');}
    public static function operationPolicy($input): stdClass {
        if(!($input instanceof stdClass)||array_diff(array_keys(get_object_vars($input)),['scope','finish_owner','require_statistics_publication','nas_max_age_seconds']))throw new MonFault('MON_OPERATION_POLICY_INVALID');
        $scope=mon_get($input,'scope','analysis_and_nas_normalization');$owner=mon_get($input,'finish_owner','execution');$stats=mon_get($input,'require_statistics_publication',true);$age=mon_get($input,'nas_max_age_seconds',30);
        if(!in_array($scope,['analysis','analysis_and_nas_normalization'],true)||!in_array($owner,['execution','publication_legacy'],true)||!is_bool($stats)||!is_int($age)||$age<5||$age>90)throw new MonFault('MON_OPERATION_POLICY_INVALID');
        if($scope==='analysis_and_nas_normalization'&&$owner!=='execution')throw new MonFault('MON_OPERATION_POLICY_INVALID');
        return (object)['contract'=>'MON-OPERATION-COMPLETION-1.0','scope'=>$scope,'finish_owner'=>$owner,'require_nas_collection'=>$scope==='analysis_and_nas_normalization',
            'require_statistics_publication'=>$stats,'nas_max_age_seconds'=>$age,'reader_min_version'=>'1.8.5'];
    }
    private static function operationState(MonBudget $b): stdClass {
        $e=self::$store->readPrivate('operation-'.$b->run->run_context_id.'.php');
        if($e===null)return (object)['contract'=>'MON-OPERATION-COMPLETION-1.0','run_context_id'=>$b->run->run_context_id,'received_at'=>$b->run->received_at,
            'policy_sha256'=>MonRun::digest($b->run->operation_policy),'nas'=>null,'statistics'=>null,'finished_at'=>null,'completion_receipt'=>null];
        $s=mon_get($e,'state');
        if(!($s instanceof stdClass)||$s->run_context_id!==$b->run->run_context_id||$s->received_at!==$b->run->received_at||
            !hash_equals((string)mon_get($s,'policy_sha256'),MonRun::digest($b->run->operation_policy))||
            !hash_equals((string)mon_get($e,'sha256'),hash('sha256',MonRun::json($s))))throw new MonFault('MON_OPERATION_STATE_INVALID');return $s;
    }
    private static function operationUpdate(callable $fn): void {
        $b=self::$active;if(!$b||!self::$store||!property_exists($b->run,'operation_policy'))throw new MonFault('MON_OPERATION_SCOPE_REQUIRED');
        $h=fopen(self::$store->path('operation-'.$b->run->run_context_id.'.lock'),'c+');
        if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if($h)fclose($h);throw new MonFault('MON_OPERATION_BUSY');}
        try{$s=self::operationState($b);$fn($s);self::$store->writePrivate('operation-'.$b->run->run_context_id.'.php',(object)['state'=>$s,'sha256'=>hash('sha256',MonRun::json($s))]);}
        finally{flock($h,LOCK_UN);fclose($h);}
    }
    public static function checkNas(MonNasConnection $client): array {
        $result=$client->health();$observation=mon_get($result,'observation');
        self::operationUpdate(static function($s)use($observation,$result){$s->nas=$observation;$s->nas_connection_state=mon_get($result,'state');});
        self::completeOperation();return ['connection'=>$result,'operation'=>self::operation(self::$active)];
    }
    public static function operation(MonBudget $b,?array $analysis=null): ?array {
        $policy=mon_get($b->run,'operation_policy');if(!($policy instanceof stdClass))return null;
        $analysis=$analysis??self::completion($b);$s=self::operationState($b);$business=[];$jobs=[];$nasOk=!$policy->require_nas_collection;$reason=null;
        $nas=mon_get($s,'nas');$health=mon_get($nas,'health');$now=$b->now();
        if($policy->require_nas_collection){$reason='MON_NAS_COLLECTION_PENDING';
            $age=$nas instanceof stdClass?$now-MonRun::seconds($nas->observed_at):PHP_INT_MAX;$heartbeat=mon_time(mon_get($health,'heartbeat_at'));
            $progress=mon_get($health,'detail_progress');$counts=mon_get($progress,'counts',new stdClass());$requests=(int)mon_get($counts,'requests',0);
            $blocked=array_sum(array_map(static function($k)use($counts){return (int)mon_get($counts,$k,0);},['pending','retry_wait','serialization_pending','blocked','conflict','invalid','current_missing']));
            $nasOk=$nas instanceof stdClass&&$nas->source===MonNasConnection::ENDPOINT&&$age>=-5&&$age<=$policy->nas_max_age_seconds&&
                is_string($nas->processor_version)&&version_compare($nas->processor_version,$policy->reader_min_version,'>=')&&
                mon_get($health,'version')===$nas->processor_version&&mon_get($health,'running')===true&&mon_get($health,'heartbeat_fresh')===true&&
                mon_get($health,'boot_verified')===true&&mon_get($health,'restart_required')===false&&mon_get($health,'watchlist_loaded')===true&&
                $heartbeat!==null&&$heartbeat<=$now+5&&$now-$heartbeat<=$policy->nas_max_age_seconds&&
                in_array(mon_get($health,'readiness'),['collecting','off_session','details','throttled'],true)&&$blocked===0&&(int)mon_get($counts,'done',0)===$requests&&
                mon_get($progress,'publication')==='stored'&&preg_match('/^[a-f0-9]{40}$/',(string)mon_get($progress,'source_blob_sha'))===1;
            if(!$nasOk){$repair=$age>=-5&&$age<=$policy->nas_max_age_seconds?mon_get($health,'repair_job'):null;$business[]=(object)['kind'=>'collector_repair','task'=>'operation:nas_collection','code'=>$reason,'next_api'=>mon_get($repair,'next_api','mon_operation_nas_check'),'repair_job'=>$repair,'source_blob_sha'=>mon_get($progress,'source_blob_sha')];}
        }
        $ackPending=false;foreach(mon_get($b->run,'external_operations',new stdClass())as $id=>$row)if(mon_get($row,'state')==='open'){
            $ackPending=true;$business[]=(object)['kind'=>'telemetry_flush','task'=>'operation:'.hash('sha256',$id),'code'=>'MON_EXTERNAL_ACK_PENDING','operation_id'=>$id,'next_api'=>'mon_run_report'];}
        $closed=mon_get($b->run,'finished_at')!==null&&mon_get($b->run,'terminal',false)===true&&mon_get($b->run,'finish_fully_complete',false)===true;
        $proof=mon_get($s,'statistics');$statsOk=!$policy->require_statistics_publication||($proof instanceof stdClass&&$closed&&
            $proof->revision===mon_get($b->run,'finish_revision')&&preg_match('/^[a-f0-9]{40}$/',(string)mon_get($proof,'blob_sha'))===1);
        $jobs=$business;$postPending=false;foreach(mon_get($s,'postprocessing',new stdClass())as $id=>$row)if($row->state==='open'){$postPending=true;$jobs[]=(object)['kind'=>'telemetry_post','task'=>'operation:'.hash('sha256',$id),'code'=>'MON_POSTPROCESS_ACK_PENDING','operation_id'=>$id,'next_api'=>'mon_operation_report'];}
        if($policy->require_statistics_publication&&!$statsOk)$jobs[]=(object)['kind'=>'statistics_publication','task'=>'operation:statistics','code'=>'MON_STATISTICS_PUBLICATION_PENDING',
            'next_api'=>$closed?'mon_operation_verify_statistics':'mon_run_finish'];
        if(!$analysis['fully_complete'])$jobs=array_merge($analysis['pending_jobs'],$jobs);
        $overall=$analysis['fully_complete']&&$closed&&!$business&&!$postPending&&$statsOk;
        return ['contract'=>$policy->contract,'scope'=>$policy->scope,'run_context_id'=>$b->run->run_context_id,'received_at'=>$b->run->received_at,
            'overall_complete'=>$overall,'business_complete'=>$closed,'business_pending_jobs'=>$business,'pending_jobs'=>$jobs,'next_required_job'=>$jobs[0]??null,
            'terminal'=>$s->finished_at!==null,'finished_at'=>$s->finished_at,'completion_receipt'=>$s->completion_receipt,
            'scopes'=>['analysis'=>$analysis['fully_complete'],'publication'=>$analysis['publication_verified'],'nas_collection'=>$nasOk,'telemetry'=>$statsOk&&!$ackPending&&!$postPending],
            'required_scopes'=>array_merge(['analysis','publication'],($policy->require_nas_collection?['nas_collection']:[]),['telemetry']),
            'finish_owner'=>$policy->finish_owner,'nas_observed_at'=>mon_get($nas,'observed_at'),'statistics_proof'=>$proof,'postprocessing'=>mon_get($s,'postprocessing',new stdClass())];
    }
    private static function completeOperation(): void {
        if(!self::$active||!property_exists(self::$active->run,'operation_policy'))return;$op=self::operation(self::$active);
        if(!$op['overall_complete']||$op['terminal'])return;
        self::operationUpdate(static function($s)use($op){$s->finished_at=MonBudget::iso(self::$active->now());
            $s->completion_receipt=(object)['contract'=>$op['contract'],'run_context_id'=>$op['run_context_id'],'received_at'=>$op['received_at'],
                'finished_at'=>$s->finished_at,'required_scopes'=>$op['required_scopes'],'business_revision'=>self::$active->run->finish_revision,
                'nas_body_sha256'=>mon_get(mon_get($s,'nas'),'body_sha256'),'statistics'=>$s->statistics,'policy_sha256'=>$s->policy_sha256];
            $s->completion_receipt->receipt_id=hash('sha256',MonRun::json($s->completion_receipt));});
    }
    public static function postReport($report): void {
        if(!self::$active||mon_get(self::$active->run,'finished_at')===null)throw new MonFault('MON_BUSINESS_COMPLETION_REQUIRED');
        $id=mon_get($report,'operation_id');$phase=mon_get($report,'phase');$operation=mon_get($report,'operation');
        if(!($report instanceof stdClass)||!is_string($id)||!preg_match('/^[A-Za-z0-9._:-]{1,96}$/',$id)||!in_array($phase,['begin','end'],true)||
            !in_array($operation,['statistics_publication','statistics_verification','nas_health'],true))throw new MonFault('MON_MEASUREMENT_INVALID');
        if($phase==='end'&&(!is_bool(mon_get($report,'success'))||!(is_numeric(mon_get($report,'seconds'))&&is_finite((float)$report->seconds)&&$report->seconds>=0&&$report->seconds<=2592000||
            mon_get($report,'seconds')===null&&mon_get($report,'timing_unmeasured')===true)))throw new MonFault('MON_MEASUREMENT_INVALID');
        self::operationUpdate(static function($s)use($report,$id,$phase,$operation){
            if(!(mon_get($s,'postprocessing')instanceof stdClass))$s->postprocessing=new stdClass();$old=mon_get($s->postprocessing,$id);
            if($old&&$old->operation!==$operation)throw new MonFault('MON_OPERATION_ID_REUSED');if($old&&$old->state==='acknowledged')return;
            $s->postprocessing->$id=(object)['operation'=>$operation,'state'=>$phase==='begin'?'open':'acknowledged','measurement'=>'operator_reported',
                'seconds'=>$phase==='end'?mon_get($report,'seconds'):null,'success'=>$phase==='end'?$report->success:null,'observed_at'=>MonBudget::iso(self::$active->now())];
        });self::completeOperation();
    }
    public static function verifyStatistics(MonPublication $publication,float $deadline): array {
        if(!self::$active||mon_get(self::$active->run,'finished_at')===null||mon_get(self::$active->run,'finish_fully_complete')!==true)throw new MonFault('MON_BUSINESS_COMPLETION_REQUIRED');
        $stats=MonRunStatistics::load(self::$store);$expected=null;foreach($stats->samples as $row)if($row->run_context_id===self::$active->run->run_context_id&&$row->revision===self::$active->run->finish_revision)$expected=$row;
        if(!$expected)throw new MonFault('MON_STATISTICS_REVISION_MISSING');$proof=$publication->statisticsProof($expected,$deadline);
        self::operationUpdate(static function($s)use($proof){$s->statistics=$proof;});self::completeOperation();return self::operation(self::$active);
    }

    public static function start(MonStore $store,$request,?callable $wall=null,?callable $mono=null): MonBudget {
        $client=mon_get($request,'run_id');
        if(!is_string($client)||!preg_match('/^[A-Za-z0-9._:-]{1,128}$/',$client))throw new MonFault('MON_RUN_ID_INVALID');
        $id=hash('sha256',self::CONTRACT.'|'.$client);
        $lock=@fopen($store->path('run-'.$id.'.lock'),'c+');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new MonFault('MON_RUN_BUSY');}
        try {
            $old=$store->readPrivate('run-'.$id.'.php');
            if($old instanceof stdClass){
                if(mon_get($request,'received_at')!==null && MonRun::utc($request->received_at)!==$old->received_at)throw new MonFault('MON_RUN_START_IMMUTABLE');
                if(mon_get($request,'mode',$old->mode)!==$old->mode||mon_get($request,'workload',mon_get($old,'workload','mon'))!==mon_get($old,'workload','mon'))throw new MonFault('MON_RUN_START_IMMUTABLE');
                if(property_exists($request,'operation_policy')&&MonRun::json(self::operationPolicy($request->operation_policy))!==MonRun::json(mon_get($old,'operation_policy')))throw new MonFault('MON_RUN_START_IMMUTABLE');
                return self::resume($store,$id,$wall,$mono);
            }
            $now=$wall?(float)$wall():microtime(true);$received=mon_get($request,'received_at');
            $partial=$received===null; $received=$partial?MonBudget::iso($now):MonRun::utc($received);
            if(MonRun::seconds($received)>$now+5 || $now-MonRun::seconds($received)>86400)throw new MonFault('MON_RUN_TIME_INVALID');
            $mode=mon_get($request,'mode','auto');$reason=mon_get($request,'extension_reason','');$workload=mon_get($request,'workload','mon');
            if(!in_array($mode,['auto','normal','extended'],true)||!is_string($reason)||strlen($reason)>1000||($mode==='extended'&&trim($reason)===''))throw new MonFault('MON_RUN_MODE_INVALID');
            if(!is_string($workload)||!preg_match('/^[A-Za-z0-9._:-]{1,32}$/',$workload))throw new MonFault('MON_WORKLOAD_INVALID');
            self::register($store,'',$now);
            $stats=MonRunStatistics::load($store);foreach($stats->samples as $record)if($record->run_context_id===$id)throw new MonFault('MON_RUN_ALREADY_RECORDED');
            $policy=MonRunStatistics::recommend($stats,$workload,$now);
            if($mode!=='auto'){$policy->reason='explicit_'.$mode;$policy->limits=(object)($mode==='extended'?['collection'=>280,'decision'=>290,'publication'=>300,'recovery'=>300]:['collection'=>100,'decision'=>110,'publication'=>120,'recovery'=>300]);}
            $run=(object)['contract'=>self::CONTRACT,'run_context_id'=>$id,'received_at'=>$received,'first_control_at'=>MonBudget::iso($now),
                'last_seen_at'=>MonBudget::iso($now),'mode'=>$mode,'extension_reason'=>$reason,'partial_timing'=>$partial,
                'timing_basis'=>$partial?'server_first_control':'caller_reported_receipt_plus_server_monotonic',
                'final_available'=>false,'new_analysis_complete'=>false,'analysis_origin'=>'none','save_status'=>'not_requested','events'=>[],
                'workload'=>$workload,'adaptive_policy'=>$policy,'operations'=>new stdClass(),'counts'=>new stdClass(),'reported_operations'=>[],
                'reported_displays'=>[],'unfinished'=>[],'finished_at'=>null,'statistics_status'=>'not_recorded'];
            if(property_exists($request,'operation_policy'))$run->operation_policy=self::operationPolicy($request->operation_policy);
            $run->immutable_sha256=MonRun::digest(self::immutable($run));
            $store->writePrivate('run-'.$id.'.php',$run);self::register($store,$id,$now,false);
            return self::resume($store,$id,$wall,$mono);
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function resume(MonStore $store,string $id,?callable $wall=null,?callable $mono=null): MonBudget {
        self::id($id);$run=$store->readPrivate('run-'.$id.'.php');
        if(!($run instanceof stdClass)||mon_get($run,'contract')!==self::CONTRACT||mon_get($run,'run_context_id')!==$id||
            !hash_equals((string)mon_get($run,'immutable_sha256'),MonRun::digest(self::immutable($run))))throw new MonFault('MON_RUN_CONTEXT_INVALID');
        self::$store=$store;self::$active=new MonBudget($run,$wall,$mono);return self::$active;
    }
    private static function register(MonStore $store,string $id,float $now,bool $sweep=true): void {
        $lock=@fopen($store->path('run-index.lock'),'c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return;}
        $oldActive=self::$active;$oldStore=self::$store;
        try{$index=$store->readPrivate('run-index.php');$ids=is_array(mon_get($index,'ids'))?array_slice($index->ids,-60):[];$keep=[];$checked=0;
            foreach($ids as $prior){try{self::id($prior);$r=$store->readPrivate('run-'.$prior.'.php');if(!($r instanceof stdClass))continue;
                if(mon_get($r,'statistics_status')==='recorded')continue;
                if($sweep&&$checked++<8){$b=self::resume($store,$prior,static function()use($now){return $now;});
                    if(mon_get($r,'finished_at')!==null)self::finish((string)mon_get($r,'finish_reason','deadline'),mon_get($r,'unfinished',[]),mon_get($r,'censored',false));
                    elseif($now>=$b->end('recovery')){ $b->guard('recovery');self::finish('deadline',[(object)['task'=>'pending_run','code'=>'MON_RESUME_REQUIRED']],true); }
                }
                if(self::$active&&mon_get(self::$active->run,'run_context_id')===$prior&&mon_get(self::$active->run,'statistics_status')==='recorded')continue;
                $keep[]=$prior;
            }catch(MonFault $e){$keep[]=$prior;}}
            if($id!=='')$keep[]=$id;$store->writePrivate('run-index.php',(object)['ids'=>array_slice(array_values(array_unique($keep)),-60)]);
        }finally{self::$active=$oldActive;self::$store=$oldStore;flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function mutate(callable $fn): void {
        if(!self::$active||!self::$store)return;$b=self::$active;$id=$b->run->run_context_id;
        $lock=@fopen(self::$store->path('event-'.$id.'.lock'),'c+');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new MonFault('MON_RUN_BUSY');}
        $buffer=self::$pending[$id]??null;
        try{$run=self::$store->readPrivate('run-'.$id.'.php');
            if(!($run instanceof stdClass)||!hash_equals((string)mon_get($run,'immutable_sha256'),MonRun::digest(self::immutable($run))))throw new MonFault('MON_RUN_CONTEXT_INVALID');
            $fn($run);self::$store->writePrivate('run-'.$id.'.php',$run);$b->run=$run;
        }catch(Throwable $e){if($buffer!==null)self::$pending[$id]=$buffer;throw $e;}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function consumeHttp(string $host): void {
        if(!self::$active)return;$p=mon_get(self::$active->run,'adaptive_policy');if(!$p)return;
        self::mutate(static function($r)use($host,$p){$n=(int)mon_get($r->counts,'http_calls',0);$data=(int)mon_get($r->counts,'data_http_calls',0);
            $r->counts->http_calls=$n+1;if($host!=='api.github.com')$r->counts->data_http_calls=$data+1;
            if($n+1>$p->http_calls)$r->counts->http_limit_hits=(int)mon_get($r->counts,'http_limit_hits',0)+1;
        });
    }
    public static function observeThreshold(string $phase): void {
        if(!self::$active||!self::$store||mon_get(mon_get(self::$active->run,'threshold_phases'),$phase,false))return;
        self::mutate(static function($r)use($phase){
            if(!(mon_get($r,'threshold_phases')instanceof stdClass))$r->threshold_phases=new stdClass();
            if(mon_get($r->threshold_phases,$phase,false))return;$r->threshold_phases->$phase=true;
            $r->events[]=(object)['phase'=>'threshold_exceeded','at'=>MonBudget::iso(self::$active->now()),'code'=>'MON_'.strtoupper($phase).'_THRESHOLD_EXCEEDED','recovery'=>'continue_pending_work'];
            $r->events=array_slice($r->events,-100);$r->last_seen_at=MonBudget::iso(self::$active->now());
        });
    }
    public static function continueWork(): void {
        if(!self::$active||mon_get(self::$active->run,'finished_at')===null)return;
        if(mon_get(self::$active->run,'finish_reason')==='interrupted')throw new MonFault('MON_RUN_INTERRUPTED');
        if(self::completion(self::$active)['fully_complete'])throw new MonFault('MON_RUN_FINISHED');
        self::mutate(static function($r){$r->finished_at=null;$r->terminal=false;$r->finish_reason='pending';$r->statistics_status='pending';});
    }
    public static function completion(MonBudget $budget): array {
        if(!self::$store)throw new MonFault('MON_RUN_CONTEXT_REQUIRED');
        return MonCompletion::assess(self::$store,$budget->run,MonBudget::iso($budget->now()));
    }
    public static function recoverFinal(string $action): void {
        if(!self::$active||!self::$store)throw new MonFault('MON_RUN_CONTEXT_REQUIRED');
        if(mon_get(self::$active->run,'finish_reason')==='interrupted')throw new MonFault('MON_RUN_INTERRUPTED');
        if(self::completion(self::$active)['fully_complete'])throw new MonFault('MON_RUN_FINISHED');
        if(!in_array($action,['confirm','reprepare'],true))throw new MonFault('MON_RECOVERY_ACTION_INVALID');
        self::mutate(static function($r)use($action){
            $history=mon_get($r,'final_revisions',[]);$history[]=(object)['artifact_id'=>mon_get($r,'artifact_id'),'job_id'=>mon_get($r,'job_id'),
                'recovery_action'=>$action,'at'=>MonBudget::iso(self::$active->now())];$r->final_revisions=array_slice($history,-20);
            $r->finished_at=null;$r->terminal=false;$r->finish_reason='pending';$r->finish_fully_complete=false;$r->statistics_status='pending';
            unset($r->job_id,$r->artifact_id,$r->completion_receipt);$r->save_status='not_requested';$r->reported_unfinished=[];
            if($action==='reprepare'){$r->new_analysis_complete=false;$r->final_available=false;$r->analysis_origin='recovery_preparation_required';}
        });
    }
    public static function countExcess($run): stdClass {
        $p=mon_get($run,'adaptive_policy');$c=mon_get($run,'counts',new stdClass());
        return (object)['http_calls'=>max(0,(int)mon_get($c,'http_calls',0)-(int)mon_get($p,'http_calls',30)),
            'data_http_calls'=>max(0,(int)mon_get($c,'data_http_calls',0)-max(0,(int)mon_get($p,'http_calls',30)-(int)mon_get($p,'publication_call_reserve',12))),
            'search_batches'=>max(0,(int)mon_get($c,'search_batches',0)-(int)mon_get($p,'external_search_batches',2)),
            'detail_attempts'=>max(0,(int)mon_get($c,'detail_attempt_excess',0))];
    }
    public static function httpTimeout(string $host,float $fallback): float {
        return self::$active?(float)mon_get(mon_get(mon_get(self::$active->run,'adaptive_policy'),'http_timeouts'),$host,$fallback):$fallback;
    }
    public static function measure(string $operation,float $seconds,?string $code=null,int $units=1,string $measurement='server',bool $retry=false): void {
        if(!self::$active||!self::$store)return;
        if(mon_get(self::$active->run,'finished_at')!==null&&!(in_array(self::$active->run->save_status,['queued','publishing'],true)&&
            (strpos($operation,'http/')===0||$operation==='handler/mon_publish_process')))return;
        if(!preg_match('/^(http|handler|detail|external|connector)\/[A-Za-z0-9._-]{1,64}$/',$operation)||!is_finite($seconds)||$seconds<0||$seconds>2592000||$units<0||$units>1000)throw new MonFault('MON_MEASUREMENT_INVALID');
        $id=self::$active->run->run_context_id;if(!isset(self::$pending[$id]))self::$pending[$id]=[];
        $known=array_unique(array_merge(array_keys(get_object_vars(mon_get(self::$active->run,'operations',new stdClass()))),array_keys(self::$pending[$id])));
        // Bound metric cardinality without cancelling work or losing counts/outcomes.
        if(!in_array($operation,$known,true)&&count($known)>=58)$operation=explode('/',$operation,2)[0].'/other';
        if(!isset(self::$pending[$id][$operation])){
            self::$pending[$id][$operation]=(object)['measurement'=>$measurement,'count'=>0,'successes'=>0,'timeouts'=>0,'failures'=>0,'retries'=>0,'retry_successes'=>0,'units'=>0,'seconds'=>[]];}
        $o=self::$pending[$id][$operation];$o->count++;$o->units+=$units;$ok=$code===null;$o->successes+=(int)$ok;$o->failures+=(int)!$ok;
        $o->timeouts+=(int)($code!==null&&(strpos($code,'DEADLINE')!==false||strpos($code,'TIMEOUT')!==false||in_array($code,['HTTP_TRANSPORT_28','HTTP_408','HTTP_LATE_RESPONSE','DETAIL_LATE_RESPONSE','BUDGET_EXHAUSTED'],true)));
        $o->retries+=(int)$retry;$o->retry_successes+=(int)($retry&&$ok);$o->seconds[]=round($seconds,6);$o->seconds=array_slice($o->seconds,-20);
    }
    private static function drain(stdClass $run): void {
        $id=$run->run_context_id;if(!isset(self::$pending[$id]))return;
        if(!(mon_get($run,'operations')instanceof stdClass))$run->operations=new stdClass();
        foreach(self::$pending[$id]as $key=>$o){$old=mon_get($run->operations,$key);
            if($old instanceof stdClass){foreach(['count','successes','timeouts','failures','retries','retry_successes','units']as $k)$old->$k+=$o->$k;$old->seconds=array_slice(array_merge($old->seconds,$o->seconds),-20);}
            else $run->operations->$key=$o;
        }unset(self::$pending[$id]);
    }
    public static function report($report): void {
        if($report instanceof stdClass&&property_exists($report,'phase')&&!in_array($report->phase,['begin','end'],true))throw new MonFault('MON_MEASUREMENT_INVALID');
        if($report instanceof stdClass&&mon_get($report,'phase')==='begin'){if(!preg_match('/^[A-Za-z0-9._:-]{1,96}$/',(string)mon_get($report,'operation_id'))||!in_array(mon_get($report,'operation'),['external/nas','external/search','external/source','connector/github'],true))throw new MonFault('MON_MEASUREMENT_INVALID');
            self::mutate(static function($r)use($report){if(mon_get($r,'finished_at')!==null)throw new MonFault('MON_RUN_FINISHED');if(in_array($report->operation_id,mon_get($r,'reported_operations',[]),true))return;if(!(mon_get($r,'external_operations')instanceof stdClass))$r->external_operations=new stdClass();$old=mon_get($r->external_operations,$report->operation_id);if($old&&$old->operation!==$report->operation)throw new MonFault('MON_OPERATION_ID_REUSED');$r->external_operations->{$report->operation_id}=(object)['state'=>'open','operation'=>$report->operation];});return;}
        if(!($report instanceof stdClass)||!preg_match('/^[A-Za-z0-9._:-]{1,96}$/',(string)mon_get($report,'operation_id'))||
            !in_array(mon_get($report,'operation'),['external/nas','external/search','external/source','connector/github'],true)||
            !is_bool(mon_get($report,'success'))||!(is_numeric(mon_get($report,'seconds'))&&is_finite((float)$report->seconds)&&$report->seconds>=0&&$report->seconds<=2592000||mon_get($report,'seconds')===null&&mon_get($report,'timing_unmeasured')===true)||
            !is_int(mon_get($report,'units',1))||mon_get($report,'units',1)<1||mon_get($report,'units',1)>1000||
            (mon_get($report,'code')!==null&&(!is_string($report->code)||!preg_match('/^[A-Z0-9_.-]{1,96}$/',$report->code))))throw new MonFault('MON_MEASUREMENT_INVALID');
        $accepted=false;$over=false;self::mutate(static function($r)use($report,&$accepted,&$over){
            if(mon_get($r,'finished_at')!==null)throw new MonFault('MON_RUN_FINISHED');
            if(!is_array(mon_get($r,'reported_operations')))$r->reported_operations=[];
            if(in_array($report->operation_id,$r->reported_operations,true))return;
            $over=$report->operation==='external/search'&&(int)mon_get(mon_get($r,'counts'),'search_batches',0)>=(int)mon_get(mon_get($r,'adaptive_policy'),'external_search_batches',2);
            $open=mon_get(mon_get($r,'external_operations'),$report->operation_id);if($open&&$open->operation!==$report->operation)throw new MonFault('MON_OPERATION_ID_REUSED');if($open)$open->state='acknowledged';
            $r->reported_operations[]=$report->operation_id;if(!(mon_get($r,'counts')instanceof stdClass))$r->counts=new stdClass();
            $r->counts->reported_external_units=(int)mon_get($r->counts,'reported_external_units',0)+(int)mon_get($report,'units',1);if($report->operation==='external/source')$r->counts->source_http_calls=(int)mon_get($r->counts,'source_http_calls',0)+(int)mon_get($report,'units',1);
            $k=$report->operation==='external/search'?'search_batches':'reported_external_calls';$r->counts->$k=(int)mon_get($r->counts,$k,0)+1;$accepted=true;
        });
        if($accepted){if(mon_get($report,'timing_unmeasured')===true)self::mutate(static function($r)use($report){if(!(mon_get($r,'unmeasured_operations')instanceof stdClass))$r->unmeasured_operations=new stdClass();$r->unmeasured_operations->{$report->operation_id}=MonRun::pick($report,['operation','success','units','code']);});else self::measure($report->operation,(float)$report->seconds,$report->success?null:(string)mon_get($report,'code','EXTERNAL_FAILED'),(int)mon_get($report,'units',1),'operator_reported');
            self::event('external_operation_reported',$over?['code'=>'MON_SEARCH_THRESHOLD_EXCEEDED']:[]);}
    }
    public static function finish(string $reason='finished',array $unfinished=[],bool $censored=false): array {
        if(!self::$active||!self::$store)throw new MonFault('MON_RUN_CONTEXT_REQUIRED');
        if(!in_array($reason,['finished','interrupted','failed','deadline'],true)||count($unfinished)>30)throw new MonFault('MON_FINISH_INVALID');
        foreach($unfinished as $row)if(!($row instanceof stdClass)||!preg_match('/^[A-Za-z0-9._: -]{1,96}$/',(string)mon_get($row,'task'))||
            !preg_match('/^[A-Z0-9_.-]{1,96}$/',(string)mon_get($row,'code'))||array_diff(array_keys((array)$row),['task','code']))throw new MonFault('MON_FINISH_INVALID');
        $b=self::$active;
        self::mutate(static function($r)use($unfinished){$r->reported_unfinished=$unfinished;});
        $completion=self::completion($b);$operation=self::operation($b,$completion);if($operation&&$operation['business_pending_jobs']){$completion['pending_jobs']=array_merge($completion['pending_jobs'],$operation['business_pending_jobs']);$completion['fully_complete']=false;$completion['required_checks_pending']=count($completion['pending_jobs']);$completion['completion_receipt']=null;}
        $required=array_map(static function($job){return (object)['task'=>$job->task,'code'=>$job->code];},$completion['pending_jobs']);
        // Keep the complete recovery queue; the legacy statistics projection remains bounded.
        $unfinished=array_slice($required,0,30);
        self::mutate(static function($r)use($reason,$unfinished,$b,$censored,$completion){self::drain($r);
            $fully=$completion['fully_complete']&&!in_array($reason,['interrupted','failed'],true);
            $r->completion=$completion;$r->required_jobs=$completion['pending_jobs'];
            if(mon_get($r,'finished_at')!==null&&!$fully&&$reason==='finished'&&in_array(mon_get($r,'finish_reason'),['failed','interrupted'],true))$reason=$r->finish_reason;
            if(!$unfinished&&!$fully)$unfinished=mon_get($r,'unfinished',[]);
            $terminal=$fully||in_array($reason,['interrupted','failed'],true);
            $state=MonRun::digest((object)['state'=>MonRun::pick($r,['final_available','new_analysis_complete','save_status','artifact_id','job_id','counts','operations']),'terminal'=>$terminal,
                'elapsed_bucket'=>$terminal?null:(int)floor($b->elapsed()/10),'unfinished'=>$unfinished]);
            if(mon_get($r,'finished_at')!==null&&mon_get($r,'finish_state_sha256')===$state)return;
            if(mon_get($r,'checkpoint_at')!==null&&!$terminal&&mon_get($r,'finish_state_sha256')===$state)return;
            if(mon_get($r,'finished_at')!==null&&!$unfinished&&!$fully)$unfinished=mon_get($r,'unfinished',[]);
            $r->finish_state_sha256=$state;$r->finish_revision=(int)mon_get($r,'finish_revision',0)+1;
            $at=MonBudget::iso($b->now());$r->finished_at=$terminal?$at:null;$r->checkpoint_at=$at;$r->terminal=$terminal;
            $r->elapsed_seconds=round($b->elapsed(),6);$r->finish_reason=$terminal?$reason:'pending';
            $r->censored=$censored;$r->finish_fully_complete=$fully;
            $r->unfinished=$unfinished;$r->statistics_status='pending';
            if($fully){$r->completion_receipt=$completion['completion_receipt'];self::$store->writePrivate('completion-receipt-'.$r->run_context_id.'.php',$r->completion_receipt);}
        });
        $r=$b->run;$fully=mon_get($r,'finish_fully_complete',false)===true;
        $observed=MonRun::seconds(mon_get($r,'checkpoint_at',$r->finished_at));$timeExcess=new stdClass();
        foreach(['collection','decision','publication','recovery']as $phase)$timeExcess->$phase=round(max(0,$observed-$b->end($phase)),6);
        $sample=(object)['run_context_id'=>$r->run_context_id,'revision'=>$r->finish_revision,'workload'=>mon_get($r,'workload','mon'),'received_at'=>$r->received_at,'finished_at'=>mon_get($r,'checkpoint_at',$r->finished_at),
            'elapsed_seconds'=>$r->elapsed_seconds,'first_control_seconds'=>round(max(0,MonRun::seconds($r->first_control_at)-MonRun::seconds($r->received_at)),6),
            'first_display_seconds'=>mon_get($r,'first_display_seconds'),'total_limit_seconds'=>(int)($b->end('publication')-MonRun::seconds($r->received_at)),
            'measurement'=>'server','partial_timing'=>$r->partial_timing,'censored'=>mon_get($r,'censored',false),'deadline_missed'=>MonRun::seconds(mon_get($r,'checkpoint_at',$r->finished_at))>$b->end('publication'),
            'new_analysis_complete'=>$r->new_analysis_complete,'fully_complete'=>$fully&&!mon_get($r,'censored',false),
            'terminal'=>mon_get($r,'terminal',true),'limit_behavior'=>'observe_and_continue','time_excess_seconds'=>$timeExcess,'count_excess'=>self::countExcess($r),
            'outcome'=>mon_get($r,'terminal',true)===false?'incomplete':(mon_get($r,'censored',false)?'expired':($fully?'complete':($r->finish_reason==='interrupted'?'interrupted':($r->finish_reason==='failed'?'failed':'incomplete')))),
            'operations'=>mon_get($r,'operations',new stdClass()),'counts'=>mon_get($r,'counts',new stdClass()),'unfinished'=>mon_get($r,'unfinished',[]),
            'completion_contract'=>MonCompletion::CONTRACT,'required_checks_pending'=>$completion['required_checks_pending'],
            'publication_verified'=>$completion['publication_verified'],'completion_receipt_id'=>$fully?$r->completion_receipt->receipt_id:null];
        $incoming=MonRunStatistics::seal((object)['contract'=>MonRunStatistics::CONTRACT,'generation'=>0,'samples'=>[$sample]]);
        try{$stats=MonRunStatistics::save(self::$store,$incoming);self::mutate(static function($r){$r->statistics_status=mon_get($r,'terminal',true)?'recorded':'checkpoint_recorded';});
            self::completeOperation();return ['finish_accepted'=>$fully||in_array($reason,['interrupted','failed'],true),'code'=>$fully?null:'MON_COMPLETION_PENDING',
                'run'=>self::view($b),'statistics'=>MonRunStatistics::summary($stats,$sample->workload,$b->now()),'next_policy'=>MonRunStatistics::recommend($stats,$sample->workload,$b->now()),'statistics_snapshot'=>$stats];
        }catch(MonFault $e){self::mutate(static function($r)use($e){$r->statistics_status=$e->faultCode;});throw $e;}
    }
    public static function event(string $phase,array $fields=[]): void {
        if(!self::$active||!self::$store)return;$budget=self::$active;
        if($phase==='final_display_reported'){if(!self::completion($budget)['fully_complete'])throw new MonFault('MON_COMPLETION_PENDING');$op=self::operation($budget);if($op&&!$op['overall_complete'])throw new MonFault('MON_OPERATION_PENDING');}
        self::mutate(static function($run)use($phase,$fields,$budget){
            self::drain($run);$row=(object)['phase'=>$phase,'at'=>MonBudget::iso($budget->now()),'elapsed_seconds'=>round($budget->elapsed(),6)];
            foreach(['symbol_id','code','attempts','recovery','previous_data','result_impact','next_check','job_id','reported_at']as $k)if(isset($fields[$k]))$row->$k=$fields[$k];
            if(!(mon_get($run,'counts')instanceof stdClass))$run->counts=new stdClass();
            if(in_array($phase,['initial_display_reported','intermediate_display_reported','final_display_reported'],true)){
                if(!is_array(mon_get($run,'reported_displays')))$run->reported_displays=[];
                $key=$phase==='intermediate_display_reported'?$phase.'|'.(string)mon_get($fields,'reported_at','once'):$phase;
                if(in_array($key,$run->reported_displays,true))return;
                if(mon_get($run,'finished_at')!==null&&$phase!=='final_display_reported')throw new MonFault('MON_RUN_FINISHED');
                $run->reported_displays[]=$key;$run->counts->displays_reported=(int)mon_get($run->counts,'displays_reported',0)+1;
                $row->measurement='operator_reported';
                if(mon_get($fields,'reported_at')!==null){$at=MonRun::seconds(MonRun::utc($fields['reported_at']));
                    if($at<MonRun::seconds($run->received_at)||$at>$budget->now()+5)throw new MonFault('MON_DISPLAY_TIME_INVALID');
                    if($phase==='initial_display_reported')$run->first_display_seconds=round($at-MonRun::seconds($run->received_at),6);
                }
            }
            if(mon_get($fields,'code')==='MON_HTTP_COUNT_LIMIT')$run->counts->http_limit_hits=(int)mon_get($run->counts,'http_limit_hits',0)+1;
            if(in_array(mon_get($fields,'code'),['MON_SEARCH_COUNT_LIMIT','MON_SEARCH_THRESHOLD_EXCEEDED'],true))$run->counts->search_limit_hits=(int)mon_get($run->counts,'search_limit_hits',0)+1;
            if($phase==='detail_attempt'&&(int)mon_get($fields,'attempts',0)>(int)mon_get(mon_get($run,'adaptive_policy'),'detail_attempts',2))$run->counts->detail_attempt_excess=(int)mon_get($run->counts,'detail_attempt_excess',0)+1;
            if($phase==='failure')$run->counts->handler_failures=(int)mon_get($run->counts,'handler_failures',0)+1;
            $run->events[]= $row; $run->events=array_slice($run->events,-100);$run->last_seen_at=$row->at;
            foreach(['final_available','new_analysis_complete','analysis_origin','save_status','job_id','artifact_id']as $k)if(array_key_exists($k,$fields))$run->$k=$fields[$k];
        });
    }
    public static function view(MonBudget $b): array {
        $complete=mon_get($b->run,'new_analysis_complete',false)===true;
        $completion=self::completion($b);$next=$completion['next_action'];
        $actions=['analysis'=>'continue_pending_work','verified_artifact'=>'continue_pending_work','verification_binding'=>'repair_verification_binding',
            'analysis_reprepare'=>'continue_preparation','calendar'=>'verify_market_calendar','new_identity_quota'=>'continue_discovery','issuer_review'=>'continue_issuer_review',
            'current_entry'=>'continue_current_entry','reported_work'=>'continue_pending_work','publication'=>'continue_publication','complete'=>'complete'];
        if(mon_get($b->run,'finish_reason')==='interrupted')$next='interrupted';
        return ['contract'=>self::CONTRACT,'run_context_id'=>$b->run->run_context_id,'timing'=>$b->timing(),
            'final_available'=>$b->run->final_available,'save_status'=>$b->run->save_status,
            'new_analysis_complete'=>$complete,'analysis_origin'=>mon_get($b->run,'analysis_origin','unmeasured'),
            'fully_complete'=>$completion['fully_complete'],'completion'=>$completion,'operation'=>self::operation($b,$completion),
            'limit_behavior'=>'observe_and_continue','stop_on_threshold'=>false,'count_excess'=>self::countExcess($b->run),
            'next_action'=>$next==='interrupted'?'interrupted':($actions[$next]??'continue_pending_work'),'next_required_job'=>$completion['pending_jobs'][0]??null,
            'remaining_seconds'=>['collection'=>round($b->remaining('collection'),3),'decision'=>round($b->remaining('decision'),3),'publication'=>round($b->remaining('publication'),3)],
            'remaining_counts'=>['http_calls'=>max(0,(int)mon_get(mon_get($b->run,'adaptive_policy'),'http_calls',30)-(int)mon_get(mon_get($b->run,'counts'),'http_calls',0)),
                'search_batches'=>max(0,(int)mon_get(mon_get($b->run,'adaptive_policy'),'external_search_batches',2)-(int)mon_get(mon_get($b->run,'counts'),'search_batches',0))],
            'job_id'=>mon_get($b->run,'job_id'),'artifact_id'=>mon_get($b->run,'artifact_id'),'events'=>$b->run->events,
            'adaptive_policy'=>mon_get($b->run,'adaptive_policy'),'counts'=>mon_get($b->run,'counts',new stdClass()),
            'operations'=>mon_get($b->run,'operations',new stdClass()),'unfinished'=>mon_get($b->run,'unfinished',[]),
            'finished_at'=>mon_get($b->run,'finished_at'),'checkpoint_at'=>mon_get($b->run,'checkpoint_at'),'terminal'=>mon_get($b->run,'terminal',mon_get($b->run,'finished_at')!==null),
            'statistics_status'=>mon_get($b->run,'statistics_status','legacy_unmeasured')];
    }
}

/* Initial/failure view is a small, provisional projection. Never prepare indicators or select stocks here. */
final class MonBootstrap {
    const CONTRACT='MON-BOOTSTRAP-1.0';
    public static function build($d,string $at,?string $sha=null,int $cursor=0,string $token=''): array {
        MonRun::utc($at);$cards=[];$counts=['KR'=>0,'US'=>0,'JP'=>0];$periods=[];$asOf=null;$sourceError=null;
        try{
            if(!($d instanceof stdClass))throw new MonFault('MON_BOOTSTRAP_SOURCE_UNAVAILABLE');
            MonRun::validate($d);$asOf=$d->analysis->as_of;$watch=MonRun::rows($d->watchlist->symbols);$periods=MonRun::periods($d,$at);
            foreach($d->analysis->results as $r){
                $sid=mon_get($r,'symbol_id');$symbol=$watch[$sid]??null;
                if(!$symbol||!in_array(mon_get($r,'action'),['BUY_REVIEW','SELL_REVIEW','REFERENCE_REVIEW'],true)&&!$symbol->is_held)continue;
                $country=$symbol->country;$current=mon_get($periods[$country]??null,'current_period',false);
                $now=MonRun::current($r,$d->watchlist,$at,$current);$reason=(string)mon_get($r,'reason','');
                $short=preg_replace('/^(.{0,100}).*/us','$1',$reason);
                $cards[]=(object)['symbol_id'=>$sid,'country'=>$country,'name'=>$symbol->name,'currency'=>$symbol->currency,
                    'stored_action'=>$r->action,'state'=>$r->action==='REFERENCE_REVIEW'||!$current?'previous_reference':'previous_decision_provisional',
                    'readiness'=>$now->readiness==='ready'?'needs_confirmation':$now->readiness,'blockers'=>$now->blockers,
                    'signal_as_of'=>mon_get($r,'signal_as_of',mon_get(mon_get($r,'swing_signal'),'bars_as_of')),
                    'entry_plans'=>mon_get($r,'entry_plans',[]),'plan_valid_until'=>mon_get($r,'plan_valid_until'),
                    'reason_excerpt'=>$short,'reason_sha256'=>hash('sha256',$reason)];$counts[$country]++;
            }
        }catch(Throwable $e){$cards=[];$counts=['KR'=>0,'US'=>0,'JP'=>0];$sourceError=$e instanceof MonFault?$e->faultCode:'MON_BOOTSTRAP_SOURCE_INVALID';}
        $snapshot=MonRun::digest([$asOf,$sha,$at,$cards,$periods,$sourceError]);
        if($cursor!==0&&($token===''||!hash_equals($snapshot,$token)))throw new MonFault('MON_BOOTSTRAP_CURSOR_INVALID');
        $header=['contract'=>self::CONTRACT,'processor_version'=>MON_VERSION,'detail_codec'=>MonDetails::CODEC,'operation_completion'=>'MON-OPERATION-COMPLETION-1.0','snapshot_token'=>$snapshot,'rendered_at'=>$at,'analysis_as_of'=>$asOf,
            'source_blob_sha'=>$sha,'source_validation'=>'schema_only_previous_record','new_analysis_complete'=>false,'analysis_origin'=>'previous_saved_result','auto_selected'=>false,
            'write_required'=>false,'save_status'=>'not_requested','state'=>$cards?'previous_saved_result':'needs_data','counts'=>$counts,'periods'=>array_values($periods),'source_error'=>$sourceError];
        $pages=MonPaging::pages($header,$cards,8192);if(!isset($pages['pages'][$cursor]))throw new MonFault('MON_BOOTSTRAP_CURSOR_INVALID');
        $page=$pages['pages'][$cursor];$page['page']=['index'=>$cursor,'total'=>count($pages['pages']),'next_cursor'=>$cursor+1<count($pages['pages'])?$cursor+1:null];
        return $page;
    }
    public static function fromStore(MonStore $store,?string $at=null,int $cursor=0,string $token=''): array {
        $at=$at??mon_iso();$seconds=MonRun::seconds($at);if($seconds>microtime(true)+5||$seconds<microtime(true)-60)throw new MonFault('MON_BOOTSTRAP_TIME_INVALID');
        try{$d=$store->read('remote_cache.json');$sha=mon_get(mon_get($d,'_mon_cache'),'blob_sha');return self::build($d,$at,is_string($sha)?$sha:null,$cursor,$token);}
        catch(MonFault $e){if(in_array($e->faultCode,['MON_BOOTSTRAP_CURSOR_INVALID','MON_BOOTSTRAP_TIME_INVALID'],true))throw $e;return self::build(null,$at);}
    }
    public static function recover($data,$request=null,?MonStore $store=null): array {
        if(!($data instanceof stdClass)&&$request instanceof stdClass)try{
            $c=mon_get($request,'context',mon_get(mon_get($request,'decision'),'view_context'));$sha=mon_get($c,'source_blob_sha');
            if(is_string($sha))$data=mon_run_source($request,'source_json',$sha);
        }catch(Throwable $ignore){}
        if($data instanceof stdClass)return self::build($data,mon_iso());
        if($store)try{return self::fromStore($store);}catch(Throwable $ignore){}
        return self::build(null,mon_iso());
    }
    public static function summary(array $view,string $code): string {
        $h=$view['header'];$text='처리 제한 · '.$code.' · 기존 판단 기준 '.($h['analysis_as_of']??'없음').' · 이전 기록 잠정 재표시';
        if(!$view['cards'])return '자료 부족 · '.$text.' · 저장·현재 진입 여부 미확인';
        foreach($view['cards']as $r)$text.="\n".(mon_get($r,'country','')).' '.mon_get($r,'name',mon_get($r,'symbol_id','원문 확인 필요')).' · '.mon_get($r,'state','원문 확인 필요').' · '.mon_get($r,'signal_as_of','미확인').' · '.mon_get($r,'readiness','needs_data');
        return $text.' · 새 선정·원래 판단시각 갱신 없음';
    }
}
final class MonPaging {
    public static function pages(array $header,array $cards,int $limit=12288): array {
        $out=[];$artifacts=new stdClass();
        if(strlen(MonRun::json((object)['header'=>$header,'cards'=>[]]))>$limit-1024){
            $raw=MonRun::json((object)$header);$sha=hash('sha256',$raw);$artifacts->$sha=(object)$header;
            $header=(array)MonRun::pick((object)$header,['state_token','prepared_fingerprint','as_of','criteria_version','source_blob_sha']);
            $header['header_artifact_sha256']=$sha;$header['header_bytes']=strlen($raw);
        }
        $page=['header'=>$header,'cards'=>[],'artifact_cards'=>new stdClass()];
        foreach($cards as $card){
            $test=$page;$test['cards'][]=$card;
            if(strlen(MonRun::json((object)$test))>$limit-256){
                if($page['cards']){$out[]=$page;$page=['header'=>$header,'cards'=>[],'artifact_cards'=>new stdClass()];}
                $test=$page;$test['cards'][]=$card;
                if(strlen(MonRun::json((object)$test))>$limit-256){
                    $raw=MonRun::json($card);$id=hash('sha256',$raw);$page['artifact_cards']->$id=$card;
                    $card=(object)['symbol_id'=>mon_get($card,'symbol_id'),'card_kind'=>'artifact_reference','artifact_sha256'=>$id,'bytes'=>strlen($raw),'content_ref'=>'artifact_cards:'.$id];
                }
            }
            $page['cards'][]=$card;
        }
        if($page['cards']||!$out)$out[]=$page;
        // Full oversized cards are transport artifacts, never part of a model page.
        foreach($out as &$p){foreach($p['artifact_cards']as $id=>$c)$artifacts->$id=$c;unset($p['artifact_cards']);}
        unset($p);$total=count($out);
        foreach($out as $i=>&$p){$p['page']=['index'=>$i,'count'=>$total];$p['model_input_bytes']=0;
            for($n=0;$n<3;$n++)$p['model_input_bytes']=strlen(MonRun::json((object)$p));
            if($p['model_input_bytes']>$limit)throw new MonFault('MON_VIEW_ITEM_TOO_LARGE');}unset($p);
        return ['pages'=>$out,'artifact_cards'=>$artifacts];
    }
}
final class MonPreparation {
    public static $store=null;public static $builds=0;private static $memory=[];
    public static function key($d,$context,array $liveRows=[]): string {
        $c=MonRun::context($context);$p=MonRun::payload($d);$live=[];
        foreach(MonRun::rows($liveRows)as $sid=>$row)$live[$sid]=MonSwing::live($row,$d->watchlist,$c->rendered_at);
        ksort($live,SORT_STRING);
        // Exact input bytes: never erase a source change by rounding the cache key.
        return hash('sha256',MonRun::json((object)['version'=>MON_VERSION,'rules'=>MonSwing::rules(),
            'series'=>mon_get($p,'series'),'metrics'=>mon_get($p,'candidate_metrics'),'history'=>mon_get($p,'history_metadata'),
            'imports'=>mon_get($p,'detail_imports'),'plans'=>mon_get($p,'detail_plans'),'previous'=>mon_get($p,'selection_facts'),
            'old_plans'=>mon_get($p,'final_results_details'),'audit'=>$d->analysis->candidate_audit,
            'mapping'=>$d->watchlist->symbols,'calendar'=>$d->watchlist->calendar,'settings'=>$d->watchlist->settings,
            'periods'=>MonRun::periods($d,$c->rendered_at),'countries'=>$c->countries,'live'=>(object)$live]));
    }
    public static function get(string $key): ?array {
        if(isset(self::$memory[$key]))return unserialize(serialize(self::$memory[$key]));
        if(self::$store)try{$cache=self::$store->readPrivate('prepare-cache.php');
            if(mon_get($cache,'key')===$key&&mon_get($cache,'version')===MON_VERSION&&hash_equals((string)mon_get($cache,'sha256'),hash('sha256',MonRun::json($cache->prepared)))){
                $a=(array)$cache->prepared;foreach(['counts','rank_hint','preparation']as $k)$a[$k]=self::arrays($a[$k]);
                self::$memory[$key]=$a;return unserialize(serialize($a));
            }
        }catch(MonFault $e){/* Cache failure cannot bypass source validation. */}
        return null;
    }
    private static function arrays($v){if($v instanceof stdClass){$a=[];foreach($v as $k=>$x)$a[$k]=self::arrays($x);return $a;}if(is_array($v))return array_map([self::class,'arrays'],$v);return $v;}
    public static function put(string $key,array $prepared): void {
        MonSwing::flushTechnicalCache();
        $copy=$prepared;unset($copy['state_token'],$copy['as_of']);self::$memory=[$key=>unserialize(serialize($copy))];self::$builds++;
        if(self::$store)try{self::$store->writePrivate('prepare-cache.php',(object)['version'=>MON_VERSION,'key'=>$key,'sha256'=>hash('sha256',MonRun::json((object)$copy)),'prepared'=>(object)$copy]);}
        catch(MonFault $e){self::$store->log('prepare_cache_unavailable',['code'=>$e->faultCode]);}
    }
    public static function reset(): void {self::$memory=[];self::$builds=0;self::$store=null;}
}

final class MonRiskState {
    public static function at($review,string $country,string $at): stdClass {
        $issuerAt=mon_get($review,'checked_at');$tradingAt=mon_get($review,'trading_checked_at',$issuerAt);$issuer=false;$trading=false;$issuerAge=null;$tradingAge=null;
        try{$zone=MonMarket::timezone($country);$day=(new DateTimeImmutable(MonRun::utc($at)))->setTimezone($zone)->format('Y-m-d');$now=MonRun::seconds($at);
            $inspect=static function($value)use($zone,$day,$now){try{if(!is_string($value))return [null,false];$t=MonRun::utc($value);$age=$now-MonRun::seconds($t);return [$age,$age>=-5&&(new DateTimeImmutable($t))->setTimezone($zone)->format('Y-m-d')===$day];}catch(Throwable $e){return [null,false];}};
            [$issuerAge,$issuer]=$inspect($issuerAt);[$tradingAge,$trading]=$inspect($tradingAt);$trading=$trading&&$tradingAge<=300;
        }catch(Throwable $e){$issuer=false;$trading=false;}
        return (object)['issuer_at'=>$issuerAt,'trading_at'=>$tradingAt,'issuer_age'=>$issuerAge,'trading_age'=>$tradingAge,'issuer_same_day'=>$issuer,'trading_current'=>$trading,
            'corporate_verified'=>$issuer&&mon_get($review,'status')==='verified'&&!mon_get($review,'pending_items',[]),
            'trading_verified'=>$trading&&mon_get($review,'trading_status')==='verified','halted'=>$trading&&mon_get($review,'trading_status')==='halted'];
    }
    public static function updateChecks($r,string $at): stdClass {
        $v=self::at(mon_get($r,'risk_review'),$r->country,$at);if(!(mon_get($r,'risk_checks')instanceof stdClass))$r->risk_checks=new stdClass();
        $r->risk_checks->current_corporate_risk_verified=$v->corporate_verified;$r->risk_checks->current_trading_status_verified=$v->trading_verified;
        if($v->corporate_verified||$v->trading_verified)$r->risk_checks->verified_as_of=$v->trading_verified?$v->trading_at:$v->issuer_at;
        return $v;
    }
}
final class MonTradingReview {
    public static function apply($r,$base,$change,string $fixed,callable $require): void {
        if(!property_exists($change,'trading_review'))return;$v=$change->trading_review;
        if(!($v instanceof stdClass)||array_diff(array_keys((array)$v),['status','checked_at','applies_as_of','evidence_ids'])||!in_array(mon_get($v,'status'),['verified','needs_check'],true))throw new MonFault('MON_TRADING_REVIEW_INVALID');
        $require(mon_get($v,'evidence_ids'));$checked=MonRun::seconds(mon_get($v,'checked_at'));
        if(MonRun::utc(mon_get($v,'applies_as_of'))!==MonRun::utc($fixed)||$checked>MonRun::seconds($fixed)+5||$checked<MonRun::seconds($base->analysis->as_of))throw new MonFault('MON_TRADING_REVIEW_TIME_INVALID');
        if($v->status==='verified'){
            $q=mon_get($change,'price_observation');$ids=mon_get($q,'evidence_ids');
            if(!($q instanceof stdClass)||!is_array($ids)||!$ids||mon_get($q,'symbol_id')!==$r->symbol_id||mon_get($q,'source_kind')!=='independent'||
                array_diff($v->evidence_ids,$ids)||!MonRun::quoteState($r,$base->watchlist,$fixed)->valid)throw new MonFault('MON_TRADING_OBSERVATION_REQUIRED');
        }
        $review=mon_get($r,'risk_review');if(!($review instanceof stdClass))$review=(object)['status'=>'needs_check','pending_items'=>['issuer_review'],'checked_at'=>null];else $review=clone $review;
        $review->trading_status=$v->status;$review->trading_checked_at=MonRun::utc($v->checked_at);$review->trading_evidence_ids=$v->evidence_ids;$r->risk_review=$review;
        $r->evidence_ids=array_values(array_unique(array_merge(mon_get($r,'evidence_ids',[]),$v->evidence_ids)));
    }
}
final class MonMeaning {
    const VOLATILE=['as_of','applies_as_of','decided_at','analyzed_at','rendered_at','updated_at','run_id','collection_id','age_seconds','readiness','verification_status','action_price_valid','session_at_analysis','readiness_blockers','quote_valid_until'];
    private static function feed($hash,$v): void {
        $flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR;
        if($v instanceof stdClass){
            $keys=array_values(array_diff(array_keys(get_object_vars($v)),self::VOLATILE));sort($keys,SORT_STRING);hash_update($hash,'{');$first=true;
            foreach($keys as $key){if(!$first)hash_update($hash,',');$first=false;hash_update($hash,json_encode((string)$key,$flags).':');self::feed($hash,$v->$key);}hash_update($hash,'}');return;
        }
        if(is_array($v)){
            $list=true;$i=0;foreach($v as $key=>$unused)if($key!==$i++){$list=false;break;}hash_update($hash,$list?'[':'{');$first=true;
            foreach($v as $key=>$value){if(!$first)hash_update($hash,',');$first=false;if(!$list)hash_update($hash,json_encode((string)$key,$flags).':');self::feed($hash,$value);}hash_update($hash,$list?']':'}');return;
        }
        hash_update($hash,json_encode($v,$flags));
    }
    private static function digest($v): string {
        // Hash the identical canonical stable JSON incrementally, without copying all bars twice.
        $precision=ini_get('serialize_precision');ini_set('serialize_precision','-1');$hash=hash_init('sha256');
        try{self::feed($hash,$v);return hash_final($hash);}finally{ini_set('serialize_precision',(string)$precision);}
    }
    public static function same($base,$candidate,$payload): bool {
        $old=MonRun::payload($base);
        $fact=static function($d,$p){return (object)['criteria'=>$d->analysis->criteria_version,
            'series'=>mon_get($p,'series'),'metrics'=>mon_get($p,'candidate_metrics'),'history'=>mon_get($p,'history_metadata'),
            'results'=>mon_get($p,'final_results_details'),'evidence'=>$d->analysis->evidence,
            'corporate_risk'=>mon_get($p->selection_facts,'corporate_risk'),'positions'=>mon_get($p->selection_facts,'positions'),
            'settings'=>$d->watchlist->settings,'calendar'=>$d->watchlist->calendar,
            'symbols'=>$d->watchlist->symbols,'discovery'=>mon_get($d->analysis,'discovery_state')];};
        return hash_equals(self::digest($fact($base,$old)),self::digest($fact($candidate,$payload)));
    }
}
final class MonConfirmation {
    public static function apply($base,$out,$p,$confirmation,string $fixed): void {
        $old=MonRun::payload($base);$watch=MonRun::rows($out->watchlist->symbols);$evidence=MonRun::evidence($base,$old);$new=[];
        if($confirmation===null)$confirmation=(object)['new_evidence'=>[],'changes'=>[]];
        if(!($confirmation instanceof stdClass)||!is_array(mon_get($confirmation,'new_evidence'))||!is_array(mon_get($confirmation,'changes'))||
            count($confirmation->new_evidence)>1000||count($confirmation->changes)>1000)throw new MonFault('MON_CONFIRMATION_INVALID');
        if(!(mon_get($p,'evidence_archive')instanceof stdClass))$p->evidence_archive=new stdClass();
        foreach($confirmation->new_evidence as $e){
            $id=mon_get($e,'id');$url=mon_get($e,'source_url');
            if(!($e instanceof stdClass)||!is_string($id)||!preg_match('/^[A-Za-z0-9._:-]{1,180}$/',$id)||mon_get($e,'source_kind')!=='independent'||
                !is_string($url)||parse_url($url,PHP_URL_SCHEME)!=='https'||!parse_url($url,PHP_URL_HOST)||!is_string(mon_get($e,'claim'))||trim($e->claim)==='')throw new MonFault('MON_EVIDENCE_INVALID');
            $checked=MonRun::seconds(mon_get($e,'checked_at'));
            if($checked>MonRun::seconds($fixed)+5||$checked<MonRun::seconds($base->analysis->as_of))throw new MonFault('MON_EVIDENCE_TIME_INVALID');
            if(isset($new[$id])||(isset($evidence[$id])&&MonRun::json($evidence[$id])!==MonRun::json($e)))throw new MonFault('MON_EVIDENCE_ID_CONFLICT');
            $new[$id]=$e;if(!isset($evidence[$id]))$out->analysis->evidence[]=$e;$p->evidence_archive->$id=$e;$evidence[$id]=$e;
        }
        $require=static function($ids)use($evidence){if(!is_array($ids)||!$ids)throw new MonFault('MON_EVIDENCE_MISSING');foreach($ids as $id)if(!is_string($id)||!isset($evidence[$id])||mon_get($evidence[$id],'source_kind')!=='independent')throw new MonFault('MON_EVIDENCE_MISSING');};
        // Keep original observations when only the display time changed.
        foreach($out->analysis->results as $r){
            $prior=mon_get($old->final_results_details,$r->symbol_id);
            if($prior instanceof stdClass && $r->action==='BUY_REVIEW' && $prior->action==='BUY_REVIEW'){
                foreach(['price','price_as_of','price_type','price_checks','risk_checks','risk_review']as $k)if(property_exists($prior,$k))$r->$k=mon_decode(MonRun::json($prior->$k));
                $r->evidence_ids=array_values(array_unique(array_merge(mon_get($r,'evidence_ids',[]),mon_get($prior,'evidence_ids',[]))));
            }
        }
        $rows=MonRun::rows($out->analysis->results);$seen=[];
        foreach($confirmation->changes as $change){
            $sid=mon_get($change,'symbol_id');MonRun::identity($sid);
            if(isset($seen[$sid])||!isset($rows[$sid],$watch[$sid]))throw new MonFault('MON_CONFIRMATION_SYMBOL_INVALID');$seen[$sid]=true;$r=$rows[$sid];$wr=$watch[$sid];
            foreach($change as $key=>$unused)if(!in_array($key,['symbol_id','price_observation','risk_review','trading_review','reason','next_check'],true))throw new MonFault('MON_CONFIRMATION_INVALID');
            if(property_exists($change,'price_observation')){
                $q=$change->price_observation;$require(mon_get($q,'evidence_ids'));
                if(!($q instanceof stdClass)||mon_get($q,'symbol_id')!==$sid||mon_get($q,'source_kind')!=='independent'||
                    mon_get($q,'currency')!==$wr->currency||mon_get($q,'venue')!==$wr->exchange||mon_number(mon_get($q,'price'))<=0||
                    !in_array(mon_get($q,'price_type'),['last','minute_close','close'],true)||!in_array(mon_get($q,'timestamp_basis'),['trade','bar_end','close','unknown'],true))throw new MonFault('MON_PRICE_IDENTITY_INVALID');
                $at=MonRun::utc(mon_get($q,'quote_at'));$delay=mon_get($q,'delay_seconds');
                if($delay!==null&&(mon_number($delay)===null||mon_number($delay)<0)||MonRun::seconds($at)>MonRun::seconds($fixed)+5)throw new MonFault('MON_PRICE_INVALID');
                $r->price=(float)MonRun::decimal6($q->price);$r->price_as_of=$at;$r->price_type=$q->price_type;
                $r->price_checks=(object)['timestamp_basis'=>$q->timestamp_basis,'delay_seconds'=>$delay,'delay_kind'=>$delay===null?'unknown':($delay==0?'realtime':'delayed'),
                    'market_date'=>mon_get($q,'market_date'),'session_at_analysis'=>mon_get($q,'session','unknown'),'source'=>mon_get($q,'source','independent')];
                $r->evidence_ids=array_values(array_unique(array_merge($r->evidence_ids,$q->evidence_ids)));
                if(!is_array(mon_get($p,'independent_price_observations')))$p->independent_price_observations=[];
                $p->independent_price_observations[]=$q;
            }
            if(property_exists($change,'risk_review')){
                $review=$change->risk_review;$require(mon_get($review,'evidence_ids'));
                if(!($review instanceof stdClass)||!in_array(mon_get($review,'status'),['verified','needs_check'],true)||
                    !in_array(mon_get($review,'trading_status'),['verified','needs_check','halted'],true)||!is_string(mon_get($review,'scope'))||
                    trim($review->scope)===''||!is_array(mon_get($review,'pending_items'))||($review->status==='verified'&&$review->pending_items))throw new MonFault('MON_RISK_REVIEW_INVALID');
                if(MonRun::utc(mon_get($review,'applies_as_of'))!==MonRun::utc($fixed)||MonRun::seconds(mon_get($review,'checked_at'))>MonRun::seconds($fixed)+5||
                    MonRun::seconds($review->checked_at)<MonRun::seconds($base->analysis->as_of))throw new MonFault('MON_RISK_REVIEW_TIME_INVALID');
                if(property_exists($review,'confirmed_material_adverse')&&!is_bool($review->confirmed_material_adverse))throw new MonFault('MON_RISK_REVIEW_INVALID');
                $r->risk_review=$review;$r->evidence_ids=array_values(array_unique(array_merge($r->evidence_ids,$review->evidence_ids)));
                if(!(mon_get($r,'risk_checks')instanceof stdClass))$r->risk_checks=new stdClass();
                if(mon_get($review,'confirmed_material_adverse')===true)$r->risk_checks->confirmed_material_adverse=true;
                elseif($review->status==='verified'&&property_exists($review,'confirmed_material_adverse'))$r->risk_checks->confirmed_material_adverse=false;
            }
            MonTradingReview::apply($r,$base,$change,$fixed,$require);
            foreach(['reason','next_check']as $k)if(property_exists($change,$k)){
                if(!is_string($change->$k)||trim($change->$k)===''||strlen($change->$k)>1000)throw new MonFault('MON_CONFIRMATION_INVALID');$r->$k=$change->$k;
            }
        }
        $periods=MonRun::periods($out,$fixed);$used=[];$ready=[];$independent=[];$now=MonRun::seconds($fixed);
        foreach($out->analysis->results as $r){
            $sid=$r->symbol_id;MonRiskState::updateChecks($r,$fixed);
            $state=MonRun::current($r,$out->watchlist,$fixed,mon_get($periods[$r->country]??null,'current_period')===true);
            $r->price_checks->action_price_valid=$state->quote->valid;$r->price_checks->age_seconds=$state->quote->age_seconds;$r->price_checks->readiness_blockers=$state->blockers;
            if($r->action==='BUY_REVIEW')$r->readiness=$state->readiness;
            $r->verification_status=$r->readiness==='ready'?'verified':'needs_check';
            $r->active_plan=$r->readiness==='ready'?($state->matching_plans[0]??null):null;
            if($r->active_plan)foreach($r->entry_plans as $plan)if($plan->mode===$r->active_plan){$r->entry_zone=$plan->entry_zone;$r->invalidation=$plan->invalidation;}
            $r->name=isset($watch[$sid])?$watch[$sid]->name:$sid;
            $p->final_results_details->$sid=$r;$p->independent_results->$sid=$r;
            $used[]=MonRun::pick($r,['symbol_id','price','price_type','price_as_of','price_checks','risk_checks','risk_review','evidence_ids']);
            $independent[]=(object)['symbol_id'=>$sid,'rank'=>$r->rank,'action'=>$r->action,'readiness'=>$r->readiness,'result_ref'=>'independent_results:'.$sid];
            if($r->action==='BUY_REVIEW'&&$r->readiness==='ready')$ready[$r->country]=($ready[$r->country]??0)+1;
        }
        $p->input_facts->used_prices=$used;$out->analysis->independent_results=$independent;
        foreach($out->analysis->coverage as $row)$row->ready_count=$ready[$row->country]??0;
    }
}
final class MonDiscovery {
    public static function pool($d,?stdClass $p=null): stdClass {return mon_get($p??MonRun::payload($d),'discovery_candidates',new stdClass());}
    private static function identityVerified($symbol,array $proof,string $at): bool {
        $ids=mon_get($symbol,'identity_evidence_ids',[]);if(!is_array($ids)||!$ids)return false;
        foreach($ids as $id){if(!is_string($id))return false;$e=$proof[$id]??null;$m=mon_get($e,'identity');$checked=mon_time(mon_get($e,'checked_at'));
            if(mon_get($e,'source_kind')!=='independent'||mon_get($e,'symbol_id')!==$symbol->symbol_id||!($m instanceof stdClass)||
                !preg_match('~^https://[^\s]+$~',(string)mon_get($e,'source_url'))||$checked===null||$checked>MonRun::seconds($at)+5)return false;
            foreach(['name'=>'name','country'=>'country','symbol'=>'symbol','venue'=>'exchange','currency'=>'currency']as $key=>$field)if(mon_get($m,$key)!==$symbol->$field)return false;
        }return true;
    }
    // Register identities once, independent of the bounded detail-request slots.
    public static function acquire($d,$context,$items,array $evidence): array {
        MonRun::validate($d);$c=MonRun::context($context);if(!is_array($items)||count($items)>1000)throw new MonFault('MON_DISCOVERY_INVALID');
        $at=mon_iso();$out=mon_decode(MonRun::json($d));$p=MonRun::payload($out);$pool=self::pool($out,$p);$proof=MonRun::evidence($out,$p);$originalIds=array_fill_keys(array_keys($proof),true);$submitted=[];$rows=[];$added=0;
        foreach($evidence as $e){$id=mon_get($e,'id');if(!is_string($id)||!preg_match('/^[A-Za-z0-9._:-]{1,180}$/',$id)||!($e instanceof stdClass))throw new MonFault('MON_EVIDENCE_INVALID');
            $e=clone $e;if(!is_string(mon_get($e,'claim'))||trim($e->claim)==='')$e->claim='공식 거래소 목록의 종목명·국가·코드·시장·통화를 확인';
            if(isset($submitted[$id])||isset($proof[$id])&&MonRun::json($proof[$id])!==MonRun::json($e))throw new MonFault('MON_EVIDENCE_ID_CONFLICT');$submitted[$id]=$e;$proof[$id]=$e;}
        $known=MonRun::rows($d->watchlist->symbols)+MonRun::rows($d->analysis->candidate_audit)+get_object_vars(MonDetails::requests($d));$knownCodes=[];
        foreach(array_keys($known)as $sid){$parts=explode('|',$sid);$knownCodes[$parts[0].'|'.$parts[2]]=true;}
        foreach($items as $item){$sid=mon_get(mon_get($item,'symbol'),'symbol_id');try{
            $s=MonDetails::symbol(mon_get($item,'symbol'));$sid=$s->symbol_id;if(!in_array($s->country,$c->countries,true))throw new MonFault('MON_SELECTION_SCOPE_INVALID');
            if(property_exists($pool,$sid)){$rows[]=['symbol_id'=>$sid,'status'=>'reuse','first_seen_at'=>$pool->$sid->first_seen_at,'quota_date'=>mon_get($pool->$sid,'discovery_quota_date')];continue;}
            if(isset($knownCodes[$s->country.'|'.$s->symbol]))throw new MonFault('MON_DISCOVERY_ALREADY_KNOWN');
            if(!self::identityVerified($s,$proof,$at))throw new MonFault('MON_EVIDENCE_MISSING');
            foreach($s->identity_evidence_ids as $id){$e=$proof[$id];$host=parse_url($e->source_url,PHP_URL_HOST);
                if(!preg_match('/(^|\.)(nasdaq\.com|nasdaqtrader\.com|krx\.co\.kr|jpx\.co\.jp)$/',(string)$host))throw new MonFault('MON_PRIMARY_LISTING_REQUIRED');
                if(!isset($originalIds[$id]))$out->analysis->evidence[]=$e;
            }
            $day=self::day($out->watchlist,$s->country,$at);$s->first_seen_at=$at;$s->candidate_group='new';
            $s->discovery_quota_date=$day['calendar_verified']?($day['trading_day']?$day['date']:$day['next_trading_date']):null;
            $pool->$sid=$s;$knownCodes[$s->country.'|'.$s->symbol]=true;$added++;
            $rows[]=['symbol_id'=>$sid,'status'=>'registered','first_seen_at'=>$at,'quota_date'=>$s->discovery_quota_date];
        }catch(MonFault $e){$rows[]=['symbol_id'=>$sid,'status'=>'rejected','error'=>$e->faultCode];}}
        if(!$added)return ['contract'=>'MON-DISCOVERY-3.0','registered_at'=>$at,'registered'=>0,'rows'=>$rows,'write_required'=>false,'files'=>[]];
        $p->discovery_candidates=$pool;MonRun::compactEvidence($out,$p);$out->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
        $out->analysis->discovery_state=self::counts($out,$p,['full_signals'=>new stdClass()],$at);self::coverage($out);
        MonRun::validate($out);MonRun::verifyFacts($out,$p);$storage=MonRun::storage($out,true);
        if(MonRun::json($d->collection)!==MonRun::json($out->collection)||MonRun::json($d->analysis->results)!==MonRun::json($out->analysis->results))throw new MonFault('MON_OWNERSHIP_INVALID');
        $raw=MonRun::json($out);$result=MonRun::render($out,$out->analysis->as_of,['공식 신규 신원 '.$added.'개 확보·다음 거래일 대기; 기존 시장 판단 보존']);
        return ['contract'=>'MON-DISCOVERY-3.0','registered_at'=>$at,'registered'=>$added,'rows'=>$rows,'write_required'=>true,
            'files'=>[['path'=>'mon_data.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)],['path'=>'mon_result.md','content'=>$result,'blob_sha'=>MonRun::gitSha($result)]],
            'discovery_state'=>$out->analysis->discovery_state,'storage'=>$storage,'collection_preserved'=>true,'analysis_as_of_preserved'=>true,'detail_slot_limit'=>MonDetails::MAX_REQUESTS];
    }
    // Discovery uses its real local day. A completed bar may still belong to yesterday.
    public static function day($w,string $country,string $at): array {
        $now=(int)MonRun::seconds(MonRun::utc($at));$date=(new DateTimeImmutable('@'.$now))->setTimezone(MonMarket::timezone($country))->format('Y-m-d');
        $verified=false;$trading=false;$next=null;$until=mon_time(mon_get(mon_get($w,'calendar'),'valid_until'));
        if($until!==null&&$until>=$now)foreach(mon_get($w->calendar,'markets',[])as $market){
            $checked=mon_time(mon_get($market,'checked_at'));
            if(mon_get($market,'country')!==$country||mon_get($market,'timezone')!==MonMarket::timezone($country)->getName()||$checked===null||$checked>$now||$until-$checked>15*86400)continue;
            $verified=true;foreach(mon_get($market,'sessions',[])as $session){
                $day=mon_get($session,'market_date');$open=mon_time(mon_get($session,'open_at'));$close=mon_time(mon_get($session,'close_at'));
                if(!is_string($day)||$open===null||$close===null||$open>=$close)continue;
                if($day===$date)$trading=true;if($day>$date&&($next===null||$day<$next))$next=$day;
            }
        }
        return ['date'=>$date,'calendar_verified'=>$verified,'trading_day'=>$trading,'next_trading_date'=>$next];
    }
    public static function coverage($d): void {
        $by=[];foreach(mon_get(mon_get($d->analysis,'discovery_state'),'countries',[])as $row)$by[$row->country]=$row;
        foreach(mon_get($d->analysis,'coverage',[])as $row){$count=$by[$row->country]??null;
            // Missing evidence is unknown/short, never an inherited "goal met".
            $found=(int)mon_get($count,'quota_identified_count',mon_get($count,'new_ids_identified_today',0));$goal=(int)mon_get($count,'daily_target',20);
            $row->new_search_count=$found;$row->search_target=$goal;$row->daily_goal_already_met=$count!==null&&mon_get($count,'daily_goal_met')===true;
            $row->discovery_market_date=mon_get($count,'discovery_date',mon_get($count,'market_date'));
            $row->discovery_completed_bar_date=mon_get($count,'completed_bar_date');$row->discovery_shortfall=(int)mon_get($count,'shortfall',max(0,$goal-$found));
            $row->discovery_goal_status=mon_get($count,'goal_status',$row->daily_goal_already_met?'met':'shortfall');
            $row->new_details_acquired_today=(int)mon_get($count,'new_details_acquired_today',0);$row->new_eligible_verified_today=(int)mon_get($count,'new_eligible_verified_today',0);
            $row->new_identities_found_today=(int)mon_get($count,'new_ids_identified_today',0);$row->next_session_queued_count=(int)mon_get($count,'next_session_queued_count',0);$row->next_trading_date=mon_get($count,'next_trading_date');
        }
    }
    public static function schedule(array $requests): array {
        $buckets=[];foreach($requests as $r){$origin=mon_get($r->symbol,'candidate_origin',[]);$group=mon_get($r->symbol,'candidate_group');$new=$group!==null?$group==='new':in_array('new_in_mon',$origin,true);
            $key=$r->symbol->country.'|'.($new?'new':'previous');$buckets[$key][]=$r;}
        foreach($buckets as &$bucket)usort($bucket,static function($a,$b){return strcmp($a->requested_at,$b->requested_at)?:strcmp($a->symbol_id,$b->symbol_id);});unset($bucket);
        $out=[];do{$more=false;foreach(['KR|new','KR|previous','US|new','US|previous','JP|new','JP|previous']as $key)if(!empty($buckets[$key])){$out[]=array_shift($buckets[$key]);$more=true;}}while($more);
        return $out;
    }
    public static function plan($d,$context,$items): array {
        if($items===null||$items===[]){$items=[];foreach(self::pool($d)as $sid=>$s){$r=mon_get(MonDetails::requests($d),$sid);if($r instanceof stdClass)continue;
            $items[]=(object)['symbol'=>$s,'request_id'=>'discovery-'.substr(hash('sha256',$sid.'|'.$s->first_seen_at),0,24),'wait_seconds'=>120,'force_refresh'=>false];}}
        if(!is_array($items)||count($items)>1000)throw new MonFault('MON_DISCOVERY_INVALID');
        $c=MonRun::context($context);$pool=[];$rejected=[];
        foreach($items as $item)try{$s=MonDetails::symbol(mon_get($item,'symbol'));if(!in_array($s->country,$c->countries,true))throw new MonFault('MON_SELECTION_SCOPE_INVALID');if(isset($pool[$s->symbol_id]))continue;
            $pool[$s->symbol_id]=(object)['symbol'=>$s,'requested_at'=>$c->rendered_at,'symbol_id'=>$s->symbol_id,'item'=>$item];
        }catch(MonFault $e){$rejected[]=['symbol_id'=>mon_get(mon_get($item,'symbol'),'symbol_id'),'error'=>$e->faultCode];}
        $ordered=self::schedule(array_values($pool));$targets=[];foreach($c->countries as $country)$targets[$country]=MonDetails::target($d->watchlist,$country,(int)MonRun::seconds($c->rendered_at));
        return ['items'=>array_map(static function($r){return $r->item;},array_slice($ordered,0,MonDetails::MAX_REQUESTS)),
            'remaining_items'=>array_map(static function($r){return $r->item;},array_slice($ordered,MonDetails::MAX_REQUESTS)),
            'target_completed_periods'=>$targets,'rejected'=>$rejected,'daily_discovery_target_per_country'=>20,
            'identity_status'=>'metadata_consistency_checked_primary_identity_evidence_still_required',
            'selection_bonus_for_newness'=>false,'auto_selected'=>false,'final_selector'=>'ChatGPT'];
    }
    public static function counts($d,$p,array $prepared,string $at): stdClass {
        $countries=[];$requests=MonDetails::requests($d);$watch=MonRun::rows($d->watchlist->symbols);$old=[];$proof=MonRun::evidence($d,$p);
        foreach(mon_get(mon_get($d->analysis,'discovery_state'),'countries',[])as $row)$old[$row->country]=$row;
        foreach(['KR','US','JP']as $country){
            $target=MonDetails::target($d->watchlist,$country,(int)MonRun::seconds($at));$discovery=self::day($d->watchlist,$country,$at);$day=$discovery['date'];$previous=$old[$country]??null;
            $identified=[];$acquired=[];$verified=[];$quota=[];$queued=[];
            if(mon_get($previous,'discovery_date',mon_get($previous,'market_date'))===$day)foreach(['identified_ids'=>'identified','acquired_ids'=>'acquired','verified_ids'=>'verified']as $field=>$unused){
                if($field!=='identified_ids'&&mon_get($previous,'completed_bar_date')!==$target['date'])continue;
                foreach(mon_get($previous,$field,[])as $sid)if(is_string($sid)&&strpos($sid,$country.'|')===0){if($field==='identified_ids')$identified[$sid]=true;elseif($field==='acquired_ids')$acquired[$sid]=true;else $verified[$sid]=true;}
            }
            $symbols=get_object_vars(self::pool($d,$p));foreach($requests as $sid=>$request)try{MonDetails::validRequest($request,$sid);if(!isset($symbols[$sid]))$symbols[$sid]=$request->symbol;}catch(MonFault $e){}
            foreach($symbols as $sid=>$symbol){
                if($symbol->country!==$country)continue;
                $firstSeen=mon_get($symbol,'first_seen_at');$new=mon_get($symbol,'candidate_group')==='new';
                if(!is_string($firstSeen))continue;
                try{$firstDay=(new DateTimeImmutable(MonRun::utc($firstSeen)))->setTimezone(MonMarket::timezone($country))->format('Y-m-d');}catch(Throwable $e){continue;}
                if(MonRun::seconds($firstSeen)>MonRun::seconds($at)+5)continue;
                if(!$new||!self::identityVerified($symbol,$proof,$at))continue;
                if($firstDay===$day)$identified[$sid]=true;
                $credit=mon_get($symbol,'discovery_quota_date',$firstDay);if($credit===$day)$quota[$sid]=true;
                if($discovery['next_trading_date']!==null&&$credit===$discovery['next_trading_date'])$queued[$sid]=true;
                if($firstDay!==$day)continue;$request=mon_get($requests,$sid);
                $response=mon_get(mon_get($d->collection,'detail_responses'),$sid);
                if(MonDetails::matching($request,$response)&&mon_get($response,'usable')===true&&mon_get($response,'conflict')!==true)try{
                    $snapshot=MonDetails::checkedSnapshot($request,$response);if($snapshot->metrics->bars_as_of===$target['date'])$acquired[$sid]=true;
                }catch(MonFault $e){}
                if(mon_get(mon_get($prepared['full_signals'],$sid),'eligible')===true)$verified[$sid]=true;
            }
            $sets=[];foreach([$identified,$acquired,$verified]as $set){$ids=array_keys($set);sort($ids,SORT_STRING);$sets[]=$ids;}
            $goal=count($quota)>=20;
            $countries[]=(object)['country'=>$country,'market_date'=>$day,'discovery_date'=>$day,'completed_bar_date'=>$target['date'],'target_verified'=>$target['verified'],
                'daily_target'=>20,'identified_ids'=>$sets[0],'acquired_ids'=>$sets[1],'verified_ids'=>$sets[2],
                'new_ids_identified_today'=>count($sets[0]),'new_details_acquired_today'=>count($sets[1]),'new_eligible_verified_today'=>count($sets[2]),
                'quota_identified_count'=>count($quota),'quota_identified_ids'=>array_keys($quota),'next_session_queued_count'=>count($queued),'next_session_queued_ids'=>array_keys($queued),
                'daily_goal_met'=>$goal&&$discovery['calendar_verified']&&$discovery['trading_day'],'shortfall'=>$discovery['calendar_verified']&&!$discovery['trading_day']?0:max(0,20-count($quota)),
                'goal_status'=>!$discovery['calendar_verified']?'calendar_unverified':(!$discovery['trading_day']?(count($queued)>=20?'not_due_prepared':'not_due'):($goal?'met':'shortfall')),
                'next_trading_date'=>$discovery['next_trading_date'],'count_basis'=>'server_first_seen_unique_primary_identity; off_day_credit_next_verified_session_once'];
        }
        $result=mon_get($d->analysis,'discovery_state',new stdClass());$result=mon_decode(MonRun::json($result));$history=mon_get($result,'previous_days',[]);
        foreach($old as $previous){$country=$previous->country;$date=mon_get($previous,'discovery_date',mon_get($previous,'market_date'));$current=self::day($d->watchlist,$country,$at);
            if($date===$current['date'])continue;$entry=mon_decode(MonRun::json($previous));$entry->discovery_date=$date;
            $offDay=strpos((string)mon_get($entry,'goal_status'),'not_due')===0;$entry->shortfall=$offDay?0:max(0,(int)mon_get($entry,'daily_target',20)-(int)mon_get($entry,'quota_identified_count',mon_get($entry,'new_ids_identified_today',0)));if(!$offDay)$entry->goal_status=mon_get($entry,'daily_goal_met')===true?'met':'shortfall';
            $key=$country.'|'.$date;$exists=false;foreach($history as $row)if($row->country.'|'.mon_get($row,'discovery_date',$row->market_date)===$key)$exists=true;if(!$exists)$history[]=$entry;
        }
        $result->contract='MON-DISCOVERY-3.0';$result->previous_days=array_slice($history,-60);$result->countries=$countries;return $result;
    }
}

final class MonGaps {
    const CONTRACT='MON-GAP-1.0';
    public static $sourceBundle=null;
    private static $archiveBundle=null;
    private static $recordCache=[];
    private static function file($manifest,string $path,bool $network=true): string {
        $sha=(string)mon_get($manifest,'sha256');$blob=(string)mon_get($manifest,'blob_sha');
        if(!self::dependencyPath($path)||mon_get($manifest,'path')!==$path||!preg_match('/^[a-f0-9]{64}$/',$sha)||!preg_match('/^[a-f0-9]{40}$/',$blob))throw new MonFault('MON_GAP_SOURCE_MANIFEST_INVALID');
        $raw=$path==='mon_recovery_inputs.json'?self::$sourceBundle:self::$archiveBundle;if(is_string($raw)&&!hash_equals($sha,hash('sha256',$raw)))$raw=null;
        $store=self::store();$name=($path==='mon_recovery_inputs.json'?'recovery-inputs-':'recovery-archive-').$sha.'.php';
        if(!is_string($raw)){
            $local=__DIR__.'/'.$path;if(is_file($local)&&filesize($local)<=MonRun::MAX_SOURCE){$candidate=file_get_contents($local);if(is_string($candidate)&&hash_equals($sha,hash('sha256',$candidate)))$raw=$candidate;}
        }
        if(!is_string($raw)){try{$cached=$store->readPrivate($name);$candidate=mon_get($cached,'raw_json');if(is_string($candidate)&&hash_equals($sha,hash('sha256',$candidate)))$raw=$candidate;}catch(MonFault $e){/* Corrupt cache cannot bypass the immutable source. */}}
        if(!is_string($raw)){
            if(!$network)throw new MonFault('MON_GAP_SOURCE_UNAVAILABLE');
            $cfg=new MonConfig(getenv('MON_ENV_FILE')?:'',$store->dir);$repo=$cfg->get('MON_REPOSITORY','wskimgit/stock');if(!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~',$repo))throw new MonFault('MON_GAP_SOURCE_MANIFEST_INVALID');
            $headers=['Accept: application/vnd.github+json'];if($cfg->get('GITHUB_TOKEN')!=='')$headers[]='Authorization: Bearer '.$cfg->get('GITHUB_TOKEN');
            $r=MonHttp::decode((new MonHttp())->request('GET','https://api.github.com/repos/'.$repo.'/git/blobs/'.$blob,$headers,null,microtime(true)+4,3));
            $raw=mon_get($r,'encoding')==='base64'?base64_decode((string)mon_get($r,'content'),true):null;
            if(!is_string($raw)||strlen($raw)>MonRun::MAX_SOURCE||!hash_equals($sha,hash('sha256',$raw))||!hash_equals($blob,MonRun::gitSha($raw)))throw new MonFault('MON_GAP_SOURCE_HASH_INVALID');
            $store->writePrivate($name,(object)['raw_json'=>$raw,'sha256'=>$sha]);
        }
        if(strlen($raw)>MonRun::MAX_SOURCE||!hash_equals($blob,MonRun::gitSha($raw)))throw new MonFault('MON_GAP_SOURCE_HASH_INVALID');
        if($path==='mon_recovery_inputs.json')self::$sourceBundle=$raw;else self::$archiveBundle=$raw;return $raw;
    }
    private static function sources($p,bool $network=true): stdClass {
        $manifest=mon_get($p,'recovery_source_manifest');if(!($manifest instanceof stdClass))return mon_get($p,'recovery_sources',new stdClass());
        $raw=self::file($manifest,'mon_recovery_inputs.json',$network);
        try{$bundle=mon_decode($raw);}catch(Throwable $e){throw new MonFault('MON_GAP_SOURCE_INVALID');}
        if(mon_get($bundle,'contract')!=='MON-GAP-SOURCES-1.0'||mon_get($bundle,'criteria_version')!==MonSwing::CRITERIA||!(mon_get($bundle,'sources')instanceof stdClass)||count(get_object_vars($bundle->sources))!==(int)mon_get($manifest,'source_count'))throw new MonFault('MON_GAP_SOURCE_INVALID');
        self::$sourceBundle=$raw;return $bundle->sources;
    }
    public static function dependencyRaw($manifest,bool $network=true): string {return self::file($manifest,(string)mon_get($manifest,'path'),$network);}
    public static function dependencyPath(string $path): bool {
        return in_array($path,['mon_recovery_inputs.json','mon_recovery_archive.json'],true)||
            preg_match('/^mon_recovery_archive_[a-f0-9]{64}\.json$/',$path)===1;
    }
    private static function store(): MonStore {return MonExecution::$store??MonPreparation::$store??new MonStore(getenv('MON_STATE_DIR')?:__DIR__);}
    public static function archiveFile($manifest,bool $network=true): stdClass {
        $path=(string)mon_get($manifest,'path');$raw=self::file($manifest,$path,$network);$bundle=mon_decode($raw);
        if(!in_array(mon_get($bundle,'contract'),['MON-GAP-ARCHIVE-1.0','MON-GAP-ARCHIVE-2.0'],true)||!(mon_get($bundle,'strategy_archive')instanceof stdClass))throw new MonFault('MON_GAP_ARCHIVE_INVALID');
        if($bundle->contract==='MON-GAP-ARCHIVE-2.0'&&(!is_int(mon_get($bundle,'generation'))||$bundle->generation<1||
            mon_get($manifest,'generation')!==$bundle->generation||!($bundle->previous_manifest===null||$bundle->previous_manifest instanceof stdClass)))throw new MonFault('MON_GAP_ARCHIVE_INVALID');
        if($bundle->contract==='MON-GAP-ARCHIVE-2.0'&&$bundle->previous_manifest instanceof stdClass&&
            (int)mon_get($bundle->previous_manifest,'generation',0)!==$bundle->generation-1)throw new MonFault('MON_GAP_ARCHIVE_CYCLE');
        return $bundle;
    }
    private static function alreadyArchived($manifest,string $key,string $sha): bool {
        try{while($manifest instanceof stdClass){$bundle=self::archiveFile($manifest,false);$value=mon_get($bundle->strategy_archive,$key);
            if($value!==null&&hash_equals($sha,hash('sha256',MonRun::json($value))))return true;$manifest=mon_get($bundle,'previous_manifest');}}
        catch(MonFault $e){return false;}return false;
    }
    public static function compact($d,string $at,int $reserveFloor=655360): array {
        MonRun::utc($at);MonRun::validate($d);$out=clone $d;$out->analysis=clone $d->analysis;$out->analysis->input_snapshot=clone $d->analysis->input_snapshot;
        $out->watchlist=clone $d->watchlist;$out->watchlist->settings=clone $d->watchlist->settings;
        $policy=clone mon_get($d->watchlist->settings,'analysis_storage_policy',new stdClass());
        $policy->collection_reserve_min_bytes=max($reserveFloor,(int)mon_get($policy,'collection_reserve_min_bytes',65536));$out->watchlist->settings->analysis_storage_policy=$policy;
        $p=MonRun::payload($d);MonRun::verifyFacts($d,$p);$archive=mon_get($p,'strategy_archive',new stdClass());$fresh=new stdClass();$kept=new stdClass();$recent=mon_get($p,'archive_recent_hashes',new stdClass());
        foreach($archive as $key=>$value){
            if(is_string(mon_get($value,'archive_ref')))$kept->$key=$value;
            else{ $digest=hash('sha256',MonRun::json($value));$index=hash('sha256',$key);if(mon_get($recent,$index)!==$digest||!self::alreadyArchived(mon_get($p,'recovery_archive_head'),(string)$key,$digest))$fresh->$key=$value;$recent->$index=$digest;}
        }
        $previous=mon_get($p,'recovery_archive_head',mon_get($p,'recovery_archive_manifest'));$files=[];$chunk=new stdClass();$bytes=0;
        $flush=static function()use(&$chunk,&$bytes,&$previous,&$files,$at){
            if(!count(get_object_vars($chunk)))return;
            $generation=(int)mon_get($previous,'generation',0)+1;
            $raw=MonRun::json((object)['contract'=>'MON-GAP-ARCHIVE-2.0','created_at'=>$at,'generation'=>$generation,'previous_manifest'=>$previous,'strategy_archive'=>$chunk]);
            if(strlen($raw)>MonRun::MAX_SOURCE)throw new MonFault('MON_GAP_ARCHIVE_TOO_LARGE');
            $sha=hash('sha256',$raw);$path='mon_recovery_archive_'.$sha.'.json';$blob=MonRun::gitSha($raw);
            self::store()->writePrivate('recovery-archive-'.$sha.'.php',(object)['raw_json'=>$raw,'sha256'=>$sha]);
            $files[]=['path'=>$path,'content'=>$raw,'blob_sha'=>$blob];$previous=(object)['path'=>$path,'sha256'=>$sha,'blob_sha'=>$blob,'generation'=>$generation];
            $chunk=new stdClass();$bytes=0;
        };
        foreach($fresh as $key=>$value){$rowBytes=strlen(MonRun::json((object)[$key=>$value]));
            if($rowBytes>MonRun::MAX_SOURCE-2048)throw new MonFault('MON_GAP_ARCHIVE_ENTRY_TOO_LARGE');
            if($bytes+$rowBytes>MonRun::MAX_SOURCE-2048)$flush();$chunk->$key=$value;$bytes+=$rowBytes;
        }$flush();
        if($files||MonRun::json($archive)!==MonRun::json($kept)){$p->archive_recent_hashes=(object)array_slice(get_object_vars($recent),-64,null,true);$p->strategy_archive=$kept;$p->recovery_archive_head=$previous;
            $out->analysis->input_snapshot->compressed_fields=array_keys(get_object_vars($p));sort($out->analysis->input_snapshot->compressed_fields,SORT_STRING);
            $out->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');}
        MonRun::validate($out);MonRun::verifyFacts($out,$p);$storage=MonRun::storage($out,true);
        if(MonRun::json($out->collection)!==MonRun::json($d->collection))throw new MonFault('MON_OWNERSHIP_INVALID');
        $raw=MonRun::json($out);$summary=MonRun::render($out,$out->analysis->as_of,['이력 공간 분리; 기존 판단·자료시각 보존']);
        return ['data'=>$out,'write_required'=>MonRun::json($out)!==MonRun::json($d),
            'files'=>array_merge([['path'=>'mon_data.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)],['path'=>'mon_result.md','content'=>$summary,'blob_sha'=>MonRun::gitSha($summary)]],$files),
            'dependency_files'=>$files,'storage'=>$storage,'archive_entries_moved'=>count(get_object_vars($fresh)),'analysis_as_of_preserved'=>$out->analysis->as_of===$d->analysis->as_of];
    }
    public static function archivePage($d,?string $cursor=null): array {
        $p=MonRun::payload($d);$head=mon_get($p,'recovery_archive_head',mon_get($p,'recovery_archive_manifest'));
        if(!($head instanceof stdClass))return ['entries'=>[],'next_cursor'=>null,'complete'=>true];
        $binding=(string)$head->sha256;$state=(object)['head_sha256'=>$binding,'manifest'=>$head,'index'=>0];
        if($cursor!==null){MonExecution::id($cursor);$state=self::store()->readPrivate('archive-page-'.$cursor.'.php');
            if(!($state instanceof stdClass)||$state->head_sha256!==$binding||!hash_equals($cursor,hash('sha256',MonRun::json($state))))throw new MonFault('MON_ARCHIVE_CURSOR_INVALID');}
        $bundle=self::archiveFile($state->manifest);$keys=array_keys(get_object_vars($bundle->strategy_archive));$rows=[];
        for($i=$state->index;$i<count($keys)&&count($rows)<10;$i++){$key=$keys[$i];$rows[]=(object)['key'=>$key,'sha256'=>hash('sha256',MonRun::json($bundle->strategy_archive->$key)),
            'manifest'=>$state->manifest,'content_ref'=>'mon_archive_lookup'];}
        $next=null;
        if($i<count($keys))$next=(object)['head_sha256'=>$binding,'manifest'=>$state->manifest,'index'=>$i];
        elseif(mon_get($bundle,'previous_manifest')instanceof stdClass)$next=(object)['head_sha256'=>$binding,'manifest'=>$bundle->previous_manifest,'index'=>0];
        $token=null;if($next){$token=hash('sha256',MonRun::json($next));self::store()->writePrivate('archive-page-'.$token.'.php',$next);}
        return ['entries'=>$rows,'next_cursor'=>$token,'complete'=>$next===null,'head_sha256'=>$binding];
    }
    public static function archiveLookup($d,string $key,?string $cursor=null): array {
        if($key===''||strlen($key)>256)throw new MonFault('MON_ARCHIVE_KEY_INVALID');$page=self::archivePage($d,$cursor);
        foreach($page['entries']as $entry)if($entry->key===$key){$bundle=self::archiveFile($entry->manifest);$value=$bundle->strategy_archive->$key;
            $sha=hash('sha256',MonRun::json($value));$card=MonArtifact::card(self::store(),$sha,$value);unset($card['content']);return ['found'=>true,'artifact'=>$card,'next_cursor'=>null];}
        return ['found'=>false,'search_complete'=>$page['complete'],'next_cursor'=>$page['next_cursor']];
    }
    public static function archived($d): stdClass {
        $p=MonRun::payload($d);$out=clone mon_get($p,'strategy_archive',new stdClass());$manifest=mon_get($p,'recovery_archive_manifest');
        if($manifest instanceof stdClass){$bundle=self::archiveFile($manifest);
            foreach($out as $key=>$row)if(mon_get($row,'archive_ref')==='MON-GAP-ARCHIVE:'.$key){$original=mon_get($bundle->strategy_archive,$key);
                if(!($original instanceof stdClass)||!hash_equals((string)mon_get($row,'sha256'),MonRun::digest($original)))throw new MonFault('MON_GAP_ARCHIVE_INVALID');$out->$key=$original;}}
        if(mon_get($p,'recovery_archive_head')instanceof stdClass){$cursor=null;do{$page=self::archivePage($d,$cursor);foreach($page['entries']as $entry){
            $bundle=self::archiveFile($entry->manifest);$out->{$entry->key}=$bundle->strategy_archive->{$entry->key};
            if(strlen(MonRun::json($out))>MonRun::MAX_SOURCE)throw new MonFault('MON_ARCHIVE_PAGING_REQUIRED');}$cursor=$page['next_cursor'];}while($cursor!==null);}
        return $out;
    }
    public static function rawRecords($ref) {
        $key=(string)mon_get($ref,'snapshot_sha256');if(array_key_exists($key,self::$recordCache))return self::$recordCache[$key];$records=null;
        try{$source=self::sources((object)['recovery_source_manifest'=>mon_get($ref,'source_manifest')],false);$packed=mon_get($source,(string)mon_get($ref,'symbol_id'));
            if(!($packed instanceof stdClass)||!hash_equals($key,(string)mon_get($packed,'sha256')))throw new MonFault('MON_GAP_SOURCE_HASH_INVALID');$snapshot=MonDetails::unpack($packed);$records=mon_get($snapshot->origin,'raw_records');
        }catch(MonFault $e){/* An unavailable raw record stays unproved; never adds HTTP work to a daemon slice. */}
        self::$recordCache=[$key=>$records];return $records;
    }
    private static function checked($snapshot): stdClass {
        if(!($snapshot instanceof stdClass)||mon_get($snapshot,'criteria_version')!==MonSwing::CRITERIA||mon_get($snapshot->origin,'price_basis')!==MonDetails::BASIS||mon_get($snapshot->origin,'source')!=='SPG_STOCKANALYSIS')throw new MonFault('MON_GAP_SOURCE_INVALID');
        $s=MonDetails::snapshot($snapshot->symbol,$snapshot->bars,$snapshot->benchmark_bars,$snapshot->benchmark_id,$snapshot->origin,$snapshot->benchmark_origin);
        foreach(['metrics','technical','entry_plans','history_metadata']as $key)if(MonRun::json($s->$key)!==MonRun::json($snapshot->$key))throw new MonFault('MON_GAP_SOURCE_INVALID');
        if(!preg_match('/^[a-f0-9]{64}$/',(string)mon_get($s->origin,'source_record_sha256')))throw new MonFault('MON_GAP_SOURCE_INVALID');return $s;
    }
    // Maintenance stages verified inputs; old decisions and collection remain immutable.
    public static function stage($d,array $sources,string $at): array {
        MonRun::utc($at);MonRun::validate($d);$out=mon_decode(MonRun::json($d));$p=MonRun::payload($out);MonRun::verifyFacts($out,$p);$audit=MonRun::rows($out->analysis->candidate_audit);$saved=[];$countries=[];
        if(!(mon_get($p,'recovery_sources')instanceof stdClass))$p->recovery_sources=new stdClass();$rejected=[];$quarantined=new stdClass();
        foreach($sources as $source){$sid=mon_get($source,'symbol_id');try{$value=mon_get($source,'snapshot');if(mon_get($value,'encoding')==='gzip+base64')$value=MonDetails::unpack($value);$s=self::checked($value);$sid=$s->symbol_id;$country=$s->symbol->country;
            if(!isset($audit[$sid])||mon_time($s->history_metadata->checked_at)>MonRun::seconds($at)+5)throw new MonFault('MON_GAP_SOURCE_INVALID');
            $target=MonDetails::target($d->watchlist,$country,(int)MonRun::seconds($at));if(!$target['verified']||$s->metrics->bars_as_of!==$target['date']||$s->benchmark_id!==MonDetails::benchmark($s->symbol)['id'])throw new MonFault('MON_GAP_PERIOD_INVALID');
            $cached=MonDetails::cached($d,$s->symbol,$p);if($cached instanceof stdClass){$comparison=MonBarConflict::compare($cached,(object)['bars'=>$s->bars,'origin'=>$s->origin],$s->symbol);
                if($comparison['conflict']){
                    $independent=mon_get($source,'corroborating_source');if(mon_get($independent,'encoding')==='gzip+base64')$independent=MonDetails::unpack($independent);
                    try{$proof=MonDaily::priorWindowProof($cached,(object)['bars'=>$s->bars,'origin'=>$s->origin],$s->symbol->currency);}
                    catch(MonFault $e){if(!($independent instanceof stdClass))throw $e;$proof=MonDaily::wholeWindowProof((object)['bars'=>$s->bars,'origin'=>$s->origin],$independent,$s->symbol->currency);}
                    $proof->previous_bars_sha256=MonRun::digest($cached->bars);$proof->previous_conflict_count=count($comparison['diagnostics']);$s->origin->history_resolution=$proof;
                    $s=MonDetails::snapshot($s->symbol,$s->bars,$s->benchmark_bars,$s->benchmark_id,$s->origin,$s->benchmark_origin);
                }}
            $p->recovery_sources->$sid=MonDetails::pack($s);$saved[]=(object)['symbol_id'=>$sid,'bars_as_of'=>$s->metrics->bars_as_of,'bar_count'=>count($s->bars),'checked_at'=>$s->history_metadata->checked_at,'source_url'=>$s->origin->source_url,'sha256'=>$p->recovery_sources->$sid->sha256];$countries[$country]=true;
            }catch(MonFault $e){$rejected[]=(object)['symbol_id'=>$sid,'error'=>$e->faultCode,'state'=>'needs_additional_source_proof','resolved'=>false];
                if(is_string($sid)&&mon_get($source,'snapshot')instanceof stdClass)$quarantined->$sid=MonRun::pick($source,['snapshot','corroborating_source']);}
        }
        $archiveFile=null;$archiveFiles=[];
        $bundleRaw=MonRun::json((object)['contract'=>'MON-GAP-SOURCES-1.0','criteria_version'=>MonSwing::CRITERIA,'created_at'=>$at,'sources'=>$p->recovery_sources,'audit'=>$saved,'rejected'=>$rejected,'quarantined_sources'=>$quarantined]);
        if(strlen($bundleRaw)>MonRun::MAX_SOURCE)throw new MonFault('MON_GAP_SOURCE_BUNDLE_TOO_LARGE');
        $p->recovery_source_manifest=(object)['path'=>'mon_recovery_inputs.json','blob_sha'=>MonRun::gitSha($bundleRaw),'sha256'=>hash('sha256',$bundleRaw),'source_count'=>count(get_object_vars($p->recovery_sources))];unset($p->recovery_sources);
        self::$sourceBundle=$bundleRaw;
        MonDiscovery::coverage($out);$out->analysis->pending_analysis=true;$out->analysis->pending_analysis_countries=array_keys($countries);
        $by=[];foreach($saved as $row){$country=explode('|',$row->symbol_id)[0];$by[$country]=($by[$country]??0)+1;}
        $out->analysis->source_recovery=(object)['contract'=>self::CONTRACT,'checked_at'=>$at,'staged_count'=>count($saved),'source_count_by_country'=>$by,
            'source_bundle'=>$p->recovery_source_manifest,'sources_preview'=>array_map(static function($r){return MonRun::pick($r,['symbol_id','bars_as_of','bar_count']);},array_slice($saved,0,20)),
            'rejected'=>$rejected,'state'=>'verified_inputs_pending_autonomous_selection','analysis_as_of_preserved'=>true];
        $out->analysis->input_snapshot->compressed_fields=array_keys(get_object_vars($p));sort($out->analysis->input_snapshot->compressed_fields,SORT_STRING);$out->analysis->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
        MonRun::validate($out);MonRun::verifyFacts($out,$p);if(!MonRun::storage($out,false)->fits||strlen(MonRun::json(mon_get($p,'strategy_archive',new stdClass())))>100000){$compacted=self::compact($out,$at);$out=$compacted['data'];$archiveFiles=$compacted['dependency_files'];}$storage=MonRun::storage($out,true);if(MonRun::json($d->collection)!==MonRun::json($out->collection))throw new MonFault('MON_OWNERSHIP_INVALID');
        $raw=MonRun::json($out);$summary=MonRun::render($out,$out->analysis->as_of,['신규 목표 표시 정정; 대체 원천의 검증 입력 '.count($saved).'종목 확보, 기존 판단과 별도 저장']);
        $files=[['path'=>'mon_data.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)],['path'=>'mon_result.md','content'=>$summary,'blob_sha'=>MonRun::gitSha($summary)],['path'=>'mon_recovery_inputs.json','content'=>$bundleRaw,'blob_sha'=>MonRun::gitSha($bundleRaw)]];$files=array_merge($files,$archiveFiles);
        return ['data'=>$out,'files'=>$files,
            'validation'=>['collection_preserved'=>true,'analysis_as_of_preserved'=>$d->analysis->as_of===$out->analysis->as_of,'result_fingerprint_preserved'=>$d->analysis->result_fingerprint===$out->analysis->result_fingerprint,'staged_count'=>count($saved),'rejected'=>$rejected,'source_bundle_bytes'=>strlen($bundleRaw),'archive_preserved_externally'=>count($archiveFiles)>0,'storage'=>$storage]];
    }
    public static function inputs($out,$p,string $at): bool {
        $changed=false;$watch=MonRun::rows($out->watchlist->symbols);
        foreach(self::sources($p)as $sid=>$packed){
            $s=self::checked(MonDetails::unpack($packed));if($s->symbol_id!==$sid||mon_time($s->history_metadata->checked_at)>MonRun::seconds($at)+5)throw new MonFault('MON_GAP_SOURCE_INVALID');
            $target=MonDetails::target($out->watchlist,$s->symbol->country,(int)MonRun::seconds($at));if(!$target['verified']||$target['date']!==$s->metrics->bars_as_of)continue;
            $response=mon_get(mon_get($out->collection,'detail_responses'),$sid);$request=mon_get(MonDetails::requests($out),$sid);
            if(MonDetails::matching($request,$response)&&mon_time(mon_get($response,'completed_at'))>=mon_time($s->history_metadata->checked_at))continue;
            $history=clone $s->history_metadata;
            // Indicators need prices, not a second in-memory copy of every raw record.
            if(property_exists($history,'raw_records')){
                unset($history->raw_records);$history->raw_records_source_ref=(object)['source_manifest'=>mon_get($p,'recovery_source_manifest'),'symbol_id'=>$sid,'snapshot_sha256'=>$packed->sha256];
            }
            $p->series->$sid=$s->bars;$p->candidate_metrics->$sid=$s->metrics;$p->history_metadata->$sid=$history;$p->series->{$s->benchmark_id}=$s->benchmark_bars;
            if(!(mon_get($p,'detail_plans')instanceof stdClass))$p->detail_plans=new stdClass();$p->detail_plans->$sid=$s->entry_plans;
            if(!(mon_get($p,'detail_imports')instanceof stdClass))$p->detail_imports=new stdClass();$p->detail_imports->$sid=(object)['data_status'=>'independent_recovered_data','conflict'=>false,'source_checked_at'=>$s->history_metadata->checked_at,'history_resolution'=>mon_get($s->origin,'history_resolution')];
            if(!isset($watch[$sid])){$out->watchlist->symbols[]=$s->symbol;$watch[$sid]=$s->symbol;}
            foreach($out->analysis->market_summary as $market)if($market->country===$s->symbol->country){$market->completed_bar_date=$target['date'];$market->target_completed_bar_date=$target['date'];$market->comparison_status='independent_source_verified';}
            $changed=true;
        }
        if($changed){$out->analysis->pending_analysis=false;$out->analysis->pending_analysis_countries=[];}
        return $changed;
    }
    public static function plan($d,string $at): array {
        MonRun::utc($at);MonRun::validate($d);$p=MonRun::payload($d);MonRun::verifyFacts($d,$p);$jobs=[];$targets=[];
        foreach(['KR','US','JP']as $country)$targets[$country]=MonDetails::target($d->watchlist,$country,(int)MonRun::seconds($at));
        foreach(mon_get(mon_get($d->analysis,'discovery_state'),'countries',[])as $row){$remaining=(int)mon_get($row,'shortfall',max(0,(int)mon_get($row,'daily_target',20)-(int)mon_get($row,'new_ids_identified_today',0)));
            if($remaining)$jobs[]=(object)['country'=>$row->country,'kind'=>'identity_discovery','state'=>'needs_primary_identity','remaining'=>$remaining,
                'discovery_date'=>mon_get($row,'discovery_date',mon_get($row,'market_date')),'completed_bar_date'=>$row->completed_bar_date,
                'alternatives'=>['official_exchange_listing','issuer_identity','server_first_seen_registration'],'resolved'=>false,'backdate_allowed'=>false];
        }
        $sources=self::sources($p);
        foreach($d->analysis->candidate_audit as $row){$sid=$row->symbol_id;$country=explode('|',$sid)[0];$bars=mon_get(mon_get($p,'series'),$sid,[]);$metric=mon_get(mon_get($p,'candidate_metrics'),$sid);
            $error=mon_get(mon_get(mon_get($p,'detail_imports'),$sid),'error');$target=$targets[$country]['date'];$kind=null;
            $recovered=mon_get($sources,$sid);if($recovered instanceof stdClass){$s=self::checked(MonDetails::unpack($recovered));if($s->metrics->bars_as_of===$target){
                if($country==='JP'&&!MonDetails::tickRuleUsable($s->symbol,MonDiscovery::day($d->watchlist,'JP',$at)['date']))$jobs[]=(object)['symbol_id'=>$sid,'country'=>$country,'kind'=>'tick_rule','state'=>'needs_primary_tick_rule','resolved'=>false,
                    'alternatives'=>['official_JPX_constituent_and_tick_table'],'numeric_history_verified'=>true,'entry_plan_fabrication_allowed'=>false];continue;
            }}
            if($error==='DETAIL_PRICE_BASIS_CONFLICT')$kind='source_basis_conflict';
            elseif(!is_array($bars)||count($bars)<21)$kind='history_shortfall';
            elseif(mon_get($metric,'bars_as_of')!==$target||mon_get($metric,'comparison_end')!==$target)$kind='completed_period_missing';
            if($kind)$jobs[]=(object)['symbol_id'=>$sid,'country'=>$country,'kind'=>$kind,'state'=>'needs_source','resolved'=>false,'target_completed_date'=>$target,
                'available_bars'=>is_array($bars)?count($bars):0,'minimum_same_session_closes'=>21,'macd_requires_full_provider_window'=>true,
                'alternatives'=>$country==='US'?['SPG_STOCKANALYSIS','NASDAQ_RAW_CORROBORATION','FRED_SP500']:($country==='JP'?['SPG_STOCKANALYSIS','saved_same_session_TOPIX','authorized_JPX_history']:['KIS_split_fields','independent_raw_history','explicit_conflict_diagnostics'])];
        }
        foreach($d->analysis->results as $row){$country=$row->country;$session=MonMarket::state($d->watchlist,$country,(int)MonRun::seconds($at));$day=MonDiscovery::day($d->watchlist,$country,$at);
            $jobs[]=(object)['symbol_id'=>$row->symbol_id,'country'=>$country,'kind'=>'current_entry_checks','state'=>$session['session']==='regular'?'needs_live_confirmation':'waiting_regular_session',
                'resolved'=>false,'next_trading_date'=>$day['next_trading_date'],'required'=>['live_price_delay_and_entry_zone','trading_status_within_300_seconds','current_issuer_filings_events_and_pending_items'],
                'closed_price_substitutes_live'=>false,'risk_scope'=>'latest_filings_material_events_known_pending_items_not_all_possible_risk'];
            if(mon_get(mon_get($row,'risk_checks'),'current_corporate_risk_verified')!==true)$jobs[]=(object)['symbol_id'=>$row->symbol_id,'country'=>$country,'kind'=>'issuer_risk_review','state'=>'needs_current_primary_review','resolved'=>false,
                'required'=>['latest_financial_filing','material_events_since_filing','known_pending_items'],'alternatives'=>['issuer_IR','official_exchange_disclosures','regulator_filings'],
                'depends_on_regular_session'=>false,'unbounded_all_risk_claim_allowed'=>false];
        }
        $counts=[];foreach($jobs as $job)$counts[$job->kind]=($counts[$job->kind]??0)+1;
        return MonPaging::pages(['contract'=>self::CONTRACT,'checked_at'=>$at,'analysis_as_of'=>$d->analysis->as_of,'counts'=>$counts,
            'stop_on_time_or_count'=>false,'auto_selected'=>false,'new_analysis_complete'=>false,'write_required'=>false],$jobs);
    }
}

final class MonBarConflict {
    private static function record($origin,string $date,string $sid): ?stdClass {
        $records=mon_get($origin,'raw_records');if(!($records instanceof stdClass)&&mon_get($origin,'raw_records_source_ref')instanceof stdClass)$records=MonGaps::rawRecords($origin->raw_records_source_ref);
        $record=mon_get($records,$date);$raw=mon_get($record,'raw_json');
        if(!is_string($raw)||!hash_equals((string)mon_get($record,'record_sha256'),hash('sha256',$raw)))return null;
        try{$r=mon_decode($raw);}catch(Throwable $e){return null;}
        if(mon_get($r,'symbol_id')!==$sid||mon_get($r,'date')!==$date||mon_get($r,'price_basis')!==MonDetails::BASIS||
            !is_array(mon_get($r,'values'))||count($r->values)!==5)return null;
        return $r;
    }
    private static function derives(array $row,$origin,$record): bool {
        foreach([1=>'open',2=>'high',3=>'low',4=>'close',5=>'volume']as $i=>$field){
            $raw=mon_number($record->values[$i-1]);$actual=mon_number($row[$i]);if($raw===null||$actual===null)return false;
            $precision=mon_get(mon_get($origin,'display_precision'),$field);
            if($precision===null){if($actual!==$raw)return false;}
            elseif(!is_int($precision)||$precision<0||$precision>8||$actual!==round($raw,$precision,PHP_ROUND_HALF_UP))return false;
        }return true;
    }
    public static function compare($cached,$fresh,$symbol): array {
        $old=[];foreach(MonSwing::bars($cached->bars)as $bar)$old[$bar[0]]=$bar;
        $diagnostics=[];$conflict=false;$new=MonSwing::bars($fresh->bars);
        $oldBasis=mon_get($cached->history_metadata,'price_basis');$newBasis=mon_get($fresh->origin,'price_basis');
        if($oldBasis!==MonDetails::BASIS||$newBasis!==MonDetails::BASIS){$conflict=true;$diagnostics[]=(object)['symbol_id'=>$symbol->symbol_id,'field'=>'price_basis','old_value'=>$oldBasis,'new_value'=>$newBasis,'classification'=>'source_conflict'];}
        foreach($new as $row){$date=$row[0];if(isset($old[$date])){
            $fields=[];foreach([1=>'open',2=>'high',3=>'low',4=>'close',5=>'volume']as $i=>$field)if((float)$old[$date][$i] !== (float)$row[$i])$fields[$field]=[$old[$date][$i],$row[$i]];
            if($fields){
                $a=self::record($cached->origin,$date,$symbol->symbol_id);$b=self::record($fresh->origin,$date,$symbol->symbol_id);
                $display=$a&&$b&&MonRun::json($a)===MonRun::json($b)&&
                    mon_get(mon_get($cached->origin->raw_records,$date),'record_sha256')===mon_get(mon_get($fresh->origin->raw_records,$date),'record_sha256')&&
                    self::derives($old[$date],$cached->origin,$a)&&self::derives($row,$fresh->origin,$b);
                if(!$display)$conflict=true;else $row=array_merge([$date],$b->values);
                foreach($fields as $field=>$values)$diagnostics[]=(object)['symbol_id'=>$symbol->symbol_id,'date'=>$date,'field'=>$field,
                    'old_value'=>$values[0],'new_value'=>$values[1],'old_basis'=>$oldBasis,'new_basis'=>$newBasis,
                    'old_provider'=>mon_get($cached->origin,'source'),'new_provider'=>mon_get($fresh->origin,'source'),
                    'old_record_sha256'=>mon_get(mon_get(mon_get($cached->origin,'raw_records'),$date),'record_sha256'),
                    'new_record_sha256'=>mon_get(mon_get(mon_get($fresh->origin,'raw_records'),$date),'record_sha256'),
                    'old_checked_at'=>mon_get($cached->origin,'checked_at'),'new_checked_at'=>mon_get($fresh->origin,'checked_at'),
                    'classification'=>$display?'display_only_same_immutable_record':'source_conflict'];
            }
        }$old[$date]=$row;}
        ksort($old,SORT_STRING);return ['conflict'=>$conflict,'bars'=>array_values($old),'diagnostics'=>$diagnostics];
    }
}
final class MonOperator {
    public static function configured(MonConfig $cfg): bool {return strlen($cfg->get('MON_OPERATOR_TOKEN'))>=24||$cfg->get('MON_OPERATOR_USER')!=='';}
    public static function check(MonConfig $cfg,?array $server=null): string {
        $server=$server??$_SERVER;$allowed=$cfg->get('MON_OPERATOR_USER');
        // REMOTE_USER must be set by NAS/web-server authentication, never by a request header.
        $user=$server['REMOTE_USER']??'';
        if($allowed!==''&&is_string($user)&&hash_equals($allowed,$user))return $user;
        $expected=$cfg->get('MON_OPERATOR_TOKEN');$provided=$server['HTTP_X_MON_OPERATOR_TOKEN']??'';
        if(strlen($expected)>=24&&is_string($provided)&&hash_equals($expected,$provided))return 'operator_token';
        if(!self::configured($cfg))throw new MonFault('MON_OPERATOR_AUTH_UNCONFIGURED');
        throw new MonFault('MON_OPERATOR_AUTH_REQUIRED');
    }
}
final class MonArtifact {
    const CONTRACT='MON-FILES-1.0';
    public static function detachCards(MonStore $store,&$node,callable $authorize): void {
        if(!is_array($node)&&!($node instanceof stdClass))return;
        $cards=mon_get($node,'artifact_cards');
        if($cards instanceof stdClass&&count(get_object_vars($cards))){
            $authorize();$refs=[];foreach($cards as $sha=>$card){$a=self::card($store,$sha,$card);unset($a['content']);$a['content_ref']='mon_card_read';$refs[]=$a;}
            if(is_array($node)){$node['artifact_cards']=[];$node['card_artifacts']=$refs;}else{$node->artifact_cards=[];$node->card_artifacts=$refs;}
        }
        foreach($node as &$child)if(is_array($child)||$child instanceof stdClass)self::detachCards($store,$child,$authorize);unset($child);
    }
    public static function create(MonStore $store,string $source,array $files,array $metadata=[]): array {
        if(count($files)<2)throw new MonFault('MON_FILE_PAIR_INVALID');
        $rows=[];$seen=[];$dependencies=[];if(is_string(mon_get($metadata,'dependency_parent_artifact'))){$parent=self::load($store,$metadata['dependency_parent_artifact']);foreach(mon_get($parent,'dependencies',[])as $ref)$files[]=(array)self::file($store,$parent,$ref->path);}
        foreach($files as $file){
            $path=mon_get($file,'path');$raw=mon_get($file,'content');
            if((!in_array($path,['mon_data.json','mon_result.md'],true)&&!MonGaps::dependencyPath((string)$path))||isset($seen[$path])||!is_string($raw)||strlen($raw)>MonRun::MAX_SOURCE)throw new MonFault('MON_FILE_PAIR_INVALID');
            $sha=MonRun::gitSha($raw);if(!hash_equals((string)mon_get($file,'blob_sha'),$sha))throw new MonFault('MON_FILE_SHA_MISMATCH');
            $seen[$path]=true;$row=(object)['path'=>$path,'content'=>$raw,'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'blob_sha'=>$sha];if(in_array($path,['mon_data.json','mon_result.md'],true))$rows[]=$row;else{$store->writePrivate('dependency-'.$sha.'.php',$row);$dependencies[]=MonRun::pick($row,['path','bytes','sha256','blob_sha']);}
        }
        if(count($rows)!==2)throw new MonFault('MON_FILE_PAIR_INVALID');usort($dependencies,static function($a,$b){return strcmp($a->path,$b->path);});
        usort($rows,static function($a,$b){return strcmp($a->path,$b->path);});
        $metadata['dependencies']=$dependencies;
        $sourceSha=MonRun::gitSha($source);$base=mon_run_source((object)['source_json'=>$source],'source_json',$sourceSha);
        $candidate=mon_decode($rows[0]->content);MonRun::validate($candidate);MonRun::verifyFacts($candidate,MonRun::payload($candidate));
        $payload=MonRun::payload($candidate);
        foreach($dependencies as $ref){
            if(preg_match('/^mon_recovery_archive_([a-f0-9]{64})\.json$/',$ref->path,$match)){if($match[1]!==$ref->sha256)throw new MonFault('MON_DEPENDENCY_BINDING_INVALID');}
            else{$manifest=mon_get($payload,$ref->path==='mon_recovery_inputs.json'?'recovery_source_manifest':'recovery_archive_manifest');
                if(mon_get($manifest,'path')!==$ref->path||mon_get($manifest,'blob_sha')!==$ref->blob_sha||mon_get($manifest,'sha256')!==$ref->sha256)throw new MonFault('MON_DEPENDENCY_BINDING_INVALID');}
        }
        if(MonRun::json($base->collection)!==MonRun::json($candidate->collection))throw new MonFault('MON_OWNERSHIP_INVALID');
        $descriptors=array_map(static function($f){return MonRun::pick($f,['path','bytes','sha256','blob_sha']);},$rows);
        $id=hash('sha256',MonRun::json((object)['contract'=>self::CONTRACT,'source_blob_sha'=>$sourceSha,'files'=>$descriptors,'dependencies'=>$dependencies,'run_context_id'=>mon_get($metadata,'run_context_id')]));
        $artifact=(object)['contract'=>self::CONTRACT,'artifact_id'=>$id,'source_blob_sha'=>$sourceSha,'source_sha256'=>hash('sha256',$source),
            'source_json'=>$source,'files'=>$rows,'dependencies'=>$dependencies,'metadata'=>(object)$metadata];
        $artifact->sha256=hash('sha256',MonRun::json($artifact));
        $old=$store->readPrivate('artifact-'.$id.'.php');
        if($old===null)$store->writePrivate('artifact-'.$id.'.php',$artifact);
        MonCompletion::bind($store,$candidate,$id,$rows,$metadata);
        return self::manifest(self::load($store,$id));
    }
    public static function load(MonStore $store,string $id): stdClass {
        MonExecution::id($id);$a=$store->readPrivate('artifact-'.$id.'.php');$copy=$a instanceof stdClass?clone $a:new stdClass();unset($copy->sha256);
        if(!($a instanceof stdClass)||mon_get($a,'contract')!==self::CONTRACT||mon_get($a,'artifact_id')!==$id||
            !hash_equals((string)mon_get($a,'sha256'),hash('sha256',MonRun::json($copy)))||
            !hash_equals($a->source_blob_sha,MonRun::gitSha($a->source_json))||!hash_equals($a->source_sha256,hash('sha256',$a->source_json)))throw new MonFault('MON_ARTIFACT_INVALID');
        foreach($a->files as $f)if(!in_array($f->path,['mon_data.json','mon_result.md'],true)||strlen($f->content)!==$f->bytes||
            !hash_equals($f->sha256,hash('sha256',$f->content))||!hash_equals($f->blob_sha,MonRun::gitSha($f->content)))throw new MonFault('MON_ARTIFACT_HASH_CHANGED');
        foreach(mon_get($a,'dependencies',[])as $f)if(!MonGaps::dependencyPath((string)mon_get($f,'path'))||!is_int(mon_get($f,'bytes'))||$f->bytes<0||$f->bytes>MonRun::MAX_SOURCE||
            !preg_match('/^[a-f0-9]{40}$/',(string)mon_get($f,'blob_sha'))||!preg_match('/^[a-f0-9]{64}$/',(string)mon_get($f,'sha256')))throw new MonFault('MON_ARTIFACT_HASH_CHANGED');
        return $a;
    }
    public static function manifest($a): array {
        return ['contract'=>self::CONTRACT,'artifact_id'=>$a->artifact_id,'source_blob_sha'=>$a->source_blob_sha,
            'prepared_fingerprint'=>mon_get($a->metadata,'prepared_fingerprint'),
            'files'=>array_map(static function($f){$m=MonRun::pick($f,['path','bytes','sha256','blob_sha']);$m->content_ref='mon_artifact_read';return $m;},array_merge($a->files,mon_get($a,'dependencies',[]))),
            'content_in_model'=>false,'lossless'=>true];
    }
    public static function chunk(MonStore $store,$request): array {
        $a=self::load($store,(string)mon_get($request,'artifact_id',''));$path=mon_get($request,'path');$offset=mon_get($request,'offset',0);$size=mon_get($request,'length',65536);
        if(!is_int($offset)||$offset<0||!is_int($size)||$size<1||$size>65536)throw new MonFault('MON_ARTIFACT_RANGE_INVALID');
        $file=null;foreach(array_merge($a->files,mon_get($a,'dependencies',[]))as $f)if($f->path===$path)$file=$f;
        if(!$file||$offset>$file->bytes)throw new MonFault('MON_ARTIFACT_RANGE_INVALID');
        $file=self::file($store,$a,$file->path);$chunk=substr($file->content,$offset,$size);$next=$offset+strlen($chunk);
        return ['artifact_id'=>$a->artifact_id,'path'=>$path,'offset'=>$offset,'encoding'=>'base64','data'=>base64_encode($chunk),
            'chunk_bytes'=>strlen($chunk),'chunk_sha256'=>hash('sha256',$chunk),'total_bytes'=>$file->bytes,'sha256'=>$file->sha256,
            'blob_sha'=>$file->blob_sha,'next_offset'=>$next<$file->bytes?$next:null];
    }
    public static function file(MonStore $store,$a,string $path): stdClass {
        foreach($a->files as $f)if($f->path===$path)return $f;
        foreach(mon_get($a,'dependencies',[])as $ref)if($ref->path===$path){$f=$store->readPrivate('dependency-'.$ref->blob_sha.'.php');
            if(!($f instanceof stdClass)||mon_get($f,'path')!==$path||!is_string(mon_get($f,'content'))||strlen($f->content)!==$ref->bytes||
                !hash_equals($ref->sha256,hash('sha256',$f->content))||!hash_equals($ref->blob_sha,MonRun::gitSha($f->content)))throw new MonFault('MON_ARTIFACT_HASH_CHANGED');return $f;}
        throw new MonFault('MON_ARTIFACT_RANGE_INVALID');
    }
    public static function card(MonStore $store,string $sha,$value=null): array {
        MonExecution::id($sha);
        if($value!==null){$raw=MonRun::json($value);if(!hash_equals($sha,hash('sha256',$raw)))throw new MonFault('MON_CARD_HASH_INVALID');
            $store->writePrivate('card-'.$sha.'.php',(object)['sha256'=>$sha,'bytes'=>strlen($raw),'content'=>$raw]);}
        $c=$store->readPrivate('card-'.$sha.'.php');
        if(!($c instanceof stdClass)||!hash_equals($sha,hash('sha256',$c->content))||strlen($c->content)!==$c->bytes)throw new MonFault('MON_CARD_HASH_INVALID');
        return ['sha256'=>$sha,'bytes'=>$c->bytes,'content'=>$c->content];
    }
}
final class MonPublication {
    const CONTRACT='MON-PUBLISH-1.0';
    private $store;private $http;private $cfg;private $clock;private $mono;private $wallStart;private $monoStart;
    public function __construct(MonStore $store,MonHttp $http,MonConfig $cfg,?callable $clock=null,?callable $mono=null){
        $this->store=$store;$this->http=$http;$this->cfg=$cfg;$this->clock=$clock?:static function(){return microtime(true);};$this->mono=$mono?:static function(){return mon_monotonic();};
        $this->wallStart=(float)($this->clock)();$this->monoStart=(float)($this->mono)();}
    private function now(): float {return max((float)($this->clock)(),$this->wallStart+max(0,(float)($this->mono)()-$this->monoStart));}
    private function save($job): void {
        $job->last_seen_at=MonBudget::iso(max($this->now(),MonRun::seconds(mon_get($job,'last_seen_at',$job->created_at))));
        $copy=clone $job;unset($copy->journal_sha256);$job->journal_sha256=hash('sha256',MonRun::json($copy));
        $this->store->writePrivate('publish-'.$job->job_id.'.php',$job);
        try{$this->store->writePrivate('publication-receipt.php',(object)$this->receipt($job));}catch(MonFault $e){$this->store->log('publication_receipt_unavailable',['code'=>$e->faultCode]);}
    }
    public static function journal(MonStore $store,string $id): stdClass {
        MonExecution::id($id);$j=$store->readPrivate('publish-'.$id.'.php');$c=$j instanceof stdClass?clone $j:new stdClass();unset($c->journal_sha256);
        if(!($j instanceof stdClass)||mon_get($j,'contract')!==self::CONTRACT||mon_get($j,'job_id')!==$id||
            !hash_equals((string)mon_get($j,'journal_sha256'),hash('sha256',MonRun::json($c))))throw new MonFault('MON_PUBLICATION_JOURNAL_INVALID');
        return $j;
    }
    public function load(string $id): stdClass {return self::journal($this->store,$id);}
    public function enqueue(string $artifactId,string $baseHead,MonBudget $budget,string $operator): array {
        if(!preg_match('/^[a-f0-9]{40}$/',$baseHead))throw new MonFault('MON_EXPECTED_HEAD_INVALID');
        $a=MonArtifact::load($this->store,$artifactId);$decision=mon_get($a->metadata,'decision_sha256');
        if(mon_get($a->metadata,'run_context_id')!==$budget->run->run_context_id||mon_get($a->metadata,'received_at')!==$budget->run->received_at)throw new MonFault('MON_PUBLICATION_RUN_MISMATCH');
        if(!preg_match('/^[a-f0-9]{64}$/',(string)$decision))throw new MonFault('MON_DECISION_HASH_INVALID');
        $id=hash('sha256',self::CONTRACT.'|'.$artifactId.'|'.$baseHead.'|'.$decision);
        $lock=@fopen($this->store->path('enqueue-'.$id.'.lock'),'c+');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new MonFault('MON_PUBLICATION_BUSY');}
        try{
            if(is_file($this->store->path('publish-'.$id.'.php')))return $this->receipt($this->load($id));
            $job=(object)['contract'=>self::CONTRACT,'job_id'=>$id,'run_context_id'=>$budget->run->run_context_id,'artifact_id'=>$artifactId,
                'original_artifact_id'=>$artifactId,'expected_head'=>$baseHead,'decision_sha256'=>$decision,'queued_by'=>$operator,
                'received_at'=>$budget->run->received_at,'created_at'=>MonBudget::iso($this->now()),'expires_at'=>MonBudget::iso($budget->end('recovery')),
                'status'=>'queued','stage'=>'source','parent_head'=>null,'base_tree'=>null,
                'blob_shas'=>new stdClass(),'tree_sha'=>null,'candidate_commit_sha'=>null,'ref_attempted'=>false,'ref_acknowledged'=>false,
                'confirmation_checked'=>false,'rebase_count'=>0,'safe_failures'=>0,'retry_after'=>null,'last_error'=>null,'published_commit_sha'=>null,'completed_at'=>null,
                'pending_analysis'=>false,'pending_analysis_countries'=>[]];
            $this->save($job);return $this->receipt($this->load($id));
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function receipt($job): array {
        $run=$this->store->readPrivate('run-'.$job->run_context_id.'.php');
        $completion=$run instanceof stdClass?MonCompletion::assess($this->store,$run,MonBudget::iso($this->now()),$job->job_id):null;
        return ['contract'=>self::CONTRACT,'job_id'=>$job->job_id,'run_context_id'=>$job->run_context_id,'artifact_id'=>$job->artifact_id,
            'save_status'=>$job->status,'stage'=>$job->stage,'final_available'=>true,'publication_complete'=>$job->status==='saved',
            'fully_complete'=>$completion!==null&&$completion['fully_complete'],'completion'=>$completion,
            'expected_head'=>$job->expected_head,'candidate_commit_sha'=>$job->candidate_commit_sha,'published_commit_sha'=>$job->published_commit_sha,
            'completed_at'=>$job->completed_at,'expires_at'=>$job->expires_at,'elapsed_seconds'=>round(max(0,$this->now()-MonRun::seconds($job->received_at)),6),
            'limit_behavior'=>'observe_and_continue','time_excess_seconds'=>round(max(0,$this->now()-MonRun::seconds($job->expires_at)),6),
            'last_error'=>$job->last_error,'rebase_count'=>$job->rebase_count,'safe_failures'=>$job->safe_failures,
            'pending_analysis'=>$job->pending_analysis,'pending_analysis_countries'=>$job->pending_analysis_countries];
    }
    public function statisticsProof($expected,float $deadline): stdClass {
        $binding=hash('sha256',MonRun::json($expected));$name='statistics-proof-'.$expected->run_context_id.'-'.$binding.'.php';$e=$this->store->readPrivate($name);$state=mon_get($e,'state');
        if($state!==null&&(!($state instanceof stdClass)||!hash_equals((string)mon_get($e,'sha256'),hash('sha256',MonRun::json($state)))))throw new MonFault('MON_STATISTICS_PROOF_INVALID');
        if($state===null)$state=(object)['binding'=>$binding,'head'=>null,'tree'=>null,'blob_sha'=>null];
        $save=function()use($name,$state){$this->store->writePrivate($name,(object)['state'=>$state,'sha256'=>hash('sha256',MonRun::json($state))]);};
        if($state->head===null){$state->head=$this->head($deadline);$save();}
        if($state->tree===null){$commit=$this->api('GET','/git/commits/'.$state->head,null,$deadline);$state->tree=mon_get(mon_get($commit,'tree'),'sha');
            if(!preg_match('/^[a-f0-9]{40}$/',(string)$state->tree))throw new MonFault('MON_TREE_INVALID');$save();}
        if($state->blob_sha===null){$tree=$this->api('GET','/git/trees/'.$state->tree,null,$deadline);foreach(mon_get($tree,'tree',[])as $entry)if(mon_get($entry,'path')==='mon_execution_stats.json')$state->blob_sha=$entry->sha;
            if(!preg_match('/^[a-f0-9]{40}$/',(string)$state->blob_sha)){$state->head=null;$state->tree=null;$save();throw new MonFault('MON_STATISTICS_REMOTE_MISMATCH');}$save();}
        $raw=$this->api('GET','/contents/mon_execution_stats.json?ref='.$state->head,null,$deadline,true);
        try{$stats=MonRunStatistics::validate(mon_decode($raw));}catch(Throwable $e){throw new MonFault('MON_STATISTICS_REMOTE_INVALID');}
        $actual=null;foreach($stats->samples as $row)if($row->run_context_id===$expected->run_context_id)$actual=$row;
        if(!hash_equals($state->blob_sha,MonRun::gitSha($raw))||!$actual||$actual->revision!==$expected->revision||MonRun::json($actual)!==MonRun::json($expected)){
            $state->head=null;$state->tree=null;$state->blob_sha=null;$save();throw new MonFault('MON_STATISTICS_REMOTE_MISMATCH');}
        return (object)['run_context_id'=>$expected->run_context_id,'revision'=>$expected->revision,'row_sha256'=>$binding,
            'blob_sha'=>$state->blob_sha,'published_commit_sha'=>$state->head,'checked_at'=>MonBudget::iso($this->now())];
    }
    private function api(string $method,string $path,$body,float $deadline,bool $raw=false) {
        if($this->cfg->get('MON_REPOSITORY','wskimgit/stock')!=='wskimgit/stock'||$this->cfg->get('MON_BRANCH','main')!=='main')throw new MonFault('MON_PUBLICATION_SCOPE_INVALID');
        $token=$this->cfg->get('GITHUB_TOKEN');if($token===''&&$method!=='GET')throw new MonFault('GITHUB_TOKEN_MISSING');
        if($this->http->remaining($deadline)<.05)throw new MonFault('BUDGET_EXHAUSTED');
        $headers=['Accept: '.($raw?'application/vnd.github.raw+json':'application/vnd.github+json'),
            'Content-Type: application/json','X-GitHub-Api-Version: 2022-11-28'];
        if($token!=='')$headers[]='Authorization: Bearer '.$token;
        $r=$this->http->request($method,'https://api.github.com/repos/wskimgit/stock'.$path,$headers,$body===null?null:mon_json($body,false),$deadline,3);
        if($raw){if($r['status']<200||$r['status']>=300)MonHttp::decode($r);return $r['body'];}
        return MonHttp::decode($r);
    }
    private function head(float $deadline): string {
        $ref=$this->api('GET','/git/ref/heads/main',null,$deadline);$sha=mon_get(mon_get($ref,'object'),'sha');
        if(!preg_match('/^[a-f0-9]{40}$/',(string)$sha))throw new MonFault('MON_REF_INVALID');return $sha;
    }
    private function root(string $head,float $deadline): array {
        $commit=$this->api('GET','/git/commits/'.$head,null,$deadline);$tree=mon_get(mon_get($commit,'tree'),'sha');
        if(!preg_match('/^[a-f0-9]{40}$/',(string)$tree))throw new MonFault('MON_TREE_INVALID');
        $t=$this->api('GET','/git/trees/'.$tree,null,$deadline);
        if(mon_get($t,'truncated')===true||!is_array(mon_get($t,'tree')))throw new MonFault('MON_TREE_INVALID');
        $files=[];foreach($t->tree as $e)if(in_array(mon_get($e,'path'),['mon_data.json','mon_result.md','mon_execution_stats.json'],true)||MonGaps::dependencyPath((string)mon_get($e,'path')))$files[$e->path]=$e->sha;
        return ['tree'=>$tree,'files'=>$files];
    }
    
    private function publicationFiles($a,$job): array {
        $rows=[];foreach(array_merge(mon_get($a,'dependencies',[]),mon_get($job,'dynamic_dependencies',[]),$a->files)as $f){
            if(isset($rows[$f->path])&&$rows[$f->path]->blob_sha!==$f->blob_sha)throw new MonFault('MON_DEPENDENCY_BINDING_INVALID');$rows[$f->path]=$f;}
        return array_values($rows);
    }
    private function content($a,$job,string $path): string {
        $copy=clone $a;$copy->dependencies=mon_get($job,'dynamic_dependencies',[]);foreach(mon_get($a,'dependencies',[])as $ref)$copy->dependencies[]=$ref;
        return MonArtifact::file($this->store,$copy,$path)->content;
    }
    private function dependencies($job,$a,float $deadline): bool {
        if(!property_exists($job,'dependency_queue')){
            $p=MonRun::payload(mon_decode($a->files[0]->content));$queue=[];
            foreach(mon_get($a,'dependencies',[])as $ref)$queue[$ref->path]=$ref;
            foreach(['recovery_source_manifest','recovery_archive_manifest','recovery_archive_head']as $key){$ref=mon_get($p,$key);if($ref instanceof stdClass){
                $old=$queue[$ref->path]??null;if($old&&$old->blob_sha!==$ref->blob_sha)throw new MonFault('MON_DEPENDENCY_BINDING_INVALID');$queue[$ref->path]=$ref;}}
            unset($p);$job->dependency_queue=array_values($queue);$job->dependency_cursor=0;$job->dynamic_dependencies=[];$this->save($job);
        }
        while($job->dependency_cursor<count($job->dependency_queue)){
            if($this->http->remaining($deadline)<.1)return false;$ref=$job->dependency_queue[$job->dependency_cursor];$path=(string)mon_get($ref,'path');
            if(!MonGaps::dependencyPath($path)||!preg_match('/^[a-f0-9]{40}$/',(string)mon_get($ref,'blob_sha'))||!preg_match('/^[a-f0-9]{64}$/',(string)mon_get($ref,'sha256')))throw new MonFault('MON_DEPENDENCY_BINDING_INVALID');
            $row=$this->store->readPrivate('dependency-'.$ref->blob_sha.'.php');$raw=mon_get($row,'content');
            if(!is_string($raw))try{$raw=MonGaps::dependencyRaw($ref,false);}catch(MonFault $e){
                if($e->faultCode!=='MON_GAP_SOURCE_UNAVAILABLE')throw $e;$blob=$this->api('GET','/git/blobs/'.$ref->blob_sha,null,$deadline);
                $raw=mon_get($blob,'encoding')==='base64'?base64_decode((string)mon_get($blob,'content'),true):null;
            }
            if(!is_string($raw)||strlen($raw)>MonRun::MAX_SOURCE||!hash_equals($ref->sha256,hash('sha256',$raw))||!hash_equals($ref->blob_sha,MonRun::gitSha($raw)))throw new MonFault('MON_DEPENDENCY_HASH_INVALID');
            if(preg_match('/^mon_recovery_archive_([a-f0-9]{64})\.json$/',$path,$match)&&$match[1]!==$ref->sha256)throw new MonFault('MON_DEPENDENCY_BINDING_INVALID');
            if($path!=='mon_recovery_inputs.json'){
                $bundle=mon_decode($raw);if(!in_array(mon_get($bundle,'contract'),['MON-GAP-ARCHIVE-1.0','MON-GAP-ARCHIVE-2.0'],true))throw new MonFault('MON_GAP_ARCHIVE_INVALID');
                $parent=mon_get($bundle,'previous_manifest');
                if(mon_get($bundle,'contract')==='MON-GAP-ARCHIVE-2.0'){
                    if(!is_int(mon_get($bundle,'generation'))||$bundle->generation<1||mon_get($ref,'generation',$bundle->generation)!==$bundle->generation||
                        $parent!==null&&!($parent instanceof stdClass)||$parent instanceof stdClass&&(int)mon_get($parent,'generation',0)!==$bundle->generation-1)throw new MonFault('MON_GAP_ARCHIVE_CYCLE');
                    if($parent instanceof stdClass){$known=false;foreach($job->dependency_queue as $queued)if($queued->path===$parent->path){
                        if($queued->blob_sha!==$parent->blob_sha)throw new MonFault('MON_DEPENDENCY_BINDING_INVALID');$known=true;}
                        if(!$known)$job->dependency_queue[]=$parent;
                    }
                }
            }
            $descriptor=(object)['path'=>$path,'bytes'=>strlen($raw),'sha256'=>$ref->sha256,'blob_sha'=>$ref->blob_sha];
            $this->store->writePrivate('dependency-'.$ref->blob_sha.'.php',(object)array_merge((array)$descriptor,['content'=>$raw]));
            $job->dynamic_dependencies[]=$descriptor;$job->dependency_cursor++;$this->save($job);unset($raw,$bundle);
        }
        return true;
    }
    private function source($job,float $deadline): void {
        $a=MonArtifact::load($this->store,$job->artifact_id);$head=$this->head($deadline);$root=$this->root($head,$deadline);if(!$this->dependencies($job,$a,$deadline)){$job->status='queued';$this->save($job);return;}
        $same=true;foreach($this->publicationFiles($a,$job)as $f)if(($root['files'][$f->path]??null)!==$f->blob_sha)$same=false;
        if($same){$job->status='saved';$job->published_commit_sha=$head;$job->completed_at=MonBudget::iso($this->now());$job->stage='complete';$job->last_error=null;$job->retry_after=null;$this->save($job);return;}
        if($head!==$job->expected_head||($root['files']['mon_data.json']??null)!==$a->source_blob_sha){
            $raw=$this->api('GET','/contents/mon_data.json?ref='.$head,null,$deadline,true);
            $latest=mon_run_source((object)['source_json'=>$raw],'source_json',(string)($root['files']['mon_data.json']??''));
            $base=mon_decode($a->source_json);$candidate=mon_decode($a->files[0]->content);
            if(($root['files']['mon_result.md']??null)===$a->files[1]->blob_sha){
                try{
                    // A connector may already have saved the decision; later NAS quotes are retained.
                    $observed=MonRun::rebase($candidate,$candidate,$latest);
                    $manifest=MonArtifact::create($this->store,$raw,[['path'=>'mon_data.json','content'=>$raw,'blob_sha'=>MonRun::gitSha($raw)],
                        ['path'=>'mon_result.md','content'=>$a->files[1]->content,'blob_sha'=>$a->files[1]->blob_sha]],array_merge((array)$a->metadata,['dependency_parent_artifact'=>$a->artifact_id]));
                    $job->artifact_id=$manifest['artifact_id'];$job->pending_analysis=mon_get($observed->analysis,'pending_analysis',false);$job->pending_analysis_countries=mon_get($observed->analysis,'pending_analysis_countries',[]);
                    $next=MonArtifact::load($this->store,$job->artifact_id);$all=true;foreach($this->publicationFiles($next,$job)as $file)if(($root['files'][$file->path]??null)!==$file->blob_sha)$all=false;
                    if($all){$job->status='saved';$job->stage='complete';$job->published_commit_sha=$head;$job->completed_at=MonBudget::iso($this->now());}
                    else{$job->status='queued';$job->stage='blobs';$job->parent_head=$head;$job->base_tree=$root['tree'];$job->blob_shas=new stdClass();$job->tree_sha=null;$job->candidate_commit_sha=null;$job->ref_attempted=false;$job->ref_acknowledged=false;}
                    $job->last_error=null;$job->retry_after=null;$this->save($job);return;
                }catch(MonFault $e){if($e->faultCode!=='MON_ANALYSIS_SOURCE_CONFLICT')throw $e;}
            }
            $merged=MonRun::rebase($base,$candidate,$latest);
            $text=MonRun::json($merged);$summary=MonRun::render($merged,$merged->analysis->as_of,['동시 수집 병합; 판단시각 보존']);
            $manifest=MonArtifact::create($this->store,$raw,[['path'=>'mon_data.json','content'=>$text,'blob_sha'=>MonRun::gitSha($text)],['path'=>'mon_result.md','content'=>$summary,'blob_sha'=>MonRun::gitSha($summary)]],array_merge((array)$a->metadata,['dependency_parent_artifact'=>$a->artifact_id]));
            $job->artifact_id=$manifest['artifact_id'];$job->rebase_count++;$a=MonArtifact::load($this->store,$job->artifact_id);
            $job->pending_analysis=mon_get($merged->analysis,'pending_analysis',false);$job->pending_analysis_countries=mon_get($merged->analysis,'pending_analysis_countries',[]);
        }
        $same=true;foreach($this->publicationFiles($a,$job)as $f)if(($root['files'][$f->path]??null)!==$f->blob_sha)$same=false;
        if($same){$job->status='saved';$job->published_commit_sha=$head;$job->completed_at=MonBudget::iso($this->now());$job->stage='complete';$job->last_error=null;$job->retry_after=null;}
        else{$job->parent_head=$head;$job->base_tree=$root['tree'];$job->stage='blobs';$job->blob_shas=new stdClass();$job->tree_sha=null;$job->candidate_commit_sha=null;$job->ref_attempted=false;$job->ref_acknowledged=false;}
        $this->save($job);
    }
    public function resume(string $id): array {
        MonExecution::id($id);$lock=@fopen($this->store->path('publish-'.$id.'.lock'),'c+');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new MonFault('MON_PUBLICATION_BUSY');}
        try{$job=$this->load($id);$run=$this->store->readPrivate('run-'.$job->run_context_id.'.php');
            if(mon_get($run,'finish_reason')==='interrupted')throw new MonFault('MON_RUN_INTERRUPTED');
            if(in_array($job->status,['failed','conflict','expired','unknown'],true)){
                $job->status=$job->ref_attempted?'publishing':'queued';$job->retry_after=null;$this->save($job);
            }return $this->receipt($job);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function confirm($job,float $deadline): void {
        if($this->http->remaining($deadline)<.05)throw new MonFault('BUDGET_EXHAUSTED');
        $job->confirmation_checked=true;$this->save($job);$head=$this->head($deadline);$saved=$head===$job->candidate_commit_sha;
        if(!$saved){$root=$this->root($head,$deadline);$a=MonArtifact::load($this->store,$job->artifact_id);$saved=true;
            foreach($this->publicationFiles($a,$job)as $f)if(($root['files'][$f->path]??null)!==$f->blob_sha)$saved=false;}
        $job->status=$saved?'saved':'queued';$job->published_commit_sha=$saved?$head:null;
        $job->completed_at=$saved?MonBudget::iso($this->now()):null;
        if($saved){$job->stage='complete';$job->last_error=null;}
        else{
            // A successful current-head/file-pair read proves the intended result is absent.
            // Start from that source again; a real concurrent analysis conflict remains protected.
            $job->stage='source';$job->ref_attempted=false;$job->ref_acknowledged=false;$job->confirmation_checked=false;
            $job->last_error='MON_PUBLICATION_NOT_YET_SAVED';$job->retry_after=$this->now()+30;
        }$this->save($job);
    }
    public function process(string $id,float $deadline): array {
        MonExecution::id($id);
        $lock=@fopen($this->store->path('publish-'.$id.'.lock'),'c+');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return $this->receipt($this->load($id));}
        $job=null;$oldActive=MonExecution::$active;$oldStore=MonExecution::$store;$metered=false;$processStart=(float)($this->mono)();
        try{
            $job=$this->load($id);$hasBudget=$this->http->remaining($deadline)>=.05;
            if($hasBudget&&is_file($this->store->path('run-'.$job->run_context_id.'.php'))){
                MonExecution::resume($this->store,$job->run_context_id,$this->clock,$this->mono);$metered=true;
            }
            if($hasBudget)MonArtifact::load($this->store,$job->artifact_id);
            if(($job->status==='expired'&&$job->last_error==='MON_PUBLICATION_DEADLINE')||
                ($job->status==='failed'&&MonDaily::retryable((string)$job->last_error)))$job->status=$job->ref_attempted?'publishing':'queued';
            if($job->status==='unknown'&&$job->ref_attempted)$job->status='publishing';
            if(in_array($job->status,['saved','failed','conflict','expired','unknown'],true))return $this->receipt($job);
            if($this->now()+5<MonRun::seconds(mon_get($job,'last_seen_at',$job->created_at))){$job->status=$job->ref_attempted?'unknown':'failed';$job->last_error='MON_CLOCK_REWIND';$this->save($job);return $this->receipt($job);}
            if(!$hasBudget)return $this->receipt($job);
            if($metered){MonExecution::continueWork();MonExecution::$active->guard('recovery');}
            if(mon_get($job,'retry_after')!==null&&$this->now()<$job->retry_after)return $this->receipt($job);
            $this->http->rememberDeadline($deadline);
            $job->status='publishing';$this->save($job);
            if($job->ref_attempted){$this->confirm($job,$deadline);return $this->receipt($job);}
            if($job->stage==='source')$this->source($job,$deadline);
            if($job->status==='saved')return $this->receipt($job);
            $a=MonArtifact::load($this->store,$job->artifact_id);
            if($job->stage==='blobs'){
                foreach($this->publicationFiles($a,$job)as $f)if(mon_get($job->blob_shas,$f->path)!==$f->blob_sha){
                    $blob=$this->api('POST','/git/blobs',['content'=>$this->content($a,$job,$f->path),'encoding'=>'utf-8'],$deadline);
                    if(mon_get($blob,'sha')!==$f->blob_sha)throw new MonFault('MON_REMOTE_BLOB_SHA_MISMATCH');
                    $job->blob_shas->{$f->path}=$f->blob_sha;$this->save($job);
                }$job->stage='tree';$this->save($job);
            }
            if($job->stage==='tree'){
                $entries=[];foreach($this->publicationFiles($a,$job)as $f)$entries[]=['path'=>$f->path,'mode'=>'100644','type'=>'blob','sha'=>$f->blob_sha];
                $tree=$this->api('POST','/git/trees',['base_tree'=>$job->base_tree,'tree'=>$entries],$deadline);
                $job->tree_sha=mon_get($tree,'sha');if(!preg_match('/^[a-f0-9]{40}$/',(string)$job->tree_sha))throw new MonFault('MON_TREE_INVALID');
                $job->stage='commit';$this->save($job);
            }
            if($job->stage==='commit'){
                $commit=$this->api('POST','/git/commits',['message'=>'mon: finalize '.$job->decision_sha256,'tree'=>$job->tree_sha,'parents'=>[$job->parent_head]],$deadline);
                $job->candidate_commit_sha=mon_get($commit,'sha');if(!preg_match('/^[a-f0-9]{40}$/',(string)$job->candidate_commit_sha))throw new MonFault('MON_COMMIT_INVALID');
                $job->stage='ref';$this->save($job);
            }
            if($job->stage==='ref'){
                $current=$this->head($deadline);
                if($current!==$job->parent_head){$job->stage='source';$this->save($job);$this->source($job,$deadline);$job->status=$job->status==='saved'?'saved':'queued';$this->save($job);return $this->receipt($job);}
                if($this->http->remaining($deadline)<.05)throw new MonFault('BUDGET_EXHAUSTED');
                // Persist intent BEFORE the only public mutation. Restarts confirm, never blindly re-patch.
                $job->ref_attempted=true;$job->confirmation_checked=false;$this->save($job);
                $ref=$this->api('PATCH','/git/refs/heads/main',['sha'=>$job->candidate_commit_sha,'force'=>false],$deadline);
                if(mon_get(mon_get($ref,'object'),'sha')!==$job->candidate_commit_sha)throw new MonFault('MON_PUBLICATION_UNCONFIRMED');
                $job->ref_acknowledged=true;$this->save($job);$this->confirm($job,$deadline);
            }
            return $this->receipt($job);
        }catch(MonStop $e){throw $e;}
        catch(MonFault $e){
            if(!$job)throw $e;$job->last_error=$e->faultCode;
            if($job->ref_attempted){
                if(!$job->ref_acknowledged&&in_array($e->faultCode,['HTTP_401','HTTP_403','HTTP_404'],true)){$job->status='failed';}
                elseif(!$job->ref_acknowledged&&in_array($e->faultCode,['HTTP_409','HTTP_422'],true)){
                    $job->ref_attempted=false;$job->stage='source';
                    try{$this->source($job,$deadline);if($job->status!=='saved')$job->status='queued';}
                    catch(MonFault $conflict){$job->status=MonDaily::retryable($conflict->faultCode)?'queued':'conflict';$job->last_error=$conflict->faultCode;}
                }elseif(MonDaily::retryable($e->faultCode)||$e->faultCode==='MON_PUBLICATION_UNCONFIRMED'){
                    $job->status='publishing';$job->retry_after=$this->now()+max(30,$e->retryAfter);
                }else $job->status='unknown';
            }elseif($e->faultCode==='BUDGET_EXHAUSTED')$job->status='queued';
            elseif($e->faultCode==='MON_ANALYSIS_SOURCE_CONFLICT')$job->status='conflict';
            elseif(MonDaily::retryable($e->faultCode)){
                $job->safe_failures++;$job->status='queued';$job->retry_after=$this->now()+max(min(300,30*(2**min(4,$job->safe_failures-1))),$e->retryAfter);
            }else $job->status='failed';
            $this->save($job);return $this->receipt($job);
        }finally{
            try{if($metered&&$job){
                MonExecution::measure('handler/mon_publish_process',max(0,(float)($this->mono)()-$processStart),mon_get($job,'last_error'));
                $receipt=$this->receipt($job);MonExecution::event('publication_observed',['save_status'=>$receipt['save_status'],'job_id'=>$id]);
                if(mon_get(mon_get(MonExecution::$active->run,'operation_policy'),'finish_owner','publication_legacy')==='publication_legacy')MonExecution::finish(in_array($job->status,['failed','conflict'],true)?'failed':'finished');
            }}catch(MonFault $e){$this->store->log('run_metrics_unavailable',['code'=>$e->faultCode]);}
            finally{if($oldActive&&MonExecution::$active&&$oldActive->run->run_context_id===MonExecution::$active->run->run_context_id)$oldActive->run=MonExecution::$active->run;
                MonExecution::$active=$oldActive;MonExecution::$store=$oldStore;flock($lock,LOCK_UN);fclose($lock);}
        }
    }
    public function processNext(float $deadline): ?array {
        $files=glob($this->store->path('publish-*.php'))?:[];sort($files,SORT_STRING);
        foreach($files as $file)if(preg_match('/^mon_publish-([a-f0-9]{64})\.php$/',basename($file),$m)){
            try{$j=$this->load($m[1]);$resumable=in_array($j->status,['queued','publishing'],true)||($j->status==='unknown'&&$j->ref_attempted)||
                    ($j->status==='expired'&&$j->last_error==='MON_PUBLICATION_DEADLINE')||($j->status==='failed'&&MonDaily::retryable((string)$j->last_error));
                if($resumable&&($j->retry_after===null||$this->now()>=$j->retry_after))return $this->process($m[1],$deadline);}
            catch(MonFault $e){$this->store->log('publication_job_invalid',['code'=>$e->faultCode]);continue;}
        }return null;
    }
}

/* Recover only a remotely confirmed predecessor. Preserve receipt, selection and clock history. */
function mon_recover_completion(MonStore $store,$request,MonBudget $budget): array {
    $action=mon_get($request,'action','confirm');if(!in_array($action,['confirm','reprepare'],true))throw new MonFault('MON_RECOVERY_ACTION_INVALID');
    if(mon_get($budget->run,'finish_reason')==='interrupted')throw new MonFault('MON_RUN_INTERRUPTED');
    $gate=MonExecution::completion($budget);if($gate['fully_complete'])throw new MonFault('MON_RUN_FINISHED');
    $jobId=mon_get($budget->run,'job_id');if(!is_string($jobId))throw new MonFault('MON_RECOVERY_PUBLICATION_REQUIRED');
    $job=MonPublication::journal($store,$jobId);
    if($job->status!=='saved'||$job->stage!=='complete'||!preg_match('/^[a-f0-9]{40}$/',(string)$job->published_commit_sha)||$job->run_context_id!==$budget->run->run_context_id)throw new MonFault('MON_RECOVERY_PUBLICATION_REQUIRED');
    $previous=MonArtifact::load($store,$job->artifact_id);$context=MonRun::context(mon_get($request,'context'));
    if(mon_get($context,'run_context_id')!==$budget->run->run_context_id||MonRun::seconds($context->rendered_at)>$budget->now()+5)throw new MonFault('MON_RECOVERY_CONTEXT_INVALID');
    $data=mon_run_source($request,'source_json',$context->source_blob_sha);$old=mon_decode($previous->files[0]->content);
    // Rebase checks exact non-collection ownership; a different MON decision cannot be replaced.
    MonRun::rebase($old,$old,$data);
    if($action==='reprepare'){
        MonExecution::recoverFinal('reprepare');MonExecution::event('completion_reprepare_requested');
        return ['status'=>'recovery_preparation_required','write_required'=>false,'files'=>[],'next_action'=>'mon_detail_prepare_then_finalize',
            'run_context_id'=>$budget->run->run_context_id,'previous_artifact_id'=>$previous->artifact_id,'received_at'=>$budget->run->received_at];
    }
    $patch=mon_get($request,'decision_patch');
    if(!($patch instanceof stdClass)||MonRun::json(MonRun::context(mon_get($patch,'view_context')))!==MonRun::json($context)||
        mon_get($patch,'run_mode')!=='evaluate'||!is_array(mon_get($patch,'changes'))||!$patch->changes||!is_array(mon_get($patch,'new_evidence'))||!$patch->new_evidence||
        MonRun::seconds(mon_get($patch,'fixed_as_of'))>$budget->now()+5)throw new MonFault('MON_RECOVERY_EVIDENCE_REQUIRED');
    foreach(MonRun::periods($data,$patch->fixed_as_of)as $period)if(!$period->current_period&&in_array($period->country,$context->countries,true))throw new MonFault('MON_PERIOD_CHANGED_PREPARATION_REQUIRED');
    $result=mon_apply_mon_patch($data,$patch);
    if(!$result['write_required'])throw new MonFault('MON_RECOVERY_EVIDENCE_REQUIRED');
    $metadata=(array)$previous->metadata;$metadata['recovery_action']='confirm';$metadata['previous_artifact_id']=$previous->artifact_id;
    $metadata['run_context_id']=$budget->run->run_context_id;$metadata['received_at']=$budget->run->received_at;
    $result['manifest']=MonArtifact::create($store,$request->source_json,$result['files'],$metadata);
    MonExecution::recoverFinal('confirm');
    MonExecution::event('completion_confirmation_updated',['artifact_id'=>$result['manifest']['artifact_id'],'new_analysis_complete'=>true,
        'final_available'=>true,'analysis_origin'=>'confirmed_existing_selection','save_status'=>'not_requested']);
    $result['status']='recovery_confirmation_prepared';$result['next_action']='mon_publish_queue';return $result;
}
function mon_run_web(string $api): int {
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');$start=microtime(true);$mono=mon_monotonic();
    $data=null;$request=null;$store=null;$publication=null;$publicationJobId=null;$publicationReceipt=null;$publicationAttempted=false;
    MonExecution::$active=null;MonExecution::$store=null;MonPreparation::$store=null;
    try {
        $controls=['mon_run_start','mon_run_status','mon_run_observe','mon_run_report','mon_run_finish','mon_run_recover','mon_run_statistics','mon_operation_nas_check','mon_operation_verify_statistics','mon_operation_report','mon_storage_compact','mon_archive_page','mon_archive_lookup','mon_artifact_read','mon_card_read','mon_publish_queue','mon_publish_process'];
        $legacy=['mon_bootstrap','mon_view','mon_patch','mon_swing_prepare','mon_swing_migrate','mon_swing_select','mon_detail_request','mon_detail_requests','mon_detail_view','mon_detail_prepare','mon_detail_select'];
        if(!in_array($api,array_merge($controls,$legacy,['mon_detail_finalize','mon_discovery_plan','mon_discovery_acquire','mon_gap_plan']),true))throw new MonFault('MON_API_UNKNOWN');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new MonFault('MON_METHOD_INVALID');
        if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new MonFault('MON_CONTENT_TYPE_INVALID');
        if((int)($_SERVER['CONTENT_LENGTH']??0)>MonRun::MAX_BODY)throw new MonFault('MON_BODY_TOO_LARGE');
        $raw=file_get_contents('php://input',false,null,0,MonRun::MAX_BODY+1);
        if(!is_string($raw)||strlen($raw)>MonRun::MAX_BODY)throw new MonFault('MON_BODY_TOO_LARGE');
        try{$request=mon_decode($raw);}catch(Throwable $e){throw new MonFault('MON_REQUEST_JSON_INVALID');}
        if(!($request instanceof stdClass))throw new MonFault('MON_REQUEST_INVALID');
        if(!in_array(mon_get($request,'response_mode',in_array($api,['mon_detail_finalize','mon_run_recover'],true)?'manifest':'inline'),['inline','manifest'],true))throw new MonFault('MON_RESPONSE_MODE_INVALID');
        $store=new MonStore(getenv('MON_STATE_DIR')?:__DIR__);$cfg=new MonConfig(getenv('MON_ENV_FILE')?:'',$store->dir);$operator=null;
        if(in_array($api,$controls,true)){
            $operator=MonOperator::check($cfg);
            if($api==='mon_run_start'){$result=MonExecution::view(MonExecution::start($store,$request));$result['initial_result']=MonBootstrap::fromStore($store);}
            elseif($api==='mon_run_statistics'){
                $workload=(string)mon_get($request,'workload','mon');if(!preg_match('/^[A-Za-z0-9._:-]{1,32}$/',$workload))throw new MonFault('MON_WORKLOAD_INVALID');
                $stats=property_exists($request,'statistics_snapshot')?MonRunStatistics::save($store,MonRunStatistics::validate($request->statistics_snapshot)):MonRunStatistics::load($store);
                $result=['statistics'=>MonRunStatistics::summary($stats,$workload,microtime(true)),'next_policy'=>MonRunStatistics::recommend($stats,$workload,microtime(true))];
                if(mon_get($request,'export',false)===true)$result['statistics_snapshot']=$stats;
            }
            elseif($api==='mon_artifact_read')$result=MonArtifact::chunk($store,$request);
            elseif($api==='mon_card_read')$result=MonArtifact::card($store,(string)mon_get($request,'sha256',''));
            else {
                $budget=MonExecution::resume($store,(string)mon_get($request,'run_context_id',''));
                $publication=new MonPublication($store,new MonHttp(),$cfg);
                if($api==='mon_run_status'){
                    $result=MonExecution::view($budget);$jobId=mon_get($budget->run,'job_id');
                    if(is_string($jobId)){$receipt=$publication->receipt($publication->load($jobId));MonExecution::event('publication_observed',['save_status'=>$receipt['save_status']]);
                        $result=MonExecution::view($budget);$result['publication']=$receipt;}
                }elseif($api==='mon_run_finish'){
                    $jobId=mon_get($budget->run,'job_id');
                    if(is_string($jobId)){$receipt=$publication->receipt($publication->load($jobId));MonExecution::event('publication_observed',['save_status'=>$receipt['save_status']]);}
                    MonExecution::measure('handler/control',mon_monotonic()-$mono);MonExecution::event('handler_measured');
                    $remaining=mon_get($request,'unfinished',[]);if(!is_array($remaining))throw new MonFault('MON_FINISH_INVALID');
                    $result=MonExecution::finish((string)mon_get($request,'reason','finished'),$remaining);
                    if(mon_get($request,'export',false)!==true)unset($result['statistics_snapshot']);
                }elseif($api==='mon_run_recover'){
                    $mode=mon_get($request,'publish_mode','manual');if(!in_array($mode,['manual','queue','inline'],true))throw new MonFault('MON_PUBLICATION_MODE_INVALID');
                    if($mode!=='manual'&&!preg_match('/^[a-f0-9]{40}$/',(string)mon_get($request,'expected_head','')))throw new MonFault('MON_EXPECTED_HEAD_INVALID');
                    $result=mon_recover_completion($store,$request,$budget);
                    if(isset($result['manifest'])){
                        if($mode!=='manual'){
                            $receipt=$publication->enqueue($result['manifest']['artifact_id'],(string)mon_get($request,'expected_head',''),$budget,$operator);
                            $publicationJobId=$receipt['job_id'];MonExecution::event('publication_queued',['save_status'=>$receipt['save_status'],'job_id'=>$receipt['job_id']]);
                            if($mode==='inline'){$publicationAttempted=true;$receipt=$publication->process($receipt['job_id'],microtime(true)+8);}
                            $publicationReceipt=$receipt;$result['publication']=$receipt;$result['save_status']=$receipt['save_status'];
                        }
                        if(mon_get($request,'response_mode','manifest')!=='inline'){$result['files']=[];$result['large_body_in_model']=false;}
                    }
                    $result['run']=MonExecution::view($budget);
                }elseif($api==='mon_operation_report'){MonExecution::postReport(mon_get($request,'report'));$result=['operation'=>MonExecution::operation($budget)];
                }elseif($api==='mon_operation_nas_check'){$result=MonExecution::checkNas(new MonNasConnection($store,$budget->run->run_context_id));
                }elseif($api==='mon_operation_verify_statistics'){$result=['operation'=>MonExecution::verifyStatistics($publication,microtime(true)+20)];
                }elseif(in_array($api,['mon_storage_compact','mon_archive_page','mon_archive_lookup'],true)){$context=MonRun::context(mon_get($request,'context'));$source=mon_run_source($request,'source_json',$context->source_blob_sha);
                    if(mon_get($context,'run_context_id')!==$budget->run->run_context_id)throw new MonFault('MON_OPERATION_SCOPE_REQUIRED');
                    $result=$api==='mon_storage_compact'?MonGaps::compact($source,$context->rendered_at):($api==='mon_archive_page'?MonGaps::archivePage($source,mon_get($request,'cursor')):MonGaps::archiveLookup($source,(string)mon_get($request,'key'),mon_get($request,'cursor')));unset($result['data']);
                }elseif($api==='mon_run_report'){
                    MonExecution::report(mon_get($request,'report'));$result=MonExecution::view($budget);
                }elseif($api==='mon_run_observe'){
                    $event=mon_get($request,'event');
                    if(!in_array($event,['initial_display_reported','intermediate_display_reported','final_display_reported','access_unconfirmed','deadline_missed'],true))throw new MonFault('MON_RUN_EVENT_INVALID');
                    $fields=(array)mon_get($request,'fields',new stdClass());
                    foreach($fields as $key=>$value)if(!is_scalar($value)&&$value!==null||is_string($value)&&strlen($value)>1000)throw new MonFault('MON_RUN_EVENT_INVALID');
                    // Display reports cannot turn a reused table into server-confirmed new analysis.
                    $fields=array_intersect_key($fields,array_flip(['symbol_id','code','attempts','recovery','previous_data','result_impact','next_check','reported_at']));
                    MonExecution::event($event,$fields);$result=MonExecution::view($budget);
                }elseif($api==='mon_publish_queue'){
                    $result=$publication->enqueue((string)mon_get($request,'artifact_id',''),(string)mon_get($request,'expected_head',''),$budget,$operator);
                    $publicationJobId=$result['job_id'];$publicationReceipt=$result;
                    MonExecution::event('publication_queued',['final_available'=>true,'save_status'=>$result['save_status'],'job_id'=>$result['job_id'],'artifact_id'=>$result['artifact_id']]);
                }else{
                    $id=(string)mon_get($request,'job_id','');$job=$publication->load($id);
                    if($job->run_context_id!==$budget->run->run_context_id||mon_get($budget->run,'job_id')!==$id)throw new MonFault('MON_PUBLICATION_RUN_MISMATCH');
                    if(mon_get($request,'resume',false)===true)$publication->resume($id);
                    $publicationJobId=$id;$publicationAttempted=true;
                    $result=$publication->process($id,min(microtime(true)+10,$budget->deadline('recovery')));
                    $publicationReceipt=$result;
                    MonExecution::event('publication_observed',['save_status'=>$result['save_status'],'job_id'=>$id]);
                }
            }
        }else {
            $select=in_array($api,['mon_swing_select','mon_detail_select','mon_detail_finalize'],true);
            $context=$select?mon_get(mon_get($request,'decision'),'view_context'):($api==='mon_patch'?mon_get(mon_get($request,'decision_patch'),'view_context'):mon_get($request,'context'));
            $c=MonRun::context($context);if(MonRun::seconds($c->rendered_at)>microtime(true)+5)throw new MonFault('MON_TIME_FUTURE');
            if(property_exists($c,'run_context_id')){
                $operator=MonOperator::check($cfg);MonExecution::resume($store,$c->run_context_id);MonPreparation::$store=$store;
                if(in_array($api,['mon_detail_request','mon_detail_requests','mon_discovery_plan','mon_discovery_acquire'],true))MonExecution::$active->guard('collection');
                if(in_array($api,['mon_detail_prepare','mon_swing_prepare','mon_swing_migrate','mon_patch'],true)||
                    ($select&&!($api==='mon_detail_finalize'&&is_string(mon_get(MonExecution::$active->run,'artifact_id')))))MonExecution::$active->guard('decision');
            }
            $data=mon_run_source($request,'source_json',$c->source_blob_sha);
            if($api==='mon_bootstrap')$result=MonBootstrap::build($data,$c->rendered_at,$c->source_blob_sha);
            elseif($api==='mon_detail_request')$result=MonDetails::register($data,$context,mon_get($request,'item'));
            elseif($api==='mon_detail_requests')$result=MonDetails::registerMany($data,$context,mon_get($request,'items'));
            elseif($api==='mon_discovery_plan')$result=MonDiscovery::plan($data,$context,mon_get($request,'items'));
            elseif($api==='mon_discovery_acquire')$result=MonDiscovery::acquire($data,$context,mon_get($request,'items'),mon_get($request,'evidence',[]));
            elseif($api==='mon_gap_plan')$result=MonGaps::plan($data,$c->rendered_at);
            elseif($api==='mon_detail_view')$result=MonDetails::view($data,$context);
            elseif($api==='mon_detail_prepare')$result=MonDetails::prepare($data,$context,mon_get($request,'live_observations',[]));
            elseif($select){
                $decision=mon_get($request,'decision');$fixed=MonRun::seconds(mon_get($decision,'fixed_as_of'));
                if($fixed>microtime(true)+5)throw new MonFault('MON_TIME_FUTURE');
                $decisionSha=hash('sha256',MonRun::json($decision));$prior=MonExecution::$active?mon_get(MonExecution::$active->run,'artifact_id'):null;
                if($api==='mon_detail_finalize'&&is_string($prior)){
                    $a=MonArtifact::load($store,$prior);
                    if(mon_get($a->metadata,'decision_sha256')!==$decisionSha||$a->source_blob_sha!==$c->source_blob_sha)throw new MonFault('MON_FINAL_DECISION_IMMUTABLE');
                    $result=['status'=>'reuse','write_required'=>false,'final_available'=>true,'save_status'=>MonExecution::$active->run->save_status,
                        'manifest'=>MonArtifact::manifest($a),'summary'=>$a->files[1]->content,'files'=>[]];
                    if(is_string(mon_get(MonExecution::$active->run,'job_id'))){$publication=new MonPublication($store,new MonHttp(),$cfg);$result['publication']=$publication->receipt($publication->load(MonExecution::$active->run->job_id));$result['save_status']=$result['publication']['save_status'];$result['manifest']=MonArtifact::manifest(MonArtifact::load($store,$result['publication']['artifact_id']));}
                }else $result=$api==='mon_swing_select'?MonSwing::select($data,$decision):MonDetails::finalize($data,$decision);
            }elseif($api==='mon_swing_prepare')$result=MonSwing::prepareView(MonSwing::prepare($data,$context,mon_get($request,'live_observations',[])));
            elseif($api==='mon_swing_migrate')$result=MonSwing::migrate($data,$context,mon_get($request,'history_updates'));
            elseif($api==='mon_view')$result=mon_project_for_mon($data,$context);
            else {
                $patch=mon_get($request,'decision_patch');if(MonRun::seconds(mon_get($patch,'fixed_as_of'))>microtime(true)+5)throw new MonFault('MON_TIME_FUTURE');
                $result=mon_apply_mon_patch($data,$patch);
            }
            if(property_exists($request,'latest_source_json')&&in_array($api,['mon_patch','mon_swing_migrate','mon_swing_select','mon_detail_select','mon_detail_finalize','mon_detail_request','mon_detail_requests','mon_discovery_acquire'],true)){
                $latest=mon_run_source($request,'latest_source_json',(string)mon_get($request,'latest_source_blob_sha',''));
                if($result['write_required']){
                    $candidate=mon_decode($result['files'][0]['content']);$merged=MonRun::rebase($data,$candidate,$latest);$text=MonRun::json($merged);
                    $result['files'][0]=['path'=>'mon_data.json','content'=>$text,'blob_sha'=>MonRun::gitSha($text)];
                    if(count($result['files'])===2){$summary=MonRun::render($merged,$merged->analysis->as_of);$result['files'][1]=['path'=>'mon_result.md','content'=>$summary,'blob_sha'=>MonRun::gitSha($summary)];$result['summary']=$summary;}
                    $result['validation']['rebase_source_blob_sha']=$request->latest_source_blob_sha;
                    // Artifact base must be the exact collection merged above.
                    if($api==='mon_detail_finalize'){$data=$latest;$c->source_blob_sha=$request->latest_source_blob_sha;}
                }else MonRun::rebase($data,$data,$latest);
            }
            if($api==='mon_view'&&mon_get($request,'all_pages',true)===true){
                $result['additional_views']=[];$cursor=$result['view']['page']['next_cursor'];
                while($cursor!==null){$next=clone $context;$next->cursor=$cursor;$next->state_token=$result['state_token'];$part=mon_project_for_mon($data,$next);
                    foreach(mon_get($part,'artifact_cards',new stdClass())as $sha=>$card)$result['artifact_cards']->$sha=$card;
                    $result['additional_views'][]=['model_input_bytes'=>$part['model_input_bytes'],'view'=>$part['view']];$cursor=$part['view']['page']['next_cursor'];}
            }
            if($api==='mon_detail_finalize'&&MonExecution::$active&&($result['write_required']||$result['status']==='no_change')){
                $operator=$operator??MonOperator::check($cfg);
                $source=property_exists($request,'latest_source_json')?$request->latest_source_json:$request->source_json;
                $unchanged=!$result['write_required'];
                if($unchanged){
                    // Even a revalidated no-change requires an actual file-pair remote check.
                    $summary=$result['summary'];$result['files']=[['path'=>'mon_data.json','content'=>$source,'blob_sha'=>MonRun::gitSha($source)],
                        ['path'=>'mon_result.md','content'=>$summary,'blob_sha'=>MonRun::gitSha($summary)]];
                    $result['write_required']=true;$result['status']='verification_pending';$result['analysis_changed']=false;
                }
                $result['manifest']=MonArtifact::create($store,$source,$result['files'],['decision_sha256'=>$decisionSha,'run_context_id'=>$c->run_context_id,
                    'received_at'=>MonExecution::$active->run->received_at,'countries'=>$c->countries,'prepared_fingerprint'=>mon_get($request->decision,'prepared_fingerprint')]);
                $mode=mon_get($request,'publish_mode','manual');if(!in_array($mode,['manual','queue','inline'],true))throw new MonFault('MON_PUBLICATION_MODE_INVALID');
                $result['save_status']='not_requested';
                MonExecution::event('final_prepared',['final_available'=>true,'new_analysis_complete'=>true,'analysis_origin'=>$unchanged?'revalidated_unchanged_decision':'secured_data_decision','save_status'=>'not_requested','artifact_id'=>$result['manifest']['artifact_id']]);
                if($mode!=='manual'){
                    $publication=new MonPublication($store,new MonHttp(),$cfg);$receipt=$publication->enqueue($result['manifest']['artifact_id'],(string)mon_get($request,'expected_head',''),MonExecution::$active,$operator);
                    $publicationJobId=$receipt['job_id'];$publicationReceipt=$receipt;
                    MonExecution::event('publication_queued',['save_status'=>$receipt['save_status'],'job_id'=>$receipt['job_id']]);
                    if($mode==='inline'){$publicationAttempted=true;$receipt=$publication->process($receipt['job_id'],min(microtime(true)+8,MonExecution::$active->deadline('publication')));$publicationReceipt=$receipt;}
                    $result['publication']=$receipt;$result['save_status']=$receipt['save_status'];
                    $result['manifest']=MonArtifact::manifest(MonArtifact::load($store,$receipt['artifact_id']));
                    MonExecution::event('publication_observed',['save_status'=>$receipt['save_status'],'job_id'=>$receipt['job_id']]);
                }
                if(mon_get($request,'response_mode','manifest')!=='inline'){$result['files']=[];$result['large_body_in_model']=false;}
            }elseif($api==='mon_detail_finalize'&&!MonExecution::$active&&mon_get($request,'response_mode','manifest')!=='inline'){
                throw new MonFault('MON_RUN_CONTEXT_REQUIRED');
            }
            if(mon_get($request,'response_mode','inline')==='manifest'){
                MonArtifact::detachCards($store,$result,static function()use($cfg,&$operator){$operator=$operator??MonOperator::check($cfg);});
            }
            if(isset($result['summary'])){
                $displayData=$data;
                if(!empty($result['files']))$displayData=mon_decode($result['files'][0]['content']);
                elseif(isset($result['manifest']))$displayData=mon_decode(MonArtifact::load($store,$result['manifest']['artifact_id'])->files[0]->content);
                $result['displayed_at']=mon_iso();$result['display_summary']=MonRun::render($displayData,$result['displayed_at']);
            }
        }
        if(MonExecution::$active&&!in_array($api,['mon_run_finish','mon_publish_process'],true)){
            $operation=in_array($api,['mon_detail_finalize','mon_publish_process'],true)?$api:(in_array($api,['mon_detail_prepare','mon_swing_prepare'],true)?'preparation':(in_array($api,['mon_detail_request','mon_detail_requests','mon_discovery_plan','mon_discovery_acquire'],true)?'collection':'control'));
            MonExecution::measure('handler/'.$operation,mon_monotonic()-$mono);MonExecution::event('handler_measured');
            if(in_array($api,['mon_run_start','mon_run_status','mon_run_observe','mon_run_report'],true))$result=array_merge($result,MonExecution::view(MonExecution::$active));
        }
        $timing=MonExecution::$active?MonExecution::$active->timing():['partial_timing'=>true,'received_at'=>null,'first_control_at'=>MonBudget::iso($start),'timing_basis'=>'handler_only'];
        echo mon_json(['ok'=>true,'api'=>$api,'processor_version'=>MON_VERSION,'handler_seconds'=>mon_monotonic()-$mono,'run_timing'=>$timing,'result'=>$result],false);return 0;
    }catch(MonFault $e){
        $code=$e->faultCode;
        if($code==='MON_METHOD_INVALID'){http_response_code(405);header('Allow: POST');}
        elseif($code==='MON_API_UNKNOWN')http_response_code(404);
        elseif($code==='MON_CONTENT_TYPE_INVALID')http_response_code(415);
        elseif($code==='MON_OPERATOR_AUTH_REQUIRED')http_response_code(403);
        elseif(in_array($code,['MON_OPERATOR_AUTH_UNCONFIGURED','MON_XZ_UNAVAILABLE','MON_CODEC_UNAVAILABLE','MON_CODEC_TIMEOUT','MON_CODEC_TEMP_FAILED'],true))http_response_code(503);
        elseif(in_array($code,['MON_BODY_TOO_LARGE','MON_STORAGE_LIMIT','MON_PAYLOAD_TOO_LARGE'],true))http_response_code(413);
        elseif(in_array($code,['MON_ANALYSIS_SOURCE_CONFLICT','MON_STATE_TOKEN_INVALID','MON_SOURCE_SHA_MISMATCH','MON_FINAL_DECISION_IMMUTABLE','MON_COMPLETION_PENDING','MON_RECOVERY_PUBLICATION_REQUIRED'],true))http_response_code(409);
        else http_response_code(400);
        if($publication&&is_string($publicationJobId))try{$publicationReceipt=$publication->receipt($publication->load($publicationJobId));}catch(Throwable $ignore){if($publicationAttempted)$publicationReceipt=['save_status'=>'unknown','job_id'=>$publicationJobId,'fully_complete'=>false];}
        try{MonExecution::measure('handler/control',mon_monotonic()-$mono,$code);MonExecution::event('failure',['code'=>$code,'result_impact'=>'publication_receipt_authoritative_no_substitute_selection']);}catch(Throwable $ignore){}
        $fallback=MonBootstrap::recover($data,$request,$store);
        echo mon_json(['ok'=>false,'api'=>$api,'processor_version'=>MON_VERSION,'error_code'=>$code,'write_required'=>false,
            'remote_written'=>mon_get($publicationReceipt,'save_status')==='saved'?true:(in_array(mon_get($publicationReceipt,'save_status'),['publishing','unknown'],true)?null:false),
            'publication'=>$publicationReceipt,'fallback_result'=>$fallback,'new_analysis_complete'=>MonExecution::$active?mon_get(MonExecution::$active->run,'new_analysis_complete',false):false,'fallback_summary'=>MonBootstrap::summary($fallback,$code),'handler_seconds'=>mon_monotonic()-$mono,
            'run_timing'=>MonExecution::$active?MonExecution::$active->timing():['partial_timing'=>true]],false);return 1;
    }catch(Throwable $e){
        try{MonExecution::measure('handler/control',mon_monotonic()-$mono,'MON_HANDLER_INTERNAL_ERROR');MonExecution::event('failure',['code'=>'MON_HANDLER_INTERNAL_ERROR']);}catch(Throwable $ignore){}
        $fallback=MonBootstrap::recover($data,$request,$store);
        http_response_code(500);echo mon_json(['ok'=>false,'api'=>$api,'processor_version'=>MON_VERSION,'error_code'=>'MON_HANDLER_INTERNAL_ERROR','write_required'=>false,'remote_written'=>$publicationAttempted?null:false,
            'publication'=>$publicationReceipt,'fallback_result'=>$fallback,'new_analysis_complete'=>MonExecution::$active?mon_get(MonExecution::$active->run,'new_analysis_complete',false):false,'fallback_summary'=>MonBootstrap::summary($fallback,'MON_HANDLER_INTERNAL_ERROR')],false);return 1;
    }
}

function mon_failure_summary($data,string $code): string {
    return MonBootstrap::summary(MonBootstrap::recover($data),$code);
}

function mon_web(): int {
    if (isset($_GET['api'])) return mon_run_web((string)$_GET['api']);
    header('Cache-Control: no-store');
    if(($_GET['view']??'')==='bootstrap'){
        header('Content-Type: application/json; charset=utf-8');
        try{
            if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new MonFault('MON_METHOD_INVALID');
            $cursor=(string)($_GET['cursor']??'0');if(!preg_match('/^\d{1,3}$/',$cursor))throw new MonFault('MON_BOOTSTRAP_CURSOR_INVALID');
            $store=new MonStore(getenv('MON_STATE_DIR')?:__DIR__);
            $result=MonBootstrap::fromStore($store,isset($_GET['at'])?(string)$_GET['at']:null,(int)$cursor,(string)($_GET['since']??''));
            $cfg=new MonConfig(getenv('MON_ENV_FILE')?:'',$store->dir);
            $state=mon_web_state($store,$cfg);$health=array_intersect_key($state,array_flip(['version','running','heartbeat_fresh','readiness','monitoring_active','restart_required','boot_verified','watchlist_loaded','watched']));
            $progress=$state['detail_progress'];unset($progress['cards']);$health['detail_progress']=$progress;$health['heartbeat_at']=mon_get($state['status'],'heartbeat_at');$health['repair_job']=MonProgress::nextRepair($store->read('remote_cache.json'),$store->read('detail_pending.json'),time());
            echo mon_json(['ok'=>true,'processor_version'=>MON_VERSION,'capabilities'=>['detail_codec'=>MonDetails::CODEC,'operation_completion'=>'MON-OPERATION-COMPLETION-1.0','archive'=>'MON-GAP-ARCHIVE-2.0','chatgpt_bridge'=>class_exists('MonChatGPTBridge')?MonChatGPTBridge::CONTRACT:null],
                'nas_state'=>$health,'result'=>$result],false);return 0;
        }catch(MonFault $e){http_response_code($e->faultCode==='MON_METHOD_INVALID'?405:400);if($e->faultCode==='MON_METHOD_INVALID')header('Allow: GET');echo mon_json(['ok'=>false,'error_code'=>$e->faultCode],false);return 1;}
        catch(Throwable $e){http_response_code(500);echo mon_json(['ok'=>false,'error_code'=>'MON_BOOTSTRAP_INTERNAL_ERROR'],false);return 1;}
    }
    if(($_GET['view']??'')==='progress'){
        header('Content-Type: application/json; charset=utf-8');
        try{
            if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new MonFault('MON_METHOD_INVALID');
            if(isset($_GET['stage'])){
                if($_GET['stage']!=='1')throw new MonFault('MON_STEP_QUERY_INVALID');$store=new MonStore(getenv('MON_STATE_DIR')?:__DIR__);
                echo mon_json(['ok'=>true,'step'=>MonDetailStep::view($store,time(),(string)($_GET['since']??''))],false);return 0;
            }
            $cursor=(string)($_GET['cursor']??'0');if(!preg_match('/^\d{1,3}$/',$cursor))throw new MonFault('MON_PROGRESS_CURSOR_INVALID');
            $store=new MonStore(getenv('MON_STATE_DIR')?:__DIR__);$progress=MonProgress::cached($store->read('remote_cache.json'),$store->read('detail_pending.json'),(string)($_GET['since']??''),(int)$cursor);
            echo mon_json(['ok'=>true,'progress'=>$progress],false);return 0;
        }catch(MonFault $e){http_response_code($e->faultCode==='MON_METHOD_INVALID'?405:400);if($e->faultCode==='MON_METHOD_INVALID')header('Allow: GET');echo mon_json(['ok'=>false,'error_code'=>$e->faultCode],false);return 1;}
        catch(Throwable $e){http_response_code(500);echo mon_json(['ok'=>false,'error_code'=>'MON_PROGRESS_INTERNAL_ERROR'],false);return 1;}
    }
    $json=($_GET['view']??'')==='status' || strpos($_SERVER['HTTP_ACCEPT']??'','application/json')!==false;
    $notice='';$ok=true;$data=null;$path='';
    try {
        $dir=getenv('MON_STATE_DIR')?:__DIR__; $store=new MonStore($dir); $envFile=getenv('MON_ENV_FILE')?:'';
        $cfg=new MonConfig($envFile,$dir); $path=$cfg->get('PHP_CLI');
        if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
            $action=$_POST['action']??'';
            if ($action==='start') $notice=mon_web_start($store,$cfg);
            elseif ($action==='stop') $notice=mon_web_stop($store);
            elseif ($action==='save_settings') { $notice=mon_web_save($store,$cfg,$_POST);$cfg=new MonConfig($envFile,$dir);$path=$cfg->get('PHP_CLI'); }
            else throw new MonFault('WEB_ACTION_INVALID');
        }
        $data=mon_web_state($store,$cfg);
    } catch (MonFault $e) { $ok=false;$notice=mon_web_error($e->faultCode);$fault=$e->faultCode; }
    catch (Throwable $e) { $ok=false;$notice=mon_web_error('WEB_INTERNAL_ERROR');$fault='WEB_INTERNAL_ERROR'; }
    if (!$ok) {
        http_response_code(isset($fault) && in_array($fault,['WEB_INPUT_INVALID','WEB_ACTION_INVALID'],true)?400:503);
        if (isset($store,$cfg)) { try{$data=mon_web_state($store,$cfg);}catch(Throwable $ignore){} }
    }
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo mon_json(['ok'=>$ok,'message'=>$notice,'error_code'=>$fault??null,'data'=>$data]); return $ok?0:1;
    }
    header('Content-Type: text/html; charset=utf-8');
    if ($data===null) $data=['running'=>false,'label'=>'상태 확인 필요','message'=>$notice,'watched'=>0,'collected'=>0,'fresh_quotes'=>0,'watchlist_loaded'=>false,'monitoring_active'=>false,'readiness'=>'unknown','connection'=>['github'=>false,'kis'=>false],'last_mirrored_at'=>null,'heartbeat_fresh'=>false];
    mon_web_html($data,$notice,$ok,$path); return $ok?0:1;
}
function mon_main(array $argv): int {
    if (PHP_SAPI!=='cli') return mon_web();
    $mode='status';$launchTicket='';$nasRun=''; $envFile=getenv('MON_ENV_FILE')?:''; $dir=getenv('MON_STATE_DIR')?:__DIR__; $maxTicks=0; $instance=bin2hex(random_bytes(12));
    foreach (array_slice($argv,1) as $arg) {
        if (in_array($arg,['--run','--daemon','--once','--status','--stop','--check','--help','--nas-check'],true)) $mode=substr($arg,2);
        elseif (strpos($arg,'--env=')===0) $envFile=substr($arg,6);
        elseif (strpos($arg,'--state-dir=')===0) $dir=substr($arg,12);
        elseif (preg_match('/^--max-ticks=(\d+)$/',$arg,$m)) $maxTicks=(int)$m[1];
        elseif (preg_match('/^--instance=([a-f0-9]{24})$/',$arg,$m)) $instance=$m[1];
        elseif (preg_match('/^--launch-ticket=([a-f0-9]{64})$/',$arg,$m)) $launchTicket=$m[1];
        elseif (preg_match('/^--run-id=([A-Za-z0-9._:-]{1,96})$/',$arg,$m)) $nasRun=$m[1];
        else throw new MonFault('CLI_OPTION_INVALID');
    }
    if ($mode==='help') {
        echo 'mon.php '.MON_VERSION." (PHP 7.4+)\n시작은 웹 시작 버튼만 허용합니다. NAS 부팅 뒤 자동 시작하지 않습니다.\n옵션 없음 / --status: 상태, --stop: 중지, --check: 설정 확인\n--nas-check --run-id=ID: 한 번의 읽기 전용 NAS 접속/복구 상태 조회; retry_in_seconds 뒤 같은 ID로 계속합니다.\n--daemon / --once는 사용할 수 없으며 --run은 일회용 웹 시작 권한이 필요합니다.\n";return 0;
    }
    if(in_array($mode,['daemon','once'],true)||($mode==='run'&&$launchTicket===''))throw new MonFault('MANUAL_START_REQUIRED');
    $store=new MonStore($dir);
    if($mode==='nas-check'){
        if($nasRun==='')throw new MonFault('NAS_RUN_ID_REQUIRED');
        echo mon_json((new MonNasConnection($store,$nasRun))->poll(),false);return 0;
    }
    if($nasRun!=='')throw new MonFault('CLI_OPTION_INVALID');
    if ($mode==='check') {
        $cfg=new MonConfig($envFile,$dir);
        echo mon_json(['php'=>PHP_VERSION,'curl'=>extension_loaded('curl'),'pcntl'=>function_exists('pcntl_fork'),'github_token_present'=>$cfg->get('GITHUB_TOKEN')!=='','kis_credentials_present'=>$cfg->get('KIS_APP_KEY')!==''&&$cfg->get('KIS_APP_SECRET')!=='','repository'=>$cfg->get('MON_REPOSITORY','wskimgit/stock'),'branch'=>$cfg->get('MON_BRANCH','main'),'state_directory'=>$store->dir,'state_directory_writable'=>is_writable($dir)]); return 0;
    }
    if ($mode==='status') { echo mon_json(mon_status($store)); return 0; }
    if ($mode==='stop') {echo mon_web_stop($store)."\n";return 0;}
    (new MonManualStart($store))->validate($instance,$launchTicket);
    if (!extension_loaded('curl')) throw new MonFault('PHP_CURL_REQUIRED');
    return (new MonDaemon($store,$envFile,$instance,$launchTicket))->run($maxTicks);
}

// Optional, isolated GitHub mailbox bridge. Legacy MON remains functional when absent.
if(is_file(__DIR__ . '/mon_chatgpt_bridge.php'))require_once __DIR__ . '/mon_chatgpt_bridge.php';

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try { exit(mon_main($argv??[])); }
    catch (MonFault $e) { if (defined('STDERR')) fwrite(STDERR,$e->faultCode."\n"); exit($e->faultCode==='ALREADY_RUNNING'?0:1); }
    catch (Throwable $e) { if (defined('STDERR')) fwrite(STDERR,"MON_START_FAILED\n"); exit(1); }
}


<?php
/**
 * MON 2.2.3 Lite — clean-start, single-file, fixed-runtime, completed-daily swing monitor.
 * PHP 7.4+ / manual web start only / no cron / no orders.
 * Runtime: /web/mon_runtime with a small fixed file set.
 */
declare(strict_types=1);

const MON_LITE_VERSION = '2.2.3';
const MON_LITE_BUILD = 'MON-LITE-2.2.3-20261017-r1';
const MON_LITE_POLICY = 'MON-LITE-OPS-2.2.3';
const MON_LITE_RUNTIME = __DIR__ . '/mon_runtime';
const MON_LITE_GITHUB_REPO = 'wskimgit/stock';
const MON_LITE_GITHUB_BRANCH = 'main';
const MON_LITE_PREFIX = "<?php exit; ?>\n";

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
function lm_git_blob_sha(string $bytes): string { return sha1('blob '.strlen($bytes)."\0".$bytes); }
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
        self::$readyDir=$d;return $d;
    }
    public static function path(string $name): string {
        if(!preg_match('/^[A-Za-z0-9_.-]{1,80}$/',$name))throw new LiteMonFault('STATE_NAME_INVALID');return self::ready().'/'.$name;
    }
    private static function decodeFile(string $raw) {if(strpos($raw,MON_LITE_PREFIX)===0)$raw=substr($raw,strlen(MON_LITE_PREFIX));return lm_decode($raw);}
    public static function read(string $key) {
        if(!in_array($key,self::FILES,true))throw new LiteMonFault('STATE_NAME_INVALID');$p=self::path($key.'.php');if(!is_file($p))return new stdClass();
        $n=@filesize($p);if($n===false||$n>self::MAX_FILE)throw new LiteMonFault('RUNTIME_FILE_SIZE_LIMIT');$raw=@file_get_contents($p);if(!is_string($raw))throw new LiteMonFault('RUNTIME_READ_FAILED');return self::decodeFile($raw);
    }
    public static function write(string $key,$value): bool {
        if(!in_array($key,self::FILES,true))throw new LiteMonFault('STATE_NAME_INVALID');$raw=MON_LITE_PREFIX.lm_json($value);if(strlen($raw)>self::MAX_FILE)throw new LiteMonFault('RUNTIME_FILE_SIZE_LIMIT');
        $p=self::path($key.'.php');if(is_file($p)){$current=@file_get_contents($p);if(is_string($current)&&hash_equals(hash('sha256',$current),hash('sha256',$raw)))return false;}
        self::capacity(strlen($raw));$tmp=self::path('.write.tmp');self::allowNewFile($tmp);
        if(@file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw))throw new LiteMonFault('RUNTIME_WRITE_FAILED');@chmod($tmp,0600);
        if(!@rename($tmp,$p)){@unlink($tmp);throw new LiteMonFault('RUNTIME_RENAME_FAILED');}@chmod($p,0600);return true;
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
    public static function sample(): array {
        $load=function_exists('sys_getloadavg')?@sys_getloadavg():false;$load1=is_array($load)?(float)$load[0]:null;$stat=(string)@file_get_contents('/proc/stat');$cores=preg_match_all('/^cpu\d+\s/m',$stat,$m);$cores=max(1,(int)$cores);$mem=(string)@file_get_contents('/proc/meminfo');$total=null;$avail=null;if(preg_match('/^MemTotal:\s+(\d+)\s+kB/m',$mem,$m))$total=(int)$m[1]*1024;if(preg_match('/^MemAvailable:\s+(\d+)\s+kB/m',$mem,$m))$avail=(int)$m[1]*1024;elseif(preg_match('/^MemFree:\s+(\d+)\s+kB/m',$mem,$m))$avail=(int)$m[1]*1024;
        // Lite keeps a small fixed runtime. 64 MiB was too conservative on DS213-class systems;
        // keep a 32 MiB / 6% floor and retry later rather than skipping the day.
        $floor=max(32*1048576,(int)(($total??0)*.06));$loadOk=$load1===null||$load1<=1.5*$cores;$memOk=$avail===null||$avail>=$floor;
        return ['cores'=>$cores,'load1'=>$load1,'memory_available_bytes'=>$avail,'memory_floor_bytes'=>$floor,'memory_mode'=>$avail!==null&&$avail<48*1048576?'low':'normal','heavy_allowed'=>$loadOk&&$memOk,'reason'=>!$memOk?'memory':(!$loadOk?'load':null)];
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
        $s=LiteRuntime::read('settings');$v=lm_get($s,'github_token');if(is_string($v)&&trim($v)!=='')$this->githubToken=trim($v);$v=lm_get($s,'php_cli');if(is_string($v)&&trim($v)!=='')$this->phpCli=trim($v);if($this->phpCli==='')$this->phpCli=self::discoverCli();
    }
}

final class LiteHealth {
    public static function local(LiteConfig $cfg): array {
        $blockers=[];$warnings=[];
        if(PHP_VERSION_ID<70400)$blockers[]='PHP_VERSION_UNSUPPORTED';
        if(!extension_loaded('curl'))$blockers[]='PHP_CURL_REQUIRED';
        if($cfg->githubToken==='')$blockers[]='GITHUB_TOKEN_MISSING';
        if($cfg->phpCli===''||!is_file($cfg->phpCli)||!is_executable($cfg->phpCli))$blockers[]='PHP_CLI_NOT_FOUND';
        if(!function_exists('exec'))$blockers[]='WEB_PROCESS_LAUNCH_DISABLED';
        try{$runtime=LiteRuntime::view();LiteRuntime::capacity(0);}catch(Throwable $e){$runtime=['path'=>MON_LITE_RUNTIME];$blockers[]=$e instanceof LiteMonFault?$e->faultCode:'RUNTIME_HEALTH_FAILED';}
        $resource=LiteResource::sample();if(!$resource['heavy_allowed'])$warnings[]='RESOURCE_PRESSURE_'.strtoupper((string)$resource['reason']);
        return ['contract'=>'MON-LITE-NAS-HEALTH-1.0','policy'=>MON_LITE_POLICY,'version'=>MON_LITE_VERSION,'php_version'=>PHP_VERSION,'php_sapi'=>PHP_SAPI,'curl'=>extension_loaded('curl'),'exec'=>function_exists('exec'),'php_cli'=>$cfg->phpCli,'github_configured'=>$cfg->githubToken!=='','runtime'=>$runtime,'resource'=>$resource,'blockers'=>array_values(array_unique($blockers)),'warnings'=>array_values(array_unique($warnings)),'ready_to_start'=>count($blockers)===0];
    }
}

final class LiteHttp {
    private $cfg;
    private $handles=[];
    private $githubTurn=[];
    private $githubKnown=[];
    private $stats=[];
    public function __construct(LiteConfig $cfg){$this->cfg=$cfg;$this->resetStats();}
    public function __destruct(){foreach($this->handles as $ch)if($ch)@curl_close($ch);$this->handles=[];}
    public function clearRequestCache(): void {$this->githubTurn=[];}
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
        while(true){$attempt++;$responseHeaders=[];$ch=$this->handle($url);$opts=[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_CONNECTTIMEOUT=>min(4,$timeout),CURLOPT_TIMEOUT=>$timeout,CURLOPT_USERAGENT=>'MON-Lite/'.MON_LITE_VERSION,CURLOPT_ENCODING=>''];
            if(defined('CURLOPT_TCP_KEEPALIVE'))$opts[CURLOPT_TCP_KEEPALIVE]=1;if(defined('CURL_HTTP_VERSION_2TLS'))$opts[CURLOPT_HTTP_VERSION]=CURL_HTTP_VERSION_2TLS;
            if($method!=='GET')$opts[CURLOPT_CUSTOMREQUEST]=$method;if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;if($headers)$opts[CURLOPT_HTTPHEADER]=$headers;
            $opts[CURLOPT_HEADERFUNCTION]=static function($ch,string $line)use(&$responseHeaders){$n=strlen($line);$p=strpos($line,':');if($p!==false){$k=strtolower(trim(substr($line,0,$p)));$v=trim(substr($line,$p+1));if($k!=='')$responseHeaders[$k]=$v;}return $n;};curl_setopt_array($ch,$opts);
            $raw=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$wire=(float)curl_getinfo($ch,CURLINFO_SIZE_DOWNLOAD);$newConnections=defined('CURLINFO_NUM_CONNECTS')?(int)curl_getinfo($ch,CURLINFO_NUM_CONNECTS):0;$this->stats['http_requests']++;$this->stats['wire_bytes_received']+=(int)round($wire);$this->stats['new_connections']+=$newConnections;if(is_string($raw))$this->stats['decoded_body_bytes']+=strlen($raw);
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
        if($r['status']===404){$this->githubKnown[$path]=null;$this->githubTurn[$path]=null;return null;}
        if($r['status']===304){$this->stats['http_304']++;if(!is_array($known)||!isset($known['content']))throw new LiteMonFault('GITHUB_304_WITHOUT_CACHE');$this->githubTurn[$path]=$known;return $known;}
        $j=lm_decode($r['body']);$sha=(string)lm_get($j,'sha','');$content=lm_get($j,'content');$b=is_string($content)&&$content!==''?base64_decode(str_replace(["\r","\n"],'',$content),true):false;
        if(!is_string($b)){$rawHeaders=['Accept: application/vnd.github.raw+json','Authorization: Bearer '.$this->cfg->githubToken];$b=$this->get($url,$rawHeaders,12);}
        $item=['content'=>$b,'sha'=>$sha,'commit_sha'=>null,'etag'=>self::headerValue($r['headers'],'etag')];$this->githubKnown[$path]=$item;$this->githubTurn[$path]=$item;return $item;
    }
    public function githubPut(string $path,string $content,string $message,?string $oldSha=null): array {
        if($this->cfg->githubToken==='')throw new LiteMonFault('GITHUB_TOKEN_MISSING');$url='https://api.github.com/repos/'.MON_LITE_GITHUB_REPO.'/contents/'.$path;$payload=['message'=>$message,'content'=>base64_encode($content),'branch'=>MON_LITE_GITHUB_BRANCH];if($oldSha!==null)$payload['sha']=$oldSha;
        // PUT is intentionally not auto-retried: a transport failure can occur after GitHub committed the write.
        try{$r=$this->request('PUT',$url,$this->headers(true),lm_json($payload),12,0);}catch(LiteMonFault $e){if(strpos($e->faultCode,'HTTP_')===0&&strpos($e->faultCode,'HTTP_TRANSPORT_')!==0)throw new LiteMonFault('GITHUB_WRITE_'.substr($e->faultCode,5));throw $e;}
        $j=lm_decode($r['body']);$blob=(string)lm_get(lm_get($j,'content'),'sha','');$commit=(string)lm_get(lm_get($j,'commit'),'sha','');if($blob===''||!hash_equals(lm_git_blob_sha($content),$blob))throw new LiteMonFault('GITHUB_BLOB_VERIFY_FAILED');
        // Keep the verified body but discard an old validator so the next forced readback must contact GitHub for fresh representation metadata.
        $item=['content'=>$content,'sha'=>$blob,'commit_sha'=>$commit,'etag'=>null];$this->githubKnown[$path]=$item;$this->githubTurn[$path]=$item;return ['blob_sha'=>$blob,'commit_sha'=>$commit,'unchanged'=>false];
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
    public static function analyzeMapped(array $symbol,array $bars,array $bm): array {
        $b=self::bars($bars);$n=count($b);if($n<60)throw new LiteMonFault('HISTORY_MISSING');$start=$n-21;$sum10=0.0;$sum20=0.0;$adv=0.0;$low5=INF;$resistance=-INF;$firstDate=$b[$start][0];$lastDate=$b[$n-1][0];
        for($i=$start;$i<$n;$i++){if(!isset($bm[$b[$i][0]]))throw new LiteMonFault('BENCHMARK_DATE_MISSING');if($i>=$n-20){$sum20+=$b[$i][4];$adv+=$b[$i][4]*$b[$i][5];}if($i>=$n-10)$sum10+=$b[$i][4];if($i>=$n-5)$low5=min($low5,$b[$i][3]);if($i<$n-1)$resistance=max($resistance,$b[$i][2]);}
        $tech=self::technicalBars($b);$last=$b[$n-1][4];$ma10=$sum10/10;$ma20=$sum20/20;$support=max(array_filter([$tech['ma5'],$ma10,$ma20,$low5],function($x)use($last){return $x<=$last;}));$atr=$tech['atr14'];$plans=[];if($atr>0)foreach([['pullback',$support-$atr,$support+$atr,min($low5,$support)-.5*$atr],['breakout',$resistance,$resistance+$atr,$resistance-$atr]]as $q){$lo=self::roundTick($symbol,$q[1],true);$hi=self::roundTick($symbol,$q[2],false);$stop=self::roundTick($symbol,$q[3],false);if($lo!==null&&$hi!==null&&$stop!==null&&$stop>0&&$stop<$lo&&$lo<=$hi)$plans[]=['mode'=>$q[0],'entry_zone'=>[$lo,$hi],'invalidation'=>$stop,'planned_risk_pct'=>lm_round6(100*($hi-$stop)/$hi)];}$stockR=$last/$b[$start][4]-1;$marketR=$bm[$lastDate]/$bm[$firstDate]-1;$metrics=['last_close'=>$last,'bars_as_of'=>$lastDate,'R20'=>lm_round6($stockR),'RS20_pp'=>lm_round6(100*($stockR-$marketR)),'ADV20'=>lm_round6($adv/20),'MA5'=>$tech['ma5'],'MA10'=>lm_round6($ma10),'MA20'=>lm_round6($ma20),'ATR14'=>$atr,'S'=>lm_round6($support),'R'=>lm_round6($resistance)];$eligible=$metrics['RS20_pp']>0&&$tech['technical_qualified'];return ['symbol_id'=>$symbol['symbol_id'],'country'=>$symbol['country'],'name'=>$symbol['name'],'currency'=>$symbol['currency'],'bars_as_of'=>$lastDate,'metrics'=>$metrics,'technical'=>$tech,'entry_plans'=>$plans,'eligible'=>$eligible,'status'=>$eligible?'pass':'near','reason_codes'=>array_values(array_filter([$metrics['RS20_pp']<=0?'not_stronger_than_market':null,!$tech['technical_qualified']?'no_swing_signal':null]))];
    }
}

final class LiteYahoo {
    const RANGE_CACHE_BARS=40;
    private $http;private $rangeCache=[];private $stats=[];
    public function __construct(LiteHttp $http){$this->http=$http;$this->beginTurn();}
    public function beginTurn(): void {$this->rangeCache=[];$this->stats=['yahoo_range_requests'=>0,'yahoo_range_cache_hits'=>0];}
    public function stats(): array {return $this->stats;}

    public static function ticker(array $s): string {if(isset($s['raw_ticker']))return (string)$s['raw_ticker'];if($s['country']==='KR')return $s['symbol'].($s['exchange']==='KOSDAQ'?'.KQ':'.KS');if($s['country']==='JP')return $s['symbol'].'.T';return $s['symbol'];}
    // Yahoo daily OHLC/Close is already split-adjusted; never apply split events a second time.
    private static function completed(array $rows,string $country): array {
        if(!$rows)return $rows;$tz=new DateTimeZone(['KR'=>'Asia/Seoul','JP'=>'Asia/Tokyo','US'=>'America/New_York'][$country]);$now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone($tz);$today=$now->format('Y-m-d');$cut=['KR'=>'16:00','JP'=>'16:00','US'=>'16:30'][$country];$last=end($rows);if($last[0]===$today&&$now->format('H:i')<$cut)array_pop($rows);return $rows;
    }
    private function fetchRange(array $s,int $p1,int $p2): array {
        $tickerRaw=self::ticker($s);$p2Day=gmdate('Y-m-d',$p2);$cached=$this->rangeCache[$tickerRaw]??null;
        if(is_array($cached)&&$cached['p2_day']===$p2Day&&$cached['p1']<=$p1){$this->stats['yahoo_range_cache_hits']++;$from=gmdate('Y-m-d',$p1);return array_values(array_filter($cached['rows'],static function($r)use($from){return $r[0]>=$from;}));}
        $ticker=rawurlencode($tickerRaw);$url='https://query1.finance.yahoo.com/v8/finance/chart/'.$ticker.'?period1='.$p1.'&period2='.$p2.'&interval=1d&events=splits&includeAdjustedClose=false';$this->stats['yahoo_range_requests']++;$j=lm_decode($this->http->get($url,[],8));$r=lm_get(lm_get(lm_get($j,'chart'),'result'),0);if(!$r)throw new LiteMonFault('YAHOO_EMPTY');$m=lm_get($r,'meta');$cur=(string)lm_get($m,'currency');if($cur!==$s['currency'])throw new LiteMonFault('YAHOO_CURRENCY_MISMATCH');$ts=lm_get($r,'timestamp',[]);$q=lm_get(lm_get(lm_get($r,'indicators'),'quote'),0);if(!is_array($ts)||!is_object($q))throw new LiteMonFault('YAHOO_ROWS_INVALID');$rows=[];foreach($ts as $i=>$t){$o=lm_num(lm_get($q,'open',[])[$i]??null);$h=lm_num(lm_get($q,'high',[])[$i]??null);$l=lm_num(lm_get($q,'low',[])[$i]??null);$c=lm_num(lm_get($q,'close',[])[$i]??null);$v=lm_num(lm_get($q,'volume',[])[$i]??null);if($o===null||$h===null||$l===null||$c===null||$v===null)continue;$date=gmdate('Y-m-d',(int)$t);$rows[]=['_ts'=>(int)$t,0=>$date,1=>$o,2=>$h,3=>$l,4=>$c,5=>$v];}$rows=self::completed(LiteSwing::bars($rows),$s['country']);
        $cacheRows=array_slice($rows,-self::RANGE_CACHE_BARS);$cacheP1=$cacheRows?strtotime($cacheRows[0][0].' 00:00:00 UTC'):$p1;if($cacheP1===false)$cacheP1=$p1;if(!is_array($cached)||$cacheP1<$cached['p1'])$this->rangeCache[$tickerRaw]=['p1'=>$cacheP1,'p2_day'=>$p2Day,'rows'=>$cacheRows];return $rows;
    }
    public function daily(array $s,int $days=220): array {
        $p2=time()+86400;$calendarDays=(int)ceil($days*1.5)+45;$p1=$p2-$calendarDays*86400;return array_slice($this->fetchRange($s,$p1,$p2),-$days);
    }
    public function recent(array $s,string $lastDate,int $overlapDays=21): array {
        $t=strtotime($lastDate.' 00:00:00 UTC');if($t===false)throw new LiteMonFault('INCREMENTAL_DATE_INVALID');$p1=$t-max(7,$overlapDays)*86400;$p2=time()+86400;return $this->fetchRange($s,$p1,$p2);
    }
    public function benchmark(string $country,?string $exchange=null,int $days=220): array {
        if($country==='KR')$ticker=$exchange==='KOSDAQ'?'^KQ11':'^KS11';elseif($country==='US')$ticker='^GSPC';else $ticker='^TOPX';$s=['symbol_id'=>$country.'|INDEX|'.$ticker,'symbol'=>$ticker,'raw_ticker'=>$ticker,'country'=>$country,'exchange'=>$country==='JP'?'TSE':($country==='KR'?($exchange==='KOSDAQ'?'KOSDAQ':'KOSPI'):'NYSE'),'currency'=>['KR'=>'KRW','US'=>'USD','JP'=>'JPY'][$country],'name'=>$ticker];return $this->daily($s,$days);
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
    public function __construct(){LiteRuntime::ready();if(function_exists('proc_nice'))@proc_nice(10);$limit=trim((string)ini_get('memory_limit'));if($limit===''||$limit==='-1'||preg_match('/^(\d+)M$/i',$limit,$mm)&&((int)$mm[1])>96)@ini_set('memory_limit','96M');$this->cfg=new LiteConfig();$this->http=new LiteHttp($this->cfg);$this->yahoo=new LiteYahoo($this->http);}
    public static function nextFullAt(?int $now=null): string {$now=$now??time();$tz=new DateTimeZone('Asia/Seoul');$d=(new DateTimeImmutable('@'.$now))->setTimezone($tz);$target=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$d->format('Y-m-d').' 16:40',$tz);if(!$target)throw new LiteMonFault('SCHEDULE_INVALID');if($target->getTimestamp()<=$now)$target=$target->modify('+1 day');while((int)$target->format('N')>5)$target=$target->modify('+1 day');return lm_iso($target->getTimestamp());}
    public static function defaultState(): stdClass {return (object)['contract'=>'MON-LITE-STATE-1.0','policy'=>MON_LITE_POLICY,'version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'running'=>false,'stop_requested'=>false,'heartbeat_at'=>null,'phase'=>'IDLE','last_light_check'=>null,'last_full_scan'=>null,'next_light_check'=>null,'next_full_scan'=>null,'last_mailbox_check'=>null,'next_mailbox_check'=>null,'last_mailbox_error'=>null,'publication_pending'=>false,'publication_fingerprint'=>null,'last_publication_error'=>null,'last_error'=>null,'progress'=>null,'last_publish_fingerprint'=>null,'last_scan_mode'=>null,'last_network_stats'=>null];}
    public function state(): stdClass {$s=LiteRuntime::read('state');if(lm_get($s,'contract')!=='MON-LITE-STATE-1.0'||lm_get($s,'policy')!==MON_LITE_POLICY)$s=self::defaultState();return $s;}
    public function saveState($s,bool $allowStopReset=false): void {if(!$allowStopReset){$disk=LiteRuntime::read('state');if(lm_get($disk,'stop_requested')===true)$s->stop_requested=true;}LiteRuntime::write('state',$s);}
    private function heartbeat($s,string $phase,?string $err=null): void {$s->heartbeat_at=lm_iso();$s->phase=$phase;$s->last_error=$err;$this->saveState($s);}
    private function cachedWatchlist(): array {$c=$this->cacheObj();$rows=[];foreach((array)lm_get($c,'watchlist',[]) as $x){$a=(array)$x;if(isset($a['symbol_id']))$rows[]=$a;}return $rows;}
    private static function symbolFromId(string $sid,$src=null): ?array {$p=explode('|',$sid);if(count($p)!==3||!in_array($p[0],['KR','US','JP'],true)||$p[1]===''||$p[2]==='')return null;$cc=['KR'=>'KRW','US'=>'USD','JP'=>'JPY'];return ['symbol_id'=>$sid,'country'=>$p[0],'exchange'=>$p[1],'symbol'=>$p[2],'name'=>(string)lm_get($src,'name',$p[2]),'currency'=>(string)lm_get($src,'currency',$cc[$p[0]])];}
    private function handoffWatchlist(): array {$m=$this->http->githubGet('mon_chatgpt_handoff.json');if(!$m||trim((string)$m['content'])==='')return [];$q=lm_decode($m['content']);if(lm_get($q,'contract')!=='MON-LITE-BRIDGE-1.0'||lm_get($q,'policy')!==MON_LITE_POLICY||lm_get($q,'stage')!=='candidates')return [];$ids=lm_get($q,'candidate_ids',[]);$rows=[];if(is_array($ids))foreach($ids as $sid){if(!is_string($sid))continue;$a=self::symbolFromId($sid);if($a)$rows[$sid]=$a;}return array_values($rows);}
    private function loadRemoteWatchlist(bool $preferCache=false): array {
        if($preferCache){$cached=$this->cachedWatchlist();if($cached)return $cached;}
        $rows=[];$g=$this->http->githubGet('mon_data.json');if($g&&trim((string)$g['content'])!==''){try{$d=lm_decode($g['content']);if(lm_get($d,'contract')==='MON-LITE-DATA-2.2'&&lm_get($d,'policy')===MON_LITE_POLICY){$w=lm_get(lm_get($d,'watchlist'),'symbols',[]);if(is_array($w))foreach($w as $x){$sid=(string)lm_get($x,'symbol_id');$a=self::symbolFromId($sid,$x);if($a)$rows[$sid]=$a;}}}catch(LiteMonFault $ignore){}}
        if(!$rows)foreach($this->cachedWatchlist() as $x)$rows[$x['symbol_id']]=$x;if(!$rows)foreach($this->handoffWatchlist() as $x)$rows[$x['symbol_id']]=$x;if(!$rows)throw new LiteMonFault('WATCHLIST_EMPTY_WAITING_FOR_CANDIDATES');return array_values($rows);
    }
    private function cacheObj(): stdClass {$c=LiteRuntime::read('cache');if(!in_array(lm_get($c,'contract'),['MON-LITE-CACHE-1.0','MON-LITE-CACHE-1.1','MON-LITE-CACHE-1.2'],true))$c=(object)['contract'=>'MON-LITE-CACHE-1.2','watchlist'=>[],'symbols'=>new stdClass(),'benchmarks'=>new stdClass(),'benchmark_meta'=>new stdClass(),'last_full_resync_at'=>null,'updated_at'=>null];$c->contract='MON-LITE-CACHE-1.2';if(!property_exists($c,'symbols')||!is_object($c->symbols))$c->symbols=new stdClass();foreach(get_object_vars($c->symbols) as $sid=>$row)if($row instanceof stdClass&&lm_get($row,'analysis') instanceof stdClass)$row->analysis=lm_array($row->analysis);if(!property_exists($c,'benchmarks')||!is_object($c->benchmarks))$c->benchmarks=new stdClass();if(!property_exists($c,'last_full_resync_at'))$c->last_full_resync_at=null;if(!property_exists($c,'benchmark_meta')||!is_object($c->benchmark_meta))$c->benchmark_meta=new stdClass();return $c;}
    private function networkStats(array $stats): array {return array_merge($stats,$this->http->stats(),$this->yahoo->stats());}
    private function pendingCount(stdClass $c): int {$n=0;foreach((array)$c->symbols as $r)if(lm_get($r,'status')!=='ok')$n++;return $n;}
    private function benchmarkKey(array $s): string {return $s['country']==='KR'?'KR-'.$s['exchange']:$s['country'];}
    private function benchmarkSpec(string $key): array {if($key==='KR-KOSPI')return ['KR','KOSPI'];if($key==='KR-KOSDAQ')return ['KR','KOSDAQ'];if($key==='US')return ['US',null];if($key==='JP')return ['JP',null];throw new LiteMonFault('BENCHMARK_KEY_INVALID');}
    private function buildCurrentResults(stdClass $c,array $watch): array {
        $results=[];$errors=[];$done=0;$pending=0;foreach($watch as $sym){$r=lm_get($c->symbols,$sym['symbol_id']);if($r&&lm_get($r,'status')==='ok'&&is_array(lm_get($r,'analysis'))){$a=$r->analysis;$fc=lm_get($r,'final_check');if($fc instanceof stdClass)$a['final_check']=(array)$fc;$a['selection_ready']=($a['eligible']??false)===true&&lm_get($fc,'status')==='confirmed';$results[]=$a;$done++;}else{$pending++;$errors[]=['symbol_id'=>$sym['symbol_id'],'error'=>$r?(string)lm_get($r,'last_error','PENDING'):'PENDING'];}}
        usort($results,function($a,$b){return $b['metrics']['RS20_pp']<=>$a['metrics']['RS20_pp']?:$b['metrics']['R20']<=>$a['metrics']['R20']?:$b['metrics']['ADV20']<=>$a['metrics']['ADV20']?:strcmp($a['symbol_id'],$b['symbol_id']);});$rank=1;foreach($results as &$r){$r['rank_hint']=$r['eligible']?$rank++:null;}unset($r);return [$results,$errors,$done,$pending];
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
    private function analysisReusable($old,array $bars): bool {return $old&&lm_get($old,'status')==='ok'&&is_array(lm_get($old,'bars'))&&$old->bars===$bars&&is_array(lm_get($old,'analysis'));}
    private function shortlist(array $results): array {
        $by=[];foreach($results as $r)if(($r['eligible']??false)===true)$by[$r['country']][]=$r;foreach($by as &$rows){usort($rows,function($a,$b){return $a['rank_hint']<=>$b['rank_hint'];});$rows=array_slice($rows,0,self::FINAL_RECHECK_PER_COUNTRY);}unset($rows);return $by;
    }
    private function finalRecheck(stdClass $c,array $results,array &$stats): array {
        $short=$this->shortlist($results);$checked=[];
        foreach($short as $rows)foreach($rows as $a){
            $sid=$a['symbol_id'];$row=lm_get($c->symbols,$sid);if(!$row||!is_array(lm_get($row,'bars')))continue;$sym=(array)$row->symbol;$oldBars=$row->bars;$last=$oldBars[count($oldBars)-1];$prior=lm_get($row,'final_check');$priorAt=$prior instanceof stdClass?strtotime((string)lm_get($prior,'checked_at','')):false;
            if($prior instanceof stdClass&&lm_get($prior,'status')==='confirmed'&&lm_get($prior,'date')===$last[0]&&abs((float)lm_get($prior,'close',0)-(float)$last[4])/max(abs((float)$last[4]),1e-9)<=0.001&&$priorAt!==false&&time()-$priorAt<self::FINAL_RECHECK_TTL){$stats['final_recheck_cache_hits']++;$row->analysis['final_check']=(array)$prior;$row->analysis['selection_ready']=true;$c->symbols->{$sid}=$row;$checked[$sid]=true;continue;}
            try{$stats['final_rechecks']++;$recent=$this->yahoo->recent($sym,$last[0],10);if(!$recent)throw new LiteMonFault('FINAL_RECHECK_EMPTY');$newLast=$recent[count($recent)-1];$ok=$newLast[0]===$last[0]&&abs($newLast[4]-$last[4])/max(abs($last[4]),1e-9)<=0.001;$row->final_check=(object)['status'=>$ok?'confirmed':'needs_review','checked_at'=>lm_iso(),'date'=>$newLast[0],'close'=>$newLast[4]];$row->analysis['final_check']=(array)$row->final_check;$row->analysis['selection_ready']=$ok;if(!$ok){$row->status='pending';$row->last_error='FINAL_RECHECK_MISMATCH';}$c->symbols->{$sid}=$row;$checked[$sid]=$ok;}
            catch(Throwable $e){$code=$e instanceof LiteMonFault?$e->faultCode:'FINAL_RECHECK_FAILED';$row->final_check=(object)['status'=>'unavailable','checked_at'=>lm_iso(),'error'=>$code];$row->analysis['final_check']=(array)$row->final_check;$row->analysis['selection_ready']=false;$row->status='pending';$row->last_error=$code;$c->symbols->{$sid}=$row;$checked[$sid]=null;}
        }
        return $checked;
    }
    private function mailboxCheck(): void {
        $error=null;try{$this->processMailbox();}catch(Throwable $e){$error=$e instanceof LiteMonFault?$e->faultCode:'MAILBOX_FAILED';try{LiteRuntime::log('mailbox_error',['code'=>$error]);}catch(Throwable $ignore){}}
        $s=$this->state();$s->last_mailbox_check=lm_iso();$s->next_mailbox_check=lm_iso(time()+self::MAILBOX_SECONDS);$s->last_mailbox_error=$error;$s->heartbeat_at=lm_iso();$this->saveState($s);
    }
    private function retryPublication(): void {
        $s=$this->state();if(lm_get($s,'publication_pending')!==true)return;$e=LiteRuntime::read('evidence');$summary=lm_get($e,'latest_scan');if($summary instanceof stdClass)$summary=(array)$summary;if(!is_array($summary)){$s->publication_pending=true;$s->last_publication_error='PUBLICATION_SUMMARY_MISSING';$this->saveState($s);return;}$watch=$this->cachedWatchlist();if(!$watch){$s->last_publication_error='PUBLICATION_WATCHLIST_MISSING';$this->saveState($s);return;}
        try{$ok=$this->publishData($summary,$watch);if($ok){$s=$this->state();$s->last_publish_fingerprint=(string)lm_get($s,'publication_fingerprint','');$s->publication_pending=false;$s->last_publication_error=null;$this->saveState($s);}}
        catch(Throwable $x){$s=$this->state();$s->publication_pending=true;$s->last_publication_error=$x instanceof LiteMonFault?$x->faultCode:'PUBLICATION_FAILED';$this->saveState($s);try{LiteRuntime::log('publication_retry_pending',['code'=>$s->last_publication_error]);}catch(Throwable $ignore){}}
    }
    public function fullScan(bool $retryOnly=false): array {
        $this->http->clearRequestCache();$this->http->resetStats();$this->yahoo->beginTurn();$s=$this->state();if(!is_string($s->last_full_scan)){$this->mailboxCheck();$s=$this->state();}$res=LiteResource::sample();if(!$res['heavy_allowed']){$s->last_error='RESOURCE_PRESSURE_'.strtoupper((string)$res['reason']);$s->next_light_check=lm_iso(time()+self::LIGHT_SECONDS);$s->next_full_scan=lm_iso(time()+self::LIGHT_SECONDS);$this->heartbeat($s,'WAIT',$s->last_error);LiteRuntime::write('evidence',(object)['contract'=>'MON-LITE-EVIDENCE-1.1','resource'=>$res,'observed_at'=>lm_iso()]);return ['deferred'=>true,'resource'=>$res];}
        $this->heartbeat($s,'SCAN');$watch=$this->loadRemoteWatchlist($retryOnly);$c=$this->cacheObj();$c->watchlist=array_map(function($x){return(object)$x;},$watch);$forceFull=!$retryOnly&&$this->resyncDue($c);$stats=['mode'=>$forceFull?'full_resync':($retryOnly?'retry_incremental':'incremental'),'full_downloads'=>0,'incremental_downloads'=>0,'fallback_full_downloads'=>0,'analysis_reused'=>0,'analysis_recomputed'=>0,'benchmark_downloads'=>0,'benchmark_cache_hits'=>0,'final_rechecks'=>0,'final_recheck_cache_hits'=>0];
        $targets=[];foreach($watch as $sym){$old=lm_get($c->symbols,$sym['symbol_id']);if(!$retryOnly||!$old||lm_get($old,'status')!=='ok')$targets[]=$sym;}
        if(!$targets){$stats=$this->networkStats($stats);$this->lightCheck();return ['at'=>lm_iso(),'done'=>count($watch),'pending'=>0,'candidate_count'=>count($watch),'eligible_count'=>0,'results'=>[],'errors'=>[],'network_stats'=>$stats];}
        $needed=[];foreach($targets as $sym)$needed[$this->benchmarkKey($sym)]=true;$bench=[];$benchErrors=[];
        foreach(array_keys($needed) as $key){$cached=lm_get($c->benchmarks,$key);$meta=lm_get($c->benchmark_meta,$key);[$country,$exchange]=$this->benchmarkSpec($key);try{$checkedAt=is_object($meta)?strtotime((string)lm_get($meta,'checked_at','')):false;$freshRetry=$retryOnly&&is_array($cached)&&count($cached)>=60&&$checkedAt!==false&&time()-$checkedAt<self::BENCHMARK_RETRY_TTL;if($freshRetry){$bench[$key]=$cached;$stats['benchmark_cache_hits']++;}elseif($forceFull||!is_array($cached)||count($cached)<60){$stats['benchmark_downloads']++;$bench[$key]=$this->yahoo->benchmark($country,$exchange,220);}else{$spec=['symbol_id'=>$country.'|INDEX|'.$key,'symbol'=>$country,'raw_ticker'=>$country==='KR'?($exchange==='KOSDAQ'?'^KQ11':'^KS11'):($country==='US'?'^GSPC':'^TOPX'),'country'=>$country,'exchange'=>$exchange??($country==='US'?'NYSE':'TSE'),'currency'=>['KR'=>'KRW','US'=>'USD','JP'=>'JPY'][$country],'name'=>$key];$stats['benchmark_downloads']++;$bench[$key]=$this->fetchSeries($spec,$cached,false,$stats);}if(!$freshRetry){$lastBench=$bench[$key][count($bench[$key])-1]??null;$c->benchmark_meta->{$key}=(object)['checked_at'=>lm_iso(),'last_date'=>is_array($lastBench)?$lastBench[0]:null];}$c->benchmarks->{$key}=$bench[$key];}catch(Throwable $e){$benchErrors[]=['scope'=>'benchmark','country'=>$key,'error'=>$e instanceof LiteMonFault?$e->faultCode:'BENCHMARK_FAILED'];}}$benchMap=[];foreach($bench as $key=>$rows)try{$benchMap[$key]=LiteSwing::benchmarkMap($rows);}catch(Throwable $e){$benchErrors[]=['scope'=>'benchmark_map','country'=>$key,'error'=>$e instanceof LiteMonFault?$e->faultCode:'BENCHMARK_INVALID'];unset($bench[$key]);}
        $processed=0;$lastCheckpoint=0;foreach($targets as $sym){if($this->stopRequested())throw new LiteMonStop();$old=lm_get($c->symbols,$sym['symbol_id']);try{$oldBars=$old&&is_array(lm_get($old,'bars'))?$old->bars:null;$bars=$this->fetchSeries($sym,$oldBars,$forceFull,$stats);$benchKey=$this->benchmarkKey($sym);if(!isset($benchMap[$benchKey]))throw new LiteMonFault('BENCHMARK_UNAVAILABLE');$q=LiteQuality::compare($oldBars,$bars);if(!$q['accept'])throw new LiteMonFault($q['reason']);if($this->analysisReusable($old,$bars)){$a=$old->analysis;$stats['analysis_reused']++;}else{$a=LiteSwing::analyzeMapped($sym,$bars,$benchMap[$benchKey]);$stats['analysis_recomputed']++;}$c->symbols->{$sym['symbol_id']}=(object)['status'=>'ok','symbol'=>(object)$sym,'bars'=>$bars,'analysis'=>$a,'quality'=>$q,'checked_at'=>lm_iso()];}catch(Throwable $e){$code=$e instanceof LiteMonFault?$e->faultCode:'SCAN_FAILED';$prev=$old?:new stdClass();$prev->status='pending';$prev->last_error=$code;$prev->checked_at=lm_iso();$prev->symbol=(object)$sym;$c->symbols->{$sym['symbol_id']}=$prev;}$processed++;
            if($processed-$lastCheckpoint>=self::CHECKPOINT_EVERY||$processed===count($targets)){$c->updated_at=lm_iso();LiteRuntime::write('cache',$c);$fresh=$this->state();if($fresh->stop_requested)throw new LiteMonStop();$fresh->running=true;$fresh->phase='SCAN';$fresh->heartbeat_at=lm_iso();$fresh->progress=['index'=>$processed,'total'=>count($targets),'symbol_id'=>$sym['symbol_id'],'retry_only'=>$retryOnly,'mode'=>$stats['mode']];$this->saveState($fresh);$s=$fresh;$lastCheckpoint=$processed;}usleep(150000);
        }
        [$results,$errors,$done,$pending]=$this->buildCurrentResults($c,$watch);if($done>0){$this->finalRecheck($c,$results,$stats);[$results,$errors,$done,$pending]=$this->buildCurrentResults($c,$watch);}if($forceFull&&$pending===0)$c->last_full_resync_at=lm_iso();$c->updated_at=lm_iso();LiteRuntime::write('cache',$c);$errors=array_merge($benchErrors,$errors);$stats=$this->networkStats($stats);$summary=['scan_id'=>'scan-'.gmdate('Ymd-His'),'at'=>lm_iso(),'mode'=>$stats['mode'],'done'=>$done,'pending'=>$pending,'candidate_count'=>count($watch),'eligible_count'=>count(array_filter($results,function($x){return $x['eligible'];})),'results'=>$results,'errors'=>$errors,'network_stats'=>$stats];LiteRuntime::write('evidence',(object)['contract'=>'MON-LITE-EVIDENCE-1.1','latest_scan'=>$summary,'error_summary'=>$this->compressErrors($errors)]);
        $s=$this->state();$s->last_full_scan=lm_iso();$s->last_scan_mode=$stats['mode'];$s->last_network_stats=$stats;$s->next_full_scan=self::nextFullAt();$s->next_light_check=lm_iso(time()+self::LIGHT_SECONDS);$s->progress=null;$s->heartbeat_at=lm_iso();$s->phase='RESULT';$s->last_error=null;$fingerprint=lm_hash([$summary['done'],$summary['pending'],$summary['results'],$summary['errors'],$watch]);$s->publication_fingerprint=$fingerprint;$s->publication_pending=$fingerprint!==lm_get($s,'last_publish_fingerprint');$s->last_publication_error=null;$this->saveState($s);if($s->publication_pending){try{if($this->publishData($summary,$watch)){$s=$this->state();$s->last_publish_fingerprint=$fingerprint;$s->publication_pending=false;$s->last_publication_error=null;$this->saveState($s);}}catch(Throwable $e){$s=$this->state();$s->publication_pending=true;$s->last_publication_error=$e instanceof LiteMonFault?$e->faultCode:'PUBLICATION_FAILED';$this->saveState($s);try{LiteRuntime::log('publication_pending',['code'=>$s->last_publication_error]);}catch(Throwable $ignore){}}}$this->mailboxCheck();$summary['publication_pending']=lm_get($this->state(),'publication_pending')===true;return $summary;
    }
    private function compressErrors(array $errors): array {$x=[];$now=lm_iso();foreach($errors as $e){$k=($e['error']??'UNKNOWN').'|'.($e['symbol_id']??$e['country']??'');if(!isset($x[$k]))$x[$k]=['error'=>$e['error']??'UNKNOWN','subject'=>$e['symbol_id']??$e['country']??'','count'=>0,'first_at'=>$now,'last_at'=>$now];$x[$k]['count']++;$x[$k]['last_at']=$now;}return array_values($x);}
    public function lightCheck(): array {$this->http->clearRequestCache();$this->http->resetStats();$this->yahoo->beginTurn();$s=$this->state();$s->last_light_check=lm_iso();$s->next_light_check=lm_iso(time()+self::LIGHT_SECONDS);$s->heartbeat_at=lm_iso();$s->phase='WAIT';$s->last_error=null;$this->saveState($s);$c=$this->cacheObj();$pending=$this->pendingCount($c);$this->retryPublication();$this->mailboxCheck();LiteRuntime::cleanup();return ['at'=>lm_iso(),'pending'=>$pending,'next_light_check'=>$this->state()->next_light_check];}
    private function putVerified(string $path,string $content,string $message,bool $readback=false): array {
        $old=$this->http->githubGet($path);if($old&&hash_equals($old['content'],$content))return ['blob_sha'=>$old['sha'],'commit_sha'=>$old['commit_sha']??null,'unchanged'=>true];$put=$this->http->githubPut($path,$content,$message,$old?$old['sha']:null);if($readback){$check=$this->http->githubGet($path,true);if(!$check||!hash_equals($check['content'],$content)||!hash_equals($check['sha'],$put['blob_sha']))throw new LiteMonFault('GITHUB_READBACK_MISMATCH');}return $put;
    }
    private function publishData(array $summary,array $watch): bool {
        if($this->cfg->githubToken==='')return false;$data=['contract'=>'MON-LITE-DATA-2.2','policy'=>MON_LITE_POLICY,'version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'updated_at'=>lm_iso(),'watchlist'=>['symbols'=>$watch],'scan'=>['scan_id'=>$summary['scan_id'],'at'=>$summary['at'],'done'=>$summary['done'],'pending'=>$summary['pending'],'candidate_count'=>$summary['candidate_count'],'eligible_count'=>$summary['eligible_count'],'mode'=>$summary['mode']??null,'network_stats'=>$summary['network_stats']??null,'decision_status'=>'pending_chatgpt'],'results'=>$summary['results'],'errors'=>$summary['errors']];$raw=lm_json($data);$this->putVerified('mon_data.json',$raw,'MON Lite: completed-daily scan update',true);
        $lines=["MON 2.2.3 Lite · 스캔 완료 · ".$summary['at'],"",'- 자동 스캔 결과이며 최종 ChatGPT 판단은 대기 중입니다.','- 완료 '.$summary['done'].' / '.$summary['candidate_count'].' · 미확인 '.$summary['pending'].' · 적격 '.$summary['eligible_count']];$shown=0;foreach($summary['results'] as $r){if(!($r['eligible']??false))continue;$lines[]='- 후보 '.($r['symbol_id']??'').' · RS20 '.($r['metrics']['RS20_pp']??'').'pp';if(++$shown>=9)break;}$this->putVerified('mon_result.md',implode("\n",$lines)."\n",'MON Lite: scan result pending ChatGPT decision',true);return true;
    }
    private function processMailbox(): void {
        if($this->cfg->githubToken==='')return;$m=$this->http->githubGet('mon_chatgpt_handoff.json');if(!$m||trim((string)$m['content'])==='')return;$q=lm_decode($m['content']);if(lm_get($q,'contract')!=='MON-LITE-BRIDGE-1.0')return;$stage=(string)lm_get($q,'stage');if($stage==='idle')return;$rid=(string)lm_get($q,'request_id');if($rid===''||!in_array($stage,['candidates','decision'],true))return;$req=LiteRuntime::read('request');if($stage==='decision'&&(!is_string(lm_get($req,'request_id'))||lm_get($req,'request_id')==='')){LiteRuntime::write('request',(object)['stage'=>'ignored_stale_decision','ignored_handoff_sha'=>$m['sha'],'request_id'=>$rid,'updated_at'=>lm_iso()]);return;}if(lm_get($q,'policy')!==MON_LITE_POLICY)throw new LiteMonFault('BRIDGE_POLICY_MISMATCH');$c=$this->cacheObj();
        if($stage==='candidates'){$ids=lm_get($q,'candidate_ids',[]);if(!is_array($ids))return;$newRequest=lm_get($req,'candidate_request_sha')!==$m['sha'];$changed=false;if($newRequest){$nextWatch=[];$nextSymbols=new stdClass();foreach($ids as $sid){if(!is_string($sid))continue;$row=lm_get($c->symbols,$sid);if(!$row){$sym=self::symbolFromId($sid);if(!$sym)continue;$row=(object)['status'=>'pending','symbol'=>(object)$sym,'last_error'=>'CANDIDATE_NOT_COLLECTED','checked_at'=>lm_iso()];}$row->status='pending';$row->last_error='CANDIDATE_REFRESH_REQUIRED';$row->candidate_request_sha=$m['sha'];$row->checked_at=lm_iso();$nextWatch[]=$row->symbol;$nextSymbols->{$sid}=$row;}$c->watchlist=$nextWatch;$c->symbols=$nextSymbols;$changed=true;}if($changed){$c->updated_at=lm_iso();LiteRuntime::write('cache',$c);}if($newRequest){$wake=$this->state();$wake->next_light_check=lm_iso(time()+30);$wake->heartbeat_at=lm_iso();$this->saveState($wake);}$rows=[];$usable=[];$unresolved=[];foreach($ids as $sid){$r=lm_get($c->symbols,(string)$sid);$a=$r?lm_get($r,'analysis'):null;if($r&&lm_get($r,'status')==='ok'&&is_array($a)){$rows[]=$a;$usable[]=$sid;}else{$rows[]=['symbol_id'=>$sid,'status'=>'pending','error'=>$r?lm_get($r,'last_error'):null];$unresolved[]=$sid;}}$evaluationId=lm_hash([$rid,$m['sha'],$usable,array_map(function($r){return lm_hash($r);},$rows)]);if(lm_get($req,'candidate_request_sha')===$m['sha']&&lm_get($req,'evaluation_id')===$evaluationId&&is_string(lm_get($req,'details_blob_sha')))return;$response=['contract'=>'MON-LITE-BRIDGE-1.0','policy'=>MON_LITE_POLICY,'stage'=>'details','request_id'=>$rid,'candidate_request_sha'=>$m['sha'],'status'=>$usable?'ready_subset':'collection_pending','evaluation_id'=>$evaluationId,'evaluated_ids'=>$usable,'unresolved_ids'=>$unresolved,'rows'=>$rows,'as_of'=>lm_iso()];$put=$this->putVerified('mon_chatgpt_response.json',lm_json($response),'MON Lite: detail response',false);LiteRuntime::write('request',(object)['request_id'=>$rid,'stage'=>'details','candidate_request_sha'=>$m['sha'],'details_blob_sha'=>$put['blob_sha'],'evaluation_id'=>$evaluationId,'evaluated_ids'=>$usable,'unresolved_ids'=>$unresolved,'updated_at'=>lm_iso()]);return;}
        $selected=lm_get($q,'selected',[]);if(!is_array($selected))return;$saved=LiteRuntime::read('result');if(lm_get($saved,'handoff_sha')===$m['sha']&&lm_get($saved,'request_id')===$rid&&lm_get($saved,'save_status')==='saved')return;if(lm_get($req,'request_id')!==$rid||lm_get($q,'candidate_request_sha')!==lm_get($req,'candidate_request_sha')||lm_get($q,'details_receipt_sha')!==lm_get($req,'details_blob_sha')||lm_get($q,'evaluation_id')!==lm_get($req,'evaluation_id'))throw new LiteMonFault('DECISION_PROOF_MISMATCH');$eval=lm_get($req,'evaluated_ids',[]);$per=[];foreach($selected as $x){$sid=(string)lm_get($x,'symbol_id');$reason=trim((string)lm_get($x,'reason',''));if(!in_array($sid,$eval,true)||$reason==='')throw new LiteMonFault('DECISION_SCOPE_INVALID');$row=lm_get($c->symbols,$sid);if(!$row||lm_get($row,'status')!=='ok'||lm_get(lm_get($row,'analysis'),'eligible')!==true||lm_get(lm_get($row,'final_check'),'status')!=='confirmed')throw new LiteMonFault('DECISION_NOT_READY');$country=explode('|',$sid)[0];$per[$country]=($per[$country]??0)+1;if($per[$country]>3)throw new LiteMonFault('DECISION_LIMIT_INVALID');}$this->saveDecision($rid,$selected,(array)lm_get($req,'unresolved_ids',[]),(string)$req->evaluation_id,$m['sha']);
    }
    private function saveDecision(string $rid,array $selected,array $unresolved,string $evaluationId,string $handoffSha): void {
        $now=lm_iso();$result=['contract'=>'MON-LITE-RESULT-2.0','policy'=>MON_LITE_POLICY,'request_id'=>$rid,'evaluation_id'=>$evaluationId,'handoff_sha'=>$handoffSha,'selected'=>$selected,'unresolved_ids'=>$unresolved,'scope_complete'=>count($unresolved)===0,'saved_at'=>$now,'save_status'=>'preparing'];$lines=["MON 2.2.3 Lite · 완료 일봉 스윙 · ".$now,""];if(!$selected)$lines[]='- 확인된 평가 범위 내 선정 종목 없음.';else foreach($selected as $x)$lines[]='- '.lm_get($x,'symbol_id').' · '.trim((string)lm_get($x,'reason','선정'));if($unresolved)$lines[]='- 미확인 후보 '.count($unresolved).'개: 전체 후보 순위 확정 아님.';$md=implode("\n",$lines)."\n";
        $putR=$this->putVerified('mon_result.md',$md,'MON Lite: ChatGPT decision result',true);$remote=$this->http->githubGet('mon_data.json');$data=(object)['contract'=>'MON-LITE-DATA-2.2','policy'=>MON_LITE_POLICY,'version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'updated_at'=>$now,'watchlist'=>(object)['symbols'=>[]],'scan'=>(object)['state'=>'decision_only','decision_status'=>'saved'],'results'=>[],'errors'=>[]];if($remote&&trim((string)$remote['content'])!==''){try{$candidate=lm_decode($remote['content']);if(lm_get($candidate,'contract')==='MON-LITE-DATA-2.2'&&lm_get($candidate,'policy')===MON_LITE_POLICY)$data=$candidate;}catch(LiteMonFault $ignore){}}$data->policy=MON_LITE_POLICY;$data->version=MON_LITE_VERSION;$data->build=MON_LITE_BUILD;$data->updated_at=$now;$data->lite_decision=(object)$result;$data->lite_decision->result_blob_sha=$putR['blob_sha'];$data->lite_decision->result_commit_sha=$putR['commit_sha'];$putD=$this->putVerified('mon_data.json',lm_json($data),'MON Lite: decision scope record',true);$result['result_commit_sha']=$putR['commit_sha'];$result['data_commit_sha']=$putD['commit_sha'];$result['pair_readback_verified']=true;$result['save_status']='saved';LiteRuntime::write('result',(object)$result);$response=['contract'=>'MON-LITE-BRIDGE-1.0','policy'=>MON_LITE_POLICY,'stage'=>'decision','request_id'=>$rid,'status'=>'saved','result_commit_sha'=>$putR['commit_sha'],'data_commit_sha'=>$putD['commit_sha'],'pair_readback_verified'=>true,'evaluation_id'=>$evaluationId,'selected_count'=>count($selected),'unresolved_count'=>count($unresolved)];$this->putVerified('mon_chatgpt_response.json',lm_json($response),'MON Lite: decision saved',false);
    }
    public function loop(): void {
        $lock=LiteRuntime::lock();$s=$this->state();$s->running=true;$s->stop_requested=false;$this->saveState($s,true);try{while(true){$s=$this->state();if($s->stop_requested)break;try{$dueFull=!is_string($s->last_full_scan)||!is_string($s->next_full_scan)||strtotime($s->next_full_scan)<=time();$dueMailbox=!is_string($s->next_mailbox_check)||strtotime($s->next_mailbox_check)<=time();$dueLight=!is_string($s->next_light_check)||strtotime($s->next_light_check)<=time();if($dueFull)$this->fullScan(false);elseif($dueMailbox){$this->retryPublication();$this->mailboxCheck();}elseif($dueLight){$c=$this->cacheObj();if($this->pendingCount($c)>0)$this->fullScan(true);else $this->lightCheck();}}catch(LiteMonStop $e){break;}catch(Throwable $e){$code=$e instanceof LiteMonFault?$e->faultCode:'LOOP_ERROR';$s=$this->state();if($code==='WATCHLIST_EMPTY_WAITING_FOR_CANDIDATES'){$s->last_error=null;$s->phase='WAIT';$s->progress=['state'=>'waiting_for_candidates','policy'=>MON_LITE_POLICY];$s->next_light_check=lm_iso(time()+self::LIGHT_SECONDS);$s->heartbeat_at=lm_iso();$this->saveState($s);$this->mailboxCheck();}else{try{LiteRuntime::log('cycle_error',['code'=>$code]);}catch(Throwable $ignore){}$s->last_error=$code;$s->phase='ERROR';$s->next_light_check=lm_iso(time()+self::LIGHT_SECONDS);$this->saveState($s);}}
            for($i=1;$i<=60;$i++){sleep(30);$s=$this->state();if($s->stop_requested)break 2;$nf=is_string($s->next_full_scan)?strtotime($s->next_full_scan):false;$nl=is_string($s->next_light_check)?strtotime($s->next_light_check):false;$nm=is_string($s->next_mailbox_check)?strtotime($s->next_mailbox_check):false;if(($nf!==false&&$nf<=time())||($nl!==false&&$nl<=time())||($nm!==false&&$nm<=time()))break;if($i%10===0){$s->heartbeat_at=lm_iso();if($s->phase!=='ERROR')$s->phase='WAIT';$this->saveState($s);}}
        }}finally{$s=$this->state();$s->running=false;$s->phase='IDLE';$s->heartbeat_at=lm_iso();$this->saveState($s);flock($lock,LOCK_UN);fclose($lock);}
    }
}

function lm_web(): int {
    $cfg=new LiteConfig();$mon=new LiteMonitor();$state=$mon->state();$notice='';$ok=true;$health=LiteHealth::local($cfg);
    if(($_GET['view']??'')==='health'){header('Content-Type: application/json; charset=utf-8');echo lm_json(['ok'=>true,'health'=>$health]);return 0;}
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){$a=$_POST['action']??'';try{if($a==='start'){if(LiteRuntime::lockHeld())throw new LiteMonFault('ALREADY_RUNNING');if(!$health['ready_to_start'])throw new LiteMonFault((string)($health['blockers'][0]??'NAS_PREFLIGHT_FAILED'));$php=$cfg->phpCli;if($php==='')throw new LiteMonFault('PHP_CLI_NOT_FOUND');$cmd=escapeshellcmd($php).' '.escapeshellarg(__FILE__).' --run >/dev/null 2>&1 &';if(!function_exists('exec'))throw new LiteMonFault('WEB_PROCESS_LAUNCH_DISABLED');@exec($cmd,$o,$rc);if($rc!==0)throw new LiteMonFault('WEB_PROCESS_LAUNCH_FAILED');$confirmed=false;for($i=0;$i<20;$i++){usleep(150000);clearstatcache();$fresh=$mon->state();if(lm_get($fresh,'running')===true){$confirmed=true;break;}}if(!$confirmed)throw new LiteMonFault('DAEMON_START_NOT_CONFIRMED');$notice='시작 확인 완료';}elseif($a==='stop'){$state->stop_requested=true;$mon->saveState($state);$notice='중지 요청을 기록했습니다.';}elseif($a==='scan'){$state->next_full_scan=lm_iso(time()-1);$mon->saveState($state);$notice='다음 주기에 정밀 스캔을 요청했습니다.';}else throw new LiteMonFault('WEB_ACTION_INVALID');}catch(Throwable $e){$ok=false;$notice=$e instanceof LiteMonFault?$e->faultCode:'WEB_ERROR';}}
    $state=$mon->state();if(($_GET['view']??'')==='status'){header('Content-Type: application/json; charset=utf-8');echo lm_json(['ok'=>$ok,'version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'policy'=>MON_LITE_POLICY,'state'=>$state,'runtime'=>LiteRuntime::view(),'health'=>$health,'message'=>$notice]);return $ok?0:1;}
    header('Content-Type: text/html; charset=utf-8');$h=function($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');};$runtime=LiteRuntime::view();echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MON 2.2.3 Lite</title><style>body{font-family:system-ui;margin:24px;max-width:760px}section{border:1px solid #ddd;border-radius:12px;padding:18px;margin:14px 0}button{padding:10px 18px;margin-right:8px}.bad{color:#a00}.ok{color:#073}</style><h1>MON 2.2.3 Lite</h1><section><b>상태:</b> '.$h($state->phase).' / '.($state->running?'실행 중':'중지').' <br><b>최근 응답:</b> '.$h($state->heartbeat_at).' <br><b>최근 정밀 스캔:</b> '.$h($state->last_full_scan).' <br><b>다음 경량 확인:</b> '.$h($state->next_light_check).' <br><b>다음 정밀 스캔:</b> '.$h($state->next_full_scan).' <br><b>최근 오류:</b> '.$h($state->last_error).' <br><b>진행:</b> '.$h(lm_json($state->progress)).' <br><b>PHP CLI:</b> '.$h($cfg->phpCli).' <br><b>NAS 준비:</b> '.($health['ready_to_start']?'PASS':'BLOCK '. $h(implode(',', $health['blockers']))).' <br><b>Mailbox:</b> '.$h($state->last_mailbox_check).' / 오류 '.$h($state->last_mailbox_error).' <br><b>결과 게시:</b> '.($state->publication_pending?'재시도 대기':'정상').' / '.$h($state->last_publication_error).' <br><b>저장:</b> '.$h($runtime['path']).' · '.$h($runtime['file_count']).' files · '.round($runtime['bytes']/1048576,2).' MiB</section><form method="post"><button name="action" value="start">시작</button><button name="action" value="stop">중지</button><button name="action" value="scan">정밀 스캔 요청</button></form><p class="'.($ok?'ok':'bad').'">'.$h($notice).'</p><section><b>운영 원칙</b><br>30분: 경량 확인·실패 종목 재시도<br>정밀 스캔: 평일 16:40 KST · 증분 기본 / 7일마다 220봉 전체 재동기화<br>모든 부속파일: /web/mon_runtime 고정파일<br>운영 규격: '.$h(MON_LITE_POLICY).'</section>';
    return 0;
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){try{if(PHP_SAPI==='cli'){if(in_array('--run',$argv??[],true)){(new LiteMonitor())->loop();exit(0);}echo lm_json(['version'=>MON_LITE_VERSION,'build'=>MON_LITE_BUILD,'policy'=>MON_LITE_POLICY,'runtime'=>LiteRuntime::view()])."\n";exit(0);}exit(lm_web());}catch(Throwable $e){$code=$e instanceof LiteMonFault?$e->faultCode:'MON_LITE_FATAL';try{LiteRuntime::log('fatal',['code'=>$code]);}catch(Throwable $ignore){}if(PHP_SAPI==='cli'){fwrite(STDERR,$code."\n");exit(1);}http_response_code(500);echo htmlspecialchars($code,ENT_QUOTES,'UTF-8');}}
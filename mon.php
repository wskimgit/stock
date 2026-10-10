<?php
/**
 * mon.php 1.3.0 -- PHP 7.4+ web control / persistent quote daemon.
 * Repository: wskimgit/stock; data interface: mon_data.json schema 3.
 * The daemon writes only collection. Stateless MON adapters return files; MON owns analysis and result.
 */
declare(strict_types=1);

// Put mon.php in /volume1/web, open /mon.php in a browser, then press Start.
// Reuse the existing SIS token and broker credential file in the same folder.
const MON_VERSION = '1.3.0';
const MON_PRIVATE_SYNC_CONFIG = __DIR__ . '/sis_private_sync_config.php';
const MON_BROKER_CONFIG = __DIR__ . '/broker_config.local.php';
const MON_CONFIG = [
    'GITHUB_TOKEN' => '',
    'KIS_APP_KEY' => '',
    'KIS_APP_SECRET' => '',
    'PHP_CLI' => ''
];
const MON_SETTINGS_PREFIX = "<?php exit; ?>\n";

function mon_get($o, string $key, $default = null) {
    if (is_object($o) && property_exists($o, $key)) return $o->$key;
    if (is_array($o) && array_key_exists($key, $o)) return $o[$key];
    return $default;
}
function mon_iso(?int $ts = null): string { return gmdate('Y-m-d\TH:i:s\Z', $ts === null ? time() : $ts); }
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
            if (@file_put_contents($tmp, $prefix . mon_json($value), LOCK_EX) === false) throw new MonFault('STATE_WRITE_FAILED');
            if (!@rename($tmp, $this->path($name))) throw new MonFault('STATE_RENAME_FAILED');
        } finally { if (is_file($tmp)) @unlink($tmp); }
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
        $end = microtime(true) + max(0, $seconds);
        do { $this->check(); $left = $end - microtime(true); if ($left > 0) usleep((int)(min($left, 0.2) * 1000000)); } while ($left > 0);
    }
}

class MonHttp {
    private $control;
    private $transport;
    private $lastQuoteStart = 0.0;
    public function __construct(?MonControl $control = null, ?callable $transport = null) { $this->control = $control; $this->transport = $transport; }
    public function request(string $method, string $url, array $headers, ?string $body, float $deadline, float $timeout = 5.0, float $spacing = 0.0): array {
        if ($this->control) $this->control->check();
        if ($spacing > 0) {
            $wait = $this->lastQuoteStart + $spacing - microtime(true);
            if ($wait > 0) {
                if (microtime(true) + $wait >= $deadline) throw new MonFault('BUDGET_EXHAUSTED');
                if ($this->control) $this->control->wait($wait); else usleep((int)($wait * 1000000));
            }
            $this->lastQuoteStart = microtime(true);
        }
        $left = $deadline - microtime(true);
        if ($left < 0.05) throw new MonFault('BUDGET_EXHAUSTED');
        $timeout = min($timeout, $left);
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, ['api.github.com', 'openapi.koreainvestment.com', 'm.stock.naver.com', 'query1.finance.yahoo.com'], true)) throw new MonFault('HTTP_HOST_NOT_ALLOWED');
        if ($this->transport) return ($this->transport)($method, $url, $headers, $body, $timeout);
        if (!extension_loaded('curl')) throw new MonFault('PHP_CURL_REQUIRED');
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
                if (strlen($responseBody) + strlen($chunk) > 2097152) { $tooLarge = true; return 0; }
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
        if ($ok === false) throw new MonFault($tooLarge ? 'HTTP_RESPONSE_TOO_LARGE' : 'HTTP_TRANSPORT_' . $errno);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
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

final class MonData {
    public static function validate($d): void {
        if (!($d instanceof stdClass) || mon_get($d, 'schema_version') !== 3) throw new MonFault('DATA_SCHEMA_INVALID');
        foreach (['watchlist', 'collection', 'analysis'] as $k) if (!(mon_get($d, $k) instanceof stdClass) || mon_get($d->$k, 'schema_version') !== 3) throw new MonFault('DATA_OBJECT_INVALID');
        $w = $d->watchlist;
        $required = ['watchlist'=>['criteria_version','watchlist_version','updated_at','run_id','settings','calendar','symbols'], 'collection'=>['collection_id','watchlist_version','started_at','completed_at','status','next_cursor','quotes'], 'analysis'=>['criteria_version','run_id','as_of','analyzed_at','watchlist_version','collection_id','status','selection_fingerprint','input_fingerprint','result_fingerprint','coverage','independent_results','results','candidate_audit','candidate_history','changes','evidence']];
        foreach ($required as $k=>$fields) foreach ($fields as $field) if (!property_exists($d->$k,$field)) throw new MonFault('DATA_FIELD_MISSING');
        if (!in_array($d->analysis->criteria_version,['MON-P2.0','MON-P3.0'],true) || !($w->calendar instanceof stdClass)) throw new MonFault('DATA_CRITERIA_INVALID');
        if (mon_get($w, 'criteria_version') !== $d->analysis->criteria_version || !is_int(mon_get($w, 'watchlist_version')) || $w->watchlist_version < 0 || !(mon_get($w, 'settings') instanceof stdClass) || !is_bool(mon_get($w->settings, 'enabled')) || !is_array(mon_get($w, 'symbols'))) throw new MonFault('WATCHLIST_INVALID');
        if (!is_array(mon_get($d->collection, 'quotes'))) throw new MonFault('COLLECTION_INVALID');
        if ($d->analysis->criteria_version === 'MON-P3.0') {
            if (mon_get($w->settings,'investment_style') !== 'swing' || MonRun::json(mon_get($w->settings,'swing_profile')) !== MonRun::json(MonSwing::rules())) throw new MonFault('MON_SWING_PROFILE_INVALID');
            foreach (mon_get($d->analysis,'results',[]) as $r) if (mon_get($r,'action') === 'BUY_REVIEW' && !MonSwing::active($r)) throw new MonFault('MON_SWING_RECOMMENDATION_INVALID');
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
        $defaults = ['poll_seconds'=>60, 'mirror_seconds'=>180, 'force_mirror_before_analysis_seconds'=>60, 'collection_budget_seconds'=>45, 'tick_budget_seconds'=>55, 'publish_reserve_seconds'=>10, 'http_timeout_seconds'=>5, 'github_http_timeout_seconds'=>3, 'request_spacing_seconds'=>1.25, 'github_conflict_retries'=>2, 'auxiliary_max_age_seconds'=>900, 'action_price_max_age_seconds'=>300, 'future_clock_tolerance_seconds'=>5];
        foreach ($defaults as $k => $v) {
            $n = mon_number(mon_get($w->settings, $k, $v));
            if ($n === null || $n < 0) throw new MonFault('SETTINGS_INVALID');
            $defaults[$k] = $n;
        }
        $defaults['poll_seconds'] = max(10, $defaults['poll_seconds']);
        $defaults['http_timeout_seconds'] = min(5, max(0.1, $defaults['http_timeout_seconds']));
        $defaults['github_http_timeout_seconds'] = min(3, max(0.1, $defaults['github_http_timeout_seconds']));
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
    private function headers(bool $writing=false): array {
        $token=$this->config->get('GITHUB_TOKEN'); if ($writing && $token==='') throw new MonFault('GITHUB_TOKEN_MISSING');
        $headers=['Accept: application/vnd.github+json','Content-Type: application/json','X-GitHub-Api-Version: 2022-11-28'];
        if($token!=='')$headers[]='Authorization: Bearer '.$token;
        return $headers;
    }
    public function read(float $deadline, float $timeout=3): array {
        $url=$this->url.'?'.http_build_query(['ref'=>$this->config->get('MON_BRANCH','main')]);
        $d=MonHttp::decode($this->http->request('GET',$url,$this->headers(),null,$deadline,$timeout));
        if (mon_get($d,'encoding')!=='base64' || !is_string(mon_get($d,'sha')) || !is_string(mon_get($d,'content'))) throw new MonFault('GITHUB_CONTENT_INVALID');
        $raw=base64_decode(str_replace(["\r","\n"],'',$d->content),true);
        if ($raw===false || strlen($raw)>MonRun::MAX_SOURCE) throw new MonFault('DATA_SIZE_INVALID');
        try { $data=mon_decode($raw); } catch (Throwable $e) { throw new MonFault('DATA_JSON_INVALID'); }
        MonData::validate($data); return ['sha'=>$d->sha,'data'=>$data];
    }
    public function publish($batch, string $signature, float $deadline, array $settings): array {
        $headers=$this->headers(true); // Fail before any network work if writes are not configured.
        for ($attempt=0; $attempt <= $settings['github_conflict_retries']; $attempt++) {
            $latest=$this->read($deadline,$settings['github_http_timeout_seconds']);
            if (!$latest['data']->watchlist->settings->enabled || MonData::signature($latest['data']->watchlist)!==$signature) throw new MonFault('WATCHLIST_CHANGED');
            $latest['data']->collection=$batch; // Preserve all other objects and unknown fields.
            $raw=mon_json($latest['data'],false); if (strlen($raw)>MonRun::MAX_SOURCE) throw new MonFault('DATA_SIZE_EXCEEDED');
            $body=mon_json(['message'=>'mon: mirror collection '.$batch->collection_id,'content'=>base64_encode($raw),'sha'=>$latest['sha'],'branch'=>$this->config->get('MON_BRANCH','main')]);
            $r=$this->http->request('PUT',$this->url,$headers,$body,$deadline,$settings['github_http_timeout_seconds']);
            if (in_array($r['status'],[409,422],true) && $attempt < $settings['github_conflict_retries']) continue;
            $saved=MonHttp::decode($r);
            if (!is_string(mon_get(mon_get($saved,'content'),'sha'))) throw new MonFault('GITHUB_WRITE_UNCONFIRMED');
            return ['sha'=>$saved->content->sha,'data'=>$latest['data']];
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
    private function token(float $deadline, array $settings): string {
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
        while ($visited<$count && microtime(true)<$deadline) {
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
                $points=$this->fetch($s,$w,$deadline,$settings); $new=$points[0]; $older=$points[1]??null;
                $newAt=mon_time($new->quote_at); $oldAt=mon_time(mon_get($r->point,'quote_at'));
                if ($older===null&&$newAt!==null&&$oldAt!==null&&$oldAt<$newAt) $older=$r->point;
                if ($older===null&&$newAt!==null&&$oldAt===$newAt) $older=$r->previous_point;
                $r->point=$new; $r->previous_point=$older; $r->fetch_status='ok'; $r->error=null;
                $r->quality_status=MonData::quality($new,$this->now(),$settings);
                $this->succeeded++;
            } catch (MonStop $e) { break; }
            catch (MonFault $e) {
                if ($e->faultCode==='BUDGET_EXHAUSTED') break;
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
    private $failures=0;
    private $poll=60.0;
    private $lastError=null;
    private $activity='starting';
    private $lastAttempted=0;
    private $lastSucceeded=0;
    public function __construct(MonStore $store,string $envFile,string $instance) {
        $this->store=$store; $this->envFile=$envFile; $this->control=new MonControl($store,$instance); $this->started=mon_iso();
        $this->http=new MonHttp($this->control);
    }
    private function heartbeat(string $state,?string $error=null): void {
        if($state!=='waiting')$this->activity=$state;
        if ($error!==null) $this->lastError=$error;
        elseif (in_array($state,['paused','empty_watchlist','mirrored','collected','market_closed','stopped'],true)) $this->lastError=null;
        $this->store->write('status.json',(object)['version'=>MON_VERSION,'instance_id'=>$this->control->instance,'pid'=>getmypid(),'started_at'=>$this->started,'heartbeat_at'=>mon_iso(),'state'=>$state,'activity_state'=>$this->activity,'ticks'=>$this->ticks,'last_published_at'=>$this->lastPublished?mon_iso($this->lastPublished):null,'last_attempted'=>$this->lastAttempted,'last_succeeded'=>$this->lastSucceeded,'error_code'=>$this->lastError]);
    }
    private function cacheRemote($data,MonConfig $cfg): void {
        $local=clone $data;
        $local->_mon_cache=(object)['repository'=>$cfg->get('MON_REPOSITORY','wskimgit/stock'),'branch'=>$cfg->get('MON_BRANCH','main'),'fetched_at'=>mon_iso()];
        $this->store->write('remote_cache.json',$local);
    }
    private function readRemote(MonGitHub $gh,MonConfig $cfg,float $deadline): array {
        if($cfg->get('GITHUB_TOKEN')===''){
            try{
                $cached=$this->store->read('remote_cache.json');$meta=mon_get($cached,'_mon_cache');$at=mon_time(mon_get($meta,'fetched_at'));
                if($at!==null && $at<=time()+5 && time()-$at<300 && mon_get($meta,'repository')===$cfg->get('MON_REPOSITORY','wskimgit/stock') && mon_get($meta,'branch')===$cfg->get('MON_BRANCH','main')){
                    MonData::validate($cached);unset($cached->_mon_cache);
                    return ['sha'=>null,'data'=>$cached,'cached'=>true];
                }
            }catch(MonFault $e){/* An invalid local cache is replaced only by a verified remote read. */}
        }
        $latest=$gh->read($deadline);$latest['cached']=false;return $latest;
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
    private function tick(): void {
        $start=microtime(true); $cfg=new MonConfig($this->envFile,$this->store->dir);
        $this->poll=$cfg->int('MON_IDLE_POLL_SECONDS',60,1,3600);
        $this->lastAttempted=0;$this->lastSucceeded=0;
        $gh=new MonGitHub($this->http,$cfg); $latest=$this->readRemote($gh,$cfg,$start+10); $data=$latest['data']; $w=$data->watchlist; $settings=MonData::settings($w); $this->poll=$settings['poll_seconds'];
        if(!$latest['cached'])$this->cacheRemote($data,$cfg);
        if($cfg->get('GITHUB_TOKEN')===''){$this->poll=max(300,$this->poll);$this->heartbeat('needs_setup',$cfg->githubTokenError());return;}
        if (!$w->settings->enabled || count($w->symbols)===0) { $this->heartbeat($w->settings->enabled?'empty_watchlist':'paused'); return; }
        $signature=MonData::signature($w); $pending=$this->store->read('pending.json');
        $previous=mon_get($pending,'signature')===$signature?mon_get($pending,'collection'):$data->collection;
        $cred=hash('sha256',$cfg->get('KIS_APP_KEY')."\0".$cfg->get('KIS_APP_SECRET'));
        if (!$this->collector || $cred!==$this->credentialSignature) { $this->collector=new MonCollector($this->http,$this->store,$cfg,$this->control); $this->credentialSignature=$cred; }
        else $this->collector->configure($cfg);
        $tickDeadline=$start+$settings['tick_budget_seconds'];
        $collectDeadline=min($start+$settings['collection_budget_seconds'],$tickDeadline-$settings['publish_reserve_seconds']);
        $batch=$this->collector->collect($data,$previous,$collectDeadline,$settings);
        $this->lastAttempted=$this->collector->attempted;$this->lastSucceeded=$this->collector->succeeded;
        $dirty=$this->collector->attempted>0 || (mon_get($pending,'signature')===$signature && mon_get($pending,'dirty',false));
        $this->store->write('pending.json',(object)['signature'=>$signature,'collection'=>$batch,'dirty'=>$dirty]);
        $force=self::forceMirror($w,time(),$settings);
        $due=($dirty && time()-$this->lastPublished >= $settings['mirror_seconds']) || $force;
        if ($due) {
            try {
                $saved=$gh->publish($batch,$signature,$tickDeadline,$settings); $this->lastPublished=time();
                $this->cacheRemote($saved['data'],$cfg); @unlink($this->store->path('pending.json'));
                $this->store->log('mirror_ok',['status'=>$batch->status,'quotes'=>count($batch->quotes)]);
            } catch (MonFault $e) {
                if ($e->faultCode==='WATCHLIST_CHANGED') { @unlink($this->store->path('pending.json')); $this->heartbeat('watchlist_changed',$e->faultCode); return; }
                throw $e;
            }
        }
        if($this->lastAttempted>0 && $this->lastSucceeded===0)$this->heartbeat('quote_failed','QUOTE_FETCH_FAILED');
        else $this->heartbeat($this->lastAttempted===0?'market_closed':($due?'mirrored':'collected'));
    }
    public function run(int $maxTicks=0): int {
        $this->lock=@fopen($this->store->path('daemon.lock'),'c+');
        if (!$this->lock||!flock($this->lock,LOCK_EX|LOCK_NB)) { if ($this->lock) fclose($this->lock); throw new MonFault('ALREADY_RUNNING'); }
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true); $ctl=$this->control;
            pcntl_signal(SIGTERM,static function() use($ctl){$ctl->stop=true;}); pcntl_signal(SIGINT,static function() use($ctl){$ctl->stop=true;});
        }
        $exit=0; $this->heartbeat('starting'); $this->store->log('daemon_started');
        try {
            while (!$maxTicks || $this->ticks<$maxTicks) {
                $this->control->check(); $start=microtime(true); $stamp=mon_settings_stamp($this->store,$this->envFile);$this->ticks++;
                try { $this->tick(); $this->failures=0; }
                catch (MonStop $e) { throw $e; }
                catch (MonFault $e) {
                    $this->failures++; $exit=1; $this->store->log('tick_error',['code'=>$e->faultCode]); $this->heartbeat('retrying',$e->faultCode);
                    $this->poll=max($this->poll,min(300,5*(2**min($this->failures-1,6))),$e->retryAfter);
                } catch (Throwable $e) {
                    $this->failures++; $exit=1; $this->store->log('tick_error',['code'=>'INTERNAL_ERROR']); $this->heartbeat('retrying','INTERNAL_ERROR'); $this->poll=max($this->poll,30);
                }
                if ($maxTicks && $this->ticks>=$maxTicks) break;
                $remaining=max(0,$this->poll-(microtime(true)-$start));
                while ($remaining>0) {
                    $slice=min(5,$remaining); $this->control->wait($slice); $this->heartbeat('waiting'); $remaining-=$slice;
                    if(mon_settings_stamp($this->store,$this->envFile)!==$stamp)break;
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
    $h=@fopen($store->path('daemon.lock'),'c+'); if (!$h) throw new MonFault('LOCK_READ_FAILED');
    $free=flock($h,LOCK_EX|LOCK_NB); if ($free) flock($h,LOCK_UN); fclose($h);
    $status=$store->read('status.json');
    return ['running'=>!$free,'status'=>$status];
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
        $command=mon_web_detach_command('nohup '.escapeshellarg($runtime['path']).' '.escapeshellarg(__FILE__).' --run --env='.escapeshellarg($envFile).' --state-dir='.escapeshellarg($store->dir).' --instance='.escapeshellarg($instance).' > '.escapeshellarg($store->path('launcher.log')).' 2>&1 < /dev/null &');
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
        throw new MonFault('DAEMON_START_NOT_CONFIRMED');
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function mon_web_stop(MonStore $store): string {
    $s=mon_status($store); if (!$s['running']) return '이미 중지되어 있습니다.';
    $id=mon_get($s['status'],'instance_id');
    if (!is_string($id)) throw new MonFault('DAEMON_STARTING_RETRY_STOP');
    $store->write('stop.json',(object)['instance_id'=>$id,'requested_at'=>mon_iso()]);
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
    $configured=$cfg->get('GITHUB_TOKEN')!=='';$error=mon_get($status,'error_code');$blockers=[];$markets=[];$freshQuotes=0;
    $restart=$s['running']&&is_string(mon_get($status,'version'))&&mon_get($status,'version')!==MON_VERSION;
    if(!$configured)$blockers[]=$cfg->githubTokenError();
    if($loaded&&$enabled===false)$blockers[]='COLLECTION_DISABLED';
    if($loaded&&count($symbols)===0)$blockers[]='EMPTY_WATCHLIST';
    if($restart)$blockers[]='DAEMON_RESTART_REQUIRED';
    if($s['running']&&!$fresh)$blockers[]='HEARTBEAT_STALE';
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
    $readiness='stopped';$label='중지됨';$message='시작 버튼을 누르면 백그라운드에서 계속 실행합니다.';
    if($s['running']){
        if($stopping){$readiness='stopping';$label='중지 중';$message='중지 요청을 처리하고 있습니다.';}
        elseif(!$fresh){$readiness='unknown';$label='응답 확인 필요';$message='프로세스 잠금은 유지되지만 최신 상태 응답을 확인하지 못했습니다.';}
        elseif($restart){$readiness='restart_required';$label='재시작 필요';$message='새 코드가 저장되었습니다. 중지 후 시작하면 v'.MON_VERSION.'이 적용됩니다.';}
        elseif(!$configured){$readiness='needs_setup';$label='설정 필요';$message=mon_web_error($cfg->githubTokenError());if($enabled===false)$message.=' 관찰목록의 수집 설정도 꺼져 있습니다.';}
        elseif($error){$readiness='error';$label=$error==='QUOTE_FETCH_FAILED'?'시세 조회 실패':'연결 확인 필요';$message=mon_web_error($error);}
        elseif($enabled===false){$readiness='paused';$label='수집 꺼짐';$message='관찰목록의 수집 설정이 꺼져 있어 대기 중입니다.';}
        elseif(!$loaded){$readiness='loading';$label='목록 확인 중';$message='GitHub 관찰목록을 확인하고 있습니다.';}
        elseif(count($symbols)===0){$readiness='empty';$label='종목 없음';$message='관찰종목 등록을 기다리고 있습니다.';}
        elseif(!$open){$readiness='off_session';$label='장외 대기';$message='현재 수집 시간에 해당하는 시장이 없습니다. 다음 정규장에 수집합니다.';}
        elseif(in_array($activity,['collected','mirrored'],true)){
            $partial=(int)mon_get($status,'last_succeeded',0)<(int)mon_get($status,'last_attempted',0);
            $readiness=$partial?'partial':'collecting';$label=$partial?'부분 수집':'수집 중';$message='정규장 시세를 수집하고 GitHub에 반영합니다.';
        }else{$readiness='loading';$label='수집 확인 중';$message='데몬은 실행 중입니다. 첫 시세 수집 결과를 기다립니다.';}
    }
    $active=$s['running']&&$fresh&&in_array($readiness,['collecting','partial'],true)&&$freshQuotes>0;
    return [
        'version'=>MON_VERSION,'running'=>$s['running'],'stopping'=>$stopping,'heartbeat_fresh'=>$fresh,
        'readiness'=>$readiness,'monitoring_active'=>$active,'restart_required'=>$restart,'setup_blockers'=>$blockers,'market_states'=>$markets,'watchlist_loaded'=>$loaded,'fresh_quotes'=>$freshQuotes,
        'label'=>$label,
        'message'=>$message,'status'=>$status,
        'watched'=>is_array($symbols)?count($symbols):0,'collected'=>is_array($quotes)?count(array_filter($quotes,static function($q){return mon_get($q,'point')!==null;})):0,
        'last_mirrored_at'=>mon_get($status,'last_published_at'),
        'connection'=>['github'=>$configured,'kis'=>$cfg->get('KIS_APP_KEY')!==''&&$cfg->get('KIS_APP_SECRET')!==''],
        'github_token_source'=>$cfg->githubTokenSource(),
        'kis_credentials_source'=>$cfg->kisCredentialsSource(),'kis_config_error'=>$cfg->kisConfigError()
    ];
}
function mon_web_html(array $data, string $notice, bool $ok, string $phpPath): void {
    $h=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};
    $initial=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
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
var current=$initial,busy=false;
function text(id,value){document.getElementById(id).textContent=value;}
function draw(s){
 current=s;text('state',s.label);text('message',s.message);text('count',s.watchlist_loaded?s.watched:'—');text('collected',s.fresh_quotes||0);
 document.getElementById('dot').className='dot'+(s.readiness==='partial'&&s.monitoring_active?' warn':s.monitoring_active?' on':s.running&&s.heartbeat_fresh?' warn':'');
 document.getElementById('start').disabled=busy||s.running;document.getElementById('stop').disabled=busy||!s.running;
 var stamp=s.last_mirrored_at;
 text('mirror',stamp?new Date(stamp).toLocaleTimeString('ko-KR',{timeZone:'Asia/Seoul',hour:'2-digit',minute:'2-digit'}):'—');
 text('settings-status','GitHub '+(s.github_token_source==='sis_private_sync_config.php'?'기존 설정 파일 사용':s.connection.github?'설정됨':'미설정')+' · 한국투자증권 '+(s.kis_credentials_source==='broker_config.local.php'?'기존 설정 파일 사용':s.connection.kis?'설정됨':'미설정'));
 text('kis-config-error',s.kis_config_error?({BROKER_CONFIG_UNREADABLE:'broker_config.local.php의 읽기 권한을 확인하세요. 보완 원천을 사용합니다.',BROKER_CONFIG_INVALID:'broker_config.local.php의 앱키 설정 형식을 확인하세요. 보완 원천을 사용합니다.',BROKER_CREDENTIALS_INVALID:'broker_config.local.php의 앱키·앱시크릿 형식을 확인하세요. 보완 원천을 사용합니다.',BROKER_CREDENTIALS_MISSING:'broker_config.local.php에 앱키와 앱시크릿이 모두 있어야 합니다. 보완 원천을 사용합니다.'}[s.kis_config_error]||'한국투자증권 설정을 확인하세요.'):'');
}
function notice(message,ok){var e=document.getElementById('notice');e.textContent=message;e.className=ok?'ok':'bad';}
function unverified(message){
 draw(Object.assign({},current,{heartbeat_fresh:false,monitoring_active:false,label:'상태 확인 필요',message:message}));notice(message,false);
}
async function refresh(){
 if(busy)return;
 try{var r=await fetch(location.pathname+'?view=status',{cache:'no-store',headers:{Accept:'application/json'}});var j=await r.json();if(j.ok&&j.data)draw(j.data);else unverified(j.message||'서버 상태를 확인하지 못했습니다.');}
 catch(e){unverified('서버 상태를 읽지 못했습니다. 자동으로 다시 확인합니다.');}
}
document.querySelectorAll('.action-form').forEach(function(form){
 form.addEventListener('submit',async function(e){
  e.preventDefault();if(busy)return;busy=true;draw(current);
  var button=form.querySelector('button');button.disabled=true;
  try{
   var body=new URLSearchParams(new FormData(form));
   var r=await fetch(location.pathname,{method:'POST',headers:{Accept:'application/json'},body:body});var j=await r.json();
   notice(j.message,j.ok);if(j.data)draw(j.data);
   if(j.ok&&body.get('action')==='save_settings')['github','kis-key','kis-secret'].forEach(function(id){document.getElementById(id).value='';});
  }catch(error){notice('요청 결과를 확인하지 못했습니다. 상태를 다시 확인합니다.',false);}
  finally{busy=false;button.disabled=false;draw(current);refresh();}
 });
});
draw(current);setInterval(refresh,3000);
})();
</script></body></html>
HTML;
}
// MON-RUN-1.0: stateless input/output adapters. No credentials or remote writes.
final class MonRun {
    const CONTRACT = 'MON-RUN-1.0';
    const MAX_SOURCE = 786432;
    const MAX_BODY = 4194304;
    const MAX_PAYLOAD = 8388608;
    const VIEW_BYTES = 12288;
    const BLOCKERS = ['identity_needs_check', 'quote_time_unknown', 'current_price_needs_check', 'quote_delay_needs_check', 'quote_basis_needs_check', 'price_invalid', 'market_date_needs_check', 'regular_session_needs_check', 'outside_entry_zone', 'entry_plan_needs_data', 'plan_expired', 'latest_comparison_pending', 'corporate_risk_needs_check', 'trading_status_needs_check', 'confirmed_material_adverse', 'swing_signal_invalid', 'swing_price_confirmation_required'];
    private static $payloadCache = [];

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
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING); $o->important_ids = $ids; return $o;
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
            $current = $verified === $target && mon_get($m, 'comparison_status') !== 'pending_benchmark';
            $out[$country] = (object)['country' => $country, 'verified_completed_date' => $verified, 'target_completed_date' => $target, 'comparison_status' => $current ? mon_get($m, 'comparison_status', 'needs_check') : ($verified === $target ? 'pending_benchmark' : 'preparation_required'), 'current_period' => $current, 'session' => $state['session'], 'preparation_running' => false];
        }
        return $out;
    }
    public static function token($d, $context): string {
        return hash('sha256', self::CONTRACT . '|' . MON_VERSION . '|' . self::json($context) . '|' . self::json($d));
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
            $rows[$sid] = $row;
        }
        ksort($rows, SORT_STRING); return array_values($rows);
    }
    public static function verifyFacts($d, $payload): void {
        if ($d->analysis->criteria_version === 'MON-P3.0') {
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
        $review = mon_get($r, 'risk_review', new stdClass());
        $reviewAt = mon_get($risk, 'verified_as_of', mon_get($review, 'checked_at'));
        $sameReview = false; $tradingCurrent = false;
        try {
            $reviewAge = self::seconds($at) - self::seconds($reviewAt); $zone = MonMarket::timezone($r->country);
            $sameReview = $reviewAge >= -5 && (new DateTimeImmutable($reviewAt))->setTimezone($zone)->format('Y-m-d') === (new DateTimeImmutable($at))->setTimezone($zone)->format('Y-m-d');
            $tradingCurrent = $sameReview && $reviewAge <= 300;
        } catch (MonFault $ignore) {}
        if (!$sameReview || mon_get($risk, 'current_corporate_risk_verified') !== true) $blockers[] = 'corporate_risk_needs_check';
        if (!$tradingCurrent || mon_get($risk, 'current_trading_status_verified') !== true) $blockers[] = 'trading_status_needs_check';
        if (mon_get($risk, 'confirmed_material_adverse') === true) $blockers[] = 'confirmed_material_adverse';
        if (mon_get($w,'criteria_version') === 'MON-P3.0' && mon_get($r,'action') === 'BUY_REVIEW') {
            if (!MonSwing::active($r)) $blockers[]='swing_signal_invalid';
            else {
                $s=$r->swing_signal; $liveOK=false; $hist=mon_get($s,'histogram'); $kind=mon_get($s,'macd_kind');
                if ($q->valid && $price!==null) {
                    $four=mon_get($s,'prior_four_closes',[]);
                    if (is_array($four) && count($four)===4 && $price>(array_sum($four)+$price)/5 &&
                        (mon_get($s,'ma5_reclaim_close')===true || mon_get($s,'ma5_reclaim_live')===true)) $liveOK=true;
                    $ef=mon_number(mon_get($s,'ema_fast_last')); $es=mon_number(mon_get($s,'ema_slow_last')); $sig=mon_number(mon_get($s,'signal'));
                    if ($ef!==null && $es!==null && $sig!==null && in_array($kind,['MACD_GOLD','MACD_AFTER','MACD_BEFORE'],true)) {
                        $m=(2/13*$price+11/13*$ef)-(2/27*$price+25/27*$es); $h=$m-(0.1*$m+0.9*$sig);
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
        foreach ($a->results as $r) if (in_array(mon_get($r, 'action'), ['BUY_REVIEW', 'SELL_REVIEW'], true) || mon_get($watch[mon_get($r, 'symbol_id')] ?? null, 'is_held') === true) $rows[] = $r;
        $known = self::rows($a->results);
        foreach ($watch as $sid => $wr) if ($wr->is_held && !isset($known[$sid])) $rows[] = (object)['symbol_id' => $sid, 'country' => $wr->country, 'name' => $wr->name, 'currency' => $wr->currency, 'action' => 'HOLD', 'entry_plans' => null, 'reason' => '등록 보유분의 분석 준비 필요'];
        usort($rows, static function ($a, $b) { return strcmp($a->country, $b->country) ?: ((int)mon_get($a, 'rank', 9999) <=> (int)mon_get($b, 'rank', 9999)) ?: strcmp($a->symbol_id, $b->symbol_id); });
        $safe = static function ($s) { return str_replace(['|', "\r", "\n", '<', '>'], ['／', ' ', ' ', '＜', '＞'], (string)$s); };
        $num = static function ($n) { if (mon_number($n) === null) return '미확인'; return rtrim(rtrim(MonRun::decimal6($n), '0'), '.'); };
        $same = self::utc($a->as_of) === self::utc($at);
        $text = ($a->criteria_version === 'MON-P3.0' ? 'MON 스윙 · MON-P3.0 · ' : '') . '기준 ' . $a->as_of . ' · ' . $a->status . ($same ? '' : ' · 재사용 표시 ' . $at) . "\n\n| 국가 | 종목 | 판단 | 가격 조건 | 핵심 이유 |\n|---|---|---|---|---|\n";
        foreach ($rows as $r) {
            $conditions = [];
            if ($a->criteria_version === 'MON-P3.0') {
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
            if ($r->action === 'SELL_REVIEW') $label = '매도 검토'; elseif ($r->action === 'BUY_REVIEW') $label = ($comparisonReady ? '매입 검토 · ' : '이전 후보 참고 · ') . $state; else $label = '보유 관찰';
            $text .= '| ' . $safe($r->country) . ' | ' . $safe(mon_get($r, 'name', $r->symbol_id)) . ' (' . $safe(explode('|', $r->symbol_id)[2]) . ') | ' . $label . ' | ' . $safe($conditions ? implode('; ', $conditions) . ' ' . mon_get($r, 'currency', '') : '계획 확인 필요') . ' | ' . $safe(mon_get($r, 'reason', '확인 필요')) . " |\n";
        }
        if (!$rows) $text .= '| 한·미·일 | — | 추천 확정 대기 | — | ' . ($a->criteria_version === 'MON-P3.0' ? 'MA5/MACD 신호 검증 후 ChatGPT 자율 선정' : '자료 확인 필요') . " |\n";
        $notes = [];
        if ($reasons) $notes[] = implode('; ', array_values(array_unique($reasons)));
        if (!$same) $notes[] = '이전 검증 결과 재사용. 현재 가격·거래·기업 확인은 새로 완료한 것이 아닙니다.';
        $limits = mon_get($a, 'limitations', []); $riskNotes = [];
        $corporate = mon_get(mon_get($a, 'input_snapshot'), 'corporate_risk');
        foreach (['confirmed_current_material_adverse' => '확인된 중대 위험', 'pending_items' => '후속 확인 필요'] as $kind => $label) foreach (mon_get($corporate, $kind, []) as $event) {
            $sid = mon_get($event, 'symbol_id'); if (!is_string($sid)) continue;
            $riskNotes[] = $label . ': ' . mon_get($watch[$sid] ?? null, 'name', $sid);
        }
        if ($limits || $riskNotes) $notes[] = '기록 기준 ' . mon_get($a, 'limitations_as_of', $a->as_of) . ': ' . implode('; ', array_values(array_unique(array_merge($limits, $riskNotes))));
        foreach ($notes as $note) $text .= "\n- " . $safe($note);
        return $text . "\n";
    }
    public static function rebase($base, $candidate, $latest): stdClass {
        self::validate($latest); $b = clone $base; $l = clone $latest; unset($b->collection, $l->collection);
        if (self::json($b) !== self::json($l)) throw new MonFault('MON_ANALYSIS_SOURCE_CONFLICT');
        $out = mon_decode(self::json($candidate)); $out->collection = $latest->collection; self::storage($out, true); return $out;
    }
}

/* MON-SWING-1.0: numerical eligibility support; final selections belong to MON/ChatGPT. */
final class MonSwing {
    const CRITERIA = 'MON-P3.0';
    const FORMULA = 'MON-SWING-1.0';
    public static function rules(): stdClass {
        return (object)['strategy'=>'swing','formula_version'=>self::FORMULA,'ma_period'=>5,'min_below_days'=>10,
            'macd_fast'=>12,'macd_slow'=>26,'macd_signal'=>19,'macd_after_days'=>3,
            'macd_before_hist_bars'=>3,'macd_before_gap_atr'=>0.10,'rs20_min_pp'=>0,'rs20_operator'=>'>',
            'ema_seed'=>'SMA_of_first_N_valid_closes','ema_alpha'=>'2/(N+1)',
            'final_selector'=>'ChatGPT','reference_limit_per_country'=>10,'buy_limit_per_country'=>3];
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
        $b=self::bars($input); $n=count($b); if($n<14 || ($n<15 && !($live instanceof stdClass))) throw new MonFault('MON_SWING_MA_HISTORY_MISSING');
        $closes=array_column($b,4); $ma=[];
        foreach($closes as $i=>$v)$ma[$i]=$i>=4?array_sum(array_slice($closes,$i-4,5))/5:null;
        $below=0; for($i=$n-2;$i>=4 && $closes[$i]<$ma[$i];$i--)$below++;
        $closeReclaim=$below>=10 && $closes[$n-1]>$ma[$n-1];
        $trs=[];for($i=1;$i<$n;$i++)$trs[]=max($b[$i][2]-$b[$i][3],abs($b[$i][2]-$closes[$i-1]),abs($b[$i][3]-$closes[$i-1]));
        $atr=count($trs)>=14?array_sum(array_slice($trs,-14))/14:0.0;
        $fast=self::ema($closes,12);$slow=self::ema($closes,26);$macd=[];
        foreach($closes as $i=>$unused)$macd[$i]=$fast[$i]!==null && $slow[$i]!==null?$fast[$i]-$slow[$i]:null;
        $signal=self::ema($macd,19);$hist=[];
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
        return (object)['formula_version'=>self::FORMULA,'bars_as_of'=>$b[$n-1][0],'bar_count'=>$n,
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
    }
    public static function active($r): bool {
        $s=mon_get($r,'swing_signal');
        return $s instanceof stdClass && mon_get($s,'formula_version')===self::FORMULA && mon_get($s,'eligible')===true &&
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
                    $s=self::technical($b,$live);foreach($s as $k=>$v)$r->$k=$v;
                    $r->bar_input_sha256=hash('sha256',MonRun::json((object)['bars'=>$bars,'benchmark_id'=>$benchId,'benchmark'=>$bb,'rules'=>self::rules()]));
                    $r->comparison_current=mon_get($periods[$country]??null,'current_period')===true && mon_get($periods[$country]??null,'verified_completed_date')===$last;
                    if(!$r->market_strong)$r->reason_codes[]='not_stronger_than_market';
                    if(!$r->technical_qualified)$r->reason_codes[]=$r->macd_status==='needs_history'?'macd_history_missing_no_ma_reclaim':'no_swing_signal';
                    if(!$r->comparison_current)$r->reason_codes[]='latest_comparison_pending';
                    $r->eligible=$r->market_strong && $r->technical_qualified && $r->comparison_current;
                    $missingSignal=$r->market_strong && !$r->technical_qualified && $r->macd_status==='needs_history';
                    $r->eligibility=$r->eligible?'pass':($r->comparison_current && !$missingSignal?'near':'unverified');
                }
            }catch(MonFault $e){$r->reason_codes[]=$e->getMessage();}
            $r->entry_plans=mon_get($previousSelection[$sid]??null,'entry_plans',mon_get(mon_get($p->final_results_details,$sid),'entry_plans'));
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
        $out=$prepared;unset($out['full_signals']);$cards=$out['cards'];$out['cards']=[];$pages=[];
        foreach($cards as $card){$test=$out;$test['cards'][]=$card;if(strlen(MonRun::json((object)$test))>MonRun::VIEW_BYTES && $out['cards']){$pages[]=$out['cards'];$out['cards']=[];}$out['cards'][]=$card;}
        $pages[]=$out['cards'];$out['cards']=$pages[0];$out['additional_card_pages']=array_slice($pages,1);
        $out['paging_note']='Read all card pages; same prepared_fingerprint and state_token';return $out;
    }
    public static function archive($d,$p): void {
        if(!(mon_get($p,'strategy_archive') instanceof stdClass))$p->strategy_archive=new stdClass();
        $key=$d->analysis->criteria_version.':'.$d->analysis->run_id;
        if(!property_exists($p->strategy_archive,$key))$p->strategy_archive->$key=(object)[
            'analysis'=>MonRun::pick($d->analysis,['criteria_version','run_id','as_of','selection_fingerprint','input_fingerprint','result_fingerprint','results','independent_results','candidate_audit','coverage','limitations','changes']),
            'selection_facts'=>$p->selection_facts,'input_facts'=>$p->input_facts,
            'final_results_details'=>$p->final_results_details,'independent_results'=>$p->independent_results];
    }
    public static function finalize($base,$out,$p,$context,array $reasons): array {
        $a=$out->analysis;$a->selection_fingerprint=MonRun::digest($p->selection_facts);
        $p->input_facts->selection_fingerprint=$a->selection_fingerprint;$a->input_fingerprint=MonRun::digest($p->input_facts);
        $a->result_fingerprint=MonRun::digest(MonRun::business($p->final_results_details));
        $a->input_snapshot->compressed_fields=array_keys(get_object_vars($p));sort($a->input_snapshot->compressed_fields,SORT_STRING);
        // P3 uses PHP-native gzip so the NAS web path does not require an xz command.
        $a->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');
        if(!MonRun::storage($out,false)->fits){MonRun::compactEvidence($out,$p);$a->input_snapshot->compressed_fields=array_keys(get_object_vars($p));sort($a->input_snapshot->compressed_fields,SORT_STRING);$a->input_snapshot->lossless_payload=MonRun::pack($p,'gzip+base64');}
        MonRun::validate($out);MonRun::verifyFacts($out,$p);$storage=MonRun::storage($out,true);
        if(MonRun::json($base->collection)!==MonRun::json($out->collection))throw new MonFault('MON_OWNERSHIP_INVALID');
        $text=MonRun::json($out);$summary=MonRun::render($out,$context->rendered_at,$reasons);
        return ['status'=>'changed','write_required'=>true,'files'=>[['path'=>'mon_data.json','content'=>$text,'blob_sha'=>MonRun::gitSha($text)],['path'=>'mon_result.md','content'=>$summary,'blob_sha'=>MonRun::gitSha($summary)]],
            'summary'=>$summary,'validation'=>['criteria_version'=>self::CRITERIA,'collection_preserved'=>true,'payload_hash_verified'=>true,'selection_fingerprint'=>$a->selection_fingerprint,'input_fingerprint'=>$a->input_fingerprint,'result_fingerprint'=>$a->result_fingerprint,'storage'=>$storage]];
    }
    public static function migrate($d,$context): array {
        $c=MonRun::context($context);$prepared=self::prepare($d,$context);
        if($d->analysis->criteria_version===self::CRITERIA)return ['status'=>'no_change','write_required'=>false,'files'=>[],'prepared'=>self::prepareView($prepared)];
        $p=MonRun::payload($d);self::archive($d,$p);$out=mon_decode(MonRun::json($d));$a=$out->analysis;
        $out->watchlist->criteria_version=self::CRITERIA;$out->watchlist->settings->investment_style='swing';$out->watchlist->settings->swing_profile=self::rules();
        if(!(mon_get($out->watchlist->settings,'analysis_storage_policy')instanceof stdClass))$out->watchlist->settings->analysis_storage_policy=new stdClass();
        $out->watchlist->settings->analysis_storage_policy->max_bytes=MonRun::MAX_SOURCE;
        $out->watchlist->watchlist_version++;$out->watchlist->updated_at=$c->rendered_at;$out->watchlist->run_id='mon-swing-migrate-'.gmdate('YmdHis',(int)MonRun::seconds($c->rendered_at));
        foreach($out->watchlist->symbols as $row)if(!$row->is_held)$row->purpose='스윙 후보 관찰';
        $a->criteria_version=self::CRITERIA;$a->as_of=$c->rendered_at;$a->analyzed_at=$c->rendered_at;$a->run_id=$out->watchlist->run_id;
        $a->watchlist_version=$out->watchlist->watchlist_version;$a->collection_id=$d->collection->collection_id;$a->status='partial';
        $oldFull=$p->final_results_details;$p->swing_signals=$prepared['full_signals'];$p->final_results_details=new stdClass();$p->independent_results=new stdClass();
        $a->results=[];$a->independent_results=[];$audit=[];
        foreach($out->watchlist->symbols as $wr)if($wr->is_held && property_exists($oldFull,$wr->symbol_id)){
            $held=mon_decode(MonRun::json($oldFull->{$wr->symbol_id}));if($held->action==='BUY_REVIEW')$held->action='HOLD';
            $p->final_results_details->{$wr->symbol_id}=$held;$a->results[]=$held;
        }
        foreach($a->candidate_audit as $row){$s=$p->swing_signals->{$row->symbol_id};$row->previous_eligibility=$row->eligibility;$row->eligibility=$s->eligibility;$row->rank=$s->rank_hint;$row->failed_checks=$s->reason_codes;$row->swing_signal_ref='swing_signals:'.$row->symbol_id;$audit[]=$row;}$a->candidate_audit=$audit;
        $a->swing_preparation=(object)['formula_version'=>self::FORMULA,'prepared_at'=>$c->rendered_at,'prepared_fingerprint'=>$prepared['prepared_fingerprint'],'candidate_count'=>$prepared['candidate_count'],'counts'=>$prepared['counts'],'final_selector'=>'ChatGPT','selection_status'=>'awaiting_autonomous_selection'];
        foreach($a->coverage as $row){$row->buy_review_count=0;$row->ready_count=0;$row->observed_count=0;$row->price_verified_count=0;$row->pass_count=$prepared['counts'][$row->country]['pass']??0;$row->near_count=$prepared['counts'][$row->country]['near']??0;$row->unverified_count=$prepared['counts'][$row->country]['unverified']??0;$row->fail_count=$prepared['counts'][$row->country]['fail']??0;$row->reason='MON-P3.0 스윙 신호 계산; ChatGPT 자율 최종선정 대기';}
        $p->selection_facts=(object)['criteria_version'=>self::CRITERIA,'rules'=>self::rules(),'candidates_ref'=>'swing_signals','candidate_facts_sha256'=>MonRun::digest(array_values(get_object_vars($p->swing_signals))),'selected'=>[], 'positions'=>mon_get($p->selection_facts,'positions',[]),'corporate_risk'=>mon_get($p->selection_facts,'corporate_risk',new stdClass())];
        $p->input_facts=(object)['criteria_version'=>self::CRITERIA,'as_of'=>$a->as_of,'selection_fingerprint'=>'','collection_id'=>$a->collection_id,'collection_status'=>$d->collection->status,'used_prices'=>[],'corporate_risk_status'=>'needs_check','trading_status_check'=>(object)['status'=>'needs_check']];
        $a->input_snapshot->swing_rules=self::rules();$a->input_snapshot->mon_input_state=(object)['run_contract'=>MonRun::CONTRACT,'run_mode'=>'swing_migration','preparation_running'=>false,'selection_status'=>'awaiting_autonomous_selection'];
        $a->limitations=['새 기준의 수치 자격을 계산했으며 ChatGPT의 최종 자율 선정은 대기 중','실시간 가격·거래 가능 여부·개별 기업 위험 확인 필요','미국10/9 최신 지수 비교 미완료; 이전 추천은 새 추천으로 승계하지 않음'];$a->limitations_as_of=$a->as_of;
        $a->changes[]=(object)['at'=>$a->as_of,'kind'=>'swing_criteria_migration','from'=>'MON-P2.0','to'=>self::CRITERIA,'old_buy_reviews_archived'=>true,'watchlist_version_before'=>$d->watchlist->watchlist_version,'watchlist_version_after'=>$out->watchlist->watchlist_version];
        $a->display=(object)['analysis_as_of'=>$a->as_of,'rendered_at'=>$a->as_of,'analysis_recomputed'=>true,'selection_recomputed'=>false];
        return self::finalize($d,$out,$p,$c,['스윙 기준 적용; 이전 추천은 이력으로 보존하고 새 추천 확정 대기']);
    }
    public static function select($d,$decision): array {
        if(!($decision instanceof stdClass))throw new MonFault('MON_SWING_DECISION_INVALID');
        $c=MonRun::context(mon_get($decision,'view_context'));$fixed=MonRun::utc(mon_get($decision,'fixed_as_of'));
        if(MonRun::seconds($fixed)<MonRun::seconds($c->rendered_at) || MonRun::seconds($fixed)<MonRun::seconds($d->analysis->as_of))throw new MonFault('MON_PATCH_AS_OF_INVALID');
        if($d->analysis->criteria_version!==self::CRITERIA)throw new MonFault('MON_SWING_MIGRATION_REQUIRED');
        $prepared=self::prepare($d,$decision->view_context,mon_get($decision,'live_observations',[]));
        if(!hash_equals($prepared['state_token'],(string)mon_get($decision,'state_token','')) || !hash_equals($prepared['prepared_fingerprint'],(string)mon_get($decision,'prepared_fingerprint','')))throw new MonFault('MON_STATE_TOKEN_INVALID');
        $selected=mon_get($decision,'selected');if(!is_array($selected))throw new MonFault('MON_SWING_DECISION_INVALID');
        $ids=[];$perCountry=[];
        foreach($selected as $r){$sid=mon_get($r,'symbol_id');MonRun::identity($sid);$s=mon_get($prepared['full_signals'],$sid);
            if(isset($ids[$sid]) || !($s instanceof stdClass) || !$s->eligible || !is_string(mon_get($r,'reason')) || trim($r->reason)==='')throw new MonFault('MON_SWING_SELECTION_NOT_ELIGIBLE');
            $country=explode('|',$sid)[0];$perCountry[$country]=($perCountry[$country]??0)+1;if($perCountry[$country]>3)throw new MonFault('MON_RESULT_LIMIT_INVALID');$ids[$sid]=$r;}
        $p=MonRun::payload($d);self::archive($d,$p);$out=mon_decode(MonRun::json($d));$a=$out->analysis;$watch=MonRun::rows($out->watchlist->symbols);$oldFull=$p->final_results_details;
        $p->swing_signals=$prepared['full_signals'];$p->final_results_details=new stdClass();$p->independent_results=new stdClass();$a->results=[];$a->independent_results=[];
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
                'entry_plans'=>$s->entry_plans,'active_plan'=>null,'entry_zone'=>is_array($s->entry_plans)&&$s->entry_plans?mon_get($s->entry_plans[0],'entry_zone'):null,
                'invalidation'=>is_array($s->entry_plans)&&$s->entry_plans?mon_get($s->entry_plans[0],'invalidation'):null,
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
        return self::finalize($d,$out,$p,$c,['ChatGPT 자율 스윙 선정; 현재 진입 확인은 별도']);
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
        if (mon_get($m, 'current_period') !== true) $pending[] = (object)['country' => $country, 'kind' => mon_get($m, 'comparison_status') === 'pending_benchmark' ? 'benchmark' : 'completed_period', 'target_date' => mon_get($m, 'target_completed_date'), 'status' => 'preparation_required', 'ranking_scope' => 'previous_reference'];
    }
    foreach ($results as $sid => $r) {
        $country = explode('|', $sid)[0]; $held = mon_get($watch[$sid] ?? null, 'is_held') === true; $action = mon_get($r, 'action');
        $event = isset($events[$sid]) || mon_get(mon_get($r, 'risk_checks'), 'confirmed_material_adverse') === true || in_array($sid, $c->important_ids, true);
        $include = $held || $action === 'SELL_REVIEW' || $event || ($action === 'BUY_REVIEW' && in_array($country, $c->countries, true));
        if (!$include) continue;
        if (!$held && $action === 'BUY_REVIEW') { $counts[$country] = ($counts[$country] ?? 0) + 1; if ($counts[$country] > 3) throw new MonFault('MON_RESULT_LIMIT_INVALID'); }
        $card = MonRun::pick($r, ['symbol_id', 'country', 'name', 'currency', 'rank', 'action', 'eligibility', 'entry_plans', 'active_plan', 'plan_valid_until', 'price', 'price_as_of', 'price_type', 'risk_checks', 'details_ref', 'swing_signal', 'autonomous_decision']);
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
    for (; $i < count($cards); $i++) {
        $view['cards'][] = $cards[$i]; $view['page']['remaining_cards'] = count($cards) - $i - 1; $view['page']['next_cursor'] = $i + 1 < count($cards) ? $i + 1 : null;
        if (strlen(MonRun::json((object)$view)) > MonRun::VIEW_BYTES) { array_pop($view['cards']); break; }
    }
    $view['page']['remaining_cards'] = count($cards) - $i; $view['page']['next_cursor'] = $i < count($cards) ? $i : null;
    $bytes = strlen(MonRun::json((object)$view));
    if ($bytes > MonRun::VIEW_BYTES || (!$view['cards'] && $i < count($cards))) throw new MonFault('MON_VIEW_ITEM_TOO_LARGE');
    return ['state_token' => $token, 'model_input_bytes' => $bytes, 'view' => $view];
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
        foreach ($change as $k => $v) if (!in_array($k, ['symbol_id', 'price_observation', 'risk_review', 'reason', 'next_check'], true)) throw new MonFault('MON_PATCH_PREPARATION_REQUIRED');
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
        $riskCurrent = false; $tradingCurrent = false;
        try {
            $checkedAt = MonRun::utc(mon_get($review, 'checked_at')); $riskAge = MonRun::seconds($fixed) - MonRun::seconds($checkedAt); $zone = MonMarket::timezone($r->country);
            $riskCurrent = $riskAge >= -5 && (new DateTimeImmutable($checkedAt))->setTimezone($zone)->format('Y-m-d') === (new DateTimeImmutable($fixed))->setTimezone($zone)->format('Y-m-d');
            $tradingCurrent = $riskCurrent && $riskAge <= 300;
        } catch (MonFault $ignore) {}
        if (!(mon_get($r, 'risk_checks') instanceof stdClass)) $r->risk_checks = new stdClass();
        $r->risk_checks->current_corporate_risk_verified = $riskCurrent && mon_get($review, 'status') === 'verified' && !mon_get($review, 'pending_items', []);
        $r->risk_checks->current_trading_status_verified = $tradingCurrent && mon_get($review, 'trading_status') === 'verified';
        if ($r->risk_checks->current_corporate_risk_verified || $r->risk_checks->current_trading_status_verified) $r->risk_checks->verified_as_of = $checkedAt;
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
function mon_run_web(string $api): int {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); $start = microtime(true);
    try {
        if (!in_array($api, ['mon_view', 'mon_patch', 'mon_swing_prepare', 'mon_swing_migrate', 'mon_swing_select'], true)) throw new MonFault('MON_API_UNKNOWN');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new MonFault('MON_METHOD_INVALID');
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') throw new MonFault('MON_CONTENT_TYPE_INVALID');
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MonRun::MAX_BODY) throw new MonFault('MON_BODY_TOO_LARGE');
        $raw = file_get_contents('php://input', false, null, 0, MonRun::MAX_BODY + 1);
        if (!is_string($raw) || strlen($raw) > MonRun::MAX_BODY) throw new MonFault('MON_BODY_TOO_LARGE');
        try { $request = mon_decode($raw); } catch (Throwable $e) { throw new MonFault('MON_REQUEST_JSON_INVALID'); }
        if (!($request instanceof stdClass)) throw new MonFault('MON_REQUEST_INVALID');
        $context = in_array($api,['mon_view','mon_swing_prepare','mon_swing_migrate'],true) ? mon_get($request,'context') : mon_get(mon_get($request,$api === 'mon_swing_select' ? 'decision' : 'decision_patch'),'view_context');
        $c = MonRun::context($context); if (MonRun::seconds($c->rendered_at) > microtime(true) + 5) throw new MonFault('MON_TIME_FUTURE');
        $data = mon_run_source($request, 'source_json', $c->source_blob_sha);
        if ($api === 'mon_swing_prepare') $result=MonSwing::prepareView(MonSwing::prepare($data,$context,mon_get($request,'live_observations',[])));
        elseif ($api === 'mon_swing_migrate') $result=MonSwing::migrate($data,$context);
        elseif ($api === 'mon_swing_select') {
            if(MonRun::seconds(mon_get(mon_get($request,'decision'),'fixed_as_of'))>microtime(true)+5)throw new MonFault('MON_TIME_FUTURE');
            $result=MonSwing::select($data,mon_get($request,'decision'));
        }
        elseif ($api === 'mon_view') $result = mon_project_for_mon($data, $context);
        else {
            $patch = mon_get($request, 'decision_patch');
            if (MonRun::seconds(mon_get($patch, 'fixed_as_of')) > microtime(true) + 5) throw new MonFault('MON_TIME_FUTURE');
            $result = mon_apply_mon_patch($data, $patch);
            if (property_exists($request, 'latest_source_json')) {
                $latest = mon_run_source($request, 'latest_source_json', (string)mon_get($request, 'latest_source_blob_sha', ''));
                if ($result['write_required']) {
                    $candidate = mon_decode($result['files'][0]['content']); $merged = MonRun::rebase($data, $candidate, $latest); $text = MonRun::json($merged);
                    $result['files'][0]['content'] = $text; $result['files'][0]['blob_sha'] = MonRun::gitSha($text);
                    $result['validation']['storage'] = MonRun::storage($merged, true); $result['validation']['rebase_source_blob_sha'] = $request->latest_source_blob_sha;
                } else MonRun::rebase($data, $data, $latest);
            }
        }
        if ($api === 'mon_view' && mon_get($request, 'all_pages', true) === true) {
            $result['additional_views'] = [];
            $cursor = $result['view']['page']['next_cursor'];
            while ($cursor !== null) {
                $next = clone $context; $next->cursor = $cursor; $next->state_token = $result['state_token']; $part = mon_project_for_mon($data, $next);
                $result['additional_views'][] = ['model_input_bytes' => $part['model_input_bytes'], 'view' => $part['view']];
                $cursor = $part['view']['page']['next_cursor'];
            }
        }
        if (in_array($api,['mon_swing_migrate','mon_swing_select'],true) && property_exists($request,'latest_source_json')) {
            $latest=mon_run_source($request,'latest_source_json',(string)mon_get($request,'latest_source_blob_sha',''));
            if ($result['write_required']) {
                $candidate=mon_decode($result['files'][0]['content']);$merged=MonRun::rebase($data,$candidate,$latest);$text=MonRun::json($merged);
                $result['files'][0]['content']=$text;$result['files'][0]['blob_sha']=MonRun::gitSha($text);
                $result['validation']['storage']=MonRun::storage($merged,true);$result['validation']['rebase_source_blob_sha']=$request->latest_source_blob_sha;
            } else MonRun::rebase($data,$data,$latest);
        }
        echo mon_json(['ok' => true, 'api' => $api, 'processor_version' => MON_VERSION, 'handler_seconds' => microtime(true) - $start, 'result' => $result], false); return 0;
    } catch (MonFault $e) {
        $code = $e->faultCode;
        if ($code === 'MON_METHOD_INVALID') { http_response_code(405); header('Allow: POST'); }
        elseif ($code === 'MON_API_UNKNOWN') http_response_code(404);
        elseif ($code === 'MON_CONTENT_TYPE_INVALID') http_response_code(415);
        elseif (in_array($code, ['MON_BODY_TOO_LARGE', 'MON_STORAGE_LIMIT', 'MON_PAYLOAD_TOO_LARGE'], true)) http_response_code(413);
        elseif (in_array($code, ['MON_ANALYSIS_SOURCE_CONFLICT', 'MON_STATE_TOKEN_INVALID', 'MON_SOURCE_SHA_MISMATCH'], true)) http_response_code(409);
        elseif (in_array($code, ['MON_XZ_UNAVAILABLE', 'MON_CODEC_UNAVAILABLE', 'MON_CODEC_TIMEOUT', 'MON_CODEC_TEMP_FAILED'], true)) http_response_code(503);
        else http_response_code(400);
        echo mon_json(['ok' => false, 'api' => $api, 'processor_version' => MON_VERSION, 'error_code' => $code, 'write_required' => false, 'remote_written' => false, 'handler_seconds' => microtime(true) - $start], false); return 1;
    } catch (Throwable $e) {
        http_response_code(500); echo mon_json(['ok' => false, 'api' => $api, 'processor_version' => MON_VERSION, 'error_code' => 'MON_HANDLER_INTERNAL_ERROR', 'write_required' => false, 'remote_written' => false], false); return 1;
    }
}

function mon_web(): int {
    if (isset($_GET['api'])) return mon_run_web((string)$_GET['api']);
    header('Cache-Control: no-store');
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
    $mode='daemon'; $envFile=getenv('MON_ENV_FILE')?:''; $dir=getenv('MON_STATE_DIR')?:__DIR__; $maxTicks=0; $instance=bin2hex(random_bytes(12));
    foreach (array_slice($argv,1) as $arg) {
        if (in_array($arg,['--run','--daemon','--once','--status','--stop','--check','--help'],true)) $mode=substr($arg,2);
        elseif (strpos($arg,'--env=')===0) $envFile=substr($arg,6);
        elseif (strpos($arg,'--state-dir=')===0) $dir=substr($arg,12);
        elseif (preg_match('/^--max-ticks=(\d+)$/',$arg,$m)) $maxTicks=(int)$m[1];
        elseif (preg_match('/^--instance=([a-f0-9]{24})$/',$arg,$m)) $instance=$m[1];
        else throw new MonFault('CLI_OPTION_INVALID');
    }
    if ($mode==='help') {
        echo 'mon.php '.MON_VERSION." (PHP 7.4+ CLI)\nphp74 mon.php = 데몬 시작 (옵션 생략 가능)\n--daemon 시작 / --run 전면 실행 / --once 한 주기 / --status 상태 / --stop 종료 / --check 설정\n기본 설정은 코드 상단 MON_CONFIG, 상태 파일은 mon.php와 같은 폴더\n"; return 0;
    }
    $store=new MonStore($dir);
    if ($mode==='check') {
        $cfg=new MonConfig($envFile,$dir);
        echo mon_json(['php'=>PHP_VERSION,'curl'=>extension_loaded('curl'),'pcntl'=>function_exists('pcntl_fork'),'github_token_present'=>$cfg->get('GITHUB_TOKEN')!=='','kis_credentials_present'=>$cfg->get('KIS_APP_KEY')!==''&&$cfg->get('KIS_APP_SECRET')!=='','repository'=>$cfg->get('MON_REPOSITORY','wskimgit/stock'),'branch'=>$cfg->get('MON_BRANCH','main'),'state_directory'=>$store->dir,'state_directory_writable'=>is_writable($dir)]); return 0;
    }
    if ($mode==='status') { echo mon_json(mon_status($store)); return 0; }
    if ($mode==='stop') {
        $s=mon_status($store); if (!$s['running']) { echo "이미 중지되어 있습니다.\n"; return 0; }
        $id=mon_get($s['status'],'instance_id'); if (!is_string($id)) throw new MonFault('DAEMON_STARTING_RETRY_STOP');
        $store->write('stop.json',(object)['instance_id'=>$id,'requested_at'=>mon_iso()]); echo "종료 요청을 기록했습니다.\n"; return 0;
    }
    if ($mode==='daemon') {
        if (mon_status($store)['running']) { echo "이미 실행 중입니다.\n"; return 0; }
        if (DIRECTORY_SEPARATOR!=='/') throw new MonFault('USE_FOREGROUND_WITH_SERVICE_MANAGER');
        if (function_exists('pcntl_fork') && function_exists('posix_setsid')) {
            $pid=pcntl_fork(); if ($pid<0) throw new MonFault('DAEMON_FORK_FAILED');
            if ($pid===0) {
                if (posix_setsid()<0) exit(1);
                $child=pcntl_fork(); if ($child<0) exit(1); if ($child>0) exit(0);
                // Release inherited caller pipes so the parent command can complete.
                @fclose(STDIN); @fclose(STDOUT); @fclose(STDERR);
                $in=fopen('/dev/null','r'); $out=fopen($store->path('launcher.log'),'a'); $err=fopen($store->path('launcher.log'),'a');
                exit((new MonDaemon($store,$envFile,$instance))->run($maxTicks));
            }
        } else {
            if (!function_exists('exec')) throw new MonFault('USE_FOREGROUND_WITH_SERVICE_MANAGER');
            $command='nohup '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --run --env='.escapeshellarg($envFile).' --state-dir='.escapeshellarg($dir).' --instance='.escapeshellarg($instance).' > '.escapeshellarg($store->path('launcher.log')).' 2>&1 < /dev/null &';
            exec($command);
        }
        $end=microtime(true)+5;
        do {
            $s=mon_status($store);
            if ($s['running']&&mon_get($s['status'],'instance_id')===$instance) { echo "mon 데몬을 시작했습니다. PID=".mon_get($s['status'],'pid')."\n"; return 0; }
            usleep(100000);
        } while (microtime(true)<$end);
        throw new MonFault('DAEMON_START_NOT_CONFIRMED');
    }
    if (!extension_loaded('curl')) throw new MonFault('PHP_CURL_REQUIRED');
    return (new MonDaemon($store,$envFile,$instance))->run($mode==='once'?1:$maxTicks);
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try { exit(mon_main($argv??[])); }
    catch (MonFault $e) { if (defined('STDERR')) fwrite(STDERR,$e->faultCode."\n"); exit($e->faultCode==='ALREADY_RUNNING'?0:1); }
    catch (Throwable $e) { if (defined('STDERR')) fwrite(STDERR,"MON_START_FAILED\n"); exit(1); }
}


<?php
/**
 * mon.php 1.0.0 -- PHP 7.4+ CLI quote collector / persistent daemon.
 * Repository: wskimgit/stock; data interface: mon_data.json schema 3.
 * Only collection is written. Selection, orders and mon_result.md belong to mon.
 */
declare(strict_types=1);

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
function mon_json($value): string {
    $s = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
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
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true)) throw new MonFault('STATE_DIRECTORY_UNWRITABLE');
        @chmod($this->dir, 0700);
    }
    public function path(string $name): string { return $this->dir . DIRECTORY_SEPARATOR . $name; }
    public function read(string $name) {
        $p = $this->path($name);
        if (!is_file($p)) return null;
        $s = @file_get_contents($p);
        if ($s === false) throw new MonFault('STATE_READ_FAILED');
        try { return mon_decode($s); } catch (Throwable $e) { throw new MonFault('STATE_JSON_INVALID'); }
    }
    public function write(string $name, $value): void {
        $tmp = @tempnam($this->dir, 'mon_');
        if ($tmp === false) throw new MonFault('STATE_WRITE_FAILED');
        try {
            if (@file_put_contents($tmp, mon_json($value), LOCK_EX) === false) throw new MonFault('STATE_WRITE_FAILED');
            @chmod($tmp, 0600);
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
        @chmod($p, 0600);
    }
}

final class MonConfig {
    public $values;
    public function __construct(?string $envFile = null) {
        $values = [];
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
    }
    public function get(string $key, string $default = ''): string {
        $v = getenv($key);
        return $v !== false ? $v : ($this->values[$key] ?? $default);
    }
    public function int(string $key, int $default, int $min, int $max): int {
        $s = $this->get($key, (string)$default);
        if (!preg_match('/^\d+$/', $s)) throw new MonFault('ENV_NUMBER_INVALID');
        return max($min, min($max, (int)$s));
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
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'mon.php/1.0.0',
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
        if ($d->analysis->criteria_version!=='MON-P2.0' || !($w->calendar instanceof stdClass)) throw new MonFault('DATA_CRITERIA_INVALID');
        if (mon_get($w, 'criteria_version') !== 'MON-P2.0' || !is_int(mon_get($w, 'watchlist_version')) || $w->watchlist_version < 0 || !(mon_get($w, 'settings') instanceof stdClass) || !is_bool(mon_get($w->settings, 'enabled')) || !is_array(mon_get($w, 'symbols'))) throw new MonFault('WATCHLIST_INVALID');
        if (!is_array(mon_get($d->collection, 'quotes'))) throw new MonFault('COLLECTION_INVALID');
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
    private function headers(): array {
        $token=$this->config->get('GITHUB_TOKEN'); if ($token==='') throw new MonFault('GITHUB_TOKEN_MISSING');
        return ['Accept: application/vnd.github+json','Authorization: Bearer '.$token,'Content-Type: application/json','X-GitHub-Api-Version: 2022-11-28'];
    }
    public function read(float $deadline, float $timeout=3): array {
        $url=$this->url.'?'.http_build_query(['ref'=>$this->config->get('MON_BRANCH','main')]);
        $d=MonHttp::decode($this->http->request('GET',$url,$this->headers(),null,$deadline,$timeout));
        if (mon_get($d,'encoding')!=='base64' || !is_string(mon_get($d,'sha')) || !is_string(mon_get($d,'content'))) throw new MonFault('GITHUB_CONTENT_INVALID');
        $raw=base64_decode(str_replace(["\r","\n"],'',$d->content),true);
        if ($raw===false || strlen($raw)>524288) throw new MonFault('DATA_SIZE_INVALID');
        try { $data=mon_decode($raw); } catch (Throwable $e) { throw new MonFault('DATA_JSON_INVALID'); }
        MonData::validate($data); return ['sha'=>$d->sha,'data'=>$data];
    }
    public function publish($batch, string $signature, float $deadline, array $settings): array {
        for ($attempt=0; $attempt <= $settings['github_conflict_retries']; $attempt++) {
            $latest=$this->read($deadline,$settings['github_http_timeout_seconds']);
            if (!$latest['data']->watchlist->settings->enabled || MonData::signature($latest['data']->watchlist)!==$signature) throw new MonFault('WATCHLIST_CHANGED');
            $latest['data']->collection=$batch; // Preserve all other objects and unknown fields.
            $raw=mon_json($latest['data']); if (strlen($raw)>524288) throw new MonFault('DATA_SIZE_EXCEEDED');
            $body=mon_json(['message'=>'mon: mirror collection '.$batch->collection_id,'content'=>base64_encode($raw),'sha'=>$latest['sha'],'branch'=>$this->config->get('MON_BRANCH','main')]);
            $r=$this->http->request('PUT',$this->url,$this->headers(),$body,$deadline,$settings['github_http_timeout_seconds']);
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
    private $http;
    private $store;
    private $config;
    private $control;
    private $clock;
    private $providerCooldown=[];
    public function __construct(MonHttp $http, MonStore $store, MonConfig $config, ?MonControl $control=null, ?callable $clock=null) {
        $this->http=$http; $this->store=$store; $this->config=$config; $this->control=$control; $this->clock=$clock ?: static function(){return time();};
    }
    private function now(): int { return (int)($this->clock)(); }
    public function configure(MonConfig $config): void { $this->config=$config; }
    private function token(float $deadline, array $settings): string {
        $key=$this->config->get('KIS_APP_KEY'); $secret=$this->config->get('KIS_APP_SECRET');
        if ($key==='' || $secret==='') throw new MonFault('KIS_CREDENTIALS_MISSING');
        $signature=hash('sha256',$key."\0".$secret); $cached=$this->store->read('kis_token.json');
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
        $this->store->write('kis_token.json',(object)['signature'=>$signature,'access_token'=>$token,'expires_at'=>$expires]); return $token;
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
            if (in_array($code,['EGW00121','EGW00123'],true)) @unlink($this->store->path('kis_token.json'));
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
        $providerStatus=mon_get($r,'marketStatus'); $session=$providerStatus==='OPEN'?'regular':($providerStatus==='CLOSE'?'closed':$market['session']);
        $delay=mon_number($this->config->get('NAVER_DELAY_SECONDS_KR',''));
        $p=$this->point($s,'NAVER',$symbol,$price,null,$at,$date,$session,$session==='closed'?'close':'last',$at===null?'unknown':($session==='closed'?'close':'trade'),$delay,false);
        $p->change_pct=mon_number(mon_get($r,'fluctuationsRatio'));
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
        $price=mon_number(mon_get($meta,'regularMarketPrice')); $rawAt=mon_get($meta,'regularMarketTime');
        if ($price===null||$price<=0||!is_int($rawAt)||$rawAt<=0) throw new MonFault('YAHOO_QUOTE_INVALID');
        $tz=MonMarket::timezone($s->country); $date=(new DateTimeImmutable('@'.$rawAt))->setTimezone($tz)->format('Y-m-d');
        $market=MonMarket::state($w,$s->country,$this->now()); $session=$market['session'];
        $regular=mon_get(mon_get($meta,'currentTradingPeriod'),'regular'); $start=mon_get($regular,'start'); $end=mon_get($regular,'end');
        if (is_int($start)&&is_int($end)&&$start<$end) $session=$this->now()>=$start&&$this->now()<$end?'regular':'closed';
        // Zero has an unambiguous meaning. Positive undocumented units are not guessed.
        $providerDelay=mon_number(mon_get($meta,'exchangeDataDelayedBy'));
        $delay=$providerDelay===0.0?0.0:mon_number($this->config->get('YAHOO_DELAY_SECONDS_'.$s->country,''));
        if ($delay!==null&&$delay<0) throw new MonFault('YAHOO_DELAY_INVALID');
        return [$this->point($s,'YAHOO',$symbol,$price,null,$rawAt,$date,$session,'last','trade',$delay,false)];
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
        $this->attempted=0;
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
    public function __construct(MonStore $store,string $envFile,string $instance) {
        $this->store=$store; $this->envFile=$envFile; $this->control=new MonControl($store,$instance); $this->started=mon_iso();
        $this->http=new MonHttp($this->control);
    }
    private function heartbeat(string $state,?string $error=null): void {
        if ($error!==null) $this->lastError=$error;
        elseif (in_array($state,['paused','empty_watchlist','mirrored','collected'],true)) $this->lastError=null;
        $this->store->write('status.json',(object)['version'=>'1.0.0','instance_id'=>$this->control->instance,'pid'=>getmypid(),'started_at'=>$this->started,'heartbeat_at'=>mon_iso(),'state'=>$state,'ticks'=>$this->ticks,'last_published_at'=>$this->lastPublished?mon_iso($this->lastPublished):null,'error_code'=>$this->lastError]);
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
        $start=microtime(true); $cfg=new MonConfig($this->envFile);
        $this->poll=$cfg->int('MON_IDLE_POLL_SECONDS',60,1,3600);
        $gh=new MonGitHub($this->http,$cfg); $latest=$gh->read($start+10); $data=$latest['data']; $w=$data->watchlist; $settings=MonData::settings($w); $this->poll=$settings['poll_seconds'];
        $this->store->write('remote_cache.json',$data);
        if (!$w->settings->enabled || count($w->symbols)===0) { $this->heartbeat($w->settings->enabled?'empty_watchlist':'paused'); return; }
        $signature=MonData::signature($w); $pending=$this->store->read('pending.json');
        $previous=mon_get($pending,'signature')===$signature?mon_get($pending,'collection'):$data->collection;
        $cred=hash('sha256',$cfg->get('KIS_APP_KEY')."\0".$cfg->get('KIS_APP_SECRET'));
        if (!$this->collector || $cred!==$this->credentialSignature) { $this->collector=new MonCollector($this->http,$this->store,$cfg,$this->control); $this->credentialSignature=$cred; }
        else $this->collector->configure($cfg);
        $tickDeadline=$start+$settings['tick_budget_seconds'];
        $collectDeadline=min($start+$settings['collection_budget_seconds'],$tickDeadline-$settings['publish_reserve_seconds']);
        $batch=$this->collector->collect($data,$previous,$collectDeadline,$settings);
        $dirty=$this->collector->attempted>0 || (mon_get($pending,'signature')===$signature && mon_get($pending,'dirty',false));
        $this->store->write('pending.json',(object)['signature'=>$signature,'collection'=>$batch,'dirty'=>$dirty]);
        $force=self::forceMirror($w,time(),$settings);
        $due=($dirty && time()-$this->lastPublished >= $settings['mirror_seconds']) || $force;
        if ($due) {
            try {
                $saved=$gh->publish($batch,$signature,$tickDeadline,$settings); $this->lastPublished=time();
                $this->store->write('remote_cache.json',$saved['data']); @unlink($this->store->path('pending.json'));
                $this->store->log('mirror_ok',['status'=>$batch->status,'quotes'=>count($batch->quotes)]);
            } catch (MonFault $e) {
                if ($e->faultCode==='WATCHLIST_CHANGED') { @unlink($this->store->path('pending.json')); $this->heartbeat('watchlist_changed',$e->faultCode); return; }
                throw $e;
            }
        }
        $this->heartbeat($due?'mirrored':'collected');
    }
    public function run(int $maxTicks=0): int {
        $this->lock=@fopen($this->store->path('daemon.lock'),'c+');
        if (!$this->lock||!flock($this->lock,LOCK_EX|LOCK_NB)) { if ($this->lock) fclose($this->lock); throw new MonFault('ALREADY_RUNNING'); }
        @chmod($this->store->path('daemon.lock'),0600);
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true); $ctl=$this->control;
            pcntl_signal(SIGTERM,static function() use($ctl){$ctl->stop=true;}); pcntl_signal(SIGINT,static function() use($ctl){$ctl->stop=true;});
        }
        $exit=0; $this->heartbeat('starting'); $this->store->log('daemon_started');
        try {
            while (!$maxTicks || $this->ticks<$maxTicks) {
                $this->control->check(); $start=microtime(true); $this->ticks++;
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
                while ($remaining>0) { $slice=min(5,$remaining); $this->control->wait($slice); $this->heartbeat('waiting'); $remaining-=$slice; }
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
function mon_main(array $argv): int {
    if (PHP_SAPI!=='cli') { http_response_code(403); echo "mon.php는 PHP CLI에서 실행합니다.\n"; return 1; }
    $taskUserDir=getenv('HOME');
    $privateDir=$taskUserDir?rtrim($taskUserDir,DIRECTORY_SEPARATOR).'/mon_private':sys_get_temp_dir().'/mon-private-'.substr(hash('sha256',__DIR__),0,16);
    $mode='run'; $envFile=getenv('MON_ENV_FILE')?:$privateDir.'/mon.env'; $dir=getenv('MON_STATE_DIR')?:$privateDir.'/state'; $maxTicks=0; $instance=bin2hex(random_bytes(12));
    foreach (array_slice($argv,1) as $arg) {
        if (in_array($arg,['--run','--daemon','--once','--status','--stop','--check','--help'],true)) $mode=substr($arg,2);
        elseif (strpos($arg,'--env=')===0) $envFile=substr($arg,6);
        elseif (strpos($arg,'--state-dir=')===0) $dir=substr($arg,12);
        elseif (preg_match('/^--max-ticks=(\d+)$/',$arg,$m)) $maxTicks=(int)$m[1];
        elseif (preg_match('/^--instance=([a-f0-9]{24})$/',$arg,$m)) $instance=$m[1];
        else throw new MonFault('CLI_OPTION_INVALID');
    }
    if ($mode==='help') {
        echo "mon.php 1.0.0 (PHP 7.4+ CLI)\n--daemon 시작 / --run 전면 실행 / --once 한 주기 / --status 상태 / --stop 종료 / --check 설정\n--env=/비공개/mon.env --state-dir=/비공개/mon_state\n"; return 0;
    }
    $store=new MonStore($dir);
    if ($mode==='check') {
        $cfg=new MonConfig($envFile);
        echo mon_json(['php'=>PHP_VERSION,'curl'=>extension_loaded('curl'),'pcntl'=>function_exists('pcntl_fork'),'github_token_present'=>$cfg->get('GITHUB_TOKEN')!=='','kis_credentials_present'=>$cfg->get('KIS_APP_KEY')!==''&&$cfg->get('KIS_APP_SECRET')!=='','repository'=>$cfg->get('MON_REPOSITORY','wskimgit/stock'),'branch'=>$cfg->get('MON_BRANCH','main'),'state_directory_writable'=>is_writable($dir)]); return 0;
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
                @chmod($store->path('launcher.log'),0600);
                exit((new MonDaemon($store,$envFile,$instance))->run($maxTicks));
            }
        } else {
            if (!function_exists('exec')) throw new MonFault('USE_FOREGROUND_WITH_SERVICE_MANAGER');
            $command='nohup '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --run --env='.escapeshellarg($envFile).' --state-dir='.escapeshellarg($dir).' --instance='.escapeshellarg($instance).' > '.escapeshellarg($store->path('launcher.log')).' 2>&1 < /dev/null &';
            exec($command); @chmod($store->path('launcher.log'),0600);
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

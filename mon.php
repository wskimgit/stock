<?php
/**
 * MON 2.2.3 Lite — layered, single-file, fixed-runtime, completed-daily swing monitor.
 * PHP 7.4+ / manual web start only / no cron / no orders.
 * Runtime: /web/mon_runtime with a small fixed file set.
 */
declare(strict_types = 1);
const MON_LITE_VERSION = '2.2.3';
const MON_LITE_BUILD = 'MON-LITE-2.2.3-20261026-r20';
const MON_LITE_POLICY = 'MON-LITE-OPS-2.2.3';
const MON_LITE_RUNTIME = __DIR__ . '/mon_runtime';
const MON_LITE_GITHUB_REPO = 'wskimgit/stock';
const MON_LITE_GITHUB_BRANCH = 'main';
const MON_LITE_PREFIX = "<?php exit; ?>\n";
define('MON_LITE_SOURCE_SHA256', hash_file('sha256', __FILE__));

class LiteMonFault extends RuntimeException {
    public $faultCode;

    public function __construct(string $code) {
        $this->faultCode = preg_replace('/[^A-Z0-9_.-]/i', '_', $code);
        parent::__construct($this->faultCode);
    }
}

class LiteMonStop extends RuntimeException {
}

function lm_get($o, $k, $default = null) {
    if (is_array($o)) return array_key_exists($k, $o) ? $o[$k] : $default;
    if (is_object($o)) {
        $key = (string) $k;
        return property_exists($o, $key) ? $o->$key : $default;
    }
    return $default;
}

function lm_num($v): ?float {
    if (is_int($v) || is_float($v)) return is_finite((float) $v) ? (float) $v : null;
    if (is_string($v) && is_numeric(trim($v))) {
        $n = (float) $v;
        return is_finite($n) ? $n : null;
    }
    return null;
}

function lm_json($v): string {
    $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($s)) throw new LiteMonFault('JSON_ENCODE_FAILED');
    return $s;
}

function lm_decode(string $s) {
    $v = json_decode($s);
    if (json_last_error() !== JSON_ERROR_NONE) throw new LiteMonFault('JSON_DECODE_FAILED');
    return $v;
}

function lm_iso(?int $ts = null): string {
    return gmdate('Y-m-d\TH:i:s\Z', $ts ?? time());
}

function lm_hash($v): string {
    return hash('sha256', is_string($v) ? $v : lm_json($v));
}

function lm_git_blob_sha(string $bytes): string {
    $h = hash_init('sha1');
    hash_update($h, 'blob ' . strlen($bytes) . "\0");
    hash_update($h, $bytes);
    return hash_final($h);
}

function lm_round6($v): ?float {
    return $v === null ? null : (float) number_format((float) $v, 6, '.', '');
}

function lm_array($v) {
    if ($v instanceof stdClass) $v = (array) $v;
    if (is_array($v)) {
        foreach ($v as $k => $x) $v[$k] = lm_array($x);
    }
    return $v;
}

final class LiteRuntime {
    const MAX_FILE = 16777216;
    const MAX_FILES = 11;
    // guard + 6 data + log/current-old + lock + transient temp
    const MAX_TOTAL = 67108864;
    const MAX_LOG = 262144;
    const MIN_FREE = 33554432;
    const FILES = ['state', 'cache', 'request', 'result', 'evidence', 'settings'];
    private static $readyDir = null;

    private static function rawFileCount(string $dir): int {
        $count = 0;
        $it = @opendir($dir);
        if (!$it) return 0;
        try {
            while (false !== ($n = readdir($it))) {
                if ($n === '.' || $n === '..') continue;
                if (is_file($dir . '/' . $n)) $count++;
            }
        } finally {
            closedir($it);
        }
        return $count;
    }

    public static function ready(): string {
        if (self::$readyDir !== null && is_dir(self::$readyDir)) return self::$readyDir;
        $d = MON_LITE_RUNTIME;
        if (is_link($d)) throw new LiteMonFault('RUNTIME_SYMLINK_REJECTED');
        if (!is_dir($d) && !@mkdir($d, 0700, true)) throw new LiteMonFault('RUNTIME_DIRECTORY_UNWRITABLE');
        if (!is_writable($d)) throw new LiteMonFault('RUNTIME_DIRECTORY_UNWRITABLE');
        @chmod($d, 0700);
        $guard = $d . '/.mon-lite-runtime';
        if (!is_file($guard)) {
            if (self::rawFileCount($d) >= self::MAX_FILES) throw new LiteMonFault('RUNTIME_FILE_COUNT_LIMIT');
            if (@file_put_contents($guard, MON_LITE_BUILD . "\n", LOCK_EX) === false) throw new LiteMonFault('RUNTIME_GUARD_WRITE_FAILED');
            @chmod($guard, 0600);
        } else {
            $g = @file_get_contents($guard);
            if (!is_string($g) || trim($g) !== MON_LITE_BUILD) {
                if (@file_put_contents($guard, MON_LITE_BUILD . "\n", LOCK_EX) === false) throw new LiteMonFault('RUNTIME_GUARD_WRITE_FAILED');
                @chmod($guard, 0600);
            }
        }
        self::$readyDir = $d;
        return $d;
    }

    public static function path(string $name): string {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $name)) throw new LiteMonFault('STATE_NAME_INVALID');
        return self::ready() . '/' . $name;
    }

    public static function read(string $key) {
        if (!in_array($key, self::FILES, true)) throw new LiteMonFault('STATE_NAME_INVALID');
        $p = self::path($key . '.php');
        if (!is_file($p)) return new stdClass();
        $n = @filesize($p);
        if ($n === false || $n > self::MAX_FILE) throw new LiteMonFault('RUNTIME_FILE_SIZE_LIMIT');
        $h = @fopen($p, 'rb');
        if (!$h) throw new LiteMonFault('RUNTIME_READ_FAILED');
        try {
            $prefix = fread($h, strlen(MON_LITE_PREFIX));
            if ($prefix !== MON_LITE_PREFIX) rewind($h);
            $raw = stream_get_contents($h);
            if (!is_string($raw)) throw new LiteMonFault('RUNTIME_READ_FAILED');
            return lm_decode($raw);
        } finally {
            fclose($h);
        }
    }

    public static function write(string $key, $value, ?callable $merge = null): bool {
        if (!in_array($key, self::FILES, true)) throw new LiteMonFault('STATE_NAME_INVALID');
        // Serialize the entire shared-temp replacement; compare/read back using bounded streaming hashes.
        $guard = @fopen(self::path('.mon-lite-runtime'), 'r+');
        if (!$guard || !flock($guard, LOCK_EX)) {
            if ($guard) fclose($guard);
            throw new LiteMonFault('RUNTIME_WRITE_LOCK_FAILED');
        }
        try {
            if ($merge !== null) $value = $merge(self::read($key), $value);
            $json = lm_json($value);
            $length = strlen($json) + strlen(MON_LITE_PREFIX);
            if ($length > self::MAX_FILE) throw new LiteMonFault('RUNTIME_FILE_SIZE_LIMIT');
            $hash = hash_init('sha256');
            hash_update($hash, MON_LITE_PREFIX);
            hash_update($hash, $json);
            $expected = hash_final($hash);
            $p = self::path($key . '.php');
            clearstatcache(true, $p);
            if (is_file($p) && @filesize($p) === $length) {
                $current = @hash_file('sha256', $p);
                if (is_string($current) && hash_equals($current, $expected)) return false;
            }
            self::capacity($length);
            $tmp = self::path('.write.tmp');
            self::allowNewFile($tmp);
            $h = @fopen($tmp, 'wb');
            if (!$h) throw new LiteMonFault('RUNTIME_WRITE_FAILED');
            try {
                foreach ([MON_LITE_PREFIX, $json] as $part) {
                    $offset = 0;
                    $partLength = strlen($part);
                    while ($offset < $partLength) {
                        $written = @fwrite($h, substr($part, $offset, 65536));
                        if ($written === false || $written === 0) throw new LiteMonFault('RUNTIME_WRITE_FAILED');
                        $offset += $written;
                    }
                }
                if (!@fflush($h)) throw new LiteMonFault('RUNTIME_FLUSH_FAILED');
                if (function_exists('fsync') && !@fsync($h)) throw new LiteMonFault('RUNTIME_FLUSH_FAILED');
            } finally {
                fclose($h);
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $p)) {
                @unlink($tmp);
                throw new LiteMonFault('RUNTIME_RENAME_FAILED');
            }
            @chmod($p, 0600);
            $check = @hash_file('sha256', $p);
            if (!is_string($check) || !hash_equals($expected, $check)) throw new LiteMonFault('RUNTIME_READBACK_MISMATCH');
            return true;
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    public static function log(string $event, array $fields = []): void {
        $p = self::path('mon.log.php');
        $old = self::path('mon.log.old.php');
        $row = lm_json(['at' => lm_iso(), 'event' => $event, 'fields' => $fields]) . "\n";
        $new = !is_file($p);
        self::capacity(strlen($row) + ($new ? strlen(MON_LITE_PREFIX): 0));
        if (is_file($p) && @filesize($p) > self::MAX_LOG) {
            @unlink($old);
            @rename($p, $old);
            $new = true;
        }
        self::allowNewFile($p);
        if ($new && @file_put_contents($p, MON_LITE_PREFIX, LOCK_EX) !== strlen(MON_LITE_PREFIX)) throw new LiteMonFault('RUNTIME_LOG_WRITE_FAILED');
        if (@file_put_contents($p, $row, FILE_APPEND | LOCK_EX) !== strlen($row)) throw new LiteMonFault('RUNTIME_LOG_WRITE_FAILED');
        @chmod($p, 0600);
    }

    public static function lock() {
        $p = self::path('mon.lock');
        self::allowNewFile($p);
        $h = @fopen($p, 'c+');
        if (!$h) throw new LiteMonFault('RUNTIME_LOCK_FAILED');
        @chmod($p, 0600);
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            throw new LiteMonFault('ALREADY_RUNNING');
        }
        return $h;
    }

    public static function lockHeld(): bool {
        $p = self::path('mon.lock');
        if (!is_file($p)) return false;
        $h = @fopen($p, 'r+');
        if (!$h) return true;
        $ok = @flock($h, LOCK_EX | LOCK_NB);
        if ($ok) {
            @flock($h, LOCK_UN);
            fclose($h);
            return false;
        }
        fclose($h);
        return true;
    }

    private static function usage(): array {
        $d = self::ready();
        $count = 0;
        $bytes = 0;
        $it = @opendir($d);
        if (!$it) throw new LiteMonFault('RUNTIME_SCAN_FAILED');
        try {
            while (false !== ($n = readdir($it))) {
                if ($n === '.' || $n === '..') continue;
                $p = $d . '/' . $n;
                if (!is_file($p)) continue;
                $count++;
                $z = @filesize($p);
                if ($z !== false) $bytes += (int) $z;
            }
        } finally {
            closedir($it);
        }
        return ['files' => $count, 'bytes' => $bytes];
    }

    private static function allowNewFile(string $path): void {
        if (is_file($path)) return;
        $u = self::usage();
        if ($u['files'] >= self::MAX_FILES) throw new LiteMonFault('RUNTIME_FILE_COUNT_LIMIT');
    }

    public static function capacity(int $incoming = 0): void {
        $d = self::ready();
        $free = @disk_free_space($d);
        if (is_float($free) && $free < self::MIN_FREE) throw new LiteMonFault('RUNTIME_DISK_RESERVE');
        $u = self::usage();
        if ($u['files'] > self::MAX_FILES) throw new LiteMonFault('RUNTIME_FILE_COUNT_LIMIT');
        if ($u['bytes'] + $incoming > self::MAX_TOTAL) throw new LiteMonFault('RUNTIME_CAPACITY_LIMIT');
    }

    public static function cleanup(): array {
        $removed = 0;
        $p = self::path('.write.tmp');
        if (is_file($p) && time() - (int) @filemtime($p) > 3600) {
            @unlink($p);
            $removed++;
        }
        $u = self::usage();
        return [
            'removed' => $removed,
            'file_count' => $u['files'],
            'max_files' => self::MAX_FILES,
            'bytes' => $u['bytes'],
            'max_bytes' => self::MAX_TOTAL
        ];
    }

    public static function view(): array {
        $d = self::ready();
        $files = [];
        $bytes = 0;
        $it = @opendir($d);
        if ($it) {
            try {
                while (false !== ($n = readdir($it))) {
                    if ($n === '.' || $n === '..') continue;
                    $p = $d . '/' . $n;
                    if (is_file($p)) {
                        $z = (int) @filesize($p);
                        $files[] = ['name' => $n, 'bytes' => $z];
                        $bytes += $z;
                    }
                }
            } finally {
                closedir($it);
            }
        }
        return [
            'path' => $d,
            'file_count' => count($files),
            'max_files' => self::MAX_FILES,
            'bytes' => $bytes,
            'max_bytes' => self::MAX_TOTAL,
            'files' => $files
        ];
    }
}

final class LiteResource {

    private static function memKb(string $mem, string $key): ?int {
        return preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s+kB/m', $mem, $m) ? (int) $m[1] : null;
    }

    public static function memoryInfo(string $mem): array {
        $totalKb = self::memKb($mem, 'MemTotal');
        $availKb = self::memKb($mem, 'MemAvailable');
        $source = 'MemAvailable';
        if ($availKb === null) {
            $free = self::memKb($mem, 'MemFree');
            $buffers = self::memKb($mem, 'Buffers') ?? 0;
            $cached = self::memKb($mem, 'Cached') ?? 0;
            $reclaim = self::memKb($mem, 'SReclaimable') ?? 0;
            $shmem = self::memKb($mem, 'Shmem') ?? 0;
            if ($free !== null) {
                $availKb = max(0, $free + $buffers + $cached + $reclaim - $shmem);
                $source = 'reclaimable_estimate';
            } else $source = 'unknown';
        }
        return [
            'total_bytes' => $totalKb === null ? null : $totalKb * 1024,
            'available_bytes' => $availKb === null ? null : $availKb * 1024,
            'source' => $source
        ];
    }

    public static function limitBytes(string $value): ?int {
        $v = trim($value);
        if ($v === '' || $v === '-1' || $v === '0') return null;
        if (!preg_match('/^(\d+)\s*([KMG]?)$/i', $v, $m)) return null;
        $factor = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])];
        $bytes = (float) $m[1] * $factor;
        return $bytes > PHP_INT_MAX ? PHP_INT_MAX : (int) $bytes;
    }
    private static $previousCpu = null, $cpuObservedAt = null, $lastCpu = null, $previousOwn = null;

    public static function cpuInfo(string $stat): ?array {
        if (!preg_match('/^cpu\s+([^\r\n]+)$/m', $stat, $m)) return null;
        $parts = preg_split('/\s+/', trim($m[1]));
        if (count($parts) < 4) return null;
        $ticks = [];
        for ($i = 0; $i < 8; $i++) {
            $v = $parts[$i] ?? '0';
            if (!preg_match('/^\d+$/', $v)) return null;
            $ticks[] = (float) $v;
        }
        return ['ticks' => $ticks, 'total' => array_sum($ticks), 'idle' => $ticks[3], 'iowait' => $ticks[4]];
    }

    public static function cpuDelta(?array $before, ?array $after, float $seconds): array {
        $r = ['cpu_busy_pct' => null, 'cpu_iowait_pct' => null, 'cpu_sample_seconds' => $seconds];
        if (!$before || !$after || $seconds < 0.1 || $seconds > 90) return $r;
        for ($i = 0; $i < 8; $i++) if (!isset ($before['ticks'][$i], $after['ticks'][$i]) || $after['ticks'][$i] < $before['ticks'][$i]) return $r;
        $total = $after['total'] - $before['total'];
        if ($total <= 0) return $r;
        $idle = $after['idle'] - $before['idle'];
        $wait = $after['iowait'] - $before['iowait'];
        $r['cpu_busy_pct'] = round(max(0, min(100, 100 * ($total - $idle - $wait) / $total)), 2);
        $r['cpu_iowait_pct'] = round(max(0, min(100, 100 * $wait / $total)), 2);
        return $r;
    }

    public static function cpuPolicy(?float $load, int $cores, ?float $busy, ?float $age): array {
        $r = ['cpu_mode' => 'defer', 'cpu_batch_limit' => 0, 'cpu_reason' => 'CPU_MEASUREMENT_UNKNOWN'];
        $cores = max(1, $cores);
        $fresh = $busy !== null && $busy >= 0 && $busy <= 100 && $age !== null && $age >= 0 && $age <= 90;
        if ($load === null || $load < 0) return $r;
        if ($load >= 3 * $cores) {
            $r['cpu_reason'] = 'CPU_HARD_LOAD';
            return $r;
        }
        if ($fresh && $busy >= 95) {
            $r['cpu_reason'] = 'CPU_BUSY';
            return $r;
        }
        if ($load <= 1.5 * $cores) return ['cpu_mode' => 'normal', 'cpu_batch_limit' => null, 'cpu_reason' => null];
        if ($fresh && $busy <= 85) return ['cpu_mode' => 'limited', 'cpu_batch_limit' => 1, 'cpu_reason' => 'CPU_SOFT_LOAD'];
        $r['cpu_reason'] = $fresh ? 'CPU_BUSY' : 'CPU_MEASUREMENT_UNKNOWN';
        return $r;
    }

    private static function loadOne(): ?float {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg(): false;
        if (!is_array($load) || !isset ($load[0]) || !is_numeric($load[0])) return null;
        $one = (float) $load[0];
        return is_finite($one) && $one >= 0 ? $one : null;
    }

    private static function ownCpuSeconds(): ?float {
        $usage = function_exists('getrusage') ? @getrusage(): false;
        return is_array($usage) ? ((float)($usage['ru_utime.tv_sec'] ?? 0) + (float)($usage['ru_stime.tv_sec'] ?? 0) + ((float)($usage['ru_utime.tv_usec']
            ?? 0) + (float)($usage['ru_stime.tv_usec'] ?? 0)) / 1000000): null;
    }

    public static function sample(): array {
        $load1 = self::loadOne();
        $stat = (string) @file_get_contents('/proc/stat');
        $cores = max(1, (int) preg_match_all('/^cpu\d+\s/m', $stat, $m));
        $now = microtime(true);
        $cpu = self::cpuInfo($stat);
        $seconds = self::$previousCpu === null ? 0 : $now - self::$previousCpu['at'];
        $delta = self::cpuDelta(self::$previousCpu['value'] ?? null, $cpu, $seconds);
        $primed = false;
        $confirmation = null;
        if ($delta['cpu_busy_pct'] === null && $load1 !== null && $load1 > 1.5 * $cores && $load1 < 3 * $cores && $cpu !== null
            && (self::$previousCpu === null || $seconds > 90)) {
            $primed = true;
            $before = $cpu;
            $start = $now;
            usleep(100000);
            $stat = (string) @file_get_contents('/proc/stat');
            $cpu = self::cpuInfo($stat);
            $now = microtime(true);
            $delta = self::cpuDelta($before, $cpu, $now - $start);
        }
        if ($delta['cpu_busy_pct'] === null && ($cpu === null || $seconds >= 0.1)) {
            self::$lastCpu = null;
            self::$cpuObservedAt = null;
        }
        if ($delta['cpu_busy_pct'] !== null) {
            self::$lastCpu = $delta;
            self::$cpuObservedAt = $now;
        }
        if ($cpu !== null && (self::$previousCpu === null || $now - self::$previousCpu['at'] >= 0.1)) self::$previousCpu = ['value' => $cpu, 'at' => $now];
        $age = self::$cpuObservedAt === null ? null : max(0, $now - self::$cpuObservedAt);
        $cpuMetrics = self::$lastCpu ?? $delta;
        $cpuMetrics['cpu_sample_age_seconds'] = $age;
        $cpuMetrics['cpu_sample_observed_at'] = self::$cpuObservedAt === null ? null : lm_iso((int) self::$cpuObservedAt);
        $policy = self::cpuPolicy($load1, $cores, $cpuMetrics['cpu_busy_pct'], $age);
        // Confirm a short work burst during one quiet window; never subtract our CPU from host busy.
        if (!$primed && $policy['cpu_reason'] === 'CPU_BUSY' && $cpu !== null && $load1 !== null && $load1 < 3 * $cores
            && $cpuMetrics['cpu_sample_seconds'] >= 0.1 && $cpuMetrics['cpu_sample_seconds'] <= 1 && $age !== null
            && $age <= 1) {
            $before = $cpu;
            $start = $now;
            $ownBefore = self::ownCpuSeconds();
            $confirmation = [
                'valid' => false,
                'seconds' => null,
                'busy_before_pct' => $cpuMetrics['cpu_busy_pct'],
                'sample_before_seconds' => $cpuMetrics['cpu_sample_seconds'],
                'own_cpu_seconds' => null
            ];
            usleep(500000);
            $stat = (string) @file_get_contents('/proc/stat');
            $cpu = self::cpuInfo($stat);
            $now = microtime(true);
            $quietSeconds = $now - $start;
            $quiet = self::cpuDelta($before, $cpu, $quietSeconds);
            $load1 = self::loadOne();
            $ownAfter = self::ownCpuSeconds();
            $confirmation['seconds'] = $quietSeconds;
            $confirmation['own_cpu_seconds'] = $ownBefore === null || $ownAfter === null ? null : max(0, $ownAfter - $ownBefore);
            $valid = $quietSeconds >= 0.45 && $quietSeconds <= 1 && $quiet['cpu_busy_pct'] !== null && $load1 !== null
                && $load1 >= 0;
            $confirmation['valid'] = $valid;
            self::$previousCpu = $cpu === null ? null : ['value' => $cpu, 'at' => $now];
            if ($valid) {
                self::$lastCpu = $quiet;
                self::$cpuObservedAt = $now;
                $cpuMetrics = $quiet;
                $cpuMetrics['cpu_sample_age_seconds'] = 0;
                $cpuMetrics['cpu_sample_observed_at'] = lm_iso((int) $now);
                $policy = self::cpuPolicy($load1, $cores, $quiet['cpu_busy_pct'], 0);
            } else {
                self::$lastCpu = null;
                self::$cpuObservedAt = null;
                $cpuMetrics['cpu_sample_age_seconds'] = $age + max(0, $quietSeconds);
                if ($load1 !== null && $load1 >= 3 * $cores) $policy = self::cpuPolicy($load1, $cores, null, null);
            }
        }
        $cpuMetrics['cpu_confirmation'] = $confirmation;
        $own = self::ownCpuSeconds();
        $ownPct = null;
        if ($own !== null && self::$previousOwn !== null && $now > self::$previousOwn['at']) $ownPct = max(0, 100 * ($own - self::$previousOwn['value']) / ($now - self::$previousOwn['at']));
        if ($own !== null) self::$previousOwn = ['value' => $own, 'at' => $now];
        $rss = null;
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', (string) @file_get_contents('/proc/self/status'), $rm)) $rss = (int) $rm[1] * 1024;
        $mi = self::memoryInfo((string) @file_get_contents('/proc/meminfo'));
        $total = $mi['total_bytes'];
        $avail = $mi['available_bytes'];
        $limit = self::limitBytes((string) ini_get('memory_limit'));
        $used = memory_get_usage(true);
        $headroom = $limit === null ? null : max(0, $limit - $used);
        $effective = $avail === null ? $headroom : ($headroom === null ? $avail : min($avail, $headroom));
        $phpOk = $headroom === null || $headroom >= 24 * 1048576;
        $floor = max(32 * 1048576, (int)(($total ?? 0) * .06));
        $memOk = $avail === null || $avail >= $floor;
        return array_merge(
            [
                'observed_at' => lm_iso(),
                'process_sapi' => PHP_SAPI,
                'pid' => getmypid(),
                'cores' => $cores,
                'load1' => $load1,
                'own_cpu_seconds' => $own,
                'own_cpu_pct' => $ownPct,
                'process_rss_bytes' => $rss,
                'memory_available_bytes' => $avail,
                'memory_available_source' => $mi['source'],
                'memory_floor_bytes' => $floor,
                'php_memory_limit_bytes' => $limit,
                'php_memory_used_bytes' => $used,
                'php_memory_usage_bytes' => memory_get_usage(false),
                'php_memory_peak_bytes' => memory_get_peak_usage(true),
                'php_memory_headroom_bytes' => $headroom,
                'effective_memory_available_bytes' => $effective,
                'memory_mode' => !$phpOk || ($avail !== null && $avail < 48 * 1048576) ? 'low' : 'normal',
                'heavy_allowed' => $policy['cpu_mode'] === 'normal' && $memOk && $phpOk,
                'reason' => $policy['cpu_mode'] !== 'normal' ? 'load' : (!$memOk || !$phpOk ? 'memory' : null)
            ],
            $cpuMetrics,
            $policy
        );
    }

    public static function lowMemoryBatch(array $res): int {
        $avail = lm_num($res['memory_available_bytes'] ?? null);
        $room = lm_num($res['php_memory_headroom_bytes'] ?? null);
        if (($avail !== null && $avail < LitePolicy::LOW_MEMORY_HARD_FLOOR) || ($room !== null && $room < 8 * 1048576)) return 0;
        $host = $avail !== null && $avail < 24 * 1048576 ? 1 : ($avail !== null && $avail < 30 * 1048576 ? 2 : 3);
        $php = $room !== null && $room < 12 * 1048576 ? 1 : ($room !== null && $room < 20 * 1048576 ? 2 : 3);
        return min($host, $php);
    }

    public static function cpuMode(array $res): string {
        return (string)($res['cpu_mode'] ?? (($res['reason'] ?? null) === 'load' ? 'defer' : 'normal'));
    }
}

final class LiteConfig {
    public $githubToken = '';
    public $phpCli = '';

    private static function cliCandidates(): array {
        $x = [];
        $env = getenv('MON_PHP_CLI');
        if (is_string($env) && trim($env) !== '') $x[] = trim($env);
        if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' && stripos(basename(PHP_BINARY), 'fpm') === false) $x[] = PHP_BINARY;
        foreach ([
            '/usr/local/bin/php74',
            '/usr/local/bin/php',
            '/usr/bin/php74',
            '/usr/bin/php',
            '/bin/php',
            '/var/packages/PHP7.4/target/usr/local/bin/php74'
        ] as $p) $x[] = $p;
        return array_values(array_unique($x));
    }

    private static function discoverCli(): string {
        $c = self::cliCandidates();
        $path = getenv('PATH');
        if (is_string($path)) foreach (explode(PATH_SEPARATOR, $path) as $d) if ($d !== '') foreach (['php74', 'php'] as $n) $c[] = rtrim($d, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $n;
        foreach (array_values(array_unique($c)) as $p) if (is_string($p) && $p !== '' && is_file($p) && is_executable($p)) return $p;
        return '';
    }

    public function __construct() {
        $p = __DIR__ . '/sis_private_sync_config.php';
        if (is_file($p) && is_readable($p)) {
            $x = (static function ($f) {
                return @include $f;
            })($p);
            if (is_array($x)) {
                if (isset ($x['github_token']) && is_string($x['github_token'])) $this->githubToken = trim($x['github_token']);
                if (isset ($x['GITHUB_TOKEN']) && is_string($x['GITHUB_TOKEN'])) $this->githubToken = trim($x['GITHUB_TOKEN']);
            }
        }
        try {
            $s = LiteRuntime::read('settings');
        } catch (Throwable $ignore) {
            $s = new stdClass();
        }
        $v = lm_get($s, 'github_token');
        if (is_string($v) && trim($v) !== '') $this->githubToken = trim($v);
        $v = lm_get($s, 'php_cli');
        if (is_string($v) && trim($v) !== '') $this->phpCli = trim($v);
        if ($this->phpCli === '') $this->phpCli = self::discoverCli();
    }
}

final class LiteHealth {

    private static function execUsable(): bool {
        if (!function_exists('exec')) return false;
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        return !in_array('exec', $disabled, true);
    }

    private static function cliProbe(string $php): array {
        $r = ['checked' => true, 'ok' => false, 'version_id' => null, 'curl' => null, 'sapi' => null, 'error' => null];
        if ($php === '' || !is_file($php) || !is_executable($php)) {
            $r['error'] = 'PHP_CLI_NOT_FOUND';
            return $r;
        }
        if (!self::execUsable()) {
            $r['error'] = 'WEB_PROCESS_LAUNCH_DISABLED';
            return $r;
        }
        $probe = 'echo PHP_VERSION_ID,"|",(extension_loaded("curl")?"1":"0"),"|",PHP_SAPI;';
        $out = [];
        $rc = 1;
        @exec(escapeshellarg($php) . ' -r ' . escapeshellarg($probe) . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            $r['error'] = 'PHP_CLI_PROBE_FAILED';
            return $r;
        }
        $p = explode('|', trim(implode("\n", $out)));
        if (count($p) !== 3 || !ctype_digit($p[0])) {
            $r['error'] = 'PHP_CLI_PROBE_INVALID';
            return $r;
        }
        $r['version_id'] = (int) $p[0];
        $r['curl'] = $p[1] === '1';
        $r['sapi'] = $p[2];
        $r['ok'] = $r['version_id'] >= 70400 && $r['curl'] && $r['sapi'] === 'cli';
        if (!$r['ok']) $r['error'] = $r['version_id'] < 70400 ? 'CLI_PHP_VERSION_UNSUPPORTED' : (!$r['curl'] ? 'CLI_PHP_CURL_REQUIRED' : 'CLI_PHP_SAPI_INVALID');
        return $r;
    }

    private static function networkProbe(LiteConfig $cfg): array {
        $r = ['checked' => true, 'github_read' => false, 'yahoo_read' => false, 'errors' => []];
        if (!extension_loaded('curl')) {
            $r['errors'][] = 'PHP_CURL_REQUIRED';
            return $r;
        }
        try {
            $http = new LiteHttp($cfg);
            $g = $http->githubGet('mon_chatgpt_handoff.json', true);
            if (!$g) throw new LiteMonFault('GITHUB_HANDOFF_MISSING');
            $q = lm_decode($g['content']);
            if (lm_get($q, 'contract') !== 'MON-LITE-BRIDGE-1.0' || lm_get($q, 'policy') !== MON_LITE_POLICY) throw new LiteMonFault('GITHUB_HANDOFF_CONTRACT_MISMATCH');
            $r['github_read'] = true;
        } catch (Throwable $e) {
            $r['errors'][] = $e instanceof LiteMonFault ? $e->faultCode : 'GITHUB_READ_FAILED';
        }
        try {
            if (!isset ($http)) $http = new LiteHttp($cfg);
            $raw = $http->get('https://query1.finance.yahoo.com/v8/finance/chart/%5EGSPC?range=5d&interval=1d', [], 8);
            $j = lm_decode($raw);
            $x = lm_get(lm_get(lm_get($j, 'chart'), 'result'), 0);
            if (!$x) throw new LiteMonFault('YAHOO_EMPTY');
            $r['yahoo_read'] = true;
        } catch (Throwable $e) {
            $r['errors'][] = $e instanceof LiteMonFault ? $e->faultCode : 'YAHOO_READ_FAILED';
        }
        return $r;
    }

    public static function local(LiteConfig $cfg, bool $deep = false, bool $checkNetwork = true): array {
        $blockers = [];
        $warnings = [];
        $execOk = self::execUsable();
        if (PHP_VERSION_ID < 70400) $blockers[] = 'PHP_VERSION_UNSUPPORTED';
        if (!extension_loaded('curl')) $blockers[] = 'PHP_CURL_REQUIRED';
        if ($cfg->githubToken === '') $blockers[] = 'GITHUB_TOKEN_MISSING';
        if ($cfg->phpCli === '' || !is_file($cfg->phpCli) || !is_executable($cfg->phpCli)) $blockers[] = 'PHP_CLI_NOT_FOUND';
        if (!$execOk) $blockers[] = 'WEB_PROCESS_LAUNCH_DISABLED';
        try {
            $runtime = LiteRuntime::view();
            LiteRuntime::capacity(0);
        } catch (Throwable $e) {
            $runtime = ['path' => MON_LITE_RUNTIME];
            $blockers[] = $e instanceof LiteMonFault ? $e->faultCode : 'RUNTIME_HEALTH_FAILED';
        }
        $resource = LiteResource::sample();
        if (!$resource['heavy_allowed']) $warnings[] = 'RESOURCE_PRESSURE_' . strtoupper((string) $resource['reason']);
        $cli = ['checked' => false, 'ok' => null, 'error' => null];
        if ($deep && $cfg->phpCli !== '' && $execOk) {
            $cli = self::cliProbe($cfg->phpCli);
            if (!$cli['ok']) $blockers[] = $cli['error'];
        }
        $network = ['checked' => false, 'github_read' => null, 'yahoo_read' => null, 'errors' => []];
        if ($deep && $checkNetwork && $cfg->githubToken !== '' && extension_loaded('curl')) {
            $network = self::networkProbe($cfg);
            foreach ($network['errors'] as $e) $warnings[] = 'NETWORK_' . $e;
        }
        return [
            'contract' => 'MON-LITE-NAS-HEALTH-1.1',
            'policy' => MON_LITE_POLICY,
            'version' => MON_LITE_VERSION,
            'build' => MON_LITE_BUILD,
            'installed_identity' => LiteStateStore::installedIdentity(),
            'deep_checked' => $deep,
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'curl' => extension_loaded('curl'),
            'exec' => $execOk,
            'php_cli' => $cfg->phpCli,
            'cli_probe' => $cli,
            'github_configured' => $cfg->githubToken !== '',
            'network' => $network,
            'runtime' => $runtime,
            'resource' => $resource,
            'blockers' => array_values(array_unique(array_filter($blockers))),
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'ready_to_start' => count(array_filter($blockers)) === 0
        ];
    }
}

final class LiteHttp {
    const MAX_CACHE_BODY = 262144;
    const MAX_CACHE_TOTAL = 524288;
    const MAX_CACHE_FILES = 4;
    const MAX_RESPONSE_BYTES = 25165824;
    private $cfg;
    private $handles = [];
    private $githubTurn = [];
    private $githubKnown = [];
    private $stats = [];

    public function __construct(LiteConfig $cfg) {
        $this->cfg = $cfg;
        $this->resetStats();
    }

    public function __destruct() {
        foreach ($this->handles as $ch) if ($ch) @curl_close($ch);
        $this->handles = [];
    }

    public function clearRequestCache(): void {
        $this->githubTurn = [];
    }

    public function releasePayloads(): void {
        $this->githubTurn = [];
        $total = 0;
        foreach ($this->githubKnown as $key => $v) {
            if (is_array($v) && strlen($v['content']) > self::MAX_CACHE_BODY) unset ($this->githubKnown[$key]);
            elseif (is_array($v)) $total += strlen($v['content']);
        }
        while (count($this->githubKnown) > self::MAX_CACHE_FILES || $total > self::MAX_CACHE_TOTAL) {
            $key = array_key_first($this->githubKnown);
            $v = $this->githubKnown[$key];
            if (is_array($v)) $total -= strlen($v['content']);
            unset ($this->githubKnown[$key]);
        }
        foreach ($this->handles as $ch) if ($ch) @curl_reset($ch);
    }

    private function remember(string $path, ?array $item): void {
        unset ($this->githubKnown[$path], $this->githubTurn[$path]);
        if ($item !== null && strlen($item['content']) > self::MAX_CACHE_BODY) return;
        $this->githubKnown[$path] = $item;
        $this->githubTurn[$path] = $item;
        $total = 0;
        foreach ($this->githubKnown as $v) if (is_array($v)) $total += strlen($v['content']);
        while (count($this->githubKnown) > self::MAX_CACHE_FILES || $total > self::MAX_CACHE_TOTAL) {
            $key = array_key_first($this->githubKnown);
            $v = $this->githubKnown[$key];
            if (is_array($v)) $total -= strlen($v['content']);
            unset ($this->githubKnown[$key], $this->githubTurn[$key]);
        }
    }

    public static function responseLimit(): int {
        $limit = LiteResource::limitBytes((string) ini_get('memory_limit'));
        if ($limit === null) return self::MAX_RESPONSE_BYTES;
        $room = max(0, $limit - memory_get_usage(true));
        return min(self::MAX_RESPONSE_BYTES, (int) floor($room / 4));
    }

    public function resetStats(): void {
        $this->stats = [
            'http_requests' => 0,
            'http_retries' => 0,
            'http_304' => 0,
            'handle_reuses' => 0,
            'new_connections' => 0,
            'github_body_cache_hits' => 0,
            'wire_bytes_received' => 0,
            'decoded_body_bytes' => 0
        ];
    }

    public function stats(): array {
        return $this->stats;
    }

    private function headers(bool $json = false): array {
        $h = ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . $this->cfg->githubToken];
        if ($json) $h[] = 'Content-Type: application/json';
        return $h;
    }

    private function handle(string $url) {
        if (!extension_loaded('curl')) throw new LiteMonFault('PHP_CURL_REQUIRED');
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') throw new LiteMonFault('HTTP_URL_INVALID');
        if (isset ($this->handles[$host])) {
            $this->stats['handle_reuses']++;
            $ch = $this->handles[$host];
            @curl_reset($ch);
            return $ch;
        }
        $ch = curl_init();
        if ($ch === false) throw new LiteMonFault('HTTP_INIT_FAILED');
        $this->handles[$host] = $ch;
        return $ch;
    }

    private static function transientTransport(int $errno): bool {
        return in_array($errno, [5, 6, 7, 16, 18, 28, 35, 52, 55, 56, 92], true);
    }

    private static function transientStatus(int $status): bool {
        return in_array($status, [429, 502, 503, 504], true);
    }

    private static function headerValue(array $headers, string $name): ?string {
        $k = strtolower($name);
        return isset ($headers[$k]) && $headers[$k] !== '' ? $headers[$k] : null;
    }

    private function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 8,
        int $maxRetries = 1,
        array $allowStatuses = []
    ): array {
        $attempt = 0;
        while (true) {
            $attempt++;
            $responseHeaders = [];
            $raw = '';
            $tooLarge = false;
            $responseLimit = self::responseLimit();
            $ch = $this->handle($url);
            $opts = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'MON-Lite/' . MON_LITE_VERSION,
                CURLOPT_ENCODING => ''
            ];
            if (defined('CURLOPT_TCP_KEEPALIVE')) $opts[CURLOPT_TCP_KEEPALIVE] = 1;
            if (defined('CURL_HTTP_VERSION_2TLS')) $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
            if ($method !== 'GET') $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
            if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use(& $raw, & $tooLarge, $responseLimit) {
                $n = strlen($chunk);
                if (strlen($raw) + $n > $responseLimit) {
                    $tooLarge = true;
                    return 0;
                }
                $raw .= $chunk;
                return $n;
            };
            $opts[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use(& $responseHeaders) {
                $n = strlen($line);
                $p = strpos($line, ':');
                if ($p !== false) {
                    $k = strtolower(trim(substr($line, 0, $p)));
                    $v = trim(substr($line, $p + 1));
                    if ($k !== '') $responseHeaders[$k] = $v;
                }
                return $n;
            };
            curl_setopt_array($ch, $opts);
            curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $wire = (float) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            $newConnections = defined('CURLINFO_NUM_CONNECTS') ? (int) curl_getinfo($ch, CURLINFO_NUM_CONNECTS): 0;
            $this->stats['http_requests']++;
            $this->stats['wire_bytes_received'] += (int) round($wire);
            $this->stats['new_connections'] += $newConnections;
            $this->stats['decoded_body_bytes'] += strlen($raw);
            curl_reset($ch);
            unset ($opts);
            if ($tooLarge) {
                $host = (string) parse_url($url, PHP_URL_HOST);
                curl_close($ch);
                unset ($this->handles[$host]);
                throw new LiteMonFault('HTTP_RESPONSE_TOO_LARGE');
            }
            if ($errno !== 0) {
                $host = (string) parse_url($url, PHP_URL_HOST);
                curl_close($ch);
                unset ($this->handles[$host]);
            }
            $retry = ($errno !== 0 && self::transientTransport($errno)) || ($errno === 0 && self::transientStatus($status));
            if ($retry && $attempt <= $maxRetries) {
                $this->stats['http_retries']++;
                $retryAfter = (int)(self::headerValue($responseHeaders, 'retry-after') ?? 0);
                $delay = $retryAfter > 0 ? min(2.0, (float) $retryAfter): (0.20 * $attempt);
                usleep((int)($delay * 1000000));
                continue;
            }
            if ($errno !== 0) throw new LiteMonFault('HTTP_TRANSPORT_' . $errno);
            if ($status === 304 || in_array($status, $allowStatuses, true)) return ['status' => $status, 'body' => is_string($raw) ? $raw : '', 'headers' => $responseHeaders];
            if ($status < 200 || $status >= 300) throw new LiteMonFault('HTTP_' . $status);
            if (!is_string($raw)) throw new LiteMonFault('HTTP_EMPTY');
            return ['status' => $status, 'body' => $raw, 'headers' => $responseHeaders];
        }
    }

    public function get(string $url, array $headers = [], int $timeout = 8): string {
        $r = $this->request('GET', $url, $headers, null, $timeout, 1);
        return $r['body'];
    }

    public function githubGet(string $path, bool $force = false): ?array {
        if ($this->cfg->githubToken === '') return null;
        if (!$force && array_key_exists($path, $this->githubTurn)) {
            $this->stats['github_body_cache_hits']++;
            return $this->githubTurn[$path];
        }
        $url = 'https://api.github.com/repos/' . MON_LITE_GITHUB_REPO . '/contents/' . $path . '?ref=' . MON_LITE_GITHUB_BRANCH;
        $h = $this->headers();
        $known = $this->githubKnown[$path] ?? null;
        $etag = is_array($known) ? ($known['etag'] ?? null): null;
        if (!$force && is_string($etag) && $etag !== '') $h[] = 'If-None-Match: ' . $etag;
        $r = $this->request('GET', $url, $h, null, 8, 1, [304, 404]);
        if ($r['status'] === 404) {
            $this->remember($path, null);
            return null;
        }
        if ($r['status'] === 304) {
            $this->stats['http_304']++;
            if (!is_array($known) || !isset ($known['content'])) throw new LiteMonFault('GITHUB_304_WITHOUT_CACHE');
            $this->githubTurn[$path] = $known;
            return $known;
        }
        $j = lm_decode($r['body']);
        $sha = (string) lm_get($j, 'sha', '');
        $content = lm_get($j, 'content');
        $b = is_string($content) && $content !== '' ? base64_decode(str_replace(["\r", "\n"], '', $content), true): false;
        if (!is_string($b)) {
            $rawHeaders = ['Accept: application/vnd.github.raw+json', 'Authorization: Bearer ' . $this->cfg->githubToken];
            $b = $this->get($url, $rawHeaders, 12);
        }
        $etag = self::headerValue($r['headers'], 'etag');
        unset ($r, $j, $content);
        $item = ['content' => $b, 'sha' => $sha, 'commit_sha' => null, 'etag' => $etag];
        $this->remember($path, $item);
        return $item;
    }

    public function githubPut(string $path, string $content, string $message, ?string $oldSha = null): array {
        if ($this->cfg->githubToken === '') throw new LiteMonFault('GITHUB_TOKEN_MISSING');
        $url = 'https://api.github.com/repos/' . MON_LITE_GITHUB_REPO . '/contents/' . $path;
        $payload = ['message' => $message, 'content' => base64_encode($content), 'branch' => MON_LITE_GITHUB_BRANCH];
        if ($oldSha !== null) $payload['sha'] = $oldSha;
        // PUT is intentionally not auto-retried: a transport failure can occur after GitHub committed the write.
        try {
            $r = $this->request('PUT', $url, $this->headers(true), lm_json($payload), 12, 0);
        } catch (LiteMonFault $e) {
            if (strpos($e->faultCode, 'HTTP_') === 0 && strpos($e->faultCode, 'HTTP_TRANSPORT_') !== 0) throw new LiteMonFault('GITHUB_WRITE_'
                . substr($e->faultCode, 5));
            throw $e;
        }
        $j = lm_decode($r['body']);
        $blob = (string) lm_get(lm_get($j, 'content'), 'sha', '');
        $commit = (string) lm_get(lm_get($j, 'commit'), 'sha', '');
        if ($blob === '' || !hash_equals(lm_git_blob_sha($content), $blob)) throw new LiteMonFault('GITHUB_BLOB_VERIFY_FAILED');
        // Keep the verified body but discard an old validator so the next forced readback must contact GitHub for fresh representation metadata.
        $item = ['content' => $content, 'sha' => $blob, 'commit_sha' => $commit, 'etag' => null];
        $this->remember($path, $item);
        return ['blob_sha' => $blob, 'commit_sha' => $commit, 'unchanged' => false];
    }
}

final class LiteSwing {

    public static function bars($rows): array {
        if (!is_array($rows) || count($rows) > 5000) throw new LiteMonFault('BARS_INVALID');
        $out = [];
        $last = '';
        foreach ($rows as $r) {
            if (!is_array($r) || count($r) < 6 || !is_string($r[0]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $r[0])
                || $r[0] <= $last) throw new LiteMonFault('BAR_ORDER_INVALID');
            $v = [];
            for ($i = 1; $i < 6; $i++) {
                $n = lm_num($r[$i]);
                if ($n === null || ($i < 5 ? $n <= 0 : $n < 0)) throw new LiteMonFault('BAR_VALUE_INVALID');
                $v[] = $n;
            }
            if ($v[1] < $v[2] || $v[0] < $v[2] || $v[0] > $v[1] || $v[3] < $v[2] || $v[3] > $v[1]) throw new LiteMonFault('BAR_RANGE_INVALID');
            $out[] = [$r[0], $v[0], $v[1], $v[2], $v[3], $v[4]];
            $last = $r[0];
        }
        return $out;
    }

    private static function macdState(array $closes): array {
        $n = count($closes);
        $hist = array_fill(0, $n, null);
        $fast = null;
        $slow = null;
        $signal = null;
        $fastSeed = 0.0;
        $slowSeed = 0.0;
        $signalSeed = 0.0;
        $signalCount = 0;
        $af = 2 / 13;
        $as = 2 / 27;
        $ag = 2 / 10;
        $bf = 1 - $af;
        $bs = 1 - $as;
        $bg = 1 - $ag;
        $lastMacd = null;
        foreach ($closes as $i => $v) {
            $v = (float) $v;
            if ($fast === null) {
                $fastSeed += $v;
                if ($i === 11) $fast = $fastSeed / 12;
            } else $fast = $af * $v + $bf * $fast;
            if ($slow === null) {
                $slowSeed += $v;
                if ($i === 25) $slow = $slowSeed / 26;
            } else $slow = $as * $v + $bs * $slow;
            if ($fast === null || $slow === null) continue;
            $lastMacd = $fast - $slow;
            if ($signal === null) {
                $signalSeed += $lastMacd;
                $signalCount++;
                if ($signalCount < 9) continue;
                $signal = $signalSeed / 9;
            } else $signal = $ag * $lastMacd + $bg * $signal;
            $hist[$i] = $lastMacd - $signal;
        }
        return ['macd' => $lastMacd, 'signal' => $signal, 'hist' => $hist];
    }

    public static function classifyMacd(array $hist, float $atr): array {
        $n = count($hist);
        if ($n < 2 || $hist[$n - 1] === null || $hist[$n - 2] === null) return ['kind' => null, 'cross_index' => null];
        $last = $hist[$n - 1];
        if ($hist[$n - 2] <= 0 && $last > 0) return ['kind' => 'MACD_GOLD', 'cross_index' => $n - 1];
        if ($last > 0) for ($i = $n - 2; $i >= max(1, $n - 4); $i--) {
            if ($hist[$i] === null || $hist[$i - 1] === null) continue;
            if ($hist[$i - 1] <= 0 && $hist[$i] > 0) {
                $positive = true;
                for ($j = $i; $j < $n; $j++) if ($hist[$j] === null || $hist[$j] <= 0) $positive = false;
                if ($positive) return ['kind' => 'MACD_AFTER', 'cross_index' => $i];
            }
        }
        if ($n >= 3 && $atr > 0 && $last <= 0 && $hist[$n - 3] !== null && $hist[$n - 2] !== null && $hist[$n - 3] < $hist[$n - 2]
            && $hist[$n - 2] < $last && abs($last) / $atr <= 0.10) return ['kind' => 'MACD_BEFORE', 'cross_index' => null];
        return ['kind' => null, 'cross_index' => null];
    }

    private static function averageAt(array $closes, int $period, int $offset = 0): ?float {
        $end = count($closes) - $offset;
        if ($end < $period) return null;
        $sum = 0.0;
        for ($i = $end - $period; $i < $end; $i++) $sum += $closes[$i];
        return lm_round6($sum / $period);
    }

    /** Scalar trend context shares the existing completed-bar snapshot; no extra provider call. */
    private static function trendContext(array $b, array $closes): array {
        $n = count($closes);
        $last = $closes[$n - 1];
        $v = [];
        foreach ([5, 20, 60, 120, 200] as $period) {
            $now = self::averageAt($closes, $period);
            $prev = self::averageAt($closes, $period, 1);
            $prev5 = self::averageAt($closes, $period, 5);
            $v['MA' . $period] = $now;
            $v['MA' . $period . '_slope_1'] = $now !== null && $prev !== null ? lm_round6($now - $prev) : null;
            $v['MA' . $period . '_slope_5'] = $now !== null && $prev5 !== null ? lm_round6($now - $prev5) : null;
        }
        $priorLow = null;
        $volumeSum = 0.0;
        if ($n >= 21) for ($i = $n - 21; $i < $n - 1; $i++) {
            $priorLow = $priorLow === null ? $b[$i][3] : min($priorLow, $b[$i][3]);
            $volumeSum += $b[$i][5];
        }
        $v['prior20_low'] = $priorLow;
        $v['volume_ratio20'] = $n >= 21 && $volumeSum > 0 ? lm_round6($b[$n - 1][5] / ($volumeSum / 20)) : null;
        $v['R20'] = $n >= 21 ? lm_round6($last / $closes[$n - 21] - 1) : null;
        $enough = $n >= 205;
        $fall20 = $v['MA20'] !== null && $v['MA20_slope_5'] !== null && $last < $v['MA20'] && $v['MA20_slope_5'] < 0;
        $fall60 = $v['MA60'] !== null && $v['MA60_slope_5'] !== null && $last < $v['MA60'] && $v['MA60_slope_5'] < 0;
        $below200 = $v['MA200'] !== null && $last < $v['MA200'];
        $broken = $priorLow !== null && $last < $priorLow;
        $up = $enough && $v['R20'] > 0 && $last >= $v['MA5'] && $last >= $v['MA20'] && $last >= $v['MA60']
            && $v['MA5_slope_1'] > 0
            && $v['MA20_slope_1'] >= 0 && $v['MA20_slope_5'] >= 0
            && $v['MA60_slope_1'] >= 0 && $v['MA60_slope_5'] >= 0;
        $state = !$enough ? 'insufficient_history' : ($broken ? 'support_broken' : (($fall20 || $fall60) ? 'declining' : ($up ? 'uptrend' : 'recovering')));
        return ['metrics' => $v, 'trend' => [
            'state' => $state, 'history_complete' => $enough, 'absolute_uptrend' => $up,
            'below_falling_ma20' => $fall20, 'below_falling_ma60' => $fall60,
            'below_ma200' => $below200, 'long_term_damage' => $below200,
            'prior20_low' => $priorLow, 'support_broken' => $broken,
            'volume_ratio20' => $v['volume_ratio20'],
            'volume_confirmed' => $v['volume_ratio20'] !== null && $v['volume_ratio20'] >= 1.10,
            'buy_reference_qualified' => false
        ]];
    }

    private static function technicalBars(array $b): array {
        $n = count($b);
        if ($n < 15) throw new LiteMonFault('MA_HISTORY_MISSING');
        $closes = array_column($b, 4);
        $ma = array_fill(0, $n, null);
        $sum5 = 0.0;
        foreach ($closes as $i => $v) {
            $sum5 += (float) $v;
            if ($i >= 5) $sum5 -= (float) $closes[$i - 5];
            if ($i >= 4) $ma[$i] = $sum5 / 5;
        }
        $below = 0;
        for ($i = $n - 2; $i >= 4 && $closes[$i] < $ma[$i]; $i--) $below++;
        $reclaim = $below >= 10 && $closes[$n - 1] > $ma[$n - 1];
        $atrSum = 0.0;
        $atrStart = max(1, $n - 14);
        for ($i = $atrStart; $i < $n; $i++) $atrSum += max(
            $b[$i][2] - $b[$i][3],
            abs($b[$i][2] - $closes[$i - 1]),
            abs($b[$i][3] - $closes[$i - 1])
        );
        $atr = $n - 1 >= 14 ? $atrSum / 14 : 0.0;
        $m = self::macdState($closes);
        $hist = $m['hist'];
        $kind = self::classifyMacd($hist, $atr);
        $qualified = [];
        if ($reclaim) $qualified[] = 'MA5_RECLAIM_CLOSE';
        if ($kind['kind'] !== null) $qualified[] = $kind['kind'];
        $lastHist = $hist[$n - 1];
        $context = self::trendContext($b, $closes);
        return [
            'formula_version' => 'MON-SWING-1.2',
            'trend_metrics' => $context['metrics'],
            'trend' => $context['trend'],
            'bars_as_of' => $b[$n - 1][0],
            'bar_count' => $n,
            'qualifying_signals' => $qualified,
            'technical_qualified' => count($qualified) > 0,
            'ma5' => lm_round6($ma[$n - 1]),
            'ma5_below_days_before_last' => $below,
            'ma5_reclaim_close' => $reclaim,
            'last_close' => $closes[$n - 1],
            'macd' => lm_round6($m['macd']),
            'signal' => lm_round6($m['signal']),
            'histogram' => lm_round6($lastHist),
            'macd_kind' => $kind['kind'],
            'macd_cross_date' => $kind['cross_index'] === null ? null : $b[$kind['cross_index']][0],
            'macd_cross_age_days' => $kind['cross_index'] === null ? null : $n - 1 - $kind['cross_index'],
            'atr14' => lm_round6($atr),
            'macd_gap_atr' => $atr > 0 && $lastHist !== null ? lm_round6(abs($lastHist) / $atr): null
        ];
    }

    public static function technical(array $input): array {
        return self::technicalBars(self::bars($input));
    }

    private static function tick(array $s, float $value): ?float {
        $country = $s['country'];
        if ($country === 'US') return $value >= 1 ? 0.01 : 0.0001;
        if ($country === 'KR') {
            foreach ([[2000, 1], [5000, 5], [20000, 10], [50000, 50], [200000, 100], [500000, 500]] as $band) if ($value < $band[0]) return (float) $band[1];
            return 1000.0;
        }
        if ($country === 'JP') {
            foreach ([
                [1000, .1],
                [3000, .5],
                [10000, 1],
                [30000, 5],
                [100000, 10],
                [300000, 50],
                [1000000, 100],
                [3000000, 500],
                [10000000, 1000],
                [30000000, 5000]
            ] as $band) if ($value <= $band[0]) return (float) $band[1];
            return 10000.0;
        }
        return null;
    }

    private static function roundTick(array $s, float $value, bool $up): ?float {
        $step = self::tick($s, $value);
        if ($step === null) return null;
        for ($i = 0; $i < 4; $i++) {
            $x = ($up ? ceil($value / $step - 1e-10): floor($value / $step + 1e-10)) * $step;
            $next = self::tick($s, $x);
            if ($next === $step) return lm_round6($x);
            $step = $next;
        }
        return null;
    }

    public static function benchmarkMap(array $benchmark): array {
        $bm = [];
        foreach ($benchmark as $r) {
            if (!is_array($r) || count($r) < 5 || !is_string($r[0])) throw new LiteMonFault('BENCHMARK_INVALID');
            $v = lm_num($r[4]);
            if ($v === null || $v <= 0) throw new LiteMonFault('BENCHMARK_INVALID');
            $bm[$r[0]] = $v;
        }
        return $bm;
    }

    /** Recheck stored scalar gates before display; a cached true flag is not enough. */
    public static function referenceBuyReady(array $a): bool {
        $m = $a['metrics'] ?? [];
        $t = $a['technical'] ?? [];
        $trend = $a['trend'] ?? [];
        $check = $a['final_check'] ?? [];
        if (in_array(lm_get($check, 'status'), ['unavailable', 'needs_review'], true) || lm_get($check, 'error')) return false;
        if (($a['eligible'] ?? false) !== true || ($t['formula_version'] ?? '') !== 'MON-SWING-1.2'
            || ($t['technical_qualified'] ?? false) !== true || ($trend['buy_reference_qualified'] ?? false) !== true
            || ($trend['history_complete'] ?? false) !== true || ($trend['support_broken'] ?? false)
            || in_array($a['reference_setup']['status'] ?? '', ['invalidated', 'unverified'], true)) return false;
        $day = $a['bars_as_of'] ?? null;
        if (!self::referenceDate($day)) return false;
        $cross = $t['macd_cross_date'] ?? null;
        $age = lm_num($t['macd_cross_age_days'] ?? null);
        $event = ($t['ma5_reclaim_close'] ?? false) === true || (in_array($t['macd_kind'] ?? null, ['MACD_GOLD', 'MACD_AFTER'], true)
            && self::referenceDate($cross) && strcmp($cross, $day) <= 0 && $age !== null && $age >= 0 && $age <= 3);
        if (!$event) return false;
        $values = [];
        foreach (['last_close', 'MA5', 'MA20', 'MA60', 'MA200', 'MA5_slope_1', 'MA20_slope_1', 'MA20_slope_5',
            'MA60_slope_1', 'MA60_slope_5', 'ATR14', 'volume_ratio20', 'R20', 'RS20_pp', 'prior20_low'] as $key) {
            $values[$key] = lm_num($m[$key] ?? null);
            if ($values[$key] === null) return false;
        }
        $hist = lm_num($t['histogram'] ?? null);
        $last = $values['last_close'];
        return $hist !== null && $hist > 0 && $values['R20'] > 0 && $values['RS20_pp'] > 0
            && $values['ATR14'] > 0 && $values['volume_ratio20'] >= 1.10
            && $last >= $values['MA5'] && $last >= $values['MA20'] && $last >= $values['MA60'] && $last >= $values['MA200']
            && $last >= $values['prior20_low'] && $last - $values['MA20'] <= 2 * $values['ATR14']
            && $values['MA5_slope_1'] > 0 && $values['MA20_slope_1'] >= 0 && $values['MA20_slope_5'] >= 0
            && $values['MA60_slope_1'] >= 0 && $values['MA60_slope_5'] >= 0;
    }

    /** Reference MS7 is a diagnostic, never an order or a ChatGPT final decision. */
    public static function ms7FromAnalysis(array $a): array {
        $labels = ['M0' => '관찰', 'M1' => '매입 검토', 'M2' => '분할매입', 'W' => '대기',
            'S1' => '매도 준비', 'S2' => '분할매도', 'S3' => '대부분 매도'];
        $m = $a['metrics'] ?? [];
        $t = $a['technical'] ?? [];
        $trend = $a['trend'] ?? [];
        $rs = lm_num($m['RS20_pp'] ?? null);
        $last = lm_num($m['last_close'] ?? null);
        $ma5 = lm_num($m['MA5'] ?? null);
        $ma20 = lm_num($m['MA20'] ?? null);
        $hist = lm_num($t['histogram'] ?? null);
        if ($rs === null || $last === null || $ma5 === null || $ma20 === null || !isset($trend['state'])) return [
            'code' => 'W', 'label' => $labels['W'], 'confidence' => 'PROVISIONAL', 'reason_codes' => ['trend_data_pending']
        ];
        $invalidated = ($a['reference_setup']['status'] ?? '') === 'invalidated';
        if (($trend['support_broken'] ?? false) || $invalidated) {
            $code = $rs <= -15 ? 'S3' : ($rs <= -10 ? 'S2' : 'S1');
            $why = [$invalidated ? 'previous_setup_invalidated' : 'prior20_support_broken', 'risk_warning_not_order'];
        } elseif (($trend['below_falling_ma20'] ?? false) && ($trend['below_falling_ma60'] ?? false)) {
            $code = $rs <= -15 ? 'S3' : ($rs <= -10 ? 'S2' : 'S1');
            $why = ['absolute_downtrend', 'risk_warning_not_order'];
        } elseif ($rs <= -15 && $last < $ma20 && ($hist === null || $hist < 0)) {
            $code = 'S3';
            $why = ['severe_relative_weakness', 'below_ma20', 'risk_warning_not_order'];
        } elseif ($rs <= -10 && $last < $ma20) {
            $code = 'S2';
            $why = ['relative_weakness', 'below_ma20', 'risk_warning_not_order'];
        } elseif ($rs <= -5 && $last < $ma20) {
            $code = 'S1';
            $why = ['weak_relative_strength', 'below_ma20', 'risk_warning_not_order'];
        } elseif (self::referenceBuyReady($a)) {
            $stack = lm_num($m['MA200'] ?? null) !== null && $ma5 >= $ma20 && $ma20 >= (float)$m['MA60']
                && (float)$m['MA60'] >= (float)$m['MA200'] && (float)($m['MA200_slope_5'] ?? -1) >= 0;
            $code = $stack ? 'M2' : 'M1';
            $why = [$stack ? 'aligned_trend_reference' : 'confirmed_recovery_reference', 'qualified_swing_signal', 'volume_confirmed', 'positive_rs20'];
        } elseif (($trend['history_complete'] ?? false) && ($last >= $ma5 || ($hist !== null && $hist > 0))) {
            $code = 'M0';
            $why = ['recovery_observation_only', 'buy_conditions_incomplete'];
        } else {
            $code = 'W';
            $why = ['signal_not_ready'];
        }
        return ['code' => $code, 'label' => $labels[$code], 'confidence' => 'REFERENCE_ONLY', 'reason_codes' => $why];
    }

    public static function referenceDate($value): bool {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /** Only bounded scalar fields from the existing cache may cross a restart. */
    public static function normalizeReferenceSetup($value): ?array {
        $v = is_object($value) ? (array)$value : $value;
        if (!is_array($v)) return null;
        if (($v['status'] ?? '') === 'unverified') return ['status' => 'unverified',
            'first_guard_unverified_at' => self::referenceDate($v['first_guard_unverified_at'] ?? null) ? $v['first_guard_unverified_at'] : null,
            'previous_failure_date_unknown' => true, 'reason_codes' => ['reference_setup_invalid']];
        $status = $v['status'] ?? '';
        $support = lm_num($v['locked_support'] ?? null);
        if (!in_array($status, ['active', 'invalidated'], true) || !self::referenceDate($v['signal_date'] ?? null)
            || $support === null || $support <= 0) return null;
        $established = $v['established_at'] ?? $v['signal_date'];
        if (!self::referenceDate($established) || strcmp($established, $v['signal_date']) < 0) return null;
        $out = ['status' => $status, 'signal_date' => $v['signal_date'],
            'established_at' => $established, 'locked_support' => $support,
            'invalidated_at' => null, 'invalidated_support' => null];
        if ($status === 'invalidated') {
            $floor = lm_num($v['invalidated_support'] ?? null);
            if (!self::referenceDate($v['invalidated_at'] ?? null) || strcmp($v['invalidated_at'], $established) < 0
                || $floor === null || $floor <= 0) return null;
            $out['invalidated_at'] = $v['invalidated_at'];
            $out['invalidated_support'] = $floor;
        }
        if (isset($v['rearmed_after'])) {
            $floor = lm_num($v['previous_invalidated_support'] ?? null);
            if (!self::referenceDate($v['rearmed_after']) || $floor === null || $floor <= 0
                || strcmp($v['signal_date'], $v['rearmed_after']) <= 0) return null;
            $out['rearmed_after'] = $v['rearmed_after'];
            $out['previous_invalidated_support'] = $floor;
        }
        if (($v['previous_guard_unverified'] ?? false) === true) {
            if (!self::referenceDate($v['guard_unverified_since'] ?? null) || strcmp($v['signal_date'], $v['guard_unverified_since']) <= 0) return null;
            $out['previous_guard_unverified'] = true;
            $out['previous_failure_date_unknown'] = true;
            $out['guard_unverified_since'] = $v['guard_unverified_since'];
        }
        $allowed = ['locked_support_broken', 'previous_hypothesis_failed', 'new_signal_after_failure', 'new_macd_cross_setup',
            'old_support_recovered', 'reference_hypothesis_started', 'prior_reference_support_preserved', 'new_cross_after_unverified'];
        $out['reason_codes'] = [];
        foreach (array_slice(is_array($v['reason_codes'] ?? null) ? $v['reason_codes'] : [], 0, 4) as $reason)
            if (is_string($reason) && in_array($reason, $allowed, true)) $out['reason_codes'][] = $reason;
        return $out;
    }

    /** Preserve one fixed hypothesis, not a history of prices or an inferred account position. */
    public static function applyReferenceSetup(array $a, array $prior = [], array $completedBars = []): array {
        $day = (string)($a['bars_as_of'] ?? '');
        $last = lm_num($a['metrics']['last_close'] ?? null);
        $old = $prior['reference_setup'] ?? null;
        $setup = self::normalizeReferenceSetup($old);
        if ($setup === null && !array_key_exists('reference_setup', $prior) && ($prior['eligible'] ?? false) === true
            && in_array($prior['ms7']['code'] ?? '', ['M1', 'M2'], true)
            && lm_num($prior['metrics']['S'] ?? null) !== null && is_string($prior['bars_as_of'] ?? null)) {
            $setup = ['status' => 'active', 'signal_date' => $prior['bars_as_of'], 'established_at' => $prior['bars_as_of'], 'locked_support' => $prior['metrics']['S'],
                'invalidated_at' => null, 'invalidated_support' => null, 'reason_codes' => ['prior_reference_support_preserved']];
            $old = $setup;
            $prior['reference_setup'] = $setup;
            $setup = self::normalizeReferenceSetup($setup);
        }
        $priorDay = (string)($prior['reference_setup_last_evaluated_date'] ?? $prior['bars_as_of'] ?? '');
        if ($setup && self::referenceDate($priorDay) && strcmp($day, $priorDay) < 0) {
            $a['reference_setup'] = $setup;
            $a['eligible'] = false;
            $a['status'] = 'observe';
            $a['trend']['buy_reference_qualified'] = false;
            $a['reason_codes'][] = 'reference_setup_older_bar';
            $a['technical']['trend'] = $a['trend'];
            $a['ms7'] = self::ms7FromAnalysis($a);
            return $a;
        }
        $futureGuard = false;
        foreach (['signal_date', 'established_at', 'invalidated_at', 'rearmed_after', 'guard_unverified_since'] as $field)
            if (isset($setup[$field]) && self::referenceDate($setup[$field]) && strcmp($setup[$field], $day) > 0) $futureGuard = true;
        if ((array_key_exists('reference_setup', $prior) && $setup === null) || ($setup['status'] ?? '') === 'unverified' || $futureGuard) {
            $raw = is_object($old) ? (array)$old : (is_array($old) ? $old : []);
            $first = $setup['first_guard_unverified_at'] ?? $raw['first_guard_unverified_at'] ?? null;
            if (!self::referenceDate($first) || strcmp($first, $day) > 0) $first = $day;
            $candidate = $a;
            unset($candidate['reference_setup']);
            $cross = $a['technical']['macd_cross_date'] ?? null;
            $newCross = self::referenceDate($cross) && strcmp($cross, $first) > 0 && strcmp($cross, $day) <= 0
                && in_array($a['technical']['macd_kind'] ?? null, ['MACD_GOLD', 'MACD_AFTER'], true);
            if ($newCross && self::referenceBuyReady($candidate)) {
                $a['reference_setup'] = ['status' => 'active', 'signal_date' => $cross, 'established_at' => $day,
                    'locked_support' => $a['metrics']['S'], 'invalidated_at' => null, 'invalidated_support' => null,
                    'previous_guard_unverified' => true, 'previous_failure_date_unknown' => true,
                    'guard_unverified_since' => $first, 'reason_codes' => ['new_cross_after_unverified']];
            } else {
                $a['reference_setup'] = ['status' => 'unverified', 'first_guard_unverified_at' => $first,
                    'previous_failure_date_unknown' => true, 'reason_codes' => ['reference_setup_invalid']];
                $a['eligible'] = false;
                $a['status'] = 'observe';
                $a['trend']['buy_reference_qualified'] = false;
                $a['reason_codes'][] = 'reference_setup_invalid';
            }
            $a['technical']['trend'] = $a['trend'];
            $a['ms7'] = self::ms7FromAnalysis($a);
            return $a;
        }
        $event = ($a['technical']['ma5_reclaim_close'] ?? false) ? $day : null;
        $cross = $a['technical']['macd_cross_date'] ?? null;
        if (in_array($a['technical']['macd_kind'] ?? null, ['MACD_GOLD', 'MACD_AFTER'], true) && is_string($cross)
            && self::referenceDate($cross) && strcmp($cross, $day) <= 0 && ($event === null || strcmp($cross, $event) > 0)) $event = $cross;
        if ($setup && ($setup['status'] ?? '') === 'active' && $last !== null) {
            $from = max((string)($setup['established_at'] ?? $setup['signal_date']), $priorDay);
            $failedDay = null;
            if ($completedBars && strcmp($from, (string)$completedBars[0][0]) < 0) {
                $a['reference_setup'] = ['status' => 'unverified', 'first_guard_unverified_at' => $day,
                    'previous_failure_date_unknown' => true, 'reason_codes' => ['reference_setup_invalid']];
                $a['eligible'] = false;
                $a['status'] = 'observe';
                $a['trend']['buy_reference_qualified'] = false;
                $a['reason_codes'][] = 'reference_setup_window_missing';
                $a['technical']['trend'] = $a['trend'];
                $a['ms7'] = self::ms7FromAnalysis($a);
                return $a;
            }
            // Recheck the retained window for provider corrections, including previously evaluated bars.
            // Coverage continuity still uses $from above, so a long unobserved gap remains unverified.
            $retainedFrom = (string)($setup['established_at'] ?? $setup['signal_date']);
            foreach ($completedBars as $bar) {
                if (strcmp((string)$bar[0], $retainedFrom) >= 0 && strcmp((string)$bar[0], $day) <= 0 && (float)$bar[4] < (float)$setup['locked_support']) {
                    $failedDay = (string)$bar[0];
                    break;
                }
            }
            if ($failedDay === null && strcmp($day, $from) >= 0 && $last < (float)$setup['locked_support']) $failedDay = $day;
            if ($failedDay !== null) {
                $setup['status'] = 'invalidated';
                $setup['invalidated_at'] = $failedDay;
                $setup['invalidated_support'] = $setup['locked_support'];
                $setup['reason_codes'] = ['locked_support_broken', 'previous_hypothesis_failed'];
            }
        }
        $candidate = $a;
        unset($candidate['reference_setup']);
        $full = self::referenceBuyReady($candidate);
        unset($candidate);
        if ($setup && ($setup['status'] ?? '') === 'invalidated') {
            $failed = (string)($setup['invalidated_at'] ?? '');
            $floor = lm_num($setup['invalidated_support'] ?? $setup['locked_support'] ?? null);
            $newSignal = $event !== null && strcmp($event, $failed) > 0;
            $newCross = is_string($cross) && strcmp($cross, $failed) > 0
                && in_array($a['technical']['macd_kind'] ?? null, ['MACD_GOLD', 'MACD_AFTER'], true);
            $oldSupportRecovered = $floor !== null && $last !== null && $last > $floor;
            if (!$full || !$newSignal || (!$newCross && !$oldSupportRecovered)) {
                $a['eligible'] = false;
                $a['status'] = 'risk';
                $a['trend']['buy_reference_qualified'] = false;
                $a['reason_codes'][] = 'previous_setup_invalidated';
            } else {
                $setup = ['status' => 'active', 'signal_date' => $event, 'established_at' => $day, 'locked_support' => $a['metrics']['S'],
                    'invalidated_at' => null, 'invalidated_support' => null,
                    'rearmed_after' => $failed, 'previous_invalidated_support' => $floor,
                    'reason_codes' => ['new_signal_after_failure', $newCross ? 'new_macd_cross_setup' : 'old_support_recovered']];
            }
        } elseif ($setup === null && $full && $event !== null) {
            $setup = ['status' => 'active', 'signal_date' => $event, 'established_at' => $day, 'locked_support' => $a['metrics']['S'],
                'invalidated_at' => null, 'invalidated_support' => null, 'reason_codes' => ['reference_hypothesis_started']];
        }
        if ($setup !== null) $a['reference_setup'] = $setup;
        $a['reason_codes'] = array_values(array_unique($a['reason_codes']));
        $a['technical']['trend'] = $a['trend'];
        $a['ms7'] = self::ms7FromAnalysis($a);
        return $a;
    }

    public static function analyzeMapped(array $symbol, array $bars, array $bm, array $priorAnalysis = []): array {
        $b = self::bars($bars);
        $n = count($b);
        if ($n < 60) throw new LiteMonFault('HISTORY_MISSING');
        $start = $n - 21;
        $sum10 = 0.0;
        $sum20 = 0.0;
        $adv = 0.0;
        $low5 = INF;
        $resistance = - INF;
        $firstDate = $b[$start][0];
        $lastDate = $b[$n - 1][0];
        for ($i = $start; $i < $n; $i++) {
            if (!isset ($bm[$b[$i][0]])) throw new LiteMonFault('BENCHMARK_DATE_MISSING');
            if ($i >= $n - 20) {
                $sum20 += $b[$i][4];
                $adv += $b[$i][4] * $b[$i][5];
            }
            if ($i >= $n - 10) $sum10 += $b[$i][4];
            if ($i >= $n - 5) $low5 = min($low5, $b[$i][3]);
            if ($i < $n - 1) $resistance = max($resistance, $b[$i][2]);
        }
        $tech = self::technicalBars($b);
        $last = $b[$n - 1][4];
        $ma10 = $sum10 / 10;
        $ma20 = $sum20 / 20;
        $support = max(
            array_filter([$tech['ma5'], $ma10, $ma20, $low5], function ($x) use($last) {
                return $x <= $last;
            })
        );
        $atr = $tech['atr14'];
        $plans = [];
        if ($atr > 0) foreach ([
            ['pullback', $support - $atr, $support + $atr, min($low5, $support) - .5 * $atr],
            ['breakout', $resistance, $resistance + $atr, $resistance - $atr]
        ] as $q) {
            $lo = self::roundTick($symbol, $q[1], true);
            $hi = self::roundTick($symbol, $q[2], false);
            $stop = self::roundTick($symbol, $q[3], false);
            if ($lo !== null && $hi !== null && $stop !== null && $stop > 0 && $stop < $lo && $lo <= $hi) $plans[] = [
                'mode' => $q[0],
                'entry_zone' => [$lo, $hi],
                'invalidation' => $stop,
                'planned_risk_pct' => lm_round6(100 * ($hi - $stop) / $hi)
            ];
        }
        $stockR = $last / $b[$start][4] - 1;
        $marketR = $bm[$lastDate] / $bm[$firstDate] - 1;
        $metrics = [
            'last_close' => $last,
            'bars_as_of' => $lastDate,
            'R20' => lm_round6($stockR),
            'RS20_pp' => lm_round6(100 * ($stockR - $marketR)),
            'ADV20' => lm_round6($adv / 20),
            'MA5' => $tech['ma5'],
            'MA10' => lm_round6($ma10),
            'MA20' => lm_round6($ma20),
            'ATR14' => $atr,
            'S' => lm_round6($support),
            'R' => lm_round6($resistance)
        ];
        foreach ($tech['trend_metrics'] as $key => $value) if (!array_key_exists($key, $metrics)) $metrics[$key] = $value;
        $trend = $tech['trend'];
        $trend['ma20_extension_pct'] = $ma20 > 0 ? lm_round6(100 * ($last / $ma20 - 1)) : null;
        $trend['ma20_distance_atr'] = $atr > 0 ? lm_round6(($last - $ma20) / $atr) : null;
        $trend['overextended'] = $atr > 0 ? $last - $ma20 > 2 * $atr : null;
        $trend['buy_reference_qualified'] = $trend['absolute_uptrend'] && !$trend['below_ma200'] && !$trend['support_broken'] && $trend['overextended'] === false
            && $trend['volume_confirmed'] && $metrics['RS20_pp'] > 0 && $metrics['R20'] > 0
            && $tech['technical_qualified'] && $tech['histogram'] !== null && $tech['histogram'] > 0;
        $trend['reasons'] = array_values(array_filter([
            !$trend['history_complete'] ? 'long_history_incomplete' : null,
            $trend['below_falling_ma20'] ? 'below_falling_ma20' : null,
            $trend['below_falling_ma60'] ? 'below_falling_ma60' : null,
            $trend['below_ma200'] ? 'below_ma200' : null,
            $trend['support_broken'] ? 'prior20_support_broken' : null,
            !$trend['volume_confirmed'] ? 'volume_below_1_10' : null,
            $trend['overextended'] === true ? 'ma20_extension_above_2_atr' : null,
            $trend['overextended'] === null ? 'extension_atr_unavailable' : null,
            $metrics['R20'] <= 0 ? 'absolute_return_nonpositive' : null
        ]));
        $eligible = $trend['buy_reference_qualified'];
        $a = [
            'symbol_id' => $symbol['symbol_id'],
            'country' => $symbol['country'],
            'name' => $symbol['name'],
            'currency' => $symbol['currency'],
            'bars_as_of' => $lastDate,
            'metrics' => $metrics,
            'technical' => $tech,
            'trend' => $trend,
            'entry_plans' => $plans,
            'eligible' => $eligible,
            'status' => $eligible ? 'pass' : ($trend['support_broken'] || $trend['state'] === 'declining' ? 'risk' : 'observe'),
            'reason_codes' => array_values(
                array_filter(
                    [
                        $metrics['RS20_pp'] <= 0 ? 'not_stronger_than_market' : null,
                        !$tech['technical_qualified'] ? 'no_swing_signal' : null,
                        !$trend['absolute_uptrend'] ? 'absolute_trend_not_confirmed' : null,
                        !$trend['volume_confirmed'] ? 'volume_below_1_10' : null,
            $trend['overextended'] === true ? 'ma20_extension_above_2_atr' : null,
            $trend['overextended'] === null ? 'extension_atr_unavailable' : null,
                        $trend['support_broken'] ? 'prior20_support_broken' : null
                    ]
                )
            )
        ];
        return self::applyReferenceSetup($a, $priorAnalysis, $b);
    }
}

final class LiteYahoo {
    const RANGE_CACHE_BARS = 40;
    const RANGE_CACHE_SYMBOLS = 48;
    private $http;
    private $rangeCache = [];
    private $stats = [];
    private $benchmarkSources = [];
    private $recoveries = [];
    private $latestDates = [];
    private $referenceCloses = [];

    public function __construct(LiteHttp $http) {
        $this->http = $http;
        $this->beginTurn();
    }

    public function beginTurn(): void {
        $this->rangeCache = [];
        $this->recoveries = [];
        $this->latestDates = [];
        $this->referenceCloses = [];
        $this->stats = [
            'yahoo_range_requests' => 0,
            'yahoo_range_cache_hits' => 0,
            'yahoo_rows_dropped' => 0,
            'yahoo_rows_clamped' => 0,
            'benchmark_fallbacks' => 0,
            'jp_benchmark_source' => null,
            'yahoo_envelope_recovery_requests' => 0,
            'yahoo_envelope_recoveries' => 0
        ];
    }

    public function stats(): array {
        return $this->stats;
    }

    public function releasePayloads(): void {
        $this->rangeCache = [];
        $this->referenceCloses = [];
        $this->latestDates = [];
    }

    public static function benchmarkCandidates(string $country, ?string $exchange = null): array {
        if ($country === 'KR') return [$exchange === 'KOSDAQ' ? '^KQ11' : '^KS11'];
        if ($country === 'US') return ['^GSPC'];
        if ($country === 'JP') return ['^TOPX', '1306.T', '^N225'];
        throw new LiteMonFault('BENCHMARK_COUNTRY_INVALID');
    }

    public static function benchmarkFallbackFault(string $code): bool {
        return in_array(
            $code,
            [
                'HTTP_404',
                'YAHOO_EMPTY',
                'YAHOO_ROWS_INVALID',
                'YAHOO_CURRENCY_MISMATCH',
                'YAHOO_INVALID_BAR_BURST',
                'YAHOO_LATEST_BAR_INVALID'
            ],
            true
        );
    }

    public static function sanitizeProviderRows(array $rows): array {
        $out = [];
        $dropped = 0;
        $clamped = 0;
        $n = count($rows);
        $limit = max(2, (int) ceil(max(1, $n) * 0.01));
        foreach ($rows as $i => $r) {
            $bad = false;
            if (!is_array($r) || count($r) < 6 || !is_string($r[0]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $r[0])) $bad = true;
            $v = [];
            if (!$bad) for ($k = 1; $k < 6; $k++) {
                $x = lm_num($r[$k] ?? null);
                if ($x === null || ($k < 5 ? $x <= 0 : $x < 0)) {
                    $bad = true;
                    break;
                }
                $v[$k] = $x;
            }
            if (!$bad) {
                $o = $v[1];
                $h = $v[2];
                $l = $v[3];
                $c = $v[4];
                $top = max($o, $c);
                $bottom = min($o, $c);
                $tol = max(1e-8, max(abs($o), abs($h), abs($l), abs($c), 1.0) * 1e-9);
                if ($h + $tol < $l || $h + $tol < $top || $l - $tol > $bottom) $bad = true;
                else {
                    if ($h < $top) {
                        $h = $top;
                        $clamped++;
                    }
                    if ($l > $bottom) {
                        $l = $bottom;
                        $clamped++;
                    }
                    $r[1] = $o;
                    $r[2] = $h;
                    $r[3] = $l;
                    $r[4] = $c;
                    $r[5] = $v[5];
                }
            }
            if ($bad) {
                if ($i === $n - 1) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
                $dropped++;
                if ($dropped > $limit) throw new LiteMonFault('YAHOO_INVALID_BAR_BURST');
                continue;
            }
            $out[] = $r;
        }
        return ['rows' => $out, 'dropped' => $dropped, 'clamped' => $clamped];
    }

    public static function ticker(array $s): string {
        if (isset ($s['raw_ticker'])) return (string) $s['raw_ticker'];
        if ($s['country'] === 'KR') return $s['symbol'] . ($s['exchange'] === 'KOSDAQ' ? '.KQ' : '.KS');
        if ($s['country'] === 'JP') return $s['symbol'] . '.T';
        return str_replace('.', '-', $s['symbol']);
    }
    // Yahoo daily OHLC/Close is already split-adjusted; never apply split events a second time.
    private static function completed(array $rows, string $country): array {
        if (!$rows) return $rows;
        $tz = new DateTimeZone(['KR' => 'Asia/Seoul', 'JP' => 'Asia/Tokyo', 'US' => 'America/New_York'][$country]);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($tz);
        $today = $now->format('Y-m-d');
        $cut = ['KR' => '16:00', 'JP' => '16:00', 'US' => '16:30'][$country];
        $currentComplete = $now->format('H:i') >= $cut;
        return array_values(
            array_filter(
                $rows,
                static function ($r) use($today, $currentComplete) {
                    return $r[0] < $today || $r[0] === $today && $currentComplete;
                }
            )
        );
    }

    public function recoveryFor(string $symbolId): ?array {
        return $this->recoveries[$symbolId] ?? null;
    }

    private function recoverEnvelope(array $s, array $rows): array {
        if ($s['country'] !== 'US' || !$rows) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
        $i = count($rows) - 1;
        $daily = $rows[$i];
        for ($k = 1; $k <= 5; $k++) {
            if (lm_num($daily[$k] ?? null) === null || ($k < 5 ? $daily[$k] <= 0 : $daily[$k] < 0)) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
        }
        $badHigh = $daily[2] < max($daily[1], $daily[4]);
        $badLow = $daily[3] > min($daily[1], $daily[4]);
        if (!$badHigh && !$badLow || $daily[2] < $daily[3]) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
        $date = (string) $daily[0];
        $tz = new DateTimeZone('America/New_York');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 09:30', $tz);
        if (!$start || (int) $start->format('N') > 5) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
        $end = $start->modify('+390 minutes')->getTimestamp();
        if ($end > time()) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
        $p1 = $start->getTimestamp();
        $ticker = rawurlencode(self::ticker($s));
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . $ticker . '?period1=' . $p1 . '&period2='
            . $end . '&interval=5m&includePrePost=false&includeAdjustedClose=false';
        $this->stats['yahoo_envelope_recovery_requests']++;
        $j = lm_decode($this->http->get($url, [], 8));
        $r = lm_get(lm_get(lm_get($j, 'chart'), 'result'), 0);
        if (!$r || lm_get(lm_get($r, 'meta'), 'currency') !== $s['currency']) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
        $ts = lm_get($r, 'timestamp', []);
        $q = lm_get(lm_get(lm_get($r, 'indicators'), 'quote'), 0);
        if (!is_array($ts) || !is_object($q)) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
        $slots = [];
        foreach ($ts as $original => $stamp) if ((int) $stamp >= $p1 && (int) $stamp < $end) $slots[] = [(int) $stamp, $original];
        if (count($slots) !== 78) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
        $high = 0.0;
        $low = INF;
        $first = null;
        $last = null;
        foreach ($slots as $n => $slot) {
            [$stamp, $original] = $slot;
            if ($stamp !== $p1 + $n * 300) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
            $o = lm_num(lm_get($q, 'open', [])[$original] ?? null);
            $h = lm_num(lm_get($q, 'high', [])[$original] ?? null);
            $l = lm_num(lm_get($q, 'low', [])[$original] ?? null);
            $c = lm_num(lm_get($q, 'close', [])[$original] ?? null);
            if ($o === null || $h === null || $l === null || $c === null || min($o, $h, $l, $c) <= 0 || $h < max($o, $c)
                || $l > min($o, $c) || $h < $l) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
            if ($n === 0) $first = $o;
            $last = $c;
            $high = max($high, $h);
            $low = min($low, $l);
        }
        if (abs($first - $daily[1]) / $daily[1] > 0.001 || abs($last - $daily[4]) / $daily[4] > 0.001 || abs($high - $daily[2]) / $daily[2] > 0.001
            || abs($low - $daily[3]) / $daily[3] > 0.001) throw new LiteMonFault('YAHOO_LATEST_BAR_INVALID');
        if ($badHigh) $rows[$i][2] = $high;
        if ($badLow) $rows[$i][3] = $low;
        $clean = self::sanitizeProviderRows($rows);
        $note = [
            'method' => 'completed_regular_5m_envelope',
            'date' => $date,
            'checked_at' => lm_iso(),
            'regular_bar_count' => 78,
            'daily_open_close_volume_preserved' => true,
            'source_url' => $url
        ];
        $this->recoveries[$s['symbol_id']] = $note;
        while (count($this->recoveries) > self::RANGE_CACHE_SYMBOLS) unset ($this->recoveries[array_key_first($this->recoveries)]);
        $this->stats['yahoo_envelope_recoveries']++;
        return $clean;
    }

    private function fetchRange(array $s, int $p1, int $p2): array {
        $tickerRaw = self::ticker($s);
        $p2Day = gmdate('Y-m-d', $p2);
        $cached = $this->rangeCache[$tickerRaw] ?? null;
        if (is_array($cached) && $cached['p2_day'] === $p2Day && $cached['p1'] <= $p1) {
            $this->stats['yahoo_range_cache_hits']++;
            $from = gmdate('Y-m-d', $p1);
            return array_values(array_filter($cached['rows'], static function ($r) use($from) {
                return $r[0] >= $from;
            }));
        }
        $ticker = rawurlencode($tickerRaw);
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . $ticker . '?period1=' . $p1 . '&period2='
            . $p2 . '&interval=1d&events=splits&includeAdjustedClose=false';
        $this->stats['yahoo_range_requests']++;
        $j = lm_decode($this->http->get($url, [], 8));
        $r = lm_get(lm_get(lm_get($j, 'chart'), 'result'), 0);
        if (!$r) throw new LiteMonFault('YAHOO_EMPTY');
        $m = lm_get($r, 'meta');
        $cur = (string) lm_get($m, 'currency');
        if ($cur !== $s['currency']) throw new LiteMonFault('YAHOO_CURRENCY_MISMATCH');
        $ts = lm_get($r, 'timestamp', []);
        $q = lm_get(lm_get(lm_get($r, 'indicators'), 'quote'), 0);
        if (!is_array($ts) || !is_object($q)) throw new LiteMonFault('YAHOO_ROWS_INVALID');
        $rows = [];
        foreach ($ts as $i => $t) {
            $o = lm_num(lm_get($q, 'open', [])[$i] ?? null);
            $h = lm_num(lm_get($q, 'high', [])[$i] ?? null);
            $l = lm_num(lm_get($q, 'low', [])[$i] ?? null);
            $c = lm_num(lm_get($q, 'close', [])[$i] ?? null);
            $v = lm_num(lm_get($q, 'volume', [])[$i] ?? null);
            $date = gmdate('Y-m-d', (int) $t);
            $rows[] = ['_ts' => (int) $t, 0 => $date, 1 => $o, 2 => $h, 3 => $l, 4 => $c, 5 => $v];
        }
        $rows = self::completed($rows, $s['country']);
        if ($rows) {
            $this->latestDates[$tickerRaw] = $rows[count($rows) - 1][0];
            $closes = [];
            foreach (array_slice($rows, - 21) as $prior) if (lm_num($prior[4]) !== null && $prior[4] > 0) $closes[$prior[0]] = (float) $prior[4];
            $this->referenceCloses[$tickerRaw] = $closes;
            while (count($this->referenceCloses) > self::RANGE_CACHE_SYMBOLS) unset ($this->referenceCloses[array_key_first($this->referenceCloses)]);
            while (count($this->latestDates) > self::RANGE_CACHE_SYMBOLS) unset ($this->latestDates[array_key_first($this->latestDates)]);
        }
        try {
            $clean = self::sanitizeProviderRows($rows);
        } catch (LiteMonFault $e) {
            if ($e->faultCode !== 'YAHOO_LATEST_BAR_INVALID' || $s['country'] !== 'US') throw $e;
            try {
                $clean = $this->recoverEnvelope($s, $rows);
            } catch (Throwable $ignore) {
                throw $e;
            }
        }
        $this->stats['yahoo_rows_dropped'] += $clean['dropped'];
        $this->stats['yahoo_rows_clamped'] += $clean['clamped'];
        $rows = LiteSwing::bars($clean['rows']);
        $cacheRows = array_slice($rows, - self::RANGE_CACHE_BARS);
        $cacheP1 = $cacheRows ? strtotime($cacheRows[0][0] . ' 00:00:00 UTC'): $p1;
        if ($cacheP1 === false) $cacheP1 = $p1;
        if (!is_array($cached) || $cacheP1 < $cached['p1']) $this->rangeCache[$tickerRaw] = ['p1' => $cacheP1, 'p2_day' => $p2Day, 'rows' => $cacheRows];
        while (count($this->rangeCache) > self::RANGE_CACHE_SYMBOLS) unset ($this->rangeCache[array_key_first($this->rangeCache)]);
        return $rows;
    }

    public function daily(array $s, int $days = 220): array {
        $p2 = time() + 86400;
        $calendarDays = (int) ceil($days * 1.5) + 45;
        $p1 = $p2 - $calendarDays * 86400;
        return array_slice($this->fetchRange($s, $p1, $p2), - $days);
    }

    public function recent(array $s, string $lastDate, int $overlapDays = 21): array {
        $t = strtotime($lastDate . ' 00:00:00 UTC');
        if ($t === false) throw new LiteMonFault('INCREMENTAL_DATE_INVALID');
        $p1 = $t - max(7, $overlapDays) * 86400;
        $p2 = time() + 86400;
        return $this->fetchRange($s, $p1, $p2);
    }

    public function latestDateFor(string $ticker): ?string {
        return $this->latestDates[$ticker] ?? null;
    }

    public function referenceClosesFor(string $ticker): array {
        return $this->referenceCloses[$ticker] ?? [];
    }

    public function naverBenchmark(string $country, ?string $exchange = null, int $days = 220): array {
        if ($country !== 'KR' || !in_array($exchange, ['KOSPI', 'KOSDAQ'], true)) throw new LiteMonFault('BENCHMARK_SOURCE_INVALID');
        $symbol = $exchange;
        $url = 'https://fchart.stock.naver.com/sise.nhn?symbol=' . $symbol . '&timeframe=day&count=' . ($days + 1)
            . '&requestType=0';
        $body = $this->http->get($url, [], 8);
        if (strlen($body) > 131072 || !preg_match('/<chartdata\b([^>]*)>/i', $body, $chart) || !preg_match('/\bsymbol="'
            . preg_quote($symbol, '/') . '"/', $chart[1]) || !preg_match('/\btimeframe="day"/', $chart[1])) throw new LiteMonFault('BENCHMARK_FALLBACK_IDENTITY_MISMATCH');
        if (!preg_match_all('/<item\b[^>]*\bdata="([^"]+)"[^>]*\/?\s*>/i', $body, $items)) throw new LiteMonFault('BENCHMARK_FALLBACK_INVALID');
        $rows = [];
        $previous = '';
        foreach ($items[1] as $item) {
            $v = explode('|', $item);
            if (count($v) !== 6 || !preg_match('/^\d{8}$/', $v[0])) throw new LiteMonFault('BENCHMARK_FALLBACK_INVALID');
            $date = DateTimeImmutable::createFromFormat('!Ymd', $v[0], new DateTimeZone('Asia/Seoul'));
            if (!$date || $date->format('Ymd') !== $v[0]) throw new LiteMonFault('BENCHMARK_FALLBACK_INVALID');
            $day = $date->format('Y-m-d');
            if ($day <= $previous) throw new LiteMonFault('BENCHMARK_FALLBACK_INVALID');
            $previous = $day;
            $row = [$day];
            for ($i = 1; $i < 6; $i++) {
                $n = lm_num($v[$i]);
                if ($n === null || ($i < 5 ? $n <= 0 : $n < 0)) throw new LiteMonFault('BENCHMARK_FALLBACK_INVALID');
                $row[] = $n;
            }
            if ($row[2] < max($row[1], $row[4]) || $row[3] > min($row[1], $row[4]) || $row[2] < $row[3]) throw new LiteMonFault('BENCHMARK_FALLBACK_INVALID');
            $rows[] = $row;
        }
        $rows = array_slice(self::completed($rows, 'KR'), - $days);
        if (count($rows) < $days) throw new LiteMonFault('BENCHMARK_FALLBACK_TOO_SHORT');
        return [
            'rows' => $rows,
            'source_provider' => 'NAVER',
            'provider_symbol' => $symbol,
            'source_url' => $url,
            'currency' => 'KRW'
        ];
    }

    public function benchmarkSource(string $country, ?string $exchange = null): ?string {
        return $this->benchmarkSources[$country . '|' . ($exchange ?? '')] ?? null;
    }

    public function benchmark(
        string $country,
        ?string $exchange = null,
        int $days = 220,
        ?string $preferred = null,
        array $exclude = []
    ): array {
        $tickers = self::benchmarkCandidates($country, $exchange);
        if ($preferred !== null && in_array($preferred, $tickers, true)) $tickers = array_merge([$preferred], array_values(array_diff($tickers, [$preferred])));
        $tickers = array_values(array_diff($tickers, $exclude));
        if (!$tickers) throw new LiteMonFault('BENCHMARK_UNAVAILABLE');
        $last = null;
        foreach ($tickers as $i => $ticker) {
            $s = [
                'symbol_id' => $country . '|INDEX|' . $ticker,
                'symbol' => $ticker,
                'raw_ticker' => $ticker,
                'country' => $country,
                'exchange' => $country === 'JP' ? 'TSE' : ($country === 'KR' ? ($exchange === 'KOSDAQ' ? 'KOSDAQ' : 'KOSPI'): 'NYSE'),
                'currency' => ['KR' => 'KRW', 'US' => 'USD', 'JP' => 'JPY'][$country],
                'name' => $ticker
            ];
            try {
                $rows = $this->daily($s, $days);
                $this->benchmarkSources[$country . '|' . ($exchange ?? '')] = $ticker;
                if ($country === 'JP') $this->stats['jp_benchmark_source'] = $ticker;
                if ($i > 0) $this->stats['benchmark_fallbacks']++;
                return $rows;
            } catch (LiteMonFault $e) {
                $last = $e;
                if ($country !== 'JP' || $i === count($tickers) - 1 || !self::benchmarkFallbackFault($e->faultCode)) throw $e;
            }
        }
        if ($last) throw $last;
        throw new LiteMonFault('BENCHMARK_UNAVAILABLE');
    }
}

final class LiteQuality {

    public static function compare(?array $old, array $new): array {
        if (!$old) return ['state' => 'new', 'accept' => true, 'reason' => null];
        if ($old === $new) return ['state' => 'exact', 'accept' => true, 'reason' => null];
        $a = [];
        foreach ($old as $r) $a[$r[0]] = $r;
        $diff = 0;
        $maxRel = 0.0;
        $last20 = array_slice($new, - 20);
        foreach ($last20 as $r) {
            if (!isset ($a[$r[0]])) continue;
            $o = $a[$r[0]];
            for ($i = 1; $i <= 4; $i++) {
                $rel = abs($o[$i] - $r[$i]) / max(abs($o[$i]), 1e-9);
                $maxRel = max($maxRel, $rel);
                if ($rel > 1e-9) $diff++;
            }
        }
        if ($diff === 0) return ['state' => 'exact', 'accept' => true, 'reason' => null];
        $oldTech = LiteSwing::technical($old);
        $newTech = LiteSwing::technical($new);
        $same = lm_hash([$oldTech['qualifying_signals'], $oldTech['macd_kind'], $oldTech['ma5_reclaim_close']]) === lm_hash([$newTech['qualifying_signals'], $newTech['macd_kind'], $newTech['ma5_reclaim_close']]);
        if ($same && $maxRel <= 0.001) return [
            'state' => 'minor_no_signal_change',
            'accept' => true,
            'reason' => 'MINOR_SOURCE_DIFFERENCE_NO_SIGNAL_CHANGE'
        ];
        return ['state' => 'needs_review', 'accept' => false, 'reason' => 'MATERIAL_SOURCE_DIFFERENCE'];
    }
}
// Operating policy and schedule
final class LitePolicy {
    const LIGHT_SECONDS = 1800;
    const CHECKPOINT_EVERY = 5;
    const FULL_RESYNC_SECONDS = 604800;
    const INCREMENTAL_OVERLAP_DAYS = 21;
    const FINAL_RECHECK_PER_COUNTRY = 3;
    const BENCHMARK_RETRY_TTL = 14400;
    const BENCHMARK_COVERAGE_VERSION = 'MON-BENCHMARK-COVERAGE-1.0';
    const MAILBOX_SECONDS = 300;
    const FINAL_RECHECK_TTL = 14400;
    const LOW_MEMORY_HARD_FLOOR = 20971520;
    const LOW_MEMORY_RETRY_SECONDS = 120;
    const CALCULATION_VERSION = 'MON-LITE-CALC-2.2.3-r20';

    public static function nextFullAt(?int $now = null): string {
        $now = $now ?? time();
        $tz = new DateTimeZone('Asia/Seoul');
        $d = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $target = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $d->format('Y-m-d') . ' 16:40', $tz);
        if (!$target) throw new LiteMonFault('SCHEDULE_INVALID');
        if ($target->getTimestamp() <= $now) $target = $target->modify('+1 day');
        while ((int) $target->format('N') > 5) $target = $target->modify('+1 day');
        return lm_iso($target->getTimestamp());
    }
}
// Atomic runtime state and commands
final class LiteStateStore {

    public static function defaultState(): stdClass {
        return (object) [
            'contract' => 'MON-LITE-STATE-1.0',
            'policy' => MON_LITE_POLICY,
            'version' => MON_LITE_VERSION,
            'build' => MON_LITE_BUILD,
            'running' => false,
            'stop_requested' => false,
            'heartbeat_at' => null,
            'phase' => 'IDLE',
            'last_light_check' => null,
            'last_full_scan' => null,
            'next_light_check' => null,
            'next_full_scan' => null,
            'last_mailbox_check' => null,
            'next_mailbox_check' => null,
            'last_mailbox_error' => null,
            'publication_pending' => false,
            'publication_fingerprint' => null,
            'last_publication_error' => null,
            'last_error' => null,
            'progress' => null,
            'last_publish_fingerprint' => null,
            'last_scan_mode' => null,
            'last_network_stats' => null,
            'last_memory' => null,
            'reference_publication_status' => null,
            'run_identity' => null,
            'scan_request_seq' => 0,
            'scan_handled_seq' => 0,
            'control_seq' => 0,
            'manual_control' => null,
            'resource_wait' => null,
            'historical_error' => null
        ];
    }

    private static function canonicalState($s): stdClass {
        $d = self::defaultState();
        if (lm_get($s, 'contract') !== 'MON-LITE-STATE-1.0' || lm_get($s, 'policy') !== MON_LITE_POLICY) return $d;
        foreach (get_object_vars($d) as $k => $v) if (!property_exists($s, $k)) $s->$k = $v;
        if ($s->historical_error === null && is_string($s->last_error) && $s->last_error !== '') $s->historical_error = (object) ['code' => $s->last_error, 'at' => $s->heartbeat_at, 'phase' => $s->phase, 'legacy' => true];
        return $s;
    }

    public static function state(): stdClass {
        return self::canonicalState(LiteRuntime::read('state'));
    }

    public static function installedIdentity(): array {
        return [
            'version' => MON_LITE_VERSION,
            'build' => MON_LITE_BUILD,
            'policy' => MON_LITE_POLICY,
            'source_sha256' => MON_LITE_SOURCE_SHA256
        ];
    }

    public static function startState(?string $controlId = null, ?int $controlSeq = null): stdClass {
        $s = self::state();
        $s->version = MON_LITE_VERSION;
        $s->build = MON_LITE_BUILD;
        $s->policy = MON_LITE_POLICY;
        $s->running = true;
        $s->stop_requested = false;
        $s->last_error = null;
        $s->resource_wait = null;
        $s->phase = 'WAIT';
        $s->heartbeat_at = lm_iso();
        $s->run_identity = (object) array_merge(
            self::installedIdentity(),
            [
                'pid' => getmypid(),
                'started_at' => lm_iso(),
                'started_unix' => microtime(true),
                'sapi' => PHP_SAPI
            ]
        );
        self::saveState($s, true, $controlId, $controlSeq);
        return $s;
    }

    public static function idleHeartbeat(): void {
        $s = self::state();
        $at = strtotime((string) lm_get($s, 'heartbeat_at', ''));
        if ($at === false || time() - $at >= 60) {
            $s->heartbeat_at = lm_iso();
            if ($s->running && !in_array($s->phase, ['ERROR', 'SAVE_PENDING'], true)) $s->phase = 'WAIT';
            self::saveState($s);
        }
    }

    public static function daemonLiveness(): array {
        $s = self::state();
        $held = LiteRuntime::lockHeld();
        $at = strtotime((string) $s->heartbeat_at);
        $age = $at === false ? null : max(0, time() - $at);
        $identity = $s->run_identity;
        $known = is_object($identity) && is_string(lm_get($identity, 'source_sha256')) && strlen(lm_get($identity, 'source_sha256')) === 64;
        $fresh = $age !== null && $age <= 180;
        $status = !$held ? 'stopped' : (!$fresh ? 'stale' : ($known ? 'confirmed' : 'alive_identity_unknown'));
        return [
            'status' => $status,
            'lock_held' => $held,
            'heartbeat_age_seconds' => $age,
            'heartbeat_fresh' => $fresh,
            'identity_known' => $known,
            'installed_source_matches' => $known ? hash_equals(MON_LITE_SOURCE_SHA256, (string) lm_get($identity, 'source_sha256')): null,
            'stored_running' => $s->running
        ];
    }

    public static function saveState($s, bool $allowStopReset = false, ?string $controlId = null, ?int $controlSeq = null): void {
        LiteRuntime::write(
            'state',
            $s,
            static function ($disk, $next) use($allowStopReset, $controlId, $controlSeq) {
                // Lifecycle commands belong to the latest disk generation, never a daemon snapshot.
                $next->control_seq = (int) lm_get($disk, 'control_seq', 0);
                $next->manual_control = lm_get($disk, 'manual_control');
                if ($allowStopReset && ($controlId !== null || $controlSeq !== null)) {
                    $record = self::currentControl($disk, $controlId, $controlSeq);
                    if ($record['phase'] !== 'launching') throw new LiteMonFault('CONTROL_REQUEST_NOT_LAUNCHING');
                    $record['phase'] = 'started';
                    $next->manual_control = (object) $record;
                    if (!is_object(lm_get($next, 'run_identity'))) throw new LiteMonFault('CONTROL_REQUEST_INVALID');
                    $next->run_identity->control_id = $controlId;
                    $next->run_identity->control_seq = $controlSeq;
                }
                if (!$allowStopReset && lm_get($disk, 'stop_requested') === true) $next->stop_requested = true;
                $next->scan_request_seq = max((int) lm_get($disk, 'scan_request_seq', 0), (int) lm_get($next, 'scan_request_seq', 0));
                $next->scan_handled_seq = max((int) lm_get($disk, 'scan_handled_seq', 0), (int) lm_get($next, 'scan_handled_seq', 0));
                if ($next->scan_request_seq > $next->scan_handled_seq) {
                    $pending = lm_get($disk, 'next_full_scan');
                    if (is_string($pending) && strtotime($pending) !== false && (
                        !is_string(lm_get($next, 'next_full_scan')) || strtotime($pending) < strtotime($next->next_full_scan)
                    )) $next->next_full_scan = $pending;
                }
                return $next;
            }
        );
    }

    public static function requestAction(string $action): void {
        if (!in_array($action, ['stop', 'scan'], true)) throw new LiteMonFault('WEB_ACTION_INVALID');
        LiteRuntime::write(
            'state',
            new stdClass(),
            static function ($disk) use($action) {
                $s = self::canonicalState($disk);
                if ($action === 'stop') {
                    $s->stop_requested = true;
                    $s->control_seq = (int) $s->control_seq + 1;
                    $s->manual_control = null;
                } else {
                    $s->scan_request_seq = (int) lm_get($s, 'scan_request_seq', 0) + 1;
                    $s->next_full_scan = lm_iso(time() - 1);
                }
                return $s;
            }
        );
    }

    private static function currentControl($disk, ?string $id, ?int $seq): array {
        if ($id === null || !preg_match('/^[a-f0-9]{32}$/D', $id) || $seq === null || $seq < 1) throw new LiteMonFault('CONTROL_REQUEST_INVALID');
        $record = lm_array(lm_get($disk, 'manual_control'));
        if (!is_array($record) || lm_get($record, 'id') !== $id || lm_get($record, 'seq') !== $seq || (int) lm_get($disk, 'control_seq', 0) !== $seq) throw new LiteMonFault('CONTROL_REQUEST_CANCELLED');
        if (lm_get($record, 'source_sha256') !== MON_LITE_SOURCE_SHA256) throw new LiteMonFault('CONTROL_SOURCE_MISMATCH');
        $deadline = lm_num(lm_get($record, 'deadline_unix'));
        if ($deadline === null || microtime(true) >= $deadline) throw new LiteMonFault('CONTROL_REQUEST_EXPIRED');
        return $record;
    }

    public static function requestControl(string $action, ?int $expectedSeq = null): array {
        if (!in_array($action, ['start', 'restart'], true)) throw new LiteMonFault('WEB_ACTION_INVALID');
        $record = [];
        LiteRuntime::write(
            'state',
            new stdClass(),
            static function ($disk) use($action, $expectedSeq, & $record) {
                $state = self::canonicalState($disk);
                if ($expectedSeq !== null && (int) $state->control_seq !== $expectedSeq) throw new LiteMonFault('CONTROL_REQUEST_CANCELLED');
                $now = microtime(true);
                $state->control_seq = (int) $state->control_seq + 1;
                $record = [
                    'id' => bin2hex(random_bytes(16)),
                    'seq' => $state->control_seq,
                    'action' => $action,
                    'phase' => $action === 'restart' ? 'stopping' : 'waiting',
                    'requested_at' => lm_iso(),
                    'requested_unix' => $now,
                    'deadline_unix' => $now + 180,
                    'source_sha256' => MON_LITE_SOURCE_SHA256,
                    'launch_unix' => null,
                    'error' => null
                ];
                $state->manual_control = (object) $record;
                if ($action === 'restart') $state->stop_requested = true;
                return $state;
            }
        );
        return $record;
    }

    public static function claimControl(string $id, int $seq): array {
        $answer = ['record' => [], 'claimed' => false];
        LiteRuntime::write(
            'state',
            new stdClass(),
            static function ($disk) use($id, $seq, & $answer) {
                $state = self::canonicalState($disk);
                $record = self::currentControl($state, $id, $seq);
                $answer['record'] = $record;
                if (in_array($record['phase'], ['launching', 'started'], true)) return $state;
                if (!in_array($record['phase'], ['waiting', 'stopping'], true)) throw new LiteMonFault('CONTROL_REQUEST_INVALID');
                if (LiteRuntime::lockHeld()) return $state;
                $record['phase'] = 'launching';
                $record['launch_unix'] = microtime(true);
                $state->manual_control = (object) $record;
                $answer = ['record' => $record, 'claimed' => true];
                return $state;
            }
        );
        return $answer;
    }

    public static function failControl(string $id, int $seq, string $code): void {
        LiteRuntime::write(
            'state',
            new stdClass(),
            static function ($disk) use($id, $seq, $code) {
                $state = self::canonicalState($disk);
                $record = lm_array($state->manual_control);
                if (!is_array($record) || lm_get($record, 'id') !== $id || lm_get($record, 'seq') !== $seq || (int) $state->control_seq !== $seq) return $state;
                $record['phase'] = 'failed';
                $record['error'] = $code;
                $state->manual_control = (object) $record;
                return $state;
            }
        );
    }

    public static function heartbeat($s, string $phase, ?string $err = null): void {
        $s->heartbeat_at = lm_iso();
        $s->phase = $phase;
        $s->last_error = $err;
        if ($err !== null) $s->historical_error = (object) ['code' => $err, 'at' => lm_iso(), 'phase' => $phase];
        self::saveState($s);
    }

    public static function stopRequested(): bool {
        return lm_get(self::state(), 'stop_requested') === true;
    }

    public static function resourcePause(stdClass $s, array $resource, string $reason): void {
        $prior = lm_get($s, 'resource_wait');
        $s->resource_wait = (object) [
            'reason' => $reason,
            'since' => lm_get($prior, 'reason') === $reason ? lm_get($prior, 'since', lm_iso()): lm_iso(),
            'checked_at' => lm_iso(),
            'resource' => $resource
        ];
        $s->last_error = null;
        $s->scan_handled_seq = max((int) lm_get($s, 'scan_handled_seq', 0), (int) lm_get($s, 'scan_request_seq', 0));
        $s->next_light_check = lm_iso(time() + LitePolicy::LOW_MEMORY_RETRY_SECONDS);
        $s->next_full_scan = lm_iso(time() + LitePolicy::LOW_MEMORY_RETRY_SECONDS);
        self::heartbeat($s, 'WAIT');
        $e = LiteRuntime::read('evidence');
        if (!$e instanceof stdClass) $e = new stdClass();
        $e->contract = 'MON-LITE-EVIDENCE-1.1';
        $e->resource = $resource;
        $e->observed_at = lm_iso();
        LiteRuntime::write('evidence', $e);
    }
}

final class LiteTurn {
    private $http;
    private $yahoo;

    public function __construct($http, $yahoo) {
        $this->http = $http;
        $this->yahoo = $yahoo;
    }

    public function begin(): void {
        $this->http->clearRequestCache();
        $this->http->resetStats();
        $this->yahoo->beginTurn();
    }

    public function end(): void {
        if (method_exists($this->http, 'releasePayloads')) $this->http->releasePayloads();
        if (method_exists($this->yahoo, 'releasePayloads')) $this->yahoo->releasePayloads();
        self::releaseMemory();
    }

    public function networkStats(array $stats): array {
        return array_merge($stats, $this->http->stats(), $this->yahoo->stats());
    }

    public static function releaseMemory(): void {
        if (function_exists('gc_collect_cycles')) @gc_collect_cycles();
        if (function_exists('gc_mem_caches')) @gc_mem_caches();
    }
}
// Market data, benchmark coverage and reference calculations
/**
 * Market retrieval and cached-analysis rules.
 * The cache and every scan work record belong to the caller, never this service.
 */
final class LiteMarketData {
    private $http;
    private $yahoo;
    private $benchmarkRefreshes = [];

    public function __construct($http, $yahoo) {
        $this->http = $http;
        $this->yahoo = $yahoo;
    }

    public function beginTurn(): void {
        $this->benchmarkRefreshes = [];
    }

    public function cachedWatchlist(?stdClass $c = null): array {
        $c = $c ?? $this->cacheObj();
        $rows = [];
        foreach ((array) lm_get($c, 'watchlist', []) as $x) {
            $a = (array) $x;
            if (isset ($a['symbol_id'])) $rows[] = $a;
        }
        return $rows;
    }

    public static function symbolFromId(string $sid, $src = null): ?array {
        $sid = trim($sid);
        $p = explode('|', $sid);
        if (count($p) !== 3 || !in_array($p[0], ['KR', 'US', 'JP'], true) || $p[1] === '' || $p[2] === '') return null;
        $ex = ['KR' => ['KOSPI', 'KOSDAQ'], 'US' => ['NASDAQ', 'NYSE', 'AMEX'], 'JP' => ['TSE']];
        if (!in_array($p[1], $ex[$p[0]], true)) return null;
        $cc = ['KR' => 'KRW', 'US' => 'USD', 'JP' => 'JPY'];
        return [
            'symbol_id' => $sid,
            'country' => $p[0],
            'exchange' => $p[1],
            'symbol' => $p[2],
            'name' => (string) lm_get($src, 'name', $p[2]),
            'currency' => (string) lm_get($src, 'currency', $cc[$p[0]])
        ];
    }

    public function handoffWatchlist(): array {
        $m = $this->http->githubGet('mon_chatgpt_handoff.json');
        if (!$m || trim((string) $m['content']) === '') return [];
        $q = lm_decode($m['content']);
        if (lm_get($q, 'contract') !== 'MON-LITE-BRIDGE-1.0' || lm_get($q, 'policy') !== MON_LITE_POLICY || lm_get($q, 'stage') !== 'candidates') return [];
        $ids = lm_get($q, 'candidate_ids', []);
        $rows = [];
        if (is_array($ids)) foreach ($ids as $sid) {
            if (!is_string($sid)) continue;
            $a = self::symbolFromId($sid);
            if ($a) $rows[$sid] = $a;
        }
        return array_values($rows);
    }

    public function loadRemoteWatchlist(bool $preferCache = false): array {
        if ($preferCache) {
            $cached = $this->cachedWatchlist();
            if ($cached) return $cached;
        }
        $rows = [];
        $g = $this->http->githubGet('mon_data.json');
        if ($g && trim((string) $g['content']) !== '') {
            try {
                $d = lm_decode($g['content']);
                if (lm_get($d, 'contract') === 'MON-LITE-DATA-2.2' && lm_get($d, 'policy') === MON_LITE_POLICY) {
                    $w = lm_get(lm_get($d, 'watchlist'), 'symbols', []);
                    if (is_array($w)) foreach ($w as $x) {
                        $sid = (string) lm_get($x, 'symbol_id');
                        $a = self::symbolFromId($sid, $x);
                        if ($a) $rows[$sid] = $a;
                    }
                }
            } catch (LiteMonFault $ignore) {
            }
        }
        if (!$rows) foreach ($this->cachedWatchlist() as $x) $rows[$x['symbol_id']] = $x;
        if (!$rows) foreach ($this->handoffWatchlist() as $x) $rows[$x['symbol_id']] = $x;
        if (!$rows) foreach (self::fallbackCandidates() as $fallbacks) foreach ($fallbacks as $f) {
            $a = self::symbolFromId($f['symbol_id'], $f);
            if ($a) $rows[$a['symbol_id']] = $a;
        }
        if (!$rows) throw new LiteMonFault('WATCHLIST_FALLBACK_UNAVAILABLE');
        return array_values($rows);
    }

    public function cacheObj(): stdClass {
        $c = LiteRuntime::read('cache');
        if (!in_array(lm_get($c, 'contract'), ['MON-LITE-CACHE-1.0', 'MON-LITE-CACHE-1.1', 'MON-LITE-CACHE-1.2'], true)) $c = (object) [
            'contract' => 'MON-LITE-CACHE-1.2',
            'watchlist' => [],
            'symbols' => new stdClass(),
            'benchmarks' => new stdClass(),
            'benchmark_meta' => new stdClass(),
            'last_full_resync_at' => null,
            'full_resync_pending' => false,
            'updated_at' => null
        ];
        $c->contract = 'MON-LITE-CACHE-1.2';
        if (!property_exists($c, 'symbols') || !is_object($c->symbols)) $c->symbols = new stdClass();
        foreach (get_object_vars($c->symbols) as $sid => $row) if ($row instanceof stdClass && lm_get($row, 'analysis') instanceof stdClass) $row->analysis = lm_array($row->analysis);
        if (!property_exists($c, 'benchmarks') || !is_object($c->benchmarks)) $c->benchmarks = new stdClass();
        if (!property_exists($c, 'last_full_resync_at')) $c->last_full_resync_at = null;
        if (!property_exists($c, 'full_resync_pending')) $c->full_resync_pending = false;
        if (!property_exists($c, 'benchmark_meta') || !is_object($c->benchmark_meta)) $c->benchmark_meta = new stdClass();
        return $c;
    }

    public static function needsTrendHistoryRefresh($row): bool {
        $bars = lm_get($row, 'bars');
        return is_array($bars) && count($bars) < 205 && lm_get($row, 'calculation_version') !== LitePolicy::CALCULATION_VERSION;
    }

    public function priorReferenceAnalysis(stdClass $c, string $sid, $row): array {
        $prior = lm_array(lm_get($row, 'analysis')) ?? [];
        $stored = lm_get(lm_get($c, 'reference_setups'), $sid);
        if (!$stored) return $prior;
        $storedSetup = LiteSwing::normalizeReferenceSetup(lm_get($stored, 'reference_setup'));
        $storedDay = (string)lm_get($stored, 'last_evaluated_date', '');
        if ($storedSetup === null || !LiteSwing::referenceDate($storedDay)) {
            $prior['reference_setup'] = ['status' => 'unverified', 'first_guard_unverified_at' => $storedSetup['first_guard_unverified_at'] ?? null,
                'previous_failure_date_unknown' => true, 'reason_codes' => ['reference_setup_invalid']];
            return $prior;
        }
        // The separate checkpoint is authoritative even if an old symbol row is replayed or re-added.
        $prior['reference_setup'] = $storedSetup;
        $prior['reference_setup_last_evaluated_date'] = $storedDay;
        return $prior;
    }

    public function guardCurrentAnalysis(stdClass $c, string $sid, $row): array {
        $a = lm_array(lm_get($row, 'analysis')) ?? [];
        return LiteSwing::applyReferenceSetup($a, $this->priorReferenceAnalysis($c, $sid, $row), (array)lm_get($row, 'bars', []));
    }

    public function rememberReferenceSetup(stdClass $c, string $sid, array $a): void {
        if (!array_key_exists('reference_setup', $a) || !self::symbolFromId($sid)) return;
        $setup = LiteSwing::normalizeReferenceSetup($a['reference_setup']);
        if ($setup === null) $setup = ['status' => 'unverified', 'reason_codes' => ['reference_setup_invalid']];
        if (!property_exists($c, 'reference_setups') || !is_object($c->reference_setups)) $c->reference_setups = new stdClass();
        $day = (string)($a['bars_as_of'] ?? '');
        $old = lm_get($c->reference_setups, $sid);
        $oldDay = (string)lm_get($old, 'last_evaluated_date', '');
        $oldSetup = LiteSwing::normalizeReferenceSetup(lm_get($old, 'reference_setup'));
        $legitimateRearm = is_array($oldSetup) && ($setup['status'] ?? '') === 'active' && LiteSwing::referenceBuyReady($a)
            && ((($oldSetup['status'] ?? '') === 'invalidated' && ($setup['rearmed_after'] ?? null) === ($oldSetup['invalidated_at'] ?? null))
                || (($oldSetup['status'] ?? '') === 'unverified' && ($setup['previous_guard_unverified'] ?? false) === true
                    && ($setup['guard_unverified_since'] ?? null) === ($oldSetup['first_guard_unverified_at'] ?? null)));
        if ($old && (strcmp($day, $oldDay) < 0 || ($day === $oldDay && is_array($oldSetup)
            && in_array($oldSetup['status'] ?? '', ['invalidated', 'unverified'], true) && ($setup['status'] ?? '') === 'active' && !$legitimateRearm))) return;
        $c->reference_setups->$sid = (object)['reference_setup' => $setup, 'last_evaluated_date' => $day];
        $rows = get_object_vars($c->reference_setups);
        if (count($rows) <= 256) return;
        uksort($rows, static function($left, $right) use($rows) {
            return strcmp((string)lm_get($rows[$left], 'last_evaluated_date', ''), (string)lm_get($rows[$right], 'last_evaluated_date', ''))
                ?: strcmp($left, $right);
        });
        $c->reference_setups = (object)array_slice($rows, -256, null, true);
    }

    public function pendingCount(stdClass $c): int {
        $ids = [];
        $active = [];
        foreach ($this->cachedWatchlist($c) as $sym) {
            $sid = $sym['symbol_id'];
            $active[$sid] = true;
            if (!$this->rowCurrent($c, $sym, lm_get($c->symbols, $sid))) $ids[$sid] = true;
        }
        foreach ((array) lm_get($c, 'scan_queue', []) as $sid) if (is_string($sid) && isset ($active[$sid])) $ids[$sid] = true;
        return count($ids);
    }

    public function benchmarkKey(array $s): string {
        return $s['country'] === 'KR' ? 'KR-' . $s['exchange'] : $s['country'];
    }

    public function benchmarkSpec(string $key): array {
        if ($key === 'KR-KOSPI') return ['KR', 'KOSPI'];
        if ($key === 'KR-KOSDAQ') return ['KR', 'KOSDAQ'];
        if ($key === 'US') return ['US', null];
        if ($key === 'JP') return ['JP', null];
        throw new LiteMonFault('BENCHMARK_KEY_INVALID');
    }

    public static function missingBenchmarkDates(array $map, array $bars, bool $historyBound = false): array {
        $missing = [];
        $first = $map ? min(array_keys($map)): null;
        foreach (array_slice($bars, $historyBound ? - 220 : - 21) as $r) {
            if ($historyBound && $first !== null && $r[0] < $first) continue;
            if (!isset ($map[$r[0]]) || lm_num($map[$r[0]]) === null || $map[$r[0]] <= 0) $missing[] = $r[0];
        }
        return array_values(array_unique($missing));
    }

    public static function reportedRequirement(array $requirement, ?string $reported): array {
        $dates = array_fill_keys($requirement['required_dates'], true);
        if ($reported !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reported)) $dates[$reported] = true;
        ksort($dates);
        $dates = array_slice(array_keys($dates), - 220);
        return ['required_date' => $dates ? $dates[count($dates) - 1] : null, 'required_dates' => $dates];
    }

    public function benchmarkRequirement(stdClass $c, string $key, ?array $extra = null): array {
        $dates = [];
        foreach ($this->cachedWatchlist($c) as $sym) {
            if ($this->benchmarkKey($sym) !== $key) continue;
            $bars = lm_get(lm_get($c->symbols, $sym['symbol_id']), 'bars');
            if (is_array($bars)) foreach (array_slice($bars, - 21) as $r) $dates[$r[0]] = true;
        }
        if ($extra) foreach (array_slice($extra, - 21) as $r) $dates[$r[0]] = true;
        return self::reportedRequirement(
            ['required_dates' => array_keys($dates)],
            lm_get(lm_get($c->benchmark_meta, $key), 'reported_date')
        );
    }

    public static function checkBenchmarkOverlap(array $rows, array $reference): void {
        $map = LiteSwing::benchmarkMap($rows);
        foreach ($reference as $date => $close) if (isset ($map[$date]) && abs($map[$date] - $close) / max(abs($close), 1e-9) > 0.001) throw new LiteMonFault('BENCHMARK_FALLBACK_IDENTITY_MISMATCH');
    }

    public static function benchmarkProviderFault(string $code): bool {
        return LiteYahoo::benchmarkFallbackFault($code) || in_array($code, ['BENCHMARK_COVERAGE_MISSING', 'JSON_DECODE_FAILED'], true)
            || preg_match('/^HTTP_(?:[45]\d{2}|TRANSPORT_\d+|EMPTY|RESPONSE_TOO_LARGE)$/', $code) === 1;
    }

    public static function benchmarkDetails($meta): array {
        return [
            'source_ticker' => lm_get($meta, 'source_ticker'),
            'source_provider' => lm_get($meta, 'source_provider', 'YAHOO'),
            'provider_symbol' => lm_get($meta, 'provider_symbol'),
            'source_url' => lm_get($meta, 'source_url'),
            'as_of' => lm_get($meta, 'last_date'),
            'required_date' => lm_get($meta, 'required_date'),
            'coverage_status' => lm_get($meta, 'coverage_status'),
            'status' => lm_get($meta, 'status')
        ];
    }

    public function loadBenchmark(stdClass $c, string $key, bool $forceFull, bool $retryOnly, array & $stats, ?array $extraBars = null): array {
        [$country, $exchange] = $this->benchmarkSpec($key);
        $candidates = LiteYahoo::benchmarkCandidates($country, $exchange);
        $cached = lm_get($c->benchmarks, $key);
        $meta = lm_get($c->benchmark_meta, $key, new stdClass());
        $source = (string) lm_get($meta, 'source_ticker', '');
        $provider = (string) lm_get($meta, 'source_provider', 'YAHOO');
        $providerSymbol = (string) lm_get($meta, 'provider_symbol', $source);
        $known = in_array($source, $candidates, true) && in_array($provider, ['YAHOO', 'NAVER'], true) && ($provider !== 'NAVER'
            || $country === 'KR' && $providerSymbol === $exchange);
        $requirement = $this->benchmarkRequirement($c, $key, $extraBars);
        $requiredBars = array_map(static function ($date) {
            return [$date];
        }, $requirement['required_dates']);
        $cacheMap = [];
        if (is_array($cached)) {
            try {
                $cacheMap = LiteSwing::benchmarkMap($cached);
            } catch (LiteMonFault $ignore) {
                $known = false;
            }
        }
        $cacheMissing = self::missingBenchmarkDates($cacheMap, $requiredBars, true);
        $goodAt = strtotime((string) lm_get($meta, 'last_good_at', lm_get($meta, 'checked_at', '')));
        $fresh = $retryOnly && $known && lm_get($meta, 'coverage_version') === LitePolicy::BENCHMARK_COVERAGE_VERSION
            && lm_get($meta, 'status') === 'ok' && is_array($cached) && count($cached) >= 60 && !$cacheMissing && $goodAt !== false
            && time() - $goodAt < LitePolicy::BENCHMARK_RETRY_TTL;
        $network = false;
        $sourceUrl = lm_get($meta, 'source_url');
        try {
            if ($fresh) {
                $rows = $cached;
                $stats['benchmark_cache_hits']++;
            } else {
                if (isset ($this->benchmarkRefreshes[$key])) throw new LiteMonFault('BENCHMARK_COVERAGE_MISSING');
                $this->benchmarkRefreshes[$key] = true;
                $network = true;
                $stats['benchmark_downloads']++;
                if ($provider === 'NAVER' && $known) {
                    $fallback = $this->yahoo->naverBenchmark($country, $exchange, 220);
                    $rows = $fallback['rows'];
                    self::checkBenchmarkOverlap($rows, array_slice($cacheMap, - 21, null, true));
                    $sourceUrl = $fallback['source_url'];
                } else {
                    try {
                        $full = $forceFull || !$known || !is_array($cached) || count($cached) < 60 || !empty ($cacheMissing);
                        if (!$full) {
                            $spec = [
                                'symbol_id' => $country . '|INDEX|' . $key,
                                'symbol' => $source,
                                'raw_ticker' => $source,
                                'country' => $country,
                                'exchange' => $exchange ?? ($country === 'US' ? 'NYSE' : 'TSE'),
                                'currency' => ['KR' => 'KRW', 'US' => 'USD', 'JP' => 'JPY'][$country],
                                'name' => $key
                            ];
                            try {
                                $rows = $this->fetchSeries($spec, $cached, false, $stats);
                            } catch (LiteMonFault $e) {
                                if ($country !== 'JP' || !LiteYahoo::benchmarkFallbackFault($e->faultCode)) throw $e;
                                $rows = $this->yahoo->benchmark($country, $exchange, 220, null, [$source]);
                                $source = method_exists($this->yahoo, 'benchmarkSource') ? (string) $this->yahoo->benchmarkSource($country, $exchange): $candidates[0];
                            }
                        } else {
                            $rows = $this->yahoo->benchmark($country, $exchange, 220, $known ? $source : null);
                            $source = method_exists($this->yahoo, 'benchmarkSource') ? (string) $this->yahoo->benchmarkSource($country, $exchange): $candidates[0];
                        }
                        $provider = 'YAHOO';
                        $providerSymbol = $source;
                        $sourceUrl = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($source);
                        if (!in_array($source, $candidates, true)) throw new LiteMonFault('BENCHMARK_SOURCE_UNKNOWN');
                        $map = LiteSwing::benchmarkMap($rows);
                        if (self::missingBenchmarkDates($map, $requiredBars, true)) throw new LiteMonFault('BENCHMARK_COVERAGE_MISSING');
                    } catch (LiteMonFault $e) {
                        if ($country !== 'KR' || !self::benchmarkProviderFault($e->faultCode)) throw $e;
                        $reported = method_exists($this->yahoo, 'latestDateFor') ? $this->yahoo->latestDateFor($source ? : $candidates[0]): null;
                        $requirement = self::reportedRequirement($requirement, $reported);
                        $requiredBars = array_map(static function ($date) {
                            return [$date];
                        }, $requirement['required_dates']);
                        $fallback = $this->yahoo->naverBenchmark($country, $exchange, 220);
                        $rows = $fallback['rows'];
                        $reference = array_slice($cacheMap, - 21, null, true);
                        if (method_exists($this->yahoo, 'referenceClosesFor')) $reference = array_replace($reference, $this->yahoo->referenceClosesFor($source ? : $candidates[0]));
                        self::checkBenchmarkOverlap($rows, $reference);
                        $source = $candidates[0];
                        $provider = 'NAVER';
                        $providerSymbol = $exchange;
                        $sourceUrl = $fallback['source_url'];
                        $stats['benchmark_same_index_fallbacks'] = ($stats['benchmark_same_index_fallbacks'] ?? 0) + 1;
                    }
                }
                $rows = LiteSwing::bars($rows);
                if (count($rows) < 60) throw new LiteMonFault('BENCHMARK_TOO_SHORT');
            }
            $map = LiteSwing::benchmarkMap($rows);
            $missing = self::missingBenchmarkDates($map, $requiredBars, true);
            if ($missing) throw new LiteMonFault('BENCHMARK_COVERAGE_MISSING');
            $fingerprint = lm_hash([$source, $provider, $providerSymbol, $map]);
            $last = $rows[count($rows) - 1];
            $c->benchmarks->{
                $key
            }
            = $rows;
            $c->benchmark_meta->{
                $key
            }
            = (object) array_merge(
                $requirement,
                [
                    'coverage_version' => LitePolicy::BENCHMARK_COVERAGE_VERSION,
                    'source_ticker' => $source,
                    'source_provider' => $provider,
                    'provider_symbol' => $providerSymbol,
                    'source_url' => $sourceUrl,
                    'currency' => ['KR' => 'KRW', 'US' => 'USD', 'JP' => 'JPY'][$country],
                    'checked_at' => lm_iso(),
                    'last_good_at' => $fresh ? lm_get($meta, 'last_good_at', lm_get($meta, 'checked_at')): lm_iso(),
                    'last_date' => $last[0],
                    'reported_date' => max((string) lm_get($meta, 'reported_date', ''), $last[0], (string) $requirement['required_date']),
                    'map_fingerprint' => $fingerprint,
                    'coverage_status' => 'complete',
                    'missing_dates' => [],
                    'status' => 'ok',
                    'last_error' => null
                ]
            );
            return [
                'rows' => $rows,
                'map' => $map,
                'source_ticker' => $source,
                'source_provider' => $provider,
                'map_fingerprint' => $fingerprint,
                'details' => self::benchmarkDetails($c->benchmark_meta->{
                    $key
                }),
                'network_refreshed' => $network
            ];
        } catch (Throwable $e) {
            $m = lm_array($meta);
            if (!is_array($m)) $m = [];
            $reported = method_exists($this->yahoo, 'latestDateFor') ? $this->yahoo->latestDateFor($source ? : $candidates[0]): null;
            $requirement = self::reportedRequirement($requirement, $reported);
            $m['reported_date'] = max((string) lm_get($meta, 'reported_date', ''), (string) $reported);
            $missing = self::missingBenchmarkDates(
                $cacheMap,
                array_map(static function ($date) {
                    return [$date];
                }, $requirement['required_dates']),
                true
            );
            $m = array_merge($m, $requirement);
            $m['coverage_version'] = LitePolicy::BENCHMARK_COVERAGE_VERSION;
            $m['coverage_status'] = 'provider_incomplete';
            $m['missing_dates'] = $missing;
            $m['status'] = 'unavailable';
            $m['checked_at'] = lm_iso();
            $m['last_error'] = $e instanceof LiteMonFault ? $e->faultCode : 'BENCHMARK_FAILED';
            $c->benchmark_meta->{
                $key
            }
            = (object) $m;
            throw $e;
        }
    }

    public function markBenchmarkPending(stdClass $c, string $key, array $watch, array & $queue, array & $stats): void {
        foreach ($watch as $sym) {
            if ($this->benchmarkKey($sym) !== $key) continue;
            $sid = $sym['symbol_id'];
            $old = lm_get($c->symbols, $sid, new stdClass());
            $priorError = (string) lm_get($old, 'last_error', '');
            if (!property_exists($old, 'stock_data_status')) $old->stock_data_status = lm_get($old, 'status') === 'ok'
                || strpos($priorError, 'BENCHMARK_') === 0 ? 'ok' : 'unavailable';
            $old->status = 'pending';
            $old->symbol = (object) $sym;
            $old->last_error = 'BENCHMARK_UNAVAILABLE';
            $old->checked_at = lm_iso();
            $c->symbols->{
                $sid
            }
            = $old;
            unset ($queue[$sid]);
            $stats['benchmark_blocked_symbols']++;
        }
        $c->scan_queue = array_keys($queue);
    }

    public function rowCurrent(stdClass $c, array $sym, $row): bool {
        if (!$row || lm_get($row, 'status') !== 'ok' || !is_array(lm_get($row, 'analysis')) || lm_get($row, 'calculation_version') !== LitePolicy::CALCULATION_VERSION) return false;
        $meta = lm_get($c->benchmark_meta, $this->benchmarkKey($sym));
        $fingerprint = lm_get($meta, 'map_fingerprint');
        return lm_get($meta, 'status') === 'ok' && lm_get($meta, 'coverage_version') === LitePolicy::BENCHMARK_COVERAGE_VERSION
            && !(array) lm_get($meta, 'missing_dates', []) && is_string($fingerprint) && $fingerprint !== '' && lm_get($row, 'benchmark_fingerprint') === $fingerprint;
    }

    public function buildCurrentResults(stdClass $c, array $watch): array {
        $results = [];
        $errors = [];
        $done = 0;
        $pending = 0;
        $queued = array_fill_keys((array) lm_get($c, 'scan_queue', []), true);
        foreach ($watch as $sym) {
            $r = lm_get($c->symbols, $sym['symbol_id']);
            if (!isset ($queued[$sym['symbol_id']]) && $this->rowCurrent($c, $sym, $r)) {
                $a = $this->guardCurrentAnalysis($c, $sym['symbol_id'], $r);
                if (!isset ($a['ms7']) || !is_array($a['ms7'])) $a['ms7'] = LiteSwing::ms7FromAnalysis($a);
                $fc = lm_get($r, 'final_check');
                if ($fc instanceof stdClass) $a['final_check'] = (array) $fc;
                $a['selection_ready'] = lm_get($fc, 'status') !== 'needs_review' && ($a['selection_ready'] ?? true) !== false;
                $results[] = $a;
                $done++;
            } else {
                $pending++;
                $errors[] = [
                    'symbol_id' => $sym['symbol_id'],
                    'error' => isset ($queued[$sym['symbol_id']]) ? 'QUEUED_REFRESH' : ($r ? (string) lm_get($r, 'last_error', 'ANALYSIS_REFRESH_REQUIRED'): 'PENDING')
                ];
            }
        }
        usort(
            $results,
            function ($a, $b) {
                return $b['metrics']['RS20_pp'] <=> $a['metrics']['RS20_pp'] ? : $b['metrics']['R20'] <=> $a['metrics']['R20'] ? : $b['metrics']['ADV20'] <=> $a['metrics']['ADV20'] ? : strcmp($a['symbol_id'], $b['symbol_id']);
            }
        );
        $rank = 1;
        foreach ($results as & $r) {
            $r['rank_hint'] = $rank++;
        }
        unset ($r);
        return [$results, $errors, $done, $pending];
    }

    public static function referenceStateLabel(array $row): string {
        $labels = ['support_broken' => '지지 붕괴', 'declining' => '중기 하락', 'recovering' => '회복 관찰',
            'uptrend' => '단·중기 상승 확인', 'insufficient_history' => '추세 자료 부족',
            'analysis_refresh_required' => '새 규격 재계산 필요', 'final_check_unavailable' => '가격 보조확인 미완료',
            'reference_setup_review_required' => '이전 가설 검증 필요', 'pending' => '자료 미확인',
            'needs_review' => '자료 충돌 확인 필요', 'evaluated' => '완료봉 확인', 'unresolved' => '자료 미확인'];
        $state = (string)($row['trend']['state'] ?? $row['data_status'] ?? 'unresolved');
        $text = $labels[$state] ?? '자료 확인 필요';
        if (($row['trend']['long_term_damage'] ?? false) === true) $text .= ' · 장기선 하회';
        return $text;
    }

    public static function ms7Priority(string $code): int {
        $m = ['M2' => 0, 'M1' => 1, 'M0' => 2, 'W' => 3, 'S1' => 4, 'S2' => 5, 'S3' => 6];
        return $m[$code] ?? 9;
    }

    public static function fallbackCandidates(): array {
        return [
            'KR' => [
                ['symbol_id' => 'KR|KOSPI|005930', 'name' => '삼성전자'],
                ['symbol_id' => 'KR|KOSPI|000660', 'name' => 'SK하이닉스'],
                ['symbol_id' => 'KR|KOSPI|005380', 'name' => '현대차']
            ],
            'US' => [
                ['symbol_id' => 'US|NASDAQ|MSFT', 'name' => 'Microsoft'],
                ['symbol_id' => 'US|NASDAQ|NVDA', 'name' => 'NVIDIA'],
                ['symbol_id' => 'US|NASDAQ|GOOGL', 'name' => 'Alphabet A']
            ],
            'JP' => [
                ['symbol_id' => 'JP|TSE|7203', 'name' => 'Toyota'],
                ['symbol_id' => 'JP|TSE|6758', 'name' => 'Sony'],
                ['symbol_id' => 'JP|TSE|8306', 'name' => 'MUFG']
            ]
        ];
    }

    /** Separate discovery observations and risk warnings from qualified buy references. */
    public static function referenceLists(array $rows, array $candidateIds = []): array {
        $empty = ['KR' => [], 'US' => [], 'JP' => []];
        $lists = ['buy_candidates' => $empty, 'observations' => $empty, 'risk_warnings' => $empty, 'unresolved' => $empty];
        $seen = [];
        usort($rows, static function($a, $b) {
            $ca = (string)lm_get(lm_get($a, 'ms7'), 'code', 'W');
            $cb = (string)lm_get(lm_get($b, 'ms7'), 'code', 'W');
            $pa = in_array($ca, ['S1', 'S2', 'S3'], true) ? -(int)substr($ca, 1) : self::ms7Priority($ca);
            $pb = in_array($cb, ['S1', 'S2', 'S3'], true) ? -(int)substr($cb, 1) : self::ms7Priority($cb);
            if ($pa !== $pb) return $pa <=> $pb;
            $ma = lm_get($a, 'metrics');
            $mb = lm_get($b, 'metrics');
            if (in_array($ca, ['S1', 'S2', 'S3'], true)) return (float)lm_get($ma, 'RS20_pp', -999) <=> (float)lm_get($mb, 'RS20_pp', -999)
                ?: (float)lm_get($ma, 'R20', -999) <=> (float)lm_get($mb, 'R20', -999)
                ?: strcmp((string)lm_get($a, 'symbol_id', ''), (string)lm_get($b, 'symbol_id', ''));
            return (float)lm_get($mb, 'RS20_pp', -999) <=> (float)lm_get($ma, 'RS20_pp', -999)
                ?: strcmp((string)lm_get($a, 'symbol_id', ''), (string)lm_get($b, 'symbol_id', ''));
        });
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $sid = (string)($r['symbol_id'] ?? '');
            $sym = self::symbolFromId($sid, $r);
            if (!$sym || isset($seen[$sid])) continue;
            $seen[$sid] = true;
            $cc = $sym['country'];
            $metrics = is_array($r['metrics'] ?? null) ? $r['metrics'] : null;
            $ms = is_array($r['ms7'] ?? null) ? $r['ms7'] : LiteSwing::ms7FromAnalysis($r);
            $code = (string)($ms['code'] ?? 'W');
            $ready = ($r['selection_ready'] ?? true) !== false;
            $current = ($r['technical']['formula_version'] ?? '') === 'MON-SWING-1.2';
            $check = $r['final_check'] ?? [];
            $checkUnavailable = in_array(lm_get($check, 'status'), ['unavailable', 'needs_review'], true) || (bool)lm_get($check, 'error');
            $guardUnverified = ($r['reference_setup']['status'] ?? '') === 'unverified'
                || in_array('reference_setup_older_bar', $r['reason_codes'] ?? [], true);
            if ($metrics !== null && (!$current || $checkUnavailable || $guardUnverified)) $ms = ['code' => 'W', 'label' => '대기', 'confidence' => 'PROVISIONAL',
                'reason_codes' => [!$current ? 'analysis_refresh_required' : ($checkUnavailable ? 'final_check_unavailable' : 'reference_setup_review_required')]];
            if ($metrics === null || !$ready || !$current || $checkUnavailable || $guardUnverified) $bucket = 'unresolved';
            elseif (in_array($code, ['S1', 'S2', 'S3'], true) || ($r['reference_setup']['status'] ?? '') === 'invalidated') $bucket = 'risk_warnings';
            elseif ($current && LiteSwing::referenceBuyReady($r) && in_array($code, ['M1', 'M2'], true)) $bucket = 'buy_candidates';
            else $bucket = 'observations';
            if (count($lists[$bucket][$cc]) >= 3) continue;
            $lists[$bucket][$cc][] = [
                'symbol_id' => $sid, 'country' => $cc, 'name' => $r['name'] ?? $sym['name'],
                'bars_as_of' => $r['bars_as_of'] ?? null, 'last_close' => $metrics['last_close'] ?? null,
                'ms7' => $ms, 'eligible' => $bucket === 'buy_candidates',
                'data_status' => $metrics === null ? 'pending' : (!$current ? 'analysis_refresh_required' : ($checkUnavailable ? 'final_check_unavailable' : ($guardUnverified ? 'reference_setup_review_required' : (!$ready ? 'needs_review' : 'evaluated')))),
                'reason_codes' => $r['reason_codes'] ?? [], 'trend' => $r['trend'] ?? null,
                'reference_setup' => $r['reference_setup'] ?? null
            ];
        }
        foreach ($candidateIds as $sid) {
            if (!is_string($sid) || isset($seen[$sid])) continue;
            $sym = self::symbolFromId($sid);
            if (!$sym || count($lists['unresolved'][$sym['country']]) >= 3) continue;
            $seen[$sid] = true;
            $lists['unresolved'][$sym['country']][] = [
                'symbol_id' => $sid, 'country' => $sym['country'], 'name' => $sym['name'],
                'bars_as_of' => null, 'last_close' => null, 'eligible' => false,
                'ms7' => ['code' => 'W', 'label' => '대기', 'confidence' => 'PROVISIONAL', 'reason_codes' => ['data_pending']],
                'data_status' => 'pending', 'reason_codes' => ['data_pending']
            ];
        }
        return $lists;
    }

    public static function recommendThree(array $rows, array $candidateIds = []): array {
        return self::referenceLists($rows, $candidateIds)['buy_candidates'];
    }

    public function resyncDue(stdClass $c): bool {
        $t = lm_get($c, 'last_full_resync_at');
        return !is_string($t) || strtotime($t) === false || time() - (int) strtotime($t) >= LitePolicy::FULL_RESYNC_SECONDS;
    }

    public function mergeBars(array $old, array $recent, int $limit = 220): array {
        if (!$old) return array_slice(LiteSwing::bars($recent), - $limit);
        $map = [];
        foreach (LiteSwing::bars($old) as $r) $map[$r[0]] = $r;
        foreach (LiteSwing::bars($recent) as $r) $map[$r[0]] = $r;
        ksort($map, SORT_STRING);
        return array_slice(array_values($map), - $limit);
    }

    public function fetchSeries(array $sym, ?array $oldBars, bool $forceFull, array & $stats, ?array & $quality = null): array {
        $quality = null;
        if ($forceFull || !$oldBars || count($oldBars) < 60) {
            $stats['full_downloads']++;
            return $this->yahoo->daily($sym, 220);
        }
        $last = $oldBars[count($oldBars) - 1][0];
        $stats['incremental_downloads']++;
        $recent = $this->yahoo->recent($sym, $last, LitePolicy::INCREMENTAL_OVERLAP_DAYS);
        if (!$recent) return $oldBars;
        $merged = $this->mergeBars($oldBars, $recent, 220);
        $overlapOld = [];
        foreach ($oldBars as $r) $overlapOld[$r[0]] = $r;
        $changed = false;
        foreach ($recent as $r) if (isset ($overlapOld[$r[0]]) && $overlapOld[$r[0]] !== $r) {
            $changed = true;
            break;
        }
        if ($changed) {
            $q = LiteQuality::compare($oldBars, $merged);
            if (!$q['accept']) {
                $stats['fallback_full_downloads']++;
                return $this->yahoo->daily($sym, 220);
            }
            $quality = $q;
        }
        return $merged;
    }

    public function analysisReusable($old, array $bars, ?string $benchmarkFingerprint = null): bool {
        return $benchmarkFingerprint !== null && lm_get($old, 'benchmark_fingerprint') === $benchmarkFingerprint
            && lm_get($old, 'calculation_version') === LitePolicy::CALCULATION_VERSION && $old && lm_get($old, 'status') === 'ok'
            && is_array(lm_get($old, 'bars')) && $old->bars === $bars && is_array(lm_get($old, 'analysis'));
    }

    public function shortlist(array $results): array {
        $by = [];
        foreach ($results as $r) if (($r['eligible'] ?? false) === true) $by[$r['country']][] = $r;
        foreach ($by as & $rows) {
            usort($rows, function ($a, $b) {
                return $a['rank_hint'] <=> $b['rank_hint'];
            });
            $rows = array_slice($rows, 0, LitePolicy::FINAL_RECHECK_PER_COUNTRY);
        }
        unset ($rows);
        return $by;
    }

    public function finalRecheck(stdClass $c, array $results, array & $stats): void {
        $short = $this->shortlist($results);
        foreach ($short as $rows) foreach ($rows as $a) {
            $sid = $a['symbol_id'];
            $row = lm_get($c->symbols, $sid);
            if (!$row || !is_array(lm_get($row, 'bars'))) continue;
            $sym = (array) $row->symbol;
            $oldBars = $row->bars;
            $last = $oldBars[count($oldBars) - 1];
            $prior = lm_get($row, 'final_check');
            $priorAt = $prior instanceof stdClass ? strtotime((string) lm_get($prior, 'checked_at', '')): false;
            if ($prior instanceof stdClass && lm_get($prior, 'status') === 'confirmed' && lm_get($prior, 'date') === $last[0]
                && abs((float) lm_get($prior, 'close', 0) - (float) $last[4]) / max(abs((float) $last[4]), 1e-9) <= 0.001
                && $priorAt !== false && time() - $priorAt < LitePolicy::FINAL_RECHECK_TTL) {
                $stats['final_recheck_cache_hits']++;
                $row->analysis['final_check'] = (array) $prior;
                $row->analysis['selection_ready'] = true;
                $c->symbols->{
                    $sid
                }
                = $row;
                continue;
            }
            try {
                $stats['final_rechecks']++;
                $recent = $this->yahoo->recent($sym, $last[0], 10);
                if (!$recent) throw new LiteMonFault('FINAL_RECHECK_EMPTY');
                $newLast = $recent[count($recent) - 1];
                $ok = $newLast[0] === $last[0] && abs($newLast[4] - $last[4]) / max(abs($last[4]), 1e-9) <= 0.001;
                $row->final_check = (object) [
                    'status' => $ok ? 'confirmed' : 'needs_review',
                    'checked_at' => lm_iso(),
                    'date' => $newLast[0],
                    'close' => $newLast[4]
                ];
                $row->analysis['final_check'] = (array) $row->final_check;
                $row->analysis['selection_ready'] = $ok;
                if (!$ok) {
                    $row->last_error = 'FINAL_RECHECK_MISMATCH';
                }
                $c->symbols->{
                    $sid
                }
                = $row;
            } catch (Throwable $e) {
                $code = $e instanceof LiteMonFault ? $e->faultCode : 'FINAL_RECHECK_FAILED';
                $row->final_check = (object) ['status' => 'unavailable', 'checked_at' => lm_iso(), 'error' => $code];
                $row->analysis['final_check'] = (array) $row->final_check;
                $row->analysis['selection_ready'] = true;
                $row->last_error = $code;
                $c->symbols->{
                    $sid
                }
                = $row;
            }
        }
    }
}
// Conditional publication and exact readback
final class LiteVerifiedWriter {
    private $http;

    public function __construct($http) {
        $this->http = $http;
    }

    public function putVerified(string $path, string $content, string $message, bool $readback = false, ?string $expectedOldSha = null): array {
        $old = $this->http->githubGet($path, $readback);
        if ($expectedOldSha !== null && ($old ? (string) $old['sha'] : '') !== $expectedOldSha) throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');
        $expected = lm_git_blob_sha($content);
        if ($old && hash_equals($old['content'], $content) && hash_equals($expected, (string) $old['sha'])) return ['blob_sha' => $old['sha'], 'commit_sha' => $old['commit_sha']
            ?? null, 'unchanged' => true];
        $put = $this->http->githubPut($path, $content, $message, $old ? $old['sha'] : null);
        if ($readback) {
            $check = $this->http->githubGet($path, true);
            if (!$check || !hash_equals($check['content'], $content) || !hash_equals($expected, (string) $check['sha'])) throw new LiteMonFault('GITHUB_READBACK_MISMATCH');
        }
        return $put;
    }
}
// ChatGPT evidence, final decisions and durable recovery
final class LiteDecisionService {
    private $cfg, $http, $market, $writer;

    public function __construct(LiteConfig $cfg, $http, LiteMarketData $market, LiteVerifiedWriter $writer) {
        $this->cfg = $cfg;
        $this->http = $http;
        $this->market = $market;
        $this->writer = $writer;
    }

    public static function finalSaveStatus(): array {
        try {
            $r = LiteRuntime::read('result');
            $status = (string) lm_get($r, 'save_status', 'none');
            $verified = $status === 'saved' && lm_get($r, 'pair_readback_verified') === true && lm_get($r, 'receipt_readback_verified') === true;
            return [
                'status' => $status,
                'last_local_receipt_verified' => $verified,
                'scope' => 'last_local_receipt',
                'saved_at' => lm_get($r, 'saved_at'),
                'decision_id' => lm_get($r, 'decision_id'),
                'request_id' => lm_get($r, 'request_id'),
                'receipt_blob_sha' => lm_get($r, 'receipt_blob_sha')
            ];
        } catch (Throwable $e) {
            return ['status' => 'unknown', 'last_local_receipt_verified' => false, 'scope' => 'last_local_receipt'];
        }
    }

    public static function normalizeChatgptDecision(array $selected): array {
        $labels = [
            'M0' => '관찰',
            'M1' => '매입 검토',
            'M2' => '분할매입',
            'W' => '대기',
            'S1' => '매도 준비',
            'S2' => '분할매도',
            'S3' => '대부분 매도'
        ];
        $per = ['KR' => 0, 'US' => 0, 'JP' => 0];
        $out = [];
        $seen = [];
        foreach ($selected as $x) {
            if (!is_array($x)) throw new LiteMonFault('DECISION_ITEM_INVALID');
            $sid = trim((string)($x['symbol_id'] ?? ''));
            $sym = LiteMarketData::symbolFromId($sid);
            if (!$sym) throw new LiteMonFault('DECISION_SYMBOL_INVALID');
            if (isset ($seen[$sid])) throw new LiteMonFault('DECISION_DUPLICATE_SYMBOL');
            $seen[$sid] = true;
            $country = $sym['country'];
            if (isset ($x['country']) && $x['country'] !== $country) throw new LiteMonFault('DECISION_COUNTRY_MISMATCH');
            $per[$country]++;
            if ($per[$country] > 3) throw new LiteMonFault('DECISION_LIMIT_INVALID');
            $name = trim((string)($x['name'] ?? ''));
            if ($name === '' || strcasecmp($name, (string) $sym['symbol']) === 0) throw new LiteMonFault('DECISION_NAME_REQUIRED');
            $reason = trim((string)($x['reason'] ?? ''));
            $risk = trim((string)($x['risk_note'] ?? ''));
            if (strlen($reason) < 8 || strlen($risk) < 4) throw new LiteMonFault('DECISION_ANALYSIS_TOO_THIN');
            $ms = $x['ms7'] ?? null;
            if (!is_array($ms)) throw new LiteMonFault('DECISION_MS7_REQUIRED');
            $code = (string)($ms['code'] ?? '');
            if (!isset ($labels[$code])) throw new LiteMonFault('DECISION_MS7_INVALID');
            $y = $x;
            $y['symbol_id'] = $sid;
            $y['country'] = $country;
            $y['name'] = $name;
            $y['reason'] = $reason;
            $y['risk_note'] = $risk;
            $y['ms7'] = [
                'code' => $code,
                'label' => $labels[$code],
                'confidence' => 'CHATGPT_FINAL',
                'reason_codes' => (array)($ms['reason_codes'] ?? [])
            ];
            $y['authority'] = 'chatgpt_final';
            $out[] = $y;
        }
        return $out;
    }

    public function mailboxCheck(): void {
        $error = null;
        try {
            $this->processMailbox();
        } catch (Throwable $e) {
            $error = $e instanceof LiteMonFault ? $e->faultCode : 'MAILBOX_FAILED';
            if ($this->decisionPending()) $this->recordDecisionError($e);
            try {
                LiteRuntime::log('mailbox_error', ['code' => $error]);
            } catch (Throwable $ignore) {
            }
        }
        $s = LiteStateStore::state();
        $s->last_mailbox_check = lm_iso();
        $s->next_mailbox_check = lm_iso(time() + LitePolicy::MAILBOX_SECONDS);
        $s->last_mailbox_error = $error;
        $s->heartbeat_at = lm_iso();
        LiteStateStore::saveState($s);
    }

    public function decisionPending($result = null): bool {
        $r = $result ?? LiteRuntime::read('result');
        return in_array(lm_get($r, 'save_status'), ['pending_remote', 'pending_receipt'], true) || (
            lm_get($r, 'save_status') === 'saved' && lm_get($r, 'authority') === 'chatgpt_final' && lm_get($r, 'receipt_readback_verified') !== true
        );
    }

    public function recordDecisionError(Throwable $e): void {
        $code = $e instanceof LiteMonFault ? $e->faultCode : 'FINAL_DECISION_RETRY_FAILED';
        if ($code === 'CONCURRENT_DECISION_CONFLICT' && $this->supersedeVerifiedDecision()) return;
        $r = LiteRuntime::read('result');
        if ($this->decisionPending($r)) {
            $r->last_error = $code;
            LiteRuntime::write('result', $r);
        }
        $s = LiteStateStore::state();
        $s->publication_pending = true;
        $s->last_publication_error = $code;
        $s->phase = 'SAVE_PENDING';
        $s->next_mailbox_check = lm_iso(time() + LitePolicy::MAILBOX_SECONDS);
        LiteStateStore::saveState($s);
    }

    public function processMailbox(): void {
        if ($this->decisionPending()) {
            $this->resumeDecision();
            return;
        }
        if ($this->cfg->githubToken === '') return;
        $m = $this->http->githubGet('mon_chatgpt_handoff.json');
        if (!$m || trim((string) $m['content']) === '') return;
        $q = lm_array(lm_decode($m['content']));
        if (lm_get($q, 'contract') !== 'MON-LITE-BRIDGE-1.0') return;
        $stage = (string) lm_get($q, 'stage');
        if ($stage === 'idle') return;
        $rid = (string) lm_get($q, 'request_id');
        if ($rid === '' || !in_array($stage, ['candidates', 'decision'], true)) return;
        $req = LiteRuntime::read('request');
        if ($stage === 'decision' && (!is_string(lm_get($req, 'request_id')) || lm_get($req, 'request_id') === '')) {
            throw new LiteMonFault('DECISION_LOCAL_PROOF_MISSING');
        }
        if (lm_get($q, 'policy') !== MON_LITE_POLICY) throw new LiteMonFault('BRIDGE_POLICY_MISMATCH');
        if ($stage === 'candidates') {
            $c = $this->market->cacheObj();
            $rawIds = lm_get($q, 'candidate_ids', []);
            if (!is_array($rawIds)) return;
            $ids = [];
            $rejected = [];
            $seen = [];
            foreach ($rawIds as $raw) {
                if (!is_string($raw)) {
                    $rejected[] = '<non-string>';
                    continue;
                }
                $sid = trim($raw);
                if ($sid === '' || isset ($seen[$sid])) continue;
                $seen[$sid] = true;
                if (!LiteMarketData::symbolFromId($sid)) {
                    $rejected[] = $sid;
                    continue;
                }
                $ids[] = $sid;
            }
            $newRequest = lm_get($req, 'candidate_request_sha') !== $m['sha'];
            $changed = false;
            if ($newRequest) {
                $nextWatch = [];
                $nextSymbols = new stdClass();
                foreach ($ids as $sid) {
                    $row = lm_get($c->symbols, $sid);
                    if (!$row) {
                        $sym = LiteMarketData::symbolFromId($sid);
                        $row = (object) [
                            'status' => 'pending',
                            'symbol' => (object) $sym,
                            'last_error' => 'CANDIDATE_NOT_COLLECTED',
                            'checked_at' => lm_iso()
                        ];
                    }
                    $row->status = 'pending';
                    $row->last_error = 'CANDIDATE_REFRESH_REQUIRED';
                    $row->candidate_request_sha = $m['sha'];
                    $row->checked_at = lm_iso();
                    $nextWatch[] = $row->symbol;
                    $nextSymbols->{
                        $sid
                    }
                    = $row;
                }
                $c->watchlist = $nextWatch;
                $c->symbols = $nextSymbols;
                $c->scan_queue = [];
                $changed = true;
            }
            if ($changed) {
                $c->updated_at = lm_iso();
                LiteRuntime::write('cache', $c);
            }
            if ($newRequest && $ids) {
                $wake = LiteStateStore::state();
                $wake->next_light_check = lm_iso(time() + 30);
                $wake->heartbeat_at = lm_iso();
                LiteStateStore::saveState($wake);
            }
            $rows = [];
            $usable = [];
            $unresolved = [];
            $detailQueue = array_fill_keys((array) lm_get($c, 'scan_queue', []), true);
            foreach ($ids as $sid) {
                $r = lm_get($c->symbols, $sid);
                $a = $r ? lm_get($r, 'analysis'): null;
                if (!isset ($detailQueue[$sid]) && $this->market->rowCurrent($c, LiteMarketData::symbolFromId($sid), $r)) {
                    $a = $this->market->guardCurrentAnalysis($c, $sid, $r);
                    $rows[] = $a;
                    $usable[] = $sid;
                } else {
                    $rows[] = ['symbol_id' => $sid, 'status' => 'pending', 'error' => $r ? lm_get($r, 'last_error'): null];
                    $unresolved[] = $sid;
                }
            }
            foreach ($rejected as $sid) $rows[] = ['symbol_id' => $sid, 'status' => 'rejected', 'error' => 'INVALID_SYMBOL_ID'];
            foreach ($rows as & $rr) if (is_array($rr) && isset ($rr['metrics']) && (!isset ($rr['ms7']) || !is_array($rr['ms7']))) $rr['ms7'] = LiteSwing::ms7FromAnalysis($rr);
            unset ($rr);
            $referenceLists = LiteMarketData::referenceLists($rows, $ids);
            $analysisReference = $referenceLists['buy_candidates'];
            $status = 'ready_for_chatgpt';
            $evaluationId = lm_hash(
                [
                    $rid,
                    $m['sha'],
                    $usable,
                    $rejected,
                    $analysisReference,
                    array_map(function ($r) {
                        return lm_hash($r);
                    }, $rows)
                ]
            );
            if (lm_get($req, 'candidate_request_sha') === $m['sha'] && lm_get($req, 'evaluation_id') === $evaluationId
                && is_string(lm_get($req, 'details_blob_sha'))) return;
            $response = [
                'contract' => 'MON-LITE-BRIDGE-1.0',
                'policy' => MON_LITE_POLICY,
                'stage' => 'details',
                'request_id' => $rid,
                'candidate_request_sha' => $m['sha'],
                'status' => $status,
                'evaluation_id' => $evaluationId,
                'evaluated_ids' => $usable,
                'unresolved_ids' => $unresolved,
                'rejected_ids' => $rejected,
                'analysis_reference' => $analysisReference,
                'reference_lists' => $referenceLists,
                'rows' => $rows,
                'as_of' => lm_iso()
            ];
            $put = $this->writer->putVerified('mon_chatgpt_response.json', lm_json($response), 'MON Lite: evidence package for ChatGPT', false);
            LiteRuntime::write(
                'request',
                (object) [
                    'request_id' => $rid,
                    'stage' => 'details',
                    'candidate_request_sha' => $m['sha'],
                    'details_blob_sha' => $put['blob_sha'],
                    'evaluation_id' => $evaluationId,
                    'evaluated_ids' => $usable,
                    'unresolved_ids' => $unresolved,
                    'rejected_ids' => $rejected,
                    'analysis_reference' => $analysisReference,
                    'updated_at' => lm_iso()
                ]
            );
            return;
        }
        if (lm_get($q, 'authority') !== 'chatgpt_final' || lm_get($q, 'independent_analysis') !== true) throw new LiteMonFault('DECISION_AUTHORITY_REQUIRED');
        $saved = LiteRuntime::read('result');
        if (lm_get($saved, 'handoff_sha') === $m['sha'] && lm_get($saved, 'request_id') === $rid && in_array(lm_get($saved, 'save_status'), ['saved', 'superseded'], true)) return;
        $views = lm_get($q, 'market_view', []);
        if (!is_array($views)) throw new LiteMonFault('DECISION_MARKET_VIEW_REQUIRED');
        foreach (['KR', 'US', 'JP'] as $cc) {
            $v = trim((string)($views[$cc] ?? ''));
            if (strlen($v) < 8) throw new LiteMonFault('DECISION_MARKET_VIEW_TOO_THIN');
            $views[$cc] = $v;
        }
        $outcome = (string) lm_get($q, 'outcome', 'completed');
        if (!in_array($outcome, ['completed', 'limited_evidence', 'no_candidates'], true)) throw new LiteMonFault('DECISION_OUTCOME_INVALID');
        $conclusion = trim((string) lm_get($q, 'conclusion', ''));
        if (strlen($conclusion) < 8) throw new LiteMonFault('DECISION_CONCLUSION_REQUIRED');
        $analysisAt = (string) lm_get($q, 'analysis_at', '');
        $at = strtotime($analysisAt);
        if ($at === false || $at > time() + 300 || time() - $at > 86400) throw new LiteMonFault('DECISION_FRESH_ANALYSIS_REQUIRED');
        $selected = lm_get($q, 'selected', []);
        if (!is_array($selected)) throw new LiteMonFault('DECISION_ITEMS_REQUIRED');
        if (!$selected && $outcome === 'completed') throw new LiteMonFault('DECISION_EMPTY_OUTCOME_REQUIRED');
        $sources = lm_get($q, 'independent_sources', []);
        if (!is_array($sources) || ($selected && count($sources) < 2)) throw new LiteMonFault('DECISION_INDEPENDENT_SOURCES_REQUIRED');
        $sourceUrls = [];
        foreach ($sources as $source) {
            $url = is_array($source) ? trim((string)($source['url'] ?? '')): '';
            $checked = is_array($source) ? strtotime((string)($source['checked_at'] ?? '')): false;
            $parts = parse_url($url);
            if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https', 'http'], true)
                || empty ($parts['host']) || $checked === false || $checked > time() + 300 || time() - $checked > 86400) throw new LiteMonFault('DECISION_SOURCE_EVIDENCE_REQUIRED');
            $sourceUrls[$url] = true;
        }
        if ($selected && count($sourceUrls) < 2) throw new LiteMonFault('DECISION_INDEPENDENT_SOURCES_REQUIRED');
        if (lm_get($req, 'request_id') !== $rid || lm_get($q, 'candidate_request_sha') !== lm_get($req, 'candidate_request_sha')
            || lm_get($q, 'details_receipt_sha') !== lm_get($req, 'details_blob_sha') || lm_get($q, 'evaluation_id') !== lm_get($req, 'evaluation_id')) throw new LiteMonFault('DECISION_PROOF_MISMATCH');
        $this->saveDecision(
            $rid,
            $selected,
            (array) lm_get($req, 'unresolved_ids', []),
            (string) $req->evaluation_id,
            $views,
            $m['sha'],
            [
                'outcome' => $outcome,
                'conclusion' => $conclusion,
                'analysis_at' => $analysisAt,
                'independent_sources' => $sources
            ]
        );
    }

    public function saveDecision(
        string $rid,
        array $selected,
        array $unresolved,
        string $evaluationId,
        array $marketView,
        string $handoffSha,
        array $context = []
    ): void {
        $old = LiteRuntime::read('result');
        if ($this->decisionPending($old)) {
            if (lm_get($old, 'request_id') !== $rid || lm_get($old, 'handoff_sha') !== $handoffSha) throw new LiteMonFault('PENDING_DECISION_MUST_FINISH');
            if (lm_get($old, 'outbox')) {
                $this->resumeDecision();
                return;
            }
        }
        $now = is_string($context['saved_at'] ?? null) ? $context['saved_at'] : lm_iso();
        $selected = self::normalizeChatgptDecision(lm_array($selected));
        $grouped = ['KR' => [], 'US' => [], 'JP' => []];
        foreach ($selected as $x) $grouped[$x['country']][] = $x;
        $decisionLists = ['buy_candidates' => ['KR' => [], 'US' => [], 'JP' => []],
            'observations' => ['KR' => [], 'US' => [], 'JP' => []], 'risk_warnings' => ['KR' => [], 'US' => [], 'JP' => []]];
        foreach ($selected as $x) {
            $code = $x['ms7']['code'];
            $bucket = in_array($code, ['M1', 'M2'], true) ? 'buy_candidates' : (in_array($code, ['S1', 'S2', 'S3'], true) ? 'risk_warnings' : 'observations');
            $decisionLists[$bucket][$x['country']][] = $x;
        }
        $result = [
            'contract' => 'MON-LITE-RESULT-2.1',
            'policy' => MON_LITE_POLICY,
            'authority' => 'chatgpt_final',
            'independent_analysis' => true,
            'request_id' => $rid,
            'evaluation_id' => $evaluationId,
            'handoff_sha' => $handoffSha,
            'outcome' => $context['outcome'] ?? ($selected ? 'completed' : 'limited_evidence'),
            'conclusion' => $context['conclusion'] ?? 'ChatGPT independent final decision.',
            'analysis_at' => array_key_exists('analysis_at', $context) ? $context['analysis_at'] : $now,
            'analysis_status' => $context['analysis_status'] ?? 'current_independent_analysis',
            'received_at' => $context['received_at'] ?? $now,
            'independent_sources' => $context['independent_sources'] ?? [],
            'market_view' => $marketView,
            'selected' => $selected,
            'recommendations' => $grouped,
            'decision_lists' => $decisionLists,
            'unresolved_ids' => $unresolved,
            'saved_at' => $now,
            'save_status' => 'pending_remote',
            'pair_readback_verified' => false,
            'receipt_readback_verified' => false
        ];
        $result['decision_id'] = lm_hash($result);
        $lines = [
            "MON 2.2.3 Lite · ChatGPT 최종 결정 · " . ($result['analysis_at'] ?? '원 분석시각 미확인'),
            "",
            '- 최종 추천 권한: ChatGPT 독립 분석',
            '- MON/PHP 분석은 보조 참고자료로만 사용',
            '- 결정 ID: ' . $result['decision_id'],
            '- 요청 ID: ' . $rid,
            '- 평가 ID: ' . $evaluationId,
            '- 분석 시각: ' . ($result['analysis_at'] ?? '미확인'),
            '- 결론: ' . $result['conclusion'],
            ""
        ];
        $heads = ['KR' => '한국', 'US' => '미국', 'JP' => '일본'];
        $sections = ['buy_candidates' => '매입 검토 · M1/M2', 'observations' => '관찰·대기 · M0/W', 'risk_warnings' => '위험 경계 · S1/S2/S3'];
        $lines[] = '- 관찰·대기와 위험 경계는 신규 매입 추천이 아니며, MS7은 자동 주문 지시가 아닙니다.';
        foreach (['KR', 'US', 'JP'] as $cc) {
            $lines[] = '## ' . $heads[$cc] . ' · ' . ($marketView[$cc] ?? '자료 미확인');
            foreach ($sections as $bucket => $title) {
                $lines[] = '### ' . $title;
                if (!$decisionLists[$bucket][$cc]) $lines[] = '- 해당 없음';
                foreach ($decisionLists[$bucket][$cc] as $x) {
                    $ms = $x['ms7'];
                    $price = array_key_exists('reference_price', $x) && $x['reference_price'] !== null ? ' · 참고가 ' . (string)$x['reference_price'] : '';
                    $lines[] = '- ' . $x['name'] . ' (' . $x['symbol_id'] . ') · MS7 ' . $ms['code'] . ' ' . $ms['label']
                        . $price . ' · ' . $x['reason'] . ' · 위험: ' . $x['risk_note'];
                }
            }
            $lines[] = '';
        }
        $lines[] = '- 판단 유형: ' . $result['outcome'];
        foreach ($result['independent_sources'] as $source) $lines[] = '- 확인 출처: ' . (string)($source['url'] ?? '')
            . ' · ' . (string)($source['checked_at'] ?? '');
        if ($unresolved) $lines[] = '- MON 미확인 자료 ' . count($unresolved) . '개는 보조자료 미확인으로 기록합니다.';
        $markdown = implode("\n", $lines) . "\n";
        $result['outbox'] = [
            'contract' => 'MON-LITE-OUTBOX-1.0',
            'markdown' => $markdown,
            'result_blob_sha' => lm_git_blob_sha($markdown),
            'data_json' => null,
            'response_json' => null,
            'result_commit_sha' => null,
            'data_commit_sha' => null
        ];
        // Never expose remote writes before the complete, normalized final decision is durable.
        LiteRuntime::write('result', (object) $result);
        try {
            $this->resumeDecision();
        } catch (Throwable $e) {
            $this->recordDecisionError($e);
            throw $e;
        }
    }

    private static function finalIdentity($f): ?string {
        if (!$f || lm_get($f, 'authority') !== 'chatgpt_final') return null;
        $id = (string) lm_get($f, 'decision_id', '');
        if ($id !== '') return 'decision:' . $id;
        return 'legacy:' . lm_hash(
            [
                lm_get($f, 'request_id'),
                lm_get($f, 'evaluation_id'),
                lm_get($f, 'handoff_sha'),
                lm_get($f, 'analysis_at'),
                lm_get($f, 'saved_at')
            ]
        );
    }

    private function decisionRemote(array $r, array $o): ?array {
        $remote = $this->http->githubGet('mon_data.json', true);
        if (!$remote || trim((string) $remote['content']) === '') {
            if (array_key_exists('base_final_id', $o) && $o['base_final_id'] !== null) throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');
            return $remote;
        }
        $d = lm_decode($remote['content']);
        $f = lm_get($d, 'chatgpt_final', lm_get($d, 'previous_chatgpt_final'));
        $current = self::finalIdentity($f);
        $own = 'decision:' . $r['decision_id'];
        if ($current !== null && $current !== $own) {
            $remoteAt = strtotime((string) lm_get($f, 'analysis_at', lm_get($f, 'saved_at', '')));
            $localAt = strtotime((string)($r['analysis_at'] ?? $r['received_at'] ?? $r['saved_at']));
            if ($remoteAt !== false && $localAt !== false && $remoteAt > $localAt) throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');
            if (array_key_exists('base_final_id', $o) && $current !== $o['base_final_id']) throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');
        }
        if (array_key_exists('base_final_id', $o) && $current === null && $o['base_final_id'] !== null) throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');
        return $remote;
    }

    private function supersedeVerifiedDecision(): bool {
        // Retire an older outbox only after independently reading the complete newer final and its receipt.
        try {
            $r = lm_array(LiteRuntime::read('result'));
            if (!is_array($r)) return false;
            $data = $this->http->githubGet('mon_data.json', true);
            $receipt = $this->http->githubGet('mon_chatgpt_response.json', true);
            $markdown = $this->http->githubGet('mon_result.md', true);
            if (!$data || !$receipt || !$markdown) return false;
            $d = lm_decode($data['content']);
            $f = lm_get($d, 'chatgpt_final');
            $q = lm_decode($receipt['content']);
            $id = (string) lm_get($f, 'decision_id', '');
            $remoteAt = strtotime((string) lm_get($f, 'analysis_at', ''));
            $localAt = strtotime((string)($r['analysis_at'] ?? $r['received_at'] ?? $r['saved_at'] ?? ''));
            if (lm_get($d, 'contract') !== 'MON-LITE-DATA-2.2' || lm_get($d, 'policy') !== MON_LITE_POLICY || lm_get($f, 'policy') !== MON_LITE_POLICY
                || lm_get($f, 'independent_analysis') !== true || lm_get($q, 'contract') !== 'MON-LITE-BRIDGE-1.0'
                || lm_get($q, 'policy') !== MON_LITE_POLICY || lm_get($q, 'stage') !== 'decision' || $id === ''
                || $id === ($r['decision_id'] ?? '') || lm_get($f, 'authority') !== 'chatgpt_final' || $remoteAt === false
                || $localAt === false || $remoteAt <= $localAt || lm_get($q, 'status') !== 'saved' || lm_get($q, 'authority') !== 'chatgpt_final'
                || lm_get($q, 'decision_id') !== $id || lm_get($q, 'request_id') !== lm_get($f, 'request_id') || lm_get($q, 'evaluation_id') !== lm_get($f, 'evaluation_id')
                || lm_get($q, 'pair_readback_verified') !== true) return false;
            foreach ([
                [$data, lm_get($q, 'data_blob_sha')],
                [$markdown, lm_get($q, 'result_blob_sha')],
                [$receipt, $receipt['sha']]
            ] as $item) if (!is_string($item[1]) || !hash_equals((string) $item[0]['sha'], $item[1]) || !hash_equals(lm_git_blob_sha($item[0]['content']), (string) $item[0]['sha'])) return false;
            foreach (['mon_data.json' => $data, 'mon_result.md' => $markdown, 'mon_chatgpt_response.json' => $receipt] as $path => $expected) {
                $check = $this->http->githubGet($path, true);
                if (!$check || !hash_equals((string) $check['sha'], (string) $expected['sha']) || !hash_equals($check['content'], $expected['content'])) return false;
            }
            $r['save_status'] = 'superseded';
            $r['last_error'] = null;
            $r['superseded_by'] = [
                'decision_id' => $id,
                'request_id' => lm_get($f, 'request_id'),
                'analysis_at' => lm_get($f, 'analysis_at'),
                'result_blob_sha' => $markdown['sha'],
                'data_blob_sha' => $data['sha'],
                'receipt_blob_sha' => $receipt['sha'],
                'verified_at' => lm_iso()
            ];
            LiteRuntime::write('result', (object) $r);
            $s = LiteStateStore::state();
            $s->publication_pending = false;
            $s->last_publication_error = null;
            $s->last_error = null;
            $s->phase = 'WAIT';
            LiteStateStore::saveState($s);
            return true;
        } catch (Throwable $ignore) {
            return false;
        }
    }

    private function decisionPath(string $path, string $ownSha, string $baseSha): string {
        $v = $this->http->githubGet($path, true);
        $current = $v ? (string) $v['sha'] : '';
        if ($current !== $ownSha && $current !== $baseSha) throw new LiteMonFault('CONCURRENT_DECISION_CONFLICT');
        return $current;
    }

    private function verifyDecisionPair(array $o): void {
        foreach ([
            'mon_result.md' => [$o['markdown'], $o['result_blob_sha']],
            'mon_data.json' => [$o['data_json'], $o['data_blob_sha']]
        ] as $path => $expected) {
            $v = $this->http->githubGet($path, true);
            if (!$v || !hash_equals($expected[0], $v['content']) || !hash_equals($expected[1], (string) $v['sha'])) throw new LiteMonFault('DECISION_PAIR_READBACK_MISMATCH');
        }
    }

    public function resumeDecision(): void {
        $r = lm_array(LiteRuntime::read('result'));
        if (!is_array($r) || !$this->decisionPending($r)) return;
        if (!isset ($r['outbox']) || !is_array($r['outbox'])) {
            // Recover the r12 pending format once, preserving the original decision.
            $this->saveDecision(
                (string)($r['request_id'] ?? ''),
                lm_array($r['selected'] ?? []),
                lm_array($r['unresolved_ids'] ?? []),
                (string)($r['evaluation_id'] ?? ''),
                lm_array($r['market_view'] ?? []),
                (string)($r['handoff_sha'] ?? ''),
                [
                    'saved_at' => $r['saved_at'] ?? $r['retry_at'] ?? lm_iso(),
                    'analysis_at' => $r['analysis_at'] ?? null,
                    'analysis_status' => isset ($r['analysis_at']) ? 'current_independent_analysis' : 'legacy_source_metadata_unavailable',
                    'received_at' => $r['received_at'] ?? $r['saved_at'] ?? $r['retry_at'] ?? lm_iso(),
                    'independent_sources' => $r['independent_sources'] ?? [],
                    'outcome' => $r['outcome'] ?? (empty ($r['selected']) ? 'limited_evidence' : 'completed'),
                    'conclusion' => $r['conclusion'] ?? 'Previously accepted ChatGPT decision; original source metadata unavailable.'
                ]
            );
            return;
        }
        $o = $r['outbox'];
        if (($o['contract'] ?? '') !== 'MON-LITE-OUTBOX-1.0' || !is_string($o['markdown'] ?? null) || !hash_equals((string)($o['result_blob_sha']
            ?? ''), lm_git_blob_sha($o['markdown']))) throw new LiteMonFault('DECISION_OUTBOX_INVALID');
        if (!is_string($o['data_json'] ?? null)) {
            $remote = $this->decisionRemote($r, $o);
            $data = (object) [
                'contract' => 'MON-LITE-DATA-2.2',
                'watchlist' => (object) ['symbols' => []],
                'scan' => (object) ['state' => 'decision_only'],
                'results' => [],
                'errors' => []
            ];
            if ($remote && trim((string) $remote['content']) !== '') {
                $candidate = lm_decode($remote['content']);
                if (lm_get($candidate, 'contract') !== 'MON-LITE-DATA-2.2' || lm_get($candidate, 'policy') !== MON_LITE_POLICY) throw new LiteMonFault('DECISION_DATA_CONTRACT_MISMATCH');
                $data = $candidate;
            }
            $o['base_final_id'] = self::finalIdentity(lm_get($data, 'chatgpt_final', lm_get($data, 'previous_chatgpt_final')));
            $baseResult = $this->http->githubGet('mon_result.md', true);
            $baseResponse = $this->http->githubGet('mon_chatgpt_response.json', true);
            $o['base_result_blob_sha'] = $baseResult ? (string) $baseResult['sha'] : '';
            $o['base_response_blob_sha'] = $baseResponse ? (string) $baseResponse['sha'] : '';
            $final = $r;
            unset ($final['outbox']);
            $final['save_status'] = 'receipt_required';
            $final['result_blob_sha'] = $o['result_blob_sha'];
            $data->policy = MON_LITE_POLICY;
            $data->version = MON_LITE_VERSION;
            $data->build = MON_LITE_BUILD;
            $data->updated_at = $r['saved_at'];
            if (!is_object($data->scan ?? null)) $data->scan = new stdClass();
            $data->scan->decision_status = 'chatgpt_final';
            $data->recommendations = (object) $r['recommendations'];
            if (isset($r['decision_lists'])) $data->decision_lists = $r['decision_lists'];
            $data->chatgpt_final = (object) $final;
            unset ($data->previous_chatgpt_final);
            $o['data_json'] = lm_json($data);
            $o['data_blob_sha'] = lm_git_blob_sha($o['data_json']);
            $r['outbox'] = $o;
            LiteRuntime::write('result', (object) $r);
        }
        if (!hash_equals((string)($o['data_blob_sha'] ?? ''), lm_git_blob_sha($o['data_json']))) throw new LiteMonFault('DECISION_OUTBOX_INVALID');
        $this->decisionRemote($r, $o);
        $safeResult = $this->decisionPath('mon_result.md', $o['result_blob_sha'], (string)($o['base_result_blob_sha']
            ?? ''));
        $putR = $this->writer->putVerified('mon_result.md', $o['markdown'], 'MON Lite: ChatGPT authoritative final decision', true, $safeResult);
        if (!empty ($putR['commit_sha'])) $o['result_commit_sha'] = $putR['commit_sha'];
        $r['outbox'] = $o;
        LiteRuntime::write('result', (object) $r);
        $safeRemote = $this->decisionRemote($r, $o);
        $putD = $this->writer->putVerified(
            'mon_data.json',
            $o['data_json'],
            'MON Lite: ChatGPT final recommendation record',
            true,
            $safeRemote ? (string) $safeRemote['sha'] : ''
        );
        if (!empty ($putD['commit_sha'])) $o['data_commit_sha'] = $putD['commit_sha'];
        $r['outbox'] = $o;
        LiteRuntime::write('result', (object) $r);
        // Verify the pair together after both writes, including unchanged/read-after-timeout paths.
        $this->verifyDecisionPair($o);
        $r['pair_readback_verified'] = true;
        $r['save_status'] = 'pending_receipt';
        $r['result_blob_sha'] = $o['result_blob_sha'];
        $r['data_blob_sha'] = $o['data_blob_sha'];
        $r['result_commit_sha'] = $o['result_commit_sha'];
        $r['data_commit_sha'] = $o['data_commit_sha'];
        if (!is_string($o['response_json'] ?? null)) {
            $response = [
                'contract' => 'MON-LITE-BRIDGE-1.0',
                'policy' => MON_LITE_POLICY,
                'stage' => 'decision',
                'authority' => 'chatgpt_final',
                'request_id' => $r['request_id'],
                'status' => 'saved',
                'decision_id' => $r['decision_id'],
                'evaluation_id' => $r['evaluation_id'],
                'result_blob_sha' => $o['result_blob_sha'],
                'data_blob_sha' => $o['data_blob_sha'],
                'result_commit_sha' => $o['result_commit_sha'],
                'data_commit_sha' => $o['data_commit_sha'],
                'pair_readback_verified' => true,
                'selected_count' => count($r['selected']),
                'unresolved_count' => count($r['unresolved_ids']),
                'outcome' => $r['outcome'],
                'saved_at' => $r['saved_at']
            ];
            $o['response_json'] = lm_json($response);
            $o['response_blob_sha'] = lm_git_blob_sha($o['response_json']);
        }
        if (!hash_equals((string)($o['response_blob_sha'] ?? ''), lm_git_blob_sha($o['response_json']))) throw new LiteMonFault('DECISION_OUTBOX_INVALID');
        $r['outbox'] = $o;
        LiteRuntime::write('result', (object) $r);
        $safeResponse = $this->decisionPath('mon_chatgpt_response.json', $o['response_blob_sha'], (string)($o['base_response_blob_sha']
            ?? ''));
        $receipt = $this->writer->putVerified(
            'mon_chatgpt_response.json',
            $o['response_json'],
            'MON Lite: ChatGPT final decision saved receipt',
            true,
            $safeResponse
        );
        $this->verifyDecisionPair($o);
        $r['receipt_blob_sha'] = $receipt['blob_sha'];
        $r['receipt_readback_verified'] = true;
        $r['save_status'] = 'saved';
        $r['last_error'] = null;
        LiteRuntime::write('result', (object) $r);
        $s = LiteStateStore::state();
        $s->publication_pending = false;
        $s->last_publication_error = null;
        $s->last_publish_fingerprint = $s->publication_fingerprint;
        LiteStateStore::saveState($s);
    }
}
// Reference publication with final-decision protection
final class LiteReferencePublisher {
    private $cfg, $http, $decisions, $writer;

    public function __construct(LiteConfig $cfg, $http, LiteDecisionService $decisions, LiteVerifiedWriter $writer) {
        $this->cfg = $cfg;
        $this->http = $http;
        $this->decisions = $decisions;
        $this->writer = $writer;
    }

    public function publishData(array $summary, array $watch): bool {
        if ($this->cfg->githubToken === '') return false;
        if ($this->decisions->decisionPending()) throw new LiteMonFault('FINAL_DECISION_PUBLICATION_PENDING');
        $ids = array_map(function ($x) {
            return $x['symbol_id'];
        }, $watch);
        $referenceLists = $summary['reference_lists'] ?? LiteMarketData::referenceLists($summary['results'] ?? [], $ids);
        $ref = $referenceLists['buy_candidates'];
        $data = [
            'contract' => 'MON-LITE-DATA-2.2',
            'policy' => MON_LITE_POLICY,
            'version' => MON_LITE_VERSION,
            'build' => MON_LITE_BUILD,
            'updated_at' => lm_iso(),
            'watchlist' => ['symbols' => $watch],
            'scan' => [
                'scan_id' => $summary['scan_id'],
                'at' => $summary['at'],
                'done' => $summary['done'],
                'pending' => $summary['pending'],
                'candidate_count' => $summary['candidate_count'],
                'eligible_count' => $summary['eligible_count'],
                'mode' => $summary['mode'] ?? null,
                'coverage' => $summary['coverage'] ?? null,
                'network_stats' => $summary['network_stats'] ?? null,
                'decision_status' => 'awaiting_chatgpt'
            ],
            'analysis_reference' => $ref,
            'reference_lists' => $referenceLists,
            'results' => $summary['results'],
            'errors' => $summary['errors']
        ];
        // Preserve the last confirmed final decision across subsequent reference scans.
        // A scan must never silently downgrade a saved decision to awaiting_chatgpt.
        $prior = $this->http->githubGet('mon_data.json', true);
        if (!$prior || trim((string) $prior['content']) === '') throw new LiteMonFault('PRIOR_FINAL_STATUS_UNVERIFIED');
        if ($prior && trim((string) $prior['content']) !== '') {
            try {
                $previous = lm_decode($prior['content']);
                $final = lm_get($previous, 'chatgpt_final', lm_get($previous, 'previous_chatgpt_final'));
                if ($final && lm_get($final, 'authority') === 'chatgpt_final') {
                    $finalAt = strtotime((string) lm_get($final, 'analysis_at', ''));
                    $scanAt = strtotime((string)($summary['at'] ?? ''));
                    if ($finalAt === false || $scanAt === false || $finalAt >= $scanAt) {
                        $st = LiteStateStore::state();
                        $st->reference_publication_status = 'superseded_by_final';
                        LiteStateStore::saveState($st);
                        return true;
                    }
                }
                if ($final && lm_get($final, 'authority') === 'chatgpt_final') {
                    // Archive only: historical picks are never active recommendations for a new scan.
                    $data['previous_chatgpt_final'] = lm_array($final);
                    $data['recommendations'] = ['KR' => [], 'US' => [], 'JP' => []];
                    $data['scan']['decision_status'] = 'independent_chatgpt_required';
                    $data['scan']['decision_note'] = 'New independent ChatGPT analysis required; archived final is historical only.';
                }
            } catch (Throwable $ignored) {
                throw new LiteMonFault('PRIOR_FINAL_STATUS_UNVERIFIED');
            }
        }
        $lines = [
            "MON 2.2.3 Lite · 보조 참고분석 · " . $summary['at'],
            "",
            '- 아래 목록은 PHP/MON 참고분석이며 최종 추천이 아닙니다. 나라별 매입 검토는 최대 3개이고 0개도 정상입니다.',
            '- 관찰·대기와 위험 경계는 매입 후보와 분리하며 자동 주문을 의미하지 않습니다.',
            '- 최종 결정 권한: ChatGPT 독립 분석',
            '- 평가 ' . $summary['done'] . ' / ' . $summary['candidate_count'] . ' · 미확인 ' . $summary['pending'] . ' · 정밀 적격 '
                . $summary['eligible_count']
        ];
        $heads = ['KR' => '한국', 'US' => '미국', 'JP' => '일본'];
        $sectionHeads = ['buy_candidates' => '매입 검토 참고', 'observations' => '관찰·대기', 'risk_warnings' => '위험 경계', 'unresolved' => '자료 미확인'];
        foreach (['KR', 'US', 'JP'] as $country) {
            $lines[] = '';
            $lines[] = '## ' . $heads[$country] . ' 참고';
            foreach ($sectionHeads as $bucket => $title) {
                $lines[] = '### ' . $title;
                if (!$referenceLists[$bucket][$country]) $lines[] = '- 해당 없음';
                foreach ($referenceLists[$bucket][$country] as $r) {
                    $ms = $r['ms7'] ?? ['code' => 'W', 'label' => '대기', 'confidence' => 'PROVISIONAL'];
                    $price = $r['last_close'] === null ? '가격 미확인' : (string)$r['last_close'];
                    $lines[] = '- ' . ($r['name'] ?? '') . ' (' . ($r['symbol_id'] ?? '') . ') · 참고 MS7 '
                        . ($ms['code'] ?? 'W') . ' ' . ($ms['label'] ?? '대기') . ' · ' . $price
                        . ' · 상태 ' . LiteMarketData::referenceStateLabel($r);
                    $setup = $r['reference_setup'] ?? null;
                    if (is_array($setup) && ($setup['status'] ?? '') === 'invalidated') $lines[] = '  - 이전 가설 실패: '
                        . (string)($setup['invalidated_at'] ?? '') . ' · 고정 지지 ' . (string)($setup['invalidated_support'] ?? '');
                    elseif (is_array($setup) && ($setup['status'] ?? '') === 'unverified') $lines[] = '  - 이전 가설 검증 미완료: 최초 확인 '
                        . (string)($setup['first_guard_unverified_at'] ?? '') . ' · 실제 실패 시점 미확인';
                    elseif (is_array($setup) && ($setup['previous_guard_unverified'] ?? false)) $lines[] = '  - 이전 가설의 실제 실패 시점은 미확인 · 새 신호 '
                        . (string)($setup['signal_date'] ?? '') . '로 독립 재평가';
                    elseif (is_array($setup) && isset($setup['rearmed_after'])) $lines[] = '  - 이전 가설 실패: '
                        . (string)$setup['rearmed_after'] . ' · 당시 지지 ' . (string)($setup['previous_invalidated_support'] ?? '')
                        . ' · 새 신호 ' . (string)($setup['signal_date'] ?? '') . '로 별도 재평가';
                }
            }
        }
        if (($data['scan']['decision_status'] ?? '') === 'independent_chatgpt_required') {
            $lines[] = '';
            $lines[] = '## 이전 ChatGPT 최종 결정 (새 분석 아님)';
            $lines[] = '- 새 스캔에서 확정 결정을 생성하지 않았으므로 마지막 저장 결정을 보존합니다. 현재 매입 신호로 간주하지 마세요.';
            foreach ((lm_array(lm_get($data['previous_chatgpt_final'], 'recommendations')) ?? []) as $cc => $items) foreach ($items as $item) {
                $lines[] = '- ' . ($item['name'] ?? '') . ' (' . ($item['symbol_id'] ?? '') . ') · ' . ($item['ms7']['code']
                    ?? 'W') . ' · ' . ($item['reason'] ?? '');
            }
        }
        // Keep a durable local snapshot before attempting either remote write.
        $markdown = implode("\n", $lines) . "\n";
        LiteRuntime::write(
            'evidence',
            (object) [
                'latest_scan' => $summary,
                'publication_snapshot' => (object) ['data' => $data, 'markdown' => $markdown, 'at' => lm_iso()]
            ]
        );
        $referenceBase = !isset ($data['previous_chatgpt_final']) ? $this->http->githubGet('mon_result.md', true): null;
        $this->writer->putVerified(
            'mon_data.json',
            lm_json($data),
            'MON Lite: analysis reference update',
            true,
            (string) $prior['sha']
        );
        // A reference scan never overwrites the last authoritative final document.
        if (!isset ($data['previous_chatgpt_final'])) $this->writer->putVerified(
            'mon_result.md',
            $markdown,
            'MON Lite: reference analysis',
            true,
            $referenceBase ? (string) $referenceBase['sha'] : ''
        );
        $st = LiteStateStore::state();
        $st->reference_publication_status = 'published_readback';
        LiteStateStore::saveState($st);
        return true;
    }
}
// Phased scans, bounded queues and checkpoints
/** One scan's mutable work. The engine never retains this record after return. */
final class LiteScanSession {
    public $retryOnly;
    public $state = null;
    public $resource = null;
    public $cpuMode = null;
    public $memoryBatch = null;
    public $lowMemory = null;
    public $cpuLimited = null;
    public $bounded = null;
    public $batch = null;
    public $scanSeq = null;
    public $watch = [];
    public $cache = null;
    public $forceFull = null;
    public $baseMode = null;
    public $mode = null;
    public $stats = [];
    public $queuedIds = [];
    public $targets = [];
    public $queuedTargets = null;
    public $loadedResource = null;
    public $needed = [];
    public $bench = [];
    public $benchMap = [];
    public $benchFingerprint = [];
    public $benchSource = [];
    public $benchErrors = [];
    public $blocked = [];
    public $processed = null;
    public $lastCheckpoint = null;
    public $checkpointEvery = null;
    public $stopResource = null;
    public $stopReason = null;
    public $remainingQueue = null;
    public $results = [];
    public $errors = [];
    public $done = null;
    public $pending = null;
    public $summary = null;
    public $candidateIds = [];
    public $analysisReference = [];
    public $coverage = [];
    public $shouldPublish = null;
    public $fingerprint = null;

    public function __construct(bool $retryOnly) {
        $this->retryOnly = $retryOnly;
    }
}
/** Coordinates the scan lifecycle without owning market cache or final-decision bytes. */
final class LiteScanEngine {
    private $yahoo, $market, $decisions, $references, $turn;

    public function __construct(
        $yahoo,
        LiteMarketData $market,
        LiteDecisionService $decisions,
        LiteReferencePublisher $references,
        LiteTurn $turn
    ) {
        $this->yahoo = $yahoo;
        $this->market = $market;
        $this->decisions = $decisions;
        $this->references = $references;
        $this->turn = $turn;
    }

    public function fullScan(bool $retryOnly = false): array {
        try {
            $work = new LiteScanSession($retryOnly);
            $early = $this->prepareScan($work);
            if ($early !== null) return $early;
            $early = $this->prepareTargets($work);
            if ($early !== null) return $early;
            $this->prepareBenchmarks($work);
            $this->scanRows($work);
            $this->collectResults($work);
            return $this->publishSummary($work);
        } catch (Throwable $error) {
            try {
                $this->turn->end();
            } catch (Throwable $cleanupError) {
            }
            throw $error;
        }
    }

    private function prepareScan(LiteScanSession $work): ?array {
        $this->market->beginTurn();
        $this->turn->end();
        $this->turn->begin();
        if ($this->decisions->decisionPending()) $this->decisions->resumeDecision();
        $work->state = LiteStateStore::state();
        if (!is_string($work->state->last_full_scan)) {
            $this->decisions->mailboxCheck();
            $work->state = LiteStateStore::state();
        }
        $work->resource = LiteResource::sample();
        $work->cpuMode = LiteResource::cpuMode($work->resource);
        $work->memoryBatch = LiteResource::lowMemoryBatch($work->resource);
        $work->lowMemory = ($work->resource['memory_mode'] ?? 'normal') === 'low' || (!$work->resource['heavy_allowed']
            && ($work->resource['reason'] ?? null) === 'memory');
        $work->cpuLimited = $work->cpuMode === 'limited';
        $work->bounded = $work->lowMemory || $work->cpuLimited;
        $work->batch = $work->bounded ? min($work->memoryBatch, $work->cpuLimited ? 1 : 3): PHP_INT_MAX;
        if ($work->cpuMode === 'defer' || $work->memoryBatch === 0) {
            $reason = $work->cpuMode === 'defer' ? (string)($work->resource['cpu_reason'] ?? 'CPU_PRESSURE'): 'MEMORY_HARD_FLOOR';
            LiteStateStore::resourcePause($work->state, $work->resource, $reason);
            $this->turn->end();
            return ['deferred' => true, 'resource' => $work->resource, 'reason' => $reason];
        }
        $work->scanSeq = (int) lm_get($work->state, 'scan_request_seq', 0);
        $work->state->resource_wait = null;
        LiteStateStore::heartbeat($work->state, 'SCAN');
        $work->watch = $this->market->loadRemoteWatchlist($work->retryOnly);
        $work->cache = $this->market->cacheObj();
        $work->cache->watchlist = array_map(static function ($x) {
            return (object) $x;
        }, $work->watch);
        $work->forceFull = (!$work->retryOnly && $this->market->resyncDue($work->cache)) || lm_get($work->cache, 'full_resync_pending') === true;
        if ($work->forceFull) $work->cache->full_resync_pending = true;
        $work->baseMode = $work->forceFull ? 'full_resync' : ($work->retryOnly ? 'retry_incremental' : 'incremental');
        $work->mode = ($work->cpuLimited ? 'cpu_limited_' : ($work->lowMemory ? 'low_memory_' : '')) . $work->baseMode;
        $work->stats = [
            'mode' => $work->mode,
            'low_memory_active' => $work->lowMemory,
            'low_memory_batch_size' => $work->lowMemory ? $work->batch : 0,
            'cpu_limited_active' => $work->cpuLimited,
            'cpu_early_stop' => false,
            'resource_before' => $work->resource,
            'memory_early_stop' => false,
            'full_downloads' => 0,
            'incremental_downloads' => 0,
            'fallback_full_downloads' => 0,
            'analysis_reused' => 0,
            'analysis_recomputed' => 0,
            'quality_reused' => 0,
            'benchmark_downloads' => 0,
            'benchmark_cache_hits' => 0,
            'benchmark_blocked_symbols' => 0,
            'benchmark_sources' => [],
            'benchmark_details' => [],
            'final_rechecks' => 0,
            'final_recheck_cache_hits' => 0
        ];
        return null;
    }

    private function prepareTargets(LiteScanSession $work): ?array {
        $watchIds = [];
        foreach ($work->watch as $sym) $watchIds[$sym['symbol_id']] = true;
        $work->queuedIds = [];
        foreach ((array) lm_get($work->cache, 'scan_queue', []) as $sid) if (is_string($sid) && isset ($watchIds[$sid])) $work->queuedIds[$sid] = true;
        $cleanQueue = array_keys($work->queuedIds);
        if ((array) lm_get($work->cache, 'scan_queue', []) !== $cleanQueue) {
            $work->cache->scan_queue = $cleanQueue;
            LiteRuntime::write('cache', $work->cache);
        }
        unset ($watchIds, $cleanQueue);
        $work->targets = [];
        foreach ($work->watch as $sym) {
            $old = lm_get($work->cache->symbols, $sym['symbol_id']);
            if (($work->retryOnly && $work->queuedIds) ? isset ($work->queuedIds[$sym['symbol_id']]): (!$work->retryOnly
                || !$this->market->rowCurrent($work->cache, $sym, $old))) $work->targets[] = $sym;
        }
        unset ($old);
        if (!$work->targets) {
            $work->stats = $this->turn->networkStats($work->stats);
            $count = count($work->watch);
            unset ($work->cache, $work->targets, $work->queuedIds);
            $this->lightCheck();
            return [
                'at' => lm_iso(),
                'done' => $count,
                'pending' => 0,
                'candidate_count' => $count,
                'eligible_count' => 0,
                'results' => [],
                'errors' => [],
                'network_stats' => $work->stats
            ];
        }
        if ($work->bounded) usort(
            $work->targets,
            static function ($a, $b) use($work) {
                $ta = strtotime((string) lm_get(lm_get($work->cache->symbols, $a['symbol_id']), 'checked_at', '')) ? : 0;
                $tb = strtotime((string) lm_get(lm_get($work->cache->symbols, $b['symbol_id']), 'checked_at', '')) ? : 0;
                return $ta <=> $tb ? : strcmp($a['symbol_id'], $b['symbol_id']);
            }
        );
        $work->queuedTargets = count($work->targets);
        $work->cache->scan_queue = array_map(static function ($x) {
            return $x['symbol_id'];
        }, $work->targets);
        $work->queuedIds = array_fill_keys($work->cache->scan_queue, true);
        $work->loadedResource = LiteResource::sample();
        if (($work->loadedResource['memory_mode'] ?? 'normal') === 'low') {
            $work->lowMemory = true;
            $work->bounded = true;
            $work->batch = min($work->batch, LiteResource::lowMemoryBatch($work->loadedResource));
        }
        if (LiteResource::cpuMode($work->loadedResource) === 'limited') {
            $work->cpuLimited = true;
            $work->bounded = true;
            $work->batch = min($work->batch, 1);
        }
        if (LiteResource::cpuMode($work->loadedResource) === 'defer') {
            $work->batch = 0;
            $work->bounded = true;
        }
        $work->stats['low_memory_active'] = $work->lowMemory;
        $work->stats['low_memory_batch_size'] = $work->lowMemory ? $work->batch : 0;
        $work->stats['cpu_limited_active'] = $work->cpuLimited;
        $work->stats['mode'] = ($work->cpuLimited ? 'cpu_limited_' : ($work->lowMemory ? 'low_memory_' : '')) . $work->baseMode;
        if ($work->bounded) $work->targets = array_slice($work->targets, 0, $work->batch);
        return null;
    }

    private function prepareBenchmarks(LiteScanSession $work): void {
        $work->needed = [];
        foreach ($work->targets as $sym) $work->needed[$this->market->benchmarkKey($sym)] = true;
        $work->bench = [];
        $work->benchMap = [];
        $work->benchFingerprint = [];
        $work->benchSource = [];
        $work->benchErrors = [];
        $work->blocked = [];
        foreach (array_keys($work->needed) as $key) {
            try {
                $loaded = $this->market->loadBenchmark($work->cache, $key, $work->forceFull, $work->retryOnly, $work->stats);
                $work->bench[$key] = $loaded['rows'];
                $work->benchMap[$key] = $loaded['map'];
                $work->benchFingerprint[$key] = $loaded['map_fingerprint'];
                $work->benchSource[$key] = $loaded['source_ticker'];
                $work->stats['benchmark_sources'][$key] = $loaded['source_ticker'];
                $work->stats['benchmark_details'][$key] = $loaded['details'];
            } catch (Throwable $e) {
                $work->stats['benchmark_details'][$key] = LiteMarketData::benchmarkDetails(lm_get($work->cache->benchmark_meta, $key));
                $work->blocked[$key] = true;
                $work->benchErrors[] = [
                    'scope' => 'benchmark',
                    'country' => $key,
                    'error' => $e instanceof LiteMonFault ? $e->faultCode : 'BENCHMARK_FAILED'
                ];
            }
        }
        foreach ($work->watch as $sym) {
            $key = $this->market->benchmarkKey($sym);
            $sid = $sym['symbol_id'];
            $old = lm_get($work->cache->symbols, $sid);
            if (!isset ($work->benchFingerprint[$key]) || !$old) continue;
            $goodStock = lm_get($old, 'stock_data_status') === 'ok' || lm_get($old, 'stock_data_status') === null
                && strpos((string) lm_get($old, 'last_error', ''), 'BENCHMARK_') === 0;
            if ($work->retryOnly && !$work->forceFull && $goodStock && is_array(lm_get($old, 'bars')) && !LiteMarketData::missingBenchmarkDates($work->benchMap[$key], $old->bars)) {
                $stockLast = $old->bars[count($old->bars) - 1][0] ?? '';
                $benchmarkLast = $work->bench[$key][count($work->bench[$key]) - 1][0] ?? '';
                $old->recalculate_only = $stockLast !== '' && $benchmarkLast !== '' && strcmp($stockLast, $benchmarkLast) >= 0;
            }
            if (lm_get($old, 'status') !== 'ok' || $this->market->rowCurrent($work->cache, $sym, $old)) continue;
            $old->status = 'pending';
            $old->last_error = 'BENCHMARK_REFRESH_REQUIRED';
            if ($work->retryOnly && !$work->forceFull && is_array(lm_get($old, 'bars'))) {
                $stockLast = $old->bars[count($old->bars) - 1][0] ?? '';
                $benchmarkLast = $work->bench[$key][count($work->bench[$key]) - 1][0] ?? '';
                $old->recalculate_only = $stockLast !== '' && $benchmarkLast !== '' && strcmp($stockLast, $benchmarkLast) >= 0;
            }
            $work->cache->symbols->{
                $sid
            }
            = $old;
            $work->queuedIds[$sid] = true;
        }
        $work->cache->scan_queue = array_keys($work->queuedIds);
        unset ($old);
        // Failed groups are marked once, retaining valid bars and historical analysis as references.
        foreach (array_keys($work->blocked) as $key) $this->market->markBenchmarkPending($work->cache, $key, $work->watch, $work->queuedIds, $work->stats);
    }

    private function scanRows(LiteScanSession $work): void {
        $work->processed = 0;
        $work->lastCheckpoint = 0;
        $work->checkpointEvery = $work->bounded ? 1 : LitePolicy::CHECKPOINT_EVERY;
        $loadedCpu = LiteResource::cpuMode($work->loadedResource);
        $work->stopResource = $loadedCpu === 'defer' || LiteResource::lowMemoryBatch($work->loadedResource) === 0 ? $work->loadedResource : null;
        $work->stopReason = $work->stopResource === null ? null : ($loadedCpu === 'defer' ? (string)($work->loadedResource['cpu_reason']
            ?? 'CPU_PRESSURE'): 'MEMORY_HARD_FLOOR');
        $work->stats['cpu_early_stop'] = $loadedCpu === 'defer';
        $work->stats['memory_early_stop'] = LiteResource::lowMemoryBatch($work->loadedResource) === 0;
        foreach ($work->targets as $sym) {
            $sid = $sym['symbol_id'];
            if (!isset ($work->queuedIds[$sid])) continue;
            if (LiteStateStore::stopRequested()) throw new LiteMonStop();
            $nowRes = LiteResource::sample();
            $cpuNow = LiteResource::cpuMode($nowRes);
            $budget = LiteResource::lowMemoryBatch($nowRes);
            if ($cpuNow === 'defer' || $budget === 0) {
                $work->stopResource = $nowRes;
                $work->stopReason = $cpuNow === 'defer' ? (string)($nowRes['cpu_reason'] ?? 'CPU_PRESSURE'): 'MEMORY_HARD_FLOOR';
                $work->stats['cpu_early_stop'] = $cpuNow === 'defer';
                $work->stats['memory_early_stop'] = $budget === 0;
                break;
            }
            if ($cpuNow === 'limited') {
                $work->cpuLimited = true;
                $work->bounded = true;
                $work->batch = min($work->batch, 1);
                $work->checkpointEvery = 1;
            }
            if (($nowRes['memory_mode'] ?? 'normal') === 'low') {
                $work->lowMemory = true;
                $work->bounded = true;
                $work->batch = min($work->batch, $budget);
                $work->checkpointEvery = 1;
            }
            if ($work->bounded && $work->processed >= $work->batch) {
                $work->stopResource = $nowRes;
                $work->stopReason = $work->cpuLimited ? 'CPU_SOFT_LOAD' : 'MEMORY_BATCH';
                break;
            }
            $this->processSymbol($work, $sym);
            $work->processed++;
            unset ($work->queuedIds[$sid]);
            $work->cache->scan_queue = array_keys($work->queuedIds);
            $this->checkpoint($work, $sid);
            if ($work->bounded) {
                LiteTurn::releaseMemory();
                usleep(400000);
            } else usleep(150000);
        }
    }

    private function processSymbol(LiteScanSession $work, array $sym): void {
        $sid = $sym['symbol_id'];
        $old = lm_get($work->cache->symbols, $sid);
        $goodBars = null;
        try {
            $oldBars = $old && is_array(lm_get($old, 'bars')) ? $old->bars : null;
            $benchKey = $this->market->benchmarkKey($sym);
            $stockLast = $oldBars ? $oldBars[count($oldBars) - 1][0] : '';
            $benchmarkLast = $work->bench[$benchKey][count($work->bench[$benchKey]) - 1][0] ?? '';
            $seriesQuality = null;
            $upgradeHistory = LiteMarketData::needsTrendHistoryRefresh($old);
            $bars = $work->retryOnly && !$work->forceFull && !$upgradeHistory && lm_get($old, 'recalculate_only') === true && $oldBars
                && $benchmarkLast !== '' && strcmp($stockLast, $benchmarkLast) >= 0 ? $oldBars : $this->market->fetchSeries($sym, $oldBars, $work->forceFull || $upgradeHistory, $work->stats, $seriesQuality);
            if ($seriesQuality !== null) $work->stats['quality_reused']++;
            $q = $seriesQuality ?? LiteQuality::compare($oldBars, $bars);
            if (!$q['accept']) throw new LiteMonFault($q['reason']);
            $goodBars = $bars;
            $missing = LiteMarketData::missingBenchmarkDates($work->benchMap[$benchKey], $bars);
            if ($missing && max($missing) < min(array_keys($work->benchMap[$benchKey]))) throw new LiteMonFault('BENCHMARK_DATE_MISSING');
            if ($missing) {
                $req = $this->market->benchmarkRequirement($work->cache, $benchKey, $bars);
                $meta = lm_get($work->cache->benchmark_meta, $benchKey, new stdClass());
                foreach ($req as $field => $value) $meta->$field = $value;
                $meta->status = 'unavailable';
                $meta->missing_dates = $missing;
                $meta->coverage_status = 'provider_incomplete';
                $work->cache->benchmark_meta->{
                    $benchKey
                }
                = $meta;
                try {
                    $loaded = $this->market->loadBenchmark($work->cache, $benchKey, false, false, $work->stats, $bars);
                    $work->bench[$benchKey] = $loaded['rows'];
                    $work->benchMap[$benchKey] = $loaded['map'];
                    $work->benchFingerprint[$benchKey] = $loaded['map_fingerprint'];
                    $work->benchSource[$benchKey] = $loaded['source_ticker'];
                    $work->stats['benchmark_sources'][$benchKey] = $loaded['source_ticker'];
                    $work->stats['benchmark_details'][$benchKey] = $loaded['details'];
                } catch (Throwable $e) {
                    $work->stats['benchmark_details'][$benchKey] = LiteMarketData::benchmarkDetails(lm_get($work->cache->benchmark_meta, $benchKey));
                    $work->blocked[$benchKey] = true;
                    $work->benchErrors[] = [
                        'scope' => 'benchmark',
                        'country' => $benchKey,
                        'error' => $e instanceof LiteMonFault ? $e->faultCode : 'BENCHMARK_FAILED'
                    ];
                    $this->market->markBenchmarkPending($work->cache, $benchKey, $work->watch, $work->queuedIds, $work->stats);
                    throw new LiteMonFault('BENCHMARK_COVERAGE_MISSING');
                }
                foreach ($work->watch as $other) {
                    if ($this->market->benchmarkKey($other) !== $benchKey || $other['symbol_id'] === $sid) continue;
                    $prior = lm_get($work->cache->symbols, $other['symbol_id']);
                    if ($prior && lm_get($prior, 'status') === 'ok' && !$this->market->rowCurrent($work->cache, $other, $prior)) {
                        $prior->status = 'pending';
                        $prior->last_error = 'BENCHMARK_REFRESH_REQUIRED';
                        $work->queuedIds[$other['symbol_id']] = true;
                    }
                }
                $work->cache->scan_queue = array_keys($work->queuedIds);
            }
            if ($this->market->analysisReusable($old, $bars, $work->benchFingerprint[$benchKey])) {
                $a = $this->market->guardCurrentAnalysis($work->cache, $sid, $old);
                $work->stats['analysis_reused']++;
            } else {
                $a = LiteSwing::analyzeMapped($sym, $bars, $work->benchMap[$benchKey], $this->market->priorReferenceAnalysis($work->cache, $sid, $old));
                $work->stats['analysis_recomputed']++;
            }
            if (!isset ($a['ms7']) || !is_array($a['ms7'])) $a['ms7'] = LiteSwing::ms7FromAnalysis($a);
            if (method_exists($this->yahoo, 'recoveryFor')) {
                $recovery = $this->yahoo->recoveryFor($sid);
                if ($recovery !== null) $a['provider_recovery'] = $recovery;
            }
            $this->market->rememberReferenceSetup($work->cache, $sid, $a);
            $row = (object) [
                'status' => 'ok',
                'symbol' => (object) $sym,
                'bars' => $bars,
                'analysis' => $a,
                'quality' => $q,
                'checked_at' => lm_iso(),
                'stock_data_status' => 'ok',
                'stock_data_checked_at' => lm_iso(),
                'benchmark_source_ticker' => $work->benchSource[$benchKey],
                'benchmark_fingerprint' => $work->benchFingerprint[$benchKey],
                'calculation_version' => LitePolicy::CALCULATION_VERSION
            ];
            if ($oldBars === $bars && lm_get($old, 'final_check') instanceof stdClass) $row->final_check = $old->final_check;
            $work->cache->symbols->{
                $sid
            }
            = $row;
        } catch (Throwable $e) {
            $code = $e instanceof LiteMonFault ? $e->faultCode : 'SCAN_FAILED';
            $prev = $old ? : new stdClass();
            unset ($prev->recalculate_only);
            if ($goodBars !== null && strpos($code, 'BENCHMARK_') === 0) {
                $prev->bars = $goodBars;
                $prev->stock_data_status = 'ok';
                $prev->stock_data_checked_at = lm_iso();
            } else $prev->stock_data_status = 'unavailable';
            $prev->status = 'pending';
            $prev->last_error = $code;
            $prev->checked_at = lm_iso();
            $prev->symbol = (object) $sym;
            $work->cache->symbols->{
                $sid
            }
            = $prev;
        }
    }

    private function checkpoint(LiteScanSession $work, string $sid): void {
        if ($work->processed - $work->lastCheckpoint >= $work->checkpointEvery || $work->processed === count($work->targets)) {
            $work->cache->updated_at = lm_iso();
            LiteRuntime::write('cache', $work->cache);
            $fresh = LiteStateStore::state();
            if ($fresh->stop_requested) throw new LiteMonStop();
            $fresh->phase = 'SCAN';
            $fresh->heartbeat_at = lm_iso();
            $fresh->progress = [
                'state' => 'scanning',
                'index' => $work->processed,
                'batch_total' => count($work->targets),
                'queue_total' => $work->queuedTargets,
                'symbol_id' => $sid,
                'retry_only' => $work->retryOnly,
                'mode' => $work->stats['mode']
            ];
            LiteStateStore::saveState($fresh);
            $work->lastCheckpoint = $work->processed;
        }
    }

    private function collectResults(LiteScanSession $work): void {
        $work->remainingQueue = count($work->queuedIds);
        if ($work->remainingQueue > 0 && $work->stopResource === null && $work->bounded) {
            $work->stopResource = $work->loadedResource;
            $work->stopReason = $work->cpuLimited ? 'CPU_SOFT_LOAD' : 'MEMORY_BATCH';
        }
        $work->cache->scan_queue = array_keys($work->queuedIds);
        [$work->results, $work->errors, $work->done, $work->pending] = $this->market->buildCurrentResults($work->cache, $work->watch);
        if ($work->forceFull && $work->remainingQueue === 0 && $work->pending === 0) {
            $work->cache->last_full_resync_at = lm_iso();
            $work->cache->full_resync_pending = false;
        }
        if ($work->done > 0 && !$work->bounded && $work->stopResource === null) {
            $this->market->finalRecheck($work->cache, $work->results, $work->stats);
            [$work->results, $work->errors, $work->done, $work->pending] = $this->market->buildCurrentResults($work->cache, $work->watch);
        }
        $work->cache->updated_at = lm_iso();
        LiteRuntime::write('cache', $work->cache);
        unset (
            $work->cache,
            $work->bench,
            $work->benchMap,
            $work->benchFingerprint,
            $work->benchSource,
            $work->targets,
            $work->queuedIds,
            $work->needed
        );
        if (method_exists($this->yahoo, 'releasePayloads')) $this->yahoo->releasePayloads();
        LiteTurn::releaseMemory();
        $work->errors = array_merge($work->benchErrors, $work->errors);
        $work->stats['queue_remaining'] = $work->remainingQueue;
        $work->stats['low_memory_active'] = $work->lowMemory;
        $work->stats['cpu_limited_active'] = $work->cpuLimited;
        $work->stats['resource_after'] = LiteResource::sample();
        $work->stats = $this->turn->networkStats($work->stats);
        $work->candidateIds = array_map(static function ($x) {
            return $x['symbol_id'];
        }, $work->watch);
        $referenceLists = LiteMarketData::referenceLists($work->results, $work->candidateIds);
        $work->analysisReference = $referenceLists['buy_candidates'];
        $work->coverage = [
            'KR' => ['done' => 0, 'pending' => 0],
            'US' => ['done' => 0, 'pending' => 0],
            'JP' => ['done' => 0, 'pending' => 0]
        ];
        foreach ($work->results as $r) $work->coverage[$r['country']]['done']++;
        foreach ($work->watch as $sym) $work->coverage[$sym['country']]['pending']++;
        foreach ($work->coverage as & $cv) $cv['pending'] -= $cv['done'];
        unset ($cv);
        $work->summary = [
            'scan_id' => 'scan-' . gmdate('Ymd-His'),
            'at' => lm_iso(),
            'mode' => $work->stats['mode'],
            'done' => $work->done,
            'pending' => $work->pending,
            'candidate_count' => count($work->watch),
            'eligible_count' => count(array_filter($work->results, static function ($x) {
                return $x['eligible'];
            })),
            'coverage' => $work->coverage,
            'analysis_reference' => $work->analysisReference,
            'reference_lists' => $referenceLists,
            'results' => $work->results,
            'errors' => $work->errors,
            'network_stats' => $work->stats
        ];
        LiteRuntime::write(
            'evidence',
            (object) [
                'contract' => 'MON-LITE-EVIDENCE-1.1',
                'latest_scan' => $work->summary,
                'error_summary' => $this->compressErrors($work->errors)
            ]
        );
    }

    private function publishSummary(LiteScanSession $work): array {
        $work->state = LiteStateStore::state();
        $work->state->scan_handled_seq = max((int) lm_get($work->state, 'scan_handled_seq', 0), $work->scanSeq);
        $work->state->last_full_scan = lm_iso();
        if ($work->pending === 0 && $work->remainingQueue === 0) $work->state->last_completed_scan_at = lm_iso();
        $work->state->last_scan_mode = $work->stats['mode'];
        $work->state->last_network_stats = $work->stats;
        $work->state->next_full_scan = LitePolicy::nextFullAt();
        $work->state->next_light_check = lm_iso(time() + ($work->pending > 0 || $work->remainingQueue > 0 ? LitePolicy::LOW_MEMORY_RETRY_SECONDS : LitePolicy::LIGHT_SECONDS));
        $work->state->progress = [
            'state' => $work->pending > 0 || $work->remainingQueue > 0 ? 'partial' : 'complete',
            'done' => $work->done,
            'total' => count($work->watch),
            'pending' => $work->pending,
            'queue_remaining' => $work->remainingQueue,
            'coverage' => $work->coverage,
            'next_retry_seconds' => $work->pending > 0 || $work->remainingQueue > 0 ? LitePolicy::LOW_MEMORY_RETRY_SECONDS : null
        ];
        $work->state->phase = $work->pending > 0 || $work->remainingQueue > 0 ? 'WAIT' : 'RESULT';
        $work->state->heartbeat_at = lm_iso();
        $work->state->last_error = null;
        $work->state->resource_wait = $work->stopResource === null ? null : (object) [
            'reason' => $work->stopReason,
            'since' => lm_iso(),
            'checked_at' => lm_iso(),
            'resource' => $work->stopResource
        ];
        $work->shouldPublish = $work->remainingQueue === 0;
        $work->fingerprint = lm_hash(
            [
                $work->summary['done'],
                $work->summary['pending'],
                $work->summary['results'],
                $work->summary['errors'],
                $work->watch
            ]
        );
        if ($work->shouldPublish) {
            $work->state->publication_fingerprint = $work->fingerprint;
            $work->state->publication_pending = $work->fingerprint !== lm_get($work->state, 'last_publish_fingerprint');
            $work->state->last_publication_error = null;
        }
        LiteStateStore::saveState($work->state);
        if ($work->shouldPublish && $work->state->publication_pending) {
            try {
                if ($this->references->publishData($work->summary, $work->watch)) {
                    $work->state = LiteStateStore::state();
                    $work->state->last_publish_fingerprint = $work->fingerprint;
                    $work->state->publication_pending = false;
                    $work->state->last_publication_error = null;
                    LiteStateStore::saveState($work->state);
                }
            } catch (Throwable $e) {
                $work->state = LiteStateStore::state();
                $work->state->publication_pending = true;
                $work->state->last_publication_error = $e instanceof LiteMonFault ? $e->faultCode : 'PUBLICATION_FAILED';
                LiteStateStore::saveState($work->state);
                try {
                    LiteRuntime::log('publication_pending', ['code' => $work->state->last_publication_error]);
                } catch (Throwable $ignore) {
                }
            }
        }
        unset ($work->results, $work->errors, $work->analysisReference, $work->candidateIds, $work->watch);
        $this->turn->end();
        $this->decisions->mailboxCheck();
        $this->turn->end();
        $work->summary['publication_pending'] = lm_get(LiteStateStore::state(), 'publication_pending') === true;
        return $work->summary;
    }

    private function compressErrors(array $errors): array {
        $x = [];
        $now = lm_iso();
        foreach ($errors as $e) {
            $k = ($e['error'] ?? 'UNKNOWN') . '|' . ($e['symbol_id'] ?? $e['country'] ?? '');
            if (!isset ($x[$k])) $x[$k] = [
                'error' => $e['error'] ?? 'UNKNOWN',
                'subject' => $e['symbol_id'] ?? $e['country'] ?? '',
                'count' => 0,
                'first_at' => $now,
                'last_at' => $now
            ];
            $x[$k]['count']++;
            $x[$k]['last_at'] = $now;
        }
        return array_values($x);
    }

    public function lightCheck(): array {
        $this->turn->begin();
        $s = LiteStateStore::state();
        $s->last_light_check = lm_iso();
        $s->next_light_check = lm_iso(time() + LitePolicy::LIGHT_SECONDS);
        $s->heartbeat_at = lm_iso();
        $s->phase = 'WAIT';
        $s->last_error = null;
        LiteStateStore::saveState($s);
        $c = $this->market->cacheObj();
        $pending = $this->market->pendingCount($c);
        unset ($c);
        $this->retryPublication();
        $this->decisions->mailboxCheck();
        LiteRuntime::cleanup();
        $this->turn->end();
        return [
            'at' => lm_iso(),
            'pending' => $pending,
            'next_light_check' => LiteStateStore::state()->next_light_check
        ];
    }

    public function retryPublication(): void {
        // The durable final outbox always has priority over reference publication.
        if ($this->decisions->decisionPending()) {
            try {
                $this->decisions->resumeDecision();
            } catch (Throwable $e) {
                $this->decisions->recordDecisionError($e);
                return;
            }
        }
        $s = LiteStateStore::state();
        if (lm_get($s, 'publication_pending') !== true) return;
        $e = LiteRuntime::read('evidence');
        $summary = lm_array(lm_get($e, 'latest_scan'));
        unset ($e);
        if (is_array($summary) && (int) lm_get(lm_get($summary, 'network_stats'), 'queue_remaining', 0) > 0) return;
        if (!is_array($summary)) {
            $s->last_publication_error = 'PUBLICATION_SUMMARY_MISSING';
            LiteStateStore::saveState($s);
            return;
        }
        $watch = $this->market->cachedWatchlist();
        if (!$watch) {
            $s->last_publication_error = 'PUBLICATION_WATCHLIST_MISSING';
            LiteStateStore::saveState($s);
            return;
        }
        try {
            if ($this->references->publishData($summary, $watch)) {
                $s = LiteStateStore::state();
                $s->last_publish_fingerprint = (string) lm_get($s, 'publication_fingerprint', '');
                $s->publication_pending = false;
                $s->last_publication_error = null;
                LiteStateStore::saveState($s);
            }
        } catch (Throwable $e) {
            $s = LiteStateStore::state();
            $s->publication_pending = true;
            $s->last_publication_error = $e instanceof LiteMonFault ? $e->faultCode : 'PUBLICATION_FAILED';
            LiteStateStore::saveState($s);
            try {
                LiteRuntime::log('publication_retry_pending', ['code' => $s->last_publication_error]);
            } catch (Throwable $ignore) {
            }
        }
    }
}
// Composition root, public facade and daemon scheduling
final class LiteApplication {
    public $market;
    public $writer;
    public $decisions;
    public $references;
    public $scanner;
    public $turn;

    public function __construct(LiteConfig $cfg, $http, $yahoo) {
        $this->turn = new LiteTurn($http, $yahoo);
        $this->market = new LiteMarketData($http, $yahoo);
        $this->writer = new LiteVerifiedWriter($http);
        $this->decisions = new LiteDecisionService($cfg, $http, $this->market, $this->writer);
        $this->references = new LiteReferencePublisher($cfg, $http, $this->decisions, $this->writer);
        $this->scanner = new LiteScanEngine($yahoo, $this->market, $this->decisions, $this->references, $this->turn);
    }
}

final class LiteMonitor {
    private $cfg;
    private $http;
    private $yahoo;
    private $application = null;
    const LIGHT_SECONDS = LitePolicy::LIGHT_SECONDS;
    const CHECKPOINT_EVERY = LitePolicy::CHECKPOINT_EVERY;
    const FULL_RESYNC_SECONDS = LitePolicy::FULL_RESYNC_SECONDS;
    const INCREMENTAL_OVERLAP_DAYS = LitePolicy::INCREMENTAL_OVERLAP_DAYS;
    const FINAL_RECHECK_PER_COUNTRY = LitePolicy::FINAL_RECHECK_PER_COUNTRY;
    const BENCHMARK_RETRY_TTL = LitePolicy::BENCHMARK_RETRY_TTL;
    const BENCHMARK_COVERAGE_VERSION = LitePolicy::BENCHMARK_COVERAGE_VERSION;
    const MAILBOX_SECONDS = LitePolicy::MAILBOX_SECONDS;
    const FINAL_RECHECK_TTL = LitePolicy::FINAL_RECHECK_TTL;
    const LOW_MEMORY_HARD_FLOOR = LitePolicy::LOW_MEMORY_HARD_FLOOR;
    const LOW_MEMORY_RETRY_SECONDS = LitePolicy::LOW_MEMORY_RETRY_SECONDS;
    const CALCULATION_VERSION = LitePolicy::CALCULATION_VERSION;

    public function __construct(?LiteConfig $cfg = null, $http = null, $yahoo = null) {
        LiteRuntime::ready();
        if (function_exists('proc_nice')) {
            @proc_nice(10);
        }
        $limit = LiteResource::limitBytes((string) ini_get('memory_limit'));
        if ($limit === null || $limit > 96 * 1048576) {
            @ini_set('memory_limit', '96M');
        }
        $this->cfg = $cfg ?? new LiteConfig();
        $this->http = $http ?? new LiteHttp($this->cfg);
        $this->yahoo = $yahoo ?? new LiteYahoo($this->http);
    }

    private function services(): LiteApplication {
        if ($this->application === null) {
            $this->application = new LiteApplication($this->cfg, $this->http, $this->yahoo);
        }
        return $this->application;
    }

    public static function nextFullAt(?int $now = null): string {
        return LitePolicy::nextFullAt($now);
    }

    public static function defaultState(): stdClass {
        return LiteStateStore::defaultState();
    }

    public function state(): stdClass {
        return LiteStateStore::state();
    }

    public static function installedIdentity(): array {
        return LiteStateStore::installedIdentity();
    }

    public function daemonLiveness(): array {
        return LiteStateStore::daemonLiveness();
    }

    public function finalSaveStatus(): array {
        return LiteDecisionService::finalSaveStatus();
    }

    public function saveState($state, bool $allowStopReset = false): void {
        LiteStateStore::saveState($state, $allowStopReset);
    }

    public function requestAction(string $action): void {
        LiteStateStore::requestAction($action);
    }

    public static function recommendThree(array $rows, array $candidateIds = []): array {
        return LiteMarketData::recommendThree($rows, $candidateIds);
    }

    public static function normalizeChatgptDecision(array $selected): array {
        return LiteDecisionService::normalizeChatgptDecision($selected);
    }

    public static function lowMemoryBatch(array $resource): int {
        return LiteResource::lowMemoryBatch($resource);
    }

    public function fullScan(bool $retryOnly = false): array {
        return $this->services()->scanner->fullScan($retryOnly);
    }

    public function lightCheck(): array {
        return $this->services()->scanner->lightCheck();
    }

    public function loop(?string $controlId = null, ?int $controlSeq = null): void {
        $lock = LiteRuntime::lock();
        try {
            LiteStateStore::startState($controlId, $controlSeq);
            while (true) {
                $state = LiteStateStore::state();
                if ($state->stop_requested) break;
                try {
                    $this->executeDueWork($state);
                } catch (LiteMonStop $stop) {
                    break;
                } catch (Throwable $error) {
                    $this->recordCycleError($error);
                }
                $this->finishCycle();
                if (!$this->waitForNextDue()) break;
            }
        } finally {
            try {
                $state = LiteStateStore::state();
                $state->running = false;
                $state->phase = 'IDLE';
                $state->heartbeat_at = lm_iso();
                LiteStateStore::saveState($state);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function executeDueWork(stdClass $state): void {
        $app = $this->services();
        $dueFull = !is_string($state->last_full_scan) || !is_string($state->next_full_scan) || strtotime($state->next_full_scan) <= time();
        $dueMailbox = !is_string($state->next_mailbox_check) || strtotime($state->next_mailbox_check) <= time();
        $dueLight = !is_string($state->next_light_check) || strtotime($state->next_light_check) <= time();
        if ($app->decisions->decisionPending()) {
            if ($dueMailbox) $app->scanner->retryPublication();
        } elseif ($dueMailbox) {
            $app->scanner->retryPublication();
            if (!$app->decisions->decisionPending()) $app->decisions->mailboxCheck();
        } elseif ($dueFull) {
            $app->scanner->fullScan(false);
        } elseif ($dueLight) {
            $cache = $app->market->cacheObj();
            $hasPending = $app->market->pendingCount($cache) > 0;
            unset ($cache);
            if ($hasPending) $app->scanner->fullScan(true);
            else $app->scanner->lightCheck();
        }
    }

    private function recordCycleError(Throwable $error): void {
        $code = $error instanceof LiteMonFault ? $error->faultCode : 'LOOP_ERROR';
        try {
            LiteRuntime::log('cycle_error', ['code' => $code]);
        } catch (Throwable $ignored) {
        }
        $state = LiteStateStore::state();
        $state->last_error = $code;
        $state->historical_error = (object) ['code' => $code, 'at' => lm_iso(), 'phase' => 'ERROR'];
        $state->phase = 'ERROR';
        $state->scan_handled_seq = max((int) lm_get($state, 'scan_handled_seq', 0), (int) lm_get($state, 'scan_request_seq', 0));
        $state->next_full_scan = lm_iso(time() + LitePolicy::LOW_MEMORY_RETRY_SECONDS);
        $state->next_light_check = lm_iso(time() + LitePolicy::LOW_MEMORY_RETRY_SECONDS);
        LiteStateStore::saveState($state);
    }

    private function finishCycle(): void {
        $this->services()->turn->end();
        $state = LiteStateStore::state();
        $state->last_memory = LiteResource::sample();
        if ($state->running && !in_array($state->phase, ['ERROR', 'SAVE_PENDING'], true)) $state->phase = 'WAIT';
        $state->heartbeat_at = lm_iso();
        LiteStateStore::saveState($state);
    }

    private function waitForNextDue(): bool {
        for ($i = 1; $i <= 1800; $i++) {
            sleep(1);
            $state = LiteStateStore::state();
            if ($state->stop_requested) return false;
            if ($i % 30 !== 0) continue;
            LiteStateStore::idleHeartbeat();
            $full = is_string($state->next_full_scan) ? strtotime($state->next_full_scan): false;
            $light = is_string($state->next_light_check) ? strtotime($state->next_light_check): false;
            $mailbox = is_string($state->next_mailbox_check) ? strtotime($state->next_mailbox_check): false;
            if (($full !== false && $full <= time()) || ($light !== false && $light <= time()) || ($mailbox !== false
                && $mailbox <= time())) return true;
        }
        return true;
    }
}
// Status views and manual web commands
/** A browser-requested start or restart; status reads never launch a process. */
final class LiteManualControl {
    private $cfg;

    public function __construct(LiteConfig $cfg) {
        $this->cfg = $cfg;
    }

    public function begin(string $action): array {
        if (!in_array($action, ['start', 'restart'], true)) throw new LiteMonFault('WEB_ACTION_INVALID');
        $expectedSeq = (int) lm_get(LiteStateStore::state(), 'control_seq', 0);
        if ($action === 'start' && LiteRuntime::lockHeld()) throw new LiteMonFault('ALREADY_RUNNING');
        // Check the local CLI before asking a running daemon to stop.
        $health = LiteHealth::local($this->cfg, true, false);
        if (!$health['ready_to_start']) throw new LiteMonFault((string)($health['blockers'][0] ?? 'NAS_PREFLIGHT_FAILED'));
        $record = LiteStateStore::requestControl($action, $expectedSeq);
        $id = (string) $record['id'];
        $seq = (int) $record['seq'];
        if (!LiteRuntime::lockHeld()) return $this->advance($id, $seq);
        return self::status($id, $seq);
    }

    public function advance(string $id, int $seq): array {
        $claim = LiteStateStore::claimControl($id, $seq);
        if ($claim['claimed'] === true) {
            // __FILE__ is the assembled single mon.php, never a fragment path.
            $command = escapeshellarg($this->cfg->phpCli) . ' ' . escapeshellarg(__FILE__) . ' --run ' . escapeshellarg('--control-id='
                . $id) . ' ' . escapeshellarg('--control-seq=' . (string) $seq) . ' >/dev/null 2>&1 &';
            $failure = null;
            try {
                $output = [];
                $code = 1;
                @exec($command, $output, $code);
                if ($code !== 0) $failure = 'WEB_PROCESS_LAUNCH_FAILED';
            } catch (Throwable $error) {
                $failure = 'WEB_PROCESS_LAUNCH_FAILED';
            }
            if ($failure !== null) LiteStateStore::failControl($id, $seq, $failure);
        }
        return self::status($id, $seq);
    }

    public static function status(string $id, int $seq): array {
        $liveness = LiteStateStore::daemonLiveness();
        $state = LiteStateStore::state();
        $held = LiteRuntime::lockHeld();
        $identity = lm_get($state, 'run_identity');
        $record = lm_get($state, 'manual_control');
        $response = [
            'operation_id' => $id,
            'operation_seq' => $seq,
            'phase' => 'cancelled',
            'message' => '이 요청은 취소되었거나 다른 요청으로 바뀌었습니다.',
            'error' => 'CONTROL_REQUEST_CANCELLED',
            'lock_held' => $held,
            'daemon_identity' => $identity
        ];
        if ($id === '' || $seq < 1 || lm_get($record, 'id') !== $id || lm_get($record, 'seq') !== $seq || lm_get($state, 'control_seq') !== $seq) return $response;
        $phase = (string) lm_get($record, 'phase', '');
        if ($phase === 'failed') {
            return self::result($response, 'failed', '요청을 완료하지 못했습니다.', (string) lm_get($record, 'error', 'CONTROL_REQUEST_FAILED'));
        }
        if (lm_get($record, 'source_sha256') !== MON_LITE_SOURCE_SHA256) {
            return self::result($response, 'failed', '설치 파일이 바뀌었습니다. 새 요청으로 다시 시작해 주세요.', 'CONTROL_SOURCE_MISMATCH');
        }
        if ($phase === 'started') {
            if (!$held) return self::result($response, 'failed', '시작한 데몬이 종료되었습니다.', 'DAEMON_EXITED');
            $launch = lm_num(lm_get($record, 'launch_unix'));
            $started = lm_num(lm_get($identity, 'started_unix'));
            $matches = lm_get($identity, 'control_id') === $id && lm_get($identity, 'control_seq') === $seq && lm_get($identity, 'source_sha256') === MON_LITE_SOURCE_SHA256
                && $launch !== null && $launch > 0 && $started !== null && $started >= $launch;
            if (!$matches) return self::result($response, 'failed', '요청한 데몬의 실행 신원을 확인하지 못했습니다.', 'DAEMON_IDENTITY_MISMATCH');
            if (lm_get($liveness, 'lock_held') !== true || lm_get($liveness, 'heartbeat_fresh') !== true || lm_get($liveness, 'installed_source_matches') !== true) {
                return self::result($response, 'starting', '시작한 데몬의 응답을 확인하고 있습니다.', 'DAEMON_HEARTBEAT_STALE');
            }
            return self::result($response, 'confirmed', '시작 확인 완료');
        }
        $deadline = lm_num(lm_get($record, 'deadline_unix'));
        if ($deadline === null || microtime(true) >= $deadline) {
            return self::result($response, 'expired', '요청 대기 시간이 지났습니다. 상태를 확인한 뒤 다시 요청해 주세요.', 'CONTROL_REQUEST_EXPIRED');
        }
        if ($phase === 'waiting' || $phase === 'stopping') {
            return $held ? self::result($response, 'stopping', '기존 데몬의 종료를 기다리고 있습니다.'): self::result($response, 'ready', '기존 데몬이 종료되었습니다. 시작을 이어갈 수 있습니다.');
        }
        if ($phase === 'launching') return self::result($response, 'starting', '새 데몬의 시작을 확인하고 있습니다.');
        return self::result($response, 'failed', '요청 상태를 확인하지 못했습니다.', 'CONTROL_REQUEST_INVALID');
    }

    private static function result(array $response, string $phase, string $message, ?string $error = null): array {
        $response['phase'] = $phase;
        $response['message'] = $message;
        $response['error'] = $error;
        return $response;
    }
}

final class LiteWebController {

    public static function run(): int {
        header('Cache-Control: no-store, max-age=0');
        $view = (string)($_GET['view'] ?? '');
        if ($view === 'control') {
            try {
                $control = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? self::command(): self::controlStatus();
                return self::controlResponse($control);
            } catch (Throwable $error) {
                return self::controlError($error);
            }
        }
        $cfg = new LiteConfig();
        if ($view === 'health') {
            header('Content-Type: application/json; charset=utf-8');
            echo lm_json(['ok' => true, 'health' => LiteHealth::local($cfg, true)]);
            return 0;
        }
        $notice = '';
        $ok = true;
        $control = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                $control = self::command($cfg);
                if (!empty ($control['operation_id'])) {
                    header(
                        'Location: ?operation_id=' . rawurlencode((string) $control['operation_id']) . '&operation_seq='
                            . (int) $control['operation_seq'],
                        true,
                        303
                    );
                    return 0;
                }
                $notice = (string)($control['message'] ?? '');
            } catch (Throwable $error) {
                $ok = false;
                $notice = self::errorMessage($error);
            }
        } elseif (!empty ($_GET['operation_id'])) {
            $control = self::controlStatus();
            $notice = (string)($control['message'] ?? '');
        }
        $health = LiteHealth::local($cfg, false);
        $monitor = null;
        $state = LiteStateStore::defaultState();
        try {
            $monitor = new LiteMonitor($cfg);
            $state = $monitor->state();
        } catch (Throwable $error) {
            $ok = false;
            $notice = self::errorMessage($error);
        }
        $runtime = is_array(lm_get($health, 'runtime')) ? $health['runtime'] : ['path' => MON_LITE_RUNTIME, 'file_count' => 0, 'bytes' => 0];
        if ($view === 'status') return self::status($ok, $monitor, $state, $runtime, $health, $notice);
        return self::render($cfg, $monitor, $state, $runtime, $health, $notice, $ok, $control);
    }

    private static function command(?LiteConfig $cfg = null): array {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'stop' || $action === 'scan') {
            LiteStateStore::requestAction($action);
            if ($action === 'stop') {
                $status = self::currentControlStatus();
                $status['message'] = $status['phase'] === 'stopped' ? '중지 완료' : '중지 요청을 기록했습니다. 기존 작업의 안전한 종료를 확인하고 있습니다.';
                return $status;
            }
            return ['phase' => 'requested', 'message' => '다음 주기에 정밀 스캔을 요청했습니다.'];
        }
        $manual = new LiteManualControl($cfg ?? new LiteConfig());
        if ($action === 'start' || $action === 'restart') return $manual->begin($action);
        if ($action === 'continue') return $manual->advance((string)($_POST['operation_id'] ?? ''), (int)($_POST['operation_seq']
            ?? 0));
        throw new LiteMonFault('WEB_ACTION_INVALID');
    }

    private static function currentControlStatus(): array {
        $state = LiteStateStore::state();
        $held = LiteRuntime::lockHeld();
        $phase = !$held ? 'stopped' : ($state->stop_requested ? 'stopping' : 'running');
        return [
            'phase' => $phase,
            'message' => $phase === 'stopped' ? '중지 완료' : ($phase === 'stopping' ? '기존 데몬의 종료를 확인하고 있습니다.' : '데몬 실행 중'),
            'lock_held' => $held,
            'daemon_identity' => lm_get($state, 'run_identity')
        ];
    }

    private static function controlStatus(): array {
        $id = (string)($_GET['operation_id'] ?? '');
        return $id === '' ? self::currentControlStatus(): LiteManualControl::status($id, (int)($_GET['operation_seq']
            ?? 0));
    }

    private static function controlResponse(array $control): int {
        header('Content-Type: application/json; charset=utf-8');
        $ok = !in_array($control['phase'] ?? '', ['failed', 'expired', 'cancelled'], true);
        echo lm_json(['ok' => $ok, 'control' => $control]);
        return $ok ? 0 : 1;
    }

    private static function errorMessage(Throwable $error): string {
        $code = $error instanceof LiteMonFault ? $error->faultCode : 'WEB_ERROR';
        if ($code === 'ALREADY_RUNNING') return '이미 실행 중입니다. 다시 시작하려면 재시작 버튼을 눌러 주세요.';
        if (in_array($code, ['CONTROL_CANCELLED', 'CONTROL_REQUEST_CANCELLED'], true)) return '더 최근의 요청으로 취소되었습니다.';
        if (in_array($code, ['CONTROL_EXPIRED', 'CONTROL_REQUEST_EXPIRED'], true)) return '요청 확인 시간이 지났습니다. 현재 상태를 확인한 뒤 다시 요청해 주세요.';
        return '요청을 완료하지 못했습니다: ' . $code;
    }

    private static function controlError(Throwable $error): int {
        return self::controlResponse(
            [
                'phase' => 'failed',
                'message' => self::errorMessage($error),
                'error' => $error instanceof LiteMonFault ? $error->faultCode : 'WEB_ERROR'
            ]
        );
    }

    private static function status(bool $ok, ?LiteMonitor $monitor, stdClass $state, array $runtime, array $health, string $notice): int {
        header('Content-Type: application/json; charset=utf-8');
        echo lm_json(
            [
                'ok' => $ok,
                'version' => MON_LITE_VERSION,
                'build' => MON_LITE_BUILD,
                'policy' => MON_LITE_POLICY,
                'installed_identity' => LiteStateStore::installedIdentity(),
                'daemon_identity' => lm_get($state, 'run_identity'),
                'daemon_liveness' => $monitor ? $monitor->daemonLiveness(): ['status' => 'unknown'],
                'final_save' => $monitor ? $monitor->finalSaveStatus(): ['status' => 'unknown', 'last_local_receipt_verified' => false],
                'state' => $state,
                'runtime' => $runtime,
                'health' => $health,
                'message' => $notice
            ]
        );
        return $ok ? 0 : 1;
    }

    private static function render(
        LiteConfig $cfg,
        ?LiteMonitor $mon,
        stdClass $state,
        array $runtime,
        array $health,
        string $notice,
        bool $ok,
        ?array $control = null
    ): int {
        header('Content-Type: text/html; charset=utf-8');
        $h = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $nas = $health['ready_to_start'] ? 'LOCAL PASS · 시작 시 CLI/통신 재검사' : 'BLOCK ' . implode(',', (array) $health['blockers']);
        $final = $mon ? $mon->finalSaveStatus(): ['status' => 'unknown', 'last_local_receipt_verified' => false];
        $finalText = $final['last_local_receipt_verified'] ? '마지막 저장 영수증 검증 · ' . (string)($final['saved_at'] ?? ''): ((string)($final['status']
            ?? 'unknown'));
        $alive = $mon ? $mon->daemonLiveness(): ['status' => 'unknown'];
        $identity = lm_get($state, 'run_identity');
        $daemonText = $identity ? (
            (string) lm_get($identity, 'build', 'UNKNOWN') . ' · PID ' . (string) lm_get($identity, 'pid', 'UNKNOWN')
                . ' · 시작 ' . (string) lm_get($identity, 'started_at', 'UNKNOWN')
        ): 'UNKNOWN · 새 데몬 시작 후 확인';
        $mem = lm_get($state, 'last_memory');
        $memText = '관측 없음';
        if ($mem) {
            $room = lm_get($mem, 'php_memory_headroom_bytes');
            $memText = (string) lm_get($mem, 'observed_at', '시각 미확인') . ' / ' . (string) lm_get($mem, 'process_sapi', '미확인')
                . ' · 사용 ' . round((int) lm_get($mem, 'php_memory_used_bytes', 0) / 1048576, 1) . ' MiB · 최대 ' . round((int) lm_get($mem, 'php_memory_peak_bytes', 0) / 1048576, 1)
                . ' MiB · 여유 ' . ($room === null ? '제한 없음' : round((int) $room / 1048576, 1) . ' MiB');
        }
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MON 2.2.3 Lite</title><style>body{font-family:system-ui;margin:24px;max-width:760px}section{border:1px solid #ddd;border-radius:12px;padding:18px;margin:14px 0}button{padding:10px 18px;margin-right:8px}.bad{color:#a00}.ok{color:#073}</style><h1>MON 2.2.3 Lite</h1><section><b>상태:</b> '
            . $h($state->phase) . ' / ' . $h($alive['status']) . ' <br><b>설치 Build:</b> ' . $h(MON_LITE_BUILD) . ' <br><b>실행 데몬:</b> '
            . $h($daemonText) . ' <br><b>최근 응답:</b> ' . $h($state->heartbeat_at) . ' <br><b>최근 정밀 스캔:</b> ' . $h($state->last_full_scan)
            . ' <br><b>다음 경량 확인:</b> ' . $h($state->next_light_check) . ' <br><b>다음 정밀 스캔:</b> ' . $h($state->next_full_scan)
            . ' <br><b>현재 오류:</b> ' . $h($state->last_error) . ' <br><b>자원 대기:</b> ' . $h(lm_json($state->resource_wait))
            . ' <br><b>이전 오류:</b> ' . $h(lm_json($state->historical_error)) . ' <br><b>진행:</b> ' . $h(lm_json($state->progress))
            . ' <br><b>PHP CLI:</b> ' . $h($cfg->phpCli) . ' <br><b>NAS 준비:</b> ' . $h($nas) . ' <br><b>Mailbox:</b> '
            . $h($state->last_mailbox_check) . ' / 오류 ' . $h($state->last_mailbox_error) . ' <br><b>데몬 메모리 관측:</b> '
            . $h($memText) . ' <br><b>참고자료 게시:</b> ' . ($state->publication_pending ? '재시도 대기' : '대기 없음') . ' / '
            . $h($state->last_publication_error) . ' <br><b>최종 결과 저장:</b> ' . $h($finalText) . ' <br><b>저장:</b> '
            . $h(lm_get($runtime, 'path', MON_LITE_RUNTIME)) . ' · ' . $h(lm_get($runtime, 'file_count', 0)) . ' files · '
            . round((int) lm_get($runtime, 'bytes', 0) / 1048576, 2) . ' MiB</section><form id="mon-controls" method="post"><button name="action" value="start">시작</button><button name="action" value="restart">재시작</button><button name="action" value="stop">중지</button><button name="action" value="scan">정밀 스캔 요청</button></form><p id="control-progress" role="status" aria-live="polite" class="'
            . ($ok ? 'ok' : 'bad') . '">' . $h($notice) . '</p><section><b>운영 원칙</b><br>5분: mailbox 확인<br>30분: 경량 확인 · 미확인 재시도: 2분<br>정밀 스캔: 평일 16:40 KST · 증분 기본 / 7일마다 220봉 전체 재동기화<br>모든 부속파일: /web/mon_runtime 고정파일<br>운영 규격: '
            . $h(MON_LITE_POLICY) . '</section>';
        try {
            $evidence = LiteRuntime::read('evidence');
            $scan = lm_array(lm_get($evidence, 'latest_scan'));
            if (is_array($scan) && isset($scan['results'])) {
                $lists = $scan['reference_lists'] ?? LiteMarketData::referenceLists($scan['results'], []);
                echo '<section><b>마지막 PHP 참고분석</b> · ' . $h($scan['at'] ?? '시각 미확인')
                    . '<p>최종 추천은 ChatGPT가 독립 결정합니다. 관찰·대기와 위험 경계는 매입 후보가 아니며 자동 주문을 뜻하지 않습니다.</p>';
                $titles = ['buy_candidates' => '매입 검토 참고', 'observations' => '관찰·대기', 'risk_warnings' => '위험 경계', 'unresolved' => '자료 미확인'];
                $countries = ['KR' => '한국', 'US' => '미국', 'JP' => '일본'];
                foreach ($titles as $bucket => $title) {
                    echo '<h3>' . $h($title) . '</h3>';
                    foreach ($countries as $cc => $label) {
                        $items = array_slice($lists[$bucket][$cc] ?? [], 0, 3);
                        echo '<b>' . $h($label) . '</b>';
                        if (!$items) { echo ' · 해당 없음<br>'; continue; }
                        echo '<ul>';
                        foreach ($items as $item) {
                            echo '<li>' . $h(($item['name'] ?? '') . ' (' . ($item['symbol_id'] ?? '') . ')') . ' · '
                                . $h(($item['ms7']['code'] ?? 'W') . ' ' . ($item['ms7']['label'] ?? '대기')) . ' · '
                                . $h(LiteMarketData::referenceStateLabel($item));
                            $setup = $item['reference_setup'] ?? null;
                            if (is_array($setup) && ($setup['status'] ?? '') === 'invalidated') echo '<br>' . $h('이전 가설 실패 '
                                . ($setup['invalidated_at'] ?? '') . ' · 고정 지지 ' . ($setup['invalidated_support'] ?? ''));
                            elseif (is_array($setup) && ($setup['status'] ?? '') === 'unverified') echo '<br>' . $h('이전 가설 검증 미완료 · 최초 확인 '
                                . ($setup['first_guard_unverified_at'] ?? '') . ' · 실제 실패 시점 미확인');
                            elseif (is_array($setup) && ($setup['previous_guard_unverified'] ?? false)) echo '<br>' . $h('이전 가설 실패 시점 미확인 · 새 신호 '
                                . ($setup['signal_date'] ?? '') . '로 독립 재평가');
                            elseif (is_array($setup) && isset($setup['rearmed_after'])) echo '<br>' . $h('이전 실패 '
                                . $setup['rearmed_after'] . ' · 당시 지지 ' . ($setup['previous_invalidated_support'] ?? '')
                                . ' · 새 신호 ' . ($setup['signal_date'] ?? '') . '로 재평가');
                            echo '</li>';
                        }
                        echo '</ul>';
                    }
                }
                echo '</section>';
            }
        } catch (Throwable $ignored) {
            echo '<section>참고분석 표시 자료를 확인하지 못했습니다. 마지막 저장 결과와 상태를 확인해 주세요.</section>';
        }
        if ($control && !empty ($control['operation_id'])) {
            if (in_array($control['phase'] ?? '', ['ready', 'stopping', 'starting'], true)) echo '<form method="post"><input type="hidden" name="action" value="continue"><input type="hidden" name="operation_id" value="'
                . $h($control['operation_id']) . '"><input type="hidden" name="operation_seq" value="' . (int) $control['operation_seq']
                . '"><button>상태 확인 및 재시작 계속</button></form>';
        }
        echo <<<'MON_CONTROL_SCRIPT'
<script>
(() => {
    const form = document.getElementById('mon-controls');
    const progress = document.getElementById('control-progress');
    if (!form || !progress || !window.fetch) return;
    let generation = 0;
    let busy = false;
    const buttons = Array.from(form.querySelectorAll('button'));
    const show = value => { progress.textContent = value; };
    const enable = value => {
        busy = !value;
        buttons.forEach(button => { button.disabled = !value && button.value !== 'stop'; });
    };
    const endpoint = new URL(window.location.href);
    endpoint.search = '?view=control';
    const command = async data => {
        const response = await fetch(endpoint.toString(), {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        });
        return (await response.json()).control;
    };
    const poll = async (id, sequence, token) => {
        const deadline = Date.now() + 180000;
        while (token === generation && Date.now() < deadline) {
            const url = new URL(endpoint.toString());
            if (id) {
                url.searchParams.set('operation_id', id);
                url.searchParams.set('operation_seq', String(sequence));
            }
            let state = (await (await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })).json()).control;
            if (token !== generation) return;
            show(state.message || state.phase);
            if (state.phase === 'ready' && id) {
                state = await command({ action: 'continue', operation_id: id, operation_seq: String(sequence) });
                if (token !== generation) return;
                show(state.message || state.phase);
            }
            if (['confirmed', 'failed', 'expired', 'cancelled', 'stopped'].includes(state.phase)) return;
            await new Promise(resolve => setTimeout(resolve, 2000));
        }
        if (token === generation) show('확인 시간이 지났습니다. 상태를 새로고침한 뒤 다시 요청해 주세요.');
    };
    form.addEventListener('submit', async event => {
        const button = event.submitter || document.activeElement;
        const action = button && button.value;
        if (!action || (busy && action !== 'stop')) { event.preventDefault(); return; }
        event.preventDefault();
        const token = ++generation;
        enable(false);
        show(action === 'stop' ? '중지를 요청하고 있습니다.' : '요청을 확인하고 있습니다.');
        try {
            const state = await command({ action });
            if (token !== generation) return;
            show(state.message || state.phase);
            if (state.operation_id && !['failed', 'cancelled', 'expired', 'confirmed'].includes(state.phase)) {
                await poll(state.operation_id, state.operation_seq, token);
            } else if (action === 'stop' && state.phase === 'stopping') {
                await poll('', 0, token);
            }
        } catch (error) {
            if (token === generation) show('응답을 확인하지 못했습니다. 상태를 새로고침해 실제 실행 상태를 확인해 주세요.');
        } finally {
            if (token === generation) enable(true);
        }
    });
})();
</script>
MON_CONTROL_SCRIPT;
        return 0;
    }
}

function lm_web(): int {
    return LiteWebController::run();
}

final class LiteEntrypoint {

    public static function run(array $arguments): int {
        $controlId = null;
        $controlSeq = null;
        foreach ($arguments as $argument) {
            if (strpos($argument, '--control-id=') === 0) $controlId = substr($argument, 13);
            if (strpos($argument, '--control-seq=') === 0) $controlSeq = (int) substr($argument, 14);
        }
        try {
            if (PHP_SAPI !== 'cli') return LiteWebController::run();
            if (in_array('--run', $arguments, true)) {
                (new LiteMonitor())->loop($controlId, $controlSeq);
            } else {
                echo lm_json(
                    [
                        'version' => MON_LITE_VERSION,
                        'build' => MON_LITE_BUILD,
                        'policy' => MON_LITE_POLICY,
                        'runtime' => LiteRuntime::view()
                    ]
                ) . "\n";
            }
            return 0;
        } catch (Throwable $error) {
            $code = $error instanceof LiteMonFault ? $error->faultCode : 'MON_LITE_FATAL';
            if ($controlId !== null && $controlSeq !== null) {
                try {
                    LiteStateStore::failControl($controlId, $controlSeq, $code);
                } catch (Throwable $ignored) {
                }
            }
            try {
                LiteRuntime::log('fatal', ['code' => $code]);
            } catch (Throwable $ignored) {
            }
            if (PHP_SAPI === 'cli') fwrite(STDERR, $code . "\n");
            else {
                http_response_code(500);
                echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
            }
            return 1;
        }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit (LiteEntrypoint::run($argv ?? []));
}

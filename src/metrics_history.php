<?php
declare(strict_types=1);
/**
 * NYX compact metrics history — ~15min samples, 30-day retention, low disk.
 * Format per relay: list of [t_min, latency_ms|null, status:0|1|2]
 * t_min = unix_minutes (time()/60)
 */
if (!defined('DATA_DIR')) return;

const NYX_METRICS_FILE = 'metrics/history.json';
const NYX_METRICS_INTERVAL_MIN = 15;   // sample at most every 15 minutes
const NYX_METRICS_RETENTION_DAYS = 30;
const NYX_METRICS_MAX_POINTS = 2880;   // 30d * 24h * 4 (15-min)

function metrics_path(): string {
    $dir = DATA_DIR . '/metrics';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/history.json';
}

function metrics_load(): array {
    $p = metrics_path();
    if (!is_file($p)) return ['v' => 1, 'last_ms' => 0, 'relays' => []];
    $j = @json_decode((string)@file_get_contents($p), true);
    if (!is_array($j)) return ['v' => 1, 'last_ms' => 0, 'relays' => []];
    if (!isset($j['relays']) || !is_array($j['relays'])) $j['relays'] = [];
    return $j;
}

function metrics_save(array $store): void {
    $p = metrics_path();
    $tmp = $p . '.tmp';
    $json = json_encode($store, JSON_UNESCAPED_SLASHES);
    if ($json === false) return;
    // atomic-ish write
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $p);
    }
}

function metrics_status_code(string $st): int {
    if ($st === 'ok') return 0;
    if ($st === 'warn') return 1;
    return 2;
}

function metrics_status_from_code(int $c): string {
    return $c === 0 ? 'ok' : ($c === 1 ? 'warn' : 'crit');
}

/** Prune samples older than retention; keep max points */
function metrics_prune_list(array $samples, int $nowMin): array {
    $cut = $nowMin - (NYX_METRICS_RETENTION_DAYS * 24 * 60);
    $out = [];
    foreach ($samples as $s) {
        if (!is_array($s) || count($s) < 3) continue;
        $t = (int)$s[0];
        if ($t >= $cut) $out[] = [(int)$s[0], $s[1] === null ? null : (int)$s[1], (int)$s[2]];
    }
    if (count($out) > NYX_METRICS_MAX_POINTS) {
        $out = array_slice($out, -NYX_METRICS_MAX_POINTS);
    }
    return $out;
}

/**
 * Sample current relay snapshot if interval elapsed; always enrich with history arrays.
 * Mutates $relays in place.
 */
function metrics_sample_and_enrich(array &$relays, int $nowMs): void {
    $store = metrics_load();
    $nowMin = (int)floor($nowMs / 60000);
    $lastMs = (int)($store['last_ms'] ?? 0);
    $shouldSample = ($nowMs - $lastMs) >= (NYX_METRICS_INTERVAL_MIN * 60 * 1000);

    if ($shouldSample) {
        foreach ($relays as $r) {
            $id = (string)($r['id'] ?? '');
            if ($id === '') continue;
            if (!isset($store['relays'][$id]) || !is_array($store['relays'][$id])) {
                $store['relays'][$id] = [];
            }
            $lat = isset($r['latency_ms']) && $r['latency_ms'] !== null ? (int)$r['latency_ms'] : null;
            $st = metrics_status_code((string)($r['status'] ?? 'crit'));
            $list = $store['relays'][$id];
            // avoid duplicate same-minute samples
            $n = count($list);
            if ($n > 0 && (int)$list[$n - 1][0] === $nowMin) {
                $list[$n - 1] = [$nowMin, $lat, $st];
            } else {
                $list[] = [$nowMin, $lat, $st];
            }
            $store['relays'][$id] = metrics_prune_list($list, $nowMin);
        }
        // prune relays removed from topology
        $alive = [];
        foreach ($relays as $r) {
            $id = (string)($r['id'] ?? '');
            if ($id !== '') $alive[$id] = true;
        }
        foreach (array_keys($store['relays']) as $rid) {
            if (!isset($alive[$rid])) unset($store['relays'][$rid]);
        }
        $store['last_ms'] = $nowMs;
        $store['v'] = 1;
        metrics_save($store);
    }

    // Enrich each relay with history for UI
    foreach ($relays as &$r) {
        $id = (string)($r['id'] ?? '');
        $samples = ($id !== '' && isset($store['relays'][$id])) ? $store['relays'][$id] : [];
        if (!is_array($samples)) $samples = [];

        // latency_history: last 24h as {t, v} for charts (ms timestamps)
        $latHist = [];
        $cut24 = $nowMin - (24 * 60);
        foreach ($samples as $s) {
            if ((int)$s[0] < $cut24) continue;
            if ($s[1] === null) continue;
            $latHist[] = ['t' => ((int)$s[0]) * 60000, 'v' => (int)$s[1]];
        }
        // if empty but we have current latency, seed one point
        if (!$latHist && isset($r['latency_ms']) && $r['latency_ms'] !== null) {
            $latHist[] = ['t' => $nowMs, 'v' => (int)$r['latency_ms']];
        }
        $r['latency_history'] = $latHist;

        // uptime_history: last 90 day-status buckets for calendar (ok/warn/crit strings)
        // Build from samples: one status per day (worst of day)
        $byDay = [];
        foreach ($samples as $s) {
            $day = (int)floor(((int)$s[0]) / (24 * 60));
            $code = (int)$s[2];
            if (!isset($byDay[$day]) || $code > $byDay[$day]) $byDay[$day] = $code;
        }
        $uptimeHist = [];
        $today = (int)floor($nowMin / (24 * 60));
        for ($i = 89; $i >= 0; $i--) {
            $d = $today - $i;
            if (isset($byDay[$d])) {
                $uptimeHist[] = metrics_status_from_code($byDay[$d]);
            } else {
                // no data: assume ok if currently ok, else unknown-ish as ok for sparse history
                $uptimeHist[] = ($r['status'] ?? 'ok') === 'crit' ? 'crit' : 'ok';
            }
        }
        $r['uptime_history'] = $uptimeHist;

        // recompute uptime percents from real samples when enough data
        $dayCodes = array_values($byDay);
        if (count($dayCodes) >= 1) {
            $okN = count(array_filter($dayCodes, fn($c) => $c === 0));
            $pct = round(100.0 * $okN / max(1, count($dayCodes)), 2);
            // blend with probe for short history
            if (count($dayCodes) >= 3) {
                $r['uptime_percent'] = $pct;
                $r['uptime_30d'] = $pct;
            }
        }
        // 24h uptime from samples in last 24h
        $ok24 = 0; $n24 = 0;
        foreach ($samples as $s) {
            if ((int)$s[0] < $cut24) continue;
            $n24++;
            if ((int)$s[2] === 0) $ok24++;
        }
        if ($n24 >= 2) {
            $r['uptime_24h'] = round(100.0 * $ok24 / $n24, 2);
        }
        // success_rate approx from non-crit ratio last 24h
        if ($n24 >= 2) {
            $r['success_rate'] = round(100.0 * $ok24 / $n24, 1);
        }
    }
    unset($r);
}

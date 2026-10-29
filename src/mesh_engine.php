<?php
// NYX self-organizing mesh engine 1.30
if (!defined('DATA_DIR')) return;
if (!defined('MESH_MAX_FRIENDS')) define('MESH_MAX_FRIENDS', 6);
if (!defined('MESH_FAIL_REPLACE')) define('MESH_FAIL_REPLACE', 3);
if (!defined('MESH_FOF_MAX')) define('MESH_FOF_MAX', 24);
if (!defined('MESH_SEEDS')) define('MESH_SEEDS', ["https://acebet90.co/nyx", "https://hotbet.center/nyx", "https://hotbet.live/nyx", "https://enfejar.center/nyx", "https://amnbet.org/nyx", "https://skgonline.info/nyx", "https://amoozeshpoker.org/nyx", "https://ace-bet.net/nyx", "https://shirbetreview.com/nyx", "https://aisaneslami.net/nyx"]);
if (!defined('MAX_PEERS')) define('MAX_PEERS', 32);

function mesh_peer_id(string $baseUrl): string {
    return 'peer_' . substr(hash('sha256', strtolower(rtrim($baseUrl, '/'))), 0, 12);
}
function mesh_normalize_url(string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
    return rtrim($u, '/');
}
function mesh_self_base(): string {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    return ($https ? 'https://' : 'http://') . $host . '/nyx';
}
function mesh_load_state(): array {
    $p = DATA_DIR . '/topology/mesh_state.json';
    $s = store_get($p);
    if (!is_array($s)) $s = [];
    $s += [
        'friends' => [], // id => ['base_url'=>,'status'=>,'fails'=>,'latency_ms'=>,'trust'=>,'last_ok_ms'=>]
        'known' => [],   // id => ['base_url'=>,'via'=>,'seen_ms'=>,'announce'=>]
        'replaced' => [],
        'ts_ms' => 0,
    ];
    return $s;
}
function mesh_save_state(array $s): void {
    if (!is_dir(DATA_DIR . '/topology')) @mkdir(DATA_DIR . '/topology', 0750, true);
    $s['ts_ms'] = now_ms();
    store_put(DATA_DIR . '/topology/mesh_state.json', $s);
}
function mesh_http_json(string $url, int $timeout = 5): array {
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NYX-Mesh/1.30'],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $data = null;
    if ($http >= 200 && $http < 300 && $body) {
        $j = json_decode($body, true);
        $data = is_array($j) ? ($j['data'] ?? $j) : null;
    }
    return ['ok' => is_array($data), 'http' => $http, 'ms' => $ms, 'data' => $data, 'err' => $err ?: null];
}
function mesh_probe(string $base): array {
    $base = mesh_normalize_url($base);
    if ($base === '') return ['ok' => false, 'ms' => null];
    $info = mesh_http_json($base . '/api/v3/ops/info', 5);
    $topo = mesh_http_json($base . '/api/v3/federation/topology', 5);
    $friends = [];
    $version = '';
    if ($info['ok'] && is_array($info['data'])) {
        $version = (string)($info['data']['version'] ?? '');
    }
    if ($topo['ok'] && is_array($topo['data'])) {
        foreach (($topo['data']['friends'] ?? []) as $f) {
            $bu = mesh_normalize_url((string)($f['base_url'] ?? $f['endpoint'] ?? ''));
            if ($bu !== '') $friends[] = ['base_url' => $bu, 'status' => $f['status'] ?? 'unknown', 'trust' => $f['trust'] ?? 0.5];
        }
        foreach (($topo['data']['known'] ?? []) as $k) {
            $bu = mesh_normalize_url((string)($k['base_url'] ?? ''));
            if ($bu !== '') $friends[] = ['base_url' => $bu, 'status' => 'candidate', 'trust' => 0.4];
        }
    }
    // fallback mesh peers list
    if (!$friends) {
        $mesh = mesh_http_json($base . '/api/v3/federation/mesh?probe=0', 4);
        if ($mesh['ok'] && is_array($mesh['data']['peers'] ?? null)) {
            foreach ($mesh['data']['peers'] as $p) {
                $bu = mesh_normalize_url((string)($p['base_url'] ?? ''));
                if ($bu !== '') $friends[] = ['base_url' => $bu, 'status' => $p['status'] ?? 'unknown', 'trust' => 0.45];
            }
        }
    }
    return [
        'ok' => $info['ok'],
        'ms' => $info['ms'],
        'version' => $version,
        'fof' => $friends,
        'base_url' => $base,
    ];
}
function mesh_score_candidate(array $selfGeo, string $candUrl, array $probe, float $trust): float {
    // Prefer reachable, fast, and geographically diverse (farther)
    if (empty($probe['ok'])) return 0.0;
    $ms = max(1, (int)($probe['ms'] ?? 500));
    $speed = 1.0 / (1.0 + $ms / 80.0);
    $distScore = 0.5;
    // optional geo from announce not always present
    $trust = max(0.05, min(1.0, $trust));
    return 0.45 * $speed + 0.30 * $trust + 0.25 * $distScore;
}
function mesh_ingest_known(array &$state, string $baseUrl, string $via = '', float $trustHint = 0.5): void {
    $baseUrl = mesh_normalize_url($baseUrl);
    if ($baseUrl === '' || $baseUrl === mesh_self_base()) return;
    $id = mesh_peer_id($baseUrl);
    if (!isset($state['known'][$id])) {
        $state['known'][$id] = ['base_url' => $baseUrl, 'via' => $via, 'seen_ms' => now_ms(), 'trust' => $trustHint];
    } else {
        $state['known'][$id]['seen_ms'] = now_ms();
        $state['known'][$id]['via'] = $via ?: ($state['known'][$id]['via'] ?? '');
        $state['known'][$id]['trust'] = max((float)($state['known'][$id]['trust'] ?? 0.4), $trustHint);
    }
}
function mesh_sync_from_peer_store(array &$state): void {
    $pidx = store_get(DATA_DIR . '/peers/_index.json') ?? ['ids' => []];
    foreach (($pidx['ids'] ?? []) as $pid) {
        $peer = store_get(DATA_DIR . '/peers/' . $pid . '.json');
        if (!$peer || empty($peer['base_url'])) continue;
        mesh_ingest_known($state, $peer['base_url'], 'peer_store', 0.7);
        // seed as friend candidate if under cap
        $id = mesh_peer_id($peer['base_url']);
        if (!isset($state['friends'][$id])) {
            $state['friends'][$id] = [
                'base_url' => mesh_normalize_url($peer['base_url']),
                'status' => 'pending',
                'fails' => 0,
                'latency_ms' => null,
                'trust' => 0.7,
                'last_ok_ms' => 0,
            ];
        }
    }
}
function mesh_heal_cycle(): array {
    $bootResult = null;
    $once = DATA_DIR . '/topology/auto_bootstrap_once.flag';
    // ALWAYS_BOOT real extra relays (idempotent)
    if (function_exists('mesh_bootstrap_domains')) {
        $bootResult = mesh_bootstrap_domains(['amnbet.org','skgonline.info','amoozeshpoker.org','ace-bet.net','shirbetreview.com','aisaneslami.net']);
        @file_put_contents(DATA_DIR . '/topology/auto_bootstrap_once.flag', (string)time());
    }
    $bootFile = DATA_DIR . '/topology/bootstrap_request.json';
    $bootResult = null;
    if (is_file($bootFile)) {
        $req = store_get($bootFile);
        if (is_array($req) && !empty($req['domains']) && function_exists('mesh_bootstrap_domains')) {
            $bootResult = mesh_bootstrap_domains($req['domains']);
        }
        @unlink($bootFile);
    }
    $state = mesh_load_state();
    mesh_sync_from_peer_store($state);
    // Real network seeds (not demo) — every relay learns the live core set
    foreach (MESH_SEEDS as $seedUrl) {
        mesh_ingest_known($state, $seedUrl, 'seed', 0.8);
        $sid = mesh_peer_id($seedUrl);
        if ($seedUrl !== mesh_self_base() && !isset($state['friends'][$sid])) {
            $state['friends'][$sid] = [
                'base_url' => mesh_normalize_url($seedUrl),
                'status' => 'pending',
                'fails' => 0,
                'latency_ms' => null,
                'trust' => 0.8,
                'last_ok_ms' => 0,
                'source' => 'seed',
            ];
        }
    }
    $self = mesh_self_base();
    $log = [];
    $fofPool = []; // url => best trust

    // 1) Probe existing friends
    foreach ($state['friends'] as $id => &$fr) {
        $base = mesh_normalize_url((string)($fr['base_url'] ?? ''));
        if ($base === '' || $base === $self) { unset($state['friends'][$id]); continue; }
        $pr = mesh_probe($base);
        if ($pr['ok']) {
            $fr['status'] = 'active';
            $fr['fails'] = 0;
            $fr['latency_ms'] = $pr['ms'];
            $fr['last_ok_ms'] = now_ms();
            $fr['version'] = $pr['version'] ?? '';
            $fr['trust'] = min(1.0, (float)($fr['trust'] ?? 0.5) + 0.01);
            topo_trust_boost($id, mesh_peer_id($self), 0.005);
            foreach ($pr['fof'] as $f) {
                $u = $f['base_url'];
                if ($u === $self) continue;
                mesh_ingest_known($state, $u, $base, (float)($f['trust'] ?? 0.4));
                $fofPool[$u] = max($fofPool[$u] ?? 0, (float)($f['trust'] ?? 0.4));
            }
            $log[] = ['peer' => $base, 'event' => 'ok', 'ms' => $pr['ms']];
        } else {
            $fr['fails'] = (int)($fr['fails'] ?? 0) + 1;
            $fr['status'] = $fr['fails'] >= MESH_FAIL_REPLACE ? 'down' : 'degraded';
            $fr['trust'] = max(0.05, (float)($fr['trust'] ?? 0.5) - 0.05);
            $log[] = ['peer' => $base, 'event' => 'fail', 'fails' => $fr['fails']];
        }
    }
    unset($fr);

    // 2) Drop dead friends past threshold → candidates for replacement
    $slotsFree = 0;
    foreach ($state['friends'] as $id => $fr) {
        if (($fr['status'] ?? '') === 'down' && (int)($fr['fails'] ?? 0) >= MESH_FAIL_REPLACE) {
            $state['replaced'][] = ['id' => $id, 'base_url' => $fr['base_url'], 'at_ms' => now_ms()];
            $state['replaced'] = array_slice($state['replaced'], -40);
            unset($state['friends'][$id]);
            $slotsFree++;
            $log[] = ['peer' => $fr['base_url'], 'event' => 'replaced_removed'];
        }
    }
    // Cap active friends
    $active = array_filter($state['friends'], fn($f) => in_array($f['status'] ?? '', ['active', 'degraded', 'pending'], true));
    $slotsFree += max(0, MESH_MAX_FRIENDS - count($active));

    // 3) Fill slots from FoF + known (self-drawn topology)
    if ($slotsFree > 0) {
        $candidates = [];
        foreach ($fofPool as $u => $tr) {
            $candidates[$u] = max($candidates[$u] ?? 0, $tr + 0.1); // FoF bonus
        }
        foreach ($state['known'] as $kid => $kn) {
            $u = $kn['base_url'] ?? '';
            if ($u === '' || $u === $self) continue;
            if (isset($state['friends'][mesh_peer_id($u)])) continue;
            $candidates[$u] = max($candidates[$u] ?? 0, (float)($kn['trust'] ?? 0.4));
        }
        // score candidates
        $scored = [];
        foreach ($candidates as $u => $tr) {
            if (isset($state['friends'][mesh_peer_id($u)])) continue;
            $pr = mesh_probe($u);
            $sc = mesh_score_candidate([], $u, $pr, (float)$tr);
            if ($sc <= 0) continue;
            $scored[] = ['url' => $u, 'score' => $sc, 'probe' => $pr, 'trust' => $tr];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $added = 0;
        foreach ($scored as $cand) {
            if ($added >= $slotsFree) break;
            if (count(array_filter($state['friends'], fn($f) => in_array($f['status'] ?? '', ['active', 'degraded', 'pending'], true))) >= MESH_MAX_FRIENDS) break;
            $id = mesh_peer_id($cand['url']);
            $state['friends'][$id] = [
                'base_url' => $cand['url'],
                'status' => !empty($cand['probe']['ok']) ? 'active' : 'pending',
                'fails' => 0,
                'latency_ms' => $cand['probe']['ms'] ?? null,
                'trust' => min(1.0, (float)$cand['trust']),
                'last_ok_ms' => !empty($cand['probe']['ok']) ? now_ms() : 0,
                'version' => $cand['probe']['version'] ?? '',
                'source' => 'fof_heal',
            ];
            // register into peers store for federation fanout if possible
            $pid = $id;
            $pidx = store_get(DATA_DIR . '/peers/_index.json') ?? ['ids' => []];
            if (!in_array($pid, $pidx['ids'] ?? [], true) && count($pidx['ids'] ?? []) < MAX_PEERS) {
                $pidx['ids'][] = $pid;
                store_put(DATA_DIR . '/peers/_index.json', $pidx);
                store_put(DATA_DIR . '/peers/' . $pid . '.json', [
                    'id' => $pid,
                    'base_url' => $cand['url'],
                    'status' => 'active',
                    'added_ms' => now_ms(),
                    'source' => 'mesh_heal_fof',
                    'trust' => $cand['trust'],
                ]);
            }
            $added++;
            $log[] = ['peer' => $cand['url'], 'event' => 'promoted_fof', 'score' => round($cand['score'], 4)];
        }
    }

    // 4) Enforce hard cap: if over MESH_MAX_FRIENDS, drop lowest trust/slowest
    $frList = [];
    foreach ($state['friends'] as $id => $fr) {
        $frList[] = ['id' => $id] + $fr;
    }
    if (count($frList) > MESH_MAX_FRIENDS) {
        usort($frList, function ($a, $b) {
            $sa = ((float)($a['trust'] ?? 0)) * (1.0 / (1.0 + ((int)($a['latency_ms'] ?? 300)) / 100.0));
            $sb = ((float)($b['trust'] ?? 0)) * (1.0 / (1.0 + ((int)($b['latency_ms'] ?? 300)) / 100.0));
            return $sa <=> $sb; // worst first
        });
        while (count($frList) > MESH_MAX_FRIENDS) {
            $drop = array_shift($frList);
            unset($state['friends'][$drop['id']]);
            $log[] = ['peer' => $drop['base_url'] ?? $drop['id'], 'event' => 'cap_evict'];
        }
    }

    mesh_save_state($state);
    $activeN = count(array_filter($state['friends'], fn($f) => ($f['status'] ?? '') === 'active'));
    return [
        'active_friends' => $activeN,
        'friend_cap' => MESH_MAX_FRIENDS,
        'known' => count($state['known']),
        'events' => $log,
        'friends' => array_values(array_map(function ($id, $f) {
            return ['id' => $id] + $f;
        }, array_keys($state['friends']), $state['friends'])),
        'host_domains' => function_exists('mesh_list_host_domains') ? mesh_list_host_domains() : [],
        'bootstrap' => $bootResult,
    ];
}
function mesh_export_topology(): array {
    $state = mesh_load_state();
    $self = mesh_self_base();
    $friends = [];
    foreach ($state['friends'] as $id => $f) {
        if (($f['status'] ?? '') === 'down') continue;
        $friends[] = [
            'id' => $id,
            'base_url' => $f['base_url'] ?? '',
            'status' => $f['status'] ?? 'unknown',
            'latency_ms' => $f['latency_ms'] ?? null,
            'trust' => round((float)($f['trust'] ?? 0.5), 3),
            'version' => $f['version'] ?? '',
        ];
    }
    $known = [];
    $i = 0;
    foreach ($state['known'] as $id => $k) {
        if ($i++ >= MESH_FOF_MAX) break;
        $known[] = ['id' => $id, 'base_url' => $k['base_url'] ?? '', 'via' => $k['via'] ?? '', 'trust' => $k['trust'] ?? 0.4];
    }
    return [
        'self' => $self,
        'version' => defined('NYX_VERSION') ? NYX_VERSION : '1.30.0-testnet',
        'max_friends' => MESH_MAX_FRIENDS,
        'friends' => $friends,
        'known' => $known,
        'algorithm' => 'self_heal_fof_trust_speed',
        'ts_ms' => $state['ts_ms'] ?? now_ms(),
    ];
}


function mesh_list_host_domains(): array {
    $root = '/home/enfejarc/domains';
    $out = [];
    if (!is_dir($root)) return $out;
    foreach (scandir($root) as $d) {
        if ($d === '.' || $d === '..' || !is_dir("$root/$d")) continue;
        $ph = null;
        foreach (['public_html', 'public', 'httpdocs', 'www'] as $w) {
            if (is_dir("$root/$d/$w")) { $ph = "$root/$d/$w"; break; }
        }
        $nyx = $ph ? "$ph/nyx" : null;
        $out[] = [
            'domain' => $d,
            'public_html' => $ph,
            'nyx_exists' => $nyx && is_dir($nyx),
            'nyx_index' => $nyx && is_file("$nyx/index.php"),
            'nyx_bytes' => ($nyx && is_file("$nyx/index.php")) ? (int)filesize("$nyx/index.php") : 0,
        ];
    }
    return $out;
}


function mesh_bootstrap_domains(array $domains): array {
    $src = __DIR__;
    $copyFiles = ['index.php', 'status.html', 'client.html', 'landing.html', 'identity.html', 'mesh_engine.php', '.htaccess'];
    $ht = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /nyx/\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^ index.php [L]\n</IfModule>\n";
    $out = [];
    foreach ($domains as $dom) {
        $dom = preg_replace('/[^a-z0-9.\\-]/i', '', (string)$dom);
        if ($dom === '') continue;
        $base = '/home/enfejarc/domains/' . $dom;
        $ph = null;
        foreach (['public_html', 'public', 'httpdocs', 'www'] as $w) {
            if (is_dir("$base/$w")) { $ph = "$base/$w"; break; }
        }
        if (!$ph) { $out[] = ['domain' => $dom, 'error' => 'no_public_html']; continue; }
        $dst = "$ph/nyx";
        if (!is_dir($dst)) @mkdir($dst, 0755, true);
        $copied = [];
        foreach ($copyFiles as $f) {
            if (is_file("$src/$f") && @copy("$src/$f", "$dst/$f")) $copied[] = $f;
        }
        @file_put_contents("$dst/.htaccess", $ht);
        // protect /nyx from parent SPA rewrite
        $parentHt = "$ph/.htaccess";
        $marker = '# NYX-RELAY-BYPASS';
        $rule = "$marker\nRewriteRule ^nyx($|/) - [L]\n";
        if (is_file($parentHt)) {
            $cur = @file_get_contents($parentHt) ?: '';
            if (strpos($cur, $marker) === false) {
                // prepend after RewriteEngine On if present
                if (preg_match('/RewriteEngine\s+On/i', $cur)) {
                    $cur = preg_replace('/RewriteEngine\s+On/i', "RewriteEngine On\n$rule", $cur, 1);
                } else {
                    $cur = "RewriteEngine On\n$rule\n" . $cur;
                }
                @file_put_contents($parentHt, $cur);
            }
        } else {
            @file_put_contents($parentHt, "RewriteEngine On\n$rule\n");
        }
        foreach (['data','data/users','data/devices','data/prekeys','data/messages','data/spaces','data/memberships','data/invites','data/rate','data/peers','data/backups','data/topology'] as $sub) {
            if (!is_dir("$dst/$sub")) @mkdir("$dst/$sub", 0755, true);
        }
        if (is_file("$src/data/.admin_token")) @copy("$src/data/.admin_token", "$dst/data/.admin_token");
        if (is_file("$src/data/.federation_secret")) @copy("$src/data/.federation_secret", "$dst/data/.federation_secret");
        $out[] = [
            'domain' => $dom,
            'path' => $dst,
            'copied' => $copied,
            'index_ok' => is_file("$dst/index.php"),
            'htaccess_ok' => is_file("$dst/.htaccess"),
            'parent_bypass' => is_file($parentHt) && strpos((string)@file_get_contents($parentHt), $marker) !== false,
        ];
    }
    return $out;
}


function mesh_build_incidents(array $relays, array $links, int $now): array {
    $incs = [];
    foreach ($relays as $r) {
        $st = $r['status'] ?? '';
        if ($st === 'crit') $incs[] = ['id'=>'inc_crit_'.$r['id'],'time'=>$now,'sev'=>'crit','title'=>'Relay unreachable','relay'=>$r['name']??$r['id'],'detail'=>$r['status_reason']??'Probe failed','resolved'=>false];
        elseif ($st === 'warn') $incs[] = ['id'=>'inc_warn_'.$r['id'],'time'=>$now,'sev'=>'warn','title'=>'Relay degraded','relay'=>$r['name']??$r['id'],'detail'=>$r['status_reason']??'Degraded','resolved'=>false];
        $lat = $r['latency_ms'] ?? null;
        if ($lat !== null && (int)$lat > 900) $incs[] = ['id'=>'inc_lat_'.$r['id'],'time'=>$now,'sev'=>'warn','title'=>'High latency '.$lat.'ms','relay'=>$r['name']??$r['id'],'detail'=>'Probe latency above 900ms','resolved'=>false];
    }
    foreach ($links as $L) {
        if (($L['status'] ?? '') === 'crit') $incs[] = ['id'=>'inc_link_'.($L['a']??'').'_'.($L['b']??''),'time'=>$now,'sev'=>'crit','title'=>'Friend link down','relay'=>($L['a']??'?').' ↔ '.($L['b']??'?'),'detail'=>'trust='.($L['trust']??'?'),'resolved'=>false];
    }
    if (function_exists('mesh_load_state')) {
        $st = mesh_load_state();
        foreach (array_slice($st['replaced'] ?? [], -6) as $rep) {
            $incs[] = ['id'=>'inc_repl_'.md5(json_encode($rep)),'time'=>(int)($rep['at_ms']??$now),'sev'=>'warn','title'=>'Friend replaced','relay'=>(string)($rep['base_url']??'peer'),'detail'=>'Slot refilled from FoF','resolved'=>true];
        }
        foreach ($st['friends'] ?? [] as $fid=>$fr) {
            if (($fr['status']??'')==='degraded') $incs[] = ['id'=>'inc_deg_'.$fid,'time'=>$now,'sev'=>'warn','title'=>'Friend degraded','relay'=>(string)($fr['base_url']??$fid),'detail'=>'fails='.(int)($fr['fails']??0),'resolved'=>false];
        }
    }
    if (!$incs) {
        $okN = count(array_filter($relays, fn($r)=>($r['status']??'')==='ok'));
        $incs[] = ['id'=>'inc_ok_'.$now,'time'=>$now,'sev'=>'ok','title'=>'Network nominal','relay'=>'mesh','detail'=>$okN.' relays healthy · no active faults','resolved'=>true];
    }
    usort($incs, fn($a,$b)=>($b['time']<=>$a['time']));
    return array_slice($incs, 0, 40);
}

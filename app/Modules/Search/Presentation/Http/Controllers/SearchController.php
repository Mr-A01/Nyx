<?php
declare(strict_types=1);
namespace Nyx\Modules\Search\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;

final class SearchController {
    public function messages(Request $request): JsonResponse {
        $q = trim((string)($request->query('q') ?? ($request->json()['q'] ?? '')));
        $convId = (string)($request->query('conv_id') ?? ($request->json()['conv_id'] ?? ''));
        if ($q === '' || strlen($q) < 2) return ApiEnvelope::error('q min length 2', 422);
        $s = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $qLower = strtolower($q);
        $hits = [];
        $convs = $convId !== '' ? [$convId] : [];
        if ($convs === []) {
            $root = $s->root() . '/messages';
            if (is_dir($root)) {
                foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $d) $convs[] = basename($d);
            }
        }
        foreach (array_slice($convs, 0, 50) as $cid) {
            $idx = $s->get("messages/{$cid}/_index.json") ?? ['ids' => []];
            foreach (($idx['ids'] ?? []) as $id) {
                $m = $s->get("messages/{$cid}/{$id}.json");
                if (!is_array($m) || !empty($m['deleted'])) continue;
                $body = strtolower((string)($m['body'] ?? ''));
                if ($body !== '' && str_contains($body, $qLower)) {
                    $hits[] = ['id' => $m['id'], 'conv_id' => $cid, 'snippet' => substr((string)$m['body'], 0, 120), 'created_ms' => $m['created_ms'] ?? null];
                    if (count($hits) >= 40) break 2;
                }
            }
        }
        return ApiEnvelope::ok(['q' => $q, 'hits' => $hits, 'count' => count($hits)]);
    }

    public function users(Request $request): JsonResponse {
        $q = trim((string)($request->query('q') ?? ($request->json()['q'] ?? '')));
        $limit = max(1, min(50, (int)($request->query('limit') ?? ($request->json()['limit'] ?? 20))));
        $federate = !in_array(strtolower((string)($request->query('federate') ?? '1')), ['0','false','no'], true);
        /*nyx-search-v2*/
        if ($q === '') return ApiEnvelope::error('q required', 422);
        $qLower = strtolower($q);
        $items = $this->searchLocalUsers($qLower, $limit);
        $sources = ['local'];
        if ($federate && count($items) < $limit) {
            $remote = $this->searchRemoteUsers($qLower, $limit - count($items));
            if ($remote) {
                $seen = [];
                foreach ($items as $it) $seen[strtolower((string)($it['username'] ?? $it['id'] ?? ''))] = true;
                foreach ($remote as $it) {
                    $k = strtolower((string)($it['username'] ?? $it['id'] ?? ''));
                    if ($k === '' || isset($seen[$k])) continue;
                    $seen[$k] = true;
                    $items[] = $it;
                    if (count($items) >= $limit) break;
                }
                $sources[] = 'federation';
            }
        }
        return ApiEnvelope::ok([
            'q' => $q,
            'items' => $items,
            'users' => $items,
            'count' => count($items),
            'sources' => $sources,
        ]);
    }

    public function spaces(Request $request): JsonResponse {
        $q = trim((string)($request->query('q') ?? ($request->json()['q'] ?? '')));
        $limit = max(1, min(50, (int)($request->query('limit') ?? 20)));
        if ($q === '') return ApiEnvelope::error('q required', 422);
        $qLower = strtolower($q);
        $s = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $root = $s->root() . '/spaces';
        $items = [];
        if (is_dir($root)) {
            foreach (glob($root . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (!is_array($rec)) continue;
                $name = strtolower((string)($rec['name'] ?? ''));
                $sid = strtolower((string)($rec['space_id'] ?? $rec['id'] ?? ''));
                if ($name === '' && $sid === '') continue;
                if (str_contains($name, $qLower) || str_contains($sid, $qLower)) {
                    $items[] = [
                        'id' => $rec['space_id'] ?? $rec['id'] ?? basename($f, '.json'),
                        'space_id' => $rec['space_id'] ?? $rec['id'] ?? basename($f, '.json'),
                        'name' => $rec['name'] ?? '',
                        'type' => $rec['type'] ?? 'group',
                        'owner_id' => $rec['owner_id'] ?? '',
                        'public' => !empty($rec['is_public']) || !empty($rec['public']),
                        'created_ms' => $rec['created_ms'] ?? null,
                    ];
                    if (count($items) >= $limit) break;
                }
            }
        }
        return ApiEnvelope::ok(['q' => $q, 'items' => $items, 'spaces' => $items, 'count' => count($items)]);
    }

    public function discover(Request $request): JsonResponse {
        $limit = max(1, min(50, (int)($request->query('limit') ?? 20)));
        $s = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $root = $s->root() . '/spaces';
        $items = [];
        if (is_dir($root)) {
            $files = glob($root . '/*.json') ?: [];
            usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
            foreach ($files as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (!is_array($rec)) continue;
                $public = !empty($rec['is_public']) || !empty($rec['public']) || (($rec['type'] ?? '') === 'channel');
                if (!$public) continue;
                $items[] = [
                    'id' => $rec['space_id'] ?? $rec['id'] ?? basename($f, '.json'),
                    'space_id' => $rec['space_id'] ?? $rec['id'] ?? basename($f, '.json'),
                    'name' => $rec['name'] ?? '',
                    'type' => $rec['type'] ?? 'channel',
                    'owner_id' => $rec['owner_id'] ?? '',
                    'public' => true,
                    'created_ms' => $rec['created_ms'] ?? null,
                ];
                if (count($items) >= $limit) break;
            }
        }
        return ApiEnvelope::ok(['items' => $items, 'spaces' => $items, 'count' => count($items)]);
    }

    private function searchLocalUsers(string $qLower, int $limit): array {
        $items = [];
        $data = defined('DATA_DIR') ? DATA_DIR : '';
        $dirs = [
            rtrim($data, '/') . '/identity/usernames',
            rtrim($data, '/') . '/users',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (!is_array($rec)) continue;
                $username = strtolower((string)($rec['username'] ?? basename($f, '.json')));
                $display = strtolower((string)($rec['display_name'] ?? $rec['displayName'] ?? ''));
                if ($username === '' ) continue;
                $prefix = str_starts_with($username, $qLower) || ($display !== '' && str_starts_with($display, $qLower));
                if (str_contains($username, $qLower) || ($display !== '' && str_contains($display, $qLower))) {
                    $items[] = [ 'rank' => $prefix ? 0 : 1,
                        'id' => $rec['user_id'] ?? $username,
                        'user_id' => $rec['user_id'] ?? $username,
                        'username' => $rec['username'] ?? $username,
                        'displayName' => $rec['display_name'] ?? $rec['displayName'] ?? ($rec['username'] ?? $username),
                        'display_name' => $rec['display_name'] ?? $rec['displayName'] ?? ($rec['username'] ?? $username),
                        'source' => 'local',
                    ];
                    if (count($items) >= $limit) usort($items, static fn($a, $b) => ($a['rank'] ?? 1) <=> ($b['rank'] ?? 1));
        return $this->uniqueUsers($items);
                }
            }
        }
        usort($items, static fn($a, $b) => ($a['rank'] ?? 1) <=> ($b['rank'] ?? 1));
        return $this->uniqueUsers($items);
    }

    private function uniqueUsers(array $items): array {
        $seen = []; $out = [];
        foreach ($items as $it) {
            $k = strtolower((string)($it['username'] ?? $it['id'] ?? ''));
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true; $out[] = $it;
        }
        return $out;
    }

    private function searchRemoteUsers(string $qLower, int $limit): array {
        if ($limit <= 0) return [];
        $data = defined('DATA_DIR') ? DATA_DIR : '';
        $peerFile = rtrim($data, '/') . '/peers/_index.json';
        $urls = [];
        if (is_file($peerFile)) {
            $idx = json_decode((string)file_get_contents($peerFile), true);
            foreach (($idx['ids'] ?? []) as $pid) {
                $p = json_decode((string)@file_get_contents(rtrim($data,'/').'/peers/'.$pid.'.json'), true);
                if (is_array($p) && !empty($p['base_url'])) $urls[] = rtrim((string)$p['base_url'], '/');
            }
        }
        // fallback known mesh hosts from topology style endpoints
        if ($urls === []) {
            foreach (['acebet90.co','hotbet.center','hotbet.live','enfejar.center','amnbet.org','skgonline.info','amoozeshpoker.org','ace-bet.net','shirbetreview.com','aisaneslami.net'] as $h) {
                $urls[] = 'https://' . $h . '/nyx';
            }
        }
        $hostSelf = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $out = [];
        foreach (array_slice(array_unique($urls), 0, 8) as $base) {
            if ($hostSelf && str_contains(strtolower($base), $hostSelf)) continue;
            $url = $base . '/api/v3/users/search?q=' . rawurlencode($qLower) . '&limit=' . $limit . '&federate=0';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NYX-Federation-Search/1.0'],
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code < 200 || $code >= 300 || !is_string($raw) || $raw === '') continue;
            $j = json_decode($raw, true);
            $data = is_array($j) ? ($j['data'] ?? $j) : null;
            $list = is_array($data) ? ($data['items'] ?? $data['users'] ?? []) : [];
            foreach ($list as $it) {
                if (!is_array($it)) continue;
                $it['source'] = 'relay:' . parse_url($base, PHP_URL_HOST);
                $out[] = [
                    'id' => $it['user_id'] ?? $it['id'] ?? $it['username'] ?? '',
                    'user_id' => $it['user_id'] ?? $it['id'] ?? $it['username'] ?? '',
                    'username' => $it['username'] ?? '',
                    'displayName' => $it['displayName'] ?? $it['display_name'] ?? ($it['username'] ?? ''),
                    'display_name' => $it['display_name'] ?? $it['displayName'] ?? ($it['username'] ?? ''),
                    'source' => $it['source'],
                ];
                if (count($out) >= $limit) return $out;
            }
        }
        return $out;
    }

    public function globalSearch(Request $r): JsonResponse
    {
        $q = trim((string)($r->query('q') ?? (($r->json() ?? [])['q'] ?? '')));
        if ($q === '') return ApiEnvelope::error('q required', 422);
        $ql = strtolower($q);
        $store = new \Nyx\Shared\Infrastructure\Storage\FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $users = [];
        $root = $store->root() . '/users';
        // Prefer username index for speed, then capped users scan
        $idxDir = $store->root() . '/identity/usernames';
        if (is_dir($idxDir)) {
            $n = 0;
            foreach (glob($idxDir . '/*.json') ?: [] as $f) {
                if (++$n > 400) break;
                $base = strtolower(basename($f, '.json'));
                if (!str_contains($base, $ql)) continue;
                $u = json_decode((string)@file_get_contents($f), true);
                if (!is_array($u)) continue;
                $uid = strtolower((string)($u['user_id'] ?? $base));
                $users[] = [
                    'user_id' => $uid,
                    'username' => $u['username'] ?? $base,
                    'display_name' => $u['display_name'] ?? $u['displayName'] ?? ($u['username'] ?? $base),
                    'displayName' => $u['display_name'] ?? $u['displayName'] ?? ($u['username'] ?? $base),
                ];
                if (count($users) >= 20) break;
            }
        }
        if (count($users) < 5 && is_dir($root)) {
            $n = 0;
            foreach (glob($root . '/*.json') ?: [] as $f) {
                if (++$n > 300) break;
                $u = json_decode((string)@file_get_contents($f), true);
                if (!is_array($u)) continue;
                $uname = strtolower((string)($u['username'] ?? ''));
                $dname = strtolower((string)($u['display_name'] ?? $u['displayName'] ?? ''));
                $uid = strtolower((string)($u['user_id'] ?? basename($f, '.json')));
                if ($uname === '' && $dname === '') continue;
                if (str_contains($uname, $ql) || str_contains($dname, $ql) || str_contains($uid, $ql)) {
                    $users[] = [
                        'user_id' => $uid,
                        'username' => $u['username'] ?? $uid,
                        'display_name' => $u['display_name'] ?? $u['displayName'] ?? ($u['username'] ?? $uid),
                        'displayName' => $u['display_name'] ?? $u['displayName'] ?? ($u['username'] ?? $uid),
                    ];
                }
                if (count($users) >= 20) break;
            }
        }
        // unique by user_id
        $seen = [];
        $uniq = [];
        foreach ($users as $u) {
            $id = $u['user_id'] ?? '';
            if ($id === '' || isset($seen[$id])) continue;
            $seen[$id] = true;
            $uniq[] = $u;
        }
        $users = $uniq;
        $messages = [];
        try {
            $m = $this->messages($r);
            // JsonResponse body
            if (method_exists($m, 'getContent')) {
                $md = json_decode((string)$m->getContent(), true);
            } else {
                $md = null;
            }
            if (is_array($md)) {
                $messages = $md['data']['hits'] ?? $md['data']['messages'] ?? [];
            }
        } catch (\Throwable $e) {}
        $spaces = [];
        $sroot = $store->root() . '/spaces';
        if (is_dir($sroot)) {
            $n = 0;
            foreach (glob($sroot . '/*.json') ?: [] as $f) {
                if (++$n > 400) break;
                $sp = json_decode((string)@file_get_contents($f), true);
                if (!is_array($sp)) continue;
                $name = strtolower((string)($sp['name'] ?? ''));
                if ($name !== '' && str_contains($name, $ql)) {
                    $spaces[] = [
                        'id' => $sp['space_id'] ?? $sp['id'] ?? basename($f, '.json'),
                        'name' => $sp['name'] ?? '',
                        'type' => $sp['type'] ?? 'group',
                    ];
                }
                if (count($spaces) >= 20) break;
            }
        }
        return ApiEnvelope::ok([
            'q' => $q,
            'users' => $users,
            'messages' => is_array($messages) ? array_slice($messages, 0, 30) : [],
            'spaces' => $spaces,
            'counts' => [
                'users' => count($users),
                'messages' => is_array($messages) ? count($messages) : 0,
                'spaces' => count($spaces),
            ],
        ]);
    }
}

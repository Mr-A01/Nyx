<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Content-Type: application/json; charset=utf-8');
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
function m_out(array $payload, int $code = 200): never {
    http_response_code($code);
    header('X-NYX-Modular: 1.40.0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function m_ok($data = null): never { m_out(['ok'=>true,'data'=>$data,'error'=>null]); }
function m_err(string $msg, int $code=400, $extra=null): never {
    $e=['message'=>$msg]; if ($extra!==null) $e['details']=$extra;
    m_out(['ok'=>false,'data'=>null,'error'=>$e], $code);
}
function m_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $_POST;
}
function m_store_put(string $path, array $data): bool {
    global $dataDir;
    $full = $dataDir . '/' . ltrim(str_replace('..','',$path),'/');
    $dir = dirname($full);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $tmp = $full . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
    return @rename($tmp, $full);
}
function m_store_get(string $path): ?array {
    global $dataDir;
    $full = $dataDir . '/' . ltrim(str_replace('..','',$path),'/');
    if (!is_file($full)) return null;
    $j = json_decode((string)file_get_contents($full), true);
    return is_array($j) ? $j : null;
}
function m_id($v, int $max=128): ?string {
    if (!is_string($v) && !is_numeric($v)) return null;
    $s = trim((string)$v);
    if ($s==='' || strlen($s)>$max || !preg_match('/^[a-zA-Z0-9._:@\-]+$/', $s)) return null;
    return $s;
}
$path = $_GET['path'] ?? '';
if ($path === '') {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/api/v3/modular(/[^?]*)#', $uri, $m)) $path = '/api/v3/modular' . $m[1];
}
$path = '/' . trim((string)$path, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Id');
    http_response_code(204); exit;
}
if ($method==='GET' && (str_ends_with($path,'/health') || $path==='/api/v3/modular' || $path==='/')) {
    m_ok(['service'=>'nyx-modular','version'=>'1.40.0','protocol'=>3,'status'=>'ok','checks'=>[
        'sodium'=>extension_loaded('sodium'),'sqlite'=>extension_loaded('pdo_sqlite')||extension_loaded('sqlite3'),
        'storage'=>is_writable($dataDir),'relay'=>is_file(__DIR__.'/index.php'),'client'=>is_file(__DIR__.'/client.html'),'sw'=>is_file(__DIR__.'/sw.js'),
    ],'time_ms'=>(int)round(microtime(true)*1000)]);
}
if ($method==='GET' && str_ends_with($path,'/selftest')) {
    $tests=['storage_write'=>m_store_put('_selftest/ping.json',['t'=>time()]),'storage_read'=>is_array(m_store_get('_selftest/ping.json'))];
    @unlink($dataDir.'/_selftest/ping.json');
    $tests['sodium']=extension_loaded('sodium');
    if ($tests['sodium']) { $key=sodium_crypto_secretbox_keygen(); $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $c=sodium_crypto_secretbox('nyx',$n,$key); $tests['sodium_roundtrip']=sodium_crypto_secretbox_open($c,$n,$key)==='nyx'; }
    else $tests['sodium_roundtrip']=false;
    $tests['client']=is_file(__DIR__.'/client.html'); $tests['sw']=is_file(__DIR__.'/sw.js'); $tests['random']=strlen(random_bytes(16))===16;
    if (!in_array(false,$tests,true)) m_ok(['ok'=>true,'tests'=>$tests]);
    m_err('selftest_failed',500,$tests);
}
if ($method==='GET' && str_ends_with($path,'/features')) {
    m_ok(['version'=>'1.40.0','protocol'=>3,'count'=>103,'modular'=>['health','selftest','features','messages/send','presence/touch','devices/register','moderation/report','attachments/meta','privacy','metrics','identity','mesh']]);
}
if ($method==='POST' && str_contains($path,'messages/send')) {
    $b=m_body(); $conv=m_id($b['conversation_id']??null,160); $sender=m_id($b['sender_id']??null);
    $text=isset($b['text'])?substr((string)$b['text'],0,8000):''; $ct=isset($b['ciphertext'])?substr((string)$b['ciphertext'],0,350000):'';
    if(!$conv||!$sender) m_err('invalid_conversation_or_sender'); if($text===''&&$ct==='') m_err('empty_message');
    $id='msg_'.bin2hex(random_bytes(16)); $ts=(int)round(microtime(true)*1000);
    $evt=['id'=>$id,'conversation_id'=>$conv,'sender_id'=>$sender,'client_id'=>(string)($b['client_id']??''),'text'=>$text,'ciphertext'=>$ct,'created_at_ms'=>$ts];
    m_store_put('messages/'.hash('sha256',$id).'.json',$evt); m_ok(['message'=>$evt]);
}
if ($method==='POST' && str_contains($path,'presence/touch')) {
    $b=m_body(); $uid=m_id($b['user_id']??null); if(!$uid) m_err('invalid_user');
    $rec=['user_id'=>$uid,'status'=>(string)($b['status']??'online'),'ts_ms'=>(int)round(microtime(true)*1000)];
    m_store_put('presence/'.hash('sha256',$uid).'.json',$rec); m_ok($rec);
}
if ($method==='POST' && str_contains($path,'devices/register')) {
    $b=m_body(); $uid=m_id($b['user_id']??null); if(!$uid) m_err('invalid_user');
    $id='dev_'.bin2hex(random_bytes(16)); $ts=(int)round(microtime(true)*1000);
    $rec=['id'=>$id,'user_id'=>$uid,'name'=>substr((string)($b['name']??'device'),0,80),'created_at_ms'=>$ts,'last_seen_ms'=>$ts];
    m_store_put('devices/'.hash('sha256',$id).'.json',$rec); m_ok($rec);
}
if ($method==='POST' && str_contains($path,'moderation/report')) {
    $b=m_body(); $r=m_id($b['reporter_id']??null); $m=m_id($b['message_id']??null); if(!$r||!$m) m_err('invalid_input');
    $id='rpt_'.bin2hex(random_bytes(16));
    $rec=['id'=>$id,'reporter_id'=>$r,'message_id'=>$m,'reason'=>substr((string)($b['reason']??'unspecified'),0,500),'status'=>'open','created_at_ms'=>(int)round(microtime(true)*1000)];
    m_store_put('reports/'.hash('sha256',$id).'.json',$rec); m_ok($rec);
}
if ($method==='POST' && str_contains($path,'attachments/meta')) {
    $b=m_body(); $o=m_id($b['owner_id']??null); if(!$o) m_err('invalid_input');
    $id='att_'.bin2hex(random_bytes(16));
    $rec=['id'=>$id,'owner_id'=>$o,'mime'=>substr((string)($b['mime']??'application/octet-stream'),0,120),'size'=>(int)($b['size']??0),'name'=>substr((string)($b['name']??'file'),0,255),'created_at_ms'=>(int)round(microtime(true)*1000)];
    m_store_put('attachments/'.hash('sha256',$id).'.json',$rec); m_ok($rec);
}
m_err('Not Found', 404);

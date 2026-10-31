<?php
declare(strict_types=1);
/**
 * NYX Relay Installer — shared hosting / VPS
 * Open this file in the browser once, then delete it.
 */
header('Content-Type: text/html; charset=utf-8');
$base = dirname(__DIR__);
$public = __DIR__;
$steps = [];
$ok = true;

function step(string $title, bool $pass, string $detail = ''): array {
    return compact('title', 'pass', 'detail');
}

// 1) PHP version
$phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
$steps[] = step('PHP >= 8.2', $phpOk, 'Running ' . PHP_VERSION);
$ok = $ok && $phpOk;

// 2) Extensions
foreach (['json','pdo','sqlite3','sodium','curl','mbstring'] as $ext) {
    $has = extension_loaded($ext);
    $steps[] = step("ext-$ext", $has, $has ? 'loaded' : 'MISSING');
    $ok = $ok && $has;
}

// 3) Writable dirs
foreach (['storage', 'storage/data', 'storage/logs', 'storage/cache', 'data', 'bootstrap/cache'] as $dir) {
    $path = $base . '/' . $dir;
    if (!is_dir($path)) @mkdir($path, 0775, true);
    $w = is_dir($path) && is_writable($path);
    $steps[] = step("writable $dir", $w, $path);
    $ok = $ok && $w;
}

// 4) .env
$envExample = $base . '/.env.example';
$envFile = $base . '/.env';
if (!is_file($envFile) && is_file($envExample)) {
    @copy($envExample, $envFile);
}
$steps[] = step('.env present', is_file($envFile), is_file($envFile) ? 'ok' : 'copy .env.example → .env');

// 5) Front controller
$fc = is_file($public . '/index.php');
$steps[] = step('public/index.php', $fc, $fc ? 'ok' : 'missing');
$ok = $ok && $fc;

// 6) Optional write lock file
if ($ok && isset($_GET['finish'])) {
    @file_put_contents($base . '/storage/data/installed.lock', date('c') . "\n");
}

$done = is_file($base . '/storage/data/installed.lock');
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>NYX Installer</title>
<style>
body{font-family:system-ui,sans-serif;background:#0b1020;color:#e8eefc;margin:0;padding:32px}
.box{max-width:640px;margin:0 auto;background:#121a2f;border:1px solid #1e2a44;border-radius:14px;padding:24px}
h1{margin:0 0 8px;font-size:22px}.m{color:#8b9bb8;font-size:14px;margin-bottom:20px}
.row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #1e2a44;font-size:14px}
.ok{color:#3dd68c}.bad{color:#f87171}
a.btn{display:inline-block;margin-top:18px;padding:10px 16px;border-radius:10px;background:#5b8cff;color:#fff;text-decoration:none;font-weight:600}
</style></head><body><div class="box">
<h1>NYX Relay Installer</h1>
<p class="m">Checks environment for modular Protocol 3 relay. Delete <code>public/install.php</code> after success.</p>
<?php foreach ($steps as $s): ?>
<div class="row"><span><?= htmlspecialchars($s['title']) ?></span>
<span class="<?= $s['pass']?'ok':'bad' ?>"><?= $s['pass']?'OK':'FAIL' ?> · <?= htmlspecialchars($s['detail']) ?></span></div>
<?php endforeach; ?>
<?php if ($ok && !$done): ?>
<a class="btn" href="?finish=1">Mark installed & continue</a>
<?php elseif ($done): ?>
<p class="ok" style="margin-top:16px">Installed. Open <a href="index.html" style="color:#8ab4ff">index</a> · <a href="client.html" style="color:#8ab4ff">client</a> · <a href="api/v3/ops/health" style="color:#8ab4ff">health</a></p>
<p class="m">Security: remove <code>install.php</code> now.</p>
<?php else: ?>
<p class="bad" style="margin-top:16px">Fix failed checks, then reload.</p>
<?php endif; ?>
</div></body></html>

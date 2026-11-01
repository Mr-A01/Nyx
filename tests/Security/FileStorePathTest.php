<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
use Nyx\Shared\Infrastructure\Storage\FileStore;
$tmp = sys_get_temp_dir() . '/nyx_fs_' . uniqid();
mkdir($tmp, 0755, true);
$s = new FileStore($tmp);
if (!$s->put('sessions/ab12.json', ['ok' => true])) throw new Exception('put failed');
if (($s->get('sessions/ab12.json')['ok'] ?? null) !== true) throw new Exception('get failed');
if ($s->put('../etc/passwd', ['x' => 1]) !== false) throw new Exception('traversal allowed');
if ($s->put('a/../../b.json', ['x' => 1]) !== false) throw new Exception('nested traversal allowed');
echo "PASS FileStorePathTest\n";

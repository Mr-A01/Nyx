<?php
declare(strict_types=1);
define('NYX_START', microtime(true));
define('NYX_BASE', is_dir(__DIR__ . '/../app') ? dirname(__DIR__) : __DIR__);
define('NYX_VERSION', '1.45.0-scale');
define('NYX_PROTOCOL', 3);
$dataDir = is_dir(NYX_BASE . '/data') ? NYX_BASE . '/data' : NYX_BASE . '/storage/data';
define('NYX_DATA_DIR', $dataDir); define('DATA_DIR', $dataDir);
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Nyx\\')) return;
    $file = NYX_BASE . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});
use Nyx\Core\Support\Env; use Nyx\Core\Security\SecurityHeaders;
use Nyx\Core\Bootstrap\Application; use Nyx\Core\Http\Request;
Env::load(NYX_BASE); SecurityHeaders::apply();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Id, Accept');
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    http_response_code(204); exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_contains($path, '/nyx')) $path = substr($path, (int)strpos($path, '/nyx') + 4) ?: '/';
$path = '/' . trim($path, '/'); if ($path !== '/') $path = rtrim($path, '/') ?: '/';
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$wantsHtml = $method === 'GET' && $path === '/' && !isset($_GET['json']) && (str_contains($accept, 'text/html') || !str_contains($accept, 'application/json'));
if ($wantsHtml) {
    $home = __DIR__ . '/home.html';
    if (is_file($home)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        readfile($home);
        exit;
    }
}
/** @var array<string,string> $static allow-list only */
$static = [
    '/client.html' => 'client.html',
    '/status.html' => 'status.html',
    '/identity.html' => 'identity.html',
    '/home.html' => 'home.html',
];
if ($method === 'GET' && isset($static[$path])) {
    $name = basename($static[$path]); // neutralize any oddities
    if (preg_match('/^[a-z0-9._-]+$/i', $name) === 1) {
        $f = __DIR__ . DIRECTORY_SEPARATOR . $name;
        $real = realpath($f);
        $base = realpath(__DIR__);
        if ($real !== false && $base !== false && str_starts_with($real, $base) && is_file($real)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($real);
            exit;
        }
    }
}
$app = Application::configure(NYX_BASE)->withRouting(api: NYX_BASE.'/routes/api/v3/modular.php', web: null)->withMiddleware()->withExceptions()->create();
$app->handle(Request::capture())->send();

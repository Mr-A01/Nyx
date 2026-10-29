<?php
declare(strict_types=1);

namespace Nyx\Core\Support;

/**
 * Boots Protocol-3 production relay (legacy/relay.php) with full feature parity.
 * MVC shell routes modular endpoints; everything else goes through this kernel.
 */
final class LegacyRelayKernel
{
    public static function shouldHandle(string $path): bool
    {
        if ($path === '/' || $path === '') {
            return true;
        }
        if (str_starts_with($path, '/api/')) {
            return true;
        }
        return false;
    }

    public static function dispatch(string $basePath): never
    {
        if (!defined('NYX_BASE')) {
            define('NYX_BASE', $basePath);
        }

        // Prefer existing production data/ directory for zero-migration deploy
        $dataDir = is_dir($basePath . '/data')
            ? $basePath . '/data'
            : $basePath . '/storage/data';

        if (!defined('NYX_DATA_DIR')) {
            define('NYX_DATA_DIR', $dataDir);
        }
        if (!defined('DATA_DIR')) {
            define('DATA_DIR', $dataDir);
        }
        if (!defined('NYX_VERSION')) {
            define('NYX_VERSION', '1.40.0-pro');
        }
        if (!defined('NYX_PROTOCOL')) {
            define('NYX_PROTOCOL', 3);
        }

        $dirs = [
            DATA_DIR,
            DATA_DIR . '/users', DATA_DIR . '/devices', DATA_DIR . '/prekeys',
            DATA_DIR . '/messages', DATA_DIR . '/spaces', DATA_DIR . '/memberships',
            DATA_DIR . '/invites', DATA_DIR . '/rate', DATA_DIR . '/peers',
            DATA_DIR . '/backups', DATA_DIR . '/presence', DATA_DIR . '/receipts',
            DATA_DIR . '/typing', DATA_DIR . '/pins', DATA_DIR . '/reactions',
            DATA_DIR . '/mutes', DATA_DIR . '/profiles', DATA_DIR . '/notifs',
            DATA_DIR . '/blocks', DATA_DIR . '/push', DATA_DIR . '/fed_queue',
            DATA_DIR . '/identity', DATA_DIR . '/identity/sessions',
            DATA_DIR . '/topology', DATA_DIR . '/attachments', DATA_DIR . '/uploads',
        ];
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0750, true);
            }
        }

        $legacy = $basePath . '/legacy';
        $relay = $legacy . '/relay.php';
        if (!is_file($relay)) {
            // Fallback: flat layout (relay.php next to index)
            $relay = $basePath . '/relay.php';
        }
        if (!is_file($relay)) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => ['message' => 'Legacy relay missing']], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Ensure mesh/update/identity sit beside relay (__DIR__ inside relay)
        $relayDir = dirname($relay);
        foreach (['mesh_engine.php', 'update_engine.php', 'identity_api.php'] as $eng) {
            $src = $legacy . '/' . $eng;
            $dst = $relayDir . '/' . $eng;
            if ($src !== $dst && is_file($src) && !is_file($dst)) {
                @copy($src, $dst);
            }
        }

        require $relay;
        exit;
    }
}

<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Storage;

/**
 * Optional SQLite index for messages/tips.
 * SOURCE OF TRUTH remains JSON FileStore under data/.
 * Enable with MESSAGE_DB=sqlite. Failures are swallowed — never block the primary path.
 */
final class SqliteStore
{

    private static ?\PDO $pdo = null;
    private static bool $tried = false;

    public static function enabled(): bool
    {
        $flag = getenv('MESSAGE_DB') ?: '';
        return strtolower($flag) === 'sqlite' && self::pdo() !== null;
    }

    public static function pdo(): ?\PDO
    {
        if (self::$tried) {
            return self::$pdo;
        }
        self::$tried = true;
        if (!class_exists(\PDO::class) || !in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return null;
        }
        $base = defined('DATA_DIR') ? DATA_DIR : (defined('NYX_DATA_DIR') ? NYX_DATA_DIR : sys_get_temp_dir());
        $path = rtrim($base, '/').'/nyx_messages.sqlite';
        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode=WAL;');
            $pdo->exec('PRAGMA synchronous=NORMAL;');
            $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
                id TEXT NOT NULL,
                conv_id TEXT NOT NULL,
                sender_id TEXT,
                created_ms INTEGER NOT NULL,
                e2ee INTEGER DEFAULT 0,
                deleted INTEGER DEFAULT 0,
                integrity_hash TEXT,
                prev_hash TEXT,
                PRIMARY KEY (conv_id, id)
            );');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_msg_conv_ts ON messages(conv_id, created_ms);');
            $pdo->exec('CREATE TABLE IF NOT EXISTS tips (
                conv_id TEXT PRIMARY KEY,
                tip_hash TEXT,
                count INTEGER,
                last_id TEXT,
                updated_ms INTEGER
            );');
            self::$pdo = $pdo;
            return $pdo;
        } catch (\Throwable $e) {
            self::$pdo = null;
            return null;
        }
    }

    public static function indexMessage(array $m): void
    {
        $pdo = self::pdo();
        if (!$pdo) return;
        try {
            $st = $pdo->prepare('INSERT OR REPLACE INTO messages(id,conv_id,sender_id,created_ms,e2ee,deleted,integrity_hash,prev_hash) VALUES(?,?,?,?,?,?,?,?)');
            $st->execute([
                (string)($m['id'] ?? ''),
                (string)($m['conv_id'] ?? ''),
                (string)($m['sender_id'] ?? ''),
                (int)($m['created_ms'] ?? 0),
                !empty($m['e2ee']) || !empty($m['ciphertext']) ? 1 : 0,
                !empty($m['deleted']) ? 1 : 0,
                (string)($m['integrity_hash'] ?? ''),
                (string)($m['prev_hash'] ?? ''),
            ]);
        } catch (\Throwable $e) {}
    }

    public static function setTip(string $convId, array $tip): void
    {
        $pdo = self::pdo();
        if (!$pdo) return;
        try {
            $st = $pdo->prepare('INSERT OR REPLACE INTO tips(conv_id,tip_hash,count,last_id,updated_ms) VALUES(?,?,?,?,?)');
            $st->execute([
                $convId,
                (string)($tip['tip_hash'] ?? ''),
                (int)($tip['count'] ?? 0),
                (string)($tip['last_id'] ?? ''),
                (int)($tip['updated_ms'] ?? 0),
            ]);
        } catch (\Throwable $e) {}
    }

    public static function tip(string $convId): ?array
    {
        $pdo = self::pdo();
        if (!$pdo) return null;
        try {
            $st = $pdo->prepare('SELECT tip_hash,count,last_id,updated_ms FROM tips WHERE conv_id=?');
            $st->execute([$convId]);
            $row = $st->fetch();
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

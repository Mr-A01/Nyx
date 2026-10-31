<?php
declare(strict_types=1);

namespace Nyx\Core\Database;

use PDO;

final class SqliteConnection
{
    private static ?PDO $pdo = null;

    public static function pdo(string $dataDir): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0750, true);
        }
        $path = $dataDir . '/nyx.sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec('PRAGMA synchronous=NORMAL;');
        self::migrate($pdo);
        self::$pdo = $pdo;
        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
            id TEXT PRIMARY KEY,
            conv_id TEXT NOT NULL,
            sender_id TEXT,
            recipient_id TEXT,
            body TEXT,
            payload TEXT,
            created_ms INTEGER NOT NULL,
            edited_ms INTEGER,
            deleted INTEGER DEFAULT 0
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages(conv_id, created_ms)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS devices (
            device_id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            public_key TEXT,
            meta TEXT,
            created_ms INTEGER,
            touched_ms INTEGER
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS presence (
            user_id TEXT PRIMARY KEY,
            status TEXT,
            last_seen_ms INTEGER,
            meta TEXT
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS peers (
            host TEXT PRIMARY KEY,
            url TEXT,
            last_ok_ms INTEGER,
            meta TEXT
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS spaces (
            space_id TEXT PRIMARY KEY,
            name TEXT,
            owner_id TEXT,
            meta TEXT,
            created_ms INTEGER
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS memberships (
            space_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            role TEXT,
            joined_ms INTEGER,
            PRIMARY KEY (space_id, user_id)
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS identities (
            username TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            public_key TEXT,
            created_ms INTEGER
        )');
    }
}

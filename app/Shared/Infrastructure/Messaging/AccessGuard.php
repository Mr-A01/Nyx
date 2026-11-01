<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Messaging;

use Nyx\Shared\Infrastructure\Storage\FileStore;

/** Membership + space visibility helpers. */
final class AccessGuard
{
    public function __construct(private FileStore $store) {}

    public static function make(): self
    {
        $root = defined('DATA_DIR') ? DATA_DIR : (defined('NYX_DATA_DIR') ? NYX_DATA_DIR : '');
        return new self(new FileStore($root));
    }

    public function space(string $convId): ?array
    {
        $sp = $this->store->get("spaces/{$convId}.json");
        return is_array($sp) ? $sp : null;
    }

    public function isPublicSpace(?array $sp): bool
    {
        if ($sp === null) {
            return false;
        }
        if (!empty($sp['is_public']) || !empty($sp['public'])) {
            return true;
        }
        return false;
    }

    public function isMember(string $convId, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }
        $uid = strtolower($userId);
        $m = $this->store->get("memberships/{$convId}/{$userId}.json");
        if (!is_array($m)) {
            $m = $this->store->get("memberships/{$convId}/{$uid}.json");
        }
        if (is_array($m)) {
            return true;
        }
        // Scan membership folder for matching user_id / username (id format mismatches)
        $root = $this->store->root() . '/memberships/' . $convId;
        if (is_dir($root)) {
            foreach (glob($root . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)@file_get_contents($f), true);
                if (!is_array($rec)) {
                    continue;
                }
                foreach (['user_id', 'username', 'id', 'uid'] as $k) {
                    if (isset($rec[$k]) && strtolower((string)$rec[$k]) === $uid) {
                        return true;
                    }
                }
                $bn = strtolower(basename($f, '.json'));
                if ($bn === $uid) {
                    return true;
                }
            }
        }
        $sp = $this->space($convId);
        if (is_array($sp)) {
            $members = $sp['members'] ?? $sp['member_ids'] ?? [];
            if (is_array($members)) {
                foreach ($members as $x) {
                    if (strtolower((string)$x) === $uid) {
                        return true;
                    }
                }
            }
            if (strtolower((string)($sp['owner_id'] ?? '')) === $uid) {
                return true;
            }
        }
        return false;
    }

    public function canRead(string $convId, string $userId): bool
    {
        $sp = $this->space($convId);
        if ($this->isPublicSpace($sp)) {
            return $userId !== '';
        }
        return $this->isMember($convId, $userId);
    }

    public function canWrite(string $convId, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }
        $sp = $this->space($convId);
        if ($sp === null) {
            return true; // DMs / ad-hoc threads
        }
        if ($this->isPublicSpace($sp)) {
            $posting = strtolower((string)($sp['posting'] ?? 'members'));
            if ($posting === 'admins') {
                if ($this->isMember($convId, $userId)) {
                    $m = $this->store->get("memberships/{$convId}/{$userId}.json");
                    if (!is_array($m)) {
                        $m = $this->store->get("memberships/{$convId}/" . strtolower($userId) . ".json");
                    }
                    $role = is_array($m) ? strtolower((string)($m['role'] ?? 'member')) : 'member';
                    if (in_array($role, ['owner', 'admin', 'member'], true)) {
                        return true;
                    }
                }
                // Empty owner: only existing members may post (no open invite for strangers)
                return $this->isMember($convId, $userId);
            }
            return true;
        }
        return $this->isMember($convId, $userId);
    }

    public function requiresE2ee(?array $sp, string $convId): bool
    {
        if (str_starts_with($convId, 'dm_') || str_starts_with($convId, 'dm:')) {
            return true;
        }
        if ($this->isPublicSpace($sp)) {
            return false;
        }
        return $sp !== null;
    }
}

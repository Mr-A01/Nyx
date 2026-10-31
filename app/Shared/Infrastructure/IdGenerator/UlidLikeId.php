<?php
declare(strict_types=1);
namespace Nyx\Shared\Infrastructure\IdGenerator;
final class UlidLikeId {
    public static function generate(string $prefix = ''): string {
        $id = bin2hex(random_bytes(16));
        return $prefix !== '' ? $prefix . '_' . $id : $id;
    }
}

<?php
declare(strict_types=1);

namespace Nyx\Modules\Federation\Infrastructure\Mesh;

/**
 * Wraps legacy mesh functions when available; safe no-op fallbacks otherwise.
 */
final class MeshEngineAdapter
{
    public function __construct(private string $legacyDir)
    {
        $mesh = $this->legacyDir . '/mesh_engine.php';
        if (is_file($mesh) && !function_exists('mesh_heal_cycle')) {
            // Only load if DATA_DIR already defined (relay context)
            if (defined('DATA_DIR')) {
                require_once $mesh;
            }
        }
    }

    public function topology(): array
    {
        if (function_exists('mesh_export_topology')) {
            return mesh_export_topology();
        }
        return [
            'friends' => [],
            'known' => [],
            'max_friends' => 6,
            'algorithm' => 'self_heal_fof',
            'source' => 'adapter-fallback',
        ];
    }

    public function heal(): array
    {
        if (function_exists('mesh_heal_cycle')) {
            return mesh_heal_cycle();
        }
        return ['status' => 'unavailable', 'healed' => 0];
    }
}

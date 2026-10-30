<?php
define('NYX_BASE', realpath(__DIR__ . '/../../'));
require_once __DIR__ . '/../../vendor/autoload.php';

echo "=== NYX Self-Test & Verification Suite ===\n";

$requiredExtensions = ['pdo', 'mbstring', 'curl', 'json'];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        echo "[FAIL] Missing required extension: {$ext}\n";
        exit(1);
    }
}
echo "[PASS] All required PHP extensions loaded.\n";

// Test Application instance creation without full request dispatching if desired, or test Application directly
try {
    $app = Nyx\Core\Bootstrap\Application::configure(NYX_BASE);
    echo "[PASS] Application configure successful.\n";
} catch (Throwable $e) {
    echo "[FAIL] Application configure failed: " . $e->getMessage() . "\n";
    exit(1);
}

$coreClasses = [
    Nyx\Core\Bootstrap\Application::class,
    Nyx\Modules\Administration\Presentation\Http\Controllers\OpsController::class,
];
foreach ($coreClasses as $class) {
    if (!class_exists($class)) {
        echo "[FAIL] Missing core class: {$class}\n";
        exit(1);
    }
}
echo "[PASS] Core classes verified.\n";

echo "=== All Self-Tests Passed Successfully ===\n";

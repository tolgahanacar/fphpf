<?php

declare(strict_types=1);

/**
 * FPHP Cache - Standalone Autoloader & Bootstrap
 * 
 * Works out-of-the-box with zero dependencies.
 */
// Load standalone PSR fallback interfaces if not provided by external vendor
require_once __DIR__ . '/src/Adapter/PsrShims.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'Fphp\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

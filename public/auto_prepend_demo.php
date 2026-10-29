<?php

declare(strict_types=1);

/**
 * FPHP Cache - Zero-Framework Auto Prepend Demo
 * 
 * If you configure php.ini or .htaccess:
 *   php_value auto_prepend_file "c:/MAMP/htdocs/fphpf/fphp-bootstrap.php"
 * 
 * Then FphpCache::boot() runs BEFORE any index.php or WordPress/Laravel code is loaded.
 * If cache HIT, the entire framework and database are 100% bypassed, responding in microseconds!
 */

require_once dirname(__DIR__) . '/fphp-bootstrap.php';
\Fphp\FphpCache::boot();

// Simulated heavy framework bootstrap (e.g. Laravel service providers, Eloquent, blade templates)
$timeStart = hrtime(true);
usleep(18000); // 18ms bootstrap simulation

echo "<!DOCTYPE html><html><head><title>Framework Page</title></head><body>";
echo "<h1>Full Framework Executed (Cache MISS)</h1>";
echo "<p>Execution time: " . round((hrtime(true) - $timeStart) / 1000000, 2) . " ms</p>";
echo "<p>Refresh this page to see it served instantly in <strong>~120 µs</strong> via FPHP Cache!</p>";
echo "</body></html>";

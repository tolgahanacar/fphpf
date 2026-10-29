<?php

declare(strict_types=1);

/**
 * FPHP Cache - Dashboard Router
 * Seamlessly routes to the unified control center stats view
 */

if (!isset($_GET['page'])) {
    $_GET['page'] = 'stats';
}

require_once __DIR__ . '/index.php';

<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Buckie\Config\ConfigService;
use Buckie\Http\Router;

// Disable output buffering for streaming
if (ob_get_level() > 0) {
    ob_end_clean();
}

$dataDir = ConfigService::getDataDir();
$router  = new Router($dataDir);
$router->dispatch();


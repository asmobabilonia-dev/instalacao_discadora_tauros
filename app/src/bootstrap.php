<?php

declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Location: install/');
        exit;
    }
    throw new RuntimeException('Arquivo config/config.php nao encontrado. Execute o instalador web em /install/.');
}

$config = require $configPath;
date_default_timezone_set($config['timezone']);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AmiClient.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/migrations.php';

run_migrations($config);

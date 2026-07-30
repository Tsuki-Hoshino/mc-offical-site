<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/main_database.php';

$config = site_main_database_config();
return [
    'dsn' => site_main_database_dsn($config),
    'username' => (string) $config['username'],
    'password' => (string) $config['password'],
];

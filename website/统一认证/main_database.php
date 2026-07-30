<?php
declare(strict_types=1);

function site_main_database_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    $config = is_file($path) ? require $path : [];
    if (!is_array($config)) {
        $config = [];
    }

    foreach (['host', 'database', 'username', 'password'] as $key) {
        if (!isset($config[$key]) || (string) $config[$key] === '') {
            throw new RuntimeException('主站数据库尚未配置。');
        }
    }
    $config['port'] = (int) ($config['port'] ?? 3306);
    return $config;
}

function site_main_database_dsn(array $config): string
{
    return sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string) $config['host'],
        (int) $config['port'],
        (string) $config['database']
    );
}

function site_main_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = site_main_database_config();
    $pdo = new PDO(site_main_database_dsn($config), (string) $config['username'], (string) $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

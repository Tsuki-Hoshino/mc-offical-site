<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/main_database.php';

function auth_database_config(): array
{
    return site_main_database_config();
}

function auth_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = auth_database_config();
    $pdo = new PDO(site_main_database_dsn($config), (string) $config['username'], (string) $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    auth_initialize_database($pdo);
    auth_bootstrap_superadmin($pdo);
    return $pdo;
}

function auth_initialize_database(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'editor',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_users_enabled (enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            event VARCHAR(64) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            context_json TEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_audit_created (created_at),
            INDEX idx_audit_user (user_id),
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function auth_bootstrap_superadmin(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        if (is_file(BOOTSTRAP_ADMIN_FILE)) {
            @unlink(BOOTSTRAP_ADMIN_FILE);
        }
        return;
    }
    if (!is_file(BOOTSTRAP_ADMIN_FILE)) {
        return;
    }
    $record = json_decode((string) file_get_contents(BOOTSTRAP_ADMIN_FILE), true);
    if (!is_array($record) || empty($record['username']) || empty($record['password_hash'])) {
        throw new RuntimeException('超级管理员引导配置无效。');
    }
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, role, enabled, created_at, updated_at)
         VALUES (:username, :password_hash, :role, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        'username' => (string) $record['username'],
        'password_hash' => (string) $record['password_hash'],
        'role' => 'superadmin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    @unlink(BOOTSTRAP_ADMIN_FILE);
}

function auth_user_count(): int
{
    return (int) auth_db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

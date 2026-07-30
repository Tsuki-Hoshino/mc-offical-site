<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../统一认证/main_database.php';

function database_config(): array
{
    return site_main_database_config();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = database_config();
    $pdo = new PDO(site_main_database_dsn($config), (string) $config['username'], (string) $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    initialize_database($pdo);
    bootstrap_superadmin($pdo);
    migrate_legacy_sqlite($pdo);
    return $pdo;
}

function initialize_database(PDO $pdo): void
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
        "CREATE TABLE IF NOT EXISTS machines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            machine_name VARCHAR(120) NOT NULL,
            player_name VARCHAR(64) NOT NULL,
            dimension VARCHAR(20) NOT NULL,
            x DOUBLE NOT NULL,
            y DOUBLE NOT NULL,
            z DOUBLE NOT NULL,
            converted_dimension VARCHAR(20) NULL,
            converted_x DOUBLE NULL,
            converted_y DOUBLE NULL,
            converted_z DOUBLE NULL,
            photo_path VARCHAR(255) NULL,
            manual_text MEDIUMTEXT NULL,
            manual_file_path VARCHAR(255) NULL,
            notes_text MEDIUMTEXT NULL,
            notes_file_path VARCHAR(255) NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_machines_dimension (dimension),
            INDEX idx_machines_updated (updated_at),
            CONSTRAINT fk_machines_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_machines_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
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

function bootstrap_superadmin(PDO $pdo): void
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

function migrate_legacy_sqlite(PDO $mysql): void
{
    if (!is_file(LEGACY_SQLITE_PATH) || !extension_loaded('pdo_sqlite')) {
        return;
    }
    $marker = LEGACY_SQLITE_PATH . '.migrated';
    if (is_file($marker)) {
        return;
    }
    $sqlite = new PDO('sqlite:' . LEGACY_SQLITE_PATH);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $records = $sqlite->query('SELECT * FROM machines ORDER BY id')->fetchAll();
    $sql = 'INSERT INTO machines (
        id, machine_name, player_name, dimension, x, y, z,
        converted_dimension, converted_x, converted_y, converted_z,
        photo_path, manual_text, manual_file_path, notes_text, notes_file_path,
        created_at, updated_at
    ) VALUES (
        :id, :machine_name, :player_name, :dimension, :x, :y, :z,
        :converted_dimension, :converted_x, :converted_y, :converted_z,
        :photo_path, :manual_text, :manual_file_path, :notes_text, :notes_file_path,
        :created_at, :updated_at
    ) ON DUPLICATE KEY UPDATE id = VALUES(id)';
    $stmt = $mysql->prepare($sql);
    $columns = [
        'id', 'machine_name', 'player_name', 'dimension', 'x', 'y', 'z',
        'converted_dimension', 'converted_x', 'converted_y', 'converted_z',
        'photo_path', 'manual_text', 'manual_file_path', 'notes_text', 'notes_file_path',
        'created_at', 'updated_at',
    ];
    $mysql->beginTransaction();
    try {
        foreach ($records as $record) {
            $params = [];
            foreach ($columns as $column) {
                $params[$column] = array_key_exists($column, $record) ? $record[$column] : null;
            }
            $stmt->execute($params);
        }
        $mysql->commit();
        $sqlite = null;
        if (!rename(LEGACY_SQLITE_PATH, $marker)) {
            throw new RuntimeException('旧 SQLite 数据已迁移，但备份文件改名失败。');
        }
    } catch (Throwable $exception) {
        if ($mysql->inTransaction()) {
            $mysql->rollBack();
        }
        throw $exception;
    }
}

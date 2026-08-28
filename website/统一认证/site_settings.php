<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const SITE_SETTINGS_KEY = 'site_config';
const SITE_ICP_NUMBER = '';
const SITE_POLICE_NUMBER = '';
const SITE_POLICE_CODE = '';

function site_feature_definitions(): array
{
    return [
        'status' => ['label' => '实时状态', 'path' => '/状态/'],
        'stats' => ['label' => '玩家统计', 'path' => '/统计数据/'],
        'recipes' => ['label' => '配方', 'path' => '/配方/'],
        'enchant' => ['label' => '附魔计算', 'path' => '/附魔计算/'],
        'machines' => ['label' => '经纬度', 'path' => '/经纬度/'],
        'plans' => ['label' => '计划表', 'path' => '/计划表/'],
    ];
}

function site_default_settings(): array
{
    return [
        'siteName' => 'Minecraft 生存服务器',
        'serverName' => '生存服务器',
        'serverAddress' => '服务器地址未配置',
        'editionLabel' => 'MINECRAFT JAVA EDITION 26.1 FABRIC WITH CARPET',
        'homeDescription' => '生存服务器 Minecraft 服务器官网，提供实时状态、玩家统计。',
        'homeContentTitle' => '服务器内容',
        'homeContentSubtitle' => '状态与玩家统计',
        'offlineAfterSeconds' => 15,
        'terminalUrl' => '',
        'terminalKey' => '',
        'features' => array_fill_keys(array_keys(site_feature_definitions()), true),
    ];
}

function site_settings_initialize(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
            setting_value MEDIUMTEXT NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_site_settings_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function site_merge_settings(array $settings): array
{
    $defaults = site_default_settings();
    $merged = array_merge($defaults, array_intersect_key($settings, $defaults));
    $features = $settings['features'] ?? [];
    $features = is_array($features) ? $features : [];
    $merged['features'] = array_merge($defaults['features'], array_intersect_key($features, $defaults['features']));
    foreach ($merged['features'] as $key => $enabled) {
        $merged['features'][$key] = (bool) $enabled;
    }
    $merged['offlineAfterSeconds'] = max(5, min(3600, (int) $merged['offlineAfterSeconds']));
    return $merged;
}

function site_settings_read(): array
{
    $pdo = auth_db();
    site_settings_initialize($pdo);
    $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :setting_key');
    $stmt->execute(['setting_key' => SITE_SETTINGS_KEY]);
    $encoded = $stmt->fetchColumn();
    if (!is_string($encoded) || $encoded === '') {
        return site_default_settings();
    }
    $decoded = json_decode($encoded, true);
    return site_merge_settings(is_array($decoded) ? $decoded : []);
}

function site_public_settings(): array
{
    $settings = site_settings_read();
    $settings['icpNumber'] = SITE_ICP_NUMBER;
    $settings['policeNumber'] = SITE_POLICE_NUMBER;
    $settings['policeCode'] = SITE_POLICE_CODE;
    $settings['featureDefinitions'] = site_feature_definitions();
    $settings['currentUser'] = null;
    try {
        if (auth_is_authenticated()) {
            $user = auth_current_user();
            $settings['currentUser'] = $user ? [
                'username' => (string) $user['username'],
                'role' => (string) $user['role'],
                'isSuperadmin' => (string) $user['role'] === 'superadmin',
            ] : null;
        }
    } catch (Throwable $exception) {
        error_log('Site current user lookup failed: ' . $exception->getMessage());
    }
    if (empty($settings['currentUser']['isSuperadmin'])) {
        unset($settings['terminalUrl']);
    }
    unset($settings['terminalKey']);
    return $settings;
}

function site_clean_text(array $input, string $key, int $limit): string
{
    $value = trim((string) ($input[$key] ?? ''));
    if (mb_strlen($value) > $limit) {
        $value = mb_substr($value, 0, $limit);
    }
    return $value;
}

function site_validate_terminal_url(array $input, array &$errors): string
{
    $value = trim((string) ($input['terminalUrl'] ?? ''));
    if ($value === '') {
        return '';
    }
    if (mb_strlen($value) > 500 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        $errors[] = '终端地址过长或包含非法字符。';
        return '';
    }
    $parts = parse_url($value);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
        $errors[] = '终端地址必须是以 http:// 或 https:// 开头的完整地址。';
        return '';
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        $errors[] = '终端地址不能包含账号或密码。';
        return '';
    }
    return $value;
}

function site_validate_terminal_key(array $input, array &$errors): string
{
    $value = trim((string) ($input['terminalKey'] ?? ''));
    if ($value === '') {
        return '';
    }
    if (mb_strlen($value) > 200 || preg_match('/[\x00-\x20\x7F]/', $value)) {
        $errors[] = '终端密钥过长或包含非法字符。';
        return '';
    }
    return $value;
}

function site_validate_settings(array $input): array
{
    $errors = [];
    $defaults = site_default_settings();
    $settings = [
        'siteName' => site_clean_text($input, 'siteName', 40),
        'serverName' => site_clean_text($input, 'serverName', 80),
        'serverAddress' => site_clean_text($input, 'serverAddress', 120),
        'editionLabel' => site_clean_text($input, 'editionLabel', 120),
        'homeDescription' => site_clean_text($input, 'homeDescription', 180),
        'homeContentTitle' => site_clean_text($input, 'homeContentTitle', 60),
        'homeContentSubtitle' => site_clean_text($input, 'homeContentSubtitle', 80),
        'offlineAfterSeconds' => (int) ($input['offlineAfterSeconds'] ?? $defaults['offlineAfterSeconds']),
        'terminalUrl' => site_validate_terminal_url($input, $errors),
        'terminalKey' => site_validate_terminal_key($input, $errors),
        'features' => [],
    ];

    foreach (['siteName', 'serverName', 'serverAddress', 'editionLabel'] as $key) {
        if ($settings[$key] === '') {
            $errors[] = '站点名称、服务器名称、地址和版本标签不能为空。';
            break;
        }
    }

    if ($settings['offlineAfterSeconds'] < 5 || $settings['offlineAfterSeconds'] > 3600) {
        $errors[] = '离线判定时间必须在 5 到 3600 秒之间。';
        $settings['offlineAfterSeconds'] = $defaults['offlineAfterSeconds'];
    }

    $postedFeatures = $input['features'] ?? [];
    $postedFeatures = is_array($postedFeatures) ? $postedFeatures : [];
    foreach (site_feature_definitions() as $key => $_definition) {
        $settings['features'][$key] = array_key_exists($key, $postedFeatures);
    }

    return [site_merge_settings($settings), $errors];
}

function site_settings_save(array $settings, int $userId): void
{
    $pdo = auth_db();
    site_settings_initialize($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (:setting_key, :setting_value, :updated_by, :updated_at)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'setting_key' => SITE_SETTINGS_KEY,
        'setting_value' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_by' => $userId,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

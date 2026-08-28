<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    return auth_csrf_token();
}

function verify_csrf(): void
{
    auth_verify_csrf((string) ($_POST['csrf_token'] ?? ''));
}

function dimensions(): array
{
    return [
        'overworld' => '主世界',
        'nether' => '地狱',
        'end' => '末地',
    ];
}

function render_site_header(string $title, string $description = ''): void
{
    machine_session_start();
    $pageTitle = $title === '经纬度' ? '经纬度 | Minecraft 生存服务器' : $title . ' | 经纬度 | Minecraft 生存服务器';
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f9a8d4">
    <meta name="description" content="<?= h($description ?: 'Minecraft 生存服务器 Minecraft 机器坐标登记与维度坐标换算。') ?>">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="../assets/site.css?v=<?= h(SITE_CSS_VERSION) ?>">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260829b"></script>
    <script src="../assets/site.js?v=<?= h(SITE_JS_VERSION) ?>"></script>
    <script defer src="assets/machines.js?v=<?= h(MACHINE_JS_VERSION) ?>"></script>
</head>
<body class="machine-page">
<header class="topbar"><div class="shell"><a class="brand" href="../">Minecraft 生存服务器</a><nav class="nav" aria-label="站点导航"><a href="../">首页</a><a href="../状态/">实时状态</a><a href="../统计数据/">玩家统计</a><a href="../配方/">配方</a><a href="../附魔计算/">附魔计算</a><a href="./" aria-current="page">经纬度</a><a href="../计划表/">计划表</a><?php if (machine_is_superadmin()): ?><a class="nav-account" href="../admin/">后台</a><?php endif; ?><?php if (machine_is_authenticated()): ?><a href="../个人资料/">个人资料</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/经纬度/"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button type="submit">退出</button></form><?php endif; ?></nav></div></header>
<?php
}

function render_site_footer(): void
{
    ?>
<footer class="site-footer"><div class="shell"><span>Minecraft 生存服务器</span><div class="filing"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"></a><a href="https://beian.mps.gov.cn/#/query/webSearch?code=" target="_blank" rel="noopener noreferrer"></a></div></div></footer>
</body>
</html>
<?php
}

function dimension_name(?string $dimension): string
{
    $dimensions = dimensions();
    return $dimensions[$dimension ?? ''] ?? '未知';
}

function convert_coordinates(string $dimension, float $x, float $y, float $z): array
{
    if ($dimension === 'overworld') {
        return [
            'dimension' => 'nether',
            'x' => floor($x / 8),
            'y' => $y,
            'z' => floor($z / 8),
        ];
    }

    if ($dimension === 'nether') {
        return [
            'dimension' => 'overworld',
            'x' => floor($x * 8),
            'y' => $y,
            'z' => floor($z * 8),
        ];
    }

    return [
        'dimension' => null,
        'x' => null,
        'y' => null,
        'z' => null,
    ];
}

function format_number($number): string
{
    if ($number === null || $number === '') {
        return '';
    }

    $float = (float) $number;
    if (floor($float) === $float) {
        return (string) (int) $float;
    }

    return rtrim(rtrim(number_format($float, 6, '.', ''), '0'), '.');
}

function format_coordinates($x, $y, $z): string
{
    return format_number($x) . ', ' . format_number($y) . ', ' . format_number($z);
}

function display_time(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('Y-m-d H:i', $timestamp);
}

function upload_file(string $field, string $folder, array $allowedExtensions, ?string $oldPath = null): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return $oldPath;
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('文件上传失败：' . $field);
    }

    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('文件过大，最大允许 10MB。');
    }

    $originalName = (string) $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('不允许的文件类型：' . h($originalName));
    }

    if ($folder === 'photos') {
        $imageInfo = @getimagesize((string) $file['tmp_name']);
        $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
        $photoMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $photoMimes, true)) {
            throw new RuntimeException('照片内容不是受支持的图片格式。');
        }
    }

    $targetDir = UPLOAD_DIR . '/' . $folder;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    $safeName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('保存上传文件失败。');
    }

    if ($oldPath) {
        delete_uploaded_file($oldPath);
    }

    return $folder . '/' . $safeName;
}

function delete_uploaded_file(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $relativePath = preg_replace('#^uploads/#', '', str_replace('\\', '/', $relativePath));
    $fullPath = realpath(UPLOAD_DIR . '/' . $relativePath);
    $uploadRoot = realpath(UPLOAD_DIR);

    if ($fullPath && $uploadRoot && strpos($fullPath, $uploadRoot . DIRECTORY_SEPARATOR) === 0 && is_file($fullPath)) {
        unlink($fullPath);
    }
}

function machine_file_url(int $id, string $type): string
{
    return 'file.php?id=' . $id . '&amp;type=' . rawurlencode($type);
}

function machine_file_path(?string $relativePath): ?string
{
    if (!$relativePath) {
        return null;
    }
    $relativePath = preg_replace('#^uploads/#', '', str_replace('\\', '/', $relativePath));
    $fullPath = realpath(UPLOAD_DIR . '/' . $relativePath);
    $uploadRoot = realpath(UPLOAD_DIR);
    if (!$fullPath || !$uploadRoot || strpos($fullPath, $uploadRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($fullPath)) {
        return null;
    }
    return $fullPath;
}

function machine_player_options(): array
{
    static $options = null;
    if (is_array($options)) {
        return $options;
    }

    try {
        $sql = "SELECT u.username, u.role, COALESCE(p.nickname, '') AS nickname,
                       COALESCE(p.minecraft_username, '') AS minecraft_username
                FROM users u
                LEFT JOIN plan_profiles p ON p.username = u.username
                WHERE u.enabled = 1
                ORDER BY u.role = 'superadmin' DESC, u.username";
        $records = db()->query($sql)->fetchAll();
    } catch (Throwable $exception) {
        error_log('Machine player list profile lookup failed: ' . $exception->getMessage());
        $records = db()->query("SELECT username, role, '' AS nickname, '' AS minecraft_username FROM users WHERE enabled = 1 ORDER BY role = 'superadmin' DESC, username")->fetchAll();
    }

    $options = array_map(static function (array $record): array {
        $username = (string) $record['username'];
        $minecraftName = trim((string) ($record['minecraft_username'] ?? ''));
        $nickname = trim((string) ($record['nickname'] ?? ''));
        $label = $nickname !== '' ? $nickname . ' · ' . $username : $username;
        if ($minecraftName !== '' && $minecraftName !== $username) {
            $label .= ' · MC ' . $minecraftName;
        }
        return ['value' => $username, 'label' => $label];
    }, $records);

    return $options;
}

function validate_machine_input(array $input, array $legacyPlayerNames = []): array
{
    $errors = [];
    $dimensions = dimensions();

    $machineName = trim((string) ($input['machine_name'] ?? ''));
    $playerName = trim((string) ($input['player_name'] ?? ''));
    $dimension = (string) ($input['dimension'] ?? '');

    if ($machineName === '') {
        $errors[] = '请填写机器名称。';
    }

    if ($playerName === '') {
        $errors[] = '请选择登记玩家。';
    } else {
        $allowedPlayerNames = array_column(machine_player_options(), 'value');
        $allowedPlayerNames = array_merge($allowedPlayerNames, $legacyPlayerNames);
        if (!in_array($playerName, $allowedPlayerNames, true)) {
            $errors[] = '登记玩家必须是已启用的统一认证账户。';
        }
    }

    if (!array_key_exists($dimension, $dimensions)) {
        $errors[] = '请选择正确的维度。';
    }

    foreach (['x' => 'X 坐标', 'y' => 'Y 坐标', 'z' => 'Z 坐标'] as $field => $label) {
        if (!isset($input[$field]) || trim((string) $input[$field]) === '' || !is_numeric($input[$field])) {
            $errors[] = '请填写正确的' . $label . '。';
        }
    }

    return $errors;
}

function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function find_machine(int $id): ?array
{
    require_once __DIR__ . '/db.php';

    $stmt = db()->prepare('SELECT * FROM machines WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $machine = $stmt->fetch();

    return $machine ?: null;
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/site_settings.php';

auth_require();
if (!auth_is_superadmin()) {
    http_response_code(403);
    exit('Forbidden');
}

function admin_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$user = auth_current_user();
$message = '';
$errors = [];

try {
    $settings = site_settings_read();
} catch (Throwable $exception) {
    error_log('Admin settings read failed: ' . $exception->getMessage());
    $settings = site_default_settings();
    $errors[] = '站点设置暂时不可用，错误已写入服务器日志。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        auth_verify_csrf();
        [$settings, $errors] = site_validate_settings($_POST);
        if (!$errors) {
            site_settings_save($settings, (int) ($user['id'] ?? 0));
            auth_audit('site_settings_updated', ['features' => $settings['features']]);
            $message = '设置已保存。';
        }
    } catch (Throwable $exception) {
        error_log('Admin settings save failed: ' . $exception->getMessage());
        $errors[] = '保存失败，请刷新后重试。';
    }
}

$featureDefinitions = site_feature_definitions();
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <meta name="theme-color" content="#f9a8d4">
    <title>后台管理 | 示例服务器</title>
    <link rel="stylesheet" href="/assets/site.css?v=20260731a">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260724a"></script>
    <script src="/assets/site.js?v=20260731a"></script>
</head>
<body class="admin-page machine-page">
<header class="topbar"><div class="shell"><a class="brand" href="/">示例服务器</a><nav class="nav" aria-label="站点导航"><a href="/">首页</a><a href="/状态/">实时状态</a><a href="/统计数据/">玩家统计</a><a href="/配方/">配方</a><a href="/附魔计算/">附魔计算</a><a href="/经纬度/">经纬度</a><a href="/计划表/">计划表</a><a class="nav-account" href="/admin/" aria-current="page">后台</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/"><input type="hidden" name="csrf_token" value="<?= admin_h(auth_csrf_token()) ?>"><button type="submit">退出</button></form></nav></div></header>
<main class="admin-main shell">
    <header class="admin-heading">
        <div>
            <h1>站点后台</h1>
            <p>修改首页展示信息、服务器地址、入口开关和站内账户。</p>
        </div>
        <span>当前账户：<?= admin_h((string) ($user['username'] ?? '')) ?></span>
    </header>

    <?php if ($message !== ''): ?><div class="admin-toast" role="status"><?= admin_h($message) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="admin-alert" role="alert"><?php foreach ($errors as $error): ?><p><?= admin_h($error) ?></p><?php endforeach; ?></div><?php endif; ?>

    <form class="admin-grid" method="post" action="/admin/" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= admin_h(auth_csrf_token()) ?>">
        <section class="admin-panel">
            <h2>展示信息</h2>
            <label><span>网站名称</span><input name="siteName" maxlength="40" required value="<?= admin_h((string) $settings['siteName']) ?>"></label>
            <label><span>首页服务器名称</span><input name="serverName" maxlength="80" required value="<?= admin_h((string) $settings['serverName']) ?>"></label>
            <label><span>服务器地址</span><input name="serverAddress" maxlength="120" required value="<?= admin_h((string) $settings['serverAddress']) ?>"></label>
            <label><span>版本标签</span><input name="editionLabel" maxlength="120" required value="<?= admin_h((string) $settings['editionLabel']) ?>"></label>
            <label><span>首页描述</span><textarea name="homeDescription" maxlength="180"><?= admin_h((string) $settings['homeDescription']) ?></textarea></label>
        </section>

        <section class="admin-panel">
            <h2>首页区块</h2>
            <label><span>内容区标题</span><input name="homeContentTitle" maxlength="60" value="<?= admin_h((string) $settings['homeContentTitle']) ?>"></label>
            <label><span>内容区副标题</span><input name="homeContentSubtitle" maxlength="80" value="<?= admin_h((string) $settings['homeContentSubtitle']) ?>"></label>
            <label><span>状态离线判定秒数</span><input type="number" name="offlineAfterSeconds" min="5" max="3600" step="1" value="<?= (int) $settings['offlineAfterSeconds'] ?>"></label>
            <div class="admin-fixed">
                <span>固定备案信息</span>
                <strong><?= admin_h(SITE_ICP_NUMBER) ?></strong>
                <strong><?= admin_h(SITE_POLICE_NUMBER) ?></strong>
            </div>
        </section>

        <section class="admin-panel">
            <h2>账户管理</h2>
            <div class="admin-fixed">
                <span>统一认证账户</span>
                <strong>账户只能由超级管理员手动维护</strong>
                <a class="machine-button primary" href="/admin/users.php">进入账户管理</a>
            </div>
        </section>

        <section class="admin-panel">
            <h2>配方管理</h2>
            <div class="admin-fixed">
                <span>数据库配方与缩略图</span>
                <strong>维护自定义合成表，缩略图保存在 data/thumbnails</strong>
                <a class="machine-button primary" href="/admin/recipes.php">进入配方管理</a>
            </div>
        </section>

        <section class="admin-panel admin-feature-panel">
            <h2>功能入口</h2>
            <?php foreach ($featureDefinitions as $key => $definition): ?>
                <label class="admin-toggle">
                    <input type="checkbox" name="features[<?= admin_h($key) ?>]" value="1" <?= !empty($settings['features'][$key]) ? 'checked' : '' ?>>
                    <span><?= admin_h((string) $definition['label']) ?></span>
                    <small><?= admin_h((string) $definition['path']) ?></small>
                </label>
            <?php endforeach; ?>
        </section>

        <section class="admin-actions">
            <button class="machine-button primary" type="submit">保存设置</button>
            <a class="machine-button" href="/">返回首页</a>
        </section>
    </form>
</main>
<footer class="site-footer"><div class="shell"><span>示例服务器</span><div class="filing"></div></div></footer>
</body>
</html>

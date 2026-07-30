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

function admin_display_time(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('Y-m-d H:i', $timestamp);
}

$users = auth_db()->query(
    "SELECT id, username, role, enabled, last_login_at, created_at, updated_at
     FROM users ORDER BY enabled DESC, role = 'superadmin' DESC, username"
)->fetchAll();
$logs = auth_db()->query(
    'SELECT audit_logs.*, users.username
     FROM audit_logs LEFT JOIN users ON users.id = audit_logs.user_id
     ORDER BY audit_logs.id DESC LIMIT 100'
)->fetchAll();
$csrf = auth_csrf_token();
$message = trim((string) ($_GET['message'] ?? ''));
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <meta name="theme-color" content="#f9a8d4">
    <title>账户管理 | 后台 | 示例服务器</title>
    <link rel="stylesheet" href="/assets/site.css?v=20260731a">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260724a"></script>
    <script src="/assets/site.js?v=20260731a"></script>
</head>
<body class="admin-page machine-page">
<header class="topbar"><div class="shell"><a class="brand" href="/">示例服务器</a><nav class="nav" aria-label="站点导航"><a href="/">首页</a><a href="/状态/">实时状态</a><a href="/统计数据/">玩家统计</a><a href="/配方/">配方</a><a href="/附魔计算/">附魔计算</a><a href="/经纬度/">经纬度</a><a href="/计划表/">计划表</a><a class="nav-account" href="/admin/" aria-current="page">后台</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/"><input type="hidden" name="csrf_token" value="<?= admin_h($csrf) ?>"><button type="submit">退出</button></form></nav></div></header>
<main class="machine-main machine-admin shell">
    <header class="machine-heading">
        <div><a class="machine-back" href="/admin/">返回后台</a><h1>账户管理</h1><p>统一认证账户、角色状态与最近操作记录</p></div>
        <a class="machine-button primary" href="/admin/user_create.php">创建账户</a>
    </header>
    <?php if ($message !== ''): ?><div class="admin-toast" role="status"><?= admin_h($message) ?></div><?php endif; ?>
    <section class="machine-admin-section">
        <div class="machine-section-title"><h2>账户</h2><span><?= count($users) ?> 个</span></div>
        <div class="machine-admin-table">
            <table><thead><tr><th>账户</th><th>角色</th><th>状态</th><th>最后登录</th><th>创建时间</th><th></th></tr></thead>
            <tbody><?php foreach ($users as $account): ?><tr>
                <td><strong><?= admin_h($account['username']) ?></strong></td>
                <td><?= $account['role'] === 'superadmin' ? '超级管理员' : '编辑账户' ?></td>
                <td><span class="machine-status <?= (int) $account['enabled'] === 1 ? 'enabled' : 'disabled' ?>"><?= (int) $account['enabled'] === 1 ? '启用' : '停用' ?></span></td>
                <td><?= admin_h(admin_display_time($account['last_login_at'])) ?></td>
                <td><?= admin_h(admin_display_time($account['created_at'])) ?></td>
                <td><a href="/admin/user_edit.php?id=<?= (int) $account['id'] ?>">管理</a></td>
            </tr><?php endforeach; ?></tbody></table>
        </div>
    </section>
    <section class="machine-admin-section">
        <div class="machine-section-title"><h2>最近操作</h2><span>最近 100 条</span></div>
        <div class="machine-admin-table audit">
            <table><thead><tr><th>时间</th><th>账户</th><th>事件</th><th>来源 IP</th><th>对象</th></tr></thead>
            <tbody><?php foreach ($logs as $log): ?><tr>
                <td><?= admin_h(admin_display_time($log['created_at'])) ?></td>
                <td><?= admin_h($log['username'] ?: '匿名') ?></td>
                <td><code><?= admin_h($log['event']) ?></code></td>
                <td><code><?= admin_h($log['ip']) ?></code></td>
                <td><small><?= admin_h($log['context_json']) ?></small></td>
            </tr><?php endforeach; ?></tbody></table>
        </div>
    </section>
</main>
<footer class="site-footer"><div class="shell"><span>示例服务器</span><div class="filing"></div></div></footer>
</body>
</html>

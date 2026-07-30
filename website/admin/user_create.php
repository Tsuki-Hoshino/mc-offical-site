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

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        auth_verify_csrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['editor', 'superadmin'], true) ? (string) $_POST['role'] : 'editor';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) $errors[] = '账户名格式不正确。';
        if (strlen($password) < 12 || strlen($password) > 128) $errors[] = '密码长度必须为 12-128 位。';
        if ($password !== (string) ($_POST['password_confirm'] ?? '')) $errors[] = '两次输入的密码不一致。';
        if (!$errors) {
            $now = date('Y-m-d H:i:s');
            $stmt = auth_db()->prepare('INSERT INTO users (username, password_hash, role, enabled, created_at, updated_at) VALUES (:username, :password_hash, :role, 1, :created_at, :updated_at)');
            $stmt->execute(['username' => $username, 'password_hash' => auth_hash_password($password), 'role' => $role, 'created_at' => $now, 'updated_at' => $now]);
            auth_audit('user_created', ['id' => (int) auth_db()->lastInsertId(), 'username' => $username, 'role' => $role]);
            header('Location: /admin/users.php', true, 302);
            exit;
        }
    } catch (PDOException $exception) {
        $errors[] = $exception->getCode() === '23000' ? '该账户名已存在。' : '账户创建失败。';
    } catch (Throwable $exception) {
        error_log('Admin user create failed: ' . $exception->getMessage());
        $errors[] = '账户创建失败。';
    }
}
$csrf = auth_csrf_token();
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <meta name="theme-color" content="#f9a8d4">
    <title>创建账户 | 后台 | 示例服务器</title>
    <link rel="stylesheet" href="/assets/site.css?v=20260731a">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260724a"></script>
    <script src="/assets/site.js?v=20260731a"></script>
</head>
<body class="admin-page machine-page">
<header class="topbar"><div class="shell"><a class="brand" href="/">示例服务器</a><nav class="nav" aria-label="站点导航"><a href="/">首页</a><a href="/状态/">实时状态</a><a href="/统计数据/">玩家统计</a><a href="/配方/">配方</a><a href="/附魔计算/">附魔计算</a><a href="/经纬度/">经纬度</a><a href="/计划表/">计划表</a><a class="nav-account" href="/admin/" aria-current="page">后台</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/"><input type="hidden" name="csrf_token" value="<?= admin_h($csrf) ?>"><button type="submit">退出</button></form></nav></div></header>
<main class="machine-main machine-account-editor shell"><header class="machine-heading"><div><a class="machine-back" href="/admin/users.php">返回账户管理</a><h1>创建账户</h1><p>新账户不会公开注册，只能由超级管理员创建</p></div></header>
<?php $account = []; $action = '/admin/user_create.php'; require __DIR__ . '/_user_form.php'; ?></main>
<footer class="site-footer"><div class="shell"><span>示例服务器</span><div class="filing"></div></div></footer>
</body>
</html>

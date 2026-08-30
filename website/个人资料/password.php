<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/auth.php';

header('Cache-Control: no-store, private');
if (!auth_has_valid_session()) {
    header('Location: ' . auth_login_url('/个人资料/password.php'), true, 302);
    exit;
}

$user = auth_current_user();
$forceChange = auth_password_change_required();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        auth_verify_csrf();
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        $blockedFor = auth_rate_state((string) $user['username'])['blocked_for'];
        $storedHash = false;
        if ($blockedFor > 0) {
            $errors[] = '尝试次数过多，请稍后再试。';
        } else {
            $stmt = auth_db()->prepare('SELECT password_hash FROM users WHERE id = :id AND enabled = 1');
            $stmt->execute(['id' => (int) $user['id']]);
            $storedHash = $stmt->fetchColumn();
            if (!is_string($storedHash) || !auth_verify_password($currentPassword, $storedHash)) {
                auth_rate_state((string) $user['username'], true);
                $errors[] = '当前密码不正确。';
            }
        }
        $errors = array_merge($errors, auth_validate_new_password($newPassword));
        if ($newPassword !== $passwordConfirm) {
            $errors[] = '两次输入的新密码不一致。';
        }
        if ($newPassword !== '' && hash_equals($currentPassword, $newPassword)) {
            $errors[] = '新密码不能与当前密码相同。';
        }

        if (!$errors) {
            auth_rate_state((string) $user['username'], false, true);
            $now = date('Y-m-d H:i:s');
            $update = auth_db()->prepare(
                'UPDATE users SET password_hash = :password_hash, last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id AND enabled = 1 AND password_hash = :current_hash'
            );
            $update->execute([
                'password_hash' => auth_hash_password($newPassword),
                'last_login_at' => $now,
                'updated_at' => $now,
                'id' => (int) $user['id'],
                'current_hash' => $storedHash,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('账户密码已发生变化。');
            }
            auth_audit('password_changed', ['forced' => $forceChange]);
            auth_session_start();
            session_regenerate_id(true);
            $_SESSION['machine_authenticated_at'] = time();
            $next = $forceChange
                ? auth_normalize_next((string) ($_SESSION['auth_return_to'] ?? '/个人资料/'), '/个人资料/')
                : '/个人资料/?password_changed=1';
            if ($next === '/个人资料/password.php') {
                $next = '/个人资料/';
            }
            unset($_SESSION['auth_return_to']);
            header('Location: ' . $next, true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        error_log('Password change failed: ' . $exception->getMessage());
        $errors[] = '密码修改失败，请稍后重试。';
    }
}

function password_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$csrf = auth_csrf_token();
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <meta name="theme-color" content="#f9a8d4">
    <title>修改密码 | Minecraft 生存服务器</title>
    <link rel="stylesheet" href="/assets/site.css?v=20260829a">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260829b"></script>
    <script src="/assets/site.js?v=20260811a"></script>
</head>
<body class="machine-page">
<header class="topbar"><div class="shell"><?php if ($forceChange): ?><span class="brand">Minecraft 生存服务器</span><?php else: ?><a class="brand" href="/">Minecraft 生存服务器</a><?php endif; ?><nav class="nav" aria-label="站点导航"><?php if (!$forceChange): ?><a href="/">首页</a><a href="/状态/">实时状态</a><a href="/统计数据/">玩家统计</a><a href="/配方/">配方</a><a href="/附魔计算/">附魔计算</a><a href="/经纬度/">经纬度</a><a href="/计划表/">计划表</a><a href="/个人资料/" aria-current="page">个人资料</a><?php endif; ?><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/"><input type="hidden" name="csrf_token" value="<?= password_h($csrf) ?>"><button type="submit">退出</button></form></nav></div></header>
<main class="machine-main machine-account-editor shell">
    <header class="machine-heading"><div><?php if (!$forceChange): ?><a class="machine-back" href="/个人资料/">返回个人资料</a><?php endif; ?><h1><?= $forceChange ? '首次登录，请修改密码' : '修改密码' ?></h1><p><?= $forceChange ? '必须设置新密码后才能继续使用站内功能。' : '修改后当前会话仍保持登录。' ?></p></div></header>
    <?php if ($errors): ?><div class="machine-alert" role="alert"><strong>请修正以下问题</strong><ul><?php foreach ($errors as $error): ?><li><?= password_h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="machine-account-form" method="post" action="/个人资料/password.php">
        <input type="hidden" name="csrf_token" value="<?= password_h($csrf) ?>">
        <label><span>当前密码</span><input type="password" name="current_password" required maxlength="128" autocomplete="current-password" autofocus></label>
        <label><span>新密码</span><input type="password" name="new_password" required minlength="6" maxlength="128" autocomplete="new-password"><small>6-128 位，必须包含数字、大写字母、小写字母和特殊符号</small></label>
        <label><span>确认新密码</span><input type="password" name="password_confirm" required minlength="6" maxlength="128" autocomplete="new-password"></label>
        <div class="machine-form-actions"><?php if (!$forceChange): ?><a class="machine-button" href="/个人资料/">取消</a><?php endif; ?><button class="machine-button primary" type="submit">修改密码</button></div>
    </form>
</main>
<footer class="site-footer"><div class="shell"><span>Minecraft 生存服务器</span><div class="filing"></div></div></footer>
</body>
</html>

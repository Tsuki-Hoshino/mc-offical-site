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

$id = (int) ($_GET['id'] ?? 0);
$stmt = auth_db()->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$account = $stmt->fetch();
if (!$account) {
    http_response_code(404);
    exit('账户不存在。');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        auth_verify_csrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['editor', 'superadmin'], true) ? (string) $_POST['role'] : 'editor';
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) $errors[] = '账户名格式不正确。';
        if ($password !== '') $errors = array_merge($errors, auth_validate_new_password($password));
        if ($password !== (string) ($_POST['password_confirm'] ?? '')) $errors[] = '两次输入的新密码不一致。';
        $enabledSuperadmins = (int) auth_db()->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND enabled = 1")->fetchColumn();
        $removesLastSuperadmin = $account['role'] === 'superadmin' && (int) $account['enabled'] === 1 && ($role !== 'superadmin' || $enabled !== 1) && $enabledSuperadmins <= 1;
        if ($removesLastSuperadmin) $errors[] = '不能停用或降级最后一个超级管理员。';
        $current = auth_current_user();
        if ((int) $current['id'] === $id && $enabled !== 1) $errors[] = '不能停用当前登录账户。';
        if (!$errors) {
            $params = ['username' => $username, 'role' => $role, 'enabled' => $enabled, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $id];
            $sql = 'UPDATE users SET username = :username, role = :role, enabled = :enabled, updated_at = :updated_at';
            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params['password_hash'] = auth_hash_password($password);
            }
            $sql .= ' WHERE id = :id';
            auth_db()->prepare($sql)->execute($params);
            auth_audit('user_updated', ['id' => $id, 'username' => $username, 'role' => $role, 'enabled' => $enabled, 'password_reset' => $password !== '']);
            header('Location: /admin/users.php', true, 302);
            exit;
        }
    } catch (PDOException $exception) {
        $errors[] = $exception->getCode() === '23000' ? '该账户名已存在。' : '账户保存失败。';
    } catch (Throwable $exception) {
        error_log('Admin user edit failed: ' . $exception->getMessage());
        $errors[] = '账户保存失败。';
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
    <title>管理账户 | 后台 | Minecraft 生存服务器</title>
    <link rel="stylesheet" href="/assets/site.css?v=20260829a">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260829b"></script>
    <script src="/assets/site.js?v=20260811a"></script>
</head>
<body class="admin-page machine-page">
<header class="topbar"><div class="shell"><a class="brand" href="/">Minecraft 生存服务器</a><nav class="nav" aria-label="站点导航"><a href="/">首页</a><a href="/状态/">实时状态</a><a href="/统计数据/">玩家统计</a><a href="/配方/">配方</a><a href="/附魔计算/">附魔计算</a><a href="/经纬度/">经纬度</a><a href="/计划表/">计划表</a><a class="nav-account" href="/admin/" aria-current="page">后台</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/"><input type="hidden" name="csrf_token" value="<?= admin_h($csrf) ?>"><button type="submit">退出</button></form></nav></div></header>
<main class="machine-main machine-account-editor shell"><header class="machine-heading"><div><a class="machine-back" href="/admin/users.php">返回账户管理</a><h1>管理 <?= admin_h($account['username']) ?></h1><p>调整角色、状态或重置密码</p></div><?php if ((int) (auth_current_user()['id'] ?? 0) !== $id): ?><button class="machine-button danger" type="button" data-delete-user-open>删除账户</button><?php endif; ?></header>
<?php $action = '/admin/user_edit.php?id=' . $id; require __DIR__ . '/_user_form.php'; ?>
<?php if ((int) (auth_current_user()['id'] ?? 0) !== $id): ?>
<dialog class="recipe-confirm-dialog" id="user-delete-dialog">
    <form method="post" action="/admin/user_delete.php" class="recipe-confirm-panel">
        <input type="hidden" name="csrf_token" value="<?= admin_h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <h2>删除账户</h2>
        <p>确定删除账户 <strong><?= admin_h((string) $account['username']) ?></strong> 吗？审计日志会保留，但该账户无法再登录。</p>
        <div class="recipe-editor-actions">
            <button class="machine-button" type="button" data-delete-user-cancel>取消</button>
            <button class="machine-button danger" type="submit">删除账户</button>
        </div>
    </form>
</dialog>
<script>
(function(){
    const dialog = document.querySelector('#user-delete-dialog');
    const open = document.querySelector('[data-delete-user-open]');
    const cancel = document.querySelector('[data-delete-user-cancel]');
    if (!dialog || !open || !cancel) return;
    open.addEventListener('click', function(){ dialog.showModal(); });
    cancel.addEventListener('click', function(){ dialog.close(); });
}());
</script>
<?php endif; ?>
</main>
<footer class="site-footer"><div class="shell"><span>Minecraft 生存服务器</span><div class="filing"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"></a><a href="https://beian.mps.gov.cn/#/query/webSearch?code=" target="_blank" rel="noopener noreferrer"></a></div></div></footer>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/auth.php';
require_once __DIR__ . '/../计划表/db.php';

if (!auth_is_authenticated()) {
    header('Location: ' . auth_login_url('/个人资料/'), true, 302);
    exit;
}

$user = auth_current_user();
$csrf = auth_csrf_token();

try {
    $planPdo = plan_db();
    $profile = plan_profile($planPdo, (string) $user['username']);
    $claims = plan_user_claims($planPdo, (string) $user['username']);
} catch (Throwable $exception) {
    error_log('Profile page failed: ' . $exception->getMessage());
    $profile = ['nickname' => '', 'minecraft_username' => ''];
    $claims = [];
    $profileError = '个人资料暂时不可用，错误已写入服务器日志。';
}

function profile_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$mc = (string) ($profile['minecraft_username'] ?: $user['username']);
$display = (string) ($profile['nickname'] ?: $user['username']);
$roleLabel = ($user['role'] ?? '') === 'superadmin' ? '超级管理员' : '编辑账户';
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <meta name="theme-color" content="#f9a8d4">
    <title>个人资料 | Minecraft 生存服务器</title>
    <link rel="stylesheet" href="/assets/site.css?v=20260829a">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260829b"></script>
    <script src="/assets/site.js?v=20260811a"></script>
</head>
<body class="plans-page profile-page">
<header class="topbar"><div class="shell"><a class="brand" href="/">Minecraft 生存服务器</a><nav class="nav" aria-label="站点导航"><a href="/">首页</a><a href="/状态/">实时状态</a><a href="/统计数据/">玩家统计</a><a href="/配方/">配方</a><a href="/附魔计算/">附魔计算</a><a href="/经纬度/">经纬度</a><a href="/计划表/">计划表</a><?php if (auth_is_superadmin()): ?><a class="nav-account" href="/admin/">后台</a><?php endif; ?><a href="/个人资料/" aria-current="page">个人资料</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/"><input type="hidden" name="csrf_token" value="<?= profile_h($csrf) ?>"><button type="submit">退出</button></form></nav></div></header>
<main class="shell plans-main">
    <a class="plan-back" href="/计划表/">← 返回计划表</a>
    <?php if (!empty($profileError)): ?><section class="plan-empty" role="alert"><?= profile_h($profileError) ?></section><?php endif; ?>
    <section class="profile-head">
        <img src="/计划表/asset.php?kind=avatar&amp;name=<?= rawurlencode($mc) ?>" alt="">
        <div>
            <p class="plans-kicker">MEMBER PROFILE</p>
            <h1><?= profile_h($display) ?></h1>
            <p><?= profile_h((string) $user['username']) ?> · <?= profile_h($roleLabel) ?></p>
        </div>
    </section>
    <section class="profile-grid">
        <form class="plan-about" data-profile-form>
            <h2>展示资料</h2>
            <label>展示昵称<input name="nickname" maxlength="64" value="<?= profile_h((string) ($profile['nickname'] ?? '')) ?>" placeholder="留空则显示账户名"></label>
            <label>Minecraft 名称<input name="minecraft_username" maxlength="16" pattern="[A-Za-z0-9_]{1,16}" value="<?= profile_h((string) ($profile['minecraft_username'] ?? '')) ?>" placeholder="用于头像显示"></label>
            <p class="plan-form-error" data-error></p>
            <button class="plans-primary" type="submit">保存资料</button>
        </form>
        <section class="plan-about profile-stats">
            <h2>协作统计</h2>
            <div>
                <span>认领记录<strong><?= count($claims) ?></strong></span>
                <span>已完成<strong><?= count(array_filter($claims, static fn ($claim): bool => !empty($claim['collected_at']))) ?></strong></span>
                <span>认领盒数<strong><?= profile_h(number_format(array_sum(array_map(static fn ($claim): float => (float) $claim['boxes'], $claims)), 3)) ?></strong></span>
            </div>
            <a href="/计划表/claims.php">查看我的认领 →</a>
        </section>
    </section>
</main>
<footer class="site-footer"><div class="shell"><span>Minecraft 生存服务器</span><div class="filing"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"></a><a href="https://beian.mps.gov.cn/#/query/webSearch?code=" target="_blank" rel="noopener noreferrer"></a></div></div></footer>
<script>
document.querySelector('[data-profile-form]').addEventListener('submit', async function (event) {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('[type="submit"]');
    const error = form.querySelector('[data-error]');
    const body = Object.assign({ action: 'update_profile', csrf_token: '<?= profile_h($csrf) ?>' }, Object.fromEntries(new FormData(form)));
    button.disabled = true;
    error.textContent = '';
    try {
        const response = await fetch('/计划表/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || '保存失败');
        location.reload();
    } catch (errorValue) {
        error.textContent = errorValue.message || '保存失败';
        button.disabled = false;
    }
});
</script>
</body>
</html>

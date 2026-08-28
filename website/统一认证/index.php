<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

auth_session_start();
header('Cache-Control: no-store, private');
$requestedNext = $_GET['next'] ?? $_POST['next'] ?? $_SESSION['auth_return_to'] ?? null;
if ($requestedNext === null && !empty($_SERVER['HTTP_REFERER'])) {
    $referrer = parse_url((string) $_SERVER['HTTP_REFERER']);
    $referrerHost = strtolower((string) ($referrer['host'] ?? ''));
    $requestHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $referrerPath = rawurldecode((string) ($referrer['path'] ?? ''));
    if ($referrerHost === $requestHost && str_starts_with($referrerPath, '/经纬度/')) {
        $requestedNext = '/经纬度/';
    } elseif ($referrerHost === $requestHost && str_starts_with($referrerPath, '/计划表/')) {
        $requestedNext = '/计划表/';
    }
}
$next = auth_normalize_next($requestedNext, '/');
$_SESSION['auth_return_to'] = $next;
if (auth_is_authenticated()) {
    unset($_SESSION['auth_return_to']);
    header('Location: ' . $next, true, 302);
    exit;
}

$error = '';
$databaseReady = true;
$username = trim((string) ($_POST['username'] ?? ''));
try {
    $configured = auth_user_count() > 0;
} catch (Throwable $exception) {
    $configured = false;
    $databaseReady = false;
    $error = '统一认证暂时不可用，请稍后重试。';
}
$blockedFor = auth_rate_state($username)['blocked_for'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseReady) {
    try {
        auth_verify_csrf();
        $blockedFor = auth_rate_state($username)['blocked_for'];
        if ($blockedFor > 0) {
            $error = '尝试次数过多，请稍后再试。';
        } elseif (auth_login($username, (string) ($_POST['password'] ?? ''))) {
            unset($_SESSION['auth_return_to']);
            header('Location: ' . $next, true, 302);
            exit;
        } else {
            $blockedFor = auth_rate_state($username)['blocked_for'];
            $error = $blockedFor > 0 ? '尝试次数过多，请稍后再试。' : '账户或密码不正确。';
        }
    } catch (Throwable $exception) {
        $error = '认证请求已过期，请刷新后重试。';
    }
}

function auth_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <meta name="theme-color" content="#f9a8d4">
    <title>统一认证 | 示例服务器</title>
    <link rel="stylesheet" href="../assets/site.css?v=20260816b">
    <script src="/assets/site-config.php?v=20260815i"></script>
    <script src="../assets/site.js?v=20260811a"></script>
</head>
<body class="machine-page">
<header class="topbar"><div class="shell"><a class="brand" href="../">示例服务器</a><nav class="nav" aria-label="站点导航"><a href="../">首页</a><a href="../状态/">实时状态</a><a href="../统计数据/">玩家统计</a><a href="../配方/">配方</a><a href="../附魔计算/">附魔计算</a><a href="../经纬度/">经纬度</a><a href="../计划表/">计划表</a></nav></div></header>
<main class="machine-login shell">
    <section class="machine-login-panel">
        <div class="machine-lock" aria-hidden="true"></div>
        <h1>统一认证</h1>
        <p>使用站内凭证完成协作权限验证。</p>
        <?php if (!$configured && $databaseReady): ?><div class="machine-alert" role="alert">统一认证尚未初始化，请先完成站点部署。</div><?php elseif ($error): ?><div class="machine-alert" role="alert"><?= auth_h($error) ?></div><?php endif; ?>
        <form method="post" action="/统一认证/?next=<?= auth_h(rawurlencode($next)) ?>" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= auth_h(auth_csrf_token()) ?>">
            <input type="hidden" name="next" value="<?= auth_h($next) ?>">
            <label><span>账户</span><input type="text" name="username" required maxlength="64" autocomplete="username" value="<?= auth_h($username) ?>" autofocus></label>
            <label><span>密码</span><input type="password" name="password" required autocomplete="current-password"></label>
            <button class="machine-button primary" type="submit" <?= !$configured || $blockedFor > 0 || !$databaseReady ? 'disabled' : '' ?>>进入</button>
        </form>
    </section>
</main>
<footer class="site-footer"><div class="shell"><span>示例服务器</span><div class="filing"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"></a><a href="https://beian.mps.gov.cn/#/query/webSearch?code=" target="_blank" rel="noopener noreferrer"></a></div></div></footer>
</body>
</html>

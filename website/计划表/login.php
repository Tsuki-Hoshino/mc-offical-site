<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/auth.php';

$next = auth_scoped_next('/计划表/', $_GET['next'] ?? $_POST['next'] ?? '', '/计划表/');
if (auth_is_authenticated()) {
    header('Location: ' . $next, true, 302);
    exit;
}
header('Location: ' . auth_login_url($next), true, 302);
exit;

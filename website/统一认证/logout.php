<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$next = auth_normalize_next($_POST['next'] ?? $_GET['next'] ?? '/', '/');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        auth_verify_csrf();
        auth_logout();
    } catch (Throwable $exception) {
        http_response_code(400);
        exit('认证请求已过期，请刷新后重试。');
    }
}
header('Cache-Control: no-store, private');
header('Location: /统一认证/?next=' . rawurlencode($next), true, 302);
exit;

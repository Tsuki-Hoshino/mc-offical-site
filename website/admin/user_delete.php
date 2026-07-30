<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/site_settings.php';

auth_require();
if (!auth_is_superadmin()) {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

function admin_delete_redirect(string $message = ''): never
{
    $location = '/admin/users.php';
    if ($message !== '') {
        $location .= '?message=' . rawurlencode($message);
    }
    header('Location: ' . $location, true, 302);
    exit;
}

try {
    auth_verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $current = auth_current_user();
    if (!$current || $id <= 0) {
        admin_delete_redirect('账户无效。');
    }
    if ((int) $current['id'] === $id) {
        admin_delete_redirect('不能删除当前登录账户。');
    }

    $pdo = auth_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, username, role, enabled FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $account = $stmt->fetch();
        if (!$account) {
            $pdo->rollBack();
            admin_delete_redirect('账户不存在。');
        }

        if ((string) $account['role'] === 'superadmin' && (int) $account['enabled'] === 1) {
            $superadmins = $pdo->query("SELECT id FROM users WHERE role = 'superadmin' AND enabled = 1 FOR UPDATE")->fetchAll();
            if (count($superadmins) <= 1) {
                $pdo->rollBack();
                admin_delete_redirect('不能删除最后一个启用的超级管理员。');
            }
        }

        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    auth_audit('user_deleted', [
        'id' => $id,
        'username' => (string) $account['username'],
        'role' => (string) $account['role'],
        'enabled' => (int) $account['enabled'],
    ]);
    admin_delete_redirect('账户已删除。');
} catch (Throwable $exception) {
    error_log('Admin user delete failed: ' . $exception->getMessage());
    admin_delete_redirect('账户删除失败。');
}

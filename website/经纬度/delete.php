<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_machine_auth();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$machine = find_machine($id);
if (!$machine) {
    http_response_code(404);
    exit('登记记录不存在。');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        delete_uploaded_file($machine['photo_path']);
        delete_uploaded_file($machine['manual_file_path']);
        delete_uploaded_file($machine['notes_file_path']);
        $stmt = db()->prepare('DELETE FROM machines WHERE id = :id');
        $stmt->execute(['id' => $id]);
        machine_audit('machine_deleted', ['id' => $id]);
        redirect_to('index.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
csrf_token();
render_site_header('删除登记');
?>
<main class="machine-main machine-confirm shell">
    <a class="machine-back" href="show.php?id=<?= $id ?>">返回机器详情</a>
    <section class="machine-confirm-panel">
        <span class="machine-danger-mark">!</span>
        <h1>删除 <?= h($machine['machine_name']) ?>？</h1>
        <p>登记信息和已上传文件将一起删除，此操作无法撤销。</p>
        <?php if ($error): ?><div class="machine-alert" role="alert"><?= h($error) ?></div><?php endif; ?>
        <dl><div><dt>登记玩家</dt><dd><?= h($machine['player_name']) ?></dd></div><div><dt>位置</dt><dd><?= h(dimension_name($machine['dimension'])) ?> · <?= h(format_coordinates($machine['x'], $machine['y'], $machine['z'])) ?></dd></div></dl>
        <form method="post" action="delete.php">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <a class="machine-button" href="show.php?id=<?= $id ?>">取消</a>
            <button class="machine-button danger" type="submit">确认删除</button>
        </form>
    </section>
</main>
<?php render_site_footer(); ?>

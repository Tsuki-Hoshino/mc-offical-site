<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_machine_auth();

$id = (int) ($_GET['id'] ?? 0);
$machine = find_machine($id);

if (!$machine) {
    http_response_code(404);
    echo '登记记录不存在。';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $errors = validate_machine_input($_POST, [(string) $machine['player_name']]);
        if (!$errors) {
            $x = (float) $_POST['x'];
            $y = (float) $_POST['y'];
            $z = (float) $_POST['z'];
            $dimension = (string) $_POST['dimension'];
            $converted = convert_coordinates($dimension, $x, $y, $z);

            $photoPath = $machine['photo_path'];
            $manualFilePath = $machine['manual_file_path'];
            $notesFilePath = $machine['notes_file_path'];

            if (!empty($_POST['remove_photo'])) {
                delete_uploaded_file($photoPath);
                $photoPath = null;
            }

            if (!empty($_POST['remove_manual_file'])) {
                delete_uploaded_file($manualFilePath);
                $manualFilePath = null;
            }

            if (!empty($_POST['remove_notes_file'])) {
                delete_uploaded_file($notesFilePath);
                $notesFilePath = null;
            }

            $photoPath = upload_file('photo', 'photos', ALLOWED_PHOTO_EXTENSIONS, $photoPath);
            $manualFilePath = upload_file('manual_file', 'manuals', ALLOWED_DOCUMENT_EXTENSIONS, $manualFilePath);
            $notesFilePath = upload_file('notes_file', 'notes', ALLOWED_DOCUMENT_EXTENSIONS, $notesFilePath);

            $stmt = db()->prepare(
                'UPDATE machines SET
                    machine_name = :machine_name,
                    player_name = :player_name,
                    dimension = :dimension,
                    x = :x,
                    y = :y,
                    z = :z,
                    converted_dimension = :converted_dimension,
                    converted_x = :converted_x,
                    converted_y = :converted_y,
                    converted_z = :converted_z,
                    photo_path = :photo_path,
                    manual_text = :manual_text,
                    manual_file_path = :manual_file_path,
                    notes_text = :notes_text,
                    notes_file_path = :notes_file_path,
                    updated_by = :updated_by,
                    updated_at = :updated_at
                WHERE id = :id'
            );

            $currentUser = machine_current_user();
            $stmt->execute([
                'machine_name' => trim((string) $_POST['machine_name']),
                'player_name' => trim((string) $_POST['player_name']),
                'dimension' => $dimension,
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'converted_dimension' => $converted['dimension'],
                'converted_x' => $converted['x'],
                'converted_y' => $converted['y'],
                'converted_z' => $converted['z'],
                'photo_path' => $photoPath,
                'manual_text' => trim((string) ($_POST['manual_text'] ?? '')),
                'manual_file_path' => $manualFilePath,
                'notes_text' => trim((string) ($_POST['notes_text'] ?? '')),
                'notes_file_path' => $notesFilePath,
                'updated_by' => (int) $currentUser['id'],
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ]);

            machine_audit('machine_updated', ['id' => $id]);
            redirect_to('show.php?id=' . $id);
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<?php csrf_token(); render_site_header('编辑登记'); ?>
<main class="machine-main machine-editor shell">
    <header class="machine-heading"><div><a class="machine-back" href="show.php?id=<?= $id ?>">返回机器详情</a><h1>编辑 <?= h($machine['machine_name']) ?></h1><p>更新坐标、文档或操作说明</p></div></header>
    <?php $action = 'edit.php?id=' . $id; $submitLabel = '保存修改'; require __DIR__ . '/_form.php'; ?>
</main>
<?php render_site_footer(); ?>

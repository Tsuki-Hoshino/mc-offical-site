<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_machine_auth();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $errors = validate_machine_input($_POST);
        if (!$errors) {
            $x = (float) $_POST['x'];
            $y = (float) $_POST['y'];
            $z = (float) $_POST['z'];
            $dimension = (string) $_POST['dimension'];
            $converted = convert_coordinates($dimension, $x, $y, $z);
            $now = date('Y-m-d H:i:s');

            $photoPath = upload_file('photo', 'photos', ALLOWED_PHOTO_EXTENSIONS);
            $manualFilePath = upload_file('manual_file', 'manuals', ALLOWED_DOCUMENT_EXTENSIONS);
            $notesFilePath = upload_file('notes_file', 'notes', ALLOWED_DOCUMENT_EXTENSIONS);

            $stmt = db()->prepare(
                'INSERT INTO machines (
                    machine_name, player_name, dimension, x, y, z,
                    converted_dimension, converted_x, converted_y, converted_z,
                    photo_path, manual_text, manual_file_path, notes_text, notes_file_path,
                    created_by, updated_by, created_at, updated_at
                ) VALUES (
                    :machine_name, :player_name, :dimension, :x, :y, :z,
                    :converted_dimension, :converted_x, :converted_y, :converted_z,
                    :photo_path, :manual_text, :manual_file_path, :notes_text, :notes_file_path,
                    :created_by, :updated_by, :created_at, :updated_at
                )'
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
                'created_by' => (int) $currentUser['id'],
                'updated_by' => (int) $currentUser['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $machineId = (int) db()->lastInsertId();
            machine_audit('machine_created', ['id' => $machineId]);
            redirect_to('show.php?id=' . $machineId);
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<?php csrf_token(); render_site_header('新增登记'); ?>
<main class="machine-main machine-editor shell">
    <header class="machine-heading"><div><a class="machine-back" href="index.php">返回经纬度</a><h1>新增机器登记</h1><p>保存位置、用途和完整操作说明</p></div></header>
    <?php $machine = []; $action = 'create.php'; $submitLabel = '提交登记'; require __DIR__ . '/_form.php'; ?>
</main>
<?php render_site_footer(); ?>

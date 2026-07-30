<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$type = (string) ($_GET['type'] ?? '');
$columns = [
    'photo' => 'photo_path',
    'manual' => 'manual_file_path',
    'notes' => 'notes_file_path',
];
if ($id <= 0 || !isset($columns[$type])) {
    http_response_code(404);
    exit;
}
$machine = find_machine($id);
$path = $machine ? machine_file_path($machine[$columns[$type]] ?? null) : null;
if (!$path) {
    http_response_code(404);
    exit;
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
    'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8',
    'markdown' => 'text/markdown; charset=utf-8',
];
$mime = $mimes[$extension] ?? 'application/octet-stream';
$disposition = $type === 'photo' ? 'inline' : 'attachment';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="machine-' . $type . '.' . $extension . '"');
readfile($path);
exit;

<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/recipes.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

try {
    $stmt = recipe_db()->prepare('SELECT thumbnail FROM recipes WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $thumbnail = (string) ($stmt->fetchColumn() ?: '');
} catch (Throwable $exception) {
    error_log('Recipe thumbnail lookup failed: ' . $exception->getMessage());
    http_response_code(404);
    exit;
}

if ($thumbnail === '') {
    http_response_code(404);
    exit;
}

$base = realpath(recipe_project_root_path() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'thumbnails');
$path = recipe_project_root_path() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $thumbnail);
$real = realpath($path);
if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
    http_response_code(404);
    exit;
}

$info = @getimagesize($real);
if (!is_array($info) || ($info['mime'] ?? '') !== 'image/png') {
    http_response_code(404);
    exit;
}

$mtime = (int) filemtime($real);
$etag = '"' . sha1($id . ':' . $mtime . ':' . filesize($real)) . '"';
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if ((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
readfile($real);

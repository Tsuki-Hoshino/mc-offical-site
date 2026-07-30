<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/auth.php';
require_once __DIR__ . '/../配方/lib/recipes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function recipe_api_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function recipe_api_error(string $error, int $status = 400): never
{
    recipe_api_out(['error' => $error], $status);
}

function recipe_api_require_superadmin(): void
{
    if (!auth_is_authenticated()) {
        recipe_api_error('authentication_required', 401);
    }
    if (!auth_is_superadmin()) {
        recipe_api_error('forbidden', 403);
    }
}

function recipe_api_input(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains(strtolower($contentType), 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function recipe_api_verify_csrf(array $input): void
{
    try {
        auth_verify_csrf((string) ($input['csrf_token'] ?? ''));
    } catch (Throwable $exception) {
        recipe_api_error('csrf_failed', 403);
    }
}

function recipe_api_item_id(string $value, bool $allowTag = false): string
{
    $value = normalize_recipe_item_id($value);
    if ($value === '' || (!$allowTag && str_starts_with($value, '#'))) {
        throw new InvalidArgumentException('invalid_item_id');
    }
    $pattern = $allowTag ? '/^#?[a-z0-9_.-]+:[a-z0-9_.-]+$/' : '/^[a-z0-9_.-]+:[a-z0-9_.-]+$/';
    if (preg_match($pattern, $value) !== 1) {
        throw new InvalidArgumentException('invalid_item_id');
    }
    return $value;
}

function recipe_api_stack($value, bool $allowNull = true): ?array
{
    if ($value === null && $allowNull) {
        return null;
    }
    if (!is_array($value)) {
        throw new InvalidArgumentException('invalid_recipe_item');
    }
    $itemId = recipe_api_item_id((string) ($value['itemId'] ?? ''), false);
    $count = max(1, min(999, (int) ($value['count'] ?? 1)));
    return ['itemId' => $itemId, 'count' => $count];
}

function recipe_api_normalize_input(string $type, $input): array
{
    $filled = 0;
    if ($type === 'shaped') {
        if (!is_array($input) || count($input) !== 3) {
            throw new InvalidArgumentException('invalid_shaped_input');
        }
        $rows = [];
        foreach ($input as $row) {
            if (!is_array($row) || count($row) !== 3) {
                throw new InvalidArgumentException('invalid_shaped_input');
            }
            $cells = [];
            foreach ($row as $cell) {
                $stack = recipe_api_stack($cell, true);
                if ($stack !== null) {
                    $filled++;
                }
                $cells[] = $stack;
            }
            $rows[] = $cells;
        }
        if ($filled === 0) {
            throw new InvalidArgumentException('empty_input');
        }
        return $rows;
    }

    if (!is_array($input)) {
        throw new InvalidArgumentException('invalid_shapeless_input');
    }
    if (count($input) > 9) {
        throw new InvalidArgumentException('invalid_shapeless_input');
    }
    $merged = [];
    foreach ($input as $stack) {
        $stack = recipe_api_stack($stack, false);
        $merged[$stack['itemId']] = ($merged[$stack['itemId']] ?? 0) + $stack['count'];
        $filled++;
    }
    if ($filled === 0) {
        throw new InvalidArgumentException('empty_input');
    }
    $items = [];
    foreach ($merged as $itemId => $count) {
        $items[] = ['itemId' => $itemId, 'count' => min(999, (int) $count)];
    }
    return $items;
}

function recipe_api_validate_payload(array $input): array
{
    $type = (string) ($input['type'] ?? 'shaped');
    if (!in_array($type, ['shaped', 'shapeless'], true)) {
        throw new InvalidArgumentException('invalid_type');
    }
    $output = recipe_api_stack($input['output'] ?? null, false);
    $recipeInput = recipe_api_normalize_input($type, $input['input'] ?? null);
    $name = trim((string) ($input['name'] ?? ''));
    if (mb_strlen($name) > 255) {
        $name = mb_substr($name, 0, 255);
    }
    if ($name === '') {
        $name = recipe_item_label($output['itemId'], load_minecraft_language());
    }
    if ($name === '') {
        $name = humanize_recipe_id($output['itemId']);
    }
    return [
        'name' => $name,
        'type' => $type,
        'input' => $recipeInput,
        'output_item_id' => $output['itemId'],
        'output_count' => $output['count'],
    ];
}

function recipe_api_public_row(array $row): array
{
    $id = (int) $row['id'];
    return [
        'id' => $id,
        'name' => (string) $row['name'],
        'type' => (string) $row['type'],
        'type_label' => (string) $row['type'] === 'shapeless' ? '无序' : '有序',
        'input' => json_decode((string) $row['input'], true),
        'output' => [
            'itemId' => (string) $row['output_item_id'],
            'count' => (int) $row['output_count'],
        ],
        'output_item_id' => (string) $row['output_item_id'],
        'output_count' => (int) $row['output_count'],
        'thumbnail' => (string) ($row['thumbnail'] ?? ''),
        'thumbnail_url' => recipe_public_thumbnail_url($id, (string) ($row['thumbnail'] ?? '')),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function recipe_api_thumbnail_file(int $id): string
{
    return recipe_project_root_path() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR . 'recipe_' . $id . '.png';
}

try {
    recipe_api_require_superadmin();
    $pdo = recipe_db();
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($action === 'list') {
        $rows = $pdo->query('SELECT * FROM recipes ORDER BY updated_at DESC, id DESC')->fetchAll();
        recipe_api_out(['items' => array_map('recipe_api_public_row', $rows)]);
    }

    if ($action === 'get') {
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM recipes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            recipe_api_error('not_found', 404);
        }
        recipe_api_out(['item' => recipe_api_public_row($row)]);
    }

    if ($method !== 'POST') {
        recipe_api_error('method_not_allowed', 405);
    }

    $input = recipe_api_input();
    recipe_api_verify_csrf($input);

    if ($action === 'create' || $action === 'update') {
        $payload = recipe_api_validate_payload($input);
        $encodedInput = json_encode($payload['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedInput)) {
            recipe_api_error('invalid_input', 422);
        }
        $now = date('Y-m-d H:i:s');
        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO recipes (name, type, input, output_item_id, output_count, created_at, updated_at)
                 VALUES (:name, :type, :input, :output_item_id, :output_count, :created_at, :updated_at)'
            );
            $stmt->execute([
                'name' => $payload['name'],
                'type' => $payload['type'],
                'input' => $encodedInput,
                'output_item_id' => $payload['output_item_id'],
                'output_count' => $payload['output_count'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $pdo->lastInsertId();
            auth_audit('recipe_created', ['id' => $id, 'name' => $payload['name']]);
            recipe_api_out(['id' => $id], 201);
        }

        $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
        $exists = $pdo->prepare('SELECT id FROM recipes WHERE id = :id');
        $exists->execute(['id' => $id]);
        if (!$exists->fetch()) {
            recipe_api_error('not_found', 404);
        }
        $stmt = $pdo->prepare(
            'UPDATE recipes
             SET name = :name, type = :type, input = :input, output_item_id = :output_item_id, output_count = :output_count
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $payload['name'],
            'type' => $payload['type'],
            'input' => $encodedInput,
            'output_item_id' => $payload['output_item_id'],
            'output_count' => $payload['output_count'],
            'id' => $id,
        ]);
        auth_audit('recipe_updated', ['id' => $id, 'name' => $payload['name']]);
        recipe_api_out(['id' => $id, 'ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, name, thumbnail FROM recipes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            recipe_api_error('not_found', 404);
        }
        $pdo->prepare('DELETE FROM recipes WHERE id = :id')->execute(['id' => $id]);
        $file = recipe_api_thumbnail_file($id);
        if (is_file($file)) {
            @unlink($file);
        }
        auth_audit('recipe_deleted', ['id' => $id, 'name' => (string) $row['name']]);
        recipe_api_out(['ok' => true]);
    }

    if ($action === 'upload_thumbnail') {
        $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, name FROM recipes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            recipe_api_error('not_found', 404);
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            recipe_api_error('invalid_upload', 422);
        }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 500 * 1024) {
            recipe_api_error('file_too_large', 422);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $info = @getimagesize($tmp);
        if (!is_array($info) || ($info['mime'] ?? '') !== 'image/png') {
            recipe_api_error('invalid_png', 422);
        }
        $signature = @file_get_contents($tmp, false, null, 0, 8);
        if ($signature !== "\x89PNG\r\n\x1a\n") {
            recipe_api_error('invalid_png', 422);
        }
        $dir = dirname(recipe_api_thumbnail_file($id));
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            recipe_api_error('storage_unavailable', 500);
        }
        $target = recipe_api_thumbnail_file($id);
        if (!@move_uploaded_file($tmp, $target)) {
            recipe_api_error('upload_failed', 500);
        }
        $relative = 'data/thumbnails/recipe_' . $id . '.png';
        $pdo->prepare('UPDATE recipes SET thumbnail = :thumbnail WHERE id = :id')->execute([
            'thumbnail' => $relative,
            'id' => $id,
        ]);
        recipe_api_out([
            'ok' => true,
            'thumbnail' => $relative,
            'thumbnail_url' => recipe_public_thumbnail_url($id, $relative),
        ]);
    }

    recipe_api_error('unknown_action', 400);
} catch (InvalidArgumentException $exception) {
    recipe_api_error($exception->getMessage(), 422);
} catch (Throwable $exception) {
    error_log('Recipe admin API failed: ' . $exception->getMessage());
    recipe_api_error('server_error', 500);
}

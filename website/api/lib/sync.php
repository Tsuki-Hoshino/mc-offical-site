<?php
declare(strict_types=1);

function sync_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'sync.php';
    $config = is_file($path) ? require $path : [];
    if (!is_array($config)) {
        $config = [];
    }

    $config += [
        'token' => getenv('MC_SYNC_TOKEN') ?: '',
        'allowed_types' => ['status', 'stats'],
        'max_bytes' => 33554432,
    ];

    return $config;
}

function sync_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sync_require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        sync_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
}

function sync_storage_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'inbox';
}

function sync_normalize_type(string $type): string
{
    $type = strtolower(trim($type));
    if (!preg_match('/^[a-z0-9_-]{1,40}$/', $type)) {
        sync_json_response(['ok' => false, 'error' => 'invalid_type'], 400);
    }

    $allowed = sync_config()['allowed_types'];
    if (is_array($allowed) && $allowed !== [] && !in_array($type, $allowed, true)) {
        sync_json_response(['ok' => false, 'error' => 'type_not_allowed'], 400);
    }

    return $type;
}

function sync_data_path(string $type): string
{
    return sync_storage_dir() . DIRECTORY_SEPARATOR . $type . '.json.php';
}

function sync_guard_prefix(): string
{
    return "<?php http_response_code(404); exit; ?>\n";
}

function sync_write_data(string $type, array $payload): void
{
    $dir = sync_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        sync_json_response(['ok' => false, 'error' => 'storage_unavailable'], 500);
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        sync_json_response(['ok' => false, 'error' => 'json_encode_failed'], 500);
    }

    $path = sync_data_path($type);
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, sync_guard_prefix() . $json, LOCK_EX) === false) {
        sync_json_response(['ok' => false, 'error' => 'write_failed'], 500);
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        sync_json_response(['ok' => false, 'error' => 'replace_failed'], 500);
    }
}

function sync_read_data(string $type): ?array
{
    $path = sync_data_path($type);
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $prefix = sync_guard_prefix();
    if (strncmp($raw, $prefix, strlen($prefix)) === 0) {
        $raw = substr($raw, strlen($prefix));
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function sync_request_token(): string
{
    $header = $_SERVER['HTTP_X_MC_SYNC_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') {
        return trim($header);
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (is_string($auth) && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches) === 1) {
        return trim($matches[1]);
    }

    return '';
}

function sync_require_auth(): void
{
    $expected = (string) (sync_config()['token'] ?? '');
    if ($expected === '' || $expected === 'change-this-token-before-deploy') {
        sync_json_response(['ok' => false, 'error' => 'token_not_configured'], 503);
    }

    $actual = sync_request_token();
    if ($actual === '' || !hash_equals($expected, $actual)) {
        sync_json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function sync_decode_request_json(): array
{
    $maxBytes = max(1, (int) (sync_config()['max_bytes'] ?? 33554432));
    $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($length > $maxBytes) {
        sync_json_response(['ok' => false, 'error' => 'payload_too_large'], 413);
    }

    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || $raw === '') {
        sync_json_response(['ok' => false, 'error' => 'empty_body'], 400);
    }
    if (strlen($raw) > $maxBytes) {
        sync_json_response(['ok' => false, 'error' => 'payload_too_large'], 413);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        sync_json_response(['ok' => false, 'error' => 'invalid_json'], 400);
    }

    return $data;
}

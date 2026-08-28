<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/site_settings.php';
require_once __DIR__ . '/DaemonClient.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

auth_require();
if (!auth_is_superadmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

function terminal_api_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

try {
    auth_verify_csrf((string) ($input['csrf_token'] ?? ''));
} catch (Throwable $exception) {
    terminal_api_fail('页面已过期，请刷新后重试。', 403);
}

set_time_limit(60);

try {
    $settings = site_settings_read();
} catch (Throwable $exception) {
    error_log('Terminal settings read failed: ' . $exception->getMessage());
    terminal_api_fail('站点设置暂时不可用。', 500);
}

$terminalUrl = trim((string) ($settings['terminalUrl'] ?? ''));
$terminalKey = trim((string) ($settings['terminalKey'] ?? ''));
if ($terminalUrl === '' || $terminalKey === '') {
    terminal_api_fail('终端尚未配置面板地址或密钥，请先在后台完成配置。');
}

$clientId = (string) ($input['client'] ?? '');
if (preg_match('/^[0-9a-f]{8,32}$/i', $clientId) !== 1) {
    terminal_api_fail('连接标识无效。');
}
$clientId = strtolower($clientId);
$userId = (int) (auth_current_user()['id'] ?? 0);
$statePrefix = 'terminal-daemon-' . $userId . '-' . $clientId;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function terminal_cleanup_stale_sessions(string $stateDir, string $activePrefix): void
{
    $paths = glob(rtrim($stateDir, '/\\') . '/terminal-daemon-*.json');
    if (!is_array($paths)) {
        return;
    }
    foreach ($paths as $path) {
        if (strpos(basename((string) $path), $activePrefix . '.json') === 0) {
            continue;
        }
        $modified = (int) @filemtime((string) $path);
        if ($modified > 0 && time() - $modified > 86400) {
            @unlink((string) $path);
        }
    }
}

$action = (string) ($input['action'] ?? '');
if (!in_array($action, [
    'overview', 'instances', 'log', 'control', 'command', 'subscribe', 'poll', 'input', 'resize',
    'fileLimits', 'fileList', 'fileRead', 'fileWrite', 'fileMkdir', 'fileTouch', 'fileDelete',
    'fileMove', 'fileDownload', 'fileUpload',
], true)) {
    terminal_api_fail('未知操作。');
}

function terminal_valid_uuid(string $value): bool
{
    return preg_match('/^[0-9A-Za-z]{8,64}$/', $value) === 1;
}

function terminal_require_uuid(array $input, string $field): string
{
    $value = (string) ($input[$field] ?? '');
    if (!terminal_valid_uuid($value)) {
        terminal_api_fail('实例标识无效。');
    }
    return $value;
}

function terminal_file_relative_path(array $input, string $field, bool $allowEmpty = false): string
{
    $value = trim((string) ($input[$field] ?? ''));
    if ($value === '') {
        if ($allowEmpty) {
            return '.';
        }
        terminal_api_fail('路径不能为空。');
    }
    if (mb_strlen($value, 'UTF-8') > 512) {
        terminal_api_fail('路径过长。');
    }
    if (preg_match('/[\x00-\x1F\x7F<>:"|?*;\\\\]/u', $value) === 1) {
        terminal_api_fail('路径包含非法字符。');
    }
    if ($value[0] === '/' || $value[0] === '\\' || preg_match('/^[A-Za-z]:/', $value) === 1) {
        terminal_api_fail('路径必须是相对路径。');
    }
    if (in_array('..', explode('/', $value), true)) {
        terminal_api_fail('路径不能包含上级目录。');
    }
    return $value;
}

function terminal_filter_instance(array $item): array
{
    $result = [];
    foreach (['instanceUuid', 'status', 'started', 'autoRestarted'] as $field) {
        $result[$field] = $item[$field] ?? null;
    }
    $result['instanceUuid'] = (string) ($result['instanceUuid'] ?? '');
    $result['nickname'] = (string) ($item['config']['nickname'] ?? $item['nickname'] ?? '');
    $result['status'] = (int) ($result['status'] ?? -1);
    $result['started'] = (int) ($result['started'] ?? 0);
    $result['autoRestarted'] = (int) ($result['autoRestarted'] ?? 0);
    $result['type'] = (string) ($item['config']['type'] ?? '');
    return $result;
}

function terminal_daemon_client(array $settings, string $statePrefix): TerminalDaemonClient
{
    return new TerminalDaemonClient(
        trim((string) $settings['terminalUrl']),
        trim((string) $settings['terminalKey']),
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data',
        $statePrefix
    );
}

const TERMINAL_POLL_EVENT_WHITELIST = ['instance/stdout', 'instance/opened', 'instance/stopped', 'instance/failure', 'stream/detail', 'error'];

function terminal_filter_events(array $events): array
{
    $filtered = [];
    foreach ($events as $eventItem) {
        if (($eventItem['type'] ?? 'event') === 'fatal') {
            $filtered[] = [
                'type' => 'fatal',
                'event' => '',
                'status' => 0,
                'data' => (string) ($eventItem['error'] ?? ''),
            ];
            continue;
        }
        $eventName = (string) ($eventItem['event'] ?? '');
        if (!in_array($eventName, TERMINAL_POLL_EVENT_WHITELIST, true)) {
            continue;
        }
        $data = $eventItem['data'] ?? null;
        if ($eventName === 'instance/stdout') {
            if (is_array($data)) {
                $data = $data['text'] ?? '';
            }
            if (is_string($data) && strlen($data) > 262144) {
                $data = substr($data, -262144);
            }
        }
        $filtered[] = [
            'type' => (string) ($eventItem['type'] ?? 'event'),
            'event' => $eventName,
            'status' => (int) ($eventItem['status'] ?? 200),
            'data' => $data,
        ];
    }
    return $filtered;
}

$client = terminal_daemon_client($settings, $statePrefix);

if ($action === 'poll') {
    terminal_cleanup_stale_sessions(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data', $statePrefix);
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    $client->pollStream(28.0, function (array $events): void {
        $filtered = terminal_filter_events($events);
        if ($filtered) {
            echo json_encode(['events' => $filtered], JSON_UNESCAPED_UNICODE) . "\n";
            flush();
        }
    });
    exit;
}

try {
    if ($action === 'overview') {
        $packet = $client->request('info/overview', null, 10.0);
        $data = is_array($packet['data'] ?? null) ? $packet['data'] : [];
        echo json_encode([
            'status' => (int) ($packet['status'] ?? 200),
            'data' => [
                'version' => (string) ($data['version'] ?? ''),
                'running' => (int) ($data['instance']['running'] ?? 0),
                'total' => (int) ($data['instance']['total'] ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'instances') {
        $packet = $client->request('instance/select', [
            'page' => 1,
            'pageSize' => 50,
            'condition' => ['instanceName' => '', 'status' => '', 'tag' => []],
        ], 10.0);
        $data = is_array($packet['data'] ?? null) ? $packet['data'] : [];
        $items = [];
        foreach ((array) ($data['data'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = terminal_filter_instance($item);
            }
        }
        echo json_encode([
            'status' => (int) ($packet['status'] ?? 200),
            'data' => ['instances' => $items],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'log') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $packet = $client->request('instance/outputlog', ['instanceUuid' => $instanceUuid], 12.0);
        $text = (string) ($packet['data'] ?? '');
        if (strlen($text) > 262144) {
            $text = substr($text, -262144);
        }
        echo json_encode(['status' => (int) ($packet['status'] ?? 200), 'data' => ['text' => $text]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'control') {
        $controls = [
            'start' => 'instance/open',
            'stop' => 'instance/stop',
            'restart' => 'instance/restart',
            'kill' => 'instance/kill',
        ];
        $operation = (string) ($input['operation'] ?? '');
        if (!isset($controls[$operation])) {
            terminal_api_fail('未知的控制操作。');
        }
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $packet = $client->request($controls[$operation], [
            'instanceUuids' => [$instanceUuid],
            'disableResponse' => false,
        ], 15.0);
        echo json_encode(['status' => (int) ($packet['status'] ?? 200)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'command') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $command = (string) ($input['command'] ?? '');
        $command = trim($command);
        if ($command === '') {
            terminal_api_fail('命令不能为空。');
        }
        if (mb_strlen($command, 'UTF-8') > 20000) {
            terminal_api_fail('命令过长。');
        }
        if (preg_match('/[\x00\x0A\x0D]/', $command) === 1) {
            terminal_api_fail('命令包含非法字符。');
        }
        $client->streamCommand($instanceUuid, $command);
        echo json_encode(['status' => 200], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'input') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $rawInput = (string) ($input['input'] ?? '');
        if ($rawInput === '') {
            terminal_api_fail('输入无效。');
        }
        if (mb_strlen($rawInput, 'UTF-8') > 256) {
            terminal_api_fail('输入过长。');
        }
        if (strpos($rawInput, "\x00") !== false || strpos($rawInput, "\n") !== false) {
            terminal_api_fail('输入包含非法字符。');
        }
        $client->streamInput($instanceUuid, $rawInput);
        echo json_encode(['status' => 200], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'resize') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $cols = (int) ($input['cols'] ?? 0);
        $rows = (int) ($input['rows'] ?? 0);
        if ($cols < 20 || $cols > 500 || $rows < 5 || $rows > 200) {
            terminal_api_fail('终端尺寸无效。');
        }
        $client->streamResize($instanceUuid, $cols, $rows);
        echo json_encode(['status' => 200], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'subscribe') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $client->subscribe($instanceUuid);
        echo json_encode(['status' => 200], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileLimits') {
        echo json_encode([
            'status' => 200,
            'data' => ['maxUploadBytes' => 104857600, 'maxEditBytes' => 5242880],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileList') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $target = terminal_file_relative_path($input, 'path', true);
        $page = (int) ($input['page'] ?? 0);
        $pageSize = (int) ($input['pageSize'] ?? 50);
        if ($page < 0 || $page > 100000) {
            $page = 0;
        }
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 50;
        }
        $search = (string) ($input['search'] ?? '');
        if (mb_strlen($search, 'UTF-8') > 64 || preg_match('/[\x00-\x1F\x7F<>:"|?*;\\\\]/u', $search) === 1) {
            terminal_api_fail('搜索关键字无效。');
        }
        $packet = $client->request('file/list', [
            'instanceUuid' => $instanceUuid,
            'target' => $target,
            'page' => $page,
            'pageSize' => $pageSize,
            'fileName' => $search,
        ], 15.0);
        $data = is_array($packet['data'] ?? null) ? $packet['data'] : [];
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'name' => (string) ($item['name'] ?? ''),
                'size' => (int) ($item['size'] ?? 0),
                'time' => (string) ($item['time'] ?? ''),
                'mode' => (int) ($item['mode'] ?? 0),
                'type' => (int) ($item['type'] ?? 1),
            ];
        }
        echo json_encode([
            'status' => (int) ($packet['status'] ?? 200),
            'data' => [
                'items' => $items,
                'page' => (int) ($data['page'] ?? 0),
                'pageSize' => (int) ($data['pageSize'] ?? 0),
                'total' => (int) ($data['total'] ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileRead') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $target = terminal_file_relative_path($input, 'path');
        $packet = $client->request('file/edit', [
            'instanceUuid' => $instanceUuid,
            'target' => $target,
        ], 20.0);
        $text = is_string($packet['data'] ?? null) ? $packet['data'] : '';
        if (strlen($text) > 5242880) {
            $text = substr($text, 0, 5242880);
        }
        echo json_encode([
            'status' => (int) ($packet['status'] ?? 200),
            'data' => ['text' => $text, 'size' => strlen($text)],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileWrite') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $target = terminal_file_relative_path($input, 'path');
        $text = (string) ($input['text'] ?? '');
        if (strlen($text) > 5242880) {
            terminal_api_fail('文件内容过大（最大 5MB）。');
        }
        $packet = $client->request('file/edit', [
            'instanceUuid' => $instanceUuid,
            'target' => $target,
            'text' => $text,
        ], 30.0);
        echo json_encode(['status' => (int) ($packet['status'] ?? 200)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileMkdir') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $target = terminal_file_relative_path($input, 'path');
        $packet = $client->request('file/mkdir', [
            'instanceUuid' => $instanceUuid,
            'target' => $target,
        ], 15.0);
        echo json_encode(['status' => (int) ($packet['status'] ?? 200)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileTouch') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $target = terminal_file_relative_path($input, 'path');
        $packet = $client->request('file/touch', [
            'instanceUuid' => $instanceUuid,
            'target' => $target,
        ], 15.0);
        echo json_encode(['status' => (int) ($packet['status'] ?? 200)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileDelete') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $paths = $input['paths'] ?? [];
        if (!is_array($paths) || !$paths || count($paths) > 20) {
            terminal_api_fail('删除目标无效。');
        }
        $targets = [];
        foreach ($paths as $path) {
            $targets[] = terminal_file_relative_path(['path' => (string) $path], 'path');
        }
        $packet = $client->request('file/delete', [
            'instanceUuid' => $instanceUuid,
            'targets' => $targets,
        ], 20.0);
        echo json_encode(['status' => (int) ($packet['status'] ?? 200)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileMove') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $source = terminal_file_relative_path($input, 'source');
        $destination = terminal_file_relative_path($input, 'destination');
        $packet = $client->request('file/move', [
            'instanceUuid' => $instanceUuid,
            'targets' => [[$source, $destination]],
        ], 20.0);
        echo json_encode(['status' => (int) ($packet['status'] ?? 200)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileDownload') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $target = terminal_file_relative_path($input, 'path');
        $url = $client->registerFileDownload($instanceUuid, $target);
        echo json_encode(['status' => 200, 'data' => ['url' => $url]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fileUpload') {
        $instanceUuid = terminal_require_uuid($input, 'instanceUuid');
        $target = terminal_file_relative_path($input, 'path', true);
        $url = $client->registerFileUpload($instanceUuid, $target);
        echo json_encode(['status' => 200, 'data' => ['url' => $url]], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $exception) {
    error_log('Terminal daemon request failed: ' . $exception->getMessage());
    terminal_api_fail($exception->getMessage(), 502);
}

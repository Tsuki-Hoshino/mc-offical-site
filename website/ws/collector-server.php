<?php
declare(strict_types=1);

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;
use Workerman\Worker;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/api/lib/websocket.php';
require_once dirname(__DIR__) . '/api/lib/history.php';

$runtimeDirectory = getenv('MC_WS_RUNTIME_DIR') ?: dirname(__DIR__) . '/data/runtime';
if (!is_dir($runtimeDirectory) && !mkdir($runtimeDirectory, 0750, true) && !is_dir($runtimeDirectory)) {
    fwrite(STDERR, "Runtime directory unavailable\n");
    exit(1);
}

Worker::$pidFile = $runtimeDirectory . '/collector-ws.pid';
Worker::$logFile = $runtimeDirectory . '/collector-ws.log';

$log = static function (string $message) use ($runtimeDirectory): void {
    try {
        $file = $runtimeDirectory . '/status-server.log';
        if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
            @unlink($file . '.old');
            @rename($file, $file . '.old');
        }
        @file_put_contents($file, '[' . gmdate('c') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignored) {
    }
};

$websocketListen = getenv('MC_WS_LISTEN') ?: 'websocket://127.0.0.1:8765';
$historyListen = getenv('MC_WS_HISTORY_LISTEN') ?: 'text://127.0.0.1:8766';
$historyTarget = getenv('MC_WS_HISTORY_TARGET') ?: 'text://127.0.0.1:8766';
$maxBytes = max(1024, (int) (sync_config()['max_bytes'] ?? 33554432));

$historyWorker = new Worker($historyListen);
$historyWorker->name = 'mc-site-history-writer';
$historyWorker->count = 1;
$historyWorker->onMessage = static function (TcpConnection $connection, string $message) use ($log): void {
    try {
        $record = json_decode($message, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || !is_array($record['payload'] ?? null)) {
            throw new InvalidArgumentException('invalid_history_message');
        }
        history_store_status($record['payload'], (string) ($record['received_at'] ?? gmdate('c')));
    } catch (Throwable $error) {
        error_log('WebSocket history store failed: ' . $error->getMessage());
        $log('history_store_failed ' . $error->getMessage());
    }
};

$historyConnection = null;
$connectionStates = new WeakMap();
$websocketWorker = new Worker($websocketListen);
$websocketWorker->name = 'mc-site-collector-ws';
$websocketWorker->count = 1;
$websocketWorker->onWorkerStart = static function (Worker $worker) use ($historyTarget, &$historyConnection): void {
    $connectHistory = null;
    $connectHistory = static function () use ($historyTarget, &$connectHistory, &$historyConnection): void {
        $connection = new AsyncTcpConnection($historyTarget);
        $connection->onError = static function (): void {
        };
        $connection->onClose = static function () use (&$historyConnection, &$connectHistory): void {
            $historyConnection = null;
            Timer::add(1.0, $connectHistory, [], false);
        };
        $connection->onConnect = static function (AsyncTcpConnection $connection) use (&$historyConnection): void {
            $historyConnection = $connection;
        };
        $connection->connect();
    };
    $connectHistory();
};
$websocketWorker->onConnect = static function (TcpConnection $connection) use ($maxBytes, &$connectionStates): void {
    $connection->maxPackageSize = $maxBytes;
    $connectionStates[$connection] = [
        'authenticated' => false,
        'mode' => 'pending',
        'remote_address' => $connection->getRemoteIp(),
        'authentication_timer' => null,
    ];
    $connectionStates[$connection]['authentication_timer'] = Timer::add(5.0, static function () use ($connection, &$connectionStates): void {
        if (isset($connectionStates[$connection]) && !$connectionStates[$connection]['authenticated']) {
            $connection->close(websocket_error('authentication_required'));
        }
    }, [], false);
};
$websocketWorker->onWebSocketConnect = static function (TcpConnection $connection, Request $request) use (&$connectionStates, $log): void {
    $path = $request->path();
    if ($path === '/ws/status') {
        $connectionStates[$connection]['mode'] = 'public';
        Timer::del($connectionStates[$connection]['authentication_timer']);
        $log('public_connect remote=' . ($connectionStates[$connection]['remote_address'] ?? ''));
        $record = sync_read_data('status');
        if ($record !== null) {
            $connection->send(websocket_public_status_message($record));
        }
        return;
    }
    if ($path !== '/ws/collector') {
        $log('unknown_path ' . $path);
        $connection->close(websocket_error('not_found'));
        return;
    }
    $connectionStates[$connection]['mode'] = 'collector';
    $log('collector_connect remote=' . ($connectionStates[$connection]['remote_address'] ?? ''));
    $forwarded = trim((string) $request->header('x-real-ip', ''));
    if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
        $connectionStates[$connection]['remote_address'] = $forwarded;
    }
};
$websocketWorker->onClose = static function (TcpConnection $connection) use (&$connectionStates, $log): void {
    $closingState = $connectionStates[$connection] ?? null;
    $log('close mode=' . (is_array($closingState) ? ($closingState['mode'] ?? '?') : '?'));
    unset($connectionStates[$connection]);
};
$websocketWorker->onMessage = static function (TcpConnection $connection, string $message) use ($websocketWorker, &$historyConnection, &$connectionStates, $log): void {
    $state = $connectionStates[$connection] ?? null;
    if (!is_array($state) || $state['mode'] === 'public') {
        $connection->close(websocket_error('read_only'));
        return;
    }
    if ($state['mode'] !== 'collector') {
        $connection->close(websocket_error('authentication_required'));
        return;
    }
    if (!$state['authenticated']) {
        try {
            $request = json_decode($message, true, 8, JSON_THROW_ON_ERROR);
            $expected = getenv('MC_WS_TOKEN') ?: (string) (sync_config()['token'] ?? '');
            $actual = is_array($request) ? (string) ($request['token'] ?? '') : '';
            if (($request['action'] ?? null) !== 'authenticate' || $expected === '' || !hash_equals($expected, $actual)) {
                throw new RuntimeException('unauthorized');
            }
            $connectionStates[$connection]['authenticated'] = true;
            Timer::del($state['authentication_timer']);
            $connection->send(json_encode(['ok' => true, 'action' => 'ready'], JSON_THROW_ON_ERROR));
            $log('collector_authenticated remote=' . ($state['remote_address'] ?? ''));
        } catch (Throwable $error) {
            $log('collector_auth_failed remote=' . ($state['remote_address'] ?? ''));
            $connection->close(websocket_error('unauthorized'));
        }
        return;
    }

    $id = null;
    try {
        $envelope = websocket_validate_envelope($message);
        $id = $envelope['id'];
        $record = websocket_record($envelope, (string) $state['remote_address']);
        $connection->send(websocket_ack($id, $envelope['type'], $record['received_at']));
        if ($envelope['type'] === 'status') {
            $publicMessage = websocket_public_status_message($record);
            $publicCount = 0;
            foreach ($websocketWorker->connections as $publicConnection) {
                $publicState = $connectionStates[$publicConnection] ?? null;
                if (is_array($publicState) && ($publicState['mode'] ?? '') === 'public') {
                    $publicConnection->send($publicMessage);
                    $publicCount++;
                }
            }
            $log('status_received id=' . $id . ' received_at=' . $record['received_at'] . ' public_clients=' . $publicCount);
        }
        $historyMessage = websocket_history_message($record);
        if ($historyMessage !== null && $historyConnection instanceof AsyncTcpConnection) {
            $historyConnection->send($historyMessage);
        }
    } catch (InvalidArgumentException $error) {
        $connection->send(websocket_error($error->getMessage(), $id));
    } catch (Throwable $error) {
        error_log('WebSocket collector write failed: ' . $error->getMessage());
        $connection->send(websocket_error('storage_unavailable', $id));
    }
};

Worker::runAll();

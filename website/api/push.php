<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/sync.php';
require_once __DIR__ . '/lib/history.php';

sync_require_method('POST');
sync_require_auth();

$type = sync_normalize_type((string) ($_GET['type'] ?? ''));
$data = sync_decode_request_json();

$record = [
    'type' => $type,
    'received_at' => gmdate('c'),
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'payload' => $data,
];

sync_write_data($type, $record);

$historyStored = null;
if ($type === 'status') {
    try {
        history_store_status($data, $record['received_at']);
        $historyStored = true;
    } catch (Throwable $error) {
        $historyStored = false;
        error_log('history store failed: ' . $error->getMessage());
    }
}

sync_json_response([
    'ok' => true,
    'type' => $type,
    'received_at' => $record['received_at'],
    'bytes' => isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null,
    'history_stored' => $historyStored,
]);

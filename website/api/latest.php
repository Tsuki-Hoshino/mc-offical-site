<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/sync.php';

sync_require_method('GET');

$type = sync_normalize_type((string) ($_GET['type'] ?? ''));
$record = sync_read_data($type);
if ($record === null) {
    sync_json_response(['ok' => false, 'error' => 'not_found', 'type' => $type], 404);
}

unset($record['remote_addr']);
sync_json_response(['ok' => true] + $record);

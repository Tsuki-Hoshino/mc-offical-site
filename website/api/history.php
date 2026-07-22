<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/sync.php';
require_once __DIR__ . '/lib/history.php';

sync_require_method('GET');

try {
    $metric = strtolower(trim((string) ($_GET['metric'] ?? 'mspt')));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $from = history_parse_time((string) ($_GET['from'] ?? ''), $now->sub(new DateInterval('PT1H')));
    $to = history_parse_time((string) ($_GET['to'] ?? ''), $now);
    if ($from >= $to) {
        throw new InvalidArgumentException('invalid_range');
    }
    $result = history_read($metric, $from, $to);
    sync_json_response([
        'ok' => true,
        'metric' => $metric,
        'from' => $from->format(DATE_ATOM),
        'to' => $to->format(DATE_ATOM),
        'bucket_seconds' => $result['bucket_seconds'],
        'points' => $result['points'],
    ]);
} catch (InvalidArgumentException $error) {
    sync_json_response(['ok' => false, 'error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('history query failed: ' . $error->getMessage());
    sync_json_response(['ok' => false, 'error' => 'history_unavailable'], 503);
}
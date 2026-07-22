<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/history.php';

$deleted = history_db()->exec(
    'DELETE FROM server_metrics WHERE recorded_at < UTC_TIMESTAMP(3) - INTERVAL 1 YEAR'
);

fwrite(STDOUT, sprintf(
    '[%s] deleted %d server_metrics rows older than one year',
    gmdate('c'),
    (int) $deleted
) . PHP_EOL);

<?php
declare(strict_types=1);

function history_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    $config = is_file($path) ? require $path : [];
    if (!is_array($config)) {
        $config = [];
    }
    return $config;
}

function history_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = history_config();
    foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new RuntimeException('database_not_configured');
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string) $config['host'],
        (int) $config['port'],
        (string) $config['database']
    );
    $pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function history_number(array $runtime, string $key): ?float
{
    $value = $runtime[$key] ?? null;
    return is_numeric($value) ? (float) $value : null;
}

function history_store_status(array $payload, string $receivedAt): void
{
    $runtime = isset($payload['runtime']) && is_array($payload['runtime']) ? $payload['runtime'] : [];
    $sourceTime = (string) ($payload['generated_at'] ?? $receivedAt);
    try {
        $recordedAt = (new DateTimeImmutable($sourceTime))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    } catch (Throwable $error) {
        $recordedAt = gmdate('Y-m-d H:i:s.000');
    }

    $sql = <<<'SQL'
INSERT INTO server_metrics (
    recorded_at, mspt, process_cpu_percent, process_memory_bytes,
    host_cpu_percent, host_memory_used_bytes, host_memory_total_bytes,
    network_receive_rate, network_send_rate
) VALUES (
    :recorded_at, :mspt, :process_cpu_percent, :process_memory_bytes,
    :host_cpu_percent, :host_memory_used_bytes, :host_memory_total_bytes,
    :network_receive_rate, :network_send_rate
)
ON DUPLICATE KEY UPDATE
    mspt = VALUES(mspt),
    process_cpu_percent = VALUES(process_cpu_percent),
    process_memory_bytes = VALUES(process_memory_bytes),
    host_cpu_percent = VALUES(host_cpu_percent),
    host_memory_used_bytes = VALUES(host_memory_used_bytes),
    host_memory_total_bytes = VALUES(host_memory_total_bytes),
    network_receive_rate = VALUES(network_receive_rate),
    network_send_rate = VALUES(network_send_rate)
SQL;

    $statement = history_db()->prepare($sql);
    $statement->execute([
        ':recorded_at' => $recordedAt,
        ':mspt' => history_number($runtime, 'mspt'),
        ':process_cpu_percent' => history_number($runtime, 'process_cpu_percent'),
        ':process_memory_bytes' => history_number($runtime, 'process_memory_bytes'),
        ':host_cpu_percent' => history_number($runtime, 'host_cpu_percent'),
        ':host_memory_used_bytes' => history_number($runtime, 'host_memory_used_bytes'),
        ':host_memory_total_bytes' => history_number($runtime, 'host_memory_total_bytes'),
        ':network_receive_rate' => history_number($runtime, 'network_receive_rate'),
        ':network_send_rate' => history_number($runtime, 'network_send_rate'),
    ]);
}

function history_metric_columns(string $metric): array
{
    $metrics = [
        'mspt' => ['mspt'],
        'process_cpu' => ['process_cpu_percent'],
        'process_memory' => ['process_memory_bytes'],
        'host_cpu' => ['host_cpu_percent'],
        'host_memory' => ['host_memory_used_bytes', 'host_memory_total_bytes'],
        'network' => ['network_receive_rate', 'network_send_rate'],
    ];
    if (!isset($metrics[$metric])) {
        throw new InvalidArgumentException('invalid_metric');
    }
    return $metrics[$metric];
}

function history_parse_time(string $value, DateTimeImmutable $default): DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return $default;
    }
    if (preg_match('/^\d{10}(?:\d{3})?$/', $value) === 1) {
        $seconds = strlen($value) === 13 ? ((int) $value / 1000) : (int) $value;
        return (new DateTimeImmutable('@' . (string) floor($seconds)))->setTimezone(new DateTimeZone('UTC'));
    }

    $timezone = new DateTimeZone('Asia/Shanghai');
    $date = DateTimeImmutable::createFromFormat('!Y/m/d H:i:s', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException('invalid_time');
    }
    return $date->setTimezone(new DateTimeZone('UTC'));
}

function history_read(string $metric, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $columns = history_metric_columns($metric);
    $rangeSeconds = max(1, $to->getTimestamp() - $from->getTimestamp());
    $bucketSeconds = max(1, (int) ceil($rangeSeconds / 1200));
    $bucketExpression = "FLOOR(UNIX_TIMESTAMP(recorded_at) / {$bucketSeconds})";
    $select = ["DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(MIN(recorded_at)) / {$bucketSeconds}) * {$bucketSeconds}), '%Y-%m-%dT%H:%i:%sZ') AS time"];
    foreach ($columns as $column) {
        $select[] = "AVG(`{$column}`) AS `{$column}`";
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM server_metrics WHERE recorded_at BETWEEN :from AND :to'
        . " GROUP BY {$bucketExpression} ORDER BY {$bucketExpression}";
    $statement = history_db()->prepare($sql);
    $statement->execute([
        ':from' => $from->format('Y-m-d H:i:s'),
        ':to' => $to->format('Y-m-d H:i:s'),
    ]);

    $points = [];
    foreach ($statement->fetchAll() as $row) {
        $point = ['time' => $row['time']];
        foreach ($columns as $column) {
            $point[$column] = $row[$column] === null ? null : (float) $row[$column];
        }
        $points[] = $point;
    }
    return ['bucket_seconds' => $bucketSeconds, 'points' => $points];
}

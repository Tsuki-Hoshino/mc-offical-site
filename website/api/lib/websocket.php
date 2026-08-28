<?php
declare(strict_types=1);

require_once __DIR__ . '/sync.php';

function websocket_validate_envelope(string $message): array
{
    if (strlen($message) > (int) (sync_config()['max_bytes'] ?? 33554432)) {
        throw new InvalidArgumentException('payload_too_large');
    }

    $envelope = json_decode($message, true);
    if (!is_array($envelope)) {
        throw new InvalidArgumentException('invalid_json');
    }

    $id = trim((string) ($envelope['id'] ?? ''));
    if ($id === '' || strlen($id) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $id) !== 1) {
        throw new InvalidArgumentException('invalid_id');
    }

    $type = strtolower(trim((string) ($envelope['type'] ?? '')));
    if (preg_match('/^[a-z0-9_-]{1,40}$/', $type) !== 1) {
        throw new InvalidArgumentException('invalid_type');
    }
    $allowed = sync_config()['allowed_types'] ?? [];
    if (is_array($allowed) && $allowed !== [] && !in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('type_not_allowed');
    }

    $payload = $envelope['payload'] ?? null;
    if (!is_array($payload)) {
        throw new InvalidArgumentException('invalid_payload');
    }

    return ['id' => $id, 'type' => $type, 'payload' => $payload];
}

function websocket_record(array $envelope, string $remoteAddress): array
{
    $receivedAt = gmdate('c');
    $record = [
        'type' => $envelope['type'],
        'received_at' => $receivedAt,
        'remote_addr' => $remoteAddress,
        'payload' => $envelope['payload'],
    ];
    sync_store_data($envelope['type'], $record);
    return $record;
}

function websocket_ack(string $id, string $type, string $receivedAt): string
{
    return json_encode([
        'ok' => true,
        'id' => $id,
        'type' => $type,
        'received_at' => $receivedAt,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function websocket_error(string $code, ?string $id = null): string
{
    $payload = ['ok' => false, 'error' => $code];
    if ($id !== null && $id !== '') {
        $payload['id'] = $id;
    }
    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function websocket_history_message(array $record): ?string
{
    if (($record['type'] ?? '') !== 'status') {
        return null;
    }
    $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];
    return json_encode([
        'received_at' => $record['received_at'],
        'payload' => [
            'generated_at' => $payload['generated_at'] ?? $record['received_at'],
            'runtime' => is_array($payload['runtime'] ?? null) ? $payload['runtime'] : [],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function websocket_public_status_message(array $record): string
{
    return json_encode(['ok' => true] + sync_public_record($record), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

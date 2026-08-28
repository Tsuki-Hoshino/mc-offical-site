<?php
declare(strict_types=1);

/**
 * MCSManager Daemon 客户端（engine.io v4 / socket.io v4，HTTP 长轮询传输）。
 *
 * 只使用 PHP 标准能力与 curl 扩展，与站点技术栈保持一致。
 * 密钥仅保存在服务器端配置中，绝不输出到浏览器。
 */
final class TerminalDaemonClient
{
    private $baseUrl;
    private $key;
    private $sessionFile;
    private $pollLockFile;
    private $mainLockFile;

    public function __construct(string $baseUrl, string $key, string $stateDir, string $statePrefix = 'terminal-daemon')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->key = $key;
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0770, true);
        }
        $this->sessionFile = rtrim($stateDir, '/\\') . '/' . $statePrefix . '.json';
        $this->pollLockFile = rtrim($stateDir, '/\\') . '/' . $statePrefix . '-poll.lock';
        $this->mainLockFile = rtrim($stateDir, '/\\') . '/' . $statePrefix . '-main.lock';
    }

    private function http(string $method, string $url, string $body = '', float $timeout = 10.0, bool $timeoutIsEmpty = false): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('面板连接初始化失败。');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT_MS => 4000,
            CURLOPT_TIMEOUT_MS => (int) max(1000, $timeout * 1000),
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=UTF-8'],
            CURLOPT_USERAGENT => 'mc-site-terminal/1.0',
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($handle);
        $errno = (int) curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($response === false) {
            if ($timeoutIsEmpty && $errno === CURLE_OPERATION_TIMEOUTED) {
                return [0, ''];
            }
            throw new RuntimeException('面板连接失败：' . ($error !== '' ? $error : '网络错误'));
        }
        return [$status, (string) $response];
    }

    private static function decodePackets(string $payload): array
    {
        $packets = [];
        foreach (explode("\x1e", $payload) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $packets[] = [$chunk[0], substr($chunk, 1)];
        }
        return $packets;
    }

    private static function parseSocketFrame(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        $frameType = (int) $body[0];
        if ($frameType !== 2) {
            return null;
        }
        $frame = json_decode(substr($body, 1), true);
        if (!is_array($frame) || !isset($frame[0], $frame[1])) {
            return null;
        }
        return ['event' => (string) $frame[0], 'packet' => $frame[1]];
    }

    private function readState(): array
    {
        $raw = @file_get_contents($this->sessionFile);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function writeState(array $state): void
    {
        $tmp = $this->sessionFile . '.tmp';
        $encoded = json_encode($state, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }
        file_put_contents($tmp, $encoded);
        @rename($tmp, $this->sessionFile);
        @chmod($this->sessionFile, 0660);
    }

    private function mergeState(array $patch): void
    {
        $lock = @fopen($this->sessionFile . '.lock', 'c+');
        if ($lock && flock($lock, LOCK_EX)) {
            $state = $this->readState();
            foreach ($patch as $key => $value) {
                $state[$key] = $value;
            }
            $this->writeState($state);
            flock($lock, LOCK_UN);
        }
        if ($lock) {
            fclose($lock);
        }
    }

    private function pollingUrl(string $sid): string
    {
        return $this->baseUrl . '/socket.io/?EIO=4&transport=polling&sid=' . rawurlencode($sid);
    }

    private function handshake(): string
    {
        $nonce = substr(bin2hex(random_bytes(4)), 0, 12);
        [$status, $body] = $this->http('GET', $this->baseUrl . '/socket.io/?EIO=4&transport=polling&t=' . $nonce, '', 8.0);
        if ($status !== 200) {
            throw new RuntimeException('面板握手失败（HTTP ' . $status . '）。');
        }
        foreach (self::decodePackets($body) as [$type, $payload]) {
            if ($type !== '0') {
                continue;
            }
            $open = json_decode($payload, true);
            if (is_array($open) && is_string($open['sid'] ?? null) && $open['sid'] !== '') {
                return (string) $open['sid'];
            }
        }
        throw new RuntimeException('面板握手响应无效。');
    }

    private function connect(string $sid): void
    {
        [$status] = $this->http('POST', $this->pollingUrl($sid), '40', 8.0);
        if ($status !== 200) {
            throw new RuntimeException('面板会话建立失败（HTTP ' . $status . '）。');
        }
    }

    private function postEvent(string $sid, string $event, $data, string $uuid): void
    {
        $payload = json_encode(
            [$event, ['uuid' => $uuid, 'status' => 200, 'event' => $event, 'data' => $data]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            throw new RuntimeException('面板请求编码失败。');
        }
        [$status, $body] = $this->http('POST', $this->pollingUrl($sid), '42' . $payload, 8.0);
        if ($status !== 200 || trim($body) !== 'ok') {
            throw new RuntimeException('面板请求被拒绝（HTTP ' . $status . '）。');
        }
    }

    private function waitResponse(string $sid, string $event, string $uuid, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            [$status, $body] = $this->http('GET', $this->pollingUrl($sid), '', 22.0, true);
            if ($status === 0) {
                break;
            }
            if ($status !== 200) {
                throw new RuntimeException('面板会话已失效（HTTP ' . $status . '）。');
            }
            foreach (self::decodePackets($body) as [$type, $payload]) {
                if ($type === '2') {
                    $this->http('POST', $this->pollingUrl($sid), '3', 6.0);
                    continue;
                }
                if ($type !== '4') {
                    continue;
                }
                $parsed = self::parseSocketFrame($payload);
                if ($parsed === null) {
                    continue;
                }
                if ($parsed['event'] === $event && is_array($parsed['packet'])
                    && (string) ($parsed['packet']['uuid'] ?? '') === $uuid) {
                    return $parsed['packet'];
                }
            }
        }
        throw new RuntimeException('面板响应超时。');
    }

    private function createSession(bool $withStream, ?string $streamUuid): array
    {
        $sid = $this->handshake();
        $this->connect($sid);
        $authUuid = bin2hex(random_bytes(8));
        $authPayload = json_encode(
            ['auth', ['uuid' => $authUuid, 'status' => 200, 'event' => 'auth', 'data' => $this->key]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($authPayload === false) {
            throw new RuntimeException('面板请求编码失败。');
        }
        [$status, $body] = $this->http('POST', $this->pollingUrl($sid), '42' . $authPayload, 8.0);
        if ($status !== 200 || trim($body) !== 'ok') {
            throw new RuntimeException('面板认证请求被拒绝（HTTP ' . $status . '）。');
        }
        $authPacket = $this->waitResponse($sid, 'auth', $authUuid, 6.0);
        if (($authPacket['data'] ?? null) !== true) {
            throw new RuntimeException('面板密钥校验未通过，请检查后台配置。');
        }

        $result = ['sid' => $sid, 'streamUuid' => ''];
        if ($withStream && $streamUuid !== null && $streamUuid !== '') {
            $password = bin2hex(random_bytes(16));
            $this->postEvent($sid, 'passport/register', [
                'name' => 'stream_channel',
                'password' => $password,
                'parameter' => ['instanceUuid' => $streamUuid],
                'count' => 1,
            ], bin2hex(random_bytes(8)));
            $this->postEvent($sid, 'stream/auth', ['password' => $password], bin2hex(random_bytes(8)));
            $result['streamUuid'] = $streamUuid;
        }
        return $result;
    }

    private function ensureMainSession(): string
    {
        $state = $this->readState();
        if (is_string($state['main'] ?? null) && $state['main'] !== '') {
            return (string) $state['main'];
        }
        $created = $this->createSession(false, null);
        $this->mergeState(['main' => $created['sid']]);
        return (string) $created['sid'];
    }

    private function ensureStreamSession(string $instanceUuid): string
    {
        $state = $this->readState();
        if (is_string($state['stream'] ?? null) && $state['stream'] !== ''
            && ($state['streamUuid'] ?? null) === $instanceUuid) {
            return (string) $state['stream'];
        }
        $created = $this->createSession(true, $instanceUuid);
        $this->mergeState(['stream' => $created['sid'], 'streamUuid' => $created['streamUuid']]);
        return (string) $created['sid'];
    }

    /**
     * 发送事件并等待同 uuid 的响应（主会话，短轮询）。
     */
    public function request(string $event, ?array $data, float $timeout = 12.0): array
    {
        $lock = @fopen($this->mainLockFile, 'c+');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            try {
                $sid = $this->ensureMainSession();
                $uuid = bin2hex(random_bytes(8));
                $this->postEvent($sid, $event, $data, $uuid);
                return $this->waitResponse($sid, $event, $uuid, $timeout);
            } catch (RuntimeException $exception) {
                $this->mergeState(['main' => '']);
                $sid = $this->ensureMainSession();
                $uuid = bin2hex(random_bytes(8));
                $this->postEvent($sid, $event, $data, $uuid);
                return $this->waitResponse($sid, $event, $uuid, $timeout);
            }
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * 建立实例输出流订阅（独立会话，仅用于接收 instance/stdout）。
     */
    public function subscribe(string $instanceUuid): void
    {
        $lock = @fopen($this->mainLockFile, 'c+');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $this->mergeState(['stream' => '', 'streamUuid' => '']);
            $this->ensureStreamSession($instanceUuid);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * 向实例仿真终端写入原始输入（PTY 模式下用于发送命令）。
     */
    public function streamWrite(string $input): void
    {
        $state = $this->readState();
        $sid = is_string($state['stream'] ?? null) && $state['stream'] !== '' ? (string) $state['stream'] : null;
        if ($sid === null) {
            throw new RuntimeException('实例尚未订阅，请先选择实例。');
        }
        $this->postEvent($sid, 'stream/write', ['input' => $input], bin2hex(random_bytes(8)));
    }

    /**
     * 发送服务器命令（PTY 模式：确保订阅后写入原始输入并回车）。
     */
    public function streamCommand(string $instanceUuid, string $command): void
    {
        $this->ensureStreamSession($instanceUuid);
        $this->streamWrite($command . "\r");
    }

    /**
     * 发送仿真终端原始输入（如 Tab 补全等，不加回车）。
     */
    public function streamInput(string $instanceUuid, string $rawInput): void
    {
        $this->ensureStreamSession($instanceUuid);
        $this->streamWrite($rawInput);
    }

    /**
     * 调整 PTY 窗口大小（同步前端终端列数/行数，避免日志提前换行）。
     */
    public function streamResize(string $instanceUuid, int $cols, int $rows): void
    {
        $this->ensureStreamSession($instanceUuid);
        $state = $this->readState();
        $sid = is_string($state['stream'] ?? null) && $state['stream'] !== '' ? (string) $state['stream'] : null;
        if ($sid === null) {
            return;
        }
        $this->postEvent($sid, 'stream/resize', ['w' => $cols, 'h' => $rows], bin2hex(random_bytes(8)));
    }

    /**
     * 注册一次性文件下载任务，返回浏览器直连 daemon 的下载 URL。
     * 文件数据流由浏览器直接向节点拉取，不经过站点服务器。
     */
    public function registerFileDownload(string $instanceUuid, string $fileName): string
    {
        $password = bin2hex(random_bytes(16));
        $this->request('passport/register', [
            'name' => 'download',
            'password' => $password,
            'parameter' => ['fileName' => $fileName, 'instanceUuid' => $instanceUuid],
            'count' => 1,
        ], 10.0);
        $name = basename(str_replace('\\', '/', $fileName));
        return $this->baseUrl . '/download/' . rawurlencode($password) . '/' . rawurlencode($name);
    }

    /**
     * 注册一次性文件上传任务，返回浏览器直连 daemon 的上传 URL。
     * 文件数据流由浏览器直接上传到节点，不经过站点服务器。
     */
    public function registerFileUpload(string $instanceUuid, string $uploadDir): string
    {
        $password = bin2hex(random_bytes(16));
        $this->request('passport/register', [
            'name' => 'upload',
            'password' => $password,
            'parameter' => ['uploadDir' => $uploadDir, 'instanceUuid' => $instanceUuid],
            'count' => 1,
        ], 10.0);
        return $this->baseUrl . '/upload/' . rawurlencode($password);
    }

    /**
     * 长轮询流会话事件。同一时刻只允许一个请求挂起，其余立即返回 busy。
     * 每次只执行一次 GET（超时窗口大于 daemon 心跳间隔 20 秒），收到数据立即返回，
     * 由前端连续衔接下一次轮询。避免主动中断请求导致会话被 daemon 判定断开。
     */
    public function poll(float $waitSeconds = 22.0): array
    {
        $lock = @fopen($this->pollLockFile, 'c+');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            try {
                $events = $this->pollLocked($waitSeconds);
                $busy = false;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            return ['events' => $events, 'busy' => $busy];
        }
        if ($lock) {
            fclose($lock);
        }
        return ['events' => [], 'busy' => true];
    }

    /**
     * 流式长轮询：持续收取事件并通过回调逐批输出，直到超时。
     */
    public function pollStream(float $duration, callable $onEvents): void
    {
        $state = $this->readState();
        $sid = is_string($state['stream'] ?? null) && $state['stream'] !== ''
            ? (string) $state['stream'] : null;
        if ($sid === null) {
            return;
        }
        $lockFile = $this->pollLockFile . '-' . hash('sha256', $sid) . '.lock';
        $lock = @fopen($lockFile, 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            return;
        }
        try {
            // 一次流式请求固定使用开始时的会话，避免实例切换后误读新会话。
            $deadline = microtime(true) + $duration;
            while (microtime(true) < $deadline) {
                $events = $this->pollLocked(8.0, $sid);
                if ($events) {
                    $onEvents($events);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function pollLocked(float $waitSeconds, ?string $fixedSid = null): array
    {
        $sid = $fixedSid;
        if ($sid === null) {
            $state = $this->readState();
            $sid = is_string($state['stream'] ?? null) && $state['stream'] !== '' ? (string) $state['stream'] : null;
        }
        if ($sid === null) {
            return [];
        }
        try {
            [$status, $body] = $this->http('GET', $this->pollingUrl($sid), '', max(22.0, $waitSeconds + 3.0), true);
        } catch (RuntimeException $exception) {
            return [['type' => 'fatal', 'error' => $exception->getMessage()]];
        }
        if ($status === 0) {
            return [];
        }
        if ($status !== 200) {
            $state = $this->readState();
            if (($state['stream'] ?? null) === $sid) {
                $this->mergeState(['stream' => '', 'streamUuid' => '']);
            }
            return [['type' => 'fatal', 'error' => '面板会话已失效，正在重新连接。']];
        }
        $events = [];
        foreach (self::decodePackets($body) as [$type, $payload]) {
            if ($type === '2') {
                $this->http('POST', $this->pollingUrl($sid), '3', 6.0);
                continue;
            }
            if ($type === '1') {
                return [['type' => 'fatal', 'error' => '面板会话已关闭。']];
            }
            if ($type !== '4') {
                continue;
            }
            $parsed = self::parseSocketFrame($payload);
            if ($parsed === null) {
                continue;
            }
            if (is_array($parsed['packet'])) {
                $events[] = [
                    'event' => $parsed['event'],
                    'status' => (int) ($parsed['packet']['status'] ?? 200),
                    'uuid' => (string) ($parsed['packet']['uuid'] ?? ''),
                    'data' => $parsed['packet']['data'] ?? null,
                ];
            }
        }
        return $events;
    }
}

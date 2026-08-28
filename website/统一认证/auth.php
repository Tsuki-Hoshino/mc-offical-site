<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_lifetime', '0');
    session_name('mc_machine_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => auth_request_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function auth_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '' && str_contains(',' . $forwardedProto . ',', ',https,')) {
        return true;
    }

    $cfVisitor = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
    if ($cfVisitor !== '') {
        $decoded = json_decode($cfVisitor, true);
        if (is_array($decoded) && strtolower((string) ($decoded['scheme'] ?? '')) === 'https') {
            return true;
        }
    }

    return false;
}

function auth_client_ip(): string
{
    $candidates = [
        (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $parts = explode(',', $candidate);
        foreach ($parts as $part) {
            $part = trim($part);
            if (filter_var($part, FILTER_VALIDATE_IP)) {
                return $part;
            }
        }
    }

    return '0.0.0.0';
}

function auth_current_user(): ?array
{
    static $loadedId = null;
    static $user = null;
    auth_session_start();
    $userId = (int) ($_SESSION['machine_user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }
    if ($loadedId === $userId) {
        return $user;
    }
    $stmt = auth_db()->prepare('SELECT id, username, role, enabled FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $record = $stmt->fetch();
    $loadedId = $userId;
    $user = $record && (int) $record['enabled'] === 1 ? $record : null;
    if (!$user) {
        unset($_SESSION['machine_user_id'], $_SESSION['machine_authenticated_at']);
    }
    return $user;
}

function auth_is_authenticated(): bool
{
    auth_session_start();
    $authenticatedAt = (int) ($_SESSION['machine_authenticated_at'] ?? 0);
    if ($authenticatedAt <= 0 || time() - $authenticatedAt > AUTH_SESSION_SECONDS) {
        unset($_SESSION['machine_user_id'], $_SESSION['machine_authenticated_at']);
        return false;
    }
    return auth_current_user() !== null;
}

function auth_is_superadmin(): bool
{
    $user = auth_is_authenticated() ? auth_current_user() : null;
    return $user && $user['role'] === 'superadmin';
}

function auth_is_site_admin(): bool
{
    $user = auth_is_authenticated() ? auth_current_user() : null;
    return $user && in_array((string) $user['role'], ['editor', 'superadmin'], true);
}

function auth_require_site_admin(): void
{
    auth_require();
    if (!auth_is_site_admin()) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function auth_normalize_next(?string $value, string $fallback = '/'): string
{
    $value = trim((string) $value);
    if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) || str_contains($value, '\\')) {
        return $fallback;
    }
    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return $fallback;
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return $fallback;
    }
    $decodedPath = rawurldecode($path);
    foreach (explode('/', $decodedPath) as $segment) {
        if ($segment === '..' || $segment === '.') {
            return $fallback;
        }
    }
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $path . $query;
}

function auth_scoped_next(string $basePath, ?string $value, string $fallback): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    if ($value[0] === '/') {
        return auth_normalize_next($value, $fallback);
    }
    if (str_contains($value, '\\') || str_starts_with($value, '//') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $value)) {
        return $fallback;
    }
    return auth_normalize_next(rtrim($basePath, '/') . '/' . ltrim($value, '/'), $fallback);
}

function auth_current_request(): string
{
    return auth_normalize_next((string) ($_SERVER['REQUEST_URI'] ?? '/'), '/');
}

function auth_login_url(string $next = '/'): string
{
    return '/统一认证/?next=' . rawurlencode(auth_normalize_next($next, '/'));
}

function auth_require(): void
{
    if (auth_is_authenticated()) {
        return;
    }
    header('Cache-Control: no-store, private');
    header('Location: ' . auth_login_url(auth_current_request()), true, 302);
    exit;
}

function auth_require_role(string $role): void
{
    auth_require();
    if (!auth_is_authenticated() || (auth_current_user()['role'] ?? '') !== $role) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function auth_csrf_token(): string
{
    auth_session_start();
    if (empty($_SESSION['machine_csrf'])) {
        $_SESSION['machine_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['machine_csrf'];
}

function auth_verify_csrf(?string $token = null): void
{
    $token = $token ?? (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(auth_csrf_token(), $token)) {
        throw new RuntimeException('页面已过期，请刷新后重试。');
    }
}

function auth_hash_password(string $password): string
{
    $salt = random_bytes(16);
    $hash = hash_pbkdf2('sha256', $password, $salt, PASSWORD_ITERATIONS, 32, true);
    return 'pbkdf2_sha256$' . PASSWORD_ITERATIONS . '$' . base64_encode($salt) . '$' . base64_encode($hash);
}

function auth_validate_new_password(string $password): array
{
    $errors = [];
    $length = mb_strlen($password, 'UTF-8');
    if ($length < 6 || $length > 128) {
        $errors[] = '密码长度必须为 6-128 位。';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = '密码必须包含数字。';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = '密码必须包含大写字母。';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = '密码必须包含小写字母。';
    }
    if (!preg_match('/[^A-Za-z0-9]/u', $password)) {
        $errors[] = '密码必须包含特殊符号。';
    }
    return $errors;
}

function auth_verify_password(string $password, string $stored): bool
{
    $parts = explode('$', $stored);
    if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256') {
        return false;
    }
    $iterations = (int) $parts[1];
    $salt = base64_decode($parts[2], true);
    $expected = base64_decode($parts[3], true);
    if ($iterations < 100000 || $salt === false || $expected === false || strlen($expected) !== 32) {
        return false;
    }
    return hash_equals($expected, hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true));
}

function auth_password_needs_rehash(string $stored): bool
{
    $parts = explode('$', $stored);
    return count($parts) !== 4 || (int) ($parts[1] ?? 0) !== PASSWORD_ITERATIONS;
}

function auth_client_key(string $username = ''): string
{
    return hash('sha256', auth_client_ip() . '|' . strtolower($username));
}

function auth_rate_state(string $username = '', bool $failure = false, bool $clear = false): array
{
    if (!is_dir(AUTH_RATE_DIR)) {
        @mkdir(AUTH_RATE_DIR, 0770, true);
    }
    $path = AUTH_RATE_DIR . '/' . auth_client_key($username) . '.json';
    $handle = @fopen($path, 'c+');
    if (!$handle) {
        return ['blocked_for' => 0, 'failures' => 0];
    }
    flock($handle, LOCK_EX);
    $state = json_decode(stream_get_contents($handle) ?: '{}', true);
    $state = is_array($state) ? $state : [];
    $now = time();
    $failures = array_values(array_filter((array) ($state['failures'] ?? []), static fn ($time): bool => is_int($time) && $time >= $now - AUTH_FAILURE_WINDOW));
    $blockedUntil = (int) ($state['blocked_until'] ?? 0);
    if ($clear) {
        $failures = [];
        $blockedUntil = 0;
    } elseif ($failure && $blockedUntil <= $now) {
        $failures[] = $now;
        if (count($failures) >= AUTH_MAX_FAILURES) {
            $blockedUntil = $now + AUTH_BLOCK_SECONDS;
        }
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode(['failures' => $failures, 'blocked_until' => $blockedUntil]));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['blocked_for' => max(0, $blockedUntil - $now), 'failures' => count($failures)];
}

function auth_login(string $username, string $password): bool
{
    $username = trim($username);
    if (auth_rate_state($username)['blocked_for'] > 0) {
        return false;
    }
    $stmt = auth_db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['enabled'] !== 1 || !auth_verify_password($password, (string) $user['password_hash'])) {
        auth_rate_state($username, true);
        auth_audit('login_failed', ['username' => $username]);
        return false;
    }
    auth_rate_state($username, false, true);
    auth_session_start();
    session_regenerate_id(true);
    $_SESSION['machine_user_id'] = (int) $user['id'];
    $_SESSION['machine_authenticated_at'] = time();
    $updates = ['last_login_at' => date('Y-m-d H:i:s'), 'id' => (int) $user['id']];
    $sql = 'UPDATE users SET last_login_at = :last_login_at';
    if (auth_password_needs_rehash((string) $user['password_hash'])) {
        $sql .= ', password_hash = :password_hash, updated_at = :updated_at';
        $updates['password_hash'] = auth_hash_password($password);
        $updates['updated_at'] = date('Y-m-d H:i:s');
    }
    $sql .= ' WHERE id = :id';
    auth_db()->prepare($sql)->execute($updates);
    auth_audit('login_success');
    return true;
}

function auth_logout(): void
{
    auth_session_start();
    auth_audit('logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

function auth_audit(string $event, array $context = []): void
{
    try {
        $user = auth_current_user();
        auth_db()->prepare(
            'INSERT INTO audit_logs (user_id, event, ip, context_json, created_at)
             VALUES (:user_id, :event, :ip, :context_json, :created_at)'
        )->execute([
            'user_id' => $user ? (int) $user['id'] : null,
            'event' => $event,
            'ip' => auth_client_ip(),
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $exception) {
        error_log('Authentication audit failed: ' . $exception->getMessage());
    }
}

// Compatibility names used by the existing machine registry and plan pages.
function machine_session_start(): void { auth_session_start(); }
function machine_current_user(): ?array { return auth_current_user(); }
function machine_is_authenticated(): bool { return auth_is_authenticated(); }
function machine_is_superadmin(): bool { return auth_is_superadmin(); }
function machine_is_site_admin(): bool { return auth_is_site_admin(); }
function machine_safe_next(?string $value): string { return auth_normalize_next($value, '/'); }
function require_machine_auth(): void { auth_require(); }
function machine_hash_password(string $password): string { return auth_hash_password($password); }
function machine_verify_user_password(string $password, string $stored): bool { return auth_verify_password($password, $stored); }
function machine_password_needs_rehash(string $stored): bool { return auth_password_needs_rehash($stored); }
function machine_client_key(string $username = ''): string { return auth_client_key($username); }
function machine_rate_state(string $username = '', bool $failure = false, bool $clear = false): array { return auth_rate_state($username, $failure, $clear); }
function machine_login(string $username, string $password): bool { return auth_login($username, $password); }
function machine_logout(): void { auth_logout(); }
function machine_audit(string $event, array $context = []): void { auth_audit($event, $context); }

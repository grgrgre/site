<?php
/**
 * Shared API security helpers.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Case-insensitive request header lookup.
 */
function get_request_header_value(array $headers, string $name): string
{
    foreach ($headers as $key => $value) {
        if (is_string($key) && strcasecmp($key, $name) === 0) {
            return trim((string) $value);
        }
    }
    return '';
}

/**
 * Start hardened PHP session for API endpoints.
 */
function start_api_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (!headers_sent()) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
        if ($isSecure) {
            ini_set('session.cookie_secure', '1');
        }
    }

    session_name('SVHSESSID');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    $now = time();
    $createdAt = (int) ($_SESSION['session_created_at'] ?? 0);
    $rotatedAt = (int) ($_SESSION['session_rotated_at'] ?? 0);

    if ($createdAt <= 0) {
        session_regenerate_id(true);
        $_SESSION['session_created_at'] = $now;
        $_SESSION['session_rotated_at'] = $now;
        return;
    }

    if ($rotatedAt <= 0 || ($now - $rotatedAt) >= 900) {
        session_regenerate_id(true);
        $_SESSION['session_rotated_at'] = $now;
    }
}

/**
 * Apply security/CORS headers and handle preflight.
 *
 * @return bool true when request was OPTIONS preflight and response is complete.
 */
function send_api_headers(array $methods = ['GET', 'POST', 'OPTIONS']): bool
{
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-site');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && in_array($origin, ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Token, X-CSRF-Token');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        return true;
    }

    return false;
}

/**
 * Parse Telegram admin chat IDs from env.
 */
function telegram_admin_chat_ids(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $raw = trim((string) TELEGRAM_ADMIN_CHAT_IDS);
    if ($raw === '' && trim((string) TELEGRAM_CHAT_ID) !== '') {
        $raw = trim((string) TELEGRAM_CHAT_ID);
    }

    if ($raw === '') {
        $cache = [];
        return $cache;
    }

    $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $ids = [];
    foreach ($parts as $part) {
        $value = trim((string) $part);
        if ($value === '' || preg_match('/^-?\d{3,20}$/', $value) !== 1) {
            continue;
        }
        $ids[] = $value;
    }

    $cache = array_values(array_unique($ids));
    return $cache;
}

/**
 * Send Telegram Bot API request.
 */
function telegram_api_request(string $apiMethod, array $params = []): ?array
{
    if (trim((string) TELEGRAM_BOT_TOKEN) === '') {
        return null;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $apiMethod;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($params),
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ];
    $context = stream_context_create($options);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Notify all configured admin chats in Telegram.
 */
function telegram_notify_admins(string $text): void
{
    $message = trim($text);
    if ($message === '' || trim((string) TELEGRAM_BOT_TOKEN) === '') {
        return;
    }

    foreach (telegram_admin_chat_ids() as $chatId) {
        telegram_api_request('sendMessage', [
            'chat_id' => $chatId,
            'text' => mb_substr($message, 0, 3900),
            'disable_web_page_preview' => 'true',
        ]);
    }
}

/**
 * JSON response helper.
 */
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * JSON error response helper.
 */
function error_response(string $message, int $status = 400, array $extra = []): void
{
    json_response(array_merge([
        'success' => false,
        'error' => $message,
    ], $extra), $status);
}

/**
 * Resolve client IP from trusted headers.
 */
function get_client_ip(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            return trim(explode(',', $_SERVER[$key])[0]);
        }
    }
    return 'unknown';
}

/**
 * Read input payload from JSON body and/or form POST.
 */
function read_input_payload(): array
{
    static $payload = null;
    if ($payload !== null) {
        return $payload;
    }

    $payload = [];
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    if (!empty($_POST)) {
        $payload = array_merge($payload, $_POST);
    }

    return $payload;
}

/**
 * Remove tags/control chars and trim.
 */
function sanitize_text_field($value, int $maxLength = 255): string
{
    $text = trim((string) $value);
    $text = strip_tags($text);
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
    $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;

    if (mb_strlen($text) > $maxLength) {
        $text = mb_substr($text, 0, $maxLength);
    }

    return $text;
}

/**
 * Keep line breaks for textarea content.
 */
function sanitize_multiline_text($value, int $maxLength = 2000): string
{
    $text = trim((string) $value);
    $text = strip_tags($text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

    if (mb_strlen($text) > $maxLength) {
        $text = mb_substr($text, 0, $maxLength);
    }

    return $text;
}

/**
 * Restrict category value to an allowlist.
 */
function sanitize_topic($value, array $allowed, string $default = 'general'): string
{
    $topic = strtolower(trim((string) $value));
    return in_array($topic, $allowed, true) ? $topic : $default;
}

/**
 * Extract bearer token from headers.
 */
function get_bearer_token(): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = get_request_header_value($headers, 'Authorization');
    if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $match)) {
        return trim($match[1]);
    }

    $alt = get_request_header_value($headers, 'X-Admin-Token');
    return $alt !== '' ? trim($alt) : null;
}

/**
 * Resolve admin token from the current PHP session for same-origin admin pages.
 */
function get_admin_session_token_from_php_session(): ?string
{
    start_api_session();

    $token = $_SESSION['admin_session_token'] ?? null;
    if (!is_string($token) || $token === '') {
        return null;
    }

    $storedFingerprint = $_SESSION['admin_session_fingerprint'] ?? null;
    if (is_string($storedFingerprint) && $storedFingerprint !== '') {
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 200);
        $currentFingerprint = hash('sha256', $userAgent);
        if (!hash_equals($storedFingerprint, $currentFingerprint)) {
            return null;
        }
    }

    return $token;
}

/**
 * Validate admin auth using admin session token only.
 */
function is_admin_authorized(Database $db, ?array $input = null): bool
{
    $token = get_bearer_token();
    if (!$token) {
        $token = get_admin_session_token_from_php_session();
    }
    if ($token && $db->validateAdminSession($token)) {
        return true;
    }
    return false;
}

/**
 * Normalize and validate admin email.
 */
function normalize_admin_email(string $email): string
{
    $normalized = strtolower(trim($email));
    if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }
    return $normalized;
}

/**
 * In-request credentials cache.
 */
function admin_credentials_cache(?array $set = null, bool $reset = false): ?array
{
    static $cache = null;
    if ($reset) {
        $cache = null;
        return null;
    }

    if (is_array($set)) {
        $cache = $set;
    }

    return is_array($cache) ? $cache : null;
}

/**
 * Default admin login email.
 */
function get_admin_default_email(): string
{
    $email = normalize_admin_email((string) ADMIN_EMAIL);
    return $email !== '' ? $email : 'admin@svityazhome.com.ua';
}

/**
 * Read persisted admin credentials from storage.
 */
function read_admin_credentials_file(): ?array
{
    $path = ADMIN_CREDENTIALS_FILE;
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $email = normalize_admin_email((string) ($decoded['email'] ?? ''));
    if ($email === '') {
        $email = get_admin_default_email();
    }

    $passwordHash = (string) ($decoded['password_hash'] ?? '');
    if ($passwordHash === '') {
        return null;
    }

    return [
        'email' => $email,
        'password_hash' => $passwordHash,
        'updated_at' => (string) ($decoded['updated_at'] ?? ''),
    ];
}

/**
 * Persist admin credentials to storage.
 */
function write_admin_credentials_file(array $credentials): bool
{
    $email = normalize_admin_email((string) ($credentials['email'] ?? ''));
    $passwordHash = (string) ($credentials['password_hash'] ?? '');
    if ($email === '' || $passwordHash === '') {
        return false;
    }

    $path = ADMIN_CREDENTIALS_FILE;
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $payload = [
        'email' => $email,
        'password_hash' => $passwordHash,
        'updated_at' => (string) ($credentials['updated_at'] ?? date('c')),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Resolve active admin credentials (storage first, env fallback).
 */
function get_admin_credentials(): ?array
{
    $cached = admin_credentials_cache();
    if (is_array($cached)) {
        return $cached;
    }

    $stored = read_admin_credentials_file();
    if (is_array($stored)) {
        admin_credentials_cache($stored);
        return $stored;
    }

    $envPassword = (string) ADMIN_PASSWORD;
    if ($envPassword === '') {
        return null;
    }

    $passwordHash = password_hash($envPassword, PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        return null;
    }

    $seeded = [
        'email' => get_admin_default_email(),
        'password_hash' => $passwordHash,
        'updated_at' => date('c'),
    ];

    // Try to persist once so future requests use stable hash.
    write_admin_credentials_file($seeded);
    admin_credentials_cache($seeded);
    return $seeded;
}

/**
 * Returns the current admin login email.
 */
function get_admin_login_email(): string
{
    $credentials = get_admin_credentials();
    if (is_array($credentials)) {
        $email = normalize_admin_email((string) ($credentials['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }
    }
    return get_admin_default_email();
}

/**
 * Update persisted admin credentials.
 */
function save_admin_credentials(string $email, string $newPassword): bool
{
    $normalizedEmail = normalize_admin_email($email);
    if ($normalizedEmail === '') {
        return false;
    }

    if ($newPassword === '') {
        return false;
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        return false;
    }

    $payload = [
        'email' => $normalizedEmail,
        'password_hash' => $passwordHash,
        'updated_at' => date('c'),
    ];

    if (!write_admin_credentials_file($payload)) {
        return false;
    }

    admin_credentials_cache($payload);
    return true;
}

/**
 * Check if admin password is configured and not empty.
 */
function is_admin_password_configured(): bool
{
    return is_array(get_admin_credentials());
}

/**
 * Validate admin login password from request body.
 */
function is_admin_login_password_valid(?array $input = null): bool
{
    if (!is_array($input)) {
        return false;
    }

    $credentials = get_admin_credentials();
    if (!is_array($credentials)) {
        return false;
    }

    $password = (string) ($input['password'] ?? '');
    if ($password === '') {
        return false;
    }

    $expectedEmail = normalize_admin_email((string) ($credentials['email'] ?? ''));
    $providedEmail = normalize_admin_email((string) ($input['email'] ?? ''));
    if ($providedEmail !== '' && $expectedEmail !== '' && !hash_equals($expectedEmail, $providedEmail)) {
        return false;
    }

    $passwordHash = (string) ($credentials['password_hash'] ?? '');
    if ($passwordHash !== '') {
        return password_verify($password, $passwordHash);
    }

    return false;
}

/**
 * Enforce admin authorization.
 */
function require_admin_auth(Database $db, ?array $input = null): void
{
    if (!is_admin_authorized($db, $input)) {
        error_response('Unauthorized', 401);
    }
}

/**
 * Create or return CSRF token.
 */
function get_csrf_token(): string
{
    start_api_session();

    $now = time();
    $token = $_SESSION['csrf_token'] ?? null;
    $expires = $_SESSION['csrf_token_expires'] ?? 0;

    if (!is_string($token) || $token === '' || $now >= (int) $expires) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_expires'] = $now + CSRF_TOKEN_TTL;
    }

    return $token;
}

/**
 * Validate CSRF token from request payload/headers.
 */
function require_csrf_token(?array $input = null): void
{
    start_api_session();

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $sent = get_request_header_value($headers, 'X-CSRF-Token');
    if ($sent === '' && is_array($input)) {
        $sent = $input['csrf_token'] ?? '';
    }

    if (!is_string($sent) || $sent === '') {
        error_response('Missing CSRF token', 419);
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $expires = (int) ($_SESSION['csrf_token_expires'] ?? 0);
    $now = time();

    if (!is_string($sessionToken) || $sessionToken === '' || $now >= $expires) {
        error_response('Expired CSRF token', 419);
    }

    if (!hash_equals($sessionToken, $sent)) {
        error_response('Invalid CSRF token', 419);
    }
}

/**
 * Honeypot check for bot submissions.
 */
function enforce_honeypot(?array $input = null, string $field = 'website'): void
{
    if (!is_array($input)) {
        return;
    }

    $value = trim((string) ($input[$field] ?? ''));
    if ($value !== '') {
        error_response('Invalid submission', 400);
    }
}

/**
 * Apply DB-backed rate limit and record request.
 */
function enforce_rate_limit(Database $db, string $ip, string $action, int $maxAttempts, int $windowSeconds): void
{
    if (!$db->checkRateLimit($ip, $action, $maxAttempts, $windowSeconds)) {
        error_response('Too many requests. Please try again later.', 429, [
            'retry_after_seconds' => $windowSeconds,
        ]);
    }

    $db->recordRateLimit($ip, $action);
}

/**
 * Normalize PHP $_FILES array to a flat list.
 */
function normalize_uploaded_files(string $fieldName): array
{
    if (!isset($_FILES[$fieldName])) {
        return [];
    }

    $files = $_FILES[$fieldName];
    if (!is_array($files['name'])) {
        return [$files];
    }

    $normalized = [];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $normalized[] = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i],
        ];
    }

    return $normalized;
}

/**
 * Ensure private storage backup directory exists and is protected.
 */
function ensure_storage_backups_dir(): bool
{
    $backupDir = rtrim(STORAGE_BACKUPS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        return false;
    }

    $htaccessPath = $backupDir . '.htaccess';
    if (!file_exists($htaccessPath)) {
        $rules = <<<HTACCESS
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
Options -Indexes
HTACCESS;
        @file_put_contents($htaccessPath, $rules . PHP_EOL, LOCK_EX);
    }

    return true;
}

/**
 * List existing storage backups ordered by newest first.
 */
function sanitize_storage_backup_reason(string $reason, string $fallback = 'manual'): string
{
    $safe = strtolower(trim($reason));
    $safe = preg_replace('/[^a-z0-9_-]+/i', '-', $safe) ?? '';
    $safe = trim($safe, '-_');
    if ($safe === '') {
        $safe = strtolower(trim($fallback));
    }
    if ($safe === '') {
        $safe = 'manual';
    }
    if (strlen($safe) > 90) {
        $safe = rtrim(substr($safe, 0, 90), '-_');
    }
    return $safe !== '' ? $safe : 'manual';
}

function storage_reason_starts_with(string $value, string $prefix): bool
{
    if ($prefix === '') {
        return true;
    }
    return strncmp($value, $prefix, strlen($prefix)) === 0;
}

function classify_storage_backup_reason(string $reason): string
{
    $normalized = sanitize_storage_backup_reason($reason, 'unknown');
    if (storage_reason_starts_with($normalized, 'pre-restore-')) {
        return 'pre_restore';
    }
    if (storage_reason_starts_with($normalized, 'auto-')) {
        return 'auto';
    }
    if (storage_reason_starts_with($normalized, 'import-')) {
        return 'import';
    }
    if (storage_reason_starts_with($normalized, 'scheduled-')) {
        return 'scheduled';
    }
    if (storage_reason_starts_with($normalized, 'restore-')) {
        return 'restore';
    }
    if (storage_reason_starts_with($normalized, 'manual-') || $normalized === 'manual' || $normalized === 'manual-admin') {
        return 'manual';
    }
    return 'other';
}

function storage_backup_kind_label(string $kind): string
{
    switch ($kind) {
        case 'manual':
            return 'Ручний';
        case 'auto':
            return 'Авто';
        case 'pre_restore':
            return 'Перед відновленням';
        case 'import':
            return 'Імпорт';
        case 'scheduled':
            return 'Плановий';
        case 'restore':
            return 'Після відновлення';
        default:
            return 'Інше';
    }
}

function humanize_storage_backup_reason(string $reason): string
{
    $normalized = sanitize_storage_backup_reason($reason, 'manual');
    $clean = preg_replace('/^(auto|pre-restore|import|scheduled|restore|manual)-/i', '', $normalized) ?? $normalized;
    $clean = str_replace(['-', '_'], ' ', $clean);
    $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    if ($clean === '' || $clean === 'manual') {
        return '';
    }
    return mb_strtoupper(mb_substr($clean, 0, 1)) . mb_substr($clean, 1);
}

function parse_storage_backup_filename(string $filename): array
{
    $name = basename(trim($filename));
    $reason = 'manual';
    $createdAt = null;

    if (preg_match('/^storage-backup-(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})__([a-z0-9_-]+)\.zip$/i', $name, $m)) {
        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6])
        );
        if ($dt instanceof DateTimeImmutable) {
            $createdAt = $dt->format(DATE_ATOM);
        }
        $reason = $m[7];
    } elseif (preg_match('/^storage-import-(\d{8})-(\d{6})-([a-z0-9_-]+)\.zip$/i', $name, $m)) {
        $dt = DateTimeImmutable::createFromFormat('YmdHis', $m[1] . $m[2]);
        if ($dt instanceof DateTimeImmutable) {
            $createdAt = $dt->format(DATE_ATOM);
        }
        $reason = 'import-' . $m[3];
    } elseif (preg_match('/^storage-(\d{8})-(\d{6})-([a-z0-9_-]+)\.zip$/i', $name, $m)) {
        $dt = DateTimeImmutable::createFromFormat('YmdHis', $m[1] . $m[2]);
        if ($dt instanceof DateTimeImmutable) {
            $createdAt = $dt->format(DATE_ATOM);
        }
        $reason = $m[3];
    } elseif (preg_match('/^storage-backup-([a-z0-9_-]+)\.zip$/i', $name, $m)) {
        $reason = $m[1];
    }

    $reason = sanitize_storage_backup_reason($reason, 'manual');
    $kind = classify_storage_backup_reason($reason);
    $kindLabel = storage_backup_kind_label($kind);
    $reasonLabel = humanize_storage_backup_reason($reason);
    $label = $reasonLabel !== '' ? ($kindLabel . ': ' . $reasonLabel) : $kindLabel;

    return [
        'reason' => $reason,
        'reason_label' => $reasonLabel,
        'kind' => $kind,
        'kind_label' => $kindLabel,
        'label' => $label,
        'created_at' => $createdAt,
    ];
}

function list_storage_backups(int $limit = 30): array
{
    if (!ensure_storage_backups_dir()) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $backupDir = rtrim(STORAGE_BACKUPS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $items = [];

    foreach (scandir($backupDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!preg_match('/\.zip$/i', $entry)) {
            continue;
        }
        $fullPath = $backupDir . $entry;
        if (!is_file($fullPath)) {
            continue;
        }
        $mtime = filemtime($fullPath);
        $meta = parse_storage_backup_filename($entry);
        $createdTs = $meta['created_at'] ? strtotime((string) $meta['created_at']) : 0;
        $effectiveTs = (int) ($createdTs ?: ($mtime ?: 0));
        if ($effectiveTs <= 0) {
            $effectiveTs = time();
        }
        $items[] = [
            'name' => $entry,
            'size' => (int) (filesize($fullPath) ?: 0),
            'modified' => $mtime ? date('c', $mtime) : date('c'),
            'created_at' => date('c', $effectiveTs),
            'age_seconds' => max(0, time() - $effectiveTs),
            'reason' => (string) ($meta['reason'] ?? 'manual'),
            'reason_label' => (string) ($meta['reason_label'] ?? ''),
            'kind' => (string) ($meta['kind'] ?? 'other'),
            'kind_label' => (string) ($meta['kind_label'] ?? storage_backup_kind_label('other')),
            'label' => (string) ($meta['label'] ?? $entry),
            'timestamp' => $effectiveTs,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });

    $items = array_slice($items, 0, $limit);
    foreach ($items as &$item) {
        unset($item['timestamp']);
    }

    return $items;
}

/**
 * Create ZIP snapshot of storage data/uploads/logs.
 */
function create_storage_backup_zip(string $reason = 'manual', int $maxKeep = 40): array
{
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'ZIP extension is not available on server'];
    }

    if (!ensure_storage_backups_dir()) {
        return ['success' => false, 'error' => 'Unable to prepare backup directory'];
    }

    $safeReason = sanitize_storage_backup_reason($reason, 'manual');

    $timestamp = date('Y-m-d_H-i-s');
    $filenameBase = "storage-backup-{$timestamp}__{$safeReason}";
    $filename = $filenameBase . '.zip';
    $backupDir = rtrim(STORAGE_BACKUPS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $fullPath = $backupDir . $filename;
    $counter = 2;
    while (file_exists($fullPath) && $counter <= 1000) {
        $filename = $filenameBase . '-' . $counter . '.zip';
        $fullPath = $backupDir . $filename;
        $counter++;
    }
    if (file_exists($fullPath)) {
        return ['success' => false, 'error' => 'Unable to generate unique backup filename'];
    }

    $zip = new ZipArchive();
    $opened = $zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        return ['success' => false, 'error' => 'Unable to create backup archive'];
    }

    $sourceDirs = [
        'data' => DATA_DIR,
        'uploads' => UPLOADS_DIR,
        'logs' => STORAGE_LOGS_DIR,
    ];

    $addedFiles = 0;
    $addedBytes = 0;
    $perRootStats = [];
    foreach ($sourceDirs as $zipRoot => $sourceDir) {
        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        if (!is_dir($sourceDir)) {
            continue;
        }

        $realSource = realpath($sourceDir);
        if ($realSource === false) {
            continue;
        }

        $zip->addEmptyDir('storage/' . $zipRoot);
        $perRootStats[$zipRoot] = ['files' => 0, 'bytes' => 0];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realSource, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $itemPath = $item->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($itemPath, strlen($realSource))), '/');
            $zipPath = 'storage/' . $zipRoot . ($relative !== '' ? '/' . $relative : '');

            if ($item->isDir()) {
                $zip->addEmptyDir($zipPath);
                continue;
            }

            if ($item->isFile() && $zip->addFile($itemPath, $zipPath)) {
                $addedFiles++;
                $size = (int) ($item->getSize() ?: 0);
                $addedBytes += $size;
                $perRootStats[$zipRoot]['files']++;
                $perRootStats[$zipRoot]['bytes'] += $size;
            }
        }
    }

    $manifest = json_encode([
        'version' => 2,
        'generated_at' => date('c'),
        'reason' => $safeReason,
        'kind' => classify_storage_backup_reason($safeReason),
        'files' => $addedFiles,
        'bytes' => $addedBytes,
        'stats' => $perRootStats,
        'included' => array_keys($sourceDirs),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (is_string($manifest)) {
        $zip->addFromString('storage/backup-manifest.json', $manifest . PHP_EOL);
    }

    $zip->close();
    @chmod($fullPath, 0640);

    $maxKeep = max(5, min(200, $maxKeep));
    $existing = list_storage_backups($maxKeep + 200);
    if (count($existing) > $maxKeep) {
        $toDelete = array_slice($existing, $maxKeep);
        foreach ($toDelete as $backup) {
            $name = (string) ($backup['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $candidate = $backupDir . basename($name);
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }

    $meta = parse_storage_backup_filename($filename);
    return [
        'success' => true,
        'backup' => [
            'name' => $filename,
            'size' => (int) (filesize($fullPath) ?: 0),
            'modified' => date('c'),
            'files' => $addedFiles,
            'bytes' => $addedBytes,
            'reason' => $safeReason,
            'reason_label' => (string) ($meta['reason_label'] ?? ''),
            'kind' => (string) ($meta['kind'] ?? 'other'),
            'kind_label' => (string) ($meta['kind_label'] ?? storage_backup_kind_label('other')),
            'label' => (string) ($meta['label'] ?? $filename),
            'created_at' => (string) ($meta['created_at'] ?? date('c')),
        ],
    ];
}

/**
 * Create backup at most once per interval to avoid heavy ZIP generation.
 */
function maybe_auto_storage_backup(string $reason = 'auto', int $intervalSeconds = 21600): array
{
    if (!ensure_storage_backups_dir()) {
        return ['success' => false, 'error' => 'Backup directory unavailable'];
    }

    $intervalSeconds = max(600, $intervalSeconds);
    $stampFile = rtrim(STORAGE_BACKUPS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.auto-backup-stamp.json';
    $now = time();

    if (is_file($stampFile)) {
        $raw = file_get_contents($stampFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $lastTs = (int) ($decoded['timestamp'] ?? 0);
        if ($lastTs > 0 && ($now - $lastTs) < $intervalSeconds) {
            return [
                'success' => true,
                'skipped' => true,
                'next_in_seconds' => max(0, $intervalSeconds - ($now - $lastTs)),
            ];
        }
    }

    $result = create_storage_backup_zip('auto-' . $reason, 40);
    if (!($result['success'] ?? false)) {
        return $result;
    }

    $stampPayload = json_encode([
        'timestamp' => $now,
        'reason' => $reason,
        'backup' => $result['backup']['name'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (is_string($stampPayload)) {
        @file_put_contents($stampFile, $stampPayload, LOCK_EX);
    }

    $result['skipped'] = false;
    return $result;
}

/**
 * Correct JPEG orientation using EXIF metadata when available.
 */
function normalize_jpeg_orientation($image, string $filePath)
{
    $isValidImage = is_resource($image);
    if (!$isValidImage && class_exists('GdImage', false)) {
        $isValidImage = is_object($image) && $image instanceof GdImage;
    }
    if (!$isValidImage) {
        return $image;
    }

    if (!function_exists('exif_read_data') || !function_exists('imagerotate')) {
        return $image;
    }

    $exif = @exif_read_data($filePath);
    if (!is_array($exif)) {
        return $image;
    }

    $orientation = (int) ($exif['Orientation'] ?? 1);
    $rotated = null;

    if ($orientation === 3) {
        $rotated = @imagerotate($image, 180, 0);
    } elseif ($orientation === 6) {
        $rotated = @imagerotate($image, -90, 0);
    } elseif ($orientation === 8) {
        $rotated = @imagerotate($image, 90, 0);
    }

    if (!$rotated) {
        return $image;
    }

    imagedestroy($image);
    return $rotated;
}

/**
 * Process uploaded image as sanitized WebP (or JPEG fallback).
 */
function process_uploaded_image(array $file, string $targetDir, string $publicPrefix, int $maxWidth = 1920, int $maxHeight = 1280, int $maxSize = MAX_UPLOAD_SIZE): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed'];
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) {
        return ['success' => false, 'error' => 'Invalid upload source'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxSize) {
        return ['success' => false, 'error' => 'File size is invalid'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmp) : null;
    if ($finfo) {
        finfo_close($finfo);
    }
    $mime = strtolower(trim((string) $mime));

    $allowedMimes = [
        'image/jpeg',
        'image/jpg',
        'image/pjpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/bmp',
        'image/x-ms-bmp',
        'image/avif',
    ];

    $magicType = function_exists('exif_imagetype') ? @exif_imagetype($tmp) : false;
    $allowedMagic = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_WEBP,
        IMAGETYPE_GIF,
        IMAGETYPE_BMP,
    ];
    if (defined('IMAGETYPE_AVIF')) {
        $allowedMagic[] = constant('IMAGETYPE_AVIF');
    }

    if ($mime === '' || !in_array($mime, $allowedMimes, true)) {
        return ['success' => false, 'error' => 'Unsupported image type'];
    }

    if ($magicType !== false && !in_array((int) $magicType, $allowedMagic, true)) {
        return ['success' => false, 'error' => 'Invalid image signature'];
    }

    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
        case 'image/pjpeg':
            $source = @imagecreatefromjpeg($tmp);
            break;
        case 'image/png':
            $source = @imagecreatefrompng($tmp);
            break;
        case 'image/webp':
            $source = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : null;
            break;
        case 'image/gif':
            $source = @imagecreatefromgif($tmp);
            break;
        case 'image/bmp':
        case 'image/x-ms-bmp':
            $source = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($tmp) : null;
            break;
        case 'image/avif':
            $source = function_exists('imagecreatefromavif') ? @imagecreatefromavif($tmp) : null;
            break;
        default:
            $source = null;
    }

    if (!$source) {
        $raw = @file_get_contents($tmp);
        if (is_string($raw) && $raw !== '') {
            $source = @imagecreatefromstring($raw);
        }
    }

    if (!$source) {
        return ['success' => false, 'error' => 'Unable to decode image'];
    }

    if (in_array($mime, ['image/jpeg', 'image/jpg', 'image/pjpeg'], true)) {
        $source = normalize_jpeg_orientation($source, $tmp);
    }

    $width = imagesx($source);
    $height = imagesy($source);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($source);
        return ['success' => false, 'error' => 'Invalid image dimensions'];
    }

    $ratio = min($maxWidth / $width, $maxHeight / $height, 1);
    $newWidth = max(1, (int) round($width * $ratio));
    $newHeight = max(1, (int) round($height * $ratio));

    $dest = imagecreatetruecolor($newWidth, $newHeight);
    if (!$dest) {
        imagedestroy($source);
        return ['success' => false, 'error' => 'Image processing failed'];
    }

    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
    imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);
    imagealphablending($dest, false);
    imagesavealpha($dest, true);

    if (!imagecopyresampled($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
        imagedestroy($source);
        imagedestroy($dest);
        return ['success' => false, 'error' => 'Image resize failed'];
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        imagedestroy($source);
        imagedestroy($dest);
        return ['success' => false, 'error' => 'Unable to create upload directory'];
    }

    $random = bin2hex(random_bytes(16));
    $publicPrefix = rtrim($publicPrefix, '/') . '/';
    $targetDir = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    $filename = $random . '.webp';
    $fullPath = $targetDir . $filename;

    $saved = false;
    if (function_exists('imagewebp')) {
        $saved = @imagewebp($dest, $fullPath, 84);
    }

    if (!$saved) {
        $filename = $random . '.jpg';
        $fullPath = $targetDir . $filename;
        $saved = @imagejpeg($dest, $fullPath, 88);
    }

    imagedestroy($source);
    imagedestroy($dest);

    if (!$saved) {
        return ['success' => false, 'error' => 'Unable to save image'];
    }

    return [
        'success' => true,
        'path' => $publicPrefix . $filename,
        'filename' => $filename,
        'width' => $newWidth,
        'height' => $newHeight,
    ];
}

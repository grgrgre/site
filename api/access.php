<?php
/**
 * Early-access gate API.
 * Provides password check for preview access modal shown in the site UI.
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['GET', 'POST', 'OPTIONS'])) {
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    error_response('Method not allowed', 405);
}

start_api_session();
$db = Database::getInstance();
$ip = get_client_ip();

function is_early_access_unlocked_now(): bool
{
    $now = time();
    $expiresAt = (int) ($_SESSION['early_access_expires_at'] ?? 0);
    return ($expiresAt > $now);
}

function respond_early_access_status(): void
{
    json_response([
        'success' => true,
        'enabled' => SITE_EARLY_ACCESS_ENABLED,
        'unlocked' => (!SITE_EARLY_ACCESS_ENABLED || is_early_access_unlocked_now()),
        'expires_in' => SITE_EARLY_ACCESS_TTL,
    ]);
}

if ($method === 'GET') {
    respond_early_access_status();
}

$input = read_input_payload();
$action = strtolower(trim((string) ($input['action'] ?? 'status')));

if ($action === 'status') {
    respond_early_access_status();
}

if ($action === 'unlock') {
    if (!SITE_EARLY_ACCESS_ENABLED) {
        respond_early_access_status();
    }

    $password = (string) ($input['password'] ?? '');
    if ($password === '') {
        error_response('Password is required', 400);
    }

    enforce_rate_limit($db, $ip, 'early_access_unlock', 30, 3600);

    $isValid = false;
    if (SITE_EARLY_ACCESS_PASSWORD !== '' && hash_equals(SITE_EARLY_ACCESS_PASSWORD, $password)) {
        $isValid = true;
    }
    if (!$isValid && SITE_EARLY_ACCESS_ALLOW_ADMIN_PASSWORD && is_admin_password_configured()) {
        $isValid = is_admin_login_password_valid(['password' => $password]);
    }

    if (!$isValid) {
        error_response('Invalid password', 401);
    }

    $expiresAt = time() + SITE_EARLY_ACCESS_TTL;
    $_SESSION['early_access_expires_at'] = $expiresAt;
    $_SESSION['early_access_granted_at'] = time();

    json_response([
        'success' => true,
        'enabled' => true,
        'unlocked' => true,
        'expires_in' => SITE_EARLY_ACCESS_TTL,
        'expires_at' => gmdate('c', $expiresAt),
    ]);
}

if ($action === 'logout') {
    unset($_SESSION['early_access_expires_at'], $_SESSION['early_access_granted_at']);
    json_response([
        'success' => true,
        'enabled' => SITE_EARLY_ACCESS_ENABLED,
        'unlocked' => false,
    ]);
}

error_response('Unknown action', 400);

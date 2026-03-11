<?php

function svh_request_fingerprint(): string
{
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 200);
    return hash('sha256', $userAgent);
}

function svh_bind_admin_session_token(string $token, string $email): void
{
    start_api_session();
    session_regenerate_id(true);

    $_SESSION['admin_session_token'] = $token;
    $_SESSION['admin_login_email'] = $email;
    $_SESSION['admin_session_fingerprint'] = svh_request_fingerprint();
}

function svh_issue_admin_session(Database $db, string $email, string $password, string $ip, string $userAgent): ?array
{
    if (!is_admin_login_password_valid([
        'email' => $email,
        'password' => $password,
    ])) {
        return null;
    }

    $token = $db->createAdminSession($ip, $userAgent);
    $resolvedEmail = get_admin_login_email();
    svh_bind_admin_session_token($token, $resolvedEmail);

    return [
        'token' => $token,
        'email' => $resolvedEmail,
        'expires_in' => SESSION_LIFETIME,
    ];
}

if (!function_exists('requireAdmin')) {
    function requireAdmin(Database $db, ?array $input = null): void
    {
        require_admin_auth($db, $input);
    }
}

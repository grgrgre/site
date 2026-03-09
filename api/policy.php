<?php
/**
 * Public site policy API.
 * Single source of truth for check-in/out and booking policy texts.
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['GET', 'OPTIONS'])) {
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    error_response('Method not allowed', 405);
}

$policy = [
    'checkin' => POLICY_CHECKIN_TIME,
    'checkout' => POLICY_CHECKOUT_TIME,
    'prepayment' => POLICY_PREPAYMENT,
    'dev_mode' => SITE_DEV_MODE,
    'booking' => [
        'max_guests' => BOOKING_MAX_GUESTS,
        'min_submit_seconds' => BOOKING_MIN_FORM_SECONDS,
    ],
];

json_response([
    'ok' => true,
    'success' => true,
    'policy' => $policy,
    'csrf_token' => get_csrf_token(),
]);


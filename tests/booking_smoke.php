<?php
/**
 * Booking API smoke tests.
 *
 * Usage:
 *   php tests/booking_smoke.php [base_url]
 *
 * Example:
 *   php tests/booking_smoke.php http://127.0.0.1:8080
 */

declare(strict_types=1);

if (!function_exists('curl_init')) {
    fwrite(STDERR, "cURL extension is required for booking smoke tests.\n");
    exit(1);
}

$baseUrl = $argv[1] ?? getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$baseUrl = rtrim($baseUrl, '/');
$cookieFile = tempnam(sys_get_temp_dir(), 'svh_booking_smoke_');

if ($cookieFile === false) {
    fwrite(STDERR, "Failed to create temporary cookie file.\n");
    exit(1);
}

register_shutdown_function(static function () use ($cookieFile): void {
    if (is_file($cookieFile)) {
        @unlink($cookieFile);
    }
});

function request_json(string $baseUrl, string $cookieFile, string $method, string $path, array $headers = [], ?array $json = null): array
{
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => 0, 'body' => null, 'raw' => '', 'error' => 'curl_init failed'];
    }

    $httpHeaders = array_merge(['Accept: application/json'], $headers);
    $payload = null;
    if ($json !== null) {
        $payload = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $httpHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => $httpHeaders,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $body = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    return [
        'status' => $status,
        'body' => $body,
        'raw' => is_string($raw) ? $raw : '',
        'error' => $error !== '' ? $error : null,
    ];
}

function request_plain(string $baseUrl, string $cookieFile, string $path): array
{
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $error !== '' ? $error : null,
    ];
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tomorrow_date(int $shiftDays = 1): string
{
    return (new DateTimeImmutable('today +' . $shiftDays . ' day'))->format('Y-m-d');
}

function valid_payload(string $suffix): array
{
    return [
        'name' => 'Smoke Test ' . $suffix,
        'phone' => '+380931234567',
        'email' => 'smoke+' . strtolower($suffix) . '@example.com',
        'checkin' => tomorrow_date(1),
        'checkout' => tomorrow_date(2),
        'guests' => 2,
        'room' => 'room-1',
        'message' => 'Smoke test booking payload',
        'consent' => true,
        'website' => '',
    ];
}

$results = [];

try {
    $csrfInit = request_json($baseUrl, $cookieFile, 'GET', '/api/booking.php?action=csrf');
    assert_true($csrfInit['status'] === 200, 'CSRF init failed: HTTP ' . $csrfInit['status']);
    assert_true(is_array($csrfInit['body']) && ($csrfInit['body']['success'] ?? false) === true, 'CSRF init response is invalid');
    $csrf = (string) ($csrfInit['body']['csrf_token'] ?? '');
    assert_true($csrf !== '', 'CSRF token is empty');

    $minWait = (int) (($csrfInit['body']['policy']['booking']['min_submit_seconds'] ?? 3));
    if ($minWait < 1) {
        $minWait = 3;
    }
    sleep($minWait);

    $valid = request_json(
        $baseUrl,
        $cookieFile,
        'POST',
        '/api/booking.php',
        ['X-CSRF-Token: ' . $csrf],
        valid_payload('VALID')
    );
    assert_true($valid['status'] === 200, 'Valid submit failed: HTTP ' . $valid['status']);
    assert_true(is_array($valid['body']) && ($valid['body']['ok'] ?? false) === true, 'Valid submit did not return ok=true');
    assert_true(!empty($valid['body']['booking_id']), 'Valid submit did not return booking_id');
    assert_true(($valid['body']['mail_sent'] ?? false) === true, 'Valid submit did not send email (mail_sent=false)');
    $results[] = 'valid submit: OK';

    $honeypotPayload = valid_payload('HONEYPOT');
    $honeypotPayload['website'] = 'spam-bot-field';
    $honeypot = request_json(
        $baseUrl,
        $cookieFile,
        'POST',
        '/api/booking.php',
        ['X-CSRF-Token: ' . $csrf],
        $honeypotPayload
    );
    assert_true($honeypot['status'] === 200, 'Honeypot submit failed: HTTP ' . $honeypot['status']);
    assert_true(is_array($honeypot['body']) && ($honeypot['body']['ok'] ?? false) === true, 'Honeypot submit did not return ok=true');
    assert_true(($honeypot['body']['mail_sent'] ?? true) === false, 'Honeypot submit should not send email');
    $results[] = 'honeypot silent submit: OK';

    $missingCsrf = request_json(
        $baseUrl,
        $cookieFile,
        'POST',
        '/api/booking.php',
        [],
        valid_payload('NO_CSRF')
    );
    assert_true($missingCsrf['status'] === 400, 'Missing CSRF should return 400, got ' . $missingCsrf['status']);
    $results[] = 'missing CSRF: OK';

    $invalidDatesPayload = valid_payload('BAD_DATES');
    $invalidDatesPayload['checkin'] = tomorrow_date(3);
    $invalidDatesPayload['checkout'] = tomorrow_date(2);
    $invalidDates = request_json(
        $baseUrl,
        $cookieFile,
        'POST',
        '/api/booking.php',
        ['X-CSRF-Token: ' . $csrf],
        $invalidDatesPayload
    );
    assert_true($invalidDates['status'] === 400, 'Invalid dates should return 400, got ' . $invalidDates['status']);
    $results[] = 'invalid dates: OK';

    $gibberishPayload = valid_payload('GIBBERISH');
    $gibberishPayload['message'] = 'zzzzzzzz zzzzzzzz qqqqqqq qqqqqqq';
    $gibberish = request_json(
        $baseUrl,
        $cookieFile,
        'POST',
        '/api/booking.php',
        ['X-CSRF-Token: ' . $csrf],
        $gibberishPayload
    );
    assert_true($gibberish['status'] === 400, 'Gibberish message should return 400, got ' . $gibberish['status']);
    $results[] = 'gibberish message: OK';

    // New token for burst checks (resets anti-fast-submit timer in session).
    $csrfBurst = request_json($baseUrl, $cookieFile, 'GET', '/api/booking.php?action=csrf');
    assert_true($csrfBurst['status'] === 200, 'Burst CSRF init failed');
    $csrfBurstToken = (string) ($csrfBurst['body']['csrf_token'] ?? '');
    assert_true($csrfBurstToken !== '', 'Burst CSRF token is empty');
    $minWaitBurst = (int) (($csrfBurst['body']['policy']['booking']['min_submit_seconds'] ?? 3));
    sleep($minWaitBurst > 0 ? $minWaitBurst : 3);

    $rateLimited = false;
    for ($i = 1; $i <= 10; $i++) {
        $payload = valid_payload('RATE_' . $i);
        $resp = request_json(
            $baseUrl,
            $cookieFile,
            'POST',
            '/api/booking.php',
            ['X-CSRF-Token: ' . $csrfBurstToken],
            $payload
        );
        if ($resp['status'] === 429) {
            $rateLimited = true;
            break;
        }
    }
    assert_true($rateLimited, 'Expected 429 rate-limit during 10 rapid submits');
    $results[] = 'rate-limit burst (10 submits): OK';

    $manifest = request_plain($baseUrl, $cookieFile, '/manifest.json');
    assert_true($manifest['status'] === 200, 'manifest.json should be accessible, got HTTP ' . $manifest['status']);
    $results[] = 'manifest.json accessibility: OK';

    echo "Booking smoke tests passed:\n";
    foreach ($results as $row) {
        echo ' - ' . $row . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Booking smoke tests failed: " . $e->getMessage() . "\n");
    if (!empty($results)) {
        fwrite(STDERR, "Completed checks before failure:\n");
        foreach ($results as $row) {
            fwrite(STDERR, ' - ' . $row . "\n");
        }
    }
    exit(1);
}

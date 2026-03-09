<?php
/**
 * SvityazHOME health endpoint.
 * Public, read-only operational status for monitoring.
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['GET', 'OPTIONS'])) {
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    error_response('Method not allowed', 405);
}

initStorage();
$checks = [];

$isDirOk = static function (string $path): bool {
    return is_dir($path) && is_readable($path) && is_writable($path);
};

$checks[] = ['key' => 'storage_dir', 'ok' => $isDirOk(STORAGE_DIR)];
$checks[] = ['key' => 'data_dir', 'ok' => $isDirOk(DATA_DIR)];
$checks[] = ['key' => 'uploads_dir', 'ok' => $isDirOk(UPLOADS_DIR)];
$checks[] = ['key' => 'logs_dir', 'ok' => $isDirOk(STORAGE_LOGS_DIR)];
$checks[] = ['key' => 'backups_dir', 'ok' => $isDirOk(STORAGE_BACKUPS_DIR)];
$checks[] = ['key' => 'database_file', 'ok' => is_file(DATABASE_PATH) && is_readable(DATABASE_PATH)];

$dbHealthy = false;
$dbError = '';
$stats = null;
try {
    $db = Database::getInstance();
    $stats = $db->getStats();
    $dbHealthy = is_array($stats);
} catch (Throwable $error) {
    $dbHealthy = false;
    $dbError = $error->getMessage();
}
$checks[] = ['key' => 'database_query', 'ok' => $dbHealthy];

$diskFree = @disk_free_space(STORAGE_DIR);
$diskOk = is_numeric($diskFree) && (float) $diskFree >= (200 * 1024 * 1024);
$checks[] = ['key' => 'storage_free_space', 'ok' => $diskOk];

$allOk = true;
foreach ($checks as $check) {
    if (empty($check['ok'])) {
        $allOk = false;
        break;
    }
}

$payload = [
    'success' => $allOk,
    'status' => $allOk ? 'ok' : 'degraded',
    'generated_at' => date('c'),
    'checks' => $checks,
    'storage' => [
        'free_bytes' => is_numeric($diskFree) ? (int) $diskFree : null,
    ],
];

if (is_array($stats)) {
    $payload['stats'] = [
        'approved_reviews' => (int) ($stats['approved_reviews'] ?? 0),
        'pending_reviews' => (int) ($stats['pending_reviews'] ?? 0),
        'questions' => (int) ($stats['questions'] ?? 0),
        'rooms' => (int) ($stats['rooms'] ?? 0),
    ];
}

if ($dbError !== '') {
    $payload['errors'] = ['database' => $dbError];
}

json_response($payload, $allOk ? 200 : 503);

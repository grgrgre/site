<?php
declare(strict_types=1);

/**
 * SvityazHOME autonomy tasks runner.
 * Usage:
 *   php tools/autonomy-tasks.php health
 *   php tools/autonomy-tasks.php backup [reason]
 *   php tools/autonomy-tasks.php auto-backup [reason] [interval_seconds]
 *   php tools/autonomy-tasks.php vacuum
 */

require_once dirname(__DIR__) . '/api/security.php';

function emit_json(array $payload, int $exitCode = 0): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function run_health_checks(): array
{
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

    $result = [
        'success' => $allOk,
        'status' => $allOk ? 'ok' : 'degraded',
        'generated_at' => date('c'),
        'checks' => $checks,
        'storage' => [
            'free_bytes' => is_numeric($diskFree) ? (int) $diskFree : null,
        ],
    ];

    if (is_array($stats)) {
        $result['stats'] = [
            'approved_reviews' => (int) ($stats['approved_reviews'] ?? 0),
            'pending_reviews' => (int) ($stats['pending_reviews'] ?? 0),
            'questions' => (int) ($stats['questions'] ?? 0),
            'rooms' => (int) ($stats['rooms'] ?? 0),
        ];
    }

    if ($dbError !== '') {
        $result['errors'] = ['database' => $dbError];
    }

    return $result;
}

function run_vacuum(): array
{
    if (!is_file(DATABASE_PATH)) {
        return [
            'success' => false,
            'error' => 'Database file is missing',
            'path' => DATABASE_PATH,
        ];
    }

    try {
        $pdo = new PDO('sqlite:' . DATABASE_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('VACUUM');
        $pdo->exec('PRAGMA optimize');
    } catch (Throwable $error) {
        return [
            'success' => false,
            'error' => $error->getMessage(),
        ];
    }

    clearstatcache(true, DATABASE_PATH);
    return [
        'success' => true,
        'vacuumed_at' => date('c'),
        'database_size' => (int) (filesize(DATABASE_PATH) ?: 0),
    ];
}

$command = strtolower(trim((string) ($argv[1] ?? 'health')));

if ($command === 'health') {
    $health = run_health_checks();
    emit_json($health, ($health['success'] ?? false) ? 0 : 2);
}

if ($command === 'backup') {
    $reason = (string) ($argv[2] ?? 'scheduled-nightly');
    $result = create_storage_backup_zip($reason, 120);
    emit_json($result, ($result['success'] ?? false) ? 0 : 3);
}

if ($command === 'auto-backup') {
    $reason = (string) ($argv[2] ?? 'scheduled-auto');
    $interval = (int) ($argv[3] ?? 21600);
    $result = maybe_auto_storage_backup($reason, $interval);
    emit_json($result, ($result['success'] ?? false) ? 0 : 3);
}

if ($command === 'vacuum') {
    $result = run_vacuum();
    emit_json($result, ($result['success'] ?? false) ? 0 : 4);
}

emit_json([
    'success' => false,
    'error' => 'Unknown command',
    'usage' => [
        'php tools/autonomy-tasks.php health',
        'php tools/autonomy-tasks.php backup [reason]',
        'php tools/autonomy-tasks.php auto-backup [reason] [interval_seconds]',
        'php tools/autonomy-tasks.php vacuum',
    ],
], 1);

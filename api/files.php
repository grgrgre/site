<?php
/**
 * SvityazHOME Files API
 * Admin file editor with security
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['GET', 'POST', 'OPTIONS'])) {
    exit;
}

// Initialize
initStorage();
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = read_input_payload();
$ip = get_client_ip();

// ========== SECURITY ==========

// Extensions that are safe to manage (create/rename/list) in admin files module.
$MANAGEABLE_EXTENSIONS = ['html', 'css', 'js', 'json', 'txt', 'md', 'jpg', 'jpeg', 'png', 'webp', 'svg', 'gif', 'ico', 'avif'];
// Only text-like files can be opened in editor and saved as text payload.
$TEXT_EDITABLE_EXTENSIONS = ['html', 'css', 'js', 'json', 'txt', 'md'];

// Forbidden paths (never allow access)
$FORBIDDEN_PATHS = [
    '/api/config.php',
    '/api/database.php',
    '/storage/data/',
    '/.htaccess',
    '/.git/',
    '/vendor/',
    '/node_modules/'
];

// Explicit exceptions for admin file editor (room JSON management).
$ALLOWED_PATH_OVERRIDES = [
    '/storage/data/rooms',
    '/storage/data/room-images.json',
    '/storage/data/lake-guide.json',
];

// Root directory (can't go above this)
$ROOT_DIR = dirname(__DIR__);

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function errorResponse($message, $code = 400) {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

function normalizeRelativePath(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $normalized = preg_replace('#/+#', '/', $normalized) ?: $normalized;
    if ($normalized === '') {
        return '/';
    }
    if ($normalized[0] !== '/') {
        $normalized = '/' . $normalized;
    }
    return rtrim($normalized, '/') === '' ? '/' : rtrim($normalized, '/');
}

function isPathAllowedOverride(string $relativePath, array $allowedOverrides): bool
{
    $current = normalizeRelativePath($relativePath);
    foreach ($allowedOverrides as $allowed) {
        $base = normalizeRelativePath((string) $allowed);
        if (
            $current === $base
            || strpos($current . '/', $base . '/') === 0
            || strpos($base . '/', $current . '/') === 0
        ) {
            return true;
        }
    }
    return false;
}

function isPathSafe($path, $rootDir, $forbiddenPaths, array $allowedOverrides = []) {
    // Normalize path
    $realPath = realpath($path);
    if (!$realPath) {
        // File doesn't exist yet, check parent directory
        $parentDir = dirname($path);
        $realParent = realpath($parentDir);
        if (!$realParent || strpos($realParent, $rootDir) !== 0) {
            return false;
        }
    } else {
        // Check if within root
        if (strpos($realPath, $rootDir) !== 0) {
            return false;
        }
    }

    // Check forbidden paths
    $relativePath = normalizeRelativePath(str_replace($rootDir, '', $path));

    if (isPathAllowedOverride($relativePath, $allowedOverrides)) {
        return true;
    }

    foreach ($forbiddenPaths as $forbidden) {
        if (strpos($relativePath . '/', rtrim((string) $forbidden, '/') . '/') === 0 || strpos($relativePath, (string) $forbidden) !== false) {
            return false;
        }
    }

    return true;
}

function getFileExtension($path) {
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

function sanitizeBackupName($value) {
    $name = trim((string) $value);
    $name = basename($name);
    if ($name === '' || !preg_match('/^[a-zA-Z0-9._-]+\.zip$/', $name)) {
        return '';
    }
    return $name;
}

function resolveBackupPath($name) {
    $safeName = sanitizeBackupName($name);
    if ($safeName === '') {
        return null;
    }

    $backupRoot = realpath(STORAGE_BACKUPS_DIR);
    if ($backupRoot === false) {
        return null;
    }

    $candidate = $backupRoot . DIRECTORY_SEPARATOR . $safeName;
    $real = realpath($candidate);
    if ($real === false || strpos($real, $backupRoot) !== 0 || !is_file($real)) {
        return null;
    }

    return $real;
}

function streamBackupDownload($filePath, $downloadName) {
    if (!is_file($filePath) || !is_readable($filePath)) {
        errorResponse('Backup file not found', 404);
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $safeName = str_replace('"', '', basename((string) $downloadName));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
    header('Content-Length: ' . (string) filesize($filePath));
    header('Cache-Control: private, max-age=0, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($filePath);
    exit;
}

function sanitizeBackupUploadBase(string $name): string
{
    $base = strtolower(pathinfo($name, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9._-]+/i', '-', $base) ?? '';
    $base = trim($base, '-_.');
    if ($base === '') {
        $base = 'uploaded';
    }
    if (strlen($base) > 60) {
        $base = substr($base, 0, 60);
    }
    return $base;
}

function parseIniSizeToBytes($value): int
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return 0;
    }
    $unit = strtolower(substr($raw, -1));
    $number = (float) $raw;
    switch ($unit) {
        case 'g':
            return (int) round($number * 1024 * 1024 * 1024);
        case 'm':
            return (int) round($number * 1024 * 1024);
        case 'k':
            return (int) round($number * 1024);
        default:
            return (int) round((float) $raw);
    }
}

function describeUploadError(int $code): string
{
    $uploadMax = (string) ini_get('upload_max_filesize');
    $postMax = (string) ini_get('post_max_size');

    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "ZIP завеликий для сервера (upload_max_filesize={$uploadMax}, post_max_size={$postMax})";
        case UPLOAD_ERR_PARTIAL:
            return 'Файл завантажився частково. Спробуйте ще раз.';
        case UPLOAD_ERR_NO_FILE:
            return 'Файл не передано. Оберіть ZIP і повторіть.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'На сервері відсутня тимчасова папка для upload.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Сервер не зміг записати файл на диск.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload зупинено серверним розширенням.';
        default:
            return "Upload failed (code {$code})";
    }
}

function isZipSignatureValid(string $filePath): bool
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return false;
    }

    $handle = @fopen($filePath, 'rb');
    if (!$handle) {
        return false;
    }

    $signature = (string) fread($handle, 4);
    fclose($handle);

    return in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
}

function handleStorageBackupUpload(Database $db, string $ip): void
{
    if (!ensure_storage_backups_dir()) {
        errorResponse('Unable to prepare backup directory', 500);
    }

    $files = normalize_uploaded_files('backup');
    if (count($files) === 0) {
        $files = normalize_uploaded_files('file');
    }
    if (count($files) === 0) {
        errorResponse('No ZIP file uploaded', 400);
    }
    if (count($files) > 1) {
        errorResponse('Upload exactly one ZIP file', 400);
    }

    $file = $files[0];
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        errorResponse(describeUploadError($uploadError), 400);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        errorResponse('Invalid upload source', 400);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        errorResponse('Empty file', 400);
    }
    if ($size > 512 * 1024 * 1024) {
        errorResponse('ZIP is too large. Maximum 512 MB.', 400);
    }

    $original = (string) ($file['name'] ?? 'backup.zip');
    if (!preg_match('/\.zip$/i', $original)) {
        errorResponse('Only .zip files are allowed', 400);
    }

    if (!isZipSignatureValid($tmp)) {
        errorResponse('Uploaded file is not a valid ZIP archive', 400);
    }

    $backupDir = rtrim(STORAGE_BACKUPS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $base = sanitizeBackupUploadBase($original);
    $filenameBase = 'storage-backup-' . date('Y-m-d_H-i-s') . '__import-' . $base;
    $filename = $filenameBase . '.zip';
    $targetPath = $backupDir . $filename;
    $counter = 1;
    while (file_exists($targetPath) && $counter < 1000) {
        $filename = $filenameBase . '-' . $counter . '.zip';
        $targetPath = $backupDir . $filename;
        $counter++;
    }
    if (file_exists($targetPath)) {
        errorResponse('Failed to generate unique backup name', 500);
    }

    if (!move_uploaded_file($tmp, $targetPath)) {
        errorResponse('Failed to store uploaded backup', 500);
    }

    @chmod($targetPath, 0640);

    $meta = parse_storage_backup_filename($filename);
    $db->logAdminAction('storage_backup_upload', $filename, $ip);
    jsonResponse([
        'success' => true,
        'backup' => [
            'name' => $filename,
            'size' => (int) (filesize($targetPath) ?: 0),
            'modified' => date('c'),
            'created_at' => (string) ($meta['created_at'] ?? date('c')),
            'reason' => (string) ($meta['reason'] ?? 'import'),
            'reason_label' => (string) ($meta['reason_label'] ?? ''),
            'kind' => (string) ($meta['kind'] ?? 'import'),
            'kind_label' => (string) ($meta['kind_label'] ?? 'Імпорт'),
            'label' => (string) ($meta['label'] ?? $filename),
        ],
    ]);
}

function detectMultipartOverflow(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        return;
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'multipart/form-data') !== 0) {
        return;
    }

    if (!empty($_POST) || !empty($_FILES)) {
        return;
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxRaw = (string) ini_get('post_max_size');
    $postMaxBytes = parseIniSizeToBytes($postMaxRaw);
    $uploadMaxRaw = (string) ini_get('upload_max_filesize');

    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        errorResponse("Розмір запиту перевищує post_max_size={$postMaxRaw} (upload_max_filesize={$uploadMaxRaw})", 400);
    }

    // Fallback: multipart body was sent but PHP did not parse form fields/files.
    errorResponse('Не вдалося обробити multipart upload. Перевірте розмір ZIP і ліміти PHP.', 400);
}

function removeDirectoryRecursive(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $path = $item->getPathname();
        if ($item->isDir()) {
            if (!@rmdir($path)) {
                return false;
            }
            continue;
        }
        if (!@unlink($path)) {
            return false;
        }
    }

    return @rmdir($dir);
}

function isBackupRestoreEntrySafe(string $entryName, ?string &$relative = null, ?bool &$isDir = null): bool
{
    $relative = null;
    $isDir = false;

    $raw = str_replace('\\', '/', (string) $entryName);
    if ($raw === '' || strpos($raw, "\0") !== false) {
        return false;
    }
    if (strpos($raw, '/') === 0 || preg_match('/^[a-zA-Z]:\//', $raw)) {
        return false;
    }

    $isDir = substr($raw, -1) === '/';
    $path = trim($raw);
    while (strpos($path, './') === 0) {
        $path = substr($path, 2);
    }
    $path = trim($path, '/');
    if ($path === '') {
        return true;
    }

    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }

    // We restore only storage data/uploads/logs trees.
    if ($parts[0] !== 'storage') {
        return true;
    }
    if (count($parts) < 2) {
        return true;
    }
    if (!in_array($parts[1], ['data', 'uploads', 'logs'], true)) {
        return true;
    }

    $relative = implode('/', array_slice($parts, 1));
    return true;
}

function copyZipEntryStream($zip, string $entryName, string $targetPath): bool
{
    $input = $zip->getStream($entryName);
    if (!$input) {
        return false;
    }

    $output = @fopen($targetPath, 'wb');
    if (!$output) {
        fclose($input);
        return false;
    }

    $ok = true;
    while (!feof($input)) {
        $chunk = fread($input, 8192);
        if ($chunk === false) {
            $ok = false;
            break;
        }
        if ($chunk !== '' && fwrite($output, $chunk) === false) {
            $ok = false;
            break;
        }
    }

    fclose($output);
    fclose($input);
    return $ok;
}

function restoreStorageFromBackupArchive(string $archivePath, string $archiveName): array
{
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'ZIP extension is not available on server'];
    }
    if (!is_file($archivePath) || !is_readable($archivePath)) {
        return ['success' => false, 'error' => 'Backup archive is not readable'];
    }

    $zip = new ZipArchive();
    $opened = $zip->open($archivePath);
    if ($opened !== true) {
        return ['success' => false, 'error' => 'Failed to open backup archive'];
    }

    try {
        $token = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $token = date('Ymd-His') . '-' . mt_rand(1000, 9999);
    }

    $storageRoot = rtrim(STORAGE_DIR, DIRECTORY_SEPARATOR);
    $restoreRoot = $storageRoot . DIRECTORY_SEPARATOR . '.restore-' . $token;
    $incomingRoot = $restoreRoot . DIRECTORY_SEPARATOR . 'incoming';
    $swapRoot = $restoreRoot . DIRECTORY_SEPARATOR . 'swap';

    if (!is_dir($incomingRoot) && !mkdir($incomingRoot, 0755, true) && !is_dir($incomingRoot)) {
        $zip->close();
        return ['success' => false, 'error' => 'Unable to prepare restore workspace'];
    }

    $restoredEntries = 0;
    $restoredBytes = 0;
    $skippedEntries = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if (!is_string($entryName) || $entryName === '') {
            continue;
        }

        $relative = null;
        $isDir = false;
        if (!isBackupRestoreEntrySafe($entryName, $relative, $isDir)) {
            $zip->close();
            removeDirectoryRecursive($restoreRoot);
            return ['success' => false, 'error' => 'Unsafe path detected in backup archive'];
        }

        if ($relative === null || $relative === '') {
            $skippedEntries++;
            continue;
        }

        $targetPath = $incomingRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($isDir) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                $zip->close();
                removeDirectoryRecursive($restoreRoot);
                return ['success' => false, 'error' => 'Failed to create restore directory'];
            }
            $restoredEntries++;
            continue;
        }

        $parentDir = dirname($targetPath);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            $zip->close();
            removeDirectoryRecursive($restoreRoot);
            return ['success' => false, 'error' => 'Failed to prepare restore path'];
        }

        if (!copyZipEntryStream($zip, $entryName, $targetPath)) {
            $zip->close();
            removeDirectoryRecursive($restoreRoot);
            return ['success' => false, 'error' => 'Failed to extract backup file'];
        }

        @chmod($targetPath, 0640);
        $entryStat = $zip->statIndex($i);
        $restoredBytes += (int) ($entryStat['size'] ?? 0);
        $restoredEntries++;
    }

    $zip->close();

    if ($restoredEntries === 0) {
        removeDirectoryRecursive($restoreRoot);
        return ['success' => false, 'error' => 'Backup archive does not contain restorable storage data'];
    }

    if (!is_dir($swapRoot) && !mkdir($swapRoot, 0755, true) && !is_dir($swapRoot)) {
        removeDirectoryRecursive($restoreRoot);
        return ['success' => false, 'error' => 'Failed to prepare restore swap area'];
    }

    $swapped = [];
    foreach (['data', 'uploads', 'logs'] as $segment) {
        $newDir = $incomingRoot . DIRECTORY_SEPARATOR . $segment;
        if (!is_dir($newDir)) {
            continue;
        }

        $targetDir = $storageRoot . DIRECTORY_SEPARATOR . $segment;
        $oldDir = $swapRoot . DIRECTORY_SEPARATOR . 'old-' . $segment;

        if (is_dir($oldDir) && !removeDirectoryRecursive($oldDir)) {
            foreach (array_reverse($swapped) as $item) {
                if (is_dir($item['target'])) {
                    removeDirectoryRecursive($item['target']);
                }
                if ($item['old'] !== '' && is_dir($item['old'])) {
                    @rename($item['old'], $item['target']);
                }
            }
            removeDirectoryRecursive($restoreRoot);
            return ['success' => false, 'error' => 'Failed to prepare rollback folder'];
        }

        if (is_file($targetDir) && !@unlink($targetDir)) {
            foreach (array_reverse($swapped) as $item) {
                if (is_dir($item['target'])) {
                    removeDirectoryRecursive($item['target']);
                }
                if ($item['old'] !== '' && is_dir($item['old'])) {
                    @rename($item['old'], $item['target']);
                }
            }
            removeDirectoryRecursive($restoreRoot);
            return ['success' => false, 'error' => 'Failed to clear target path before restore'];
        }

        $oldRef = '';
        if (is_dir($targetDir)) {
            if (!@rename($targetDir, $oldDir)) {
                foreach (array_reverse($swapped) as $item) {
                    if (is_dir($item['target'])) {
                        removeDirectoryRecursive($item['target']);
                    }
                    if ($item['old'] !== '' && is_dir($item['old'])) {
                        @rename($item['old'], $item['target']);
                    }
                }
                removeDirectoryRecursive($restoreRoot);
                return ['success' => false, 'error' => 'Failed to prepare storage rollback snapshot'];
            }
            $oldRef = $oldDir;
        }

        if (!@rename($newDir, $targetDir)) {
            if ($oldRef !== '' && is_dir($oldRef) && !is_dir($targetDir)) {
                @rename($oldRef, $targetDir);
            }
            foreach (array_reverse($swapped) as $item) {
                if (is_dir($item['target'])) {
                    removeDirectoryRecursive($item['target']);
                }
                if ($item['old'] !== '' && is_dir($item['old'])) {
                    @rename($item['old'], $item['target']);
                }
            }
            removeDirectoryRecursive($restoreRoot);
            return ['success' => false, 'error' => 'Failed to apply restored storage snapshot'];
        }

        $swapped[] = [
            'target' => $targetDir,
            'old' => $oldRef,
        ];
    }

    foreach ($swapped as $item) {
        if ($item['old'] !== '' && is_dir($item['old'])) {
            removeDirectoryRecursive($item['old']);
        }
    }

    removeDirectoryRecursive($restoreRoot);

    return [
        'success' => true,
        'restored' => [
            'archive' => basename($archiveName),
            'entries' => $restoredEntries,
            'bytes' => $restoredBytes,
            'skipped' => $skippedEntries,
            'restored_at' => date('c'),
        ],
    ];
}

// ========== AUTH CHECK ==========
require_admin_auth($db, $input);
enforce_rate_limit($db, $ip, 'files_admin_' . strtolower($method), 240, 3600);
if ($method === 'POST') {
    require_csrf_token($input);
}

// ========== ROUTES ==========

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    // List files in directory
    if ($action === 'list') {
        $path = $_GET['path'] ?? '/';
        $fullPath = $ROOT_DIR . '/' . ltrim($path, '/');

        if (!isPathSafe($fullPath, $ROOT_DIR, $FORBIDDEN_PATHS, $ALLOWED_PATH_OVERRIDES)) {
            errorResponse('Access denied');
        }

        if (!is_dir($fullPath)) {
            errorResponse('Not a directory');
        }

        $items = [];
        $files = scandir($fullPath);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if ($file[0] === '.' && $file !== '.htaccess') continue; // Skip hidden files except .htaccess

            $itemPath = $fullPath . '/' . $file;
            $relativePath = $path . '/' . $file;
            $relativePath = str_replace('//', '/', $relativePath);

            if (!isPathSafe($itemPath, $ROOT_DIR, $FORBIDDEN_PATHS, $ALLOWED_PATH_OVERRIDES)) continue;

            $isDir = is_dir($itemPath);
            $ext = $isDir ? '' : getFileExtension($file);

            $items[] = [
                'name' => $file,
                'path' => $relativePath,
                'type' => $isDir ? 'directory' : 'file',
                'extension' => $ext,
                'editable' => !$isDir && in_array($ext, $TEXT_EDITABLE_EXTENSIONS, true),
                'size' => $isDir ? null : filesize($itemPath),
                'modified' => date('Y-m-d H:i:s', filemtime($itemPath))
            ];
        }

        // Sort: directories first, then files
        usort($items, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        jsonResponse([
            'success' => true,
            'path' => $path,
            'items' => $items
        ]);
    }

    // Read file content
    if ($action === 'read') {
        $path = $_GET['path'] ?? '';
        if (empty($path)) errorResponse('Path required');

        $fullPath = $ROOT_DIR . '/' . ltrim($path, '/');

        if (!isPathSafe($fullPath, $ROOT_DIR, $FORBIDDEN_PATHS, $ALLOWED_PATH_OVERRIDES)) {
            errorResponse('Access denied');
        }

        if (!file_exists($fullPath)) {
            errorResponse('File not found');
        }

        if (!is_file($fullPath)) {
            errorResponse('Not a file');
        }

        $ext = getFileExtension($fullPath);
        if (!in_array($ext, $TEXT_EDITABLE_EXTENSIONS, true)) {
            errorResponse('File type not allowed for editing');
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            errorResponse('Failed to read file', 500);
        }

        jsonResponse([
            'success' => true,
            'path' => $path,
            'content' => $content,
            'size' => strlen($content),
            'modified' => date('Y-m-d H:i:s', filemtime($fullPath))
        ]);
    }

    // List storage backups
    if ($action === 'storage-backups') {
        // Daily automatic snapshot while admin panel is used.
        maybe_auto_storage_backup('daily-list', 24 * 3600);
        jsonResponse([
            'success' => true,
            'backups' => list_storage_backups(60),
        ]);
    }

    // Download storage backup (auth protected)
    if ($action === 'download-storage-backup') {
        $name = (string) ($_GET['name'] ?? '');
        $filePath = resolveBackupPath($name);
        if (!$filePath) {
            errorResponse('Backup not found', 404);
        }
        streamBackupDownload($filePath, basename($filePath));
    }

    errorResponse('Unknown action');
}

if ($method === 'POST') {
    detectMultipartOverflow();
    $action = $input['action'] ?? 'save';

    if (in_array($action, ['save', 'create', 'delete', 'rename'], true)) {
        maybe_auto_storage_backup('files-' . $action, 6 * 3600);
    }

    if ($action === 'create-storage-backup') {
        $reason = trim((string) ($input['reason'] ?? 'manual-admin'));
        $created = create_storage_backup_zip($reason, 80);
        if (!($created['success'] ?? false)) {
            errorResponse((string) ($created['error'] ?? 'Backup failed'), 500);
        }

        $db->logAdminAction('storage_backup_create', (string) ($created['backup']['name'] ?? ''), $ip);
        jsonResponse($created);
    }

    if ($action === 'upload-storage-backup') {
        handleStorageBackupUpload($db, $ip);
    }

    if ($action === 'restore-storage-backup') {
        $name = (string) ($input['name'] ?? '');
        $filePath = resolveBackupPath($name);
        if (!$filePath) {
            errorResponse('Backup not found', 404);
        }

        $selectedBackupMeta = parse_storage_backup_filename(basename($filePath));
        $baseReason = sanitize_storage_backup_reason((string) ($selectedBackupMeta['reason'] ?? ''), 'restore');

        $preBackup = create_storage_backup_zip('pre-restore-' . $baseReason, 90);
        if (!($preBackup['success'] ?? false)) {
            errorResponse((string) ($preBackup['error'] ?? 'Failed to create pre-restore backup'), 500);
        }

        $restored = restoreStorageFromBackupArchive($filePath, basename($filePath));
        if (!($restored['success'] ?? false)) {
            errorResponse((string) ($restored['error'] ?? 'Restore failed'), 500);
        }

        try {
            $db->logAdminAction('storage_backup_restore', basename($filePath), $ip);
        } catch (Throwable $e) {
            // Ignore log write issues after data directory swap.
        }
        jsonResponse([
            'success' => true,
            'message' => 'Storage restored from backup',
            'pre_restore_backup' => $preBackup['backup'] ?? null,
            'restored' => $restored['restored'] ?? null,
        ]);
    }

    // Save file
    if ($action === 'save') {
        $path = (string) ($input['path'] ?? '');
        $content = (string) ($input['content'] ?? '');

        if (empty($path)) errorResponse('Path required');
        if (strlen($content) > 2 * 1024 * 1024) errorResponse('Content too large');

        $fullPath = $ROOT_DIR . '/' . ltrim($path, '/');

        if (!isPathSafe($fullPath, $ROOT_DIR, $FORBIDDEN_PATHS, $ALLOWED_PATH_OVERRIDES)) {
            errorResponse('Access denied');
        }

        $ext = getFileExtension($fullPath);
        if (!in_array($ext, $TEXT_EDITABLE_EXTENSIONS, true)) {
            errorResponse('File type not allowed for editing');
        }

        // Create backup
        if (file_exists($fullPath)) {
            $backupDir = $ROOT_DIR . '/storage/backups/' . date('Y-m-d');
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            $backupName = basename($path) . '.' . date('His') . '.bak';
            copy($fullPath, $backupDir . '/' . $backupName);
        }

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Save file
        $result = file_put_contents($fullPath, $content);

        if ($result === false) {
            errorResponse('Failed to save file');
        }

        $db->logAdminAction('file_edit', "Path: $path", $ip);

        jsonResponse([
            'success' => true,
            'message' => 'File saved',
            'path' => $path,
            'size' => $result
        ]);
    }

    // Create file
    if ($action === 'create') {
        $path = (string) ($input['path'] ?? '');
        $type = (string) ($input['type'] ?? 'file');

        if (empty($path)) errorResponse('Path required');

        $fullPath = $ROOT_DIR . '/' . ltrim($path, '/');

        if (!isPathSafe($fullPath, $ROOT_DIR, $FORBIDDEN_PATHS, $ALLOWED_PATH_OVERRIDES)) {
            errorResponse('Access denied');
        }

        if (file_exists($fullPath)) {
            errorResponse('File already exists');
        }

        if ($type === 'directory') {
            $result = mkdir($fullPath, 0755, true);
        } else {
            $ext = getFileExtension($fullPath);
            if (!in_array($ext, $TEXT_EDITABLE_EXTENSIONS, true)) {
                errorResponse('File type not allowed');
            }

            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $result = file_put_contents($fullPath, '') !== false;
        }

        if (!$result) {
            errorResponse('Failed to create');
        }

        $db->logAdminAction('file_create', "Path: $path, Type: $type", $ip);

        jsonResponse([
            'success' => true,
            'message' => $type === 'directory' ? 'Folder created' : 'File created'
        ]);
    }

    // Delete file
    if ($action === 'delete') {
        $path = (string) ($input['path'] ?? '');

        if (empty($path)) errorResponse('Path required');

        $fullPath = $ROOT_DIR . '/' . ltrim($path, '/');

        if (!isPathSafe($fullPath, $ROOT_DIR, $FORBIDDEN_PATHS, $ALLOWED_PATH_OVERRIDES)) {
            errorResponse('Access denied');
        }

        if (!file_exists($fullPath)) {
            errorResponse('File not found');
        }

        // Create backup before delete
        $backupDir = $ROOT_DIR . '/storage/backups/deleted/' . date('Y-m-d');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        if (is_dir($fullPath)) {
            // Don't allow deleting non-empty directories
            if (count(scandir($fullPath)) > 2) {
                errorResponse('Directory is not empty');
            }
            $result = rmdir($fullPath);
        } else {
            // Backup the file
            $backupName = basename($path) . '.' . date('His') . '.deleted';
            copy($fullPath, $backupDir . '/' . $backupName);
            $result = unlink($fullPath);
        }

        if (!$result) {
            errorResponse('Failed to delete');
        }

        $db->logAdminAction('file_delete', "Path: $path", $ip);

        jsonResponse([
            'success' => true,
            'message' => 'Deleted'
        ]);
    }

    // Rename/move file
    if ($action === 'rename') {
        $oldPath = (string) ($input['oldPath'] ?? '');
        $newPath = (string) ($input['newPath'] ?? '');

        if (empty($oldPath) || empty($newPath)) {
            errorResponse('Both paths required');
        }

        $fullOldPath = $ROOT_DIR . '/' . ltrim($oldPath, '/');
        $fullNewPath = $ROOT_DIR . '/' . ltrim($newPath, '/');

        if (!isPathSafe($fullOldPath, $ROOT_DIR, $FORBIDDEN_PATHS, $ALLOWED_PATH_OVERRIDES) ||
            !isPathSafe($fullNewPath, $ROOT_DIR, $FORBIDDEN_PATHS, $ALLOWED_PATH_OVERRIDES)) {
            errorResponse('Access denied');
        }

        if (!file_exists($fullOldPath)) {
            errorResponse('Source not found');
        }

        if (file_exists($fullNewPath)) {
            errorResponse('Destination already exists');
        }

        if (is_file($fullOldPath)) {
            $oldExt = getFileExtension($fullOldPath);
            $newExt = getFileExtension($fullNewPath);

            if (!in_array($oldExt, $MANAGEABLE_EXTENSIONS, true) || !in_array($newExt, $MANAGEABLE_EXTENSIONS, true)) {
                errorResponse('File type not allowed');
            }
        }

        $result = rename($fullOldPath, $fullNewPath);

        if (!$result) {
            errorResponse('Failed to rename');
        }

        $db->logAdminAction('file_rename', "From: $oldPath To: $newPath", $ip);

        jsonResponse([
            'success' => true,
            'message' => 'Renamed'
        ]);
    }

    errorResponse('Unknown action');
}

errorResponse('Method not allowed', 405);

<?php
/**
 * SvityazHOME — PHP Built-in Server Router
 * Емулює поведінку Apache: DirectoryIndex, MIME-типи, CORS, clean URLs, gzip
 */

$root = __DIR__;
$uri  = urldecode((string) (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'));
if ($uri === '') {
    $uri = '/';
}
$normalizedPath = '/' . ltrim($uri, '/');
$file = $root . $uri;
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocalDevHost = (
    str_contains($host, 'localhost') ||
    str_contains($host, '127.0.0.1') ||
    str_contains($host, '[::1]')
);

// ── Direct-access guards for sensitive files ───────────────────────────────
if (
    preg_match('#^/storage/backups/#i', $normalizedPath) ||
    preg_match('#^/storage/data/#i', $normalizedPath) ||
    preg_match('#^/[^/]+\.(zip|tar|gz|tgz|7z|bak|old|sql|sqlite|db)$#i', $normalizedPath) ||
    (preg_match('#(^|/)\.#', $normalizedPath) && !preg_match('#^/\.well-known/#i', $normalizedPath))
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    return true;
}

// ── Legacy HTTP Basic Auth lock is deprecated ────────────────────────────────
// Use the UI-based early-access password gate via /api/access.php instead.

// ── CORS headers (як mod_headers в Apache) ──────────────────────
$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedOrigins = [
    'https://svityazhome.com.ua',
    'https://www.svityazhome.com.ua',
    'http://localhost',
    'http://localhost:8000',
    'http://127.0.0.1',
    'http://127.0.0.1:8000',
];
$extraOrigins = trim((string) getenv('ROUTER_ALLOWED_ORIGINS'));
if ($extraOrigins !== '') {
    foreach (explode(',', $extraOrigins) as $item) {
        $candidate = trim($item);
        if ($candidate !== '') {
            $allowedOrigins[] = $candidate;
        }
    }
}
$allowedOrigins = array_values(array_unique($allowedOrigins));
$originAllowed = ($origin !== '' && in_array($origin, $allowedOrigins, true));

if ($originAllowed) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin !== '' && !$originAllowed) {
        http_response_code(403);
        exit;
    }
    http_response_code(204);
    exit;
}

// ── Розширені MIME-типи (як mod_mime) ───────────────────────────
$mimeTypes = [
    'html' => 'text/html; charset=UTF-8',
    'htm'  => 'text/html; charset=UTF-8',
    'css'  => 'text/css; charset=UTF-8',
    'js'   => 'application/javascript; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'xml'  => 'application/xml; charset=UTF-8',
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'ico'  => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2'=> 'font/woff2',
    'ttf'  => 'font/ttf',
    'otf'  => 'font/otf',
    'eot'  => 'application/vnd.ms-fontobject',
    'pdf'  => 'application/pdf',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mp3'  => 'audio/mpeg',
    'ogg'  => 'audio/ogg',
    'txt'  => 'text/plain; charset=UTF-8',
    'map'  => 'application/json',
    'mjs'  => 'application/javascript; charset=UTF-8',
    'php'  => null, // PHP обробляється окремо
];

// ── Кешування статики (як mod_expires) ──────────────────────────
$cacheTypes = [
    'css'  => 86400 * 7,   // 1 тиждень
    'js'   => 86400 * 7,
    'png'  => 86400 * 30,  // 1 місяць
    'jpg'  => 86400 * 30,
    'jpeg' => 86400 * 30,
    'gif'  => 86400 * 30,
    'webp' => 86400 * 30,
    'avif' => 86400 * 30,
    'svg'  => 86400 * 30,
    'ico'  => 86400 * 30,
    'woff' => 86400 * 365, // 1 рік
    'woff2'=> 86400 * 365,
    'ttf'  => 86400 * 365,
    'otf'  => 86400 * 365,
];

// ── DirectoryIndex (як в Apache) ────────────────────────────────
// Якщо запитано директорію — шукаємо index.php / index.html
if (is_dir($file)) {
    $file = rtrim($file, '/\\');
    foreach (['index.php', 'index.html', 'index.htm'] as $idx) {
        if (file_exists("$file/$idx")) {
            $file = "$file/$idx";
            break;
        }
    }
}

// ── Обробка неіснуючих файлів → 404 ────────────────────────────
if (!file_exists($file) || is_dir($file)) {
    // Спробувати з .html розширенням (clean URLs як mod_rewrite)
    if (file_exists($file . '.html')) {
        $file = $file . '.html';
    } elseif (file_exists($file . '.php')) {
        $file = $file . '.php';
    } else {
        // 404
        http_response_code(404);
        $custom404 = $root . '/404.html';
        if (file_exists($custom404)) {
            header('Content-Type: text/html; charset=UTF-8');
            readfile($custom404);
        } else {
            echo '<h1>404 Not Found</h1>';
        }
        return true;
    }
}

// ── Визначити розширення ────────────────────────────────────────
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

// ── PHP файли — виконати (як mod_php / php-fpm) ─────────────────
if ($ext === 'php') {
    // Встановити правильні $_SERVER змінні
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['SCRIPT_NAME']     = str_replace($root, '', $file);
    $_SERVER['DOCUMENT_ROOT']   = $root;
    chdir(dirname($file));
    include $file;
    return true;
}

// ── Статичні файли ──────────────────────────────────────────────
// Content-Type
if (isset($mimeTypes[$ext])) {
    header("Content-Type: {$mimeTypes[$ext]}");
} else {
    header('Content-Type: application/octet-stream');
}

// Cache-Control
// Для локальної розробки вимикаємо кеш JS/CSS, щоб зміни підхоплювались одразу.
if ($isLocalDevHost && in_array($ext, ['js', 'mjs', 'css', 'map'], true)) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
} elseif (in_array($ext, ['js', 'mjs', 'css', 'map'], true)) {
    // Revalidate front-end assets on each request to avoid stale UI logic after deploy.
    header('Cache-Control: public, max-age=0, must-revalidate');
} elseif (isset($cacheTypes[$ext])) {
    $maxAge = $cacheTypes[$ext];
    header("Cache-Control: public, max-age=$maxAge");
} else {
    // HTML та інше — без кешу (як для розробки)
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

// ETag (як mod_etag)
$etag = '"' . md5_file($file) . '"';
header("ETag: $etag");
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    return true;
}

// Content-Length
$size = filesize($file);
header("Content-Length: $size");

// X-Content-Type-Options (безпека)
header('X-Content-Type-Options: nosniff');

// Gzip для текстових типів (як mod_deflate)
$compressible = ['html','htm','css','js','json','xml','svg','txt','mjs','map'];
if (in_array($ext, $compressible) && $size > 1024) {
    if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
        $compressed = gzencode(file_get_contents($file), 6);
        if ($compressed !== false) {
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($compressed));
            echo $compressed;
            return true;
        }
    }
}

// Віддати файл
readfile($file);
return true;

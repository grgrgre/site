<?php
/**
 * Admin AI studio API.
 * Rewrites text files and generates/edits images through OpenAI without exposing the key in browser code.
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['POST', 'OPTIONS'])) {
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    error_response('Method not allowed', 405);
}

initStorage();
$db = Database::getInstance();
$input = read_input_payload();
$ip = get_client_ip();

require_admin_auth($db, $input);
require_csrf_token($input);
enforce_rate_limit($db, $ip, 'ai_studio_hourly', 80, 3600);
enforce_rate_limit($db, $ip, 'ai_studio_burst', 20, 300);

$action = strtolower(trim((string) ($input['action'] ?? '')));

function ai_studio_normalize_relative_path(string $path): string
{
    $normalized = str_replace('\\', '/', trim($path));
    $normalized = preg_replace('#/+#', '/', $normalized) ?: $normalized;
    if ($normalized === '') {
        return '';
    }
    if ($normalized[0] !== '/') {
        $normalized = '/' . $normalized;
    }
    return $normalized;
}

function ai_studio_openai_api_key(): string
{
    return trim((string) (getenv('OPENAI_API_KEY') ?: ''));
}

function ai_studio_openai_chat_request(string $apiKey, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL extension is missing', 'status' => 500];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        return ['ok' => false, 'error' => 'Failed to serialize request', 'status' => 500];
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 80,
        CURLOPT_CONNECTTIMEOUT => 12,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'error' => 'Connection error', 'status' => 502];
    }
    if (!is_string($response) || $response === '') {
        return ['ok' => false, 'error' => 'Empty upstream response', 'status' => 502];
    }

    $decoded = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $error = 'OpenAI request failed';
        if (is_array($decoded)) {
            $error = (string) ($decoded['error']['message'] ?? $decoded['error'] ?? $error);
        }
        return ['ok' => false, 'error' => $error, 'status' => ($httpCode > 0 ? $httpCode : 502)];
    }

    return [
        'ok' => true,
        'status' => $httpCode,
        'decoded' => is_array($decoded) ? $decoded : [],
    ];
}

function ai_studio_openai_images_json_request(string $apiKey, string $endpoint, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL extension is missing', 'status' => 500];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        return ['ok' => false, 'error' => 'Failed to serialize request', 'status' => 500];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 12,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'error' => 'Connection error', 'status' => 502];
    }
    if (!is_string($response) || $response === '') {
        return ['ok' => false, 'error' => 'Empty upstream response', 'status' => 502];
    }

    $decoded = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $error = 'OpenAI image request failed';
        if (is_array($decoded)) {
            $error = (string) ($decoded['error']['message'] ?? $decoded['error'] ?? $error);
        }
        return ['ok' => false, 'error' => $error, 'status' => ($httpCode > 0 ? $httpCode : 502)];
    }

    return [
        'ok' => true,
        'status' => $httpCode,
        'decoded' => is_array($decoded) ? $decoded : [],
    ];
}

function ai_studio_openai_images_multipart_request(string $apiKey, string $endpoint, array $fields): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL extension is missing', 'status' => 500];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 12,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'error' => 'Connection error', 'status' => 502];
    }
    if (!is_string($response) || $response === '') {
        return ['ok' => false, 'error' => 'Empty upstream response', 'status' => 502];
    }

    $decoded = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $error = 'OpenAI image request failed';
        if (is_array($decoded)) {
            $error = (string) ($decoded['error']['message'] ?? $decoded['error'] ?? $error);
        }
        return ['ok' => false, 'error' => $error, 'status' => ($httpCode > 0 ? $httpCode : 502)];
    }

    return [
        'ok' => true,
        'status' => $httpCode,
        'decoded' => is_array($decoded) ? $decoded : [],
    ];
}

function ai_studio_extract_section(string $response, string $label): string
{
    $pattern = '/\[\[\[' . preg_quote($label, '/') . '\]\]\]\s*(.*?)(?=\n\[\[\[|$)/su';
    if (preg_match($pattern, $response, $matches) === 1) {
        return trim((string) ($matches[1] ?? ''));
    }
    return '';
}

function ai_studio_resolve_existing_project_file(string $relativePath): ?string
{
    $relativePath = ai_studio_normalize_relative_path($relativePath);
    if ($relativePath === '') {
        return null;
    }

    $rootDir = dirname(__DIR__);
    $candidate = $rootDir . $relativePath;
    $real = realpath($candidate);
    if ($real === false || strpos($real, $rootDir) !== 0 || !is_file($real)) {
        return null;
    }

    return $real;
}

function ai_studio_is_public_image_read_allowed(string $fullPath): bool
{
    $normalized = str_replace('\\', '/', $fullPath);
    $uploadsRoot = str_replace('\\', '/', realpath(UPLOADS_DIR) ?: '');
    $assetsImagesRoot = str_replace('\\', '/', realpath(dirname(__DIR__) . '/assets/images') ?: '');

    if ($uploadsRoot !== '' && strpos($normalized, $uploadsRoot) === 0) {
        return true;
    }
    if ($assetsImagesRoot !== '' && strpos($normalized, $assetsImagesRoot) === 0) {
        return true;
    }

    return false;
}

function ai_studio_is_uploads_write_allowed(string $fullPath): bool
{
    $normalized = str_replace('\\', '/', $fullPath);
    $uploadsRoot = str_replace('\\', '/', realpath(UPLOADS_DIR) ?: '');
    return $uploadsRoot !== '' && strpos($normalized, $uploadsRoot) === 0;
}

function ai_studio_extension_from_format(string $format): string
{
    if ($format === 'jpeg') {
        return 'jpg';
    }
    return $format;
}

function ai_studio_detect_mime_type(string $fullPath): string
{
    if (function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($fullPath);
        if ($mime !== '') {
            return $mime;
        }
    }

    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    return $map[$ext] ?? 'application/octet-stream';
}

function ai_studio_make_output_target(string $format, string $sourceRelativePath = '', bool $replaceSource = false): array
{
    $ext = ai_studio_extension_from_format($format);
    $safeExt = in_array($ext, ['jpg', 'png', 'webp'], true) ? $ext : 'png';

    if ($replaceSource && $sourceRelativePath !== '') {
        $sourceFullPath = ai_studio_resolve_existing_project_file($sourceRelativePath);
        if ($sourceFullPath !== null && ai_studio_is_uploads_write_allowed($sourceFullPath)) {
            return [
                'relative_path' => ai_studio_normalize_relative_path($sourceRelativePath),
                'full_path' => $sourceFullPath,
                'replaced' => true,
            ];
        }
    }

    $relativeDir = '/storage/uploads/site/ai';
    if ($sourceRelativePath !== '') {
        $normalizedSource = ai_studio_normalize_relative_path($sourceRelativePath);
        $parentDir = dirname($normalizedSource);
        if ($parentDir !== '' && $parentDir !== '/' && str_starts_with($parentDir, '/storage/uploads/')) {
            $relativeDir = $parentDir;
        }
    }

    $targetDir = dirname(__DIR__) . $relativeDir;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        return [
            'error' => 'Unable to prepare output directory',
        ];
    }

    $baseName = 'ai-image-' . date('Ymd-His');
    if ($sourceRelativePath !== '') {
        $sourceName = pathinfo($sourceRelativePath, PATHINFO_FILENAME);
        $sourceName = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $sourceName) ?? '';
        $sourceName = trim($sourceName, '-_');
        if ($sourceName !== '') {
            $baseName = $sourceName . '-ai-' . date('Ymd-His');
        }
    }

    $fileName = $baseName . '.' . $safeExt;
    $fullPath = $targetDir . '/' . $fileName;

    return [
        'relative_path' => $relativeDir . '/' . $fileName,
        'full_path' => $fullPath,
        'replaced' => false,
    ];
}

function ai_studio_backup_existing_file(string $fullPath): void
{
    if (!is_file($fullPath)) {
        return;
    }

    $backupDir = rtrim(STORAGE_BACKUPS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . date('Y-m-d');
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        return;
    }

    $backupName = basename($fullPath) . '.' . date('His') . '.bak';
    @copy($fullPath, $backupDir . DIRECTORY_SEPARATOR . $backupName);
}

function ai_studio_resolve_editor_model(Database $db): string
{
    $settings = $db->getAiSettings();
    $model = sanitize_text_field((string) ($settings['model'] ?? ''), 80);
    if ($model === '' || preg_match('/^[a-zA-Z0-9._:-]{2,80}$/', $model) !== 1) {
        return 'gpt-4o-mini';
    }
    return $model;
}

if ($action === 'rewrite_html') {
    $path = ai_studio_normalize_relative_path((string) ($input['path'] ?? ''));
    $content = (string) ($input['content'] ?? '');
    $instruction = sanitize_multiline_text((string) ($input['instruction'] ?? ''), 3000);

    if ($path === '') {
        error_response('Path is required', 400);
    }
    if ($instruction === '') {
        error_response('Instruction is required', 400);
    }
    if ($content === '') {
        error_response('Current file content is required', 400);
    }
    if (strlen($content) > 220000) {
        error_response('File is too large for AI rewrite', 400);
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['html', 'css', 'js', 'json', 'txt', 'md'], true)) {
        error_response('Only text files can be processed by AI rewrite', 400);
    }

    $apiKey = ai_studio_openai_api_key();
    if ($apiKey === '') {
        error_response('OPENAI_API_KEY is not configured', 500);
    }

    $language = $ext === 'html' ? 'HTML' : strtoupper($ext);
    $model = ai_studio_resolve_editor_model($db);

    $systemPrompt = <<<PROMPT
Ти — senior-редактор статичного сайту на HTML/CSS/Vanilla JS + PHP services.
Працюєш обережно: не змінюй стек, не вигадуй бекенд-фреймворки, не ламай семантику.
Внось лише те, що просить адміністратор, але роби результат чистим, реалістичним і готовим до збереження у файл.

ПОВЕРНИ РІВНО ТАКИЙ ФОРМАТ:
[[[SUMMARY]]]
коротко опиши, що саме було змінено
[[[CONTENT]]]
повний фінальний вміст файлу без пояснень до і після

ЖОДНИХ markdown-блоків, ЖОДНИХ коментарів поза цим форматом.
PROMPT;

    $userPrompt = <<<PROMPT
Файл: {$path}
Тип файлу: {$language}

Завдання адміністратора:
{$instruction}

Поточний вміст файлу:
{$content}
PROMPT;

    $openAiResult = ai_studio_openai_chat_request($apiKey, [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'max_tokens' => max(512, min(OPENAI_EDITOR_MAX_TOKENS_HARD, OPENAI_EDITOR_MAX_TOKENS_DEFAULT)),
        'temperature' => 0.35,
    ]);

    if (!($openAiResult['ok'] ?? false)) {
        error_response((string) ($openAiResult['error'] ?? 'OpenAI request failed'), (int) ($openAiResult['status'] ?? 502));
    }

    $decoded = is_array($openAiResult['decoded'] ?? null) ? $openAiResult['decoded'] : [];
    $reply = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
    if ($reply === '') {
        error_response('Empty AI reply', 502);
    }

    $summary = ai_studio_extract_section($reply, 'SUMMARY');
    $rewrittenContent = ai_studio_extract_section($reply, 'CONTENT');
    if ($rewrittenContent === '') {
        $rewrittenContent = $reply;
    }

    $db->logAdminAction('ai_rewrite_file', 'AI rewrite for ' . $path, $ip);

    json_response([
        'success' => true,
        'summary' => ($summary !== '' ? $summary : 'AI підготував оновлену версію файлу.'),
        'content' => $rewrittenContent,
        'path' => $path,
        'model' => $model,
        'usage' => $decoded['usage'] ?? null,
    ]);
}

if ($action === 'generate_image') {
    $prompt = sanitize_multiline_text((string) ($input['prompt'] ?? ''), 4000);
    $sourcePath = ai_studio_normalize_relative_path((string) ($input['source_path'] ?? ''));
    $format = strtolower(trim((string) ($input['output_format'] ?? 'webp')));
    $quality = strtolower(trim((string) ($input['quality'] ?? 'medium')));
    $size = strtolower(trim((string) ($input['size'] ?? '1024x1024')));
    $background = strtolower(trim((string) ($input['background'] ?? 'auto')));
    $replaceSource = filter_var((string) ($input['replace_source'] ?? '0'), FILTER_VALIDATE_BOOLEAN);

    if ($prompt === '') {
        error_response('Prompt is required', 400);
    }
    if (!in_array($format, ['png', 'jpeg', 'webp'], true)) {
        error_response('Unsupported output format', 400);
    }
    if (!in_array($quality, ['low', 'medium', 'high', 'auto'], true)) {
        error_response('Unsupported quality value', 400);
    }
    if (!in_array($size, ['auto', '1024x1024', '1536x1024', '1024x1536'], true)) {
        error_response('Unsupported size value', 400);
    }
    if (!in_array($background, ['auto', 'transparent', 'opaque'], true)) {
        error_response('Unsupported background value', 400);
    }
    if ($format === 'jpeg' && $background === 'transparent') {
        error_response('JPEG does not support transparent background', 400);
    }

    $apiKey = ai_studio_openai_api_key();
    if ($apiKey === '') {
        error_response('OPENAI_API_KEY is not configured', 500);
    }

    $sourceFullPath = null;
    if ($sourcePath !== '') {
        $sourceFullPath = ai_studio_resolve_existing_project_file($sourcePath);
        if ($sourceFullPath === null || !ai_studio_is_public_image_read_allowed($sourceFullPath)) {
            error_response('Source image path is not allowed', 400);
        }

        $sourceExt = strtolower(pathinfo($sourceFullPath, PATHINFO_EXTENSION));
        if (!in_array($sourceExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            error_response('Unsupported source image format', 400);
        }
    }

    $target = ai_studio_make_output_target($format, $sourcePath, $replaceSource);
    if (!empty($target['error'])) {
        error_response((string) $target['error'], 500);
    }

    $model = OPENAI_IMAGE_MODEL;
    $endpoint = 'https://api.openai.com/v1/images/generations';
    $result = null;

    if ($sourceFullPath !== null) {
        $endpoint = 'https://api.openai.com/v1/images/edits';
        $fields = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'output_format' => $format,
            'background' => $background,
            'image' => new CURLFile($sourceFullPath, ai_studio_detect_mime_type($sourceFullPath), basename($sourceFullPath)),
        ];
        $result = ai_studio_openai_images_multipart_request($apiKey, $endpoint, $fields);
    } else {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'output_format' => $format,
            'background' => $background,
        ];
        $result = ai_studio_openai_images_json_request($apiKey, $endpoint, $payload);
    }

    if (!($result['ok'] ?? false)) {
        error_response((string) ($result['error'] ?? 'OpenAI image request failed'), (int) ($result['status'] ?? 502));
    }

    $decoded = is_array($result['decoded'] ?? null) ? $result['decoded'] : [];
    $imageData = (string) ($decoded['data'][0]['b64_json'] ?? '');
    if ($imageData === '') {
        error_response('OpenAI did not return image data', 502);
    }

    $binary = base64_decode($imageData, true);
    if ($binary === false || $binary === '') {
        error_response('Failed to decode generated image', 502);
    }

    $targetFullPath = (string) ($target['full_path'] ?? '');
    $targetRelativePath = ai_studio_normalize_relative_path((string) ($target['relative_path'] ?? ''));
    if ($targetFullPath === '' || $targetRelativePath === '') {
        error_response('Invalid output target', 500);
    }

    ai_studio_backup_existing_file($targetFullPath);
    if (file_put_contents($targetFullPath, $binary, LOCK_EX) === false) {
        error_response('Failed to store generated image', 500);
    }

    $mode = ($sourceFullPath !== null ? 'edit' : 'generate');
    $db->logAdminAction('ai_image_' . $mode, 'Saved ' . $targetRelativePath, $ip);

    json_response([
        'success' => true,
        'message' => ($sourceFullPath !== null ? 'AI оновив зображення' : 'AI згенерував нове зображення'),
        'mode' => $mode,
        'path' => $targetRelativePath,
        'url' => $targetRelativePath . '?v=' . rawurlencode((string) time()),
        'replaced' => !empty($target['replaced']),
        'bytes' => strlen($binary),
        'format' => $format,
        'model' => $model,
        'usage' => $decoded['usage'] ?? null,
    ]);
}

error_response('Unknown action', 400);

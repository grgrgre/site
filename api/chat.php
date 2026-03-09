<?php
/**
 * SvityazHOME AI chat proxy.
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['POST', 'OPTIONS'])) {
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    error_response('Method not allowed', 405);
}

$db = Database::getInstance();
$ip = get_client_ip();
enforce_rate_limit($db, $ip, 'chat_proxy_5min', RATE_LIMIT_CHAT_5MIN, 300);
enforce_rate_limit($db, $ip, 'chat_proxy_hourly', RATE_LIMIT_CHAT_HOURLY, 3600);
enforce_rate_limit($db, 'chat_global_budget', 'chat_proxy_daily_global', RATE_LIMIT_CHAT_DAILY_GLOBAL, 86400);
enforce_rate_limit(
    $db,
    'chat_global_budget',
    'chat_proxy_monthly_global',
    RATE_LIMIT_CHAT_MONTHLY_GLOBAL,
    RATE_LIMIT_CHAT_MONTH_WINDOW_DAYS * 86400
);

$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) {
    error_response('API key is not configured', 500);
}

$aiSettings = $db->getAiSettings();
$model = sanitize_text_field((string) ($aiSettings['model'] ?? 'gpt-4o-mini'), 80);
if (preg_match('/^[a-zA-Z0-9._:-]{2,80}$/', $model) !== 1) {
    $model = 'gpt-4o-mini';
}

$systemPrompt = sanitize_multiline_text((string) ($aiSettings['system_prompt'] ?? ''), 16000);
if ($systemPrompt === '') {
    error_response('AI system prompt is empty', 500);
}

$knowledgeBase = sanitize_multiline_text((string) ($aiSettings['knowledge_base'] ?? ''), 16000);
if ($knowledgeBase !== '') {
    $systemPrompt .= "\n\nБаза знань SvityazHOME:\n" . $knowledgeBase;
}

$input = read_input_payload();
$messages = $input['messages'] ?? null;
if (!is_array($messages) || count($messages) === 0) {
    error_response('Invalid request payload', 400);
}

// Guardrails to prevent abuse and huge payloads.
if (count($messages) > 36) {
    error_response('Too many messages in one request', 400);
}

$safeMessages = [];
foreach ($messages as $message) {
    if (!is_array($message)) {
        continue;
    }

    $role = strtolower(trim((string) ($message['role'] ?? 'user')));
    if (!in_array($role, ['user', 'assistant'], true)) {
        continue;
    }

    $content = sanitize_multiline_text($message['content'] ?? '', 6000);
    if ($content === '') {
        continue;
    }

    $safeMessages[] = [
        'role' => $role,
        'content' => $content,
    ];
}

if (count($safeMessages) === 0) {
    error_response('No valid messages to process', 400);
}

$maxTokens = (int) ($aiSettings['max_tokens'] ?? OPENAI_CHAT_MAX_TOKENS_DEFAULT);
$maxTokens = max(64, min(OPENAI_CHAT_MAX_TOKENS_HARD, $maxTokens));

$temperature = (float) ($aiSettings['temperature'] ?? 0.8);
$temperature = max(0, min(1.2, $temperature));

$presencePenalty = (float) ($aiSettings['presence_penalty'] ?? 0.6);
$presencePenalty = max(-2.0, min(2.0, $presencePenalty));

$frequencyPenalty = (float) ($aiSettings['frequency_penalty'] ?? 0.3);
$frequencyPenalty = max(-2.0, min(2.0, $frequencyPenalty));

$payload = json_encode([
    'model' => $model,
    'messages' => array_merge([[
        'role' => 'system',
        'content' => $systemPrompt,
    ]], $safeMessages),
    'max_tokens' => $maxTokens,
    'temperature' => $temperature,
    'presence_penalty' => $presencePenalty,
    'frequency_penalty' => $frequencyPenalty,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($payload === false) {
    error_response('Failed to serialize request', 500);
}

if (!function_exists('curl_init')) {
    error_response('cURL extension is missing', 500);
}

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_TIMEOUT => 35,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    error_response('Connection error', 502);
}

if (!is_string($response) || $response === '') {
    error_response('Empty upstream response', 502);
}

http_response_code($httpCode > 0 ? $httpCode : 502);
echo $response;

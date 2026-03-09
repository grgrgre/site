<?php
/**
 * SvityazHOME AI settings API (admin only).
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['GET', 'POST', 'OPTIONS'])) {
    exit;
}

initStorage();
$db = Database::getInstance();
$ip = get_client_ip();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = read_input_payload();
$action = strtolower(trim((string) ($input['action'] ?? 'save')));

require_admin_auth($db, $input);

$maxTokensHard = defined('OPENAI_CHAT_MAX_TOKENS_HARD') ? (int) OPENAI_CHAT_MAX_TOKENS_HARD : 1600;
$maxTokensHard = max(128, $maxTokensHard);

function ai_settings_limits(int $maxTokensHard): array
{
    return [
        'temperature_min' => 0.0,
        'temperature_max' => 1.2,
        'max_tokens_min' => 64,
        'max_tokens_max' => $maxTokensHard,
        'penalty_min' => -2.0,
        'penalty_max' => 2.0,
    ];
}

function ai_build_change_summary(array $before, array $after): string
{
    $changes = [];

    $fields = [
        'model' => 'model',
        'temperature' => 'temperature',
        'max_tokens' => 'max_tokens',
        'presence_penalty' => 'presence_penalty',
        'frequency_penalty' => 'frequency_penalty',
    ];
    foreach ($fields as $key => $label) {
        $old = (string) ($before[$key] ?? '');
        $new = (string) ($after[$key] ?? '');
        if ($old !== $new) {
            $changes[] = "{$label}: {$old} -> {$new}";
        }
    }

    $promptBefore = trim((string) ($before['system_prompt'] ?? ''));
    $promptAfter = trim((string) ($after['system_prompt'] ?? ''));
    if ($promptBefore !== $promptAfter) {
        $changes[] = 'system_prompt оновлено';
    }

    $kbBefore = trim((string) ($before['knowledge_base'] ?? ''));
    $kbAfter = trim((string) ($after['knowledge_base'] ?? ''));
    if ($kbBefore !== $kbAfter) {
        $changes[] = 'knowledge_base оновлено';
    }

    if (count($changes) === 0) {
        return 'Без змін параметрів (перезапис)';
    }

    return implode('; ', array_slice($changes, 0, 7));
}

function ai_prepare_messages($rawMessages): array
{
    if (!is_array($rawMessages)) {
        return [];
    }

    $safeMessages = [];
    foreach ($rawMessages as $message) {
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

    if (count($safeMessages) > 50) {
        $safeMessages = array_slice($safeMessages, -50);
    }

    return $safeMessages;
}

function ai_openai_chat_request(string $apiKey, array $payload): array
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
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
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

if ($method === 'GET') {
    enforce_rate_limit($db, $ip, 'ai_settings_read', 240, 3600);
    $versionsLimit = max(1, min(100, (int) ($_GET['versions_limit'] ?? 40)));

    json_response([
        'success' => true,
        'settings' => $db->getAiSettings(),
        'defaults' => $db->getAiSettingsDefaults(),
        'versions' => $db->getAiPromptVersions($versionsLimit),
        'limits' => ai_settings_limits($maxTokensHard),
    ]);
}

if ($method !== 'POST') {
    error_response('Method not allowed', 405);
}

require_csrf_token($input);

if ($action === 'test_chat') {
    enforce_rate_limit($db, $ip, 'ai_settings_test_chat', 120, 3600);
    enforce_rate_limit($db, $ip, 'ai_settings_test_chat_burst', 20, 300);

    $apiKey = trim((string) (getenv('OPENAI_API_KEY') ?: ''));
    if ($apiKey === '') {
        error_response('API key is not configured', 500);
    }

    $safeMessages = ai_prepare_messages($input['messages'] ?? null);
    if (count($safeMessages) === 0) {
        error_response('No valid test messages provided', 400);
    }

    $versionId = max(0, (int) ($input['version_id'] ?? 0));
    $settings = $versionId > 0 ? $db->getAiPromptVersionById($versionId) : $db->getAiSettings();
    if (!is_array($settings)) {
        error_response('Prompt version not found', 404);
    }

    $systemPrompt = sanitize_multiline_text((string) ($settings['system_prompt'] ?? ''), 16000);
    if ($systemPrompt === '') {
        error_response('Selected prompt is empty', 400);
    }

    $knowledgeBase = sanitize_multiline_text((string) ($settings['knowledge_base'] ?? ''), 16000);
    if ($knowledgeBase !== '') {
        $systemPrompt .= "\n\nБаза знань SvityazHOME:\n" . $knowledgeBase;
    }

    $adaptation = sanitize_multiline_text((string) ($input['adaptation'] ?? ''), 2500);
    if ($adaptation !== '') {
        $systemPrompt .= "\n\nАдаптивні нотатки по діалогу:\n" . $adaptation;
    }

    $model = sanitize_text_field((string) ($settings['model'] ?? 'gpt-4o-mini'), 80);
    if ($model === '' || preg_match('/^[a-zA-Z0-9._:-]{2,80}$/', $model) !== 1) {
        $model = 'gpt-4o-mini';
    }

    $maxTokens = (int) ($settings['max_tokens'] ?? 250);
    $maxTokens = max(64, min($maxTokensHard, $maxTokens));

    $temperature = (float) ($settings['temperature'] ?? 0.8);
    $temperature = max(0.0, min(1.2, $temperature));

    $presencePenalty = (float) ($settings['presence_penalty'] ?? 0.6);
    $presencePenalty = max(-2.0, min(2.0, $presencePenalty));

    $frequencyPenalty = (float) ($settings['frequency_penalty'] ?? 0.3);
    $frequencyPenalty = max(-2.0, min(2.0, $frequencyPenalty));

    $openAiResult = ai_openai_chat_request($apiKey, [
        'model' => $model,
        'messages' => array_merge([[
            'role' => 'system',
            'content' => $systemPrompt,
        ]], $safeMessages),
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
        'presence_penalty' => $presencePenalty,
        'frequency_penalty' => $frequencyPenalty,
    ]);

    if (!($openAiResult['ok'] ?? false)) {
        error_response((string) ($openAiResult['error'] ?? 'OpenAI request failed'), (int) ($openAiResult['status'] ?? 502));
    }

    $decoded = is_array($openAiResult['decoded'] ?? null) ? $openAiResult['decoded'] : [];
    $reply = sanitize_multiline_text((string) ($decoded['choices'][0]['message']['content'] ?? ''), 12000);
    if ($reply === '') {
        $reply = 'Не вдалося отримати змістовну відповідь для тесту.';
    }

    json_response([
        'success' => true,
        'reply' => $reply,
        'usage' => $decoded['usage'] ?? null,
        'model' => $model,
        'version_id_used' => ($versionId > 0 ? $versionId : null),
    ]);
}

enforce_rate_limit($db, $ip, 'ai_settings_write', 40, 3600);

$current = $db->getAiSettings();
$payload = [
    'model' => sanitize_text_field($input['model'] ?? '', 80),
    'system_prompt' => sanitize_multiline_text($input['system_prompt'] ?? '', 16000),
    'knowledge_base' => sanitize_multiline_text($input['knowledge_base'] ?? '', 16000),
    'temperature' => (float) ($input['temperature'] ?? 0.8),
    'max_tokens' => (int) ($input['max_tokens'] ?? 250),
    'presence_penalty' => (float) ($input['presence_penalty'] ?? 0.6),
    'frequency_penalty' => (float) ($input['frequency_penalty'] ?? 0.3),
];

if (trim((string) $payload['system_prompt']) === '') {
    error_response('System prompt is required', 400);
}

$changeSummary = ai_build_change_summary(is_array($current) ? $current : [], $payload);

if (!$db->saveAiSettings($payload, $changeSummary)) {
    error_response('Failed to save AI settings', 500);
}

$settings = $db->getAiSettings();
$db->logAdminAction(
    'ai_settings_update',
    sprintf(
        'summary=%s; model=%s; temp=%.2f; max_tokens=%d; presence=%.2f; frequency=%.2f',
        $changeSummary,
        (string) ($settings['model'] ?? ''),
        (float) ($settings['temperature'] ?? 0),
        (int) ($settings['max_tokens'] ?? 0),
        (float) ($settings['presence_penalty'] ?? 0),
        (float) ($settings['frequency_penalty'] ?? 0)
    ),
    $ip
);

json_response([
    'success' => true,
    'message' => 'AI settings saved',
    'settings' => $settings,
    'versions' => $db->getAiPromptVersions(40),
    'change_summary' => $changeSummary,
]);

<?php
/**
 * Telegram bot admin actions API.
 * Allows authorized admin panel to reset bot state and refresh webhook.
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['POST', 'OPTIONS'])) {
    exit;
}

initStorage();
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ip = get_client_ip();

if ($method !== 'POST') {
    error_response('Method not allowed', 405);
}

$input = read_input_payload();
require_admin_auth($db, $input);
require_csrf_token($input);

$action = strtolower(trim((string) ($input['action'] ?? '')));
if ($action !== 'reset_bot') {
    error_response('Invalid action', 400);
}

$stateFiles = [
    DATA_DIR . 'telegram-reply-context.json',
    DATA_DIR . 'telegram-change-room-context.json',
    DATA_DIR . 'telegram-booking-pick-context.json',
    DATA_DIR . 'telegram-admin-access.json',
];

$cleared = [];
$failed = [];
foreach ($stateFiles as $filePath) {
    if (!is_file($filePath)) {
        continue;
    }
    if (@unlink($filePath)) {
        $cleared[] = basename($filePath);
    } else {
        $failed[] = basename($filePath);
    }
}

$webhookRefreshed = false;
$commandsUpdated = false;
$menuButtonUpdated = false;
$descriptionsUpdated = false;
$telegramAvailable = trim((string) TELEGRAM_BOT_TOKEN) !== '' && trim((string) TELEGRAM_WEBHOOK_URL) !== '';

if ($telegramAvailable) {
    // Drop pending updates to remove stale callback/message queue during reset.
    telegram_api_request('deleteWebhook', [
        'drop_pending_updates' => 'true',
    ]);

    $setWebhookParams = [
        'url' => TELEGRAM_WEBHOOK_URL,
        'allowed_updates' => json_encode(['message', 'callback_query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    if (trim((string) TELEGRAM_WEBHOOK_SECRET) !== '') {
        $setWebhookParams['secret_token'] = TELEGRAM_WEBHOOK_SECRET;
    }
    $setWebhookResult = telegram_api_request('setWebhook', $setWebhookParams);
    $webhookRefreshed = is_array($setWebhookResult) && (($setWebhookResult['ok'] ?? false) === true);

    $commands = [
        ['command' => 'menu', 'description' => 'Головне меню з кнопками'],
        ['command' => 'app', 'description' => 'Відкрити Telegram App заявок'],
        ['command' => 'bookings', 'description' => 'Останні заявки'],
        ['command' => 'latest', 'description' => 'Відкрити останню заявку'],
        ['command' => 'pending', 'description' => 'Відгуки на модерації'],
        ['command' => 'reply', 'description' => 'Відповісти гостю по заявці'],
        ['command' => 'change_room', 'description' => 'Змінити номер у заявці'],
        ['command' => 'today', 'description' => 'Підсумок на сьогодні'],
        ['command' => 'tomorrow', 'description' => 'Підсумок на завтра'],
        ['command' => 'free_rooms', 'description' => 'Вільні номери на дати'],
        ['command' => 'find', 'description' => 'Пошук заявки'],
        ['command' => 'status', 'description' => 'Стан бота і сайту'],
        ['command' => 'help', 'description' => 'Повна довідка'],
        ['command' => 'login', 'description' => 'Вхід з нового пристрою'],
        ['command' => 'logout', 'description' => 'Вийти з цього пристрою'],
    ];
    $setCommandsResult = telegram_api_request('setMyCommands', [
        'commands' => json_encode($commands, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $commandsUpdated = is_array($setCommandsResult) && (($setCommandsResult['ok'] ?? false) === true);

    $setMenuButtonResult = telegram_api_request('setChatMenuButton', [
        'menu_button' => json_encode([
            'type' => 'web_app',
            'text' => 'Заявки',
            'web_app' => ['url' => TELEGRAM_MINIAPP_URL],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $menuButtonUpdated = is_array($setMenuButtonResult) && (($setMenuButtonResult['ok'] ?? false) === true);

    $setShortDescriptionResult = telegram_api_request('setMyShortDescription', [
        'short_description' => 'Заявки SvityazHOME в Telegram App',
    ]);
    $setDescriptionResult = telegram_api_request('setMyDescription', [
        'description' => 'SvityazHOME: Telegram App для заявок, деталей бронювання, пошуку і швидких дій.',
    ]);
    $descriptionsUpdated =
        is_array($setShortDescriptionResult) && (($setShortDescriptionResult['ok'] ?? false) === true) &&
        is_array($setDescriptionResult) && (($setDescriptionResult['ok'] ?? false) === true);
}

$details = implode('; ', [
    'cleared=' . (string) count($cleared),
    'failed=' . (string) count($failed),
    'webhook=' . ($webhookRefreshed ? '1' : '0'),
    'commands=' . ($commandsUpdated ? '1' : '0'),
    'menu_button=' . ($menuButtonUpdated ? '1' : '0'),
    'descriptions=' . ($descriptionsUpdated ? '1' : '0'),
]);
$db->logAdminAction('telegram_bot_reset', $details, $ip);

json_response([
    'success' => true,
    'message' => 'Telegram bot reset completed',
    'cleared_files_count' => count($cleared),
    'cleared_files' => $cleared,
    'failed_files' => $failed,
    'webhook_refreshed' => $webhookRefreshed,
    'commands_updated' => $commandsUpdated,
    'menu_button_updated' => $menuButtonUpdated,
    'descriptions_updated' => $descriptionsUpdated,
    'telegram_available' => $telegramAvailable,
]);

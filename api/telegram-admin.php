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
        ['command' => 'menu', 'description' => 'Показати кнопки меню'],
        ['command' => 'help', 'description' => 'Список команд'],
        ['command' => 'status', 'description' => 'Статус сайту'],
        ['command' => 'today', 'description' => 'Звіт за сьогодні'],
        ['command' => 'pending', 'description' => 'Pending відгуки'],
        ['command' => 'approve', 'description' => 'Схвалити відгук: /approve ID'],
        ['command' => 'reject', 'description' => 'Відхилити відгук: /reject ID'],
        ['command' => 'add_review', 'description' => 'Додати відгук'],
        ['command' => 'bookings', 'description' => 'Нові заявки'],
        ['command' => 'latest', 'description' => 'Остання заявка'],
        ['command' => 'booking', 'description' => 'Деталі заявки'],
        ['command' => 'find', 'description' => 'Пошук заявки'],
        ['command' => 'reply', 'description' => 'Відповідь на заявку'],
        ['command' => 'change_room', 'description' => 'Змінити номер у заявці'],
        ['command' => 'arrivals', 'description' => 'Заїзди: today|tomorrow'],
        ['command' => 'departures', 'description' => 'Виїзди: today'],
        ['command' => 'actions', 'description' => 'Останні дії адмінки'],
        ['command' => 'login', 'description' => 'Вхід з нового пристрою'],
        ['command' => 'logout', 'description' => 'Вийти з поточного пристрою'],
    ];
    $setCommandsResult = telegram_api_request('setMyCommands', [
        'commands' => json_encode($commands, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $commandsUpdated = is_array($setCommandsResult) && (($setCommandsResult['ok'] ?? false) === true);
}

$details = implode('; ', [
    'cleared=' . (string) count($cleared),
    'failed=' . (string) count($failed),
    'webhook=' . ($webhookRefreshed ? '1' : '0'),
    'commands=' . ($commandsUpdated ? '1' : '0'),
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
    'telegram_available' => $telegramAvailable,
]);

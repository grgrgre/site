<?php
/**
 * SvityazHOME Telegram moderation bot webhook.
 *
 * Features:
 * - list pending reviews
 * - approve / reject reviews
 * - add approved review
 * - list bookings and inspect booking details
 * - reply to booking without manual ID input (via buttons)
 * - change booking room with quick number buttons (1..20)
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/booking-workflow.php';

if ((!defined('SVH_TG_LIBRARY_ONLY') || SVH_TG_LIBRARY_ONLY !== true) && send_api_headers(['GET', 'POST', 'OPTIONS'])) {
    exit;
}

initStorage();
$db = Database::getInstance();
$pdo = $db->getPdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$REVIEWS_FILE = DATA_DIR . 'reviews.json';
$REVIEW_TOPICS = ['rooms', 'territory', 'service', 'location', 'general'];
const TG_MAX_MESSAGE_LENGTH = 3900;
const TG_DEFAULT_LIST_LIMIT = 5;
const TG_MAX_LIST_LIMIT = 15;
const TG_REPLY_CONTEXT_FILE = DATA_DIR . 'telegram-reply-context.json';
const TG_REPLY_CONTEXT_TTL = 12 * 3600;
const TG_CHANGE_ROOM_CONTEXT_FILE = DATA_DIR . 'telegram-change-room-context.json';
const TG_CHANGE_ROOM_CONTEXT_TTL = 12 * 3600;
const TG_BOOKING_PICK_CONTEXT_FILE = DATA_DIR . 'telegram-booking-pick-context.json';
const TG_BOOKING_PICK_CONTEXT_TTL = 6 * 3600;
const TG_DEVICE_ACCESS_FILE = DATA_DIR . 'telegram-admin-access.json';
const TG_DEVICE_ACCESS_TTL = 30 * 24 * 3600;
const TG_MINIAPP_ACCESS_TOKEN_TTL = TG_DEVICE_ACCESS_TTL;

$GLOBALS['svh_tg_webhook_update_type'] = null;
$GLOBALS['svh_tg_direct_response'] = null;
$GLOBALS['svh_tg_debug_request_id'] = null;

function tg_debug_log(string $message, array $context = []): void
{
    $line = '[' . gmdate('c') . '] ' . $message;
    if (!empty($context)) {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && $encoded !== '') {
            $line .= ' ' . $encoded;
        }
    }

    $logFile = rtrim(STORAGE_LOGS_DIR, '/\\') . DIRECTORY_SEPARATOR . 'telegram-webhook-debug.log';
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function tg_debug_request_id(): string
{
    $current = $GLOBALS['svh_tg_debug_request_id'] ?? null;
    if (is_string($current) && $current !== '') {
        return $current;
    }

    try {
        $current = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $current = (string) mt_rand(100000, 999999);
    }

    $GLOBALS['svh_tg_debug_request_id'] = $current;
    return $current;
}

function tg_set_webhook_update_type(?string $type): void
{
    $GLOBALS['svh_tg_webhook_update_type'] = $type;
}

function tg_webhook_update_type(): ?string
{
    $type = $GLOBALS['svh_tg_webhook_update_type'] ?? null;
    return is_string($type) && $type !== '' ? $type : null;
}

function tg_store_direct_response(array $payload): bool
{
    if (($GLOBALS['svh_tg_direct_response'] ?? null) !== null) {
        return false;
    }

    $GLOBALS['svh_tg_direct_response'] = $payload;
    return true;
}

function tg_take_direct_response(): ?array
{
    $payload = $GLOBALS['svh_tg_direct_response'] ?? null;
    $GLOBALS['svh_tg_direct_response'] = null;
    return is_array($payload) ? $payload : null;
}

function tg_maybe_queue_direct_message(string $chatId, string $text, ?array $replyMarkup = null, ?int $replyToMessageId = null): bool
{
    if (tg_webhook_update_type() !== 'message') {
        return false;
    }

    $payload = [
        'method' => 'sendMessage',
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ];

    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }
    if ($replyToMessageId !== null) {
        $payload['reply_to_message_id'] = $replyToMessageId;
        $payload['allow_sending_without_reply'] = true;
    }

    return tg_store_direct_response($payload);
}

function tg_finish_webhook_response(array $fallbackPayload, int $status = 200): void
{
    $directPayload = tg_take_direct_response();
    if (is_array($directPayload)) {
        json_response($directPayload, $status);
    }

    json_response($fallbackPayload, $status);
}

function tg_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0) {
            continue;
        }
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$name] = (string) $value;
    }

    return $headers;
}

function tg_admin_chat_ids(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $raw = trim((string) TELEGRAM_ADMIN_CHAT_IDS);
    if ($raw === '' && trim((string) TELEGRAM_CHAT_ID) !== '') {
        $raw = trim((string) TELEGRAM_CHAT_ID);
    }

    if ($raw === '') {
        $cache = [];
        return $cache;
    }

    $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $ids = [];
    foreach ($parts as $part) {
        $value = trim((string) $part);
        if ($value === '') {
            continue;
        }
        if (preg_match('/^-?\d{3,20}$/', $value) !== 1) {
            continue;
        }
        $ids[] = $value;
    }

    $cache = array_values(array_unique($ids));
    return $cache;
}

function tg_device_access_read_all(): array
{
    if (!is_file(TG_DEVICE_ACCESS_FILE)) {
        return [];
    }

    $raw = file_get_contents(TG_DEVICE_ACCESS_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $now = time();
    $result = [];
    foreach ($decoded as $chatId => $row) {
        $id = trim((string) $chatId);
        if ($id === '' || preg_match('/^-?\d{3,20}$/', $id) !== 1 || !is_array($row)) {
            continue;
        }
        $expiresAt = (int) ($row['expires_at'] ?? 0);
        if ($expiresAt <= $now) {
            continue;
        }
        $result[$id] = ['expires_at' => $expiresAt];
    }

    return $result;
}

function tg_device_access_save_all(array $data): void
{
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }
    file_put_contents(TG_DEVICE_ACCESS_FILE, $payload, LOCK_EX);
}

function tg_device_access_grant(string $chatId): void
{
    $chatId = trim($chatId);
    if ($chatId === '' || preg_match('/^-?\d{3,20}$/', $chatId) !== 1) {
        return;
    }

    $data = tg_device_access_read_all();
    $data[$chatId] = ['expires_at' => time() + TG_DEVICE_ACCESS_TTL];
    tg_device_access_save_all($data);
}

function tg_device_access_revoke(string $chatId): void
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return;
    }
    $data = tg_device_access_read_all();
    if (!array_key_exists($chatId, $data)) {
        return;
    }
    unset($data[$chatId]);
    tg_device_access_save_all($data);
}

function tg_device_access_has(string $chatId): bool
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return false;
    }
    $data = tg_device_access_read_all();
    return array_key_exists($chatId, $data);
}

function tg_admin_access_password_enabled(): bool
{
    return trim((string) TELEGRAM_ADMIN_ACCESS_PASSWORD) !== '';
}

function tg_admin_access_password_matches(string $provided): bool
{
    $password = trim((string) TELEGRAM_ADMIN_ACCESS_PASSWORD);
    $candidate = trim($provided);
    if ($password === '' || $candidate === '') {
        return false;
    }
    return hash_equals($password, $candidate);
}

function tg_is_admin_chat(string $chatId): bool
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return false;
    }
    if (in_array($chatId, tg_admin_chat_ids(), true)) {
        return true;
    }
    return tg_device_access_has($chatId);
}

function tg_webhook_secret_is_valid(array $headers): bool
{
    if (TELEGRAM_WEBHOOK_SECRET === '') {
        return true;
    }

    $provided = get_request_header_value($headers, 'X-Telegram-Bot-Api-Secret-Token');
    return $provided !== '' && hash_equals(TELEGRAM_WEBHOOK_SECRET, $provided);
}

function tg_read_update_payload(): ?array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function tg_safe_summary(string $text, int $max = 150): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($value) <= $max) {
        return $value;
    }
    return rtrim(mb_substr($value, 0, max(10, $max - 1))) . '…';
}

function tg_message_chunks(string $text, int $maxLength = TG_MAX_MESSAGE_LENGTH): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $chunks = [];
    $remaining = $text;
    while (mb_strlen($remaining) > $maxLength) {
        $slice = mb_substr($remaining, 0, $maxLength);
        $breakPos = mb_strrpos($slice, "\n");
        if ($breakPos === false || $breakPos < 100) {
            $breakPos = $maxLength;
        }
        $chunks[] = trim(mb_substr($remaining, 0, $breakPos));
        $remaining = trim(mb_substr($remaining, $breakPos));
    }

    if ($remaining !== '') {
        $chunks[] = $remaining;
    }

    return $chunks;
}

function tg_telegram_request(string $apiMethod, array $params = []): ?array
{
    if (TELEGRAM_BOT_TOKEN === '') {
        return null;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $apiMethod;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            error_log('Telegram API request failed: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($params),
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ];
    $context = stream_context_create($options);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : null;
}

function tg_send_message(string $chatId, string $text, ?array $replyMarkup = null, ?int $replyToMessageId = null): void
{
    $chunks = tg_message_chunks($text);
    if (empty($chunks)) {
        return;
    }

    foreach ($chunks as $index => $chunk) {
        $payload = [
            'chat_id' => $chatId,
            'text' => $chunk,
            'disable_web_page_preview' => 'true',
        ];

        if ($replyMarkup !== null && $index === 0) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($replyToMessageId !== null && $index === 0) {
            $payload['reply_to_message_id'] = $replyToMessageId;
            $payload['allow_sending_without_reply'] = 'true';
        }

        if ($index === 0 && tg_maybe_queue_direct_message($chatId, $chunk, $replyMarkup, $replyToMessageId)) {
            continue;
        }

        tg_telegram_request('sendMessage', $payload);
    }
}

function tg_answer_callback(string $callbackId, string $text = '', bool $alert = false): void
{
    if ($callbackId === '') {
        return;
    }
    tg_telegram_request('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => mb_substr($text, 0, 180),
        'show_alert' => $alert ? 'true' : 'false',
    ]);
}

function tg_edit_message_text(string $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
{
    if ($chatId === '' || $messageId <= 0) {
        return false;
    }

    $chunks = tg_message_chunks($text);
    if (count($chunks) !== 1) {
        return false;
    }

    $payload = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $chunks[0],
        'disable_web_page_preview' => 'true',
    ];

    if ($replyMarkup !== null) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $response = tg_telegram_request('editMessageText', $payload);
    if (!is_array($response)) {
        return false;
    }

    if (($response['ok'] ?? false) === true) {
        return true;
    }

    $description = strtolower(trim((string) ($response['description'] ?? '')));
    return $description !== '' && strpos($description, 'message is not modified') !== false;
}

function tg_remove_callback_keyboard(string $chatId, int $messageId): void
{
    if ($chatId === '' || $messageId <= 0) {
        return;
    }
    tg_telegram_request('editMessageReplyMarkup', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function tg_log_action(Database $db, string $action, string $details, string $chatId): void
{
    try {
        $db->logAdminAction($action, $details, 'telegram:' . $chatId);
    } catch (Throwable $e) {
        error_log('Telegram action log failed: ' . $e->getMessage());
    }
}

function tg_reply_context_read_all(): array
{
    if (!is_file(TG_REPLY_CONTEXT_FILE)) {
        return [];
    }

    $raw = file_get_contents(TG_REPLY_CONTEXT_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $now = time();
    $data = [];
    foreach ($decoded as $chatId => $item) {
        $key = trim((string) $chatId);
        if ($key === '' || !is_array($item)) {
            continue;
        }

        $bookingId = strtoupper(trim((string) ($item['booking_id'] ?? '')));
        $expiresAt = (int) ($item['expires_at'] ?? 0);
        if ($expiresAt <= $now || preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
            continue;
        }

        $data[$key] = [
            'booking_id' => $bookingId,
            'expires_at' => $expiresAt,
        ];
    }

    return $data;
}

function tg_reply_context_save_all(array $data): void
{
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }
    file_put_contents(TG_REPLY_CONTEXT_FILE, $payload, LOCK_EX);
}

function tg_reply_context_set(string $chatId, string $bookingId): void
{
    $chatId = trim($chatId);
    $bookingId = strtoupper(trim($bookingId));
    if ($chatId === '' || preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
        return;
    }

    $data = tg_reply_context_read_all();
    $data[$chatId] = [
        'booking_id' => $bookingId,
        'expires_at' => time() + TG_REPLY_CONTEXT_TTL,
    ];
    tg_reply_context_save_all($data);
    tg_change_room_context_clear($chatId);
}

function tg_reply_context_get(string $chatId): ?string
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return null;
    }

    $data = tg_reply_context_read_all();
    $item = $data[$chatId] ?? null;
    if (!is_array($item)) {
        return null;
    }

    $bookingId = strtoupper(trim((string) ($item['booking_id'] ?? '')));
    if (preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
        return null;
    }

    return $bookingId;
}

function tg_reply_context_clear(string $chatId): void
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return;
    }

    $data = tg_reply_context_read_all();
    if (!array_key_exists($chatId, $data)) {
        return;
    }

    unset($data[$chatId]);
    tg_reply_context_save_all($data);
}

function tg_change_room_context_read_all(): array
{
    if (!is_file(TG_CHANGE_ROOM_CONTEXT_FILE)) {
        return [];
    }

    $raw = file_get_contents(TG_CHANGE_ROOM_CONTEXT_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $now = time();
    $data = [];
    foreach ($decoded as $chatId => $item) {
        $key = trim((string) $chatId);
        if ($key === '' || !is_array($item)) {
            continue;
        }

        $bookingId = strtoupper(trim((string) ($item['booking_id'] ?? '')));
        $expiresAt = (int) ($item['expires_at'] ?? 0);
        if ($expiresAt <= $now || preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
            continue;
        }

        $data[$key] = [
            'booking_id' => $bookingId,
            'expires_at' => $expiresAt,
        ];
    }

    return $data;
}

function tg_change_room_context_save_all(array $data): void
{
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }
    file_put_contents(TG_CHANGE_ROOM_CONTEXT_FILE, $payload, LOCK_EX);
}

function tg_change_room_context_set(string $chatId, string $bookingId): void
{
    $chatId = trim($chatId);
    $bookingId = strtoupper(trim($bookingId));
    if ($chatId === '' || preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
        return;
    }

    $data = tg_change_room_context_read_all();
    $data[$chatId] = [
        'booking_id' => $bookingId,
        'expires_at' => time() + TG_CHANGE_ROOM_CONTEXT_TTL,
    ];
    tg_change_room_context_save_all($data);
    tg_reply_context_clear($chatId);
}

function tg_change_room_context_get(string $chatId): ?string
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return null;
    }

    $data = tg_change_room_context_read_all();
    $item = $data[$chatId] ?? null;
    if (!is_array($item)) {
        return null;
    }

    $bookingId = strtoupper(trim((string) ($item['booking_id'] ?? '')));
    if (preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
        return null;
    }

    return $bookingId;
}

function tg_change_room_context_clear(string $chatId): void
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return;
    }

    $data = tg_change_room_context_read_all();
    if (!array_key_exists($chatId, $data)) {
        return;
    }

    unset($data[$chatId]);
    tg_change_room_context_save_all($data);
}

function tg_booking_pick_context_read_all(): array
{
    if (!is_file(TG_BOOKING_PICK_CONTEXT_FILE)) {
        return [];
    }

    $raw = file_get_contents(TG_BOOKING_PICK_CONTEXT_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $now = time();
    $data = [];
    $allowedModes = ['booking', 'replypick', 'changeroompick'];

    foreach ($decoded as $chatId => $item) {
        $key = trim((string) $chatId);
        if ($key === '' || !is_array($item)) {
            continue;
        }

        $expiresAt = (int) ($item['expires_at'] ?? 0);
        if ($expiresAt <= $now) {
            continue;
        }

        $mode = strtolower(trim((string) ($item['mode'] ?? 'booking')));
        if (!in_array($mode, $allowedModes, true)) {
            $mode = 'booking';
        }

        $rowsRaw = is_array($item['rows'] ?? null) ? $item['rows'] : [];
        $rows = [];
        foreach ($rowsRaw as $bookingIdRaw) {
            $bookingId = strtoupper(trim((string) $bookingIdRaw));
            if (preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
                continue;
            }
            $rows[] = $bookingId;
            if (count($rows) >= TG_MAX_LIST_LIMIT) {
                break;
            }
        }

        if (empty($rows)) {
            continue;
        }

        $data[$key] = [
            'rows' => $rows,
            'mode' => $mode,
            'expires_at' => $expiresAt,
        ];
    }

    return $data;
}

function tg_booking_pick_context_save_all(array $data): void
{
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }
    file_put_contents(TG_BOOKING_PICK_CONTEXT_FILE, $payload, LOCK_EX);
}

function tg_booking_pick_context_set(string $chatId, array $rows, string $mode = 'booking'): void
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return;
    }

    $allowedModes = ['booking', 'replypick', 'changeroompick'];
    $mode = strtolower(trim($mode));
    if (!in_array($mode, $allowedModes, true)) {
        $mode = 'booking';
    }

    $bookingIds = [];
    foreach ($rows as $row) {
        $bookingId = strtoupper(trim((string) ($row['booking_id'] ?? '')));
        if (preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
            continue;
        }
        $bookingIds[] = $bookingId;
        if (count($bookingIds) >= TG_MAX_LIST_LIMIT) {
            break;
        }
    }

    $data = tg_booking_pick_context_read_all();
    if (empty($bookingIds)) {
        unset($data[$chatId]);
        tg_booking_pick_context_save_all($data);
        return;
    }

    $data[$chatId] = [
        'rows' => $bookingIds,
        'mode' => $mode,
        'expires_at' => time() + TG_BOOKING_PICK_CONTEXT_TTL,
    ];
    tg_booking_pick_context_save_all($data);
}

function tg_booking_pick_context_get(string $chatId): ?array
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return null;
    }

    $data = tg_booking_pick_context_read_all();
    $item = $data[$chatId] ?? null;
    if (!is_array($item)) {
        return null;
    }
    if (!is_array($item['rows'] ?? null) || empty($item['rows'])) {
        return null;
    }

    $mode = strtolower(trim((string) ($item['mode'] ?? 'booking')));
    if (!in_array($mode, ['booking', 'replypick', 'changeroompick'], true)) {
        $mode = 'booking';
    }

    return [
        'rows' => $item['rows'],
        'mode' => $mode,
    ];
}

function tg_booking_pick_context_select(string $chatId, int $index): ?array
{
    if ($index <= 0 || $index > TG_MAX_LIST_LIMIT) {
        return null;
    }

    $context = tg_booking_pick_context_get($chatId);
    if (!$context) {
        return null;
    }

    $rows = $context['rows'];
    $position = $index - 1;
    if (!isset($rows[$position])) {
        return null;
    }

    $bookingId = strtoupper(trim((string) $rows[$position]));
    if (preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
        return null;
    }

    return [
        'booking_id' => $bookingId,
        'mode' => (string) $context['mode'],
    ];
}

function tg_booking_pick_context_clear(string $chatId): void
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return;
    }

    $data = tg_booking_pick_context_read_all();
    if (!array_key_exists($chatId, $data)) {
        return;
    }

    unset($data[$chatId]);
    tg_booking_pick_context_save_all($data);
}

function tg_topic_labels(): array
{
    return [
        'rooms' => 'Номери',
        'territory' => 'Територія',
        'service' => 'Обслуговування',
        'location' => 'Локація',
        'general' => 'Загальні враження',
    ];
}

function tg_default_reviews_payload(): array
{
    return [
        'topics' => tg_topic_labels(),
        'approved' => [],
        'pending' => [],
        'questions' => [],
        'nextId' => 100,
    ];
}

function tg_ensure_reviews_shape(array $data): array
{
    $data = array_merge(tg_default_reviews_payload(), $data);

    foreach (['approved', 'pending', 'questions'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    if (!isset($data['nextId']) || !is_numeric($data['nextId'])) {
        $maxId = 0;
        foreach (array_merge($data['approved'], $data['pending'], $data['questions']) as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }
        }
        $data['nextId'] = $maxId + 1;
    } else {
        $data['nextId'] = (int) $data['nextId'];
    }

    return $data;
}

function tg_read_reviews_data(string $filePath): array
{
    if (!is_file($filePath)) {
        return tg_default_reviews_payload();
    }

    $raw = file_get_contents($filePath);
    if ($raw === false || $raw === '') {
        return tg_default_reviews_payload();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return tg_default_reviews_payload();
    }

    return tg_ensure_reviews_shape($decoded);
}

function tg_save_reviews_data(string $filePath, array $data): bool
{
    maybe_auto_storage_backup('telegram-reviews-write', 6 * 3600);

    $payload = json_encode(tg_ensure_reviews_shape($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return false;
    }

    return file_put_contents($filePath, $payload, LOCK_EX) !== false;
}

function tg_sort_by_created_desc(array $items): array
{
    usort($items, static function ($a, $b) {
        $dateA = strtotime((string) ($a['created_at'] ?? $a['moderated_at'] ?? $a['date'] ?? '1970-01-01')) ?: 0;
        $dateB = strtotime((string) ($b['created_at'] ?? $b['moderated_at'] ?? $b['date'] ?? '1970-01-01')) ?: 0;
        return $dateB <=> $dateA;
    });
    return $items;
}

function tg_extract_limit(string $args): int
{
    $value = (int) trim($args);
    if ($value <= 0) {
        return TG_DEFAULT_LIST_LIMIT;
    }
    return max(1, min(TG_MAX_LIST_LIMIT, $value));
}

function tg_find_pending_index(array $pending, int $id): ?int
{
    foreach ($pending as $index => $item) {
        if ((int) ($item['id'] ?? 0) === $id) {
            return $index;
        }
    }
    return null;
}

function tg_approve_review(string $reviewsFile, int $id): array
{
    $data = tg_read_reviews_data($reviewsFile);
    $index = tg_find_pending_index($data['pending'], $id);
    if ($index === null) {
        return ['ok' => false, 'message' => 'Відгук не знайдено в pending.'];
    }

    $item = $data['pending'][$index];
    $item['moderated_at'] = date('c');
    array_splice($data['pending'], $index, 1);
    $data['approved'][] = $item;

    if (!tg_save_reviews_data($reviewsFile, $data)) {
        return ['ok' => false, 'message' => 'Не вдалося зберегти зміни.'];
    }

    return [
        'ok' => true,
        'message' => 'Відгук #' . $id . ' схвалено.',
        'review' => $item,
    ];
}

function tg_reject_review(string $reviewsFile, int $id): array
{
    $data = tg_read_reviews_data($reviewsFile);

    $before = count($data['approved']) + count($data['pending']) + count($data['questions']);
    $data['pending'] = array_values(array_filter($data['pending'], static function ($item) use ($id) {
        return (int) ($item['id'] ?? 0) !== $id;
    }));
    $data['approved'] = array_values(array_filter($data['approved'], static function ($item) use ($id) {
        return (int) ($item['id'] ?? 0) !== $id;
    }));
    $data['questions'] = array_values(array_filter($data['questions'], static function ($item) use ($id) {
        return (int) ($item['id'] ?? 0) !== $id;
    }));
    $after = count($data['approved']) + count($data['pending']) + count($data['questions']);

    if ($after === $before) {
        return ['ok' => false, 'message' => 'Запис не знайдено.'];
    }

    if (!tg_save_reviews_data($reviewsFile, $data)) {
        return ['ok' => false, 'message' => 'Не вдалося зберегти зміни.'];
    }

    return [
        'ok' => true,
        'message' => 'Запис #' . $id . ' відхилено/видалено.',
    ];
}

function tg_add_approved_review(string $reviewsFile, array $payload, array $allowedTopics): array
{
    $name = sanitize_text_field($payload['name'] ?? '', 60);
    $text = sanitize_multiline_text($payload['text'] ?? '', 1500);
    $rating = max(1, min(5, (int) ($payload['rating'] ?? 5)));
    $topicRaw = strtolower(trim((string) ($payload['topic'] ?? 'general')));
    $topic = sanitize_topic($topicRaw, $allowedTopics, '');
    $roomId = (int) ($payload['room_id'] ?? 0);
    if ($roomId < 1 || $roomId > 20) {
        $roomId = 0;
    }

    if (mb_strlen($name) < 2) {
        return ['ok' => false, 'message' => "Ім'я має бути мінімум 2 символи."];
    }
    if (mb_strlen($text) < 10) {
        return ['ok' => false, 'message' => 'Текст відгуку занадто короткий (мінімум 10 символів).'];
    }
    if ($topic === '') {
        return ['ok' => false, 'message' => 'Невідома тема. Доступні: rooms, territory, service, location, general.'];
    }
    if ($topic === 'rooms' && $roomId <= 0) {
        return ['ok' => false, 'message' => 'Для теми rooms потрібно вказати room_id (1..20).'];
    }

    $data = tg_read_reviews_data($reviewsFile);
    $review = [
        'id' => $data['nextId']++,
        'name' => $name,
        'text' => $text,
        'rating' => $rating,
        'topic' => $topic,
        'source' => 'telegram',
        'images' => [],
        'date' => date('Y-m-d'),
        'created_at' => date('c'),
        'moderated_at' => date('c'),
    ];
    if ($roomId > 0) {
        $review['room_id'] = $roomId;
    }

    $data['approved'][] = $review;
    if (!tg_save_reviews_data($reviewsFile, $data)) {
        return ['ok' => false, 'message' => 'Не вдалося зберегти відгук.'];
    }

    return [
        'ok' => true,
        'message' => 'Відгук #' . $review['id'] . ' додано в approved.',
        'review' => $review,
    ];
}

function tg_clean_header_value(string $value): string
{
    $clean = preg_replace('/[\r\n]+/', ' ', $value) ?? '';
    $clean = trim($clean);
    return mb_substr($clean, 0, 190);
}

function tg_normalize_email(string $value): ?string
{
    $email = tg_clean_header_value(trim($value));
    if ($email === '') {
        return null;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $email;
}

function tg_normalize_phone_dial(string $value): ?string
{
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return null;
    }

    if (strpos($raw, '+') === 0) {
        if (strlen($digits) >= 9 && strlen($digits) <= 15) {
            return '+' . $digits;
        }
        return null;
    }

    if (strpos($digits, '380') === 0 && strlen($digits) >= 11 && strlen($digits) <= 15) {
        return '+' . $digits;
    }

    if (strlen($digits) === 10) {
        return '+38' . $digits;
    }

    if (strlen($digits) >= 9 && strlen($digits) <= 15) {
        return '+' . $digits;
    }

    return null;
}

function tg_load_phpmailer(): bool
{
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }

    $autoloadCandidates = [
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];

    foreach ($autoloadCandidates as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                return true;
            }
        }
    }

    return false;
}

function tg_log_email_copy(string $subject, string $body): bool
{
    $logFile = rtrim(STORAGE_LOGS_DIR, '/\\') . DIRECTORY_SEPARATOR . 'booking-mail.log';
    $record = "=== " . date('c') . " ===\n[BOT REPLY] " . $subject . "\n\n" . $body . "\n\n";
    return file_put_contents($logFile, $record, FILE_APPEND | LOCK_EX) !== false;
}

function tg_send_custom_email(string $to, string $subject, string $body, ?string $replyTo = null): array
{
    $recipient = tg_normalize_email($to);
    if ($recipient === null) {
        return ['sent' => false, 'transport' => 'none', 'error' => 'Invalid recipient email'];
    }

    $subject = tg_clean_header_value($subject);
    if ($subject === '') {
        $subject = 'SvityazHOME повідомлення';
    }

    $fromEmail = tg_normalize_email(SMTP_FROM) ?: BOOKING_EMAIL_TO;
    $fromName = tg_clean_header_value(SMTP_FROM_NAME);
    if ($fromName === '') {
        $fromName = 'SvityazHOME';
    }
    $replyToEmail = tg_normalize_email((string) ($replyTo ?? BOOKING_EMAIL_TO));

    if (BOOKING_EMAIL_MODE === 'log') {
        $saved = tg_log_email_copy($subject, "TO: {$recipient}\n\n{$body}");
        return [
            'sent' => $saved,
            'transport' => 'log',
            'error' => $saved ? null : 'Failed to write email log',
        ];
    }

    $smtpConfigured = SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== '' && SMTP_PORT > 0 && $fromEmail !== '';
    $trySmtp = (BOOKING_EMAIL_MODE === 'auto' || BOOKING_EMAIL_MODE === 'smtp') && $smtpConfigured;

    if ($trySmtp && tg_load_phpmailer()) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->Port = SMTP_PORT;

            $secure = SMTP_SECURE;
            if ($secure === 'ssl') {
                $mail->SMTPSecure = 'ssl';
            } elseif ($secure === 'none' || $secure === '') {
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = 'tls';
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($recipient);
            if ($replyToEmail !== null) {
                $mail->addReplyTo($replyToEmail);
            }

            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();

            return [
                'sent' => true,
                'transport' => 'smtp_phpmailer',
                'error' => null,
            ];
        } catch (Throwable $e) {
            error_log('Telegram reply SMTP send failed: ' . $e->getMessage());
            if (BOOKING_EMAIL_MODE === 'smtp') {
                return [
                    'sent' => false,
                    'transport' => 'smtp_phpmailer',
                    'error' => 'SMTP send failed',
                ];
            }
        }
    } elseif ($trySmtp && BOOKING_EMAIL_MODE === 'smtp') {
        return [
            'sent' => false,
            'transport' => 'smtp_phpmailer',
            'error' => 'PHPMailer is not installed',
        ];
    }

    if (BOOKING_EMAIL_MODE !== 'auto' && BOOKING_EMAIL_MODE !== 'mail') {
        return [
            'sent' => false,
            'transport' => 'none',
            'error' => 'Unsupported BOOKING_EMAIL_MODE value',
        ];
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . tg_clean_header_value($fromName) . ' <' . $fromEmail . '>',
    ];
    if ($replyToEmail !== null) {
        $headers[] = 'Reply-To: ' . $replyToEmail;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $sent = @mail($recipient, $encodedSubject, $body, implode("\r\n", $headers));

    if (!$sent && BOOKING_EMAIL_MODE === 'auto') {
        $saved = tg_log_email_copy($subject, "TO: {$recipient}\n\n{$body}");
        if ($saved) {
            return [
                'sent' => true,
                'transport' => 'log_fallback',
                'error' => null,
            ];
        }
    }

    return [
        'sent' => $sent,
        'transport' => 'mail',
        'error' => $sent ? null : 'mail() send failed',
    ];
}

function tg_booking_list(PDO $pdo, int $limit = TG_DEFAULT_LIST_LIMIT, bool $onlyApplications = false): array
{
    $limit = max(1, min(TG_MAX_LIST_LIMIT, $limit));
    $sql = "
        SELECT booking_id, name, phone, email, checkin_date, checkout_date, guests, room_code, status, created_at
        FROM bookings
        WHERE honeypot_triggered = 0
    ";
    if ($onlyApplications) {
        $sql .= " AND status IN ('new','new_email_failed') ";
    }
    $sql .= "
        ORDER BY datetime(created_at) DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function tg_booking_by_id(PDO $pdo, string $bookingId): ?array
{
    $id = strtoupper(trim($bookingId));
    if (preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $id) !== 1) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT booking_id, name, phone, email, checkin_date, checkout_date, guests, room_code, message, status, created_at
        FROM bookings
        WHERE booking_id = :booking_id
        LIMIT 1
    ");
    $stmt->execute([':booking_id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function tg_main_keyboard(): array
{
    return [
        'keyboard' => [
            [
                ['text' => '📲 Telegram App'],
                ['text' => '📅 Заявки'],
            ],
            [
                ['text' => '📋 Відгуки'],
                ['text' => '📌 Остання заявка'],
            ],
            [
                ['text' => '🔎 Обрати заявку'],
                ['text' => '✉️ Відповісти на заявку'],
            ],
            [
                ['text' => '🏠 Змінити номер'],
                ['text' => '🔍 Пошук заявки'],
            ],
            [
                ['text' => '📊 Сьогодні'],
                ['text' => '🌤 Завтра'],
            ],
            [
                ['text' => '🟢 Заїзди сьогодні'],
                ['text' => '🟠 Заїзди завтра'],
            ],
            [
                ['text' => '🔵 Виїзди сьогодні'],
                ['text' => '🏠 Вільні номери'],
            ],
            [
                ['text' => 'ℹ️ Статус'],
                ['text' => '❓ Допомога'],
            ],
            [
                ['text' => 'Скасувати'],
            ],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'is_persistent' => true,
        'input_field_placeholder' => 'Оберіть дію або введіть команду',
    ];
}

function tg_menu_text(): string
{
    return implode("\n", [
        "Головне меню SvityazHOME:",
        "📲 Telegram App - повний список заявок у вікні Telegram",
        "📅 Заявки - список нових бронювань",
        "📋 Відгуки - pending відгуки на модерацію",
        "📌 Остання заявка - швидко відкрити останню бронь",
        "✉️ Відповісти на заявку - оберіть заявку, потім надішліть текст",
        "🏠 Змінити номер - оберіть заявку і новий номер кімнати",
        "📊 Сьогодні / 🌤 Завтра - короткий календар",
        "🟢 Заїзди сьогодні / 🟠 Заїзди завтра / 🔵 Виїзди сьогодні",
        "🏠 Вільні номери - після кнопки надішліть дати: 2026-07-14 2026-07-18",
        "🔍 Пошук заявки - можна шукати за ID, телефоном або ім'ям",
        "",
        "Для повної довідки: /help або /app",
    ]);
}

function tg_reply_format_help(): string
{
    return implode("\n", [
        "Відповідь на заявку:",
        "Найпростіше: натисніть «✉️ Відповісти на заявку», оберіть заявку кнопкою, потім напишіть текст.",
        "Бот сам обере канал: email (якщо є) або телефон.",
        "Скасувати вибір заявки: «Скасувати»",
    ]);
}

function tg_help_text(): string
{
    return implode("\n", [
        "SvityazHOME Telegram-бот",
        "Основні дії винесені в меню внизу.",
        "",
        "Що робити щодня:",
        "Telegram App - заявка відкривається в окремому вікні Telegram",
        "Заявки - нові бронювання",
        "Відгуки - модерація pending відгуків",
        "Остання заявка - відкрити останню бронь",
        "Обрати заявку - показати останні бронювання і вибрати одну кнопкою",
        "Відповісти на заявку - вибір заявки, далі просто пишете текст",
        "Змінити номер - вибір заявки, далі вибір номера 1-20",
        "Сьогодні / Завтра - короткі підсумки по календарю",
        "Вільні номери - перевірка діапазону дат",
        "",
        "Приклади текстом:",
        "Заявки",
        "Відгуки",
        "Заїзди сьогодні",
        "Заїзди завтра",
        "Виїзди сьогодні",
        "Вільні номери 2026-07-14 2026-07-18",
        "Перевірити room-3 2026-07-14 2026-07-18",
        "Пошук 093... або BK20260714-ABC123",
        "Схвалити 123 / Відхилити 123",
        "Після списку заявок можна просто надіслати цифру 1-5.",
        "",
        "Службові команди:",
        "/menu, /help, /app, /status, /today, /tomorrow",
        "/pending [N], /bookings [N], /latest, /booking ID",
        "/reply, /change_room, /find ...",
        "/arrivals [today|tomorrow], /departures [today|tomorrow]",
        "/availability room-3 2026-07-14 2026-07-18",
        "/free_rooms 2026-07-14 2026-07-18, /actions [N], /whoami",
        "/login пароль (доступ з нового пристрою), /logout",
    ]);
}

function tg_admin_list_configured(): bool
{
    return count(tg_admin_chat_ids()) > 0;
}

function tg_miniapp_base_url(): string
{
    $url = trim((string) TELEGRAM_MINIAPP_URL);
    if ($url === '') {
        $url = rtrim(SITE_URL, '/') . '/telegram-app/';
    }

    return $url;
}

function tg_miniapp_auth_params(?string $chatId = null): array
{
    $chatId = trim((string) ($chatId ?? ''));
    if ($chatId === '' || preg_match('/^-?\d{3,20}$/', $chatId) !== 1) {
        return [];
    }

    $accessToken = telegram_miniapp_access_token_issue($chatId, TG_MINIAPP_ACCESS_TOKEN_TTL);
    if ($accessToken === '') {
        return [];
    }

    return ['access_token' => $accessToken];
}

function tg_miniapp_url(array $params = [], ?string $chatId = null): string
{
    $baseUrl = tg_miniapp_base_url();
    $query = tg_miniapp_auth_params($chatId);
    if (empty($params) && empty($query)) {
        return $baseUrl;
    }

    foreach ($params as $key => $value) {
        $name = trim((string) $key);
        $current = trim((string) $value);
        if ($name === '' || $current === '' || $name === 'access_token') {
            continue;
        }
        $query[$name] = $current;
    }

    if (empty($query)) {
        return $baseUrl;
    }

    $separator = strpos($baseUrl, '?') === false ? '?' : '&';
    return $baseUrl . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function tg_miniapp_entry_keyboard(?string $bookingId = null, ?string $chatId = null): array
{
    $bookingId = strtoupper(trim((string) ($bookingId ?? '')));
    $rows = [[[
        'text' => $bookingId !== '' ? '📲 Відкрити заявку в Telegram App' : '📲 Відкрити Telegram App',
        'web_app' => [
            'url' => tg_miniapp_url($bookingId !== '' ? ['booking_id' => $bookingId] : [], $chatId),
        ],
    ]]];

    if ($bookingId !== '') {
        $rows[] = [[
            'text' => '🔗 Відкрити в адмінці',
            'url' => rtrim(SITE_URL, '/') . '/svh-ctrl-x7k9/requests.php#booking-' . rawurlencode($bookingId),
        ]];
    }

    return ['inline_keyboard' => $rows];
}

function tg_send_miniapp_entry(string $chatId, ?string $bookingId = null, ?int $replyToMessageId = null): void
{
    $bookingId = strtoupper(trim((string) ($bookingId ?? '')));
    $text = $bookingId !== ''
        ? "Відкрийте цю заявку в Telegram App.\nТам буде повна картка і швидкі дії."
        : "Відкрийте Telegram App для заявок.\nТам є список, пошук, деталі і зміна статусу.";

    tg_send_message($chatId, $text, tg_miniapp_entry_keyboard($bookingId, $chatId), $replyToMessageId);
}

function tg_parse_command(string $text): array
{
    $value = trim($text);
    if ($value === '') {
        return ['', ''];
    }

    if (preg_match('/^\/([a-z0-9_]+)(?:@[a-z0-9_]+)?(?:\s+(.*))?$/iu', $value, $match) !== 1) {
        return ['', ''];
    }

    $command = strtolower(trim((string) ($match[1] ?? '')));
    $args = trim((string) ($match[2] ?? ''));
    return [$command, $args];
}

function tg_parse_human_command(string $text): array
{
    $raw = trim($text);
    if ($raw === '') {
        return ['', ''];
    }

    $normalized = mb_strtolower($raw);
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    $plain = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $normalized) ?? $normalized);

    if (in_array($plain, ['меню', 'menu', 'старт', 'start'], true)) {
        return ['menu', ''];
    }
    if (in_array($plain, ['допомога', 'help'], true)) {
        return ['help', ''];
    }
    if (in_array($plain, ['відгуки', 'pending', 'пендінг'], true)) {
        return ['pending', (string) TG_DEFAULT_LIST_LIMIT];
    }
    if (in_array($plain, ['telegram app', 'app', 'додаток', 'телеграм додаток', 'telegram mini app'], true)) {
        return ['app', ''];
    }
    if (in_array($plain, ['заявки', 'заявка', 'бронювання', 'bookings', 'броні'], true)) {
        return ['bookings', (string) TG_DEFAULT_LIST_LIMIT];
    }
    if (in_array($plain, ['остання заявка', 'остання бронь', 'latest', 'latest booking', 'last booking'], true)) {
        return ['latest_booking', ''];
    }
    if (in_array($plain, ['деталі бронювання', 'деталі заявки', 'бронювання id', 'деталі', 'booking id', 'обрати заявку', 'обрати бронювання'], true)) {
        return ['booking', ''];
    }
    if (in_array($plain, ['відповісти гостю', 'відповісти на заявку', 'відповідь гостю', 'відповідь', 'reply'], true)) {
        return ['reply', ''];
    }
    if (in_array($plain, ['відповісти'], true)) {
        return ['reply', ''];
    }
    if (in_array($plain, ['змінити номер', 'change room', 'change_room'], true)) {
        return ['change_room', ''];
    }
    if (in_array($plain, ['заїзд сьогодні', 'заїзди сьогодні', 'arrivals today'], true)) {
        return ['arrivals_today', ''];
    }
    if (in_array($plain, ['заїзд завтра', 'заїзди завтра', 'arrivals tomorrow'], true)) {
        return ['arrivals_tomorrow', ''];
    }
    if (in_array($plain, ['виїзд сьогодні', 'виїзди сьогодні', 'departures today'], true)) {
        return ['departures_today', ''];
    }
    if (in_array($plain, ['завтра', 'календар завтра', 'tomorrow'], true)) {
        return ['tomorrow', ''];
    }
    if (in_array($plain, ['вільні номери', 'вільні номера', 'free rooms', 'free_rooms'], true)) {
        return ['free_rooms', ''];
    }
    if (in_array($plain, ['перевірити дати', 'перевірити номер', 'availability'], true)) {
        return ['availability', ''];
    }
    if (in_array($plain, ['останні дії', 'журнал', 'логи', 'actions', 'адмін дії'], true)) {
        return ['actions', (string) TG_DEFAULT_LIST_LIMIT];
    }
    if (in_array($plain, ['пошук заявки', 'пошук', 'search', 'find booking'], true)) {
        return ['find_booking', ''];
    }
    if (in_array($plain, ['сьогодні', 'статистика сьогодні', 'today', 'today stats'], true)) {
        return ['today', ''];
    }
    if (in_array($plain, ['скасувати', 'cancel', 'відміна', 'стоп'], true)) {
        return ['cancel_reply', ''];
    }
    if (in_array($plain, ['статус', 'status'], true)) {
        return ['status', ''];
    }
    if (in_array($plain, ['whoami', 'хто я', 'мій id', 'мій айді', 'id'], true)) {
        return ['whoami', ''];
    }
    if (in_array($plain, ['logout', 'вийти', 'вихід'], true)) {
        return ['logout', ''];
    }

    if (preg_match('/^(?:approve|схвалити)\s*#?\s*(\d{1,9})$/iu', $raw, $match) === 1) {
        return ['approve', $match[1]];
    }
    if (preg_match('/^(?:reject|відхилити|видалити)\s*#?\s*(\d{1,9})$/iu', $raw, $match) === 1) {
        return ['reject', $match[1]];
    }
    if (preg_match('/^(?:пароль|login)\s+(.+)$/iu', $raw, $match) === 1) {
        return ['login', trim((string) $match[1])];
    }
    if (preg_match('/^(?:змінити\s+номер|change[_\\s-]?room|room)\s+(BK\d{8}-[A-Z0-9]{6})\s+([a-z0-9-]{1,20})$/iu', $raw, $match) === 1) {
        return ['change_room', strtoupper($match[1]) . '|' . strtolower(trim((string) $match[2]))];
    }
    if (preg_match('/^(?:пошук|search|find)\s+(.+)$/iu', $raw, $match) === 1) {
        return ['find_booking', trim((string) $match[1])];
    }
    if (preg_match('/^(?:заїзд|заїзди|arrivals?)\s+(сьогодні|today|завтра|tomorrow)$/iu', $raw, $match) === 1) {
        $selector = mb_strtolower(trim((string) $match[1]));
        if ($selector === 'завтра' || $selector === 'tomorrow') {
            return ['arrivals_tomorrow', ''];
        }
        return ['arrivals_today', ''];
    }
    if (preg_match('/^(?:виїзд|виїзди|departures?)\s+(сьогодні|today)$/iu', $raw) === 1) {
        return ['departures_today', ''];
    }
    if (preg_match('/^(?:вільні\s+номери|вільні\s+номера|free[_\s-]?rooms?)\s+(\d{4}-\d{2}-\d{2})\s+(\d{4}-\d{2}-\d{2})$/iu', $raw, $match) === 1) {
        return ['free_rooms', trim((string) $match[1]) . ' ' . trim((string) $match[2])];
    }
    if (preg_match('/^(?:перевірити|availability|номер)\s+([a-z0-9-]{1,20})\s+(\d{4}-\d{2}-\d{2})\s+(\d{4}-\d{2}-\d{2})$/iu', $raw, $match) === 1) {
        return ['availability', trim((string) $match[1]) . ' ' . trim((string) $match[2]) . ' ' . trim((string) $match[3])];
    }
    if (preg_match('/^(?:дії|actions?|журнал|логи)\s*(\d{1,2})?$/iu', $raw, $match) === 1) {
        return ['actions', trim((string) ($match[1] ?? ''))];
    }
    if (preg_match('/^(?:booking|бронь|бронювання)\s+([a-z0-9-]{6,32})$/iu', $raw, $match) === 1) {
        return ['booking', strtoupper($match[1])];
    }
    if (preg_match('/^bk\d{8}-[a-z0-9]{6}$/iu', $raw) === 1) {
        return ['booking', strtoupper($raw)];
    }

    return ['', ''];
}

function tg_parse_reply_args(string $args): array
{
    $value = trim($args);
    if ($value === '') {
        return ['', ''];
    }

    if (strpos($value, '|') !== false) {
        $parts = explode('|', $value, 2);
        $bookingId = strtoupper(trim((string) ($parts[0] ?? '')));
        $replyText = sanitize_multiline_text((string) ($parts[1] ?? ''), 2500);
        return [$bookingId, $replyText];
    }

    if (preg_match('/^(BK\d{8}-[A-Z0-9]{6})\s*\R+\s*(.+)$/isu', $value, $match) === 1) {
        $bookingId = strtoupper(trim((string) ($match[1] ?? '')));
        $replyText = sanitize_multiline_text((string) ($match[2] ?? ''), 2500);
        return [$bookingId, $replyText];
    }

    if (preg_match('/^(BK\d{8}-[A-Z0-9]{6})\s+(.+)$/isu', $value, $match) === 1) {
        $bookingId = strtoupper(trim((string) ($match[1] ?? '')));
        $replyText = sanitize_multiline_text((string) ($match[2] ?? ''), 2500);
        return [$bookingId, $replyText];
    }

    return [strtoupper($value), ''];
}

function tg_parse_room_code(string $value): ?string
{
    $raw = strtolower(trim($value));
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^room-(?:[1-9]|1[0-9]|20)$/', $raw) === 1) {
        return $raw;
    }

    if (preg_match('/^(?:[1-9]|1[0-9]|20)$/', $raw) === 1) {
        return 'room-' . $raw;
    }

    return null;
}

function tg_change_booking_room(PDO $pdo, string $bookingId, string $roomCode): array
{
    $booking = tg_booking_by_id($pdo, $bookingId);
    if (!$booking) {
        return ['ok' => false, 'message' => 'Заявку не знайдено.'];
    }

    $normalizedRoom = tg_parse_room_code($roomCode);
    if ($normalizedRoom === null) {
        return ['ok' => false, 'message' => 'Некоректний номер. Вкажіть 1..20 або room-1..room-20.'];
    }

    $checkinDate = (string) ($booking['checkin_date'] ?? '');
    $checkoutDate = (string) ($booking['checkout_date'] ?? '');
    $conflicts = svh_find_room_conflicts($pdo, $normalizedRoom, $checkinDate, $checkoutDate, (string) ($booking['booking_id'] ?? ''));
    if (($conflicts['has_conflict'] ?? false) === true) {
        $firstBooking = $conflicts['bookings'][0] ?? null;
        $firstEvent = $conflicts['events'][0] ?? null;
        if (is_array($firstBooking)) {
            return [
                'ok' => false,
                'message' => 'Номер зайнятий: ' . (string) ($firstBooking['checkin_date'] ?? '') . ' → ' . (string) ($firstBooking['checkout_date'] ?? '') . ' (' . (string) ($firstBooking['status'] ?? '') . ').',
            ];
        }
        if (is_array($firstEvent)) {
            return [
                'ok' => false,
                'message' => 'Номер заблокований: ' . (string) ($firstEvent['start_date'] ?? '') . ' → ' . (string) ($firstEvent['end_date'] ?? '') . '.',
            ];
        }
    }

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET room_code = :room_code
        WHERE booking_id = :booking_id
    ");
    $ok = $stmt->execute([
        ':room_code' => $normalizedRoom,
        ':booking_id' => strtoupper(trim($bookingId)),
    ]);
    if (!$ok) {
        return ['ok' => false, 'message' => 'Не вдалося оновити номер заявки.'];
    }

    return [
        'ok' => true,
        'message' => 'Номер у заявці ' . strtoupper(trim($bookingId)) . ' змінено на ' . $normalizedRoom . '.',
        'room_code' => $normalizedRoom,
    ];
}

function tg_handle_change_room_command(string $chatId, PDO $pdo, Database $db, string $args): void
{
    $parts = explode('|', $args, 2);
    $bookingId = strtoupper(trim((string) ($parts[0] ?? '')));
    $roomRaw = trim((string) ($parts[1] ?? ''));

    if ($bookingId !== '' && $roomRaw === '') {
        $booking = tg_booking_by_id($pdo, $bookingId);
        if (!$booking) {
            tg_send_message($chatId, 'Заявку не знайдено. Оберіть її зі списку.');
            tg_send_bookings($chatId, $pdo, (string) TG_DEFAULT_LIST_LIMIT, 'changeroompick');
            return;
        }

        tg_change_room_context_set($chatId, $bookingId);
        tg_send_message(
            $chatId,
            "Заявку {$bookingId} обрано.\nОберіть новий номер кнопкою нижче або надішліть 1-20 текстом.",
            tg_room_select_keyboard($bookingId, (string) ($booking['room_code'] ?? ''))
        );
        return;
    }

    if ($bookingId === '' || $roomRaw === '') {
        tg_send_message($chatId, 'Оберіть заявку для зміни номера:');
        tg_send_bookings($chatId, $pdo, (string) TG_DEFAULT_LIST_LIMIT, 'changeroompick');
        return;
    }

    $result = tg_change_booking_room($pdo, $bookingId, $roomRaw);
    tg_send_message($chatId, (string) ($result['message'] ?? 'Невідомий результат.'));

    if (!($result['ok'] ?? false)) {
        return;
    }

    tg_log_action(
        $db,
        'telegram_booking_change_room',
        'booking_id=' . $bookingId . '; room=' . (string) ($result['room_code'] ?? ''),
        $chatId
    );
}

function tg_handle_login_command(string $chatId, Database $db, string $args): void
{
    if (!tg_admin_access_password_enabled()) {
        tg_send_message($chatId, 'Вхід по паролю вимкнено. Додайте TELEGRAM_ADMIN_ACCESS_PASSWORD у .env');
        return;
    }

    $password = trim($args);
    if ($password === '') {
        tg_send_message($chatId, "Надішліть: /login ваш_пароль");
        return;
    }

    if (!tg_admin_access_password_matches($password)) {
        tg_send_message($chatId, 'Невірний пароль доступу.');
        return;
    }

    tg_device_access_grant($chatId);
    tg_send_message(
        $chatId,
        "Доступ надано для цього пристрою на 30 днів.\nКоманди: «📅 Заявки», «📋 Відгуки», «❓ Допомога».",
        tg_main_keyboard()
    );
    tg_log_action($db, 'telegram_device_login', 'chat_id=' . $chatId, $chatId);
}

function tg_handle_logout_command(string $chatId, Database $db): void
{
    $hadDeviceAccess = tg_device_access_has($chatId);
    tg_device_access_revoke($chatId);
    tg_reply_context_clear($chatId);
    tg_change_room_context_clear($chatId);
    tg_booking_pick_context_clear($chatId);

    if (in_array($chatId, tg_admin_chat_ids(), true)) {
        tg_send_message(
            $chatId,
            "Сесію по паролю завершено.\nЦей чат все одно має доступ, бо вказаний у TELEGRAM_ADMIN_CHAT_IDS."
        );
        return;
    }

    if ($hadDeviceAccess) {
        tg_send_message($chatId, 'Доступ для цього пристрою вимкнено.');
        tg_log_action($db, 'telegram_device_logout', 'chat_id=' . $chatId, $chatId);
        return;
    }

    tg_send_message($chatId, 'Активного доступу по паролю не було.');
}

function tg_booking_select_keyboard(array $rows, string $action = 'booking', string $refreshAction = 'apps:list', ?string $chatId = null): ?array
{
    if (empty($rows)) {
        return null;
    }

    $action = trim($action);
    if ($action === '' || preg_match('/^[a-z_]+$/', $action) !== 1) {
        $action = 'booking';
    }

    $refreshAction = trim($refreshAction);
    if ($refreshAction === '' || preg_match('/^[a-z:._-]+$/', $refreshAction) !== 1) {
        $refreshAction = 'apps:list';
    }

    $buttons = [];
    foreach ($rows as $index => $row) {
        $bookingId = strtoupper(trim((string) ($row['booking_id'] ?? '')));
        if (preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
            continue;
        }

        $name = tg_safe_summary((string) ($row['name'] ?? 'Гість'), 18);
        $checkin = (string) ($row['checkin_date'] ?? '');
        $checkout = (string) ($row['checkout_date'] ?? '');
        $text = ($index + 1) . '. ' . $name . ' • ' . $checkin . '→' . $checkout;

        if ($action === 'booking') {
            $buttons[] = [[
                'text' => $text,
                'web_app' => [
                    'url' => tg_miniapp_url(['booking_id' => $bookingId], $chatId),
                ],
            ]];
            continue;
        }

        $buttons[] = [[
            'text' => $text,
            'callback_data' => $action . ':' . $bookingId,
        ]];
    }

    if (empty($buttons)) {
        return null;
    }

    if ($action === 'booking') {
        $buttons[] = [[
            'text' => '📲 Усі заявки в Telegram App',
            'web_app' => [
                'url' => tg_miniapp_url([], $chatId),
            ],
        ]];
    }

    $buttons[] = [[
        'text' => '🔄 Оновити заявки',
        'callback_data' => $refreshAction,
    ]];

    return ['inline_keyboard' => $buttons];
}

function tg_room_select_keyboard(string $bookingId, ?string $currentRoomCode = null): array
{
    $bookingId = strtoupper(trim($bookingId));
    $normalizedCurrent = tg_parse_room_code((string) ($currentRoomCode ?? ''));
    $rows = [];
    $rowIndex = -1;

    for ($i = 1; $i <= 20; $i++) {
        if (($i - 1) % 5 === 0) {
            $rowIndex++;
            $rows[$rowIndex] = [];
        }

        $roomCode = 'room-' . $i;
        $isCurrent = ($normalizedCurrent !== null && hash_equals($normalizedCurrent, $roomCode));
        $label = $isCurrent ? ('✅ ' . $i) : (string) $i;

        $rows[$rowIndex][] = [
            'text' => $label,
            'callback_data' => 'changeroomset:' . $bookingId . ':' . $i,
        ];
    }

    $rows[] = [[
        'text' => '⬅️ До заявок',
        'callback_data' => 'changeroom:list',
    ]];

    return [
        'inline_keyboard' => $rows,
    ];
}

function tg_booking_actions_keyboard(array $booking, ?string $chatId = null): array
{
    $bookingId = strtoupper(trim((string) ($booking['booking_id'] ?? '')));
    $email = tg_normalize_email((string) ($booking['email'] ?? ''));
    $phoneDial = tg_normalize_phone_dial((string) ($booking['phone'] ?? ''));
    $status = strtolower(trim((string) ($booking['status'] ?? '')));
    $adminUrl = rtrim(SITE_URL, '/') . '/svh-ctrl-x7k9/requests.php#booking-' . rawurlencode($bookingId);

    $rows = [];
    $rows[] = [[
        'text' => '📲 Telegram App',
        'web_app' => [
            'url' => tg_miniapp_url(['booking_id' => $bookingId], $chatId),
        ],
    ], [
        'text' => '🔗 Адмінка',
        'url' => $adminUrl,
    ]];

    if ($email !== null) {
        $rows[] = [[
            'text' => '✉️ Відповісти на email',
            'callback_data' => 'replypick:' . $bookingId,
        ]];
    } else {
        $rows[] = [[
            'text' => '✍️ Текст для відповіді',
            'callback_data' => 'replypick:' . $bookingId,
        ]];
    }

    if ($phoneDial !== null) {
        $rows[] = [[
            'text' => '📞 Подзвонити',
            'url' => 'tel:' . $phoneDial,
        ]];
    }

    $rows[] = [[
        'text' => '🏠 Змінити номер',
        'callback_data' => 'changeroompick:' . $bookingId,
    ]];

    if (tg_is_booking_new_status($status)) {
        $rows[] = [[
            'text' => '✅ Завершити заявку',
            'callback_data' => 'bookingstatus:' . $bookingId . ':processed',
        ]];
    } elseif ($status === 'processed') {
        $rows[] = [[
            'text' => '🆕 Повернути в нові',
            'callback_data' => 'bookingstatus:' . $bookingId . ':new',
        ]];
    } else {
        $rows[] = [[
            'text' => '✅ Завершити заявку',
            'callback_data' => 'bookingstatus:' . $bookingId . ':processed',
        ], [
            'text' => '🆕 Повернути в нові',
            'callback_data' => 'bookingstatus:' . $bookingId . ':new',
        ]];
    }

    $rows[] = [[
        'text' => '⬅️ До списку заявок',
        'callback_data' => 'apps:list',
    ]];

    return [
        'inline_keyboard' => $rows,
    ];
}

function tg_build_pending_item_text(array $review): string
{
    $id = (int) ($review['id'] ?? 0);
    $topic = sanitize_topic($review['topic'] ?? 'general', array_keys(tg_topic_labels()), 'general');
    $topicLabel = tg_topic_labels()[$topic] ?? $topic;
    $rating = max(1, min(5, (int) ($review['rating'] ?? 5)));
    $name = trim((string) ($review['name'] ?? 'Гість'));
    $roomId = (int) ($review['room_id'] ?? 0);
    $date = (string) ($review['created_at'] ?? $review['date'] ?? '');
    $text = tg_safe_summary((string) ($review['text'] ?? ''), 220);

    $lines = [
        "ID: {$id} | {$name}",
        "Оцінка: {$rating}/5 | Тема: {$topicLabel}" . ($roomId > 0 ? " | Номер {$roomId}" : ''),
        "Дата: {$date}",
        "Текст: {$text}",
    ];

    return implode("\n", $lines);
}

function tg_pending_keyboard(int $reviewId): array
{
    return [
        'inline_keyboard' => [[
            ['text' => '✅ Схвалити', 'callback_data' => 'approve:' . $reviewId],
            ['text' => '❌ Відхилити', 'callback_data' => 'reject:' . $reviewId],
        ]],
    ];
}

function tg_status_summary(PDO $pdo, string $reviewsFile): string
{
    $reviews = tg_read_reviews_data($reviewsFile);
    $pendingReviews = count($reviews['pending']);
    $approvedReviews = count($reviews['approved']);
    $questions = count($reviews['questions']);

    $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM bookings WHERE honeypot_triggered = 0");
    $totalBookings = (int) (($stmt->fetch()['cnt'] ?? 0));
    $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM bookings WHERE honeypot_triggered = 0 AND status IN ('new','new_email_failed')");
    $newBookings = (int) (($stmt->fetch()['cnt'] ?? 0));

    return implode("\n", [
        'Статус SvityazHOME:',
        "Відгуки: pending={$pendingReviews}, approved={$approvedReviews}, questions={$questions}",
        "Бронювання: new={$newBookings}, total={$totalBookings}",
        'Час: ' . date('Y-m-d H:i:s'),
    ]);
}

function tg_today_item_count(array $items, string $today): int
{
    $count = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $raw = (string) ($item['created_at'] ?? $item['moderated_at'] ?? $item['date'] ?? '');
        if ($raw === '') {
            continue;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            continue;
        }
        if (date('Y-m-d', $ts) === $today) {
            $count++;
        }
    }
    return $count;
}

function tg_today_summary(PDO $pdo, string $reviewsFile): string
{
    unset($reviewsFile);
    return tg_day_calendar_summary_text($pdo, date('Y-m-d'), '📊 Календар на сьогодні');
}

function tg_parse_day_selector(string $args, string $defaultToken = 'today'): ?array
{
    $raw = mb_strtolower(trim($args));
    if ($raw === '') {
        $raw = $defaultToken;
    }

    $token = '';
    $label = '';
    $relative = '';
    if (in_array($raw, ['today', 'сьогодні'], true)) {
        $token = 'today';
        $label = 'сьогодні';
        $relative = 'today';
    } elseif (in_array($raw, ['tomorrow', 'завтра'], true)) {
        $token = 'tomorrow';
        $label = 'завтра';
        $relative = 'tomorrow';
    } else {
        return null;
    }

    $ts = strtotime($relative);
    if ($ts === false) {
        return null;
    }

    return [
        'token' => $token,
        'label' => $label,
        'date' => date('Y-m-d', $ts),
    ];
}

function tg_room_short_label(string $roomCode, array $catalog): string
{
    $room = $catalog[$roomCode] ?? null;
    $title = trim((string) ($room['title'] ?? ''));
    return $title !== '' ? $title : $roomCode;
}

function tg_calendar_status_label(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === 'confirmed') {
        return 'зайнято';
    }
    if ($normalized === 'waiting') {
        return 'очікує';
    }
    if ($normalized === 'blocked') {
        return 'заблоковано';
    }

    return 'вільно';
}

function tg_day_calendar_summary_text(PDO $pdo, string $date, string $title): string
{
    $summary = svh_day_occupancy_summary($pdo, $date);
    $catalog = svh_room_catalog();
    $lines = [
        $title,
        $date,
        '',
    ];

    foreach ($catalog as $roomCode => $room) {
        $state = $summary['rooms'][$roomCode] ?? null;
        $status = strtolower(trim((string) ($state['status'] ?? 'free')));
        $line = tg_room_short_label($roomCode, $catalog) . ' — ' . tg_calendar_status_label($status);

        if ($status === 'confirmed' || $status === 'waiting') {
            $booking = $state['bookings'][0] ?? null;
            if (is_array($booking)) {
                $line .= ' · ' . tg_safe_summary((string) ($booking['name'] ?? 'Гість'), 32);
                $line .= ' · до ' . (string) ($booking['checkout_date'] ?? '');
            }
        } elseif ($status === 'blocked') {
            $event = $state['events'][0] ?? null;
            if (is_array($event)) {
                $line .= ' · ' . tg_safe_summary((string) ($event['title'] ?? 'Блок'), 32);
                $line .= ' · до ' . (string) ($event['end_date'] ?? '');
            }
        }

        $lines[] = $line;
    }

    $lines[] = '';
    $lines[] = 'Заїзди: ' . count($summary['arrivals'] ?? []);
    $lines[] = 'Виїзди: ' . count($summary['departures'] ?? []);

    return implode("\n", $lines);
}

function tg_tomorrow_summary(PDO $pdo): string
{
    $date = svh_date_add_days(date('Y-m-d'), 1);
    return tg_day_calendar_summary_text($pdo, $date, '🌤 Календар на завтра');
}

function tg_parse_availability_args(string $args): array
{
    $parts = preg_split('/\s+/u', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) === 2) {
        return [null, (string) $parts[0], (string) $parts[1]];
    }
    if (count($parts) === 3) {
        $roomCode = svh_normalize_room_code((string) $parts[0]);
        return [$roomCode, (string) $parts[1], (string) $parts[2]];
    }

    return [null, '', ''];
}

function tg_send_room_availability_check(string $chatId, PDO $pdo, string $args): void
{
    [$roomCode, $checkinDate, $checkoutDate] = tg_parse_availability_args($args);
    if ($roomCode === null || !svh_is_iso_date($checkinDate) || !svh_is_iso_date($checkoutDate) || $checkoutDate <= $checkinDate) {
        tg_send_message($chatId, 'Формат: /availability room-3 2026-07-14 2026-07-18');
        return;
    }

    $availability = svh_room_range_availability($pdo, $checkinDate, $checkoutDate, $roomCode);
    $roomState = $availability[$roomCode] ?? null;
    if (!is_array($roomState)) {
        tg_send_message($chatId, 'Не вдалося перевірити номер на ці дати.');
        return;
    }

    $catalog = svh_room_catalog();
    $title = tg_room_short_label($roomCode, $catalog);
    $status = strtolower(trim((string) ($roomState['status'] ?? 'free')));
    if ($status === 'free') {
        tg_send_message($chatId, "{$title} вільний на {$checkinDate} → {$checkoutDate}.");
        return;
    }

    if ($status === 'blocked') {
        $event = $roomState['events'][0] ?? null;
        $line = "{$title} заблокований на {$checkinDate} → {$checkoutDate}.";
        if (is_array($event)) {
            $line .= "\nБлок: " . ((string) ($event['start_date'] ?? '')) . ' → ' . ((string) ($event['end_date'] ?? ''));
            $line .= "\nПричина: " . tg_safe_summary((string) ($event['title'] ?? 'Блок'), 80);
        }
        tg_send_message($chatId, $line);
        return;
    }

    $booking = $roomState['bookings'][0] ?? null;
    $lines = [
        "{$title} " . tg_calendar_status_label($status) . " на {$checkinDate} → {$checkoutDate}.",
    ];
    if (is_array($booking)) {
        $lines[] = 'Конфлікт: ' . (string) ($booking['checkin_date'] ?? '') . ' → ' . (string) ($booking['checkout_date'] ?? '');
        $lines[] = 'Статус: ' . (string) ($booking['status'] ?? '');
        $lines[] = 'Гість: ' . tg_safe_summary((string) ($booking['name'] ?? 'Гість'), 60);
    }
    tg_send_message($chatId, implode("\n", $lines));
}

function tg_send_free_rooms_for_range(string $chatId, PDO $pdo, string $args): void
{
    [, $checkinDate, $checkoutDate] = tg_parse_availability_args($args);
    if (!svh_is_iso_date($checkinDate) || !svh_is_iso_date($checkoutDate) || $checkoutDate <= $checkinDate) {
        tg_send_message($chatId, 'Формат: /free_rooms 2026-07-14 2026-07-18');
        return;
    }

    $availability = svh_room_range_availability($pdo, $checkinDate, $checkoutDate);
    if (empty($availability)) {
        tg_send_message($chatId, 'Не вдалося побудувати список номерів на цей діапазон.');
        return;
    }

    $groups = [
        'free' => [],
        'waiting' => [],
        'confirmed' => [],
        'blocked' => [],
    ];
    $catalog = svh_room_catalog();
    foreach ($availability as $roomCode => $roomState) {
        $status = strtolower(trim((string) ($roomState['status'] ?? 'free')));
        if (!array_key_exists($status, $groups)) {
            $status = 'free';
        }
        $groups[$status][] = tg_room_short_label($roomCode, $catalog);
    }

    $lines = [
        "🏠 Номери на {$checkinDate} → {$checkoutDate}",
        'Вільно: ' . (!empty($groups['free']) ? implode(', ', $groups['free']) : '—'),
        'Очікує: ' . (!empty($groups['waiting']) ? implode(', ', $groups['waiting']) : '—'),
        'Зайнято: ' . (!empty($groups['confirmed']) ? implode(', ', $groups['confirmed']) : '—'),
        'Блок: ' . (!empty($groups['blocked']) ? implode(', ', $groups['blocked']) : '—'),
    ];

    tg_send_message($chatId, implode("\n", $lines));
}

function tg_booking_list_for_date(PDO $pdo, string $dateColumn, string $date, int $limit = TG_MAX_LIST_LIMIT): array
{
    if (!in_array($dateColumn, ['checkin_date', 'checkout_date'], true)) {
        return [];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return [];
    }

    $limit = max(1, min(TG_MAX_LIST_LIMIT, $limit));
    $sql = "
        SELECT booking_id, name, phone, email, checkin_date, checkout_date, guests, room_code, status, created_at
        FROM bookings
        WHERE honeypot_triggered = 0
          AND " . svh_active_booking_where_sql() . "
          AND substr({$dateColumn}, 1, 10) = :target_date
        ORDER BY datetime(created_at) DESC
        LIMIT :limit
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':target_date', $date, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function tg_send_bookings_for_day(
    string $chatId,
    PDO $pdo,
    string $dateColumn,
    string $date,
    string $title,
    string $emptyText,
    string $refreshAction
): void {
    $rows = tg_booking_list_for_date($pdo, $dateColumn, $date, TG_MAX_LIST_LIMIT);
    if (empty($rows)) {
        tg_send_message($chatId, $emptyText . " ({$date}).");
        return;
    }

    tg_send_message(
        $chatId,
        "{$title} ({$date}): " . count($rows) . "\nНатисніть заявку: вона відкриється в Telegram App.",
        tg_booking_select_keyboard($rows, 'booking', $refreshAction, $chatId)
    );
}

function tg_send_arrivals_bookings(string $chatId, PDO $pdo, string $args, string $defaultToken = 'today'): void
{
    $selector = tg_parse_day_selector($args, $defaultToken);
    if (!is_array($selector)) {
        tg_send_message($chatId, "Параметр має бути: today|tomorrow (або сьогодні|завтра).");
        return;
    }

    $token = (string) ($selector['token'] ?? 'today');
    $date = (string) ($selector['date'] ?? date('Y-m-d'));
    $label = (string) ($selector['label'] ?? 'сьогодні');
    $title = $token === 'tomorrow' ? '🟠 Заїзди завтра' : '🟢 Заїзди сьогодні';

    tg_send_bookings_for_day(
        $chatId,
        $pdo,
        'checkin_date',
        $date,
        $title,
        "Заїздів {$label} не знайдено",
        'arrivals:' . $token
    );
}

function tg_send_departures_bookings(string $chatId, PDO $pdo, string $args, string $defaultToken = 'today'): void
{
    $selector = tg_parse_day_selector($args, $defaultToken);
    if (!is_array($selector)) {
        tg_send_message($chatId, "Параметр має бути: today|tomorrow (або сьогодні|завтра).");
        return;
    }

    $token = (string) ($selector['token'] ?? 'today');
    $date = (string) ($selector['date'] ?? date('Y-m-d'));
    tg_send_bookings_for_day(
        $chatId,
        $pdo,
        'checkout_date',
        $date,
        $token === 'tomorrow' ? '🟣 Виїзди завтра' : '🔵 Виїзди сьогодні',
        'Виїздів ' . ($token === 'tomorrow' ? 'завтра' : 'сьогодні') . ' не знайдено',
        'departures:' . $token
    );
}

function tg_recent_admin_actions(PDO $pdo, int $limit = TG_DEFAULT_LIST_LIMIT): array
{
    $limit = max(1, min(TG_MAX_LIST_LIMIT, $limit));
    $stmt = $pdo->prepare("
        SELECT action, details, ip_address, created_at
        FROM admin_log
        ORDER BY datetime(created_at) DESC, id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function tg_send_recent_admin_actions(string $chatId, PDO $pdo, string $args): void
{
    $limit = tg_extract_limit($args);
    $rows = tg_recent_admin_actions($pdo, $limit);
    if (empty($rows)) {
        tg_send_message($chatId, 'Історія адмін-дій поки порожня.');
        return;
    }

    $lines = ["🧾 Останні дії в адмінці (показано {$limit}):"];
    foreach ($rows as $row) {
        $created = trim((string) ($row['created_at'] ?? ''));
        if ($created === '') {
            $created = 'невідомий час';
        }

        $action = tg_safe_summary((string) ($row['action'] ?? 'unknown'), 40);
        $details = tg_safe_summary((string) ($row['details'] ?? ''), 100);
        $ip = tg_safe_summary((string) ($row['ip_address'] ?? ''), 30);

        $line = "{$created} | {$action}";
        if ($details !== '') {
            $line .= " | {$details}";
        }
        if ($ip !== '') {
            $line .= " | {$ip}";
        }
        $lines[] = $line;
    }

    tg_send_message($chatId, implode("\n", $lines));
}

function tg_sql_like_escape(string $value): string
{
    return strtr($value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_',
    ]);
}

function tg_booking_search(PDO $pdo, string $query, int $limit = 8): array
{
    $needle = trim($query);
    if ($needle === '') {
        return [];
    }

    $limit = max(1, min(TG_MAX_LIST_LIMIT, $limit));
    $needleLower = mb_strtolower($needle, 'UTF-8');
    $needleUpper = mb_strtoupper($needle, 'UTF-8');
    $likeLower = '%' . tg_sql_like_escape($needleLower) . '%';
    $likeUpper = '%' . tg_sql_like_escape($needleUpper) . '%';

    $stmt = $pdo->prepare("
        SELECT booking_id, name, phone, email, checkin_date, checkout_date, guests, room_code, status, created_at
        FROM bookings
        WHERE honeypot_triggered = 0
          AND (
            UPPER(booking_id) LIKE :like_upper ESCAPE '\\'
            OR LOWER(name) LIKE :like_lower ESCAPE '\\'
            OR LOWER(phone) LIKE :like_lower ESCAPE '\\'
            OR LOWER(email) LIKE :like_lower ESCAPE '\\'
          )
        ORDER BY datetime(created_at) DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':like_upper', $likeUpper, PDO::PARAM_STR);
    $stmt->bindValue(':like_lower', $likeLower, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function tg_send_booking_search_results(string $chatId, PDO $pdo, string $query): void
{
    $needle = trim($query);
    if ($needle === '') {
        tg_send_message($chatId, "Вкажіть запит для пошуку.\nПриклад: Пошук 093 або /find Влад");
        return;
    }
    if (mb_strlen($needle, 'UTF-8') < 2) {
        tg_send_message($chatId, 'Запит занадто короткий. Введіть хоча б 2 символи.');
        return;
    }

    $rows = tg_booking_search($pdo, $needle, 8);
    if (empty($rows)) {
        tg_booking_pick_context_clear($chatId);
        tg_send_message($chatId, "Нічого не знайдено за запитом: {$needle}");
        return;
    }

    tg_booking_pick_context_set($chatId, $rows, 'booking');
    $lines = ["Знайдено " . count($rows) . " заявок за запитом: {$needle}"];
    foreach ($rows as $index => $row) {
        $name = tg_safe_summary((string) ($row['name'] ?? ''), 28);
        $bookingId = (string) ($row['booking_id'] ?? '');
        $phone = trim((string) ($row['phone'] ?? ''));
        $lines[] = ($index + 1) . ". {$bookingId} | {$name}" . ($phone !== '' ? " | {$phone}" : '');
    }
    $lines[] = 'Кнопка відкриє заявку в Telegram App, або напишіть номер зі списку.';

    tg_send_message($chatId, implode("\n", $lines), tg_booking_select_keyboard($rows, 'booking', 'apps:list', $chatId));
}

function tg_is_booking_new_status(string $status): bool
{
    $normalized = strtolower(trim($status));
    return in_array($normalized, ['new', 'new_email_failed'], true);
}

function tg_update_booking_status(PDO $pdo, string $bookingId, string $status): array
{
    $bookingId = strtoupper(trim($bookingId));
    $target = strtolower(trim($status));
    if (!in_array($target, ['new', 'processed'], true)) {
        return ['ok' => false, 'message' => 'Непідтримуваний статус.'];
    }

    $booking = tg_booking_by_id($pdo, $bookingId);
    if (!$booking) {
        return ['ok' => false, 'message' => 'Заявку не знайдено.'];
    }

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET status = :status
        WHERE booking_id = :booking_id
    ");
    $ok = $stmt->execute([
        ':status' => $target,
        ':booking_id' => $bookingId,
    ]);

    if (!$ok) {
        return ['ok' => false, 'message' => 'Не вдалося оновити статус заявки.'];
    }

    $message = ($target === 'processed')
        ? "Заявку {$bookingId} позначено як оброблену."
        : "Заявку {$bookingId} повернуто в нові.";

    return [
        'ok' => true,
        'message' => $message,
        'status' => $target,
        'booking_id' => $bookingId,
    ];
}

function tg_send_pending_reviews(string $chatId, string $args, string $reviewsFile): void
{
    $limit = tg_extract_limit($args);
    $data = tg_read_reviews_data($reviewsFile);
    $pending = tg_sort_by_created_desc($data['pending']);

    if (empty($pending)) {
        tg_send_message($chatId, 'Pending відгуків немає.');
        return;
    }

    $total = count($pending);
    $items = array_slice($pending, 0, $limit);
    tg_send_message($chatId, "Pending відгуки: {$total}. Показано: " . count($items) . '.');

    foreach ($items as $review) {
        $id = (int) ($review['id'] ?? 0);
        tg_send_message($chatId, tg_build_pending_item_text($review), $id > 0 ? tg_pending_keyboard($id) : null);
    }
}

function tg_send_bookings(string $chatId, PDO $pdo, string $args, string $mode = 'booking'): void
{
    $limit = tg_extract_limit($args);
    $rows = tg_booking_list($pdo, $limit, true);

    if (empty($rows)) {
        tg_booking_pick_context_clear($chatId);
        tg_send_message($chatId, 'Нових заявок зараз немає.');
        return;
    }

    $mode = strtolower(trim($mode));
    $callbackAction = 'booking';
    $refreshAction = 'apps:list';
    $title = 'Нові заявки (натисніть на потрібну):';
    if ($mode === 'replypick') {
        $callbackAction = 'replypick';
        $refreshAction = 'reply:list';
        $title = 'Оберіть заявку для відповіді:';
    } elseif ($mode === 'changeroompick') {
        $callbackAction = 'changeroompick';
        $refreshAction = 'changeroom:list';
        $title = 'Оберіть заявку для зміни номера:';
    }

    tg_booking_pick_context_set($chatId, $rows, $callbackAction);

    $lines = [$title];
    foreach ($rows as $index => $row) {
        $name = tg_safe_summary((string) ($row['name'] ?? ''), 40);
        $roomCode = trim((string) ($row['room_code'] ?? ''));
        if ($roomCode === '') {
            $roomCode = 'room-?';
        }
        $lines[] = sprintf(
            "%d. %s | %s-%s | %s",
            $index + 1,
            $name,
            (string) ($row['checkin_date'] ?? ''),
            (string) ($row['checkout_date'] ?? ''),
            $roomCode
        );
    }
    if ($callbackAction === 'booking') {
        $lines[] = 'Кнопка заявки відкриє її в Telegram App. Або надішліть номер зі списку (1-' . count($rows) . ').';
    } else {
        $lines[] = 'Можна натиснути кнопку або надіслати номер зі списку (1-' . count($rows) . ').';
    }

    tg_send_message($chatId, implode("\n", $lines), tg_booking_select_keyboard($rows, $callbackAction, $refreshAction, $chatId));
}

function tg_send_latest_booking(string $chatId, PDO $pdo): void
{
    $rows = tg_booking_list($pdo, 1, true);
    $label = 'Остання нова заявка';
    if (empty($rows)) {
        $rows = tg_booking_list($pdo, 1, false);
        $label = 'Остання заявка';
    }

    if (empty($rows)) {
        tg_booking_pick_context_clear($chatId);
        tg_send_message($chatId, 'Поки що заявок немає.');
        return;
    }

    tg_booking_pick_context_set($chatId, $rows, 'booking');
    $bookingId = strtoupper(trim((string) ($rows[0]['booking_id'] ?? '')));
    tg_send_message($chatId, $label . ':');
    tg_send_booking_details($chatId, $pdo, $bookingId, true);
}

function tg_booking_details_text(array $row): string
{
    $message = trim((string) ($row['message'] ?? ''));
    if ($message === '') {
        $message = '—';
    } else {
        $message = tg_safe_summary($message, 500);
    }

    $email = tg_normalize_email((string) ($row['email'] ?? ''));
    $phoneDial = tg_normalize_phone_dial((string) ($row['phone'] ?? ''));
    $replyChannel = 'немає контакту';
    if ($email !== null) {
        $replyChannel = 'email';
    } elseif ($phoneDial !== null) {
        $replyChannel = 'телефон';
    }

    $lines = [
        'Деталі бронювання ' . (string) $row['booking_id'],
        "Ім'я: " . (string) $row['name'],
        'Телефон: ' . (string) $row['phone'],
        'Email: ' . ((string) ($row['email'] ?? '') !== '' ? (string) $row['email'] : 'не вказано'),
        'Канал відповіді: ' . $replyChannel,
        'Заїзд: ' . (string) $row['checkin_date'],
        'Виїзд: ' . (string) $row['checkout_date'],
        'Гості: ' . (string) $row['guests'],
        'Номер: ' . (string) $row['room_code'],
        'Статус: ' . (string) $row['status'],
        'Створено: ' . (string) $row['created_at'],
        'Коментар: ' . $message,
    ];

    return implode("\n", $lines);
}

function tg_send_booking_details(string $chatId, PDO $pdo, string $bookingId, bool $withActions = true, ?int $editMessageId = null): void
{
    $bookingId = trim($bookingId);
    if ($bookingId === '') {
        tg_send_message($chatId, 'Оберіть заявку зі списку нижче:');
        tg_send_bookings($chatId, $pdo, (string) TG_DEFAULT_LIST_LIMIT, 'booking');
        return;
    }

    $row = tg_booking_by_id($pdo, $bookingId);
    if (!$row) {
        tg_send_message($chatId, 'Бронювання не знайдено. Формат: BKYYYYMMDD-XXXXXX');
        return;
    }

    $messageText = tg_booking_details_text($row);
    $replyMarkup = $withActions ? tg_booking_actions_keyboard($row, $chatId) : null;

    if ($editMessageId !== null && tg_edit_message_text($chatId, $editMessageId, $messageText, $replyMarkup)) {
        return;
    }

    tg_send_message($chatId, $messageText, $replyMarkup);
}

function tg_send_reply_for_booking(string $chatId, PDO $pdo, Database $db, string $bookingId, string $replyText): void
{
    $bookingId = strtoupper(trim($bookingId));
    $replyText = sanitize_multiline_text($replyText, 2500);

    $booking = tg_booking_by_id($pdo, $bookingId);
    if (!$booking) {
        tg_send_message($chatId, 'Бронювання не знайдено.');
        return;
    }

    $email = tg_normalize_email((string) ($booking['email'] ?? ''));
    $phone = trim((string) ($booking['phone'] ?? ''));
    $phoneDial = tg_normalize_phone_dial($phone);

    if ($email === null && $phoneDial === null) {
        tg_reply_context_clear($chatId);
        tg_send_message($chatId, 'У заявки немає валідного email і телефону для відповіді.');
        return;
    }

    if ($email === null && $phoneDial !== null) {
        $manualLines = [
            'У заявки немає email. Підготувала текст для ручної відповіді:',
            'Заявка: ' . (string) $booking['booking_id'],
            'Телефон: ' . $phoneDial,
            '',
            'Текст:',
            $replyText,
        ];

        $manualKeyboard = [
            'inline_keyboard' => [
                [[
                    'text' => '📞 Подзвонити',
                    'url' => 'tel:' . $phoneDial,
                ]],
                [[
                    'text' => '⬅️ До списку заявок',
                    'callback_data' => 'apps:list',
                ]],
            ],
        ];

        tg_log_action(
            $db,
            'telegram_booking_reply_manual_phone',
            'booking_id=' . (string) $booking['booking_id'],
            $chatId
        );

        tg_reply_context_clear($chatId);
        tg_send_message($chatId, implode("\n", $manualLines), $manualKeyboard);
        return;
    }

    $subject = 'SvityazHOME: відповідь по бронюванню ' . (string) $booking['booking_id'];
    $body = implode("\n\n", [
        "Вітаємо, " . (string) ($booking['name'] ?? 'гостю') . "!",
        $replyText,
        'З повагою, команда SvityazHOME',
        'Телефон: +380938578540',
    ]);

    $result = tg_send_custom_email($email, $subject, $body, BOOKING_EMAIL_TO);
    if (!($result['sent'] ?? false)) {
        tg_send_message($chatId, 'Не вдалося надіслати email. Transport: ' . ($result['transport'] ?? 'unknown') . '.');
        return;
    }

    tg_log_action(
        $db,
        'telegram_booking_reply',
        'booking_id=' . (string) $booking['booking_id'] . '; transport=' . (string) ($result['transport'] ?? ''),
        $chatId
    );

    tg_reply_context_clear($chatId);
    tg_send_message($chatId, 'Відповідь по ' . (string) $booking['booking_id'] . ' відправлено на ' . $email . '.');
}

function tg_handle_reply_booking_command(string $chatId, PDO $pdo, Database $db, string $args): void
{
    [$bookingId, $replyText] = tg_parse_reply_args($args);

    if ($bookingId === '' && $replyText === '') {
        tg_send_message($chatId, 'Оберіть заявку для відповіді:');
        tg_send_bookings($chatId, $pdo, (string) TG_DEFAULT_LIST_LIMIT, 'replypick');
        return;
    }

    if ($bookingId !== '' && $replyText === '') {
        $booking = tg_booking_by_id($pdo, $bookingId);
        if (!$booking) {
            tg_send_message($chatId, 'Заявку не знайдено. Перевірте ID або виберіть зі списку.', tg_main_keyboard());
            return;
        }

        $email = tg_normalize_email((string) ($booking['email'] ?? ''));
        $phoneDial = tg_normalize_phone_dial((string) ($booking['phone'] ?? ''));
        if ($email === null && $phoneDial === null) {
            tg_send_message($chatId, 'У заявки немає валідного контакту для відповіді.', tg_main_keyboard());
            return;
        }

        tg_reply_context_set($chatId, $bookingId);
        $channel = $email !== null ? 'email' : 'телефон';
        tg_send_message(
            $chatId,
            'Заявку ' . $bookingId . " обрано (канал: {$channel}).\nТепер надішліть тільки текст відповіді (без ID).",
            tg_main_keyboard()
        );
        return;
    }

    tg_send_reply_for_booking($chatId, $pdo, $db, $bookingId, $replyText);
}

function tg_handle_add_review_command(string $chatId, Database $db, string $reviewsFile, array $allowedTopics, string $args): void
{
    $parts = array_map(static function ($value) {
        return trim((string) $value);
    }, explode('|', $args, 5));

    if (count($parts) < 4) {
        tg_send_message(
            $chatId,
            "Формат: /add_review Ім'я | 5 | topic | Текст | room_id\n" .
            "topic: rooms, territory, service, location, general"
        );
        return;
    }

    $payload = [
        'name' => $parts[0] ?? '',
        'rating' => $parts[1] ?? '',
        'topic' => $parts[2] ?? '',
        'text' => $parts[3] ?? '',
    ];
    if (isset($parts[4]) && $parts[4] !== '') {
        $payload['room_id'] = $parts[4];
    }

    $result = tg_add_approved_review($reviewsFile, $payload, $allowedTopics);
    tg_send_message($chatId, (string) ($result['message'] ?? 'Невідомий результат.'));

    if (!($result['ok'] ?? false)) {
        return;
    }

    $reviewId = (int) (($result['review']['id'] ?? 0));
    tg_log_action($db, 'telegram_review_add', 'id=' . $reviewId, $chatId);
}

function tg_handle_approve_command(string $chatId, Database $db, string $reviewsFile, int $id): void
{
    if ($id <= 0) {
        tg_send_message($chatId, 'Формат: /approve ID');
        return;
    }

    $result = tg_approve_review($reviewsFile, $id);
    tg_send_message($chatId, (string) ($result['message'] ?? 'Невідомий результат.'));

    if (!($result['ok'] ?? false)) {
        return;
    }

    tg_log_action($db, 'telegram_review_approve', 'id=' . $id, $chatId);
}

function tg_handle_reject_command(string $chatId, Database $db, string $reviewsFile, int $id): void
{
    if ($id <= 0) {
        tg_send_message($chatId, 'Формат: /reject ID');
        return;
    }

    $result = tg_reject_review($reviewsFile, $id);
    tg_send_message($chatId, (string) ($result['message'] ?? 'Невідомий результат.'));

    if (!($result['ok'] ?? false)) {
        return;
    }

    tg_log_action($db, 'telegram_review_reject', 'id=' . $id, $chatId);
}

function tg_handle_callback_query(array $callback, PDO $pdo, Database $db, string $reviewsFile): void
{
    $chatId = trim((string) ($callback['message']['chat']['id'] ?? ''));
    $callbackId = trim((string) ($callback['id'] ?? ''));
    $data = trim((string) ($callback['data'] ?? ''));
    $messageId = (int) ($callback['message']['message_id'] ?? 0);

    if (!tg_is_admin_chat($chatId)) {
        tg_answer_callback($callbackId, 'Доступ заборонено', true);
        return;
    }

    if (preg_match('/^(approve|reject):(\d+)$/', $data, $match) === 1) {
        $action = $match[1];
        $id = (int) $match[2];

        if ($action === 'approve') {
            $result = tg_approve_review($reviewsFile, $id);
            tg_answer_callback($callbackId, (string) ($result['message'] ?? 'OK'), false);
            tg_send_message($chatId, (string) ($result['message'] ?? 'OK'));
            if ($result['ok'] ?? false) {
                tg_log_action($db, 'telegram_review_approve', 'id=' . $id, $chatId);
                tg_remove_callback_keyboard($chatId, $messageId);
            }
            return;
        }

        $result = tg_reject_review($reviewsFile, $id);
        tg_answer_callback($callbackId, (string) ($result['message'] ?? 'OK'), false);
        tg_send_message($chatId, (string) ($result['message'] ?? 'OK'));
        if ($result['ok'] ?? false) {
            tg_log_action($db, 'telegram_review_reject', 'id=' . $id, $chatId);
            tg_remove_callback_keyboard($chatId, $messageId);
        }
        return;
    }

    if (preg_match('/^booking:(BK\d{8}-[A-Z0-9]{6})$/', $data, $match) === 1) {
        $bookingId = strtoupper($match[1]);
        tg_answer_callback($callbackId, 'Надсилаю кнопку для відкриття', false);
        tg_send_miniapp_entry($chatId, $bookingId);
        return;
    }

    if (preg_match('/^replypick:(BK\d{8}-[A-Z0-9]{6})$/', $data, $match) === 1) {
        $bookingId = strtoupper($match[1]);
        $booking = tg_booking_by_id($pdo, $bookingId);
        if (!$booking) {
            tg_answer_callback($callbackId, 'Заявку не знайдено', true);
            return;
        }

        $email = tg_normalize_email((string) ($booking['email'] ?? ''));
        $phoneDial = tg_normalize_phone_dial((string) ($booking['phone'] ?? ''));
        if ($email === null && $phoneDial === null) {
            tg_answer_callback($callbackId, 'Немає контактів для відповіді', true);
            return;
        }

        tg_reply_context_set($chatId, $bookingId);
        tg_answer_callback($callbackId, 'Напишіть текст відповіді', false);
        $channel = $email !== null ? 'email' : 'телефон';
        tg_send_message(
            $chatId,
            'Заявку ' . $bookingId . " обрано (канал: {$channel}).\nТепер надішліть тільки текст відповіді (без ID).",
            tg_main_keyboard()
        );
        return;
    }

    if (preg_match('/^changeroompick:(BK\d{8}-[A-Z0-9]{6})$/', $data, $match) === 1) {
        $bookingId = strtoupper($match[1]);
        $booking = tg_booking_by_id($pdo, $bookingId);
        if (!$booking) {
            tg_answer_callback($callbackId, 'Заявку не знайдено', true);
            return;
        }

        tg_change_room_context_set($chatId, $bookingId);
        tg_answer_callback($callbackId, 'Оберіть новий номер', false);
        tg_send_message(
            $chatId,
            "Заявку {$bookingId} обрано.\nОберіть новий номер кнопкою нижче або надішліть 1-20 текстом.",
            tg_room_select_keyboard($bookingId, (string) ($booking['room_code'] ?? ''))
        );
        return;
    }

    if ($data === 'apps:list') {
        tg_answer_callback($callbackId, 'Оновлено', false);
        tg_send_bookings($chatId, $pdo, (string) TG_DEFAULT_LIST_LIMIT, 'booking');
        return;
    }

    if ($data === 'reply:list') {
        tg_answer_callback($callbackId, 'Оновлено', false);
        tg_send_bookings($chatId, $pdo, (string) TG_DEFAULT_LIST_LIMIT, 'replypick');
        return;
    }

    if ($data === 'changeroom:list') {
        tg_answer_callback($callbackId, 'Оновлено', false);
        tg_send_bookings($chatId, $pdo, (string) TG_DEFAULT_LIST_LIMIT, 'changeroompick');
        return;
    }

    if ($data === 'arrivals:today') {
        tg_answer_callback($callbackId, 'Оновлено', false);
        tg_send_arrivals_bookings($chatId, $pdo, 'today', 'today');
        return;
    }

    if ($data === 'arrivals:tomorrow') {
        tg_answer_callback($callbackId, 'Оновлено', false);
        tg_send_arrivals_bookings($chatId, $pdo, 'tomorrow', 'tomorrow');
        return;
    }

    if ($data === 'departures:today') {
        tg_answer_callback($callbackId, 'Оновлено', false);
        tg_send_departures_bookings($chatId, $pdo, 'today', 'today');
        return;
    }

    if ($data === 'departures:tomorrow') {
        tg_answer_callback($callbackId, 'Оновлено', false);
        tg_send_departures_bookings($chatId, $pdo, 'tomorrow', 'tomorrow');
        return;
    }

    if (preg_match('/^changeroomset:(BK\d{8}-[A-Z0-9]{6}):(?:room-)?([1-9]|1[0-9]|20)$/', $data, $match) === 1) {
        $bookingId = strtoupper($match[1]);
        $roomRaw = trim((string) $match[2]);
        $result = tg_change_booking_room($pdo, $bookingId, $roomRaw);
        tg_answer_callback($callbackId, (string) ($result['message'] ?? 'OK'), !($result['ok'] ?? false));
        tg_send_message($chatId, (string) ($result['message'] ?? 'Невідомий результат.'));

        if ($result['ok'] ?? false) {
            tg_log_action(
                $db,
                'telegram_booking_change_room',
                'booking_id=' . $bookingId . '; room=' . (string) ($result['room_code'] ?? ''),
                $chatId
            );
            tg_change_room_context_clear($chatId);
            tg_send_booking_details($chatId, $pdo, $bookingId, true);
        }
        return;
    }

    if (preg_match('/^bookingstatus:(BK\d{8}-[A-Z0-9]{6}):(new|processed)$/', $data, $match) === 1) {
        $bookingId = strtoupper($match[1]);
        $targetStatus = strtolower($match[2]);
        $result = tg_update_booking_status($pdo, $bookingId, $targetStatus);
        tg_answer_callback($callbackId, (string) ($result['message'] ?? 'OK'), !($result['ok'] ?? false));
        tg_send_message($chatId, (string) ($result['message'] ?? 'Невідомий результат.'));

        if ($result['ok'] ?? false) {
            tg_log_action(
                $db,
                'telegram_booking_status_change',
                'booking_id=' . $bookingId . '; status=' . $targetStatus,
                $chatId
            );
            tg_send_booking_details($chatId, $pdo, $bookingId, true);
        }
        return;
    }

    tg_answer_callback($callbackId, 'Невідома дія', false);
}

function tg_handle_message(array $message, PDO $pdo, Database $db, string $reviewsFile, array $allowedTopics): void
{
    $chatId = trim((string) ($message['chat']['id'] ?? ''));
    $text = trim((string) ($message['text'] ?? ''));
    $messageId = (int) ($message['message_id'] ?? 0);

    if ($chatId === '') {
        return;
    }

    if ($text === '') {
        tg_send_message($chatId, "Натисніть кнопку «❓ Допомога» або «📋 Відгуки».", tg_main_keyboard(), $messageId);
        return;
    }

    [$command, $args] = tg_parse_command($text);
    if ($command === '') {
        [$command, $args] = tg_parse_human_command($text);
    }

    if ($command === '') {
        $selectedBookingId = tg_reply_context_get($chatId);
        if ($selectedBookingId !== null) {
            tg_send_reply_for_booking($chatId, $pdo, $db, $selectedBookingId, $text);
            return;
        }

        $changeRoomBookingId = tg_change_room_context_get($chatId);
        if ($changeRoomBookingId !== null) {
            $roomCode = tg_parse_room_code($text);
            if ($roomCode === null) {
                $booking = tg_booking_by_id($pdo, $changeRoomBookingId);
                tg_send_message(
                    $chatId,
                    'Надішліть число 1-20 або натисніть номер кнопкою нижче.',
                    tg_room_select_keyboard($changeRoomBookingId, (string) ($booking['room_code'] ?? ''))
                );
                return;
            }

            $result = tg_change_booking_room($pdo, $changeRoomBookingId, $roomCode);
            tg_send_message($chatId, (string) ($result['message'] ?? 'Невідомий результат.'));

            if ($result['ok'] ?? false) {
                tg_log_action(
                    $db,
                    'telegram_booking_change_room',
                    'booking_id=' . $changeRoomBookingId . '; room=' . (string) ($result['room_code'] ?? ''),
                    $chatId
                );
                tg_change_room_context_clear($chatId);
                tg_send_booking_details($chatId, $pdo, $changeRoomBookingId, true);
            }
            return;
        }

        if (preg_match('/^(?:#|№)?\s*(\d{1,2})$/u', $text, $match) === 1) {
            $index = (int) $match[1];
            $picked = tg_booking_pick_context_select($chatId, $index);
            if ($picked !== null) {
                $pickedBookingId = (string) ($picked['booking_id'] ?? '');
                $pickedMode = (string) ($picked['mode'] ?? 'booking');
                if ($pickedMode === 'replypick') {
                    tg_handle_reply_booking_command($chatId, $pdo, $db, $pickedBookingId);
                    return;
                }
                if ($pickedMode === 'changeroompick') {
                    tg_handle_change_room_command($chatId, $pdo, $db, $pickedBookingId);
                    return;
                }
                tg_send_booking_details($chatId, $pdo, $pickedBookingId, true);
                return;
            }
        }
    }

    if ($command === '') {
        tg_send_message(
            $chatId,
            "Не зрозуміла запит. Натисніть кнопку «❓ Допомога» або «📅 Заявки».",
            tg_main_keyboard(),
            $messageId
        );
        return;
    }

    if ($command === 'whoami') {
        $isAdmin = tg_is_admin_chat($chatId) ? 'так' : 'ні';
        $configured = tg_admin_list_configured() ? 'так' : 'ні';
        $passwordEnabled = tg_admin_access_password_enabled() ? 'так' : 'ні';
        $deviceAccess = tg_device_access_has($chatId) ? 'так' : 'ні';
        tg_send_message(
            $chatId,
            "Ваш chat_id: {$chatId}\nadmin_configured: {$configured}\nadmin_access: {$isAdmin}\npassword_enabled: {$passwordEnabled}\ndevice_session: {$deviceAccess}",
            tg_main_keyboard(),
            $messageId
        );
        return;
    }

    if ($command === 'login') {
        tg_handle_login_command($chatId, $db, $args);
        return;
    }

    if ($command === 'logout') {
        tg_handle_logout_command($chatId, $db);
        return;
    }

    if (!tg_admin_list_configured() && !tg_admin_access_password_enabled()) {
        tg_send_message(
            $chatId,
            "Бот ще не прив'язаний до адмін-акаунта.\n" .
            "Ваш chat_id: {$chatId}\n" .
            "Додайте його у TELEGRAM_ADMIN_CHAT_IDS в .env",
            null,
            $messageId
        );
        return;
    }

    if (!tg_is_admin_chat($chatId)) {
        if (tg_admin_access_password_enabled()) {
            tg_send_message($chatId, "Доступ заборонено.\nЩоб увійти з цього пристрою, надішліть: /login ваш_пароль", null, $messageId);
            return;
        }
        tg_send_message($chatId, 'Доступ заборонено. Цей бот працює тільки для admin chat_id.', null, $messageId);
        return;
    }

    switch ($command) {
        case 'start':
        case 'menu':
            tg_send_message($chatId, tg_menu_text(), tg_main_keyboard(), $messageId);
            return;

        case 'help':
            tg_send_message($chatId, tg_help_text(), tg_main_keyboard(), $messageId);
            return;

        case 'app':
            tg_send_miniapp_entry($chatId, trim($args) !== '' ? trim($args) : null, $messageId);
            return;

        case 'status':
            tg_send_message($chatId, tg_status_summary($pdo, $reviewsFile), null, $messageId);
            return;

        case 'today':
            tg_send_message($chatId, tg_today_summary($pdo, $reviewsFile), null, $messageId);
            return;

        case 'tomorrow':
            tg_send_message($chatId, tg_tomorrow_summary($pdo), null, $messageId);
            return;

        case 'arrivals':
            tg_send_arrivals_bookings($chatId, $pdo, $args, 'today');
            return;

        case 'arrivals_today':
            tg_send_arrivals_bookings($chatId, $pdo, 'today', 'today');
            return;

        case 'arrivals_tomorrow':
            tg_send_arrivals_bookings($chatId, $pdo, 'tomorrow', 'tomorrow');
            return;

        case 'departures':
            tg_send_departures_bookings($chatId, $pdo, $args, 'today');
            return;

        case 'departures_today':
            tg_send_departures_bookings($chatId, $pdo, 'today', 'today');
            return;

        case 'availability':
            tg_send_room_availability_check($chatId, $pdo, $args);
            return;

        case 'free_rooms':
            tg_send_free_rooms_for_range($chatId, $pdo, $args);
            return;

        case 'actions':
            tg_send_recent_admin_actions($chatId, $pdo, $args);
            return;

        case 'pending':
        case 'reviews_pending':
            tg_send_pending_reviews($chatId, $args, $reviewsFile);
            return;

        case 'approve':
            tg_handle_approve_command($chatId, $db, $reviewsFile, (int) trim($args));
            return;

        case 'reject':
        case 'delete':
            tg_handle_reject_command($chatId, $db, $reviewsFile, (int) trim($args));
            return;

        case 'add_review':
            tg_handle_add_review_command($chatId, $db, $reviewsFile, $allowedTopics, $args);
            return;

        case 'bookings':
            tg_send_bookings($chatId, $pdo, $args, 'booking');
            return;

        case 'latest':
        case 'latest_booking':
            tg_send_latest_booking($chatId, $pdo);
            return;

        case 'find':
        case 'search':
        case 'find_booking':
            tg_send_booking_search_results($chatId, $pdo, $args);
            return;

        case 'booking':
            if (trim($args) === '') {
                tg_send_bookings($chatId, $pdo, (string) TG_DEFAULT_LIST_LIMIT, 'booking');
                return;
            }
            tg_send_booking_details($chatId, $pdo, trim($args));
            return;

        case 'reply':
        case 'reply_booking':
            tg_handle_reply_booking_command($chatId, $pdo, $db, $args);
            return;

        case 'cancel_reply':
            tg_reply_context_clear($chatId);
            tg_change_room_context_clear($chatId);
            tg_booking_pick_context_clear($chatId);
            tg_send_message($chatId, 'Поточну дію скасовано. Можна знову натиснути «📅 Заявки».', tg_main_keyboard());
            return;

        case 'change_room':
            tg_change_room_context_clear($chatId);
            if (trim($args) === '') {
                tg_send_bookings($chatId, $pdo, (string) TG_DEFAULT_LIST_LIMIT, 'changeroompick');
                return;
            }
            tg_handle_change_room_command($chatId, $pdo, $db, $args);
            return;

        default:
            tg_send_message($chatId, "Невідома команда.\n\n" . tg_help_text(), null, $messageId);
            return;
    }
}

function tg_process_update_payload(?array $update, PDO $pdo, Database $db, string $reviewsFile, array $allowedTopics, bool $webhookMode = true): array
{
    tg_set_webhook_update_type(null);
    tg_take_direct_response();

    if (!is_array($update)) {
        return ['success' => true, 'handled' => false, 'reason' => 'empty_payload'];
    }

    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        if ($webhookMode) {
            tg_set_webhook_update_type('callback_query');
        }
        tg_handle_callback_query($update['callback_query'], $pdo, $db, $reviewsFile);
        $directPayload = $webhookMode ? tg_take_direct_response() : null;
        tg_set_webhook_update_type(null);
        return is_array($directPayload)
            ? $directPayload
            : ['success' => true, 'handled' => true, 'type' => 'callback_query'];
    }

    if (isset($update['message']) && is_array($update['message'])) {
        if ($webhookMode) {
            tg_set_webhook_update_type('message');
        }
        tg_handle_message($update['message'], $pdo, $db, $reviewsFile, $allowedTopics);
        $directPayload = $webhookMode ? tg_take_direct_response() : null;
        tg_set_webhook_update_type(null);
        return is_array($directPayload)
            ? $directPayload
            : ['success' => true, 'handled' => true, 'type' => 'message'];
    }

    return ['success' => true, 'handled' => false, 'reason' => 'unsupported_update'];
}

if (!defined('SVH_TG_LIBRARY_ONLY') || SVH_TG_LIBRARY_ONLY !== true) {
    if ($method === 'GET') {
        json_response([
            'success' => true,
            'service' => 'telegram-webhook',
            'configured' => TELEGRAM_BOT_TOKEN !== '',
            'admin_configured' => tg_admin_list_configured(),
            'admin_chats' => count(tg_admin_chat_ids()),
            'webhook_secret_enabled' => TELEGRAM_WEBHOOK_SECRET !== '',
        ]);
    }

    if ($method !== 'POST') {
        error_response('Method not allowed', 405);
    }

    if (TELEGRAM_BOT_TOKEN === '') {
        error_response('Telegram bot token is not configured', 503);
    }

    $headers = tg_request_headers();
    if (!tg_webhook_secret_is_valid($headers)) {
        tg_debug_log('telegram webhook forbidden', [
            'request_id' => tg_debug_request_id(),
            'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        error_response('Forbidden', 403);
    }

    register_shutdown_function(static function (): void {
        $lastError = error_get_last();
        if (!is_array($lastError)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        tg_debug_log('telegram webhook shutdown fatal', [
            'request_id' => tg_debug_request_id(),
            'type' => (int) ($lastError['type'] ?? 0),
            'message' => (string) ($lastError['message'] ?? ''),
            'file' => (string) ($lastError['file'] ?? ''),
            'line' => (int) ($lastError['line'] ?? 0),
        ]);
    });

    try {
        $update = tg_read_update_payload();
        $summary = ['request_id' => tg_debug_request_id()];
        if (is_array($update)) {
            $summary['update_id'] = (int) ($update['update_id'] ?? 0);
            if (isset($update['message']) && is_array($update['message'])) {
                $summary['kind'] = 'message';
                $summary['chat_id'] = (string) (($update['message']['chat']['id'] ?? ''));
                $summary['text'] = mb_substr(trim((string) ($update['message']['text'] ?? '')), 0, 120);
            } elseif (isset($update['callback_query']) && is_array($update['callback_query'])) {
                $summary['kind'] = 'callback_query';
                $summary['chat_id'] = (string) (($update['callback_query']['message']['chat']['id'] ?? ''));
                $summary['data'] = mb_substr(trim((string) ($update['callback_query']['data'] ?? '')), 0, 120);
            } else {
                $summary['kind'] = 'unsupported';
            }
        } else {
            $summary['kind'] = 'empty';
        }
        tg_debug_log('telegram webhook request', $summary);

        $response = tg_process_update_payload($update, $pdo, $db, $REVIEWS_FILE, $REVIEW_TOPICS, true);
        tg_debug_log('telegram webhook response', [
            'request_id' => tg_debug_request_id(),
            'response_keys' => array_keys($response),
        ]);
        json_response($response);
    } catch (Throwable $e) {
        error_log('Telegram webhook fatal: ' . $e->getMessage());
        tg_debug_log('telegram webhook exception', [
            'request_id' => tg_debug_request_id(),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        error_response('Webhook processing failed', 503);
    }
}

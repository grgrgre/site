<?php
/**
 * Telegram Mini App API for SvityazHOME booking operations.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/booking-workflow.php';

if (send_api_headers(['POST', 'OPTIONS'])) {
    exit;
}

initStorage();
$db = Database::getInstance();
$pdo = $db->getPdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    error_response('Method not allowed', 405);
}

function tma_debug_log(string $message, array $context = []): void
{
    $line = '[' . gmdate('c') . '] ' . $message;
    if (!empty($context)) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            $line .= ' ' . $json;
        }
    }

    $logFile = rtrim(STORAGE_LOGS_DIR, '/\\') . DIRECTORY_SEPARATOR . 'telegram-miniapp.log';
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function tma_parse_init_data(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $result = [];
    foreach (explode('&', $raw) as $pair) {
        if ($pair === '') {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $key = urldecode($key);
        if ($key === '') {
            continue;
        }

        $result[$key] = urldecode($value);
    }

    return $result;
}

function tma_validate_init_data(string $raw): array
{
    if (TELEGRAM_BOT_TOKEN === '') {
        tma_debug_log('validate: missing bot token');
        error_response('Telegram bot token is not configured', 503);
    }

    $payload = tma_parse_init_data($raw);
    $hash = strtolower(trim((string) ($payload['hash'] ?? '')));
    if ($hash === '') {
        tma_debug_log('validate: missing hash');
        error_response('Missing Telegram signature', 401);
    }

    unset($payload['hash'], $payload['signature']);
    if (empty($payload)) {
        tma_debug_log('validate: empty payload after hash remove');
        error_response('Telegram auth payload is empty', 401);
    }

    ksort($payload, SORT_STRING);
    $check = [];
    foreach ($payload as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $check[] = $key . '=' . (string) $value;
    }

    $dataCheckString = implode("\n", $check);
    $secretKey = hash_hmac('sha256', TELEGRAM_BOT_TOKEN, 'WebAppData', true);
    $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
    if (!hash_equals($expectedHash, $hash)) {
        tma_debug_log('validate: invalid hash', [
            'hash' => $hash,
            'expected' => $expectedHash,
            'keys' => array_keys($payload),
        ]);
        error_response('Invalid Telegram signature', 401);
    }

    $authDate = (int) ($payload['auth_date'] ?? 0);
    if ($authDate <= 0 || abs(time() - $authDate) > 86400) {
        tma_debug_log('validate: expired session', [
            'auth_date' => $authDate,
            'server_time' => time(),
        ]);
        error_response('Telegram session expired', 401);
    }

    $userRaw = (string) ($payload['user'] ?? '');
    $user = json_decode($userRaw, true);
    if (!is_array($user)) {
        tma_debug_log('validate: missing user object');
        error_response('Telegram user is missing', 401);
    }

    return [
        'user' => $user,
        'auth_date' => $authDate,
        'query_id' => trim((string) ($payload['query_id'] ?? '')),
        'start_param' => trim((string) ($payload['start_param'] ?? '')),
    ];
}

function tma_validate_access_token(string $raw): ?array
{
    $payload = telegram_miniapp_access_token_validate($raw);
    if (!is_array($payload)) {
        return null;
    }

    return [
        'user' => [
            'id' => trim((string) ($payload['chat_id'] ?? '')),
            'first_name' => '',
            'last_name' => '',
            'username' => '',
        ],
        'auth_date' => (int) ($payload['iat'] ?? time()),
        'query_id' => '',
        'start_param' => 'access_token',
    ];
}

function tma_has_device_access(string $chatId): bool
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        return false;
    }

    $path = DATA_DIR . 'telegram-admin-access.json';
    if (!is_file($path)) {
        return false;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return false;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return false;
    }

    $row = $decoded[$chatId] ?? null;
    if (!is_array($row)) {
        return false;
    }

    return (int) ($row['expires_at'] ?? 0) > time();
}

function tma_require_admin_user(array $auth): string
{
    $userId = trim((string) ($auth['user']['id'] ?? ''));
    if ($userId === '') {
        tma_debug_log('auth: user id missing');
        error_response('Telegram user is missing', 401);
    }

    if (in_array($userId, telegram_admin_chat_ids(), true) || tma_has_device_access($userId)) {
        return $userId;
    }

    tma_debug_log('auth: access denied', [
        'user_id' => $userId,
        'admin_ids' => telegram_admin_chat_ids(),
    ]);
    error_response('Telegram access denied', 403);
}

function tma_room_label(string $roomCode): string
{
    $catalog = svh_room_catalog();
    $room = $catalog[$roomCode] ?? null;
    if (is_array($room) && trim((string) ($room['title'] ?? '')) !== '') {
        return trim((string) $room['title']);
    }

    return $roomCode !== '' ? $roomCode : 'Номер не вказано';
}

function tma_status_label(string $status): string
{
    $normalized = strtolower(trim($status));
    $map = [
        'new' => 'Нова',
        'new_email_failed' => 'Нова',
        'waiting' => 'Очікує',
        'confirmed' => 'Підтверджена',
        'processed' => 'Оброблена',
        'cancelled' => 'Скасована',
        'rejected' => 'Відхилена',
        'done' => 'Завершена',
    ];

    return $map[$normalized] ?? ($normalized !== '' ? $normalized : '—');
}

function tma_status_tone(string $status): string
{
    $normalized = strtolower(trim($status));
    if (in_array($normalized, ['new', 'new_email_failed'], true)) {
        return 'new';
    }
    if ($normalized === 'processed' || $normalized === 'done') {
        return 'done';
    }
    if ($normalized === 'confirmed') {
        return 'confirmed';
    }
    if ($normalized === 'waiting') {
        return 'waiting';
    }

    return 'muted';
}

function tma_safe_summary(string $value, int $max = 160): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($value, 'UTF-8') <= $max) {
        return $value;
    }

    return rtrim(mb_substr($value, 0, max(16, $max - 1), 'UTF-8')) . '…';
}

function tma_like_escape(string $value): string
{
    return strtr($value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_',
    ]);
}

function tma_booking_summary(array $row): array
{
    $bookingId = strtoupper(trim((string) ($row['booking_id'] ?? '')));
    $status = trim((string) ($row['status'] ?? ''));

    return [
        'booking_id' => $bookingId,
        'name' => trim((string) ($row['name'] ?? 'Гість')),
        'phone' => trim((string) ($row['phone'] ?? '')),
        'email' => trim((string) ($row['email'] ?? '')),
        'checkin_date' => trim((string) ($row['checkin_date'] ?? '')),
        'checkout_date' => trim((string) ($row['checkout_date'] ?? '')),
        'guests' => (int) ($row['guests'] ?? 0),
        'room_code' => trim((string) ($row['room_code'] ?? '')),
        'room_label' => tma_room_label(trim((string) ($row['room_code'] ?? ''))),
        'status' => $status,
        'status_label' => tma_status_label($status),
        'status_tone' => tma_status_tone($status),
        'created_at' => trim((string) ($row['created_at'] ?? '')),
        'preview' => tma_safe_summary((string) ($row['message'] ?? ''), 110),
    ];
}

function tma_booking_detail(array $row): array
{
    $summary = tma_booking_summary($row);
    $bookingId = (string) ($summary['booking_id'] ?? '');
    $phone = trim((string) ($row['phone'] ?? ''));
    $email = trim((string) ($row['email'] ?? ''));

    $summary['message'] = trim((string) ($row['message'] ?? ''));
    $summary['admin_url'] = rtrim(SITE_URL, '/') . '/svh-ctrl-x7k9/requests.php#booking-' . rawurlencode($bookingId);
    $summary['tel_url'] = $phone !== '' ? 'tel:' . preg_replace('/[^\d+]/', '', $phone) : '';
    $summary['mailto_url'] = $email !== '' ? 'mailto:' . rawurlencode($email) : '';
    $summary['can_mark_processed'] = in_array(strtolower((string) ($row['status'] ?? '')), ['new', 'new_email_failed'], true);
    $summary['can_mark_new'] = strtolower((string) ($row['status'] ?? '')) === 'processed';

    return $summary;
}

function tma_fetch_bookings(PDO $pdo, int $limit = 40, string $query = ''): array
{
    $limit = max(1, min(60, $limit));
    $query = trim($query);

    $sql = "
        SELECT booking_id, name, phone, email, checkin_date, checkout_date, guests, room_code, message, status, created_at
        FROM bookings
        WHERE honeypot_triggered = 0
    ";
    $params = [];

    if ($query !== '') {
        $lower = '%' . tma_like_escape(mb_strtolower($query, 'UTF-8')) . '%';
        $upper = '%' . tma_like_escape(mb_strtoupper($query, 'UTF-8')) . '%';
        $sql .= "
          AND (
            UPPER(booking_id) LIKE :query_upper ESCAPE '\\'
            OR LOWER(name) LIKE :query_lower ESCAPE '\\'
            OR LOWER(phone) LIKE :query_lower ESCAPE '\\'
            OR LOWER(email) LIKE :query_lower ESCAPE '\\'
          )
        ";
        $params[':query_upper'] = $upper;
        $params[':query_lower'] = $lower;
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN status IN ('new','new_email_failed') THEN 0
                WHEN status = 'waiting' THEN 1
                WHEN status = 'confirmed' THEN 2
                WHEN status = 'processed' THEN 3
                ELSE 4
            END ASC,
            datetime(created_at) DESC,
            id DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function tma_fetch_booking(PDO $pdo, string $bookingId): ?array
{
    $bookingId = strtoupper(trim($bookingId));
    if (preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT booking_id, name, phone, email, checkin_date, checkout_date, guests, room_code, message, status, created_at
        FROM bookings
        WHERE booking_id = :booking_id
        LIMIT 1
    ");
    $stmt->execute([':booking_id' => $bookingId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function tma_fetch_counts(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN status IN ('new','new_email_failed') THEN 1 ELSE 0 END) AS new_count
        FROM bookings
        WHERE honeypot_triggered = 0
    ");
    $row = $stmt->fetch() ?: [];

    return [
        'total' => (int) ($row['total_count'] ?? 0),
        'new' => (int) ($row['new_count'] ?? 0),
    ];
}

function tma_update_booking_status(PDO $pdo, string $bookingId, string $status): ?array
{
    $bookingId = strtoupper(trim($bookingId));
    $status = strtolower(trim($status));
    if (preg_match('/^BK\d{8}-[A-Z0-9]{6}$/', $bookingId) !== 1) {
        return null;
    }
    if (!in_array($status, ['new', 'processed'], true)) {
        return null;
    }

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET status = :status
        WHERE booking_id = :booking_id
    ");
    $ok = $stmt->execute([
        ':status' => $status,
        ':booking_id' => $bookingId,
    ]);

    if (!$ok) {
        return null;
    }

    return tma_fetch_booking($pdo, $bookingId);
}

$input = read_input_payload();
$initDataRaw = (string) ($input['init_data'] ?? '');
$accessTokenRaw = trim((string) ($input['access_token'] ?? ($_GET['access_token'] ?? '')));
tma_debug_log('request', [
    'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    'action' => (string) ($input['action'] ?? ''),
    'init_data_len' => strlen($initDataRaw),
    'access_token_len' => strlen($accessTokenRaw),
    'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
]);
$authMethod = '';
$auth = null;

if ($accessTokenRaw !== '') {
    $auth = tma_validate_access_token($accessTokenRaw);
    if (is_array($auth)) {
        $authMethod = 'access_token';
    } else {
        tma_debug_log('auth: invalid access token', [
            'token_len' => strlen($accessTokenRaw),
            'has_init_data' => trim($initDataRaw) !== '',
        ]);
    }
}

if (!is_array($auth)) {
    if (trim($initDataRaw) === '') {
        tma_debug_log('auth: missing init_data and access_token');
        error_response('Missing Telegram authentication data', 401);
    }
    $auth = tma_validate_init_data($initDataRaw);
    $authMethod = 'init_data';
}

$adminUserId = tma_require_admin_user($auth);
tma_debug_log('auth: resolved', [
    'method' => $authMethod,
    'user_id' => $adminUserId,
]);
$action = strtolower(trim((string) ($input['action'] ?? 'bootstrap')));

switch ($action) {
    case 'bootstrap':
        $selectedBookingId = strtoupper(trim((string) ($input['booking_id'] ?? '')));
        $query = trim((string) ($input['query'] ?? ''));
        $rows = tma_fetch_bookings($pdo, (int) ($input['limit'] ?? 40), $query);
        $active = $selectedBookingId !== '' ? tma_fetch_booking($pdo, $selectedBookingId) : null;
        if ($active === null && !empty($rows)) {
            $active = tma_fetch_booking($pdo, (string) ($rows[0]['booking_id'] ?? ''));
        }

        tma_debug_log('bootstrap: success', [
            'user_id' => $adminUserId,
            'rows' => count($rows),
            'active' => is_array($active),
        ]);
        json_response([
            'success' => true,
            'viewer' => [
                'id' => trim((string) ($auth['user']['id'] ?? '')),
                'first_name' => trim((string) ($auth['user']['first_name'] ?? '')),
                'last_name' => trim((string) ($auth['user']['last_name'] ?? '')),
                'username' => trim((string) ($auth['user']['username'] ?? '')),
            ],
            'counts' => tma_fetch_counts($pdo),
            'bookings' => array_map('tma_booking_summary', $rows),
            'active_booking' => is_array($active) ? tma_booking_detail($active) : null,
        ]);
        break;

    case 'booking':
        $bookingId = strtoupper(trim((string) ($input['booking_id'] ?? '')));
        $row = tma_fetch_booking($pdo, $bookingId);
        if (!is_array($row)) {
            tma_debug_log('booking: not found', [
                'user_id' => $adminUserId,
                'booking_id' => $bookingId,
            ]);
            error_response('Booking not found', 404);
        }

        tma_debug_log('booking: success', [
            'user_id' => $adminUserId,
            'booking_id' => $bookingId,
        ]);
        json_response([
            'success' => true,
            'booking' => tma_booking_detail($row),
        ]);
        break;

    case 'set_status':
        $bookingId = strtoupper(trim((string) ($input['booking_id'] ?? '')));
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $updated = tma_update_booking_status($pdo, $bookingId, $status);
        if (!is_array($updated)) {
            tma_debug_log('set_status: failed', [
                'user_id' => $adminUserId,
                'booking_id' => $bookingId,
                'status' => $status,
            ]);
            error_response('Failed to update booking status', 400);
        }

        $db->logAdminAction(
            'telegram_miniapp_booking_status',
            'booking_id=' . $bookingId . '; status=' . $status,
            'telegram-miniapp:' . $adminUserId
        );

        tma_debug_log('set_status: success', [
            'user_id' => $adminUserId,
            'booking_id' => $bookingId,
            'status' => $status,
        ]);
        json_response([
            'success' => true,
            'message' => $status === 'processed' ? 'Заявку позначено як оброблену.' : 'Заявку повернуто в нові.',
            'counts' => tma_fetch_counts($pdo),
            'booking' => tma_booking_detail($updated),
        ]);
        break;

    default:
        tma_debug_log('request: invalid action', [
            'action' => $action,
            'user_id' => $adminUserId,
        ]);
        error_response('Invalid action', 400);
}

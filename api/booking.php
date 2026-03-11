<?php
/**
 * SvityazHOME booking API endpoint.
 * Accepts JSON requests and sends booking notifications to booking@svityazhome.com.ua.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (send_api_headers(['GET', 'POST', 'OPTIONS'])) {
    exit;
}

initStorage();
$db = Database::getInstance();
$pdo = $db->getPdo();
$ip = get_client_ip();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Booking-specific JSON success helper.
 */
function booking_success(array $payload = [], int $status = 200): void
{
    svh_respond_success($payload, (string) ($payload['message'] ?? ''), $status, array_merge([
        'ok' => true,
    ], $payload));
}

/**
 * Booking-specific JSON error helper.
 */
function booking_error(string $message, int $status = 400, array $payload = []): void
{
    svh_respond_error($message, $status, $payload, array_merge([
        'ok' => false,
        'error' => $message,
    ], $payload));
}

/**
 * Policy payload shared by booking form.
 */
function booking_policy_payload(): array
{
    return [
        'checkin' => POLICY_CHECKIN_TIME,
        'checkout' => POLICY_CHECKOUT_TIME,
        'prepayment' => POLICY_PREPAYMENT,
        'dev_mode' => SITE_DEV_MODE,
        'booking' => [
            'max_guests' => BOOKING_MAX_GUESTS,
            'min_submit_seconds' => BOOKING_MIN_FORM_SECONDS,
        ],
    ];
}

/**
 * Return all headers in a resilient way.
 */
function booking_request_headers(): array
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

/**
 * Case-insensitive header getter.
 */
function booking_header(array $headers, string $name): string
{
    foreach ($headers as $key => $value) {
        if (strcasecmp((string) $key, $name) === 0) {
            return trim((string) $value);
        }
    }
    return '';
}

/**
 * CSRF validation for booking endpoint (returns HTTP 400 on failure).
 */
function booking_require_csrf(): void
{
    start_api_session();

    $headers = booking_request_headers();
    $token = booking_header($headers, 'X-CSRF-Token');
    if ($token === '') {
        booking_error('Missing CSRF token', 400);
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $expiresAt = (int) ($_SESSION['csrf_token_expires'] ?? 0);

    if (!is_string($sessionToken) || $sessionToken === '' || time() >= $expiresAt) {
        booking_error('Expired CSRF token', 400);
    }

    if (!hash_equals($sessionToken, $token)) {
        booking_error('Invalid CSRF token', 400);
    }
}

/**
 * Parse strict YYYY-MM-DD date.
 */
function booking_parse_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false) {
        return null;
    }
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }
    if ($date->format('Y-m-d') !== $value) {
        return null;
    }
    return $date;
}

/**
 * Prevent header injection in email headers.
 */
function booking_clean_header_value(string $value): string
{
    $clean = preg_replace('/[\r\n]+/', ' ', $value) ?? '';
    $clean = trim($clean);
    return mb_substr($clean, 0, 190);
}

/**
 * Validate and normalize email for Reply-To.
 */
function booking_normalize_email(string $value): ?string
{
    $email = booking_clean_header_value(trim($value));
    if ($email === '') {
        return null;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $email;
}

function booking_short_text(string $value, int $max = 220): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, max(20, $max - 1))) . '…';
}

/**
 * Heuristic check for obvious gibberish/nonsense text.
 */
function booking_contains_gibberish(string $value): bool
{
    $text = mb_strtolower(trim($value), 'UTF-8');
    if (mb_strlen($text, 'UTF-8') < 8) {
        return false;
    }

    $normalized = preg_replace('/[^a-zа-яіїєґ\s]+/iu', ' ', $text) ?? '';
    $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? '';
    if (mb_strlen($normalized, 'UTF-8') < 8) {
        return false;
    }

    if (preg_match('/(.)\1{5,}/u', $normalized)) {
        return true;
    }

    $compact = preg_replace('/\s+/u', '', $normalized) ?? '';
    $compactLength = mb_strlen($compact, 'UTF-8');
    if ($compactLength === 0) {
        return false;
    }

    $chars = preg_split('//u', $compact, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $uniqueChars = count(array_unique($chars));
    $uniqueRatio = $uniqueChars / max(1, $compactLength);

    $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $longNoVowelTokens = 0;
    $tokenCounts = [];

    foreach ($tokens as $token) {
        if (mb_strlen($token, 'UTF-8') >= 6 && !preg_match('/[aeiouyаеиіоуюяєіїґ]/iu', $token)) {
            $longNoVowelTokens++;
        }
        $tokenCounts[$token] = ($tokenCounts[$token] ?? 0) + 1;
    }

    foreach ($tokenCounts as $token => $count) {
        if ($count >= 3 && mb_strlen($token, 'UTF-8') >= 3) {
            return true;
        }
    }

    if ($longNoVowelTokens >= 2) {
        return true;
    }

    return $compactLength >= 16 && $uniqueRatio < 0.24;
}

/**
 * Resolve room capacity from local room JSON data.
 */
function booking_room_capacity(string $roomCode): ?int
{
    if (!preg_match('/^room-(\d{1,2})$/', $roomCode, $match)) {
        return null;
    }

    $roomId = (int) $match[1];
    if ($roomId < 1 || $roomId > 20) {
        return null;
    }

    $fallbackCapacities = [
        1 => 3,
        2 => 3,
        3 => 4,
        4 => 2,
        5 => 4,
        6 => 4,
        7 => 4,
        8 => 4,
        9 => 6,
        10 => 6,
        11 => 4,
        12 => 6,
        13 => 8,
        14 => 2,
        15 => 2,
        16 => 2,
        17 => 3,
        18 => 3,
        19 => 6,
        20 => 6,
    ];

    $roomFile = DATA_DIR . 'rooms/room-' . $roomId . '.json';
    if (!is_file($roomFile)) {
        return $fallbackCapacities[$roomId] ?? null;
    }

    $raw = file_get_contents($roomFile);
    if ($raw === false || $raw === '') {
        return $fallbackCapacities[$roomId] ?? null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $fallbackCapacities[$roomId] ?? null;
    }

    $capacity = (int) ($decoded['capacity'] ?? $decoded['guests'] ?? 0);
    if ($capacity < 1 || $capacity > 20) {
        return $fallbackCapacities[$roomId] ?? null;
    }

    return $capacity;
}

/**
 * Generate booking ID: BKYYYYMMDD-XXXXXX
 */
function booking_generate_id(): string
{
    return 'BK' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Build plain-text email body for booking notification.
 */
function booking_email_body(array $booking, string $ip, string $userAgent): string
{
    $lines = [
        'Нова заявка на бронювання SvityazHOME',
        'Booking ID: ' . ($booking['booking_id'] ?? ''),
        'Дата заявки: ' . date('c'),
        '',
        'Ім\'я: ' . ($booking['name'] ?? ''),
        'Телефон: ' . ($booking['phone'] ?? ''),
        'Email: ' . (($booking['email'] ?? '') !== '' ? $booking['email'] : 'не вказано'),
        'Заїзд: ' . ($booking['checkin_date'] ?? ''),
        'Виїзд: ' . ($booking['checkout_date'] ?? ''),
        'Гості: ' . ($booking['guests'] ?? ''),
        'Номер: ' . ($booking['room_code'] ?? ''),
        'Коментар:',
        (string) ($booking['message'] ?? ''),
        '',
        'IP: ' . $ip,
        'User-Agent: ' . $userAgent,
    ];

    return implode("\n", $lines);
}

/**
 * Append outgoing email payload to log file (transport=log).
 */
function booking_log_email_copy(string $subject, string $body): bool
{
    $logFile = rtrim(STORAGE_LOGS_DIR, '/\\') . DIRECTORY_SEPARATOR . 'booking-mail.log';
    $record = "=== " . date('c') . " ===\n" . $subject . "\n\n" . $body . "\n\n";
    return file_put_contents($logFile, $record, FILE_APPEND | LOCK_EX) !== false;
}

/**
 * Try loading PHPMailer from common autoload locations.
 */
function booking_load_phpmailer(): bool
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

/**
 * Send booking email. SMTP via PHPMailer is preferred; mail() is fallback.
 */
function booking_send_email(array $booking, string $ip, string $userAgent): array
{
    $subject = 'SvityazHOME бронювання: ' . ($booking['booking_id'] ?? '');
    $body = booking_email_body($booking, $ip, $userAgent);
    $replyTo = booking_normalize_email((string) ($booking['email'] ?? ''));
    $fromEmail = booking_normalize_email(SMTP_FROM) ?: BOOKING_EMAIL_TO;
    $fromName = booking_clean_header_value(SMTP_FROM_NAME);
    if ($fromName === '') {
        $fromName = 'SvityazHOME';
    }

    if (BOOKING_EMAIL_MODE === 'log') {
        $saved = booking_log_email_copy($subject, $body);
        return [
            'sent' => $saved,
            'transport' => 'log',
            'error' => $saved ? null : 'Failed to write email log',
        ];
    }

    $smtpConfigured = SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== '' && SMTP_PORT > 0 && $fromEmail !== '';
    $trySmtp = (BOOKING_EMAIL_MODE === 'auto' || BOOKING_EMAIL_MODE === 'smtp') && $smtpConfigured;

    if ($trySmtp && booking_load_phpmailer()) {
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
            $mail->addAddress(BOOKING_EMAIL_TO);
            if ($replyTo !== null) {
                $mail->addReplyTo($replyTo);
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
            error_log('Booking SMTP send failed: ' . $e->getMessage());
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
        'From: ' . booking_clean_header_value($fromName) . ' <' . $fromEmail . '>',
    ];

    if ($replyTo !== null) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $sent = @mail(BOOKING_EMAIL_TO, $encodedSubject, $body, implode("\r\n", $headers));

    if (!$sent && BOOKING_EMAIL_MODE === 'auto') {
        $saved = booking_log_email_copy($subject, $body);
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

/**
 * Store booking entry in SQLite.
 */
function booking_store(PDO $pdo, array $booking): void
{
    $stmt = $pdo->prepare("
        INSERT INTO bookings (
            booking_id, name, phone, email, checkin_date, checkout_date, guests,
            room_code, message, consent, honeypot_triggered, ip_address, user_agent,
            email_sent, email_transport, email_error, status
        ) VALUES (
            :booking_id, :name, :phone, :email, :checkin_date, :checkout_date, :guests,
            :room_code, :message, :consent, :honeypot_triggered, :ip_address, :user_agent,
            :email_sent, :email_transport, :email_error, :status
        )
    ");

    $stmt->execute([
        ':booking_id' => $booking['booking_id'],
        ':name' => $booking['name'],
        ':phone' => $booking['phone'],
        ':email' => $booking['email'],
        ':checkin_date' => $booking['checkin_date'],
        ':checkout_date' => $booking['checkout_date'],
        ':guests' => $booking['guests'],
        ':room_code' => $booking['room_code'],
        ':message' => $booking['message'],
        ':consent' => $booking['consent'] ? 1 : 0,
        ':honeypot_triggered' => $booking['honeypot_triggered'] ? 1 : 0,
        ':ip_address' => $booking['ip_address'],
        ':user_agent' => $booking['user_agent'],
        ':email_sent' => $booking['email_sent'] ? 1 : 0,
        ':email_transport' => $booking['email_transport'],
        ':email_error' => $booking['email_error'],
        ':status' => $booking['status'],
    ]);
}

if ($method === 'GET') {
    $action = strtolower(svh_query_string('action', 32, 'csrf'));
    if ($action !== 'csrf') {
        booking_error('Invalid action', 400);
    }

    start_api_session();
    $_SESSION['booking_form_issued_at'] = time();

    booking_success([
        'csrf_token' => get_csrf_token(),
        'policy' => booking_policy_payload(),
        'message' => 'Booking form session initialized',
    ]);
}

if ($method !== 'POST') {
    booking_error('Method not allowed', 405);
}

try {
    svh_require_json_request();

    $input = read_input_payload();
    if (!is_array($input)) {
        booking_error('Invalid JSON payload', 400);
    }

    booking_require_csrf();
    enforce_rate_limit($db, $ip, 'booking_submit_burst', RATE_LIMIT_BOOKING_BURST, 600);
    enforce_rate_limit($db, $ip, 'booking_submit_hourly', RATE_LIMIT_BOOKING_HOURLY, 3600);

    start_api_session();
    $issuedAt = (int) ($_SESSION['booking_form_issued_at'] ?? 0);
    if ($issuedAt <= 0 || (time() - $issuedAt) < BOOKING_MIN_FORM_SECONDS) {
        booking_error('Форма надіслана занадто швидко. Спробуйте ще раз через кілька секунд.', 400);
    }

    $userAgent = svh_request_user_agent(255);
    $honeypot = trim((string) ($input['website'] ?? ''));

    $bookingId = booking_generate_id();
    $name = svh_input_string($input, 'name', 100);
    $phone = svh_input_string($input, 'phone', 40);
    $email = booking_normalize_email((string) ($input['email'] ?? '')) ?? '';
    $checkin = svh_input_string($input, 'checkin', 10);
    $checkout = svh_input_string($input, 'checkout', 10);
    $guests = (int) ($input['guests'] ?? 0);
    $room = strtolower(svh_input_string($input, 'room', 20));
    $message = svh_input_multiline($input, 'message', 2000);
    $consent = svh_input_bool($input, 'consent', false);

    if ($honeypot !== '') {
        $spamRow = [
            'booking_id' => $bookingId,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'checkin_date' => $checkin,
            'checkout_date' => $checkout,
            'guests' => max(0, $guests),
            'room_code' => $room,
            'message' => $message,
            'consent' => (bool) $consent,
            'honeypot_triggered' => true,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'email_sent' => false,
            'email_transport' => null,
            'email_error' => null,
            'status' => 'spam_honeypot',
        ];
        booking_store($pdo, $spamRow);

        booking_success([
            'booking_id' => $bookingId,
            'mail_sent' => false,
            'transport' => 'none',
            'message' => 'Дякуємо! Заявку отримано.',
        ]);
    }

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        booking_error('Вкажіть коректне ім’я (2-100 символів).', 400);
    }
    if (BOOKING_BLOCK_GIBBERISH && booking_contains_gibberish($name)) {
        booking_error('Імʼя виглядає некоректно. Перевірте, будь ласка, написання.', 400);
    }

    if ($phone === '') {
        booking_error('Вкажіть телефон.', 400);
    }
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($phoneDigits) < 9 || strlen($phoneDigits) > 15) {
        booking_error('Телефон має містити 9-15 цифр.', 400);
    }

    if ((string) ($input['email'] ?? '') !== '' && $email === '') {
        booking_error('Email має некоректний формат.', 400);
    }

    $checkinDate = booking_parse_date($checkin);
    $checkoutDate = booking_parse_date($checkout);
    if (!$checkinDate || !$checkoutDate) {
        booking_error('Некоректні дати заїзду/виїзду.', 400);
    }

    $today = new DateTimeImmutable('today');
    if ($checkinDate < $today) {
        booking_error('Дата заїзду не може бути в минулому.', 400);
    }
    if ($checkoutDate <= $checkinDate) {
        booking_error('Дата виїзду має бути пізніше дати заїзду.', 400);
    }

    if ($guests < 1 || $guests > BOOKING_MAX_GUESTS) {
        booking_error('Кількість гостей має бути від 1 до ' . BOOKING_MAX_GUESTS . '.', 400);
    }

    if (!preg_match('/^room-(?:[1-9]|1[0-9]|20)$/', $room)) {
        booking_error('Оберіть конкретний номер із переліку.', 400);
    }

    $roomCapacity = booking_room_capacity($room);
    if ($roomCapacity === null) {
        booking_error('Не вдалося визначити місткість вибраного номера.', 500);
    }
    if ($guests > $roomCapacity) {
        booking_error('Кількість гостей перевищує місткість вибраного номера.', 400);
    }

    $conflicts = svh_find_room_conflicts($pdo, $room, $checkinDate->format('Y-m-d'), $checkoutDate->format('Y-m-d'));
    if (($conflicts['has_conflict'] ?? false) === true) {
        $firstBooking = $conflicts['bookings'][0] ?? null;
        $firstEvent = $conflicts['events'][0] ?? null;

        if (is_array($firstBooking)) {
            booking_error(
                'На вибрані дати номер уже зайнятий (' .
                (string) ($firstBooking['checkin_date'] ?? '') . ' → ' .
                (string) ($firstBooking['checkout_date'] ?? '') . ', статус: ' .
                (string) ($firstBooking['status'] ?? '') . ').',
                409
            );
        }

        if (is_array($firstEvent)) {
            booking_error(
                'На вибрані дати номер тимчасово заблоковано (' .
                (string) ($firstEvent['start_date'] ?? '') . ' → ' .
                (string) ($firstEvent['end_date'] ?? '') . ').',
                409
            );
        }
    }

    if (mb_strlen($message) > 2000) {
        booking_error('Коментар занадто довгий.', 400);
    }
    if (BOOKING_BLOCK_GIBBERISH && $message !== '' && booking_contains_gibberish($message)) {
        booking_error('Коментар виглядає некоректно. Уточніть текст, будь ласка.', 400);
    }

    if ($consent !== true) {
        booking_error('Потрібна згода на обробку персональних даних.', 400);
    }

    $booking = [
        'booking_id' => $bookingId,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'checkin_date' => $checkinDate->format('Y-m-d'),
        'checkout_date' => $checkoutDate->format('Y-m-d'),
        'guests' => $guests,
        'room_code' => $room,
        'message' => $message,
        'consent' => true,
        'honeypot_triggered' => false,
        'ip_address' => $ip,
        'user_agent' => $userAgent,
        'email_sent' => false,
        'email_transport' => null,
        'email_error' => null,
        'status' => 'new',
    ];

    $emailResult = booking_send_email($booking, $ip, $userAgent);
    $booking['email_sent'] = (bool) ($emailResult['sent'] ?? false);
    $booking['email_transport'] = $emailResult['transport'] ?? null;
    $booking['email_error'] = $emailResult['error'] ?? null;
    $booking['status'] = $booking['email_sent'] ? 'new' : 'new_email_failed';

    booking_store($pdo, $booking);

    if (!$booking['email_sent']) {
        error_log('Booking email was not sent for ' . $bookingId . ' via ' . ($booking['email_transport'] ?? 'unknown'));
    }

    if (TELEGRAM_NOTIFY_BOOKINGS) {
        $emailView = $booking['email'] !== '' ? $booking['email'] : 'не вказано';
        $messageView = $booking['message'] !== '' ? booking_short_text((string) $booking['message']) : '—';
        telegram_notify_admins(implode("\n", [
            '📥 Нова заявка бронювання',
            'ID: ' . (string) $booking['booking_id'],
            'Імʼя: ' . (string) $booking['name'],
            'Телефон: ' . (string) $booking['phone'],
            'Email: ' . $emailView,
            'Дати: ' . (string) $booking['checkin_date'] . ' → ' . (string) $booking['checkout_date'],
            'Гості: ' . (string) $booking['guests'],
            'Номер: ' . (string) $booking['room_code'],
            'Коментар: ' . $messageView,
            'Статус: ' . (string) $booking['status'],
            'Час: ' . date('Y-m-d H:i:s'),
        ]));
    }

    booking_success([
        'booking_id' => $bookingId,
        'mail_sent' => $booking['email_sent'],
        'transport' => $booking['email_transport'],
        'message' => 'Дякуємо! Заявку отримано, ми зв’яжемося з вами найближчим часом.',
    ]);
} catch (Throwable $e) {
    error_log('Booking API fatal error: ' . $e->getMessage());
    booking_error('Внутрішня помилка сервера. Спробуйте пізніше.', 500);
}


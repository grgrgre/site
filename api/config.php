<?php
/**
 * SvityazHOME Configuration
 * Конфігурація з захистом
 */

// PHP 7.4 compatibility for PHP 8 string helpers used across the project.
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        if ($len > strlen($haystack)) {
            return false;
        }
        return substr($haystack, -$len) === $needle;
    }
}

// ═══════════════════════════════════════════════════════════════
// ВАЖЛИВО: Папка /storage/ НЕ повинна перезаливатись!
// При оновленні сайту заливайте все КРІМ папки storage
// ═══════════════════════════════════════════════════════════════

if (!function_exists('svh_env_bootstrap')) {
    function svh_env_bootstrap(array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_string($path) || !is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $rawLine) {
                $line = trim((string) $rawLine);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
                }

                $eqPos = strpos($line, '=');
                if ($eqPos === false) {
                    continue;
                }

                $name = trim(substr($line, 0, $eqPos));
                if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                    continue;
                }

                if (getenv($name) !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                    continue;
                }

                $value = trim(substr($line, $eqPos + 1));
                if ($value !== '') {
                    $firstChar = $value[0];
                    $lastChar = $value[strlen($value) - 1];
                    if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                        $value = substr($value, 1, -1);
                    } else {
                        $value = preg_split('/\s+#/', $value, 2)[0] ?? $value;
                        $value = trim($value);
                    }
                }

                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

if (!function_exists('svh_env')) {
    function svh_env(string $name, $default = '')
    {
        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }

        if (array_key_exists($name, $_ENV)) {
            return $_ENV[$name];
        }

        if (array_key_exists($name, $_SERVER)) {
            return $_SERVER[$name];
        }

        return $default;
    }
}

$projectRoot = dirname(__DIR__);
svh_env_bootstrap([
    $projectRoot . '/.env',
]);

// Базова папка для збереження даних (поза основним сайтом)
define('STORAGE_DIR', dirname(__DIR__) . '/storage/');

// Шляхи до файлів даних
define('DATA_DIR', STORAGE_DIR . 'data/');
define('UPLOADS_DIR', STORAGE_DIR . 'uploads/');
define('STORAGE_LOGS_DIR', STORAGE_DIR . 'logs/');
define('STORAGE_BACKUPS_DIR', STORAGE_DIR . 'backups/');

// SQLite database
define('DATABASE_PATH', DATA_DIR . 'svityazhome.db');

// Legacy JSON files (for migration)
define('REVIEWS_FILE_PATH', DATA_DIR . 'reviews.json');
define('CONTENT_FILE_PATH', DATA_DIR . 'content-changes.json');
define('ROOM_IMAGES_FILE_PATH', DATA_DIR . 'room-images.json');

// URL для доступу до завантажених файлів
define('UPLOADS_URL', '/storage/uploads/');

// Public URLs and allowed CORS origins
define('SITE_URL', 'https://svityazhome.com.ua');
define('SITE_URL_WWW', 'https://www.svityazhome.com.ua');
define('ALLOWED_ORIGINS', [
    SITE_URL,
    SITE_URL_WWW,
    'http://localhost',
    'http://localhost:8000',
    'http://127.0.0.1',
    'http://127.0.0.1:8000',
]);

// ═══════════════════════════════════════════════════════════════
// БЕЗПЕКА - ЗМІНІТЬ ЦІ ЗНАЧЕННЯ!
// ═══════════════════════════════════════════════════════════════

// Пароль адміна: only from env, no insecure fallback.
// Known leaked default is explicitly rejected.
$adminPassword = trim((string) (svh_env('ADMIN_PASSWORD', '')));
if ($adminPassword === 'svityaz2026') {
    $adminPassword = '';
}
define('ADMIN_PASSWORD', $adminPassword);

$adminEmail = trim((string) (svh_env('ADMIN_EMAIL', 'Admin@svityazhome.com.ua')));
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $adminEmail = 'Admin@svityazhome.com.ua';
}
define('ADMIN_EMAIL', $adminEmail);
define('ADMIN_CREDENTIALS_FILE', DATA_DIR . 'admin-auth.json');

// Хеш пароля для порівняння через password_verify()
define('ADMIN_PASSWORD_HASH', ADMIN_PASSWORD !== '' ? password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT) : '');

// Секретний ключ для токенів (ЗМІНІТЬ!)
define('SECRET_KEY', 'svh_secret_' . md5(__DIR__ . 'svityazhome_salt_2026'));

// CSRF токен сіль
define('CSRF_SALT', 'svh_csrf_' . md5(__DIR__ . date('Y-m')));

// ═══════════════════════════════════════════════════════════════
// ЛІМІТИ
// ═══════════════════════════════════════════════════════════════

define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('MAX_IMAGES_PER_REVIEW', 3);
define('RATE_LIMIT_REVIEWS', 20); // Max reviews per hour
define('RATE_LIMIT_QUESTIONS', 10); // Max questions per hour
define('SESSION_LIFETIME', 86400); // 24 hours
define('CSRF_TOKEN_TTL', 7200); // 2 hours

// AI chat limits (per-IP + global budgets).
$chatRate5Min = max(5, (int) (svh_env('RATE_LIMIT_CHAT_5MIN', 60)));
$chatRateHourly = max($chatRate5Min, (int) (svh_env('RATE_LIMIT_CHAT_HOURLY', 240)));
$chatRateDailyGlobal = max($chatRateHourly, (int) (svh_env('RATE_LIMIT_CHAT_DAILY_GLOBAL', 1500)));
$chatMonthWindowDays = max(28, min(31, (int) (svh_env('RATE_LIMIT_CHAT_MONTH_WINDOW_DAYS', 30))));
$chatRateMonthlyGlobal = max($chatRateDailyGlobal, (int) (svh_env('RATE_LIMIT_CHAT_MONTHLY_GLOBAL', 30000)));
$chatMaxTokensDefault = max(64, (int) (svh_env('OPENAI_CHAT_MAX_TOKENS_DEFAULT', 900)));
$chatMaxTokensHard = max($chatMaxTokensDefault, (int) (svh_env('OPENAI_CHAT_MAX_TOKENS_HARD', 1400)));
$editorMaxTokensDefault = max(512, (int) (svh_env('OPENAI_EDITOR_MAX_TOKENS_DEFAULT', 2600)));
$editorMaxTokensHard = max($editorMaxTokensDefault, (int) (svh_env('OPENAI_EDITOR_MAX_TOKENS_HARD', 5200)));
$openAiImageModel = trim((string) (svh_env('OPENAI_IMAGE_MODEL', 'gpt-image-1')));
if ($openAiImageModel === '') {
    $openAiImageModel = 'gpt-image-1';
}

define('RATE_LIMIT_CHAT_5MIN', $chatRate5Min);
define('RATE_LIMIT_CHAT_HOURLY', $chatRateHourly);
define('RATE_LIMIT_CHAT_DAILY_GLOBAL', $chatRateDailyGlobal);
define('RATE_LIMIT_CHAT_MONTH_WINDOW_DAYS', $chatMonthWindowDays);
define('RATE_LIMIT_CHAT_MONTHLY_GLOBAL', $chatRateMonthlyGlobal);
define('OPENAI_CHAT_MAX_TOKENS_DEFAULT', $chatMaxTokensDefault);
define('OPENAI_CHAT_MAX_TOKENS_HARD', $chatMaxTokensHard);
define('OPENAI_EDITOR_MAX_TOKENS_DEFAULT', $editorMaxTokensDefault);
define('OPENAI_EDITOR_MAX_TOKENS_HARD', $editorMaxTokensHard);
define('OPENAI_IMAGE_MODEL', $openAiImageModel);

// Unified policy constants used in public texts and API payloads
define('POLICY_CHECKIN_TIME', '14:00');
define('POLICY_CHECKOUT_TIME', '11:00');
define('POLICY_PREPAYMENT', '30%');

// Booking form limits and anti-spam
define('BOOKING_MAX_GUESTS', 8);
define('BOOKING_MIN_FORM_SECONDS', 3);
define('RATE_LIMIT_BOOKING_HOURLY', 20);   // per IP, per 1 hour
define('RATE_LIMIT_BOOKING_BURST', 8);     // per IP, per 10 minutes

// Booking email delivery
define('BOOKING_EMAIL_TO', svh_env('BOOKING_EMAIL_TO', 'booking@svityazhome.com.ua'));
define('BOOKING_EMAIL_MODE', strtolower(trim((string) (svh_env('BOOKING_EMAIL_MODE', 'auto')))));
define('SMTP_HOST', svh_env('SMTP_HOST', ''));
define('SMTP_USER', svh_env('SMTP_USER', ''));
define('SMTP_PASS', svh_env('SMTP_PASS', ''));
define('SMTP_PORT', (int) (svh_env('SMTP_PORT', 587)));
define('SMTP_FROM', svh_env('SMTP_FROM', BOOKING_EMAIL_TO));
define('SMTP_FROM_NAME', svh_env('SMTP_FROM_NAME', 'SvityazHOME'));
define('SMTP_SECURE', strtolower(trim((string) (svh_env('SMTP_SECURE', 'tls')))));

// Telegram integrations (alerts + moderation bot webhook)
$telegramBotToken = trim((string) (svh_env('TELEGRAM_BOT_TOKEN', '')));
$telegramChatId = trim((string) (svh_env('TELEGRAM_CHAT_ID', '')));
$telegramAdminChatIds = trim((string) (svh_env('TELEGRAM_ADMIN_CHAT_IDS', $telegramChatId)));
$telegramWebhookSecret = trim((string) (svh_env('TELEGRAM_WEBHOOK_SECRET', '')));
$telegramWebhookUrl = trim((string) (svh_env('TELEGRAM_WEBHOOK_URL', '')));
$telegramMiniAppUrl = trim((string) (svh_env('TELEGRAM_MINIAPP_URL', rtrim(SITE_URL, '/') . '/telegram-app-v4/')));
$telegramAdminAccessPassword = trim((string) (svh_env('TELEGRAM_ADMIN_ACCESS_PASSWORD', '')));
$telegramNotifyReviews = filter_var((string) (svh_env('TELEGRAM_NOTIFY_REVIEWS', '1')), FILTER_VALIDATE_BOOLEAN);
$telegramNotifyQuestions = filter_var((string) (svh_env('TELEGRAM_NOTIFY_QUESTIONS', '1')), FILTER_VALIDATE_BOOLEAN);
$telegramNotifyBookings = filter_var((string) (svh_env('TELEGRAM_NOTIFY_BOOKINGS', '1')), FILTER_VALIDATE_BOOLEAN);
$telegramNotifyAdminActions = filter_var((string) (svh_env('TELEGRAM_NOTIFY_ADMIN_ACTIONS', '1')), FILTER_VALIDATE_BOOLEAN);

define('TELEGRAM_BOT_TOKEN', $telegramBotToken);
define('TELEGRAM_CHAT_ID', $telegramChatId);
define('TELEGRAM_ADMIN_CHAT_IDS', $telegramAdminChatIds);
define('TELEGRAM_WEBHOOK_SECRET', $telegramWebhookSecret);
define('TELEGRAM_WEBHOOK_URL', $telegramWebhookUrl);
define('TELEGRAM_MINIAPP_URL', $telegramMiniAppUrl);
define('TELEGRAM_ADMIN_ACCESS_PASSWORD', $telegramAdminAccessPassword);
define('TELEGRAM_NOTIFY_REVIEWS', $telegramNotifyReviews);
define('TELEGRAM_NOTIFY_QUESTIONS', $telegramNotifyQuestions);
define('TELEGRAM_NOTIFY_BOOKINGS', $telegramNotifyBookings);
define('TELEGRAM_NOTIFY_ADMIN_ACTIONS', $telegramNotifyAdminActions);

// Developer mode flag (client-side diagnostics, optional API debug blocks)
define('SITE_DEV_MODE', filter_var((string) svh_env('SITE_DEV_MODE', '0'), FILTER_VALIDATE_BOOLEAN));

// Early-access gate for preview mode (UI password modal on the site)
// Backward-compatible with legacy SITE_LOCK_* envs.
$legacySiteLockEnabled = filter_var((string) (svh_env('SITE_LOCK_ENABLED', '0')), FILTER_VALIDATE_BOOLEAN);
$legacySiteLockPassword = trim((string) (svh_env('SITE_LOCK_PASSWORD', '')));
$earlyAccessEnabledRaw = svh_env('SITE_EARLY_ACCESS_ENABLED', '');
$earlyAccessEnabled = ($earlyAccessEnabledRaw === false || trim((string) $earlyAccessEnabledRaw) === '')
    ? $legacySiteLockEnabled
    : filter_var((string) $earlyAccessEnabledRaw, FILTER_VALIDATE_BOOLEAN);
$earlyAccessPassword = trim((string) (svh_env('SITE_EARLY_ACCESS_PASSWORD', '')));
if ($earlyAccessPassword === '' && $legacySiteLockPassword !== '') {
    $earlyAccessPassword = $legacySiteLockPassword;
}
$earlyAccessAllowAdminPasswordRaw = svh_env('SITE_EARLY_ACCESS_ALLOW_ADMIN_PASSWORD', '');
$earlyAccessAllowAdminPassword = ($earlyAccessAllowAdminPasswordRaw === false || trim((string) $earlyAccessAllowAdminPasswordRaw) === '')
    ? true
    : filter_var((string) $earlyAccessAllowAdminPasswordRaw, FILTER_VALIDATE_BOOLEAN);

define('SITE_EARLY_ACCESS_ENABLED', $earlyAccessEnabled);
define('SITE_EARLY_ACCESS_PASSWORD', $earlyAccessPassword);
define('SITE_EARLY_ACCESS_ALLOW_ADMIN_PASSWORD', $earlyAccessAllowAdminPassword);
define('SITE_EARLY_ACCESS_TTL', max(300, (int) (svh_env('SITE_EARLY_ACCESS_TTL', SESSION_LIFETIME))));

// Guard against obvious nonsense/spam text in free-form fields
define('BOOKING_BLOCK_GIBBERISH', filter_var((string) svh_env('BOOKING_BLOCK_GIBBERISH', '1'), FILTER_VALIDATE_BOOLEAN));

/**
 * Ініціалізація структури папок
 */
function initStorage() {
    $dirs = [
        STORAGE_DIR,
        DATA_DIR,
        DATA_DIR . 'rooms/',
        UPLOADS_DIR,
        STORAGE_LOGS_DIR,
        STORAGE_BACKUPS_DIR,
        UPLOADS_DIR . 'reviews/',
        UPLOADS_DIR . 'rooms/',
        UPLOADS_DIR . 'site/',
        UPLOADS_DIR . 'site/ai/',
        UPLOADS_DIR . 'yard/',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    // Копіюємо існуючі дані якщо є (до створення дефолтних файлів)
    $oldReviewsFile = dirname(__DIR__) . '/assets/data/reviews.json';
    if (!file_exists(REVIEWS_FILE_PATH) && file_exists($oldReviewsFile)) {
        copy($oldReviewsFile, REVIEWS_FILE_PATH);
    }

    $oldRoomImagesFile = dirname(__DIR__) . '/assets/data/room-images.json';
    if (!file_exists(ROOM_IMAGES_FILE_PATH) && file_exists($oldRoomImagesFile)) {
        copy($oldRoomImagesFile, ROOM_IMAGES_FILE_PATH);
    }

    // Створюємо початкові файли якщо не існують
    if (!file_exists(REVIEWS_FILE_PATH)) {
        $initialReviews = [
            'topics' => [
                'rooms' => 'Номери',
                'territory' => 'Територія',
                'service' => 'Обслуговування',
                'location' => 'Локація',
                'general' => 'Загальні враження'
            ],
            'approved' => [],
            'pending' => [],
            'questions' => []
        ];
        file_put_contents(REVIEWS_FILE_PATH, json_encode($initialReviews, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    if (!file_exists(CONTENT_FILE_PATH)) {
        file_put_contents(CONTENT_FILE_PATH, '{}');
    }
}

// Автоматична ініціалізація при підключенні
initStorage();

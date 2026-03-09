<?php

require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/site-content.php';

initStorage();
start_api_session();
admin_send_security_headers();

function admin_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; script-src 'self'");
}

function admin_db(): Database
{
    static $db = null;

    if (!$db instanceof Database) {
        $db = Database::getInstance();
    }

    return $db;
}

function admin_pdo(): PDO
{
    return admin_db()->getPdo();
}

function admin_url(string $path = 'index.php'): string
{
    return '/svh-ctrl-x7k9/' . ltrim($path, '/');
}

function admin_asset_url(string $path): string
{
    return '/svh-ctrl-x7k9/assets/' . ltrim($path, '/');
}

function admin_ip(): string
{
    return get_client_ip();
}

function admin_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_current_token(): string
{
    return is_string($_SESSION['admin_session_token'] ?? null) ? $_SESSION['admin_session_token'] : '';
}

function admin_session_fingerprint(): string
{
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 200);
    return hash('sha256', $userAgent);
}

function admin_login_rate_limit_action(): string
{
    return 'admin_login_attempt';
}

function admin_is_login_rate_limited(): bool
{
    return !admin_db()->checkRateLimit(admin_ip(), admin_login_rate_limit_action(), 8, 900);
}

function admin_record_failed_login(): void
{
    admin_db()->recordRateLimit(admin_ip(), admin_login_rate_limit_action());
}

function admin_is_logged_in(): bool
{
    $token = admin_current_token();
    if ($token === '') {
        return false;
    }

    $fingerprint = (string) ($_SESSION['admin_session_fingerprint'] ?? '');
    if ($fingerprint === '' || !hash_equals($fingerprint, admin_session_fingerprint())) {
        admin_logout();
        return false;
    }

    if (!admin_db()->validateAdminSession($token)) {
        admin_logout();
        return false;
    }

    return true;
}

function admin_require_login(): void
{
    if (admin_is_logged_in()) {
        return;
    }

    header('Location: ' . admin_url('login.php'));
    exit;
}

function admin_login(string $email, string $password): bool
{
    $credentials = [
        'email' => $email,
        'password' => $password,
    ];

    if (!is_admin_login_password_valid($credentials)) {
        return false;
    }

    session_regenerate_id(true);
    $token = admin_db()->createAdminSession(admin_ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $_SESSION['admin_session_token'] = $token;
    $_SESSION['admin_login_email'] = get_admin_login_email();
    $_SESSION['admin_session_fingerprint'] = admin_session_fingerprint();
    admin_db()->logAdminAction('admin_login', 'PHP admin panel login', admin_ip());

    return true;
}

function admin_logout(): void
{
    $token = admin_current_token();
    if ($token !== '') {
        admin_db()->deleteAdminSession($token);
    }

    unset($_SESSION['admin_session_token'], $_SESSION['admin_login_email'], $_SESSION['admin_session_fingerprint']);
    session_regenerate_id(true);
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function admin_consume_flash(): ?array
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);

    return is_array($flash) ? $flash : null;
}

function admin_csrf_token(): string
{
    return get_csrf_token();
}

function admin_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }
}

function admin_verify_csrf(): void
{
    require_csrf_token($_POST);
}

function admin_redirect(string $path): void
{
    header('Location: ' . admin_url($path));
    exit;
}

function admin_nav_items(): array
{
    return [
        'index.php' => 'Огляд',
        'content.php' => 'Контент',
        'files.php' => 'Файли + AI',
        'rooms.php' => 'Номери',
        'reviews.php' => 'Відгуки',
        'contacts.php' => 'Контакти',
        'gallery.php' => 'Галерея',
        'requests.php' => 'Запити',
    ];
}

function admin_page_meta(string $active, string $title): array
{
    $meta = [
        'eyebrow' => 'Адмінка',
        'title' => $title,
        'description' => 'Просте керування сайтом без зайвої складності.',
        'actions' => [],
    ];

    $byPage = [
        'index.php' => [
            'eyebrow' => 'Огляд системи',
            'description' => 'Швидкий стан сайту, модерації, бронювань і останніх адміністративних дій.',
            'actions' => [
                ['label' => 'Редагувати головну', 'path' => 'content.php', 'tone' => 'primary'],
                ['label' => 'Переглянути заявки', 'path' => 'requests.php', 'tone' => 'ghost'],
            ],
        ],
        'content.php' => [
            'eyebrow' => 'Публічний контент',
            'description' => 'Оновлення головної сторінки, SEO та основних текстових блоків без редагування коду.',
            'actions' => [
                ['label' => 'Відкрити сайт', 'href' => '/', 'tone' => 'ghost', 'external' => true],
                ['label' => 'Номери', 'path' => 'rooms.php', 'tone' => 'ghost'],
            ],
        ],
        'files.php' => [
            'eyebrow' => 'Файли та AI',
            'description' => 'Редагування HTML/CSS/JS і AI-асистент для переписування сторінок та оновлення зображень.',
            'actions' => [
                ['label' => 'Відкрити сайт', 'href' => '/', 'tone' => 'ghost', 'external' => true],
                ['label' => 'Контент', 'path' => 'content.php', 'tone' => 'ghost'],
            ],
        ],
        'rooms.php' => [
            'eyebrow' => 'Номери',
            'description' => 'Керуйте описом, місткістю, ціною, правилами та зображеннями кожного номера.',
            'actions' => [
                ['label' => 'Головна', 'path' => 'index.php', 'tone' => 'ghost'],
                ['label' => 'Запити', 'path' => 'requests.php', 'tone' => 'ghost'],
            ],
        ],
        'reviews.php' => [
            'eyebrow' => 'Відгуки і питання',
            'description' => 'Модерація, редагування і публікація відгуків, а також перегляд питань від гостей.',
            'actions' => [
                ['label' => 'Запити', 'path' => 'requests.php', 'tone' => 'ghost'],
                ['label' => 'Головна', 'path' => 'index.php', 'tone' => 'ghost'],
            ],
        ],
        'contacts.php' => [
            'eyebrow' => 'Контакти',
            'description' => 'Оновіть телефон, email, адресу, мапу та соціальні посилання, які бачить гість.',
            'actions' => [
                ['label' => 'Переглянути сайт', 'href' => '/', 'tone' => 'ghost', 'external' => true],
            ],
        ],
        'gallery.php' => [
            'eyebrow' => 'Галерея',
            'description' => 'Додавайте фото, редагуйте alt-тексти, категорії та візуальні акценти галереї.',
            'actions' => [
                ['label' => 'Відкрити галерею', 'href' => '/gallery/', 'tone' => 'ghost', 'external' => true],
            ],
        ],
        'requests.php' => [
            'eyebrow' => 'Заявки з сайту',
            'description' => 'Швидко проглядайте контакти, дати, повідомлення гостей і оновлюйте статус бронювання.',
            'actions' => [
                ['label' => 'Огляд', 'path' => 'index.php', 'tone' => 'ghost'],
                ['label' => 'Номери', 'path' => 'rooms.php', 'tone' => 'ghost'],
            ],
        ],
    ];

    if (isset($byPage[$active])) {
        $meta = array_merge($meta, $byPage[$active]);
    }

    return $meta;
}

function admin_booking_status_options(): array
{
    return [
        'new' => 'Нова',
        'new_email_failed' => 'Нова, лист не пішов',
        'confirmed' => 'Підтверджена',
        'cancelled' => 'Скасована',
        'done' => 'Завершена',
        'spam_honeypot' => 'Спам / honeypot',
    ];
}

function admin_booking_status_meta(string $status): array
{
    $options = admin_booking_status_options();
    $label = $options[$status] ?? $status;
    $tone = 'default';

    if (in_array($status, ['new', 'new_email_failed'], true)) {
        $tone = 'warning';
    } elseif (in_array($status, ['cancelled', 'spam_honeypot'], true)) {
        $tone = 'danger';
    } elseif (in_array($status, ['confirmed', 'done'], true)) {
        $tone = 'success';
    }

    return [
        'label' => $label,
        'tone' => $tone,
    ];
}

function admin_render_header(string $title, string $active = ''): void
{
    $flash = admin_consume_flash();
    $navItems = admin_nav_items();
    $currentEmail = $_SESSION['admin_login_email'] ?? get_admin_login_email();
    $pageMeta = admin_page_meta($active, $title);
    ?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= admin_e($title) ?> | SvityazHOME Admin</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" type="image/png" href="/assets/images/favicon/favicon-32x32.png">
  <link rel="stylesheet" href="<?= admin_e(admin_asset_url('admin.css')) ?>">
</head>
<body class="admin-body admin-page-<?= admin_e(pathinfo($active !== '' ? $active : 'index.php', PATHINFO_FILENAME)) ?>">
  <div class="admin-shell">
    <aside class="admin-sidebar">
      <div class="admin-sidebar__head">
        <a class="admin-brand" href="<?= admin_e(admin_url('index.php')) ?>">SvityazHOME Admin</a>
        <a class="admin-sidebar__site-link" href="/" target="_blank" rel="noopener noreferrer">Відкрити сайт</a>
      </div>
      <p class="admin-sidebar__meta">PHP-панель для контенту, номерів та заявок.</p>
      <nav class="admin-nav" aria-label="Навігація адміністратора">
        <?php foreach ($navItems as $file => $label): ?>
          <a class="admin-nav__link<?= $active === $file ? ' is-active' : '' ?>" href="<?= admin_e(admin_url($file)) ?>"><?= admin_e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="admin-sidebar__footer">
        <p class="admin-sidebar__user"><?= admin_e((string) $currentEmail) ?></p>
        <div class="admin-sidebar__footer-actions">
          <a class="admin-sidebar__site-link" href="/" target="_blank" rel="noopener noreferrer">Сайт</a>
          <a class="admin-sidebar__logout" href="<?= admin_e(admin_url('logout.php')) ?>">Вийти</a>
        </div>
      </div>
    </aside>
    <main class="admin-main">
      <header class="admin-topbar">
        <div class="admin-topbar__copy">
          <p class="admin-topbar__eyebrow"><?= admin_e((string) ($pageMeta['eyebrow'] ?? 'Адмінка')) ?></p>
          <h1><?= admin_e($title) ?></h1>
          <p><?= admin_e((string) ($pageMeta['description'] ?? '')) ?></p>
        </div>
        <?php if (!empty($pageMeta['actions']) && is_array($pageMeta['actions'])): ?>
          <div class="admin-topbar__actions">
            <?php foreach ($pageMeta['actions'] as $action): ?>
              <?php
              $tone = (string) ($action['tone'] ?? 'ghost');
              $href = isset($action['href'])
                  ? (string) $action['href']
                  : admin_url((string) ($action['path'] ?? 'index.php'));
              $class = $tone === 'primary' ? 'admin-button' : 'admin-button--ghost';
              $external = !empty($action['external']);
              ?>
              <a class="<?= admin_e($class) ?>" href="<?= admin_e($href) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                <?= admin_e((string) ($action['label'] ?? 'Відкрити')) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </header>
      <?php if (is_array($flash)): ?>
        <div class="admin-alert admin-alert--<?= admin_e((string) ($flash['type'] ?? 'info')) ?>" role="alert">
          <?= admin_e((string) ($flash['message'] ?? '')) ?>
        </div>
      <?php endif; ?>
<?php
}

function admin_render_footer(): void
{
    ?>
    </main>
  </div>
</body>
</html>
<?php
}

function admin_post_list(string $key): array
{
    $lines = preg_split('/\r\n|\r|\n/', (string) ($_POST[$key] ?? '')) ?: [];
    $items = [];

    foreach ($lines as $line) {
        $value = trim(strip_tags((string) $line));
        if ($value !== '') {
            $items[] = $value;
        }
    }

    return $items;
}

function admin_post_text(string $key, int $limit = 5000): string
{
    return sanitize_multiline_text((string) ($_POST[$key] ?? ''), $limit);
}

function admin_post_field(string $key, int $limit = 255): string
{
    return sanitize_text_field((string) ($_POST[$key] ?? ''), $limit);
}

function admin_room_path(int $roomId): string
{
    return DATA_DIR . 'rooms/room-' . $roomId . '.json';
}

function admin_read_room(int $roomId): array
{
    $path = admin_room_path($roomId);
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : [];
}

function admin_write_room(int $roomId, array $room): bool
{
    $path = admin_room_path($roomId);
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $room['id'] = $roomId;
    $room['guests'] = (int) ($room['capacity'] ?? $room['guests'] ?? 0);
    $room['price'] = (int) ($room['pricePerNight'] ?? 0) > 0 ? ((int) $room['pricePerNight']) . ' грн' : '';
    $room['cover'] = (string) ($room['cover'] ?? (($room['images'][0] ?? '')));

    $json = json_encode($room, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function admin_reviews_data(): array
{
    $raw = is_file(REVIEWS_FILE_PATH) ? file_get_contents(REVIEWS_FILE_PATH) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($decoded)) {
        $decoded = [];
    }

    $decoded['approved'] = isset($decoded['approved']) && is_array($decoded['approved']) ? $decoded['approved'] : [];
    $decoded['pending'] = isset($decoded['pending']) && is_array($decoded['pending']) ? $decoded['pending'] : [];
    $decoded['questions'] = isset($decoded['questions']) && is_array($decoded['questions']) ? $decoded['questions'] : [];
    $decoded['topics'] = isset($decoded['topics']) && is_array($decoded['topics']) ? $decoded['topics'] : [];
    $decoded['nextId'] = (int) ($decoded['nextId'] ?? 100);

    return $decoded;
}

function admin_save_reviews_data(array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(REVIEWS_FILE_PATH, $json, LOCK_EX) !== false;
}

function admin_find_review(array $data, int $id): array
{
    foreach (['approved', 'pending', 'questions'] as $bucket) {
        foreach (($data[$bucket] ?? []) as $index => $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return [$bucket, $index, $item];
            }
        }
    }

    return ['', -1, null];
}

function admin_booking_rows(): array
{
    $stmt = admin_pdo()->query("
        SELECT id, booking_id, name, phone, email, room_code, checkin_date, checkout_date, guests, message, status, created_at
        FROM bookings
        ORDER BY created_at DESC
        LIMIT 200
    ");

    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

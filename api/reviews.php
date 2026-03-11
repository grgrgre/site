<?php
/**
 * SvityazHOME Reviews API
 * Secure JSON-based reviews/questions endpoint.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (send_api_headers(['GET', 'POST', 'OPTIONS'])) {
    exit;
}

initStorage();
$db = Database::getInstance();
$ip = get_client_ip();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$REVIEWS_FILE = DATA_DIR . 'reviews.json';
$REVIEW_TOPICS = ['rooms', 'territory', 'service', 'location', 'general'];
$QUESTION_TOPICS = ['booking', 'rooms', 'prices', 'amenities', 'location', 'other'];
$SORT_OPTIONS = ['latest', 'oldest', 'rating_desc', 'rating_asc'];
const REVIEW_ROOM_MIN_ID = 1;
const REVIEW_ROOM_MAX_ID = 20;

function default_reviews_payload(): array
{
    return svh_reviews_defaults();
}

function ensure_reviews_shape(array $data): array
{
    return svh_normalize_reviews_payload($data);
}

function read_reviews_data(string $filePath): array
{
    return svh_read_reviews_storage($filePath);
}

function save_reviews_data(string $filePath, array $data): void
{
    maybe_auto_storage_backup('reviews-write', 6 * 3600);

    if (!svh_write_reviews_storage($data, $filePath)) {
        error_response('Failed to save data', 500);
    }
}

function filter_and_sort_reviews(array $reviews, ?string $topic, string $sort): array
{
    if ($topic && $topic !== 'all') {
        $reviews = array_values(array_filter($reviews, static function ($review) use ($topic) {
            return ($review['topic'] ?? 'general') === $topic;
        }));
    }

    usort($reviews, static function ($a, $b) use ($sort) {
        $dateA = strtotime($a['created_at'] ?? $a['date'] ?? '1970-01-01');
        $dateB = strtotime($b['created_at'] ?? $b['date'] ?? '1970-01-01');
        $ratingA = (int) ($a['rating'] ?? 5);
        $ratingB = (int) ($b['rating'] ?? 5);

        switch ($sort) {
            case 'oldest':
                return $dateA <=> $dateB;
            case 'rating_asc':
                return $ratingA === $ratingB ? ($dateB <=> $dateA) : ($ratingA <=> $ratingB);
            case 'rating_desc':
                return $ratingA === $ratingB ? ($dateB <=> $dateA) : ($ratingB <=> $ratingA);
            case 'latest':
            default:
                return $dateB <=> $dateA;
        }
    });

    return $reviews;
}

function paginate_items(array $items, int $page, int $perPage): array
{
    $total = count($items);
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($items, $offset, $perPage),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $pages,
        ],
    ];
}

function sort_items_by_created_desc(array $items): array
{
    usort($items, static function ($a, $b) {
        $dateA = strtotime($a['created_at'] ?? $a['moderated_at'] ?? $a['date'] ?? '1970-01-01');
        $dateB = strtotime($b['created_at'] ?? $b['moderated_at'] ?? $b['date'] ?? '1970-01-01');
        return $dateB <=> $dateA;
    });
    return $items;
}

function respond_policy(): array
{
    return [
        'checkin' => POLICY_CHECKIN_TIME,
        'checkout' => POLICY_CHECKOUT_TIME,
        'prepayment' => POLICY_PREPAYMENT,
    ];
}

function normalize_review_room_id($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    $id = (int) $value;
    if ($id < REVIEW_ROOM_MIN_ID || $id > REVIEW_ROOM_MAX_ID) {
        return null;
    }

    return $id;
}

function reviews_short(string $text, int $max = 180): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($value) <= $max) {
        return $value;
    }
    return rtrim(mb_substr($value, 0, max(20, $max - 1))) . '…';
}

function issue_cache_headers(string $seed): void
{
    $etag = '"' . sha1($seed) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=120');

    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
        http_response_code(304);
        exit;
    }
}

if ($method === 'GET') {
    $action = strtolower(svh_query_string('action', 40, ''));

    if ($action === 'csrf') {
        svh_respond_legacy_success([
            'csrf_token' => get_csrf_token(),
        ], 'CSRF token issued');
    }

    if ($action === 'admin_account') {
        require_admin_auth($db, $_GET);
        svh_respond_legacy_success([
            'email' => get_admin_login_email(),
        ]);
    }

    $data = read_reviews_data($REVIEWS_FILE);

    if (isset($_GET['admin'])) {
        require_admin_auth($db, $_GET);

        $approved = sort_items_by_created_desc($data['approved']);
        $pending = sort_items_by_created_desc($data['pending']);
        $questions = sort_items_by_created_desc($data['questions']);

        svh_respond_legacy_success([
            'approved' => $approved,
            'pending' => $pending,
            'questions' => $questions,
            'stats' => [
                'approved_reviews' => count($approved),
                'pending_reviews' => count($pending),
                'questions' => count($questions),
            ],
            'admin_email' => get_admin_login_email(),
            'policy' => respond_policy(),
        ]);
    }

    $topic = sanitize_topic(svh_query_string('topic', 40, 'all'), array_merge($REVIEW_TOPICS, ['all']), 'all');
    $sort = strtolower(svh_query_string('sort', 20, 'latest'));
    if (!in_array($sort, $SORT_OPTIONS, true)) {
        $sort = 'latest';
    }

    $page = svh_query_int('page', 1, 1);
    $perPage = svh_query_int('per_page', 12, 1, 60);

    $filtered = filter_and_sort_reviews($data['approved'], $topic, $sort);
    $paged = paginate_items($filtered, $page, $perPage);
    $total = $paged['pagination']['total'];

    $average = null;
    if ($total > 0) {
        $sum = array_reduce($filtered, static function ($carry, $item) {
            return $carry + (int) ($item['rating'] ?? 5);
        }, 0);
        $average = round($sum / $total, 1);
    }

    $cacheSeed = implode('|', [
        file_exists($REVIEWS_FILE) ? (string) filemtime($REVIEWS_FILE) : '0',
        $topic,
        $sort,
        (string) $page,
        (string) $perPage,
        (string) $total,
    ]);
    issue_cache_headers($cacheSeed);

    svh_respond_legacy_success([
        'reviews' => $paged['items'],
        'total' => $total,
        'average_rating' => $average,
        'pagination' => $paged['pagination'],
        'sort' => $sort,
        'topic' => $topic,
        'policy' => respond_policy(),
    ]);
}

if ($method !== 'POST') {
    error_response('Method not allowed', 405);
}

$input = read_input_payload();
$action = strtolower(trim((string) ($input['action'] ?? 'submit')));
$userAgent = svh_request_user_agent(255);
$data = read_reviews_data($REVIEWS_FILE);

if ($action === 'auth_check') {
    enforce_rate_limit($db, $ip, 'admin_auth_check', 30, 3600);

    if (!is_admin_password_configured()) {
        error_response('Admin login is not configured', 503);
    }

    $email = svh_input_string($input, 'email', 120, get_admin_login_email());
    $password = (string) ($input['password'] ?? '');

    $issued = svh_issue_admin_session($db, $email, $password, $ip, $userAgent);
    if (!is_array($issued)) {
        error_response('Invalid credentials', 401);
    }

    $db->logAdminAction('auth_login', 'reviews_api', $ip);

    svh_respond_legacy_success($issued, 'Admin session created');
}

if ($action === 'submit') {
    require_csrf_token($input);
    enforce_honeypot($input, 'website');
    enforce_rate_limit($db, $ip, 'review_submit', RATE_LIMIT_REVIEWS, 3600);

    $name = sanitize_text_field($input['name'] ?? '', 60);
    $text = sanitize_multiline_text($input['text'] ?? '', 1500);
    $rating = (int) ($input['rating'] ?? 5);
    $rating = max(1, min(5, $rating));
    $topic = sanitize_topic($input['topic'] ?? 'general', $REVIEW_TOPICS, 'general');
    $roomId = normalize_review_room_id($input['room_id'] ?? null);

    if (mb_strlen($name) < 2) {
        error_response("Вкажіть ім'я", 400);
    }

    if (mb_strlen($text) < 10) {
        error_response('Відгук занадто короткий', 400);
    }

    if ($topic === 'rooms' && $roomId === null) {
        error_response('Оберіть номер для відгуку про номер', 400);
    }

    $images = [];
    $uploaded = normalize_uploaded_files('images');
    if (count($uploaded) > MAX_IMAGES_PER_REVIEW) {
        error_response('Можна завантажити не більше 3 зображень', 400);
    }

    if (!empty($uploaded)) {
        foreach ($uploaded as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $result = process_uploaded_image(
                $file,
                UPLOADS_DIR . 'reviews/',
                UPLOADS_URL . 'reviews/',
                1920,
                1280,
                MAX_UPLOAD_SIZE
            );
            if (!$result['success']) {
                error_response($result['error'] ?? 'Не вдалося обробити зображення', 400);
            }
            $images[] = $result['path'];
        }
    }

    $review = [
        'id' => $data['nextId']++,
        'name' => $name,
        'text' => $text,
        'rating' => $rating,
        'topic' => $topic,
        'source' => 'site',
        'images' => $images,
        'date' => date('Y-m-d'),
        'created_at' => date('c'),
    ];
    if ($roomId !== null) {
        $review['room_id'] = $roomId;
    }

    $data['pending'][] = $review;
    save_reviews_data($REVIEWS_FILE, $data);

    if (TELEGRAM_NOTIFY_REVIEWS) {
        $roomSuffix = $roomId !== null ? (' | room-' . $roomId) : '';
        telegram_notify_admins(implode("\n", [
            '🆕 Новий відгук (pending)',
            'ID: #' . (string) $review['id'],
            'Імʼя: ' . $name,
            'Тема: ' . $topic . $roomSuffix,
            'Оцінка: ' . $rating . '/5',
            'Текст: ' . reviews_short($text, 220),
            'Час: ' . date('Y-m-d H:i:s'),
        ]));
    }

    svh_respond_legacy_success([
        'message' => 'Дякуємо! Відгук буде опубліковано після перевірки.',
    ], 'Дякуємо! Відгук буде опубліковано після перевірки.');
}

if ($action === 'question') {
    require_csrf_token($input);
    enforce_honeypot($input, 'website');
    enforce_rate_limit($db, $ip, 'review_question', RATE_LIMIT_QUESTIONS, 3600);

    $name = sanitize_text_field($input['name'] ?? '', 60);
    $contact = sanitize_text_field($input['contact'] ?? '', 100);
    $text = sanitize_multiline_text($input['text'] ?? '', 1500);
    $topic = sanitize_topic($input['topic'] ?? 'other', $QUESTION_TOPICS, 'other');

    if (mb_strlen($name) < 2) {
        error_response("Вкажіть ім'я", 400);
    }
    if (mb_strlen($contact) < 3) {
        error_response('Вкажіть контакт для відповіді', 400);
    }
    if (mb_strlen($text) < 10) {
        error_response('Запитання занадто коротке', 400);
    }

    $question = [
        'id' => $data['nextId']++,
        'name' => $name,
        'contact' => $contact,
        'text' => $text,
        'topic' => $topic,
        'date' => date('Y-m-d'),
        'created_at' => date('c'),
    ];

    $data['questions'][] = $question;
    save_reviews_data($REVIEWS_FILE, $data);

    if (TELEGRAM_NOTIFY_QUESTIONS) {
        telegram_notify_admins(implode("\n", [
            '❓ Нове запитання',
            'ID: #' . (string) $question['id'],
            'Імʼя: ' . $name,
            'Контакт: ' . $contact,
            'Тема: ' . $topic,
            'Текст: ' . reviews_short($text, 220),
            'Час: ' . date('Y-m-d H:i:s'),
        ]));
    }

    svh_respond_legacy_success([
        'message' => "Дякуємо! Ми зв'яжемося з вами найближчим часом.",
    ], "Дякуємо! Ми зв'яжемося з вами найближчим часом.");
}

require_admin_auth($db, $input);
require_csrf_token($input);

if ($action === 'change_password') {
    enforce_rate_limit($db, $ip, 'admin_change_password', 10, 3600);

    $expectedEmail = normalize_admin_email(get_admin_login_email());
    $emailRaw = sanitize_text_field($input['email'] ?? '', 120);
    $email = normalize_admin_email($emailRaw);
    if ($email === '') {
        $email = $expectedEmail;
    }
    $currentPassword = (string) ($input['current_password'] ?? '');
    $newPassword = (string) ($input['new_password'] ?? '');

    if ($email === '' || $expectedEmail === '' || !hash_equals($expectedEmail, $email)) {
        error_response('Invalid admin email', 400);
    }

    if ($currentPassword === '' || $newPassword === '') {
        error_response('Current and new passwords are required', 400);
    }

    if (strlen($newPassword) < 8) {
        error_response('New password must be at least 8 characters', 400);
    }

    if (strlen($newPassword) > 128) {
        error_response('New password is too long', 400);
    }

    if (!is_admin_login_password_valid([
        'email' => $email,
        'password' => $currentPassword,
    ])) {
        error_response('Current password is incorrect', 401);
    }

    if (hash_equals($currentPassword, $newPassword)) {
        error_response('New password must be different from current password', 400);
    }

    if (!save_admin_credentials($email, $newPassword)) {
        error_response('Failed to update admin password', 500);
    }

    $db->logAdminAction('auth_password_change', 'reviews_api', $ip);
    svh_respond_legacy_success([
        'email' => get_admin_login_email(),
        'message' => 'Пароль адміністратора змінено',
    ], 'Пароль адміністратора змінено');
}

$id = (int) ($input['id'] ?? 0);

if ($action === 'approve' && $id > 0) {
    foreach ($data['pending'] as $index => $item) {
        if ((int) ($item['id'] ?? 0) === $id) {
            $item['moderated_at'] = date('c');
            array_splice($data['pending'], $index, 1);
            $data['approved'][] = $item;
            save_reviews_data($REVIEWS_FILE, $data);
            $db->logAdminAction('review_approve', 'id=' . $id, $ip);
            svh_respond_legacy_success(['message' => 'Схвалено'], 'Схвалено');
        }
    }
    error_response('Відгук не знайдено', 404);
}

if (($action === 'reject' || $action === 'delete') && $id > 0) {
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
        error_response('Запис не знайдено', 404);
    }

    save_reviews_data($REVIEWS_FILE, $data);
    $db->logAdminAction('review_delete', 'id=' . $id, $ip);
    svh_respond_legacy_success(['message' => 'Видалено'], 'Видалено');
}

if ($action === 'add_review') {
    $name = sanitize_text_field($input['name'] ?? '', 60);
    $text = sanitize_multiline_text($input['text'] ?? '', 1500);
    $rating = max(1, min(5, (int) ($input['rating'] ?? 5)));
    $topic = sanitize_topic($input['topic'] ?? 'general', $REVIEW_TOPICS, 'general');
    $roomId = normalize_review_room_id($input['room_id'] ?? null);
    $source = sanitize_text_field($input['source'] ?? 'admin', 30);
    $date = sanitize_text_field($input['date'] ?? date('Y-m-d'), 20);

    if (mb_strlen($name) < 2 || mb_strlen($text) < 10) {
        error_response('Некоректні дані відгуку', 400);
    }

    if ($topic === 'rooms' && $roomId === null) {
        error_response('Оберіть номер для відгуку про номер', 400);
    }

    $images = [];
    $uploaded = normalize_uploaded_files('images');
    if (count($uploaded) > MAX_IMAGES_PER_REVIEW) {
        error_response('Можна завантажити не більше 3 зображень', 400);
    }

    if (!empty($uploaded)) {
        foreach ($uploaded as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $result = process_uploaded_image(
                $file,
                UPLOADS_DIR . 'reviews/',
                UPLOADS_URL . 'reviews/',
                1920,
                1280,
                MAX_UPLOAD_SIZE
            );
            if (!($result['success'] ?? false)) {
                error_response($result['error'] ?? 'Не вдалося обробити зображення', 400);
            }
            $images[] = (string) ($result['path'] ?? '');
        }
        $images = array_values(array_filter($images));
    }

    $review = [
        'id' => $data['nextId']++,
        'name' => $name,
        'text' => $text,
        'rating' => $rating,
        'topic' => $topic,
        'source' => $source,
        'images' => $images,
        'date' => $date,
        'created_at' => date('c'),
        'moderated_at' => date('c'),
    ];
    if ($roomId !== null) {
        $review['room_id'] = $roomId;
    }

    $data['approved'][] = $review;
    save_reviews_data($REVIEWS_FILE, $data);
    $db->logAdminAction('review_add', 'id=' . $review['id'], $ip);
    svh_respond_legacy_success(['message' => 'Додано'], 'Додано');
}

error_response('Unknown action', 400);

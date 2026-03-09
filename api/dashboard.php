<?php
/**
 * SvityazHOME admin dashboard data API.
 * Returns aggregated operational metrics for charts in admin stats page.
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['GET', 'OPTIONS'])) {
    exit;
}

initStorage();
$db = Database::getInstance();
$pdo = $db->getPdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$REVIEWS_FILE = DATA_DIR . 'reviews.json';

if ($method !== 'GET') {
    error_response('Method not allowed', 405);
}

require_admin_auth($db);

function dashboard_reviews_default_payload(): array
{
    return [
        'approved' => [],
        'pending' => [],
        'questions' => [],
        'nextId' => 100,
    ];
}

function dashboard_read_reviews_data(string $filePath): array
{
    if (!is_file($filePath)) {
        return dashboard_reviews_default_payload();
    }

    $raw = file_get_contents($filePath);
    if (!is_string($raw) || trim($raw) === '') {
        return dashboard_reviews_default_payload();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return dashboard_reviews_default_payload();
    }

    $data = array_merge(dashboard_reviews_default_payload(), $decoded);
    foreach (['approved', 'pending', 'questions'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }
    return $data;
}

function dashboard_date_labels(int $days): array
{
    $result = [];
    $start = new DateTimeImmutable('today');
    $start = $start->modify('-' . max(0, $days - 1) . ' days');

    for ($i = 0; $i < $days; $i++) {
        $day = $start->modify('+' . $i . ' days');
        $result[] = $day->format('Y-m-d');
    }
    return $result;
}

function dashboard_short_label(string $date): string
{
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }
    return date('d.m', $ts);
}

function dashboard_booking_status_label(string $status): string
{
    $map = [
        'new' => 'Нові',
        'new_email_failed' => 'Нові (email fail)',
        'confirmed' => 'Підтверджені',
        'cancelled' => 'Скасовані',
        'done' => 'Завершені',
        'spam_honeypot' => 'Спам',
    ];
    return $map[$status] ?? $status;
}

function dashboard_read_date_from_item(array $item): ?string
{
    $createdAt = trim((string) ($item['created_at'] ?? ''));
    if ($createdAt !== '') {
        $ts = strtotime($createdAt);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }

    $date = trim((string) ($item['date'] ?? ''));
    if ($date !== '') {
        $ts = strtotime($date);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }

    return null;
}

function dashboard_query_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return (int) ($row['cnt'] ?? 0);
}

try {
    $days = (int) ($_GET['days'] ?? 14);
    if ($days < 7) {
        $days = 7;
    }
    if ($days > 60) {
        $days = 60;
    }

    $today = date('Y-m-d');
    $labels = dashboard_date_labels($days);
    $fromDate = $labels[0] ?? $today;

    $bookingsTotal = dashboard_query_scalar(
        $pdo,
        "SELECT COUNT(*) AS cnt FROM bookings WHERE honeypot_triggered = 0"
    );
    $bookingsNew = dashboard_query_scalar(
        $pdo,
        "SELECT COUNT(*) AS cnt FROM bookings WHERE honeypot_triggered = 0 AND status IN ('new','new_email_failed')"
    );
    $bookingsToday = dashboard_query_scalar(
        $pdo,
        "SELECT COUNT(*) AS cnt FROM bookings WHERE honeypot_triggered = 0 AND substr(created_at, 1, 10) = :today",
        [':today' => $today]
    );
    $adminActionsToday = dashboard_query_scalar(
        $pdo,
        "SELECT COUNT(*) AS cnt FROM admin_log WHERE substr(created_at, 1, 10) = :today",
        [':today' => $today]
    );

    $statusStmt = $pdo->query("
        SELECT status, COUNT(*) AS cnt
        FROM bookings
        WHERE honeypot_triggered = 0
        GROUP BY status
        ORDER BY cnt DESC, status ASC
    ");
    $statusRows = $statusStmt->fetchAll() ?: [];
    $bookingsStatus = array_map(static function ($row) {
        $status = trim((string) ($row['status'] ?? ''));
        return [
            'status' => $status,
            'label' => dashboard_booking_status_label($status),
            'count' => (int) ($row['cnt'] ?? 0),
        ];
    }, $statusRows);

    $bookingDailyMap = array_fill_keys($labels, 0);
    $bookingDailyStmt = $pdo->prepare("
        SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS cnt
        FROM bookings
        WHERE honeypot_triggered = 0
          AND substr(created_at, 1, 10) >= :from_date
        GROUP BY day
        ORDER BY day ASC
    ");
    $bookingDailyStmt->execute([':from_date' => $fromDate]);
    foreach (($bookingDailyStmt->fetchAll() ?: []) as $row) {
        $day = trim((string) ($row['day'] ?? ''));
        if (!array_key_exists($day, $bookingDailyMap)) {
            continue;
        }
        $bookingDailyMap[$day] = (int) ($row['cnt'] ?? 0);
    }
    $bookingsDaily = [];
    foreach ($labels as $day) {
        $bookingsDaily[] = [
            'date' => $day,
            'label' => dashboard_short_label($day),
            'count' => (int) ($bookingDailyMap[$day] ?? 0),
        ];
    }

    $adminDailyMap = array_fill_keys($labels, 0);
    $adminDailyStmt = $pdo->prepare("
        SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS cnt
        FROM admin_log
        WHERE substr(created_at, 1, 10) >= :from_date
        GROUP BY day
        ORDER BY day ASC
    ");
    $adminDailyStmt->execute([':from_date' => $fromDate]);
    foreach (($adminDailyStmt->fetchAll() ?: []) as $row) {
        $day = trim((string) ($row['day'] ?? ''));
        if (!array_key_exists($day, $adminDailyMap)) {
            continue;
        }
        $adminDailyMap[$day] = (int) ($row['cnt'] ?? 0);
    }
    $adminActionsDaily = [];
    foreach ($labels as $day) {
        $adminActionsDaily[] = [
            'date' => $day,
            'label' => dashboard_short_label($day),
            'count' => (int) ($adminDailyMap[$day] ?? 0),
        ];
    }

    $topRoomsStmt = $pdo->query("
        SELECT room_code, COUNT(*) AS cnt
        FROM bookings
        WHERE honeypot_triggered = 0
          AND room_code <> ''
          AND room_code IS NOT NULL
        GROUP BY room_code
        ORDER BY cnt DESC, room_code ASC
        LIMIT 8
    ");
    $topRooms = array_map(static function ($row) {
        $roomCode = trim((string) ($row['room_code'] ?? ''));
        $roomLabel = $roomCode;
        if (preg_match('/^room-(\d{1,2})$/', $roomCode, $match) === 1) {
            $roomLabel = 'Номер ' . (int) $match[1];
        }
        return [
            'room_code' => $roomCode,
            'room_label' => $roomLabel,
            'count' => (int) ($row['cnt'] ?? 0),
        ];
    }, ($topRoomsStmt->fetchAll() ?: []));

    $recentActionsStmt = $pdo->query("
        SELECT action, details, ip_address, created_at
        FROM admin_log
        ORDER BY created_at DESC
        LIMIT 12
    ");
    $recentActions = array_map(static function ($row) {
        return [
            'action' => trim((string) ($row['action'] ?? '')),
            'details' => trim((string) ($row['details'] ?? '')),
            'ip_address' => trim((string) ($row['ip_address'] ?? '')),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
        ];
    }, ($recentActionsStmt->fetchAll() ?: []));

    $reviewsData = dashboard_read_reviews_data($REVIEWS_FILE);
    $approvedReviews = (int) count($reviewsData['approved']);
    $pendingReviews = (int) count($reviewsData['pending']);
    $questionsTotal = (int) count($reviewsData['questions']);

    $reviewsDailyMap = array_fill_keys($labels, ['reviews' => 0, 'questions' => 0]);
    foreach (array_merge($reviewsData['approved'], $reviewsData['pending']) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $day = dashboard_read_date_from_item($item);
        if ($day === null || !array_key_exists($day, $reviewsDailyMap)) {
            continue;
        }
        $reviewsDailyMap[$day]['reviews']++;
    }
    foreach ($reviewsData['questions'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $day = dashboard_read_date_from_item($item);
        if ($day === null || !array_key_exists($day, $reviewsDailyMap)) {
            continue;
        }
        $reviewsDailyMap[$day]['questions']++;
    }

    $reviewsDaily = [];
    foreach ($labels as $day) {
        $reviewsDaily[] = [
            'date' => $day,
            'label' => dashboard_short_label($day),
            'reviews' => (int) ($reviewsDailyMap[$day]['reviews'] ?? 0),
            'questions' => (int) ($reviewsDailyMap[$day]['questions'] ?? 0),
        ];
    }

    json_response([
        'success' => true,
        'period_days' => $days,
        'summary' => [
            'bookings_total' => $bookingsTotal,
            'bookings_new' => $bookingsNew,
            'bookings_today' => $bookingsToday,
            'reviews_pending' => $pendingReviews,
            'reviews_approved' => $approvedReviews,
            'questions_total' => $questionsTotal,
            'admin_actions_today' => $adminActionsToday,
        ],
        'bookings_status' => $bookingsStatus,
        'bookings_daily' => $bookingsDaily,
        'reviews_daily' => $reviewsDaily,
        'top_rooms' => $topRooms,
        'admin_actions_recent' => $recentActions,
        'admin_actions_daily' => $adminActionsDaily,
        'generated_at' => date('c'),
    ]);
} catch (Throwable $e) {
    error_log('Dashboard API error: ' . $e->getMessage());
    error_response('Внутрішня помилка сервера', 500);
}


<?php
/**
 * Rooms API
 * Secure room data and image management endpoint.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (send_api_headers(['GET', 'POST', 'OPTIONS'])) {
    exit;
}

initStorage();
$db = Database::getInstance();
$ip = get_client_ip();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = read_input_payload();
$action = strtolower(trim((string) ($_GET['action'] ?? ($input['action'] ?? ''))));

define('ROOMS_DATA_DIR', DATA_DIR . 'rooms/');
define('ROOMS_IMAGES_DIR', dirname(__DIR__) . '/storage/uploads/rooms/');
define('ROOM_IMAGES_MAP', DATA_DIR . 'room-images.json');
define('ROOMS_MIN_ID', 1);
define('ROOMS_MAX_ID', 20);
define('ROOM_CAPACITY_FALLBACK', [
    1 => 3, 2 => 3, 3 => 4, 4 => 2, 5 => 4,
    6 => 4, 7 => 4, 8 => 4, 9 => 6, 10 => 6,
    11 => 4, 12 => 6, 13 => 8, 14 => 2, 15 => 2,
    16 => 2, 17 => 3, 18 => 3, 19 => 6, 20 => 6,
]);
define('ROOM_TYPE_FALLBACK', [
    1 => 'lux',
    9 => 'bunk',
    10 => 'bunk',
    11 => 'lux',
    12 => 'bunk',
    13 => 'lux',
    18 => 'economy',
    19 => 'future',
    20 => 'future',
]);

function normalize_room_id($value): int
{
    $id = (int) $value;
    if ($id < ROOMS_MIN_ID || $id > ROOMS_MAX_ID) {
        return 0;
    }
    return $id;
}

function get_room_path(int $id): string
{
    return ROOMS_DATA_DIR . "room-{$id}.json";
}

function fallback_room_type(int $id): string
{
    $type = ROOM_TYPE_FALLBACK[$id] ?? 'standard';
    return sanitize_text_field($type, 20);
}

function fallback_room_capacity(int $id): int
{
    $capacity = (int) (ROOM_CAPACITY_FALLBACK[$id] ?? 2);
    return max(1, min(20, $capacity));
}

function fallback_room_price(int $id, int $capacity, string $type): int
{
    $baseByCapacity = [
        2 => 1700,
        3 => 2000,
        4 => 2300,
        6 => 2900,
        8 => 3600,
    ];

    $price = (int) ($baseByCapacity[$capacity] ?? (1500 + $capacity * 280));
    if ($type === 'lux') {
        $price += 450;
    } elseif ($type === 'economy') {
        $price -= 250;
    } elseif ($type === 'future') {
        $price -= 100;
    }

    return max(900, $price);
}

function fallback_room_cover(int $id): string
{
    $cover = "/storage/uploads/rooms/room-{$id}/cover.webp";
    $fullPath = dirname(__DIR__) . $cover;
    if (is_file($fullPath)) {
        return $cover;
    }
    return '/assets/images/placeholders/no-image.svg';
}

function build_fallback_room(int $id): array
{
    $capacity = fallback_room_capacity($id);
    $type = fallback_room_type($id);
    $price = fallback_room_price($id, $capacity, $type);
    $cover = fallback_room_cover($id);

    return [
        'id' => $id,
        'title' => "Номер {$id}",
        'summary' => "Затишний номер №{$id} для відпочинку біля озера Світязь.",
        'description' => "Номер {$id} підходить для {$capacity} гостей. Для уточнення деталей і доступності зверніться до адміністратора.",
        'type' => $type,
        'capacity' => $capacity,
        'guests' => $capacity,
        'pricePerNight' => $price,
        'price' => "{$price} грн",
        'amenities' => [
            'Wi-Fi',
            'Приватна ванна',
            'Телевізор',
        ],
        'rules' => [
            'Паління в номері заборонено',
            'Шум після 22:00 заборонено',
            "Заселення з " . POLICY_CHECKIN_TIME . ", виселення до " . POLICY_CHECKOUT_TIME,
            "Попередня оплата " . POLICY_PREPAYMENT,
        ],
        'images' => [$cover],
        'cover' => $cover,
    ];
}

function normalize_room_type_label(string $value): string
{
    $raw = mb_strtolower(trim($value));
    if ($raw === '') {
        return 'standard';
    }

    if (str_contains($raw, 'люкс') || str_contains($raw, 'lux')) {
        return 'lux';
    }
    if (str_contains($raw, 'стандарт') || str_contains($raw, 'standard')) {
        return 'standard';
    }
    if (str_contains($raw, 'двоярус') || str_contains($raw, 'bunk')) {
        return 'bunk';
    }
    if (str_contains($raw, 'економ') || str_contains($raw, 'economy')) {
        return 'economy';
    }
    if (str_contains($raw, 'підготов') || str_contains($raw, 'future')) {
        return 'future';
    }

    return sanitize_text_field($raw, 30);
}

function normalize_room_text(string $value, int $limit = 5000): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($value));
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return sanitize_multiline_text($text, $limit);
}

function extract_first_int(string $value): int
{
    if (preg_match('/(\d{1,5})/u', $value, $match) !== 1) {
        return 0;
    }
    return (int) ($match[1] ?? 0);
}

function extract_node_text_with_breaks(?DOMNode $node): string
{
    if (!$node || !($node instanceof DOMElement) || !($node->ownerDocument instanceof DOMDocument)) {
        return '';
    }

    $html = (string) $node->ownerDocument->saveHTML($node);
    if ($html === '') {
        return '';
    }

    $html = preg_replace('#^<p\b[^>]*>#i', '', $html) ?? $html;
    $html = preg_replace('#</p>$#i', '', $html) ?? $html;
    $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return normalize_room_text($text, 5000);
}

function parse_room_html_template(int $id): ?array
{
    $path = dirname(__DIR__) . "/rooms/room-{$id}/index.html";
    if (!is_file($path) || !is_readable($path) || !class_exists('DOMDocument')) {
        return null;
    }

    $html = file_get_contents($path);
    if ($html === false || trim($html) === '') {
        return null;
    }

    $dom = new DOMDocument();
    $prevUseErrors = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prevUseErrors);
    if (!$loaded) {
        return null;
    }

    $xpath = new DOMXPath($dom);
    $q = static function (string $expr) use ($xpath): string {
        $node = $xpath->query($expr)->item(0);
        return $node ? trim((string) $node->textContent) : '';
    };

    $title = sanitize_text_field($q('//div[contains(@class,"room-detail__header")]//h1'), 120);
    if ($title === '') {
        $title = "Номер {$id}";
    }

    $summary = '';
    $metaDesc = $xpath->query('//meta[@name="description"]')->item(0);
    if ($metaDesc instanceof DOMElement) {
        $summary = sanitize_text_field((string) $metaDesc->getAttribute('content'), 260);
    }

    $descriptionNode = $xpath->query('//section[contains(@class,"room-detail")]//div[contains(@class,"content")]/p[1]')->item(0);
    $description = extract_node_text_with_breaks($descriptionNode);
    if ($description === '') {
        $description = sanitize_multiline_text($summary, 5000);
    }

    $capacityRaw = extract_first_int($q('(//div[contains(@class,"room-detail__header")]//div[contains(@class,"room-meta")]//span[contains(@class,"pill")])[1]'));
    $capacity = $capacityRaw > 0 ? max(1, min(20, $capacityRaw)) : fallback_room_capacity($id);

    $type = normalize_room_type_label($q('(//div[contains(@class,"room-detail__header")]//div[contains(@class,"room-meta")]//span[contains(@class,"pill")])[2]'));
    if ($type === '') {
        $type = fallback_room_type($id);
    }

    $priceRaw = extract_first_int($q('//div[contains(@class,"room-detail__header")]//span[contains(@class,"price-pill")]'));
    $pricePerNight = $priceRaw > 0 ? $priceRaw : fallback_room_price($id, $capacity, $type);

    $amenities = [];
    foreach ($xpath->query('//ul[contains(@class,"list-check")]/li') as $node) {
        $text = sanitize_text_field((string) $node->textContent, 120);
        if ($text !== '') {
            $amenities[] = $text;
        }
    }
    if (count($amenities) === 0) {
        $amenities = ['Wi-Fi', 'Приватна ванна', 'Телевізор'];
    }

    $rules = [];
    foreach ($xpath->query('//ul[contains(@class,"list-bullet")]/li') as $node) {
        $text = sanitize_text_field((string) $node->textContent, 180);
        if ($text !== '') {
            $rules[] = $text;
        }
    }
    if (count($rules) === 0) {
        $rules = [
            'Паління в номері заборонено',
            'Шум після 22:00 заборонено',
            "Заселення з " . POLICY_CHECKIN_TIME . ", виселення до " . POLICY_CHECKOUT_TIME,
            "Попередня оплата " . POLICY_PREPAYMENT,
        ];
    }

    $images = [];
    foreach ($xpath->query('//div[contains(@class,"room-carousel__container")]//img') as $imgNode) {
        if (!$imgNode instanceof DOMElement) {
            continue;
        }
        $src = normalize_room_image_path((string) $imgNode->getAttribute('src'));
        if ($src !== '') {
            $images[] = $src;
        }
    }
    $images = unique_room_image_paths($images);

    $cover = $images[0] ?? fallback_room_cover($id);
    if ($summary === '') {
        $summary = sanitize_text_field($description !== '' ? $description : "Затишний номер №{$id} для відпочинку біля озера Світязь.", 260);
    }

    return [
        'id' => $id,
        'title' => $title,
        'summary' => $summary,
        'description' => $description,
        'type' => $type,
        'capacity' => $capacity,
        'guests' => $capacity,
        'pricePerNight' => $pricePerNight,
        'price' => "{$pricePerNight} грн",
        'amenities' => $amenities,
        'rules' => $rules,
        'images' => $images,
        'cover' => $cover,
    ];
}

function get_room(int $id): ?array
{
    if ($id < ROOMS_MIN_ID || $id > ROOMS_MAX_ID) {
        return null;
    }

    $file = get_room_path($id);
    if (!file_exists($file)) {
        $parsed = parse_room_html_template($id);
        if (is_array($parsed)) {
            // Best-effort bootstrap: persist parsed room JSON to avoid HTML edits later.
            save_room($id, $parsed);
            return $parsed;
        }
        return build_fallback_room($id);
    }

    $decoded = svh_read_json_file($file, []);
    if (!is_array($decoded)) {
        return build_fallback_room($id);
    }

    return $decoded;
}

function save_room(int $id, array $data): bool
{
    return svh_write_json_file(get_room_path($id), $data);
}

function get_all_rooms(): array
{
    $roomsById = [];
    foreach (glob(ROOMS_DATA_DIR . 'room-*.json') ?: [] as $file) {
        $room = svh_read_json_file($file, []);
        if (!is_array($room)) {
            continue;
        }

        $id = normalize_room_id($room['id'] ?? 0);
        if ($id <= 0) {
            if (preg_match('/room-(\d+)\.json$/', $file, $match) === 1) {
                $id = normalize_room_id((int) ($match[1] ?? 0));
            }
        }
        if ($id <= 0) {
            continue;
        }

        $roomsById[$id] = $room;
    }

    $rooms = [];
    for ($id = ROOMS_MIN_ID; $id <= ROOMS_MAX_ID; $id++) {
        if (isset($roomsById[$id]) && is_array($roomsById[$id])) {
            $rooms[] = $roomsById[$id];
            continue;
        }
        $rooms[] = get_room($id) ?? build_fallback_room($id);
    }

    return $rooms;
}

function get_room_images_map(): array
{
    return svh_read_room_images_map(ROOM_IMAGES_MAP);
}

function save_room_images_map(array $data): bool
{
    return svh_write_room_images_map($data, ROOM_IMAGES_MAP);
}

function normalize_room_image_path(string $path): string
{
    $value = trim(str_replace('\\', '/', $path));
    if ($value === '') {
        return '';
    }

    // Backward compatibility: migrate legacy asset paths to data-storage URLs.
    $legacyPrefix = '/assets/images/rooms/';
    $currentPrefix = '/storage/uploads/rooms/';
    if (strpos($value, $legacyPrefix) === 0) {
        $value = $currentPrefix . substr($value, strlen($legacyPrefix));
    }

    if (strpos($value, $currentPrefix) !== 0) {
        return '';
    }

    return $value;
}

function unique_room_image_paths(array $items): array
{
    $result = [];
    $seen = [];
    foreach ($items as $item) {
        $path = normalize_room_image_path((string) $item);
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $result[] = $path;
    }
    return $result;
}

function extract_room_id_from_image_path(string $path): int
{
    if (preg_match('#/room-(\d+)/#', $path, $match) !== 1) {
        return 0;
    }
    return normalize_room_id((int) ($match[1] ?? 0));
}

function append_uploaded_paths_to_room_map(int $roomId, array $uploaded): bool
{
    $map = get_room_images_map();
    $key = (string) $roomId;
    $existing = is_array($map[$key] ?? null) ? $map[$key] : [];

    foreach ($uploaded as $item) {
        $path = normalize_room_image_path((string) ($item['path'] ?? ''));
        if ($path !== '') {
            $existing[] = $path;
        }
    }

    $map[$key] = unique_room_image_paths($existing);
    return save_room_images_map($map);
}

function remove_path_from_room_map(string $path): bool
{
    $normalized = normalize_room_image_path($path);
    if ($normalized === '') {
        return true;
    }

    $map = get_room_images_map();
    $roomId = extract_room_id_from_image_path($normalized);
    if ($roomId > 0) {
        $key = (string) $roomId;
        if (is_array($map[$key] ?? null)) {
            $map[$key] = array_values(array_filter(unique_room_image_paths($map[$key]), static function ($item) use ($normalized) {
                return $item !== $normalized;
            }));
            if (count($map[$key]) === 0) {
                unset($map[$key]);
            }
        }
    } else {
        foreach ($map as $key => $list) {
            if (!is_array($list)) {
                continue;
            }
            $map[$key] = array_values(array_filter(unique_room_image_paths($list), static function ($item) use ($normalized) {
                return $item !== $normalized;
            }));
        }
    }

    return save_room_images_map($map);
}

function remove_path_from_room_json(string $path): bool
{
    $normalized = normalize_room_image_path($path);
    if ($normalized === '') {
        return true;
    }

    $roomId = extract_room_id_from_image_path($normalized);
    if ($roomId <= 0) {
        return true;
    }

    $room = get_room($roomId);
    if (!$room) {
        return true;
    }

    $currentImages = is_array($room['images'] ?? null) ? $room['images'] : [];
    $room['images'] = array_values(array_filter(unique_room_image_paths($currentImages), static function ($item) use ($normalized) {
        return $item !== $normalized;
    }));

    if ((string) ($room['cover'] ?? '') === $normalized) {
        $room['cover'] = $room['images'][0] ?? '';
    }

    return save_room($roomId, $room);
}

function sanitize_room_payload(array $payload, int $id): array
{
    $room = $payload;
    $room['id'] = $id;
    $room['title'] = sanitize_text_field($payload['title'] ?? ("Номер {$id}"), 120);
    $room['summary'] = sanitize_text_field($payload['summary'] ?? '', 260);
    $room['description'] = sanitize_multiline_text($payload['description'] ?? '', 5000);
    $room['type'] = sanitize_text_field($payload['type'] ?? 'standard', 30);
    $room['capacity'] = max(1, min(20, (int) ($payload['capacity'] ?? ($payload['guests'] ?? 2))));
    $room['guests'] = $room['capacity'];
    $room['pricePerNight'] = max(0, (int) ($payload['pricePerNight'] ?? ($payload['price'] ?? 0)));
    $room['price'] = $room['pricePerNight'] . ' грн';

    $amenities = is_array($payload['amenities'] ?? null) ? $payload['amenities'] : [];
    $rules = is_array($payload['rules'] ?? null) ? $payload['rules'] : [];
    $images = is_array($payload['images'] ?? null) ? $payload['images'] : [];

    $room['amenities'] = array_values(array_filter(array_map(static function ($item) {
        return sanitize_text_field($item, 120);
    }, $amenities)));

    $room['rules'] = array_values(array_filter(array_map(static function ($item) {
        return sanitize_text_field($item, 180);
    }, $rules)));

    $room['images'] = array_values(array_filter(array_map(static function ($item) {
        return normalize_room_image_path((string) $item);
    }, $images)));

    $room['cover'] = normalize_room_image_path((string) ($payload['cover'] ?? ''));
    if ($room['cover'] === '' && count($room['images']) > 0) {
        $room['cover'] = $room['images'][0];
    }

    return $room;
}

function ensure_delete_path_safe(string $path): ?string
{
    $path = normalize_room_image_path($path);
    if ($path === '') {
        return null;
    }

    $fullPath = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $real = realpath($fullPath);
    if ($real === false) {
        return null;
    }

    $allowedRoots = [
        realpath(dirname(__DIR__) . '/storage/uploads/rooms'),
    ];

    foreach ($allowedRoots as $root) {
        if ($root && strpos($real, $root) === 0) {
            return $real;
        }
    }

    return null;
}

if ($method === 'GET') {
    if ($action === 'list') {
        json_response(['success' => true, 'rooms' => get_all_rooms()]);
    }

    if ($action === 'room') {
        $id = normalize_room_id($_GET['id'] ?? 0);
        if ($id <= 0) {
            error_response('Invalid room ID', 400);
        }

        $room = get_room($id);
        if (!$room) {
            error_response('Room not found', 404);
        }

        json_response(['success' => true, 'room' => $room]);
    }

    if ($action === 'images-map') {
        json_response(['success' => true, 'images' => get_room_images_map()]);
    }

    error_response('Invalid action', 400);
}

if ($method !== 'POST') {
    error_response('Method not allowed', 405);
}

require_admin_auth($db, $input);
enforce_rate_limit($db, $ip, 'rooms_admin_' . $action, 120, 3600);
require_csrf_token($input);

if (in_array($action, ['save', 'upload-image', 'delete-image', 'save-images-map'], true)) {
    maybe_auto_storage_backup('rooms-' . $action, 6 * 3600);
}

if ($action === 'save') {
    $id = normalize_room_id($_GET['id'] ?? ($input['id'] ?? 0));
    if ($id <= 0) {
        error_response('Invalid room ID', 400);
    }

    $roomData = $input;
    if (empty($roomData) || !is_array($roomData)) {
        error_response('Invalid room data', 400);
    }

    $sanitized = sanitize_room_payload($roomData, $id);
    if (!save_room($id, $sanitized)) {
        error_response('Failed to save room', 500);
    }

    $db->logAdminAction('room_save', 'room=' . $id, $ip);
    json_response(['success' => true, 'message' => "Room {$id} saved"]);
}

if ($action === 'upload-image') {
    $id = normalize_room_id($_GET['id'] ?? ($input['id'] ?? 0));
    if ($id <= 0) {
        error_response('Invalid room ID', 400);
    }

    $files = normalize_uploaded_files('image');
    if (count($files) === 0) {
        // Fallback for form field name image[]
        $files = normalize_uploaded_files('images');
    }
    if (count($files) === 0) {
        error_response('No file uploaded', 400);
    }

    if (count($files) > 15) {
        error_response('Too many files. Maximum 15 images per upload.', 400);
    }

    $roomDir = ROOMS_IMAGES_DIR . "room-{$id}/";
    $uploaded = [];
    $errors = [];

    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $result = process_uploaded_image($file, $roomDir, "/storage/uploads/rooms/room-{$id}/", 1920, 1280, 10 * 1024 * 1024);
        if (!($result['success'] ?? false)) {
            $errors[] = (string) ($result['error'] ?? 'Failed to upload image');
            continue;
        }

        $uploaded[] = $result;
    }

    if (count($uploaded) === 0) {
        $message = count($errors) > 0 ? implode('; ', array_slice($errors, 0, 3)) : 'Failed to upload image';
        error_response($message, 400);
    }

    if (!append_uploaded_paths_to_room_map($id, $uploaded)) {
        $errors[] = 'Uploaded, but room-images map was not updated';
    }

    $db->logAdminAction('room_upload_image', 'room=' . $id . ';files=' . count($uploaded), $ip);

    $payload = [
        'success' => true,
        'count' => count($uploaded),
        'files' => $uploaded,
        'errors' => $errors,
    ];

    // Backward compatibility for previous single-file clients.
    if (count($uploaded) === 1) {
        $payload = array_merge($uploaded[0], $payload);
    }

    json_response($payload);
}

if ($action === 'delete-image') {
    $path = (string) ($input['path'] ?? '');
    $safePath = ensure_delete_path_safe($path);
    if (!$safePath || !file_exists($safePath)) {
        error_response('Image not found', 404);
    }

    if (!unlink($safePath)) {
        error_response('Failed to delete image', 500);
    }

    $normalizedPath = normalize_room_image_path($path);
    $warnings = [];
    if ($normalizedPath !== '') {
        if (!remove_path_from_room_map($normalizedPath)) {
            $warnings[] = 'Image deleted, but room-images map update failed';
        }
        if (!remove_path_from_room_json($normalizedPath)) {
            $warnings[] = 'Image deleted, but room JSON update failed';
        }
    }

    $db->logAdminAction('room_delete_image', $path, $ip);
    json_response(['success' => true, 'warnings' => $warnings]);
}

if ($action === 'save-images-map') {
    if (!is_array($input)) {
        error_response('Invalid images map', 400);
    }

    $sanitizedMap = [];
    foreach ($input as $roomKey => $items) {
        $roomId = normalize_room_id($roomKey);
        if ($roomId <= 0 || !is_array($items)) {
            continue;
        }
        $normalized = unique_room_image_paths($items);
        if (count($normalized) > 0) {
            $sanitizedMap[(string) $roomId] = $normalized;
        }
    }

    if (!save_room_images_map($sanitizedMap)) {
        error_response('Failed to save images map', 500);
    }

    $db->logAdminAction('room_save_images_map', null, $ip);
    json_response(['success' => true]);
}

error_response('Invalid action', 400);

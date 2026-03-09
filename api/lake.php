<?php
/**
 * Lake Guide API
 * Public structured content for /ozero-svityaz/ page.
 */

require_once __DIR__ . '/security.php';

if (send_api_headers(['GET', 'OPTIONS'])) {
    exit;
}

initStorage();

define('LAKE_GUIDE_FILE', DATA_DIR . 'lake-guide.json');

function lake_default_payload(): array
{
    return [
        'updatedAt' => date('Y-m-d'),
        'title' => 'Актуальна довідка про Світязь',
        'intro' => 'Ключові факти про озеро та маршрути доїзду.',
        'facts' => [],
        'routes' => [],
        'tips' => [],
        'sources' => [],
    ];
}

function lake_trimmed_string($value, int $maxLength): string
{
    return sanitize_text_field((string) ($value ?? ''), $maxLength);
}

function load_lake_guide_payload(): array
{
    $default = lake_default_payload();
    if (!is_file(LAKE_GUIDE_FILE) || !is_readable(LAKE_GUIDE_FILE)) {
        return $default;
    }

    $raw = file_get_contents(LAKE_GUIDE_FILE);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $default;
    }

    $sources = [];
    foreach (($decoded['sources'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = lake_trimmed_string($row['id'] ?? '', 80);
        $label = lake_trimmed_string($row['label'] ?? '', 180);
        $url = trim((string) ($row['url'] ?? ''));
        if ($id === '' || $label === '' || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }
        $sources[$id] = [
            'id' => $id,
            'label' => $label,
            'url' => $url,
        ];
    }

    $facts = [];
    foreach (($decoded['facts'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $title = lake_trimmed_string($row['title'] ?? '', 140);
        $value = lake_trimmed_string($row['value'] ?? '', 80);
        $description = lake_trimmed_string($row['description'] ?? '', 260);
        $sourceId = lake_trimmed_string($row['sourceId'] ?? '', 80);
        if ($title === '' || $value === '') {
            continue;
        }
        $facts[] = [
            'title' => $title,
            'value' => $value,
            'description' => $description,
            'sourceId' => isset($sources[$sourceId]) ? $sourceId : '',
        ];
    }

    $routes = [];
    foreach (($decoded['routes'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $title = lake_trimmed_string($row['title'] ?? '', 140);
        $details = lake_trimmed_string($row['details'] ?? '', 340);
        $sourceId = lake_trimmed_string($row['sourceId'] ?? '', 80);
        if ($title === '' || $details === '') {
            continue;
        }
        $routes[] = [
            'title' => $title,
            'details' => $details,
            'sourceId' => isset($sources[$sourceId]) ? $sourceId : '',
        ];
    }

    $tips = [];
    foreach (($decoded['tips'] ?? []) as $tip) {
        $text = lake_trimmed_string($tip, 220);
        if ($text !== '') {
            $tips[] = $text;
        }
    }

    return [
        'updatedAt' => lake_trimmed_string($decoded['updatedAt'] ?? $default['updatedAt'], 40),
        'title' => lake_trimmed_string($decoded['title'] ?? $default['title'], 140),
        'intro' => lake_trimmed_string($decoded['intro'] ?? $default['intro'], 320),
        'facts' => $facts,
        'routes' => $routes,
        'tips' => $tips,
        'sources' => array_values($sources),
    ];
}

$action = strtolower(trim((string) ($_GET['action'] ?? 'guide')));
if ($action !== 'guide') {
    error_response('Invalid action', 400);
}

json_response([
    'success' => true,
    'guide' => load_lake_guide_payload(),
]);


<?php

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../includes/site-content.php';

if (send_api_headers(['GET', 'OPTIONS'])) {
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    error_response('Method not allowed', 405);
}

$content = svh_read_site_content();

header('Cache-Control: public, max-age=120');
json_response([
    'success' => true,
    'content' => $content,
    'updated_at' => (string) ($content['updated_at'] ?? ''),
]);

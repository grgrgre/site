<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/site-content.php';

if (send_api_headers(['GET', 'OPTIONS'])) {
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    error_response('Method not allowed', 405);
}

$content = svh_read_site_content();

header('Cache-Control: public, max-age=120');
svh_respond_legacy_success([
    'content' => $content,
    'updated_at' => (string) ($content['updated_at'] ?? ''),
]);

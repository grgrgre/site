#!/usr/bin/env php
<?php

declare(strict_types=1);

define('SVH_TG_LIBRARY_ONLY', true);
require_once __DIR__ . '/../api/telegram.php';

const TG_POLL_OFFSET_FILE = DATA_DIR . 'telegram-update-offset.json';
const TG_POLL_LIMIT = 20;

function tg_poll_read_offset(): int
{
    $data = svh_read_json_file(TG_POLL_OFFSET_FILE, []);
    $offset = (int) ($data['offset'] ?? 0);
    return max(0, $offset);
}

function tg_poll_write_offset(int $offset): bool
{
    return svh_write_json_file(TG_POLL_OFFSET_FILE, [
        'offset' => max(0, $offset),
        'updated_at' => gmdate('c'),
    ]);
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run via CLI.\n");
    exit(1);
}

if (trim((string) TELEGRAM_BOT_TOKEN) === '') {
    fwrite(STDERR, "TELEGRAM_BOT_TOKEN is not configured.\n");
    exit(1);
}

$offset = tg_poll_read_offset();
$result = telegram_api_request('getUpdates', [
    'offset' => (string) $offset,
    'limit' => (string) TG_POLL_LIMIT,
    'timeout' => '0',
    'allowed_updates' => json_encode(['message', 'callback_query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

if (!is_array($result) || (($result['ok'] ?? false) !== true)) {
    $description = (string) ($result['description'] ?? 'Telegram getUpdates failed');
    fwrite(STDERR, $description . "\n");
    exit((int) ($result['error_code'] ?? 1));
}

$updates = is_array($result['result'] ?? null) ? $result['result'] : [];
$processed = 0;
$failed = 0;
$lastOffset = $offset;

foreach ($updates as $update) {
    if (!is_array($update)) {
        continue;
    }

    $updateId = (int) ($update['update_id'] ?? 0);
    if ($updateId > 0) {
        $lastOffset = max($lastOffset, $updateId + 1);
    }

    try {
        tg_process_update_payload($update, $pdo, $db, $REVIEWS_FILE, $REVIEW_TOPICS, false);
        $processed++;
    } catch (Throwable $e) {
        $failed++;
        error_log('Telegram poll processing failed: ' . $e->getMessage());
    }
}

if ($lastOffset !== $offset) {
    tg_poll_write_offset($lastOffset);
}

echo json_encode([
    'success' => true,
    'processed' => $processed,
    'failed' => $failed,
    'updates_seen' => count($updates),
    'offset_before' => $offset,
    'offset_after' => $lastOffset,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

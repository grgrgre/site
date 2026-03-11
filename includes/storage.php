<?php

function svh_read_json_file(string $path, array $default = []): array
{
    if (!is_file($path) || !is_readable($path)) {
        return $default;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function svh_write_json_file(string $path, array $payload, int $mode = 0640): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    try {
        $suffix = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $suffix = (string) mt_rand(100000, 999999);
    }

    $tempPath = $path . '.tmp.' . $suffix;
    if (file_put_contents($tempPath, $json, LOCK_EX) === false) {
        return false;
    }

    if (!rename($tempPath, $path)) {
        @unlink($tempPath);
        return false;
    }

    @chmod($path, $mode);
    return true;
}

function svh_reviews_defaults(): array
{
    return [
        'topics' => [
            'rooms' => 'Номери',
            'territory' => 'Територія',
            'service' => 'Обслуговування',
            'location' => 'Локація',
            'general' => 'Загальні враження',
        ],
        'approved' => [],
        'pending' => [],
        'questions' => [],
        'nextId' => 100,
    ];
}

function svh_normalize_reviews_payload(array $data): array
{
    $normalized = array_merge(svh_reviews_defaults(), $data);

    foreach (['approved', 'pending', 'questions'] as $key) {
        if (!isset($normalized[$key]) || !is_array($normalized[$key])) {
            $normalized[$key] = [];
        }
    }

    if (!isset($normalized['topics']) || !is_array($normalized['topics'])) {
        $normalized['topics'] = svh_reviews_defaults()['topics'];
    }

    if (!isset($normalized['nextId']) || !is_numeric($normalized['nextId'])) {
        $maxId = 0;
        foreach (array_merge($normalized['approved'], $normalized['pending'], $normalized['questions']) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $maxId = max($maxId, (int) ($item['id'] ?? 0));
        }
        $normalized['nextId'] = $maxId + 1;
    } else {
        $normalized['nextId'] = (int) $normalized['nextId'];
    }

    return $normalized;
}

function svh_read_reviews_storage(?string $path = null): array
{
    $filePath = is_string($path) && $path !== '' ? $path : REVIEWS_FILE_PATH;
    return svh_normalize_reviews_payload(svh_read_json_file($filePath, svh_reviews_defaults()));
}

function svh_write_reviews_storage(array $data, ?string $path = null): bool
{
    $filePath = is_string($path) && $path !== '' ? $path : REVIEWS_FILE_PATH;
    return svh_write_json_file($filePath, svh_normalize_reviews_payload($data));
}

function svh_room_json_path(int $roomId): string
{
    return DATA_DIR . 'rooms/room-' . $roomId . '.json';
}

function svh_read_room_json(int $roomId): array
{
    if ($roomId <= 0) {
        return [];
    }

    return svh_read_json_file(svh_room_json_path($roomId), []);
}

function svh_write_room_json(int $roomId, array $payload): bool
{
    if ($roomId <= 0) {
        return false;
    }

    $payload['id'] = $roomId;
    return svh_write_json_file(svh_room_json_path($roomId), $payload);
}

function svh_read_room_images_map(?string $path = null): array
{
    $filePath = is_string($path) && $path !== '' ? $path : ROOM_IMAGES_FILE_PATH;
    $map = svh_read_json_file($filePath, []);
    return is_array($map) ? $map : [];
}

function svh_write_room_images_map(array $map, ?string $path = null): bool
{
    $filePath = is_string($path) && $path !== '' ? $path : ROOM_IMAGES_FILE_PATH;
    return svh_write_json_file($filePath, $map);
}

<?php

function svh_api_payload(bool $success, ?string $message = null, array $data = [], array $extra = []): array
{
    $payload = [
        'success' => $success,
        'error' => $success ? null : $message,
        'message' => $message ?? '',
        'data' => $data,
    ];

    foreach ($extra as $key => $value) {
        if (!array_key_exists((string) $key, $payload)) {
            $payload[$key] = $value;
        }
    }

    return $payload;
}

function svh_respond_success(array $data = [], ?string $message = null, int $status = 200, array $extra = []): void
{
    json_response(svh_api_payload(true, $message, $data, $extra), $status);
}

function svh_respond_error(string $message, int $status = 400, array $data = [], array $extra = []): void
{
    json_response(svh_api_payload(false, $message, $data, $extra), $status);
}

function svh_respond_legacy_success(array $fields = [], ?string $message = null, int $status = 200): void
{
    svh_respond_success($fields, $message, $status, $fields);
}

function svh_respond_legacy_error(string $message, int $status = 400, array $fields = []): void
{
    $payload = $fields;
    if (!isset($payload['error'])) {
        $payload['error'] = $message;
    }

    svh_respond_error($message, $status, $fields, $payload);
}

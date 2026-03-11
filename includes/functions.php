<?php

function svh_filter_input_scalar(int $type, string $name): ?string
{
    $value = filter_input($type, $name, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
    if ($value === null || $value === false || is_array($value)) {
        return null;
    }

    return (string) $value;
}

function svh_query_string(string $name, int $maxLength = 255, string $default = ''): string
{
    $value = svh_filter_input_scalar(INPUT_GET, $name);
    if ($value === null) {
        return $default;
    }

    return sanitize_text_field($value, $maxLength);
}

function svh_query_int(string $name, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $raw = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);
    if ($raw === null || $raw === false) {
        $value = $default;
    } else {
        $value = (int) $raw;
    }

    if ($min !== null && $value < $min) {
        $value = $min;
    }
    if ($max !== null && $value > $max) {
        $value = $max;
    }

    return $value;
}

function svh_post_string(string $name, int $maxLength = 255, string $default = ''): string
{
    $value = svh_filter_input_scalar(INPUT_POST, $name);
    if ($value === null) {
        return $default;
    }

    return sanitize_text_field($value, $maxLength);
}

function svh_input_string(array $input, string $name, int $maxLength = 255, string $default = ''): string
{
    if (!array_key_exists($name, $input)) {
        return $default;
    }

    return sanitize_text_field($input[$name], $maxLength);
}

function svh_input_multiline(array $input, string $name, int $maxLength = 2000, string $default = ''): string
{
    if (!array_key_exists($name, $input)) {
        return $default;
    }

    return sanitize_multiline_text($input[$name], $maxLength);
}

function svh_input_bool(array $input, string $name, bool $default = false): bool
{
    if (!array_key_exists($name, $input)) {
        return $default;
    }

    $value = filter_var($input[$name], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $value === null ? $default : $value;
}

function svh_request_user_agent(int $maxLength = 255): string
{
    return sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $maxLength);
}

function svh_require_json_request(): void
{
    $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
    if (strpos($contentType, 'application/json') !== 0) {
        svh_respond_error('Content-Type must be application/json', 415);
    }
}

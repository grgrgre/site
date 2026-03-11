<?php

function svh_detect_mime_type(string $path): string
{
    $mime = '';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $path) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
    } elseif (function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($path);
    }

    return strtolower(trim($mime));
}

function svh_upload_base_name(string $name, string $fallback = 'upload', int $maxLength = 60): string
{
    $base = strtolower(pathinfo($name, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9._-]+/i', '-', $base) ?? '';
    $base = trim($base, '-_.');

    if ($base === '') {
        $base = $fallback;
    }

    if (strlen($base) > $maxLength) {
        $base = rtrim(substr($base, 0, $maxLength), '-_.');
    }

    return $base !== '' ? $base : $fallback;
}

function svh_zip_signature_valid(string $filePath): bool
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return false;
    }

    $handle = @fopen($filePath, 'rb');
    if (!$handle) {
        return false;
    }

    $signature = (string) fread($handle, 4);
    fclose($handle);

    return in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
}

function svh_validate_uploaded_file(array $file, array $allowedMimeMap, int $maxSize, ?callable $signatureValidator = null): array
{
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['success' => false, 'error' => 'Invalid upload source'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxSize) {
        return ['success' => false, 'error' => 'File size is invalid'];
    }

    $originalName = basename((string) ($file['name'] ?? 'upload'));
    $originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = array_values(array_unique(array_map('strtolower', array_values($allowedMimeMap))));
    if ($originalExt !== '' && !in_array($originalExt, $allowedExtensions, true)) {
        return ['success' => false, 'error' => 'File extension is not allowed'];
    }

    $mime = svh_detect_mime_type($tmp);
    if ($mime === '' || !isset($allowedMimeMap[$mime])) {
        return ['success' => false, 'error' => 'File type is not allowed'];
    }

    if ($signatureValidator !== null && !$signatureValidator($tmp)) {
        return ['success' => false, 'error' => 'Invalid file signature'];
    }

    return [
        'success' => true,
        'tmp_name' => $tmp,
        'size' => $size,
        'mime' => $mime,
        'extension' => (string) $allowedMimeMap[$mime],
        'original_name' => $originalName,
        'base_name' => svh_upload_base_name($originalName),
    ];
}

function svh_store_uploaded_file(array $validated, string $targetDir, string $filenameBase, ?string $publicPrefix = null): array
{
    $extension = strtolower(trim((string) ($validated['extension'] ?? '')));
    $tmp = (string) ($validated['tmp_name'] ?? '');

    if ($extension === '' || $tmp === '') {
        return ['success' => false, 'error' => 'Validated upload payload is incomplete'];
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        return ['success' => false, 'error' => 'Unable to prepare upload directory'];
    }

    $targetDir = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $base = svh_upload_base_name($filenameBase, 'upload', 120);
    $filename = $base . '.' . $extension;
    $targetPath = $targetDir . $filename;
    $counter = 1;

    while (file_exists($targetPath) && $counter < 1000) {
        $filename = $base . '-' . $counter . '.' . $extension;
        $targetPath = $targetDir . $filename;
        $counter++;
    }

    if (file_exists($targetPath)) {
        return ['success' => false, 'error' => 'Unable to generate unique upload name'];
    }

    if (!move_uploaded_file($tmp, $targetPath)) {
        return ['success' => false, 'error' => 'Failed to store uploaded file'];
    }

    @chmod($targetPath, 0640);

    return [
        'success' => true,
        'filename' => $filename,
        'path' => $publicPrefix !== null
            ? rtrim($publicPrefix, '/') . '/' . $filename
            : $targetPath,
        'size' => (int) (filesize($targetPath) ?: 0),
    ];
}

function svh_process_gallery_upload(array $file): ?string
{
    $result = process_uploaded_image(
        $file,
        UPLOADS_DIR . 'site/',
        '/storage/uploads/site/',
        2400,
        1600,
        MAX_UPLOAD_SIZE
    );

    if (!($result['success'] ?? false)) {
        return null;
    }

    return (string) ($result['path'] ?? '');
}

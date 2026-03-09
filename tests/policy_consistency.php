<?php
/**
 * Simple policy consistency check:
 * - key pages must use data-policy hooks
 * - no stale "12:00" checkout wording in key public files
 * - policy API/reviews API must reference config constants
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';

$errors = [];

$pagesWithPolicyHook = [
    __DIR__ . '/../index.html',
    __DIR__ . '/../booking/index.html',
    __DIR__ . '/../reviews/index.html',
];

foreach ($pagesWithPolicyHook as $page) {
    $raw = @file_get_contents($page);
    if ($raw === false) {
        $errors[] = 'Cannot read file: ' . $page;
        continue;
    }

    if (strpos($raw, 'data-policy-checkin-text') === false) {
        $errors[] = 'Missing data-policy-checkin-text hook: ' . $page;
    }
}

$filesToScan = [
    __DIR__ . '/../index.html',
    __DIR__ . '/../booking/index.html',
    __DIR__ . '/../reviews/index.html',
    __DIR__ . '/../assets/js/policy.js',
    __DIR__ . '/../assets/js/app.js',
];

foreach ($filesToScan as $file) {
    $raw = @file_get_contents($file);
    if ($raw === false) {
        $errors[] = 'Cannot read file: ' . $file;
        continue;
    }

    if (preg_match('/(виїзд|виселення)\D{0,30}12:00/ui', $raw)) {
        $errors[] = 'Found stale checkout time (12:00): ' . $file;
    }
}

$roomPolicyFiles = array_merge(
    glob(__DIR__ . '/../rooms/room-*/index.html') ?: [],
    glob(__DIR__ . '/../storage/data/rooms/room-*.json') ?: []
);

foreach ($roomPolicyFiles as $file) {
    $raw = @file_get_contents($file);
    if ($raw === false) {
        $errors[] = 'Cannot read file: ' . $file;
        continue;
    }

    if (strpos($raw, POLICY_CHECKIN_TIME) === false || strpos($raw, POLICY_CHECKOUT_TIME) === false) {
        $errors[] = 'Room policy text does not match configured check-in/check-out: ' . $file;
    }
}

$apiRefs = [
    __DIR__ . '/../api/policy.php',
    __DIR__ . '/../api/reviews.php',
];

foreach ($apiRefs as $file) {
    $raw = @file_get_contents($file);
    if ($raw === false) {
        $errors[] = 'Cannot read file: ' . $file;
        continue;
    }

    foreach (['POLICY_CHECKIN_TIME', 'POLICY_CHECKOUT_TIME', 'POLICY_PREPAYMENT'] as $constant) {
        if (strpos($raw, $constant) === false) {
            $errors[] = sprintf('Missing policy constant "%s" in %s', $constant, $file);
        }
    }
}

if (!empty($errors)) {
    fwrite(STDERR, "Policy consistency check failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}

echo sprintf(
    "Policy consistency check passed (check-in %s, check-out %s, prepayment %s)\n",
    POLICY_CHECKIN_TIME,
    POLICY_CHECKOUT_TIME,
    POLICY_PREPAYMENT
);

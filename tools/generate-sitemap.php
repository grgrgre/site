<?php
/**
 * Refresh sitemap.xml lastmod values using source file mtimes.
 *
 * Usage:
 *   php tools/generate-sitemap.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$sitemapPath = $root . '/sitemap.xml';

if (!is_file($sitemapPath) || !is_readable($sitemapPath)) {
    fwrite(STDERR, "[ERROR] sitemap.xml is missing or unreadable\n");
    exit(1);
}

libxml_use_internal_errors(true);
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;

if (!$dom->load($sitemapPath)) {
    fwrite(STDERR, "[ERROR] Failed to parse sitemap.xml\n");
    exit(1);
}

$updated = 0;
$skipped = 0;

/** @var DOMElement $url */
foreach ($dom->getElementsByTagName('url') as $url) {
    $loc = '';
    foreach ($url->childNodes as $childNode) {
        if (!$childNode instanceof DOMElement) {
            continue;
        }
        if ($childNode->localName === 'loc') {
            $loc = trim((string) $childNode->textContent);
            break;
        }
    }

    if ($loc === '') {
        $skipped++;
        continue;
    }

    $sourceFile = resolve_source_file($root, $loc);
    if ($sourceFile === null) {
        $skipped++;
        continue;
    }

    $lastmodValue = gmdate('Y-m-d', (int) filemtime($sourceFile));
    $lastmodNode = null;

    foreach ($url->childNodes as $childNode) {
        if ($childNode instanceof DOMElement && $childNode->localName === 'lastmod') {
            $lastmodNode = $childNode;
            break;
        }
    }

    if ($lastmodNode === null) {
        $lastmodNode = $dom->createElement('lastmod');
        $url->appendChild($lastmodNode);
    }

    $lastmodNode->textContent = $lastmodValue;
    $updated++;
}

if ($dom->save($sitemapPath) === false) {
    fwrite(STDERR, "[ERROR] Failed to write sitemap.xml\n");
    exit(1);
}

fwrite(STDOUT, "[OK] sitemap.xml refreshed: updated={$updated}, skipped={$skipped}\n");
exit(0);

function resolve_source_file(string $root, string $loc): ?string
{
    $path = parse_url($loc, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }

    $normalized = '/' . ltrim(rawurldecode($path), '/');

    if ($normalized === '/') {
        $candidate = $root . '/index.html';
        return is_file($candidate) ? $candidate : null;
    }

    $trimmed = trim($normalized, '/');
    if ($trimmed === '') {
        return null;
    }

    $candidates = [
        $root . '/' . $trimmed . '/index.html',
        $root . '/' . $trimmed . '.html',
        $root . '/' . $trimmed,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

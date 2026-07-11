<?php
/**
 * Remove duplicate hardcoded favicon lines after wuc_portal_favicon_links() calls.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$duplicateTail = '#\s*<link rel="icon" type="image/png" sizes="32x32" href="[^"]*/images/favicon-32\.png">\s*\n'
    . '\s*<link rel="icon" type="image/png" sizes="16x16" href="[^"]*/images/favicon-16\.png">\s*\n'
    . '\s*<link rel="apple-touch-icon" href="[^"]*/images/apple-touch-icon\.png">\s*\n#s';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $path = $fileInfo->getPathname();
    $src = file_get_contents($path);
    if ($src === false || strpos($src, 'wuc_portal_favicon_links') === false) {
        continue;
    }
    $updated = preg_replace($duplicateTail, "\n", $src, 1) ?? $src;
    if ($updated !== $src) {
        file_put_contents($path, $updated);
        echo 'CLEANED: ' . str_replace('\\', '/', substr($path, strlen($root) + 1)) . "\n";
    }
}

echo "Done.\n";

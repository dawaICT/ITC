<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$excluded = ['vendor', 'node_modules', 'backups', 'wuc-nextjs-portal', 'wucportal-modern'];
$failures = [];
$count = 0;
$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $item) use ($excluded): bool {
    if (!$item->isDir()) return true;
    $name = $item->getFilename();
    return !in_array($name, $excluded, true) && !str_starts_with($name, 'tmp') && $name !== '.git';
});
$iterator = new RecursiveIteratorIterator($filter);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $first = explode('/', $relative, 2)[0];
    if (in_array($first, $excluded, true) || str_starts_with($first, 'tmp')) continue;
    $count++;
    try {
        token_get_all((string)file_get_contents($file->getPathname()), TOKEN_PARSE);
    } catch (ParseError $e) {
        $failures[] = $relative . ': ' . $e->getMessage();
    }
}
foreach ($failures as $failure) echo "FAIL {$failure}\n";
echo "PARSED {$count} PHP files; failures=" . count($failures) . "\n";
exit($failures ? 1 : 0);

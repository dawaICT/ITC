<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$errors = [];
$checkedPages = 0;

$navbar = (string)file_get_contents($root . '/students/includes/navbar.php');
$premiumPosition = strpos($navbar, '/wucportal/css/wuc-premium.css');
$unifiedPosition = strpos($navbar, '/wucportal/students/css/student-unified.css');
if ($premiumPosition === false || $unifiedPosition === false || $unifiedPosition < $premiumPosition) {
    $errors[] = 'student-unified.css must load after shared premium CSS';
}

$unifiedCss = (string)file_get_contents($root . '/students/css/student-unified.css');
if (!str_contains($unifiedCss, '--sp-bg: #f8f9fa;')) {
    $errors[] = 'student background must match the timetable #f8f9fa baseline';
}
if (!str_contains($unifiedCss, '.student-page-heading')) {
    $errors[] = 'shared student page heading component is missing';
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/students', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    $source = (string)file_get_contents($file->getPathname());
    if (!str_contains($source, '<body') || !preg_match('/(?:include|require).*includes\/navbar\.php/s', str_replace('\\', '/', $source))) {
        continue;
    }
    if (str_ends_with($path, '/students/includes/student_header.php')) {
        continue;
    }
    if (preg_match('#/students/(?:test|debug|check_react_setup)#i', $path)) {
        continue;
    }

    $checkedPages++;
    if (!preg_match('/class=["\'][^"\']*(?:content-wrapper|dash-content|main-content)[^"\']*["\']/', $source)) {
        $errors[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1) . ' does not use the shared student page shell';
    }
}

foreach (['students/timetable.php', 'students/learning_assistant.php'] as $relativePath) {
    $source = (string)file_get_contents($root . '/' . $relativePath);
    if (!str_contains($source, 'student-page-heading')) {
        $errors[] = $relativePath . ' does not use the shared timetable heading';
    }
    if (!str_contains($source, 'content-wrapper')) {
        $errors[] = $relativePath . ' does not use the shared content wrapper';
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "[fail] {$error}\n");
    }
    exit(1);
}

echo "[pass] shared student stylesheet loads last\n";
echo "[pass] timetable-derived background and page heading are available\n";
echo "[pass] {$checkedPages} student page templates use the shared shell\n";
echo "Student UI consistency test complete.\n";

<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../db/connect.php';

$pass = 0;
$fail = 0;
$ok = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    $condition ? $pass++ : $fail++;
    echo ($condition ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
};

const ZLIB_URL = 'https://z-library.biz/';

// ── DB invariant: exactly one seeded Z-Library digital resource ──
// Defensive column pick: base schema has no `visibility` column; select it only if present.
$resTable = $db->query('SHOW COLUMNS FROM `library_digital_resources`');
$selCols = ['id', 'title', 'resource_type', 'url', 'access_level'];
$haveCols = [];
if ($resTable) {
    while ($row = $resTable->fetch_assoc()) {
        $haveCols[strtolower((string) $row['Field'])] = true;
    }
    $resTable->free();
}
if (isset($haveCols['visibility'])) {
    $selCols[] = 'visibility';
}

$rows = [];
$urlParam = ZLIB_URL;
$sel = $db->prepare('SELECT ' . implode(', ', $selCols) . ' FROM library_digital_resources WHERE url = ?');
if ($sel) {
    $sel->bind_param('s', $urlParam);
    $sel->execute();
    if ($res = $sel->get_result()) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    $sel->close();
}

$ok(count($rows) === 1, 'exactly one library_digital_resources row points at ' . ZLIB_URL . ' (run php migrations/20260904_add_zlibrary_book_source.php first if 0)');

if (count($rows) === 1) {
    $seed = $rows[0];
    $ok(strtolower((string) $seed['resource_type']) === 'ebook', 'seeded resource_type is ebook');
    $allowed = ['open', 'registered', 'student', 'students', 'campus', 'public', 'all'];
    $ok(in_array(strtolower((string) $seed['access_level']), $allowed, true), 'seeded access_level is within the student-visibility allow-list');
    $ok(!empty($seed['title']), 'seeded resource has a title');
} else {
    // Keep counters meaningful when the seed is missing (still fail loudly).
    $ok(false, 'seeded resource_type is ebook');
    $ok(false, 'seeded access_level is within the student-visibility allow-list');
    $ok(false, 'seeded resource has a title');
}

// ── Structural checks: each library page links to Z-Library with safe attrs ──
$pages = [
    'students/digital_library.php' => __DIR__ . '/../../students/digital_library.php',
    'students/library.php'         => __DIR__ . '/../../students/library.php',
    'library/index.php'            => __DIR__ . '/../../library/index.php',
    'admin/library.php'            => __DIR__ . '/../../admin/library.php',
];

foreach ($pages as $label => $path) {
    $src = is_file($path) ? (string) file_get_contents($path) : '';
    $hasHref = strpos($src, 'https://z-library.biz/') !== false;
    $hasTarget = strpos($src, 'target="_blank"') !== false;
    $hasRel = strpos($src, 'rel="noopener noreferrer"') !== false;
    $ok($hasHref, "{$label} links to https://z-library.biz/");
    $ok($hasTarget && $hasRel, "{$label} external link uses target=_blank rel=noopener noreferrer");
}

echo PHP_EOL . "Result: {$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
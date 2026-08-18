<?php
declare(strict_types=1);

/**
 * Frontend audit — shell consistency, viewport, responsive CSS overrides, React assets.
 * Run: php tests/frontend_audit/run.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$issues = [];
$warnings = [];
$checked = 0;

function rel_path(string $root, string $path): string
{
    $normRoot = str_replace('\\', '/', $root);
    $normPath = str_replace('\\', '/', $path);
    return ltrim(substr($normPath, strlen($normRoot)), '/');
}

function scan_dir(string $dir, callable $callback): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $callback($file->getPathname());
    }
}

// ── 1. Student pages with navbar but broken shell ─────────────────────────
scan_dir($root . '/students', static function (string $path) use ($root, &$issues, &$checked): void {
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
        return;
    }
    $rel = rel_path($root, $path);
    if (preg_match('#students/(?:includes/|test|debug|check_react|session_debug|api_|process)#i', $rel)) {
        return;
    }
    $source = (string)file_get_contents($path);
    if (!preg_match('/(?:include|require).*includes\/navbar\.php/s', str_replace('\\', '/', $source))) {
        return;
    }
    $checked++;
    if (!preg_match('/class=["\'][^"\']*(?:content-wrapper|dash-content|main-content)[^"\']*["\']/', $source)) {
        $issues[] = "[shell] {$rel} — navbar included but no content-wrapper/dash-content";
    }
    if (preg_match('/\.content-wrapper\s*\{[^}]*margin\s*:\s*0\s+auto/i', $source)) {
        $issues[] = "[responsive] {$rel} — .content-wrapper uses margin:0 auto (breaks sidebar offset)";
    }
    if (preg_match('/<head[^>]*>([\s\S]*?)<\/head>/i', $source, $headMatch)
        && preg_match('/(?:include|require).*includes\/navbar\.php/s', str_replace('\\', '/', $headMatch[1] ?? ''))) {
        $issues[] = "[markup] {$rel} — navbar included inside <head> (CSS/JS load order broken)";
    }
    if (preg_match('/<body[^>]*class=["\'][^"\']*bg-light[^"\']*["\']/i', $source)
        && !preg_match('/single-page-document|print/i', $source)) {
        $warnings[] = "[theme] {$rel} — body.bg-light may fight student-unified.css background";
    }
});

// ── 2. Staff modules: viewport + main.css chain ─────────────────────────
foreach (['admin', 'accounts', 'admissions', 'hod', 'lecturers', 'registrar', 'dean', 'transport', 'enterprise'] as $module) {
    $moduleDir = $root . '/' . $module;
    scan_dir($moduleDir, static function (string $path) use ($root, $module, &$warnings): void {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            return;
        }
        $rel = rel_path($root, $path);
        if (preg_match('#/(includes/|ajax/|api/)#', $rel)) {
            return;
        }
        $source = (string)file_get_contents($path);
        if (!str_contains($source, '<html') && !str_contains($source, '<body')) {
            return;
        }
        if (!preg_match('/viewport/i', $source) && !preg_match('/nav_unified\.php|navbar\.php|common_header\.php|layout\.php/', $source)) {
            $warnings[] = "[viewport] {$rel} — no viewport meta and no shared header include detected";
        }
    });
}

// ── 3. React / webpack assets ───────────────────────────────────────────
$bundlePath = $root . '/students/dist/js/courseRegistration.bundle.js';
if (!is_file($bundlePath)) {
    $issues[] = '[react] students/dist/js/courseRegistration.bundle.js is missing — run npm install && npm run build';
}
if (!is_dir($root . '/node_modules/react')) {
    $warnings[] = '[react] node_modules/react not installed — webpack build unavailable on this machine';
}
if (!is_file($root . '/package.json')) {
    $issues[] = '[react] package.json missing';
}

$reactSources = glob($root . '/students/js/react/**/*.{js,jsx}', GLOB_BRACE) ?: [];
if ($reactSources === []) {
    $warnings[] = '[react] no JSX sources under students/js/react/';
}

// ── 4. Shared responsive CSS present ────────────────────────────────────
foreach ([
    'assets/css/responsive.css',
    'css/wuc-premium.css',
    'students/css/student-unified.css',
    'css/unified-sidebar.css',
] as $cssRel) {
    if (!is_file($root . '/' . $cssRel)) {
        $issues[] = "[css] missing {$cssRel}";
    }
}

// ── 5. Duplicate bootstrap CDN on student pages that already use navbar ──
scan_dir($root . '/students', static function (string $path) use ($root, &$warnings): void {
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
        return;
    }
    $rel = rel_path($root, $path);
    $source = (string)file_get_contents($path);
    if (!preg_match('/navbar\.php/', $source)) {
        return;
    }
    if (!preg_match('/<head[^>]*>([\s\S]*?)<\/head>/i', $source, $headMatch)) {
        return;
    }
    if (preg_match('/bootstrap/i', $headMatch[1] ?? '')) {
        $warnings[] = "[css] {$rel} — duplicate Bootstrap in <head> (navbar loads Bootstrap again in body)";
    }
});

// ── Output ───────────────────────────────────────────────────────────────
echo "Frontend audit — {$checked} student shell pages scanned\n";
echo str_repeat('─', 60) . "\n";

if ($issues === [] && $warnings === []) {
    echo "[pass] No blocking issues or warnings.\n";
    exit(0);
}

foreach ($issues as $issue) {
    fwrite(STDERR, "[fail] {$issue}\n");
}
foreach ($warnings as $warning) {
    echo "[warn] {$warning}\n";
}

exit($issues === [] ? 0 : 1);

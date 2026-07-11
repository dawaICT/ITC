<?php
/**
 * Normalize every HTML table across the portal to the canonical
 * admin/applicants.php style:
 *
 *   <table class="table table-hover align-middle">  +  <thead class="table-light">
 *
 * Conservative by design:
 *  - Only rewrites <table>/<thead> opening tags whose attributes are static
 *    (tags containing "<?" are left untouched).
 *  - Strips legacy visual variants (striped/bordered/dark/sm, w3-*) but keeps
 *    any other custom classes, ids, data-* attributes, and quoting style.
 *  - Skips print/receipt/PDF/letter layouts where tables are page layout.
 *  - Backs up every modified file under backups/table_ui_<date>/.
 *  - Emits a JSON change log for the final report.
 *
 * Run:  E:\xampp\php\php.exe tools\normalize_tables.php [--dry-run]
 */

$root = dirname(__DIR__);
$dry = in_array('--dry-run', $argv, true);
$backupDir = $root . '/backups/table_ui_' . date('Ymd');

// Modules to process (directory => recurse)
$dirs = [
    'admin', 'admissions', 'accounts', 'registrar', 'lecturers', 'dean',
    'hod', 'library', 'students', 'transport', 'elearning', 'vc',
    'online_services', 'hostels', 'staff', 'templates',
];

// Root-level UI pages reachable from menus
$rootFiles = [
    'semesterReg_stud.php', 'admittedstud_report.php', 'studentAccount.php',
    'accessRight.php', 'manage_departments.php', 'program_missing_list.php',
    'view_lecturer_assignments.php', 'retiral.php',
];

// File-name patterns to skip entirely (print/document layouts + junk)
$skipFile = '/(print|receipt|_pdf|pdf_|docket|letter|slip|template\.csv|\.bak$|backup|^test_|_test\.|debug|temp_|^check_|sample_)/i';
// Directory parts to skip
$skipDir = '/(node_modules|vendor|backups|dist|uploads|logs|chrome-profile|\.git)/i';

$blacklistTable = [
    'table-striped','table-bordered','table-borderless','table-dark','table-sm',
    'table-light','table-condensed','table-primary','table-secondary','table-success',
    'table-info','table-warning','table-danger','w3-table','w3-table-all','w3-striped',
    'w3-bordered','w3-hoverable','w3-card','w3-card-4','w3-white','table-hover',
];
$blacklistThead = [
    'thead-dark','thead-light','table-dark','table-primary','table-secondary',
    'table-success','table-info','table-warning','table-danger','bg-dark','bg-primary',
    'bg-secondary','bg-success','bg-info','bg-warning','bg-danger','bg-light',
    'text-white','text-light','w3-red','w3-blue','w3-green','w3-grey','w3-gray',
    'w3-light-grey','w3-dark-grey','table-light',
];

function rewriteTag(string $tag, array $blacklist, array $required): ?string {
    if (strpos($tag, '<?') !== false) return null; // PHP inside tag — skip
    // Find a static class attribute in one of three quoting styles
    if (preg_match('/\bclass\s*=\s*(\\\\?["\'])(.*?)\1/s', $tag, $m, PREG_OFFSET_CAPTURE)) {
        $quote = $m[1][0];
        $value = $m[2][0];
        if (strpos($value, '<?') !== false) return null;
        $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        $kept = [];
        foreach ($tokens as $t) {
            if (!in_array(strtolower($t), $blacklist, true) && !in_array(strtolower($t), $required, true)) {
                $kept[] = $t;
            }
        }
        $newValue = implode(' ', array_merge($required, $kept));
        if ($newValue === trim(preg_replace('/\s+/', ' ', $value))) return null; // no change
        $newAttr = 'class=' . $quote . $newValue . $quote;
        $start = $m[0][1];
        $len = strlen($m[0][0]);
        return substr($tag, 0, $start) . $newAttr . substr($tag, $start + $len);
    }
    // No class attribute — inject one right after the tag name
    $newTag = preg_replace('/^<(table|thead)\b/i', '<$1 class="' . implode(' ', $required) . '"', $tag, 1, $count);
    return $count ? $newTag : null;
}

function processFile(string $path, array $blT, array $blH): array {
    $src = file_get_contents($path);
    if ($src === false || stripos($src, '<table') === false) return [0, 0, null];

    $tableChanges = 0;
    $theadChanges = 0;

    $out = preg_replace_callback('/<table\b[^>]*>/is', function ($m) use (&$tableChanges, $blT, $src) {
        $tag = $m[0];
        // Layout-table guard: if the tag has no Bootstrap "table" class token,
        // only claim it as a data table when its body actually has header
        // cells (<th>) before the closing </table>.
        $hasBootstrapToken = preg_match('/\bclass\s*=\s*\\\\?["\'][^"\']*\btable\b/i', $tag);
        if (!$hasBootstrapToken) {
            $start = strpos($src, $tag);
            $end = stripos($src, '</table>', $start === false ? 0 : $start);
            $segment = ($start !== false && $end !== false) ? substr($src, $start, $end - $start) : '';
            if (stripos($segment, '<th') === false) {
                return $tag; // layout table — leave untouched
            }
        }
        $new = rewriteTag($tag, $blT, ['table', 'table-hover', 'align-middle']);
        if ($new !== null) {
            // Drop presentational attributes superseded by the stylesheet.
            $new = preg_replace('/\s+(border|cellpadding|cellspacing)\s*=\s*\\\\?["\'][^"\']*\\\\?["\']/i', '', $new);
            $tableChanges++;
            return $new;
        }
        return $tag;
    }, $src);

    $out = preg_replace_callback('/<thead\b[^>]*>/is', function ($m) use (&$theadChanges, $blH) {
        $new = rewriteTag($m[0], $blH, ['table-light']);
        if ($new !== null) { $theadChanges++; return $new; }
        return $m[0];
    }, $out);

    return [$tableChanges, $theadChanges, ($tableChanges + $theadChanges) ? $out : null];
}

// Collect files
$files = [];
foreach ($dirs as $d) {
    $base = $root . '/' . $d;
    if (!is_dir($base)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (preg_match($GLOBALS['skipDir'], $rel)) continue;
        if (preg_match($GLOBALS['skipFile'], basename($rel))) continue;
        $files[] = $rel;
    }
}
foreach ($rootFiles as $f) {
    if (is_file($root . '/' . $f)) $files[] = $f;
}

$log = [];
$totalT = $totalH = 0;
foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    [$t, $h, $out] = processFile($path, $blacklistTable, $blacklistThead);
    if ($out === null) continue;
    if (!$dry) {
        $bk = $backupDir . '/' . $rel;
        if (!is_dir(dirname($bk))) mkdir(dirname($bk), 0777, true);
        copy($path, $bk);
        file_put_contents($path, $out);
    }
    $module = strpos($rel, '/') !== false ? explode('/', $rel)[0] : '(root)';
    $log[$module][] = ['file' => $rel, 'tables' => $t, 'theads' => $h];
    $totalT += $t; $totalH += $h;
}

ksort($log);
foreach ($log as $module => $entries) {
    $mt = array_sum(array_column($entries, 'tables'));
    $mh = array_sum(array_column($entries, 'theads'));
    echo sprintf("%-16s %3d files, %3d <table>, %3d <thead>\n", $module, count($entries), $mt, $mh);
}
echo "\nTOTAL: {$totalT} <table> tags, {$totalH} <thead> tags" . ($dry ? ' (DRY RUN — nothing written)' : " — backups in {$backupDir}") . "\n";
file_put_contents($root . '/tools/normalize_tables_log.json', json_encode($log, JSON_PRETTY_PRINT));
echo "Change log: tools/normalize_tables_log.json\n";

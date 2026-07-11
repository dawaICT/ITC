<?php
/**
 * Repair legacy course_registration rows where Year stores a calendar year
 * instead of year-of-study, and deactivate duplicate active enrolments.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/repair_course_registration_years.php
 *   C:\xampp\php\php.exe scripts/repair_course_registration_years.php --apply
 *   C:\xampp\php\php.exe scripts/repair_course_registration_years.php --apply --student=CSE26456789
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/schema_guard.php';

$apply = in_array('--apply', $argv ?? [], true);
$studentFilter = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--student=')) {
        $studentFilter = trim(substr($arg, 10));
    }
}

if (!wuc_table_exists($db, 'course_registration')) {
    echo "course_registration table not found.\n";
    exit(0);
}

function crColumn(mysqli $db, array $candidates): ?string
{
    static $cache = [];
    foreach ($candidates as $col) {
        $key = 'course_registration.' . strtolower($col);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $col);
        $exists = ($r = @$db->query("SHOW COLUMNS FROM course_registration LIKE '{$safe}'")) && $r->num_rows > 0;
        if ($r) {
            $r->free();
        }
        if ($exists) {
            return $cache[$key] = $col;
        }
        $cache[$key] = null;
    }
    return null;
}

$sidCol = crColumn($db, ['Sid', 'student_id', 'SID']) ?? 'Sid';
$yearCol = crColumn($db, ['Year', 'year_of_study']) ?? 'Year';
$activeCol = crColumn($db, ['is_active']);

function resolveYearOfStudy(mysqli $db, string $studentId): int
{
    if ($stmt = $db->prepare(
        'SELECT year_of_study, current_year_number, semester FROM student_program
          WHERE Sid = ? AND LOWER(COALESCE(status, \'active\')) = \'active\'
          ORDER BY id DESC LIMIT 1'
    )) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            foreach (['year_of_study', 'current_year_number'] as $k) {
                $v = (int)($row[$k] ?? 0);
                if ($v >= 1 && $v <= 10) {
                    return $v;
                }
            }
        }
    }
    if ($stmt = $db->prepare('SELECT year FROM students WHERE SID = ? LIMIT 1')) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $y = (int)($row['year'] ?? 0);
        if ($y >= 1 && $y <= 10) {
            return $y;
        }
    }
    return 1;
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . ": repair course_registration.Year values and duplicate actives\n\n";

$where = "CAST(cr.`{$yearCol}` AS UNSIGNED) > 10";
$types = '';
$params = [];
if ($studentFilter !== null) {
    $where .= " AND cr.`{$sidCol}` = ?";
    $types = 's';
    $params[] = $studentFilter;
}

$sql = "SELECT cr.id, cr.`{$sidCol}` AS sid, cr.course_code, cr.`{$yearCol}` AS year_val
          FROM course_registration cr
         WHERE {$where}
         ORDER BY cr.id";
$stmt = $db->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$badYearRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo 'Rows with calendar-year in Year column: ' . count($badYearRows) . "\n";
$yearFixes = 0;
foreach ($badYearRows as $row) {
    $yos = resolveYearOfStudy($db, (string)$row['sid']);
    echo "  id={$row['id']} {$row['sid']} {$row['course_code']}: Year {$row['year_val']} -> {$yos}\n";
    if ($apply) {
        $upd = $db->prepare("UPDATE course_registration SET `{$yearCol}` = ? WHERE id = ?");
        $upd->bind_param('ii', $yos, $row['id']);
        if ($upd->execute()) {
            $yearFixes++;
        }
        $upd->close();
    }
}

$dupSql = "SELECT cr.`{$sidCol}` AS sid, cr.course_code, cr.`{$yearCol}` AS yos, COUNT(*) AS c
             FROM course_registration cr";
if ($activeCol) {
    $dupSql .= " WHERE COALESCE(cr.`{$activeCol}`, 1) = 1";
}
$dupSql .= " GROUP BY cr.`{$sidCol}`, cr.course_code, cr.`{$yearCol}` HAVING c > 1";
$dupRows = [];
if ($res = $db->query($dupSql)) {
    $dupRows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

echo "\nDuplicate active (sid, course, year-of-study) groups: " . count($dupRows) . "\n";
$deactivated = 0;
foreach ($dupRows as $group) {
    if ($studentFilter !== null && $group['sid'] !== $studentFilter) {
        continue;
    }
    $sel = $db->prepare(
        "SELECT id FROM course_registration
          WHERE `{$sidCol}` = ? AND course_code = ? AND `{$yearCol}` = ?"
        . ($activeCol ? " AND COALESCE(`{$activeCol}`, 1) = 1" : '')
        . ' ORDER BY id DESC'
    );
    $sel->bind_param('ssi', $group['sid'], $group['course_code'], $group['yos']);
    $sel->execute();
    $ids = [];
    $r = $sel->get_result();
    while ($idRow = $r->fetch_assoc()) {
        $ids[] = (int)$idRow['id'];
    }
    $sel->close();
    if (count($ids) < 2) {
        continue;
    }
    array_shift($ids);
    echo "  {$group['sid']} {$group['course_code']} yos={$group['yos']}: deactivate ids " . implode(',', $ids) . "\n";
    if ($apply && $activeCol) {
        foreach ($ids as $id) {
            $upd = $db->prepare("UPDATE course_registration SET `{$activeCol}` = 0 WHERE id = ?");
            $upd->bind_param('i', $id);
            if ($upd->execute()) {
                $deactivated++;
            }
            $upd->close();
        }
    }
}

echo "\nSummary: year fixes=" . ($apply ? $yearFixes : count($badYearRows))
    . ', duplicate deactivations=' . ($apply ? $deactivated : count($dupRows)) . "\n";
if (!$apply && (count($badYearRows) > 0 || count($dupRows) > 0)) {
    echo "Re-run with --apply to commit.\n";
}

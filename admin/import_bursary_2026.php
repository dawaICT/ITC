<?php
/**
 * One-off importer: admit the ITC Bursary 2026 awardees from the supplied
 * Excel workbook ("Industrial TC Bursary 2026.xlsx", sheet "2026").
 *
 * Each awardee is FULLY admitted using the same canonical helpers as the
 * online-applicant flow:
 *   students + student_program + student_login + course assignment + invoice
 * all inside one transaction per student.
 *
 * Decisions (confirmed with the registrar before running):
 *   - Sponsor is recorded as TEVETA  → automatic 100% bursary (fees covered).
 *   - Programmes not yet in the `programs` catalogue are auto-created from the
 *     sheet's abbreviation (e.g. ITC-TTL1ET) so every student gets a real
 *     programme enrolment rather than a degraded "no programme" admission.
 *   - 2026 intake, January start, academic_year 2026.
 *
 * SAFETY: runs as a DRY RUN by default — it parses, maps, and reports without
 * touching the database. Pass --commit to actually write. Re-running is safe:
 * a student whose NRC already exists is skipped (idempotent).
 *
 * Usage (from a shell):
 *   php admin/import_bursary_2026.php                 # dry run
 *   php admin/import_bursary_2026.php --commit        # perform admission
 *   php admin/import_bursary_2026.php --commit --file="C:/path/to/file.xlsx"
 */

require __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/applicant_admission.php';

// ── CLI args ───────────────────────────────────────────────────────────────
$COMMIT = in_array('--commit', $argv, true);
$FILE   = 'C:/Users/Dawa/Downloads/Industrial TC Bursary 2026.xlsx';
foreach ($argv as $a) {
    if (strpos($a, '--file=') === 0) {
        $FILE = substr($a, 7);
    }
}
$SPONSOR      = 'TEVETA';   // → 100% bursary
$ENTRY_YEAR   = 2026;
$SHEET        = 'xl/worksheets/sheet2.xml'; // "2026" sheet
$STAFF        = 'ITC900';

function out(string $s = ''): void { echo $s . "\n"; }

// ── Read the workbook (xlsx = zip of XML) ──────────────────────────────────
function loadSheetRows(string $file, string $sheetPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        throw new RuntimeException("Cannot open workbook: $file");
    }
    $shared = [];
    if (($i = $zip->locateName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($zip->getFromIndex($i));
        foreach ($xml->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            } else {
                foreach ($si->r as $r) { $t .= (string)$r->t; }
            }
            $shared[] = $t;
        }
    }
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException("Sheet not found: $sheetPath");
    }
    $x = simplexml_load_string($sheetXml);
    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = preg_replace('/\d+/', '', (string)$c['r']);
            $v   = (string)$c->v;
            if ((string)$c['t'] === 's') { $v = $shared[(int)$v] ?? $v; }
            if (isset($c->is->t))        { $v = (string)$c->is->t; }
            $cells[$col] = $v;
        }
        $rows[] = $cells;
    }
    return $rows;
}

// Excel 1900-system serial → Y-m-d.
function excelSerialToDate($serial): ?string
{
    if (!is_numeric($serial)) {
        return null;
    }
    $serial = (int)$serial;
    if ($serial <= 0) {
        return null;
    }
    $base = new DateTime('1899-12-30');
    $base->modify("+{$serial} days");
    return $base->format('Y-m-d');
}

// "Surname Firstname Middle" → [Fname, Lname]. Sheet lists surname first.
function splitName(string $full): array
{
    $parts = preg_split('/\s+/', trim($full));
    if (count($parts) <= 1) {
        return [$full, $full];
    }
    $lname = array_shift($parts);
    $fname = implode(' ', $parts);
    return [$fname, $lname];
}

// Parse "(Type) Full Name (CODE)" → [type, name, abbr].
function parseProgramme(string $s): ?array
{
    if (!preg_match('/^\(([^)]+)\)\s*(.+?)\s*\(([A-Za-z0-9]+)\)\s*$/', trim($s), $m)) {
        return null;
    }
    return ['type' => trim($m[1]), 'name' => trim($m[2]), 'abbr' => strtoupper(trim($m[3]))];
}

// Spreadsheet abbreviation → existing catalogue code (where one already exists).
$EXISTING_MAP = [
    'DCES' => 'ITC-ENG-02', // Diploma in Computer Systems Engineering
    'CCPE' => 'ITC-ENG-06', // Craft Certificate in Power Electrical
    'CCAE' => 'ITC-ENG-05', // Craft Certificate in Auto-Electrical & Electronics
    'CCT'  => 'ITC-ENG-04', // Craft Certificate in (Electronic and) Telecommunications
];

// Resolve a programme abbreviation to a catalogue code, creating the programme
// when it does not yet exist. Returns the program_code.
function ensureProgrammeCode(mysqli $db, array $prog, array $existingMap, bool $commit, array &$created): string
{
    $abbr = $prog['abbr'];
    if (isset($existingMap[$abbr])) {
        return $existingMap[$abbr];
    }
    $code = 'ITC-' . $abbr;

    // Already present?
    $stmt = $db->prepare('SELECT program_code FROM programs WHERE program_code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($exists) {
        return $code;
    }

    if (!isset($created[$code])) {
        $created[$code] = $prog['name'];
        if ($commit) {
            admissionsInsert($db, 'programs', [
                'program_code'     => $code,
                'program_name'     => $prog['name'],
                'program_type'     => $prog['type'],
                'study_mode'       => 'Full Time',
                'program_duration' => programmeDuration($prog),
                'is_active'        => 1,
            ]);
        }
    }
    return $code;
}

// Duration in years, derived from the academic qualification framework. The
// sheet's own "Duration" column (carried on $prog['years']) is authoritative
// when present; otherwise fall back to the TEVET norms by qualification type:
//   Trade Test (any level)        → 1 year
//   Craft / Technician Certificate → 2 years
//   Diploma                        → 2 years (3 for the engineering diplomas,
//                                    but those map to existing codes already).
function programmeDuration(array $prog): int
{
    if (!empty($prog['years']) && (int)$prog['years'] > 0) {
        return (int)$prog['years'];
    }
    $type = strtolower($prog['type'] ?? '');
    if (strpos($type, 'trade') !== false) {
        return 1;
    }
    return 2;
}

// Full admission of a single bursary student row, in one transaction.
function admitBursaryStudent(mysqli $db, array $std, string $sponsor, int $entryYear, bool $commit): array
{
    $nrc = trim((string)$std['nrc']);

    // Idempotency: NRC already a student?
    $existing = admissionsFindExistingStudent($db, $nrc, '', '');
    if ($existing !== null) {
        return ['status' => 'skip', 'reason' => "already a student ({$existing})"];
    }

    $programCode  = $std['program_code'];
    $academicYear = (string)$entryYear;
    $period       = '1'; // January 2026 intake

    if (!$commit) {
        // Dry run: programmes that *would* be created don't exist yet, so a
        // catalogue lookup can legitimately fail. Preview a best-effort SID.
        try {
            $studentId = generateStudentId($db, $programCode, $period, $academicYear, $nrc);
        } catch (Throwable $e) {
            $studentId = '(pending)';
        }
        return ['status' => 'would-admit', 'sid' => $studentId, 'program' => $programCode];
    }

    $program = admissionsResolveProgram($db, $programCode);
    $intake  = admissionsBuildIntake($program['period_mode'], $period, $entryYear);
    [$termStart, $termEnd] = admissionsTermDates($program['period_mode'], $period, $entryYear);
    $endYear = $entryYear + $program['duration'];

    $studentId = generateStudentId($db, $programCode, $period, $academicYear, $nrc);

    $db->begin_transaction();
    try {
        admissionsInsert($db, 'students', [
            'SID'             => $studentId,
            'Fname'           => $std['fname'],
            'Lname'           => $std['lname'],
            'sex'             => $std['sex'],
            'dob'             => $std['dob'],
            'nrc_pass'        => $nrc,
            'country'         => 'Zambia',
            'sponsor'         => $sponsor,
            'program'         => $programCode,
            'intake'          => $intake,
            'mode'            => 'Full-time',
            'academic_year'   => $academicYear,
            'year'            => 1,
            'status'          => 'active',
            'dte_adm'         => date('Y-m-d H:i:s'),
            'enrollment_date' => date('Y-m-d H:i:s'),
        ]);

        admissionsInsert($db, 'student_program', [
            'Sid'             => $studentId,
            'program_code'    => $programCode,
            'intake'          => $intake,
            'term'            => $period,
            'mode'            => 'Full-time',
            'startYear'       => $entryYear,
            'endYear'         => $endYear,
            'status'          => 'active',
            'academic_year'   => $academicYear,
            'term_start_date' => $termStart,
            'term_end_date'   => $termEnd,
        ]);

        $totalFees = admissionsAssignCourses($db, $studentId, $programCode, $period, $academicYear);
        admissionsEnsureStudentLogin($db, $studentId, $nrc, null);

        // TEVETA bursary = 100% → invoice nets to zero but documents the award.
        $bursary       = in_array(strtoupper($sponsor), ['TEVETA', 'CDF'], true) ? 100.0 : 0.0;
        $invoiceAmount = $totalFees * (1 - $bursary / 100);
        $desc = $program['name'] . ' registration - ' . $intake
            . ($bursary > 0 ? sprintf(' (%s bursary %.0f%%)', $sponsor, $bursary) : '');
        admissionsCreateInvoice($db, $studentId, $invoiceAmount, $desc);

        $db->commit();
        return ['status' => 'admitted', 'sid' => $studentId, 'program' => $programCode, 'intake' => $intake];
    } catch (Throwable $e) {
        $db->rollback();
        return ['status' => 'error', 'reason' => $e->getMessage()];
    }
}

// ── Main ───────────────────────────────────────────────────────────────────
out('ITC Bursary 2026 importer — ' . ($COMMIT ? 'COMMIT (writing to DB)' : 'DRY RUN (no writes)'));
out('File: ' . $FILE);
out(str_repeat('-', 72));

$rows = loadSheetRows($FILE, $SHEET);

$students = [];
$created  = [];
$unparsedProg = [];

foreach ($rows as $r) {
    $no   = trim((string)($r['A'] ?? ''));
    $name = trim((string)($r['B'] ?? ''));
    // Data rows have a numeric No. and a Name; skip title/header/blank rows.
    if ($name === '' || !ctype_digit($no)) {
        continue;
    }
    $prog = parseProgramme((string)($r['F'] ?? ''));
    if ($prog === null) {
        $unparsedProg[] = $no . ': ' . ($r['F'] ?? '(blank)');
        continue;
    }
    // Carry the sheet's stated duration (e.g. "1 years", "2 years") so newly
    // created programmes get the correct length straight from the source.
    if (preg_match('/(\d+)/', (string)($r['G'] ?? ''), $gm)) {
        $prog['years'] = (int)$gm[1];
    }
    $code = ensureProgrammeCode($db, $prog, $EXISTING_MAP, $COMMIT, $created);
    [$fname, $lname] = splitName($name);
    $sex = strtoupper(substr(trim((string)($r['C'] ?? 'M')), 0, 1));
    if (!in_array($sex, ['M', 'F'], true)) { $sex = 'M'; }

    $students[] = [
        'no'           => $no,
        'fname'        => $fname,
        'lname'        => $lname,
        'sex'          => $sex,
        'dob'          => excelSerialToDate($r['D'] ?? null),
        'nrc'          => trim((string)($r['E'] ?? '')),
        'program_code' => $code,
        'program_name' => $prog['name'],
    ];
}

out('Parsed ' . count($students) . ' student rows.');
if ($unparsedProg) {
    out('WARNING — ' . count($unparsedProg) . ' rows had an unparseable programme and were skipped:');
    foreach ($unparsedProg as $u) { out('   ' . $u); }
}
if ($created) {
    out('');
    out(($COMMIT ? 'Created' : 'Would create') . ' ' . count($created) . ' new programme(s):');
    foreach ($created as $code => $nm) { out("   {$code}  —  {$nm}"); }
}
out(str_repeat('-', 72));

$counts = ['admitted' => 0, 'would-admit' => 0, 'skip' => 0, 'error' => 0];
$errors = [];
foreach ($students as $std) {
    $res = admitBursaryStudent($db, $std, $SPONSOR, $ENTRY_YEAR, $COMMIT);
    $counts[$res['status']] = ($counts[$res['status']] ?? 0) + 1;
    if ($res['status'] === 'admitted') {
        out(sprintf('  ✓ %-3s %s %s → %s (%s)', $std['no'], $std['lname'], $std['fname'], $res['sid'], $res['program']));
    } elseif ($res['status'] === 'skip') {
        out(sprintf('  · %-3s %s %s — skipped: %s', $std['no'], $std['lname'], $std['fname'], $res['reason']));
    } elseif ($res['status'] === 'error') {
        $errors[] = $std['no'] . ' ' . $std['lname'] . ': ' . $res['reason'];
        out(sprintf('  ✗ %-3s %s %s — ERROR: %s', $std['no'], $std['lname'], $std['fname'], $res['reason']));
    }
}

out(str_repeat('-', 72));
out('Summary:');
out('   Students parsed : ' . count($students));
if ($COMMIT) {
    out('   Admitted        : ' . $counts['admitted']);
} else {
    out('   Would admit     : ' . $counts['would-admit']);
}
out('   Skipped (dup)   : ' . $counts['skip']);
out('   Errors          : ' . $counts['error']);
if ($errors) {
    out('');
    out('Errors detail:');
    foreach ($errors as $e) { out('   ' . $e); }
}
if (!$COMMIT) {
    out('');
    out('This was a DRY RUN. Re-run with --commit to perform the admission.');
}

<?php
/**
 * generate_register.php
 *
 * Produces a printable PDF register for one of several register types
 * (see admin/includes/register_types.php):
 *   - program-scoped: semester / term / test / exam  -> course or exam roster
 *   - short-course:   short_course                   -> short-course enrollees
 *
 * Method: POST (from admin/print_registers.php)
 * Program-scope fields: csrf_token, register_type, program_code, year_of_study,
 *                       semester, course_code
 * Short-course fields:  csrf_token, register_type=short_course, short_course_id
 *
 * On validation failure it renders a friendly HTML error page (inside the admin
 * shell) instead of a broken/blank PDF.
 */

require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/csrf_guard.php';
require_once dirname(__DIR__) . '/includes/helpers/course_availability_helpers.php';
require_once __DIR__ . '/includes/register_types.php';

// Real errors are logged, never echoed into the PDF stream. TCPDF still emits
// PHP 8.2 deprecation notices, which should not pollute register-generation logs.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Render a styled error page within the admin layout and stop.
 */
function register_error(string $message): void
{
    // header.php (included below) relies on these globals being in scope.
    global $db, $page_title;
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $page_title = 'Print Registers';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container-fluid px-4">';
    echo '  <div class="alert alert-danger d-flex align-items-start gap-2 mt-4" role="alert">';
    echo '      <i class="fas fa-triangle-exclamation mt-1"></i>';
    echo '      <div><strong>Could not generate the register.</strong><br>' . htmlspecialchars($message) . '</div>';
    echo '  </div>';
    echo '  <a href="print_registers.php" class="btn btn-primary"><i class="fas fa-arrow-left me-1"></i>Back to Print Registers</a>';
    echo '</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// --- Validate request -------------------------------------------------------
// This endpoint only produces a PDF in response to the Print Registers form
// being submitted. A direct visit (GET) just sends the user to that form
// instead of showing a bare error page.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (!headers_sent()) {
        header('Location: print_registers.php');
    }
    exit;
}

// CSRF check (accepts csrf_token field or X-CSRF-Token header).
$sessionToken  = (string)($_SESSION['csrf_token'] ?? '');
$providedToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if ($sessionToken === '' || $providedToken === '' || !hash_equals($sessionToken, $providedToken)) {
    register_error('Your session has expired or the request was invalid. Please go back and try again.');
}

$register_type = wuc_register_normalise_type($_POST['register_type'] ?? 'semester');
$type_config   = wuc_register_type_config($register_type);
$type_title    = $type_config['title'];
$period_word   = $type_config['period_word'];

try {
    // These are populated per scope and consumed by the PDF builder below.
    $students    = [];
    $meta_lines  = [];   // centred lines under the title
    $cols        = [];   // [header, width, align]
    $row_cells   = null; // fn(array $row, int $index): array<string>
    $filename    = '';
    $roster_label = 'students';

    if ($type_config['scope'] === 'short_course') {
        // ---------------------------------------------------------------
        // Short-course register
        // ---------------------------------------------------------------
        $short_course_id = $_POST['short_course_id'] ?? '';
        if ($short_course_id === '' || !ctype_digit((string)$short_course_id)) {
            register_error('Please select a short course before generating the register.');
        }
        $short_course_id = (int)$short_course_id;

        $stmt = $db->prepare("SELECT course_code, course_name, status, start_date, end_date
                                FROM short_courses WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $short_course_id);
        $stmt->execute();
        $sc = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$sc) {
            register_error('The selected short course no longer exists.');
        }

        $students     = wuc_register_short_course_roster($db, $short_course_id);
        $roster_label = 'enrolled students';

        $period = '';
        if (!empty($sc['start_date'])) {
            $period = date('d M Y', strtotime($sc['start_date']));
            if (!empty($sc['end_date'])) {
                $period .= ' - ' . date('d M Y', strtotime($sc['end_date']));
            }
        }
        $meta_lines[] = 'Short Course: ' . $sc['course_code'] . ' - ' . $sc['course_name'];
        if ($period !== '') {
            $meta_lines[] = 'Period: ' . $period;
        }
        $meta_lines[] = 'Generated: ' . date('Y-m-d H:i');

        $cols = [
            ['No.',          12, 'C'],
            ['Student ID',   45, 'C'],
            ['Student Name', 85, 'L'],
            ['Status',       40, 'C'],
            ['Signature',    65, 'C'],
        ];
        $row_cells = static function (array $row, int $i): array {
            $name = trim((string)($row['full_name'] ?? ''));
            return [
                (string)($i + 1),
                (string)$row['SID'],
                $name !== '' ? $name : (string)$row['SID'],
                ucfirst((string)($row['enroll_status'] ?? '')),
                '',
            ];
        };

        $filename = 'short_course_register_'
            . preg_replace('/[^A-Za-z0-9]+/', '_', (string)$sc['course_code']) . '.pdf';

    } else {
        // ---------------------------------------------------------------
        // Program-scoped register (semester / term / test / exam)
        // ---------------------------------------------------------------
        $program_code = trim($_POST['program_code'] ?? '');
        $course_code  = trim($_POST['course_code'] ?? '');
        $year_raw     = $_POST['year_of_study'] ?? '';
        $period_raw   = $_POST['semester'] ?? '';

        if ($program_code === '' || $course_code === '' || $year_raw === '' || $period_raw === '') {
            register_error('Please select a program, year, ' . strtolower($period_word) . ' and course before generating the register.');
        }
        if (!ctype_digit((string)$year_raw) || !ctype_digit((string)$period_raw)) {
            register_error('Year of study and ' . strtolower($period_word) . ' must be valid numbers.');
        }
        $year_of_study = (int)$year_raw;
        $period        = (int)$period_raw;

        $stmt = $db->prepare("SELECT program_name FROM programs WHERE program_code = ? LIMIT 1");
        $stmt->bind_param('s', $program_code);
        $stmt->execute();
        $program = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$program) {
            register_error('The selected program no longer exists.');
        }
        $program_name = $program['program_name'];

        // Prefer the master courses table.
        $pcCols = wuc_course_availability_columns($db, 'program_courses');
        $where = [
            'TRIM(UPPER(pc.program_code)) = TRIM(UPPER(?))',
            'TRIM(UPPER(pc.course_code)) = TRIM(UPPER(?))',
            'pc.year = ?',
        ];
        $types = 'ssi';
        $params = [$program_code, $course_code, $year_of_study];
        $periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcCols['semester'] ?? null, $period);
        if ($periodFilter['sql'] !== '1=1') {
            $where[] = $periodFilter['sql'];
            $types .= $periodFilter['types'];
            $params = array_merge($params, $periodFilter['params']);
        }

        $stmt = $db->prepare("SELECT c.course_name
                                FROM program_courses pc
                                LEFT JOIN courses c ON TRIM(UPPER(c.course_code)) = TRIM(UPPER(pc.course_code))
                               WHERE " . implode(' AND ', $where) . "
                               LIMIT 1");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$course) {
            register_error('The selected course is not attached to this program for the chosen year and ' . strtolower($period_word) . '.');
        }
        $course_name = $course['course_name'];

        $students = wuc_register_program_roster(
            $db,
            $type_config['source'],
            $program_code,
            $course_code,
            $period,
            $year_of_study
        );

        $meta_lines[] = 'Course: ' . $course_code . ' - ' . $course_name;
        $meta_lines[] = 'Programme: ' . $program_code . ' - ' . $program_name;
        $meta_lines[] = 'Year ' . $year_of_study . ', ' . $period_word . ' ' . $period
            . '   |   Generated: ' . date('Y-m-d H:i');

        $cols = [
            ['No.',          12, 'C'],
            ['Student ID',   40, 'C'],
            ['Student Name', 80, 'L'],
            ['Programme',    55, 'C'],
            ['Yr',           15, 'C'],
            ['Signature',    65, 'C'],
        ];
        $row_cells = static function (array $row, int $i): array {
            $name = trim((string)($row['full_name'] ?? ''));
            return [
                (string)($i + 1),
                (string)$row['SID'],
                $name !== '' ? $name : '-',
                (string)($row['student_program'] ?? '-'),
                (string)($row['student_year'] ?? ''),
                '',
            ];
        };

        $filename = strtolower($register_type) . '_register_'
            . preg_replace('/[^A-Za-z0-9]+/', '_', $course_code)
            . '_y' . $year_of_study . 'p' . $period . '.pdf';
    }

    // --- Build the PDF (shared for all scopes) ------------------------------
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';

    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('ITC Portal');
    $pdf->SetAuthor('Industrial Training Centre');
    $pdf->SetTitle($type_title);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);

    // The logo is a wide banner (~418x120). Size it for a centred letterhead at the
    // top of the page, not a faint square watermark floating in the middle.
    $logo_file = __DIR__ . '/../images/itc_logo.png';
    $logo_w = 0.0;
    $logo_h = 0.0;
    if (is_file($logo_file) && ($size = @getimagesize($logo_file))) {
        $logo_w = 72.0;                                   // mm
        $logo_h = ($size[1] / max(1, $size[0])) * $logo_w;
    }

    // Full letterhead: centred logo banner, accent rule, register title, meta lines.
    // Repeated at the top of every page so each printed sheet is self-identifying.
    $drawLetterhead = function () use ($pdf, $logo_file, $logo_w, $logo_h, $type_title, $meta_lines) {
        $topY = 10;
        if ($logo_w > 0) {
            $x = ($pdf->getPageWidth() - $logo_w) / 2;
            $pdf->Image($logo_file, $x, $topY, $logo_w, $logo_h, 'PNG', '', '', false, 300, '', false, false, 0);
            $pdf->SetY($topY + $logo_h + 1);
        } else {
            $pdf->SetY($topY);
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 8, 'INDUSTRIAL TRAINING COLLEGE', 0, 1, 'C');
        }

        // Accent rule in the portal purple.
        $ruleY = $pdf->GetY() + 1;
        $pdf->SetDrawColor(111, 66, 193);
        $pdf->SetLineWidth(0.6);
        $pdf->Line(15, $ruleY, $pdf->getPageWidth() - 15, $ruleY);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $pdf->Ln(4);

        // Register title.
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetTextColor(111, 66, 193);
        $pdf->Cell(0, 7, $type_title, 0, 1, 'C');

        // Meta lines.
        $pdf->SetTextColor(33, 37, 41);
        $pdf->SetFont('helvetica', '', 10);
        foreach ($meta_lines as $line) {
            $pdf->Cell(0, 5, $line, 0, 1, 'C');
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    };

    $drawTableHead = function () use ($pdf, $cols) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(111, 66, 193); // portal purple
        $pdf->SetTextColor(255, 255, 255);
        $last = count($cols) - 1;
        foreach ($cols as $i => $col) {
            $pdf->Cell($col[1], 9, $col[0], 1, $i === $last ? 1 : 0, 'C', true);
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 10);
    };

    $pdf->AddPage();
    $drawLetterhead();
    $drawTableHead();

    if (empty($students)) {
        $totalWidth = array_sum(array_column($cols, 1));
        $pdf->SetFont('helvetica', 'I', 11);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell($totalWidth, 12, 'No ' . $roster_label . ' found for this selection.', 1, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $rowH = 9;
        $last = count($cols) - 1;
        foreach ($students as $i => $row) {
            if ($pdf->GetY() + $rowH > ($pdf->getPageHeight() - 15)) {
                $pdf->AddPage();
                $drawLetterhead();
                $drawTableHead();
            }
            // Zebra striping for readability.
            $fill = ($i % 2 === 1);
            if ($fill) { $pdf->SetFillColor(244, 242, 250); } // soft purple tint
            $cells = $row_cells($row, $i);
            foreach ($cols as $ci => $col) {
                $pdf->Cell($col[1], $rowH, $cells[$ci], 1, $ci === $last ? 1 : 0, $col[2], $fill);
            }
        }
    }

    // Summary + signature block.
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Total ' . $roster_label . ': ' . count($students), 0, 1, 'L');
    $pdf->Ln(8);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(130, 6, 'Lecturer / Invigilator: ____________________________', 0, 0, 'L');
    $pdf->Cell(0, 6, 'Signature: ____________________   Date: ____________', 0, 1, 'L');

    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Ln(3);
    $pdf->Cell(0, 5, 'Generated by ' . ($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'system') . ' on ' . date('Y-m-d H:i:s'), 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Output($filename, 'I');
    exit;

} catch (Throwable $e) {
    error_log('generate_register error: ' . $e->getMessage());
    register_error('An unexpected error occurred while building the register. The technical team has been notified.');
}

<?php
if (isset($_POST['ajax_bulk'])) {
    // AJAX Request: minimal includes, no HTML output
    require_once __DIR__ . '/includes/session_handler.php';
    require_once dirname(__DIR__) . '/db/connect.php';
} else {
    // Standard Request: include full navigation (outputs HTML)
    require __DIR__ . "/includes/nav.php";
}

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

require_once __DIR__ . '/includes/registration_handlers.php'; // admissionsTermBasedSql / admissionsIsProgramTermBased

// Fetch available intakes from database or define them
$intake_types = [
    'semester' => ['January', 'June'],
    'term' => ['Term1', 'Term2', 'Term3']
];

// Term vs semester is read from the authoritative programs.period_mode (NOT
// study_mode, which is Full/Part-time attendance and the same for every programme).
function isTermBasedProgram($program_code, $db) {
    return admissionsIsProgramTermBased($db, (string)$program_code);
}

// Get current academic year
function getCurrentAcademicYear() {
    $current_month = date('n'); // 1-12
    $current_year = date('Y');
    
    // Assuming academic year starts in January
    if ($current_month >= 1 && $current_month <= 6) {
        return ['start' => $current_year, 'end' => $current_year];
    } else {
        return ['start' => $current_year, 'end' => $current_year + 1];
    }
}

// Get available terms/years based on program type
function getAvailableTerms($program_code, $db) {
    $term_based = isTermBasedProgram($program_code, $db);
    $current_year = getCurrentAcademicYear();
    
    $terms = [];
    if ($term_based) {
        // Term-based programs
        $terms = [
            'Term1' => $current_year['start'] . '-01',
            'Term2' => $current_year['start'] . '-05',
            'Term3' => $current_year['start'] . '-09'
        ];
    } else {
        // Semester-based programs
        $terms = [
            'January' => $current_year['start'] . '-01',
            'June' => $current_year['start'] . '-06'
        ];
    }
    
    return $terms;
}

// AJAX Bulk Handler
if (isset($_POST['ajax_bulk'])) {
    // Clear buffer to ensure JSON response
    ob_clean();
    header('Content-Type: application/json');

    // Validate basics
    if (!isset($_POST["Sid"], $_POST["program_code"], $_POST["intake"], $_POST["mode"], $_POST["startYear"])) {
         echo json_encode(['success' => false, 'message' => 'Missing required fields']);
         exit;
    }

    $Sid = trim($_POST["Sid"]);
    $program_code = trim($_POST["program_code"]);
    $intake = trim($_POST["intake"]);
    $mode = trim($_POST["mode"]);
    $entryYear = (int)substr(trim($_POST["startYear"]), 0, 4) ?: (int)date('Y');

    // Optional transfer details (kept — orthogonal to enrolment).
    if (isset($_POST["isTransfer"])) {
        $previous_institution = isset($_POST["previous_institution"]) ? trim($_POST["previous_institution"]) : null;
        $credits_transferred  = isset($_POST["credits_transferred"]) ? trim($_POST["credits_transferred"]) : null;
        if ($studentTransfer = $db->prepare("UPDATE students SET is_transfer = 1, transfer_from = ?, transfer_credits = ? WHERE SID = ?")) {
            $studentTransfer->bind_param("sis", $previous_institution, $credits_transferred, $Sid);
            $studentTransfer->execute();
            $studentTransfer->close();
        }
    }

    // Single canonical admission path (full enrolment + activation + login +
    // course/invoice) — same helper the admin module and online-applicant flow use.
    require_once dirname(__DIR__) . '/includes/applicant_admission.php';
    echo json_encode(admissionsEnrollExistingStudent($db, $Sid, $program_code, $intake, $mode, $entryYear));
    exit;
}


if (!empty($_POST)) {
    if (isset($_POST["Sid"], $_POST["program_code"], $_POST["intake"], $_POST["mode"], $_POST["startYear"])) {

        $Sid = trim($_POST["Sid"]);
        $program_code = trim($_POST["program_code"]);
        $intake = trim($_POST["intake"]);
        $mode = trim($_POST["mode"]);
        $entryYear = (int)substr(trim($_POST["startYear"]), 0, 4) ?: (int)date('Y');

        // Optional transfer details (orthogonal to enrolment).
        if (isset($_POST["isTransfer"])) {
            $previous_institution = isset($_POST["previous_institution"]) ? trim($_POST["previous_institution"]) : null;
            $credits_transferred  = isset($_POST["credits_transferred"]) ? trim($_POST["credits_transferred"]) : null;
            if ($studentTransfer = $db->prepare("UPDATE students SET is_transfer = 1, transfer_from = ?, transfer_credits = ? WHERE SID = ?")) {
                $studentTransfer->bind_param("sis", $previous_institution, $credits_transferred, $Sid);
                $studentTransfer->execute();
                $studentTransfer->close();
            }
        }

        // Single canonical admission path (full enrolment + activation + login +
        // course/invoice) shared with the admin module and online-applicant flow.
        require_once dirname(__DIR__) . '/includes/applicant_admission.php';
        $result = admissionsEnrollExistingStudent($db, $Sid, $program_code, $intake, $mode, $entryYear);

        $dest = $result['success'] ? 'students.php' : 'admitEnrolled_student.php';
        echo "<script>alert(" . json_encode($result['message']) . ");window.open('" . $dest . "','_self')</script>";
    }
}

// Fetch programs
$records = array();
$termBasedExpr = admissionsTermBasedSql($db);
if ($results = $db->query("SELECT *, {$termBasedExpr} AS term_based FROM programs WHERE COALESCE(is_active, 1) = 1 AND program_code NOT IN ('CSE', 'ICT-002') ORDER BY program_name")) {
    while ($row = $results->fetch_object()) {
        $records[] = $row;
    }
    $results->free();
}

?>


<?php include_once "includes/admit_modal.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php require "includes/footer.php"; ?>


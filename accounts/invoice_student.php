<?php
/**
 * Invoice Student Account
 *
 * Search for a student and create an invoice for their account.
 */

$page_title = 'Invoice Student Account';
require "includes/nav.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/**
 * Expected POST contract:
 * - Search form: search, student_id
 * - Invoice form: csrf_token, student_id, invoice_amount, narration, other_narration, semester, year_of_study
 */

/**
 * Resolve the highest configured year of study for a program.
 */
function getMaxYearOfStudy(mysqli $db, string $programCode): int
{
    $defaultMaxYear = 4;
    $sql = "SELECT MAX(year_of_study) AS max_year
            FROM fee_structure
            WHERE entity_type = 'program'
              AND program_code = ?
              AND year_of_study IS NOT NULL";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return $defaultMaxYear;
    }

    $stmt->bind_param('s', $programCode);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $maxYear = (int)($row['max_year'] ?? 0);
    return $maxYear > 0 ? $maxYear : $defaultMaxYear;
}

$student = null;
$error_message = '';
$max_year_of_study = 4;

if (isset($_POST['search'])) {
    $student_id = trim($_POST['student_id'] ?? '');

    if ($student_id === '') {
        $error_message = 'Please enter a Student ID to search.';
    } else {
        $sql = "SELECT
                    s.SID,
                    s.title,
                    s.Fname,
                    s.Lname,
                    s.nrc_pass,
                    sp.program_code,
                    (
                        SELECT COALESCE(SUM(COALESCE(i.total_amount, i.amount, 0)), 0)
                        FROM invoices i
                        WHERE CAST(i.SID AS CHAR(50)) COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci
                           OR i.student_id COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci
                    ) - (
                        SELECT COALESCE(SUM(p.amount_paid), 0)
                        FROM student_payments p
                        WHERE p.Sid COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci
                    ) AS calculated_balance
                FROM students s
                INNER JOIN student_program sp
                    ON sp.Sid COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci
                WHERE sp.Sid COLLATE utf8mb4_general_ci = ?
                LIMIT 1";

        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $student_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $student = $result->fetch_assoc();
                $max_year_of_study = getMaxYearOfStudy($db, (string)($student['program_code'] ?? ''));
            } else {
                $error_message = 'This student ID is not registered in the system.';
            }
            $stmt->close();
        } else {
            $error_message = 'Database error. Please try again later.';
        }
    }
}
?>

<div class="container-fluid px-4 portal-dashboard accounts-page invoice-account-page">
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Invoice Student Account</h1>
                <p class="text-muted mb-0">Create invoices for registered students</p>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-9 mx-auto">
            <div class="data-table-card mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-receipt me-2"></i>Invoice Student Account
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label for="student_id" class="form-label">
                                    <i class="fas fa-user-graduate me-1"></i> Student ID
                                </label>
                                <input type="text" class="form-control" name="student_id" id="student_id"
                                       placeholder="Enter Student ID"
                                       value="<?php echo htmlspecialchars($_POST['student_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                       autocomplete="off" required>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary w-100" name="search">
                                    <i class="fas fa-search me-2"></i>Search
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($student): ?>
                <div class="alert alert-info text-center mb-4">
                    <h4 class="mb-0">
                        <strong><?php echo htmlspecialchars(trim(($student['title'] ?? '') . ' ' . ($student['Fname'] ?? '') . ' ' . ($student['Lname'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong> -
                        <strong><?php echo htmlspecialchars($student['nrc_pass'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong><br>
                        <span class="badge bg-primary mt-2"><?php echo htmlspecialchars($student['program_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                    </h4>
                </div>

                <div class="data-table-card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-file-invoice me-2"></i>Invoice Details
                            </h5>
                        </div>
                    </div>
                    <div class="card-body">
                        <form action="process_invoice.php" method="POST" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="col-md-4">
                                <label for="invoice_student_id" class="form-label">
                                    Student ID <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="student_id" id="invoice_student_id"
                                       value="<?php echo htmlspecialchars($student['SID'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>

                            <div class="col-md-4">
                                <label for="invoice_amount" class="form-label">
                                    Invoice Amount (ZMW) <span class="text-danger">*</span>
                                </label>
                                <input type="number" step="0.01" min="0.01" class="form-control"
                                       name="invoice_amount" id="invoice_amount"
                                       placeholder="Enter invoice amount" required>
                            </div>

                            <div class="col-md-4">
                                <label for="calculated_balance" class="form-label">Calculated Balance (ZMW)</label>
                                <input type="text" class="form-control"
                                       id="calculated_balance"
                                       value="<?php echo number_format((float)($student['calculated_balance'] ?? 0), 2, '.', ''); ?>"
                                       readonly>
                                <div class="form-text">Advisory only. The current balance is recalculated again when you submit.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="narration" class="form-label">Narration <span class="text-danger">*</span></label>
                                <select class="form-select" name="narration" id="narration" required>
                                    <option value="" selected disabled>Select narration</option>
                                    <option value="Tuition Fee">Tuition Fee</option>
                                    <option value="Registration Fee">Registration Fee</option>
                                    <option value="Library Fee">Library Fee</option>
                                    <option value="Accommodation Fee">Accommodation Fee</option>
                                    <option value="Examination Fee">Examination Fee</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="col-md-6 d-none" id="other_narration_wrapper">
                                <label for="other_narration" class="form-label">Other Narration <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="other_narration" id="other_narration"
                                       placeholder="Enter custom narration">
                            </div>

                            <div class="col-md-3">
                                <label for="semester" class="form-label">
                                    Semester/Term <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" name="semester" id="semester" required>
                                    <option value="" disabled selected>Select</option>
                                    <option value="1">Semester 1</option>
                                    <option value="2">Semester 2</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label for="year_of_study" class="form-label">
                                    Year <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="year_of_study" name="year_of_study" required>
                                    <option value="" disabled selected>Year of study</option>
                                    <?php for ($year = 1; $year <= $max_year_of_study; $year++): ?>
                                        <option value="<?php echo $year; ?>">Year <?php echo $year; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-12 text-end mt-4">
                                <a href="invoice_student.php" class="btn btn-outline-secondary me-2">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button class="btn btn-primary px-4" type="submit" name="submit">
                                    <i class="fas fa-check me-2"></i>Submit Invoice
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($student): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var narrationSelect = document.getElementById('narration');
    var otherNarrationWrapper = document.getElementById('other_narration_wrapper');
    var otherNarrationInput = document.getElementById('other_narration');

    if (!narrationSelect || !otherNarrationWrapper || !otherNarrationInput) {
        return;
    }

    function toggleOtherNarration() {
        var isOther = narrationSelect.value === 'Other';
        otherNarrationWrapper.classList.toggle('d-none', !isOther);
        otherNarrationInput.required = isOther;
        if (!isOther) {
            otherNarrationInput.value = '';
        }
    }

    narrationSelect.addEventListener('change', toggleOtherNarration);
    toggleOtherNarration();
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>

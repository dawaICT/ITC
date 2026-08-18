<?php
require "includes/nav.php";
require_once __DIR__ . '/includes/registration_handlers.php'; // admissionsTermBasedSql / admissionsIsProgramTermBased

if(isset($_GET['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    echo "<!-- DEBUG MODE ENABLED -->";
}

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
<div class="container-fluid px-4 py-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-3 d-flex align-items-center justify-content-between">
        <h3 class="dashboard-title mb-0"><i class="fas fa-user-check me-2"></i>Admit Student</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#admitModal"><i class="fas fa-plus me-1"></i> Open Form</button>
    </div>
</div>

<div class="modal fade" id="admitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog" style="max-width: 720px;">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Student Admission Form</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="data-table-card border-0">
                    <div class="card-body">
                        <form action="processAdmit_student.php" method="post" id="admissionForm">
                            <div class="mb-3">
                                <label for="Sid" class="form-label">Student ID (SID)</label>
                                <input type="text" class="form-control" id="Sid" name="Sid" required 
                                       placeholder="Enter student ID" oninput="checkStudentStatus()">
                                <div id="studentStatus" class="form-text"></div>
                            </div>
                            
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="program_code" class="form-label">Program of study</label>
                                    <select class="form-select" name="program_code" id="program_code" required 
                                            onchange="updateIntakeOptions()">
                                        <option disabled selected value="">Select program</option>
                                        <?php foreach($records as $r): ?>
                                        <option value="<?php echo htmlspecialchars($r->program_code); ?>"
                                                data-term-based="<?php echo isset($r->term_based) ? $r->term_based : 0; ?>">
                                            <?php echo htmlspecialchars($r->program_name); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="programType" class="form-text"></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="intake" class="form-label">Intake</label>
                                    <select class="form-select" name="intake" id="intake" required disabled>
                                        <option disabled selected value="">Select program first</option>
                                    </select>
                                    <div id="intakeInfo" class="form-text"></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="mode" class="form-label">Mode of study</label>
                                <select class="form-select" name="mode" id="mode" required>
                                    <option disabled selected value="">Select mode</option>
                                    <option value="Full-Time">Full-Time</option>
                                    <option value="Part-Time(Evening)">Part-Time (Evening)</option>
                                    <option value="Distance">Distance Learning</option>
                                    <option value="Online">Online</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="isTransfer" name="isTransfer">
                                    <label class="form-check-label" for="isTransfer">
                                        Transfer Student
                                    </label>
                                </div>
                            </div>
                            
                            <div id="transferDetails" style="display: none;">
                                <h6 class="mt-3 mb-2">Transfer Details</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="previous_institution" class="form-label">Previous Institution</label>
                                        <input type="text" class="form-control" id="previous_institution" name="previous_institution">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="credits_transferred" class="form-label">Credits Transferred</label>
                                        <input type="number" class="form-control" id="credits_transferred" name="credits_transferred">
                                    </div>
                                </div>
                            </div>
                            
                            <hr class="my-3">
                            
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="startYear" class="form-label">Academic Year Start</label>
                                    <input type="month" class="form-control" id="startYear" name="startYear" required
                                           value="<?php echo date('Y-m'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="endYear" class="form-label">Academic Year End</label>
                                    <input type="month" class="form-control" id="endYear" name="endYear" required
                                           value="<?php echo date('Y-m', strtotime('+1 year')); ?>">
                                </div>
                            </div>
                            
                            <div id="termDates" style="display: none;">
                                <h6 class="mt-3 mb-2">Term Dates</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="term_start_date" class="form-label">Term Start Date</label>
                                        <input type="date" class="form-control" id="term_start_date" name="term_start_date">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="term_end_date" class="form-label">Term End Date</label>
                                        <input type="date" class="form-control" id="term_end_date" name="term_end_date">
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100 py-2 mt-3" id="submitBtn">Admit Student</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Function to check student status
function checkStudentStatus() {
    const sid = document.getElementById('Sid').value;
    if (sid.length < 3) return;
    
    fetch('checkStudent.php?sid=' + encodeURIComponent(sid))
        .then(response => response.json())
        .then(data => {
            const statusDiv = document.getElementById('studentStatus');
            if (data.exists) {
                if (data.status === 'active') {
                    statusDiv.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-triangle"></i> Student is already active</span>';
                } else if (data.status === 'inactive') {
                    statusDiv.innerHTML = '<span class="text-info"><i class="fas fa-info-circle"></i> Student is inactive - can be reactivated</span>';
                } else {
                    statusDiv.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> Student found</span>';
                }
            } else {
                statusDiv.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> Student ID not found</span>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// Function to update intake options based on program
function updateIntakeOptions() {
    const programSelect = document.getElementById('program_code');
    const intakeSelect = document.getElementById('intake');
    const programTypeDiv = document.getElementById('programType');
    const intakeInfoDiv = document.getElementById('intakeInfo');
    const termDatesDiv = document.getElementById('termDates');
    
    if (!programSelect.value) {
        intakeSelect.disabled = true;
        intakeSelect.innerHTML = '<option disabled selected value="">Select program first</option>';
        return;
    }
    
    const selectedOption = programSelect.options[programSelect.selectedIndex];
    const isTermBased = selectedOption.getAttribute('data-term-based') === '1';
    
    // Update program type display
    if (isTermBased) {
        programTypeDiv.innerHTML = '<span class="text-success"><i class="fas fa-calendar-alt"></i> Term-based Program</span>';
        intakeInfoDiv.innerHTML = '<span class="text-success">This program uses term-based intakes</span>';
        termDatesDiv.style.display = 'block';
    } else {
        programTypeDiv.innerHTML = '<span class="text-primary"><i class="fas fa-calendar"></i> Semester-based Program</span>';
        intakeInfoDiv.innerHTML = '<span class="text-primary">This program uses semester-based intakes</span>';
        termDatesDiv.style.display = 'none';
    }
    
    // Enable intake select
    intakeSelect.disabled = false;
    
    // Clear and repopulate intake options
    intakeSelect.innerHTML = '<option disabled selected value="">Select intake</option>';
    
    if (isTermBased) {
        // Term-based intakes
        const currentYear = new Date().getFullYear();
        const terms = [
            {value: 'Term1', text: `Term 1 (Jan-Apr ${currentYear})`, class: 'term-option'},
            {value: 'Term2', text: `Term 2 (May-Aug ${currentYear})`, class: 'term-option'},
            {value: 'Term3', text: `Term 3 (Sep-Dec ${currentYear})`, class: 'term-option'}
        ];
        
        terms.forEach(term => {
            const option = document.createElement('option');
            option.value = term.value;
            option.textContent = term.text;
            option.className = term.class;
            intakeSelect.appendChild(option);
        });
        
        // Set default term dates
        const month = new Date().getMonth() + 1;
        let defaultTerm = 'Term1';
        if (month >= 5 && month <= 8) defaultTerm = 'Term2';
        if (month >= 9) defaultTerm = 'Term3';
        
        updateTermDates(defaultTerm);
        intakeSelect.value = defaultTerm;
    } else {
        // Semester-based intakes
        const currentYear = new Date().getFullYear();
        const nextYear = currentYear + 1;
        const semesters = [
            {value: 'January', text: `January Intake ${currentYear}`, class: 'semester-option'},
            {value: 'June', text: `June Intake ${currentYear}`, class: 'semester-option'}
        ];
        
        semesters.forEach(semester => {
            const option = document.createElement('option');
            option.value = semester.value;
            option.textContent = semester.text;
            option.className = semester.class;
            intakeSelect.appendChild(option);
        });
        
        // Set default semester based on current month
        const month = new Date().getMonth() + 1;
        if (month <= 5) {
            intakeSelect.value = 'January';
        } else {
            intakeSelect.value = 'June';
        }
    }
}

// Function to update term dates based on selected intake
function updateTermDates(term) {
    const currentYear = new Date().getFullYear();
    let startDate, endDate;
    
    switch(term) {
        case 'Term1':
            startDate = `${currentYear}-01-15`;
            endDate = `${currentYear}-04-15`;
            break;
        case 'Term2':
            startDate = `${currentYear}-05-15`;
            endDate = `${currentYear}-08-15`;
            break;
        case 'Term3':
            startDate = `${currentYear}-09-15`;
            endDate = `${currentYear}-12-15`;
            break;
    }
    
    document.getElementById('term_start_date').value = startDate;
    document.getElementById('term_end_date').value = endDate;
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Transfer student checkbox
    document.getElementById('isTransfer').addEventListener('change', function() {
        document.getElementById('transferDetails').style.display = this.checked ? 'block' : 'none';
    });
    
    // When intake changes, update term dates for term-based programs
    document.getElementById('intake').addEventListener('change', function() {
        const programSelect = document.getElementById('program_code');
        const selectedOption = programSelect.options[programSelect.selectedIndex];
        const isTermBased = selectedOption.getAttribute('data-term-based') === '1';
        
        if (isTermBased && this.value.startsWith('Term')) {
            updateTermDates(this.value);
        }
    });
    
    // Reset form when modal closes
    const admitModal = document.getElementById('admitModal');
    admitModal.addEventListener('hidden.bs.modal', function () {
        document.getElementById('admissionForm').reset();
        document.getElementById('intake').disabled = true;
        document.getElementById('intake').innerHTML = '<option disabled selected value="">Select program first</option>';
        document.getElementById('programType').innerHTML = '';
        document.getElementById('intakeInfo').innerHTML = '';
        document.getElementById('studentStatus').innerHTML = '';
        document.getElementById('termDates').style.display = 'none';
        document.getElementById('transferDetails').style.display = 'none';
    });
    
    // Form validation before submit
    document.getElementById('admissionForm').addEventListener('submit', function(e) {
        const sid = document.getElementById('Sid').value.trim();
        const program = document.getElementById('program_code').value;
        const intake = document.getElementById('intake').value;
        
        if (!sid || !program || !intake) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return;
        }
        
        // Confirm admission
        if (!confirm('Are you sure you want to admit this student?')) {
            e.preventDefault();
        }
    });
});
</script>

<?php require "includes/footer.php"; ?>



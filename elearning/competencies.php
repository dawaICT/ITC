<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = trim($_GET['course_code'] ?? ($_POST['course_code'] ?? ''));

if (!$staffId || !$courseCode) {
    http_response_code(403);
    die('Unauthorized');
}

enforceLecturerCourseAccess($db, $staffId, $courseCode);

// Resolve course name
$courseName = $courseCode;
if ($stmt = $db->prepare("SELECT course_name FROM courses WHERE course_code=? LIMIT 1")) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $courseName = $row['course_name'];
    }
    $stmt->close();
}

$errors = [];
$success = null;

// Handle CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Action: Add new competency
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['add_competency'])) {
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if (!hash_equals($csrfToken, $token)) {
        $errors[] = 'Security token validation failed. Refresh and try again.';
    }
    
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    
    if ($title === '') {
        $errors[] = 'Competency title is required.';
    }
    
    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO el_competencies (course_code, title, description) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $courseCode, $title, $description);
        if ($stmt->execute()) {
            $success = 'Competency task created successfully!';
        } else {
            $errors[] = 'Failed to create competency task.';
        }
        $stmt->close();
    }
}

// Action: Save student verification
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['verify_student'])) {
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if (!hash_equals($csrfToken, $token)) {
        $errors[] = 'Security token validation failed. Refresh and try again.';
    }
    
    $studentId = trim((string)($_POST['student_id'] ?? ''));
    $competencyId = (int)($_POST['competency_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? 'not_started'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $verifyAs = trim((string)($_POST['verify_as'] ?? 'lecturer')); // lecturer, trainer, or industry
    $supervisorName = trim((string)($_POST['supervisor_name'] ?? ''));
    
    $allowedStatuses = ['not_started', 'in_progress', 'competent', 'verified'];
    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Invalid status.';
    }
    
    if (!$errors) {
        // Find existing record
        $stmt = $db->prepare("SELECT id FROM el_student_competencies WHERE student_id=? AND competency_id=? LIMIT 1");
        $stmt->bind_param('si', $studentId, $competencyId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        
        if ($exists) {
            // Update
            if ($verifyAs === 'trainer') {
                $sql = "UPDATE el_student_competencies 
                        SET status=?, notes=?, trainer_id=?, trainer_verified_at=NOW() 
                        WHERE student_id=? AND competency_id=?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('ssssi', $status, $notes, $staffId, $studentId, $competencyId);
            } elseif ($verifyAs === 'industry') {
                $sql = "UPDATE el_student_competencies 
                        SET status=?, notes=?, industry_supervisor_name=?, industry_verified_at=NOW() 
                        WHERE student_id=? AND competency_id=?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('ssssi', $status, $notes, $supervisorName, $studentId, $competencyId);
            } else {
                // Default lecturer
                $sql = "UPDATE el_student_competencies 
                        SET status=?, notes=?, lecturer_id=?, lecturer_verified_at=NOW() 
                        WHERE student_id=? AND competency_id=?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('ssssi', $status, $notes, $staffId, $studentId, $competencyId);
            }
        } else {
            // Insert
            if ($verifyAs === 'trainer') {
                $sql = "INSERT INTO el_student_competencies (student_id, competency_id, status, notes, trainer_id, trainer_verified_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('sisss', $studentId, $competencyId, $status, $notes, $staffId);
            } elseif ($verifyAs === 'industry') {
                $sql = "INSERT INTO el_student_competencies (student_id, competency_id, status, notes, industry_supervisor_name, industry_verified_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('sisss', $studentId, $competencyId, $status, $notes, $supervisorName);
            } else {
                // Default lecturer
                $sql = "INSERT INTO el_student_competencies (student_id, competency_id, status, notes, lecturer_id, lecturer_verified_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('sisss', $studentId, $competencyId, $status, $notes, $staffId);
            }
        }
        
        if ($stmt->execute()) {
            $success = 'Evaluation saved successfully!';
        } else {
            $errors[] = 'Failed to save evaluation.';
        }
        $stmt->close();
    }
}

// Get all competencies for this course
$competencies = [];
if ($stmt = $db->prepare("SELECT * FROM el_competencies WHERE course_code = ? ORDER BY id ASC")) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $competencies[] = $row;
    }
    $stmt->close();
}

// Get enrolled students for the course
$students = [];
// course_registration is the single enrollment source (student_courses never
// existed); live rows carry status 'registered'.
$studentSql = "SELECT r.Sid AS student_id, s.Fname AS fname, s.Lname AS lname
               FROM course_registration r
               INNER JOIN students s ON s.SID = r.Sid
               WHERE r.course_code = ? AND COALESCE(r.is_active, 1) = 1
               GROUP BY r.Sid, s.Fname, s.Lname";

if ($stmt = $db->prepare($studentSql)) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $students[$row['student_id']] = $row;
    }
    $stmt->close();
}

// Fetch all competency results for course students
$studentCompetencies = [];
$sql = "SELECT sc.*, c.title AS comp_title
        FROM el_student_competencies sc
        INNER JOIN el_competencies c ON c.id = sc.competency_id
        WHERE c.course_code = ?";
if ($stmt = $db->prepare($sql)) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $studentCompetencies[$row['student_id']][$row['competency_id']] = $row;
    }
    $stmt->close();
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>
<div class="elearning-shell">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-tasks text-purple"></i> Practical & Competency Tracking</h1>
            <p class="elearning-subtitle"><?php echo htmlspecialchars($courseCode); ?> — <?php echo htmlspecialchars($courseName); ?></p>
        </div>
    </div>
    
    <?php elearningCourseTabs($courseCode, 'competencies'); ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="row mt-4">
        <div class="col-lg-8">
            <!-- Roster Table -->
            <section class="elearning-panel">
                <div class="elearning-panel-header"><strong>Student Competency Roster</strong></div>
                <div class="elearning-panel-body">
                    <?php if (empty($students)): ?>
                        <p class="text-muted">No students are currently registered for this course.</p>
                    <?php elseif (empty($competencies)): ?>
                        <p class="text-muted">Please add at least one competency standard to begin tracking.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student ID & Name</th>
                                        <?php foreach ($competencies as $c): ?>
                                            <th style="min-width: 140px; font-size: 0.8rem; text-align: center;" title="<?php echo htmlspecialchars($c['description'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($c['title']); ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $sId => $s): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($sId); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($s['fname'] . ' ' . $s['lname']); ?></small>
                                            </td>
                                            <?php foreach ($competencies as $c): 
                                                $compRecord = $studentCompetencies[$sId][$c['id']] ?? null;
                                                $status = $compRecord['status'] ?? 'not_started';
                                                $badgeClass = 'bg-light text-dark';
                                                if ($status === 'in_progress') $badgeClass = 'bg-warning text-dark';
                                                elseif ($status === 'competent') $badgeClass = 'bg-success text-white';
                                                elseif ($status === 'verified') $badgeClass = 'bg-purple text-white';
                                            ?>
                                                <td style="text-align: center;">
                                                    <span class="badge <?php echo $badgeClass; ?> mb-1 d-block" style="font-size: 0.78rem;">
                                                        <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                                                    </span>
                                                    
                                                    <?php if ($compRecord && $compRecord['evidence_path']): ?>
                                                        <a href="<?php echo htmlspecialchars($compRecord['evidence_path']); ?>" target="_blank" class="btn btn-sm btn-link p-0 text-decoration-none d-block mb-1" style="font-size: 0.72rem;">
                                                            <i class="fas fa-paperclip"></i> View Evidence
                                                        </a>
                                                    <?php endif; ?>

                                                    <!-- Edit Action trigger -->
                                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.75rem;" 
                                                            onclick="openEvalModal(<?php echo json_encode($sId); ?>, <?php echo json_encode($s['fname'] . ' ' . $s['lname']); ?>, <?php echo (int)$c['id']; ?>, <?php echo json_encode($c['title']); ?>, <?php echo json_encode($status); ?>, <?php echo json_encode($compRecord['notes'] ?? ''); ?>, <?php echo json_encode($compRecord['industry_supervisor_name'] ?? ''); ?>)">
                                                        Evaluate
                                                    </button>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            <!-- Add Competency Standard Form -->
            <section class="elearning-panel mb-4">
                <div class="elearning-panel-header"><strong>Create Competency Standard</strong></div>
                <div class="elearning-panel-body">
                    <form method="POST">
                        <input type="hidden" name="add_competency" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        
                        <div class="form-group mb-3">
                            <label class="form-label">Task/Skill Title</label>
                            <input type="text" name="title" class="form-control" placeholder="e.g. Normalization to 3NF" required>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="form-label">Detailed Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Provide instructions, requirements, or evidence outcomes expected from the student..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-plus"></i> Add Competency
                        </button>
                    </form>
                </div>
            </section>

            <!-- Listed Standards -->
            <section class="elearning-panel">
                <div class="elearning-panel-header"><strong>Mapped Standards</strong></div>
                <div class="elearning-panel-body">
                    <?php if (empty($competencies)): ?>
                        <p class="text-muted">No standards mapped yet.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($competencies as $c): ?>
                                <li class="list-group-item px-0">
                                    <strong><?php echo htmlspecialchars($c['title']); ?></strong>
                                    <p class="text-muted small mb-0"><?php echo htmlspecialchars($c['description']); ?></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<!-- Evaluation Modal -->
<div class="modal fade" id="evalModal" tabindex="-1" aria-labelledby="evalModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="evalModalLabel">Log Competency Evaluation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="verify_student" value="1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="student_id" id="modalStudentId">
        <input type="hidden" name="competency_id" id="modalCompetencyId">

        <div class="mb-3">
            <strong>Student:</strong> <span id="modalStudentName"></span> (<span id="modalStudentIdLabel"></span>)
        </div>
        <div class="mb-3">
            <strong>Task:</strong> <span id="modalCompTitle"></span>
        </div>
        
        <hr>

        <div class="mb-3">
            <label class="form-label">Verification Mode</label>
            <select name="verify_as" id="modalVerifyAs" class="form-select" onchange="toggleIndustryField()" required>
                <option value="lecturer">Verify as Assigned Lecturer</option>
                <option value="trainer">Verify as Practical Trainer / Coordinator</option>
                <option value="industry">Verify as Industry Supervisor (Internship)</option>
            </select>
        </div>

        <div class="mb-3 d-none" id="industryNameGroup">
            <label class="form-label">Industry Supervisor Name</label>
            <input type="text" name="supervisor_name" id="modalSupervisorName" class="form-control" placeholder="e.g. Eng. Mulenga, Barloworld">
        </div>

        <div class="mb-3">
            <label class="form-label">Competency Status</label>
            <select name="status" id="modalStatus" class="form-select" required>
                <option value="not_started">Not Started</option>
                <option value="in_progress">In Progress</option>
                <option value="competent">Competent (Approved)</option>
                <option value="verified">Verified (Signed Off)</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Evaluator Feedback Notes</label>
            <textarea name="notes" id="modalNotes" class="form-control" rows="3" placeholder="Provide feedback or notes on evidence uploaded..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Evaluation</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEvalModal(studentId, studentName, competencyId, compTitle, status, notes, supervisorName) {
    document.getElementById('modalStudentId').value = studentId;
    document.getElementById('modalStudentIdLabel').textContent = studentId;
    document.getElementById('modalStudentName').textContent = studentName;
    document.getElementById('modalCompetencyId').value = competencyId;
    document.getElementById('modalCompTitle').textContent = compTitle;
    document.getElementById('modalStatus').value = status;
    document.getElementById('modalNotes').value = notes;
    document.getElementById('modalSupervisorName').value = supervisorName || '';
    
    // Reset verify_as select to default
    document.getElementById('modalVerifyAs').value = 'lecturer';
    toggleIndustryField();
    
    var myModal = new bootstrap.Modal(document.getElementById('evalModal'));
    myModal.show();
}

function toggleIndustryField() {
    var val = document.getElementById('modalVerifyAs').value;
    var group = document.getElementById('industryNameGroup');
    if (val === 'industry') {
        group.classList.remove('d-none');
    } else {
        group.classList.add('d-none');
    }
}
</script>
</body>
</html>

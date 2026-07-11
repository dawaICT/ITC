<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/grading_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/academic_risk_engine.php';

$evaluated = false;
$results = [];
$min_gpa = 2.0;
$min_credits = 12;
$max_fails = 2;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['evaluate'])) {
    $min_gpa = (float)($_POST['min_gpa'] ?? $_GET['min_gpa'] ?? 2.0);
    $min_credits = (int)($_POST['min_credits'] ?? $_GET['min_credits'] ?? 12);
    $max_fails = (int)($_POST['max_fails'] ?? $_GET['max_fails'] ?? 2);
    
    // Fetch all students
    $studentsRes = $db->query("SELECT s.SID, s.Fname, s.Lname, sp.program_code 
                               FROM students s 
                               LEFT JOIN student_program sp ON sp.Sid = s.SID
                               WHERE COALESCE(s.status, 'active') NOT IN ('inactive','deleted')");
    while ($studentsRes && $student = $studentsRes->fetch_assoc()) {
        $sid = $student['SID'];
        
        // Fetch all published exams for this student
        $examsRes = $db->query("SELECT e.Course_Code, e.Exam_marks, e.Total_marks, 
                                       COALESCE(c.credit_hours, 3) AS credit_hours,
                                       COALESCE(sa.Total_CA, 0) AS Total_CA
                                FROM exams e
                                LEFT JOIN courses c ON c.course_code = e.Course_Code
                                LEFT JOIN semester_assessment sa ON sa.Sid = e.Sid AND sa.Course_Code = e.Course_Code AND sa.Year = e.Year AND sa.semester = e.semester
                                WHERE e.Sid = '" . $db->real_escape_string($sid) . "'
                                  AND e.status = 'Published' AND e.Exam_marks IS NOT NULL");
        
        $totalPoints = 0;
        $totalCredits = 0;
        $failsCount = 0;
        $earnedCredits = 0;
        
        while ($examsRes && $exam = $examsRes->fetch_assoc()) {
            $ca = (float)$exam['Total_CA'];
            $examMark = (float)$exam['Exam_marks'];
            $credits = (int)$exam['credit_hours'];
            
            $computed = wuc_result_compute($db, $sid, $ca, $examMark);
            $finalMark = $computed['final'];
            $points = $computed['points'];
            $grade = $computed['grade'];
            
            $totalPoints += $points * $credits;
            $totalCredits += $credits;
            
            if ($grade === 'F') {
                $failsCount++;
            } else {
                $earnedCredits += $credits;
            }
        }
        
        $gpa = $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : 0.0;
        
        // Progression status
        $eligible = ($gpa >= $min_gpa && $earnedCredits >= $min_credits && $failsCount <= $max_fails);
        
        $results[] = [
            'SID' => $sid,
            'name' => trim($student['Fname'] . ' ' . $student['Lname']),
            'program_code' => $student['program_code'] ?? 'N/A',
            'gpa' => $gpa,
            'earned_credits' => $earnedCredits,
            'fails' => $failsCount,
            'eligible' => $eligible
        ];
    }
    $evaluated = true;
}
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <h1 class="dashboard-title"><i class="fas fa-user-graduate me-2 text-primary"></i>Progression & Graduation Clearance</h1>
    <p class="text-muted mb-0">Evaluate student eligibility, view progression status, and verify graduation criteria.</p>
  </div>

  <div class="row g-3">
    <div class="col-lg-12">
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
          <h5 class="card-title mb-0 fw-semibold"><i class="fas fa-sliders-h me-2 text-secondary"></i>Eligibility Evaluation Parameters</h5>
        </div>
        <div class="card-body">
          <form method="POST" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
              <label class="form-label fw-semibold">Min Cumulative GPA</label>
              <input type="number" step="0.1" min="0" max="4" value="<?php echo htmlspecialchars((string)$min_gpa); ?>" class="form-control" name="min_gpa" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Min Earned Credits</label>
              <input type="number" min="0" max="200" value="<?php echo htmlspecialchars((string)$min_credits); ?>" class="form-control" name="min_credits" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Max Fails Allowed</label>
              <input type="number" min="0" max="10" value="<?php echo htmlspecialchars((string)$max_fails); ?>" class="form-control" name="max_fails" required>
            </div>
            <div class="col-md-3">
              <button class="btn btn-primary w-100 py-2" type="submit"><i class="fas fa-calculator me-2"></i>Evaluate Eligibility</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <?php if ($evaluated): ?>
    <div class="col-12">
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <h5 class="card-title mb-0 fw-semibold"><i class="fas fa-table me-2 text-secondary"></i>Evaluation Results</h5>
          <span class="badge bg-primary fs-6"><?php echo count($results); ?> Student(s) Evaluated</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Student ID</th>
                  <th>Full Name</th>
                  <th>Program</th>
                  <th class="text-center">Cumulative GPA</th>
                  <th class="text-center">Earned Credits</th>
                  <th class="text-center">Fails</th>
                  <th class="text-center">Clearance Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($results)): ?>
                  <tr><td colspan="7" class="text-center py-4 text-muted">No students found.</td></tr>
                <?php else: ?>
                  <?php foreach ($results as $r): ?>
                    <tr>
                      <td class="fw-semibold"><code><?php echo htmlspecialchars($r['SID']); ?></code></td>
                      <td><?php echo htmlspecialchars($r['name']); ?></td>
                      <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($r['program_code']); ?></span></td>
                      <td class="text-center fw-bold"><?php echo number_format($r['gpa'], 2); ?></td>
                      <td class="text-center"><?php echo $r['earned_credits']; ?></td>
                      <td class="text-center">
                        <?php if ($r['fails'] > 0): ?>
                          <span class="badge bg-danger"><?php echo $r['fails']; ?></span>
                        <?php else: ?>
                          <span class="badge bg-success">0</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-center">
                        <?php if ($r['eligible']): ?>
                          <span class="badge bg-success py-2 px-3"><i class="fas fa-check-circle me-1"></i>Cleared</span>
                        <?php else: ?>
                          <span class="badge bg-danger py-2 px-3"><i class="fas fa-times-circle me-1"></i>Ineligible</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="col-12">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white py-3">
          <h5 class="card-title mb-0 fw-semibold"><i class="fas fa-graduation-cap me-2 text-secondary"></i>Other Clearance Checklists</h5>
        </div>
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2">
            <a href="../admittedStud_report.php" class="btn btn-outline-primary py-2 px-3"><i class="fas fa-user-check me-2"></i>Check Academic Status</a>
            <a href="../../accounts/fees_student_accounts.php" class="btn btn-outline-secondary py-2 px-3"><i class="fas fa-wallet me-2"></i>Verify Financial Accounts</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

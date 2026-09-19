<?php
/**
 * Student Portal - Digital Campus Services & Clearance Dashboard
 */

require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';
require_once __DIR__ . '/includes/student_fee_records.php';
require_once dirname(__DIR__) . '/includes/academic_risk_engine.php';

$student_id = (string)($_SESSION['Sid'] ?? '');
if ($student_id === '') {
    header('Location: /wucportal/studentLogin.php');
    exit;
}

// Fetch student profile details
$studentRec = null;
$query_student = "SELECT s.SID, s.Fname, s.Lname, s.email, sp.program_code, p.program_name 
                  FROM students s
                  LEFT JOIN student_program sp ON s.SID = sp.Sid
                  LEFT JOIN programs p ON sp.program_code = p.program_code
                  WHERE s.SID = ? LIMIT 1";
$stmt = $db->prepare($query_student);
if ($stmt) {
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $studentRec = $stmt->get_result()->fetch_object();
    $stmt->close();
}

if (!$studentRec) {
    die("Student record not found.");
}

// Fallback program name for short course students without student_program row
if (empty($studentRec->program_name)) {
    if (function_exists('sc_student_enrolments')) {
        $scs = sc_student_enrolments($db, $student_id);
        if (!empty($scs)) {
            $studentRec->program_code = $scs[0]['course_code'] ?? 'SHORT_COURSE';
            $studentRec->program_name = $scs[0]['course_name'] ?? 'Short Course Programme';
        }
    }
    if (empty($studentRec->program_name)) {
        $studentRec->program_name = 'General Programme';
    }
}

// Handle service requests POST
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request') {
    $service_type = $_POST['service_type'] ?? 'general';
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (empty($subject) || empty($message)) {
        $error_msg = 'Please fill out all required fields.';
    } else {
        $stmt_ins = $db->prepare("INSERT INTO student_campus_requests (student_id, service_type, subject, message, status) VALUES (?, ?, ?, ?, 'Pending')");
        if ($stmt_ins) {
            $stmt_ins->bind_param("ssss", $student_id, $service_type, $subject, $message);
            if ($stmt_ins->execute()) {
                $success_msg = 'Your campus service request has been submitted successfully.';
            } else {
                $error_msg = 'Failed to submit request: ' . $db->error;
            }
            $stmt_ins->close();
        }
    }
}

// Handle Graduation Application POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_graduation') {
    // Verify clearance holds first
    $finance_ok = false;
    $library_ok = false;
    $academic_ok = false;
    $admin_ok = false;

    // 1. Finance Check
    $outstanding = 0.0;
    $newAccStmt = $db->prepare("SELECT balance FROM student_fee_accounts WHERE student_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    if ($newAccStmt) {
        $newAccStmt->bind_param('s', $student_id);
        $newAccStmt->execute();
        $res = $newAccStmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $outstanding = (float)$row['balance'];
        }
        $newAccStmt->close();
    }
    $finance_ok = ($outstanding <= 0.0);

    // 2. Library Check
    $library_loans = 0;
    $libStmt = $db->prepare("SELECT COUNT(*) as count FROM library_loans WHERE borrower_id = ? AND borrower_type = 'student' AND returned_at IS NULL");
    if ($libStmt) {
        $libStmt->bind_param('s', $student_id);
        $libStmt->execute();
        $libRes = $libStmt->get_result()->fetch_assoc();
        $library_loans = (int)($libRes['count'] ?? 0);
        $libStmt->close();
    }
    $library_ok = ($library_loans === 0);

    // 3. Academic Check
    $examsRes = $db->query("SELECT e.Course_Code, e.Exam_marks, COALESCE(sa.Total_CA, 0) AS Total_CA
                            FROM exams e
                            LEFT JOIN semester_assessment sa ON sa.Sid = e.Sid AND sa.Course_Code = e.Course_Code AND sa.Year = e.Year AND sa.semester = e.semester
                            WHERE e.Sid = '" . $db->real_escape_string($student_id) . "' AND e.status = 'Published' AND e.Exam_marks IS NOT NULL");
    $totalPoints = 0;
    $totalCredits = 0;
    $failsCount = 0;
    while ($examsRes && $exam = $examsRes->fetch_assoc()) {
        $ca = (float)$exam['Total_CA'];
        $examMark = (float)$exam['Exam_marks'];
        $computed = wuc_result_compute($db, $student_id, $ca, $examMark);
        $points = $computed['points'];
        $grade = $computed['grade'];
        $totalPoints += $points * 3; // Assume standard 3 credits
        $totalCredits += 3;
        if ($grade === 'F') $failsCount++;
    }
    $gpa = $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : 0.0;
    $academic_ok = ($gpa >= 2.0 && $failsCount === 0 && $totalCredits >= 12);

    // 4. Admin Clearance check
    $clrRow = null;
    $clrStmt = $db->prepare("SELECT admin_cleared FROM student_clearance WHERE student_id = ? LIMIT 1");
    if ($clrStmt) {
        $clrStmt->bind_param('s', $student_id);
        $clrStmt->execute();
        $clrRow = $clrStmt->get_result()->fetch_assoc();
        $clrStmt->close();
    }
    $admin_ok = (!empty($clrRow['admin_cleared']));

    if ($finance_ok && $library_ok && $academic_ok && $admin_ok) {
        // Insert/update clearance status for graduation
        $year = (int)date('Y');
        $upd = $db->prepare("INSERT INTO student_clearance (student_id, finance_cleared, library_cleared, academic_cleared, admin_cleared, graduation_status, graduation_year) 
                             VALUES (?, 1, 1, 1, 1, 'Applied', ?) 
                             ON DUPLICATE KEY UPDATE graduation_status = 'Applied', graduation_year = ?");
        if ($upd) {
            $upd->bind_param('sii', $student_id, $year, $year);
            if ($upd->execute()) {
                $success_msg = 'Your Graduation Application has been submitted successfully!';
            } else {
                $error_msg = 'Failed to submit graduation request.';
            }
            $upd->close();
        }
    } else {
        $error_msg = 'You cannot apply for graduation until all clearance requirements are met.';
    }
}

// Fetch requests history
$requests = [];
$req_res = $db->query("SELECT * FROM student_campus_requests WHERE student_id = '" . $db->real_escape_string($student_id) . "' ORDER BY created_at DESC");
while ($req_res && $row = $req_res->fetch_assoc()) {
    $requests[] = $row;
}

// Fetch Clearance Record
$clearance = null;
$clr_res = $db->query("SELECT * FROM student_clearance WHERE student_id = '" . $db->real_escape_string($student_id) . "' LIMIT 1");
if ($clr_res && $clr_res->num_rows > 0) {
    $clearance = $clr_res->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Digital Campus Services &amp; Clearance - ITC</title>
  <link rel="stylesheet" href="/wucportal/css/admin-style.css">
  <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
  <link rel="stylesheet" href="../css/consistent-styles.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<div class="content-wrapper portal-dashboard pt-3">
  <div class="container-fluid">
    
    <!-- Title Area -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
      <div>
        <h5 class="page-title mb-0 text-purple fw-bold"><i class="fas fa-university me-2"></i>Campus Services & Clearance Workspace</h5>
        <p class="page-subtitle mb-0 text-muted">Submit service requests, monitor clearances, and manage your graduation lifecycle.</p>
      </div>
      <div>
        <a href="index.php" class="btn btn-light border btn-sm rounded-pill px-3">
          <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
        </a>
      </div>
    </div>

    <!-- Notifications -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Tabs Navigation -->
    <ul class="nav nav-pills mb-4" id="servicesTab" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="requests-tab" data-bs-toggle="tab" data-bs-target="#requests-pane" type="button" role="tab"><i class="fas fa-concierge-bell me-2"></i>Support Requests</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="clearance-tab" data-bs-toggle="tab" data-bs-target="#clearance-pane" type="button" role="tab"><i class="fas fa-user-graduate me-2"></i>Clearance & Graduation</button>
      </li>
    </ul>

    <!-- Tabs Content -->
    <div class="tab-content" id="servicesTabContent">
      
      <!-- TAB 1: Support Requests -->
      <div class="tab-pane fade show active" id="requests-pane" role="tabpanel">
        <div class="row g-4">
          <!-- Request Form -->
          <div class="col-lg-5">
            <div class="card shadow-sm border-0">
              <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 text-purple font-weight-bold"><i class="fas fa-plus-circle me-2"></i>Submit New Request</h5>
              </div>
              <div class="card-body">
                <form action="" method="post">
                  <input type="hidden" name="action" value="request">
                  
                  <div class="mb-3">
                    <label class="form-label font-weight-bold">Select Campus Service</label>
                    <select name="service_type" class="form-select" required>
                      <option value="counselling">Counselling & Psychological Support</option>
                      <option value="medical">Medical & Clinic Appointment</option>
                      <option value="hostel">Hostel & Accommodation Request</option>
                      <option value="transport">Transport (Njila Route Support)</option>
                      <option value="general">General Student Request</option>
                    </select>
                  </div>

                  <div class="mb-3">
                    <label class="form-label font-weight-bold">Subject</label>
                    <input type="text" name="subject" class="form-control" placeholder="e.g. Schedule Counselling Session" required>
                  </div>

                  <div class="mb-3">
                    <label class="form-label font-weight-bold">Detailed Message / Reason</label>
                    <textarea name="message" class="form-control" rows="4" placeholder="Describe your request..." required></textarea>
                  </div>

                  <button type="submit" class="btn btn-purple w-100"><i class="fas fa-paper-plane me-2"></i>Submit Request</button>
                </form>
              </div>
            </div>
          </div>

          <!-- Requests History -->
          <div class="col-lg-7">
            <div class="card shadow-sm border-0">
              <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 text-primary font-weight-bold"><i class="fas fa-history me-2"></i>Request History</h5>
              </div>
              <div class="card-body p-0">
                <?php if (empty($requests)): ?>
                  <div class="p-4 text-center text-muted">
                    <i class="fas fa-inbox fa-3x mb-3 text-muted"></i>
                    <p class="mb-0">No requests submitted yet.</p>
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>Service</th>
                          <th>Subject</th>
                          <th>Submitted</th>
                          <th>Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($requests as $r): ?>
                          <tr>
                            <td><span class="badge bg-purple"><?= htmlspecialchars(ucfirst($r['service_type'])) ?></span></td>
                            <td>
                              <strong><?= htmlspecialchars($r['subject']) ?></strong>
                              <div class="text-muted small text-truncate" style="max-width: 250px;"><?= htmlspecialchars($r['message']) ?></div>
                            </td>
                            <td><?= date('M d, Y H:i', strtotime($r['created_at'])) ?></td>
                            <td>
                              <?php
                                $status = $r['status'];
                                $badge = 'bg-secondary';
                                if ($status === 'Pending') $badge = 'bg-warning text-dark';
                                elseif ($status === 'In Progress') $badge = 'bg-info text-white';
                                elseif ($status === 'Scheduled' || $status === 'Resolved') $badge = 'bg-success';
                                elseif ($status === 'Rejected') $badge = 'bg-danger';
                              ?>
                              <span class="badge <?= $badge ?>"><?= $status ?></span>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 2: Clearance & Graduation -->
      <div class="tab-pane fade" id="clearance-pane" role="tabpanel">
        <div class="row g-4">
          
          <!-- Clearance Checklist Panel -->
          <div class="col-lg-6">
            <div class="card shadow-sm border-0">
              <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 text-purple font-weight-bold"><i class="fas fa-clipboard-check me-2"></i>Multi-Point Clearance Checklist</h5>
              </div>
              <div class="card-body">
                
                <?php
                // Real-time queries
                // 1. Finance Check
                $outstanding = 0.0;
                $newAccStmt = $db->prepare("SELECT balance FROM student_fee_accounts WHERE student_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
                if ($newAccStmt) {
                    $newAccStmt->bind_param('s', $student_id);
                    $newAccStmt->execute();
                    $res = $newAccStmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $outstanding = (float)$row['balance'];
                    }
                    $newAccStmt->close();
                }
                $finance_ok = ($outstanding <= 0.0);

                // 2. Library Check
                $library_loans = 0;
                $libStmt = $db->prepare("SELECT COUNT(*) as count FROM library_loans WHERE borrower_id = ? AND borrower_type = 'student' AND returned_at IS NULL");
                if ($libStmt) {
                    $libStmt->bind_param('s', $student_id);
                    $libStmt->execute();
                    $libRes = $libStmt->get_result()->fetch_assoc();
                    $library_loans = (int)($libRes['count'] ?? 0);
                    $libStmt->close();
                }
                $library_ok = ($library_loans === 0);

                // 3. Academic Check
                $examsRes = $db->query("SELECT e.Course_Code, e.Exam_marks, COALESCE(sa.Total_CA, 0) AS Total_CA
                                        FROM exams e
                                        LEFT JOIN semester_assessment sa ON sa.Sid = e.Sid AND sa.Course_Code = e.Course_Code AND sa.Year = e.Year AND sa.semester = e.semester
                                        WHERE e.Sid = '" . $db->real_escape_string($student_id) . "' AND e.status = 'Published' AND e.Exam_marks IS NOT NULL");
                $totalPoints = 0;
                $totalCredits = 0;
                $failsCount = 0;
                while ($examsRes && $exam = $examsRes->fetch_assoc()) {
                    $ca = (float)$exam['Total_CA'];
                    $examMark = (float)$exam['Exam_marks'];
                    $computed = wuc_result_compute($db, $student_id, $ca, $examMark);
                    $points = $computed['points'];
                    $grade = $computed['grade'];
                    $totalPoints += $points * 3;
                    $totalCredits += 3;
                    if ($grade === 'F') $failsCount++;
                }
                $gpa = $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : 0.0;
                $academic_ok = ($gpa >= 2.0 && $failsCount === 0 && $totalCredits >= 12);

                // 4. Admin Clearance check
                $admin_ok = (!empty($clearance['admin_cleared']));
                ?>

                <!-- Finance Node -->
                <div class="d-flex justify-content-between align-items-center border-bottom py-3">
                  <div>
                    <h6 class="mb-1 fw-bold">1. Financial Clearance</h6>
                    <p class="small text-muted mb-0">No outstanding fees or tuition balances.</p>
                  </div>
                  <div>
                    <?php if ($finance_ok): ?>
                      <span class="badge bg-success py-2 px-3"><i class="fas fa-check me-1"></i>Cleared</span>
                    <?php else: ?>
                      <span class="badge bg-danger py-2 px-3" title="Balance due: ZMW <?= number_format($outstanding, 2) ?>"><i class="fas fa-times me-1"></i>Pending Payments</span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Library Node -->
                <div class="d-flex justify-content-between align-items-center border-bottom py-3">
                  <div>
                    <h6 class="mb-1 fw-bold">2. Library Clearance</h6>
                    <p class="small text-muted mb-0">No unreturned catalog book checkouts or unpaid library fines.</p>
                  </div>
                  <div>
                    <?php if ($library_ok): ?>
                      <span class="badge bg-success py-2 px-3"><i class="fas fa-check me-1"></i>Cleared</span>
                    <?php else: ?>
                      <span class="badge bg-danger py-2 px-3" title="<?= $library_loans ?> loan(s) pending"><i class="fas fa-times me-1"></i><?= $library_loans ?> Books Due</span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Academic Node -->
                <div class="d-flex justify-content-between align-items-center border-bottom py-3">
                  <div>
                    <h6 class="mb-1 fw-bold">3. Academic Clearance</h6>
                    <p class="small text-muted mb-0">Minimum cumulative GPA of 2.0 with zero failed courses.</p>
                  </div>
                  <div>
                    <?php if ($academic_ok): ?>
                      <span class="badge bg-success py-2 px-3"><i class="fas fa-check me-1"></i>Cleared</span>
                    <?php else: ?>
                      <span class="badge bg-danger py-2 px-3" title="GPA: <?= $gpa ?>, Fails: <?= $failsCount ?>"><i class="fas fa-times me-1"></i>Ineligible (GPA: <?= $gpa ?>)</span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Admin Node -->
                <div class="d-flex justify-content-between align-items-center py-3">
                  <div>
                    <h6 class="mb-1 fw-bold">4. Administrative Clearance</h6>
                    <p class="small text-muted mb-0">Disciplinary reviews and administrative sign-offs cleared.</p>
                  </div>
                  <div>
                    <?php if ($admin_ok): ?>
                      <span class="badge bg-success py-2 px-3"><i class="fas fa-check me-1"></i>Cleared</span>
                    <?php else: ?>
                      <span class="badge bg-warning text-dark py-2 px-3"><i class="fas fa-spinner me-1"></i>Awaiting Registrar Review</span>
                    <?php endif; ?>
                  </div>
                </div>

              </div>
            </div>
          </div>

          <!-- Graduation Application Panel -->
          <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 text-primary font-weight-bold"><i class="fas fa-graduation-cap me-2"></i>Graduation Status</h5>
              </div>
              <div class="card-body d-flex flex-column justify-content-center text-center p-4">
                
                <?php
                  $eligible = ($finance_ok && $library_ok && $academic_ok && $admin_ok);
                  $status = $clearance['graduation_status'] ?? 'Not Eligible';
                ?>

                <div class="mb-4">
                  <i class="fas fa-award fa-4x text-purple mb-3"></i>
                  <h4 class="fw-bold">Academic Program Completion</h4>
                  <p class="text-muted">Program: <strong><?= htmlspecialchars($studentRec->program_name) ?></strong></p>
                </div>

                <div class="alert alert-secondary py-3 mb-4">
                  <span class="h6 mb-0 font-weight-bold">Current Lifecycle Status:</span>
                  <span class="badge bg-purple ms-2 fs-6 px-3 py-2"><?= htmlspecialchars($status) ?></span>
                </div>

                <?php if ($status === 'Graduated'): ?>
                  <div class="alert alert-success">
                    <i class="fas fa-check-circle me-1"></i>Congratulations! You have graduated in the class of <?= htmlspecialchars((string)($clearance['graduation_year'] ?? '')) ?>.
                  </div>
                <?php elseif ($status === 'Approved'): ?>
                  <div class="alert alert-success">
                    <i class="fas fa-check-circle me-1"></i>Your graduation application has been approved by the Senate! Please check graduation venue listings.
                  </div>
                <?php elseif ($status === 'Applied'): ?>
                  <div class="alert alert-info">
                    <i class="fas fa-spinner fa-spin me-1"></i>Your application is currently being audited by the Academic Registrar.
                  </div>
                <?php elseif ($eligible): ?>
                  <form action="" method="post">
                    <input type="hidden" name="action" value="apply_graduation">
                    <p class="text-success small mb-3"><i class="fas fa-shield-halved"></i> Excellent! You satisfy all finance, library, academic, and administrative clearance nodes.</p>
                    <button type="submit" class="btn btn-purple w-100 py-3"><i class="fas fa-paper-plane me-2"></i>Apply for Graduation</button>
                  </form>
                <?php else: ?>
                  <div class="alert alert-danger py-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>Graduation applications will unlock once all four clearance nodes are fully cleared.
                  </div>
                <?php endif; ?>

              </div>
            </div>
          </div>

        </div>
      </div>

    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
/**
 * Decision Support & Analytics Dashboard
 */

require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
wuc_require_any_permission(
    ['reports.view', 'reports.manage'],
    'You do not have permission to view analytics reports.',
    '/wucportal/portal_selection.php'
);
require_once __DIR__ . '/includes/header.php';

// Retrieve unique filter values
$programs = [];
if ($res = $db->query("SELECT DISTINCT program_code, program_name FROM programs WHERE is_active = 1")) {
    while ($row = $res->fetch_assoc()) {
        $programs[] = $row;
    }
}
$intakes = [];
if ($res = $db->query("SELECT DISTINCT intake FROM students WHERE intake IS NOT NULL AND intake <> ''")) {
    while ($row = $res->fetch_assoc()) {
        $intakes[] = $row['intake'];
    }
}
$academic_years = [];
if ($res = $db->query("SELECT DISTINCT academic_year FROM students WHERE academic_year IS NOT NULL AND academic_year <> ''")) {
    while ($row = $res->fetch_assoc()) {
        $academic_years[] = $row['academic_year'];
    }
}

// Read filters
$filter_prog = $_GET['program'] ?? '';
$filter_intake = $_GET['intake'] ?? '';
$filter_ay = $_GET['academic_year'] ?? '';
$filter_gender = $_GET['gender'] ?? '';

// Build query conditions
$where_conds = [];
$params = [];
$types = '';

if ($filter_prog !== '') {
    $where_conds[] = "s.program = ?";
    $params[] = $filter_prog;
    $types .= 's';
}
if ($filter_intake !== '') {
    $where_conds[] = "s.intake = ?";
    $params[] = $filter_intake;
    $types .= 's';
}
if ($filter_ay !== '') {
    $where_conds[] = "s.academic_year = ?";
    $params[] = $filter_ay;
    $types .= 's';
}
if ($filter_gender !== '') {
    $where_conds[] = "s.sex = ?";
    $params[] = $filter_gender;
    $types .= 's';
}

$where_clause = '';
if (!empty($where_conds)) {
    $where_clause = " WHERE " . implode(" AND ", $where_conds);
}

// 1. KPI: Total Registered Students
$totalStudents = 0;
$stmt_stud = $db->prepare("SELECT COUNT(DISTINCT s.SID) as total FROM students s" . $where_clause);
if ($stmt_stud) {
    if (!empty($params)) {
        $stmt_stud->bind_param($types, ...$params);
    }
    $stmt_stud->execute();
    $totalStudents = (int)($stmt_stud->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt_stud->close();
}

// 2. KPI: Fee Collection Status
$totalPayable = 0.0;
$totalPaid = 0.0;
$feeQuery = "SELECT SUM(f.total_payable) as payable, SUM(f.amount_paid) as paid 
             FROM student_fee_accounts f 
             INNER JOIN students s ON f.student_id = s.SID" . $where_clause;
$stmt_fee = $db->prepare($feeQuery);
if ($stmt_fee) {
    if (!empty($params)) {
        $stmt_fee->bind_param($types, ...$params);
    }
    $stmt_fee->execute();
    $feeRes = $stmt_fee->get_result()->fetch_assoc();
    $totalPayable = (float)($feeRes['payable'] ?? 0.0);
    $totalPaid = (float)($feeRes['paid'] ?? 0.0);
    $stmt_fee->close();
}
$collectionRate = $totalPayable > 0 ? round(($totalPaid / $totalPayable) * 100, 1) : 100.0;

// 3. KPI: High Risk Students
$highRiskCount = 0;
$riskQuery = "SELECT COUNT(DISTINCT r.student_id) as total
              FROM student_risk_summary r
              INNER JOIN students s ON r.student_id = s.SID" . $where_clause .
              (empty($where_clause) ? " WHERE " : " AND ") . "r.risk_level = 'High'";
$stmt_risk = $db->prepare($riskQuery);
if ($stmt_risk) {
    if (!empty($params)) {
        $stmt_risk->bind_param($types, ...$params);
    }
    $stmt_risk->execute();
    $highRiskCount = (int)($stmt_risk->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt_risk->close();
}

// 4. KPI: Graduates Approved
$gradCount = 0;
$gradQuery = "SELECT COUNT(*) as total
              FROM student_clearance c
              INNER JOIN students s ON c.student_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci" . $where_clause .
              (empty($where_clause) ? " WHERE " : " AND ") . "c.graduation_status IN ('Approved', 'Graduated')";
$stmt_grad = $db->prepare($gradQuery);
if ($stmt_grad) {
    if (!empty($params)) {
        $stmt_grad->bind_param($types, ...$params);
    }
    $stmt_grad->execute();
    $gradCount = (int)($stmt_grad->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt_grad->close();
}

// 5. Query: Admissions Pipeline breakdown
$admissionsPipeline = [];
$pipeQuery = "SELECT status, COUNT(*) as count 
              FROM students s" . $where_clause . " 
              GROUP BY status";
$stmt_pipe = $db->prepare($pipeQuery);
if ($stmt_pipe) {
    if (!empty($params)) {
        $stmt_pipe->bind_param($types, ...$params);
    }
    $stmt_pipe->execute();
    $res = $stmt_pipe->get_result();
    while ($row = $res->fetch_assoc()) {
        $admissionsPipeline[] = $row;
    }
    $stmt_pipe->close();
}

// 6. Query: Lecturer Workload list
$workload = [];
$workloadQuery = "SELECT st.staff_id, st.Fname, st.Lname, COUNT(cl.course_code) as courses_count 
                  FROM staff st
                  INNER JOIN course_lecturer cl ON cl.staff_id = st.staff_id
                  WHERE cl.status = 'active' AND st.role = 'Lecturer'
                  GROUP BY st.staff_id, st.Fname, st.Lname
                  ORDER BY courses_count DESC";
$workloadRes = $db->query($workloadQuery);
while ($workloadRes && $row = $workloadRes->fetch_assoc()) {
    $workload[] = $row;
}
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="dashboard-title"><i class="fas fa-chart-bar me-2 text-primary"></i>Decision Support & Analytics</h1>
      <p class="text-muted mb-0">Cross-department metrics on admissions, student performance, workloads, and fee collections.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="ajax/analytics_exports.php?export=workload" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-csv me-1"></i>Export Workloads</a>
      <a href="ajax/analytics_exports.php?export=risks" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-csv me-1"></i>Export Risks</a>
      <a href="ajax/analytics_exports.php?export=fees" class="btn btn-sm btn-outline-success"><i class="fas fa-file-csv me-1"></i>Export Fees Report</a>
    </div>
  </div>

  <!-- Filters Toolbar -->
  <div class="card shadow-sm border-0 mb-4 bg-light">
    <div class="card-body py-2">
      <form action="" method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label small fw-bold text-muted mb-1">Academic Program</label>
          <select name="program" class="form-select form-select-sm">
            <option value="">All Programs</option>
            <?php foreach ($programs as $p): ?>
              <option value="<?= htmlspecialchars($p['program_code']) ?>" <?= $filter_prog === $p['program_code'] ? 'selected' : '' ?>><?= htmlspecialchars($p['program_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-bold text-muted mb-1">Intake</label>
          <select name="intake" class="form-select form-select-sm">
            <option value="">All Intakes</option>
            <?php foreach ($intakes as $i): ?>
              <option value="<?= htmlspecialchars($i) ?>" <?= $filter_intake === $i ? 'selected' : '' ?>><?= htmlspecialchars($i) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-bold text-muted mb-1">Academic Year</label>
          <select name="academic_year" class="form-select form-select-sm">
            <option value="">All Years</option>
            <?php foreach ($academic_years as $ay): ?>
              <option value="<?= htmlspecialchars($ay) ?>" <?= $filter_ay === $ay ? 'selected' : '' ?>><?= htmlspecialchars($ay) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-bold text-muted mb-1">Gender</label>
          <select name="gender" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="M" <?= $filter_gender === 'M' ? 'selected' : '' ?>>Male</option>
            <option value="F" <?= $filter_gender === 'F' ? 'selected' : '' ?>>Female</option>
          </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button type="submit" class="btn btn-sm btn-purple w-100"><i class="fas fa-filter me-1"></i>Apply Filters</button>
          <a href="analytics_dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-undo"></i></a>
        </div>
      </form>
    </div>
  </div>

  <!-- KPI Grid -->
  <div class="row g-3 mb-4">
    
    <!-- Total Registered Students -->
    <div class="col-md-6 col-lg-3">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="rounded-pill p-3 bg-purple text-white"><i class="fas fa-user-graduate fa-lg"></i></div>
          <div>
            <h6 class="text-muted mb-1 small fw-bold">Active Students</h6>
            <h4 class="mb-0 fw-bold"><?= number_format($totalStudents) ?></h4>
          </div>
        </div>
      </div>
    </div>

    <!-- Fee Collection Rate -->
    <div class="col-md-6 col-lg-3">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="rounded-pill p-3 bg-success text-white"><i class="fas fa-wallet fa-lg"></i></div>
          <div>
            <h6 class="text-muted mb-1 small fw-bold">Fee Collection</h6>
            <h4 class="mb-0 fw-bold"><?= $collectionRate ?>%</h4>
            <small class="text-success"><?= number_format($totalPaid) ?> / <?= number_format($totalPayable) ?></small>
          </div>
        </div>
      </div>
    </div>

    <!-- High Risk Learners -->
    <div class="col-md-6 col-lg-3">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="rounded-pill p-3 bg-danger text-white"><i class="fas fa-brain fa-lg"></i></div>
          <div>
            <h6 class="text-muted mb-1 small fw-bold">High Risk Learners</h6>
            <h4 class="mb-0 fw-bold text-danger"><?= number_format($highRiskCount) ?></h4>
          </div>
        </div>
      </div>
    </div>

    <!-- Graduates Audit -->
    <div class="col-md-6 col-lg-3">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="rounded-pill p-3 bg-info text-white"><i class="fas fa-award fa-lg"></i></div>
          <div>
            <h6 class="text-muted mb-1 small fw-bold">Clearance Approved</h6>
            <h4 class="mb-0 fw-bold text-info"><?= number_format($gradCount) ?></h4>
          </div>
        </div>
      </div>
    </div>

  </div>

  <div class="row g-4">
    
    <!-- Left Column: Admissions & Pipeline Breakdown -->
    <div class="col-lg-6">
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-bottom py-3">
          <h5 class="card-title mb-0 fw-bold text-purple"><i class="fas fa-funnel-dollar me-2"></i>Admissions Pipeline</h5>
        </div>
        <div class="card-body p-0">
          <?php if (empty($admissionsPipeline)): ?>
            <div class="p-4 text-center text-muted">
              <p class="mb-0">No pipeline records logged for this filter query.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Lifecycle Stage</th>
                    <th class="text-center">Count</th>
                    <th class="text-center">Share</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($admissionsPipeline as $pipe): ?>
                    <tr>
                      <td class="fw-bold"><?= htmlspecialchars($pipe['status']) ?></td>
                      <td class="text-center"><?= $pipe['count'] ?></td>
                      <td class="text-center">
                        <div class="progress" style="height: 8px;">
                          <div class="progress-bar bg-purple" role="progressbar" style="width: <?= ($totalStudents > 0 ? ($pipe['count'] / $totalStudents) * 100 : 0) ?>%"></div>
                        </div>
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

    <!-- Right Column: Lecturer Workload roster -->
    <div class="col-lg-6">
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-bottom py-3">
          <h5 class="card-title mb-0 fw-bold text-primary"><i class="fas fa-chalkboard-teacher me-2"></i>Lecturer Workload</h5>
        </div>
        <div class="card-body p-0">
          <?php if (empty($workload)): ?>
            <div class="p-4 text-center text-muted">
              <p class="mb-0">No active lecturers logged workload in database.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Staff ID</th>
                    <th>Full Name</th>
                    <th class="text-center">Active Courses</th>
                    <th class="text-center">Workload Rating</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($workload as $l): ?>
                    <tr>
                      <td><code><?= htmlspecialchars($l['staff_id']) ?></code></td>
                      <td><strong><?= htmlspecialchars($l['Fname'] . ' ' . $l['Lname']) ?></strong></td>
                      <td class="text-center fw-bold"><?= $l['courses_count'] ?></td>
                      <td class="text-center">
                        <?php if ($l['courses_count'] > 4): ?>
                          <span class="badge bg-danger">High Load</span>
                        <?php elseif ($l['courses_count'] >= 2): ?>
                          <span class="badge bg-success">Optimal Load</span>
                        <?php else: ?>
                          <span class="badge bg-info">Underutilized</span>
                        <?php endif; ?>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>

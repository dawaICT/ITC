<?php
/**
 * Decision Support & Analytics Dashboard
 */

require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/predictive_analytics.php';
wuc_require_any_permission(
    ['reports.view', 'reports.manage'],
    'You do not have permission to view analytics reports.',
    '/wucportal/portal_selection.php'
);

// Retrieve unique filter values
$programs = [];
try {
    if (wuc_table_exists($db, 'programs') && ($res = $db->query("SELECT DISTINCT program_code, program_name FROM programs WHERE is_active = 1 ORDER BY program_name"))) {
        while ($row = $res->fetch_assoc()) {
            $programs[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
    error_log('Analytics program filters failed: ' . $e->getMessage());
}
$intakes = [];
try {
    if (wuc_table_exists($db, 'students') && ($res = $db->query("SELECT DISTINCT intake FROM students WHERE intake IS NOT NULL AND intake <> '' ORDER BY intake DESC"))) {
        while ($row = $res->fetch_assoc()) {
            $intakes[] = $row['intake'];
        }
        $res->free();
    }
} catch (Throwable $e) {
    error_log('Analytics intake filters failed: ' . $e->getMessage());
}
$academic_years = [];
try {
    if (wuc_table_exists($db, 'students') && ($res = $db->query("SELECT DISTINCT academic_year FROM students WHERE academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC"))) {
        while ($row = $res->fetch_assoc()) {
            $academic_years[] = $row['academic_year'];
        }
        $res->free();
    }
} catch (Throwable $e) {
    error_log('Analytics academic year filters failed: ' . $e->getMessage());
}

// Read filters
$filter_prog = substr(trim((string)($_GET['program'] ?? '')), 0, 20);
$filter_intake = substr(trim((string)($_GET['intake'] ?? '')), 0, 50);
$filter_ay = substr(trim((string)($_GET['academic_year'] ?? '')), 0, 10);
$filter_gender = in_array((string)($_GET['gender'] ?? ''), ['M', 'F'], true) ? (string)$_GET['gender'] : '';
$forecastHorizon = (int)($_GET['horizon'] ?? 3);
if (!in_array($forecastHorizon, [3, 6, 12], true)) {
    $forecastHorizon = 3;
}

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
try {
    if (wuc_table_exists($db, 'students') && ($stmt_stud = $db->prepare("SELECT COUNT(DISTINCT s.SID) as total FROM students s" . $where_clause))) {
        wuc_pa_bind_execute($stmt_stud, $types, $params);
        $totalStudents = (int)($stmt_stud->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt_stud->close();
    }
} catch (Throwable $e) {
    error_log('Analytics student KPI failed: ' . $e->getMessage());
}

// 2. KPI: Fee Collection Status
$totalPayable = 0.0;
$totalPaid = 0.0;
$feeQuery = "SELECT SUM(f.total_payable) as payable, SUM(f.amount_paid) as paid 
             FROM student_fee_accounts f 
             INNER JOIN students s ON f.student_id = s.SID" . $where_clause;
try {
    if (wuc_table_exists($db, 'student_fee_accounts') && ($stmt_fee = $db->prepare($feeQuery))) {
        wuc_pa_bind_execute($stmt_fee, $types, $params);
        $feeRes = $stmt_fee->get_result()->fetch_assoc();
        $totalPayable = (float)($feeRes['payable'] ?? 0.0);
        $totalPaid = (float)($feeRes['paid'] ?? 0.0);
        $stmt_fee->close();
    }
} catch (Throwable $e) {
    error_log('Analytics fee KPI failed: ' . $e->getMessage());
}
$collectionRate = $totalPayable > 0 ? round(($totalPaid / $totalPayable) * 100, 1) : 0.0;

// 3. KPI: High Risk Students
$highRiskCount = 0;
$riskQuery = "SELECT COUNT(DISTINCT r.student_id) as total
              FROM student_risk_summary r
              INNER JOIN students s ON r.student_id = s.SID" . $where_clause .
              (empty($where_clause) ? " WHERE " : " AND ") . "r.risk_level = 'High'";
try {
    if (wuc_table_exists($db, 'student_risk_summary') && ($stmt_risk = $db->prepare($riskQuery))) {
        wuc_pa_bind_execute($stmt_risk, $types, $params);
        $highRiskCount = (int)($stmt_risk->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt_risk->close();
    }
} catch (Throwable $e) {
    error_log('Analytics risk KPI failed: ' . $e->getMessage());
}

// 4. KPI: Graduates Approved
$gradCount = 0;
$gradQuery = "SELECT COUNT(*) as total
              FROM student_clearance c
              INNER JOIN students s ON c.student_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci" . $where_clause .
              (empty($where_clause) ? " WHERE " : " AND ") . "c.graduation_status IN ('Approved', 'Graduated')";
try {
    if (wuc_table_exists($db, 'student_clearance') && ($stmt_grad = $db->prepare($gradQuery))) {
        wuc_pa_bind_execute($stmt_grad, $types, $params);
        $gradCount = (int)($stmt_grad->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt_grad->close();
    }
} catch (Throwable $e) {
    error_log('Analytics graduate KPI failed: ' . $e->getMessage());
}

// 5. Query: Admissions Pipeline breakdown
$admissionsPipeline = [];
$pipeQuery = "SELECT status, COUNT(*) as count 
              FROM students s" . $where_clause . " 
              GROUP BY status";
try {
    if (wuc_table_exists($db, 'students') && ($stmt_pipe = $db->prepare($pipeQuery))) {
        wuc_pa_bind_execute($stmt_pipe, $types, $params);
        $res = $stmt_pipe->get_result();
        while ($row = $res->fetch_assoc()) {
            $admissionsPipeline[] = $row;
        }
        $stmt_pipe->close();
    }
} catch (Throwable $e) {
    error_log('Analytics pipeline query failed: ' . $e->getMessage());
}

// 6. Query: Lecturer Workload list
$workload = [];
$workloadQuery = "SELECT st.staff_id, st.Fname, st.Lname, COUNT(cl.course_code) as courses_count 
                  FROM staff st
                  INNER JOIN course_lecturer cl ON cl.staff_id = st.staff_id
                  WHERE cl.status = 'active' AND st.role = 'Lecturer'
                  GROUP BY st.staff_id, st.Fname, st.Lname
                  ORDER BY courses_count DESC";
try {
    if (wuc_table_exists($db, 'course_lecturer') && wuc_table_exists($db, 'staff') && ($workloadRes = $db->query($workloadQuery))) {
        while ($row = $workloadRes->fetch_assoc()) {
            $workload[] = $row;
        }
        $workloadRes->free();
    }
} catch (Throwable $e) {
    error_log('Analytics workload query failed: ' . $e->getMessage());
}

$forecastFilters = [
    'program' => $filter_prog,
    'intake' => $filter_intake,
    'academic_year' => $filter_ay,
    'gender' => $filter_gender,
];
$predictive = wuc_predictive_analytics_build($db, $forecastFilters, $forecastHorizon, 12);
$confidenceClass = static function (int $score): string {
    return $score < 30 ? 'text-bg-danger' : ($score < 55 ? 'text-bg-warning' : ($score < 75 ? 'text-bg-info' : 'text-bg-success'));
};
$forecastExportQuery = http_build_query(array_filter(array_merge(
    $forecastFilters,
    ['horizon' => $forecastHorizon]
), static fn($value): bool => $value !== ''));

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="css/admin-dashboard.css">
<link rel="stylesheet" href="css/analytics-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="dashboard-title"><i class="fas fa-chart-line me-2 text-primary"></i>Predictive Analysis & Forecasting</h1>
      <p class="text-muted mb-0">Operational trends, forward estimates, academic risk, workloads, and fee collection insights.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="ajax/analytics_exports.php?export=workload" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-csv me-1"></i>Export Workloads</a>
      <a href="ajax/analytics_exports.php?export=risks" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-csv me-1"></i>Export Risks</a>
      <a href="ajax/analytics_exports.php?export=fees" class="btn btn-sm btn-outline-success"><i class="fas fa-file-csv me-1"></i>Export Fees Report</a>
      <a href="ajax/predictive_analytics_export.php?<?= htmlspecialchars($forecastExportQuery) ?>" class="btn btn-sm btn-primary"><i class="fas fa-download me-1"></i>Export Forecast</a>
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
        <div class="col-md-1">
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
        <div class="col-md-2">
          <label class="form-label small fw-bold text-muted mb-1">Forecast Horizon</label>
          <select name="horizon" class="form-select form-select-sm">
            <?php foreach ([3, 6, 12] as $months): ?>
              <option value="<?= $months ?>" <?= $forecastHorizon === $months ? 'selected' : '' ?>><?= $months ?> months</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-filter me-1"></i>Apply</button>
          <a href="analytics_dashboard.php" class="btn btn-sm btn-outline-secondary" aria-label="Reset analytics filters" title="Reset filters"><i class="fas fa-undo"></i></a>
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

  <section class="mb-4" aria-labelledby="forecast-heading">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
      <div>
        <h2 id="forecast-heading" class="h4 mb-1"><i class="fas fa-wand-magic-sparkles me-2 text-primary"></i>Forward Outlook</h2>
        <p class="text-muted mb-0">Projected from completed monthly records through <?= htmlspecialchars($predictive['through_period']) ?>.</p>
      </div>
      <span class="badge rounded-pill text-bg-light border text-dark"><i class="fas fa-calendar-days me-1"></i><?= $forecastHorizon ?>-month horizon</span>
    </div>

    <div class="row g-3 mb-3">
      <?php $admissionForecast = $predictive['admissions']; ?>
      <div class="col-lg-4 col-md-6">
        <article class="forecast-summary-card h-100">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <div class="forecast-label">Projected admissions</div>
              <div class="forecast-value"><?= number_format((float)$admissionForecast['expected_total'], 0) ?></div>
              <div class="small text-muted">Across the next <?= $forecastHorizon ?> months</div>
            </div>
            <div class="forecast-icon bg-purple"><i class="fas fa-user-plus"></i></div>
          </div>
          <div class="mt-3 d-flex align-items-center justify-content-between gap-2">
            <span class="badge <?= $confidenceClass((int)$admissionForecast['confidence_score']) ?>"><?= htmlspecialchars($admissionForecast['confidence_label']) ?> confidence</span>
            <span class="small text-muted"><?= number_format((int)$admissionForecast['sample_records']) ?> records</span>
          </div>
        </article>
      </div>

      <?php $collectionForecast = $predictive['collections']; ?>
      <div class="col-lg-4 col-md-6">
        <article class="forecast-summary-card h-100">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <div class="forecast-label">Projected fee collections</div>
              <div class="forecast-value">ZMW <?= number_format((float)$collectionForecast['expected_total'], 2) ?></div>
              <div class="small text-muted">Across the next <?= $forecastHorizon ?> months</div>
            </div>
            <div class="forecast-icon bg-success"><i class="fas fa-coins"></i></div>
          </div>
          <div class="mt-3 d-flex align-items-center justify-content-between gap-2">
            <span class="badge <?= $confidenceClass((int)$collectionForecast['confidence_score']) ?>"><?= htmlspecialchars($collectionForecast['confidence_label']) ?> confidence</span>
            <span class="small text-muted"><?= number_format((int)$collectionForecast['sample_records']) ?> payments</span>
          </div>
        </article>
      </div>

      <div class="col-lg-4">
        <article class="forecast-summary-card h-100">
          <div class="d-flex align-items-start gap-3">
            <div class="forecast-icon bg-warning text-dark"><i class="fas fa-lightbulb"></i></div>
            <div class="min-w-0">
              <div class="forecast-label">Planning signals</div>
              <?php if ($predictive['actions']): ?>
                <ul class="small ps-3 mb-0 mt-2">
                  <?php foreach ($predictive['actions'] as $action): ?>
                    <li class="mb-1"><?= htmlspecialchars($action) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="small text-muted mb-0 mt-2">No forecast warning was triggered for the selected scope.</p>
              <?php endif; ?>
            </div>
          </div>
        </article>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-xl-6">
        <article class="card shadow-sm border-0 h-100 forecast-chart-card">
          <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-start gap-2">
            <div>
              <h3 class="h5 card-title mb-1"><i class="fas fa-users-viewfinder me-2 text-primary"></i>Admissions Forecast</h3>
              <div class="small text-muted">Actual monthly admissions and projected range</div>
            </div>
            <span class="badge <?= $confidenceClass((int)$admissionForecast['confidence_score']) ?>"><?= (int)$admissionForecast['confidence_score'] ?>/100</span>
          </div>
          <div class="card-body">
            <?php if ($admissionForecast['available']): ?>
              <div class="forecast-chart-wrap"><canvas id="admissionsForecastChart" aria-label="Admissions forecast chart"></canvas></div>
            <?php else: ?>
              <div class="forecast-empty"><i class="fas fa-chart-line"></i><p>No complete admissions history is available.</p></div>
            <?php endif; ?>
          </div>
        </article>
      </div>

      <div class="col-xl-6">
        <article class="card shadow-sm border-0 h-100 forecast-chart-card">
          <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-start gap-2">
            <div>
              <h3 class="h5 card-title mb-1"><i class="fas fa-money-bill-trend-up me-2 text-success"></i>Fee Collection Forecast</h3>
              <div class="small text-muted">Approved portal payments and projected range (ZMW)</div>
            </div>
            <span class="badge <?= $confidenceClass((int)$collectionForecast['confidence_score']) ?>"><?= (int)$collectionForecast['confidence_score'] ?>/100</span>
          </div>
          <div class="card-body">
            <?php if ($collectionForecast['available']): ?>
              <div class="forecast-chart-wrap"><canvas id="collectionsForecastChart" aria-label="Fee collection forecast chart"></canvas></div>
            <?php else: ?>
              <div class="forecast-empty"><i class="fas fa-chart-line"></i><p>No complete approved-payment history is available.</p></div>
            <?php endif; ?>
          </div>
        </article>
      </div>
    </div>

    <div class="alert alert-light border mt-3 mb-0 small d-flex align-items-start gap-2" role="note">
      <i class="fas fa-circle-info text-primary mt-1"></i>
      <div><strong>How to read this:</strong> <?= htmlspecialchars($predictive['disclaimer']) ?> <?= htmlspecialchars($admissionForecast['method']) ?></div>
    </div>
  </section>

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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  'use strict';

  const admissionsModel = <?= json_encode($admissionForecast, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const collectionsModel = <?= json_encode($collectionForecast, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function monthLabel(period) {
    const parts = String(period).split('-');
    if (parts.length !== 2) return period;
    return new Intl.DateTimeFormat('en', { month: 'short', year: 'numeric' })
      .format(new Date(Number(parts[0]), Number(parts[1]) - 1, 1));
  }

  function renderForecastChart(canvasId, model, currency) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined' || !model.available) return;

    const history = model.history || [];
    const forecast = model.forecast || [];
    const labels = history.map(row => monthLabel(row.period)).concat(forecast.map(row => monthLabel(row.period)));
    const actual = history.map(row => Number(row.value)).concat(forecast.map(() => null));
    const projection = history.map(() => null);
    const lower = history.map(() => null);
    const upper = history.map(() => null);
    if (history.length) {
      const anchor = Number(history[history.length - 1].value);
      projection[history.length - 1] = anchor;
      lower[history.length - 1] = anchor;
      upper[history.length - 1] = anchor;
    }
    forecast.forEach(row => {
      projection.push(Number(row.value));
      lower.push(Number(row.lower));
      upper.push(Number(row.upper));
    });

    new Chart(canvas, {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Actual',
            data: actual,
            borderColor: '#6f42c1',
            backgroundColor: 'rgba(111, 66, 193, 0.12)',
            borderWidth: 3,
            pointRadius: 3,
            tension: 0.28
          },
          {
            label: 'Lower estimate',
            data: lower,
            borderColor: 'rgba(245, 158, 11, 0.25)',
            pointRadius: 0,
            borderWidth: 1,
            tension: 0.28
          },
          {
            label: 'Forecast range',
            data: upper,
            borderColor: 'rgba(245, 158, 11, 0.25)',
            backgroundColor: 'rgba(245, 158, 11, 0.14)',
            pointRadius: 0,
            borderWidth: 1,
            fill: '-1',
            tension: 0.28
          },
          {
            label: 'Forecast',
            data: projection,
            borderColor: '#f59e0b',
            backgroundColor: '#f59e0b',
            borderDash: [7, 5],
            borderWidth: 3,
            pointRadius: 3,
            tension: 0.28
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
          tooltip: {
            callbacks: {
              label: function (context) {
                if (context.raw === null) return '';
                const value = Number(context.raw);
                return context.dataset.label + ': ' + (currency
                  ? 'ZMW ' + value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                  : Math.round(value).toLocaleString());
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: value => currency ? 'ZMW ' + Number(value).toLocaleString() : Number(value).toLocaleString()
            },
            grid: { color: 'rgba(148, 163, 184, 0.18)' }
          },
          x: { grid: { display: false } }
        }
      }
    });
  }

  renderForecastChart('admissionsForecastChart', admissionsModel, false);
  renderForecastChart('collectionsForecastChart', collectionsModel, true);
}());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

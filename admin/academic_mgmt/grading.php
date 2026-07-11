<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <h1 class="dashboard-title"><i class="fas fa-chart-line me-2"></i>Grading & Results</h1>
    <p class="text-muted mb-0">Compute GPA/CGPA, publish results, and manage appeals.</p>
  </div>

  <div class="data-table-card mb-3">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Grading Scale (Zambia)</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Grade</th><th>% Range</th><th>Grade Point</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>A+</td><td>86–100</td><td>5.0</td></tr>
            <tr><td>A</td><td>75–85</td><td>4.0</td></tr>
            <tr><td>B+</td><td>66–74</td><td>3.5</td></tr>
            <tr><td>B</td><td>60–65</td><td>3.0</td></tr>
            <tr><td>C+</td><td>55–59</td><td>2.5</td></tr>
            <tr><td>C</td><td>50–54</td><td>2.0</td></tr>
            <tr><td>D+</td><td>45–49</td><td>1.5</td></tr>
            <tr><td>D</td><td>40–44</td><td>1.0</td></tr>
            <tr><td>E</td><td><40</td><td>0.0</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="data-table-card h-100">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-calculator me-2"></i>GPA/CGPA Computation</h5>
          </div>
        </div>
        <div class="card-body">
          <a href="../student_results.php" class="btn btn-outline-primary"><i class="fas fa-equals me-2"></i>Compute & View</a>
          <p class="text-muted mt-2 mb-0">Automatically compute per semester and cumulative.</p>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="data-table-card h-100">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-bullhorn me-2"></i>Publish Results</h5>
          </div>
        </div>
        <div class="card-body">
          <a href="../publishResults.php" class="btn btn-outline-primary"><i class="fas fa-bullhorn me-2"></i>Publish via Portal</a>
          <a href="../sms/sms.php" class="btn btn-outline-secondary ms-2"><i class="fas fa-sms me-2"></i>Notify via SMS</a>
          <p class="text-muted mt-2 mb-0">Secure portal access and notifications.</p>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Download Transcripts</strong></div>
        <div class="card-body">
          <form method="POST" action="../transcript.php" target="_blank" class="row g-2">
            <div class="col-md-5">
              <label class="form-label">Student ID (SID)</label>
              <input type="text" class="form-control" name="Sid" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Year</label>
              <input type="number" class="form-control" name="Year" value="<?php echo date('Y'); ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">Sem</label>
              <select name="semester" class="form-select" required>
                <option value="1">1</option>
                <option value="2">2</option>
              </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button class="btn btn-primary w-100"><i class="fas fa-download me-2"></i>PDF</button>
            </div>
          </form>
          <p class="text-muted mt-2 mb-0">Generates a printable transcript.</p>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Appeals & Re-mark Requests</strong></div>
        <div class="card-body">
          <form action="save_appeal_workflow.php" method="post" class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Course</label>
              <select class="form-select" name="course_code">
                <?php
                if ($crs = $db->query("SELECT course_code, course_name FROM courses ORDER BY course_name")) {
                  while ($c = $crs->fetch_assoc()) {
                    echo '<option value="'.htmlspecialchars($c['course_code']).'">'.htmlspecialchars($c['course_name']).'</option>';
                  }
                  $crs->free();
                }
                ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Student ID</label>
              <input type="text" name="Sid" class="form-control" placeholder="SID...">
            </div>
            <div class="col-md-4">
              <label class="form-label">Reason</label>
              <input type="text" name="reason" class="form-control">
            </div>
            <div class="col-12">
              <button class="btn btn-primary" type="submit"><i class="fas fa-save me-2"></i>Log Appeal</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>




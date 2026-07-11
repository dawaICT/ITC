<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/header.php';

// Ensure export table for QMIS batches. The app DB user is DML-only, so only
// attempt DDL when the table is truly absent (a denied CREATE would fatal).
$qeCheck = $db->query("SHOW TABLES LIKE 'qmis_exports'");
if (!$qeCheck || $qeCheck->num_rows === 0) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS qmis_exports (
          id INT AUTO_INCREMENT PRIMARY KEY,
          export_name VARCHAR(100) NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[records] qmis_exports missing and CREATE denied — run migrations: ' . $e->getMessage());
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'qmis') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="qmis_export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SID','FirstName','LastName','ProgramCode','Year','Semester','CreditsCompleted','GPA']);
    $sql = "SELECT s.SID, s.Fname, s.Lname, sp.program_code, sr.Year, sr.semester,
                   COALESCE(cc.credits_completed,0) AS credits_completed,
                   COALESCE(g.gpa,0) AS gpa
            FROM students s
            LEFT JOIN student_program sp ON sp.Sid = s.SID
            LEFT JOIN semester_registration sr ON sr.Sid = s.SID
            LEFT JOIN (
                SELECT cr.Sid, SUM(c.credit_hours) AS credits_completed
                FROM course_registration cr
                JOIN courses c ON c.course_code = cr.course_code
                GROUP BY cr.Sid
            ) cc ON cc.Sid = s.SID
            LEFT JOIN (
                SELECT e.Sid, ROUND(AVG(e.Total_marks)/25,2) AS gpa
                FROM exams e
                GROUP BY e.Sid
            ) g ON g.Sid = s.SID
            WHERE s.status = 'Active'
            LIMIT 10000";
    if ($res = $db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            fputcsv($out, [$row['SID'],$row['Fname'],$row['Lname'],$row['program_code'],$row['Year'],$row['semester'],$row['credits_completed'],$row['gpa']]);
        }
        $res->free();
    }
    fclose($out);
    exit;
}

?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title"><i class="fas fa-folder-open me-2"></i>Student Records & Certificates</h1>
        <p class="text-muted">Maintain profiles, generate ZAQA-compliant certificates, and export QMIS CSV.</p>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="data-table-card h-100">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-file-certificate me-2"></i>Generate Certificate</h5>
          </div>
        </div>
        <div class="card-body">
          <form method="post" action="../transcript.php" target="_blank" class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Student ID (SID)</label>
              <input type="text" class="form-control" name="Sid" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Year</label>
              <input type="number" class="form-control" name="Year" value="<?php echo date('Y'); ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Semester</label>
              <select name="semester" class="form-select" required>
                <option value="1">1</option>
                <option value="2">2</option>
              </select>
            </div>
            <div class="col-12">
              <button name="submit" class="btn btn-primary"><i class="fas fa-file-alt me-2"></i>Generate Transcript</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="data-table-card h-100">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-file-export me-2"></i>QMIS Export (CSV)</h5>
          </div>
        </div>
        <div class="card-body">
          <p>Export active student progression data for QMIS upload.</p>
          <a href="?export=qmis" class="btn btn-outline-primary"><i class="fas fa-download me-2"></i>Download CSV</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>




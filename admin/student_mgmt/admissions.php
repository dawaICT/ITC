<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/header.php';

// Ensure tables for eligibility metrics. The app DB user is DML-only, so any
// CREATE (even IF NOT EXISTS on an existing table) is privilege-denied and
// would fatal the page — only attempt it when the table is truly absent.
$asCheck = $db->query("SHOW TABLES LIKE 'applicant_scores'");
if (!$asCheck || $asCheck->num_rows === 0) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS applicant_scores (
          id INT AUTO_INCREMENT PRIMARY KEY,
          applicant_id INT NOT NULL,
          olevel_credits INT NOT NULL DEFAULT 0,
          merit_score DECIMAL(5,2) DEFAULT NULL,
          bursary_requested TINYINT(1) DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_applicant (applicant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[admissions] applicant_scores missing and CREATE denied — run migrations: ' . $e->getMessage());
    }
}

// Fetch applicants (online)
$apps = [];
if ($res = $db->query("SELECT * FROM online_applicants ORDER BY dte_adm DESC LIMIT 200")) {
    while ($row = $res->fetch_assoc()) { $apps[] = $row; }
    $res->free();
}

// Helper: derive eligibility (simplified – expects applicant uploaded results summary with detected credits)
function estimate_credits(array $app): int {
    // Placeholder: if results filename contains 'creditsX', parse X. Otherwise default 0
    $f = strtolower((string)($app['results'] ?? ''));
    if (preg_match('/credits(\d{1,2})/', $f, $m)) { return max(0, (int)$m[1]); }
    return 0; // Fallback if no automated parsing
}

// Map credits to bursary percentage
function derive_bursary_percent(int $credits): int {
    if ($credits >= 9) { return 75; }
    if ($credits >= 7) { return 50; }
    if ($credits >= 5) { return 20; }
    return 0;
}

// Build a program => estimated fee map for Year 1, Semester 1
$program_fee_map = [];
try {
    $has_fee_structures = $db->query("SHOW TABLES LIKE 'fee_structures'");
    $has_fee_structure = $db->query("SHOW TABLES LIKE 'fee_structure'");
    if ($has_fee_structures && $has_fee_structures->num_rows > 0) {
        $q = "SELECT program_code, SUM(amount) AS total FROM fee_structures WHERE status='active' AND year_of_study=1 AND semester=1 GROUP BY program_code";
        if ($r = $db->query($q)) {
            while ($row = $r->fetch_assoc()) { $program_fee_map[$row['program_code']] = (float)$row['total']; }
            $r->free();
        }
    } elseif ($has_fee_structure && $has_fee_structure->num_rows > 0) {
        $q = "SELECT program_code, SUM(amount) AS total FROM fee_structure WHERE status='active' AND year_of_study=1 AND semester=1 GROUP BY program_code";
        if ($r = $db->query($q)) {
            while ($row = $r->fetch_assoc()) { $program_fee_map[$row['program_code']] = (float)$row['total']; }
            $r->free();
        }
    }
    if ($has_fee_structures) { $has_fee_structures->free(); }
    if ($has_fee_structure) { $has_fee_structure->free(); }
} catch (Throwable $e) {
    // ignore; fee map is optional
}

// Stats for Student Management quick cards
try {
    $total_students = $db->query("SELECT COUNT(*) as total FROM students")->fetch_object()->total ?? 0;
    $male_students = $db->query("SELECT COUNT(*) as total FROM students WHERE sex='M'")->fetch_object()->total ?? 0;
    $female_students = $db->query("SELECT COUNT(*) as total FROM students WHERE sex='F'")->fetch_object()->total ?? 0;

    $program_stats = [];
    if ($pr = $db->query("SELECT p.program_name, COUNT(*) as count FROM student_program sp JOIN programs p ON sp.program_code = p.program_code GROUP BY p.program_name")) {
        while ($row = $pr->fetch_object()) { $program_stats[$row->program_name] = $row->count; }
        $pr->free();
    }

    $year_stats = [];
    if ($yr = $db->query("SELECT intake, COUNT(*) as count FROM student_program GROUP BY intake")) {
        while ($row = $yr->fetch_object()) { $year_stats[$row->intake] = $row->count; }
        $yr->free();
    }
} catch (Throwable $e) {
    // keep defaults on failure
}

?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title"><i class="fas fa-user-check me-2"></i>Admissions Management</h1>
        <p class="text-muted">Review online applications, check eligibility (O-level >= 5), and shortlist by merit.</p>
      </div>
      <div class="col-auto">
        <a href="../applicants.php" class="btn btn-outline-secondary"><i class="fas fa-list me-2"></i>Legacy Applicants</a>
      </div>
    </div>
  </div>

  <!-- Student Management: Quick Stats -->
  <div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
      <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-primary rounded-circle p-3 me-3"><i class="fas fa-users fa-2x text-white"></i></div>
          <div>
            <h3 class="mb-1"><?php echo number_format((int)($total_students ?? 0)); ?></h3>
            <p class="text-muted mb-0">Total Students</p>
          </div>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-info rounded-circle p-3 me-3"><i class="fas fa-mars fa-2x text-white"></i></div>
          <div>
            <h3 class="mb-1"><?php echo number_format((int)($male_students ?? 0)); ?></h3>
            <p class="text-muted mb-0">Male Students</p>
          </div>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-danger rounded-circle p-3 me-3"><i class="fas fa-venus fa-2x text-white"></i></div>
          <div>
            <h3 class="mb-1"><?php echo number_format((int)($female_students ?? 0)); ?></h3>
            <p class="text-muted mb-0">Female Students</p>
          </div>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-success rounded-circle p-3 me-3"><i class="fas fa-graduation-cap fa-2x text-white"></i></div>
          <div>
            <h3 class="mb-1"><?php echo count($program_stats ?? []); ?></h3>
            <p class="text-muted mb-0">Active Programs</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Minimum O-level Credits</label>
          <select class="form-select" id="minCredits">
            <option value="5" selected>>= 5 Credits</option>
            <option value="6">>= 6 Credits</option>
            <option value="7">>= 7 Credits</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Bursary Requested</label>
          <select class="form-select" id="bursaryFilter">
            <option value="any" selected>Any</option>
            <option value="yes">Yes</option>
            <option value="no">No</option>
          </select>
        </div>
        <div class="col-md-6 d-flex align-items-end">
          <button class="btn btn-primary me-2" id="applyFilters"><i class="fas fa-search me-2"></i>Apply</button>
          <button class="btn btn-outline-primary" id="shortlistMerit"><i class="fas fa-trophy me-2"></i>Shortlist by Merit</button>
        </div>
      </div>
    </div>
  </div>

  <div class="data-table-card">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Online Applicants</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="appsTable">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>NRC</th>
              <th>Program</th>
              <th>Intake</th>
              <th>Credits</th>
              <th>Bursary</th>
              <th>Est. Fee</th>
              <th>Bursary %</th>
              <th>Bursary Amt</th>
              <th>Net Payable</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 1; foreach ($apps as $a): $credits = estimate_credits($a); ?>
              <tr data-credits="<?php echo (int)$credits; ?>" data-bursary="<?php echo strtolower($a['sponsor'] ?? '') === 'bursary' ? 'yes' : 'no'; ?>">
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars(($a['Fname'] ?? '') . ' ' . ($a['Lname'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($a['nrc_pass'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($a['program'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($a['intake'] ?? ''); ?></td>
                <td><span class="badge bg-<?php echo $credits >= 5 ? 'success' : 'danger'; ?>"><?php echo (int)$credits; ?></span></td>
                <td><?php echo strtolower($a['sponsor'] ?? '') === 'bursary' ? '<span class="badge bg-info">Yes</span>' : 'No'; ?></td>
                <?php 
                  $progCode = (string)($a['program'] ?? '');
                  $estFee = isset($program_fee_map[$progCode]) ? (float)$program_fee_map[$progCode] : 0.0;
                  $bursaryPercent = derive_bursary_percent((int)$credits);
                  $bursaryAmt = round($estFee * ($bursaryPercent/100), 2);
                  $netPay = max(0, $estFee - $bursaryAmt);
                ?>
                <td data-fee="<?php echo htmlspecialchars(number_format($estFee, 2)); ?>"><?php echo number_format($estFee, 2); ?></td>
                <td data-bp="<?php echo (int)$bursaryPercent; ?>"><?php echo $bursaryPercent; ?>%</td>
                <td><?php echo number_format($bursaryAmt, 2); ?></td>
                <td data-net="<?php echo htmlspecialchars(number_format($netPay, 2)); ?>"><?php echo number_format($netPay, 2); ?></td>
                <td>
                  <div class="btn-group">
                    <a class="btn btn-sm btn-outline-secondary" href="../trackApp.php?id=<?php echo (int)$a['id']; ?>" target="_blank"><i class="fas fa-eye"></i></a>
                    <?php if ($credits >= 5): ?>
                      <form action="../acceptApplicant.php" method="POST" style="display:inline;">
                        <input type="hidden" name="mov" value="<?php echo (int)$a['id']; ?>">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <button type="submit" class="btn btn-sm btn-success">
                          <i class="fas fa-check me-1"></i>Accept
                        </button>
                      </form>
                    <?php else: ?>
                      <button class="btn btn-sm btn-outline-danger" disabled><i class="fas fa-times me-1"></i>Below 5</button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Student Management: Actions -->
  <div class="row mb-4 mt-4">
    <div class="col-12">
      <div class="card shadow-sm admin-card selector-card">
        <div class="card-header student-header bg-white py-3">
          <h5 class="mb-0 text-primary"><i class="fas fa-tasks me-2"></i>Student Management</h5>
        </div>
        <div class="card-body p-4">
          <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-4 col-xl-3">
              <a href="../student_mgmt/admissions.php" class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                <i class="fas fa-users"></i>
                <span>Applicants</span>
              </a>
            </div>
            <div class="col-12 col-md-6 col-lg-4 col-xl-3">
              <a href="#" class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 py-2 disabled" tabindex="-1" aria-disabled="true" title="Coming soon">
                <i class="fas fa-check-circle"></i>
                <span>Clearance</span>
              </a>
            </div>
            <div class="col-12 col-md-6 col-lg-4 col-xl-3">
              <a href="../admitEnrolled_student.php" class="btn btn-outline-success w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                <i class="fas fa-user-plus"></i>
                <span>Admit Student</span>
              </a>
            </div>
            <div class="col-12 col-md-6 col-lg-4 col-xl-3">
              <a href="../editStud_by_prog.php" class="btn btn-outline-warning w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                <i class="fas fa-edit"></i>
                <span>Edit by Program</span>
              </a>
            </div>
            <div class="col-12 col-md-6 col-lg-4 col-xl-3">
              <a href="../regNewStud.php" class="btn btn-outline-success w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                <i class="fas fa-user-plus"></i>
                <span>Register New Student</span>
              </a>
            </div>
            <div class="col-12 col-md-6 col-lg-4 col-xl-3">
              <a href="../regOldStud.php" class="btn btn-outline-info w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                <i class="fas fa-user-graduate"></i>
                <span>Register Existing Student</span>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Student Management: Records Table -->
  <div class="card shadow-sm data-table-container student-table">
    <div class="card-header student-header bg-white py-3">
      <div class="row align-items-center">
        <div class="col">
          <h5 class="mb-0 text-primary"><i class="fas fa-user-graduate me-2"></i>Student Records</h5>
        </div>
        <div class="col-auto">
          <div class="d-flex gap-2">
            <div class="search-box">
              <div class="input-group">
                <input type="text" class="form-control" id="quickSearch" placeholder="Search students...">
                <button class="btn btn-primary" type="button"><i class="fas fa-search"></i></button>
              </div>
            </div>
            <div class="btn-group">
              <button type="button" class="btn btn-outline-primary active" data-view="table"><i class="fas fa-table"></i></button>
              <button type="button" class="btn btn-outline-primary" data-view="grid"><i class="fas fa-th"></i></button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table id="myTable" class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>No.</th>
              <th>Student No</th>
              <th>Full Name</th>
              <th>Gender</th>
              <th>Program</th>
              <th>Intake</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            if($results = $db->query("SELECT s.*, sp.*, p.program_name FROM students s INNER JOIN student_program sp ON s.SID = sp.Sid INNER JOIN programs p ON sp.program_code = p.program_code ORDER BY s.SID ASC")) {
                $number = 1;
                while($r = $results->fetch_object()) {
            ?>
                <tr>
                  <td><?php echo $number++; ?>.</td>
                  <td><?php echo $r->SID; ?></td>
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="avatar-circle me-2 bg-primary text-white"><?php echo strtoupper(substr($r->Fname, 0, 1)); ?></div>
                      <div><?php echo "$r->Fname $r->Lname"; ?></div>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-<?php echo $r->sex == 'M' ? 'info' : 'danger'; ?>"><?php echo $r->sex == 'M' ? 'Male' : 'Female'; ?></span>
                  </td>
                  <td><span class="badge bg-primary"><?php echo $r->program_name; ?></span></td>
                  <td><span class="badge bg-secondary"><?php echo $r->intake; ?></span></td>
                  <td class="text-center">
                    <div class="btn-group">
                      <a class="btn btn-sm btn-outline-info" href="../view_student.php?view=<?php echo $r->SID?>" title="View Details"><i class="fas fa-eye"></i></a>
                      <a class="btn btn-sm btn-outline-warning" href="../editStudent.php?update=<?php echo $r->studentID?>" title="Edit Student"><i class="fas fa-edit"></i></a>
                      <button class="btn btn-sm btn-outline-danger" onclick="deleteStudent('<?php echo $r->SID?>')" title="Delete Student"><i class="fas fa-trash"></i></button>
                    </div>
                  </td>
                </tr>
            <?php 
                }
                $results->free();
            }  
            ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('applyFilters').addEventListener('click', function(){
  const minCredits = parseInt(document.getElementById('minCredits').value, 10);
  const bursary = document.getElementById('bursaryFilter').value;
  document.querySelectorAll('#appsTable tbody tr').forEach(function(row){
    const credits = parseInt(row.getAttribute('data-credits')||'0',10);
    const rowBursary = row.getAttribute('data-bursary');
    const passCredits = credits >= minCredits;
    const passBursary = (bursary==='any') || (bursary===rowBursary);
    row.style.display = (passCredits && passBursary) ? '' : 'none';
  });
});

document.getElementById('shortlistMerit').addEventListener('click', function(){
  const tbody = document.querySelector('#appsTable tbody');
  const rows = Array.from(tbody.querySelectorAll('tr'));
  rows.sort((a,b) => (parseInt(b.getAttribute('data-credits')||'0',10) - parseInt(a.getAttribute('data-credits')||'0',10)));
  rows.forEach(r => tbody.appendChild(r));
});
</script>

<script>
// Student Records enhancements
$(document).ready(function() {
  if ($('#myTable').length) {
    $('#myTable').DataTable({
      responsive: true,
      language: { search: "_INPUT_", searchPlaceholder: "Search students..." }
    });
  }
});

function deleteStudent(id) {
  if (confirm('Are you sure you want to delete this student?')) {
    window.location.href = '../delete_student.php?sid=' + encodeURIComponent(id);
  }
}

$('[data-view]').on('click', function() {
  const view = $(this).data('view');
  $('[data-view]').removeClass('active');
  $(this).addClass('active');
  if (view === 'grid') {
    $('#myTable').hide();
  } else {
    $('#myTable').show();
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>




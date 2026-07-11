<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <h1 class="dashboard-title"><i class="fas fa-th-large me-2"></i>Academic Management</h1>
    <p class="text-muted">Overview of academic administration modules.</p>
  </div>

  <div class="row g-3">
    <div class="col-md-6 col-xl-4">
      <a class="card shadow-sm h-100 text-decoration-none" href="curriculum.php">
        <div class="card-body d-flex align-items-center gap-3">
          <i class="fas fa-sitemap fa-2x text-primary"></i>
          <div>
            <h5 class="mb-1">Course & Curriculum</h5>
            <div class="text-muted">Programs, syllabi, credits, prerequisites</div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-md-6 col-xl-4">
      <a class="card shadow-sm h-100 text-decoration-none" href="timetabling.php">
        <div class="card-body d-flex align-items-center gap-3">
          <i class="fas fa-calendar-alt fa-2x text-primary"></i>
          <div>
            <h5 class="mb-1">Timetabling & Scheduling</h5>
            <div class="text-muted">Courses, exams, rooms</div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-md-6 col-xl-4">
      <a class="card shadow-sm h-100 text-decoration-none" href="exams.php">
        <div class="card-body d-flex align-items-center gap-3">
          <i class="fas fa-file-contract fa-2x text-primary"></i>
          <div>
            <h5 class="mb-1">Examinations</h5>
            <div class="text-muted">Question bank, proctoring, grading</div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-md-6 col-xl-4">
      <a class="card shadow-sm h-100 text-decoration-none" href="grading.php">
        <div class="card-body d-flex align-items-center gap-3">
          <i class="fas fa-chart-line fa-2x text-primary"></i>
          <div>
            <h5 class="mb-1">Grading & Results</h5>
            <div class="text-muted">GPA/CGPA, publication, appeals</div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-md-6 col-xl-4">
      <a class="card shadow-sm h-100 text-decoration-none" href="progression.php">
        <div class="card-body d-flex align-items-center gap-3">
          <i class="fas fa-user-graduate fa-2x text-primary"></i>
          <div>
            <h5 class="mb-1">Progression & Graduation</h5>
            <div class="text-muted">Eligibility, alerts, clearance</div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-md-6 col-xl-4">
      <a class="card shadow-sm h-100 text-decoration-none" href="campus_services_admin.php">
        <div class="card-body d-flex align-items-center gap-3">
          <i class="fas fa-university fa-2x text-primary"></i>
          <div>
            <h5 class="mb-1">Campus Services &amp; Clearance</h5>
            <div class="text-muted">Counselling, medical, approvals</div>
          </div>
        </div>
      </a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>




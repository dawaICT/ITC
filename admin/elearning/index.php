<?php
require_once __DIR__ . '/../includes/admin.php';
// Session already started in admin.php, no need to check here
require_once __DIR__ . '/../../includes/elearning_guard.php';
elearning_require_role(['systems_admin','lecturer','head_of_department','dean','registrar']);

$config = require __DIR__ . '/../../config/elearning.php';

// Ensure storage folder exists
$root = $config['storage_root'];
if (!is_dir($root)) { @mkdir($root, 0775, true); }

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../css/admin-dashboard.css" />
<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">eLearning Module</h1>
        <p class="text-muted">Manage course content, virtual sessions, assessments, and analytics.</p>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-xl-3 col-md-6">
      <a class="card stat-card text-decoration-none" href="modules.php">
        <div class="stat-card-body">
          <div class="stat-card-icon"><i class="fas fa-folder-open"></i></div>
          <div>
            <h6 class="stat-card-title">Course Content</h6>
            <p class="mb-0 text-muted">Upload PDFs, videos, SCORM; schedule releases</p>
          </div>
        </div>
      </a>
    </div>
    <div class="col-xl-3 col-md-6">
      <a class="card stat-card text-decoration-none" href="sessions.php">
        <div class="stat-card-body">
          <div class="stat-card-icon"><i class="fas fa-video"></i></div>
          <div>
            <h6 class="stat-card-title">Virtual Classroom</h6>
            <p class="mb-0 text-muted">Zoom/Teams integration, breakout, polls</p>
          </div>
        </div>
      </a>
    </div>
    <div class="col-xl-3 col-md-6">
      <a class="card stat-card text-decoration-none" href="assessments.php">
        <div class="stat-card-body">
          <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
          <div>
            <h6 class="stat-card-title">Assessments</h6>
            <p class="mb-0 text-muted">Auto-graded quizzes, rubrics, plagiarism</p>
          </div>
        </div>
      </a>
    </div>
    <div class="col-xl-3 col-md-6">
      <a class="card stat-card text-decoration-none" href="analytics.php">
        <div class="stat-card-body">
          <div class="stat-card-icon"><i class="fas fa-chart-line"></i></div>
          <div>
            <h6 class="stat-card-title">Analytics</h6>
            <p class="mb-0 text-muted">Engagement, alerts, certificates</p>
          </div>
        </div>
      </a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>




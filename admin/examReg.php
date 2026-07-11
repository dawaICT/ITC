<?php
/**
 * examReg.php  —  Exam Registration (Admin view)
 *
 * This file is a thin entry point:
 *   1. Bootstrap (session, auth guard, DB connection, autoload)
 *   2. CSRF verification on POST
 *   3. Hand off to the Controller (returns a ViewModel array)
 *   4. Render the view
 *
 * All business logic lives in app/ExamRegistration/.
 * No SQL queries, no raw user data, no direct $_POST access in the view.
 */

// ─── 1. Bootstrap ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/admin.php';         // session_start() + auth check + DB
require_once dirname(__DIR__) . '/app/autoload.php';  // PSR-4 autoloader
require_once dirname(__DIR__) . '/students/includes/period_mode_helper.php';
require_once dirname(__DIR__) . '/students/includes/exam_helpers.php';

// In production, errors go to the log only, never to the browser.
// Flip to E_ALL + display_errors=1 in your .env for local development only.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Fail-safe: guard.php should have already aborted if $db isn't set,
// but be explicit so the rest of the file is safe to run.
if (!isset($db) || $db->connect_error) {
    error_log('[ExamReg] Database connection unavailable.');
    // Show a user-safe message; no internal details.
    require __DIR__ . '/includes/header.php';
    echo '<div class="container mt-4">
            <div class="alert alert-danger">
                <strong>Service unavailable.</strong> Please contact the system administrator.
            </div>
          </div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

student_exam_ensure_schema($db);

// ─── 2. CSRF verification on every POST ──────────────────────────────────────
use App\Csrf;
use App\ExamRegistration\Controller;
use App\ExamRegistration\Repository;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify('examReg.php');
}

// ─── 3. Controller ────────────────────────────────────────────────────────────
$repo       = new Repository($db);
$controller = new Controller($repo);
$vm         = $controller->handle();   // returns ViewModel; may redirect internally

// ─── 4. Render view ───────────────────────────────────────────────────────────
require __DIR__ . '/includes/header.php';

/**
 * Safe HTML-encoding shorthand — use everywhere user-derived data is echoed.
 */
if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Pull and clear the one-time flash message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Convenience aliases from ViewModel
$searchPerformed = $vm['searchPerformed'];
$courses         = $vm['courses'];
$lastSearch      = $vm['lastSearch'];
?>

<div class="container-fluid px-4 portal-dashboard">

  <!-- Page header -->
  <div class="dashboard-header admin-section mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Exam Registration</h1>
        <p class="text-muted">Register students for external end-of-period assessments</p>
      </div>
      <?php if ($searchPerformed): ?>
      <div class="col-auto">
        <a href="examReg.php" class="btn btn-outline-primary d-flex align-items-center gap-2">
          <i class="fas fa-arrow-left"></i> New Search
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Flash message -->
  <?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
    <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
  <?php endif; ?>

  <!-- ── Search form ─────────────────────────────────────────────────────── -->
  <div class="data-table-card mb-4">
    <div class="card-header">
      <h5 class="mb-0"><i class="fas fa-search me-2"></i>Search Student</h5>
    </div>
    <div class="card-body">
      <form method="POST" action="examReg.php" class="row g-3" novalidate>
        <?php Csrf::field(); ?>

        <div class="col-md-4">
          <label for="Sid" class="form-label">Student ID</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-id-card"></i></span>
            <input type="text" class="form-control" name="Sid" id="Sid"
                   value="<?= e($lastSearch['Sid']) ?>"
                   required autocomplete="off" placeholder="Enter Student ID"
                   pattern="\d{9}" title="9-digit Student ID" maxlength="9">
          </div>
          <div id="student-lookup-msg" class="form-text"></div>
        </div>

        <div class="col-md-3">
          <label for="semester" class="form-label">Assessment Period</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
            <select class="form-select" name="semester" id="semester" required>
              <option value="" disabled <?= $lastSearch['semester'] === '' ? 'selected' : '' ?>>
                Select period
              </option>
              <?php foreach (['1' => 'Period 1', '2' => 'Period 2', '3' => 'Period 3'] as $val => $label): ?>
              <option value="<?= e($val) ?>" <?= $lastSearch['semester'] === $val ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="col-md-3">
          <label for="Year" class="form-label">Year of Study</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-layer-group"></i></span>
            <select class="form-select" name="Year" id="Year" required>
              <option value="" disabled <?= $lastSearch['Year'] === '' ? 'selected' : '' ?>>
                Select year
              </option>
              <?php for ($i = 1; $i <= 5; $i++): ?>
              <option value="<?= $i ?>" <?= $lastSearch['Year'] === (string)$i ? 'selected' : '' ?>>
                Year <?= $i ?>
              </option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit" name="search" id="exam-reg-search-btn">
            <i class="fas fa-search me-2"></i>Search
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Registration form (shown only after a successful search) ─────────── -->
  <?php if ($searchPerformed && !empty($courses)): ?>
  <div class="data-table-card">
    <div class="card-header">
      <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Register for Exams</h5>
    </div>
    <div class="card-body">
      <form action="examReg.php" method="post" class="row g-3" novalidate>
        <?php Csrf::field(); ?>

        <!--
          Hidden fields carry the validated Sid/semester/Year from the search.
          The controller re-validates these on submission so tampering is caught
          server-side; they are not trusted blindly.
        -->
        <input type="hidden" name="Sid"      value="<?= e($lastSearch['Sid']) ?>">
        <input type="hidden" name="semester" value="<?= e($lastSearch['semester']) ?>">
        <input type="hidden" name="Year"     value="<?= e($lastSearch['Year']) ?>">

        <!-- Course selection -->
        <div class="col-md-12">
          <label for="course_code" class="form-label">Select Courses for External Assessment</label>
          <select class="form-select" name="course_code[]" id="course_code"
                  multiple required size="<?= min(count($courses), 8) ?>"
                  multiselect-search="true" multiselect-select-all="true">
            <?php foreach ($courses as $c): ?>
            <option value="<?= e($c->course_code) ?>">
              <?= e($c->course_code . ' - ' . ($c->assessment_label ?? 'External Exam')) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Hold Ctrl / Cmd to select multiple courses.</div>
        </div>

        <input type="hidden" name="examType" value="External Assessment">

        <div class="col-md-12">
          <div class="alert alert-info mb-0">
            <i class="fas fa-info-circle me-2"></i>
            The portal assigns the assessment type automatically: End of Semester, End of Term, or Short Course Test.
          </div>
        </div>

        <div class="col-12">
          <button class="btn btn-success w-100" type="submit" name="register">
            <i class="fas fa-save me-2"></i>Register for Exams
          </button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /.container-fluid -->

<script src="js/student_lookup.js"></script>
<script>
wucBindStudentLookup({ inputId: 'Sid', msgId: 'student-lookup-msg', submitSelector: '#exam-reg-search-btn' });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

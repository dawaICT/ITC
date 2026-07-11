<?php
require "includes/admin.php";
error_reporting(0);

if(!empty($_POST)){
      if(isset($_POST["course_code"], $_POST["staff_id"])) {

        $course_code = trim($_POST["course_code"]);
        $staff_id = trim($_POST["staff_id"]);

        $checkStmt = $db->prepare("SELECT course_code FROM course_lecturer WHERE course_code = ? AND staff_id = ? LIMIT 1");
        $index = null;
        if ($checkStmt) {
            $checkStmt->bind_param("ss", $course_code, $staff_id);
            $checkStmt->execute();
            $checkStmt->bind_result($index);
            $checkStmt->fetch();
            $checkStmt->close();
        }

        if (isset($index)) {
            echo"<script>alert('Failed! Course Module already assigned to the selected lecturer!')</script>";
            echo"<script>window.open('courses.php','_self')</script>";

              }

        else  if (!empty($course_code) && !empty($staff_id)) {
            $insert = $db->prepare("INSERT INTO  course_lecturer (course_code, staff_id) VALUE(?,?)");
            $insert ->bind_param("ss", $course_code, $staff_id);

            if ($insert->execute()) {
              echo "<script>alert('Lecturer assigned course module successfully!')</script>";
              echo"<script>window.open('courses.php','_self')</script>";
              }
            }
            else {
              echo "<script>alert('Process failed!')</script>";
              echo"<script>window.open('courses.php','_self')</script>";

            }

        }
          }

$page_title = 'Assign Course to Lecturer';
require 'includes/header.php';
$records = [];
$records1 = [];
if($results = $db->query("SELECT * FROM courses ORDER BY course_name")) {
  while($rows = $results->fetch_object()){ $records[] = $rows; }
  $results->free();
}
if($results1 = $db->query("SELECT * FROM staff ORDER BY Lname, Fname")) {
  while($row = $results1->fetch_object()){ $records1[] = $row; }
  $results1->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
  <!-- Page Header -->
  <div class="page-header mb-4 mt-2">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <h5 class="page-title mb-0"><i class="fas fa-link me-2 text-primary"></i>Lecturer Module Assignment</h5>
        <p class="page-subtitle mb-0">Link academic courses to faculty members for teaching and management</p>
      </div>
      <div class="header-actions">
        <a href="courses.php" class="btn btn-outline-secondary shadow-sm">
          <i class="fas fa-book me-1"></i>View Modules
        </a>
      </div>
    </div>
  </div>

  <div class="row justify-content-center">
    <div class="col-lg-6 col-md-8">
      <div class="stat-card p-0 border-0 bg-white overflow-hidden mb-5">
        <div class="card-header bg-white py-3 px-4 border-bottom">
          <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-plus-circle me-2 text-primary"></i>New Assignment</h6>
        </div>
        <div class="card-body p-4">
          <form action="assign_course_lecturer.php" method="post" class="needs-validation" novalidate>
            <div class="mb-4">
              <label for="course_code" class="form-label fw-bold small text-muted text-uppercase">Academic Module</label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="fas fa-book-open"></i></span>
                <select class="form-select border-start-0" name="course_code" id="course_code" required>
                  <option disabled selected value="">Choose a course...</option>
                  <?php foreach($records as $r): ?>
                    <option value="<?php echo htmlspecialchars($r->course_code); ?>"><?php echo htmlspecialchars($r->course_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="invalid-feedback">Please select a course to assign.</div>
            </div>

            <div class="mb-4">
              <label for="staff_id" class="form-label fw-bold small text-muted text-uppercase">Faculty Member</label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="fas fa-chalkboard-teacher"></i></span>
                <select class="form-select border-start-0" name="staff_id" id="staff_id" required>
                  <option disabled selected value="">Select lecturer...</option>
                  <?php foreach($records1 as $r): ?>
                    <option value="<?php echo htmlspecialchars($r->staff_id); ?>"><?php echo htmlspecialchars($r->title.' '.$r->Fname.' '.$r->Lname); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="invalid-feedback">Please select a lecturer for the module.</div>
            </div>

            <div class="alert alert-info py-2 small mb-4">
              <i class="fas fa-info-circle me-1"></i> Assigned lecturers will gain access to upload marks and manage student results for this module.
            </div>

            <div class="d-grid mt-4">
              <button class="btn btn-primary btn-lg fw-bold shadow-sm" type="submit">
                <i class="fas fa-check-circle me-2"></i>Finalize Assignment
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var forms = document.querySelectorAll('.needs-validation');
  Array.prototype.slice.call(forms).forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php require 'includes/footer.php'; ?>

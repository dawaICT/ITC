<?php
require 'includes/nav.php';

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

if(!empty($_POST)){
      if(isset($_POST["course_code"], $_POST["staff_id"])) {

        $course_code = trim($_POST["course_code"]);
        $staff_id = trim($_POST["staff_id"]);

        $check = $db->prepare("SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ? LIMIT 1");
        $check->bind_param('ss', $course_code, $staff_id);
        $index = null;
        if ($check->execute()) {
          $res = $check->get_result();
          if ($res->num_rows) { $index = 1; }
        }
        $check->close();

        if (isset($index)) {
            echo"<script>alert('Failed! This course has already been assigned to the lecturer')</script>";
            echo"<script>window.open('courses.php','_self')</script>";
        } else if (!empty($course_code) && !empty($staff_id)) {
            $insert = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id) VALUE(?,?)");
            $insert ->bind_param("ss", $course_code, $staff_id);

            if ($insert->execute()) {
              echo "<script>alert('Course assigned successfully')</script>";
              echo"<script>window.open('courses.php','_self')</script>";
            } else {
              echo "<script>alert('Process failed!')</script>";
              echo"<script>window.open('courses.php','_self')</script>";
              }
        } else {
              echo "<script>alert('Process failed!')</script>";
              echo"<script>window.open('courses.php','_self')</script>";
        }

      }
}
?>

<div class="container-fluid px-4 py-4 portal-dashboard">
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="data-table-card">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>Assign Course to Lecturer</h5>
          </div>
        </div>
        <div class="card-body">
          <form action="assign_course_lecturer.php" method="post" class="row g-3 needs-validation" novalidate>
            <div class="col-12">
              <label for="course_code" class="form-label">Course code</label>
              <select class="form-select" name="course_code" id="course_code" required>
                <option disabled selected value="">Select course</option>
                  <?php
                $records = [];
                    if($results = $db->query("SELECT * FROM courses")) {
                  while($row = $results->fetch_object()) { $records[] = $row; }
                            $results->free();
                          }
                foreach($records as $r): ?>
                  <option value="<?php echo htmlspecialchars($r->course_code ?? $r->Course_Code); ?>"><?php echo htmlspecialchars($r->course_name); ?></option>
                <?php endforeach; ?>
                </select> 
              <div class="invalid-feedback">Please select a course</div>
                </div>

            <div class="col-12">
              <label for="staff_id" class="form-label">Lecturer ID</label>
              <select class="form-select" name="staff_id" id="staff_id" required>
                <option disabled selected value="">Assign lecturer</option>
                  <?php
                $records1 = [];
                    if($results1 = $db->query("SELECT * FROM staff")) {
                  while($row = $results1->fetch_object()) { $records1[] = $row; }
                            $results1->free();
                          }
                foreach($records1 as $r): ?>
                  <option value="<?php echo htmlspecialchars($r->staff_id); ?>"><?php echo htmlspecialchars($r->title.' '.$r->Fname.' '.$r->Lname); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback">Please select a lecturer</div>
            </div>

            <div class="col-12">
              <button class="btn btn-primary" type="submit">Assign</button>
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

<?php
require 'includes/admin.php';
error_reporting(0);

$page_title = 'Search Student for Assessments Upload';
require 'includes/header.php';
?>

<div class="container-fluid px-4 portal-dashboard">
  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
          <h5 class="mb-0"><i class="fas fa-search me-2"></i>Upload Student Assessment Results</h5>
        </div>
        <div class="card-body">
          <form role="form" method="GET" action="upload_assessments.php" class="row g-3 needs-validation" novalidate>
            <div class="col-12">
              <label for="SID" class="form-label">Enter student #</label>
              <input type="text" class="form-control" name="SID" id="SID" placeholder="Enter student number" required>
              <div class="invalid-feedback">Student number is required</div>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-search me-1"></i> Search
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

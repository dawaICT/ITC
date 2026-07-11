<?php
require "includes/nav.php";
require_once dirname(__DIR__) . '/includes/grading_helpers.php'; // wuc_result_* + assessment_weighting_total
$records = [];
$student_info = null;
$number = 1;

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

?>
<div class="container-fluid px-4 py-4 portal-dashboard">
		<div class="dashboard-header admin-section mb-3">
			<h3 class="dashboard-title mb-0"><i class="fas fa-poll me-2"></i>Student Results</h3>
		</div>

		<?php
						if (isset($_GET['submit'])) {
							$Sid = trim((string)($_GET['Sid'] ?? ''));
							$Year = (int)($_GET['Year'] ?? 0);

								$sql = "SELECT e.Sid, s.SID, s.Fname, s.Lname, s.program,
                           e.Course_Code, c.course_name, e.Total_CA, e.Exam AS Total_marks, e.Year
                    FROM semester_assessment e
                                  INNER JOIN courses c ON e.Course_Code COLLATE utf8mb4_general_ci = c.course_code COLLATE utf8mb4_general_ci
                                  INNER JOIN students s ON s.SID COLLATE utf8mb4_general_ci = e.Sid COLLATE utf8mb4_general_ci
                                  WHERE e.Sid COLLATE utf8mb4_general_ci = ? AND e.Year = ?";
                if($stmt = $db->prepare($sql)) {
                  $stmt->bind_param('si', $Sid, $Year);
                  $stmt->execute();
                  $results = $stmt->get_result();
									while($row = $results->fetch_object()){
										$records[] = $row;
									}
                  $student_info = $records[0] ?? null;
                  $stmt->close();
							}
						}

          ?>

          <div id="print" class="data-table-card">
            <div class="card-header">
              <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Result Transcript</h5>
              </div>
            </div>
            <div class="card-body">
              <div class="text-center mb-3"><img src="images/LOGO2.jpeg" alt="ITC Logo" style="height:72px;width:auto;max-width:100%"></div>
              <h4 class="text-center">Ministry of Technology and Science</h4>
              <h4 class="text-center">The University Name</h4>
              <h4 class="text-center mb-4">Result Transcript</h4>

              <div class="table-responsive">
                <table class="table table-hover align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>No.</th>
                      <th>Course code</th>
                      <th>Course name</th>
                      <th>Grades</th>
                      <th>Session/Year</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    foreach($records as $r) {
                  ?>
                    <tr>
                      <td><?php echo $number++; ?>.</td>
                      <td><?php echo htmlspecialchars($r->Course_Code ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($r->course_name ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td>
                        <?php
                          // Show a grade only when the student sat the final exam;
                          // a CA-only row (Exam NULL) shows NE, not a bogus Fail.
                          if (!wuc_result_exam_written($r->Total_marks)) {
                              echo WUC_RESULT_NOT_EXAMINED;
                          } else {
                              $Total_Grade = assessment_weighting_total($db, (string)$r->Sid, $r->Total_CA, $r->Total_marks);
                              $gradeLetter = wuc_result_grade((float)$Total_Grade);
                              echo htmlspecialchars($gradeLetter . " — " . wuc_result_remark($gradeLetter), ENT_QUOTES, "UTF-8");
                          }
                        ?>
                      </td>
                      <td><?php echo htmlspecialchars($r->Year ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                  <?php	
                    }  
                  ?>
                  <?php if (empty($records)): ?>
                    <tr><td colspan="5" class="text-center">No results found.</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <hr/>
              <h5><strong>SID:</strong> <?php echo htmlspecialchars($student_info->SID ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
              <h5><strong>Student Name:</strong> <?php echo htmlspecialchars(trim(($student_info->Fname ?? '') . ' ' . ($student_info->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></h5>
              <h5><strong>Program of Study:</strong> <?php echo htmlspecialchars($student_info->program ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
              <p class="mt-3"><i><strong>Note:</strong> This is a result transcript generated from the official college online results portal.</i></p>
            </div>
          </div>
          <div class="text-end mt-3">
            <button class="btn btn-success" onClick="printContent('print')"><i class="fas fa-print me-1"></i> Print Statement</button>
          </div>
</div>
<script>
  function printContent(print) {
    var source = document.getElementById(print);
      if (!source) return;
      if (window.wucPrintElement) {
        window.wucPrintElement(source, document.title);
        return;
      }
      var printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) {
      window.print();
      return;
    }
    var styles = Array.prototype.map.call(
      document.querySelectorAll('link[rel="stylesheet"], style'),
      function (node) { return node.outerHTML; }
    ).join('\n');
    printWindow.document.open();
    printWindow.document.write('<!DOCTYPE html><html><head><title>' + document.title + '</title>' + styles + '<style>@media print{button,.d-print-none,.no-print{display:none!important}}body{background:#fff}</style></head><body>' + source.innerHTML + '<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};};<\/script></body></html>');
    printWindow.document.close();
  }
</script>
<?php require "includes/footer.php"; ?>


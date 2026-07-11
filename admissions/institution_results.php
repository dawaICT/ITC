<?php
require "includes/nav.php";
$records = [];
$report_info = null;
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
			<h3 class="dashboard-title mb-0"><i class="fas fa-university me-2"></i>Institution Results</h3>
		</div>

		<?php
						if (isset($_POST['submit'])) {
							$Year = (int)($_POST['Year'] ?? 0);
          $program = trim((string)($_POST['program'] ?? ''));

								$sql = "SELECT e.Sid, s.Fname, s.Lname, e.Course_Code, c.course_name,
                           sa.Total_CA, e.Total_marks, e.Year, p.program_name
                    FROM exams e
                    INNER JOIN courses c ON c.course_code = e.Course_Code
                    INNER JOIN semester_assessment sa
                      ON e.Course_Code = sa.Course_Code
                     AND e.Sid = sa.Sid
                     AND e.Year = sa.Year
                    INNER JOIN students s ON s.SID = e.Sid
                    INNER JOIN student_program sp ON s.SID = sp.Sid
                    INNER JOIN programs p ON sp.program_code = p.program_code
									WHERE e.Year = ? AND sp.program_code = ?
                    ORDER BY s.Lname, s.Fname, e.Course_Code";
                if($stmt = $db->prepare($sql)) {
                  $stmt->bind_param('is', $Year, $program);
                  $stmt->execute();
                  $results = $stmt->get_result();
									while($row = $results->fetch_object()){
										$records[] = $row;
									}
                  $report_info = $records[0] ?? null;
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
                      <th>SID</th>
                      <th>Stude Name</th>
                      <th>Course code</th>
                      <th>Course name</th>
                      <th>Grades</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    foreach($records as $r) {
                  ?>
                    <tr>
                      <td><?php echo $number++; ?>.</td>
                      <td><?php echo htmlspecialchars($r->Sid ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars(trim(($r->Fname ?? '') . ' ' . ($r->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($r->Course_Code ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($r->course_name ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td>
                        <?php
                          $Total_Grade = $r->Total_CA + $r->Total_marks;
                          if ($Total_Grade >= 90) {
                            echo "A+";
                          } elseif ($Total_Grade >= 85){
                            echo "A";
                          } elseif ($Total_Grade >= 75){
                            echo "B+";
                          } elseif ($Total_Grade >= 65){
                            echo "B";
                          } elseif ($Total_Grade >= 55){
                            echo "C+";
                          } elseif ($Total_Grade >= 50){
                            echo "C";
                          } else {
                            echo "D";
                          }
                        ?>
                      </td> 
                    </tr>
                  <?php	
                    }  
                  ?>
                  <?php if (empty($records)): ?>
                    <tr><td colspan="6" class="text-center">No results found.</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <hr/>
              <h5><strong>Program of Study:</strong> <?php echo htmlspecialchars($report_info->program_name ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
              <h5><strong>Exam Year:</strong> <?php echo htmlspecialchars($report_info->Year ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
              <h5><strong>Year of Exams:</strong> <?php echo htmlspecialchars($report_info->Year ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
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


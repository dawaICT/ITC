<?php
include "includes/admin.php";
require_once __DIR__ . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/assessment_weighting_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';
error_reporting(0);
$records = [];
$student_info = null;
?>

<link rel="stylesheet" href="css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title"><i class="fas fa-clipboard-list me-2"></i>Student Results</h1>
        <p class="text-muted mb-0">View results by student and year</p>
      </div>
      <div class="col-auto">
        <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</button>
      </div>
    </div>
  </div>

    					<?php
    						if (isset($_GET['submit'])) {
    							$Sid = trim($_GET['Sid'] ?? '');
    							$Year = (int)($_GET['Year'] ?? 0);
    							$number = 1;

                                if ($Sid !== '' && $Year > 0) {
                                    $sql = "SELECT sa.Sid, students.SID, students.Fname, students.Lname, students.program,
                                                   sa.Course_Code, courses.course_name, sa.Total_CA,
                                                   sa.Exam AS Exam_marks, sa.Year, sa.status
                                              FROM semester_assessment sa
                                              INNER JOIN courses ON sa.Course_Code COLLATE utf8mb4_general_ci = courses.course_code COLLATE utf8mb4_general_ci
                                              INNER JOIN students ON students.SID COLLATE utf8mb4_general_ci = sa.Sid COLLATE utf8mb4_general_ci
                                             WHERE sa.Sid COLLATE utf8mb4_general_ci = ? AND sa.Year = ?
                                             ORDER BY sa.Course_Code";
                                    if ($stmt = $db->prepare($sql)) {
                                        $yearStr = (string)$Year;
                                        $stmt->bind_param('ss', $Sid, $yearStr);
                                        $stmt->execute();
                                        $results = $stmt->get_result();
                                        while($row = $results->fetch_object()){
                                            $records[] = $row;
                                        }
                                        $student_info = $records[0] ?? null;
                                        $stmt->close();
                                    }
                                }
    							}

              ?>

              <!--student result table starts here -->
              <div id="print" class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-table me-2"></i>Results</h5>
                        <button class="btn btn-sm btn-secondary" onClick="printContent('print')"><i class="fas fa-print me-1"></i>Print</button>
                    </div>
                </div>
                <div class="card-body">
                        <div class="table-responsive">
                        <table class="table table-hover align-middle">
                              <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Course code</th>
                                    <th>Course name</th>
                                    <th>Grade</th>
                                    <th>Session/Year</th>
                                </tr>
                              </thead>
                              <tbody>
                          <?php
      						foreach($records as $r) {
      					?>

      					  <tr>
                                      <td><?php echo $number++; ?>.</td>
                                      <td><?php echo htmlspecialchars((string)$r->Course_Code, ENT_QUOTES, 'UTF-8'); ?></td>
                                      <td><?php echo htmlspecialchars((string)$r->course_name, ENT_QUOTES, 'UTF-8'); ?></td>
                                      <td>
                                        <?php

                                       //echo 'Exam'.$r->Total_marks.',';
                                        //echo 'CA'.$r->Total_CA.','; 
                                        $Total_Grade = assessment_weighting_total($db, (string)$r->Sid, $r->Total_CA, ($r->Exam_marks ?? 0));
                                        $gradeLetter = wuc_result_grade((float)$Total_Grade);
                                        echo htmlspecialchars($gradeLetter . ' — ' . wuc_result_remark($gradeLetter), ENT_QUOTES, 'UTF-8');

                                        ?>
                                      <td><?php echo htmlspecialchars((string)$r->Year, ENT_QUOTES, 'UTF-8'); ?></td>
                                      </td> 
                                    </tr>

            					<?php	
            						}  
            					?>
                                <?php if (empty($records)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No results found.</td></tr>
                                <?php endif; ?>
            				</tbody>
                    <!--College information start -->
                    <h4 class="text-center"><strong>Ministry of Technology and Science</strong></h4>
                    <h4 class="text-center">The University Name</h4>
                    <h4 class="text-center">Result Transcript</h4>
                    <!--College information end -->

                    <!--start student information on the transcript -->
                    <hr>
                      <h6><strong>SID:</strong> <?php echo htmlspecialchars((string)($student_info->SID ?? ''), ENT_QUOTES, 'UTF-8'); ?></h6>
                      <h6><strong>Student Name:</strong> <?php echo htmlspecialchars(trim((string)($student_info->Fname ?? '') . ' ' . (string)($student_info->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></h6>
                      <h6><strong>Program of Study:</strong> <?php echo htmlspecialchars((string)($student_info->program ?? ''), ENT_QUOTES, 'UTF-8'); ?></h6>
                    <hr>
                    <!--ending student information on the transcript -->
                   </table><br><!--student result table ends -->
                   <p class="mt-3"><i><strong>Note:</strong> This is a result transcript generated from the official college online results portal.</i></p>
                </div>
              </div>
        </div>
    </div>

  <script>
    function printContent(print) {
      const source = document.getElementById(print);
      if (!source) return;
      if (window.wucPrintElement) {
        window.wucPrintElement(source, document.title);
        return;
      }
      const printWindow = window.open('', '_blank', 'width=900,height=700');
      if (!printWindow) {
        window.print();
        return;
      }
      const styles = Array.prototype.map.call(
        document.querySelectorAll('link[rel="stylesheet"], style'),
        function (node) { return node.outerHTML; }
      ).join('\n');
      printWindow.document.open();
      printWindow.document.write('<!DOCTYPE html><html><head><title>' + document.title + '</title>' + styles + '<style>@media print{button,.d-print-none,.no-print{display:none!important}}body{background:#fff}</style></head><body>' + source.innerHTML + '<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};};<\/script></body></html>');
      printWindow.document.close();
    }
  </script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>


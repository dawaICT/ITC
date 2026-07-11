<?php
include "includes/admin.php";
error_reporting(0);
$records = [];
$student_info = null;
$number = 1;

?>
<!DOCTYPE html>
<html>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<meta charset="UTF-8">
		<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<link rel="stylesheet" type="text/css" href="css_main/admin.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">

<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-10 w3-animate-right w3-border">

    					<?php
    						if (isset($_GET['view'])){
    							$view = trim((string)($_GET['view'] ?? ''));

    								$sql = "SELECT e.*, c.course_name, sa.Total_CA, s.SID, s.Fname, s.Lname
                      FROM exams e
                      INNER JOIN courses c ON e.Course_Code = c.course_code
                      INNER JOIN semester_assessment sa
                         ON sa.Course_Code = e.Course_Code
                        AND sa.Sid = e.Sid
                        AND sa.Year = e.Year
                      INNER JOIN students s ON s.SID = e.Sid
    									WHERE e.Sid = ?
                      ORDER BY e.Year, e.semester, e.Course_Code";
    								if($stmt = $db->prepare($sql)) {
                      $stmt->bind_param('s', $view);
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

              <!--student result table starts here -->
              <div id="print">
      					<table class="table table-hover align-middle">
                              <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Course code</th>
                                    <th>Course name</th>
                                    <th class="w3-left">Grades</th>
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
                                      <td class="w3-left">
                                        <?php

                                        $Total_Grade = $r->Total_CA + $r->Total_marks;

                                        if ($Total_Grade >= 90) {
                                          echo "A+";
                                        } 
                                          elseif ($Total_Grade >= 85){
                                            echo "A";

                                        } elseif ($Total_Grade >= 75){
                                            echo "B+";

                                        } elseif ($Total_Grade >= 65){
                                            echo "B";

                                        } elseif ($Total_Grade >= 55){
                                            echo "C+";

                                        } elseif ($Total_Grade >= 50){
                                            echo "C";

                                        }
                                        else {
                                          echo "D";
                                        }

                                        ?>
                                      </td> 
                                    </tr>

            					<?php	
            						}  
            					?>
                    <?php if (empty($records)): ?>
                      <tr><td colspan="4" class="text-center">No exam records found.</td></tr>
                    <?php endif; ?>
            				</tbody>
                    <!--College information start -->
                    <h4 class="w3-center"><strong>Ministry of Technology and Science</strong></h4>
                    <h4 class="w3-center">Zambia Web for Educational Information and Research (ZWEIR)</h4>
                    <h4 class="w3-center">Result Transcript</h4>
                    <!--College information end -->

                    <!--start student information on the transcript -->
                    <hr>
                      <h5><strong>SID:</strong><?php echo htmlspecialchars($student_info->SID ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
                      <h5><strong>Student Name:</strong> <?php echo htmlspecialchars(trim(($student_info->Fname ?? '') . ' ' . ($student_info->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></h5>
                      <h5><strong>Semester:</strong> <?php echo htmlspecialchars($student_info->semester ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
                      <h5><strong>Year of Exams:</strong><?php echo htmlspecialchars($student_info->Year ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
                    <hr>
                    <!--ending student information on the transcript -->
                   </table><br><!--student result table ends -->
                   <p><i><strong>Note:</strong>This is a result transcript generated from the offical college online results portal.</i></p>
               </div>
             </div>
             <button class="w3-btn w3-green w3-right col-sm-2" onClick="printContent('print')"><strong><span class="glyphicon glyphicon-print"></span> Print Statement</strong></button>
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
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>

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
    						if (isset($_GET['submit'])) {
    							$Sid = trim((string)($_GET['Sid'] ?? ''));
    							$Year = (int)($_GET['Year'] ?? 0);

    								$sql = "SELECT s.SID, s.Fname, s.Lname, sa.*, p.program_name
                      FROM students s
                      INNER JOIN semester_assessment sa ON s.SID = sa.Sid
                      INNER JOIN student_program sp ON sa.Sid = sp.Sid
                      INNER JOIN programs p ON sp.program_code = p.program_code
    									WHERE sa.Sid = ? AND sa.Year = ?
                      ORDER BY sa.Course_Code";
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

              <!--student result table starts here -->
              <div id="print">
      					<table class="table table-hover align-middle">
                              <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Course code</th>
                                    <th>S1A1</th>
                                    <th>S1A2</th>
                                    <th>S1T1</th>
                                    <th>S1T2</th>
                                    <th>S2A1</th>
                                    <th>S2A2</th>
                                    <th>S2T1</th>
                                    <th>Total CA's</th>
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
                      <td><?php echo htmlspecialchars($r->S1A1 ?? $r->A1 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($r->S1A2 ?? $r->A2 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($r->S1T1 ?? $r->T1 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($r->S1T2 ?? $r->T2 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($r->S2A1 ?? $r->A3 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($r->S2A2 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($r->S2T1 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><strong><?php echo htmlspecialchars($r->Total_CA ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></td>
                      <td><?php echo htmlspecialchars($r->Year ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>

            					<?php	
            						}  
            					?>
                    <?php if (empty($records)): ?>
                      <tr><td colspan="11" class="text-center">No CA records found.</td></tr>
                    <?php endif; ?>
            				</tbody>
                    <!--College information start -->
                    <h4 class="w3-center"><strong>Ministry of Technology and Science</strong></h4>
                    <h4 class="w3-center">The University Name</h4>
                    <h4 class="w3-center">Student CA's Sheet</h4>
                    <!--College information end -->

                    <!--start student information on the transcript -->
                    <hr>
                      <h5><strong>SID:</strong><?php echo htmlspecialchars($student_info->SID ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
                      <h5><strong>Student Name:</strong> <?php echo htmlspecialchars(trim(($student_info->Fname ?? '') . ' ' . ($student_info->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></h5>
                      <h5><strong>Program of Study:</strong> <?php echo htmlspecialchars($student_info->program_name ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
                    <hr>
                    <!--ending student information on the transcript -->
                   </table><br><!--student result table ends -->
               </div>
             </div>
             <button class="w3-btn w3-green w3-right col-sm-2" onClick="printContent('print')"><strong><span class="glyphicon glyphicon-print"></span> Print Statement</strong></button>
             <!--a href="javascript:javascript:history.go(-1)" class="w3-btn w3-blue">Back</a-->
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

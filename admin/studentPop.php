<?php
require "includes/admin.php";
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>Student Details - ITC</title>
<meta name="viewport" content="width=device-width, initial-scale=1">		<meta charset="UTF-8">   
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.10.2/css/jquery.dataTables.min.css">
<script type="text/javascript" src="https://cdn.datatables.net/1.10.2/js/jquery.dataTables.min.js"></script>
<script src="dist/js/bootstrap.min.js"></script>
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
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
	printWindow.document.write('<!DOCTYPE html><html><head><title>' + document.title + '</title>' + styles + '<style>@media print{button,.d-print-none,.no-print{display:none!important}}body{background:#fff}</style>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head><body>' + source.innerHTML + '<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};};<\/script></body></html>');
	printWindow.document.close();
}
</script>
<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-9 w3-animate-zoom">
				<div class="row">
						<div class="container w3-white w3-card-4">
            <h3>Generate Report<span class="glyphicon glyphicon-chevron-right"></span></h3>
            <hr>
            <form role="form" method="GET" action="studentPop.php" class="form-inline">
									<!-- add class="tcal" to your input field -->
									<div class="w3-row-padding">
										<div class=""><br>
                    <div class="form-group">
                      <label for="program_code">Program of study:</label><br>
                      <select class="form-control" name="program_code" id="program_code">
                        <option disabled selected>Select program</option>
                      <?php

                        if($results = $db->query("SELECT * FROM programs")) {
                                  if($count = $results->num_rows) {

                                  while($row = $results->fetch_object()){

                                    $records[] = $row;
                                }

                                $results->free();
                              }
                            }
                      ?>
                      <?php
                        foreach($records as $r) {
                        ?>
                        <option value="<?php echo ($r->program_code); ?>"><?php echo ($r->program_name); ?></option>

                        <?php 
                        }  
                        ?>
                    </select> 
                    </div>
                    <div class="form-group">
                      <label for="intake">Intake:</label><br>
											<select class="w3-input w3-border form-control"  id="intake" name="intake" required>
											  <option  disabled selected>Select Intake</option>
											  <option>January</option>
                        <option>June</option>
                      </select>
                    </div>

                    <div class="form-group">
                      <label for="mode">Study Mode:</label><br>
                      <select class="w3-input w3-border form-control" name="mode" id="mode">
                          <option  disabled selected>Select Mode</option>
											    <option>Full-Time</option>
											    <option>Part-Time(Evening)</option>
											    <option>Distance</option>
											    <option>Short Course</option>
                      </select>
                    </div>
                    <div class="form-group">
                      <label for="Year">From:</label><br>
                            <select class="w3-input w3-border form-control"  id="Year" name="Year" required>
                                <option disabled selected>Pick year</option>
                                <option>1</option>
                                <option>2</option>
                                <option>3</option>
                                <option>4</option>
                            </select>
                    </div>

                    <div class="form-group"><br>
                      <input type="submit" class="w3-btn w3-round w3-blue" name= "submit" value="submit">
                    </div>
										</div>
									</div>
								</form><br>

            <?php
            if (isset($_GET['submit'])) {

              $program_code = (string)($_GET['program_code'] ?? '');
              $intake = (string)($_GET['intake'] ?? '');
              $mode = (string)($_GET['mode'] ?? '');
              $Year = (int)($_GET['Year'] ?? 0);
              $number = 1;

              // program_levels never existed — the join added nothing but a fatal.
              $stmt = $db->prepare("SELECT * FROM semester_registration
                  INNER JOIN students ON semester_registration.SID = students.SID
                  INNER JOIN student_program ON students.SID = student_program.Sid
                  INNER JOIN programs ON student_program.program_code = programs.program_code
                  WHERE student_program.program_code = ? AND student_program.intake = ?
                    AND student_program.mode = ? AND semester_registration.Year = ?");
              if ($stmt) {
                  $stmt->bind_param('sssi', $program_code, $intake, $mode, $Year);
                  $stmt->execute();
                  $results1 = $stmt->get_result();
                  while ($row = $results1->fetch_object()) {
                      $records_1[] = $row;
                  }
                  $stmt->close();
              }
              }

                  ?> 
                  <div id="print">
                    <h4>Report title:............................................................</h4>  
					        <table class="table table-hover align-middle">
                        <thead class="table-light">
                          <tr>
                            <th>No.</th>
                            <th>Student ID</th>
                              <th>Names</th>
                              <th>Gender</th>
                              <th>Program</th>
                              <th>Level</th>
                              <th>Intake</th>
                              <th>Study mode</th>
                              <th>Year</th>
                          </tr>
                        </thead>
                        <?php
                      foreach($records_1 as $r) {
                    ?>
                        <tbody>
                              <tr>
                                <td><?php echo $number++; ?>.</td>
                                <td><?php echo ($r->Sid); ?></td>
                                <td><?php echo ($r->Fname); ?> <?php echo ($r->Lname); ?></td>
                                <td><?php echo ($r->sex); ?></td>
                                <td><?php echo ($r->program_name); ?></td>
                                <td><?php echo ($r->level); ?></td>
                                <td><?php echo ($r->intake); ?></td>
                                <td><?php echo ($r->mode); ?></td>
                                <td><?php echo ($r->Year); ?></td>

                               </tr>
                          <?php 
                          }  
                          ?>
                        </tbody>
                      </table>
                  </div><br>
                    <button class="w3-btn w3-green w3-center w3-round-large" onClick="printContent('print')">
					<strong><span class="glyphicon glyphicon-print"></span> Print</strong></button>
				</div>
			<div class="col-sm-1"></div>
		</div>
  </div>
  <script>
$(document).ready(function(){
    $('#myTable').dataTable();
});
</script>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>

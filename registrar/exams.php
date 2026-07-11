<?php
require "includes/admin.php";
include_once "upload_exam_results.php";
include_once "publishResults.php";
error_reporting(0);

if (!function_exists('exam_report_h')) {
	function exam_report_h($value): string {
		return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}
}
if (!function_exists('exam_report_grade')) {
	function exam_report_grade(float $total): string {
		require_once dirname(__DIR__) . '/includes/grading_helpers.php';
		return wuc_result_grade($total);
	}
}
if (!function_exists('exam_report_points')) {
	function exam_report_points(float $total): string {
		require_once dirname(__DIR__) . '/includes/grading_helpers.php';
		return number_format(wuc_result_points($total), 2);
	}
}

$records = [];
$selectedSemester = '';
$selectedYear = '';
$number = 1;
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<meta charset="UTF-8">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
</head>
<script>
function printContent(printScript) {
var source = document.getElementById(printScript);
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
<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-9 w3-animate-zoom">
				<div class="row">
					<h3>Examminations</h3>
					<button class="w3-btn w3-round w3-orange" onclick="document.getElementById('PublishExams').style.display='block'">
					<span class="glyphicon glyphicon-send"></span> Publish Final Results</button>
					<button class="w3-btn w3-round w3-green w3-right" onclick="document.getElementById('exams').style.display='block'">
					<span class="glyphicon glyphicon-upload"></span> UPLOAD EXAM RESULTS</button><br>
					<hr>

				</div>
					<br>
				<div class="w3-card-4">
					<header class="w3-container w3-center">
					  <h3>Exam College Results</h3>
					</header>
					<div class="w3-container">
							<form role="form" method="POST" action="exams.php" class="form-inline">
							<div class="form-group">
								<label for="semester">Semester:</label><br>
									<select class="form-control" name="semester" id="semester" require>
									<option selected disabled>Select semester</option>
									<option>1</option>
									<option>2</option>
									</select>
							</div>
							<div class="form-group">
								<label>Choose Year: </label><br>
									<select class="w3-input w3-border col-xs-3 form-control"  id="years" name="Year">
										<option disabled selected>Select exam year</option>
										<option>1</option>
										<option>2</option>
										<option>3</option>
										<option>4</option>
									</select>

							</div>
							<div class="form-group"><br>
								<button class="btn btn-block bg-primary" type="submit" name= "submit">Submit</button>
							</div>
						</form><br>
						<hr>
							<?php
							if (isset($_POST['submit'])) {
								$selectedSemester = trim((string)($_POST['semester'] ?? ''));
								$selectedYear = trim((string)($_POST['Year'] ?? ''));

								if (!in_array($selectedSemester, ['1', '2'], true) || !ctype_digit($selectedYear)) {
									echo '<h4 class="alert alert-danger text-center">Please select a valid semester and year.</h4>';
								} else {
									$sql = "SELECT e.Sid, e.Course_Code, c.course_name,
											       COALESCE(sa.Total_CA, 0) AS Total_CA,
											       COALESCE(e.Total_marks, e.Exam_marks, 0) AS Total_marks,
											       e.Year, e.semester
											FROM exams e
											INNER JOIN students s ON s.SID = e.Sid
											INNER JOIN courses c ON e.Course_Code = c.course_code
											LEFT JOIN semester_assessment sa
											       ON sa.Sid = e.Sid AND sa.Course_Code = e.Course_Code
											WHERE e.semester = ? AND e.Year = ?
											ORDER BY e.Sid, e.Course_Code";
									if ($stmt = $db->prepare($sql)) {
										$stmt->bind_param('ss', $selectedSemester, $selectedYear);
										$stmt->execute();
										$results = $stmt->get_result();
										while ($row = $results->fetch_object()) {
											$records[] = $row;
										}
										$stmt->close();
									}
									if (empty($records)) {
										echo '<h4 class="alert alert-danger text-center">No exam records were found for the selected semester or year.</h4>';
									}
								}
							}

							?>

					<!--student result table starts here -->
					<fieldset>
					<div id="printScript">
						<div class="#">
								<fieldset class="w3-border">
										<table class="table table-hover align-middle">
										<thead class="table-light">
											<tr>
												<th>No.</th>
												<th>Student ID</th>
												<th>Course code</th>
												<th>Course name</th>
												<th class="w3-left">Grades</th>
												<th>Session/Year</th>
												<th>GPA Score</th>
											</tr>
										</thead>
										<tbody>
									<?php
											foreach($records as $r) {
												$totalGrade = (float)($r->Total_CA ?? 0) + (float)($r->Total_marks ?? 0);
										?>

										<tr>
												<td><?php echo $number++; ?>.</td>
												<td><?php echo exam_report_h($r->Sid ?? ''); ?></td>
												<td><?php echo exam_report_h($r->Course_Code ?? ''); ?></td>
												<td><?php echo exam_report_h($r->course_name ?? ''); ?></td>
												<td class="w3-left">
													<?php echo exam_report_h(exam_report_grade($totalGrade)); ?>
													</td>

													<td><?php echo exam_report_h($r->Year ?? ''); ?></td><td class="w3-left">
													<?php echo exam_report_h(exam_report_points($totalGrade)); ?> 
													</td>
												</tr>

											<?php	
												}  
											?>
										</tbody>
									<!--College information start -->
									<h2 class="w3-center"><strong>Industrial training college</strong></h2>
									<div class="w3-center"><img src="../images/itc_logo.png" alt="ITC Logo" style="height:72px;width:auto;max-width:100%"></div>
									<h3 class="w3-center"><strong>Results Book</strong></h3>
									<!--College information end -->

									<!--start student information on the transcript -->
									<hr>
									<h5><strong>SEMESTER:</strong><?php echo exam_report_h($selectedSemester); ?></h5>
									<h5><strong>YEAR:</strong> <?php echo exam_report_h($selectedYear); ?></h5>
									<hr>
									<!--ending student information on the transcript -->
								</table><br><!--student result table ends -->
								<br>
								<h4>GPA AP SCORE SHEET GUIDE</h4>
								<table class="table table-hover align-middle small" style="width:50%">
									<tr>
										<th>GRADE</th>
										<th>PERCENTAGE</th>
										<th>DESCRIPTION</th>
										<th>GPA SCORE POINTS</th>
									</tr>
									<tr>
										<td>A<sup>+</sup></td>
										<td>90 - 100</td>
										<td>1<sup>ST</sup> DISTINCTION</td>
										<td>4.00</td>
									</tr>
									<tr>
										<td>A</td>
										<td>80 - 89</td>
										<td>2<sup>ND</sup> DISTINCTION</td>
										<td>3.74</td>
									</tr>
									<tr>
										<td>B<sup>+</sup></td>
										<td>75 - 79</td>
										<td>3<sup>RD</sup> DISTINCTION</td>
										<td>3.64</td>
									</tr>
									<tr>
										<td>B</td>
										<td>70 - 79</td>
										<td>MERIT</td>
										<td>3.49</td>
									</tr>
									<tr>
										<td>B<sup>-</sup></td>
										<td>65 - 69</td>
										<td>MERIT</td>
										<td>3.25</td>
									</tr>
									<tr>
										<td>C<sup>+</sup></td>
										<td>60 - 64</td>
										<td>CREDIT</td>
										<td>2.99</td>
									</tr>
									<tr>
										<td>C</td>
										<td>50 - 59</td>
										<td>PASS</td>
										<td>2.33</td>
									</tr>
									<tr>
										<td>D</td>
										<td>45 - 49</td>
										<td>PASS</td>
										<td>1.99</td>
									</tr>
									<tr>
										<td>E</td>
										<td>00 - 44</td>
										<td>FAIL</td>
										<td>1.00</td>
									</tr>
									</table>
								</fieldset>
								</div>
						</div>
							</fieldset>
						<button class="w3-btn w3-orange w3-center w3-round-large" onClick="printContent('printScript')">
								<strong><span class="glyphicon glyphicon-print"></span> Print</strong></button><br><br>

                    </div>

				</div>
			</div>
			<div class="col-sm-1"></div>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>

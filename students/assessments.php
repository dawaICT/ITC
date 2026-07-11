<?php

error_reporting(0);

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Assessments</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="/wucportal/css/admin-style.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    <div class="content-wrapper">
	<div class="container">
		<div class="row">
			<div class="col-sm-1"></div>
			<div class="col-sm-8">
			<h4>Trying Assessments</h4>
				<div class="row"><br>

					<form role="form" method="POST" action="assessments.php">
						<!-- add class="tcal" to your input field -->
						<div class="form-group">
							<label for="years">Choose Year: </label>
								<select class="w3-input w3-border col-xs-3 form-control"  id="years" name="Year">
									<option disabled selected>Select year</option>
								</select>
							    <script type="text/javascript"
							        src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"> 
							    </script>
							    <script type="text/javascript">
							    let start_years = 2000;
							    let end_years = new Date().getFullYear();
							    for (i = end_years; i > start_years; i--)
							    {
							      $('#years').append($('<option />').val(i).html(i));
							    }
							    </script>

						</div><br>
						<div class="form-group">
							<button class="btn btn-sm  btn-block w3-btn w3-green" type="submit" name= "submit">Search</button>
						</div>
				</form><br>
				<?php
				$records = [];
				$number = 1;
				if (isset($_POST['submit'])) {
					$Year = (string)($_POST['Year'] ?? '');
					$sessionSid = (string)($_SESSION['Sid'] ?? '');

					// CA marks live in semester_assessment (via the `exams`
					// compatibility view); students only see published results.
					if ($sessionSid !== '' && $Year !== '' &&
						($stmt = $db->prepare("SELECT Course_Code, A1, A2, A3, T1, T2, Total_CA, semester, `Year`
							FROM exams
							WHERE Sid = ? AND `Year` = ? AND LOWER(status) = 'published'
							ORDER BY semester, Course_Code"))) {
						$stmt->bind_param('ss', $sessionSid, $Year);
						$stmt->execute();
						$results = $stmt->get_result();
						while ($row = $results->fetch_object()) {
							$records[] = $row;
						}
						$stmt->close();
					}

					if (!empty($records)) {
						echo '<h4 class="alert alert-success text-center">Currently uploaded CA\'s.</h4>';
					} else {
						echo '<h4 class="alert alert-danger text-center">No published CA marks found for that year.</h4>';
					}
				}
  				?>
				<!--student result table starts here -->
						<table class="table table-hover align-middle">
				          <thead class="table-light">
				            <tr>
				                <th>No.</th>
				                <th>Course code</th>
				                <th>A1</th>
				                <th>A2</th>
				                <th>A3</th>
				                <th>T1</th>
				                <th>T2</th>
				                <th>Total CA's</th>
				                <th>Sem/Term</th>
				                <th>Year</th>
				            </tr>
				          </thead>
				          <tbody>
				      <?php
							foreach($records as $r) {
						?>

						  <tr>
				                  <td><?php echo $number++; ?>.</td>
				                  <td><?php echo htmlspecialchars((string)$r->Course_Code, ENT_QUOTES, 'UTF-8'); ?></td>
				                  <td><?php echo htmlspecialchars((string)($r->A1 ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
				                  <td><?php echo htmlspecialchars((string)($r->A2 ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
				                  <td><?php echo htmlspecialchars((string)($r->A3 ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
				                  <td><?php echo htmlspecialchars((string)($r->T1 ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
				                  <td><?php echo htmlspecialchars((string)($r->T2 ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
				                  <td><strong><?php echo htmlspecialchars((string)($r->Total_CA ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></td>
				                  <td><?php echo htmlspecialchars((string)$r->semester, ENT_QUOTES, 'UTF-8'); ?></td>
				                  <td><?php echo htmlspecialchars((string)$r->Year, ENT_QUOTES, 'UTF-8'); ?></td>
				                </tr>

							<?php
								}
							?>
						</tbody>
				</table><br><!--student result table ends -->
				</div>
			</div>
			<div class="col-sm-2"></div>
		</div>
    </div>
    </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

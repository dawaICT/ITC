<?php
require "../db/connect.php";
require_once __DIR__ . '/../includes/report_print.php';
date_default_timezone_set('Africa/Harare');
error_reporting(0);
$records = [];
$error_message = '';

?>
<!DOCTYPE html>
<html>
<head>
<title>Payment Receipt</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<?php render_report_print_styles(); ?>
<?php render_report_print_script(); ?>
<?php render_wuc_a4_print_script(); ?>
<style>
/* The receipt body IS the report — show the print header on screen too. */
.report-print-header { display: block; margin: 0 auto 14px; max-width: 760px; }
@media print {
    body { margin: 0; }
    .no-print { display: none !important; }
}
</style>
</head>
<body onload="wucPrintSinglePage()" class="single-page-document">
	<div class="container my-3">
		<div class="row">
				<?php
				 	if (isset($_GET['view']) && trim((string)$_GET['view']) !== '') {
						$view = trim((string)$_GET['view']);

						 if($stmt = $db->prepare("SELECT
                                p.student_id AS Sid,
                                p.student_id AS student_sid,
                                p.description AS narration,
                                p.description,
                                p.semester,
                                p.academic_year AS Year,
                                p.amount AS amount_paid,
                                p.method AS channel,
                                p.payment_date AS dte_time,
                                p.posted_by AS paymentReceivedBy,
                                s.Fname,
                                s.Lname,
                                sp.program_code
                            FROM payments p
                            INNER JOIN students s
                                ON s.SID = CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_general_ci
                            LEFT JOIN student_program sp
                                ON sp.Sid COLLATE utf8mb4_unicode_ci = CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                            WHERE p.receipt_no = ? AND p.receipt_no != ''")) {
                                $stmt->bind_param('s', $view);
                                $stmt->execute();
                                $results = $stmt->get_result();
								if($count = $results->num_rows) {

								while($row = $results->fetch_object()){

										$records[] = $row;
									}

									$stmt->close();
								}
								else {

								echo '<h4 class="alert alert-danger text-center">' . "Invoices do not have receipts generated!" . '</h4>';
								die();
							}
						}
					}
					else {
						$error_message = 'No receipt reference provided.';
					}

					$first_record = !empty($records) ? $records[0] : null;
					if ($error_message !== '') {
						echo '<h4 class="alert alert-danger text-center">' . htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') . '</h4>';
						exit;
					}
					render_report_print_header(
						'Payment Receipt',
						$first_record ? trim(($first_record->Fname ?? '') . ' ' . ($first_record->Lname ?? '')) : '',
						[
							'Reference' => $_GET['view'] ?? '',
							'Student'   => $first_record->student_sid ?? $first_record->Sid ?? '',
							'Program'   => $first_record->program_code ?? '',
						],
						'../images/itc_logo.png'
					);
				  ?>
				  <div id="print">
				  	<div class="p-3 border rounded">
						<table class="table table-bordered table-striped">
							<thead class="table-light">
								<tr>
									<th>Account</th>
									<th>Narration</th>
								    <th>Description</th>
								    <th>Amount</th>
								    <th>Payment channel</th>
								    <th>Date paid</th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach($records as $r) {
									?>
									<tr>
										<td><?php echo htmlspecialchars($r->Sid ?? $r->student_sid ?? ''); ?></td>
										<td><?php echo htmlspecialchars($r->narration ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
										<td>Sem <?php echo htmlspecialchars($r->semester ?? '', ENT_QUOTES, 'UTF-8'); ?>, Yr <?php echo htmlspecialchars($r->Year ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo htmlspecialchars(number_format((float)($r->amount_paid ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo htmlspecialchars($r->channel ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo htmlspecialchars($r->dte_time ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
									  </tr>

								<?php 
								}  
								?>
							</tbody>
						</table>
						<p class="small text-center">******************************************************** Account details ********************************************************</p>
						<p class="border p-2"><strong>Name :</strong>
							<?php echo htmlspecialchars(trim(($first_record->Fname ?? '') . ' ' . ($first_record->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?><br>
				  			<strong>Program :</strong><?php echo htmlspecialchars($first_record->program_code ?? '', ENT_QUOTES, 'UTF-8'); ?>
			   			</p>
						<div class="text-end">
							<strong>
							Signed by: <?php echo htmlspecialchars($first_record->paymentReceivedBy ?? '', ENT_QUOTES, 'UTF-8'); ?>
							</strong><br>
							<p class="small mb-0">
								<strong>
									Print Time: <?php date_default_timezone_set('Africa/harare');
									$date = date('d-m-y h:i:s');
									echo $date;?>
								</strong>
							</p>
						</div>
					</div>
					</div>
		</div>
	</div>
</body>
</html>

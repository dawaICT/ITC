<?php
$page_title = 'Receivables by Age';
require "includes/nav.php";
require_once __DIR__ . '/../includes/report_print.php';
error_reporting(0);

// Ensure utf8mb4 at connection-level to avoid charset/collation issues
if (isset($db) && $db instanceof mysqli) {
	@mysqli_set_charset($db, 'utf8mb4');
}

// Simple file logger for this page
if (!function_exists('ageRecv_log_error')) {
	function ageRecv_log_error(string $message): void {
		$logDir = __DIR__ . '/../logs';
		if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
		$logFile = $logDir . '/error.log';
		@error_log('['.date('Y-m-d H:i:s')."] ageReceivables.php: ".$message.PHP_EOL, 3, $logFile);
	}
}

?>
<?php render_report_print_styles(); ?>
<?php render_report_print_script(); ?>
<?php render_wuc_a4_print_script(); ?>
<div class="container-fluid px-4 portal-dashboard accounts-page age-receivables-page">
	<div class="dashboard-header finance-section mb-4 d-print-none">
		<div class="row align-items-center">
			<div class="col">
				<h1 class="dashboard-title">Receivables with Age Analysis</h1>
				<p class="text-muted mb-0">Aging categories and export</p>
			</div>
		</div>
	</div>

	<?php
	render_report_print_header(
		'Receivables with Age Analysis',
		'Outstanding student balances by aging band',
		[
			'Filter SID' => $_GET['Sid'] ?? 'All students',
		]
	);
	?>

	<div class="row g-4 age-recv-content-row">
		<div class="col-12">
			<div class="data-table-card age-recv-card">
				<div class="card-header d-print-none">
					<h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Report manager | Student balance</h5>
				</div>
				<div class="card-body">
					<div id="print" class="age-recv-print">
						<div class="text-center age-recv-branding d-print-none">
							<h4 class="mb-2"><strong>Woodlands University</strong></h4>
							<img src="images/LOGO2.jpeg" alt="ITC Logo" class="mb-2 img-fluid" style="height:72px;width:auto;max-width:100%">
							<h4><strong>Receivables with Age Analysis</strong></h4>
						</div>
						<div class="age-recv-table-section">
						<?php

						$number = 1;
						$filterSid = isset($_GET['Sid']) ? trim($_GET['Sid']) : '';

						$sql = "SELECT
							i.SID AS Sid,
							GREATEST(SUM(i.amount) - COALESCE(p.total_paid, 0), 0) AS balance,
							MAX(i.date_generated) AS dte_time,
							CASE
								WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) < 10 THEN 'Very Low'
								WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) BETWEEN 10 AND 30 THEN 'Low'
								WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) BETWEEN 31 AND 45 THEN 'Medium'
								WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) BETWEEN 46 AND 60 THEN 'High'
								WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) BETWEEN 61 AND 90 THEN 'Very High'
								ELSE 'Critical'
							END AS comment
						FROM invoices i
						LEFT JOIN (
							SELECT student_id, SUM(amount) AS total_paid
							FROM payments
							WHERE LOWER(status) IN ('completed', 'paid', 'success')
							GROUP BY student_id
						) p ON CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(i.SID USING utf8mb4) COLLATE utf8mb4_general_ci
						WHERE 1=1";

						if ($filterSid !== '') { $sql .= " AND i.SID = ?"; }
						$sql .= " GROUP BY i.SID, p.total_paid HAVING balance > 0 ORDER BY dte_time DESC";

						$stmt = $db->prepare($sql);
						if (!$stmt) {
							ageRecv_log_error('Prepare failed: '.$db->error);
							echo "<div class='alert alert-danger'>Failed to prepare receivables query.</div>";
						} else {
							if ($filterSid !== '') { $stmt->bind_param('s', $filterSid); }
							if (!$stmt->execute()) {
								ageRecv_log_error('Execute failed: '.$stmt->error);
								echo "<div class='alert alert-danger'>Failed to execute receivables query.</div>";
								$result = false;
							} else {
								$result = $stmt->get_result();
							}
						}

					// Display results
					if ($result && $result->num_rows > 0) {
						echo "<div class='table-responsive age-recv-table-wrap'><table id='myTable' class='table table-hover align-middle'>";
						echo "<thead class='table-light'><tr>
								<th>No</th>
								<th>Student ID</th>
								<th>Balance (ZMW)</th>
								<th>Entry Date</th>
								<th>Period (days)</th>
								<th>Default status</th>
							</tr></thead><tbody>";

						$totalBalance = 0.0;

						while ($row = $result->fetch_assoc()) {
							$sid = htmlspecialchars((string)$row['Sid']);
							$bal = (float)$row['balance'];
							$dateVal = htmlspecialchars((string)$row['dte_time']);
							$comment = htmlspecialchars((string)$row['comment']);
							$entryDate = $row['dte_time'];
							$daysSinceLastEntry = is_string($entryDate) && $entryDate !== '' ? floor((time() - strtotime($entryDate)) / (60 * 60 * 24)) : 0;

							echo "<tr>
									<td>" . $number++ . "</td>
									<td>" . $sid . "</td>
									<td>" . number_format($bal, 2) . "</td>
									<td>" . $dateVal . "</td>
									<td>" . (int)$daysSinceLastEntry . "</td>
									<td>" . $comment . "</td>
								</tr>";

							$totalBalance += $bal;
						}

						echo "</tbody></table></div>";

						echo "<div class='age-recv-total mt-3'><strong>Total Balance for All student payments: ZMW " . number_format($totalBalance, 2) . "</strong></div>";

					} else {
						if ($filterSid !== '') {
							ageRecv_log_error('No receivables found for SID: '.$filterSid);
							echo "<div class='alert alert-warning'>No receivables found for Student ID: ".htmlspecialchars($filterSid).".</div>";
						} else {
							echo "<div class='alert alert-info'>No receivables found.</div>";
						}
					}

					if (isset($stmt) && $stmt instanceof mysqli_stmt) { $stmt->close(); }
					?> 
						</div>
					</div>

					<!-- Actions -->
					<form action="export.php" method="post" class="row g-3 age-recv-actions-row d-print-none">
						<div class="col-md-6">
							<button type="submit" class="btn btn-success" name="export_csv">Export to CSV</button>
						</div>
						<div class="col-md-6 text-end">
							<button type="button" id="btnPrintAgeReport" class="btn btn-outline-secondary">Print PDF</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
<script>
$(document).ready(function(){
    // DOM-sourced table: do NOT define columns.data; let DataTables read the DOM
    try {
        $('#myTable').DataTable({
            ordering: true,
            paging: true,
            searching: true,
            autoWidth: false
        });
    } catch(e) {}

    $('#btnPrintAgeReport').on('click', function () {
        document.body.classList.add('single-page-document');
        wucPrintSinglePage();
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

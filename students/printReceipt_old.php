<?php
// Security and session management
session_start();
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';

// Check if user is logged in
if (!isset($_SESSION['Sid'])) {
    header('Location: ../student_login.php');
    exit();
}
$currentStudentId = (string)$_SESSION['Sid'];

// Enable error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Ensure $db is defined
if (!isset($db) || !$db) {
    die('<div class="alert alert-danger">Database connection failed.</div>');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <style>
        .content-wrapper { padding: 1.5rem; }
        .page-title { font-size: 1.5rem; font-weight: 600; color: #1e293b; margin-bottom: 0.25rem; }
        .page-subtitle { color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem; }

        .nav-tabs { border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem; }
        .nav-tabs .nav-link { border: none; color: #64748b; padding: 0.75rem 1rem; font-weight: 500; }
        .nav-tabs .nav-link:hover { color: #3b82f6; border: none; }
        .nav-tabs .nav-link.active { color: #3b82f6; border: none; border-bottom: 2px solid #3b82f6; background: none; }

        .receipt-card { background: white; border-radius: 8px; border: 1px solid #e2e8f0; padding: 2rem; position: relative; overflow: hidden; }
        .receipt-header { text-align: center; margin-bottom: 2rem; }
        .receipt-header img { max-width: 120px; margin-bottom: 1rem; }
        .receipt-header h2 { color: #1e293b; margin: 0; font-weight: 600; }
        .receipt-header p { color: #64748b; margin: 0.5rem 0; }

        .receipt-details { margin-bottom: 2rem; }
        .receipt-details .row { margin-bottom: 1rem; }
        .receipt-details .label { font-weight: 500; color: #475569; }
        .receipt-details .value { color: #1e293b; }

        .receipt-table { width: 100%; margin-bottom: 2rem; }
        .receipt-table th { background: #f8fafc; padding: 0.75rem; text-align: left; font-weight: 500; color: #475569; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; }
        .receipt-table td { padding: 0.75rem; border-bottom: 1px solid #f1f5f9; color: #334155; }

        .receipt-footer { border-top: 1px solid #e2e8f0; padding-top: 1rem; margin-top: 2rem; }
        .receipt-footer .row { align-items: center; }
        .receipt-footer .text-muted { font-size: 0.8rem; color: #94a3b8; }

        .btn-print { background: #059669; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.9rem; }
        .btn-print:hover { background: #047857; color: white; }

        /* Watermark */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 4rem;
            color: rgba(0, 0, 0, 0.05);
            font-weight: bold;
            z-index: 0;
            pointer-events: none;
            user-select: none;
        }

        .receipt-content { position: relative; z-index: 1; }

        @media print {
            .no-print { display: none !important; }
            .watermark { display: block !important; }
            body { background: white !important; }
            .receipt-card { border: none !important; box-shadow: none !important; }
        }

        @media (max-width: 768px) {
            .receipt-card { padding: 1rem; }
            .receipt-table th, .receipt-table td { padding: 0.5rem; font-size: 0.8rem; }
        }
    </style>
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="content-wrapper">
        <h1 class="page-title">Payment Receipt</h1>
        <p class="page-subtitle">View and print your payment receipt</p>

        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <ul class="nav nav-tabs">
                        <li class="nav-item">
                            <a class="nav-link" href="fees.php">Payment Records</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="balanceStatement.php">Balance Statement</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="#">Receipt</a>
                        </li>
                    </ul>	
<body>
	<div class="container">
		<div class="row">
			<div class="col-sm-1"></div>
			<div class="col-sm-10 w3-card-4 w3-animate-zoom in active">
				<!-- Navigation (hidden on print) -->
				<div class="no-print">
					<h3>
						<a href="fees.php" class="w3-btn bg-primary w3-round">
							<i class="fa fa-arrow-left"></i> My Fees
						</a> 
						<span class="glyphicon glyphicon-chevron-right"></span> Payment Receipt
					</h3>
					<p>
						<a class="w3-btn bg-primary w3-round" href="fees.php">
							<i class="fa fa-list"></i> Payment Records
						</a>
						<a class="w3-btn bg-primary w3-round" href="balanceStatement.php">
							<i class="fa fa-file-text"></i> Balance Statement
						</a>
					</p>
					<hr>
				</div>

				<?php
				$records = [];
				$errorMessage = '';

				if (isset($_GET['view']) && !empty($_GET['view'])) {
					$view = $_GET['view'];
					
					// Use prepared statement to prevent SQL injection
					$stmt = $db->prepare("SELECT sp.*, s.Fname, s.Lname, s.SID, 
						stp.program_code, p.program_name 
						FROM student_payments sp
						INNER JOIN students s ON s.SID = sp.SID
						LEFT JOIN student_program stp ON s.SID = stp.Sid
						LEFT JOIN programs p ON stp.program_code = p.program_code
						WHERE sp.reference_number = ? AND sp.reference_number != ''
                          AND s.SID = ?
						LIMIT 1");
					
					if ($stmt) {
						$stmt->bind_param("ss", $view, $currentStudentId);
						$stmt->execute();
						$result = $stmt->get_result();
						
						if ($result->num_rows > 0) {
							while ($row = $result->fetch_object()) {
								$records[] = $row;
							}
						} else {
							$errorMessage = "Receipt not found or invalid reference ID.";
						}
						
						$stmt->close();
					} else {
						error_log('legacy printReceipt query error: ' . $db->error);
						$errorMessage = "Database query error.";
					}
				} else {
					$errorMessage = "No receipt reference provided.";
				}

				if (!empty($errorMessage)) {
					echo '<div class="alert alert-danger text-center">' . htmlspecialchars($errorMessage) . '</div>';
					echo '<div class="text-center no-print"><a href="fees.php" class="btn btn-primary">Return to Fees</a></div>';
					echo '</div></div></div></body></html>';
					exit();
				}
				?>

				<!-- Receipt Content -->
				<div id="print">
					<?php
					// Display receipt for each record (typically just one)
					foreach ($records as $r) {
						// Set timezone for accurate printing
						date_default_timezone_set('Africa/Harare');
						$printDate = date('d-m-Y h:i:s A');
						?>
						
						<!-- Receipt Header -->
						<div class="receipt-header">
							<?php
							// Try multiple possible logo locations
							$logoPath = '';
							$possiblePaths = [
								'images/LOGO2.jpeg',
								'images/logo.jpeg',
								'../images/LOGO2.jpeg',
								'../images/logo.jpeg'
							];
							foreach ($possiblePaths as $path) {
								if (file_exists(__DIR__ . '/' . $path)) {
									$logoPath = $path;
									break;
								}
							}
							if ($logoPath) {
									echo '<img src="' . htmlspecialchars($logoPath) . '" class="receipt-logo" width="120" height="100" alt="ITC Logo">';
							}
							?>
							<h2><strong>Industrial Training Centre</strong></h2>
							<p><strong>Official Payment Receipt</strong></p>
						</div>

						<!-- Account Details -->
						<div class="w3-border w3-padding" style="margin-bottom: 20px;">
							<h4><strong>Student Information</strong></h4>
							<table class="table table-borderless">
								<tr>
									<td><strong>Student ID:</strong></td>
									<td><?php echo htmlspecialchars($r->SID); ?></td>
								</tr>
								<tr>
									<td><strong>Name:</strong></td>
									<td><?php echo htmlspecialchars($r->Fname . ' ' . $r->Lname); ?></td>
								</tr>
								<tr>
									<td><strong>Program:</strong></td>
									<td><?php echo htmlspecialchars(($r->program_name ?? 'N/A') . ' (' . ($r->program_code ?? 'N/A') . ')'); ?></td>
								</tr>
							</table>
						</div>

						<!-- Payment Details -->
						<h4><strong>Payment Details</strong></h4>
						<table class="table table-bordered table-striped">
							<thead class="table-light" style="background-color: #9c27b0; color: white;">
								<tr>
									<th>Narration</th>
									<th>Description</th>
									<th>Amount (ZWL)</th>
									<th>Payment Channel</th>
									<th>Date Paid</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><?php echo htmlspecialchars($r->narration ?? 'N/A'); ?></td>
									<td>Semester <?php echo htmlspecialchars($r->semester ?? 'N/A'); ?>, Year <?php echo htmlspecialchars($r->Year ?? 'N/A'); ?></td>
									<td><strong>$<?php echo number_format($r->amount_paid, 2); ?></strong></td>
									<td><?php echo htmlspecialchars($r->channel ?? 'N/A'); ?></td>
									<td><?php echo htmlspecialchars($r->dte_time ?? 'N/A'); ?></td>
								</tr>
							</tbody>
						</table>

						<!-- Footer -->
						<div style="margin-top: 30px;">
							<div class="row">
								<div class="col-sm-6">
									<p><strong>Received By:</strong> <?php echo htmlspecialchars($r->paymentReceivedBy ?? 'Finance Office'); ?></p>
								</div>
								<div class="col-sm-6 text-right">
									<p class="w3-small">
										<strong>Print Time:</strong> <?php echo $printDate; ?>
									</p>
								</div>
							</div>
							<hr>
							<p class="text-center w3-small" style="color: #666;">
								<em>This is an official receipt from Industrial Training Centre. Please keep for your records.</em>
							</p>
						</div>

					<?php
					} // end foreach
					?>
				</div>

				<!-- Print Button (hidden on print) -->
				<div class="no-print" style="margin-top: 20px; margin-bottom: 20px; text-center;">
					<button class="btn btn-warning btn-lg" onClick="printContent('print')">
						<i class="fa fa-print"></i> Print Receipt
					</button>
					<a href="fees.php" class="btn btn-primary btn-lg">
						<i class="fa fa-arrow-left"></i> Back to Fees
					</a>
				</div>

			</div>
			<div class="col-sm-1"></div>
		</div>
	</div>
</body>
</html>


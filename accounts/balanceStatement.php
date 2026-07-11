<?php
$page_title = 'Balance Statement';
require "includes/nav.php";
require_once __DIR__ . '/../includes/report_print.php';
error_reporting(0);
?>
<?php render_report_print_styles(); ?>
<?php render_report_print_script(); ?>
<?php render_wuc_a4_print_script(); ?>
	<div class="container-fluid px-4 portal-dashboard accounts-page balance-statement-page">
		<div class="dashboard-header finance-section mb-4 d-print-none">
			<div class="row align-items-center">
				<div class="col">
					<h1 class="page-title">Balance Statement</h1>
					<p class="page-subtitle mb-0">Search and print student statements</p>

                <div class="search-box d-print-none">
                    <form role="form" method="POST" action="balanceStatement.php" class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label for="Sid" class="form-label">Student ID</label>
                            <input type="text" class="form-control" name="Sid" value="<?php echo htmlspecialchars($_POST['Sid'] ?? ''); ?>" id="Sid" placeholder="Enter student ID" required/>
                        </div>
                        <div class="col-md-4">
					<button type="submit" class="btn btn-primary" name="search">
						<i class="fas fa-search me-1"></i> Search
					</button>
                            <a href="../students/" class="btn btn-back ms-2">
                                <i class="fas fa-arrow-left me-1"></i> Back
                            </a>
                        </div>
                    </form>
                </div>

                <?php
                if(isset($_POST['search'])){
                    $Sid = trim((string)($_POST['Sid'] ?? ''));
                    $number = 1;
                    $Record = [];

                    $sql = "SELECT
                            p.id AS payment_id,
                            p.receipt_no AS reference_number,
                            p.description,
                            p.academic_year,
                            p.semester AS semester_term,
                            p.amount AS amount_paid,
                            0 AS balance,
                            p.method AS channel,
                            p.payment_date,
                            s.Fname,
                            s.Lname,
                            s.SID
                        FROM payments p
                        LEFT JOIN students s ON s.SID = p.student_id
                        WHERE p.student_id = ?
                        ORDER BY p.payment_date ASC, p.id ASC";
                    if($stmt = $db->prepare($sql)) {
                        $stmt->bind_param('s', $Sid);
                        $stmt->execute();
                        $results = $stmt->get_result();
                        if($count = $results->num_rows) {
                            while($row = $results->fetch_object()){
                                $Record[] = $row;
                            }
                        } else {
                            echo '<div class="alert alert-warning">No payment records found for this student.</div>';
                        }
                        $stmt->close();
                    } else {
                        echo '<div class="alert alert-danger">Unable to load balance statement. Please contact the system administrator.</div>';
                    }
                    
                    if (!empty($Record)):
                        $studentName = trim(($Record[0]->Fname ?? '') . ' ' . ($Record[0]->Lname ?? ''));
                        render_report_print_header(
                            'Student Balance Statement',
                            $studentName,
                            [
                                'Student ID' => $Sid,
                                'Name'       => $studentName,
                                'Records'    => count($Record),
                            ],
                            '../images/itc_logo.png'
                        );
                ?>
                <div id="print" class="wuc-a4-sheet">
                    <div class="print-header d-print-none">
                        <span class="wuc-logo-frame d-block mb-2">
                            <img src="../images/itc_logo.png" alt="ITC Logo" class="wuc-logo-img report-logo">
                        </span>
                        <h4>Industrial training college</h4>
                        <h5>Student Balance Statement</h5>
                    </div>
                    
                    <div class="balance-table-wrap">
                    <table class="table table-hover align-middle data-table">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Period</th>
                                <th>Amount Paid</th>
                                <th>Balance</th>
                                <th>Channel</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $totalBalance = 0;
                            $totalPaid = 0;
                            foreach($Record as $r) {
                                $totalBalance += floatval($r->balance ?? 0);
                                $totalPaid += floatval($r->amount_paid ?? 0);
                            ?>
                            <tr>
                                <td><?= $number++ ?></td>
                                <td><?= htmlspecialchars($r->reference_number ?? $r->referenceID ?? '—') ?></td>
                                <td><?= htmlspecialchars($r->narration ?? $r->description ?? '—') ?></td>
                                <td>Yr <?= htmlspecialchars($r->Year ?? $r->academic_year ?? '—') ?> Sem <?= htmlspecialchars($r->semester_term ?? $r->semester ?? '—') ?></td>
                                <td class="amount paid">ZMW <?= number_format(floatval($r->amount_paid ?? 0), 2) ?></td>
                                <td class="amount due">ZMW <?= number_format(floatval($r->balance ?? 0), 2) ?></td>
                                <td><?= htmlspecialchars($r->channel ?? '—') ?></td>
                                <td><?= htmlspecialchars($r->payment_date ?? $r->dte_time ?? '—') ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end">Totals:</td>
                                <td class="amount paid">ZMW <?= number_format($totalPaid, 2) ?></td>
                                <td class="amount due">ZMW <?= number_format($totalBalance, 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                    
                    <p class="text-end text-muted small mt-3">
                        Generated: <?php date_default_timezone_set('Africa/Harare'); echo date('d M Y, H:i'); ?>
                    </p>
                </div>
                
                <button class="btn btn-print mt-3 d-print-none" onClick="wucPrintSinglePage()">
                    <i class="fas fa-print me-1"></i> Print Statement
                </button>
				<?php 
					endif;
				}
				?>
			</div>
		</div>
	</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

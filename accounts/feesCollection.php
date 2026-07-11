<?php
$page_title = 'Collected Fees';
require "includes/nav.php";
error_reporting(0);

?>
	<div class="container-fluid px-4 portal-dashboard accounts-page fees-collection-page">
				<div class="dashboard-header finance-section mb-4">
					<div class="row align-items-center">
						<div class="col">
							<h1 class="dashboard-title">Collected Fees</h1>
							<p class="text-muted mb-0">Totals and filters</p>
						</div>
					</div>
				</div>
		<div class="row">
			<div class="col-md-9 mx-auto">
				<div class="data-table-card mb-4">
					<div class="card-header">
						<div class="d-flex justify-content-between align-items-center">
							<h5 class="mb-0">
								<i class="fas fa-calendar-alt me-2"></i>Collected Fees – Date Range
							</h5>
						</div>
					</div>
					<div class="card-body">
						<form role="form" method="POST" action="feesCollection.php">
							<div class="row g-3">
								<div class="col-md-4">
									<label for="dateFrom" class="form-label">Date From</label>
									<input type="date" class="form-control" id="dateFrom" name="dateFrom" required>
								</div>
								<div class="col-md-4">
									<label for="dateTo" class="form-label">Date To</label>
									<input type="date" class="form-control" id="dateTo" name="dateTo" required>
								</div>
							</div>
						<div class="mt-3 text-end">
							<button class="btn btn-primary px-4" type="submit" name="search">
								<i class="fas fa-search me-2"></i> Search
								</button>
							</div>
						</form>
                    <hr>
                        <?php

                        $number = 1;
                        $Records = array();
                        $totalAmount = 0.0;

                        $dateFrom = trim((string)($_POST['dateFrom'] ?? ''));
                        $dateTo = trim((string)($_POST['dateTo'] ?? ''));
                        $sql = "SELECT student_id AS Sid, amount AS amount_paid, semester, academic_year AS Year
                            FROM payments";
                        $types = '';
                        $params = [];
                        if (isset($_POST['search']) && $dateFrom !== '' && $dateTo !== '') {
                            $sql .= " WHERE DATE(payment_date) BETWEEN ? AND ?";
                            $types = 'ss';
                            $params = [$dateFrom, $dateTo];
                        }
                        $sql .= " ORDER BY payment_date DESC, id DESC";

                        if($stmt = $db->prepare($sql)) {
                            if ($types !== '') {
                                $stmt->bind_param($types, ...$params);
                            }
                            $stmt->execute();
                            $results = $stmt->get_result();
                            while($row = $results->fetch_object()){
                                $Records[] = $row;
                                $totalAmount += (float)$row->amount_paid;
                            }
                            $stmt->close();
                            if (empty($Records)) {
                                echo '<h4 class="alert alert-danger">' . "No records found." . '</h4>' . '<br>';
                            }
                        }
                        ?>
                        <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <tr>
                                <th>No</th>
                                <th>SID</th>
                                <th>Semester</th>
                                <th>Year</th>
                                <th>Paid amounts</th>
                            </tr>

                        <?php
                        foreach($Records as $r) {
                        ?>
                            <tr>
                                <td><?php echo $number++; ?></td>
                                <td><?php echo $r->Sid; ?></td>
                                <td><?php echo $r->semester; ?></td>
                                <td><?php echo $r->Year; ?></td>
                                <td><?php echo $r->amount_paid; ?></td>
                            </tr>
                            <?php 
                            }  
                            ?>
                            <tr>
                                <th>Total amount</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th><?php echo number_format($totalAmount, 2); ?></th>
                            </tr>
                        </table>
                        </div>
                </div>
		</div>
	</div>
	</div>
<?php require __DIR__ . '/includes/footer.php'; ?>


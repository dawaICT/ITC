<?php
$page_title = 'Update Student Payments';
require "includes/nav.php";
require_once __DIR__ . '/../includes/payment_helpers.php';
$erros = array();
$recentPayments = array();

if(!empty($_POST)){
            if(isset($_POST["Sid"],$_POST["referenceID"], $_POST["amount_paid"], $_POST["balance"], $_POST["narration"], $_POST["channel"], 
            $_POST["semester"], $_POST["Year"], $_POST["paymentReceivedBy"], $_POST["computer_ip"], $_POST["computer_mac"])) {

                $Sid = trim($_POST["Sid"]);
                $referenceID = trim($_POST["referenceID"]);
                $amount_paid = (float)$_POST["amount_paid"];
                $balance1 = (float)$_POST["balance"];
                $narration = trim($_POST["narration"]);
                $channel = trim($_POST["channel"]);
                $semester = trim($_POST["semester"]);
                $Year = trim($_POST["Year"]);
                $paymentReceivedBy = trim($_POST["paymentReceivedBy"]);
                $computer_ip = trim($_POST["computer_ip"]);
                $computer_mac = trim($_POST["computer_mac"]);
                $balance = max(0, $balance1 - $amount_paid);

                if ($referenceID === '') {
                    echo "<script>alert('Bank reference / receipt number is required.')</script>";
                    echo"<script>window.open('payments2.php','_self')</script>";
                    exit;
                }

                if (!payment_guard_student_exists($db, $Sid)) {
                    echo "<script>alert('Student ID not found in the system.')</script>";
                    echo"<script>window.open('payments2.php','_self')</script>";
                    exit;
                }

                if (payment_reference_posted($db, $Sid, $referenceID)) {
                    echo "<script>alert('This receipt/reference was already posted for this student.')</script>";
                    echo"<script>window.open('payments2.php','_self')</script>";
                    exit;
                }

                if (!empty($Sid) && !empty($amount_paid) && !empty($narration)
                && !empty($channel) && !empty($semester) && !empty($Year)) {
                        $reference_number = $referenceID;
                        $description = $narration;
                        $insert = $db->prepare("INSERT INTO payments
                        (student_id, receipt_no, amount, method, payment_date, status, description, posted_by, academic_year, semester)
                        VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?, ?, ?)");
                        $insert->bind_param("ssdsssss", $Sid, $reference_number, $amount_paid, $channel,
                        $description, $paymentReceivedBy, $Year, $semester);

                        if ($insert->execute()) {
                            // Allocate payment to pending installments in Finance AR (if present)
                            try {
                                $remaining = (float)$amount_paid;
                                if ($allocSel = $db->prepare("SELECT id, amount FROM finance_student_installments WHERE student_id = ? AND status='pending' ORDER BY due_date ASC, id ASC")) {
                                    $allocSel->bind_param('s', $Sid);
                                    $allocSel->execute();
                                    $allocRes = $allocSel->get_result();
                                    while ($remaining > 0 && ($row = $allocRes->fetch_assoc())) {
                                        $instAmount = (float)$row['amount'];
                                        if ($remaining + 0.0001 >= $instAmount) {
                                            if ($upd = $db->prepare("UPDATE finance_student_installments SET status='paid', updated_at=CURRENT_TIMESTAMP WHERE id = ?")) {
                                                $upd->bind_param('i', $row['id']);
                                                $upd->execute();
                                                $upd->close();
                                            }
                                            $remaining -= $instAmount;
                                        } else {
                                            break; // partial not tracked
                                        }
                                    }
                                    $allocSel->close();
                                }
                            } catch (Throwable $e) { /* ignore allocation errors */ }
                            // Audit log, if helpers available
                            if (function_exists('log_audit')) {
                                $audUser = isset($_SESSION['staff_id']) ? (string)$_SESSION['staff_id'] : (isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : 'system');
                                log_audit($db, $audUser, 'payment.record', json_encode(['Sid'=>$Sid,'amount'=>$amount_paid,'channel'=>$channel,'ref'=>$reference_number]));
                            }
                            echo "<script>alert('Student payments updated successfully!')</script>";
                            echo"<script>window.open('payments2.php','_self')</script>";

                            }
                        }
                        else {
                            echo "<script>alert('Payment Failed. There was an error please call the systems administrtator!')</script>";
                            echo"<script>window.open('payments2.php','_self')</script>";

                        }

                }
              }
?>

    <div class="container-fluid px-4 portal-dashboard accounts-page payments-page">
		<!-- Dashboard Header -->
		<div class="dashboard-header finance-section mb-4">
			<div class="row align-items-center">
				<div class="col">
					<h1 class="dashboard-title">Update Student Payments</h1>
					<p class="text-muted">Record cash and bank payments</p>
				</div>
				<div class="col-auto">
					<div class="header-actions d-flex gap-2">
						<a href="index.php" class="btn btn-primary d-flex align-items-center gap-2">
							<i class="fas fa-arrow-left"></i> Back to Dashboard
						</a>
					</div>
				</div>
			</div>
		</div>
        <div class="row g-4">
             <div class="col-md-12">
                <div class="data-table-card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-cash-coin me-2"></i>Student Payment Form
                            </h5>
                        </div>
                    </div>
                    <div class="card-body">
                        <form role="form" method="POST" action="payments2.php">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="Sid" class="form-label">
                                        <i class="bi bi-person-badge me-1"></i> Student ID
                                    </label>
                                    <input type="text" class="form-control" name="Sid" id="Sid" placeholder="Enter Student ID" autocomplete="off"/>
                                </div>
                            </div>
                            <div class="mt-4 text-end">
                                <button type="submit" class="btn btn-success px-4" name="search">
                                    <i class="bi bi-search me-1"></i> Search
                                </button>
                            </div>
                        </form>
                            <?php

                            // PHP program to get IP address of client 
                            $IP = $_SERVER['REMOTE_ADDR'];

                            $MAC = exec('getmac'); 
                            // Storing 'getmac' value in $MAC 
                            $MAC = strtok($MAC, ' '); 

                            if(isset($_POST['search'])){
                            $Sid = trim((string)$_POST['Sid']);

                            // initialize records to avoid undefined variable notices
                            $records = array();
                            $recentPayments = array();

                            $stmt = $db->prepare("SELECT s.*, sp.program_code
                                FROM students s
                                LEFT JOIN student_program sp
                                    ON sp.Sid COLLATE utf8mb4_unicode_ci = CONVERT(s.SID USING utf8mb4) COLLATE utf8mb4_unicode_ci
                                WHERE s.SID = ?
                                LIMIT 1");
                            if ($stmt) {
                                $stmt->bind_param('s', $Sid);
                                $stmt->execute();
                                $results = $stmt->get_result();
                                if ($row = $results->fetch_object()) {
                                    $dueStmt = $db->prepare("SELECT
                                            (SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE SID = ? OR student_id = ?) AS total_due,
                                            (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = ? AND LOWER(status) IN ('completed', 'paid', 'success')) AS total_paid");
                                    if ($dueStmt) {
                                        $dueStmt->bind_param('sss', $Sid, $Sid, $Sid);
                                        $dueStmt->execute();
                                        $due = $dueStmt->get_result()->fetch_object();
                                        $row->balance = max(0, (float)($due->total_due ?? 0) - (float)($due->total_paid ?? 0));
                                        $dueStmt->close();
                                    } else {
                                        $row->balance = 0;
                                    }
                                    $records[] = $row;
                                } else {
                                    echo "<script>alert('This student ID is not registered in the system.')</script>";
                                    echo"<script>window.open('payments2.php','_self')</script>";
                                    die();
                                }
                                $stmt->close();
                            }
                                        } 

                                    ?>

                                    <?php
                                    if (!empty($records)) { foreach($records as $r) {
                                        ?>
                                        <div class="alert alert-info mb-4">
                                            <div class="d-flex align-items-center mb-2">
                                                <div class="me-3">
                                                    <i class="fas fa-user-graduate fa-2x"></i>
                                                </div>
                                                <div>
                                        <h4 class="mb-0"><?php echo ($r->title); ?> <?php echo ($r->Fname); ?> <?php echo ($r->Lname); ?></h4>
                                        <p class="mb-0 small">ID: <?php echo ($r->nrc_pass); ?> | Program: <?php echo ($r->program_code); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                        $recentPayments = payment_recent_for_student($db, (string)$r->SID);
                                        if ($recentPayments): ?>
                                        <div class="table-responsive mb-4">
                                            <h6 class="text-muted">Recent payments on record</h6>
                                            <table class="table table-sm table-bordered">
                                                <thead><tr><th>Receipt</th><th>Amount</th><th>Method</th><th>Date</th><th>Term</th></tr></thead>
                                                <tbody>
                                                <?php foreach ($recentPayments as $pay): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars((string)($pay['receipt_no'] ?? ''), ENT_QUOTES) ?></td>
                                                        <td><?= htmlspecialchars((string)($pay['amount'] ?? ''), ENT_QUOTES) ?></td>
                                                        <td><?= htmlspecialchars((string)($pay['method'] ?? ''), ENT_QUOTES) ?></td>
                                                        <td><?= htmlspecialchars((string)($pay['payment_date'] ?? ''), ENT_QUOTES) ?></td>
                                                        <td><?= htmlspecialchars((string)($pay['semester'] ?? ''), ENT_QUOTES) ?> / Y<?= htmlspecialchars((string)($pay['academic_year'] ?? ''), ENT_QUOTES) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; ?>
                                            <form action="payments2.php" method="post" class="row g-3" role="form" id="payment-post-form">
                                                <div class="col-md-4 mb-3">
                                                    <label for="Sid" class="form-label">Student ID<span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="Sid" id="Sid" 
                                                        value="<?php echo ($r->Sid); ?>" autocomplete="off" readonly>
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label for="referenceID" class="form-label">Bank reference ID<span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="referenceID" id="referenceID" 
                                                        placeholder="Enter bank reference ID" autocomplete="off" required>
                                                    <div class="form-text text-danger d-none" id="reference-dup-msg">This receipt was already posted.</div>
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label for="amount_paid" class="form-label">Amount (ZMW)<span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">ZMW</span>
                                                        <input type="number" step="any" class="form-control" name="amount_paid" id="amount_paid" 
                                                            placeholder="Enter amount" autocomplete="off" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label for="balance" class="form-label">Account balance (ZMW)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">ZMW</span>
                                                        <input type="number" step="any" class="form-control" name="balance" id="balance" 
                                                            value="<?php echo ($r->balance); ?>" readonly>
                                                    </div>
                                                </div>

                                                <div class="col-md-4 mb-3">
                                                    <label for="narration" class="form-label">Narration</label>
                                                    <select class="form-select" name="narration" id="narration">
                                                        <option selected disabled>Choose narration</option>
                                                        <option>Tuition Fees</option>
                                                        <option>Exam Fees</option>
                                                        <option>User Fees</option>
                                                        <option>Hostel Fees</option>
                                                        <option>Other Fees</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-4 mb-3">
                                                    <label for="channel" class="form-label">Payment Channel</label>
                                                    <select class="form-select" name="channel" id="channel">
                                                        <option selected disabled>Choose payment channel</option>
                                                        <option>Over the Counter</option>
                                                        <option>INDO-Zambia Bank</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-4 mb-3">
                                                    <label for="semester" class="form-label">Semester/Term<span class="text-danger">*</span></label>
                                                    <select class="form-select" name="semester" id="semester">
                                                        <option disabled selected>Select semester</option>
                                                        <option>1</option>
                                                        <option>2</option>
                                                    </select>
                                                </div>
                                                                                                  <div class="col-md-4 mb-3">
                                                    <label for="Year" class="form-label">Year<span class="text-danger">*</span></label>
                                                    <select class="form-select" id="year" name="Year">
                                                        <option disabled selected>Year of study</option>
                                                        <option>1</option>
                                                        <option>2</option>
                                                        <option>3</option>
                                                        <option>4</option>
                                                    </select>
                                                </div>
                                                    <!--Capture the id of the person entering data -->
                                                    <input type="hidden" name="paymentReceivedBy" id="paymentReceivedBy" 
                                                    value="<?php echo $_SESSION['staff_id']; ?>" autocomplete="off" required><br>

                                                    <!--Capture the ip address of the computer being used by finance person -->
                                                    <input type="hidden" name="computer_ip" id="computer_ip" 
                                                    value="<?php echo $IP; ?>"><br>

                                                    <!--Capture the MAC address of the computer being used by finance person -->
                                                    <input type="hidden" name="computer_mac" id="computer_mac" 
                                                    value="<?php echo $MAC; ?>">

                                                <div class="col-12 mt-3 text-end">
                                                    <button class="btn btn-primary" type="submit" name="submit" id="payment-save-btn">
                                                        <i class="fas fa-save me-2"></i>Save Payment
                                                    </button>
                                                </div>
                                            </form>
                                        <?php   
                                        } }

                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <!-- placed at the end of the document so that the pages can load faster 
                ============================================================================-->
            <!-- jQuery/Bootstrap are already provided globally via includes/nav.php; keep legacy bootstrap JS if required -->
            <script src="dist/js/bootstrap.min.js"></script>
            
            <script>
            (function () {
                var refInput = document.getElementById('referenceID');
                var saveBtn = document.getElementById('payment-save-btn');
                var dupMsg = document.getElementById('reference-dup-msg');
                var postedRefs = <?php echo json_encode(array_values(array_map(static fn($p) => (string)($p['receipt_no'] ?? ''), $recentPayments ?? []))); ?>;
                function checkRefDup() {
                    if (!refInput || !saveBtn) return;
                    var val = refInput.value.trim().toLowerCase();
                    var dup = val !== '' && postedRefs.some(function (r) { return String(r).toLowerCase() === val; });
                    saveBtn.disabled = dup;
                    if (dupMsg) { dupMsg.classList.toggle('d-none', !dup); }
                }
                if (refInput) {
                    refInput.addEventListener('input', checkRefDup);
                    refInput.addEventListener('blur', checkRefDup);
                }
            })();
            </script>
            
            <script>
            $(document).ready(function() {
                // Initialize any components like tooltips
                $('[data-bs-toggle="tooltip"]').tooltip();
                
                // You can add custom scripts for this page here
            });
            
            /**
             * Validates amount input to ensure proper currency format
             * Rules:
             * - Must start with 1-9 (no leading zero)
             * - Can have any number of digits
             * - Optional decimal point with up to 2 digits (for cents/ngwee)
             * - No symbols or extra characters allowed
             * 
             * Valid examples: 150.50, 500, 1000.00
             * Invalid examples: 0.50, 100.555, $100
             */
            function isNumberKey(evt) {
                var charCode = (evt.which) ? evt.which : evt.keyCode;
                
                // Allow: backspace, delete, tab, escape, enter
                if (charCode === 46 || charCode === 8 || charCode === 9 || charCode === 27 || charCode === 13) {
                    return true;
                }
                
                // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                if ((charCode === 65 || charCode === 67 || charCode === 86 || charCode === 88) && (evt.ctrlKey === true || evt.metaKey === true)) {
                    return true;
                }
                
                // Allow: home, end, left, right, down, up
                if (charCode >= 35 && charCode <= 40) {
                    return true;
                }
                
                // Ensure that it is a number or decimal point
                if ((charCode < 48 || charCode > 57) && charCode !== 46) {
                    evt.preventDefault();
                    return false;
                }
                
                return true;
            }
            
            /**
             * Validates the amount field on form submission
             * @returns {boolean} true if valid, false if invalid
             */
            function validateAmountFormat() {
                var amountField = document.getElementById("amount_paid");
                if (!amountField) return true; // If field doesn't exist, don't block submission
                
                var currency = amountField.value.trim();
                
                // Allow empty if not required, otherwise check format
                if (currency === "") {
                    if (amountField.hasAttribute('required')) {
                        alert('Please enter a payment amount.');
                        amountField.focus();
                        return false;
                    }
                    return true;
                }
                
                // Regex pattern for currency validation:
                // ^[1-9] - Must start with 1-9 (no leading zero)
                // \\d* - Followed by any number of digits
                // (?:\\.\\d{0,2})? - Optional decimal point with up to 2 digits
                // $ - End of string
                var pattern = /^[1-9]\d*(?:\.\d{0,2})?$/;
                
                if (!pattern.test(currency)) {
                    alert('Receipt Amount should be in proper format!\n\nValid examples:\n• 150.50\n• 500\n• 1000.00\n\nInvalid examples:\n• 0.50 (cannot start with 0)\n• 100.555 (max 2 decimal places)\n• $100 (no symbols allowed)');
                    amountField.focus();
                    amountField.select();
                    return false;
                }
                
                return true;
            }
            
            // Attach validation to amount field on page load
            $(document).ready(function() {
                var amountField = document.getElementById('amount_paid');
                if (amountField) {
                    // Add keypress validation
                    $(amountField).on('keypress', isNumberKey);
                    
                    // Add blur validation (when user leaves the field)
                    $(amountField).on('blur', function() {
                        validateAmountFormat();
                    });
                }
                
                // Attach to all forms on the page
                $('form').on('submit', function(e) {
                    if (!validateAmountFormat()) {
                        e.preventDefault();
                        return false;
                    }
                });
            });
            </script>
<?php require __DIR__ . '/includes/footer.php'; ?>


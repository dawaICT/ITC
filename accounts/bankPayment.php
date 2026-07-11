<?php
$page_title = 'Update Bank Payment';
require "includes/nav.php";
require_once __DIR__ . '/../includes/finance_guard.php';
$erros = array();
// Ensure records array is initialized
$records = array();

// Capture client IP and MAC if available (used in hidden fields)
$IP = $_SERVER['REMOTE_ADDR'] ?? '';
$MAC = @exec('getmac');
$MAC = $MAC ? strtok($MAC, ' ') : '';

function bank_payment_table_exists(mysqli $db, string $table): bool
{
    $safeTable = $db->real_escape_string($table);
    try {
        $result = $db->query("SHOW TABLES LIKE '{$safeTable}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

function bank_payment_column_exists(mysqli $db, string $table, string $column): bool
{
    if (!bank_payment_table_exists($db, $table)) {
        return false;
    }
    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    try {
        $result = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

function bank_payment_reference_posted(mysqli $db, string $studentId, string $reference): bool
{
    return payment_reference_posted($db, $studentId, $reference);
}

function bank_payment_mirror_student_payment(mysqli $db, string $studentId, float $amount, float $balance, string $method, string $year, string $semester, string $reference, string $description): void
{
    if (!bank_payment_table_exists($db, 'student_payments')) {
        return;
    }

    $columns = [];
    $result = $db->query("SHOW COLUMNS FROM student_payments");
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string)$row['Field'];
    }
    $result->free();

    $fields = [];
    $placeholders = [];
    $types = '';
    $params = [];
    $add = static function (string $column, string $type, $value, bool $raw = false) use (&$fields, &$placeholders, &$types, &$params, $columns): void {
        if (!in_array($column, $columns, true)) {
            return;
        }
        $fields[] = "`{$column}`";
        if ($raw) {
            $placeholders[] = (string)$value;
            return;
        }
        $placeholders[] = '?';
        $types .= $type;
        $params[] = $value;
    };

    $add('Sid', 's', $studentId);
    $add('SID', 's', $studentId);
    $add('student_id', 's', $studentId);
    $add('amount_paid', 'd', $amount);
    $add('amount', 'd', $amount);
    $add('balance', 'd', $balance);
    $add('channel', 's', $method);
    $add('payment_method', 's', $method);
    $add('payment_date', '', 'NOW()', true);
    $add('academic_year', 's', $year);
    $add('semester_term', 's', $semester);
    $add('semester', 's', $semester);
    $add('reference_number', 's', $reference);
    $add('reference', 's', $reference);
    $add('referenceID', 's', $reference);
    $add('description', 's', $description);
    $add('narration', 's', $description);
    $add('status', 's', 'completed');
    $add('payment_status', 's', 'completed');
    $add('created_at', '', 'NOW()', true);

    if (empty($fields)) {
        return;
    }

    $stmt = $db->prepare("INSERT INTO student_payments (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare student payment mirror insert.');
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->close();
}

if(!empty($_POST)){
            if(isset($_POST["Sid"], $_POST["amount_paid"], $_POST["referenceID"], $_POST["receiptNum"], $_POST["balance"], $_POST["narration"], $_POST["channel"], 
            $_POST["semester"], $_POST["Year"], $_POST["paymentReceivedBy"], $_POST["computer_ip"], $_POST["computer_mac"])) {

                $Sid = trim((string)$_POST["Sid"]);
                $amount_paid = (float)$_POST["amount_paid"];
                $balance1 = (float)$_POST["balance"];
                $narration = trim($_POST["narration"]);
                $channel = trim($_POST["channel"]);
                $semester = trim($_POST["semester"]);
                $Year = trim($_POST["Year"]);
                $referenceID = trim((string)$_POST['referenceID']);
                $receiptNum = trim((string)$_POST['receiptNum']);
                $paymentReceivedBy = trim($_POST["paymentReceivedBy"]);
                $computer_ip = trim($_POST["computer_ip"]);
                $computer_mac = trim($_POST["computer_mac"]);
                $balance = max(0, $balance1 - $amount_paid);
                $reference_number = $referenceID !== '' ? $referenceID : ('RCPT-' . $receiptNum);
                $description = $narration !== '' ? $narration : 'Bank payment';

                if (bank_payment_reference_posted($db, $Sid, $reference_number)) {
                    echo '<h4 class="alert alert-danger">' . "This payment already exists in the system; it was already posted from the bank." . '</h4>' . '<br>';
                    die();
                } else if (!empty($Sid) && $amount_paid > 0) {
                    try {
                        $db->begin_transaction();
                        $postedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? $paymentReceivedBy ?? 'system');
                        $insert = $db->prepare("INSERT INTO payments
                            (student_id, receipt_no, amount, method, payment_date, status, description, posted_by, academic_year, semester)
                            VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?, ?, ?)");
                        $insert->bind_param("ssdsssss", $Sid, $reference_number, $amount_paid, $channel, $description, $postedBy, $Year, $semester);
                        $insert->execute();
                        $insert->close();

                        bank_payment_mirror_student_payment($db, $Sid, $amount_paid, $balance, $channel, $Year, $semester, $reference_number, $description);
                        $db->commit();
                            echo "<script>alert('Student payments updated successfully!')</script>";
                            echo"<script>window.open('updateBank_payment.php','_self')</script>";
                    } catch (Throwable $e) {
                        $db->rollback();
                        error_log('bankPayment: failed to post payment: ' . $e->getMessage());
                        echo "<script>alert('Payment Failed. There was an error please call the systems administrator!')</script>";
                        echo"<script>window.open('updateBank_payment.php','_self')</script>";
                    }
                } else {
                    echo "<script>alert('Payment Failed. Invalid student or amount.')</script>";
                    echo"<script>window.open('updateBank_payment.php','_self')</script>";
                }

                }
              }
?>

<div class="container-fluid px-4 portal-dashboard accounts-page bank-payment-page">
		<div class="dashboard-header finance-section mb-4">
			<div class="row align-items-center">
				<div class="col">
					<h1 class="dashboard-title">Update Bank Payment</h1>
					<p class="text-muted mb-0">Confirm and post bank transactions</p>
				</div>
			</div>
		</div>

		<div class="row g-4">
				<div class="col-md-9 mx-auto"> 
					<div class="data-table-card mb-4">
						<div class="card-header">
							<div class="d-flex justify-content-between align-items-center">
								<h5 class="mb-0">
									<i class="fas fa-university me-2"></i>Update student payments
								</h5>
							</div>
                            </div>
                            <div class="card-body">
                                <?php

                                if(isset($_POST['submit_payment'])){

                                $studentID = trim((string)$_POST['studentID']);
                                $referenceID = $_POST['referenceID'];
                                $receiptNum = $_POST['transactionId'];
                                $amount_paid = $_POST['amount'];
                                $narration = $_POST['narration'];
                                $channel = $_POST['transactionType'];
                                $semester = $_POST['semester'];
                                $Year = $_POST['Year'];

                                $stmt = $db->prepare("SELECT s.* FROM students s WHERE s.SID = ? LIMIT 1");
                                if ($stmt) {
                                    $stmt->bind_param('s', $studentID);
                                    $stmt->execute();
                                    $results = $stmt->get_result();
                                    if ($row = $results->fetch_object()) {
                                        $totalDue = wuc_get_student_term_due($db, $studentID, (string)$Year, (string)$semester);
                                        $totalPaid = wuc_get_student_term_paid($db, $studentID, (string)$Year, (string)$semester);
                                        $row->balance = max(0, $totalDue - $totalPaid);
                                        $row->receiptNum = $receiptNum;
                                        $records[] = $row;
                                    } else {
                                        echo '<h4 class="alert alert-danger">' . "Student record was not found." . '</h4>' . '<br>';
                                    }
                                    $stmt->close();
                                }
                                    }

                                    ?>

                                    <?php
                                    foreach($records as $r) {
                                        ?>
                                        <div class="alert alert-primary text-center"><strong><?php echo ($r->title); ?> <?php echo ($r->Fname); ?></strong> 
                                        <strong><?php echo ($r->Lname); ?> -</strong> <strong><?php echo ($r->nrc_pass); ?></strong><br>

                                         </div>
                                            <form action="bankPayment.php" method="post" class="row g-3" role="form">
                                                <div class="col-md-4">
                                                    <label for="Sid" class="form-label">Student ID<span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="Sid" autofocus id="Sid" 
                                                        value="<?php echo $studentID; ?>" autocomplete="off" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="referenceID" class="form-label">Bank reference ID<span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="referenceID" id="referenceID" 
                                                        value="<?php echo $referenceID; ?>" autocomplete="off" readonly>
                                                </div>
                                                <input type="hidden" name="receiptNum" id="receiptNum" 
                                                    value="<?php echo ($r->receiptNum); ?>" autocomplete="off" readonly>
                                                <div class="col-md-4">
                                                    <label for="amount_paid" class="form-label">Amount (ZMW)<span class="text-danger">*</span></label>
                                                    <input type="number" step="any" class="form-control" name="amount_paid" id="amount_paid" 
                                                        value="<?php echo $amount_paid; ?>" autocomplete="off" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="balance" class="form-label">Account balance (ZMW)</label>
                                                    <input type="number" step="any" class="form-control" name="balance" id="balance" 
                                                        value="<?php echo ($r->balance); ?>" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="narration" class="form-label">Narration</label>
                                                    <input type="text" class="form-control" name="narration" id="narration" 
                                                        value="<?php echo $narration; ?>" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="channel" class="form-label">Payment Channel</label>
                                                    <input type="text" class="form-control" name="channel" id="channel" 
                                                        value="<?php echo $channel; ?>" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="semester" class="form-label">Semester</label>
                                                    <input type="text" class="form-control" name="semester" id="semester" 
                                                        value="<?php echo $semester; ?>" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="Year" class="form-label">Year</label>
                                                    <input type="text" class="form-control" name="Year" id="Year" 
                                                        value="<?php echo $Year; ?>" readonly>
                                                </div>

                                                <!--Capture the id of the person entering data -->
                                                <input type="hidden" name="paymentReceivedBy" id="paymentReceivedBy" 
                                                    value="<?php echo $_SESSION['staff_id']; ?>" autocomplete="off" required>
                                                <!--Capture the ip address of the computer being used by finance person -->
                                                <input type="hidden" name="computer_ip" id="computer_ip" 
                                                    value="<?php echo $IP; ?>">
                                                <!--Capture the MAC address of the computer being used by finance person -->
                                                <input type="hidden" name="computer_mac" id="computer_mac" 
                                                    value="<?php echo $MAC; ?>">

                                                <div class="col-12 text-end">
										<button class="btn btn-primary" type="submit" name="submit">Update payment</button>
                                                </div>
                                            </form><br>
                                        <?php   
                                        }

                                        ?>
                                    </div> 
                             </div>
                            </div>
                        </div>
                    </div>
                </div>
            <!-- scripts moved to shared includes if needed -->

<script>
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
    if (!amountField) return true;
    
    var currency = amountField.value.trim();
    
    if (currency === "") {
        if (amountField.hasAttribute('required')) {
            alert('Please enter a payment amount.');
            amountField.focus();
            return false;
        }
        return true;
    }
    
    var pattern = /^[1-9]\d*(?:\.\d{0,2})?$/;
    
    if (!pattern.test(currency)) {
        alert('Receipt Amount should be in proper format!\n\nValid examples:\n• 150.50\n• 500\n• 1000.00\n\nInvalid examples:\n• 0.50 (cannot start with 0)\n• 100.555 (max 2 decimal places)\n• $100 (no symbols allowed)');
        amountField.focus();
        amountField.select();
        return false;
    }
    
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    var amountField = document.getElementById('amount_paid');
    if (amountField) {
        amountField.addEventListener('keypress', isNumberKey);
        amountField.addEventListener('blur', validateAmountFormat);
    }
    
    var forms = document.querySelectorAll('form');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!validateAmountFormat()) {
                e.preventDefault();
                return false;
            }
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>


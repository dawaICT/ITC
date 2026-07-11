<?php
$page_title = 'Scholarship Payments (New Students)';
require "includes/nav.php";
require_once __DIR__ . '/../includes/payment_helpers.php';
$erros = array();

if(!empty($_POST)){
            if(isset($_POST["Sid"], $_POST["amount_paid"], $_POST["balance"], $_POST["narration"], $_POST["channel"], $_POST["semester"], $_POST["Year"])) {

                $Sid = trim($_POST["Sid"]);
                $amount_paid = (float)$_POST["amount_paid"];
                $balance1 = (float)$_POST["balance"];
                $narration = trim($_POST["narration"]);
                $channel = trim($_POST["channel"]);
                $semester = trim($_POST["semester"]);
                $Year = trim($_POST["Year"]);
                $balance = max(0, $balance1 - $amount_paid);
                if (!payment_guard_student_exists($db, $Sid)) {
                    echo "<script>alert('Student ID not found in the system.')</script>";
                    echo"<script>window.open('scholarshipPayments2.php','_self')</script>";
                    exit;
                }

                $reference_number = '';
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $candidate = 'RCPT-' . mt_rand(100000, 999999);
                    if (!payment_reference_posted($db, $Sid, $candidate)) {
                        $reference_number = $candidate;
                        break;
                    }
                }
                if ($reference_number === '') {
                    echo "<script>alert('Could not allocate a unique receipt number. Try again.')</script>";
                    echo"<script>window.open('scholarshipPayments2.php','_self')</script>";
                    exit;
                }

                if (!empty($Sid) && !empty($amount_paid) && !empty($balance) && !empty($narration) 
                && !empty($channel) && !empty($semester) && !empty($Year)) {
                        $insert = $db->prepare("INSERT INTO payments
                        (student_id, receipt_no, amount, method, payment_date, status, description, posted_by, academic_year, semester)
                        VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?, ?, ?)");
                        $postedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'system');
                        $insert->bind_param("ssdsssss", $Sid, $reference_number, $amount_paid, $channel,
                        $narration, $postedBy, $Year, $semester);

                        if ($insert->execute()) {
                            echo "<script>alert('Student payments updated successfully!')</script>";
                            echo"<script>window.open('payments2.php','_self')</script>";

                            }
                        }
                        else {
                            echo "<script>alert('Payment Failed!')</script>";
                            echo"<script>window.open('payments.php','_self')</script>";

                        }

                }
              }
?>

        <div class="container-fluid px-4 portal-dashboard accounts-page scholarship-payments-page">
            <div class="dashboard-header finance-section mb-3">
                <h3 class="dashboard-title">Payments / New students</h3>
            </div>

            <div class="row">
                <div class="col-md-9 mx-auto">
                    <div class="data-table-card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-dollar-sign me-2"></i>Scholarship Payments</h5>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php
                            // Check if scholarship tables exist before running the query
                            $scholarship_tables_exist = true;
                            $check_tables = $db->query("SHOW TABLES LIKE 'scholarship_students'");
                            if (!$check_tables || $check_tables->num_rows == 0) $scholarship_tables_exist = false;
                            $check_tables = $db->query("SHOW TABLES LIKE 'scholarship'");
                            if (!$check_tables || $check_tables->num_rows == 0) $scholarship_tables_exist = false;

                            if ($scholarship_tables_exist) {
                                $results = $db->query("SELECT * FROM program_fees INNER JOIN student_program
                                ON program_fees.program_code = student_program.program_code INNER JOIN students
                                ON student_program.Sid = students.SID INNER JOIN scholarship_students
                                ON student_program.Sid = scholarship_students.Sid INNER JOIN scholarship
                                ON scholarship_students.scholarshipCode = scholarship.scholarshipcode
                                WHERE student_program.Sid = '$Sid'");
                            } else {
                                // Fallback: query without scholarship tables
                                $results = $db->query("SELECT *, 0 as Percent FROM program_fees INNER JOIN student_program
                                ON program_fees.program_code = student_program.program_code INNER JOIN students
                                ON student_program.Sid = students.SID
                                WHERE student_program.Sid = '$Sid'");
                            }

                            if($results) {
                                    if($count = $results->num_rows) {

                                        while($row = $results->fetch_object()){

                                                $records[] = $row;
                                            }

                                                $results->free();
                                            }
                                            else {
                                        echo "<script>alert('This student ID has complications with scholarship.')</script>";
                                        echo"<script>window.open('payments2.php','_self')</script>";
                                        die();
                                        }
                                        }

                                    ?>

                                    <?php
                                    foreach($records as $r) {
                                        ?>
                                        <div class="alert alert-primary text-center"><strong><?php echo ($r->title); ?> <?php echo ($r->Fname); ?></strong> <strong><?php echo ($r->Lname); ?> -</strong> <strong><?php echo ($r->nrc_pass); ?></strong></div>
                                            <form action="payments2.php" method="post" class="row g-3" role="form">
                                                <div class="col-md-4">
                                                    <label for="Sid" class="form-label">Student ID<span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="Sid" autofocus id="Sid" 
                                                        value="<?php echo ($r->Sid); ?>" autocomplete="off" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="amount_paid" class="form-label">Amount (ZMW)<span class="text-danger">*</span></label>
                                                    <input type="number" step="any" class="form-control" name="amount_paid" id="amount_paid" 
                                                        placeholder="Enter amount to be paid" autocomplete="off" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="balance" class="form-label">Account balance (ZMW)</label>
                                                    <input type="number" step="any" class="form-control" name="balance" id="balance" 
                                                        value="<?php echo ($r->semester1)-($r->semester1)*($r->Percent); ?>">
                                                </div>

                                                <div class="col-md-4">
                                                    <label for="narration" class="form-label">Narration</label>
                                                    <select class="form-select" name="narration" id="narration">
                                                        <option selected disabled>Choose narration</option>
                                                        <option>Tuition Fees</option>
                                                        <option>Exam Fees</option>
                                                        <option>Other Fees</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-4">
                                                    <label for="channel" class="form-label">Payment Channel</label>
                                                    <select class="form-select" name="channel" id="channel">
                                                        <option selected disabled>Choose payment channel</option>
                                                        <option>Over the Counter</option>
                                                        <option>ABSA Bank</option>
                                                        <option>FNB Bank</option>
                                                        <option>ZANACO</option>
                                                        <option>INDO-Zambia Bank</option>
                                                        <option>SchPay-(Mobile money)</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-4">
                                                    <label for="semester" class="form-label">Semester/Term<span class="text-danger">*</span></label>
                                                    <select class="form-select" name="semester" id="semester">
                                                        <option disabled selected>Select</option>
                                                        <option>1</option>
                                                        <option>2</option>
                                                        <option>3</option>
                                                        <option>4</option>
                                                        <option>5</option>
                                                        <option>6</option>
                                                        <option>7</option>
                                                        <option>8</option>
                                                        <option>9</option>
                                                        <option>10</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="year" class="form-label">Year<span class="text-danger">*</span></label>
                                                    <select class="form-select" id="year" name="Year">
                                                        <option disabled selected>Select year</option>
                                                    </select>
                                                    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
                                                    <script type="text/javascript">
                                                        let startYear = 2000;
                                                        let endYear = new Date().getFullYear();
                                                        for (i = endYear; i > startYear; i--) {
                                                          $('#year').append($('<option />').val(i).html(i));
                                                        }
                                                    </script>
                                                </div>

                                                <div class="col-12 text-end">
                                                    <button class="btn btn-success" type="submit" name="submit">Submit</button>
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
                </div>

            <!-- placed at the end of the document so that the pages can load faster 
                ============================================================================-->
            <!-- jQuery/Bootstrap are already provided globally via includes/nav.php; keep legacy bootstrap JS if required -->
            <script src="dist/js/bootstrap.min.js"></script>
            
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


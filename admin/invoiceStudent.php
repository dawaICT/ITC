<?php
include "includes/admin.php";
require_once __DIR__ . '/../includes/payment_helpers.php';

$records = [];

if (!empty($_POST) && isset($_POST['Sid'], $_POST['amount_paid'], $_POST['balance'], $_POST['narration'], $_POST['semester'], $_POST['Year'], $_POST['dte_time']) && !isset($_POST['search'])) {
    $Sid = trim((string)$_POST['Sid']);
    $invoice = trim((string)($_POST['invoice'] ?? ''));
    $narration = trim((string)$_POST['narration']);
    $semester = trim((string)$_POST['semester']);
    $Year = trim((string)$_POST['Year']);
    $dte_time = trim((string)$_POST['dte_time']);

    if ($Sid === '' || $invoice === '' || (float)$invoice <= 0 || $narration === '' || $semester === '' || $Year === '' || $dte_time === '') {
        echo "<script>alert('Please complete all invoice fields.')</script>";
        echo "<script>window.open('invoiceStudent.php','_self')</script>";
        exit;
    }

    if (!payment_guard_student_exists($db, $Sid)) {
        echo "<script>alert('Student ID not found in the system.')</script>";
        echo "<script>window.open('invoiceStudent.php','_self')</script>";
        exit;
    }

    $created = payment_create_student_invoice($db, $Sid, (float)$invoice, $Year, $semester, $narration);
    if (!empty($created['duplicate'])) {
        echo "<script>alert('An invoice already exists for this student and term. No duplicate was created.')</script>";
    } elseif (!empty($created['success'])) {
        echo "<script>alert('Student invoice created successfully.')</script>";
    } else {
        echo "<script>alert('Student invoice failed. Please try again.')</script>";
    }
    echo "<script>window.open('invoiceStudent.php','_self')</script>";
    exit;
}

if (isset($_POST['search'])) {
    $Sid = trim((string)$_POST['Sid']);
    if ($Sid === '') {
        echo "<script>alert('Enter a student ID to search.')</script>";
    } elseif ($stmtSearch = $db->prepare("SELECT s.*, COALESCE(i.total_due, 0) - COALESCE(p.total_paid, 0) AS balance
        FROM students s
        LEFT JOIN (
          SELECT SID, SUM(amount) AS total_due FROM invoices GROUP BY SID
        ) i ON i.SID = CONVERT(s.SID USING utf8mb4) COLLATE utf8mb4_general_ci
        LEFT JOIN (
          SELECT student_id, SUM(amount) AS total_paid FROM payments WHERE LOWER(status) IN ('completed', 'paid', 'success') GROUP BY student_id
        ) p ON CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_general_ci = s.SID
        WHERE s.SID = ?
        LIMIT 1")) {
        $stmtSearch->bind_param('s', $Sid);
        $stmtSearch->execute();
        $results = $stmtSearch->get_result();
        if ($results && $results->num_rows > 0) {
            while ($row = $results->fetch_object()) {
                $records[] = $row;
            }
        } else {
            echo "<script>alert('Student ID not found. Use new-student invoicing if this is a first-time student.')</script>";
        }
        $stmtSearch->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en-us">
  <head>
  <meta charset="UTF-8">
  <meta http-equiv="x-ua-compatible" content="IE edge">
        <link rel="icon" href="/wucportal/images/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/wucportal/images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/wucportal/images/favicon-16.png">
    <link rel="apple-touch-icon" href="/wucportal/images/apple-touch-icon.png">
        <link rel="stylesheet" type="text/css" href="home.css">
    <link rel="stylesheet" type="text/css" href="w3/w3.css">
    <link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
    </head>
  <body>
                <div class="col-md-2"></div>
                <div class="col-md-10"> 
          <div class="w3-card-4 w3-white"> 
            <div class="panel-body">
            <div class="w3-container">
              <ul class="w3-navbar">
                <li><a class="w3-hover-light-grey w3-text-blue" href="view_students.php">| Students</a></li> 
                  <li><a class="w3-hover-light-grey w3-text-blue" href="payments.php">| Payments</a></li>
                    <div class="w3-dropdown-hover">
                    <button class="w3-btn w3-white w3-hover-light-grey w3-text-blue">| Create Invoice</button>
                    <div class="w3-dropdown-content w3-light-grey  w3-border">
                      <a href="invoiceStudent.php">Returning student</a>
                      <a href="invoiceNewStudent.php">New student</a>
                    </div>
                  </div>
                </ul>
                  <h3>Invoice returning student</h3>
                <hr>
              <form role="form" method="POST" action="invoiceStudent.php" class="w3-row-padding" id="invoice-search-form">
                <label>Search student</label><br>
                <input type="text" class="w3-input w3-border col-xs-8" name="Sid" value="" id="Sid" placeholder="Enter student ID"/>
                <div id="student-lookup-msg" class="form-text"></div>
                <input type="submit" class="w3-btn w3-large w3-blue" name="search" value="search" id="invoice-search-btn">
            </form><br>
                      <?php foreach ($records as $r): ?>
                          <div class="w3-container w3-blue"><p><strong><?php echo htmlspecialchars($r->title); ?> <?php echo htmlspecialchars($r->Fname); ?></strong> <strong><?php echo htmlspecialchars($r->Lname); ?> -</strong> <strong><?php echo htmlspecialchars($r->nrc_pass); ?></strong></p><br>
                            <p><strong><u><?php echo htmlspecialchars($r->program ?? ''); ?></u></strong></p>
                          </div>
                            <form action="invoiceStudent.php" method="post" class="form-horizontal w3-container" role="form">
                                  <div class="form-group">
                                    <lable for="Sid">Student ID*:</lable>
                                        <input type="text" class="form-control w3-input w3-border w3-sand" name="Sid" id="invoiceSid" 
                                        value="<?php echo htmlspecialchars($r->SID); ?>" autocomplete="off" readonly>
                                  </div>
                                  <input type="hidden" name="amount_paid" value="0.00">
                                  <input type="hidden" name="balance" value="<?php echo htmlspecialchars((string)$r->balance); ?>">
                                  <div class="form-group">
                                  <lable for="invoice">Invoice Amount (ZMW)*:</lable>
                                        <input type="number" step="any" class="form-control w3-input w3-border w3-sand" name="invoice" id="invoice" value="0.00" autocomplete="off" required>
                                  </div>
                                  <div class="form-group">
                                    <lable for="narration">Narration*:</lable><br>
                                    <input type="text" class="form-control w3-input w3-border w3-sand" name="narration" id="narration" 
                                    placeholder="Enter narration" autocomplete="off" required>
                                  </div>
                                  <div class="form-group">
                                    <label for="semester">Semester: </label>
                                      <select class="form-control w3-sand" name="semester" id="semester" required>
                                        <option disabled selected value="">select semester</option>
                                        <?php for ($s = 1; $s <= 10; $s++): ?>
                                        <option value="<?php echo $s; ?>"><?php echo $s; ?></option>
                                        <?php endfor; ?>
                                      </select>
                                  </div>
                                  <div class="form-group">
                                  <label for="Year">Year: </label>
                                    <select class="w3-input w3-border col-xs-3 form-control w3-sand" id="year" name="Year" required>
                                      <option disabled selected value="">Select year</option>
                                      <?php for ($y = (int)date('Y'); $y >= 2000; $y--): ?>
                                      <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                      <?php endfor; ?>
                                    </select>
                                </div>
                                  <div class="form-group">
                                    <lable for="dte_time">Date</lable><br>
                                        <input type="date" class="form-control w3-input w3-border w3-sand" name="dte_time" id="dte_time" autocomplete="off" required>
                                  </div><br>
                                  <div class="form-group">
                                    <button class="btn btn-sm btn-block w3-btn w3-green" type="submit" name="submit">Submit</button>
                                </div>
                            </form>
                          <?php endforeach; ?>
                        </div>
                  </div>
               </div>
          </div>
        </div>
      </div>
      <script src="js/student_lookup.js"></script>
      <script>
      wucBindStudentLookup({ inputId: 'Sid', msgId: 'student-lookup-msg', submitSelector: '#invoice-search-btn' });
      </script>
      <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
      <script src="dist/js/bootstrap.min.js"></script>
  </body>
</html>

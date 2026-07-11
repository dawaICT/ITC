<?php

require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/db/connect.php';

if(!empty($_POST)){
      if(isset($_POST["Sid"], $_POST["semester"], $_POST["reason"])) {

        $Sid = trim($_POST["Sid"]);
        $semester = trim($_POST["semester"]);
        $reason = trim($_POST["reason"]);

      $index_1 = null;
      if ($check_query_1 = $db->prepare("SELECT Sid FROM semester_registration WHERE Sid = ? AND semester = ? LIMIT 1")) {
          $check_query_1->bind_param('ss', $Sid, $semester);
          $check_query_1->execute();
          $check_rs_1 = $check_query_1->get_result()->fetch_assoc();
          $index_1 = $check_rs_1['Sid'] ?? null;
          $check_query_1->close();
        }

        if (!isset($index_1)) {

            echo '<h4 class="alert alert-danger text-center">'."Complete semester registeration to be able to apply for Accommodation.".'</h4>';
            die();

          }

          $index = null;
          if ($check_query = $db->prepare("SELECT Sid FROM boarding_applicants WHERE Sid = ? AND semester = ? LIMIT 1")) {
              $check_query->bind_param('ss', $Sid, $semester);
              $check_query->execute();
              $check_rs = $check_query->get_result()->fetch_assoc();
              $index = $check_rs['Sid'] ?? null;
              $check_query->close();
            }

             if (isset($index)) {
                echo '<h4 class="alert alert-danger text-center">'."You have already submitted an application for accommodation. Please track your application to know the progress!".'</h4>';
                die();

                  }

             if (!empty($Sid) && !empty($semester) && !empty($reason)) {
              $insert = $db->prepare("INSERT INTO boarding_applicants (Sid, semester, reason, dte) VALUE(?,?,?,NOW())");
              $insert ->bind_param("sss", $Sid, $semester, $reason);

              if ($insert->execute()) {
                echo '<h4 class="alert alert-success text-center">'."Application for accommodation was successful do not make pay for accommodation until you have been selected. Please track your application to know the progress".'</h4>';
                }
              }
          else {
            echo '<h4 class="alert alert-danger text-center">'."There was an error submitting your application. Please contact IT administrator".'</h4>';
                die();

          }

        }
    }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Student Portal - Accommodation Application</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <style>
        /* Boarding-specific styles – generic from student-unified.css */
        .page-header { text-align: center; margin-bottom: 2rem; animation: fadeInDown 0.6s ease; }
        .header-icon { font-size: 3rem; color: var(--sp-primary); margin-bottom: 1rem; }
        .page-subtitle { font-size: 1rem; margin-bottom: 1.5rem; }

        .card { animation: fadeInUp 0.6s ease; }
        .card:hover { transform: translateY(-3px); box-shadow: var(--sp-shadow-md); }
        .card-header { background: linear-gradient(145deg, var(--sp-primary), var(--sp-primary-dark)); color: white; padding: 1.25rem; border-bottom: none; }

        .nav-pills { border-radius: var(--sp-radius-sm); overflow: hidden; display: flex; gap: 0.5rem; }
        .nav-pills .nav-link { padding: 0.875rem 1.25rem; color: var(--sp-text-dark); font-weight: 600; border-radius: var(--sp-radius-sm); transition: all 0.25s ease; display: flex; align-items: center; gap: 0.5rem; }
        .nav-pills .nav-link:hover { background-color: var(--sp-primary-light); }
        .nav-pills .nav-link.active { background: linear-gradient(145deg, var(--sp-primary), var(--sp-primary-dark)); color: white; }

        textarea.form-control { min-height: 120px; resize: vertical; }
        .form-control[readonly] { background-color: #f8f9fa; cursor: not-allowed; }
        .form-check-input:checked { background-color: var(--sp-primary); border-color: var(--sp-primary); }

        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 768px) {
            .nav-pills { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 0.5rem; }
            .nav-pills .nav-link { white-space: nowrap; padding: 0.75rem 1rem; }
        }
    </style>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    <div class="content-wrapper">
        <div class="page-header">
            <i class="fas fa-home header-icon"></i>
            <h1 class="page-title">Accommodation Application</h1>
            <p class="page-subtitle">Apply for student accommodation and track your application status</p>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <ul class="nav nav-pills">
                            <li class="nav-item">
                                <a class="nav-link active" href="#">
                                    <i class="fas fa-file-alt"></i>
                                    Application Form
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#">
                                    <i class="fas fa-search"></i>
                                    Track Application
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <form action="boardingApp.php" method="post" role="form">
                            <?php
                            $records = [];
                            $currentSid = (string)($_SESSION['Sid'] ?? '');
                            if ($currentSid !== '' && ($programStmt = $db->prepare("SELECT * FROM student_program WHERE Sid = ?"))) {
                                $programStmt->bind_param('s', $currentSid);
                                $programStmt->execute();
                                $results = $programStmt->get_result();
                                while ($results && ($row = $results->fetch_object())) {
                                    $records[] = $row;
                                }
                                $programStmt->close();
                            }
                            foreach($records as $r) {
                            ?>
                            <div class="mb-3">
                                <label for="Sid" class="form-label">Student ID</label>
                                <input type="text" class="form-control" name="Sid" id="Sid" 
                                    value="<?php echo ($r->Sid); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="semester" class="form-label">Semester</label>
                                <select class="form-select" name="semester" id="semester" required>
                                    <option value="" disabled selected>Select semester</option>
                                    <option value="1">Semester 1</option>
                                    <option value="2">Semester 2</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="reason" class="form-label">Application Summary</label>
                                <textarea class="form-control" name="reason" id="reason" 
                                    placeholder="Please provide your reason for boarding application..." 
                                    required></textarea>
                                <div class="form-text">Explain why you need accommodation and any special requirements</div>
                            </div>
                            <?php } ?>

                            <div class="mb-4">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="terms" required>
                                    <label class="form-check-label" for="terms">
                                        I agree to the terms & conditions
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-paper-plane"></i>
                                Submit Application
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle"></i>
                            Application Guidelines
                        </h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Complete semester registration first
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Provide valid reasons for accommodation
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Wait for approval before making payment
                            </li>
                            <li>
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Track your application status regularly
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>



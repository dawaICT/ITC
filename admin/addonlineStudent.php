<?php
require "includes/admin.php";
error_reporting(0);

// Shared Student ID generator + the same period/programme helpers the one-click
// admission flow uses, so a hand-entered admission produces an identically
// formatted, NRC-based number (and never a fabricated fallback).
require_once __DIR__ . '/../includes/student_id_generator.php';
require_once __DIR__ . '/../includes/applicant_admission.php';
require_once __DIR__ . '/../admissions/includes/registration_handlers.php';

// The real student number is NRC-based and can only be built once the NRC is
// known (on submit). There is no NRC at page load, so we do NOT pre-generate a
// number here — fabricating one (the old behaviour) risked persisting a value
// that does not follow the ITC format. The form just shows that it is auto-set.
$SID = '';

// ── Quick-admit (AJAX from processedApp.php) ───────────────────────────────
// The one-click "Add as Student" button on processedApp.php POSTs only the
// processed_applicants id (`view`) plus the CSRF token and expects a JSON
// reply. Detect that shape (view present, manual form fields absent) and run a
// COMPLETE admission: student record + programme enrolment + login account +
// course assignment + invoice, all in one transaction. The heavy lifting lives
// in the shared admitProcessedApplicant() helper so this flow can never drift
// from the modern admissions wizard. The manual form below is left untouched
// for hand-entered admissions.
if (!empty($_POST['view']) && !isset($_POST['title'])) {
    header('Content-Type: application/json');

    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    require_once __DIR__ . '/../includes/applicant_admission.php';

    $staffId = (string) ($_SESSION['user_id'] ?? $_SESSION['username'] ?? '');
    echo json_encode(admitProcessedApplicant($db, (int) $_POST['view'], $staffId));
    exit;
}

// Handle submission
if (!empty($_POST)){
      if(isset($_POST["title"], $_POST["Fname"], $_POST["Lname"], 
      $_POST["sex"], $_POST["nrc_pass"], $_POST["country"], $_POST["dob"], $_POST["mobile"], 
      $_POST["email"], $_POST["status"], $_POST["h_addre"], $_POST["p_addre"], $_POST["sponsor"], 
      $_POST["next_kin"], $_POST["next_kin_mobile"], $_POST["relat"])) {

        $title = trim($_POST["title"]);
        $Fname = trim($_POST["Fname"]);
        $Lname = trim($_POST["Lname"]);
        $sex = trim($_POST["sex"]);
        $nrc_pass = trim($_POST["nrc_pass"]);
        $country = trim($_POST["country"]);
        $dob = trim($_POST["dob"]);
        $mobile = trim($_POST["mobile"]);
        $email = trim($_POST["email"]);
        $status = trim($_POST["status"]);
        $h_addre = trim($_POST["h_addre"]);
        $p_addre = trim($_POST["p_addre"]);
        $sponsor = trim($_POST["sponsor"]);
        $next_kin = trim($_POST["next_kin"]);
        $next_kin_mobile = trim($_POST["next_kin_mobile"]);
        $relat = trim($_POST["relat"]);

        // Build the SID now that the NRC and programme are known. The academic
        // period is derived from the applicant's actual intake (matching the
        // one-click admission flow), NOT from today's date, so the period digit
        // in the number is correct (incl. term 3). If a valid NRC-based number
        // cannot be generated we ABORT with a clear message rather than
        // persisting a fabricated, non-conforming student number.
        $admitProgram = trim((string)($_POST['program'] ?? ''));
        $admitIntake  = trim((string)($_POST['intake'] ?? ''));
        $admitPeriod  = (string) wuc_period_for_date();
        if ($admitProgram !== '') {
            try {
                $progRow = admissionsResolveProgram($db, $admitProgram);
                $admitPeriod = admissionsPeriodFromIntake($progRow['period_mode'], $admitIntake);
            } catch (Throwable $e) {
                // Programme not in catalogue — fall back to the date-based period.
            }
        }

        try {
            $SID = generateStudentId($db, $admitProgram !== '' ? $admitProgram : 'GENERAL', $admitPeriod, date('Y'), $nrc_pass);
        } catch (Throwable $e) {
            error_log('addonlineStudent SID generation error: ' . $e->getMessage());
            echo "<script>alert('Could not generate a student number. Please check the NRC/Passport number and try again.');window.location='addonlineStudent.php';</script>";
            exit;
        }

        try {
            admissionsAssertIdentityIsUnique($db, $email, $mobile, $nrc_pass);
        } catch (RuntimeException $e) {
            $msg = htmlspecialchars(addslashes($e->getMessage()), ENT_QUOTES);
            echo "<script>alert('Registration blocked: {$msg}. This person may already be in the system.');window.location='addonlineStudent.php';</script>";
            exit;
        }

        // Check if SID already exists
        $index = null;
        if ($check_stmt = $db->prepare("SELECT SID FROM students WHERE SID = ? LIMIT 1")) {
            $sid_param = $SID; // system-generated SID is authoritative
            $check_stmt->bind_param('s', $sid_param);
            if ($check_stmt->execute()) {
                $res = $check_stmt->get_result();
                if ($row = $res->fetch_assoc()) { $index = $row['SID']; }
            }
            $check_stmt->close();
        }

        if (isset($index)) {
            echo"<script>alert('Failed! Student number already exists')</script>";
            echo"<script>window.open('students.php','_self')</script>";
        } else if (
            $title && $Fname && $Lname && $sex && $nrc_pass && $country && $dob && $mobile && $email !== '' &&
            $status && $h_addre && $p_addre !== '' && $sponsor && $next_kin && $next_kin_mobile && $relat
        ) {
            $insert = $db->prepare("INSERT INTO students (SID, title, Fname, Lname, sex, nrc_pass, country, dob, 
              mobile, email, status, h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, dte_adm) 
              VALUE(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $sid_to_use = $SID; // system-generated SID is authoritative
            $insert ->bind_param("sssssssssssssssss", $sid_to_use, $title, $Fname, $Lname, $sex, $nrc_pass, 
            $country, $dob, $mobile, $email, $status, $h_addre, $p_addre, $sponsor, $next_kin,
            $next_kin_mobile, $relat);

            if ($insert->execute()) {
              $prog = $_POST['program'] ?? '';
              $intake = $_POST['intake'] ?? '';
              $mode = $_POST['mode'] ?? '';
              $redirectUrl = "admitStudent.php?sid=" . urlencode($sid_to_use);
              if ($prog) $redirectUrl .= "&prog=" . urlencode($prog);
              if ($intake) $redirectUrl .= "&intake=" . urlencode($intake);
              if ($mode) $redirectUrl .= "&mode=" . urlencode($mode);

              echo "<script>alert('New student added successfully. Proceed to admit')</script>";
              echo"<script>window.open('$redirectUrl','_self')</script>";
            } else {
              echo "<script>alert('Insert failed')</script>";
              }
        } else {
            echo "<script>alert('Process failed! Missing required fields')</script>";
              echo"<script>window.open('students.php','_self')</script>";
        }
    }
}

$page_title = 'Add Online Student';
require 'includes/header.php';
// Prefill from processed_applicants if ?view=
$prefill = null;
if (isset($_GET['view'])) {
    $viewID = (int)$_GET['view'];
    if ($viewID > 0 && ($results = $db->prepare("SELECT * FROM processed_applicants WHERE id = ?"))) {
        $results->bind_param('i', $viewID);
        if ($results->execute()) {
            $res = $results->get_result();
            $prefill = $res->fetch_object();
        }
        $results->close();
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
  <div class="row justify-content-center">
    <div class="col-lg-10">
      <div class="card shadow-sm">
        <div class="card-header bg-white py-3 d-flex align-items-center">
          <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Add New Student</h5>
          <span class="ms-auto text-muted">Student number: <strong>auto-generated from NRC on submit</strong></span>
        </div>
        <div class="card-body">
          <form action="addonlineStudent.php" method="post" class="row g-3 needs-validation" novalidate>
            <input type="hidden" name="SID" value="<?php echo htmlspecialchars($SID); ?>">
            <input type="hidden" name="program" value="<?php echo htmlspecialchars($prefill->program ?? ''); ?>">
            <input type="hidden" name="intake" value="<?php echo htmlspecialchars($prefill->intake ?? ''); ?>">
            <input type="hidden" name="mode" value="<?php echo htmlspecialchars($prefill->mode ?? ''); ?>">

            <div class="col-md-2">
              <label for="title" class="form-label">Title</label>
              <input type="text" class="form-control" name="title" id="title" value="<?php echo htmlspecialchars($prefill->title ?? ''); ?>" required>
              <div class="invalid-feedback">Title is required</div>
            </div>

            <div class="col-md-5">
              <label for="Fname" class="form-label">First Name</label>
              <input type="text" class="form-control" name="Fname" id="Fname" value="<?php echo htmlspecialchars($prefill->Fname ?? ''); ?>" required>
              <div class="invalid-feedback">First name is required</div>
                    </div>

            <div class="col-md-5">
              <label for="Lname" class="form-label">Last Name</label>
              <input type="text" class="form-control" name="Lname" id="Lname" value="<?php echo htmlspecialchars($prefill->Lname ?? ''); ?>" required>
              <div class="invalid-feedback">Last name is required</div>
                    </div>

            <div class="col-md-4">
              <label for="sex" class="form-label">Gender</label>
              <input type="text" class="form-control" name="sex" id="sex" value="<?php echo htmlspecialchars($prefill->sex ?? ''); ?>" required>
              <div class="invalid-feedback">Gender is required</div>
                    </div>

            <div class="col-md-4">
              <label for="country" class="form-label">Country</label>
              <input type="text" class="form-control" name="country" id="country" value="<?php echo htmlspecialchars($prefill->country ?? ''); ?>" required>
              <div class="invalid-feedback">Country is required</div>
                    </div>

            <div class="col-md-4">
              <label for="nrc_pass" class="form-label">NRC/Passport No</label>
              <input type="text" class="form-control identity-check" name="nrc_pass" id="nrc_pass" data-check="nrc" value="<?php echo htmlspecialchars($prefill->nrc_pass ?? ''); ?>" required>
              <div class="invalid-feedback">NRC/Passport is required</div>
              <div class="form-text text-danger d-none" id="dup-nrc"></div>
                    </div>

            <div class="col-md-4">
              <label for="dob" class="form-label">Date of Birth</label>
              <input type="date" class="form-control" name="dob" id="dob" value="<?php echo htmlspecialchars($prefill->dob ?? ''); ?>" required>
              <div class="invalid-feedback">Date of Birth is required</div>
                    </div>

            <div class="col-md-4">
              <label for="mobile" class="form-label">Mobile</label>
              <input type="text" class="form-control identity-check" name="mobile" id="mobile" data-check="phone" value="<?php echo htmlspecialchars($prefill->mobile ?? ''); ?>" required>
              <div class="invalid-feedback">Mobile is required</div>
              <div class="form-text text-danger d-none" id="dup-phone"></div>
                    </div>

            <div class="col-md-4">
              <label for="email" class="form-label">Email</label>
              <input type="email" class="form-control identity-check" name="email" id="email" data-check="email" value="<?php echo htmlspecialchars($prefill->email ?? 'optional'); ?>">
              <div class="form-text text-danger d-none" id="dup-email"></div>
                    </div>

            <div class="col-md-4">
              <label for="status" class="form-label">Status</label>
              <input type="text" class="form-control" name="status" id="status" value="<?php echo htmlspecialchars($prefill->status ?? ''); ?>" required>
              <div class="invalid-feedback">Status is required</div>
                    </div>

            <div class="col-md-8">
              <label for="h_addre" class="form-label">Home Address</label>
              <input type="text" class="form-control" name="h_addre" id="h_addre" value="<?php echo htmlspecialchars($prefill->h_addre ?? ''); ?>" required>
              <div class="invalid-feedback">Home address is required</div>
                    </div>

            <div class="col-md-8">
              <label for="p_addre" class="form-label">Postal Address</label>
              <input type="text" class="form-control" name="p_addre" id="p_addre" value="<?php echo htmlspecialchars($prefill->p_addre ?? 'optional'); ?>">
                    </div>

            <div class="col-md-4">
              <label for="sponsor" class="form-label">Sponsor</label>
              <input type="text" class="form-control" name="sponsor" id="sponsor" value="<?php echo htmlspecialchars($prefill->sponsor ?? ''); ?>" required>
              <div class="invalid-feedback">Sponsor is required</div>
                    </div>

            <div class="col-md-6">
              <label for="next_kin" class="form-label">Next of Kin</label>
              <input type="text" class="form-control" name="next_kin" id="next_kin" value="<?php echo htmlspecialchars($prefill->next_kin ?? ''); ?>" required>
              <div class="invalid-feedback">Next of Kin is required</div>
                        </div>

            <div class="col-md-6">
              <label for="next_kin_mobile" class="form-label">Next of Kin Mobile</label>
              <input type="text" class="form-control" name="next_kin_mobile" id="next_kin_mobile" value="<?php echo htmlspecialchars($prefill->next_kin_mobile ?? ''); ?>" required>
              <div class="invalid-feedback">Next of Kin Mobile is required</div>
                    </div>

            <div class="col-md-6">
              <label for="relat" class="form-label">Relationship</label>
              <input type="text" class="form-control" name="relat" id="relat" value="<?php echo htmlspecialchars($prefill->relat ?? ''); ?>" required>
              <div class="invalid-feedback">Relationship is required</div>
                    </div>

            <div class="col-12">
              <div class="alert alert-warning d-none" id="identity-dup-alert" role="alert"></div>
              <button class="btn btn-success" type="submit" id="add-student-submit">Submit</button>
              <a href="students.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
        </div>
            </div>
    </div>
  </div>

<script>
(function(){
  var forms = document.querySelectorAll('.needs-validation');
  Array.prototype.slice.call(forms).forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (form.dataset.identityBlocked === '1') {
        event.preventDefault();
        event.stopPropagation();
        return;
      }
      if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });

  var dupState = { nrc: false, email: false, phone: false };
  var submitBtn = document.getElementById('add-student-submit');
  var alertBox = document.getElementById('identity-dup-alert');
  var checkUrl = '../admissions/checkDuplicate.php';

  function refreshSubmitState() {
    var blocked = dupState.nrc || dupState.email || dupState.phone;
    if (submitBtn) { submitBtn.disabled = blocked; }
    var form = document.querySelector('.needs-validation');
    if (form) { form.dataset.identityBlocked = blocked ? '1' : '0'; }
    if (alertBox) {
      if (blocked) {
        alertBox.classList.remove('d-none');
        alertBox.textContent = 'A student with this NRC, email, or phone already exists. Open the existing record instead of creating a duplicate.';
      } else {
        alertBox.classList.add('d-none');
        alertBox.textContent = '';
      }
    }
  }

  function setDup(kind, exists, message, sid) {
    dupState[kind] = !!exists;
    var el = document.getElementById('dup-' + (kind === 'phone' ? 'phone' : kind));
    if (el) {
      if (exists) {
        el.classList.remove('d-none');
        el.textContent = message + (sid ? ' (SID: ' + sid + ')' : '');
      } else {
        el.classList.add('d-none');
        el.textContent = '';
      }
    }
    refreshSubmitState();
  }

  var timers = {};
  document.querySelectorAll('.identity-check').forEach(function (input) {
    input.addEventListener('blur', function () {
      var kind = input.dataset.check;
      var value = input.value.trim();
      if (!value || value === 'optional') {
        setDup(kind, false, '');
        return;
      }
      clearTimeout(timers[kind]);
      timers[kind] = setTimeout(function () {
        var param = kind === 'phone' ? 'phone' : kind;
        fetch(checkUrl + '?' + encodeURIComponent(param) + '=' + encodeURIComponent(value), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            setDup(kind, !!data.exists, data.message || 'Already registered', data.sid || null);
          })
          .catch(function () {});
      }, 300);
    });
  });
})();
</script>

<?php require 'includes/footer.php'; ?>

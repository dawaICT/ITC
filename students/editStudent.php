<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../db/connect.php';
error_reporting(0);

$updateMessage = '';
$updateMessageType = 'info';

if (isset($_POST['update'])) {

    // A student may only update their OWN non-sensitive contact details.
    // Identity fields (SID, name, gender, NRC, DOB, programme, enrolment) are
    // protected: they are never written here and are blocked at the database
    // level by the identity-guard trigger. The student is identified by their
    // session, never by a posted SID, to prevent editing another record.
    $SID = $_SESSION['Sid'] ?? trim($_POST["SID"] ?? '');

    $mobile          = trim($_POST["mobile"] ?? '');
    $email           = trim($_POST["email"] ?? '');
    $h_addre         = trim($_POST["h_addre"] ?? '');
    $p_addre         = trim($_POST["p_addre"] ?? '');
    $next_kin        = trim($_POST["next_kin"] ?? '');
    $next_kin_mobile = trim($_POST["next_kin_mobile"] ?? '');
    $relat           = trim($_POST["relat"] ?? '');

    $ok = false;
    if ($SID !== '' && preg_match('/^[A-Za-z0-9\/\-_]+$/', $SID)) {
        $stmt = $db->prepare("UPDATE students SET
            mobile = ?, email = ?, h_addre = ?, p_addre = ?,
            next_kin = ?, next_kin_mobile = ?, relat = ?
            WHERE SID = ?");
        if ($stmt) {
            $stmt->bind_param("ssssssss",
                $mobile, $email, $h_addre, $p_addre,
                $next_kin, $next_kin_mobile, $relat, $SID);
            try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
            $stmt->close();
        }
    }

    if ($ok) {
        $updateMessage = 'Your contact details were successfully updated.';
        $updateMessageType = 'success';
    } else {
        $updateMessage = 'Information could not be updated. Please try again.';
        $updateMessageType = 'danger';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Student Information - ITC</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

<link rel="stylesheet" href="/wucportal/css/admin-style.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
<link rel="stylesheet" type="text/css" href="w3/w3.css">

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>

<body class="bg-light">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>
<div class="content-wrapper">
<div class="container py-4">
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <i class="fas fa-user me-2"></i>
            <h5 class="mb-0">Edit your information</h5>
        </div>
        <div class="card-body">
					<?php if ($updateMessage !== ''): ?>
						<div class="alert alert-<?php echo htmlspecialchars($updateMessageType) ?>">
							<?php echo htmlspecialchars($updateMessage) ?>
						</div>
					<?php endif; ?>
					<?php
						// A student may only ever view/edit their OWN record here (matches the
						// POST handler below). The ?update= value is display-only context, never
						// trusted as the lookup key, so a student can't read another student's
						// NRC, DOB, address, or next-of-kin details by editing the URL.
						$records_1 = [];
						$ownSid = (string)($_SESSION['Sid'] ?? '');
						if ($ownSid !== '' && ($stmt_view = $db->prepare("SELECT * FROM students WHERE SID = ?"))) {
							$stmt_view->bind_param('s', $ownSid);
							$stmt_view->execute();
							$results = $stmt_view->get_result();
							while ($row = $results->fetch_object()) {
								$records_1[] = $row;
							}
							$stmt_view->close();
						}
                    ?>

                    <?php
						foreach($records_1 as $r) {
					?>
                    <div id="react-student-root">
                        <form action="editStudent.php" method="post">
                            <div id="original-student-form-content">
                                <input type="hidden" name="id" id="id" value="<?php echo htmlspecialchars((string)$r->id) ?>">
                                <input type="hidden" name="dte_adm" id="dte_adm" value="<?php echo htmlspecialchars((string)$r->dte_adm) ?>">
                                <table id="myTable" class="table table-hover align-middle" style="min-width:0;">
                                    <tbody class="table-light">

                                    <tr>
                                        <th>Student ID</th>
            	                        <td><input type="text" class="form-control" name="SID" id="SID"
                                                value="<?php echo htmlspecialchars((string)$r->SID) ?>" readonly>
                                            </td>
            	                       </tr>
            	                       <tr>
            	                          <th>First name <i class="fas fa-lock text-muted" title="Protected"></i></th>
            	                          <td><input type="text" class="form-control" name="Fname" id="Fname"
                                                value="<?php echo htmlspecialchars((string)$r->Fname) ?>" readonly>
                                            </td>
            	                        </tr>
            	                        <tr>
            	                          <th>Last name <i class="fas fa-lock text-muted" title="Protected"></i></th>
            	                          <td><input type="text" class="form-control" name="Lname" id="Lname"
                                                value="<?php echo htmlspecialchars((string)$r->Lname) ?>" readonly>
                                            </td>
            	                        </tr>
            	                        <tr>
            	                          <th>Gender <i class="fas fa-lock text-muted" title="Protected"></i></th>
            	                          <td>
                                                <input type="text" class="form-control" id="sex"
                                                    value="<?php echo htmlspecialchars((string)$r->sex) ?>" readonly>
                                            </td>
                                      </tr>
                                      <tr>
                                        <th>Country</th>
            	                        <td><input type="text" class="form-control" name="country" id="country"
                                                value="<?php echo htmlspecialchars((string)$r->country) ?>" autocomplete="off">
                                            </td>
            	                       </tr>
            	                       <tr>
            	                          <th>NRC/Passport <i class="fas fa-lock text-muted" title="Protected"></i></th>
            	                          <td><input type="text" class="form-control" name="nrc_pass" id="nrc_pass"
                                                value="<?php echo htmlspecialchars((string)$r->nrc_pass) ?>" readonly>
                                            </td>
            	                        </tr>
            	                        <tr>
            	                          <th>Date of Birth <i class="fas fa-lock text-muted" title="Protected"></i></th>
            	                          <td><input type="text" class="form-control" name="dob" id="dob"
                                                value="<?php echo htmlspecialchars((string)$r->dob) ?>" readonly>
                                            </td>
            	                        </tr>
            	                        <tr>
            	                          <th>Mobile</th>
            	                          <td><input type="text" class="form-control" name="mobile" id="mobile"
                                                value="<?php echo htmlspecialchars((string)$r->mobile) ?>" autocomplete="off">
                                            </td>
                                      </tr>
                                      <tr>
                                        <th>Status</th>
            	                        <td><input type="text" class="form-control" name="status" id="status"
                                                value="<?php echo htmlspecialchars((string)$r->status) ?>" autocomplete="off">
                                            </td>
            	                       </tr>
            	                       <tr>
            	                          <th>Email</th>
            	                          <td><input type="text" class="form-control" name="email" id="email"
                                                value="<?php echo htmlspecialchars((string)$r->email) ?>" autocomplete="off">
                                            </td>
            	                        </tr>
            	                        <tr>
            	                          <th>Home address</th>
            	                          <td><input type="text" class="form-control" name="h_addre" id="h_addre"
                                                value="<?php echo htmlspecialchars((string)$r->h_addre) ?>" autocomplete="off">
                                            </td>
            	                        </tr>
            	                        <tr>
            	                          <th>Postal address</th>
            	                          <td><input type="text" class="form-control" name="p_addre" id="p_addre"
                                                value="<?php echo htmlspecialchars((string)$r->p_addre) ?>" autocomplete="off">
                                            </td>
            	                        </tr>
            	                        <tr>
            	                          <th>Sponsor</th>
            	                          <td><input type="text" class="form-control" name="sponsor" id="sponsor"
                                                value="<?php echo htmlspecialchars((string)$r->sponsor) ?>" autocomplete="off">
                                            </td>
                                      </tr>
            	                        <tr>
            	                          <th>Next of Kin</th>
            	                          <td><input type="text" class="form-control" name="next_kin" id="next_kin"
                                                value="<?php echo htmlspecialchars((string)$r->next_kin) ?>" autocomplete="off">
                                            </td>
                                      </tr>
            	                        <tr>
            	                          <th>Next of Kin Contact</th>
            	                          <td><input type="text" class="form-control" name="next_kin_mobile" id="next_kin_mobile"
                                                value="<?php echo htmlspecialchars((string)$r->next_kin_mobile) ?>" autocomplete="off">
                                            </td>
            	                        </tr>
            	                        <tr>
            	                          <th>Relationship</th>
            	                          <td><input type="text" class="form-control" name="relat" id="relat"
                                                value="<?php echo htmlspecialchars((string)$r->relat) ?>" autocomplete="off">
                                            </td>
                                      </tr>

                                    </tbody>
                                </table>
                            </div>
                            <br>
                            <a class="btn btn-outline-secondary" onclick="history.back()">Back</a>
                            <button class="btn btn-primary" type="submit" name="update">Update</button>
                        </form>
                    </div>
					<?php	
						}  
					?>
    </div>
                <br>
			</div>
		</div>
	</div>

    <!-- React Dependencies -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/babel-standalone@6/babel.min.js"></script>

    <script type="text/babel">
        const EditStudentForm = () => {
            const [isSubmitting, setIsSubmitting] = React.useState(false);
            const formRef = React.useRef(null);
            
            const handleSubmit = (e) => {
                if (isSubmitting) {
                    e.preventDefault();
                    return;
                }
                setIsSubmitting(true);
            };

            return (
                <form action="editStudent.php" method="post" onSubmit={handleSubmit} ref={formRef}>
                    <div dangerouslySetInnerHTML={{ __html: document.getElementById('original-student-form-content').innerHTML }} />
                    <br />
                    <a className="btn btn-outline-secondary" onClick={() => window.history.back()} style={{ cursor: 'pointer', marginRight: '10px' }}>Back</a>
                    <button className="btn btn-primary" type="submit" name="update" disabled={isSubmitting}>
                        {isSubmitting ? (
                            <><i className="fas fa-spinner fa-spin"></i> Updating...</>
                        ) : (
                            'Update'
                        )}
                    </button>
                </form>
            );
        };

        const rootNode = document.getElementById('react-student-root');
        if (rootNode) {
            const root = ReactDOM.createRoot(rootNode);
            root.render(<EditStudentForm />);
        }
    </script>
</body>
</html>

<?php
$page_title = 'Edit Bio Data';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/nav.php';

// ─── Handle Form Submission ────────────────────────────────────────
if (isset($_POST['update'])) {
    // Only allow the logged-in user to edit their OWN profile
    $staff_id   = $_SESSION['staff_id'];
    $deptId     = wuc_input('deptId');
    $title      = wuc_input('title');
    $Fname      = wuc_input('Fname');
    $Lname      = wuc_input('Lname');
    $sex        = wuc_input('sex');
    $country    = wuc_input('country');
    $nrc_pass   = wuc_input('nrc_pass');
    $mobile     = wuc_input('mobile');
    $email      = wuc_input('email');
    $address    = wuc_input('address');
    $qualification = wuc_input('qualification');

    // Use prepared statement to prevent SQL injection
    $sql = "UPDATE staff SET deptId=?, title=?, Fname=?, Lname=?, sex=?, country=?, 
            nrc_pass=?, mobile=?, email=?, address=?, qualification=? 
            WHERE staff_id=?";
    
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ssssssssssss', 
            $deptId, $title, $Fname, $Lname, $sex, $country, 
            $nrc_pass, $mobile, $email, $address, $qualification, $staff_id
        );
        
        if ($stmt->execute()) {
            wuc_flash('success', 'Your bio data has been updated successfully.');
        } else {
            wuc_flash('danger', 'Failed to update your bio data. Please try again.');
            error_log('editStaff update failed: ' . $stmt->error);
        }
        $stmt->close();
    } else {
        wuc_flash('danger', 'A system error occurred. Please try again.');
        error_log('editStaff prepare failed: ' . $db->error);
    }
    
    wuc_safe_redirect('editStaff.php?update=' . urlencode($staff_id));
}

// ─── Load Staff Record ─────────────────────────────────────────────
$staff_id = $_SESSION['staff_id'];
$record = null;

$stmt = $db->prepare("SELECT * FROM staff WHERE staff_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('s', $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $record = $result->fetch_object();
    }
    $stmt->close();
}

// Show flash messages
$flash = wuc_get_flash();
?>

<?php if ($flash): ?>
<div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show m-3" role="alert">
    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
    <?php echo htmlspecialchars($flash['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if (!$record): ?>
<div class="container-fluid px-4">
    <div class="alert alert-warning m-4">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Could not load your profile data. Please contact the system administrator.
        <a href="index.php" class="btn btn-primary btn-sm ms-3">← Dashboard</a>
    </div>
</div>
<?php else: ?>
<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Edit Bio Data</h1>
                <p class="text-muted">Update your personal information</p>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-10 mx-auto">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Bio Data</h5>
                    </div>
                </div>
                <div class="card-body">
                    <form action="editStaff.php" method="post" role="form">
                        <div class="table-responsive">
                            <table id="myTable" class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr><th colspan="2">Personal Information</th></tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th>Staff ID</th>
                                        <td><input type="text" class="form-control" name="staff_id" 
                                            value="<?php echo htmlspecialchars($record->staff_id); ?>" readonly></td>
                                    </tr>
                                    <tr>
                                        <th>Department ID</th>
                                        <td><input type="text" class="form-control" name="deptId" 
                                            value="<?php echo htmlspecialchars($record->deptId ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>Title</th>
                                        <td><input type="text" class="form-control" name="title" 
                                            value="<?php echo htmlspecialchars($record->title ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>First name</th>
                                        <td><input type="text" class="form-control" name="Fname" 
                                            value="<?php echo htmlspecialchars($record->Fname ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>Last name</th>
                                        <td><input type="text" class="form-control" name="Lname" 
                                            value="<?php echo htmlspecialchars($record->Lname ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>Gender</th>
                                        <td><input type="text" class="form-control" name="sex" 
                                            value="<?php echo htmlspecialchars($record->sex ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>Country</th>
                                        <td><input type="text" class="form-control" name="country" 
                                            value="<?php echo htmlspecialchars($record->country ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>NRC/Passport</th>
                                        <td><input type="text" class="form-control" name="nrc_pass" 
                                            value="<?php echo htmlspecialchars($record->nrc_pass ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>Mobile</th>
                                        <td><input type="text" class="form-control" name="mobile" 
                                            value="<?php echo htmlspecialchars($record->mobile ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>Email</th>
                                        <td><input type="email" class="form-control" name="email" 
                                            value="<?php echo htmlspecialchars($record->email ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>Home address</th>
                                        <td><input type="text" class="form-control" name="address" 
                                            value="<?php echo htmlspecialchars($record->address ?? ''); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th>Highest qualification</th>
                                        <td><input type="text" class="form-control" name="qualification" 
                                            value="<?php echo htmlspecialchars($record->qualification ?? ''); ?>"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-success" type="submit" name="update">
                                <i class="fas fa-save me-2"></i>Update
                            </button>
                            <a href="index.php" class="btn btn-secondary ms-2">
                                <i class="fas fa-arrow-left me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>


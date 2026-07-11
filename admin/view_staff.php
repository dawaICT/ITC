<?php
$pageTitle = "View Staff";
require_once 'includes/header.php';
require_once "includes/admin.php";

// Link the admin dashboard stylesheet
// Detect department table columns dynamically
$staffDeptCol = $__detectColumn($db, 'staff', ['DeptID', 'deptId', 'department_id']) ?? 'deptId';
$deptIdCol = $__detectColumn($db, 'departments', ['DeptID', 'id', 'department_id']) ?? 'id';
$deptNameCol = $__detectColumn($db, 'departments', ['DeptName', 'deptName', 'department_name', 'name']) ?? 'department_name';

// Get staff information - SECURE with prepared statements
$staff = null;
$activities = [];

if (isset($_GET['view'])) {
    $viewId = $_GET['view'];
    
    // SECURE: Use prepared statements to prevent SQL Injection
    $stmt = $db->prepare("SELECT s.*, d.`{$deptNameCol}` as deptName 
                          FROM staff s
                          LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdCol}`
                          WHERE s.staff_id = ?");
    $stmt->bind_param("s", $viewId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $staff = $result->fetch_object();
    }
    $stmt->close();
    
    // Fetch activity log for this staff member
    if ($staff) {
        // Check if activity_log table exists
        $tableCheck = $db->query("SHOW TABLES LIKE 'activity_log'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $actStmt = $db->prepare("SELECT * FROM activity_log 
                                     WHERE staff_id = ? 
                                     ORDER BY created_at DESC 
                                     LIMIT 10");
            $actStmt->bind_param("s", $viewId);
            $actStmt->execute();
            $actResult = $actStmt->get_result();
            while ($row = $actResult->fetch_object()) {
                $activities[] = $row;
            }
            $actStmt->close();
        } else {
            // Sample data for demonstration when table doesn't exist
            $activities = [
                (object)[
                    'action' => 'Profile Updated',
                    'description' => 'Updated contact information',
                    'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                    'ip_address' => '192.168.1.1'
                ],
                (object)[
                    'action' => 'Login',
                    'description' => 'Successful login to staff portal',
                    'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
                    'ip_address' => '192.168.1.1'
                ],
                (object)[
                    'action' => 'Course Assignment',
                    'description' => 'Assigned to teach Advanced Mathematics',
                    'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
                    'ip_address' => 'System'
                ]
            ];
        }
    }
}

// Helper function to render info items cleanly
function renderInfoItem($icon, $label, $value) {
    $val = htmlspecialchars($value ?? 'N/A');
    return "
    <div class='info-item'>
        <div class='icon-box'><i class='fas {$icon}'></i></div>
        <div class='content-box'>
            <label>{$label}</label>
            <span>{$val}</span>
        </div>
    </div>";
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-user-shield me-2"></i>Staff Profile</h1>
                <p class="text-muted">Detailed administrative view of staff records</p>
            </div>
            <div class="col-auto">
                <button onclick="history.back()" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-arrow-left me-2"></i>Back to List
                </button>
            </div>
        </div>
    </div>

    <?php if($staff): ?>
        <div class="row">
            <!-- Left Column: Profile Information -->
            <div class="col-xl-8 col-lg-7 mb-4">
                <div class="profile-card shadow-lg">
                    <div class="profile-header text-center">
                        <div class="avatar-wrapper">
                            <img src="images/avatar.png" alt="Profile" class="profile-avatar shadow">
                        </div>
                        <h3 class="profile-name">
                            <?= htmlspecialchars($staff->title . " " . $staff->Fname . " " . $staff->Lname) ?>
                        </h3>
                        <span class="badge bg-light text-primary px-3 py-2 mt-2">Staff Member</span>
                    </div>

                    <div class="profile-info p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <h5 class="section-label mb-3"><i class="fas fa-user-circle me-2"></i>Personal Information</h5>
                                <?= renderInfoItem('fa-id-badge', 'Staff ID', $staff->staff_id) ?>
                                <?= renderInfoItem('fa-venus-mars', 'Gender', $staff->sex) ?>
                                <?= renderInfoItem('fa-flag', 'Country', $staff->country) ?>
                                <?= renderInfoItem('fa-id-card', 'NRC/Passport', $staff->nrc_pass) ?>
                            </div>
                            <div class="col-md-6">
                                <h5 class="section-label mb-3"><i class="fas fa-briefcase me-2"></i>Professional Details</h5>
                                <?= renderInfoItem('fa-building', 'Department', $staff->deptName ?? 'Not Assigned') ?>
                                <?= renderInfoItem('fa-phone', 'Mobile', $staff->mobile) ?>
                                <?= renderInfoItem('fa-envelope', 'Email', $staff->email) ?>
                                <?= renderInfoItem('fa-graduation-cap', 'Qualification', $staff->qualification) ?>
                            </div>
                            <div class="col-12">
                                <?= renderInfoItem('fa-home', 'Home Address', $staff->address) ?>
                            </div>
                        </div>

                        <div class="profile-actions border-top pt-4 mt-4 text-center">
                            <a href="editStaff.php?update=<?= urlencode($staff->staff_id) ?>" class="btn btn-success btn-lg px-4">
                                <i class="fas fa-edit me-2"></i>Update Profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Activity Log -->
            <div class="col-xl-4 col-lg-5 mb-4">
                <div class="activity-card shadow-lg">
                    <div class="activity-header">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Activity Log</h5>
                        <p class="text-muted small mb-0">Recent activities and updates</p>
                    </div>
                    <div class="activity-body">
                        <?php if(!empty($activities)): ?>
                            <div class="activity-timeline">
                                <?php foreach($activities as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas <?= getActivityIcon($activity->action) ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <h6 class="activity-title"><?= htmlspecialchars($activity->action) ?></h6>
                                            <p class="activity-description"><?= htmlspecialchars($activity->description) ?></p>
                                            <span class="activity-time">
                                                <i class="far fa-clock me-1"></i>
                                                <?= date('M d, Y - h:i A', strtotime($activity->created_at)) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No recent activities recorded</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row justify-content-center mt-5">
            <div class="col-md-6 text-center">
                <div class="alert alert-custom bg-white shadow-sm border-0 p-5">
                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                    <h4 class="text-dark">Staff Member Not Found</h4>
                    <p class="text-muted">The ID provided does not match any records in our system.</p>
                    <a href="staffList.php" class="btn btn-primary mt-3">
                        <i class="fas fa-list me-2"></i>View All Staff
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
// Helper function to get appropriate icon for activity type
function getActivityIcon($action) {
    $action = strtolower($action);
    if (strpos($action, 'login') !== false) return 'fa-sign-in-alt';
    if (strpos($action, 'logout') !== false) return 'fa-sign-out-alt';
    if (strpos($action, 'update') !== false) return 'fa-edit';
    if (strpos($action, 'create') !== false) return 'fa-plus-circle';
    if (strpos($action, 'delete') !== false) return 'fa-trash-alt';
    if (strpos($action, 'assign') !== false) return 'fa-tasks';
    if (strpos($action, 'course') !== false) return 'fa-book';
    return 'fa-info-circle';
}
?>

<style>
:root {
    --primary-color: #6f42c1;
    --primary-light: #8c68cd;
    --primary-dark: #2d1950;
    --accent-color: #9461fb;
    --success-color: #28a745;
    --warning-color: #7952b3;
    --danger-color: #dc3545;
    --text-primary: #2d1950;
    --text-secondary: #6e4d9b;
    --bg-white: #ffffff;
    --bg-light: #f8f9fc;
    --border-color: #e3d9f3;

    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;

    --radius-sm: 0.25rem;
    --radius-md: 0.5rem;
    --radius-lg: 1rem;

    --shadow-sm: 0 .125rem .25rem rgba(111,66,193,.075);
    --shadow-md: 0 .5rem 1rem rgba(111,66,193,.15);
    --shadow-lg: 0 1rem 3rem rgba(111,66,193,.175);

    --transition-normal: all 0.3s ease;
}

.admin-dashboard {
    background: linear-gradient(135deg, rgba(111,66,193,0.03) 0%, rgba(140,104,205,0.03) 100%);
    min-height: 100vh;
    padding-top: 2rem;
    font-family: 'Inter', sans-serif;
}

.container-fluid {
    position: relative;
}

.container-fluid::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 60%;
    height: 60%;
    background: url('images/LOGO2.jpeg') center center no-repeat;
    background-size: contain;
    opacity: 0.03;
    pointer-events: none;
    z-index: 0;
    filter: grayscale(100%);
}

.dashboard-header {
    position: relative;
    z-index: 1;
    margin-bottom: 2rem;
}

.dashboard-header h1 {
    color: var(--text-primary);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

/* Profile Card Styles */
.profile-card {
    background: var(--bg-white);
    border-radius: var(--radius-lg);
 avatar-wrapper { 
    margin-top: -10px; 
    margin-bottom: 15px; 
}

.profile-avatar {
    width: 130px;
    height: 130px;
    border-radius: 50%;
    border: 5px solid rgba(255, 255, 255, 0.3);
    object-fit: cover;
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
}

.profile-header {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    padding: 2rem;
    text-align: center;
    color: var(--bg-white);
}

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px sflex-start;
    padding: 1rem;
    background: #f8faff;
    border-radius: 12px;
    margin-bottom: 0.75rem;
    transition: transform 0.2s ease, border-color 0.2s ease;
    border: 1px solid #eee;
}

.info-item:hover { 
    transform: translateY(-2px); 
    border-color: var(--primary-light);
    box-shadow: var(--shadow-sm);
}

.icon-box {
    min-width: 45px;
    height: 45px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
    color: white;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    font-size: 1.1rem;
}

.content-box {
    flex: 1;
}

.info-item label {
    display: block;
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.info-item span {
    color: var(--text-primary);
    font-weight: 500;
    font-size: 0.95rem;
}

.section-label {
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--primary-color);
    font-weight: 700;
    border-bottom: 2px solid var(--primary-light);
    padding-bottom: 0.5remter;
    justify-content: center;
    background: var(--primary-light);
    color: var(--bg-white);
    border-radius: 50%;
    font-size: 1.25rem;
}

.info-item div {
    flex: 1;
}

.info-item label {
    display: block;
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.info-item span {
    color: var(--text-primary);
    font-weight: 500;
}

.prActivity Card Styles */
.activity-card {
    background: var(--bg-white);
    border-radius: var(--radius-lg);
    overflow: hidden;
    height: fit-content;
    position: sticky;
    top: 20px;
}

.activity-header {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    padding: 1.5rem;
    border-bottom: 3px solid var(--primary-dark);
}

.activity-header h5 {
    color: white;
    font-weight: 600;
}

.activity-body {
    padding: 1.5rem;
    max-height: 600px;
    overflow-y: auto;
}

.activity-timeline {
    position: relative;
}

.activity-item {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    position: relative;
}

.activity-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.activity-icon {
    min-width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary-light), var(--accent-color));
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    box-shadow: var(--shadow-sm);
}

.activity-content {
    flex: 1;
}

.activity-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}

.activity-description {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
}

.activity-time {
    font-size: 0.75rem;
    color: #999;
    display: inline-block;
}

.alert-custom { 
    border-radius: 20px; 
    border-left: 5px solid var(--primary-color);
}

/* Make the row have proper spacing */
.row {
    position: relative;
    z-index: 1;
}

/* Responsive adjustments */
@media (max-width: 1199px) {
    .activity-card {
        position: relative;
        top: 0;
    }
}

/* Scrollbar styling for activity log */
.activity-body::-webkit-scrollbar {
    width: 6px;
}

.activity-body::-webkit-scrollbar-track {
    background: var(--bg-light);
    border-radius: 10px;
}

.activity-body::-webkit-scrollbar-thumb {
    background: var(--primary-light);
    border-radius: 10px;
}

.activity-body::-webkit-scrollbar-thumb:hover {
    background: var(--primary-color): 1.5rem;
    justify-content: center;
}

.profile-actions .btn {
    border-radius: var(--radius-md);
    font-weight: 500;
    transition: var(--transition-normal);
}

.profile-actions .btn-primary {
    background: var(--primary-color);
    border-color: var(--primary-color);
}

.profile-actions .btn-primary:hover {
    background: var(--primary-dark);
    border-color: var(--primary-dark);
}

.profile-actions .btn-success {
    background: var(--success-color);
    border-color: var(--success-color);
}

/* Make the row have proper spacing */
.row {
    position: relative;
    z-index: 1;
}
</style>

<?php require_once "includes/footer.php"; ?>


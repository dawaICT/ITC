<?php
$page_title = 'View Staff';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';

$hodStaffId = (string)($_SESSION['staff_id'] ?? '');
$requestedId = trim((string)($_GET['view'] ?? ''));
$staffRecord = null;
$accessDenied = false;

if ($requestedId !== '' && isset($db) && $db instanceof mysqli) {
    // A HOS may view their own profile or staff teaching within their section.
    // Systems admins (section switchers) may view any staff record.
    $allowed = ($requestedId === $hodStaffId)
        || (function_exists('hos_can_switch_sections') && hos_can_switch_sections($db, $hodStaffId));

    if (!$allowed) {
        $deptContext = hod_resolve_department($db, $hodStaffId);
        $sectionCourses = hod_section_course_codes($db, $deptContext, $hodStaffId);
        if ($sectionCourses !== [] && hod_table_exists($db, 'course_lecturer')) {
            $placeholders = implode(',', array_fill(0, count($sectionCourses), '?'));
            if ($stmt = @$db->prepare("SELECT 1 FROM course_lecturer WHERE staff_id = ? AND course_code IN ({$placeholders}) LIMIT 1")) {
                $params = array_merge([$requestedId], $sectionCourses);
                $stmt->bind_param(str_repeat('s', count($params)), ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                $allowed = $res && $res->num_rows > 0;
                $stmt->close();
            }
        }
    }

    if ($allowed) {
        if ($stmt = @$db->prepare("SELECT * FROM staff WHERE staff_id = ? LIMIT 1")) {
            $stmt->bind_param('s', $requestedId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                $staffRecord = $res->fetch_object() ?: null;
            }
            $stmt->close();
        }
    } else {
        $accessDenied = true;
        require_once dirname(__DIR__) . '/includes/audit.php';
        if (function_exists('audit_log_current_user')) {
            audit_log_current_user($db, 'security.hos_staff_view_denied', [
                'requested_staff_id' => $requestedId,
            ]);
        }
    }
}

$e = static function ($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
<div class="container-fluid px-4 portal-dashboard hod-page">
    <div class="row">
        <div class="col-lg-10 mx-auto">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i>View Staff</h5>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($accessDenied): ?>
                        <div class="alert alert-danger mb-0">
                            <i class="fas fa-ban me-2"></i>
                            You do not have permission to view this staff member. Only staff assigned to
                            courses in your section are visible to your Head of Section account.
                        </div>
                    <?php elseif ($staffRecord === null): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-user-slash fa-3x mb-3 d-block"></i>
                            <h5>Staff Member Not Found</h5>
                            <p class="small mb-0">Select a staff member from the Staff page to view their profile.</p>
                        </div>
                    <?php else: ?>
                        <h6 class="mb-3 fw-bold">Staff Personal Information</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <tbody>
                                    <tr><th class="table-light w-25">Staff ID</th><td><?php echo $e($staffRecord->staff_id ?? ''); ?></td></tr>
                                    <tr><th class="table-light">Title</th><td><?php echo $e($staffRecord->title ?? ''); ?></td></tr>
                                    <tr><th class="table-light">First name</th><td><?php echo $e($staffRecord->Fname ?? ''); ?></td></tr>
                                    <tr><th class="table-light">Last name</th><td><?php echo $e($staffRecord->Lname ?? ''); ?></td></tr>
                                    <tr><th class="table-light">Gender</th><td><?php echo $e($staffRecord->sex ?? ''); ?></td></tr>
                                    <tr><th class="table-light">Mobile</th><td><?php echo $e($staffRecord->mobile ?? ''); ?></td></tr>
                                    <tr><th class="table-light">Email</th><td><?php echo $e($staffRecord->email ?? ''); ?></td></tr>
                                    <tr><th class="table-light">Highest qualification</th><td><?php echo $e($staffRecord->qualification ?? ''); ?></td></tr>
                                    <tr><th class="table-light">Status</th><td><?php echo $e($staffRecord->status ?? ''); ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <div class="text-end">
                        <button onclick="history.back()" class="btn btn-outline-secondary">Back</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require "includes/footer.php"; ?>

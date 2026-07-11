<?php
$page_title = 'Course Registrations & Fee Status';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';

// Only show stats/overview for now
$stats = [];
$recent = [];
$staffId = (string)($_SESSION['staff_id'] ?? '');

if (isset($db) && $db instanceof mysqli) {
    $scopeJoin = " INNER JOIN course_lecturer cl
                    ON UPPER(TRIM(cl.course_code)) = UPPER(TRIM(cr.course_code))
                   AND cl.staff_id = ?
                   AND LOWER(COALESCE(cl.status, 'active')) IN ('active', 'assigned', 'current')";

    $countFor = function(string $where = '') use ($db, $staffId, $scopeJoin): int {
        $sql = "SELECT COUNT(*) AS cnt FROM course_registration cr {$scopeJoin} {$where}";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int)($row['cnt'] ?? 0);
        }
        error_log('lecturers/view_course_registrations.php: count prepare failed: ' . $db->error);
        return 0;
    };

    $distinctStudentsFor = function(string $where = '') use ($db, $staffId, $scopeJoin): int {
        $sql = "SELECT COUNT(DISTINCT cr.Sid) AS cnt FROM course_registration cr {$scopeJoin} {$where}";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int)($row['cnt'] ?? 0);
        }
        error_log('lecturers/view_course_registrations.php: student count prepare failed: ' . $db->error);
        return 0;
    };

    $stats['total'] = $countFor();
    $stats['active'] = $countFor('WHERE cr.is_active = 1');
    $stats['eligible'] = $distinctStudentsFor('WHERE cr.is_active = 1 AND cr.tuition_total > 0 AND (cr.amount_paid / cr.tuition_total) >= 0.5');
    $stats['ineligible'] = $distinctStudentsFor('WHERE cr.is_active = 1 AND cr.tuition_total > 0 AND (cr.amount_paid / cr.tuition_total) < 0.5');

    $sql = "SELECT cr.*,
                   cr.created_at AS registration_date,
                   ROUND((cr.amount_paid / NULLIF(cr.tuition_total, 0)) * 100, 2) AS payment_percent,
                   c.course_name,
                   TRIM(CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, ''))) AS student_name
            FROM course_registration cr
            {$scopeJoin}
            LEFT JOIN courses c ON UPPER(TRIM(c.course_code)) = UPPER(TRIM(cr.course_code))
            LEFT JOIN students s ON s.SID = cr.Sid
            ORDER BY cr.created_at DESC, cr.id DESC
            LIMIT 10";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        if ($res = $stmt->get_result()) {
            while ($row = $res->fetch_assoc()) {
                $recent[] = $row;
            }
        }
        $stmt->close();
    } else {
        error_log('lecturers/view_course_registrations.php: recent query prepare failed: ' . $db->error);
    }
}

require "includes/nav.php";
?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page">
    <div class="dashboard-header lecturer-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Course Registrations & Fee Status</h1>
                <p class="text-muted">Monitor student registrations and payment eligibility for CA uploads</p>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Total Registrations</h6>
                    <h2 class="mb-0"><?php echo number_format($stats['total'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Active Registrations</h6>
                    <h2 class="mb-0 text-success"><?php echo number_format($stats['active'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body">
                    <h6 class="text-muted mb-2">CA Eligible (≥50%)</h6>
                    <h2 class="mb-0 text-success"><?php echo number_format($stats['eligible'] ?? 0); ?></h2>
                    <small class="text-muted">Students with sufficient payment</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-danger">
                <div class="card-body">
                    <h6 class="text-muted mb-2">CA Ineligible (<50%)</h6>
                    <h2 class="mb-0 text-danger"><?php echo number_format($stats['ineligible'] ?? 0); ?></h2>
                    <small class="text-muted">Students below payment threshold</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Registrations Table -->
    <div class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Recent Course Registrations</h5>
        </div>
        <div class="card-body">
            <?php if (empty($recent)): ?>
                <div class="alert alert-info">
                    No course registrations found. 
                    <a href="sample_course_registration_data.php" class="alert-link">Generate sample data</a> 
                    or ensure students are properly registered for courses.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Y/S</th>
                                <th>Type</th>
                                <th>Due</th>
                                <th>Paid</th>
                                <th>%</th>
                                <th>Status</th>
                                <th>Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $r): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['Sid']); ?></strong>
                                    <?php if ($r['student_name']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($r['student_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['course_code']); ?></strong>
                                    <?php if ($r['course_name']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($r['course_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($r['Year'].'/'.$r['semester']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string)($r['program_type'] ?? 'semester'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td>$<?php echo number_format((float)($r['tuition_total'] ?? 0), 2); ?></td>
                                <td>$<?php echo number_format((float)($r['amount_paid'] ?? 0), 2); ?></td>
                                <td>
                                    <?php 
                                    $pct = $r['payment_percent'] ?? 0;
                                    $color = $pct >= 50 ? 'success' : 'danger';
                                    ?>
                                    <span class="badge bg-<?php echo $color; ?>"><?php echo number_format($pct, 1); ?>%</span>
                                </td>
                                <td>
                                    <?php if (!empty($r['is_active'])): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?php echo !empty($r['registration_date']) ? date('M j, Y', strtotime((string)$r['registration_date'])) : '-'; ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Help Section -->
    <div class="card mt-4 bg-light">
        <div class="card-body">
            <h6><i class="fas fa-info-circle me-2"></i>About Course Registration Fee Tracking</h6>
            <p class="mb-2">The CA Upload Module uses the <code>course_registration</code> table to enforce payment requirements:</p>
            <ul class="mb-2">
                <li>Students must have paid <strong>at least 50%</strong> of their tuition to receive CA marks</li>
                <li>Each course registration tracks individual fees (<code>tuition_total</code>) and payments (<code>amount_paid</code>)</li>
                <li>Only <strong>active</strong> registrations are considered when uploading grades</li>
                <li>Inactive registrations (deferred/suspended students) are automatically excluded</li>
            </ul>
            <hr>
            <h6>Utilities</h6>
            <div class="d-flex gap-2">
                <a href="ensure_course_registration_table.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-database me-1"></i>Run Migration
                </a>
                <a href="sample_course_registration_data.php" class="btn btn-sm btn-outline-success">
                    <i class="fas fa-plus me-1"></i>Generate Sample Data
                </a>
                <a href="upload_ca.php" class="btn btn-sm btn-warning">
                    <i class="fas fa-upload me-1"></i>Upload CA Marks
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

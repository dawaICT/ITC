<?php
// department_details.php
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/department_schema_helpers.php';
require "includes/header.php";
$deptId = trim((string)($_GET['id'] ?? ''));

if ($deptId === '') {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Department ID is required.</div></div>";
    require "includes/footer.php";
    exit;
}

// 1. Fetch Department & HOS Info (schema-aware: id vs department_id, SSA vs hod_id)
$dept = wuc_department_fetch_detail($db, $deptId);

if (!$dept) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Department not found.</div></div>";
    require "includes/footer.php";
    exit;
}

// 2. Fetch Programs for this specific Department
$programRows = [];
$progStmt = wuc_department_programs_stmt($db, $deptId);
if ($progStmt) {
    $progRes = $progStmt->get_result();
    while ($row = $progRes->fetch_assoc()) {
        $programRows[] = $row;
    }
    $progStmt->close();
}

function formatProgramDurationYears($years): string {
    if ($years === null || $years === '' || (float)$years <= 0) {
        return 'N/A';
    }
    $years = round((float)$years, 2);
    if ($years < 1) {
        $months = max(1, (int)round($years * 12));
        return $months . ' month' . ($months === 1 ? '' : 's');
    }
    $label = rtrim(rtrim(number_format($years, 2, '.', ''), '0'), '.');
    return $label . ' year' . ($years == 1.0 ? '' : 's');
}
?>

<div class="container-fluid px-4 py-4">
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body bg-light rounded">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1"><?php echo htmlspecialchars($dept['department_name']); ?></h2>
                    <p class="text-muted">Faculty of <?php echo htmlspecialchars($dept['faculty']); ?> | Code: <strong><?php echo htmlspecialchars($dept['department_id']); ?></strong></p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="export_department_pdf.php?id=<?php echo urlencode($deptId); ?>" class="btn btn-danger me-2" target="_blank">
                        <i class="fas fa-file-pdf me-1"></i> Export PDF
                    </a>
                    <span class="badge <?php echo $dept['status'] == 'Active' ? 'bg-success' : 'bg-secondary'; ?> fs-6">
                        <?php echo htmlspecialchars($dept['status']); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-4 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-white fw-bold"><i class="fas fa-user-tie me-2"></i>Head of Section</div>
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-user-circle fa-4x text-primary"></i>
                    </div>
                    <h5><?php echo htmlspecialchars(($dept['title'] ?? '') . " " . $dept['Fname'] . " " . $dept['Lname']); ?></h5>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($dept['email'] ?? 'No email assigned'); ?></p>
                </div>
            </div>
        </div>

        <div class="col-xl-8 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="fas fa-graduation-cap me-2"></i>Offered Programs</span>
                    <span class="badge bg-primary"><?php echo count($programRows); ?> Total</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Program Name</th>
                                    <th>Type</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($programRows) > 0): ?>
                                    <?php foreach ($programRows as $p): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($p['program_code']); ?></code></td>
                                        <td><?php echo htmlspecialchars($p['program_name']); ?></td>
                                        <td class="text-capitalize"><?php echo htmlspecialchars($p['program_type']); ?></td>
                                        <td><?php echo htmlspecialchars(formatProgramDurationYears($p['program_duration'] ?? null)); ?></td>
                                        <td>
                                            <i class="fas fa-circle <?php echo $p['is_active'] ? 'text-success' : 'text-danger'; ?> small"></i>
                                            <?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center py-4">No programs assigned to this department.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include "includes/footer.php"; ?>

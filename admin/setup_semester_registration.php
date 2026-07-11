<?php
require_once "includes/admin.php";

$page_title = 'Registration Setup';

function registration_setup_table_exists(mysqli $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS c
                          FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
    $stmt->close();
    return $exists;
}

function registration_setup_columns(mysqli $db, string $table): array {
    $stmt = $db->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
                          FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                          ORDER BY ORDINAL_POSITION");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $columns = [];
    while ($row = $res->fetch_assoc()) {
        $columns[$row['COLUMN_NAME']] = $row;
    }
    $stmt->close();
    return $columns;
}

function registration_setup_period_counts(mysqli $db): array {
    if (!registration_setup_table_exists($db, 'academic_periods')) {
        return [];
    }

    $counts = [];
    $res = $db->query("SELECT period_type,
                              COUNT(*) AS total_periods,
                              SUM(CASE WHEN is_current = 1 THEN 1 ELSE 0 END) AS current_periods
                       FROM academic_periods
                       GROUP BY period_type");
    while ($row = $res->fetch_assoc()) {
        $counts[$row['period_type']] = $row;
    }
    $res->free();
    return $counts;
}

$requiredRegistrationColumns = [
    'student_id' => 'Student identifier used by admin registration',
    'program_code' => 'Program selected for the student',
    'semester' => 'Numeric academic period value; used for semesters and terms',
    'period_type' => "Period kind: 'semester' or 'term'",
    'year_of_study' => 'Student academic year level',
    'academic_year' => 'Current academic year label/value',
    'registration_date' => 'Date/time the registration was completed',
];

$requiredAcademicPeriodColumns = [
    'academic_year' => 'Academic year label',
    'period_type' => "Period kind: 'semester' or 'term'",
    'semester_term' => 'Human readable period name or number',
    'start_date' => 'Period start date',
    'end_date' => 'Period end date',
    'is_current' => 'Marks the active period per type',
    'status' => 'upcoming, active, or completed',
];

$registrationTableReady = registration_setup_table_exists($db, 'semester_registration');
$academicPeriodsReady = registration_setup_table_exists($db, 'academic_periods');
$registrationColumns = $registrationTableReady ? registration_setup_columns($db, 'semester_registration') : [];
$academicPeriodColumns = $academicPeriodsReady ? registration_setup_columns($db, 'academic_periods') : [];
$missingRegistration = array_diff(array_keys($requiredRegistrationColumns), array_keys($registrationColumns));
$missingAcademicPeriods = array_diff(array_keys($requiredAcademicPeriodColumns), array_keys($academicPeriodColumns));
$periodCounts = registration_setup_period_counts($db);

$registrationReady = $registrationTableReady && empty($missingRegistration);
$periodsReady = $academicPeriodsReady && empty($missingAcademicPeriods);

// This SQL is displayed for a privileged database administrator. The web page
// intentionally does not execute DDL because the live app user may not have
// CREATE/ALTER privileges and should not crash the module.
$recommendedSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS semester_registration (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NULL,
    SID VARCHAR(50) NULL,
    program_code VARCHAR(20) NOT NULL,
    semester VARCHAR(1) NOT NULL,
    period_type ENUM('semester','term') NOT NULL DEFAULT 'semester',
    year_of_study INT NOT NULL,
    Year INT NOT NULL DEFAULT 1,
    academic_year VARCHAR(20) NOT NULL,
    registration_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_student_id (student_id),
    KEY idx_sid (SID),
    KEY idx_program_period (program_code, period_type, semester, year_of_study)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL,
    period_type ENUM('semester','term') NOT NULL,
    semester_term VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('upcoming','active','completed') NOT NULL DEFAULT 'upcoming',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_period_lookup (academic_year, period_type, is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

require_once "includes/header.php";
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-tools me-2 text-primary"></i>Registration Setup</h5>
                <p class="page-subtitle mb-0">Read-only backend readiness check for semester and term registration.</p>
            </div>
            <div class="header-actions">
                <a href="semester_registration.php" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-user-edit me-1"></i>Term Registration
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon <?= $registrationReady ? 'bg-success' : 'bg-warning' ?> me-3 text-white">
                        <i class="fas fa-database"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= $registrationReady ? 'Ready' : 'Needs Setup' ?></h4>
                        <p class="text-muted mb-0">semester_registration</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon <?= $periodsReady ? 'bg-success' : 'bg-warning' ?> me-3 text-white">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= $periodsReady ? 'Ready' : 'Needs Setup' ?></h4>
                        <p class="text-muted mb-0">academic_periods</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="data-table-card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Schema Check</h5></div>
        <div class="card-body">
            <?php if (!$registrationReady || !$periodsReady): ?>
                <div class="alert alert-warning">
                    One or more registration tables are missing required fields. Run the SQL below with a privileged database account.
                </div>
            <?php else: ?>
                <div class="alert alert-success">Registration tables contain the required semester and term fields.</div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-lg-6">
                    <h6 class="fw-bold">semester_registration</h6>
                    <ul class="list-group small">
                        <?php foreach ($requiredRegistrationColumns as $column => $desc): ?>
                            <li class="list-group-item d-flex justify-content-between gap-3">
                                <span><code><?= htmlspecialchars($column) ?></code><br><span class="text-muted"><?= htmlspecialchars($desc) ?></span></span>
                                <span class="badge <?= isset($registrationColumns[$column]) ? 'bg-success' : 'bg-danger' ?>">
                                    <?= isset($registrationColumns[$column]) ? 'OK' : 'Missing' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <h6 class="fw-bold">academic_periods</h6>
                    <ul class="list-group small">
                        <?php foreach ($requiredAcademicPeriodColumns as $column => $desc): ?>
                            <li class="list-group-item d-flex justify-content-between gap-3">
                                <span><code><?= htmlspecialchars($column) ?></code><br><span class="text-muted"><?= htmlspecialchars($desc) ?></span></span>
                                <span class="badge <?= isset($academicPeriodColumns[$column]) ? 'bg-success' : 'bg-danger' ?>">
                                    <?= isset($academicPeriodColumns[$column]) ? 'OK' : 'Missing' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="data-table-card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-stream me-2"></i>Configured Period Types</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Period Type</th><th>Total Periods</th><th>Current Rows</th></tr></thead>
                    <tbody>
                    <?php foreach (['semester', 'term'] as $type): $row = $periodCounts[$type] ?? null; ?>
                        <tr>
                            <td><?= ucfirst($type) ?></td>
                            <td><?= (int)($row['total_periods'] ?? 0) ?></td>
                            <td><?= (int)($row['current_periods'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="data-table-card">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-code me-2"></i>Recommended SQL</h5></div>
        <div class="card-body">
            <pre class="bg-light border rounded p-3 small mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars($recommendedSql) ?></pre>
        </div>
    </div>
</div>

<?php require_once "includes/footer.php"; ?>

<?php
/**
 * Transport Management — Reports & Summaries.
 *
 * Period-based and snapshot reporting across the TSMS tables, with on-screen
 * summary cards, printable tables and per-report CSV export. Kept as a
 * standalone page (rather than a section of transport_management.php) so the
 * CSV download can stream before any HTML is emitted.
 */

require_once __DIR__ . '/includes/transport.php';
require_once dirname(__DIR__) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/report_print.php';

global $db;
if (!isset($db) || !($db instanceof mysqli)) {
    throw new RuntimeException('Transport reports require an active database connection.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */
function tr_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tr_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function tr_query(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    if ($types === '') {
        $res = $db->query($sql);
        if (!$res) {
            error_log('Transport report query failed: ' . $db->error);
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
        return $rows;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('Transport report prepare failed: ' . $db->error);
        return [];
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        error_log('Transport report execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $rows = [];
    while ($res && $row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function tr_scalar(mysqli $db, string $sql, string $types = '', array $params = []): float
{
    $rows = tr_query($db, $sql, $types, $params);
    if (!$rows) {
        return 0.0;
    }
    $first = reset($rows[0]);
    return (float)$first;
}

function tr_money($value): string
{
    return 'K' . number_format((float)$value, 2);
}

function tr_status_badge(string $value): string
{
    $key = strtolower(trim($value));
    $map = [
        'valid' => 'success', 'active' => 'success', 'available' => 'success', 'cleared' => 'success',
        'completed' => 'success', 'resolved' => 'success', 'fit' => 'success', 'paid' => 'success',
        'due soon' => 'warning text-dark', 'scheduled' => 'warning text-dark', 'pending' => 'warning text-dark',
        'in_progress' => 'info text-dark', 'in_review' => 'info text-dark', 'assigned' => 'info text-dark',
        'enrolled' => 'info text-dark', 'planning' => 'secondary', 'on_leave' => 'secondary',
        'inactive' => 'secondary', 'not_required' => 'secondary',
        'expired' => 'danger', 'unfit' => 'danger', 'defect_reported' => 'danger', 'overdue' => 'danger',
        'maintenance' => 'danger', 'unavailable' => 'danger', 'withdrawn' => 'danger', 'failed' => 'danger',
        'cancelled' => 'dark', 'open' => 'danger',
    ];
    $cls = $map[$key] ?? 'secondary';
    $label = $value === '' ? '—' : ucwords(str_replace('_', ' ', $value));
    return '<span class="badge bg-' . $cls . '">' . tr_h($label) . '</span>';
}

/** Format a single cell for on-screen display. */
function tr_fmt_display(string $type, $value): string
{
    switch ($type) {
        case 'int':
            return number_format((float)$value);
        case 'num1':
            return number_format((float)$value, 1);
        case 'money':
            return tr_money($value);
        case 'date':
            $value = (string)$value;
            return $value === '' || $value === '0000-00-00' ? '<span class="text-muted">—</span>' : tr_h($value);
        case 'datetime':
            $value = (string)$value;
            return $value === '' ? '<span class="text-muted">—</span>' : tr_h(substr($value, 0, 16));
        case 'badge':
            return tr_status_badge((string)$value);
        case 'text':
        default:
            $value = (string)$value;
            return $value === '' ? '<span class="text-muted">—</span>' : tr_h($value);
    }
}

/** Format a single cell for CSV (plain text, no markup or currency symbols). */
function tr_fmt_csv(string $type, $value): string
{
    switch ($type) {
        case 'int':
            return (string)(int)$value;
        case 'num1':
            return number_format((float)$value, 1, '.', '');
        case 'money':
            return number_format((float)$value, 2, '.', '');
        case 'badge':
            return ucwords(str_replace('_', ' ', (string)$value));
        case 'datetime':
            return substr((string)$value, 0, 16);
        case 'date':
            $value = (string)$value;
            return $value === '0000-00-00' ? '' : $value;
        default:
            return (string)$value;
    }
}

/* ---------------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------------- */
function tr_valid_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : $fallback;
}

$today = date('Y-m-d');
$from = tr_valid_date($_GET['from'] ?? '', date('Y-01-01'));
$to = tr_valid_date($_GET['to'] ?? '', $today);
if (strtotime($from) > strtotime($to)) {
    [$from, $to] = [$to, $from];
}
$campusId = max(0, (int)($_GET['campus_id'] ?? 0));

$needsInstall = !tr_table_exists($db, 'transport_enrollments');

$campuses = [];
$campusName = 'All campuses';
if (!$needsInstall) {
    $campuses = tr_query($db, "SELECT id, campus_name FROM transport_campuses ORDER BY campus_name");
    if ($campusId > 0) {
        foreach ($campuses as $c) {
            if ((int)$c['id'] === $campusId) {
                $campusName = (string)$c['campus_name'];
            }
        }
        if ($campusName === 'All campuses') {
            $campusId = 0; // invalid id supplied
        }
    }
}

/* ---------------------------------------------------------------------------
 * Report registry 
 * ------------------------------------------------------------------------- */
$reports = [
    'enrollment_summary' => [
        'title' => 'Enrolment & Revenue Summary',
        'icon' => 'fas fa-user-graduate',
        'scope' => 'range',
        'note' => 'Trainee enrolments grouped by cohort, with fees billed and collected.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['program_code', 'Program', 'text'],
            ['cohort_name', 'Cohort', 'text'],
            ['enrolled', 'Enrolled', 'int'],
            ['completed', 'Completed', 'int'],
            ['withdrawn', 'Withdrawn', 'int'],
            ['fee_billed', 'Fees Billed', 'money'],
            ['amount_paid', 'Collected', 'money'],
            ['outstanding', 'Outstanding', 'money'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, p.program_code, c.cohort_name,
                       COUNT(e.id) AS enrolled,
                       SUM(e.status = 'completed') AS completed,
                       SUM(e.status = 'withdrawn') AS withdrawn,
                       COALESCE(SUM(e.fee_amount), 0) AS fee_billed,
                       COALESCE(SUM(e.amount_paid), 0) AS amount_paid,
                       COALESCE(SUM(e.fee_amount - e.amount_paid), 0) AS outstanding
                    FROM transport_enrollments e
                    INNER JOIN transport_cohorts c ON c.id = e.cohort_id
                    INNER JOIN transport_programs p ON p.id = c.program_id
                    INNER JOIN transport_campuses ca ON ca.id = c.campus_id
                    WHERE e.enrollment_date BETWEEN ? AND ?";
            $types = 'ss';
            $params = [$from, $to];
            if ($campusId > 0) { $sql .= " AND c.campus_id = ?"; $types .= 'i'; $params[] = $campusId; }
            $sql .= " GROUP BY c.id ORDER BY ca.campus_name, p.program_code, c.cohort_name";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'session_delivery' => [
        'title' => 'Training Delivery & Contact Hours',
        'icon' => 'fas fa-chalkboard-user',
        'scope' => 'range',
        'note' => 'Sessions delivered and RTSA contact hours by cohort and instructor.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['cohort_name', 'Cohort', 'text'],
            ['program_code', 'Program', 'text'],
            ['instructor', 'Instructor', 'text'],
            ['sessions', 'Sessions', 'int'],
            ['completed', 'Completed', 'int'],
            ['cancelled', 'Cancelled', 'int'],
            ['contact_hours', 'Contact Hrs', 'num1'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, c.cohort_name, p.program_code, i.full_name AS instructor,
                       COUNT(s.id) AS sessions,
                       SUM(s.status = 'completed') AS completed,
                       SUM(s.status = 'cancelled') AS cancelled,
                       COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.contact_hours ELSE 0 END), 0) AS contact_hours
                    FROM transport_sessions s
                    INNER JOIN transport_cohorts c ON c.id = s.cohort_id
                    INNER JOIN transport_programs p ON p.id = c.program_id
                    INNER JOIN transport_campuses ca ON ca.id = c.campus_id
                    INNER JOIN transport_instructors i ON i.id = s.instructor_id
                    WHERE s.session_date BETWEEN ? AND ?";
            $types = 'ss';
            $params = [$from, $to];
            if ($campusId > 0) { $sql .= " AND c.campus_id = ?"; $types .= 'i'; $params[] = $campusId; }
            $sql .= " GROUP BY c.id, i.id ORDER BY ca.campus_name, c.cohort_name, i.full_name";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'instructor_compliance' => [
        'title' => 'Instructor Accreditation Status',
        'icon' => 'fas fa-id-card',
        'scope' => 'snapshot',
        'note' => 'Current RTSA/TEVETA accreditation standing for every instructor.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['full_name', 'Instructor', 'text'],
            ['status', 'Status', 'badge'],
            ['license_classes', 'Licence Classes', 'text'],
            ['rtsa_expiry', 'RTSA Expiry', 'date'],
            ['teveta_expiry', 'TEVETA Expiry', 'date'],
            ['accreditation', 'Accreditation', 'badge'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, i.full_name, i.status, i.license_classes,
                       i.rtsa_expiry, i.teveta_expiry,
                       CASE
                         WHEN (i.rtsa_expiry IS NOT NULL AND i.rtsa_expiry < CURDATE())
                            OR (i.teveta_expiry IS NOT NULL AND i.teveta_expiry < CURDATE()) THEN 'Expired'
                         WHEN (i.rtsa_expiry IS NOT NULL AND i.rtsa_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY))
                            OR (i.teveta_expiry IS NOT NULL AND i.teveta_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)) THEN 'Due soon'
                         ELSE 'Valid'
                       END AS accreditation
                    FROM transport_instructors i
                    INNER JOIN transport_campuses ca ON ca.id = i.campus_id";
            $types = '';
            $params = [];
            if ($campusId > 0) { $sql .= " WHERE i.campus_id = ?"; $types = 'i'; $params[] = $campusId; }
            $sql .= " ORDER BY FIELD(i.status, 'active','on_leave','inactive'), ca.campus_name, i.full_name";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'fleet_status' => [
        'title' => 'Fleet Register & Compliance',
        'icon' => 'fas fa-truck',
        'scope' => 'snapshot',
        'note' => 'Vehicle status, mileage and fitness/insurance/service due dates.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['registration_no', 'Reg. No.', 'text'],
            ['vehicle_type', 'Type', 'badge'],
            ['status', 'Status', 'badge'],
            ['current_mileage', 'Mileage', 'int'],
            ['fitness_expiry', 'Fitness', 'date'],
            ['insurance_expiry', 'Insurance', 'date'],
            ['next_service_due', 'Next Service', 'date'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, v.registration_no, v.vehicle_type, v.status, v.current_mileage,
                       v.fitness_expiry, v.insurance_expiry, v.next_service_due
                    FROM transport_vehicles v
                    INNER JOIN transport_campuses ca ON ca.id = v.campus_id";
            $types = '';
            $params = [];
            if ($campusId > 0) { $sql .= " WHERE v.campus_id = ?"; $types = 'i'; $params[] = $campusId; }
            $sql .= " ORDER BY FIELD(v.status, 'maintenance','unavailable','assigned','available'), v.registration_no";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'preuse_summary' => [
        'title' => 'Pre-use Inspection Summary',
        'icon' => 'fas fa-clipboard-check',
        'scope' => 'range',
        'note' => 'Pre-use checks recorded per vehicle, with defect and unfit counts.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['registration_no', 'Reg. No.', 'text'],
            ['checks', 'Checks', 'int'],
            ['fit', 'Fit', 'int'],
            ['defects', 'Defects', 'int'],
            ['unfit', 'Unfit', 'int'],
            ['last_check', 'Last Check', 'date'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, v.registration_no,
                       COUNT(pc.id) AS checks,
                       SUM(pc.overall_status = 'fit') AS fit,
                       SUM(pc.overall_status = 'defect_reported') AS defects,
                       SUM(pc.overall_status = 'unfit') AS unfit,
                       MAX(pc.checklist_date) AS last_check
                    FROM transport_preuse_checks pc
                    INNER JOIN transport_vehicles v ON v.id = pc.vehicle_id
                    INNER JOIN transport_campuses ca ON ca.id = v.campus_id
                    WHERE pc.checklist_date BETWEEN ? AND ?";
            $types = 'ss';
            $params = [$from, $to];
            if ($campusId > 0) { $sql .= " AND v.campus_id = ?"; $types .= 'i'; $params[] = $campusId; }
            $sql .= " GROUP BY v.id ORDER BY ca.campus_name, v.registration_no";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'vehicle_operations' => [
        'title' => 'Fleet Maintenance & Fuel',
        'icon' => 'fas fa-gas-pump',
        'scope' => 'range',
        'note' => 'Service events, fuel/energy added and consumption per vehicle in the period.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['registration_no', 'Reg. No.', 'text'],
            ['services', 'Services', 'int'],
            ['fuel_events', 'Fuel Events', 'int'],
            ['fuel_added', 'Added', 'num1'],
            ['consumption', 'Consumption', 'num1'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, v.registration_no,
                       COALESCE(m.service_count, 0) AS services,
                       COALESCE(f.fuel_events, 0) AS fuel_events,
                       COALESCE(f.fuel_added, 0) AS fuel_added,
                       COALESCE(f.consumption, 0) AS consumption
                    FROM transport_vehicles v
                    INNER JOIN transport_campuses ca ON ca.id = v.campus_id
                    LEFT JOIN (
                        SELECT vehicle_id, COUNT(*) AS service_count
                        FROM transport_maintenance_logs
                        WHERE service_date BETWEEN ? AND ?
                        GROUP BY vehicle_id
                    ) m ON m.vehicle_id = v.id
                    LEFT JOIN (
                        SELECT vehicle_id, COUNT(*) AS fuel_events,
                               SUM(amount_added) AS fuel_added,
                               SUM(consumption) AS consumption
                        FROM transport_fuel_logs
                        WHERE fuel_date BETWEEN ? AND ?
                        GROUP BY vehicle_id
                    ) f ON f.vehicle_id = v.id";
            $types = 'ssss';
            $params = [$from, $to, $from, $to];
            if ($campusId > 0) { $sql .= " WHERE v.campus_id = ?"; $types .= 'i'; $params[] = $campusId; }
            $sql .= " HAVING services > 0 OR fuel_events > 0 ORDER BY ca.campus_name, v.registration_no";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'client_billing' => [
        'title' => 'Corporate Client Billing',
        'icon' => 'fas fa-building',
        'scope' => 'snapshot',
        'note' => 'Enrolments and outstanding balances per corporate client (all-time).',
        'columns' => [
            ['client_name', 'Client', 'text'],
            ['status', 'Status', 'badge'],
            ['active_enrollments', 'Enrolments', 'int'],
            ['total_billed', 'Billed', 'money'],
            ['total_paid', 'Paid', 'money'],
            ['outstanding', 'Outstanding', 'money'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            return tr_query($db, "
                SELECT cc.client_name, cc.status,
                       COALESCE(ce.cnt, 0) AS active_enrollments,
                       COALESCE(ce.billed, 0) AS total_billed,
                       COALESCE(ce.paid, 0) AS total_paid,
                       COALESCE(ce.balance, 0) AS outstanding
                FROM transport_corporate_clients cc
                LEFT JOIN (
                    SELECT corporate_client_id,
                           COUNT(*) AS cnt,
                           SUM(fee_amount) AS billed,
                           SUM(amount_paid) AS paid,
                           SUM(fee_amount - amount_paid) AS balance
                    FROM transport_enrollments
                    WHERE corporate_client_id IS NOT NULL
                    GROUP BY corporate_client_id
                ) ce ON ce.corporate_client_id = cc.id
                ORDER BY cc.client_name");
        },
    ],
    'incident_summary' => [
        'title' => 'Incident Reports',
        'icon' => 'fas fa-triangle-exclamation',
        'scope' => 'range',
        'note' => 'Safety and vehicle incidents logged in the period.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['registration_no', 'Reg. No.', 'text'],
            ['incident_time', 'When', 'datetime'],
            ['location', 'Location', 'text'],
            ['instructor', 'Instructor', 'text'],
            ['status', 'Status', 'badge'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, v.registration_no, ir.incident_time, ir.location,
                       COALESCE(i.full_name, '') AS instructor, ir.status
                    FROM transport_incident_reports ir
                    INNER JOIN transport_vehicles v ON v.id = ir.vehicle_id
                    INNER JOIN transport_campuses ca ON ca.id = v.campus_id
                    LEFT JOIN transport_instructors i ON i.id = ir.instructor_id
                    WHERE ir.incident_time >= ? AND ir.incident_time < DATE_ADD(?, INTERVAL 1 DAY)";
            $types = 'ss';
            $params = [$from, $to];
            if ($campusId > 0) { $sql .= " AND v.campus_id = ?"; $types .= 'i'; $params[] = $campusId; }
            $sql .= " ORDER BY ir.incident_time DESC";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'recruitment_funnel' => [
        'title' => 'Recruitment Funnel',
        'icon' => 'fas fa-filter',
        'scope' => 'range',
        'note' => 'Trainees recruited in the period, how many started (reported) and how many were trained (completed), by program.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['program_code', 'Program', 'text'],
            ['program_name', 'Programme', 'text'],
            ['recruited', 'Recruited', 'int'],
            ['reported', 'Reported/Started', 'int'],
            ['trained', 'Trained', 'int'],
            ['withdrawn', 'Withdrawn', 'int'],
            ['failed', 'Failed', 'int'],
            ['completion_rate', 'Completion', 'text'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, p.program_code, p.program_name,
                       COUNT(e.id) AS recruited,
                       SUM(e.status IN ('active','completed')) AS reported,
                       SUM(e.status = 'completed') AS trained,
                       SUM(e.status = 'withdrawn') AS withdrawn,
                       SUM(e.status = 'failed') AS failed,
                       CONCAT(ROUND(100 * SUM(e.status='completed') / NULLIF(COUNT(e.id),0)), '%') AS completion_rate
                    FROM transport_enrollments e
                    INNER JOIN transport_cohorts c ON c.id = e.cohort_id
                    INNER JOIN transport_programs p ON p.id = c.program_id
                    INNER JOIN transport_campuses ca ON ca.id = c.campus_id
                    WHERE e.enrollment_date BETWEEN ? AND ?";
            $types = 'ss'; $params = [$from, $to];
            if ($campusId > 0) { $sql .= " AND c.campus_id = ?"; $types .= 'i'; $params[] = $campusId; }
            $sql .= " GROUP BY p.id, ca.id ORDER BY recruited DESC, ca.campus_name, p.program_code";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'training_throughput' => [
        'title' => 'Training Throughput',
        'icon' => 'fas fa-people-arrows',
        'scope' => 'snapshot',
        'note' => 'Per-cohort outcome breakdown: enrolled, in-progress, trained, dropped and certificates issued.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['cohort_name', 'Cohort', 'text'],
            ['program_code', 'Program', 'text'],
            ['cohort_status', 'Cohort', 'badge'],
            ['enrolled', 'Enrolled', 'int'],
            ['in_progress', 'In progress', 'int'],
            ['trained', 'Trained', 'int'],
            ['dropped', 'Dropped', 'int'],
            ['certified', 'Certified', 'int'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, c.cohort_name, p.program_code, c.status AS cohort_status,
                       COUNT(e.id) AS enrolled,
                       SUM(e.status = 'active') AS in_progress,
                       SUM(e.status = 'completed') AS trained,
                       SUM(e.status IN ('withdrawn','failed')) AS dropped,
                       SUM(e.certificate_issued = 1) AS certified
                    FROM transport_cohorts c
                    INNER JOIN transport_programs p ON p.id = c.program_id
                    INNER JOIN transport_campuses ca ON ca.id = c.campus_id
                    LEFT JOIN transport_enrollments e ON e.cohort_id = c.id";
            $types = ''; $params = [];
            if ($campusId > 0) { $sql .= " WHERE c.campus_id = ?"; $types = 'i'; $params[] = $campusId; }
            $sql .= " GROUP BY c.id ORDER BY ca.campus_name, c.start_date DESC, c.cohort_name";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'instructor_allocation' => [
        'title' => 'Instructor Allocation (Who Trains What)',
        'icon' => 'fas fa-chalkboard-user',
        'scope' => 'range',
        'note' => 'Which instructor is delivering which programmes/cohorts, with sessions, contact hours and trainees reached.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['instructor', 'Instructor', 'text'],
            ['programmes', 'Programmes', 'text'],
            ['cohorts', 'Cohorts', 'int'],
            ['sessions', 'Sessions', 'int'],
            ['contact_hours', 'Contact Hrs', 'num1'],
            ['trainees', 'Trainees', 'int'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, i.full_name AS instructor,
                       GROUP_CONCAT(DISTINCT p.program_code ORDER BY p.program_code SEPARATOR ', ') AS programmes,
                       COUNT(DISTINCT s.cohort_id) AS cohorts,
                       COUNT(s.id) AS sessions,
                       COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.contact_hours ELSE 0 END),0) AS contact_hours,
                       COUNT(DISTINCT e.trainee_id) AS trainees
                    FROM transport_sessions s
                    INNER JOIN transport_instructors i ON i.id = s.instructor_id
                    INNER JOIN transport_cohorts c ON c.id = s.cohort_id
                    INNER JOIN transport_programs p ON p.id = c.program_id
                    INNER JOIN transport_campuses ca ON ca.id = c.campus_id
                    LEFT JOIN transport_enrollments e ON e.cohort_id = c.id
                    WHERE s.session_date BETWEEN ? AND ?";
            $types = 'ss'; $params = [$from, $to];
            if ($campusId > 0) { $sql .= " AND c.campus_id = ?"; $types .= 'i'; $params[] = $campusId; }
            $sql .= " GROUP BY i.id ORDER BY sessions DESC, i.full_name";
            return tr_query($db, $sql, $types, $params);
        },
    ],
    'cohort_progress' => [
        'title' => 'Cohort Progress & Attendance',
        'icon' => 'fas fa-list-check',
        'scope' => 'snapshot',
        'note' => 'Per-cohort: enrolled, how many actually reported (attended ≥1 session), sessions held, attendance rate, passes and certificates.',
        'columns' => [
            ['campus_name', 'Campus', 'text'],
            ['cohort_name', 'Cohort', 'text'],
            ['program_code', 'Program', 'text'],
            ['enrolled', 'Enrolled', 'int'],
            ['reported', 'Reported', 'int'],
            ['sessions_held', 'Sessions', 'int'],
            ['attendance_rate', 'Attendance', 'text'],
            ['passes', 'Passed', 'int'],
            ['certified', 'Certified', 'int'],
        ],
        'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
            $sql = "SELECT ca.campus_name, c.cohort_name, p.program_code,
                       (SELECT COUNT(*) FROM transport_enrollments e WHERE e.cohort_id=c.id) AS enrolled,
                       (SELECT COUNT(DISTINCT a.enrollment_id)
                          FROM transport_session_attendance a
                          INNER JOIN transport_sessions s2 ON s2.id=a.session_id
                          INNER JOIN transport_enrollments e4 ON e4.id=a.enrollment_id
                          WHERE s2.cohort_id=c.id AND a.status IN ('present','late')
                            AND e4.booking_status='booked'
                            AND e4.status IN ('enrolled','active','completed')) AS reported,
                       (SELECT COUNT(*) FROM transport_sessions s WHERE s.cohort_id=c.id AND s.status='completed') AS sessions_held,
                       (SELECT CONCAT(ROUND(100*SUM(a.status IN ('present','late'))/NULLIF(COUNT(*),0)),'%')
                          FROM transport_session_attendance a
                          INNER JOIN transport_sessions s3 ON s3.id=a.session_id
                          INNER JOIN transport_enrollments e5 ON e5.id=a.enrollment_id
                          WHERE s3.cohort_id=c.id
                            AND e5.booking_status='booked'
                            AND e5.status IN ('enrolled','active','completed')) AS attendance_rate,
                       (SELECT COUNT(*) FROM transport_assessments at
                          INNER JOIN transport_enrollments e2 ON e2.id=at.enrollment_id
                          WHERE e2.cohort_id=c.id AND at.result='pass') AS passes,
                       (SELECT COUNT(*) FROM transport_enrollments e3 WHERE e3.cohort_id=c.id AND e3.certificate_issued=1) AS certified
                    FROM transport_cohorts c
                    INNER JOIN transport_programs p ON p.id=c.program_id
                    INNER JOIN transport_campuses ca ON ca.id=c.campus_id";
            $types = ''; $params = [];
            if ($campusId > 0) { $sql .= " WHERE c.campus_id = ?"; $types = 'i'; $params[] = $campusId; }
            $sql .= " ORDER BY ca.campus_name, c.start_date DESC, c.cohort_name";
            return tr_query($db, $sql, $types, $params);
        },
    ],
];

// Which single report is being viewed? Empty string = directory/overview landing.
$activeReport = (string)($_GET['report'] ?? '');
if ($activeReport !== '' && !isset($reports[$activeReport])) {
    $activeReport = '';
}

/* ---------------------------------------------------------------------------
 * CSV export — must run before any HTML output.
 * ------------------------------------------------------------------------- */
$exportKey = (string)($_GET['export'] ?? '');
if ($exportKey !== '' && !$needsInstall && isset($reports[$exportKey])) {
    $report = $reports[$exportKey];
    $rows = $report['builder']($db, $from, $to, $campusId);

    $scopeLabel = $report['scope'] === 'range' ? ($from . '_to_' . $to) : ('snapshot_' . $today);
    $filename = 'transport_' . $exportKey . '_' . $scopeLabel . '.csv';
    if (function_exists('audit_log_current_user')) {
        audit_log_current_user($db, 'reports.transport.export', [
            'report' => $exportKey,
            'filename' => $filename,
            'from' => $from,
            'to' => $to,
            'campus_id' => $campusId,
            'rows' => count($rows),
        ]);
    }

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    // Title / context rows.
    fputcsv($out, [$report['title']]);
    fputcsv($out, ['Campus', $campusName]);
    if ($report['scope'] === 'range') {
        fputcsv($out, ['Period', $from . ' to ' . $to]);
    } else {
        fputcsv($out, ['As at', $today]);
    }
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);

    // Header + data.
    fputcsv($out, array_map(static fn($c) => $c[1], $report['columns']));
    foreach ($rows as $row) {
        $line = [];
        foreach ($report['columns'] as [$field, , $type]) {
            $line[] = tr_fmt_csv($type, $row[$field] ?? '');
        }
        fputcsv($out, $line);
    }
    if (!$rows) {
        fputcsv($out, ['No data for the selected criteria.']);
    }
    fclose($out);
    exit;
}

/* ---------------------------------------------------------------------------
 * Period KPIs (used in the summary band).
 * ------------------------------------------------------------------------- */
$campusJoinEnroll = $campusId > 0 ? " AND c.campus_id = ?" : '';
$campusJoinVehicle = $campusId > 0 ? " AND v.campus_id = ?" : '';

$kpi = [
    'new_enrollments' => 0, 'fees_billed' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0,
    'sessions' => 0, 'contact_hours' => 0.0, 'checks' => 0, 'defects' => 0, 'incidents' => 0,
    'active_cohorts' => 0, 'available_vehicles' => 0, 'compliance_alerts' => 0,
];

if (!$needsInstall && $activeReport === '') {
    // Period: enrolments / revenue
    $eParams = [$from, $to];
    $eTypes = 'ss';
    if ($campusId > 0) { $eParams[] = $campusId; $eTypes .= 'i'; }
    $eRow = tr_query($db, "
        SELECT COUNT(*) AS cnt,
               COALESCE(SUM(e.fee_amount), 0) AS billed,
               COALESCE(SUM(e.amount_paid), 0) AS paid
        FROM transport_enrollments e
        INNER JOIN transport_cohorts c ON c.id = e.cohort_id
        WHERE e.enrollment_date BETWEEN ? AND ?{$campusJoinEnroll}", $eTypes, $eParams);
    if ($eRow) {
        $kpi['new_enrollments'] = (int)$eRow[0]['cnt'];
        $kpi['fees_billed'] = (float)$eRow[0]['billed'];
        $kpi['collected'] = (float)$eRow[0]['paid'];
    }

    // Snapshot: outstanding across active enrolments
    $oParams = [];
    $oTypes = '';
    if ($campusId > 0) { $oParams[] = $campusId; $oTypes = 'i'; }
    $kpi['outstanding'] = tr_scalar($db, "
        SELECT COALESCE(SUM(e.fee_amount - e.amount_paid), 0)
        FROM transport_enrollments e
        INNER JOIN transport_cohorts c ON c.id = e.cohort_id
        WHERE e.status IN ('enrolled','active')" . ($campusId > 0 ? " AND c.campus_id = ?" : ''), $oTypes, $oParams);

    // Period: sessions / contact hours
    $sRow = tr_query($db, "
        SELECT COUNT(*) AS cnt, COALESCE(SUM(s.contact_hours), 0) AS hrs
        FROM transport_sessions s
        INNER JOIN transport_cohorts c ON c.id = s.cohort_id
        WHERE s.session_date BETWEEN ? AND ? AND s.status = 'completed'{$campusJoinEnroll}", $eTypes, $eParams);
    if ($sRow) {
        $kpi['sessions'] = (int)$sRow[0]['cnt'];
        $kpi['contact_hours'] = (float)$sRow[0]['hrs'];
    }

    // Period: pre-use checks / defects
    $cParams = [$from, $to];
    $cTypes = 'ss';
    if ($campusId > 0) { $cParams[] = $campusId; $cTypes .= 'i'; }
    $cRow = tr_query($db, "
        SELECT COUNT(*) AS cnt, SUM(pc.overall_status <> 'fit') AS defects
        FROM transport_preuse_checks pc
        INNER JOIN transport_vehicles v ON v.id = pc.vehicle_id
        WHERE pc.checklist_date BETWEEN ? AND ?{$campusJoinVehicle}", $cTypes, $cParams);
    if ($cRow) {
        $kpi['checks'] = (int)$cRow[0]['cnt'];
        $kpi['defects'] = (int)$cRow[0]['defects'];
    }

    // Period: incidents
    $kpi['incidents'] = (int)tr_scalar($db, "
        SELECT COUNT(*)
        FROM transport_incident_reports ir
        INNER JOIN transport_vehicles v ON v.id = ir.vehicle_id
        WHERE ir.incident_time >= ? AND ir.incident_time < DATE_ADD(?, INTERVAL 1 DAY)" . ($campusId > 0 ? " AND v.campus_id = ?" : ''), $cTypes, $cParams);

    // Snapshot KPIs
    $kpi['active_cohorts'] = (int)tr_scalar($db, "
        SELECT COUNT(*) FROM transport_cohorts c
        WHERE c.status IN ('open','in_progress')" . ($campusId > 0 ? " AND c.campus_id = ?" : ''), $oTypes, $oParams);
    $kpi['available_vehicles'] = (int)tr_scalar($db, "
        SELECT COUNT(*) FROM transport_vehicles v
        WHERE v.status = 'available'" . ($campusId > 0 ? " AND v.campus_id = ?" : ''), $oTypes, $oParams);
    $kpi['compliance_alerts'] = (int)tr_scalar($db, "
        SELECT COUNT(*) FROM transport_vehicles v
        WHERE (v.status IN ('maintenance','unavailable')
            OR (v.fitness_expiry IS NOT NULL AND v.fitness_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
            OR (v.insurance_expiry IS NOT NULL AND v.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)))"
        . ($campusId > 0 ? " AND v.campus_id = ?" : ''), $oTypes, $oParams);
}

/* ---------------------------------------------------------------------------
 * Pre-build report data sets for on-screen rendering.
 * ------------------------------------------------------------------------- */
$reportData = [];
if (!$needsInstall) {
    if ($activeReport !== '') {
        $reportData[$activeReport] = $reports[$activeReport]['builder']($db, $from, $to, $campusId);
    } else {
        foreach ($reports as $key => $report) {
            $reportData[$key] = $report['builder']($db, $from, $to, $campusId);
        }
    }
}

// AI summary calculation
$aiResult = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_summary') {
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $single = $activeReport !== '' ? $reports[$activeReport] : null;
        if ($single) {
            $context = [
                'report' => $single['title'],
                'role' => 'Transport Manager',
                'filters' => ['from' => $from, 'to' => $to, 'campus' => $campusName],
                'columns' => array_column($single['columns'], 1),
                'rows' => array_slice($reportData[$activeReport] ?? [], 0, 40),
                'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
            ];
            $promptSystem = "You summarise a {$single['title']} for the Transport Manager. Use ONLY the supplied rows. Keep it professional, and highlight key trends or outliers.";
        } else {
            $context = [
                'report' => 'Fleet & Training Operations Overview',
                'role' => 'Transport Manager',
                'filters' => ['from' => $from, 'to' => $to, 'campus' => $campusName],
                'kpi' => $kpi,
                'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
            ];
            $promptSystem = "You summarise a Transport & Fleet Operations Overview for the Transport Manager. Give a concise plain-English breakdown of enrollees, billed/collected revenue, session delivery, checks/defects, and compliance alerts.";
        }

        $ctxJson = wuc_ai_context_json($context, 14000);
        $aiResult = wuc_ai_generate($db, [
            'feature' => 'transport_report_summary',
            'user_role' => 'transport',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'transport'),
            'input_summary' => 'Transport Report Summary',
            'context_hash' => hash('sha256', $ctxJson),
            'messages' => [
                ['role' => 'system', 'content' => $promptSystem],
                ['role' => 'user', 'content' => "Transport data (JSON):\n{$ctxJson}\n\nWrite the summary."],
            ],
            'fallback' => static function () use ($activeReport, $reports, $kpi): string {
                return "Transport Report Summary — " . ($activeReport !== '' ? $reports[$activeReport]['title'] : "KPI Overview") . ". AI summary unavailable.";
            },
        ]);
    }
}

/** Build a query string preserving the active filters (and report), plus extras. */
function tr_link(array $extra = []): string
{
    global $from, $to, $campusId, $activeReport;
    $base = ['from' => $from, 'to' => $to, 'campus_id' => $campusId];
    if ($activeReport !== '') {
        $base['report'] = $activeReport;
    }
    $params = array_merge($base, $extra);
    return '?' . http_build_query($params);
}

$page_title = $activeReport !== ''
    ? ($reports[$activeReport]['title'] . ' - Transport Reports')
    : 'Transport Reports & Summaries';
require_once __DIR__ . '/includes/header.php';
render_report_print_styles();
render_report_print_script();
?>

<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
<link rel="stylesheet" href="/wucportal/lecturers/css/lecturer-dashboard.css">

<style>
    .transport-page {
        --transport-primary: #6f42c1;
        --transport-secondary: #8b5cf6;
        --transport-line: #e2e8f0;
        --transport-muted: #64748b;
        padding-top: 1.25rem;
        padding-bottom: 2rem;
        max-width: 100%;
        overflow-x: hidden;
    }
    .transport-page .page-header {
        background: #fff;
        border: 1px solid var(--transport-line);
        border-left: 4px solid var(--transport-primary);
        border-radius: .75rem;
        box-shadow: 0 2px 12px rgba(0,0,0,.07);
        padding: 1.25rem 1.5rem;
    }
    .transport-page .page-title {
        font-size: 1.65rem;
        font-weight: 700;
        color: var(--transport-primary);
        margin-bottom: .3rem;
    }
    .transport-page .page-subtitle { color: var(--transport-muted); font-size: .925rem; margin-bottom: 0; }
    .transport-module-badge {
        display: inline-flex; align-items: center; gap: .45rem;
        padding: .25rem .65rem; border-radius: 999px;
        background: #f3e8ff; color: #6b21a8; border: 1px solid #ddd6fe;
        font-size: .78rem; font-weight: 700;
    }
    .transport-stat {
        background: #fff; border: 1px solid #f2f5f8; border-radius: .75rem;
        padding: 1rem 1.1rem; height: 100%; box-shadow: 0 2px 12px rgba(0,0,0,.08);
    }
    .transport-stat .label { color: var(--transport-muted); font-size: .8rem; font-weight: 600; }
    .transport-stat .value { font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.1; }
    .transport-stat .icon {
        width: 42px; height: 42px; border-radius: 10px; display: inline-flex;
        align-items: center; justify-content: center; color: #fff; font-size: 1rem;
    }
    .transport-card {
        background: #fff; border: 1px solid var(--transport-line);
        border-radius: .75rem; box-shadow: 0 2px 12px rgba(0,0,0,.07);
    }
    .transport-card-header {
        padding: 1rem 1.25rem; border-bottom: 1px solid #f0f0f0; font-weight: 700;
        color: var(--transport-primary); display: flex; align-items: center;
        justify-content: space-between; gap: .75rem; border-left: 4px solid var(--transport-primary);
    }
    .table-transport th {
        font-size: .72rem; text-transform: uppercase; letter-spacing: .03em;
        color: var(--transport-muted); background: #f8fafc; white-space: nowrap;
    }
    .table-transport td { vertical-align: middle; font-size: .86rem; }
    .transport-page .btn-primary {
        background: linear-gradient(135deg, var(--transport-primary) 0%, var(--transport-secondary) 100%);
        border-color: var(--transport-primary);
    }
    .transport-page .text-primary { color: var(--transport-primary) !important; }
    .transport-filter-bar {
        background: #fff; border: 1px solid var(--transport-line); border-radius: .75rem;
        box-shadow: 0 2px 12px rgba(0,0,0,.07); padding: 1rem 1.25rem;
    }
    .transport-preset { font-size: .78rem; }
    .transport-report-nav {
        display: flex; flex-wrap: wrap; gap: .4rem;
        background: #fff; border: 1px solid var(--transport-line); border-radius: .75rem;
        box-shadow: 0 2px 12px rgba(0,0,0,.07); padding: .55rem;
    }
    .transport-report-pill {
        display: inline-flex; align-items: center; text-decoration: none;
        border: 1px solid transparent; background: transparent; color: #334155;
        border-radius: 7px; padding: .4rem .7rem; font-size: .82rem; font-weight: 700;
        line-height: 1.15; white-space: nowrap;
    }
    .transport-report-pill:hover, .transport-report-pill:focus {
        border-color: #cbd5e1; background: #f8fafc; color: var(--transport-primary);
    }
    .transport-report-pill.active {
        background: linear-gradient(135deg, var(--transport-primary) 0%, var(--transport-secondary) 100%);
        color: #fff; box-shadow: 0 2px 8px rgba(111,66,193,.28);
    }
    .transport-report-tile {
        position: relative; background: #fff; border: 1px solid var(--transport-line);
        border-radius: .75rem; box-shadow: 0 2px 12px rgba(0,0,0,.07);
        padding: 1.1rem 1.15rem; transition: transform .15s ease, box-shadow .15s ease;
        display: flex; flex-direction: column;
    }
    .transport-report-tile:hover { transform: translateY(-3px); box-shadow: 0 10px 26px rgba(111,66,193,.18); }
    .transport-report-tile-icon {
        width: 44px; height: 44px; border-radius: 11px; display: inline-flex;
        align-items: center; justify-content: center; font-size: 1.15rem; color: #fff;
        background: linear-gradient(135deg, var(--transport-primary) 0%, var(--transport-secondary) 100%);
    }
    .transport-report-tile-title {
        font-size: 1.02rem; font-weight: 800; color: #1e293b; text-decoration: none; line-height: 1.25;
    }
    .transport-report-tile-title:hover { color: var(--transport-primary); }
    .transport-report-tile-note { color: var(--transport-muted); font-size: .82rem; margin: .35rem 0 0; }
    .transport-report-tile-foot { margin-top: auto; }
    @media print {
        .transport-report-nav { display: none !important; }
        .transport-no-print, .sidebar, .sidebar-backdrop, .main-wrapper > .sidebar { display: none !important; }
        .main-content { margin-left: 0 !important; }
        .transport-card { box-shadow: none; border-color: #ccc; }
    }
</style>

<div class="container-fluid px-4 portal-dashboard transport-page">
    <?php $single = $activeReport !== '' ? $reports[$activeReport] : null; ?>
    <?php
    if ($single) {
        render_report_print_header(
            $single['title'],
            $campusName,
            [
                'Campus' => $campusName,
                'Period' => $single['scope'] === 'range' ? ($from . ' to ' . $to) : 'Snapshot',
                'Records' => count($reportData[$activeReport] ?? []),
            ]
        );
    } else {
        render_report_print_header(
            'Transport Reports & Summaries',
            $campusName,
            [
                'Campus' => $campusName,
                'Period' => $from . ' to ' . $to,
            ]
        );
    }
    ?>

    <div class="page-header mb-4 d-print-none">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="transport-module-badge mb-2"><i class="fas fa-chart-line"></i> Transport Reports</div>
                <?php if ($single): ?>
                    <h1 class="page-title"><i class="<?php echo tr_h($single['icon']); ?> me-2"></i><?php echo tr_h($single['title']); ?></h1>
                    <p class="page-subtitle">
                        <?php echo tr_h($single['note']); ?>
                        <span class="text-primary fw-semibold"><?php echo tr_h($campusName); ?></span>
                        ·
                        <?php if ($single['scope'] === 'range'): ?>
                            <?php echo tr_h($from); ?> to <?php echo tr_h($to); ?>
                        <?php else: ?>
                            as at <?php echo tr_h($today); ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <h1 class="page-title"><i class="fas fa-file-lines me-2"></i>Reports &amp; Summaries</h1>
                <p class="page-subtitle">
                    Pick a report below for operational, compliance and revenue insight
                    <span class="text-primary fw-semibold"><?php echo tr_h($campusName); ?></span>.
                </p>
            <?php endif; ?>
            <div class="d-flex gap-2 flex-wrap transport-no-print">
                <?php if ($single): ?>
                    <a href="<?php echo tr_h('reports.php?' . http_build_query(['from' => $from, 'to' => $to, 'campus_id' => $campusId])); ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="fas fa-table-cells me-1"></i>All Reports</a>
                    <button type="button" class="btn btn-success btn-sm rounded-pill px-3" onclick="exportToExcel('reportTable_<?= htmlspecialchars($activeReport) ?>', 'transport_<?= htmlspecialchars($activeReport) ?>.xls')">
                        <i class="fas fa-file-excel me-1"></i>Excel
                    </button>
                    <a class="btn btn-primary btn-sm rounded-pill px-3" href="<?php echo tr_h(tr_link(['export' => $activeReport])); ?>"><i class="fas fa-file-csv me-1"></i>CSV</a>
                <?php else: ?>
                    <a href="/wucportal/transport/transport_management.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="fas fa-arrow-left me-1"></i>Operations</a>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm rounded-pill px-3" onclick="printReport('<?php echo $single ? tr_h($single['title']) : 'Transport Overview'; ?>')"><i class="fas fa-print me-1"></i>Print</button>
            </div>
        </div>
    </div>

    <?php if ($needsInstall): ?>
        <div class="alert alert-warning">
            <strong>Transport Management is not installed.</strong>
            Install the TSMS database tables before generating reports.
            <a class="btn btn-sm btn-primary ms-2" href="scripts/install_transport_module.php">Install Transport Tables</a>
        </div>
    <?php else: ?>

    <!-- Report switcher -->
    <div class="transport-report-nav mb-4 transport-no-print">
        <a class="transport-report-pill <?php echo $activeReport === '' ? 'active' : ''; ?>"
           href="<?php echo tr_h('reports.php?' . http_build_query(['from' => $from, 'to' => $to, 'campus_id' => $campusId])); ?>">
            <i class="fas fa-table-cells me-1"></i>All reports
        </a>
        <?php foreach ($reports as $key => $report): ?>
            <a class="transport-report-pill <?php echo $activeReport === $key ? 'active' : ''; ?>"
               href="<?php echo tr_h('?' . http_build_query(['from' => $from, 'to' => $to, 'campus_id' => $campusId, 'report' => $key])); ?>">
                <i class="<?php echo tr_h($report['icon']); ?> me-1"></i><?php echo tr_h($report['title']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="get" class="transport-filter-bar mb-4 transport-no-print">
        <?php if ($activeReport !== ''): ?>
            <input type="hidden" name="report" value="<?php echo tr_h($activeReport); ?>">
        <?php endif; ?>
        <div class="row g-3 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small fw-bold mb-1">From</label>
                <input type="date" name="from" value="<?php echo tr_h($from); ?>" class="form-control form-control-sm">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small fw-bold mb-1">To</label>
                <input type="date" name="to" value="<?php echo tr_h($to); ?>" class="form-control form-control-sm">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small fw-bold mb-1">Campus</label>
                <select name="campus_id" class="form-select form-select-sm">
                    <option value="0">All campuses</option>
                    <?php foreach ($campuses as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $campusId === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo tr_h($c['campus_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3 d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-fill"><i class="fas fa-filter me-1"></i>Apply</button>
                <a href="<?php echo tr_h($activeReport !== '' ? ('reports.php?' . http_build_query(['report' => $activeReport])) : 'reports.php'); ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </div>
        <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
            <span class="text-muted small">Quick ranges:</span>
            <?php
                $presets = [
                    'This month' => [date('Y-m-01'), $today],
                    'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                    'This year' => [date('Y-01-01'), $today],
                    'Last 12 months' => [date('Y-m-d', strtotime('-12 months')), $today],
                    'All time' => ['2000-01-01', $today],
                ];
                foreach ($presets as $label => [$pf, $pt]):
                    $active = ($pf === $from && $pt === $to);
            ?>
                <a class="btn btn-sm transport-preset <?php echo $active ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                   href="<?php echo tr_h(tr_link(['from' => $pf, 'to' => $pt])); ?>"><?php echo tr_h($label); ?></a>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- AI Summary Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>BI Summary Insights</h5>
            <form method="post" action="reports.php?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&campus_id=<?php echo urlencode((string)$campusId); ?><?php echo $activeReport !== '' ? '&report=' . urlencode($activeReport) : ''; ?>" class="m-0">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="ai_summary">
                <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                    <i class="fas fa-arrows-rotate me-1"></i>Generate AI Insights
                </button>
            </form>
        </div>
        <div class="card-body p-4">
            <?php if ($aiResult !== null): ?>
                <?php if (empty($aiResult['used_ai'])): ?>
                    <div class="alert alert-warning small py-2 mb-3"><?php echo htmlspecialchars(wuc_ai_fallback_notice($aiResult)); ?></div>
                <?php endif; ?>
                <div class="bg-light border rounded-3 p-3">
                    <?php echo wuc_ai_output_block((string)$aiResult['text']); ?>
                </div>
            <?php else: ?>
                <span class="text-muted small">Click <strong>Generate AI Insights</strong> to analyze the transport dataset and output a plain-English report overview.</span>
            <?php endif; ?>
        </div>
    </div>

    <?php
    /**
     * Render one report card (header + table). Shared by the single-report view.
     */
    function tr_render_report_card(string $key, array $report, array $rows): void
    {
    ?>
        <div class="transport-card mb-4" id="report_card_<?php echo $key; ?>">
            <div class="transport-card-header">
                <span><i class="<?php echo tr_h($report['icon']); ?> me-2 text-primary"></i><?php echo tr_h($report['title']); ?>
                    <span class="badge bg-light text-muted border ms-2"><?php echo $report['scope'] === 'range' ? 'Period' : 'Snapshot'; ?></span>
                </span>
                <span class="d-flex align-items-center gap-2 transport-no-print">
                    <span class="badge bg-light text-dark border"><?php echo count($rows); ?> rows</span>
                    <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel('reportTable_<?php echo $key; ?>', 'transport_<?php echo $key; ?>.xls')">
                        <i class="fas fa-file-excel me-1"></i>Excel
                    </button>
                    <a class="btn btn-outline-primary btn-sm rounded-pill px-3" href="<?php echo tr_h(tr_link(['export' => $key])); ?>">
                        <i class="fas fa-file-csv me-1"></i>CSV
                    </a>
                </span>
            </div>
            <?php if ($report['note']): ?>
                <div class="px-3 pt-2 small text-muted"><?php echo tr_h($report['note']); ?></div>
            <?php endif; ?>
            <div class="table-responsive p-3">
                <table class="table table-hover align-middle table-transport mb-0" id="reportTable_<?php echo $key; ?>">
                    <thead class="table-light">
                        <tr>
                            <?php foreach ($report['columns'] as [$field, $label, $type]): ?>
                                <th class="<?php echo in_array($type, ['int','num1','money'], true) ? 'text-end' : ''; ?>"><?php echo tr_h($label); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="<?php echo count($report['columns']); ?>" class="text-center text-muted py-4">
                                <i class="fas fa-inbox me-1"></i>No data for the selected criteria.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($report['columns'] as [$field, , $type]): ?>
                                        <td class="<?php echo in_array($type, ['int','num1','money'], true) ? 'text-end' : ''; ?>">
                                            <?php echo tr_fmt_display($type, $row[$field] ?? ''); ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php
    }
    ?>

    <?php if ($single): ?>

        <!-- Single report view -->
        <?php tr_render_report_card($activeReport, $single, $reportData[$activeReport] ?? []); ?>
        <p class="text-muted small text-center mt-3">
            Generated <?php echo tr_h(date('Y-m-d H:i')); ?>
            <?php if ($single['scope'] === 'range'): ?>· Period <?php echo tr_h($from); ?> to <?php echo tr_h($to); ?><?php endif; ?>
        </p>

    <?php else: ?>

        <!-- Summary KPI band -->
        <div class="row g-3 mb-4 d-print-none">
            <?php
                $kpiCards = [
                    ['New enrolments', number_format($kpi['new_enrollments']), 'fas fa-user-plus', 'bg-primary'],
                    ['Fees billed (period)', tr_money($kpi['fees_billed']), 'fas fa-file-invoice-dollar', 'bg-info'],
                    ['Collected (period)', tr_money($kpi['collected']), 'fas fa-hand-holding-dollar', 'bg-success'],
                    ['Outstanding (active)', tr_money($kpi['outstanding']), 'fas fa-scale-unbalanced', 'bg-warning'],
                    ['Sessions delivered', number_format($kpi['sessions']), 'fas fa-calendar-check', 'bg-info'],
                    ['Contact hours', number_format($kpi['contact_hours'], 1), 'fas fa-hourglass-half', 'bg-dark'],
                    ['Pre-use checks', number_format($kpi['checks']) . ' / ' . number_format($kpi['defects']) . ' flagged', 'fas fa-clipboard-check', 'bg-secondary'],
                    ['Incidents logged', number_format($kpi['incidents']), 'fas fa-triangle-exclamation', 'bg-danger'],
                    ['Active cohorts', number_format($kpi['active_cohorts']), 'fas fa-layer-group', 'bg-primary'],
                    ['Available vehicles', number_format($kpi['available_vehicles']), 'fas fa-truck', 'bg-success'],
                    ['Fleet alerts', number_format($kpi['compliance_alerts']), 'fas fa-triangle-exclamation', 'bg-warning'],
                ];
                foreach ($kpiCards as [$label, $value, $icon, $bg]):
            ?>
            <div class="col-xl-3 col-md-4 col-sm-6">
                <div class="transport-stat d-flex align-items-center gap-3">
                    <div class="icon <?php echo $bg; ?>"><i class="<?php echo $icon; ?>"></i></div>
                    <div>
                        <div class="value"><?php echo $value; ?></div>
                        <div class="label"><?php echo tr_h($label); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Report directory -->
        <h2 class="h6 text-uppercase text-muted fw-bold mb-3 d-print-none"><i class="fas fa-folder-open me-2"></i>Available reports</h2>
        <div class="row g-3 d-print-none">
            <?php foreach ($reports as $key => $report): ?>
                <?php
                    $rows = $reportData[$key] ?? [];
                    $openLink = '?' . http_build_query(['from' => $from, 'to' => $to, 'campus_id' => $campusId, 'report' => $key]);
                ?>
                <div class="col-md-6 col-xl-4">
                    <div class="transport-report-tile h-100">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <div class="transport-report-tile-icon"><i class="<?php echo tr_h($report['icon']); ?>"></i></div>
                            <span class="badge bg-light text-muted border"><?php echo $report['scope'] === 'range' ? 'Period' : 'Snapshot'; ?></span>
                        </div>
                        <a href="<?php echo tr_h($openLink); ?>" class="transport-report-tile-title stretched-link"><?php echo tr_h($report['title']); ?></a>
                        <p class="transport-report-tile-note"><?php echo tr_h($report['note']); ?></p>
                        <div class="d-flex align-items-center justify-content-between mt-3 transport-report-tile-foot">
                            <span class="badge bg-light text-dark border"><?php echo count($rows); ?> rows</span>
                            <span class="d-flex gap-2">
                                <a class="btn btn-outline-primary btn-sm position-relative rounded-pill px-3" style="z-index:2" href="<?php echo tr_h($openLink); ?>"><i class="fas fa-eye me-1"></i>Open</a>
                                <a class="btn btn-outline-secondary btn-sm position-relative rounded-pill px-3" style="z-index:2" href="<?php echo tr_h(tr_link(['report' => $key, 'export' => $key])); ?>"><i class="fas fa-file-csv me-1"></i>CSV</a>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="text-muted small text-center mt-4 d-print-none">
            Generated <?php echo tr_h(date('Y-m-d H:i')); ?> · Period <?php echo tr_h($from); ?> to <?php echo tr_h($to); ?>
        </p>

    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
function exportToExcel(tableId, filename) {
    var table = document.getElementById(tableId);
    if (!table) { alert('No data to export.'); return; }
    var html = table.outerHTML;
    var url  = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var link = document.createElement('a');
    document.body.appendChild(link);
    link.href     = url;
    link.download = filename || 'report.xls';
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

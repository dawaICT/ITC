<?php
declare(strict_types=1);

$page_title = 'Application Status';
require_once __DIR__ . '/includes/applicant_guard.php';
require_once dirname(__DIR__) . '/includes/applicant_program_helpers.php';

$errors = [];
$applications = [];
$lookupEmail = '';
$lookupNrc = '';

/** Best-effort email from the logged-in identity for auto-load. */
$sessionEmail = '';
if (!empty($_SESSION['Sid'])) {
    $sid = (string)$_SESSION['Sid'];
    if ($stmt = $db->prepare('SELECT email FROM students WHERE SID = ? LIMIT 1')) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $sessionEmail = strtolower(trim((string)($row['email'] ?? '')));
    }
} elseif (!empty($_SESSION['staff_id'])) {
    $staffId = (string)$_SESSION['staff_id'];
    if ($stmt = $db->prepare('SELECT email FROM staff WHERE staff_id = ? LIMIT 1')) {
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $sessionEmail = strtolower(trim((string)($row['email'] ?? '')));
    }
} elseif (!empty($_SESSION['user_id_db'])) {
    $accountUserId = (int)$_SESSION['user_id_db'];
    if ($stmt = $db->prepare("SELECT username FROM users WHERE user_id = ? AND primary_role = 'applicant' LIMIT 1")) {
        $stmt->bind_param('i', $accountUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $candidate = strtolower(trim((string)($row['username'] ?? '')));
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $sessionEmail = $candidate;
        }
    }
}

$progJoin = wuc_applicant_program_join_sql();
$paProgJoin = wuc_processed_applicant_program_join_sql();

$loadApplications = static function (mysqli $db, string $email, string $nrc) use ($progJoin, $paProgJoin): array {
    $email = strtolower(trim($email));
    $nrc = trim($nrc);
    if ($email === '' && $nrc === '') {
        return [];
    }

    $rows = [];

    if ($email !== '' && $nrc !== '') {
        $sql = "SELECT oa.id, oa.Fname, oa.Lname, oa.email, oa.nrc_pass, oa.program, oa.intake,
                       oa.mode, oa.year, oa.status, oa.dte_adm, 'online' AS source,
                       {$progJoin['select']}
                FROM online_applicants oa
                {$progJoin['join']}
                WHERE LOWER(TRIM(oa.email)) = ? AND TRIM(oa.nrc_pass) = ?
                ORDER BY oa.dte_adm DESC";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $email, $nrc);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } elseif ($email !== '') {
        $sql = "SELECT oa.id, oa.Fname, oa.Lname, oa.email, oa.nrc_pass, oa.program, oa.intake,
                       oa.mode, oa.year, oa.status, oa.dte_adm, 'online' AS source,
                       {$progJoin['select']}
                FROM online_applicants oa
                {$progJoin['join']}
                WHERE LOWER(TRIM(oa.email)) = ?
                ORDER BY oa.dte_adm DESC";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    }

    if ($email !== '') {
        $sql = "SELECT pa.id, pa.Fname, pa.Lname, pa.email, pa.nrc_pass, pa.program, pa.intake,
                       pa.mode, pa.year, pa.status, pa.dte_adm, 'processed' AS source,
                       {$paProgJoin['select']}
                FROM processed_applicants pa
                {$paProgJoin['join']}
                WHERE LOWER(TRIM(pa.email)) = ?"
                . ($nrc !== '' ? ' AND TRIM(pa.nrc_pass) = ?' : '') . "
                ORDER BY pa.dte_adm DESC";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($nrc !== '') {
                $stmt->bind_param('ss', $email, $nrc);
            } else {
                $stmt->bind_param('s', $email);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (($row['program_name'] ?? '') === '') {
                    $row['program_name'] = $row['program'];
                }
                $rows[] = $row;
            }
            $stmt->close();
        }
    }

    // Applicants can also apply for short courses; resolve names the programs
    // catalogue missed against short_courses. A program_name equal to the raw
    // code means the catalogue lookup failed and only the code fell through.
    $unresolved = [];
    foreach ($rows as $row) {
        $code = trim((string)($row['program'] ?? ''));
        $name = trim((string)($row['program_name'] ?? ''));
        if ($code !== '' && ($name === '' || $name === $code)) {
            $unresolved[$code] = true;
        }
    }
    if ($unresolved) {
        $names = [];
        if ($stmt = $db->prepare('SELECT course_name FROM short_courses WHERE course_code = ? LIMIT 1')) {
            foreach (array_keys($unresolved) as $code) {
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $found = $stmt->get_result()->fetch_assoc();
                if ($found) {
                    $names[$code] = (string)$found['course_name'];
                }
            }
            $stmt->close();
        }
        foreach ($rows as &$row) {
            $code = trim((string)($row['program'] ?? ''));
            $name = trim((string)($row['program_name'] ?? ''));
            if (($name === '' || $name === $code) && isset($names[$code])) {
                $row['program_name'] = $names[$code];
            }
        }
        unset($row);
    }

    // The same application exists in both online_applicants (as submitted) and
    // processed_applicants (once admissions actions it). Show only the most
    // advanced stage of each application.
    $processedKeys = [];
    foreach ($rows as $row) {
        if (($row['source'] ?? '') === 'processed') {
            $processedKeys[strtolower(trim((string)$row['email'])) . '|' . trim((string)$row['nrc_pass']) . '|' . trim((string)$row['program']) . '|' . trim((string)$row['intake'])] = true;
        }
    }
    if ($processedKeys) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($processedKeys): bool {
            if (($row['source'] ?? '') !== 'online') {
                return true;
            }
            $key = strtolower(trim((string)$row['email'])) . '|' . trim((string)$row['nrc_pass']) . '|' . trim((string)$row['program']) . '|' . trim((string)$row['intake']);
            return !isset($processedKeys[$key]);
        }));
    }

    usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['dte_adm'] ?? ''), (string)($a['dte_adm'] ?? '')));

    return $rows;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Security token mismatch. Please refresh and try again.';
    } else {
        $lookupEmail = strtolower(trim((string)($_POST['email'] ?? '')));
        $lookupNrc = trim((string)($_POST['nrc_pass'] ?? ''));
        if ($lookupEmail === '' || $lookupNrc === '') {
            $errors[] = 'Enter both the email and NRC/passport number used on your application.';
        } else {
            $applications = $loadApplications($db, $lookupEmail, $lookupNrc);
            if (!$applications) {
                $errors[] = 'No application found for that email and NRC/passport combination.';
            }
        }
    }
}

$autoLoaded = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && $sessionEmail !== '') {
    $lookupEmail = $sessionEmail;
    $applications = $loadApplications($db, $sessionEmail, '');
    $autoLoaded = true;
}

$displayName = trim((string)($_SESSION['user_name'] ?? $_SESSION['student_name'] ?? ''));
if ($displayName === '') {
    $displayName = 'Applicant';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo applicant_portal_h($page_title); ?> — WUC Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/wucportal/css/wuc-premium.css">
    <style>
        body { background: #f6f8fb; font-family: Inter, sans-serif; }
        .applicant-nav { background: #1B2A4A; color: #fff; padding: 1rem 1.5rem; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-accepted { background: #d1e7dd; color: #0f5132; }
        .status-rejected { background: #f8d7da; color: #842029; }
    </style>
</head>
<body>

<header class="applicant-nav d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <i class="fas fa-file-signature fa-lg"></i>
        <strong>Applicant Portal</strong>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="text-white-50 small"><?php echo applicant_portal_h($displayName); ?></span>
        <a href="/wucportal/portal_selection.php" class="btn btn-sm btn-outline-light">Switch Portal</a>
        <a href="/wucportal/logout.php" class="btn btn-sm btn-outline-light"><i class="fas fa-sign-out-alt"></i></a>
    </div>
</header>

<main class="content-wrapper pt-4 pb-5">
<div class="container-fluid px-3 px-lg-4 portal-dashboard" style="max-width: 960px;">

    <div class="dashboard-header student-section mb-4">
        <h1 class="dashboard-title h3">Application Status</h1>
        <p class="text-muted mb-0">Track the progress of your admission application.</p>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach (array_unique($errors) as $e): ?>
            <div><?php echo applicant_portal_h($e); ?></div>
        <?php endforeach; ?>
    </div>
    <?php elseif ($autoLoaded && !$applications): ?>
    <div class="alert alert-info">
        <i class="fas fa-circle-info me-2"></i>No applications are linked to your account email
        (<?php echo applicant_portal_h($sessionEmail); ?>). Use the lookup below with the details from your application.
    </div>
    <?php endif; ?>

    <section class="data-table-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-search me-2"></i>Look Up Application</h5>
        </div>
        <div class="card-body">
            <p class="text-muted small">Enter the same email and NRC/passport number you used when applying online.</p>
            <form method="post" action="applicant_portal.php" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo applicant_portal_h($_SESSION['csrf_token']); ?>">
                <div class="col-md-6">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" type="email" id="email" name="email" required
                           value="<?php echo applicant_portal_h($lookupEmail); ?>" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="nrc_pass">NRC / Passport No.</label>
                    <input class="form-control" type="text" id="nrc_pass" name="nrc_pass" required
                           value="<?php echo applicant_portal_h($lookupNrc); ?>" maxlength="50">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-search me-2"></i>Check Status</button>
                    <a class="btn btn-outline-secondary ms-2" href="/wucportal/online_services/index.php">Submit New Application</a>
                </div>
            </form>
        </div>
    </section>

    <?php if ($applications): ?>
    <section class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Your Applications</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Programme</th>
                            <th>Intake</th>
                            <th>Submitted</th>
                            <th>Stage</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app):
                            $status = strtolower((string)($app['status'] ?? 'pending'));
                            $badgeClass = match ($status) {
                                'accepted' => 'status-accepted',
                                'rejected' => 'status-rejected',
                                default => 'status-pending',
                            };
                            $programLabel = trim((string)($app['program_name'] ?? ''));
                            if ($programLabel === '') {
                                $programLabel = (string)($app['program'] ?? '—');
                            }
                            $submittedAt = trim((string)($app['dte_adm'] ?? ''));
                            $submittedTs = $submittedAt !== '' ? strtotime($submittedAt) : false;
                            $isProcessed = ($app['source'] ?? '') === 'processed';
                        ?>
                        <tr>
                            <td>#<?php echo applicant_portal_h($app['id']); ?></td>
                            <td><?php echo applicant_portal_h($programLabel); ?></td>
                            <td><?php echo applicant_portal_h($app['intake'] ?? '—'); ?></td>
                            <td><?php echo applicant_portal_h($submittedTs ? date('M d, Y', $submittedTs) : $submittedAt); ?></td>
                            <td><span class="badge <?php echo $isProcessed ? 'bg-primary' : 'bg-light text-dark border'; ?>"><?php echo $isProcessed ? 'Processed' : 'Submitted'; ?></span></td>
                            <td><span class="badge <?php echo applicant_portal_h($badgeClass); ?>"><?php echo applicant_portal_h(ucfirst($status)); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>

</div>
</main>

</body>
</html>

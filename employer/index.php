<?php
/**
 * Employer Portal - Dashboard
 */

require_once __DIR__ . '/includes/guard.php';

$message = '';
$error = '';

// Who is acting: rows are scoped per employer account; systems_admin sees all.
$currentUserId = (int)($_SESSION['user_id_db'] ?? 0);
$isPortalAdmin = (($_SESSION['role'] ?? '') === 'systems_admin');
$displayName = trim((string)($_SESSION['user_name'] ?? '')) ?: 'Employer Partner';

// Company profile (created with the account in admin/employer_accounts.php).
$profile = null;
if ($currentUserId > 0) {
    $stmtP = $db->prepare("SELECT company_name, industry, location, contact_person FROM employer_profiles WHERE user_id = ? AND status = 'approved' LIMIT 1");
    if ($stmtP) {
        $stmtP->bind_param('i', $currentUserId);
        $stmtP->execute();
        $profile = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();
    }
}
$companyName = trim((string)($profile['company_name'] ?? ''));

/** A calendar-valid Y-m-d date (the date inputs submit this format). */
$isValidDate = static function (string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
};

// Handle new internship logging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_internship') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token.';
    } else {
        $studentId = mb_substr(trim($_POST['student_id'] ?? ''), 0, 50);
        $companyName = mb_substr(trim($_POST['company_name'] ?? ''), 0, 150);
        $supervisor = mb_substr(trim($_POST['supervisor_name'] ?? ''), 0, 100);
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $rating = (int)($_POST['performance_rating'] ?? 0);
        $feedback = mb_substr(trim($_POST['feedback'] ?? ''), 0, 2000);

        if ($studentId === '' || $companyName === '' || $supervisor === '' || $startDate === '') {
            $error = 'Please fill out all required fields.';
        } elseif ($rating < 1 || $rating > 5) {
            $error = 'Performance rating must be between 1 and 5.';
        } elseif (!$isValidDate($startDate)) {
            $error = 'Please provide a valid start date.';
        } elseif ($endDate !== '' && !$isValidDate($endDate)) {
            $error = 'Please provide a valid end date.';
        } elseif ($endDate !== '' && $endDate < $startDate) {
            $error = 'The end date cannot be before the start date.';
        } else {
            // Verify student exists
            $stmtCheck = $db->prepare("SELECT Fname, Lname FROM students WHERE SID = ? LIMIT 1");
            $stmtCheck->bind_param('s', $studentId);
            $stmtCheck->execute();
            $student = $stmtCheck->get_result()->fetch_assoc();
            $stmtCheck->close();

            if (!$student) {
                $error = 'Student ID not found in ITC records.';
            } else {
                // DATE columns reject '' in strict mode — an open-ended placement is NULL.
                $endDateParam = $endDate !== '' ? $endDate : null;
                $ownerParam = $currentUserId > 0 ? $currentUserId : null;
                $stmtInsert = $db->prepare("INSERT INTO employer_internships (student_id, company_name, supervisor_name, start_date, end_date, performance_rating, feedback, logged_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtInsert->bind_param('sssssisi', $studentId, $companyName, $supervisor, $startDate, $endDateParam, $rating, $feedback, $ownerParam);
                if ($stmtInsert->execute()) {
                    $message = 'Internship placement logged successfully.';
                } else {
                    $error = 'Failed to record internship details.';
                }
                $stmtInsert->close();
            }
        }
    }
}

// Handle Graduate Verification Search
$searchResult = null;
$searchPerformed = false;
$searchCert = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search_cert'])) {
    $searchPerformed = true;
    $searchCert = trim((string)$_GET['search_cert']);
    
    if ($searchCert !== '') {
        $stmtSearch = $db->prepare("
            SELECT 
                s.SID, s.Fname, s.Lname, p.program_name, 
                c.graduation_status, c.graduation_year
            FROM students s
            LEFT JOIN student_clearance c ON s.SID COLLATE utf8mb4_unicode_ci = c.student_id COLLATE utf8mb4_unicode_ci
            LEFT JOIN programs p ON s.program = p.program_code
            WHERE (s.SID = ? OR c.student_id = ?) AND (c.graduation_status = 'Approved' OR c.graduation_status = 'Graduated')
            LIMIT 1
        ");
        if ($stmtSearch) {
            $stmtSearch->bind_param('ss', $searchCert, $searchCert);
            $stmtSearch->execute();
            $searchResult = $stmtSearch->get_result()->fetch_assoc();
            $stmtSearch->close();
        }
    }
}

// Fetch internships — each employer account sees only the placements it
// logged; systems_admin sees everything (including legacy unowned rows).
$internships = [];
if ($isPortalAdmin) {
    $stmtIntern = $db->prepare("
        SELECT i.*, s.Fname, s.Lname
        FROM employer_internships i
        INNER JOIN students s ON i.student_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci
        ORDER BY i.id DESC LIMIT 30");
} else {
    $stmtIntern = $db->prepare("
        SELECT i.*, s.Fname, s.Lname
        FROM employer_internships i
        INNER JOIN students s ON i.student_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci
        WHERE i.logged_by_user_id = ?
        ORDER BY i.id DESC LIMIT 30");
    if ($stmtIntern) {
        $stmtIntern->bind_param('i', $currentUserId);
    }
}
if ($stmtIntern) {
    $stmtIntern->execute();
    $resIntern = $stmtIntern->get_result();
    while ($row = $resIntern->fetch_assoc()) {
        $internships[] = $row;
    }
    $stmtIntern->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employer Portal - ITC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #faf9fd; font-family: 'Inter', sans-serif; }
        .nav-brand-bar { background: #1B2A4A; color: #fff; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .nav-brand-bar h1 { font-size: 1.25rem; font-weight: 700; margin: 0; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .text-purple { color: #6f42c1 !important; }
        .bg-purple { background-color: #6f42c1 !important; }
        .btn-purple { background-color: #6f42c1; color: #fff; border: none; }
        .btn-purple:hover { background-color: #5a32a3; color: #fff; }
    </style>
</head>
<body>

<div class="nav-brand-bar">
    <div class="d-flex align-items-center gap-2">
        <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" style="height: 30px;" onerror="this.style.display='none'">
        <h1>Employer Portal</h1>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="text-white-50 small">Logged in as <?= htmlspecialchars($displayName) ?><?= $companyName !== '' ? ' · ' . htmlspecialchars($companyName) : '' ?></span>
        <a href="/wucportal/portal_selection.php" class="btn btn-sm btn-outline-light">Switch Portal</a>
        <a href="/wucportal/logout.php" class="btn btn-sm btn-outline-light"><i class="fas fa-sign-out-alt me-1"></i>Logout</a>
    </div>
</div>

<div class="container py-4">
    <?php if ($message !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <!-- Left Side: Verification Tool -->
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-purple"><i class="fas fa-user-check me-2"></i>Graduate Verification Registry</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Verify a student's graduation status. You can only verify approved academic records.</p>
                    
                    <form method="get" action="">
                        <div class="input-group mb-3">
                            <input type="text" name="search_cert" class="form-control" placeholder="Search by Student ID (e.g. ITC-0938)" value="<?= htmlspecialchars($searchCert) ?>" required>
                            <button class="btn btn-purple" type="submit"><i class="fas fa-search me-1"></i>Verify</button>
                        </div>
                    </form>

                    <?php if ($searchPerformed): ?>
                        <hr>
                        <?php if ($searchResult): ?>
                            <div class="alert alert-success border-0 shadow-sm d-flex align-items-center gap-3">
                                <i class="fas fa-check-circle fa-2x text-success"></i>
                                <div>
                                    <h6 class="fw-bold mb-0">Record Officially Verified</h6>
                                    <small class="text-muted">The student holds a valid graduate status.</small>
                                </div>
                            </div>
                            <div class="table-responsive small">
                                <table class="table table-bordered mb-0">
                                    <tr>
                                        <th class="bg-light w-40">Student Name</th>
                                        <td><strong><?= htmlspecialchars($searchResult['Fname'] . ' ' . $searchResult['Lname']) ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Student ID</th>
                                        <td><code><?= htmlspecialchars($searchResult['SID']) ?></code></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Academic Program</th>
                                        <td><?= htmlspecialchars($searchResult['program_name'] ?? 'N/A') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Graduation Year</th>
                                        <td><?= $searchResult['graduation_year'] !== null ? htmlspecialchars((string)$searchResult['graduation_year']) : '&mdash;' ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Status</th>
                                        <td><span class="badge bg-success">Graduated</span></td>
                                    </tr>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center gap-3">
                                <i class="fas fa-circle-xmark fa-2x text-danger"></i>
                                <div>
                                    <h6 class="fw-bold mb-0">Unverified Record</h6>
                                    <small class="text-muted">No approved graduation record was found matching this Student ID.</small>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Internship Logging -->
            <div class="card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-purple"><i class="fas fa-file-contract me-2"></i>Log Internship Placement</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="add_internship">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Student ID</label>
                            <input type="text" name="student_id" class="form-control form-control-sm" placeholder="e.g. ITC-0938" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Company Name</label>
                            <input type="text" name="company_name" class="form-control form-control-sm" placeholder="e.g. Lusaka Logistics Ltd"
                                   value="<?= htmlspecialchars($companyName) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Supervisor Name</label>
                            <input type="text" name="supervisor_name" class="form-control form-control-sm" placeholder="e.g. John Doe" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col">
                                <label class="form-label small fw-bold">Start Date</label>
                                <input type="date" name="start_date" class="form-control form-control-sm" required>
                            </div>
                            <div class="col">
                                <label class="form-label small fw-bold">End Date</label>
                                <input type="date" name="end_date" class="form-control form-control-sm">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Performance Rating (1-5)</label>
                            <select name="performance_rating" class="form-select form-select-sm" required>
                                <option value="">Select Rating</option>
                                <option value="5">5 - Excellent</option>
                                <option value="4">4 - Good</option>
                                <option value="3">3 - Satisfactory</option>
                                <option value="2">2 - Poor</option>
                                <option value="1">1 - Unsatisfactory</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Constructive Feedback</label>
                            <textarea name="feedback" class="form-control form-control-sm" rows="3" placeholder="Provide general feedback..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-purple btn-sm w-100"><i class="fas fa-paper-plane me-1"></i>Save Internship Record</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Side: Internship List -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-purple"><i class="fas fa-users-rectangle me-2"></i>Internship Placements</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($internships)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-folder-open fa-3x mb-3 text-light"></i>
                            <p class="mb-0">No internship records logged yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student</th>
                                        <th>Company / Supervisor</th>
                                        <th class="text-center">Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($internships as $intern): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($intern['Fname'] . ' ' . $intern['Lname']) ?></strong>
                                                <div class="text-muted">ID: <?= htmlspecialchars($intern['student_id']) ?></div>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars($intern['company_name']) ?></div>
                                                <small class="text-muted">Sup: <?= htmlspecialchars($intern['supervisor_name']) ?></small>
                                            </td>
                                            <td class="text-center fw-bold">
                                                <span class="badge bg-purple"><?= (int)$intern['performance_rating'] ?> / 5</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>

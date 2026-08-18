<?php
/**
 * Alumni Portal - Dashboard
 */

require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/qr_helper.php';

// Alumni identity is the linked student record only. Staff session user_ids
// are staff IDs and never match students.SID — falling back to them just
// masks the "account not linked to a student record" state.
$studentId = trim((string)($_SESSION['Sid'] ?? ''));

// Fetch student profile details
$student = null;
if ($studentId !== '') {
    $stmtS = $db->prepare("
        SELECT s.*, p.program_name, c.graduation_status, c.graduation_year 
        FROM students s
        LEFT JOIN student_clearance c ON s.SID COLLATE utf8mb4_unicode_ci = c.student_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN programs p ON s.program = p.program_code
        WHERE s.SID = ? LIMIT 1");
    if ($stmtS) {
        $stmtS->bind_param('s', $studentId);
        $stmtS->execute();
        $student = $stmtS->get_result()->fetch_assoc();
        $stmtS->close();
    }
}

$message = '';
$error = '';

// Handle updating employment profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $token = $_POST['csrf_token'] ?? '';
    $validStatuses = ['Employed', 'Self-Employed', 'Seeking Opportunities', 'Pursuing Higher Education'];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token.';
    } elseif ($studentId === '') {
        $error = 'Your account is not linked to a student record, so the professional profile cannot be saved.';
    } else {
        $company = mb_substr(trim($_POST['current_company'] ?? ''), 0, 150);
        $title = mb_substr(trim($_POST['job_title'] ?? ''), 0, 100);
        $status = trim($_POST['employment_status'] ?? '');

        if (!in_array($status, $validStatuses, true)) {
            $error = 'Please select your current employment status.';
        } else {
            $stmtUpd = $db->prepare("
                INSERT INTO alumni_employment_tracking (student_id, current_company, job_title, employment_status)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE current_company = ?, job_title = ?, employment_status = ?");
            if ($stmtUpd) {
                $stmtUpd->bind_param('sssssss', $studentId, $company, $title, $status, $company, $title, $status);
                if ($stmtUpd->execute()) {
                    $message = 'Your professional profile has been updated.';
                } else {
                    $error = 'Failed to update professional profile.';
                }
                $stmtUpd->close();
            }
        }
    }
}

// Fetch current employment tracking
$employment = null;
if ($studentId !== '') {
    $stmtE = $db->prepare("SELECT * FROM alumni_employment_tracking WHERE student_id = ? LIMIT 1");
    if ($stmtE) {
        $stmtE->bind_param('s', $studentId);
        $stmtE->execute();
        $employment = $stmtE->get_result()->fetch_assoc();
        $stmtE->close();
    }
}

// Fetch certificate details if issued
$certificate = null;
if ($studentId !== '') {
    $stmtC = $db->prepare("SELECT * FROM alumni_certificates WHERE student_id = ? LIMIT 1");
    if ($stmtC) {
        $stmtC->bind_param('s', $studentId);
        $stmtC->execute();
        $certificate = $stmtC->get_result()->fetch_assoc();
        $stmtC->close();
    }
}

// Fetch cumulative GPA & results summary
$gpa = 0.0;
$totalCredits = 0;
if ($studentId !== '') {
    // Standard academic results summary — published results only.
    // `exams` is a compatibility view over semester_assessment: marks live in
    // Total_marks (out of 100); GPA points come from wuc_result_points().
    require_once __DIR__ . '/../includes/grading_helpers.php';
    $stmtEx = $db->prepare("SELECT e.Total_marks, c.credits
                             FROM exams e
                             LEFT JOIN courses c ON c.course_code = e.Course_Code
                             WHERE e.Sid = ? AND LOWER(e.status) = 'published'");
    if ($stmtEx) {
        $stmtEx->bind_param('s', $studentId);
        $stmtEx->execute();
        $exRes = $stmtEx->get_result();
        $points = [];
        while ($row = $exRes->fetch_assoc()) {
            if ($row['Total_marks'] !== null) {
                $points[] = wuc_result_points((float)$row['Total_marks']);
                $totalCredits += (int)($row['credits'] ?? 0);
            }
        }
        if (!empty($points)) {
            $gpa = round(array_sum($points) / count($points), 2);
        }
        $stmtEx->close();
    }
}

// Graduation clearance drives what the dashboard may claim: only Approved or
// Graduated records are presented as alumni; anything else shows its real state.
$gradStatus = trim((string)($student['graduation_status'] ?? ''));
$isGraduated = in_array($gradStatus, ['Approved', 'Graduated'], true);
$displayName = trim((string)($student['Fname'] ?? '') . ' ' . (string)($student['Lname'] ?? ''));
if ($displayName === '') {
    $displayName = trim((string)($_SESSION['user_name'] ?? '')) ?: 'Alumnus';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alumni Portal - ITC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #faf9fd; font-family: 'Inter', sans-serif; }
        .nav-brand-bar { background: #1B2A4A; color: #fff; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
        .nav-brand-bar h1 { font-size: 1.25rem; font-weight: 700; margin: 0; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .text-purple { color: #6f42c1 !important; }
        .bg-purple { background-color: #6f42c1 !important; }
        .btn-purple { background-color: #6f42c1; color: #fff; border: none; }
        .btn-purple:hover { background-color: #5a32a3; color: #fff; }
        .bg-success-soft { background-color: rgba(25, 135, 84, 0.12); }
        .bg-warning-soft { background-color: rgba(255, 193, 7, 0.18); }
    </style>
</head>
<body>

<div class="nav-brand-bar">
    <div class="d-flex align-items-center gap-2">
        <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" style="height: 30px;" onerror="this.style.display='none'">
        <h1>Alumni Portal</h1>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="text-white-50 small">Logged in as <?= htmlspecialchars($displayName) ?></span>
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

    <?php if (!$student): ?>
        <div class="alert alert-info d-flex align-items-center gap-3">
            <i class="fas fa-circle-info fa-lg"></i>
            <div>
                <strong>Your account is not linked to a student record.</strong><br>
                <span class="small">Alumni details (programme, clearance, certificate) are drawn from your student record. Please contact the administration office to link your account.</span>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Left Side: Graduation Summary & Digital Certificate -->
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-purple"><i class="fas fa-award me-2"></i>Graduation Clearance Status</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="rounded-pill p-3 bg-purple text-white"><i class="fas fa-user-graduate fa-2x"></i></div>
                        <div>
                            <h5 class="fw-bold mb-1"><?= htmlspecialchars($student['program_name'] ?? 'Programme not on record') ?></h5>
                            <?php if ($isGraduated): ?>
                                <span class="badge bg-success-soft text-success"><i class="fas fa-check-double me-1"></i>Graduated<?= $student['graduation_year'] ? ' (Class of ' . htmlspecialchars((string)$student['graduation_year']) . ')' : '' ?></span>
                            <?php elseif ($gradStatus !== ''): ?>
                                <span class="badge bg-warning-soft text-dark"><i class="fas fa-hourglass-half me-1"></i>Clearance status: <?= htmlspecialchars($gradStatus) ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning-soft text-dark"><i class="fas fa-hourglass-half me-1"></i>Graduation clearance not yet on record</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive small">
                        <table class="table table-bordered mb-0">
                            <tr>
                                <th class="bg-light w-40">Cumulative GPA</th>
                                <td><strong><?= $gpa > 0 ? htmlspecialchars((string)$gpa) . ' / 4.0' : '&mdash;' ?></strong></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Total Credits Earned</th>
                                <td><?= $totalCredits > 0 ? (int)$totalCredits . ' Credits' : '&mdash;' ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Digital Certificate verification code -->
            <div class="card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-purple"><i class="fas fa-file-invoice me-2"></i>Digital Graduation Certificate</h5>
                </div>
                <div class="card-body text-center">
                    <?php if ($certificate): ?>
                        <div class="py-3">
                            <i class="fas fa-award fa-4x text-success mb-3"></i>
                            <h5 class="fw-bold">Your Digital Certificate is Ready</h5>
                            <p class="text-muted small">You can share your verification link with employers to validate your credential.</p>
                            
                            <div class="bg-light p-3 rounded mb-3">
                                <span class="small text-muted d-block mb-1">Certificate Serial Code</span>
                                <strong class="fs-5 text-purple"><code><?= htmlspecialchars($certificate['certificate_code']) ?></code></strong>
                            </div>

                            <?php
                            $verificationPath = '/wucportal/verify_certificate.php?cert=' . urlencode((string)$certificate['certificate_code']);
                            $verificationQr = wuc_qr_svg_data_uri(wuc_public_app_url($verificationPath), 3);
                            ?>
                            <?php if ($verificationQr !== ''): ?>
                                <img src="<?= htmlspecialchars($verificationQr, ENT_QUOTES, 'UTF-8') ?>" width="132" height="132" class="d-block mx-auto mb-3" alt="QR code for employer certificate verification">
                            <?php endif; ?>

                            <a href="<?= htmlspecialchars($verificationPath, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-purple btn-sm" target="_blank">
                                <i class="fas fa-external-link-alt me-1"></i>View Verification Page
                            </a>
                        </div>
                    <?php elseif ($isGraduated): ?>
                        <div class="py-4">
                            <i class="fas fa-clock fa-3x text-warning mb-3"></i>
                            <h5 class="fw-bold">Certificate Processing</h5>
                            <p class="text-muted small mb-0">Your digital graduation certificate is being processed and will be issued shortly.</p>
                        </div>
                    <?php else: ?>
                        <div class="py-4">
                            <i class="fas fa-graduation-cap fa-3x text-muted mb-3"></i>
                            <h5 class="fw-bold">No Certificate Yet</h5>
                            <p class="text-muted small mb-0">A digital certificate is issued once your graduation clearance is approved.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Side: Professional employment profile tracking -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-purple"><i class="fas fa-briefcase me-2"></i>Employment Status Tracker</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Keep your professional status updated. This helps us track alumni success metrics and placement indices.</p>
                    
                    <form method="post" action="">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Current Company / Organization</label>
                            <input type="text" name="current_company" class="form-control" placeholder="e.g. Ministry of Transport" value="<?= htmlspecialchars($employment['current_company'] ?? '') ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Job Title</label>
                            <input type="text" name="job_title" class="form-control" placeholder="e.g. Operations Coordinator" value="<?= htmlspecialchars($employment['job_title'] ?? '') ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Employment Status</label>
                            <select name="employment_status" class="form-select" required>
                                <option value="">Select Status</option>
                                <option value="Employed" <?= ($employment['employment_status'] ?? '') === 'Employed' ? 'selected' : '' ?>>Employed (Full-Time / Part-Time)</option>
                                <option value="Self-Employed" <?= ($employment['employment_status'] ?? '') === 'Self-Employed' ? 'selected' : '' ?>>Self-Employed / Entrepreneur</option>
                                <option value="Seeking Opportunities" <?= ($employment['employment_status'] ?? '') === 'Seeking Opportunities' ? 'selected' : '' ?>>Seeking Opportunities</option>
                                <option value="Pursuing Higher Education" <?= ($employment['employment_status'] ?? '') === 'Pursuing Higher Education' ? 'selected' : '' ?>>Pursuing Higher Education</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-purple w-100"><i class="fas fa-save me-1"></i>Update Professional Profile</button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>

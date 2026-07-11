<?php
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/city_guilds_helpers.php';

$studentId = (string)($_SESSION['Sid'] ?? '');
$learners = cg_student_learners($db, $studentId);
$assignments = cg_student_assignments($db, $studentId);
$assessments = cg_student_assessments($db, $studentId);
$supportInterventions = cg_student_support_interventions($db, $studentId);

$totalAssessments = count($assessments);
$verifiedAssessments = 0;
$upcomingAssessments = 0;
foreach ($assessments as $assessment) {
    if ((int)($assessment['is_verified'] ?? 0) === 1) {
        $verifiedAssessments++;
    }
    $due = trim((string)($assessment['date_due'] ?? ''));
    if ($due !== '' && strtotime($due) !== false && strtotime($due) >= strtotime(date('Y-m-d 00:00:00'))) {
        $upcomingAssessments++;
    }
}

$totalUnits = 0;
foreach ($learners as $learner) {
    $totalUnits += (int)($learner['units_total'] ?? 0);
}

function cg_student_date($value, string $fallback = '-'): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $fallback;
    }

    return date('d M Y', $timestamp);
}

function cg_student_datetime($value, string $fallback = '-'): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $fallback;
    }

    return date('d M Y, H:i', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>City &amp; Guilds - ITC</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="css/dashboard.css?v=20260612-profile-simplify">
</head>
<body class="bg-light student-dashboard-page">

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="dash-content content-wrapper portal-dashboard pt-3">
    <section class="welcome-hero hero-branded" aria-label="City and Guilds overview">
        <div class="welcome-hero-text">
            <span class="eyebrow">City &amp; Guilds</span>
            <h1>Candidate progress</h1>
            <p>View your City &amp; Guilds registration, units, assessments, verification status, and support history.</p>
            <div class="hero-chips" aria-label="Candidate context">
                <span class="hero-chip"><i class="fas fa-id-card"></i> <?php echo cg_h($studentId); ?></span>
                <span class="hero-chip"><i class="fas fa-certificate"></i> <?php echo count($learners); ?> registration<?php echo count($learners) === 1 ? '' : 's'; ?></span>
                <span class="hero-chip"><i class="fas fa-calendar"></i> <?php echo cg_h(date('D, d M Y')); ?></span>
            </div>
        </div>
        <div class="welcome-hero-meta">
            <span class="hero-badge <?php echo $learners ? 'green' : 'amber'; ?>">
                <i class="fas <?php echo $learners ? 'fa-circle-check' : 'fa-circle-info'; ?>"></i>
                <?php echo $learners ? 'Registered candidate' : 'No City & Guilds record'; ?>
            </span>
        </div>
    </section>

    <?php if (empty($learners)): ?>
        <article class="card">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-certificate"></i>
                    <p>No City &amp; Guilds candidate registration is linked to your student account yet.</p>
                </div>
            </div>
        </article>
    <?php else: ?>

    <section class="stats-grid" aria-label="City and Guilds statistics">
        <article class="stat-card">
            <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="stat-label">Registrations</div>
                <div class="stat-value"><?php echo count($learners); ?></div>
                <div class="stat-sub">candidate records</div>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-book-open"></i></div>
            <div>
                <div class="stat-label">Units</div>
                <div class="stat-value"><?php echo $totalUnits; ?></div>
                <div class="stat-sub">assigned or scheduled</div>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-icon <?php echo $upcomingAssessments > 0 ? 'amber' : 'green'; ?>"><i class="fas fa-clipboard-list"></i></div>
            <div>
                <div class="stat-label">Assessments</div>
                <div class="stat-value"><?php echo $totalAssessments; ?></div>
                <div class="stat-sub"><?php echo $upcomingAssessments; ?> upcoming</div>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-icon <?php echo $verifiedAssessments === $totalAssessments && $totalAssessments > 0 ? 'green' : 'blue'; ?>"><i class="fas fa-check-double"></i></div>
            <div>
                <div class="stat-label">Verified</div>
                <div class="stat-value"><?php echo $verifiedAssessments; ?></div>
                <div class="stat-sub">of <?php echo $totalAssessments; ?> assessments</div>
            </div>
        </article>
    </section>

    <section class="dash-grid" aria-label="City and Guilds dashboard layout">
        <section aria-label="Candidate registrations">
            <article class="card">
                <div class="card-hdr">
                    <h3><i class="fas fa-id-badge"></i> Candidate Registration</h3>
                </div>
                <div class="card-body">
                    <?php foreach ($learners as $learner): ?>
                        <?php
                            $total = (int)($learner['assessments_total'] ?? 0);
                            $verified = (int)($learner['assessments_verified'] ?? 0);
                            $percent = $total > 0 ? (int)round(($verified / $total) * 100) : 0;
                        ?>
                        <div class="profile-details mb-3">
                            <div class="profile-row">
                                <span class="profile-row-label">Candidate number</span>
                                <span class="profile-row-value"><?php echo cg_h($learner['candidate_number']); ?></span>
                            </div>
                            <div class="profile-row">
                                <span class="profile-row-label">Qualification</span>
                                <span class="profile-row-value"><?php echo cg_h($learner['qualification_code']); ?></span>
                            </div>
                            <div class="profile-row">
                                <span class="profile-row-label">Cohort</span>
                                <span class="profile-row-value"><?php echo cg_h($learner['cohort']); ?></span>
                            </div>
                            <div class="profile-row">
                                <span class="profile-row-label">Status</span>
                                <span class="profile-row-value"><?php echo cg_h($learner['progress_status']); ?></span>
                            </div>
                        </div>
                        <div class="fee-progress-meta">
                            <span>Verified progress</span>
                            <span><?php echo $percent; ?>%</span>
                        </div>
                        <div class="fee-progress" role="progressbar" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="City and Guilds verified progress">
                            <div class="fee-progress-bar <?php echo $percent >= 50 ? 'good' : 'low'; ?>" style="width: <?php echo $percent; ?>%;"></div>
                        </div>
                        <?php if (!empty($learner['next_session'])): ?>
                            <p class="fee-progress-hint"><i class="fas fa-calendar-day"></i> Next session: <?php echo cg_h(cg_student_datetime($learner['next_session'])); ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="card">
                <div class="card-hdr">
                    <h3><i class="fas fa-hands-helping"></i> Support History</h3>
                    <span class="badge bg-primary"><?php echo count($supportInterventions); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($supportInterventions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-circle-check"></i>
                            <p>No support interventions have been recorded for your City &amp; Guilds record.</p>
                        </div>
                    <?php else: ?>
                        <ul class="deadline-list">
                            <?php foreach ($supportInterventions as $support): ?>
                                <li class="deadline-item">
                                    <span class="deadline-icon blue"><i class="fas fa-hand-holding-heart"></i></span>
                                    <span class="deadline-copy">
                                        <strong><?php echo cg_h($support['intervention_type']); ?></strong>
                                        <small><?php echo cg_h($support['notes']); ?></small>
                                    </span>
                                    <span class="deadline-when blue">
                                        <strong><?php echo cg_h(cg_student_date($support['support_date'])); ?></strong>
                                        <small>Follow-up: <?php echo cg_h(cg_student_date($support['follow_up_date'] ?? '', 'None')); ?></small>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </article>
        </section>

        <section aria-label="City and Guilds activities">
            <article class="card">
                <div class="card-hdr">
                    <h3><i class="fas fa-calendar-alt"></i> Units &amp; Schedule</h3>
                    <span class="badge bg-primary"><?php echo count($assignments); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($assignments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-xmark"></i>
                            <p>No City &amp; Guilds units have been scheduled yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Unit</th>
                                        <th>Instructor</th>
                                        <th>Schedule</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $assignment): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo cg_h($assignment['unit_code'] ?: 'Unit'); ?></strong><br>
                                                <small><?php echo cg_h($assignment['unit_title'] ?: 'Not titled'); ?></small>
                                            </td>
                                            <td><?php echo cg_h(trim(($assignment['staff_title'] ?? '') . ' ' . ($assignment['staff_fname'] ?? '') . ' ' . ($assignment['staff_lname'] ?? '')) ?: $assignment['instructor_id']); ?></td>
                                            <td>
                                                <?php echo cg_h(cg_student_datetime($assignment['schedule_start'])); ?><br>
                                                <small><?php echo cg_h($assignment['location'] ?: 'Location TBA'); ?></small>
                                            </td>
                                            <td><span class="badge bg-light text-dark border"><?php echo cg_h($assignment['assignment_status']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="card">
                <div class="card-hdr">
                    <h3><i class="fas fa-clipboard-check"></i> Assessments</h3>
                    <span class="badge bg-primary"><?php echo count($assessments); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($assessments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-circle-plus"></i>
                            <p>No City &amp; Guilds assessments have been recorded yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Assessment</th>
                                        <th>Type</th>
                                        <th>Due</th>
                                        <th>Result</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assessments as $assessment): ?>
                                        <?php $verified = (int)($assessment['is_verified'] ?? 0) === 1; ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo cg_h($assessment['title']); ?></strong><br>
                                                <small><?php echo cg_h(($assessment['unit_code'] ?? '') . ' ' . ($assessment['unit_title'] ?? '')); ?></small>
                                            </td>
                                            <td><?php echo cg_h(ucfirst(strtolower($assessment['assessment_type'])) . ' / ' . ucfirst($assessment['assessment_mode'])); ?></td>
                                            <td><?php echo cg_h(cg_student_datetime($assessment['date_due'])); ?></td>
                                            <td>
                                                <?php if ($verified): ?>
                                                    <span class="badge bg-success"><?php echo cg_h($assessment['grade'] ?: 'Verified'); ?></span><br>
                                                    <small><?php echo cg_h(cg_student_date($assessment['verified_at'])); ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Pending IQA</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </section>
    </section>
    <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


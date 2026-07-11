<?php
/**
 * Quality Assurance Reports - HOD Workspace
 */

$page_title = 'Quality Assurance Reports';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';

// Scope QA analytics to the courses of this HOS's section (all departments in
// the section), so one section head never sees another section's evaluations.
$hodStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$deptContext = hod_resolve_department($db, $hodStaffId);
$qaCourseCodes = [];
$qaDeptIds = (array)($deptContext['candidates'] ?? []);
if (empty($qaDeptIds) && (string)$deptContext['id'] !== '') {
    $qaDeptIds = [(string)$deptContext['id']];
}
foreach ($qaDeptIds as $qaDeptId) {
    $qaCourseCodes = array_merge($qaCourseCodes, hod_department_course_codes($db, (string)$qaDeptId, $hodStaffId));
}
$qaCourseCodes = array_values(array_unique(array_filter($qaCourseCodes)));

$qaScopeCond = '';
$qaScopeTypes = '';
if (!empty($qaCourseCodes)) {
    $qaScopeCond = 'e.course_code IN (' . implode(',', array_fill(0, count($qaCourseCodes), '?')) . ')';
    $qaScopeTypes = str_repeat('s', count($qaCourseCodes));
}

$qaScopedRows = function (string $sql) use ($db, $qaScopeTypes, $qaCourseCodes): array {
    $out = [];
    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return $out;
    }
    if ($qaScopeTypes !== '') {
        $stmt->bind_param($qaScopeTypes, ...$qaCourseCodes);
    }
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $out[] = $row;
        }
    }
    $stmt->close();
    return $out;
};

// 1. Course Performance Averages
$courseAverages = [];
// 2. Lecturer Performance Ratings
$lecturerRatings = [];
// 3. Overall Service & Facility Benchmarks
$overallStats = ['avg_facilities' => 0, 'avg_services' => 0, 'total' => 0];
// 4. Open-ended Feedback Comments
$comments = [];

if ($qaScopeCond !== '') {
    $courseAverages = $qaScopedRows("
        SELECT
            e.course_code,
            c.course_name,
            COUNT(e.id) as evaluations_count,
            AVG(e.rating_lecturer) as avg_lecturer,
            AVG(e.rating_content) as avg_content,
            AVG(e.rating_facilities) as avg_facilities,
            AVG(e.rating_services) as avg_services,
            (AVG(e.rating_lecturer) + AVG(e.rating_content) + AVG(e.rating_facilities) + AVG(e.rating_services)) / 4 as overall_average
        FROM course_evaluations e
        LEFT JOIN courses c ON e.course_code = c.course_code
        WHERE {$qaScopeCond}
        GROUP BY e.course_code, c.course_name
        ORDER BY overall_average DESC");

    $lecturerRatings = $qaScopedRows("
        SELECT
            e.lecturer_id,
            s.Fname,
            s.Lname,
            COUNT(e.id) as evaluations_count,
            AVG(e.rating_lecturer) as avg_rating
        FROM course_evaluations e
        LEFT JOIN staff s ON e.lecturer_id = s.staff_id
        WHERE {$qaScopeCond}
        GROUP BY e.lecturer_id, s.Fname, s.Lname
        ORDER BY avg_rating DESC");

    $statsRows = $qaScopedRows("
        SELECT AVG(e.rating_facilities) as avg_fac, AVG(e.rating_services) as avg_ser, COUNT(e.id) as total
        FROM course_evaluations e
        WHERE {$qaScopeCond}");
    if (!empty($statsRows)) {
        $row = $statsRows[0];
        $overallStats['avg_facilities'] = round((float)($row['avg_fac'] ?? 0.0), 2);
        $overallStats['avg_services'] = round((float)($row['avg_ser'] ?? 0.0), 2);
        $overallStats['total'] = (int)($row['total'] ?? 0);
    }

    $comments = $qaScopedRows("
        SELECT e.course_code, e.comments, e.created_at
        FROM course_evaluations e
        WHERE e.comments IS NOT NULL AND TRIM(e.comments) <> ''
          AND {$qaScopeCond}
        ORDER BY e.created_at DESC LIMIT 30");
}
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-award text-purple me-2"></i>Quality Assurance Reports</h1>
            <p class="text-muted mb-0">Track anonymous student feedback, lecturer performance indices, and course evaluations.</p>
        </div>
        <a href="ajax/qa_export.php" class="btn btn-purple btn-sm"><i class="fas fa-file-csv me-1"></i>Export QA Report</a>
    </div>

    <!-- Overview Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-left-purple shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-purple text-uppercase mb-1">Total Submissions</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $overallStats['total'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-poll fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-left-success shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Facilities Rating</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $overallStats['avg_facilities'] ?> / 5.0</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-university fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-left-info shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Support Services Rating</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $overallStats['avg_services'] ?> / 5.0</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-headset fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Tabs -->
    <div class="row g-4">
        
        <!-- Course Evaluations Summary -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 font-weight-bold text-purple"><i class="fas fa-book me-2"></i>Course Performance Scores</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($courseAverages)): ?>
                        <div class="p-4 text-center text-muted">
                            <p class="mb-0">No course evaluations submitted yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Course</th>
                                        <th class="text-center">Count</th>
                                        <th class="text-center">Lecturing</th>
                                        <th class="text-center">Content</th>
                                        <th class="text-center">Overall Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($courseAverages as $c): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($c['course_code']) ?></strong>
                                                <div class="small text-muted"><?= htmlspecialchars($c['course_name'] ?? '') ?></div>
                                            </td>
                                            <td class="text-center"><?= $c['evaluations_count'] ?></td>
                                            <td class="text-center font-weight-bold"><?= round((float)$c['avg_lecturer'], 1) ?></td>
                                            <td class="text-center font-weight-bold"><?= round((float)$c['avg_content'], 1) ?></td>
                                            <td class="text-center">
                                                <span class="badge <?= $c['overall_average'] >= 4.0 ? 'bg-success' : ($c['overall_average'] >= 3.0 ? 'bg-warning' : 'bg-danger') ?>">
                                                    <?= round((float)$c['overall_average'], 2) ?> / 5.0
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Open Feedback Comments -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 font-weight-bold text-purple"><i class="fas fa-comments me-2"></i>Suggestions &amp; Comments</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($comments)): ?>
                        <p class="text-muted text-center mb-0 py-3">No suggestions logged yet.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($comments as $cm): ?>
                                <div class="list-group-item px-0 py-3">
                                    <div class="d-flex w-100 justify-content-between mb-1">
                                        <h6 class="font-weight-bold text-purple mb-0">Course: <?= htmlspecialchars($cm['course_code']) ?></h6>
                                        <small class="text-muted"><?= date('M d, Y H:i', strtotime($cm['created_at'])) ?></small>
                                    </div>
                                    <p class="mb-0 text-gray-800 bg-light p-2 rounded small">"<?= htmlspecialchars($cm['comments']) ?>"</p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Lecturer Ratings Summary -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 font-weight-bold text-purple"><i class="fas fa-chalkboard-teacher me-2"></i>Lecturer Performance Index</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($lecturerRatings)): ?>
                        <div class="p-4 text-center text-muted">
                            <p class="mb-0">No lecturer scores submitted yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Lecturer</th>
                                        <th class="text-center">Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lecturerRatings as $l): ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?= htmlspecialchars($l['Fname'] . ' ' . $l['Lname']) ?></div>
                                                <small class="text-muted">ID: <?= htmlspecialchars($l['lecturer_id']) ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-purple-soft text-purple">
                                                    <?= round((float)$l['avg_rating'], 2) ?> / 5.0
                                                </span>
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

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>

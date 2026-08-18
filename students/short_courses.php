<?php
/**
 * Student-facing view of enrolled short courses and their published content
 * (modules + materials). Read-only; access limited to courses the student is
 * actually enrolled in.
 */
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/short_course_student.php';

$sid = $_SESSION['Sid'] ?? '';
$enrolments = $sid !== '' ? sc_student_enrolments($db, $sid) : [];

// Map enrolled short_course_id and course_code => enrolment row (for access checks + display).
$enrolledById = [];
$enrolledByCode = [];
foreach ($enrolments as $e) {
    if (!empty($e['short_course_id'])) {
        $enrolledById[(int)$e['short_course_id']] = $e;
    }
    if (!empty($e['course_code'])) {
        $enrolledByCode[strtoupper(trim((string)$e['course_code']))] = $e;
    }
}

$viewId = (int)($_GET['id'] ?? 0);
$viewCode = strtoupper(trim((string)($_GET['code'] ?? '')));
$activeCourse = null;
if ($viewId > 0 && isset($enrolledById[$viewId])) {
    $activeCourse = $enrolledById[$viewId];
} elseif ($viewCode !== '' && isset($enrolledByCode[$viewCode])) {
    $activeCourse = $enrolledByCode[$viewCode];
    $viewId = (int)($activeCourse['short_course_id'] ?? 0);
}
$accessDenied = (($viewId > 0 || $viewCode !== '') && $activeCourse === null);

// CA marks per enrolled short course. Dual-enrolled long-programme students
// never reach the short-course branch of continuousAssessment.php, so their
// short-course marks are surfaced here instead.
$caByCourseId = [];
if ($sid !== '' && $enrolments) {
    $tbl = $db->query("SHOW TABLES LIKE 'short_course_assessment'");
    $hasCaTable = $tbl && $tbl->num_rows > 0;
    if ($tbl) { $tbl->free(); }
    if ($hasCaTable && ($caStmt = $db->prepare('SELECT short_course_id, A1, A2, T1, T2, Total_CA FROM short_course_assessment WHERE student_id = ?'))) {
        $caStmt->bind_param('s', $sid);
        $caStmt->execute();
        $caRes = $caStmt->get_result();
        while ($caRow = $caRes->fetch_assoc()) {
            $caByCourseId[(int)$caRow['short_course_id']] = $caRow;
        }
        $caStmt->close();
    }
}

// Load published modules + materials for the selected course.
$modules = [];
if ($activeCourse) {
    $ms = $db->prepare("SELECT * FROM short_course_modules WHERE short_course_id = ? AND is_published = 1 ORDER BY position, id");
    $ms->bind_param("i", $viewId);
    $ms->execute();
    $mres = $ms->get_result();
    while ($m = $mres->fetch_object()) { $m->materials = []; $modules[(int)$m->id] = $m; }
    if ($modules) {
        $matRes = $db->query("SELECT * FROM short_course_materials WHERE short_course_id = " . (int)$viewId . " ORDER BY id");
        while ($mat = $matRes->fetch_object()) {
            if (isset($modules[(int)$mat->module_id])) { $modules[(int)$mat->module_id]->materials[] = $mat; }
        }
    }
}
$moduleList = array_values($modules);

function sc_status_badge(string $status): string {
    $map = ['enrolled' => 'success', 'active' => 'info', 'completed' => 'primary'];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '-subtle text-' . $cls . ' border">' . ucfirst($status) . '</span>';
}

function sc_safe_module_html(?string $html): string {
    if ($html === null || $html === '') return '';
    $allowed = '<p><br><strong><b><em><i><u><s><a><ul><ol><li><blockquote><pre><code><h1><h2><h3><h4><h5><h6><span><div><img><table><thead class="table-light"><tbody><tr><td><th><hr>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
    $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', '$1="#"', $clean);
    return $clean;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Short Courses</title>
    <style>
        .content-wrapper { font-size: 14px; }
        .sc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        .sc-card { background: #fff; border: 1px solid #eef0f4; border-radius: 12px; overflow: hidden; transition: transform .15s, box-shadow .15s; }
        .sc-card:hover { transform: translateY(-3px); box-shadow: 0 8px 22px rgba(0,0,0,.08); }
        .sc-card .head { background: linear-gradient(135deg,#6f42c1,#4e2a84); color:#fff; padding:16px; }
        .sc-card .head h5 { margin:0; font-size:1rem; }
        .sc-card .head small { opacity:.9; }
        .sc-card .body { padding:16px; }
        .module-card { border:1px solid #eef0f4; border-radius:12px; background:#fff; margin-bottom:14px; }
        .module-card .mhead { padding:14px 18px; border-bottom:1px solid #f1f3f7; }
        .module-card .mbody { padding:14px 18px; }
        .material-row { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid #f0f2f6; border-radius:8px; margin-bottom:8px; text-decoration:none; }
        .material-row:hover { background:#faf8ff; }
        .material-row .ic { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; background:#f3eefc; color:#6f42c1; flex:0 0 auto; }
        .empty { text-align:center; padding:50px 20px; color:#6b7280; }
        .empty i { font-size:2.2rem; color:#d8dbe0; display:block; margin-bottom:12px; }
        .module-content-body { background:#fafbfc; border:1px solid #eef0f4; border-radius:8px; padding:12px 16px; color:#495057; line-height:1.6; }
        .module-content-body img { max-width:100%; height:auto; border-radius:6px; }
        .module-content-body h1,.module-content-body h2,.module-content-body h3,.module-content-body h4 { margin-top:.6em; }
    </style>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-certificate text-primary me-2"></i>My Short Courses</h2>
            <p class="text-muted mb-0">Access modules and materials for your short courses.</p>
        </div>
        <?php if ($activeCourse): ?>
            <a href="short_courses.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left me-1"></i>All My Short Courses</a>
        <?php endif; ?>
    </div>
    <hr>

    <?php if ($accessDenied): ?>
        <div class="alert alert-warning"><i class="fas fa-lock me-2"></i>You are not enrolled in that short course.</div>
    <?php endif; ?>

    <?php if ($activeCourse): ?>
        <!-- Single course content view -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-1"><?= htmlspecialchars($activeCourse['course_name']) ?></h5>
                    <span class="badge bg-light text-dark border me-2"><?= htmlspecialchars($activeCourse['course_code']) ?></span>
                    <?php $dur = sc_format_duration($activeCourse['duration_value'], $activeCourse['duration_unit']); ?>
                    <?php if ($dur): ?><span class="text-muted small me-2"><i class="fas fa-clock me-1"></i><?= htmlspecialchars($dur) ?></span><?php endif; ?>
                    <?= sc_status_badge((string)$activeCourse['status']) ?>
                </div>
            </div>
        </div>

        <?php $caRow = $caByCourseId[(int)$activeCourse['short_course_id']] ?? null; ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-chart-line text-primary me-2"></i>Continuous Assessment</h6>
                <?php if ($caRow === null): ?>
                    <div class="text-muted small"><i class="fas fa-info-circle me-1"></i>No CA marks recorded for you yet. Check back after your instructor uploads them.</div>
                <?php else: ?>
                    <?php $fmtCa = static function ($v): string { return ($v === null || $v === '') ? '&mdash;' : number_format((float)$v, 1) . '%'; }; ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-1">
                            <thead class="table-light">
                                <tr><th>Assignment 1</th><th>Assignment 2</th><th>Test 1</th><th>Test 2</th><th class="text-end">Total CA</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?= $fmtCa($caRow['A1']) ?></td>
                                    <td><?= $fmtCa($caRow['A2']) ?></td>
                                    <td><?= $fmtCa($caRow['T1']) ?></td>
                                    <td><?= $fmtCa($caRow['T2']) ?></td>
                                    <td class="text-end fw-semibold"><?= $fmtCa($caRow['Total_CA']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($moduleList)): ?>
            <div class="empty"><i class="fas fa-book-open"></i><h5 class="text-muted">No content published yet</h5>
                <p>Your instructor hasn't published modules for this course yet. Check back later.</p></div>
        <?php else: ?>
            <?php foreach ($moduleList as $idx => $m): ?>
                <div class="module-card">
                    <div class="mhead">
                        <strong><?= ($idx + 1) . '. ' . htmlspecialchars($m->title) ?></strong>
                        <?php if ($m->description): ?><div class="text-muted small mt-1"><?= nl2br(htmlspecialchars($m->description)) ?></div><?php endif; ?>
                    </div>
                    <div class="mbody">
                        <?php if (!empty($m->content)): ?>
                            <div class="module-content-body mb-3"><?= sc_safe_module_html($m->content) ?></div>
                        <?php endif; ?>
                        <?php if (empty($m->materials)): ?>
                            <div class="text-muted small"><i class="fas fa-info-circle me-1"></i>No materials in this module.</div>
                        <?php else: ?>
                            <?php foreach ($m->materials as $mat): ?>
                                <a class="material-row" href="<?= htmlspecialchars($mat->url) ?>" target="_blank" rel="noopener">
                                    <span class="ic"><i class="fas fa-<?= $mat->material_type === 'link' ? 'external-link-alt' : 'file-download' ?>"></i></span>
                                    <span class="flex-grow-1">
                                        <span class="fw-semibold d-block text-dark"><?= htmlspecialchars($mat->title) ?></span>
                                        <small class="text-muted"><?= $mat->material_type === 'link' ? 'Open link' : 'Download file' ?></small>
                                    </span>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>
        <!-- List of enrolled short courses -->
        <?php if (empty($enrolments)): ?>
            <div class="empty"><i class="fas fa-certificate"></i><h5 class="text-muted">No short courses</h5>
                <p>You are not enrolled in any short courses.</p></div>
        <?php else: ?>
            <div class="sc-grid">
                <?php foreach ($enrolments as $e): ?>
                    <div class="sc-card">
                        <div class="head">
                            <h5><?= htmlspecialchars($e['course_name']) ?></h5>
                            <small><?= htmlspecialchars($e['course_code']) ?></small>
                        </div>
                        <div class="body">
                            <div class="mb-2">
                                <?= sc_status_badge((string)$e['status']) ?>
                                <?php $cardCa = $caByCourseId[(int)$e['short_course_id']] ?? null; ?>
                                <?php if ($cardCa && $cardCa['Total_CA'] !== null && $cardCa['Total_CA'] !== ''): ?>
                                    <span class="badge bg-success-subtle text-success border ms-1" title="Total CA"><i class="fas fa-chart-line me-1"></i>CA <?= number_format((float)$cardCa['Total_CA'], 1) ?>%</span>
                                <?php endif; ?>
                                <?php $dur = sc_format_duration($e['duration_value'], $e['duration_unit']); ?>
                                <?php if ($dur): ?><span class="text-muted small ms-1"><i class="fas fa-clock me-1"></i><?= htmlspecialchars($dur) ?></span><?php endif; ?>
                            </div>
                            <?php $openUrl = !empty($e['short_course_id']) ? 'short_courses.php?id=' . (int)$e['short_course_id'] : 'short_courses.php?code=' . urlencode((string)$e['course_code']); ?>
                            <a href="<?= htmlspecialchars($openUrl) ?>" class="btn btn-primary btn-sm w-100">
                                <i class="fas fa-folder-open me-1"></i>Open Content
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

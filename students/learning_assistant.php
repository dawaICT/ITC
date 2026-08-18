<?php
declare(strict_types=1);
$page_title = 'My Learning Assistant';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';
require_once dirname(__DIR__) . '/services/support/SupportCaseService.php';

$studentId = (string)$_SESSION['Sid'];
$courseCodes = array_values(array_filter(array_map('strval', getStudentEnrolledCourses($db, $studentId))));
$courses = [];
if ($courseCodes) {
    $ph = implode(',', array_fill(0, count($courseCodes), '?'));
    $types = str_repeat('s', count($courseCodes));
    $stmt = $db->prepare("SELECT course_code,course_name FROM courses WHERE course_code IN ({$ph}) ORDER BY course_name");
    $stmt->bind_param($types, ...$courseCodes);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$supportService = new SupportCaseService($db);
$cases = $supportService->studentCases($studentId);
$selectedCase = null;
if ((int)($_GET['case'] ?? 0) > 0) {
    try { $selectedCase = $supportService->getStudentCase($studentId, (int)$_GET['case']); }
    catch (Throwable $e) { $selectedCase = null; }
}
$csrf = (string)$_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>My Learning Assistant - ITC</title>
    <?php require_once dirname(__DIR__) . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="/wucportal/css/ai-learning.css?v=20260720b">
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>
<main class="content-wrapper ai-page">
  <div class="container-fluid px-4">
    <header class="student-page-heading d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="page-title mb-1"><i class="fas fa-graduation-cap me-2"></i>My Learning Assistant</h3>
            <p class="page-subtitle text-muted mb-0">Course-aware explanations, revision practice, approved sources, and a direct bridge to your assigned lecturer.</p>
        </div>
        <span class="badge bg-light text-dark border"><i class="fas fa-shield-halved me-1"></i>Learning support</span>
    </header>

    <div class="ai-grid">
        <section>
            <article class="ai-card">
                <div class="ai-card-header"><h2><i class="fas fa-message me-2 text-primary"></i>Ask about a registered course</h2><span class="badge bg-light text-dark">AI draft</span></div>
                <div class="ai-card-body">
                    <?php if (!$courses): ?>
                        <div class="alert alert-info mb-0"><i class="fas fa-circle-info me-2"></i>No active registered courses are available for AI support.</div>
                    <?php else: ?>
                    <form id="assistantForm">
                        <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="row g-3">
                            <div class="col-md-7"><label class="form-label fw-semibold" for="courseId">Course</label><select class="form-select" id="courseId" required><?php foreach ($courses as $course): ?><option value="<?= htmlspecialchars((string)$course['course_code'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$course['course_code'] . ' — ' . (string)$course['course_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-5"><label class="form-label fw-semibold" for="explanationLevel">Explanation level</label><select class="form-select" id="explanationLevel"><option value="simple">Simple</option><option value="standard" selected>Standard</option><option value="detailed">Detailed</option></select></div>
                            <div class="col-12"><label class="form-label fw-semibold" for="question">Your question</label><textarea class="form-control" id="question" rows="4" maxlength="2000" placeholder="Ask about a concept from this course…" required></textarea></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-ai px-4" type="submit"><i class="fas fa-paper-plane me-2"></i>Ask</button><button class="btn btn-outline-primary" type="button" id="practiceButton"><i class="fas fa-dumbbell me-2"></i>Generate practice</button></div>
                    </form>
                    <?php endif; ?>
                </div>
            </article>

            <article class="ai-card">
                <div class="ai-card-header"><h2><i class="fas fa-sparkles me-2 text-primary"></i>Response</h2><span id="sourceStatus" class="ai-status draft">Waiting</span></div>
                <div class="ai-card-body">
                    <div id="answer" class="ai-answer is-empty"><div><i class="fas fa-book-open fa-2x mb-2"></i><br>Choose a course and ask a learning question.</div></div>
                    <div id="sources" class="mt-3"></div>
                    <div id="actions" class="ai-actions d-none">
                        <button class="btn btn-sm btn-outline-secondary" data-followup="Explain that more simply."><i class="fas fa-child-reaching me-1"></i>Explain simpler</button>
                        <button class="btn btn-sm btn-outline-secondary" data-followup="Give me a clear course-related example."><i class="fas fa-lightbulb me-1"></i>Give example</button>
                        <button class="btn btn-sm btn-outline-success" data-feedback="helpful"><i class="fas fa-thumbs-up me-1"></i>Helpful</button>
                        <button class="btn btn-sm btn-outline-danger" data-feedback="incorrect"><i class="fas fa-flag me-1"></i>Report incorrect</button>
                        <button class="btn btn-sm btn-ai" id="askLecturerButton"><i class="fas fa-chalkboard-user me-1"></i>Ask My Lecturer</button>
                    </div>
                </div>
            </article>
            <div class="ai-disclaimer"><i class="fas fa-shield-halved me-2"></i>AI answers are learning support drafts. They cannot award marks, alter records, decide progression, or replace your lecturer.</div>
        </section>

        <aside>
            <?php if ($selectedCase): ?>
            <article class="ai-card"><div class="ai-card-header"><h3><i class="fas fa-comments me-2 text-primary"></i>Support case #<?= (int)$selectedCase['id'] ?></h3><span class="ai-status <?= htmlspecialchars((string)$selectedCase['status']) ?>"><?= htmlspecialchars(str_replace('_',' ',ucfirst((string)$selectedCase['status']))) ?></span></div><div class="ai-card-body"><p class="fw-semibold mb-1"><?= htmlspecialchars((string)$selectedCase['course_id']) ?></p><p class="small text-muted"><?= nl2br(htmlspecialchars((string)$selectedCase['original_question'])) ?></p><?php foreach ($selectedCase['messages'] as $message): ?><div class="ai-case"><strong><?= htmlspecialchars(ucwords(str_replace('_',' ',(string)$message['sender_role']))) ?></strong><div><?= nl2br(htmlspecialchars((string)$message['message'])) ?></div><small class="text-muted"><?= htmlspecialchars((string)$message['created_at']) ?></small></div><?php endforeach; ?><?php if (!in_array($selectedCase['status'], ['resolved','closed'], true)): ?><textarea class="form-control mb-2" id="caseFollowUp" rows="2" maxlength="4000" placeholder="Add a follow-up for your lecturer…"></textarea><button class="btn btn-sm btn-outline-primary me-2" id="followUpButton" data-case-id="<?= (int)$selectedCase['id'] ?>"><i class="fas fa-reply me-1"></i>Send follow-up</button><button class="btn btn-sm btn-outline-success" id="resolveCaseButton" data-case-id="<?= (int)$selectedCase['id'] ?>"><i class="fas fa-check me-1"></i>Mark resolved</button><?php elseif ($selectedCase['status']==='resolved'): ?><button class="btn btn-sm btn-outline-secondary" id="closeCaseButton" data-case-id="<?= (int)$selectedCase['id'] ?>"><i class="fas fa-box-archive me-1"></i>Close case</button><?php endif; ?></div></article>
            <?php endif; ?>
            <article class="ai-card">
                <div class="ai-card-header"><h3><i class="fas fa-headset me-2 text-primary"></i>My support cases</h3><span class="badge bg-primary"><?= count($cases) ?></span></div>
                <div class="ai-card-body">
                    <?php if (!$cases): ?><div class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2"></i><p class="mb-0">No lecturer support cases yet.</p></div><?php else: foreach ($cases as $case): ?>
                    <a class="ai-case d-block text-decoration-none text-dark" href="?case=<?= (int)$case['id'] ?>"><div class="d-flex justify-content-between gap-2"><strong><?= htmlspecialchars((string)$case['course_id']) ?></strong><span class="ai-status <?= htmlspecialchars((string)$case['status']) ?>"><?= htmlspecialchars(str_replace('_',' ',(string)$case['status'])) ?></span></div><div class="ai-case-meta mt-2">Case #<?= (int)$case['id'] ?> · <?= htmlspecialchars((string)$case['updated_at']) ?></div></a>
                    <?php endforeach; endif; ?>
                </div>
            </article>
        </aside>
    </div>
  </div>
</main>

<div class="modal fade" id="lecturerModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Ask My Lecturer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label fw-semibold" for="studentAttempt">What have you tried?</label><textarea class="form-control" id="studentAttempt" rows="4" maxlength="4000" placeholder="Share your attempt so your lecturer can guide you."></textarea><label class="form-label fw-semibold mt-3" for="casePriority">Priority</label><select class="form-select" id="casePriority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option></select></div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-ai" id="sendLecturerRequest">Send request</button></div></div></div></div>
<script>window.WUC_AI={csrf:<?= json_encode($csrf) ?>};</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/wucportal/students/js/learning-assistant.js?v=20260720"></script>
</body></html>

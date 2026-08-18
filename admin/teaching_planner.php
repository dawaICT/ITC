<?php
declare(strict_types=1);

$page_title = 'Teaching Planner Administration';
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/teaching_planner/init.php';

if (!function_exists('isSystemsAdmin') || !isSystemsAdmin()) {
    http_response_code(403);
    $_SESSION['errorMessage'] = 'Teaching Planner template administration requires Systems Administrator access.';
    header('Location: /wucportal/portal_selection.php');
    exit;
}

$adminService = new TeachingPlannerAdminService($db, new TeachingPlannerTemplateValidator());
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        tp_require_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'upload_template') {
            $id = $adminService->uploadTemplate($_FILES['template_file'] ?? [], $_POST, tp_actor_id());
            tp_flash('success', 'Template version uploaded and validated. Version reference: ' . $id . '. Activate it after reviewing the validation report.');
        } elseif ($action === 'activate_template') {
            $adminService->activateTemplate((int)($_POST['version_id'] ?? 0), tp_actor_id());
            tp_flash('success', 'Template version activated. Any previously active version was retained and marked superseded.');
        } elseif ($action === 'create_syllabus') {
            $outcomes = (array)($_POST['outcomes'] ?? []);
            $topics = [];
            foreach ((array)($_POST['topic_title'] ?? []) as $index => $title) {
                $topics[] = [
                    'topic_title' => $title,
                    'subtopics' => $_POST['topic_subtopics'][$index] ?? '',
                    'recommended_hours' => $_POST['topic_hours'][$index] ?? 0,
                    'learning_outcomes' => $_POST['topic_outcomes'][$index] ?? '',
                    'assessment_criteria' => $_POST['topic_assessment'][$index] ?? '',
                    'resources' => $_POST['topic_resources'][$index] ?? '',
                ];
            }
            $id = $adminService->createSyllabus($_POST, $outcomes, $topics, tp_actor_id());
            tp_flash('success', 'Structured syllabus draft created with reference ' . $id . '. A Head of Section must approve it before generation.');
        } else {
            throw new RuntimeException('Unsupported administration action.');
        }
        header('Location: teaching_planner.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/includes/nav.php';
$schemaReady = tp_schema_ready($db);
$flash = tp_take_flash();
$templates = $schemaReady ? $adminService->templates() : [];
$syllabi = $schemaReady ? $adminService->syllabi() : [];
$departments = $db->query("SELECT id, department_name FROM departments WHERE status = 'active' ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);
$programs = $db->query("SELECT program_code, program_name, program_type FROM programs WHERE is_active = 1 ORDER BY program_name")->fetch_all(MYSQLI_ASSOC);
$courses = $db->query("SELECT course_code, course_name FROM courses WHERE status = 'active' ORDER BY course_code")->fetch_all(MYSQLI_ASSOC);
$curricula = $db->query("SELECT id, program_code, version_name, status FROM curriculum_versions WHERE status IN ('active','draft') ORDER BY program_code, version_name DESC")->fetch_all(MYSQLI_ASSOC);
?>
<link rel="stylesheet" href="/wucportal/css/teaching-planner.css">
<div class="container-fluid px-4 py-4 portal-dashboard tp-page">
    <section class="tp-hero mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h3 mb-1"><i class="fas fa-file-signature me-2"></i>Teaching Planner Administration</h1>
                <p class="mb-0 opacity-75">Control approved document templates and structured syllabus drafts. Academic approval remains with the Head of Section.</p>
            </div>
            <a href="teaching_planner_guide.php" class="btn btn-outline-secondary"><i class="fas fa-book-open me-1"></i>Template guide</a>
        </div>
    </section>

    <?php if (!$schemaReady): ?>
        <div class="alert alert-danger"><strong>Setup required:</strong> run <code>migrations/20260711_teaching_planner.sql</code> before using this module.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation me-2"></i><?= tp_h($error) ?></div><?php endif; ?>
    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'warning' ?>"><?= tp_h($flash['message']) ?></div><?php endif; ?>

    <ul class="nav nav-pills gap-2 mb-4" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#templates" type="button"><i class="fas fa-file-word me-1"></i>Templates</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#syllabi" type="button"><i class="fas fa-list-check me-1"></i>Syllabi</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="templates">
            <div class="row g-4">
                <div class="col-xl-4">
                    <article class="tp-card">
                        <header class="tp-card-header"><h2 class="h5 mb-0"><i class="fas fa-cloud-arrow-up text-primary me-2"></i>Upload template version</h2></header>
                        <div class="tp-card-body">
                            <form method="post" enctype="multipart/form-data" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= tp_h(tp_csrf_token()) ?>">
                                <input type="hidden" name="action" value="upload_template">
                                <div class="col-12"><label class="form-label">Template name</label><input class="form-control" name="name" maxlength="160" required></div>
                                <div class="col-12"><label class="form-label">Add version to existing series</label><select class="form-select" name="template_id"><option value="">Create new series</option><?php $series = []; foreach ($templates as $row) { if (!isset($series[$row['template_id']])) { $series[$row['template_id']] = $row; ?> <option value="<?= (int)$row['template_id'] ?>"><?= tp_h($row['name']) ?> (<?= tp_h(str_replace('_', ' ', $row['document_type'])) ?>)</option><?php }} ?></select></div>
                                <div class="col-md-6"><label class="form-label">Document type</label><select class="form-select" name="document_type" required><option value="scheme_of_work">Scheme of Work</option><option value="lesson_plan">Lesson Plan</option><option value="practical_lesson_plan">Practical Lesson Plan</option><option value="assessment_plan">Assessment Plan</option></select></div>
                                <div class="col-md-6"><label class="form-label">Structure</label><select class="form-select" name="structure_type" required><option value="term">Term</option><option value="semester">Semester</option><option value="annual">Annual</option><option value="short_course">Short course</option></select></div>
                                <div class="col-12"><label class="form-label">Department / section scope</label><select class="form-select" name="department_id"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>"><?= tp_h($department['department_name']) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-6"><label class="form-label">Programme type</label><input class="form-control" name="program_type" maxlength="60" placeholder="Optional"></div>
                                <div class="col-md-6"><label class="form-label">Effective date</label><input class="form-control" type="date" name="effective_date" value="<?= date('Y-m-d') ?>" required></div>
                                <div class="col-12"><label class="form-label">DOCX template</label><input class="form-control" type="file" name="template_file" accept=".docx" required><div class="form-text">Maximum 10 MB. MIME type, ZIP signature, placeholders and checksum are verified.</div></div>
                                <div class="col-12"><button class="btn btn-primary w-100" <?= !$schemaReady ? 'disabled' : '' ?>><i class="fas fa-shield-halved me-1"></i>Upload and validate</button></div>
                            </form>
                        </div>
                    </article>
                </div>
                <div class="col-xl-8">
                    <article class="tp-card">
                        <header class="tp-card-header"><h2 class="h5 mb-0"><i class="fas fa-code-branch text-primary me-2"></i>Template versions</h2><span class="badge bg-primary"><?= count($templates) ?></span></header>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0"><thead><tr><th>Name / scope</th><th>Version</th><th>Validation</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                            <?php if (!$templates): ?><tr><td colspan="5"><div class="tp-empty"><i class="fas fa-file-circle-plus"></i>No planner templates uploaded.</div></td></tr><?php endif; ?>
                            <?php foreach ($templates as $row): $report = tp_decode_json($row['validation_report']); ?>
                                <tr>
                                    <td><strong><?= tp_h($row['name']) ?></strong><div class="small text-muted"><?= tp_h(str_replace('_', ' ', $row['document_type'])) ?> · <?= tp_h($row['structure_type']) ?> · <?= tp_h($row['department_name'] ?: 'All departments') ?></div></td>
                                    <td>v<?= (int)$row['version_number'] ?><div class="small text-muted"><?= tp_h($row['effective_date']) ?></div></td>
                                    <td><?php if (!empty($report['valid'])): ?><span class="badge bg-success">Valid</span><?php else: ?><span class="badge bg-danger">Needs correction</span><?php endif; ?><div class="small mt-1">Missing: <?= tp_h(implode(', ', $report['missing'] ?? []) ?: 'none') ?><br>Unknown: <?= tp_h(implode(', ', $report['unknown'] ?? []) ?: 'none') ?><br>Duplicates: <?= tp_h(implode(', ', array_keys($report['duplicates'] ?? [])) ?: 'none') ?></div></td>
                                    <td><span class="badge bg-<?= $row['status'] === 'active' ? 'success' : ($row['status'] === 'draft' ? 'secondary' : 'warning') ?> tp-status"><?= tp_h($row['status']) ?></span></td>
                                    <td><div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-secondary" href="teaching_planner_template.php?id=<?= (int)$row['id'] ?>&mode=download"><i class="fas fa-download"></i></a><a class="btn btn-sm btn-outline-primary" href="teaching_planner_template.php?id=<?= (int)$row['id'] ?>&mode=preview"><i class="fas fa-eye"></i></a><?php if ($row['status'] === 'draft' && !empty($report['valid'])): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= tp_h(tp_csrf_token()) ?>"><input type="hidden" name="action" value="activate_template"><input type="hidden" name="version_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-success" onclick="return confirm('Activate this immutable template version?')"><i class="fas fa-check"></i> Activate</button></form><?php endif; ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody></table>
                        </div>
                    </article>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="syllabi">
            <div class="row g-4">
                <div class="col-xl-5">
                    <article class="tp-card"><header class="tp-card-header"><h2 class="h5 mb-0"><i class="fas fa-diagram-project text-primary me-2"></i>New structured syllabus draft</h2></header><div class="tp-card-body">
                        <form method="post" id="syllabusForm" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= tp_h(tp_csrf_token()) ?>"><input type="hidden" name="action" value="create_syllabus"><input type="hidden" name="source_type" value="manual">
                            <div class="col-md-6"><label class="form-label">Programme</label><select class="form-select" name="program_code" required><option value="">Choose...</option><?php foreach ($programs as $program): ?><option value="<?= tp_h($program['program_code']) ?>"><?= tp_h($program['program_code'] . ' — ' . $program['program_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Course</label><select class="form-select" name="course_code" required><option value="">Choose...</option><?php foreach ($courses as $course): ?><option value="<?= tp_h($course['course_code']) ?>"><?= tp_h($course['course_code'] . ' — ' . $course['course_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Curriculum version</label><select class="form-select" name="curriculum_version_id"><option value="">Optional</option><?php foreach ($curricula as $curriculum): ?><option value="<?= (int)$curriculum['id'] ?>"><?= tp_h($curriculum['program_code'] . ' — ' . $curriculum['version_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Syllabus version</label><input class="form-control" name="version_label" placeholder="e.g. 2026 Approved Draft" required></div>
                            <div class="col-md-4"><label class="form-label">Credits</label><input class="form-control" type="number" step="0.5" min="0" name="credits"></div>
                            <div class="col-md-8"><label class="form-label">Total recommended hours</label><input class="form-control" type="number" step="0.25" min="0.25" name="total_recommended_hours" required></div>
                            <div class="col-12"><label class="form-label">Purpose</label><textarea class="form-control" name="purpose" rows="2"></textarea></div>
                            <div class="col-12"><label class="form-label">Approved learning outcomes</label><div id="outcomes"><div class="input-group mb-2"><span class="input-group-text">LO1</span><input class="form-control" name="outcomes[]" required></div></div><button type="button" class="btn btn-sm btn-outline-secondary" id="addOutcome"><i class="fas fa-plus me-1"></i>Add outcome</button></div>
                            <div class="col-12"><div class="d-flex justify-content-between align-items-center"><label class="form-label mb-0">Topics</label><button type="button" class="btn btn-sm btn-outline-secondary" id="addTopic"><i class="fas fa-plus me-1"></i>Add topic</button></div><div id="topics" class="mt-2"></div></div>
                            <div class="col-md-6"><label class="form-label">Overall assessment criteria</label><textarea class="form-control" name="assessment_criteria" rows="2"></textarea></div>
                            <div class="col-md-6"><label class="form-label">Required / suggested resources</label><textarea class="form-control" name="resources" rows="2"></textarea></div>
                            <div class="col-12"><div class="alert alert-info small mb-2">This saves a draft only. Imported or AI-extracted content must follow the same human review and HOS approval step.</div><button class="btn btn-primary w-100" <?= !$schemaReady ? 'disabled' : '' ?>><i class="fas fa-floppy-disk me-1"></i>Save syllabus draft</button></div>
                        </form>
                    </div></article>
                </div>
                <div class="col-xl-7">
                    <article class="tp-card"><header class="tp-card-header"><h2 class="h5 mb-0"><i class="fas fa-book text-primary me-2"></i>Syllabus versions</h2><span class="badge bg-primary"><?= count($syllabi) ?></span></header><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Programme / course</th><th>Version</th><th>Coverage data</th><th>Status</th></tr></thead><tbody>
                        <?php if (!$syllabi): ?><tr><td colspan="4"><div class="tp-empty"><i class="fas fa-book-open"></i>No structured syllabus versions.</div></td></tr><?php endif; ?>
                        <?php foreach ($syllabi as $row): ?><tr><td><strong><?= tp_h($row['program_code'] . ' — ' . $row['course_code']) ?></strong><div class="small text-muted"><?= tp_h($row['program_name'] . ' / ' . $row['course_name']) ?></div></td><td><?= tp_h($row['version_label']) ?><div class="small text-muted">Created by <?= tp_h($row['creator_name'] ?: $row['created_by']) ?></div></td><td><?= (int)$row['topic_count'] ?> topics<br><span class="small text-muted"><?= number_format((float)$row['topic_hours'], 2) ?> / <?= number_format((float)$row['total_recommended_hours'], 2) ?> hours</span></td><td><span class="badge bg-<?= $row['status'] === 'approved' ? 'success' : ($row['status'] === 'draft' ? 'secondary' : 'warning') ?> tp-status"><?= tp_h(str_replace('_', ' ', $row['status'])) ?></span></td></tr><?php endforeach; ?>
                    </tbody></table></div></article>
                </div>
            </div>
        </div>
    </div>
</div>
<template id="topicTemplate"><div class="border rounded-3 p-3 mb-3 topic-row"><div class="d-flex justify-content-between"><strong>Topic <span class="topic-number"></span></strong><button type="button" class="btn btn-sm btn-link text-danger remove-topic"><i class="fas fa-trash"></i></button></div><div class="row g-2 mt-1"><div class="col-md-8"><input class="form-control" name="topic_title[]" placeholder="Topic title" required></div><div class="col-md-4"><input class="form-control" type="number" step="0.25" min="0.25" name="topic_hours[]" placeholder="Hours" required></div><div class="col-12"><textarea class="form-control" name="topic_subtopics[]" rows="2" placeholder="Subtopics"></textarea></div><div class="col-12"><textarea class="form-control" name="topic_outcomes[]" rows="2" placeholder="Learning outcomes for this topic" required></textarea></div><div class="col-md-6"><textarea class="form-control" name="topic_assessment[]" rows="2" placeholder="Assessment criteria"></textarea></div><div class="col-md-6"><textarea class="form-control" name="topic_resources[]" rows="2" placeholder="Resources"></textarea></div></div></div></template>
<script>
(() => {
 const topics = document.getElementById('topics'), tpl = document.getElementById('topicTemplate');
 const renumber = () => topics.querySelectorAll('.topic-number').forEach((el, i) => el.textContent = i + 1);
 const addTopic = () => { topics.appendChild(tpl.content.cloneNode(true)); renumber(); };
 document.getElementById('addTopic')?.addEventListener('click', addTopic);
 topics?.addEventListener('click', e => { const btn = e.target.closest('.remove-topic'); if (btn && topics.children.length > 1) { btn.closest('.topic-row').remove(); renumber(); } });
 document.getElementById('addOutcome')?.addEventListener('click', () => { const wrap = document.getElementById('outcomes'), n = wrap.children.length + 1; const div = document.createElement('div'); div.className='input-group mb-2'; div.innerHTML='<span class="input-group-text">LO'+n+'</span><input class="form-control" name="outcomes[]" required><button class="btn btn-outline-danger" type="button" aria-label="Remove"><i class="fas fa-times"></i></button>'; div.querySelector('button').onclick=()=>div.remove(); wrap.appendChild(div); });
 addTopic();
})();
</script>

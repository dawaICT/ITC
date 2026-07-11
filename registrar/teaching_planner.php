<?php
declare(strict_types=1);

$page_title = 'Teaching Plan Compliance';
require_once __DIR__ . '/includes/nav.php';
require_once dirname(__DIR__) . '/includes/teaching_planner/init.php';
$plans = tp_schema_ready($db) ? (new TeachingPlannerService($db))->plansForReview() : [];
$statusCounts = [];
foreach ($plans as $plan) $statusCounts[$plan['status']] = ($statusCounts[$plan['status']] ?? 0) + 1;
?>
<link rel="stylesheet" href="/wucportal/css/teaching-planner.css">
<div class="container-fluid px-3 px-lg-4 py-4 tp-page">
 <section class="tp-hero mb-4"><h1 class="h3 mb-1"><i class="fas fa-chart-line me-2"></i>Teaching Plan Compliance</h1><p class="mb-0 opacity-75">Read-only academic-office monitoring of submitted and approved structured plans.</p></section>
 <div class="row g-3 mb-4"><?php foreach (['submitted'=>'Submitted','resubmitted'=>'Resubmitted','changes_requested'=>'Changes requested','approved'=>'Approved','in_use'=>'In use'] as $key=>$label): ?><div class="col-6 col-lg"><div class="tp-stat"><span><?= tp_h($label) ?></span><strong><?= (int)($statusCounts[$key]??0) ?></strong></div></div><?php endforeach; ?></div>
 <article class="tp-card"><header class="tp-card-header"><h2 class="h5 mb-0"><i class="fas fa-list text-primary me-2"></i>Plan register</h2><span class="badge bg-primary"><?= count($plans) ?></span></header><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Document</th><th>Programme / course</th><th>Lecturer</th><th>Period</th><th>Coverage / hours</th><th>Versions</th><th>Status</th><th>Export</th></tr></thead><tbody><?php if (!$plans): ?><tr><td colspan="8"><div class="tp-empty"><i class="fas fa-inbox"></i>No submitted teaching plans.</div></td></tr><?php endif; ?><?php foreach ($plans as $plan): ?><tr><td><strong><?= tp_h($plan['document_number']) ?></strong><div class="small text-muted">Revision <?= (int)$plan['revision_number'] ?></div></td><td><?= tp_h($plan['program_name']) ?><div class="small text-muted"><?= tp_h($plan['course_code'].' — '.$plan['course_name']) ?></div></td><td><?= tp_h($plan['lecturer_name']) ?></td><td><?= tp_h($plan['academic_year'].' / '.$plan['academic_period']) ?></td><td><?= number_format((float)$plan['coverage_percent'],1) ?>%<div class="small text-muted"><?= number_format((float)$plan['planned_hours'],1) ?> / <?= number_format((float)$plan['available_hours'],1) ?> h</div></td><td>Template v<?= (int)$plan['template_version'] ?><div class="small text-muted">Syllabus <?= tp_h($plan['syllabus_version']) ?></div></td><td><span class="badge bg-<?= in_array($plan['status'],['approved','in_use'],true)?'success':'secondary' ?> tp-status"><?= tp_h(str_replace('_',' ',$plan['status'])) ?></span></td><td><a class="btn btn-sm btn-outline-secondary" href="/wucportal/teaching_planner/download.php?id=<?= (int)$plan['id'] ?>&format=docx"><i class="fas fa-file-word"></i></a></td></tr><?php endforeach; ?></tbody></table></div></article>
</div>

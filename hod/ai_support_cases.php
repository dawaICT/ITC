<?php
declare(strict_types=1);
$page_title='AI Support Escalations';
require_once __DIR__.'/includes/nav.php';
require_once dirname(__DIR__).'/services/support/SupportCaseService.php';

$staffId=(string)$_SESSION['staff_id'];
$sectionId=(string)($_SESSION['hos_section_id']??'');
$service=new SupportCaseService($db);
$cases=$service->staffCases($staffId,'head_of_section');
$csrf=(string)($_SESSION['csrf_token']??'');
if($csrf===''){$csrf=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$csrf;}

$summary=['requests'=>0,'tokens'=>0,'cost'=>0.0,'open'=>0,'responded'=>0];
foreach($cases as $case){if(!in_array($case['status'],['resolved','closed'],true))$summary['open']++;if($case['status']==='lecturer_responded')$summary['responded']++;}
if($sectionId!==''){
    $stmt=$db->prepare("SELECT COUNT(*) requests,COALESCE(SUM(u.prompt_tokens+u.completion_tokens),0) tokens,COALESCE(SUM(u.estimated_cost),0) cost
                        FROM ai_usage_logs u INNER JOIN departments d ON CAST(d.id AS CHAR)=u.department_id
                        WHERE d.section_id=? AND u.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
    $stmt->bind_param('s',$sectionId);$stmt->execute();$usage=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();
    $summary['requests']=(int)($usage['requests']??0);$summary['tokens']=(int)($usage['tokens']??0);$summary['cost']=(float)($usage['cost']??0);
}
?>
<link rel="stylesheet" href="/wucportal/css/ai-learning.css?v=20260718">
<main class="main-content ai-page">
<section class="ai-hero"><h1><i class="fas fa-people-roof me-2"></i>AI Support Escalations</h1><p>Unassigned and escalated learner support cases scoped to <?=htmlspecialchars((string)($_SESSION['hos_section_name']??'your section'))?>.</p></section>
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="ai-card h-100 mb-0"><div class="ai-card-body"><div class="text-muted small">Open cases</div><div class="fs-2 fw-bold"><?=$summary['open']?></div></div></div></div>
    <div class="col-md-3"><div class="ai-card h-100 mb-0"><div class="ai-card-body"><div class="text-muted small">Responses recorded</div><div class="fs-2 fw-bold"><?=$summary['responded']?></div></div></div></div>
    <div class="col-md-3"><div class="ai-card h-100 mb-0"><div class="ai-card-body"><div class="text-muted small">30-day AI requests</div><div class="fs-2 fw-bold"><?=$summary['requests']?></div><small><?=number_format($summary['tokens'])?> tokens</small></div></div></div>
    <div class="col-md-3"><div class="ai-card h-100 mb-0"><div class="ai-card-body"><div class="text-muted small">Estimated AI cost</div><div class="fs-2 fw-bold">$<?=number_format($summary['cost'],4)?></div></div></div></div>
</div>
<article class="ai-card"><div class="ai-card-header"><h2>Section support queue</h2><span class="badge bg-primary"><?=count($cases)?></span></div><div class="ai-card-body">
<?php if(!$cases):?><div class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2"></i><p>No escalated support cases.</p></div>
<?php else:foreach($cases as $case):?>
<div class="ai-case"><div class="d-flex justify-content-between gap-2"><div><strong>#<?=(int)$case['id']?> · <?=htmlspecialchars((string)$case['course_id'])?></strong><div class="ai-case-meta">Student <?=htmlspecialchars((string)$case['student_id'])?> · Lecturer <?=htmlspecialchars((string)($case['lecturer_id']?:'Unassigned'))?> · Priority <?=htmlspecialchars((string)$case['priority'])?></div></div><span class="ai-status <?=htmlspecialchars((string)$case['status'])?>"><?=htmlspecialchars(str_replace('_',' ',(string)$case['status']))?></span></div><p class="mt-3"><strong>Question:</strong><br><?=nl2br(htmlspecialchars((string)$case['original_question']))?></p><p><strong>Student attempt:</strong><br><?=nl2br(htmlspecialchars((string)($case['student_attempt']?:'No attempt supplied.')))?></p><?php if(!in_array($case['status'],['resolved','closed'],true)):?><textarea class="form-control" rows="3" data-case-response="<?=(int)$case['id']?>" placeholder="Provide guidance or routing information…"></textarea><button class="btn btn-ai btn-sm mt-2" data-respond-case="<?=(int)$case['id']?>">Send response</button><?php endif;?></div>
<?php endforeach;endif;?></div></article></main>
<script>window.WUC_AI={csrf:<?=json_encode($csrf)?>,endpoint:'/wucportal/api/hod/ai/respond_support_case.php'};</script><script src="/wucportal/hod/js/ai-support-cases.js?v=20260718"></script>

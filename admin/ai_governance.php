<?php
declare(strict_types=1);
$page_title='AI Governance';
require_once __DIR__.'/includes/nav.php';
if(!function_exists('isSystemsAdmin')||!isSystemsAdmin()){http_response_code(403);exit('Access denied.');}
require_once dirname(__DIR__).'/ai/config.php';
require_once dirname(__DIR__).'/ai/ollama.php';

$usage=$db->query("SELECT COUNT(*) requests,COALESCE(SUM(prompt_tokens),0) prompt_tokens,COALESCE(SUM(completion_tokens),0) completion_tokens,COALESCE(SUM(estimated_cost),0) cost,COALESCE(AVG(latency_ms),0) avg_latency,SUM(cache_hit) cache_hits FROM ai_usage_logs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetch_assoc();
$courseSettings=$db->query("SELECT course_id,is_enabled,default_ai_mode,max_prompt_tokens,max_response_tokens,per_user_daily_tokens,department_monthly_tokens,updated_at FROM ai_course_settings ORDER BY course_id")->fetch_all(MYSQLI_ASSOC);
$assessmentCount=(int)($db->query('SELECT COUNT(*) total FROM ai_assessment_settings')->fetch_assoc()['total']??0);
$auditRows=$db->query("SELECT actor_id,actor_role,action,entity_type,entity_id,course_id,created_at FROM ai_audit_logs ORDER BY id DESC LIMIT 30")->fetch_all(MYSQLI_ASSOC);
$deniedCount=(int)($db->query("SELECT COUNT(*) total FROM ai_audit_logs WHERE action='ai.api_denied' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetch_assoc()['total']??0);
$dbHealthy=true;
$ollamaHealthy=ollama_available();
$standardModel=getenv('WUC_AI_STANDARD_MODEL')?:AI_CHAT_MODEL;
$lowCostModel=getenv('WUC_AI_LOW_COST_MODEL')?:AI_CHAT_MODEL;
?>
<link rel="stylesheet" href="/wucportal/css/ai-learning.css?v=20260718">
<main class="main-content ai-page">
<section class="ai-hero"><h1><i class="fas fa-shield-halved me-2"></i>AI Governance</h1><p>Technical health, limits, aggregate usage and policy audit. Private academic conversation content is intentionally excluded.</p></section>
<div class="row g-3 mb-4">
<div class="col-md-3"><div class="ai-card h-100 mb-0"><div class="ai-card-body"><div class="text-muted small">30-day requests</div><div class="fs-2 fw-bold"><?=(int)($usage['requests']??0)?></div></div></div></div>
<div class="col-md-3"><div class="ai-card h-100 mb-0"><div class="ai-card-body"><div class="text-muted small">Tokens</div><div class="fs-2 fw-bold"><?=number_format((int)($usage['prompt_tokens']??0)+(int)($usage['completion_tokens']??0))?></div><small><?=number_format((int)($usage['cache_hits']??0))?> cache hits</small></div></div></div>
<div class="col-md-3"><div class="ai-card h-100 mb-0"><div class="ai-card-body"><div class="text-muted small">Estimated cost</div><div class="fs-2 fw-bold">$<?=number_format((float)($usage['cost']??0),4)?></div><small><?=number_format((float)($usage['avg_latency']??0))?> ms average</small></div></div></div>
<div class="col-md-3"><div class="ai-card h-100 mb-0"><div class="ai-card-body"><div class="text-muted small">Denied requests</div><div class="fs-2 fw-bold"><?=$deniedCount?></div><small>Last 30 days</small></div></div></div>
</div>
<div class="ai-grid"><section><article class="ai-card"><div class="ai-card-header"><h2><i class="fas fa-sliders me-2 text-primary"></i>Course controls</h2><span class="badge bg-primary"><?=count($courseSettings)?> courses</span></div><div class="ai-card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Course</th><th>Mode</th><th>Limits</th><th>Status</th></tr></thead><tbody><?php if(!$courseSettings):?><tr><td colspan="4" class="text-center text-muted">No explicit course overrides; secure service defaults apply.</td></tr><?php else:foreach($courseSettings as $setting):?><tr><td><?=htmlspecialchars((string)$setting['course_id'])?></td><td><?=htmlspecialchars(str_replace('_',' ',(string)$setting['default_ai_mode']))?></td><td><?=number_format((int)$setting['per_user_daily_tokens'])?> user/day<br><?=number_format((int)$setting['department_monthly_tokens'])?> department/month</td><td><span class="badge <?=$setting['is_enabled']?'bg-success':'bg-secondary'?>"><?=$setting['is_enabled']?'Enabled':'Disabled'?></span></td></tr><?php endforeach;endif;?></tbody></table></div><p class="small text-muted mb-0"><?=$assessmentCount?> assessment-specific mode record(s).</p></div></article></section>
<aside><article class="ai-card"><div class="ai-card-header"><h3><i class="fas fa-heart-pulse me-2 text-primary"></i>System health</h3></div><div class="ai-card-body"><p><span class="badge <?=$dbHealthy?'bg-success':'bg-danger'?>">Database <?=$dbHealthy?'online':'offline'?></span></p><p><span class="badge <?=$ollamaHealthy?'bg-success':'bg-warning text-dark'?>">Local AI <?=$ollamaHealthy?'online':'unavailable'?></span></p><dl class="small mb-0"><dt>Standard model</dt><dd><?=htmlspecialchars($standardModel)?></dd><dt>Low-cost model</dt><dd><?=htmlspecialchars($lowCostModel)?></dd><dt>Prompt transport</dt><dd>Relevant chunks only</dd><dt>External API secret</dt><dd>Not exposed to the portal</dd></dl></div></article></aside></div>
<article class="ai-card"><div class="ai-card-header"><h2><i class="fas fa-list-check me-2 text-primary"></i>Recent AI audit events</h2></div><div class="ai-card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Time</th><th>Actor role</th><th>Action</th><th>Record</th><th>Course</th></tr></thead><tbody><?php if(!$auditRows):?><tr><td colspan="5" class="text-center text-muted">No AI audit events recorded.</td></tr><?php else:foreach($auditRows as $row):?><tr><td><?=htmlspecialchars((string)$row['created_at'])?></td><td><?=htmlspecialchars((string)$row['actor_role'])?></td><td><?=htmlspecialchars((string)$row['action'])?></td><td><?=htmlspecialchars(trim((string)$row['entity_type'].' '.(string)$row['entity_id']))?></td><td><?=htmlspecialchars((string)($row['course_id']??''))?></td></tr><?php endforeach;endif;?></tbody></table></div></div></article>
</main>

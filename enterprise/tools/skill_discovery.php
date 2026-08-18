<?php
declare(strict_types=1);

$page_title = 'Skill Discovery';
$activeNav = 'skill_discovery';
require_once dirname(__DIR__) . '/includes/guard.php';

$studentId = trim((string)(ep_current_student_id() ?? $_SESSION['Sid'] ?? ''));
if ($studentId === '') {
    require_once dirname(__DIR__) . '/includes/layout.php';
    ?>
    <article class="card">
        <div class="card-body">
            <p class="mb-0 ep-muted">Skill Discovery is available to participants linked to an ITC student record. Staff can use management tools or ask the participant to open this page from their enterprise dashboard.</p>
        </div>
    </article>
    <?php
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$skillDiscoveryFormAction = '/wucportal/enterprise/tools/skill_discovery.php';
$skillDiscoveryEyebrow = 'Skills and Enterprise Portal';
require_once dirname(__DIR__, 2) . '/students/includes/skill_discovery_run.php';

$epAiCap = ep_ai_capability($db);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<link rel="stylesheet" href="/wucportal/enterprise/css/skill-discovery-embed.css?v=20260721">
<article class="card mb-3">
    <div class="card-body small">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span><strong>Lexical matching:</strong> <?= $epAiCap['skill_lexical_ready'] ? 'ready' : 'not ready' ?></span>
            <span><strong>Semantic (Ollama):</strong> <?= $epAiCap['skill_semantic_ready'] ? 'ready' : 'offline — keyword mode used' ?></span>
            <a class="ms-auto" href="/wucportal/enterprise/tools/ai_assist.php">AI Writing Assist</a>
        </div>
    </div>
</article>
<div class="skill-page pb-4">
    <?php require dirname(__DIR__, 2) . '/students/includes/skill_discovery_body.php'; ?>
</div>
<?php
require dirname(__DIR__, 2) . '/students/includes/skill_discovery_scripts.php';
require_once dirname(__DIR__) . '/includes/footer.php';

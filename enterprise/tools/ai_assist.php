<?php
declare(strict_types=1);

$page_title = 'AI Writing Assist';
$activeNav = 'ai';
require_once dirname(__DIR__) . '/includes/guard.php';

$errors = [];
$draft = '';
$message = '';
$messageTone = 'success';
$usedAi = false;
$kind = (string)($_POST['kind'] ?? 'bio');
$notes = trim((string)($_POST['notes'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$opportunityType = trim((string)($_POST['opportunity_type'] ?? ''));
$capability = ep_ai_capability($db);
$aiOn = $capability['enterprise_flag'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        $mode = (string)($_POST['mode'] ?? 'ai');
        $ctx = [
            'title' => $title,
            'notes' => $notes,
            'opportunity_type' => $opportunityType,
            'goals' => json_decode((string)($epMembership['participation_goals_json'] ?? '[]'), true) ?: [],
        ];

        if ($mode === 'local') {
            $res = ep_ai_local_draft($kind, $ctx);
        } else {
            $res = ep_ai_assist($db, $kind, $ctx);
        }

        if (!empty($res['ok'])) {
            $draft = (string)($res['text'] ?? '');
            $message = (string)($res['message'] ?? '');
            $usedAi = !empty($res['used_ai']);
            $messageTone = $usedAi ? 'success' : 'warning';
        } else {
            $errors[] = (string)($res['message'] ?? 'Could not generate a draft.');
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<article class="card mb-3">
    <div class="card-hdr d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="h6 mb-0"><i class="fas fa-heartbeat text-primary me-1"></i> AI capability</h2>
        <?php if ($capability['can_live_generate']): ?>
            <span class="badge text-bg-success">Live drafting ready</span>
        <?php elseif ($aiOn): ?>
            <span class="badge text-bg-warning">Flag on · provider weak</span>
        <?php else: ?>
            <span class="badge text-bg-secondary">Enterprise AI off</span>
        <?php endif; ?>
    </div>
    <div class="card-body small">
        <div class="row g-2">
            <div class="col-md-4"><strong>Portal flag:</strong> <?= $aiOn ? 'enabled' : 'disabled' ?></div>
            <div class="col-md-4"><strong>Provider:</strong> <?= ep_h($capability['provider'] !== '' ? $capability['provider'] : 'none') ?></div>
            <div class="col-md-4"><strong>Model:</strong> <?= ep_h($capability['model'] !== '' ? $capability['model'] : '—') ?></div>
            <div class="col-12 ep-muted"><?= ep_h($capability['message']) ?></div>
            <div class="col-md-6">
                <strong>Skill Discovery lexical:</strong>
                <?= $capability['skill_lexical_ready'] ? 'ready (taxonomy loaded)' : 'not ready' ?>
            </div>
            <div class="col-md-6">
                <strong>Skill Discovery semantic:</strong>
                <?= $capability['skill_semantic_ready'] ? 'ready (Ollama embed model)' : 'needs Ollama + nomic-embed-text' ?>
            </div>
        </div>
        <?php if (!$aiOn): ?>
            <div class="alert alert-secondary mb-0 mt-3">
                Live AI drafting is off. Management can enable <code>ai_enabled</code> under
                <a href="/wucportal/enterprise/management/settings.php">Portal Settings</a>,
                or set <code>ENTERPRISE_AI_ENABLED=true</code>. You can still generate a <strong>local template draft</strong> below.
            </div>
        <?php elseif (!$capability['can_live_generate']): ?>
            <div class="alert alert-warning mb-0 mt-3">
                Enterprise AI is enabled, but no usable provider is ready.
                Install/start <strong>Ollama</strong> then run
                <code>powershell -ExecutionPolicy Bypass -File ai\setup_ollama.ps1</code>,
                or add a free Groq key to <code>ai/cloud_key.txt</code>.
                Local template drafts still work.
            </div>
        <?php endif; ?>
    </div>
</article>

<article class="card mb-3">
    <div class="card-hdr">
        <h2 class="h6 mb-0"><i class="fas fa-wand-magic-sparkles text-primary me-1"></i> Drafting assistant</h2>
    </div>
    <div class="card-body">
    <p class="small ep-muted mb-2">
        AI may suggest wording only. It cannot approve memberships, verify skills, publish listings, or guarantee employment, sales, profit or funding.
        Generated text is never saved or published automatically — copy it into an editable form only after you accept it.
    </p>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= ep_h($messageTone) ?>"><?= ep_h($message) ?></div>
    <?php endif; ?>
    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <div class="col-md-4">
            <label class="form-label" for="ep-kind">Assist type</label>
            <select id="ep-kind" name="kind" class="form-select">
                <?php foreach (['bio'=>'Professional bio','product'=>'Product description','service'=>'Service description','innovation'=>'Innovation summary','readiness'=>'Readiness tips'] as $k=>$l): ?>
                    <option value="<?= ep_h($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= ep_h($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="ep-title">Working title</label>
            <input id="ep-title" name="title" class="form-control" maxlength="180" value="<?= ep_h($title) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="ep-type">Opportunity type (optional)</label>
            <select id="ep-type" name="opportunity_type" class="form-select">
                <option value="">—</option>
                <?php foreach (ep_opportunity_types() as $k => $l): ?>
                    <option value="<?= ep_h($k) ?>" <?= $opportunityType === $k ? 'selected' : '' ?>><?= ep_h($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label" for="ep-notes">Notes (no student numbers or NRC)</label>
            <textarea id="ep-notes" name="notes" class="form-control" rows="4" maxlength="1200"><?= ep_h($notes) ?></textarea>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit" name="mode" value="ai" <?= $capability['can_live_generate'] ? '' : 'disabled' ?>>
                <i class="fas fa-robot me-1"></i>Generate with AI
            </button>
            <button class="btn btn-outline-secondary" type="submit" name="mode" value="local">
                <i class="fas fa-file-lines me-1"></i>Local template draft
            </button>
            <a class="btn btn-outline-primary" href="/wucportal/enterprise/tools/skill_discovery.php">
                <i class="fas fa-wand-magic-sparkles me-1"></i>Skill Discovery
            </a>
        </div>
    </form>
    </div>
</article>
<?php if ($draft !== ''): ?>
<article class="card mb-3">
    <div class="card-hdr d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Editable review area</h2>
        <?php if ($usedAi): ?>
            <span class="badge text-bg-success">AI draft</span>
        <?php else: ?>
            <span class="badge text-bg-secondary">Template / fallback</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
    <p class="small ep-muted">Accept only after editing. Copy into your profile or opportunity form manually.</p>
    <textarea class="form-control" rows="10" id="epAiDraft"><?= ep_h($draft) ?></textarea>
    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="navigator.clipboard.writeText(document.getElementById('epAiDraft').value)">Copy text</button>
    </div>
</article>
<?php endif; ?>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>

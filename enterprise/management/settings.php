<?php
declare(strict_types=1);

$page_title = 'Portal Settings';
$activeNav = 'mgmt_set';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.settings.manage')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/index.php');
    exit;
}

$base = '/wucportal/enterprise/management';
$keys = [
    'portal_enabled' => 'Portal enabled',
    'public_directory' => 'Public directory enabled',
    'membership_approval_mode' => 'Membership approval mode',
    'allow_current_students' => 'Allow current students',
    'allow_graduates' => 'Allow graduates',
    'ai_enabled' => 'AI features enabled',
    'lead_attention_days' => 'Lead attention (days)',
    'lead_overdue_days' => 'Lead overdue (days)',
    'lead_escalated_days' => 'Lead escalated (days)',
    'consent_version' => 'Consent version',
    'currency_label' => 'Currency label',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . $base . '/settings.php');
        exit;
    }
    foreach ($keys as $key => $_label) {
        $isBool = in_array($key, ['portal_enabled', 'public_directory', 'allow_current_students', 'allow_graduates', 'ai_enabled'], true);
        if ($isBool) {
            // Unchecked switches omit the key from POST — still persist false.
            $val = !empty($_POST[$key]) ? 'true' : 'false';
            ep_set_setting($db, $key, $val);
            continue;
        }
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $val = trim((string)$_POST[$key]);
        ep_set_setting($db, $key, $val);
    }
    ep_audit($db, 'enterprise_portal.settings_updated', []);
    $_SESSION['flash_success'] = 'Settings saved.';
    header('Location: ' . $base . '/settings.php');
    exit;
}

$values = [];
foreach ($keys as $key => $label) {
    $values[$key] = ep_setting($db, $key, '');
}

require_once dirname(__DIR__) . '/includes/layout.php';
$aiCap = function_exists('ep_ai_capability') ? ep_ai_capability($db) : null;
?>
<div class="ep-card">
    <h2 class="h5 mb-3">Enterprise portal settings</h2>
    <?php if (is_array($aiCap)): ?>
        <div class="alert alert-light border small mb-3">
            <strong>AI capability:</strong>
            flag <?= $aiCap['enterprise_flag'] ? 'on' : 'off' ?> ·
            provider <?= ep_h($aiCap['provider']) ?> ·
            model <?= ep_h($aiCap['model'] !== '' ? $aiCap['model'] : '—') ?>.
            <?= ep_h($aiCap['message']) ?>
            Live drafting <?= $aiCap['can_live_generate'] ? 'is ready' : 'is not ready' ?>.
            For reliable cloud AI, add a free Groq key to <code>ai/cloud_key.txt</code>; for Skill Discovery semantic mode, run Ollama with <code>nomic-embed-text</code>.
        </div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <?php foreach ($keys as $key => $label): ?>
            <?php if (in_array($key, ['portal_enabled', 'public_directory', 'allow_current_students', 'allow_graduates', 'ai_enabled'], true)): ?>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="<?= ep_h($key) ?>" name="<?= ep_h($key) ?>" value="1"
                        <?= ep_setting_bool($db, $key, $key !== 'ai_enabled') ? ' checked' : '' ?>>
                    <label class="form-check-label" for="<?= ep_h($key) ?>"><?= ep_h($label) ?></label>
                </div>
            <?php else: ?>
                <div class="mb-3">
                    <label class="form-label" for="<?= ep_h($key) ?>"><?= ep_h($label) ?></label>
                    <input class="form-control" name="<?= ep_h($key) ?>" id="<?= ep_h($key) ?>" value="<?= ep_h((string)$values[$key]) ?>" maxlength="200">
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary">Save settings</button>
    </form>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>

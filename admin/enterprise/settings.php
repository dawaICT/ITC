<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Settings.
 */

$page_title = 'Settings — Skills-to-Trade';
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.settings.manage');

$base = '/wucportal/admin/enterprise';

if (empty($_SESSION['eh_settings_form_token']) || !is_string($_SESSION['eh_settings_form_token'])) {
    $_SESSION['eh_settings_form_token'] = bin2hex(random_bytes(16));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        wuc_set_flash('error', $e->getMessage());
        header('Location: ' . $base . '/settings.php');
        exit;
    }

    $postedFormToken = (string)($_POST['form_token'] ?? '');
    $sessionFormToken = (string)($_SESSION['eh_settings_form_token'] ?? '');
    if ($sessionFormToken === '' || !hash_equals($sessionFormToken, $postedFormToken)) {
        wuc_set_flash('error', 'Form expired or already submitted.');
        header('Location: ' . $base . '/settings.php');
        exit;
    }
    unset($_SESSION['eh_settings_form_token']);

    $action = (string)($_POST['action'] ?? 'save_settings');

    if ($action === 'reset_demo') {
        if (!eh_is_systems_admin()) {
            wuc_set_flash('error', 'Only systems administrators can reset demonstration data.');
        } else {
            $confirm = trim((string)($_POST['confirm_reset'] ?? ''));
            if ($confirm !== 'RESET DEMO') {
                wuc_set_flash('error', 'Type RESET DEMO to confirm demonstration data reset.');
            } else {
                $reset = eh_reset_demo_data($db);
                wuc_set_flash(!empty($reset['ok']) ? 'success' : 'error', (string)($reset['message'] ?? 'Reset failed.'));
            }
        }
        $_SESSION['eh_settings_form_token'] = bin2hex(random_bytes(16));
        header('Location: ' . $base . '/settings.php');
        exit;
    }

    $aiEnabled = !empty($_POST['ai_enabled']) ? 'true' : 'false';
    $exhibitionMode = !empty($_POST['exhibition_mode']) ? 'true' : 'false';
    $maxImages = max(1, min(20, (int)($_POST['max_images_per_item'] ?? 8)));
    $rateMinutes = max(1, min(1440, (int)($_POST['interest_rate_limit_minutes'] ?? 5)));
    $rateCount = max(1, min(100, (int)($_POST['interest_rate_limit_count'] ?? 3)));

    eh_set_setting($db, 'ai_enabled', $aiEnabled);
    eh_set_setting($db, 'exhibition_mode', $exhibitionMode);
    eh_set_setting($db, 'max_images_per_item', (string)$maxImages);
    eh_set_setting($db, 'interest_rate_limit_minutes', (string)$rateMinutes);
    eh_set_setting($db, 'interest_rate_limit_count', (string)$rateCount);

    eh_audit($db, 'enterprise_hub.settings_updated', [
        'ai_enabled' => $aiEnabled,
        'exhibition_mode' => $exhibitionMode,
        'max_images_per_item' => $maxImages,
        'interest_rate_limit_minutes' => $rateMinutes,
        'interest_rate_limit_count' => $rateCount,
    ]);

    $_SESSION['eh_settings_form_token'] = bin2hex(random_bytes(16));
    wuc_set_flash('success', 'Settings saved.');
    header('Location: ' . $base . '/settings.php');
    exit;
}

// Fresh reads (bypass static cache by reading DB directly for form defaults)
$settings = [
    'ai_enabled' => eh_setting($db, 'ai_enabled', 'true'),
    'exhibition_mode' => eh_setting($db, 'exhibition_mode', 'false'),
    'max_images_per_item' => eh_setting($db, 'max_images_per_item', '8'),
    'interest_rate_limit_minutes' => eh_setting($db, 'interest_rate_limit_minutes', '5'),
    'interest_rate_limit_count' => eh_setting($db, 'interest_rate_limit_count', '3'),
];

$formToken = (string)$_SESSION['eh_settings_form_token'];
$csrf = wuc_csrf_token();
$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;

require_once __DIR__ . '/../includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-cog me-2 text-primary"></i>Hub Settings</h1>
                <p class="text-muted mb-0">Configure AI, exhibition mode, media limits and interest rate limiting.</p>
            </div>
            <div class="col-auto">
                <a href="<?php echo eh_h($base); ?>/index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Hub home</a>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <?php $alertType = ($flash['type'] ?? '') === 'error' ? 'danger' : (string)$flash['type']; ?>
        <div class="alert alert-<?php echo eh_h($alertType); ?> alert-dismissible fade show" role="alert">
            <?php echo eh_h((string)($flash['message'] ?? '')); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>Configuration</h5></div>
                <div class="card-body">
                    <form method="post" action="<?php echo eh_h($base); ?>/settings.php">
                        <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                        <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="ai_enabled" name="ai_enabled" value="1"
                                <?php echo in_array(strtolower((string)$settings['ai_enabled']), ['1', 'true', 'yes', 'on'], true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="ai_enabled">AI assistant enabled</label>
                            <div class="form-text">When off, AI draft helpers fall back gracefully.</div>
                        </div>

                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="exhibition_mode" name="exhibition_mode" value="1"
                                <?php echo in_array(strtolower((string)$settings['exhibition_mode']), ['1', 'true', 'yes', 'on'], true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="exhibition_mode">Exhibition mode</label>
                            <div class="form-text">Aligns hub behaviour with exhibition / show demonstrations.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="max_images_per_item">Max images per item</label>
                            <input class="form-control" type="number" min="1" max="20" name="max_images_per_item" id="max_images_per_item"
                                   value="<?php echo eh_h((string)$settings['max_images_per_item']); ?>" required>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label" for="interest_rate_limit_minutes">Interest rate-limit window (minutes)</label>
                                <input class="form-control" type="number" min="1" max="1440" name="interest_rate_limit_minutes" id="interest_rate_limit_minutes"
                                       value="<?php echo eh_h((string)$settings['interest_rate_limit_minutes']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="interest_rate_limit_count">Max interests per window</label>
                                <input class="form-control" type="number" min="1" max="100" name="interest_rate_limit_count" id="interest_rate_limit_count"
                                       value="<?php echo eh_h((string)$settings['interest_rate_limit_count']); ?>" required>
                            </div>
                        </div>

                        <input type="hidden" name="action" value="save_settings">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save settings
                        </button>
                    </form>
                </div>
            </section>

            <?php if (eh_is_systems_admin()): ?>
            <section class="data-table-card mt-4 border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-database me-2"></i>Exhibition demo reset</h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">
                        Removes only records marked <code>is_demo = 1</code> and reloads
                        <code>database/enterprise_hub_seed.sql</code>. Live student and staff records are not deleted.
                    </p>
                    <form method="post" action="<?php echo eh_h($base); ?>/settings.php"
                          onsubmit="return confirm('Reset demonstration data and reload seed records?');">
                        <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                        <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">
                        <input type="hidden" name="action" value="reset_demo">
                        <div class="mb-3">
                            <label class="form-label" for="confirm_reset">Type <strong>RESET DEMO</strong> to confirm</label>
                            <input class="form-control" type="text" name="confirm_reset" id="confirm_reset" autocomplete="off" required>
                        </div>
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="fas fa-rotate-left me-2"></i>Reset demo data
                        </button>
                    </form>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

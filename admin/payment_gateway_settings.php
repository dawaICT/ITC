<?php
$page_title = "Payment Gateway Settings";
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/payment_helpers.php';
require_once dirname(__DIR__) . '/includes/DpoGateway.php';

$setSetting = function(mysqli $db, string $key, string $value): void {
    if ($st = @$db->prepare("INSERT INTO portal_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")) {
        $st->bind_param('ss', $key, $value);
        $st->execute();
        $st->close();
    }
};

$saved = false;
$testResult = null;
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('wuc_validate_csrf') || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your session token expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'test') {
        $config = payment_get_dpo_config($db);
        $missing = payment_dpo_missing_fields($config);
        if (!empty($missing)) {
            $testResult = ['success' => false, 'message' => 'Configuration incomplete: missing ' . implode(', ', $missing) . '.'];
        } else {
            $gateway = new DpoGateway($config);
            $response = $gateway->createToken([
                'amount' => 1.00,
                'currency' => $config['currency'],
                'company_ref' => payment_generate_reference('TEST'),
                'description' => 'Gateway connectivity test (not a payment)',
                'redirect_url' => $config['redirect_url'],
                'back_url' => $config['back_url'],
                'service_date' => date('Y/m/d H:i'),
            ]);
            $testResult = [
                'success' => (bool)$response['success'],
                'message' => (string)($response['message'] ?? ''),
            ];
        }
    } else {
        $token = trim((string)($_POST['dpo_company_token'] ?? ''));
        // Blank token field means "keep the stored value" so the secret is never echoed back.
        if ($token !== '') {
            $setSetting($db, 'dpo_company_token', $token);
        }
        $setSetting($db, 'dpo_enabled', isset($_POST['dpo_enabled']) ? '1' : '0');
        $setSetting($db, 'dpo_service_type', trim((string)($_POST['dpo_service_type'] ?? '')));
        $setSetting($db, 'dpo_api_url', trim((string)($_POST['dpo_api_url'] ?? '')));
        $setSetting($db, 'dpo_payment_url', trim((string)($_POST['dpo_payment_url'] ?? '')));
        $setSetting($db, 'dpo_currency', strtoupper(trim((string)($_POST['dpo_currency'] ?? 'ZMW'))));
        $setSetting($db, 'dpo_ptl_hours', (string)max(1, (int)($_POST['dpo_ptl_hours'] ?? 24)));
        $setSetting($db, 'dpo_debug_mode', isset($_POST['dpo_debug_mode']) ? '1' : '0');
        $setSetting($db, 'dpo_default_payment', strtoupper(trim((string)($_POST['dpo_default_payment'] ?? ''))));
        $setSetting($db, 'dpo_default_payment_country', trim((string)($_POST['dpo_default_payment_country'] ?? '')));
        $setSetting($db, 'dpo_default_payment_mno', trim((string)($_POST['dpo_default_payment_mno'] ?? '')));
        $setSetting($db, 'reg_payment_gate_enabled', isset($_POST['reg_payment_gate_enabled']) ? '1' : '0');
        $setSetting($db, 'reg_payment_threshold_pct', (string)max(0, min(100, (float)($_POST['reg_payment_threshold_pct'] ?? 50))));
        $setSetting($db, 'reg_pending_payment_expiry_days', (string)max(1, (int)($_POST['reg_pending_payment_expiry_days'] ?? 7)));
        $saved = true;
    }
}

$config = payment_get_dpo_config($db);
$regGate = payment_registration_gate_settings($db);
$tableReady = payment_ensure_gateway_transactions_table($db);
$missingFields = payment_dpo_missing_fields($config);
$gatewayReady = payment_dpo_is_ready($config);
$csrfToken = function_exists('wuc_csrf_token') ? wuc_csrf_token() : '';

require "includes/header.php";
?>

<div class="container-fluid px-4 pt-4">
    <div class="row align-items-center mb-4 pb-3 border-bottom">
        <div class="col">
            <h2 class="fw-bold" style="color:#6f42c1;"><i class="fas fa-credit-card me-2"></i>Payment Gateway Settings</h2>
            <p class="text-muted mb-0">DPO Pay hosted checkout for fees and course registration payments.</p>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($testResult !== null): ?>
        <div class="alert alert-<?php echo $testResult['success'] ? 'success' : 'warning'; ?>">
            <i class="fas fa-<?php echo $testResult['success'] ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
            <strong>Connection test:</strong> <?php echo htmlspecialchars($testResult['message'] ?: ($testResult['success'] ? 'Token created successfully.' : 'Failed.')); ?>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-10">

            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="fas fa-heartbeat me-2 text-primary"></i>Status</h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <span class="badge <?php echo $tableReady ? 'bg-success' : 'bg-danger'; ?> p-2 w-100">
                                <i class="fas fa-database me-1"></i>
                                Transactions table: <?php echo $tableReady ? 'ready' : 'missing — run migration'; ?>
                            </span>
                        </div>
                        <div class="col-md-4">
                            <span class="badge <?php echo $gatewayReady ? 'bg-success' : 'bg-secondary'; ?> p-2 w-100">
                                <i class="fas fa-plug me-1"></i>
                                Gateway: <?php echo $gatewayReady ? 'enabled & configured' : 'not active'; ?>
                            </span>
                        </div>
                        <div class="col-md-4">
                            <span class="badge <?php echo empty($missingFields) ? 'bg-success' : 'bg-warning text-dark'; ?> p-2 w-100">
                                <i class="fas fa-list-check me-1"></i>
                                <?php echo empty($missingFields) ? 'All required fields set' : 'Missing: ' . htmlspecialchars(implode(', ', $missingFields)); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="fw-bold mb-0"><i class="fas fa-bolt me-2 text-primary"></i>DPO Pay Gateway</h5>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="dpoEnabled" name="dpo_enabled" <?php echo !empty($config['enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="dpoEnabled">Enabled</label>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Company Token</label>
                                <input type="password" class="form-control" name="dpo_company_token" value=""
                                       placeholder="<?php echo $config['company_token'] !== '' ? 'Saved — leave blank to keep' : 'Paste your DPO company token'; ?>" autocomplete="new-password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Service Type</label>
                                <input type="text" class="form-control" name="dpo_service_type" value="<?php echo htmlspecialchars($config['service_type']); ?>" placeholder="e.g. 3854">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">API URL</label>
                                <input type="url" class="form-control" name="dpo_api_url" value="<?php echo htmlspecialchars($config['api_url']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Hosted Checkout URL</label>
                                <input type="url" class="form-control" name="dpo_payment_url" value="<?php echo htmlspecialchars($config['payment_url']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Currency</label>
                                <input type="text" class="form-control" name="dpo_currency" value="<?php echo htmlspecialchars($config['currency']); ?>" maxlength="3">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Payment Window</label>
                                <div class="input-group">
                                    <input type="number" min="1" class="form-control" name="dpo_ptl_hours" value="<?php echo (int)$config['ptl_hours']; ?>">
                                    <span class="input-group-text">hours</span>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Debug Mode</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="dpo_debug_mode" <?php echo !empty($config['debug']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Default Payment <span class="text-muted fw-normal">(optional)</span></label>
                                <select class="form-select" name="dpo_default_payment">
                                    <option value="" <?php echo $config['default_payment'] === '' ? 'selected' : ''; ?>>Customer chooses</option>
                                    <option value="CC" <?php echo $config['default_payment'] === 'CC' ? 'selected' : ''; ?>>Card (CC)</option>
                                    <option value="MO" <?php echo $config['default_payment'] === 'MO' ? 'selected' : ''; ?>>Mobile Money (MO)</option>
                                    <option value="BT" <?php echo $config['default_payment'] === 'BT' ? 'selected' : ''; ?>>Bank Transfer (BT)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Default Payment Country <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" class="form-control" name="dpo_default_payment_country" value="<?php echo htmlspecialchars($config['default_payment_country']); ?>" placeholder="e.g. zambia">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Default Mobile Operator <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" class="form-control" name="dpo_default_payment_mno" value="<?php echo htmlspecialchars($config['default_payment_mno']); ?>" placeholder="e.g. AirtelZM">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-3"><i class="fas fa-user-graduate me-2 text-primary"></i>Course Registration Payment Gate</h5>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="regGate" name="reg_payment_gate_enabled" <?php echo $regGate['enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="regGate">Offer "Pay &amp; Submit Registration"</label>
                                </div>
                                <div class="small text-muted">When off, students below the fee threshold see only the bank-transfer instructions (current behaviour).</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Required payment threshold</label>
                                <div class="input-group">
                                    <input type="number" min="0" max="100" step="1" class="form-control" name="reg_payment_threshold_pct" value="<?php echo htmlspecialchars((string)$regGate['threshold_pct']); ?>">
                                    <span class="input-group-text">% of term tuition</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Pending registration expiry</label>
                                <div class="input-group">
                                    <input type="number" min="1" class="form-control" name="reg_pending_payment_expiry_days" value="<?php echo (int)$regGate['pending_expiry_days']; ?>">
                                    <span class="input-group-text">days</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mb-5">
                    <button type="submit" name="action" value="test" class="btn btn-outline-primary px-4">
                        <i class="fas fa-satellite-dish me-2"></i>Test Connection
                    </button>
                    <button type="submit" class="btn btn-primary px-5 fw-bold">
                        <i class="fas fa-save me-2"></i>Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
<?php if ($saved): ?>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        title: 'Settings Saved',
        text: 'Payment gateway configuration has been updated.',
        icon: 'success',
        confirmButtonColor: '#6f42c1',
        timer: 3000
    });
});
<?php endif; ?>
</script>

<?php require "includes/footer.php"; ?>

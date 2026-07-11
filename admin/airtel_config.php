<?php
/**
 * Airtel Money Configuration Management
 * Admin page for configuring Airtel Money credentials
 */

session_start();
require_once '../db/connect.php';
require_once '../includes/Guard.php';
require_once '../includes/airtel_config.php';

// Check admin permission
Guard::adminCheck();

$message = '';
$error = '';
$testResult = '';

// Get current configuration
$config = [
    'env' => AIRTEL_ENV,
    'client_id' => AIRTEL_CLIENT_ID,
    'client_secret' => AIRTEL_CLIENT_SECRET,
    'merchant_code' => AIRTEL_MERCHANT_CODE,
    'webhook_url' => AIRTEL_WEBHOOK_URL,
    'min_amount' => AIRTEL_MIN_AMOUNT,
    'max_amount' => AIRTEL_MAX_AMOUNT,
    'enabled' => AIRTEL_MONEY_ENABLED,
    'debug_mode' => AIRTEL_DEBUG_MODE
];

// Load saved config from database if exists
try {
    $query = "SELECT `key`, `value` FROM `portal_settings` WHERE `key` LIKE 'airtel_%'";
    $result = Database::getInstance()->getConnection()->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = str_replace('airtel_', '', $row['key']);
            $config[$key] = $row['value'];
        }
    }
} catch (Exception $e) {
    // Settings table may not exist
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_config') {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Save each configuration
            $settings = [
                'airtel_env' => $_POST['env'] ?? 'sandbox',
                'airtel_client_id' => $_POST['client_id'] ?? '',
                'airtel_client_secret' => $_POST['client_secret'] ?? '',
                'airtel_merchant_code' => $_POST['merchant_code'] ?? '',
                'airtel_webhook_url' => $_POST['webhook_url'] ?? '',
                'airtel_min_amount' => $_POST['min_amount'] ?? 10,
                'airtel_max_amount' => $_POST['max_amount'] ?? 100000,
                'airtel_enabled' => isset($_POST['enabled']) ? 1 : 0,
                'airtel_debug_mode' => isset($_POST['debug_mode']) ? 1 : 0
            ];
            
            // Create settings table if doesn't exist
            $createTableQuery = "CREATE TABLE IF NOT EXISTS `portal_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `key` VARCHAR(100) UNIQUE NOT NULL,
                `value` LONGTEXT,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $db->query($createTableQuery);
            
            // Insert or update settings
            foreach ($settings as $key => $value) {
                $query = "INSERT INTO `portal_settings` (`key`, `value`) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
                $stmt = $db->prepare($query);
                if ($stmt) {
                    $stmt->bind_param('ss', $key, $value);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    throw new Exception('Database error: ' . $db->error);
                }
            }
            
            // Reload config
            $config = array_map(fn($k) => str_replace('airtel_', '', $k), array_keys($settings));
            foreach ($settings as $key => $value) {
                $configKey = str_replace('airtel_', '', $key);
                $config[$configKey] = $value;
            }
            
            $message = 'Airtel Money configuration saved successfully!';
        } catch (Exception $e) {
            $error = 'Error saving configuration: ' . $e->getMessage();
        }
    }
    
    if ($action === 'test_credentials') {
        try {
            require_once '../includes/AirtelMoneyGateway.php';
            
            $gateway = new AirtelMoneyGateway();
            $token = $gateway->authenticate();
            
            if ($token) {
                $testResult = 'success';
                $message = 'Credentials verified successfully! OAuth2 authentication is working.';
            } else {
                $testResult = 'error';
                $error = 'Authentication failed. Please check your credentials.';
            }
        } catch (Exception $e) {
            $testResult = 'error';
            $error = 'Test failed: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Airtel Money Configuration</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <style>
        .config-section {
            border-left: 4px solid #007bff;
            padding-left: 1rem;
            margin-bottom: 2rem;
        }
        
        .credential-field {
            position: relative;
        }
        
        .credential-field .toggle-secret {
            position: absolute;
            right: 10px;
            top: 38px;
            cursor: pointer;
            color: #666;
        }
        
        .credential-field input[type="password"] ~ .toggle-secret::before {
            content: "\f06e";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
        }
        
        .credential-field input[type="text"].secret ~ .toggle-secret::before {
            content: "\f070";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-top: 1rem;
        }
        
        .status-badge.enabled {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-badge.disabled {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .info-box {
            background-color: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .warning-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .test-result {
            margin-top: 1rem;
            padding: 15px;
            border-radius: 4px;
        }
        
        .test-result.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .test-result.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .btn-group-vertical {
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-md-8">
                <h1>
                    <i class="fas fa-cog"></i> Airtel Money Configuration
                </h1>
                <p class="text-muted">Manage Airtel Money payment gateway credentials and settings</p>
            </div>
            <div class="col-md-4 text-end">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Admin
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($testResult === 'success'): ?>
            <div class="test-result success">
                <i class="fas fa-check-circle"></i> 
                <strong>Connection Test Successful!</strong>
                OAuth2 authentication with Airtel Money API is working correctly.
            </div>
        <?php elseif ($testResult === 'error'): ?>
            <div class="test-result error">
                <i class="fas fa-times-circle"></i>
                <strong>Connection Test Failed!</strong>
                Please verify your credentials are correct.
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="info-box">
                            <i class="fas fa-info-circle"></i>
                            <strong>Important:</strong> These credentials control payment processing.
                            Ensure you have valid credentials from Airtel Money before configuring.
                            Use sandbox credentials for testing first.
                        </div>

                        <form method="POST" class="needs-validation">
                            <input type="hidden" name="action" value="save_config">

                            <!-- Environment Section -->
                            <div class="config-section">
                                <h5><i class="fas fa-cloud"></i> Environment</h5>
                                
                                <div class="mb-3">
                                    <label for="env" class="form-label">Environment Mode</label>
                                    <select name="env" id="env" class="form-select" required>
                                        <option value="sandbox" <?php echo $config['env'] === 'sandbox' ? 'selected' : ''; ?>>
                                            Sandbox (Testing)
                                        </option>
                                        <option value="production" <?php echo $config['env'] === 'production' ? 'selected' : ''; ?>>
                                            Production (Live)
                                        </option>
                                    </select>
                                    <small class="form-text text-muted">
                                        Sandbox is for testing. Production is for live payments.
                                    </small>
                                </div>
                            </div>

                            <!-- API Credentials Section -->
                            <div class="config-section">
                                <h5><i class="fas fa-key"></i> API Credentials</h5>
                                
                                <div class="mb-3 credential-field">
                                    <label for="client_id" class="form-label">OAuth2 Client ID</label>
                                    <input 
                                        type="text" 
                                        name="client_id" 
                                        id="client_id" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($config['client_id'] ?? ''); ?>"
                                        required
                                    >
                                    <span class="toggle-secret" onclick="toggleSecretField(this)"></span>
                                </div>

                                <div class="mb-3 credential-field">
                                    <label for="client_secret" class="form-label">OAuth2 Client Secret</label>
                                    <input 
                                        type="password" 
                                        name="client_secret" 
                                        id="client_secret" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($config['client_secret'] ?? ''); ?>"
                                        required
                                    >
                                    <span class="toggle-secret" onclick="toggleSecretField(this)"></span>
                                </div>

                                <div class="mb-3">
                                    <label for="merchant_code" class="form-label">Merchant Code</label>
                                    <input 
                                        type="text" 
                                        name="merchant_code" 
                                        id="merchant_code" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($config['merchant_code'] ?? ''); ?>"
                                        required
                                    >
                                    <small class="form-text text-muted">
                                        Your unique merchant identifier with Airtel Money
                                    </small>
                                </div>
                            </div>

                            <!-- Webhook Configuration -->
                            <div class="config-section">
                                <h5><i class="fas fa-link"></i> Webhook Configuration</h5>
                                
                                <div class="mb-3">
                                    <label for="webhook_url" class="form-label">Webhook URL</label>
                                    <input 
                                        type="url" 
                                        name="webhook_url" 
                                        id="webhook_url" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($config['webhook_url'] ?? ''); ?>"
                                        placeholder="https://yourdomain.com/wucportal/students/airtel_callback.php"
                                        required
                                    >
                                    <small class="form-text text-muted">
                                        Airtel will POST payment confirmations to this URL. Must be HTTPS and accessible from internet.
                                    </small>
                                </div>
                            </div>

                            <!-- Payment Limits -->
                            <div class="config-section">
                                <h5><i class="fas fa-coins"></i> Payment Limits (ZMW)</h5>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="min_amount" class="form-label">Minimum Amount</label>
                                        <input 
                                            type="number" 
                                            name="min_amount" 
                                            id="min_amount" 
                                            class="form-control" 
                                            value="<?php echo htmlspecialchars($config['min_amount'] ?? 10); ?>"
                                            min="1"
                                            required
                                        >
                                        <small class="form-text text-muted">Minimum amount users can pay</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="max_amount" class="form-label">Maximum Amount</label>
                                        <input 
                                            type="number" 
                                            name="max_amount" 
                                            id="max_amount" 
                                            class="form-control" 
                                            value="<?php echo htmlspecialchars($config['max_amount'] ?? 100000); ?>"
                                            min="100"
                                            required
                                        >
                                        <small class="form-text text-muted">Maximum amount users can pay</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Feature Flags -->
                            <div class="config-section">
                                <h5><i class="fas fa-toggle-on"></i> Feature Flags</h5>
                                
                                <div class="form-check mb-3">
                                    <input 
                                        type="checkbox" 
                                        name="enabled" 
                                        id="enabled" 
                                        class="form-check-input" 
                                        <?php echo ($config['enabled'] ?? true) ? 'checked' : ''; ?>
                                    >
                                    <label class="form-check-label" for="enabled">
                                        <strong>Enable Airtel Money Payments</strong>
                                        <br>
                                        <small class="text-muted">Uncheck to disable Airtel Money for all users</small>
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input 
                                        type="checkbox" 
                                        name="debug_mode" 
                                        id="debug_mode" 
                                        class="form-check-input" 
                                        <?php echo ($config['debug_mode'] ?? false) ? 'checked' : ''; ?>
                                    >
                                    <label class="form-check-label" for="debug_mode">
                                        <strong>Enable Debug Mode</strong>
                                        <br>
                                        <small class="text-muted">Log detailed information for troubleshooting (disable in production)</small>
                                    </label>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-save"></i> Save Configuration
                                </button>
                                
                                <button 
                                    type="button" 
                                    class="btn btn-info btn-lg" 
                                    onclick="testCredentials()"
                                >
                                    <i class="fas fa-flask"></i> Test Credentials
                                </button>
                            </div>
                        </form>

                        <hr class="my-4">

                        <!-- Additional Information -->
                        <div class="warning-box">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Before Going Live:</strong>
                            <ul class="mb-0">
                                <li>Test with sandbox credentials first</li>
                                <li>Ensure webhook URL is accessible from the internet</li>
                                <li>SSL certificate must be valid (no self-signed certificates)</li>
                                <li>Test complete payment flow with a test transaction</li>
                                <li>Review Airtel Money API documentation for current endpoints</li>
                                <li>Ensure database transactions table exists</li>
                                <li>Configure logs directory permissions (755)</li>
                            </ul>
                        </div>

                        <!-- Quick Reference -->
                        <div class="card bg-light mt-4">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-book"></i> Quick Reference</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Sandbox Testing</h6>
                                        <ul class="small">
                                            <li>Endpoint: sandbox.airtelapi.com</li>
                                            <li>Test Phone: 0976543210</li>
                                            <li>Test Amount: 50 ZMW</li>
                                            <li>No real money charged</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Important Files</h6>
                                        <ul class="small">
                                            <li>Gateway: includes/AirtelMoneyGateway.php</li>
                                            <li>Callback: students/airtel_callback.php</li>
                                            <li>Logs: logs/airtel_callbacks.log</li>
                                            <li>Frontend: students/js/airtel_money.js</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Transactions -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-history"></i> Recent Airtel Money Transactions</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                try {
                                    $query = "SELECT referenceID AS reference_id, studentID AS student_id,
                                                     amount, status, created_at
                                             FROM transactions
                                             WHERE channel = 'Airtel Money'
                                             ORDER BY created_at DESC
                                             LIMIT 5";
                                    $result = Database::getInstance()->getConnection()->query($query);
                                    
                                    if ($result && $result->num_rows > 0):
                                ?>
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Reference ID</th>
                                            <th>Student ID</th>
                                            <th>Amount (ZMW)</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($row['reference_id']); ?></code></td>
                                            <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                            <td><?php echo number_format($row['amount'], 2); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo match($row['status']) {
                                                        'completed' => 'success',
                                                        'failed' => 'danger',
                                                        'cancelled' => 'warning',
                                                        default => 'info'
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($row['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                                <?php else: ?>
                                <p class="text-muted">No Airtel Money transactions yet</p>
                                <?php endif; ?>
                                <?php
                                } catch (Exception $e) {
                                    echo '<p class="text-muted">Transactions table not found</p>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSecretField(element) {
            const input = element.previousElementSibling;
            if (input.type === 'password') {
                input.type = 'text';
                input.classList.add('secret');
            } else {
                input.type = 'password';
                input.classList.remove('secret');
            }
        }
        
        function testCredentials() {
            if (!confirm('Test API credentials? This will attempt to authenticate with Airtel Money.')) {
                return;
            }
            
            const form = document.querySelector('form');
            const formData = new FormData(form);
            formData.set('action', 'test_credentials');
            
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                location.reload();
            })
            .catch(error => {
                alert('Error: ' + error.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-flask"></i> Test Credentials';
            });
        }
    </script>
</body>
</html>

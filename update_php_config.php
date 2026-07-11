<?php
/**
 * PHP Configuration Update Helper
 * 
 * This script helps identify and update PHP upload limits.
 * Run this script to check current settings and get instructions.
 */

header('Content-Type: text/html; charset=utf-8');

// Function to parse ini size values
function parseIniSize($size) {
    $unit = strtolower(substr($size, -1));
    $value = (int)$size;
    switch($unit) {
        case 'g': return $value * 1024 * 1024 * 1024;
        case 'm': return $value * 1024 * 1024;
        case 'k': return $value * 1024;
        default: return $value;
    }
}

// Get current settings
$uploadMax = ini_get('upload_max_filesize');
$postMax = ini_get('post_max_size');
$memoryLimit = ini_get('memory_limit');
$maxExecutionTime = ini_get('max_execution_time');
$maxInputTime = ini_get('max_input_time');

$uploadMaxBytes = parseIniSize($uploadMax);
$postMaxBytes = parseIniSize($postMax);
$actualMaxUpload = min($uploadMaxBytes, $postMaxBytes);
$actualMaxUploadMB = round($actualMaxUpload / (1024 * 1024));

// Check if limits are sufficient for 500MB uploads
$targetSize = 500 * 1024 * 1024; // 500MB in bytes
$isConfigured = ($actualMaxUpload >= $targetSize);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Upload Configuration Check</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container my-5">
        <div class="card shadow">
            <div class="card-header <?php echo $isConfigured ? 'bg-success' : 'bg-warning'; ?> text-white">
                <h2 class="mb-0">
                    <i class="fas fa-cog"></i> PHP Upload Configuration Check
                </h2>
            </div>
            <div class="card-body">
                
                <?php if ($isConfigured): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                        <h4>Configuration OK!</h4>
                        <p class="mb-0">Your PHP server is configured to handle uploads up to <strong><?php echo $actualMaxUploadMB; ?> MB</strong>.</p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <h4>Configuration Update Required</h4>
                        <p>Your current limit is <strong><?php echo $actualMaxUploadMB; ?> MB</strong>, but your application requires <strong>500 MB</strong>.</p>
                    </div>
                <?php endif; ?>

                <h5 class="mt-4"><i class="fas fa-info-circle"></i> Current PHP Settings</h5>
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Setting</th>
                            <th>Current Value</th>
                            <th>Recommended Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="<?php echo ($uploadMaxBytes >= $targetSize) ? 'table-success' : 'table-danger'; ?>">
                            <td><code>upload_max_filesize</code></td>
                            <td><strong><?php echo $uploadMax; ?></strong></td>
                            <td><strong>500M</strong></td>
                            <td>
                                <?php if ($uploadMaxBytes >= $targetSize): ?>
                                    <i class="fas fa-check-circle text-success"></i> OK
                                <?php else: ?>
                                    <i class="fas fa-times-circle text-danger"></i> Too Low
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="<?php echo ($postMaxBytes >= $targetSize) ? 'table-success' : 'table-danger'; ?>">
                            <td><code>post_max_size</code></td>
                            <td><strong><?php echo $postMax; ?></strong></td>
                            <td><strong>550M</strong></td>
                            <td>
                                <?php if ($postMaxBytes >= $targetSize): ?>
                                    <i class="fas fa-check-circle text-success"></i> OK
                                <?php else: ?>
                                    <i class="fas fa-times-circle text-danger"></i> Too Low
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="<?php echo ($maxExecutionTime >= 300 || $maxExecutionTime == 0) ? 'table-success' : 'table-warning'; ?>">
                            <td><code>max_execution_time</code></td>
                            <td><strong><?php echo $maxExecutionTime; ?>s</strong></td>
                            <td><strong>300s</strong></td>
                            <td>
                                <?php if ($maxExecutionTime >= 300 || $maxExecutionTime == 0): ?>
                                    <i class="fas fa-check-circle text-success"></i> OK
                                <?php else: ?>
                                    <i class="fas fa-exclamation-triangle text-warning"></i> Low
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><code>memory_limit</code></td>
                            <td><strong><?php echo $memoryLimit; ?></strong></td>
                            <td><strong>512M</strong></td>
                            <td><i class="fas fa-info-circle text-info"></i> Info</td>
                        </tr>
                    </tbody>
                </table>

                <h5 class="mt-4"><i class="fas fa-wrench"></i> Configuration File Location</h5>
                <div class="alert alert-info">
                    <strong>php.ini location:</strong><br>
                    <code><?php echo php_ini_loaded_file(); ?></code>
                </div>

                <?php if (!$isConfigured): ?>
                    <h5 class="mt-4"><i class="fas fa-clipboard-list"></i> How to Fix</h5>
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6>Step 1: Edit php.ini</h6>
                            <p>Open the configuration file shown above and find/update these lines:</p>
                            <pre class="bg-dark text-light p-3 rounded"><code>upload_max_filesize = 500M
post_max_size = 550M
max_execution_time = 300
max_input_time = 300
memory_limit = 512M</code></pre>
                            
                            <h6 class="mt-3">Step 2: Restart Apache</h6>
                            <p>For XAMPP users:</p>
                            <ol>
                                <li>Open XAMPP Control Panel</li>
                                <li>Click "Stop" for Apache</li>
                                <li>Wait 2 seconds</li>
                                <li>Click "Start" for Apache</li>
                            </ol>

                            <h6 class="mt-3">Step 3: Verify Changes</h6>
                            <p>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-primary">
                                    <i class="fas fa-sync"></i> Refresh This Page
                                </a>
                            </p>
                        </div>
                    </div>

                    <div class="alert alert-warning mt-3">
                        <strong><i class="fas fa-lightbulb"></i> Quick tip:</strong> 
                        In XAMPP, the php.ini file is usually located at:<br>
                        <code>C:\xampp\php\php.ini</code>
                    </div>
                <?php endif; ?>

                <h5 class="mt-4"><i class="fas fa-terminal"></i> Additional Information</h5>
                <table class="table table-sm">
                    <tr>
                        <td>PHP Version</td>
                        <td><strong><?php echo PHP_VERSION; ?></strong></td>
                    </tr>
                    <tr>
                        <td>Server API</td>
                        <td><strong><?php echo PHP_SAPI; ?></strong></td>
                    </tr>
                    <tr>
                        <td>Effective Max Upload</td>
                        <td><strong><?php echo $actualMaxUploadMB; ?> MB</strong></td>
                    </tr>
                </table>

                <div class="mt-4">
                    <a href="admin/elearning/sessions.php" class="btn btn-success">
                        <i class="fas fa-arrow-left"></i> Back to Sessions
                    </a>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                        <i class="fas fa-sync"></i> Refresh Check
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

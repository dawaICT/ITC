<?php
/**
 * Upload Modal Debug Test Page
 * Standalone test page to verify upload modal functionality
 */

// Get actual PHP upload limits
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

$serverUploadMax = parseIniSize(ini_get('upload_max_filesize'));
$serverPostMax = parseIniSize(ini_get('post_max_size'));
$actualMaxUpload = min($serverUploadMax, $serverPostMax);
$actualMaxUploadMB = round($actualMaxUpload / (1024 * 1024));

// Handle test upload
$uploadResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['video_file'])) {
    $uploadResult = [
        'timestamp' => date('Y-m-d H:i:s'),
        'post_data' => $_POST,
        'files_data' => $_FILES,
        'server_limits' => [
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
            'effective_max_mb' => $actualMaxUploadMB
        ]
    ];
    
    if ($_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
        $uploadResult['status'] = 'SUCCESS';
        $uploadResult['file_info'] = [
            'name' => $_FILES['video_file']['name'],
            'type' => $_FILES['video_file']['type'],
            'size' => $_FILES['video_file']['size'],
            'size_mb' => round($_FILES['video_file']['size'] / 1048576, 2),
            'tmp_name' => $_FILES['video_file']['tmp_name']
        ];
    } else {
        $uploadResult['status'] = 'ERROR';
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
            UPLOAD_ERR_EXTENSION => 'PHP extension stopped upload'
        ];
        $uploadResult['error_code'] = $_FILES['video_file']['error'];
        $uploadResult['error_message'] = $errorMessages[$_FILES['video_file']['error']] ?? 'Unknown error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Modal Debug Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .debug-console {
            background: #1e1e1e;
            color: #d4d4d4;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 12px;
            padding: 15px;
            border-radius: 5px;
            max-height: 400px;
            overflow-y: auto;
            margin-top: 20px;
        }
        .debug-console .log-entry {
            padding: 5px 0;
            border-bottom: 1px solid #333;
        }
        .debug-console .log-error { color: #f48771; }
        .debug-console .log-success { color: #4ec9b0; }
        .debug-console .log-warning { color: #dcdcaa; }
        .debug-console .log-info { color: #9cdcfe; }
    </style>
</head>
<body class="bg-light">
    <div class="container my-5">
        <div class="row">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0"><i class="fas fa-bug"></i> Upload Modal Debug Test</h3>
                    </div>
                    <div class="card-body">
                        <!-- PHP Configuration Info -->
                        <div class="alert <?php echo $actualMaxUploadMB >= 500 ? 'alert-success' : 'alert-warning'; ?>">
                            <h5><i class="fas fa-info-circle"></i> Server Configuration</h5>
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td><strong>upload_max_filesize:</strong></td>
                                    <td><?php echo ini_get('upload_max_filesize'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>post_max_size:</strong></td>
                                    <td><?php echo ini_get('post_max_size'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Effective Max Upload:</strong></td>
                                    <td class="<?php echo $actualMaxUploadMB >= 500 ? 'text-success' : 'text-danger'; ?>">
                                        <strong><?php echo $actualMaxUploadMB; ?> MB</strong>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- Upload Form (Same as Sessions.php) -->
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#testUploadModal">
                            <i class="fas fa-upload"></i> Test Upload Modal
                        </button>

                        <button class="btn btn-secondary" onclick="clearConsole()">
                            <i class="fas fa-eraser"></i> Clear Console
                        </button>

                        <a href="update_php_config.php" class="btn btn-info">
                            <i class="fas fa-cog"></i> Check PHP Config
                        </a>

                        <?php if ($uploadResult): ?>
                        <div class="mt-4 alert <?php echo $uploadResult['status'] === 'SUCCESS' ? 'alert-success' : 'alert-danger'; ?>">
                            <h5><i class="fas fa-<?php echo $uploadResult['status'] === 'SUCCESS' ? 'check-circle' : 'times-circle'; ?>"></i> 
                                Upload <?php echo $uploadResult['status']; ?></h5>
                            <pre class="mb-0"><?php echo json_encode($uploadResult, JSON_PRETTY_PRINT); ?></pre>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Debug Console -->
                <div class="card shadow mt-4">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-terminal"></i> JavaScript Debug Console</h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="debugConsole" class="debug-console">
                            <div class="log-entry log-info">Console initialized - waiting for events...</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Event Monitor -->
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Event Monitor</h5>
                    </div>
                    <div class="card-body">
                        <div id="eventStats">
                            <p><strong>Button Clicks:</strong> <span id="btnClicks">0</span></p>
                            <p><strong>Modal Opens:</strong> <span id="modalOpens">0</span></p>
                            <p><strong>Files Selected:</strong> <span id="filesSelected">0</span></p>
                            <p><strong>Form Submits:</strong> <span id="formSubmits">0</span></p>
                            <p><strong>Validation Errors:</strong> <span id="validationErrors">0</span></p>
                        </div>
                    </div>
                </div>

                <div class="card shadow mt-3">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-clipboard-list"></i> Quick Tests</h5>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-sm btn-outline-primary w-100 mb-2" onclick="testBootstrap()">
                            Test Bootstrap
                        </button>
                        <button class="btn btn-sm btn-outline-primary w-100 mb-2" onclick="testModalElements()">
                            Test Modal Elements
                        </button>
                        <button class="btn btn-sm btn-outline-primary w-100 mb-2" onclick="testFileValidation()">
                            Test File Validation
                        </button>
                        <button class="btn btn-sm btn-outline-danger w-100" onclick="triggerTestError()">
                            Trigger Test Error
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Test Upload Modal (Replica from sessions.php) -->
    <div class="modal fade" id="testUploadModal" tabindex="-1" aria-labelledby="testUploadModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="testUploadModalLabel">Test Upload - Debug Mode</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" enctype="multipart/form-data" class="upload-form" data-session-id="test-001">
                    <input type="hidden" name="action" value="test_upload" />
                    <input type="hidden" name="session_id" value="test-001" />
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo $actualMaxUpload; ?>" />
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Test Session</label>
                            <input type="text" class="form-control" disabled value="DEBUG-001: Test Video Upload" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="video_file_test">Select Video File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control video-input" name="video_file" id="video_file_test" 
                                   accept="video/mp4,video/webm,video/ogg,video/quicktime" required 
                                   data-max-size="<?php echo $actualMaxUpload; ?>" />
                            <div class="form-text">
                                <strong>Supported formats:</strong> MP4, WebM, OGG, MOV<br>
                                <?php if ($actualMaxUploadMB >= 500): ?>
                                    <span class="text-success"><i class="fas fa-check-circle"></i> <strong>Maximum file size: 500 MB</strong></span>
                                <?php else: ?>
                                    <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> <strong>Server limit: <?php echo $actualMaxUploadMB; ?> MB</strong></span><br>
                                    <small class="text-muted">PHP upload_max_filesize: <?php echo ini_get('upload_max_filesize'); ?> | post_max_size: <?php echo ini_get('post_max_size'); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="file-info mt-2 text-muted small"></div>
                        </div>
                        <div class="progress mt-3" style="display: none;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary upload-btn">
                            <i class="fas fa-upload"></i> Upload Video
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Debug Console Logger
    let eventCounts = {
        btnClicks: 0,
        modalOpens: 0,
        filesSelected: 0,
        formSubmits: 0,
        validationErrors: 0
    };

    function updateEventStats() {
        Object.keys(eventCounts).forEach(key => {
            const el = document.getElementById(key);
            if (el) el.textContent = eventCounts[key];
        });
    }

    function logToConsole(message, type = 'info') {
        const console = document.getElementById('debugConsole');
        const timestamp = new Date().toLocaleTimeString();
        const entry = document.createElement('div');
        entry.className = `log-entry log-${type}`;
        entry.innerHTML = `<strong>[${timestamp}]</strong> ${message}`;
        console.appendChild(entry);
        console.scrollTop = console.scrollHeight;
        
        // Also log to browser console
        console[type === 'error' ? 'error' : 'log'](`[Upload Debug] ${message}`);
    }

    function clearConsole() {
        document.getElementById('debugConsole').innerHTML = '<div class="log-entry log-info">Console cleared</div>';
        logToConsole('Console cleared', 'info');
    }

    // Test Functions
    function testBootstrap() {
        if (typeof bootstrap !== 'undefined') {
            logToConsole('✓ Bootstrap is loaded (version: ' + bootstrap.Alert.VERSION + ')', 'success');
        } else {
            logToConsole('✗ Bootstrap is NOT loaded!', 'error');
        }
    }

    function testModalElements() {
        const modal = document.getElementById('testUploadModal');
        const form = document.querySelector('.upload-form');
        const fileInput = document.querySelector('.video-input');
        const uploadBtn = document.querySelector('.upload-btn');
        
        logToConsole('Modal element: ' + (modal ? '✓ Found' : '✗ Not found'), modal ? 'success' : 'error');
        logToConsole('Form element: ' + (form ? '✓ Found' : '✗ Not found'), form ? 'success' : 'error');
        logToConsole('File input: ' + (fileInput ? '✓ Found' : '✗ Not found'), fileInput ? 'success' : 'error');
        logToConsole('Upload button: ' + (uploadBtn ? '✓ Found' : '✗ Not found'), uploadBtn ? 'success' : 'error');
    }

    function testFileValidation() {
        logToConsole('File validation test - Select a file to validate', 'warning');
        const fileInput = document.querySelector('.video-input');
        if (fileInput) {
            fileInput.click();
        }
    }

    function triggerTestError() {
        logToConsole('Triggering test error...', 'warning');
        try {
            throw new Error('This is a test error');
        } catch (e) {
            logToConsole('✗ Error caught: ' + e.message, 'error');
            eventCounts.validationErrors++;
            updateEventStats();
        }
    }

    // Main JavaScript (Same as sessions.php)
    document.addEventListener('DOMContentLoaded', function() {
        logToConsole('=== Page Loaded - Initializing Upload Debug ===', 'info');
        
        // Check Bootstrap
        if (typeof bootstrap !== 'undefined') {
            logToConsole('✓ Bootstrap JS loaded', 'success');
        } else {
            logToConsole('✗ Bootstrap JS not loaded!', 'error');
        }
        
        // File input validation
        document.querySelectorAll('.video-input').forEach((input, idx) => {
            logToConsole(`Attaching listeners to file input #${idx + 1}`, 'info');
            
            input.addEventListener('change', function() {
                eventCounts.filesSelected++;
                updateEventStats();
                
                const file = this.files[0];
                const formGroup = this.closest('.mb-3');
                const infoDiv = formGroup ? formGroup.querySelector('.file-info') : null;
                const uploadBtn = this.closest('form').querySelector('.upload-btn');
                const maxSize = parseInt(this.dataset.maxSize) || (500 * 1048576);
                const maxSizeMB = (maxSize / 1048576).toFixed(0);
                
                logToConsole('File selected: ' + (file ? file.name : 'none'), 'info');
                logToConsole('Max allowed size: ' + maxSizeMB + ' MB', 'info');
                
                if (file && infoDiv) {
                    const sizeMB = (file.size / 1048576).toFixed(2);
                    const validTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
                    
                    logToConsole('File type: ' + file.type, 'info');
                    logToConsole('File size: ' + sizeMB + ' MB', 'info');
                    
                    if (!validTypes.includes(file.type)) {
                        infoDiv.innerHTML = '<span class="text-danger"><i class="fas fa-times"></i> Invalid file type: ' + file.type + '</span>';
                        if (uploadBtn) uploadBtn.disabled = true;
                        logToConsole('✗ Invalid file type: ' + file.type, 'error');
                        eventCounts.validationErrors++;
                        updateEventStats();
                    } else if (file.size > maxSize) {
                        infoDiv.innerHTML = '<span class="text-danger"><i class="fas fa-times"></i> File too large (' + sizeMB + ' MB). Server limit: ' + maxSizeMB + ' MB</span>';
                        if (uploadBtn) uploadBtn.disabled = true;
                        logToConsole('✗ File too large: ' + sizeMB + ' MB > ' + maxSizeMB + ' MB', 'error');
                        eventCounts.validationErrors++;
                        updateEventStats();
                    } else {
                        infoDiv.innerHTML = '<span class="text-success"><i class="fas fa-check"></i> ' + file.name + ' (' + sizeMB + ' MB) - Ready to upload</span>';
                        if (uploadBtn) uploadBtn.disabled = false;
                        logToConsole('✓ File validation passed: ' + file.name + ' (' + sizeMB + ' MB)', 'success');
                    }
                }
            });
        });
        
        // Form submission
        document.querySelectorAll('.upload-form').forEach((form, idx) => {
            const sessionId = form.dataset.sessionId;
            logToConsole(`Upload form initialized for session ${sessionId}`, 'info');
            
            form.addEventListener('submit', function(e) {
                eventCounts.formSubmits++;
                updateEventStats();
                
                logToConsole('Form submit triggered for session ' + sessionId, 'warning');
                
                const fileInput = this.querySelector('.video-input');
                const file = fileInput ? fileInput.files[0] : null;
                
                if (!file) {
                    e.preventDefault();
                    logToConsole('✗ Submit blocked: No file selected', 'error');
                    alert('Please select a video file to upload.');
                    return false;
                }
                
                const maxSize = parseInt(fileInput.dataset.maxSize) || (500 * 1048576);
                const maxSizeMB = (maxSize / 1048576).toFixed(0);
                
                if (file.size > maxSize) {
                    e.preventDefault();
                    logToConsole('✗ Submit blocked: File too large (' + (file.size / 1048576).toFixed(2) + ' MB > ' + maxSizeMB + ' MB)', 'error');
                    alert('File is too large. Maximum size is ' + maxSizeMB + ' MB.\\n\\nYour file: ' + (file.size / 1048576).toFixed(2) + ' MB');
                    return false;
                }
                
                logToConsole('✓ Validation passed - submitting form with file: ' + file.name, 'success');
                logToConsole('Upload starting... This may take a while for large files.', 'warning');
                
                // Show progress
                const progressDiv = this.querySelector('.progress');
                if (progressDiv) {
                    progressDiv.style.display = 'block';
                    logToConsole('Progress indicator shown', 'info');
                }
                
                return true;
            });
        });
        
        // Modal events
        const modal = document.getElementById('testUploadModal');
        if (modal) {
            modal.addEventListener('show.bs.modal', function(event) {
                eventCounts.modalOpens++;
                updateEventStats();
                logToConsole('Modal opening', 'info');
            });
            
            modal.addEventListener('shown.bs.modal', function() {
                logToConsole('✓ Modal fully opened', 'success');
            });
            
            modal.addEventListener('hidden.bs.modal', function() {
                logToConsole('Modal closed', 'info');
            });
        }
        
        logToConsole('=== Initialization Complete ===', 'success');
        testModalElements();
    });

    // Global error handler
    window.onerror = function(msg, url, line, col, error) {
        logToConsole('✗ JavaScript Error: ' + msg + ' (Line: ' + line + ')', 'error');
        return false;
    };
    </script>
</body>
</html>

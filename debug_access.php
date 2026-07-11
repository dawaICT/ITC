<?php
/**
 * Comprehensive Access Debugging Script
 * Tests both backend and frontend access issues
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting
ini_set('display_errors', '0');
error_reporting(E_ALL);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITC Portal - Access Debug</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .debug-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .status-ok { color: #28a745; }
        .status-error { color: #dc3545; }
        .status-warning { color: #ffc107; }
        .section-title {
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .test-row {
            padding: 10px;
            margin: 5px 0;
            border-left: 3px solid #ddd;
            background: #f8f9fa;
        }
        .test-row.pass { border-left-color: #28a745; }
        .test-row.fail { border-left-color: #dc3545; }
        .test-row.warn { border-left-color: #ffc107; }
        pre {
            background: #2d3436;
            color: #00ff87;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .badge-custom {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="text-center text-white mb-4">
            <h1><i class="fas fa-bug"></i> ITC Portal Access Debugger</h1>
            <p class="lead">Comprehensive Backend & Frontend Diagnostics</p>
        </div>

        <?php
        // Test Results Array
        $tests = ['passed' => 0, 'failed' => 0, 'warnings' => 0];
        
        // Database connection test
        require_once __DIR__ . '/db/connect.php';
        ?>

        <!-- 1. SESSION DIAGNOSTICS -->
        <div class="debug-card">
            <h3 class="section-title"><i class="fas fa-key"></i> Session & Authentication</h3>
            
            <?php if (!empty($_SESSION)): ?>
                <div class="test-row pass">
                    <strong><i class="fas fa-check-circle status-ok"></i> Session Active</strong>
                    <span class="badge bg-success ms-2">ID: <?= session_id() ?></span>
                </div>
                <?php $tests['passed']++; ?>
                
                <div class="mt-3">
                    <h5>Session Variables:</h5>
                    <pre><?= htmlspecialchars(print_r($_SESSION, true)) ?></pre>
                </div>

                <!-- Check authentication status -->
                <?php
                $auth_checks = [
                    'staff_id' => isset($_SESSION['staff_id']),
                    'user_role' => isset($_SESSION['user_role']),
                    'position' => isset($_SESSION['position']),
                    'index (admin)' => isset($_SESSION['index']) && $_SESSION['index'] === 'admin',
                    'last_activity' => isset($_SESSION['last_activity'])
                ];
                
                foreach ($auth_checks as $check => $result):
                    if ($result):
                        $tests['passed']++;
                ?>
                    <div class="test-row pass">
                        <i class="fas fa-check status-ok"></i> <?= htmlspecialchars($check) ?>: 
                        <code><?= htmlspecialchars($_SESSION[$check] ?? 'true') ?></code>
                    </div>
                <?php else: 
                        $tests['failed']++;
                ?>
                    <div class="test-row fail">
                        <i class="fas fa-times status-error"></i> <?= htmlspecialchars($check) ?>: <strong>NOT SET</strong>
                    </div>
                <?php endif; endforeach; ?>

            <?php else: 
                $tests['failed']++;
            ?>
                <div class="test-row fail">
                    <strong><i class="fas fa-exclamation-triangle status-error"></i> No Active Session</strong>
                    <p class="mb-0 mt-2">Please log in first at <a href="staff_login.php">Staff Login</a></p>
                </div>
            <?php endif; ?>

            <!-- Session timeout check -->
            <?php
            if (isset($_SESSION['last_activity'])) {
                $elapsed = time() - $_SESSION['last_activity'];
                $timeout_minutes = 30;
                $is_expired = $elapsed > ($timeout_minutes * 60);
                
                if ($is_expired) {
                    $tests['warnings']++;
                    echo '<div class="test-row warn">';
                    echo '<i class="fas fa-clock status-warning"></i> Session timeout: ';
                    echo '<strong>EXPIRED</strong> (' . round($elapsed / 60, 1) . ' minutes ago)';
                    echo '</div>';
                } else {
                    $tests['passed']++;
                    echo '<div class="test-row pass">';
                    echo '<i class="fas fa-check status-ok"></i> Session timeout: ';
                    echo '<strong>VALID</strong> (active ' . round($elapsed / 60, 1) . ' minutes ago)';
                    echo '</div>';
                }
            }
            ?>
        </div>

        <!-- 2. DATABASE CONNECTIVITY -->
        <div class="debug-card">
            <h3 class="section-title"><i class="fas fa-database"></i> Database Connectivity</h3>
            
            <?php if (isset($db) && $db instanceof mysqli && !$db->connect_error): 
                $tests['passed']++;
            ?>
                <div class="test-row pass">
                    <i class="fas fa-check-circle status-ok"></i> Database Connected
                    <span class="badge bg-success ms-2">Host: <?= $db->host_info ?></span>
                </div>
                
                <?php
                // Test critical tables
                $critical_tables = ['staff', 'students', 'user_credentials', 'access_right', 'staff_positions', 'positions'];
                foreach ($critical_tables as $table):
                    $result = $db->query("SHOW TABLES LIKE '$table'");
                    if ($result && $result->num_rows > 0):
                        $tests['passed']++;
                ?>
                    <div class="test-row pass">
                        <i class="fas fa-table status-ok"></i> Table: <code><?= htmlspecialchars($table) ?></code>
                    </div>
                <?php else: 
                        $tests['failed']++;
                ?>
                    <div class="test-row fail">
                        <i class="fas fa-times status-error"></i> Table: <code><?= htmlspecialchars($table) ?></code> - <strong>MISSING</strong>
                    </div>
                <?php endif; endforeach; ?>

            <?php else: 
                $tests['failed']++;
            ?>
                <div class="test-row fail">
                    <i class="fas fa-times-circle status-error"></i> Database Connection Failed
                    <p class="mb-0 mt-2"><?= htmlspecialchars($db->connect_error ?? 'Unknown error') ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3. USER ACCESS RIGHTS -->
        <?php if (isset($_SESSION['staff_id']) && isset($db)): ?>
        <div class="debug-card">
            <h3 class="section-title"><i class="fas fa-user-shield"></i> User Access Rights</h3>
            
            <?php
            $staff_id = $_SESSION['staff_id'];
            
            // Check staff record
            $staff_query = "SELECT * FROM staff WHERE staff_id = ? LIMIT 1";
            $stmt = $db->prepare($staff_query);
            $stmt->bind_param("s", $staff_id);
            $stmt->execute();
            $staff_result = $stmt->get_result();
            
            if ($staff_result && $staff_result->num_rows > 0):
                $staff_data = $staff_result->fetch_assoc();
                $tests['passed']++;
            ?>
                <div class="test-row pass">
                    <i class="fas fa-user status-ok"></i> Staff Record Found
                    <div class="mt-2">
                        <strong>Name:</strong> <?= htmlspecialchars($staff_data['title'] ?? '') ?> 
                        <?= htmlspecialchars($staff_data['Fname'] ?? '') ?> 
                        <?= htmlspecialchars($staff_data['Lname'] ?? '') ?><br>
                        <strong>Staff ID:</strong> <?= htmlspecialchars($staff_data['staff_id']) ?><br>
                        <strong>Email:</strong> <?= htmlspecialchars($staff_data['email'] ?? 'N/A') ?>
                    </div>
                </div>
            <?php else: 
                $tests['failed']++;
            ?>
                <div class="test-row fail">
                    <i class="fas fa-times status-error"></i> Staff Record Not Found for ID: <?= htmlspecialchars($staff_id) ?>
                </div>
            <?php endif; $stmt->close(); ?>

            <?php
            // Check position
            $pos_query = "SELECT p.PosName FROM staff_positions sp 
                         INNER JOIN positions p ON sp.PosID = p.PosID 
                         WHERE sp.staff_id = ? LIMIT 1";
            $pos_stmt = $db->prepare($pos_query);
            $pos_stmt->bind_param("s", $staff_id);
            $pos_stmt->execute();
            $pos_stmt->bind_result($position);
            $pos_stmt->fetch();
            $pos_stmt->close();
            
            if (!empty($position)):
                $tests['passed']++;
            ?>
                <div class="test-row pass">
                    <i class="fas fa-briefcase status-ok"></i> Position: <strong><?= htmlspecialchars($position) ?></strong>
                </div>
            <?php else: 
                $tests['warnings']++;
            ?>
                <div class="test-row warn">
                    <i class="fas fa-exclamation-triangle status-warning"></i> No position assigned
                </div>
            <?php endif; ?>

            <?php
            // Check access_right
            $access_query = "SELECT * FROM access_right WHERE staff_id = ? LIMIT 1";
            $access_stmt = $db->prepare($access_query);
            $access_stmt->bind_param("s", $staff_id);
            $access_stmt->execute();
            $access_result = $access_stmt->get_result();
            
            if ($access_result && $access_result->num_rows > 0):
                $access_data = $access_result->fetch_assoc();
                $tests['passed']++;
            ?>
                <div class="test-row pass">
                    <i class="fas fa-key status-ok"></i> Access Rights Found
                    <div class="mt-2">
                        <strong>Assigned Access:</strong> <?= htmlspecialchars($access_data['assigned_access'] ?? 'N/A') ?><br>
                        <strong>Can Create:</strong> <?= $access_data['can_create'] ? 'Yes' : 'No' ?><br>
                        <strong>Can Edit:</strong> <?= $access_data['can_edit'] ? 'Yes' : 'No' ?><br>
                        <strong>Can Delete:</strong> <?= $access_data['can_delete'] ? 'Yes' : 'No' ?>
                    </div>
                </div>
            <?php else: 
                $tests['warnings']++;
            ?>
                <div class="test-row warn">
                    <i class="fas fa-exclamation-triangle status-warning"></i> No access rights configured
                </div>
            <?php endif; $access_stmt->close(); ?>
        </div>
        <?php endif; ?>

        <!-- 4. FILE PERMISSIONS & CONSTANTS -->
        <div class="debug-card">
            <h3 class="section-title"><i class="fas fa-file-code"></i> File System & Constants</h3>
            
            <?php
            // Check WUC_PORTAL constant
            if (defined('WUC_PORTAL')):
                $tests['passed']++;
            ?>
                <div class="test-row pass">
                    <i class="fas fa-check status-ok"></i> WUC_PORTAL constant: <strong>DEFINED</strong>
                </div>
            <?php else: 
                $tests['failed']++;
            ?>
                <div class="test-row fail">
                    <i class="fas fa-times status-error"></i> WUC_PORTAL constant: <strong>NOT DEFINED</strong>
                    <p class="mb-0 mt-2 text-danger">This will prevent direct access to protected files!</p>
                </div>
            <?php endif; ?>

            <?php
            // Check critical files
            $critical_files = [
                'admissions/students.php' => 'Students Management',
                'admissions/includes/session_handler.php' => 'Session Handler',
                'admissions/includes/nav.php' => 'Navigation',
                'includes/nav_unified.php' => 'Unified Navigation',
                'db/connect.php' => 'Database Connection'
            ];
            
            foreach ($critical_files as $file => $desc):
                $fullPath = __DIR__ . '/' . $file;
                if (file_exists($fullPath)):
                    $tests['passed']++;
            ?>
                <div class="test-row pass">
                    <i class="fas fa-file status-ok"></i> <?= htmlspecialchars($desc) ?>
                    <small class="text-muted d-block"><?= htmlspecialchars($file) ?></small>
                </div>
            <?php else: 
                    $tests['failed']++;
            ?>
                <div class="test-row fail">
                    <i class="fas fa-times status-error"></i> <?= htmlspecialchars($desc) ?> - <strong>NOT FOUND</strong>
                    <small class="text-muted d-block"><?= htmlspecialchars($file) ?></small>
                </div>
            <?php endif; endforeach; ?>
        </div>

        <!-- 5. FRONTEND CHECKS -->
        <div class="debug-card">
            <h3 class="section-title"><i class="fas fa-code"></i> Frontend Checks</h3>
            
            <div class="test-row pass">
                <i class="fas fa-check status-ok"></i> JavaScript Enabled
            </div>
            
            <div id="vueTest" class="test-row">
                <i class="fas fa-spinner fa-spin"></i> Testing Vue.js...
            </div>
            
            <div id="axiosTest" class="test-row">
                <i class="fas fa-spinner fa-spin"></i> Testing Axios...
            </div>
            
            <div id="bootstrapTest" class="test-row">
                <i class="fas fa-spinner fa-spin"></i> Testing Bootstrap...
            </div>
        </div>

        <!-- SUMMARY -->
        <div class="debug-card">
            <h3 class="section-title"><i class="fas fa-clipboard-check"></i> Test Summary</h3>
            
            <div class="row text-center">
                <div class="col-md-4">
                    <div class="alert alert-success">
                        <h2><?= $tests['passed'] ?></h2>
                        <p class="mb-0">Passed</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="alert alert-danger">
                        <h2><?= $tests['failed'] ?></h2>
                        <p class="mb-0">Failed</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="alert alert-warning">
                        <h2><?= $tests['warnings'] ?></h2>
                        <p class="mb-0">Warnings</p>
                    </div>
                </div>
            </div>

            <?php if ($tests['failed'] > 0): ?>
                <div class="alert alert-danger mt-3">
                    <h5><i class="fas fa-exclamation-triangle"></i> Action Required</h5>
                    <p>Found <?= $tests['failed'] ?> critical issue(s) that need to be fixed.</p>
                </div>
            <?php elseif ($tests['warnings'] > 0): ?>
                <div class="alert alert-warning mt-3">
                    <h5><i class="fas fa-info-circle"></i> Review Recommended</h5>
                    <p>Found <?= $tests['warnings'] ?> warning(s) that should be reviewed.</p>
                </div>
            <?php else: ?>
                <div class="alert alert-success mt-3">
                    <h5><i class="fas fa-check-circle"></i> All Systems Operational</h5>
                    <p>No critical issues detected. Portal should be functioning correctly.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="text-center text-white mt-4">
            <a href="staff_login.php" class="btn btn-light btn-lg me-2">
                <i class="fas fa-sign-in-alt"></i> Staff Login
            </a>
            <a href="admissions/students.php" class="btn btn-primary btn-lg">
                <i class="fas fa-users"></i> Go to Students
            </a>
        </div>
    </div>

    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Test Vue.js
        setTimeout(() => {
            const vueTest = document.getElementById('vueTest');
            if (typeof Vue !== 'undefined') {
                vueTest.className = 'test-row pass';
                vueTest.innerHTML = '<i class="fas fa-check status-ok"></i> Vue.js 3: <strong>LOADED</strong>';
            } else {
                vueTest.className = 'test-row fail';
                vueTest.innerHTML = '<i class="fas fa-times status-error"></i> Vue.js 3: <strong>NOT LOADED</strong>';
            }
        }, 100);

        // Test Axios
        setTimeout(() => {
            const axiosTest = document.getElementById('axiosTest');
            if (typeof axios !== 'undefined') {
                axiosTest.className = 'test-row pass';
                axiosTest.innerHTML = '<i class="fas fa-check status-ok"></i> Axios: <strong>LOADED</strong>';
            } else {
                axiosTest.className = 'test-row fail';
                axiosTest.innerHTML = '<i class="fas fa-times status-error"></i> Axios: <strong>NOT LOADED</strong>';
            }
        }, 100);

        // Test Bootstrap
        setTimeout(() => {
            const bootstrapTest = document.getElementById('bootstrapTest');
            if (typeof bootstrap !== 'undefined') {
                bootstrapTest.className = 'test-row pass';
                bootstrapTest.innerHTML = '<i class="fas fa-check status-ok"></i> Bootstrap 5: <strong>LOADED</strong>';
            } else {
                bootstrapTest.className = 'test-row fail';
                bootstrapTest.innerHTML = '<i class="fas fa-times status-error"></i> Bootstrap 5: <strong>NOT LOADED</strong>';
            }
        }, 100);
    </script>
</body>
</html>

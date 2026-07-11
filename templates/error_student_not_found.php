<?php
// templates/error_student_not_found.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Not Found - ITC Portal</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .error-container { background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; text-align: center; }
        .error-icon { font-size: 60px; margin-bottom: 20px; }
        h1 { color: #dc3545; margin: 0 0 15px; font-size: 24px; }
        p { color: #666; line-height: 1.6; margin: 10px 0; }
        .actions { margin-top: 25px; }
        .btn { display: inline-block; padding: 10px 25px; background: #007bff; color: #fff; text-decoration: none; border-radius: 5px; margin: 5px; }
        .btn-secondary { background: #6c757d; }
        code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
        .debug-info { margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px; text-align: left; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">👤❌</div>
        <h1>Student Account Not Found</h1>
        <p>Your student ID <strong><?php echo htmlspecialchars($student_id ?? 'Unknown'); ?></strong> was not found in our records.</p>
        
        <?php if (isset($is_dev_mode) && $is_dev_mode): ?>
        <p>This may happen if:</p>
        <ul style="text-align:left;color:#666;">
            <li>Your account has not been fully registered</li>
            <li>There is a mismatch in student ID records</li>
            <li>Your session has expired</li>
        </ul>
        <?php endif; ?>
        
        <div class="actions">
            <a href="../student_login.php" class="btn">Back to Login</a>
            <a href="mailto:admin@wuc.edu" class="btn btn-secondary">Contact Support</a>
        </div>
        
        <?php if (isset($is_dev_mode) && $is_dev_mode && isset($check_student)): ?>
        <div class="debug-info">
            <h4 style="margin:0 0 10px;color:#856404;">🔧 Debug Info (Dev Mode)</h4>
            <p><strong>Query:</strong> <code><?php echo htmlspecialchars($check_student); ?></code></p>
            <p><strong>DB Error:</strong> <?php echo htmlspecialchars($db->error ?? 'None'); ?></p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

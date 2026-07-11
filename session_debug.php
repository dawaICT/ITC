<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Debug</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Session Debug Information</h1>
    
    <h2>Current Session Data:</h2>
    <pre><?php print_r($_SESSION); ?></pre>
    
    <h2>Session Configuration:</h2>
    <pre>
Session ID: <?php echo session_id(); ?>

Session Name: <?php echo session_name(); ?>

Session Status: <?php echo session_status(); ?> 
(0=PHP_SESSION_NONE, 1=PHP_SESSION_ACTIVE, 2=PHP_SESSION_DISABLED)

Cookie Parameters:
<?php print_r(session_get_cookie_params()); ?>

Session Save Path: <?php echo session_save_path(); ?>
    </pre>
    
    <h2>Test Login:</h2>
    <form action="/wucportal/login_action.php" method="post">
        <input type="hidden" name="test_debug" value="1">
        <input type="hidden" name="username" value="test_admin">
        <input type="hidden" name="password" value="test123">
        <button type="submit">Test Login (Simulate)</button>
    </form>
    
    <script>
        // Check if session cookie exists
        console.log('Session cookie exists:', document.cookie.includes('<?php echo session_name(); ?>'));
        console.log('All cookies:', document.cookie);
    </script>
</body>
</html>
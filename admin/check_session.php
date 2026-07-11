<?php
// Simple session check - run this in browser
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Debug</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .card { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        pre { background: #f0f0f0; padding: 10px; border-radius: 3px; overflow-x: auto; }
        h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .button { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px; }
        .button:hover { background: #0056b3; }
        .button.danger { background: #dc3545; }
        .button.danger:hover { background: #c82333; }
    </style>
</head>
<body>
    <h1>🔍 ITC Portal Session Debug</h1>
    
    <div class="card">
        <h2>1. Session Status</h2>
        <?php if (empty($_SESSION)): ?>
            <p class="error">❌ No active session - You need to login first!</p>
            <a href="../index.php" class="button">Go to Login</a>
        <?php else: ?>
            <p class="success">✅ Session is active</p>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h2>2. Session Data</h2>
        <pre><?php print_r($_SESSION); ?></pre>
    </div>
    
    <div class="card">
        <h2>3. Critical Session Variables</h2>
        <?php
        $critical = [
            'staff_id' => $_SESSION['staff_id'] ?? null,
            'role' => $_SESSION['role'] ?? null,
            'role_raw' => $_SESSION['role_raw'] ?? null,
        ];
        
        foreach ($critical as $key => $value) {
            if ($value === null) {
                echo "<p class='error'>❌ $key: NOT SET</p>";
            } else {
                echo "<p class='success'>✅ $key: " . htmlspecialchars($value) . "</p>";
            }
        }
        ?>
    </div>
    
    <?php if (isset($_SESSION['staff_id'])): ?>
    <div class="card">
        <h2>4. Database Check for Current User</h2>
        <?php
        require_once(__DIR__ . '/../db/connect.php');
        
        $staffId = $_SESSION['staff_id'];
        $query = "SELECT ar.*, s.fname, s.lname FROM access_right ar LEFT JOIN staff s ON ar.staff_id = s.staff_id WHERE ar.staff_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo "<p class='success'>✅ User found in database</p>";
            echo "<p><strong>Name:</strong> " . htmlspecialchars($row['fname'] . ' ' . $row['lname']) . "</p>";
            echo "<p><strong>Staff ID:</strong> " . htmlspecialchars($row['staff_id']) . "</p>";
            echo "<p><strong>Role from DB:</strong> " . htmlspecialchars($row['assigned_access']) . "</p>";
            
            $roleNorm = strtolower(trim($row['assigned_access']));
            $isAdmin = in_array($roleNorm, ['systems admin', 'admin']);
            echo "<p><strong>Is Admin:</strong> " . ($isAdmin ? "<span class='success'>YES ✅</span>" : "<span class='error'>NO ❌</span>") . "</p>";
        } else {
            echo "<p class='error'>❌ User not found in database!</p>";
        }
        ?>
    </div>
    
    <div class="card">
        <h2>5. Test Role Function</h2>
        <?php
        require_once(__DIR__ . '/../admin/includes/admin.php');
        $adminCheck = isAdmin();
        echo "<p><strong>isAdmin() function:</strong> " . ($adminCheck ? "<span class='success'>TRUE ✅</span>" : "<span class='error'>FALSE ❌</span>") . "</p>";
        ?>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <h2>6. Diagnosis & Actions</h2>
        <?php
        $issues = [];
        $actions = [];
        
        if (empty($_SESSION)) {
            $issues[] = "No session active";
            $actions[] = '<a href="../index.php" class="button">Login to Admin Panel</a>';
        } else {
            if (!isset($_SESSION['staff_id'])) {
                $issues[] = "staff_id not in session";
                $actions[] = '<a href="../index.php" class="button danger">Logout & Login Again</a>';
            }
            
            if (!isset($_SESSION['role'])) {
                $issues[] = "role not in session (needs fresh login to set)";
                $actions[] = '<a href="../index.php" class="button danger">Logout & Login Again</a>';
            }
            
            if (isset($_SESSION['role']) && $_SESSION['role'] !== 'systems_admin') {
                $issues[] = "User is not admin (role: " . $_SESSION['role'] . ")";
            }
        }
        
        if (empty($issues)) {
            echo "<p class='success'>✅ Everything looks good!</p>";
            echo "<p>If you still can't access courses, the issue might be in the courses.php page itself.</p>";
            echo '<a href="../admin/courses.php" class="button">Test Courses Page</a>';
            echo '<a href="../admin/programs.php" class="button">Test Programs Page</a>';
        } else {
            echo "<p class='error'><strong>Issues Found:</strong></p><ul>";
            foreach ($issues as $issue) {
                echo "<li>" . htmlspecialchars($issue) . "</li>";
            }
            echo "</ul>";
            
            echo "<p><strong>Recommended Actions:</strong></p>";
            foreach (array_unique($actions) as $action) {
                echo $action;
            }
        }
        ?>
    </div>
    
    <div class="card">
        <h2>7. Quick Links</h2>
        <a href="../admin/index.php" class="button">Admin Dashboard</a>
        <a href="../admin/courses.php" class="button">Courses Page</a>
        <a href="../admin/programs.php" class="button">Programs Page</a>
        <a href="?clear=1" class="button danger">Clear Session & Logout</a>
    </div>
</body>
</html>

<?php
if (isset($_GET['clear'])) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}
?>

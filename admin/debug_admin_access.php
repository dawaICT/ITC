<?php
require "includes/admin.php";

// Set page title
$page_title = "Admin Access Debug";

require "includes/header.php";
echo '<div class="container-fluid px-4 mt-4">';
echo '<h2>Admin Access & Database Debug</h2>';
echo '<div class="alert alert-info">Debugging admin access and database structure</div>';

// 1. Check current session
echo '<div class="card mb-3">';
echo '<div class="card-header bg-primary text-white"><h5>1. Current Session Information</h5></div>';
echo '<div class="card-body"><pre>';
echo "Staff ID: " . ($_SESSION['staff_id'] ?? 'NOT SET') . "\n";
echo "Role (canonical): " . ($_SESSION['role'] ?? 'NOT SET') . "\n";
echo "Role (raw): " . ($_SESSION['role_raw'] ?? 'NOT SET') . "\n";
echo "Is Admin (function): " . (isAdmin() ? 'YES' : 'NO') . "\n";
echo "Session Keys: " . implode(', ', array_keys($_SESSION)) . "\n";
echo '</pre></div></div>';

// 2. Check access_right table for current user
echo '<div class="card mb-3">';
echo '<div class="card-header bg-success text-white"><h5>2. Access Rights Table</h5></div>';
echo '<div class="card-body">';

$staffId = $_SESSION['staff_id'] ?? null;
if ($staffId) {
    $query = "SELECT * FROM access_right WHERE staff_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo '<pre>';
        while ($row = $result->fetch_assoc()) {
            print_r($row);
        }
        echo '</pre>';
    } else {
        echo '<div class="alert alert-danger">No access_right record found for staff_id: ' . htmlspecialchars($staffId) . '</div>';
    }
} else {
    echo '<div class="alert alert-danger">No staff_id in session!</div>';
}
echo '</div></div>';

// 3. Check staff table
echo '<div class="card mb-3">';
echo '<div class="card-header bg-info text-white"><h5>3. Staff Table Record</h5></div>';
echo '<div class="card-body">';

if ($staffId) {
    $query = "SELECT * FROM staff WHERE staff_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo '<pre>';
        while ($row = $result->fetch_assoc()) {
            print_r($row);
        }
        echo '</pre>';
    } else {
        echo '<div class="alert alert-danger">No staff record found!</div>';
    }
}
echo '</div></div>';

// 4. Check courses table
echo '<div class="card mb-3">';
echo '<div class="card-header bg-warning text-dark"><h5>4. Courses Table</h5></div>';
echo '<div class="card-body">';

$tableCheck = $db->query("SHOW TABLES LIKE 'courses'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    echo '<div class="alert alert-success">✓ Courses table exists</div>';
    
    // Count total courses
    $countResult = $db->query("SELECT COUNT(*) as total FROM courses");
    $count = $countResult->fetch_assoc();
    echo '<p><strong>Total courses:</strong> ' . $count['total'] . '</p>';
    
    // Check columns
    $columnsResult = $db->query("SHOW COLUMNS FROM courses");
    echo '<p><strong>Columns:</strong></p><pre>';
    while ($col = $columnsResult->fetch_assoc()) {
        echo $col['Field'] . ' (' . $col['Type'] . ')' . "\n";
    }
    echo '</pre>';
    
    // Show sample courses
    $sampleResult = $db->query("SELECT * FROM courses LIMIT 10");
    if ($sampleResult->num_rows > 0) {
        echo '<p><strong>Sample courses (first 10):</strong></p>';
        echo '<table class="table table-sm table-bordered">';
        $first = true;
        while ($row = $sampleResult->fetch_assoc()) {
            if ($first) {
                echo '<thead><tr>';
                foreach (array_keys($row) as $key) {
                    echo '<th>' . htmlspecialchars($key) . '</th>';
                }
                echo '</tr></thead><tbody>';
                $first = false;
            }
            echo '<tr>';
            foreach ($row as $value) {
                echo '<td>' . htmlspecialchars($value ?? '') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<div class="alert alert-warning">No courses found in table!</div>';
    }
} else {
    echo '<div class="alert alert-danger">✗ Courses table does not exist!</div>';
}
echo '</div></div>';

// 5. Check programs table
echo '<div class="card mb-3">';
echo '<div class="card-header bg-secondary text-white"><h5>5. Programs Table</h5></div>';
echo '<div class="card-body">';

$tableCheck = $db->query("SHOW TABLES LIKE 'programs'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    echo '<div class="alert alert-success">✓ Programs table exists</div>';
    
    $countResult = $db->query("SELECT COUNT(*) as total FROM programs");
    $count = $countResult->fetch_assoc();
    echo '<p><strong>Total programs:</strong> ' . $count['total'] . '</p>';
    
    // Show sample programs
    $sampleResult = $db->query("SELECT program_code, program_name FROM programs LIMIT 10");
    if ($sampleResult->num_rows > 0) {
        echo '<p><strong>Sample programs:</strong></p><ul>';
        while ($row = $sampleResult->fetch_assoc()) {
            echo '<li>' . htmlspecialchars($row['program_code']) . ' - ' . htmlspecialchars($row['program_name']) . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<div class="alert alert-warning">No programs found in table!</div>';
    }
} else {
    echo '<div class="alert alert-danger">✗ Programs table does not exist!</div>';
}
echo '</div></div>';

// 6. Test role mapping
echo '<div class="card mb-3">';
echo '<div class="card-header bg-dark text-white"><h5>6. Role Mapping Test</h5></div>';
echo '<div class="card-body">';

if ($staffId) {
    $query = "SELECT assigned_access FROM access_right WHERE staff_id = ? LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $rawRole = $result['assigned_access'] ?? null;
    
    echo '<pre>';
    echo "Raw Role from DB: " . ($rawRole ?: 'NULL') . "\n";
    echo "Lowercase Trimmed: " . strtolower(trim((string)$rawRole)) . "\n";
    
    $roleNorm = strtolower(trim((string)$rawRole));
    $map = [
        'systems admin' => 'systems_admin',
        'admin' => 'systems_admin',
        'lecturer' => 'lecturer',
        'assistant lecturer' => 'lecturer',
        'part time lecturer' => 'lecturer',
        'part-time lecturer' => 'lecturer',
        'tutor' => 'lecturer',
        'instructor' => 'lecturer',
        'head of department' => 'head_of_department',
        'dean' => 'dean',
        'registrar' => 'registrar',
        'admission officer' => 'admission_officer',
        'accountant' => 'accountant',
    ];
    
    $roleCanon = $map[$roleNorm] ?? $roleNorm;
    echo "Mapped Role: " . $roleCanon . "\n";
    echo "Is Systems Admin: " . ($roleCanon === 'systems_admin' ? 'YES' : 'NO') . "\n";
    echo '</pre>';
}
echo '</div></div>';

// 7. Check courses.php access
echo '<div class="card mb-3">';
echo '<div class="card-header bg-primary text-white"><h5>7. Test Navigation Links</h5></div>';
echo '<div class="card-body">';
echo '<p>Try accessing these pages:</p>';
echo '<ul>';
echo '<li><a href="courses.php" target="_blank">courses.php</a></li>';
echo '<li><a href="programs.php" target="_blank">programs.php</a></li>';
echo '<li><a href="staff.php" target="_blank">staff.php</a></li>';
echo '<li><a href="students_by_admin.php" target="_blank">students_by_admin.php</a></li>';
echo '<li><a href="elearning/sessions.php" target="_blank">elearning/sessions.php</a></li>';
echo '</ul>';
echo '</div></div>';

// 8. Recommendations
echo '<div class="card mb-3">';
echo '<div class="card-header bg-danger text-white"><h5>8. Issues & Recommendations</h5></div>';
echo '<div class="card-body">';
echo '<ul>';

$issues = [];

// Check if role is set
if (!isset($_SESSION['role'])) {
    $issues[] = "⚠️ Role not set in session - logout and login again to fix";
}

// Check if role is admin
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'systems_admin') {
    $issues[] = "⚠️ Role is '" . $_SESSION['role'] . "' not 'systems_admin' - check access_right table";
}

// Check if courses exist
$countResult = $db->query("SELECT COUNT(*) as total FROM courses");
if ($countResult) {
    $count = $countResult->fetch_assoc();
    if ($count['total'] == 0) {
        $issues[] = "⚠️ No courses in database - add courses or import sample data";
    }
}

// Check if programs exist
$countResult = $db->query("SELECT COUNT(*) as total FROM programs");
if ($countResult) {
    $count = $countResult->fetch_assoc();
    if ($count['total'] == 0) {
        $issues[] = "⚠️ No programs in database - add programs or import sample data";
    }
}

if (empty($issues)) {
    echo '<div class="alert alert-success">✓ No issues detected! Admin access should be working.</div>';
} else {
    foreach ($issues as $issue) {
        echo '<li>' . $issue . '</li>';
    }
}

echo '</ul>';
echo '<div class="mt-3">';
echo '<h6>Quick Actions:</h6>';
echo '<a href="?action=clear_session" class="btn btn-warning me-2">Clear Session & Re-login</a>';
echo '<a href="index.php" class="btn btn-primary">Back to Dashboard</a>';
echo '</div>';
echo '</div></div>';

echo '</div>'; // container

// Handle actions
if (isset($_GET['action']) && $_GET['action'] === 'clear_session') {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

require "includes/footer.php";
?>

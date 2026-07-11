<?php
// Database Debug Script for Staff Management System
require_once(__DIR__ . "/includes/admin.php");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Debug - Staff Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .debug-section { margin-bottom: 30px; }
        .table-responsive { max-height: 400px; overflow-y: auto; }
        .alert { border-radius: 8px; }
        .debug-header { background: linear-gradient(135deg, #a78bfa 0%, #c084fc 100%); color: white; padding: 15px; border-radius: 8px 8px 0 0; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="debug-header">
                        <h2 class="mb-0">
                            <i class="fas fa-database me-2"></i>Database Debug - Staff Management System
                        </h2>
                    </div>
                    <div class="card-body">

                        <!-- Database Connection Status -->
                        <div class="debug-section">
                            <h4><i class="fas fa-plug me-2"></i>Database Connection</h4>
                            <div class="alert alert-<?php echo $db->ping() ? 'success' : 'danger'; ?>">
                                <strong>Status:</strong> <?php echo $db->ping() ? 'Connected' : 'Disconnected'; ?><br>
                                <strong>Host:</strong> <?php echo $db_host; ?><br>
                                <strong>Database:</strong> <?php echo $db_name; ?><br>
                                <strong>MySQL Version:</strong> <?php echo $db->server_info; ?>
                            </div>
                        </div>

                        <!-- Tables Check -->
                        <div class="debug-section">
                            <h4><i class="fas fa-table me-2"></i>Tables Status</h4>
                            <?php
                            $required_tables = ['staff', 'departments', 'users'];
                            foreach ($required_tables as $table) {
                                $table_check = $db->query("SHOW TABLES LIKE '$table'");
                                $exists = $table_check->num_rows > 0;
                                echo "<div class='alert alert-" . ($exists ? 'success' : 'warning') . "'>";
                                echo "<strong>$table:</strong> " . ($exists ? 'Exists' : 'Missing');
                                if ($exists) {
                                    $count_result = $db->query("SELECT COUNT(*) as count FROM $table");
                                    $count = $count_result->fetch_assoc()['count'];
                                    echo " ($count records)";
                                }
                                echo "</div>";
                            }
                            ?>
                        </div>

                        <!-- Staff Table Structure -->
                        <div class="debug-section">
                            <h4><i class="fas fa-columns me-2"></i>Staff Table Structure</h4>
                            <?php
                            $structure_query = $db->query("DESCRIBE staff");
                            if ($structure_query) {
                                echo "<div class='table-responsive'>";
                                echo "<table class='table table-striped table-hover'>";
                                echo "<thead class='table-dark'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>";
                                echo "<tbody>";
                                while ($col = $structure_query->fetch_assoc()) {
                                    echo "<tr>";
                                    echo "<td><code>{$col['Field']}</code></td>";
                                    echo "<td>{$col['Type']}</td>";
                                    echo "<td>{$col['Null']}</td>";
                                    echo "<td>{$col['Key']}</td>";
                                    echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
                                    echo "<td>{$col['Extra']}</td>";
                                    echo "</tr>";
                                }
                                echo "</tbody></table>";
                                echo "</div>";
                            } else {
                                echo "<div class='alert alert-danger'>Could not read staff table structure: " . $db->error . "</div>";
                            }
                            ?>
                        </div>

                        <!-- Departments Table Structure -->
                        <div class="debug-section">
                            <h4><i class="fas fa-building me-2"></i>Departments Table Structure</h4>
                            <div class="alert alert-info">
                                <strong>Confirmed Column Name:</strong> <code>department_name</code><br>
                                This is the correct column name used for department names in the system.
                            </div>
                            <?php
                            $dept_structure_query = $db->query("DESCRIBE departments");
                            if ($dept_structure_query) {
                                echo "<div class='table-responsive'>";
                                echo "<table class='table table-striped table-hover'>";
                                echo "<thead class='table-dark'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>";
                                echo "<tbody>";
                                while ($col = $dept_structure_query->fetch_assoc()) {
                                    $highlight = ($col['Field'] === 'department_name') ? 'class="table-success"' : '';
                                    echo "<tr {$highlight}>";
                                    echo "<td><code>{$col['Field']}</code>" . (($col['Field'] === 'department_name') ? ' <span class="badge bg-success">USED</span>' : '') . "</td>";
                                    echo "<td>{$col['Type']}</td>";
                                    echo "<td>{$col['Null']}</td>";
                                    echo "<td>{$col['Key']}</td>";
                                    echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
                                    echo "<td>{$col['Extra']}</td>";
                                    echo "</tr>";
                                }
                                echo "</tbody></table>";
                                echo "</div>";
                            } else {
                                echo "<div class='alert alert-danger'>Could not read departments table structure: " . $db->error . "</div>";
                            }
                            ?>
                        </div>

                        <!-- Sample Data -->
                        <div class="debug-section">
                            <h4><i class="fas fa-list me-2"></i>Sample Data</h4>

                            <!-- Staff Data -->
                            <h5>Staff Records (First 5)</h5>
                            <?php
                            $staff_sample = $db->query("SELECT staff.staff_id, staff.Fname, staff.Lname, staff.sex, staff.email, departments.department_name as deptName
                                                      FROM staff
                                                      LEFT JOIN departments ON staff.deptId = departments.deptId
                                                      LIMIT 5");
                            if ($staff_sample && $staff_sample->num_rows > 0) {
                                echo "<div class='table-responsive'>";
                                echo "<table class='table table-striped table-hover'>";
                                echo "<thead class='table-primary'><tr><th>ID</th><th>Name</th><th>Gender</th><th>Email</th><th>Department</th></tr></thead>";
                                echo "<tbody>";
                                while ($row = $staff_sample->fetch_assoc()) {
                                    echo "<tr>";
                                    echo "<td><code>{$row['staff_id']}</code></td>";
                                    echo "<td>{$row['Fname']} {$row['Lname']}</td>";
                                    echo "<td>{$row['sex']}</td>";
                                    echo "<td>{$row['email']}</td>";
                                    echo "<td>{$row['deptName']}</td>";
                                    echo "</tr>";
                                }
                                echo "</tbody></table>";
                                echo "</div>";
                            } else {
                                echo "<div class='alert alert-warning'>No staff records found or query failed.</div>";
                            }
                            ?>

                            <!-- Departments Data -->
                            <h5 class="mt-4">Departments (All)</h5>
                            <?php
                            $dept_sample = $db->query("SELECT deptId, department_name as deptName FROM departments ORDER BY department_name");
                            if ($dept_sample && $dept_sample->num_rows > 0) {
                                echo "<div class='table-responsive'>";
                                echo "<table class='table table-striped table-hover'>";
                                echo "<thead class='table-info'><tr><th>ID</th><th>Department Name</th></tr></thead>";
                                echo "<tbody>";
                                while ($row = $dept_sample->fetch_assoc()) {
                                    echo "<tr>";
                                    echo "<td><code>{$row['deptId']}</code></td>";
                                    echo "<td>{$row['deptName']}</td>";
                                    echo "</tr>";
                                }
                                echo "</tbody></table>";
                                echo "</div>";
                            } else {
                                echo "<div class='alert alert-warning'>No departments found or query failed.</div>";
                            }
                            ?>
                        </div>

                        <!-- Query Performance -->
                        <div class="debug-section">
                            <h4><i class="fas fa-tachometer-alt me-2"></i>Query Performance</h4>
                            <?php
                            $start_time = microtime(true);
                            $test_query = $db->query("SELECT COUNT(*) as total FROM staff");
                            $end_time = microtime(true);

                            $execution_time = ($end_time - $start_time) * 1000; // Convert to milliseconds

                            echo "<div class='alert alert-info'>";
                            echo "<strong>Test Query:</strong> SELECT COUNT(*) FROM staff<br>";
                            echo "<strong>Execution Time:</strong> " . round($execution_time, 2) . " ms<br>";
                            echo "<strong>Result:</strong> " . ($test_query ? $test_query->fetch_assoc()['total'] . " records" : "Failed");
                            echo "</div>";
                            ?>
                        </div>

                        <!-- System Information -->
                        <div class="debug-section">
                            <h4><i class="fas fa-info-circle me-2"></i>System Information</h4>
                            <div class="alert alert-secondary">
                                <strong>PHP Version:</strong> <?php echo phpversion(); ?><br>
                                <strong>MySQLi Version:</strong> <?php echo mysqli_get_client_info(); ?><br>
                                <strong>Server Software:</strong> <?php echo $_SERVER['SERVER_SOFTWARE']; ?><br>
                                <strong>Current User:</strong> <?php echo $_SESSION['staff_id'] ?? 'Not logged in'; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://kit.fontawesome.com/your-kit-code.js" crossorigin="anonymous"></script>
</body>
</html>

<?php
// Clean up
$db->close();
?>

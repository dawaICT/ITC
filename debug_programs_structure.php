<?php
/**
 * Debug script to check and analyze the programs data structure
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once "db/connect.php";

echo "<h1>Programs Data Structure Diagnostic Report</h1>";
echo "<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    h1, h2, h3 { color: #333; }
    .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .success { color: #28a745; font-weight: bold; }
    .error { color: #dc3545; font-weight: bold; }
    .warning { color: #ffc107; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
    th { background: #f8f9fa; font-weight: 600; }
    tr:nth-child(even) { background: #f8f9fa; }
    .code { background: #f4f4f4; padding: 10px; border-left: 3px solid #007bff; margin: 10px 0; font-family: monospace; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; }
    .badge-success { background: #28a745; color: white; }
    .badge-danger { background: #dc3545; color: white; }
    .badge-warning { background: #ffc107; color: black; }
</style>";

$issues = [];
$fixes = [];

// 1. Check if programs table exists
echo "<div class='section'>";
echo "<h2>1. Programs Table Check</h2>";
$result = $db->query("SHOW TABLES LIKE 'programs'");
if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Programs table exists</p>";
    
    // Get table structure
    echo "<h3>Current Structure:</h3>";
    echo "<table>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    $structure = $db->query("DESCRIBE programs");
    $columns = [];
    while ($row = $structure->fetch_assoc()) {
        $columns[] = $row['Field'];
        echo "<tr>";
        echo "<td><strong>{$row['Field']}</strong></td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "<td>{$row['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check for required columns
    $required = [
        'program_code' => 'Primary identifier',
        'program_name' => 'Program name',
        'program_type' => 'Type (degree/diploma/certificate)',
        'study_mode' => 'Study mode (semester/term)',
        'program_duration' => 'Duration in months',
        'department_id' => 'Foreign key to departments',
        'is_active' => 'Active status',
        'program_description' => 'Description'
    ];
    
    echo "<h3>Required Columns Check:</h3>";
    echo "<table>";
    echo "<tr><th>Column</th><th>Purpose</th><th>Status</th></tr>";
    foreach ($required as $col => $purpose) {
        $exists = in_array($col, $columns);
        $status = $exists ? "<span class='badge badge-success'>EXISTS</span>" : "<span class='badge badge-danger'>MISSING</span>";
        echo "<tr><td><strong>$col</strong></td><td>$purpose</td><td>$status</td></tr>";
        if (!$exists) {
            $issues[] = "Missing column: programs.$col";
        }
    }
    echo "</table>";
    
    // Check for legacy columns
    $legacy = ['period_type', 'duration_months'];
    $found_legacy = array_intersect($legacy, $columns);
    if (!empty($found_legacy)) {
        echo "<p class='warning'>⚠ Legacy columns found: " . implode(', ', $found_legacy) . "</p>";
        $issues[] = "Legacy columns present: " . implode(', ', $found_legacy);
    }
    
} else {
    echo "<p class='error'>✗ Programs table does NOT exist!</p>";
    $issues[] = "Programs table missing";
}
echo "</div>";

// 2. Check departments table
echo "<div class='section'>";
echo "<h2>2. Departments Table Check</h2>";
$result = $db->query("SHOW TABLES LIKE 'departments'");
if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Departments table exists</p>";
    
    echo "<h3>Current Structure:</h3>";
    echo "<table>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    
    $structure = $db->query("DESCRIBE departments");
    $dept_columns = [];
    while ($row = $structure->fetch_assoc()) {
        $dept_columns[] = $row['Field'];
        echo "<tr>";
        echo "<td><strong>{$row['Field']}</strong></td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check for department count
    $count_result = $db->query("SELECT COUNT(*) as count FROM departments");
    $count = $count_result->fetch_assoc()['count'];
    echo "<p><strong>Total Departments:</strong> $count</p>";
    
    if ($count == 0) {
        echo "<p class='warning'>⚠ No departments found in the database</p>";
        $issues[] = "No departments in database";
    } else {
        echo "<h3>Sample Departments:</h3>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Code</th></tr>";
        $sample = $db->query("SELECT * FROM departments LIMIT 5");
        while ($row = $sample->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['department_name']}</td>";
            echo "<td>" . ($row['deptId'] ?? $row['department_code'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} else {
    echo "<p class='error'>✗ Departments table does NOT exist!</p>";
    $issues[] = "Departments table missing";
}
echo "</div>";

// 3. Check student_program table
echo "<div class='section'>";
echo "<h2>3. Student Program Table Check</h2>";
$result = $db->query("SHOW TABLES LIKE 'student_program'");
if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Student_program table exists</p>";
    
    echo "<h3>Current Structure:</h3>";
    echo "<table>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    
    $structure = $db->query("DESCRIBE student_program");
    while ($row = $structure->fetch_assoc()) {
        echo "<tr>";
        echo "<td><strong>{$row['Field']}</strong></td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check for enrollment count
    $count_result = $db->query("SELECT COUNT(*) as count FROM student_program");
    $count = $count_result->fetch_assoc()['count'];
    echo "<p><strong>Total Enrollments:</strong> $count</p>";
    
} else {
    echo "<p class='error'>✗ Student_program table does NOT exist!</p>";
    $issues[] = "Student_program table missing";
}
echo "</div>";

// 4. Check foreign key relationships
echo "<div class='section'>";
echo "<h2>4. Foreign Key Relationships</h2>";

if ($result = $db->query("SHOW TABLES LIKE 'programs'")) {
    if ($result->num_rows > 0) {
        // Check programs -> departments relationship
        echo "<h3>Programs → Departments Relationship:</h3>";
        $fk_check = $db->query("
            SELECT 
                p.program_code,
                p.program_name,
                p.department_id,
                d.department_name
            FROM programs p
            LEFT JOIN departments d ON p.department_id = d.id
            WHERE p.department_id IS NOT NULL
            LIMIT 5
        ");
        
        if ($fk_check && $fk_check->num_rows > 0) {
            echo "<p class='success'>✓ Programs correctly linked to departments</p>";
            echo "<table>";
            echo "<tr><th>Program Code</th><th>Program Name</th><th>Dept ID</th><th>Department Name</th></tr>";
            while ($row = $fk_check->fetch_assoc()) {
                $dept_status = $row['department_name'] ? 'success' : 'warning';
                echo "<tr>";
                echo "<td>{$row['program_code']}</td>";
                echo "<td>{$row['program_name']}</td>";
                echo "<td>{$row['department_id']}</td>";
                echo "<td class='$dept_status'>" . ($row['department_name'] ?? 'NOT FOUND') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>⚠ No programs linked to departments yet</p>";
        }
        
        // Check for orphaned programs
        $orphaned = $db->query("
            SELECT COUNT(*) as count 
            FROM programs p
            LEFT JOIN departments d ON p.department_id = d.id
            WHERE p.department_id IS NOT NULL AND d.id IS NULL
        ");
        $orphan_count = $orphaned->fetch_assoc()['count'];
        if ($orphan_count > 0) {
            echo "<p class='error'>✗ Found $orphan_count program(s) with invalid department_id</p>";
            $issues[] = "$orphan_count programs with invalid department references";
        }
    }
}
echo "</div>";

// 5. Data consistency checks
echo "<div class='section'>";
echo "<h2>5. Data Consistency Checks</h2>";

if ($result = $db->query("SHOW TABLES LIKE 'programs'")) {
    if ($result->num_rows > 0) {
        // Check for programs with NULL required fields
        $null_check = $db->query("
            SELECT 
                SUM(CASE WHEN program_code IS NULL OR program_code = '' THEN 1 ELSE 0 END) as null_code,
                SUM(CASE WHEN program_name IS NULL OR program_name = '' THEN 1 ELSE 0 END) as null_name,
                SUM(CASE WHEN program_type IS NULL OR program_type = '' THEN 1 ELSE 0 END) as null_type,
                SUM(CASE WHEN department_id IS NULL THEN 1 ELSE 0 END) as null_dept,
                COUNT(*) as total
            FROM programs
        ");
        
        if ($null_check) {
            $nulls = $null_check->fetch_assoc();
            echo "<table>";
            echo "<tr><th>Check</th><th>Records</th><th>Status</th></tr>";
            
            $checks = [
                'null_code' => 'Programs with NULL/empty code',
                'null_name' => 'Programs with NULL/empty name',
                'null_type' => 'Programs with NULL/empty type',
                'null_dept' => 'Programs with NULL department'
            ];
            
            foreach ($checks as $key => $label) {
                $count = $nulls[$key];
                $status = $count == 0 ? "<span class='badge badge-success'>OK</span>" : "<span class='badge badge-danger'>ISSUE</span>";
                echo "<tr><td>$label</td><td>$count / {$nulls['total']}</td><td>$status</td></tr>";
                if ($count > 0) {
                    $issues[] = "$label: $count records";
                }
            }
            echo "</table>";
        }
        
        // Check for duplicate program codes
        $dup_check = $db->query("
            SELECT program_code, COUNT(*) as count 
            FROM programs 
            GROUP BY program_code 
            HAVING count > 1
        ");
        
        if ($dup_check && $dup_check->num_rows > 0) {
            echo "<p class='error'>✗ Found duplicate program codes:</p>";
            echo "<table><tr><th>Program Code</th><th>Count</th></tr>";
            while ($row = $dup_check->fetch_assoc()) {
                echo "<tr><td>{$row['program_code']}</td><td>{$row['count']}</td></tr>";
            }
            echo "</table>";
            $issues[] = "Duplicate program codes found";
        } else {
            echo "<p class='success'>✓ No duplicate program codes</p>";
        }
    }
}
echo "</div>";

// 6. Summary and Recommendations
echo "<div class='section'>";
echo "<h2>6. Summary & Recommendations</h2>";

if (empty($issues)) {
    echo "<p class='success' style='font-size: 18px;'>✓ No critical issues found! Data structure appears healthy.</p>";
} else {
    echo "<p class='error' style='font-size: 18px;'>✗ Found " . count($issues) . " issue(s):</p>";
    echo "<ol>";
    foreach ($issues as $issue) {
        echo "<li class='error'>$issue</li>";
    }
    echo "</ol>";
    
    echo "<h3>Recommended Actions:</h3>";
    echo "<div class='code'>";
    echo "<p><strong>Run the schema fix function:</strong></p>";
    echo "<pre>POST to programs.php with action=fix_schema</pre>";
    echo "<p>Or create a fix script to:</p>";
    echo "<ul>";
    echo "<li>Add missing columns to programs table</li>";
    echo "<li>Create missing tables (departments, student_program)</li>";
    echo "<li>Fix orphaned references</li>";
    echo "<li>Clean up duplicate records</li>";
    echo "</ul>";
    echo "</div>";
}

echo "</div>";

// 7. Quick Fix Generator
echo "<div class='section'>";
echo "<h2>7. Auto-Fix SQL Generator</h2>";
echo "<p>Copy and execute these SQL statements to fix the issues:</p>";
echo "<div class='code'>";

$sql_fixes = [];

// Check if programs table needs columns
if ($result = $db->query("SHOW TABLES LIKE 'programs'")) {
    if ($result->num_rows > 0) {
        $structure = $db->query("DESCRIBE programs");
        $existing_cols = [];
        while ($row = $structure->fetch_assoc()) {
            $existing_cols[] = $row['Field'];
        }
        
        $needed_columns = [
            'program_type' => "ALTER TABLE programs ADD COLUMN program_type ENUM('degree','diploma','certificate') NOT NULL DEFAULT 'degree' AFTER program_name;",
            'study_mode' => "ALTER TABLE programs ADD COLUMN study_mode ENUM('semester','term') NOT NULL DEFAULT 'semester' AFTER program_type;",
            'program_duration' => "ALTER TABLE programs ADD COLUMN program_duration DECIMAL(5,2) DEFAULT NULL AFTER study_mode;",
            'program_description' => "ALTER TABLE programs ADD COLUMN program_description TEXT AFTER program_duration;",
            'department_id' => "ALTER TABLE programs ADD COLUMN department_id INT(11) DEFAULT NULL AFTER program_description;",
            'is_active' => "ALTER TABLE programs ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER department_id;"
        ];
        
        foreach ($needed_columns as $col => $sql) {
            if (!in_array($col, $existing_cols)) {
                $sql_fixes[] = $sql;
            }
        }
    }
}

// Check if departments table exists
$result = $db->query("SHOW TABLES LIKE 'departments'");
if ($result->num_rows == 0) {
    $sql_fixes[] = "CREATE TABLE departments (
    id INT(11) NOT NULL AUTO_INCREMENT,
    department_name VARCHAR(255) NOT NULL,
    deptId VARCHAR(50) DEFAULT NULL,
    department_code VARCHAR(50) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY deptId (deptId),
    UNIQUE KEY department_code (department_code)
);";
    $sql_fixes[] = "INSERT INTO departments (department_name, deptId, department_code) VALUES 
    ('Computer Science', 'CS', 'COMP001'),
    ('Mathematics', 'MATH', 'MATH001'),
    ('Physics', 'PHYS', 'PHYS001'),
    ('Business Administration', 'BUS', 'BUS001'),
    ('Engineering', 'ENG', 'ENG001');";
}

// Check if student_program table exists
$result = $db->query("SHOW TABLES LIKE 'student_program'");
if ($result->num_rows == 0) {
    $sql_fixes[] = "CREATE TABLE student_program (
    id INT(11) NOT NULL AUTO_INCREMENT,
    student_id VARCHAR(50) NOT NULL,
    program_code VARCHAR(50) NOT NULL,
    enrollment_date DATE DEFAULT CURDATE(),
    status ENUM('active','inactive','completed','suspended') DEFAULT 'active',
    PRIMARY KEY (id),
    KEY student_id (student_id),
    KEY program_code (program_code)
);";
}

if (empty($sql_fixes)) {
    echo "<p class='success'>No SQL fixes needed - structure is complete!</p>";
} else {
    echo "<pre style='background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
    foreach ($sql_fixes as $fix) {
        echo htmlspecialchars($fix) . "\n\n";
    }
    echo "</pre>";
    
    echo "<button onclick='copyFixes()' style='padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 10px;'>
        Copy All SQL
    </button>";
    
    echo "<script>
    function copyFixes() {
        const sql = `" . implode("\n\n", array_map('addslashes', $sql_fixes)) . "`;
        navigator.clipboard.writeText(sql).then(() => {
            alert('SQL copied to clipboard!');
        });
    }
    </script>";
}

echo "</div>";
echo "</div>";

$db->close();
?>

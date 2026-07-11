<?php
/**
 * Comprehensive fix script for programs data structure
 * Addresses all identified issues from diagnostic report
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once "db/connect.php";

echo "<h1>Programs Structure Fix Script</h1>";
echo "<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    h1, h2, h3 { color: #333; }
    .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .success { color: #28a745; font-weight: bold; }
    .error { color: #dc3545; font-weight: bold; }
    .warning { color: #ffc107; font-weight: bold; }
    .info { color: #17a2b8; font-weight: bold; }
    ul { line-height: 1.8; }
    .code { background: #f4f4f4; padding: 10px; border-left: 3px solid #007bff; margin: 10px 0; font-family: monospace; font-size: 14px; }
</style>";

$fixes_applied = [];
$errors = [];

// Begin transaction for safety
$db->begin_transaction();

try {
    echo "<div class='section'>";
    echo "<h2>Step 1: Add Missing program_duration Column</h2>";
    
    // Check if program_duration exists
    $check = $db->query("SHOW COLUMNS FROM programs LIKE 'program_duration'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE programs ADD COLUMN program_duration DECIMAL(4,1) DEFAULT NULL COMMENT 'Duration in years' AFTER study_mode";
        if ($db->query($sql)) {
            echo "<p class='success'>✓ Added program_duration column (in years)</p>";
            $fixes_applied[] = "Added program_duration column (years)";
            
            // Migrate data from duration_months if it exists (convert to years)
            $check_old = $db->query("SHOW COLUMNS FROM programs LIKE 'duration_months'");
            if ($check_old->num_rows > 0) {
                $migrate = $db->query("UPDATE programs SET program_duration = ROUND(duration_months / 12, 1) WHERE duration_months IS NOT NULL");
                if ($migrate) {
                    $affected = $db->affected_rows;
                    echo "<p class='info'>→ Migrated $affected records from duration_months to program_duration (converted to years)</p>";
                    $fixes_applied[] = "Migrated $affected duration values from months to years";
                }
            }
        } else {
            throw new Exception("Failed to add program_duration: " . $db->error);
        }
    } else {
        echo "<p class='info'>→ program_duration column already exists</p>";
    }
    echo "</div>";

    echo "<div class='section'>";
    echo "<h2>Step 2: Handle Legacy Columns</h2>";
    
    // Check for duplicate columns (study_mode vs period_mode)
    $has_period_mode = false;
    $check = $db->query("SHOW COLUMNS FROM programs LIKE 'period_mode'");
    if ($check->num_rows > 0) {
        $has_period_mode = true;
        echo "<p class='warning'>⚠ Found legacy period_mode column</p>";
        
        // Sync data from period_mode to study_mode if study_mode is empty
        $sync = $db->query("UPDATE programs SET study_mode = period_mode WHERE (study_mode IS NULL OR study_mode = '') AND period_mode IS NOT NULL");
        if ($sync) {
            $affected = $db->affected_rows;
            echo "<p class='info'>→ Synchronized $affected records from period_mode to study_mode</p>";
            $fixes_applied[] = "Synchronized period_mode to study_mode";
        }
    }
    
    // Check for term_based column
    $has_term_based = false;
    $check = $db->query("SHOW COLUMNS FROM programs LIKE 'term_based'");
    if ($check->num_rows > 0) {
        $has_term_based = true;
        echo "<p class='warning'>⚠ Found legacy term_based column</p>";
        
        // Convert term_based (0/1) to study_mode (semester/term)
        $convert = $db->query("UPDATE programs SET study_mode = CASE WHEN term_based = 1 THEN 'term' ELSE 'semester' END WHERE study_mode IS NULL OR study_mode = ''");
        if ($convert) {
            $affected = $db->affected_rows;
            echo "<p class='info'>→ Converted $affected records from term_based to study_mode</p>";
            $fixes_applied[] = "Converted term_based flags to study_mode";
        }
    }
    
    echo "<p class='info'>→ Legacy columns preserved for backward compatibility</p>";
    echo "<p class='info'>→ Recommend dropping them after verifying all data is migrated</p>";
    echo "</div>";

    echo "<div class='section'>";
    echo "<h2>Step 3: Fix Programs with NULL Departments</h2>";
    
    // Get count of programs with null departments
    $null_dept_result = $db->query("SELECT COUNT(*) as count FROM programs WHERE department_id IS NULL");
    $null_count = $null_dept_result->fetch_assoc()['count'];
    
    if ($null_count > 0) {
        echo "<p class='warning'>⚠ Found $null_count programs with NULL department_id</p>";
        
        // Create a default "Unassigned" department if it doesn't exist
        $check_default = $db->query("SELECT id FROM departments WHERE department_name = 'Unassigned' OR deptId = 'UNASSIGNED'");
        
        if ($check_default->num_rows == 0) {
            $create_default = $db->query("INSERT INTO departments (department_name, deptId, department_code) VALUES ('Unassigned', 'UNASSIGNED', 'UNASSIGNED')");
            if ($create_default) {
                $default_dept_id = $db->insert_id;
                echo "<p class='success'>✓ Created 'Unassigned' department (ID: $default_dept_id)</p>";
                $fixes_applied[] = "Created default 'Unassigned' department";
            } else {
                throw new Exception("Failed to create default department: " . $db->error);
            }
        } else {
            $default_dept_id = $check_default->fetch_assoc()['id'];
            echo "<p class='info'>→ Using existing 'Unassigned' department (ID: $default_dept_id)</p>";
        }
        
        // Assign null programs to default department
        $assign = $db->query("UPDATE programs SET department_id = $default_dept_id WHERE department_id IS NULL");
        if ($assign) {
            $affected = $db->affected_rows;
            echo "<p class='success'>✓ Assigned $affected programs to 'Unassigned' department</p>";
            $fixes_applied[] = "Assigned $affected programs to default department";
        }
    } else {
        echo "<p class='success'>✓ All programs have departments assigned</p>";
    }
    echo "</div>";

    echo "<div class='section'>";
    echo "<h2>Step 4: Standardize Student Program Table</h2>";
    
    // Check if student_program table uses 'Sid' instead of 'student_id'
    $check_sid = $db->query("SHOW COLUMNS FROM student_program LIKE 'Sid'");
    $check_student_id = $db->query("SHOW COLUMNS FROM student_program LIKE 'student_id'");
    
    if ($check_sid->num_rows > 0 && $check_student_id->num_rows == 0) {
        echo "<p class='warning'>⚠ student_program table uses 'Sid' instead of 'student_id'</p>";
        
        // Add student_id as an alias/copy
        $add_student_id = $db->query("ALTER TABLE student_program ADD COLUMN student_id VARCHAR(50) AFTER id");
        if ($add_student_id) {
            echo "<p class='success'>✓ Added student_id column</p>";
            
            // Copy data from Sid to student_id
            $copy_data = $db->query("UPDATE student_program SET student_id = Sid WHERE student_id IS NULL");
            if ($copy_data) {
                $affected = $db->affected_rows;
                echo "<p class='info'>→ Copied $affected student IDs from Sid to student_id</p>";
                $fixes_applied[] = "Standardized student_program.student_id column";
            }
        }
    } else {
        echo "<p class='success'>✓ student_program table structure is compatible</p>";
    }
    
    // Check if status enum includes all needed values
    $status_check = $db->query("SHOW COLUMNS FROM student_program LIKE 'status'");
    if ($status_check->num_rows > 0) {
        $status_col = $status_check->fetch_assoc();
        $type = $status_col['Type'];
        
        // Check if it needs 'suspended' status
        if (strpos($type, 'suspended') === false) {
            echo "<p class='info'>→ Adding 'suspended' status to enum</p>";
            $alter_enum = $db->query("ALTER TABLE student_program MODIFY COLUMN status ENUM('active','inactive','completed','suspended','withdrawn') DEFAULT 'active'");
            if ($alter_enum) {
                echo "<p class='success'>✓ Updated status enum to include all needed values</p>";
                $fixes_applied[] = "Updated student_program.status enum";
            }
        } else {
            echo "<p class='success'>✓ student_program.status enum is complete</p>";
        }
    }
    echo "</div>";

    echo "<div class='section'>";
    echo "<h2>Step 5: Add Missing Indexes for Performance</h2>";
    
    // Check and add index on programs.department_id if missing
    $indexes = $db->query("SHOW INDEX FROM programs WHERE Column_name = 'department_id'");
    if ($indexes->num_rows == 0) {
        $add_index = $db->query("ALTER TABLE programs ADD INDEX idx_department_id (department_id)");
        if ($add_index) {
            echo "<p class='success'>✓ Added index on programs.department_id</p>";
            $fixes_applied[] = "Added performance index on programs.department_id";
        }
    } else {
        echo "<p class='success'>✓ programs.department_id is indexed</p>";
    }
    
    // Check index on programs.is_active
    $indexes = $db->query("SHOW INDEX FROM programs WHERE Column_name = 'is_active'");
    if ($indexes->num_rows == 0) {
        $add_index = $db->query("ALTER TABLE programs ADD INDEX idx_is_active (is_active)");
        if ($add_index) {
            echo "<p class='success'>✓ Added index on programs.is_active</p>";
            $fixes_applied[] = "Added performance index on programs.is_active";
        }
    } else {
        echo "<p class='success'>✓ programs.is_active is indexed</p>";
    }
    echo "</div>";

    // Commit all changes
    $db->commit();
    
    echo "<div class='section'>";
    echo "<h2>✓ All Fixes Applied Successfully!</h2>";
    
    if (!empty($fixes_applied)) {
        echo "<h3>Changes Made:</h3>";
        echo "<ul>";
        foreach ($fixes_applied as $fix) {
            echo "<li class='success'>$fix</li>";
        }
        echo "</ul>";
    }
    
    echo "<div class='code'>";
    echo "<strong>Next Steps:</strong><br>";
    echo "1. Test the programs.php page to ensure everything works<br>";
    echo "2. Review and manually assign departments to 'Unassigned' programs<br>";
    echo "3. Consider dropping legacy columns after confirming data migration<br>";
    echo "4. Run the diagnostic script again to verify all issues are resolved";
    echo "</div>";
    echo "</div>";

} catch (Exception $e) {
    // Rollback on error
    $db->rollback();
    
    echo "<div class='section'>";
    echo "<h2 class='error'>✗ Error During Fix Process</h2>";
    echo "<p class='error'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p class='warning'>All changes have been rolled back.</p>";
    echo "</div>";
}

$db->close();
?>

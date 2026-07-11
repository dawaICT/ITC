<?php
/**
 * Apply Registration System Database Updates
 * Run this script to create/update all required tables for the registration system
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "<!DOCTYPE html>
<html>
<head>
    <title>Registration System DB Migration</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 50px auto; padding: 20px; }
        .success { color: #28a745; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; margin: 10px 0; }
        .error { color: #dc3545; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 10px 0; }
        .info { color: #004085; padding: 10px; background: #cce5ff; border: 1px solid #b8daff; margin: 10px 0; }
        pre { background: #f4f4f4; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
        h1 { color: #333; }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #007bff; background: #f8f9fa; }
    </style>

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>";

echo "<h1>🔧 Registration System Database Migration</h1>";
echo "<p>This script will create and update all necessary database tables for the comprehensive registration system.</p>";

// Include database connection
require_once __DIR__ . '/../includes/DatabaseConnection.php';

try {
    $dbConnection = DatabaseConnection::getInstance();
    $db = $dbConnection->getMysqli();
    
    if (!$db || $db->connect_errno) {
        throw new Exception("Database connection failed: " . ($db->connect_error ?? "Unknown error"));
    }
    
    echo "<div class='success'>✓ Database connection established successfully</div>";
    
    // Read the SQL migration file
    $sqlFile = __DIR__ . '/registration_system_db_update.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    echo "<div class='info'>📄 Reading migration file: registration_system_db_update.sql</div>";
    
    $sql = file_get_contents($sqlFile);
    
    // Split SQL into individual statements (basic splitting - may need refinement)
    $statements = array_filter(
        array_map('trim', 
            preg_split('/;[\r\n]+/', $sql)
        ),
        function($stmt) {
            // Filter out comments and empty statements
            return !empty($stmt) && 
                   !preg_match('/^--/', $stmt) && 
                   !preg_match('/^\/\*/', $stmt) &&
                   strtoupper(substr($stmt, 0, 6)) !== 'SELECT';
        }
    );
    
    echo "<div class='step'>";
    echo "<h3>📊 Executing " . count($statements) . " SQL statements...</h3>";
    
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        // Extract operation type for display
        preg_match('/^(CREATE|ALTER|INSERT|UPDATE|DROP)\s+/i', $statement, $matches);
        $operation = $matches[1] ?? 'SQL';
        
        try {
            if ($db->query($statement . ';')) {
                $successCount++;
                
                // Show abbreviated statement
                $preview = substr($statement, 0, 80);
                if (strlen($statement) > 80) {
                    $preview .= '...';
                }
                
                echo "<div style='margin: 5px 0; color: #28a745;'>✓ [$operation] $preview</div>";
            } else {
                $errorCount++;
                $errorMsg = $db->error;
                $errors[] = [
                    'statement' => $statement,
                    'error' => $errorMsg
                ];
                
                // Only show error if it's not a "duplicate column" or "table already exists" error
                if (
                    stripos($errorMsg, 'Duplicate column') === false && 
                    stripos($errorMsg, 'already exists') === false &&
                    stripos($errorMsg, 'Duplicate key') === false
                ) {
                    echo "<div style='margin: 5px 0; color: #dc3545;'>✗ [$operation] Error: $errorMsg</div>";
                }
            }
            
        } catch (Exception $e) {
            $errorCount++;
            $errors[] = [
                'statement' => $statement,
                'error' => $e->getMessage()
            ];
            echo "<div style='margin: 5px 0; color: #dc3545;'>✗ Exception: " . $e->getMessage() . "</div>";
        }
    }
    
    echo "</div>";
    
    // Summary
    echo "<div class='step'>";
    echo "<h3>📈 Migration Summary</h3>";
    echo "<p><strong>Total Statements:</strong> " . count($statements) . "</p>";
    echo "<p><strong>Successful:</strong> <span style='color: #28a745;'>$successCount</span></p>";
    echo "<p><strong>Errors:</strong> <span style='color: " . ($errorCount > 0 ? '#dc3545' : '#28a745') . "'>$errorCount</span></p>";
    echo "</div>";
    
    // Verify tables
    echo "<div class='step'>";
    echo "<h3>🔍 Verifying Tables</h3>";
    
    $requiredTables = ['courses', 'student_courses', 'course_registrations', 'invoices'];
    $allTablesExist = true;
    
    foreach ($requiredTables as $table) {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            echo "<div style='color: #28a745; margin: 5px 0;'>✓ Table '$table' exists</div>";
            
            // Show column count
            $colResult = $db->query("SHOW COLUMNS FROM $table");
            if ($colResult) {
                echo "<div style='margin-left: 20px; color: #666;'>↳ Columns: " . $colResult->num_rows . "</div>";
            }
        } else {
            echo "<div style='color: #dc3545; margin: 5px 0;'>✗ Table '$table' is missing!</div>";
            $allTablesExist = false;
        }
    }
    
    echo "</div>";
    
    // Sample data check
    echo "<div class='step'>";
    echo "<h3>📦 Sample Data</h3>";
    
    $courseCount = $db->query("SELECT COUNT(*) as count FROM courses")->fetch_assoc()['count'];
    echo "<p><strong>Courses:</strong> $courseCount</p>";
    
    if ($courseCount > 0) {
        echo "<div class='info'>Sample courses have been added for testing.</div>";
    } else {
        echo "<div class='info'>No sample courses. You may want to add courses manually.</div>";
    }
    
    echo "</div>";
    
    // Final status
    if ($allTablesExist && $errorCount === 0) {
        echo "<div class='success'>";
        echo "<h2>✅ Migration Completed Successfully!</h2>";
        echo "<p>All required tables and columns have been created/updated.</p>";
        echo "<p><strong>Next Steps:</strong></p>";
        echo "<ul>";
        echo "<li>Visit the registration page to test the system</li>";
        echo "<li>Add more courses as needed</li>";
        echo "<li>Configure program-course mappings</li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div class='error'>";
        echo "<h2>⚠️ Migration Completed with Issues</h2>";
        echo "<p>Some tables may be missing or errors occurred. Please review the logs above.</p>";
        echo "</div>";
    }
    
    // Show errors if any
    if (!empty($errors)) {
        echo "<div class='step'>";
        echo "<h3>❌ Detailed Errors</h3>";
        foreach ($errors as $error) {
            echo "<div class='error'>";
            echo "<strong>Statement:</strong><br>";
            echo "<pre>" . htmlspecialchars($error['statement']) . "</pre>";
            echo "<strong>Error:</strong> " . htmlspecialchars($error['error']);
            echo "</div>";
        }
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Fatal Error</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p style='text-align: center; color: #666;'>Migration script completed at " . date('Y-m-d H:i:s') . "</p>";
echo "</body></html>";
?>

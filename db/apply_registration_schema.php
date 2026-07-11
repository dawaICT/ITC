<?php
/**
 * Apply Registration Schema Migration
 * Run this script to apply the new registration database schema
 * 
 * Usage: php db/apply_registration_schema.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "\n";
echo "==============================================\n";
echo "  ITC Portal - Registration Schema Migration  \n";
echo "==============================================\n\n";

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Get the project root directory
$rootDir = dirname(__DIR__);

// Check if schema file exists
$schemaFile = $rootDir . '/db/registration_schema.sql';
if (!file_exists($schemaFile)) {
    die("Error: Schema file not found at: $schemaFile\n");
}

echo "Schema file found: $schemaFile\n\n";

// Database configuration
$dbConfig = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'wucportal',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4'
];

echo "Connecting to database...\n";
echo "Host: {$dbConfig['host']}\n";
echo "Database: {$dbConfig['database']}\n\n";

try {
    // Create PDO connection
    $dsn = sprintf(
        "mysql:host=%s;port=%d;dbname=%s;charset=%s",
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['database'],
        $dbConfig['charset']
    );
    
    $pdo = new PDO(
        $dsn,
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✓ Database connection established\n\n";
    
    // Read SQL file
    echo "Reading schema file...\n";
    $sql = file_get_contents($schemaFile);
    
    if ($sql === false) {
        throw new Exception("Failed to read schema file");
    }
    
    echo "✓ Schema file loaded (" . strlen($sql) . " bytes)\n\n";
    
    // Backup reminder
    echo "==============================================\n";
    echo "  IMPORTANT: Backup Reminder\n";
    echo "==============================================\n";
    echo "Have you backed up your database?\n";
    echo "If not, press Ctrl+C to cancel and run:\n";
    echo "  mysqldump -u root wucportal > backup.sql\n\n";
    
    echo "Press Enter to continue or Ctrl+C to cancel...\n";
    if (php_sapi_name() === 'cli') {
        fgets(STDIN);
    }
    
    echo "\nApplying schema changes...\n";
    echo "==============================================\n\n";
    
    // Split SQL into individual statements
    // Handle DELIMITER changes for stored procedures/triggers
    $sql = str_replace("\r\n", "\n", $sql);
    $sql = str_replace("\r", "\n", $sql);
    
    // Track what we're creating
    $tables = [];
    $views = [];
    $procedures = [];
    $triggers = [];
    $statements = 0;
    $errors = 0;
    
    // Custom parser to handle DELIMITER changes
    $delimiter = ';';
    $tempLine = '';
    $lines = explode("\n", $sql);
    $queries = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if (empty($line) || strpos($line, '--') === 0 || strpos($line, '#') === 0) {
            continue;
        }
        
        // Check for DELIMITER change
        if (stripos($line, 'DELIMITER') === 0) {
            $delimiter = trim(substr($line, 9));
            continue;
        }
        
        $tempLine .= $line . "\n";
        
        // Check if we've reached the end of a statement
        if (substr(trim($tempLine), -strlen($delimiter)) === $delimiter) {
            $query = rtrim($tempLine, $delimiter);
            $query = trim($query);
            
            if (!empty($query)) {
                $queries[] = $query;
            }
            
            $tempLine = '';
        }
    }
    
    // Add any remaining query
    if (!empty(trim($tempLine))) {
        $queries[] = trim($tempLine);
    }
    
    // Execute each query
    foreach ($queries as $query) {
        $query = trim($query);
        if (empty($query)) {
            continue;
        }
        
        try {
            // Detect what we're creating
            $queryUpper = strtoupper($query);
            
            if (strpos($queryUpper, 'CREATE TABLE') !== false) {
                preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $query, $matches);
                if (isset($matches[1])) {
                    $tables[] = $matches[1];
                    echo "Creating table: {$matches[1]}...";
                }
            } elseif (strpos($queryUpper, 'CREATE OR REPLACE VIEW') !== false || strpos($queryUpper, 'CREATE VIEW') !== false) {
                preg_match('/CREATE(?:\s+OR REPLACE)?\s+VIEW\s+`?(\w+)`?/i', $query, $matches);
                if (isset($matches[1])) {
                    $views[] = $matches[1];
                    echo "Creating view: {$matches[1]}...";
                }
            } elseif (strpos($queryUpper, 'CREATE PROCEDURE') !== false) {
                preg_match('/CREATE PROCEDURE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $query, $matches);
                if (isset($matches[1])) {
                    $procedures[] = $matches[1];
                    echo "Creating procedure: {$matches[1]}...";
                }
            } elseif (strpos($queryUpper, 'CREATE TRIGGER') !== false) {
                preg_match('/CREATE TRIGGER(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $query, $matches);
                if (isset($matches[1])) {
                    $triggers[] = $matches[1];
                    echo "Creating trigger: {$matches[1]}...";
                }
            } elseif (strpos($queryUpper, 'INSERT') !== false) {
                echo "Inserting initial data...";
            } else {
                echo "Executing statement...";
            }
            
            $pdo->exec($query);
            echo " ✓\n";
            $statements++;
            
        } catch (PDOException $e) {
            // Check if error is because object already exists
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo " (already exists)\n";
            } else {
                echo " ✗ ERROR\n";
                echo "Error: " . $e->getMessage() . "\n";
                $errors++;
                
                // Continue or stop?
                if ($errors > 5) {
                    echo "\nToo many errors. Stopping.\n";
                    break;
                }
            }
        }
    }
    
    echo "\n==============================================\n";
    echo "  Migration Summary\n";
    echo "==============================================\n";
    echo "Statements executed: $statements\n";
    echo "Errors: $errors\n\n";
    
    if (!empty($tables)) {
        echo "Tables created/verified: " . count($tables) . "\n";
        foreach ($tables as $table) {
            echo "  - $table\n";
        }
        echo "\n";
    }
    
    if (!empty($views)) {
        echo "Views created: " . count($views) . "\n";
        foreach ($views as $view) {
            echo "  - $view\n";
        }
        echo "\n";
    }
    
    if (!empty($procedures)) {
        echo "Stored procedures created: " . count($procedures) . "\n";
        foreach ($procedures as $proc) {
            echo "  - $proc\n";
        }
        echo "\n";
    }
    
    if (!empty($triggers)) {
        echo "Triggers created: " . count($triggers) . "\n";
        foreach ($triggers as $trigger) {
            echo "  - $trigger\n";
        }
        echo "\n";
    }
    
    // Verify key tables exist
    echo "==============================================\n";
    echo "  Verification\n";
    echo "==============================================\n";
    
    $requiredTables = [
        'academic_sessions',
        'semester_registration',
        'course_registration',
        'registration_audit_log',
        'registration_fees',
        'student_id_pool'
    ];
    
    $allExist = true;
    foreach ($requiredTables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        
        echo "Table '$table': " . ($exists ? "✓ EXISTS" : "✗ MISSING") . "\n";
        
        if (!$exists) {
            $allExist = false;
        }
    }
    
    echo "\n";
    
    if ($errors === 0 && $allExist) {
        echo "==============================================\n";
        echo "  ✓ Migration completed successfully!\n";
        echo "==============================================\n";
        echo "\nNext steps:\n";
        echo "1. Test the API endpoints\n";
        echo "2. Visit: http://localhost/wucportal/students/registration_redesigned.php\n";
        echo "3. Review the REGISTRATION_REDESIGN_GUIDE.md for more information\n\n";
    } else {
        echo "==============================================\n";
        echo "  ⚠ Migration completed with issues\n";
        echo "==============================================\n";
        echo "\nPlease review errors above and:\n";
        echo "1. Check your database structure\n";
        echo "2. Ensure you have proper permissions\n";
        echo "3. Verify MySQL version compatibility\n\n";
    }
    
} catch (PDOException $e) {
    echo "\n✗ Database Error:\n";
    echo $e->getMessage() . "\n\n";
    echo "Please check:\n";
    echo "1. MySQL server is running\n";
    echo "2. Database 'wucportal' exists\n";
    echo "3. Database credentials are correct\n";
    echo "4. User has proper permissions\n\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ Error:\n";
    echo $e->getMessage() . "\n\n";
    exit(1);
}

echo "\n";

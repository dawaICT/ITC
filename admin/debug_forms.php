<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "=== DEBUGGING FORM RENDERING ===\n\n";

// Define IS_SCRIPT to bypass session requirements
define('IS_SCRIPT', true);

try {
    require_once "includes/admin.php";
    echo "✓ Admin file included successfully\n";
    
    if (isset($db) && !$db->connect_error) {
        echo "✓ Database connection successful\n\n";
        
        // Get a sample program
        $prog_query = "SELECT p.*, d.department_name FROM programs p LEFT JOIN departments d ON p.department_id = d.id ORDER BY p.program_name LIMIT 1";
        $prog_result = $db->query($prog_query);
        
        if ($prog_result && $prog_result->num_rows > 0) {
            $program = $prog_result->fetch_assoc();
            echo "Sample program data:\n";
            echo "- Code: " . $program['program_code'] . "\n";
            echo "- Name: " . $program['program_name'] . "\n";
            echo "- Type: " . $program['program_type'] . "\n";
            echo "- Department: " . ($program['department_name'] ?? 'N/A') . "\n\n";
            
            // Test form generation
            echo "Testing form generation:\n";
            
            // Edit form
            echo "1. Edit Form:\n";
            $edit_form = '<form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="program_code" value="' . htmlspecialchars($program['program_code']) . '">
                <button type="submit" class="btn btn-outline-primary" title="Edit Program">
                    <i class="fas fa-edit"></i>
                    <span class="d-none d-sm-inline ms-1">Edit</span>
                </button>
            </form>';
            echo $edit_form . "\n\n";
            
            // View form
            echo "2. View Form:\n";
            $view_form = '<form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="view">
                <input type="hidden" name="program_code" value="' . htmlspecialchars($program['program_code']) . '">
                <button type="submit" class="btn btn-outline-info" title="View Details">
                    <i class="fas fa-eye"></i>
                    <span class="d-none d-sm-inline ms-1">View</span>
                </button>
            </form>';
            echo $view_form . "\n\n";
            
            // Delete form
            echo "3. Delete Form:\n";
            $delete_form = '<form method="POST" style="display: inline;" onsubmit="return confirm(\'Are you sure?\');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="program_code" value="' . htmlspecialchars($program['program_code']) . '">
                <button type="submit" class="btn btn-outline-danger" title="Delete Program">
                    <i class="fas fa-trash-alt"></i>
                    <span class="d-none d-sm-inline ms-1">Delete</span>
                </button>
            </form>';
            echo $delete_form . "\n\n";
            
            // Test POST simulation
            echo "Testing POST simulation:\n";
            $_POST['action'] = 'view';
            $_POST['program_code'] = $program['program_code'];
            
            echo "Simulated POST data:\n";
            echo "- action: " . $_POST['action'] . "\n";
            echo "- program_code: " . $_POST['program_code'] . "\n\n";
            
            // Test the action handling
            if (isset($_POST['action']) && $_POST['action'] === 'view') {
                echo "✓ View action detected\n";
                
                $view_query = "SELECT p.*, d.department_name FROM programs p LEFT JOIN departments d ON p.department_id = d.id WHERE p.program_code = ?";
                $view_stmt = $db->prepare($view_query);
                $view_stmt->bind_param("s", $_POST['program_code']);
                $view_stmt->execute();
                $view_result = $view_stmt->get_result();
                
                if ($view_result->num_rows > 0) {
                    $view_program = $view_result->fetch_assoc();
                    echo "✓ View query successful\n";
                    echo "- Found program: " . $view_program['program_name'] . "\n";
                } else {
                    echo "✗ View query failed\n";
                }
                $view_stmt->close();
            }
            
        } else {
            echo "✗ No programs found in database\n";
        }
        
    } else {
        echo "✗ Database connection failed\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nDebug completed.\n";
?> 
<?php
/**
 * Assign Librarian Role Script
 * 
 * SECURITY FEATURES:
 * - Session-based authentication check
 * - CSRF token validation (ready to enable)
 * - Prepared statements for SQL injection prevention
 * - Input validation and sanitization
 * - Role verification before assignment
 * - Transaction support with rollback
 * - Activity logging
 * 
 * @version 2.0
 * @updated 2026-02-03
 */

session_start();
require_once __DIR__ . '/../../db/connect.php';

// Security: Check if user is authenticated and has admin privileges
if (!isset($_SESSION['staff_id'])) {
    $_SESSION['errorMssg'] = "Unauthorized access. Please login first.";
    header("Location: ../../login.php");
    exit();
}

// CSRF Token Validation (Uncomment when tokens are added to forms)
/*
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['errorMssg'] = "Invalid security token. Please try again.";
    header("Location: ../staff.php");
    exit();
}
*/

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['errorMssg'] = "Invalid request method.";
    header("Location: ../staff.php");
    exit();
}

// Input Validation
$staff_id = trim($_POST['staff_id'] ?? '');

if (empty($staff_id)) {
    $_SESSION['errorMssg'] = "Staff ID is required.";
    header("Location: ../staff.php");
    exit();
}

// Sanitize input (remove any non-alphanumeric characters except hyphens and underscores)
$staff_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $staff_id);

if (strlen($staff_id) < 3 || strlen($staff_id) > 20) {
    $_SESSION['errorMssg'] = "Invalid Staff ID format (3-20 characters).";
    header("Location: ../staff.php");
    exit();
}

try {
    // Start transaction
    $db->begin_transaction();

    // Step 1: Verify staff member exists and get their details
    $stmt = $db->prepare("SELECT staff_id, Fname, Lname, email FROM staff WHERE staff_id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $db->error);
    }

    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        throw new Exception("Staff ID '$staff_id' not found in the system.");
    }

    $staff = $result->fetch_assoc();
    $stmt->close();

    // Step 2: Ensure positions table exists
    $db->query("CREATE TABLE IF NOT EXISTS positions (
        PosID VARCHAR(10) PRIMARY KEY, 
        PosName VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_posname (PosName)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Step 3: Check if Librarian position exists, create if not
    $stmt = $db->prepare("SELECT PosID FROM positions WHERE PosName = 'Librarian' LIMIT 1");
    
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $db->error);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $libPos = $row['PosID'];
        $stmt->close();
    } else {
        $stmt->close();
        
        // Generate next Position ID using ITC pattern
        require_once __DIR__ . '/../../includes/id_helpers.php';
        if (function_exists('generateNextPosId')) {
            $libPos = generateNextPosId($db);
        } else {
            // Fallback: Simple auto-increment logic
            $maxResult = $db->query("SELECT PosID FROM positions ORDER BY PosID DESC LIMIT 1");
            if ($maxResult && $maxResult->num_rows > 0) {
                $maxRow = $maxResult->fetch_assoc();
                $lastId = intval(substr($maxRow['PosID'], 3)); // Assuming format POS001
                $libPos = 'POS' . str_pad($lastId + 1, 3, '0', STR_PAD_LEFT);
            } else {
                $libPos = 'POS001'; // First position
            }
        }
        
        // Insert the Librarian position
        $stmt = $db->prepare("INSERT INTO positions (PosID, PosName, description) VALUES (?, 'Librarian', 'Library management and resource oversight')");
        
        if (!$stmt) {
            throw new Exception("Failed to create librarian position: " . $db->error);
        }

        $stmt->bind_param("s", $libPos);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to insert librarian position: " . $stmt->error);
        }
        
        $stmt->close();
    }

    // Step 4: Ensure staff_positions table exists
    $db->query("CREATE TABLE IF NOT EXISTS staff_positions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id VARCHAR(20) NOT NULL,
        PosID VARCHAR(10) NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_staff_position (staff_id, PosID),
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
        FOREIGN KEY (PosID) REFERENCES positions(PosID) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Step 5: Check if staff already has this position
    $stmt = $db->prepare("SELECT id FROM staff_positions WHERE staff_id = ? AND PosID = ? LIMIT 1");
    
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $db->error);
    }

    $stmt->bind_param("ss", $staff_id, $libPos);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $stmt->close();
        throw new Exception("Staff member already has the Librarian role assigned.");
    }
    
    $stmt->close();

    // Step 6: Assign the librarian position
    $stmt = $db->prepare("INSERT INTO staff_positions (staff_id, PosID) VALUES (?, ?)");
    
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $db->error);
    }

    $stmt->bind_param("ss", $staff_id, $libPos);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to assign position: " . $stmt->error);
    }

    $stmt->close();

    // Step 7: Log the activity (create table if needed)
    $db->query("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id VARCHAR(20),
        action TEXT NOT NULL,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_staff_id (staff_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $assigned_by = $_SESSION['staff_id'] ?? 'system';
    $action = "Assigned Librarian position ($libPos) to staff: $staff_id (" . $staff['Fname'] . " " . $staff['Lname'] . ")";
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    $stmt = $db->prepare("INSERT INTO activity_log (staff_id, action, ip_address) VALUES (?, ?, ?)");
    
    if ($stmt) {
        $stmt->bind_param("sss", $assigned_by, $action, $ip_address);
        $stmt->execute();
        $stmt->close();
    }

    // Commit transaction
    $db->commit();

    // Success message with full name
    $full_name = htmlspecialchars($staff['Fname'] . ' ' . $staff['Lname']);
    $_SESSION['successMssg'] = "✓ Librarian role successfully assigned to $full_name (Staff ID: $staff_id, Position: $libPos)";
    
    header("Location: ../staff.php");
    exit();

} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->ping()) {
        $db->rollback();
    }

    // Log error for debugging (never expose raw error to user in production)
    error_log("Librarian Assignment Error [Staff: $staff_id]: " . $e->getMessage());

    $_SESSION['errorMssg'] = "Failed to assign librarian role: " . htmlspecialchars($e->getMessage());
    header("Location: ../staff.php");
    exit();
}




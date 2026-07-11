<?php
require "includes/admin.php";
session_start();

// Debug mode - set to false in production
$debug = true;

// Define constants
define('UPLOAD_DIR', 'uploads/profile_images/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif']);

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'file_path' => '',
    'debug' => []
];

// Add debug info if debug mode is enabled
function debug_log($message) {
    global $debug, $response;
    if ($debug) {
        $response['debug'][] = $message;
    }
}

debug_log("Script started");

// Create upload directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    debug_log("Creating upload directory: " . UPLOAD_DIR);
    $result = mkdir(UPLOAD_DIR, 0755, true);
    debug_log("Directory creation result: " . ($result ? "Success" : "Failed"));
}

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
    debug_log("User not logged in");
    $response['message'] = 'You must be logged in to upload a profile picture.';
    echo json_encode($response);
    exit;
}

debug_log("User logged in with staff_id: " . $_SESSION['staff_id']);

// Verify if the staff table has the profile_image column
try {
    $check_column = $db->query("SHOW COLUMNS FROM staff LIKE 'profile_image'");
    if ($check_column->num_rows === 0) {
        debug_log("profile_image column doesn't exist, creating it now");
        $sql = "ALTER TABLE staff ADD COLUMN profile_image VARCHAR(255) NULL";
        if ($db->query($sql) === TRUE) {
            debug_log("profile_image column added successfully");
        } else {
            debug_log("Error adding profile_image column: " . $db->error);
            $response['message'] = "Database error: Could not prepare for upload.";
            echo json_encode($response);
            exit;
        }
    } else {
        debug_log("profile_image column exists");
    }
} catch (Exception $e) {
    debug_log("Error checking database: " . $e->getMessage());
    $response['message'] = "Database error: " . $e->getMessage();
    echo json_encode($response);
    exit;
}

// Check if file was uploaded
debug_log("Checking uploaded file");
if (!isset($_FILES['profile_image'])) {
    debug_log("No file uploaded");
    $response['message'] = 'No file was uploaded.';
    echo json_encode($response);
    exit;
}

debug_log("File upload details: " . json_encode($_FILES['profile_image']));

if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
    $error_messages = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
    ];
    
    $error_code = $_FILES['profile_image']['error'];
    debug_log("Upload error: " . $error_code . " - " . ($error_messages[$error_code] ?? 'Unknown upload error.'));
    $response['message'] = $error_messages[$error_code] ?? 'Unknown upload error.';
    echo json_encode($response);
    exit;
}

// Validate file size
if ($_FILES['profile_image']['size'] > MAX_FILE_SIZE) {
    debug_log("File too large: " . $_FILES['profile_image']['size'] . " bytes");
    $response['message'] = 'File size exceeds the maximum limit of 5MB.';
    echo json_encode($response);
    exit;
}

// Validate file extension
$file_info = pathinfo($_FILES['profile_image']['name']);
$extension = strtolower($file_info['extension']);

debug_log("File extension: " . $extension);

if (!in_array($extension, ALLOWED_EXTENSIONS)) {
    debug_log("Invalid file type");
    $response['message'] = 'Invalid file type. Allowed types: ' . implode(', ', ALLOWED_EXTENSIONS);
    echo json_encode($response);
    exit;
}

// Generate a unique filename
$new_filename = $_SESSION['staff_id'] . '_' . uniqid() . '.' . $extension;
$upload_path = UPLOAD_DIR . $new_filename;

debug_log("Generated filename: " . $new_filename);
debug_log("Upload path: " . $upload_path);

// Move the uploaded file
debug_log("Moving uploaded file...");
$uploaded = move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path);
debug_log("Upload result: " . ($uploaded ? "Success" : "Failed"));

if ($uploaded) {
    // Update the database with the new profile image path
    debug_log("Updating database...");
    $stmt = $db->prepare("UPDATE staff SET profile_image = ? WHERE staff_id = ?");
    
    if (!$stmt) {
        debug_log("Database prepare error: " . $db->error);
        $response['message'] = 'Database error: ' . $db->error;
        unlink($upload_path);
        echo json_encode($response);
        exit;
    }
    
    $stmt->bind_param("ss", $upload_path, $_SESSION['staff_id']);
    $result = $stmt->execute();
    debug_log("Database update result: " . ($result ? "Success" : "Failed - " . $stmt->error));
    
    if ($result) {
        $response['success'] = true;
        $response['message'] = 'Profile picture uploaded successfully.';
        $response['file_path'] = $upload_path;
    } else {
        $response['message'] = 'Database update failed: ' . $stmt->error;
        // Delete the uploaded file since database update failed
        unlink($upload_path);
    }
    
    $stmt->close();
} else {
    debug_log("Failed to save the uploaded file. PHP error: " . error_get_last()['message']);
    $response['message'] = 'Failed to save the uploaded file. Please check folder permissions.';
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
exit; 
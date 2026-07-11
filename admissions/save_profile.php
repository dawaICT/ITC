<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../db/connect.php';

if (!isset($_SESSION['staff_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
$staff_id = $_SESSION['staff_id'];

// Allowed fields to update
$allowed = [
    'Fname','Lname','email','mobile','address','country','qualification',
    'date_of_birth','secondary_phone','emergency_contact','emergency_phone','position','profile_image'
];

$input = [];
foreach ($allowed as $f) {
    $input[$f] = isset($_POST[$f]) ? trim($_POST[$f]) : null;
}

// Basic server-side validation
$errors = [];
if (!empty($input['email']) && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email format';
}
if (!empty($input['date_of_birth'])) {
    $d = date_parse($input['date_of_birth']);
    if ($d['error_count'] > 0) { $errors[] = 'Invalid date of birth'; }
}
if (!empty($input['mobile']) && strlen(preg_replace('/\D/', '', $input['mobile'])) < 7) {
    $errors[] = 'Mobile number too short';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Normalize empty strings to NULL for optional fields
foreach ($input as $k => $v) {
    if ($v === '') $input[$k] = null;
}

// Build SQL with optional profile_image
$fields = [
    'Fname','Lname','email','mobile','address','country','qualification','date_of_birth','secondary_phone','emergency_contact','emergency_phone','position'
];
if (!empty($input['profile_image'])) {
    $fields[] = 'profile_image';
}

$setParts = array_map(function($f){ return "$f = ?"; }, $fields);
$sql = "UPDATE staff SET " . implode(', ', $setParts) . " WHERE staff_id = ?";
$stmt = $db->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB prepare failed', 'error' => $db->error]);
    exit;
}

// Build bind types and values
$types = '';
$values = [];
foreach ($fields as $f) {
    $types .= 's';
    $values[] = $input[$f] ?? null;
}
$types .= 's'; // staff_id
$values[] = $staff_id;

$stmt->bind_param($types, ...$values);

if ($stmt->execute()) {
    $stmt->close();
    $resp = ['success' => true, 'message' => 'Profile updated', 'data' => $input];
    echo json_encode($resp);
    exit;
} else {
    $err = $stmt->error;
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Update failed', 'error' => $err]);
    exit;
}

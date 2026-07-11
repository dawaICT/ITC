<?php
// Minimal placeholder proxy endpoint for Turnitin API integration
// In production: store API credentials securely and implement full submission lifecycle.
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../../lecturers/includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';

$staffId = $_SESSION['staff_id'] ?? null;
if (!$staffId) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

// Echo back payload as stub
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
echo json_encode(['success'=>true, 'echo'=> $payload, 'note'=>'Implement Turnitin API calls here.']);
exit;



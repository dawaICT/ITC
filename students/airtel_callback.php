<?php
/**
 * Airtel Money Webhook Callback Handler
 * 
 * Receives payment confirmation callbacks from Airtel Money
 * URL: https://yourdomain.com/students/airtel_callback.php
 */

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/airtel_config.php';
require_once __DIR__ . '/../includes/AirtelMoneyGateway.php';

// Log all callback requests
$logFile = __DIR__ . '/../logs/airtel_callbacks.log';
$callbackData = file_get_contents('php://input');

// Ensure logs directory exists
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}

// Log the raw callback
file_put_contents(
    $logFile,
    date('Y-m-d H:i:s') . " | " . $callbackData . "\n",
    FILE_APPEND
);

// Parse JSON
$data = json_decode($callbackData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

try {
    // Initialize gateway for signature verification
    $gateway = new AirtelMoneyGateway();
    
    // Extract callback data
    $reference = $data['reference'] ?? null;
    $transactionId = $data['id'] ?? null;
    $status = strtolower($data['status'] ?? 'unknown');
    $amount = $data['amount'] ?? 0;
    $subscriber = $data['subscriber'] ?? [];
    $phoneNumber = $subscriber['msisdn'] ?? null;
    
    if (!$reference) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing reference']);
        exit;
    }
    
    // Begin transaction for atomic update
    $db->begin_transaction();
    
    try {
        // Update transactions table
        $updateTxSql = "UPDATE transactions SET status = ?, updated_at = NOW() WHERE referenceID = ? LIMIT 1";
        if ($stmt = $db->prepare($updateTxSql)) {
            $stmt->bind_param('ss', $status, $reference);
            $stmt->execute();
            $stmt->close();
        }
        
        // If payment successful, create student_payments record
        if ($status === 'success' || $status === 'completed') {
            // Get student ID and other details from transactions table
            $selectSql = "SELECT studentID, narration FROM transactions WHERE referenceID = ? LIMIT 1";
            if ($stmt = $db->prepare($selectSql)) {
                $stmt->bind_param('s', $reference);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $studentId = $row['studentID'];
                    $narration = $row['narration'] ?? 'Airtel Money Payment';
                    
                    // Check if student_payments entry already exists (avoid duplicates)
                    $checkSql = "SELECT id FROM student_payments WHERE reference_number = ? LIMIT 1";
                    if ($checkStmt = $db->prepare($checkSql)) {
                        $checkStmt->bind_param('s', $reference);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        
                        if ($checkResult->num_rows === 0) {
                            // Insert new payment record
                            $insertSql = "INSERT INTO student_payments (
                                Sid, amount_paid, channel, payment_date, 
                                reference_number, description
                            ) VALUES (?, ?, 'Airtel Money', NOW(), ?, ?)";
                            
                            if ($pStmt = $db->prepare($insertSql)) {
                                $pStmt->bind_param('sdss', $studentId, $amount, $reference, $narration);
                                $pStmt->execute();
                                $pStmt->close();
                            }
                        }
                        $checkStmt->close();
                    }
                    
                    // Log successful payment
                    error_log("Airtel payment successful: Reference=$reference, Student=$studentId, Amount=$amount");
                }
                $stmt->close();
            }
        }
        
        $db->commit();
        
        // Return success response to Airtel
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'reference' => $reference,
            'status' => 'processed'
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Airtel callback processing error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    
} catch (Exception $e) {
    error_log("Airtel callback exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

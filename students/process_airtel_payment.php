<?php
/**
 * Airtel Money Payment Processor
 * 
 * Handles payment initiation and processing through Airtel Money gateway
 * Called via AJAX from frontend
 */

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/airtel_config.php';
require_once __DIR__ . '/../includes/AirtelMoneyGateway.php';

header('Content-Type: application/json');

// Verify Airtel Money is enabled
if (!AIRTEL_MONEY_ENABLED) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Airtel Money payment gateway is not enabled',
        'code' => 'GATEWAY_DISABLED'
    ]);
    exit;
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    try {
        $gateway = new AirtelMoneyGateway();
        
        switch ($action) {
            case 'initiate':
                handlePaymentInitiation($gateway);
                break;
                
            case 'query':
                handleTransactionQuery($gateway);
                break;
                
            case 'callback':
                handlePaymentCallback($gateway);
                break;
                
            default:
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Unknown action: ' . htmlspecialchars($action),
                    'code' => 'UNKNOWN_ACTION'
                ]);
                break;
        }
    } catch (Exception $e) {
        error_log('Airtel payment processor exception: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Payment processing error: ' . $e->getMessage(),
            'code' => 'PROCESSOR_ERROR'
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed',
        'code' => 'METHOD_NOT_ALLOWED'
    ]);
}

/**
 * Handle payment initiation request
 */
function handlePaymentInitiation($gateway) {
    global $db;
    
    // Get required parameters
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $studentId = trim($_POST['student_id'] ?? $_SESSION['Sid'] ?? '');
    $narration = trim($_POST['narration'] ?? 'ITC Portal Student Registration Fee');
    
    // Validation
    if (!$phoneNumber) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Phone number is required',
            'code' => 'MISSING_PHONE'
        ]);
        return;
    }
    
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Amount must be greater than 0',
            'code' => 'INVALID_AMOUNT'
        ]);
        return;
    }
    
    if ($amount < AIRTEL_MIN_AMOUNT || $amount > AIRTEL_MAX_AMOUNT) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Amount must be between ' . AIRTEL_MIN_AMOUNT . ' and ' . AIRTEL_MAX_AMOUNT . ' ZMW',
            'code' => 'AMOUNT_OUT_OF_RANGE'
        ]);
        return;
    }
    
    if (!$studentId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Student ID is required',
            'code' => 'MISSING_STUDENT_ID'
        ]);
        return;
    }
    
    try {
        // Generate unique reference
        $reference = 'AIRTEL-' . date('YmdHis') . '-' . $studentId;
        
        // Initiate payment with Airtel
        $paymentResult = $gateway->initiatePayment(
            $phoneNumber,
            $amount,
            $studentId,
            $reference,
            $narration
        );
        
        if ($paymentResult['success']) {
            // Store transaction in database for tracking
            $insertSql = "INSERT INTO transactions (
                transactionId, studentID, referenceID, amount, narration, 
                transactionType, channel, payment_date, status
            ) VALUES (?, ?, ?, ?, ?, 'Airtel Money', 'Airtel Money', NOW(), 'pending')";
            
            if ($stmt = $db->prepare($insertSql)) {
                $txId = $paymentResult['transaction_id'];
                $stmt->bind_param(
                    'sssds',
                    $txId,
                    $studentId,
                    $reference,
                    $amount,
                    $narration
                );
                
                if ($stmt->execute()) {
                    http_response_code(200);
                    echo json_encode([
                        'success' => true,
                        'message' => 'Payment initiated successfully',
                        'reference' => $reference,
                        'transaction_id' => $txId,
                        'amount' => $amount,
                        'phone_number' => $paymentResult['phone_number'],
                        'status' => 'pending'
                    ]);
                } else {
                    error_log('Failed to store transaction: ' . $db->error);
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to store transaction record',
                        'code' => 'STORAGE_ERROR'
                    ]);
                }
                $stmt->close();
            }
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $paymentResult['error'] ?? 'Payment initiation failed',
                'code' => $paymentResult['code'] ?? 'PAYMENT_ERROR'
            ]);
        }
        
    } catch (Exception $e) {
        error_log('Payment initiation exception: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'code' => 'EXCEPTION'
        ]);
    }
}

/**
 * Handle transaction status query
 */
function handleTransactionQuery($gateway) {
    $reference = trim($_POST['reference'] ?? '');
    
    if (!$reference) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Reference is required',
            'code' => 'MISSING_REFERENCE'
        ]);
        return;
    }
    
    try {
        $result = $gateway->queryTransaction($reference);
        
        http_response_code($result['success'] ? 200 : 400);
        echo json_encode($result);
        
    } catch (Exception $e) {
        error_log('Transaction query exception: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'code' => 'EXCEPTION'
        ]);
    }
}

/**
 * Handle payment callback from Airtel
 */
function handlePaymentCallback($gateway) {
    global $db;
    
    // Get callback data
    $input = file_get_contents('php://input');
    $callbackData = json_decode($input, true);
    
    if (!$callbackData) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid callback data',
            'code' => 'INVALID_DATA'
        ]);
        return;
    }
    
    try {
        // Process callback
        $result = $gateway->handleCallback($callbackData);
        
        if ($result['success']) {
            $reference = $result['reference'];
            $status = strtolower($result['status']);
            
            // Update transaction status in database
            $updateSql = "UPDATE transactions SET status = ? WHERE referenceID = ? LIMIT 1";
            if ($stmt = $db->prepare($updateSql)) {
                $stmt->bind_param('ss', $status, $reference);
                $stmt->execute();
                $stmt->close();
            }
            
            // If successful, mirror to student_payments
            if ($status === 'success' || $status === 'completed') {
                $amount = $result['amount'] ?? 0;
                $txId = $result['id'];
                
                // Get student ID from reference
                $selectSql = "SELECT studentID FROM transactions WHERE referenceID = ? LIMIT 1";
                if ($stmt = $db->prepare($selectSql)) {
                    $stmt->bind_param('s', $reference);
                    $stmt->execute();
                    $stmt->bind_result($studentId);
                    if ($stmt->fetch()) {
                        // Insert into student_payments
                        $insertSql = "INSERT INTO student_payments (
                            Sid, amount_paid, channel, payment_date, 
                            reference_number, description
                        ) VALUES (?, ?, 'Airtel Money', NOW(), ?, 'Airtel Money Payment')";
                        
                        if ($pStmt = $db->prepare($insertSql)) {
                            $pStmt->bind_param('dss', $amount, $reference, $txId);
                            $pStmt->execute();
                            $pStmt->close();
                        }
                    }
                    $stmt->close();
                }
            }
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Callback processed successfully',
                'reference' => $reference,
                'status' => $status
            ]);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
        
    } catch (Exception $e) {
        error_log('Callback processing exception: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'code' => 'EXCEPTION'
        ]);
    }
}
?>

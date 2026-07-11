<?php
/**
 * Example Student Payment Page with Airtel Money Integration
 * 
 * This file demonstrates how to integrate Airtel Money payment option
 * into your student fees payment page.
 * 
 * NOTE: This is an example. Integrate the Airtel Money section into
 * your existing fees.php or payment page.
 */

session_start();
require_once '../db/connect.php';
require_once '../includes/Guard.php';

// Ensure student is logged in
Guard::check();

$student_id = $_SESSION['Sid'] ?? null;

if (!$student_id) {
    die('Invalid student session');
}

// Get student information
$query = "SELECT * FROM students WHERE Sid = ?";
$stmt = Database::getInstance()->getConnection()->prepare($query);
$stmt->bind_param('i', $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

// Calculate fees due (example - replace with your actual calculation)
$amount_due = 2500.00; // Example: ZMW 2,500

// Check for payment success
$payment_success = false;
$payment_reference = null;
if ($_GET['payment_success'] ?? false) {
    $payment_success = true;
    $payment_reference = $_GET['ref'] ?? 'N/A';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Fee Payment</title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Airtel Money JavaScript -->
    <script src="js/airtel_money.js"></script>
    
    <style>
        :root {
            --primary-color: #007bff;
            --airtel-red: #ee2a24;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .payment-header {
            background: linear-gradient(135deg, var(--primary-color), #0056b3);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .payment-header h1 {
            margin-bottom: 0.5rem;
        }
        
        .student-info {
            background-color: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .amount-due {
            background: linear-gradient(135deg, #fff5e1, #ffe0b2);
            padding: 2rem;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 2rem;
            border-left: 5px solid var(--airtel-red);
        }
        
        .amount-due h5 {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .amount-due .amount {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--airtel-red);
        }
        
        .payment-methods {
            margin-bottom: 2rem;
        }
        
        .payment-methods h4 {
            color: #333;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #007bff;
        }
        
        .payment-option {
            background-color: white;
            padding: 1.5rem;
            border-radius: 8px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .payment-option:hover {
            border-color: var(--airtel-red);
            box-shadow: 0 4px 12px rgba(238, 42, 36, 0.15);
        }
        
        .airtel-payment-option {
            border: 2px solid var(--airtel-red);
        }
        
        .payment-method-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .payment-logo {
            height: 40px;
            margin-right: 1rem;
        }
        
        .payment-method-header h5 {
            margin: 0;
            color: #333;
        }
        
        .payment-description {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            border-radius: 6px;
            padding: 0.75rem;
            border: 1px solid #ddd;
        }
        
        .form-control:focus {
            border-color: var(--airtel-red);
            box-shadow: 0 0 0 0.2rem rgba(238, 42, 36, 0.25);
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
        }
        
        .btn {
            border-radius: 6px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background-color: var(--airtel-red);
            border-color: var(--airtel-red);
        }
        
        .btn-primary:hover {
            background-color: #cc1f1a;
            border-color: #cc1f1a;
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .alert {
            border-radius: 6px;
            border-left: 4px solid;
        }
        
        .alert-info {
            border-left-color: #17a2b8;
        }
        
        .alert-success {
            border-left-color: #28a745;
        }
        
        .alert-danger {
            border-left-color: #dc3545;
        }
        
        .alert ol, .alert ul {
            margin-bottom: 0;
            padding-left: 1.5rem;
        }
        
        .alert li {
            margin-bottom: 0.5rem;
        }
        
        .transaction-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            padding: 2rem;
            border-radius: 8px;
            margin-top: 2rem;
            border-left: 5px solid #28a745;
            text-align: center;
        }
        
        .transaction-success h5 {
            color: #155724;
            margin-bottom: 0.5rem;
        }
        
        .transaction-reference {
            background-color: white;
            padding: 1rem;
            border-radius: 6px;
            margin-top: 1rem;
            font-family: 'Courier New', monospace;
            word-break: break-all;
        }
        
        .help-section {
            background-color: #f0f7ff;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
            border-left: 4px solid #2196F3;
        }
        
        .help-section h6 {
            color: #2196F3;
            margin-bottom: 1rem;
        }
        
        .help-list {
            list-style: none;
            padding: 0;
        }
        
        .help-list li {
            padding: 0.5rem 0;
            padding-left: 1.5rem;
            position: relative;
        }
        
        .help-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
        }
    </style>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
    <div class="container py-5">
        <!-- Header -->
        <div class="payment-header">
            <h1><i class="fas fa-credit-card"></i> Student Fee Payment</h1>
            <p class="mb-0">Pay your registration and semester fees online</p>
        </div>

        <!-- Student Information -->
        <?php if ($student): ?>
        <div class="student-info">
            <h5 class="mb-3">Your Information</h5>
            <div class="info-row">
                <span><strong>Name:</strong></span>
                <span><?php echo htmlspecialchars($student['Name'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row">
                <span><strong>Student ID:</strong></span>
                <span><?php echo htmlspecialchars($student['Sid'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row">
                <span><strong>Program:</strong></span>
                <span><?php echo htmlspecialchars($student['program_code'] ?? 'N/A'); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Amount Due -->
        <div class="amount-due">
            <h5>Total Amount Due</h5>
            <div class="amount">
                ZMW <span id="total-amount"><?php echo number_format($amount_due, 2); ?></span>
            </div>
            <small class="text-muted mt-2">Registration and Semester Fee for <?php echo date('Y'); ?></small>
        </div>

        <!-- Payment Success Message -->
        <?php if ($payment_success): ?>
        <div class="transaction-success">
            <h5><i class="fas fa-check-circle"></i> Payment Successful!</h5>
            <p class="text-success">Your payment has been processed successfully.</p>
            <div class="transaction-reference">
                <small>Transaction Reference:</small><br>
                <strong><?php echo htmlspecialchars($payment_reference); ?></strong>
            </div>
            <div class="mt-3">
                <a href="dashboard.php" class="btn btn-success">
                    <i class="fas fa-home"></i> Return to Dashboard
                </a>
            </div>
        </div>
        <?php else: ?>

        <!-- Payment Methods -->
        <div class="payment-methods">
            <h4><i class="fas fa-wallet"></i> Select Payment Method</h4>
            
            <div class="row">
                <!-- Airtel Money Payment Option -->
                <div class="col-md-6 mb-4">
                    <div id="airtel-money-container">
                        <!-- Airtel Money payment form will be inserted here by JavaScript -->
                    </div>
                </div>

                <!-- Other Payment Methods -->
                <div class="col-md-6 mb-4">
                    <div class="payment-option">
                        <div class="payment-method-header">
                            <i class="fas fa-bank" style="font-size: 2rem; color: #007bff;"></i>
                            <h5>Bank Transfer</h5>
                        </div>
                        <p class="payment-description">
                            Transfer funds directly to our bank account
                        </p>
                        <button class="btn btn-primary w-100" onclick="goToBankPayment()">
                            <i class="fas fa-arrow-right"></i> Pay via Bank
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Help Section -->
        <div class="help-section">
            <h6><i class="fas fa-question-circle"></i> Payment Help</h6>
            <ul class="help-list">
                <li><strong>Airtel Money:</strong> Pay directly from your Airtel Money account. Fast and secure.</li>
                <li><strong>Bank Transfer:</strong> Transfer from your bank account directly to our merchant account.</li>
                <li><strong>Payment Issues?</strong> Contact <a href="mailto:support@wucportal.com">support@wucportal.com</a></li>
                <li><strong>Payment Limits:</strong> Airtel Money transactions: ZMW 10 - 100,000</li>
            </ul>
        </div>

        <?php endif; ?>

        <!-- Hidden student ID for payment processing -->
        <input type="hidden" id="student-id" value="<?php echo htmlspecialchars($student_id); ?>">
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>

    <!-- Initialize Airtel Money Payment -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Airtel Money payment
            if (window.AirtelMoneyPayment) {
                const airtelPayment = new AirtelMoneyPayment({
                    processorUrl: 'process_airtel_payment.php',
                    pollInterval: 5000,
                    maxPolls: 60
                });
                
                // Initialize payment UI
                airtelPayment.initPaymentOption();
                
                // Set the amount
                const amount = parseFloat(document.getElementById('total-amount').textContent.replace(/,/g, ''));
                airtelPayment.setAmount(amount);
            }
        });

        // Bank payment redirect (replace with your actual bank payment page)
        function goToBankPayment() {
            window.location.href = 'bank_payment.php';
        }
    </script>
</body>
</html>

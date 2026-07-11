<?php
require_once __DIR__ . '/../includes/manual_entry_guards.php';
wuc_gate_debug_endpoint();
require_once __DIR__ . '/includes/guard.php';

// Test script to verify semester registration functionality
error_reporting(E_ALL);
ini_set('display_errors', '0');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test Semester Registration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="container mt-5">
        <h1>Semester Registration Test</h1>
        
        <div class="card mt-4">
            <div class="card-header bg-primary text-white">
                Registration Status Check
            </div>
            <div class="card-body">
                <?php
                // Get current student ID from session
                $studentId = $_SESSION['Sid'] ?? null;
                
                if (!$studentId) {
                    echo '<div class="alert alert-danger">No student ID found in session. Please log in.</div>';
                } else {
                    echo '<h4>Student ID: ' . htmlspecialchars($studentId) . '</h4>';
                    
                    // Check student_program entry
                    $programQuery = $db->query("SELECT * FROM student_program WHERE Sid = '$studentId'");
                    
                    if ($programQuery && $programQuery->num_rows > 0) {
                        $programData = $programQuery->fetch_assoc();
                        echo '<div class="alert alert-success">Found program entry for student. Program Code: ' . 
                            htmlspecialchars($programData['program_code']) . '</div>';
                        
                        // Check semester_registration entries
                        $regQuery = $db->query("SELECT * FROM semester_registration WHERE Sid = '$studentId' ORDER BY created_at DESC");
                        
                        if ($regQuery && $regQuery->num_rows > 0) {
                            echo '<h5 class="mt-4">Semester Registration Records:</h5>';
                            echo '<table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Program Code</th>
                                        <th>Semester</th>
                                        <th>Year</th>
                                        <th>Registered On</th>
                                    </tr>
                                </thead>
                                <tbody>';
                            
                            while ($reg = $regQuery->fetch_assoc()) {
                                echo '<tr>
                                    <td>' . htmlspecialchars($reg['program_code']) . '</td>
                                    <td>' . htmlspecialchars($reg['semester']) . '</td>
                                    <td>' . htmlspecialchars($reg['Year']) . '</td>
                                    <td>' . htmlspecialchars($reg['created_at'] ?? 'N/A') . '</td>
                                </tr>';
                            }
                            
                            echo '</tbody></table>';
                        } else {
                            echo '<div class="alert alert-warning">No semester registration records found.</div>';
                        }
                        
                        // Check student_payments entries
                        $paymentsQuery = $db->query("SELECT * FROM student_payments WHERE Sid = '$studentId' ORDER BY dte_time DESC");
                        
                        if ($paymentsQuery && $paymentsQuery->num_rows > 0) {
                            echo '<h5 class="mt-4">Student Payments Records:</h5>';
                            echo '<table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Semester</th>
                                        <th>Year</th>
                                        <th>Balance</th>
                                        <th>Narration</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>';
                            
                            while ($payment = $paymentsQuery->fetch_assoc()) {
                                echo '<tr>
                                    <td>' . htmlspecialchars($payment['invoice'] ?? 'N/A') . '</td>
                                    <td>' . htmlspecialchars($payment['semester']) . '</td>
                                    <td>' . htmlspecialchars($payment['Year']) . '</td>
                                    <td>' . htmlspecialchars($payment['balance']) . '</td>
                                    <td>' . htmlspecialchars($payment['narration']) . '</td>
                                    <td>' . htmlspecialchars($payment['dte_time'] ?? 'N/A') . '</td>
                                </tr>';
                            }
                            
                            echo '</tbody></table>';
                        } else {
                            echo '<div class="alert alert-warning">No payment records found.</div>';
                        }
                        
                    } else {
                        echo '<div class="alert alert-danger">No program entry found for student ID: ' . htmlspecialchars($studentId) . '</div>';
                    }
                }
                ?>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                Try New Registration
            </div>
            <div class="card-body">
                <p>To test the semester registration system, use the main registration pages:</p>
                <a href="registration.php" class="btn btn-primary">Go to Registration Page</a>
                
                <hr>
                
                <div class="alert alert-info mt-3">
                    <strong>Debug Info:</strong> The fixes implemented include:
                    <ul>
                        <li>Ensured consistent data between semester_registration and student_payments tables</li>
                        <li>Fixed issues in processSearchReturning.php with improper else clauses</li>
                        <li>Added proper error handling and transaction support</li>
                        <li>Fixed registration flow for returning students</li>
                        <li>Enhanced validation to prevent duplicate registrations</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

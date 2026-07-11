<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/role_helpers.php';

wuc_apply_security_headers(true);
if (session_status() === PHP_SESSION_NONE) {
    wuc_configure_session_cookie();
    session_start();
}
if (!isset($_SESSION['user_id']) && isset($_SESSION['staff_id'])) {
    $_SESSION['user_id'] = $_SESSION['staff_id'];
}
if (!isset($_SESSION['user_id']) || (function_exists('canAccessAdmissions') && !canAccessAdmissions())) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

if (!isset($_GET['sid']) || empty($_GET['sid'])) {
    die("Invalid access");
}

$sid = trim($_GET['sid']);

// Get student and program information
$query = "SELECT sp.*, s.Fname, s.Lname, s.sex, p.program_name
          FROM student_program sp
          JOIN students s ON sp.Sid = s.SID
          JOIN programs p ON sp.program_code = p.program_code
          WHERE sp.Sid = ?";

$stmt = $db->prepare($query);
$stmt->bind_param("s", $sid);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Student admission record not found");
}

$admission = $result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admission Letter - <?php echo htmlspecialchars($admission['Fname'] . ' ' . $admission['Lname']); ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm;
        }
        body {
            font-family: 'Times New Roman', serif;
            margin: 0;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .letter-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #6f42c1;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header .wuc-logo-frame {
            margin: 0 auto 10px;
            min-height: 68px;
        }
        .university-name {
            font-size: 24px;
            font-weight: bold;
            color: #6f42c1;
            margin: 0;
        }
        .university-subtitle {
            font-size: 16px;
            color: #666;
            margin: 5px 0;
        }
        .letter-title {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            margin: 30px 0 20px 0;
            text-align: center;
        }
        .letter-content {
            line-height: 1.6;
            color: #333;
        }
        .student-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #6f42c1;
        }
        .detail-row {
            display: flex;
            margin-bottom: 10px;
        }
        .detail-label {
            font-weight: bold;
            width: 200px;
            color: #555;
        }
        .detail-value {
            color: #333;
        }
        .signature-section {
            margin-top: 50px;
            text-align: left;
        }
        .signature-line {
            border-top: 1px solid #333;
            width: 200px;
            margin-top: 50px;
            padding-top: 5px;
        }
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #6f42c1;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .print-button:hover {
            background: #5a32a3;
        }
        @media print {
            .print-button {
                display: none;
            }
            body {
                background: white;
            }
            .letter-container {
                box-shadow: none;
                padding: 20px;
            }
        }
    </style>
    <link rel="stylesheet" href="/wucportal/css/wuc-premium.css">
    <!-- Global print layer: A4 paper, hides chrome, shows logo letterhead. -->
    <link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">
    <script src="/wucportal/js/wuc-print-fit.js"></script>
</head>
<body class="single-page-document no-auto-print">
    <button class="print-button" onclick="wucPrintSinglePage()">Print Admission Letter</button>

    <div class="letter-container wuc-a4-sheet">
        <div class="header">
            <span class="wuc-logo-frame">
                <img src="../images/itc_logo.png" alt="University Logo" class="wuc-logo-img report-logo">
            </span>
            <h1 class="university-name">INDUSTRIAL TRAINING COLLEGE</h1>
            <p class="university-subtitle">Academic Affairs Division</p>
            <p class="university-subtitle">Student Admissions Office</p>
        </div>

        <div class="letter-title">OFFICIAL ADMISSION LETTER</div>

        <div class="letter-content">
            <p><strong>Date:</strong> <?php echo date('F j, Y'); ?></p>

            <p><strong><?php echo htmlspecialchars($admission['Fname'] . ' ' . $admission['Lname']); ?></strong></p>
            <p><strong>Student ID:</strong> <?php echo htmlspecialchars($admission['Sid']); ?></p>

            <p>Dear <?php echo htmlspecialchars($admission['Fname'] . ' ' . $admission['Lname']); ?>,</p>

            <p>I am pleased to inform you that you have been officially admitted to <strong>Industrial training college</strong> for the academic program listed below. This admission is granted based on your application and the university's admission criteria.</p>

            <div class="student-details">
                <h4 style="margin-top: 0; color: #6f42c1;">Admission Details:</h4>

                <div class="detail-row">
                    <span class="detail-label">Student Name:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admission['Fname'] . ' ' . $admission['Lname']); ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Student ID:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admission['Sid']); ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Gender:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admission['sex']); ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Program:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admission['program_name']); ?> (<?php echo htmlspecialchars($admission['program_code']); ?>)</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Intake:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admission['intake']); ?> Intake</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Mode of Study:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admission['mode']); ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Commencement Date:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admission['startYear']); ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Expected Completion:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admission['endYear']); ?></span>
                </div>
            </div>

            <p>Congratulations on your admission! You are expected to:</p>
            <ul>
                <li>Complete all registration formalities within the stipulated time</li>
                <li>Pay the required fees as per the fee structure</li>
                <li>Attend the orientation program scheduled by the university</li>
                <li>Adhere to all university rules and regulations</li>
                <li>Maintain satisfactory academic progress throughout your studies</li>
            </ul>

            <p>Please keep this admission letter for your records and present it when required during your registration process.</p>

            <p>We welcome you to Industrial training college and wish you success in your academic journey.</p>

            <p>Best regards,</p>

            <div class="signature-section">
                <p><strong>Dr. Academic Director</strong></p>
                <p>Director of Admissions</p>
                <p>Industrial training college</p>
                <div class="signature-line">Signature</div>
            </div>
        </div>
    </div>

    <script>
        // Auto-print when page loads (optional)
        // window.onload = function() {
        //     window.print();
        // };
    </script>
</body>
</html>

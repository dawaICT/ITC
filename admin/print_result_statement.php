<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once "../db/connect.php";
require_once dirname(__DIR__) . '/includes/assessment_weighting_helpers.php';

$student_id = $_GET['student_id'] ?? $_GET['sid'] ?? $_SESSION['student_id'] ?? $_SESSION['username'] ?? null;
$year = $_GET['year'] ?? '';
$sem  = $_GET['sem'] ?? '';

// Basic validation
if (!$student_id || !ctype_digit((string)$year) || !ctype_digit((string)$sem)) {
    die("Invalid request. Missing student identification or academic period.");
}

// 1. Fetch Student & Program Details
$stud_sql = "SELECT s.*, p.program_name 
             FROM students s 
             LEFT JOIN student_program sp ON s.SID = sp.Sid 
             LEFT JOIN programs p ON sp.program_code = p.program_code 
             WHERE s.SID = ? LIMIT 1";
$student = null;
if ($stmt = $db->prepare($stud_sql)) {
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $student = $res->fetch_object();
    $stmt->close();
}

if (!$student) {
    die("Student record not found.");
}

// 2. Fetch Exam Results
$results_sql = "SELECT c.course_code, c.course_name, c.credit_units, COALESCE(sa.Total_CA, 0) AS Total_CA, COALESCE(e.Exam_marks, e.Total_marks) AS Exam_marks 
                FROM exams e 
                JOIN courses c ON e.Course_Code = c.course_code 
                LEFT JOIN semester_assessment sa
                  ON sa.Sid = e.Sid
                 AND sa.Course_Code = e.Course_Code
                 AND sa.Year = e.Year
                 AND sa.semester = e.semester
                WHERE e.Sid = ? AND e.Year = ? AND e.semester = ?";
$exam_results = [];
if ($stmt = $db->prepare($results_sql)) {
    $stmt->bind_param("sis", $student_id, $year, $sem);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_object()) {
        $exam_results[] = $row;
    }
    $stmt->close();
}

// Helper function for Grade
function getGrade($total) {
    if ($total >= 90) return 'A+';
    if ($total >= 80) return 'A';
    if ($total >= 75) return 'B+';
    if ($total >= 70) return 'B';
    if ($total >= 65) return 'B-';
    if ($total >= 60) return 'C+';
    if ($total >= 50) return 'C';
    if ($total >= 45) return 'D';
    return 'E';
}

function getPoints($total) {
    if ($total >= 90) return 4.00;
    if ($total >= 80) return 3.74;
    if ($total >= 75) return 3.64;
    if ($total >= 70) return 3.49;
    if ($total >= 65) return 3.25;
    if ($total >= 60) return 2.99;
    if ($total >= 50) return 2.33;
    if ($total >= 45) return 1.99;
    return 1.00; // Fail usually 0 but reusing logic from finalExams.php which said 1.00?
                 // Wait, finalExams.php said "else echo '1.00'". That seems generous for a Fail (E).
                 // However, I will stick to existing logic to Ensure consistency.
                 // Actually standard GPA for F is 0. But let's follow the codebase legacy.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement of Results - <?php echo htmlspecialchars($student->SID); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/wuc-premium.css">
    <!-- Global print layer: A4 paper, hides chrome, shows logo letterhead. -->
    <link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">
    
    <style>
        @page {
            size: A4 portrait;
            margin: 14mm;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #f8f9fa;
            color: #334155;
            padding: 2rem;
        }
        .report-container {
            background: #fff;
            max-width: 800px;
            margin: 0 auto;
            padding: 3rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .report-header {
            text-align: center;
            margin-bottom: 2rem;
            border-bottom: 2px solid #000;
            padding-bottom: 1rem;
        }
        .report-header img {
            max-height: 72px;
            width: auto;
        }
        .report-title {
            text-transform: uppercase;
            font-weight: 700;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #1e293b;
        }
        .report-subtitle {
            font-size: 1.1rem;
            font-weight: 600;
            color: #64748b;
        }
        .student-details {
            margin-bottom: 2rem;
        }
        .detail-row {
            display: flex;
            margin-bottom: 0.5rem;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 0.25rem;
        }
        .detail-label {
            width: 150px;
            font-weight: 600;
            color: #64748b;
        }
        .detail-value {
            font-weight: 500;
            color: #1e293b;
        }
        .results-table th {
            background-color: #f1f5f9;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        .grade-badge {
            font-weight: 700;
            width: 35px;
            display: inline-block;
            text-align: center;
        }
        
        @media print {
            body { background: #fff; padding: 0; }
            .report-container { box-shadow: none; padding: 0; width: 100%; max-width: 100%; border-radius: 0; }
            .d-print-none { display: none !important; }
            .report-header { border-bottom: 2px solid #000 !important; }
            a { text-decoration: none; color: inherit; }
        }
    </style>
</head>
<body class="single-page-document no-auto-print">
<script src="/wucportal/js/wuc-print-fit.js"></script>

    <div class="report-container wuc-a4-sheet">
        <!-- Floating Actions -->
        <div class="d-flex justify-content-between mb-4 d-print-none">
            <a href="final_results.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i> Back
            </a>
            <button onclick="wucPrintSinglePage()" class="btn btn-primary d-flex align-items-center gap-2">
                <i class="fas fa-print"></i> Print Statement
            </button>
        </div>

        <!-- Header -->
        <div class="report-header">
            <span class="wuc-logo-frame">
                <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" class="wuc-logo-img report-logo">
            </span>
            <h1 class="report-title">Industrial Training Centre</h1>
            <div class="report-subtitle">Official Statement of Results</div>
        </div>

        <!-- Student Details -->
        <div class="student-details">
            <div class="row">
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">Student ID:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($student->SID); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Name:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($student->Fname . ' ' . $student->Lname); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Academic Year:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($year); ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">Program:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($student->program_name ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Semester:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sem); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date Issued:</span>
                        <span class="detail-value"><?php echo date('d M Y'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Table -->
        <?php if(count($exam_results) > 0): ?>
        <table class="table table-bordered results-table">
            <thead>
                <tr>
                    <th style="width: 15%">Course Code</th>
                    <th>Course Name</th>
                    <th style="width: 10%" class="text-center">Credits</th>
                    <th style="width: 10%" class="text-center">Mark</th>
                    <th style="width: 10%" class="text-center">Grade</th>
                    <th style="width: 10%" class="text-center">Points</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($exam_results as $r): 
                    $total = assessment_weighting_total($db, (string)$student_id, $r->Total_CA, $r->Exam_marks);
                    $grade = getGrade($total);
                    $points = getPoints($total);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($r->course_code); ?></td>
                    <td><?php echo htmlspecialchars($r->course_name); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($r->credit_units ?? '-'); ?></td>
                    <td class="text-center"><?php echo $total; ?></td>
                    <td class="text-center"><span class="grade-badge"><?php echo $grade; ?></span></td>
                    <td class="text-center"><?php echo number_format($points, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="alert alert-info text-center">
                No results found for this period.
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="mt-5 pt-4 border-top">
            <div class="row">
                <div class="col-6">
                    <p class="mb-1"><small><strong>Registrar's Signature:</strong></small></p>
                    <div style="border-bottom: 1px dotted #000; width: 200px; height: 30px;"></div>
                </div>
                <div class="col-6 text-end">
                    <p class="mb-0 text-muted"><small>This document is an official record of the university.</small></p>
                </div>
            </div>
        </div>
    </div>

</body>
</html>

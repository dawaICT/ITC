<?php
include "includes/admin.php";
error_reporting(0);

function transcript_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (!isset($_GET['student_id'])) {
    $_SESSION['errorMsg'] = "No student ID provided";
    header("Location: assessments.php");
    exit();
}

$student_id = trim((string)$_GET['student_id']);

// Get student details
$student_query = "SELECT s.*, p.program_name 
                 FROM students s 
                 LEFT JOIN student_program sp ON s.SID = sp.Sid 
                 LEFT JOIN programs p ON sp.program_code = p.program_code 
                 WHERE s.SID = ?";
$stmt = $db->prepare($student_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student_result = $stmt->get_result();
$student = $student_result->fetch_assoc();

if (!$student) {
    $_SESSION['errorMsg'] = "Student not found";
    header("Location: assessments.php");
    exit();
}

require "includes/header.php";
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="dashboard-title">Academic Transcript</h1>
        <button class="btn btn-purple" onclick="window.print()">
            <i class="fas fa-print me-2"></i>Print Transcript
        </button>
    </div>

    <!-- Student Information -->
    <div class="card mb-4 print-section">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h5 class="text-primary mb-3">Student Information</h5>
                    <table class="table table-hover align-middle">
                        <tr>
                            <th width="150">Student ID:</th>
                            <td><?php echo transcript_h($student['SID'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>Name:</th>
                            <td><?php echo transcript_h(trim(($student['Fname'] ?? $student['FirstName'] ?? '') . ' ' . ($student['Lname'] ?? $student['LastName'] ?? ''))); ?></td>
                        </tr>
                        <tr>
                            <th>Program:</th>
                            <td><?php echo transcript_h($student['program_name'] ?? ''); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Academic Results -->
    <div class="card print-section">
        <div class="card-body">
            <h5 class="text-primary mb-3">Academic History</h5>
            <?php
            // Get all academic periods for this student
            $periods_query = "SELECT DISTINCT ap.academic_year, ap.semester_term
                            FROM semester_assessment sa
                            JOIN academic_periods ap ON sa.academic_period_id = ap.id
                            WHERE sa.Sid = ?
                            ORDER BY ap.academic_year DESC, ap.semester_term";
            $stmt = $db->prepare($periods_query);
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            $periods_result = $stmt->get_result();

            while ($period = $periods_result->fetch_assoc()) {
                echo "<div class='semester-section mb-4'>";
                echo "<h6 class='semester-header bg-light p-2 rounded'>";
                echo "Academic Year: " . transcript_h($period['academic_year'] ?? '') . " - Semester: " . transcript_h($period['semester_term'] ?? '');
                echo "</h6>";

                // Get results for this period
                $results_query = "SELECT sa.*, pc.course_name
                                FROM semester_assessment sa
                                JOIN program_courses pc ON sa.Course_Code = pc.course_code
                                JOIN academic_periods ap ON sa.academic_period_id = ap.id
                                WHERE sa.Sid = ? 
                                AND ap.academic_year = ?
                                AND ap.semester_term = ?
                                ORDER BY pc.course_name";

                $stmt = $db->prepare($results_query);
                $stmt->bind_param("sss", $student_id, $period['academic_year'], $period['semester_term']);
                $stmt->execute();
                $results = $stmt->get_result();
                ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Assignment 1</th>
                                <th>Assignment 2</th>
                                <th>Test 1</th>
                                <th>Test 2</th>
                                <th>Total CA</th>
                                <th>Exam</th>
                                <th>Final Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total_ca = 0;
                            $count = 0;
                            while ($row = $results->fetch_assoc()) {
                                echo "<tr>";
                                $a1 = $row['A1'] ?? $row['S1A1'] ?? '';
                                $a2 = $row['A2'] ?? $row['S1A2'] ?? '';
                                $t1 = $row['T1'] ?? $row['S1T1'] ?? '';
                                $t2 = $row['T2'] ?? $row['S1T2'] ?? '';
                                $exam = $row['Exam'] ?? $row['Exam_marks'] ?? '';
                                $grade = $row['Final_Grade'] ?? $row['Grade'] ?? '';
                                echo "<td>" . transcript_h($row['Course_Code'] ?? '') . "</td>";
                                echo "<td>" . transcript_h($row['course_name'] ?? '') . "</td>";
                                echo "<td>" . transcript_h($a1) . "</td>";
                                echo "<td>" . transcript_h($a2) . "</td>";
                                echo "<td>" . transcript_h($t1) . "</td>";
                                echo "<td>" . transcript_h($t2) . "</td>";
                                echo "<td>" . transcript_h($row['Total_CA'] ?? '') . "</td>";
                                echo "<td>" . transcript_h($exam) . "</td>";
                                echo "<td>" . transcript_h($grade) . "</td>";
                                echo "</tr>";
                                $total_ca += (float)($row['Total_CA'] ?? 0);
                                $count++;
                            }

                            if ($count > 0) {
                                $average_ca = round($total_ca / $count, 2);
                                echo "<tr class='table-light'>";
                                echo "<td colspan='6' class='text-end'><strong>Semester Average CA:</strong></td>";
                                echo "<td colspan='3'><strong>" . transcript_h($average_ca) . "%</strong></td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <?php
                echo "</div>";
            }
            ?>
        </div>
    </div>
</div>

<style>
:root {
    --purple-primary: #6f42c1;
    --purple-secondary: #8250df;
    --purple-light: #e9ecef;
}

.btn-purple {
    background-color: var(--purple-primary);
    color: white;
}

.btn-purple:hover {
    background-color: var(--purple-secondary);
    color: white;
}

.text-primary {
    color: var(--purple-primary) !important;
}

.card-header {
    border-left: 4px solid var(--purple-primary);
}

.dashboard-title {
    color: var(--purple-primary);
}

.semester-header {
    background-color: var(--purple-light) !important;
    color: var(--purple-primary);
}

.table-light th {
    background-color: var(--purple-light) !important;
}

@media print {
    .navbar, .sidebar, .btn, .no-print {
        display: none !important;
    }

    .print-section {
        break-inside: avoid;
    }

    .container-fluid {
        padding: 0 !important;
    }

    .card {
        border: none !important;
        box-shadow: none !important;
    }

    .semester-header {
        background-color: var(--purple-light) !important;
        -webkit-print-color-adjust: exact;
    }

    .table {
        width: 100% !important;
    }

    .table td, .table th {
        padding: 0.5rem !important;
    }

    body::before {
        content: '';
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 400px;
        height: 400px;
        background-image: url('/wucportal/assets/images/logo.png');
        background-size: contain;
        background-repeat: no-repeat;
        background-position: center;
        opacity: 0.1;
        z-index: -1;
        pointer-events: none;
    }

    @page {
        margin: 1.5cm;
    }
}

.semester-section {
    margin-bottom: 2rem;
}

.semester-header {
    font-weight: 600;
}

.table th {
    font-weight: 600;
}
</style>

<?php require_once "includes/footer.php"; ?> 

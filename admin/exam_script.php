<?php
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/assessment_weighting_helpers.php';
error_reporting(0);
$records = [];
$student_info = null;
$number = 1;

if (isset($_GET['view'])) {
    $view = trim((string)($_GET['view'] ?? ''));

    $sql = "SELECT exams.*, courses.course_name, semester_assessment.Total_CA, students.SID, students.Fname, students.Lname
            FROM exams
            INNER JOIN courses ON exams.Course_Code = courses.course_code
            INNER JOIN semester_assessment
                ON semester_assessment.Course_Code = exams.Course_Code
                AND semester_assessment.Sid = exams.Sid
                AND semester_assessment.Year = exams.Year
            INNER JOIN students ON students.SID = exams.Sid
            WHERE exams.Sid = ? AND semester_assessment.Sid = ?";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('ss', $view, $view);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($row = $results->fetch_object()) {
            $records[] = $row;
        }
        $student_info = $records[0] ?? null;
        $stmt->close();
    }
}

$page_title = 'Exam Results';
require 'includes/header.php';
?>
<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4">
        <div class="dashboard-header mb-4">
            <div class="d-flex align-items-center gap-3">
                <button onclick="history.back()" class="btn btn-outline-secondary btn-sm d-print-none">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </button>
                <h1 class="dashboard-title mb-0">Exam Results</h1>
            </div>
        </div>

        <div class="data-table-card">
            <div class="card-body">
                <div id="print">
                    <div class="text-center mb-3">
                        <img src="/wucportal/images/itc_logo.png" width="100" height="auto" alt="ITC Logo">
                        <h5 class="mt-2 mb-0"><strong>INDUSTRIAL TRAINING CENTRE</strong></h5>
                        <h6 class="text-muted">Result Transcript</h6>
                    </div>
                    <hr>
                    <?php if ($student_info): ?>
                    <p class="small mb-1"><strong>SID:</strong> <?php echo htmlspecialchars($student_info->SID ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="small mb-1"><strong>Student Name:</strong> <?php echo htmlspecialchars(trim(($student_info->Fname ?? '') . ' ' . ($student_info->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="small mb-1"><strong>Semester:</strong> <?php echo htmlspecialchars($student_info->semester ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="small mb-1"><strong>Year:</strong> <?php echo htmlspecialchars($student_info->Year ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <hr>
                    <?php endif; ?>

                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>No.</th>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $r): ?>
                            <tr>
                                <td><?php echo $number++; ?>.</td>
                                <td><?php echo htmlspecialchars($r->Course_Code ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r->course_name ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php
                                    $Total_Grade = assessment_weighting_total($db, (string)$r->Sid, $r->Total_CA, ($r->Exam_marks ?? $r->Total_marks));
                                    if ($Total_Grade >= 90) echo "A+";
                                    elseif ($Total_Grade >= 85) echo "A";
                                    elseif ($Total_Grade >= 75) echo "B+";
                                    elseif ($Total_Grade >= 65) echo "B";
                                    elseif ($Total_Grade >= 55) echo "C+";
                                    elseif ($Total_Grade >= 50) echo "C";
                                    else echo "D";
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    No exam results found<?php echo isset($_GET['view']) ? ' for student ' . htmlspecialchars($_GET['view'], ENT_QUOTES, 'UTF-8') : ''; ?>.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p class="small fst-italic text-muted">This is a result transcript generated from the official ITC online results portal.</p>
                </div>

                <button class="btn btn-success mt-3 d-print-none" onclick="printContent('print')">
                    <i class="fas fa-print me-2"></i>Print Statement
                </button>
            </div>
        </div>
    </div>
</main>
<script>
function printContent(printId) {
    var source = document.getElementById(printId);
    if (!source) return;
    if (window.wucPrintElement) {
        window.wucPrintElement(source, document.title);
        return;
    }
    var printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) { window.print(); return; }
    var styles = Array.prototype.map.call(document.querySelectorAll('link[rel="stylesheet"], style'), function (node) { return node.outerHTML; }).join('\n');
    printWindow.document.open();
    printWindow.document.write('<!DOCTYPE html><html><head><title>' + document.title + '</title>' + styles + '<style>@media print{button,.d-print-none,.no-print{display:none!important}}body{background:#fff}</style></head><body>' + source.innerHTML + '<\/body><\/html>');
    printWindow.document.close();
    printWindow.onload = function () { printWindow.print(); printWindow.onafterprint = function () { printWindow.close(); }; };
}
</script>
<?php require 'includes/footer.php'; ?>

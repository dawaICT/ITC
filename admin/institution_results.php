<?php
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/assessment_weighting_helpers.php';
error_reporting(0);
$records = [];
$report_info = null;
$number = 1;

if (isset($_POST['submit'])) {
    $Year = (int)($_POST['Year'] ?? 0);
    $program = trim($_POST['program'] ?? '');

    if ($Year > 0 && $program !== '') {
        $sql = "SELECT exams.Sid, students.Fname, students.Lname, exams.Course_Code, courses.course_name, semester_assessment.Total_CA, exams.Exam_marks, exams.Total_marks, exams.Year, programs.program_name FROM exams
            INNER JOIN courses ON courses.course_code = exams.Course_Code
            INNER JOIN semester_assessment ON exams.Course_Code = semester_assessment.Course_Code AND exams.Sid = semester_assessment.Sid AND exams.Year = semester_assessment.Year
            INNER JOIN students ON students.SID = exams.Sid
            INNER JOIN student_program ON students.SID = student_program.Sid
            INNER JOIN programs ON student_program.program_code = programs.program_code
            WHERE exams.Year = ? AND student_program.program_code = ?";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('is', $Year, $program);
            $stmt->execute();
            $results = $stmt->get_result();
            while ($row = $results->fetch_object()) {
                $records[] = $row;
            }
            $report_info = $records[0] ?? null;
            $stmt->close();
        }
    }
}

$page_title = 'Institution Results';
require 'includes/header.php';
?>
<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4">
        <div class="dashboard-header mb-4">
            <h1 class="dashboard-title">Institution Results</h1>
        </div>
        <div class="data-table-card">
            <div class="card-body">
                <div id="print">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>No.</th>
                                <th>SID</th>
                                <th>Student Name</th>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $r): ?>
                            <tr>
                                <td><?php echo $number++; ?>.</td>
                                <td><?php echo htmlspecialchars((string)$r->Sid, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(trim((string)$r->Fname . ' ' . (string)$r->Lname), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$r->Course_Code, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$r->course_name, ENT_QUOTES, 'UTF-8'); ?></td>
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
                                <td colspan="6" class="text-center text-muted py-4">
                                    No results found. Submit the search form to view institution results.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($report_info): ?>
                    <hr>
                    <div class="small mb-2">
                        <strong>Program of Study:</strong> <?php echo htmlspecialchars((string)($report_info->program_name ?? ''), ENT_QUOTES, 'UTF-8'); ?><br>
                        <strong>Exam Year:</strong> <?php echo htmlspecialchars((string)($report_info->Year ?? $Year ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <p class="small fst-italic">This is a result transcript generated from the official ITC online results portal.</p>
                    <?php endif; ?>
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

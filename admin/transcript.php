<?php
include "includes/admin.php";
include_once "upload_exam_results.php";
require_once dirname(__DIR__) . '/includes/assessment_weighting_helpers.php';
error_reporting(0);
$Recordz_1 = [];
$transcript_info = null;
$number = 1;
$no_records_error = '';

if (isset($_POST['submit'])) {
    $Sid    = trim((string)($_POST['Sid']    ?? ''));
    $period = (int)($_POST['period'] ?? 0);
    $Year   = (int)($_POST['Year']   ?? 0);

    require '../students/publishedResults.php';

    $sql = "SELECT s.SID, s.Fname, s.Lname, e.Sid, e.Course_Code, e.Exam_marks, e.Total_marks,
                   e.semester, e.Year, c.course_name, sa.Total_CA, p.program_name
            FROM students s
            INNER JOIN exams e ON s.SID = e.Sid
            INNER JOIN courses c ON e.Course_Code = c.course_code
            INNER JOIN semester_assessment sa
                ON c.course_code = sa.Course_Code
                AND sa.Sid = e.Sid
                AND sa.semester = e.semester
                AND sa.Year = e.Year
            INNER JOIN student_program sp ON sa.Sid = sp.Sid
            INNER JOIN programs p ON sp.program_code = p.program_code
            WHERE e.Sid = ?
              AND e.semester = ?
              AND e.Year = ?
            ORDER BY e.Course_Code";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('sii', $Sid, $period, $Year);
        $stmt->execute();
        $Results1 = $stmt->get_result();
        while ($Row = $Results1->fetch_object()) {
            $Recordz_1[] = $Row;
        }
        $transcript_info = $Recordz_1[0] ?? null;
        $stmt->close();

        if (empty($Recordz_1)) {
            $no_records_error = 'No Exam records were found for the selected year.';
        }
    }
}

$page_title = 'Student Transcript';
require 'includes/header.php';
?>
<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4">
        <div class="dashboard-header mb-4">
            <div class="d-flex align-items-center gap-3">
                <button onclick="history.back()" class="btn btn-outline-secondary btn-sm d-print-none">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </button>
                <h1 class="dashboard-title mb-0">Student Transcript</h1>
            </div>
        </div>

        <?php if ($no_records_error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($no_records_error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (!empty($Recordz_1)): ?>
        <div class="data-table-card">
            <div class="card-body">
                <div id="printScript">
                    <div class="text-center mb-3">
                        <img src="/wucportal/images/itc_logo.png" width="100" height="auto" alt="ITC Logo">
                        <h5 class="mt-2 mb-0"><strong>INDUSTRIAL TRAINING CENTRE</strong></h5>
                        <h6 class="text-muted">Transcript of Results</h6>
                    </div>
                    <hr>
                    <?php if ($transcript_info): ?>
                    <p class="small mb-1"><strong>SID:</strong> <?php echo htmlspecialchars($transcript_info->Sid ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="small mb-1"><strong>Student Name:</strong> <?php echo htmlspecialchars(trim(($transcript_info->Fname ?? '') . ' ' . ($transcript_info->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="small mb-1"><strong>Program of Study:</strong> <?php echo htmlspecialchars($transcript_info->program_name ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="small mb-1"><strong>Semester:</strong> <?php echo htmlspecialchars($transcript_info->semester ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="small mb-1"><strong>Year:</strong> <?php echo htmlspecialchars($transcript_info->Year ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <hr>
                    <?php endif; ?>

                    <table class="table table-hover align-middle small">
                        <thead class="table-dark">
                            <tr>
                                <th>No.</th>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Grade</th>
                                <th>GPA</th>
                                <th>Comment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($Recordz_1 as $r): ?>
                            <?php
                            $Total_Grade = assessment_weighting_total($db, (string)$r->Sid, $r->Total_CA, ($r->Exam_marks ?? $r->Total_marks));
                            if ($Total_Grade >= 90)      { $grade = 'A+'; $gpa = '4.00'; }
                            elseif ($Total_Grade >= 80)  { $grade = 'A';  $gpa = '3.74'; }
                            elseif ($Total_Grade >= 75)  { $grade = 'B+'; $gpa = '3.64'; }
                            elseif ($Total_Grade >= 70)  { $grade = 'B';  $gpa = '3.49'; }
                            elseif ($Total_Grade >= 65)  { $grade = 'B-'; $gpa = '3.25'; }
                            elseif ($Total_Grade >= 60)  { $grade = 'C+'; $gpa = '2.99'; }
                            elseif ($Total_Grade >= 50)  { $grade = 'C';  $gpa = '2.33'; }
                            elseif ($Total_Grade >= 45)  { $grade = 'D';  $gpa = '1.99'; }
                            else                          { $grade = 'E';  $gpa = '1.00'; }
                            ?>
                            <tr>
                                <td><?php echo $number++; ?>.</td>
                                <td><?php echo htmlspecialchars($r->Course_Code ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r->course_name ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($grade, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($gpa, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><em><?php echo $Total_Grade >= 45 ? 'Proceed' : 'Repeat'; ?></em></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($transcript_info): ?>
                    <p class="small">
                        This is to certify that <strong><?php echo htmlspecialchars(trim(($transcript_info->Fname ?? '') . ' ' . ($transcript_info->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                        Student no. <strong><?php echo htmlspecialchars($transcript_info->Sid ?? '', ENT_QUOTES, 'UTF-8'); ?></strong> is
                        studying <strong><?php echo htmlspecialchars($transcript_info->program_name ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>. The results in the
                        various subjects are tabulated above.
                    </p>
                    <?php endif; ?>

                    <p class="small text-muted fst-italic">
                        This transcript is not a certificate but represents student performance for the
                        particular academic semester at Industrial Training Centre.
                    </p>
                    <p class="small">
                        <u>GRADING SYSTEM GUIDE</u><br>
                        A<sup>+</sup>: 1st distinction &nbsp;|&nbsp; A: 2nd distinction &nbsp;|&nbsp;
                        B<sup>+</sup>: 3rd distinction &nbsp;|&nbsp; B: merit &nbsp;|&nbsp;
                        B<sup>-</sup>: merit &nbsp;|&nbsp; C<sup>+</sup>: credit &nbsp;|&nbsp;
                        C: pass &nbsp;|&nbsp; E: fail
                    </p>
                    <hr>
                    <div class="small">
                        <strong>AUTHENTICATION</strong><br>
                        Principal<br>
                        Dr. Mkhenzi<br>
                        <u>signed</u>
                    </div>
                </div>

                <button class="btn btn-success mt-3 d-print-none" onclick="printContent('printScript')">
                    <i class="fas fa-print me-2"></i>Print Transcript
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>
<script>
function printContent(printScript) {
    var source = document.getElementById(printScript);
    if (!source) return;
    if (window.wucPrintElement) {
        window.wucPrintElement(source, document.title);
        return;
    }
    var printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) { window.print(); return; }
    var styles = Array.prototype.map.call(document.querySelectorAll('link[rel="stylesheet"], style'), function (node) { return node.outerHTML; }).join('\n');
    printWindow.document.open();
    printWindow.document.write('<!DOCTYPE html><html><head><title>' + document.title + '</title>' + styles + '<style>@page{size:A4 portrait;margin:12mm}@media print{button,.d-print-none,.no-print{display:none!important}}body{background:#fff}</style></head><body>' + source.innerHTML + '<\/body><\/html>');
    printWindow.document.close();
    printWindow.onload = function () { printWindow.print(); printWindow.onafterprint = function () { printWindow.close(); }; };
}
</script>
<?php require 'includes/footer.php'; ?>

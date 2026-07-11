<?php
include "includes/admin.php";
error_reporting(0);
$records = [];
$student_info = null;
$number = 1;

if (isset($_GET['submit'])) {
    $Sid  = trim((string)($_GET['Sid']  ?? ''));
    $Year = (int)($_GET['Year'] ?? 0);

    $sql = "SELECT students.SID, students.Fname, students.Lname, semester_assessment.*, programs.program_name
            FROM students
            INNER JOIN semester_assessment ON students.SID = semester_assessment.Sid
            INNER JOIN student_program ON semester_assessment.Sid = student_program.Sid
            INNER JOIN programs ON student_program.program_code = programs.program_code
            WHERE semester_assessment.Sid = ? AND semester_assessment.Year = ?";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('si', $Sid, $Year);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($row = $results->fetch_object()) {
            $records[] = $row;
        }
        $student_info = $records[0] ?? null;
        $stmt->close();
    }
}

$page_title = 'Student CA Sheet';
require 'includes/header.php';
?>
<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4">
        <div class="dashboard-header mb-4">
            <div class="d-flex align-items-center gap-3">
                <button onclick="history.back()" class="btn btn-outline-secondary btn-sm d-print-none">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </button>
                <h1 class="dashboard-title mb-0">Student CA Sheet</h1>
            </div>
        </div>

        <div class="data-table-card">
            <div class="card-body">
                <div id="print">
                    <div class="text-center mb-3">
                        <img src="/wucportal/images/itc_logo.png" width="100" height="auto" alt="ITC Logo">
                        <h5 class="mt-2 mb-0"><strong>INDUSTRIAL TRAINING CENTRE</strong></h5>
                        <h6 class="text-muted">Student Continuous Assessment Sheet</h6>
                    </div>
                    <hr>
                    <?php if ($student_info): ?>
                    <p class="small mb-1"><strong>SID:</strong> <?php echo htmlspecialchars($student_info->SID ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="small mb-1"><strong>Student Name:</strong> <?php echo htmlspecialchars(trim(($student_info->Fname ?? '') . ' ' . ($student_info->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="small mb-1"><strong>Program of Study:</strong> <?php echo htmlspecialchars($student_info->program_name ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <hr>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle small">
                            <thead class="table-dark">
                                <tr>
                                    <th>No.</th>
                                    <th>Course Code</th>
                                    <th>S1A1</th>
                                    <th>S1A2</th>
                                    <th>S1T1</th>
                                    <th>S1T2</th>
                                    <th>S2A1</th>
                                    <th>S2A2</th>
                                    <th>S2T1</th>
                                    <th>Total CA</th>
                                    <th>Session/Year</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $r): ?>
                                <tr>
                                    <td><?php echo $number++; ?>.</td>
                                    <td><?php echo htmlspecialchars($r->Course_Code ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->S1A1 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->S1A2 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->S1T1 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->S1T2 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->S2A1 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->S2A2 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->S2T1 ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><strong><?php echo htmlspecialchars($r->Total_CA ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($r->Year ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted py-4">No CA records found.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <button class="btn btn-success mt-3 d-print-none" onclick="printContent('print')">
                    <i class="fas fa-print me-2"></i>Print CA Sheet
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

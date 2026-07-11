<?php
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/../includes/applicant_admission.php';

if (!empty($_POST) && isset($_POST['Sid'], $_POST['program_code'], $_POST['intake'], $_POST['mode'], $_POST['startYear'])) {
    $Sid = trim((string)$_POST['Sid']);
    $programCode = trim((string)$_POST['program_code']);
    $intake = trim((string)$_POST['intake']);
    $mode = trim((string)$_POST['mode']);
    $entryYear = (int)substr(trim((string)$_POST['startYear']), 0, 4) ?: (int)date('Y');

    $result = admissionsEnrollExistingStudent($db, $Sid, $programCode, $intake, $mode, $entryYear);
    $dest = $result['success'] ? 'students_by_admin.php' : 'admitStudent.php';
    echo '<script>alert(' . json_encode($result['message']) . ");window.location.href='" . htmlspecialchars($dest, ENT_QUOTES, 'UTF-8') . "';</script>";
    exit;
}

$studentRecord = null;
if ($res = $db->query('SELECT SID, Fname, Lname FROM students ORDER BY dte_adm DESC LIMIT 1')) {
    $studentRecord = $res->fetch_object();
    $res->free();
}

$programs = [];
if ($res = $db->query("SELECT program_code, program_name FROM programs WHERE COALESCE(is_active, 1) = 1 ORDER BY program_name")) {
    while ($row = $res->fetch_object()) {
        $programs[] = $row;
    }
    $res->free();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admit Student | Registrar</title>
<link rel="stylesheet" type="text/css" href="../css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="../js/jquery-3.5.1.min.js"></script>
<script src="../js/bootstrap.min.js"></script>
</head>
<body class="p-4">
<div class="container" style="max-width:720px;">
    <h3 class="mb-3"><i class="fas fa-user-check text-primary me-2"></i>Student Admission</h3>
    <p class="text-muted">Enrols the student into a programme, creates portal login, assigns courses, and raises an invoice.</p>
    <form action="admitStudent.php" method="post" class="card p-4 shadow-sm border-0">
        <div class="mb-3">
            <label for="Sid" class="form-label">Student ID (SID)</label>
            <input type="text" class="form-control" id="Sid" name="Sid" required
                   value="<?php echo htmlspecialchars((string)($studentRecord->SID ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="mb-3">
            <label for="program_code" class="form-label">Programme</label>
            <select class="form-control" name="program_code" id="program_code" required>
                <option value="" disabled selected>Select programme</option>
                <?php foreach ($programs as $program): ?>
                <option value="<?php echo htmlspecialchars($program->program_code, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($program->program_name, ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="intake" class="form-label">Intake</label>
            <select class="form-control" name="intake" id="intake" required>
                <option value="" disabled selected>Select intake</option>
                <option>January <?php echo date('Y'); ?></option>
                <option>May <?php echo date('Y'); ?></option>
                <option>September <?php echo date('Y'); ?></option>
            </select>
        </div>
        <div class="mb-3">
            <label for="mode" class="form-label">Mode of study</label>
            <select class="form-control" name="mode" id="mode" required>
                <option value="" disabled selected>Select mode</option>
                <option>Full-time</option>
                <option>Part-time</option>
                <option>Distance</option>
            </select>
        </div>
        <div class="mb-3">
            <label for="startYear" class="form-label">Start date</label>
            <input type="date" class="form-control" id="startYear" name="startYear" required
                   value="<?php echo date('Y-m-d'); ?>">
        </div>
        <button type="submit" class="btn btn-primary">Admit student</button>
        <a href="students_by_admin.php" class="btn btn-outline-secondary ms-2">Back to students</a>
    </form>
</div>
</body>
</html>

<?php

declare(strict_types=1);

// Legacy CA viewer — redirect to the annual CA report page.
require_once __DIR__ . '/includes/guard.php';

$redirect = 'continuousAssessment.php';
if (!empty($_GET['Year'])) {
    $redirect .= '?academic_year=' . rawurlencode((string)$_GET['Year']);
}
header('Location: ' . $redirect, true, 302);
exit;

// --- legacy code below (unreachable) ---
$wucDebug = isset($_GET['debug'])
    && (string)$_GET['debug'] === 'true'
    && function_exists('wuc_should_show_error_details')
    && wuc_should_show_error_details();
if ($wucDebug) {
    ini_set('display_errors', '0');
}

// Build available CA terms for this student
$terms = [];
$byYear = [];
$selectedYear = isset($_GET['Year']) ? (string)trim($_GET['Year']) : '';
$selectedSemester = isset($_GET['semester']) ? (string)trim($_GET['semester']) : '';

$sid = (string)($_SESSION['Sid'] ?? '');
$latestYear = '';
$latestSem = '';

if ($sid !== '') {
    $sqlTerms = "SELECT DISTINCT Year, semester FROM semester_assessment WHERE Sid='" . $db->real_escape_string($sid) . "' ORDER BY Year DESC, semester DESC";
    if ($res = $db->query($sqlTerms)) {
        while ($row = $res->fetch_assoc()) {
            $yr = (string)($row['Year'] ?? '');
            $sm = (string)($row['semester'] ?? '');
            if ($yr === '' || $sm === '') { continue; }
            $terms[] = ['Year' => $yr, 'semester' => $sm];
            $byYear[$yr] = isset($byYear[$yr]) ? array_values(array_unique(array_merge($byYear[$yr], [$sm]))) : [$sm];
        }
        $res->free();
    }
}

// Determine selected/default term
if (!empty($terms)) {
    $latestYear = (string)$terms[0]['Year'];
    $latestSem = (string)$terms[0]['semester'];
}
if ($selectedYear === '' || $selectedSemester === '') {
    $selectedYear = $latestYear;
    $selectedSemester = $latestSem;
} else {
    // Validate selection exists; otherwise fallback to latest
    $valid = false;
    foreach ($terms as $t) {
        if ((string)$t['Year'] === (string)$selectedYear && (string)$t['semester'] === (string)$selectedSemester) { $valid = true; break; }
    }
    if (!$valid) { $selectedYear = $latestYear; $selectedSemester = $latestSem; }
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>View CAs - ITC</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
<link rel="stylesheet" href="/wucportal/css/admin-style.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">

<style>
    /* Use global sidebar spacing; avoid fixed offsets */
    .content-wrapper { padding: 2rem 1.25rem; }

    .container {
        padding: 2rem 0;
        max-width: 90%;
    }

    .page-header {
        border-bottom: 2px solid #6f42c1;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .back-button {
        text-decoration: none;
        color: white;
        background: #6f42c1;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 14px;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn {
        padding: 8px 16px;
        border-radius: 20px;
        border: none;
        transition: all 0.3s ease;
        text-transform: uppercase;
        font-size: 12px;
        font-weight: bold;
        letter-spacing: 1px;
        color: inherit;
    }

    .btn-danger { background: #dc3545; color: white; }
    .btn-outline-dark { border: 1px solid #6c757d; color: #6c757d; }
    .btn-outline-dark:hover { background: #6c757d; color: white; }

    .back-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        opacity: 0.9;
        color: white;
        text-decoration: none;
    }

    .card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        margin-bottom: 1.5rem;
        transition: transform 0.3s ease;
    }

    .card:hover {
        transform: translateY(-5px);
    }

    .card-body {
        padding: 2rem;
    }

    .form-control {
        border-radius: 8px;
        border: 1px solid #ddd;
        padding: 0.75rem;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }

    .form-control:focus {
        border-color: #6f42c1;
        box-shadow: 0 0 0 0.2rem rgba(111, 66, 193, 0.25);
    }

    .form-label {
        font-weight: 600;
        color: #444;
        margin-bottom: 0.5rem;
    }

    .btn-submit {
        background: #6f42c1;
        color: white;
        border: none;
        border-radius: 20px;
        padding: 12px 24px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
        transition: all 0.3s ease;
        width: 100%;
    }

    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        opacity: 0.9;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead {
        background-color: #6f42c1;
        color: white;
    }

    .table th {
        padding: 15px !important;
        font-weight: 600;
        border-bottom: none;
    }

    .table td {
        padding: 12px 15px !important;
        vertical-align: middle;
    }

    .table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .alert {
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
        text-align: center;
    }

    .footer {
        background: #6f42c1;
        color: white;
        padding: 20px;
        border-radius: 8px;
        margin-top: 30px;
        text-align: center;
    }

    @media (max-width: 768px) {
        .content-wrapper {
            margin-left: 0;
            right: 0;
            width: 100%;
        }

        .container {
            padding: 1rem;
        }

        .card-body {
            padding: 1rem;
        }
    }
</style>

<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    <div class="content-wrapper">
        <div class="container">
            <div class="row">
                <div class="col-12 animate__animated animate__fadeIn">
                    <div class="page-header">
                        <a href="myCourses.php" class="back-button">
                            <i class="fas fa-arrow-left"></i> Back to Courses
                        </a>
                        <h2 class="text-primary">View CAs</h2>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <?php if(!empty($terms)) { ?>
                            <form role="form" method="GET" action="view_ca.php" class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label class="form-label" for="Year">Academic Year</label>
                                    <select class="form-select" id="Year" name="Year" required>
                                        <?php
                                        $printedYears = [];
                                        foreach ($terms as $t) {
                                            $yr = (string)$t['Year'];
                                            if (isset($printedYears[$yr])) { continue; }
                                            $printedYears[$yr] = true;
                                            $sel = ($yr === (string)$selectedYear) ? ' selected' : '';
                                            echo '<option value="'.htmlspecialchars($yr).'"'.$sel.'>'.htmlspecialchars($yr).'</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="semester">Semester</label>
                                    <select class="form-select" name="semester" id="semester" required>
                                        <?php
                                        $semList = isset($byYear[(string)$selectedYear]) ? $byYear[(string)$selectedYear] : [];
                                        foreach ($semList as $sm) {
                                            $sel = ((string)$sm === (string)$selectedSemester) ? ' selected' : '';
                                            echo '<option value="'.htmlspecialchars((string)$sm).'"'.$sel.'>Semester '.htmlspecialchars((string)$sm).'</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button class="btn-submit" type="submit" name="submit">
                                        <i class="fas fa-filter me-1"></i> Filter
                                    </button>
                                </div>
                            </form>
                            <script>
                            (function(){
                                var map = <?php echo json_encode($byYear ?? []); ?>;
                                var y = document.getElementById('Year');
                                var s = document.getElementById('semester');
                                function refresh(){
                                    if (!y || !s) return;
                                    var list = map[y.value] || [];
                                    s.innerHTML = '';
                                    list.forEach(function(v){ var o=document.createElement('option'); o.value=v; o.textContent='Semester ' + v; s.appendChild(o); });
                                }
                                if (y) { y.addEventListener('change', refresh); }
                            })();
                            </script>
                            <?php } ?>

                            <?php
                            $records = [];
                            $number = 1;

                            if ($sid !== '' && $selectedYear !== '' && $selectedSemester !== '') {
                                $q = "SELECT sa.Course_Code, sa.A1, sa.A2, sa.T1, sa.T2, sa.Total_CA\n"
                                   . "FROM semester_assessment sa\n"
                                   . "WHERE sa.Sid='".$db->real_escape_string($sid)."'\n"
                                   . "  AND sa.Year='".$db->real_escape_string((string)$selectedYear)."'\n"
                                   . "  AND sa.semester='".$db->real_escape_string((string)$selectedSemester)."'\n"
                                   . "  AND sa.status = 'Published'\n"
                                   . "ORDER BY sa.Course_Code";
                                if ($results = $db->query($q)) {
                                    if ($results->num_rows > 0) {
                                        while ($row = $results->fetch_object()) { $records[] = $row; }
                                        $results->free();
                                        echo '<div class="alert alert-success">Showing CAs for Semester '.htmlspecialchars((string)$selectedSemester).' · Year '.htmlspecialchars((string)$selectedYear).'</div>';
                                    } else {
                                        echo '<div class="alert alert-info">No CAs have been uploaded for the selected term.</div>';
                                    }
                                }
                            } else {
                                echo '<div class="alert alert-warning">No CA records available.</div>';
                            }
                            ?>

                            <?php if(count($records) > 0): ?>
                            <div class="table-responsive mt-4">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>No.</th>
                                            <th>Course Code</th>
                                            <th>A1</th>
                                            <th>A2</th>
                                            <th>T1</th>
                                            <th>T2</th>
                                            <th>Total CAs</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($records as $r): ?>
                                        <tr>
                                            <td class="text-muted"><?php echo $number++; ?>.</td>
                                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)$r->Course_Code); ?></span></td>
                                            <td><?php echo htmlspecialchars((string)$r->A1); ?></td>
                                            <td><?php echo htmlspecialchars((string)$r->A2); ?></td>
                                            <td><?php echo htmlspecialchars((string)$r->T1); ?></td>
                                            <td><?php echo htmlspecialchars((string)$r->T2); ?></td>
                                            <td><strong><?php echo htmlspecialchars((string)$r->Total_CA); ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


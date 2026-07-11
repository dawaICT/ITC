<?php
// Use shared admin bootstrap (session + db connection)
require_once 'includes/admin.php';
// Ensure errors are logged early (before header include)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Page state
$errors = [];
$studentData = null;
$searchedSid = '';

// Generate CSRF token once
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle POST (search) before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['token'])) {
        $errors[] = 'Invalid security token.';
    } else {
        $sid = trim((string)($_POST['Sid'] ?? ''));
        if ($sid === '') {
            $errors[] = 'Student ID is required';
        } elseif (!preg_match('/^[A-Za-z0-9]+$/', $sid)) {
            $errors[] = 'Student ID must contain only letters and numbers';
        } else {
            $searchedSid = $sid;
            // Execute search directly (avoid redirect to prevent header issues)
            if (!isset($db)) {
                $errors[] = 'Database connection not available. Please try again later.';
            } elseif ($stmt = $db->prepare(
                "SELECT s.SID, s.Fname, s.Lname, COALESCE(p.program_name, 'N/A') AS program_name\n"
                . " FROM students s\n"
                . " LEFT JOIN student_program sp ON s.SID = sp.Sid\n"
                . " LEFT JOIN programs p ON sp.program_code = p.program_code\n"
                . " WHERE s.SID = ?\n"
                . " LIMIT 1"
            )) {
                $stmt->bind_param('s', $searchedSid);
                try {
                    if ($stmt->execute()) {
                        $stmt->bind_result($SID, $Fname, $Lname, $programName);
                        if ($stmt->fetch()) {
                            $studentData = [
                                'SID' => $SID,
                                'Fname' => $Fname,
                                'Lname' => $Lname,
                                'program_name' => $programName,
                            ];
                        } else {
                            $errors[] = 'Student not found';
                        }
                    } else {
                        $errors[] = 'Error searching for student. Please try again.';
                    }
                } catch (Exception $e) {
                    error_log('Student search error: ' . $e->getMessage());
                    $errors[] = 'Error searching for student. Please try again.';
                }
                $stmt->close();
            } else {
                $errors[] = 'Database error. Please try again later.';
            }
        }
    }
}

// Handle GET display (after redirect or direct link)
if (isset($_GET['sid'])) {
    $searchedSid = trim((string)$_GET['sid']);
    if ($searchedSid !== '' && preg_match('/^[A-Za-z0-9]+$/', $searchedSid)) {
        if (!isset($db)) {
            $errors[] = 'Database connection not available. Please try again later.';
        } elseif ($stmt = $db->prepare(
            "SELECT s.SID, s.Fname, s.Lname, COALESCE(p.program_name, 'N/A') AS program_name\n"
            . " FROM students s\n"
            . " LEFT JOIN student_program sp ON s.SID = sp.Sid\n"
            . " LEFT JOIN programs p ON sp.program_code = p.program_code\n"
            . " WHERE s.SID = ?\n"
            . " LIMIT 1"
        )) {
            $stmt->bind_param('s', $searchedSid);
            if ($stmt->execute()) {
                $stmt->bind_result($SID, $Fname, $Lname, $programName);
                if ($stmt->fetch()) {
                    $studentData = [
                        'SID' => $SID,
                        'Fname' => $Fname,
                        'Lname' => $Lname,
                        'program_name' => $programName,
                    ];
                } else {
                    $errors[] = 'Student not found';
                }
            } else {
                $errors[] = 'Error searching for student. Please try again.';
            }
            $stmt->close();
        } else {
            $errors[] = 'Database error. Please try again later.';
        }
    } else if ($searchedSid !== '') {
        $errors[] = 'Invalid Student ID format';
    }
}

// Title for header
$page_title = 'Edit Student by ID';
require_once 'includes/header.php';

// Optional: component styles used across admin dashboard
echo '<link rel="stylesheet" href="' . htmlspecialchars(dirname($_SERVER['PHP_SELF'])) . '/css/admin-dashboard.css">';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-search me-2"></i>Student Edit</h5>
                        <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                    </div>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="<?php echo htmlspecialchars(basename(__FILE__), ENT_QUOTES); ?>" class="needs-validation" novalidate>
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES); ?>">
                        <div class="row g-3 align-items-end">
                            <div class="col-12 col-md-8">
                                <label for="Sid" class="form-label">Student ID</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                    <input type="text"
                                           class="form-control"
                                           name="Sid"
                                           id="Sid"
                                           placeholder="Enter student ID"
                                           value="<?php echo htmlspecialchars($searchedSid, ENT_QUOTES); ?>"
                                           pattern="^[A-Za-z0-9]+$"
                                           maxlength="20"
                                           required>
                                </div>
                                <div class="form-text">Use alphanumeric ID (max 20 characters)</div>
                            </div>
                            <div class="col-12 col-md-4 text-md-end">
                                <div class="d-grid d-md-flex gap-2 justify-content-md-end">
                                    <button type="reset" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-counterclockwise"></i> Clear
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i> Search Student
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger mt-4">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo htmlspecialchars($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($studentData): ?>
                        <div class="mt-4">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <tbody>
                                        <tr>
                                            <th style="width: 200px;">Student ID</th>
                                            <td><?php echo htmlspecialchars($studentData['SID']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Name</th>
                                            <td><?php echo htmlspecialchars($studentData['Fname'] . ' ' . $studentData['Lname']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Program</th>
                                            <td><?php echo htmlspecialchars($studentData['program_name']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Actions</th>
                                            <td>
                                                <a href="editStudent.php?update=<?php echo urlencode($studentData['SID']); ?>" class="btn btn-sm btn-warning">
                                                    <i class="bi bi-pencil-square"></i> Edit Student
                                                </a>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const searchInput = document.getElementById('Sid');

    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    });

    searchInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^A-Za-z0-9]/g, '').slice(0, 20);
    });
});
</script>

<?php require 'includes/footer.php'; ?>

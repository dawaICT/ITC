<?php
include "includes/admin.php";
include "includes/header.php";
$page_title = "Edit Program";

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Invalid program ID!'
        }).then(function() {
            window.location.href = 'programs.php';
        });
    </script>";
    exit;
}

$program_code = trim($_GET['id']);

// Handle form submission
if (isset($_POST['update_program'])) {
    $program_name = trim($_POST['program_name']);
    // Registration period model (semester vs term) — the field that drives
    // Registration & Enrolment. study_mode (Full Time/Part Time) and the granular
    // program_type are left untouched here to avoid clobbering them.
    $period_mode = strtolower(trim($_POST['period_mode'] ?? 'semester'));
    $period_mode = $period_mode === 'term' ? 'term' : 'semester';

    // Start transaction
    $db->begin_transaction();

    try {
        // Update program details against the real schema.
        $update_query = "UPDATE programs SET
            program_name = ?,
            period_mode = ?
            WHERE program_code = ?";

        $update_stmt = $db->prepare($update_query);
        $update_stmt->bind_param(
            "sss",
            $program_name, $period_mode, $program_code
        );

        if (!$update_stmt->execute()) {
            throw new Exception("Error updating program: " . $db->error);
        }

        // Commit transaction
        $db->commit();

        echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: 'Program updated successfully!',
                showConfirmButton: false,
                timer: 1500
            }).then(function() {
                window.location.href = 'programs.php';
            });
        </script>";

    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollback();

        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '" . addslashes($e->getMessage()) . "'
            });
        </script>";
    }
}

// Fetch program details
$query = "SELECT * FROM programs WHERE program_code = ?";
$stmt = $db->prepare($query);
$stmt->bind_param("s", $program_code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Program not found or has been deleted!'
        }).then(function() {
            window.location.href = 'programs.php';
        });
    </script>";
    exit;
}

$program = $result->fetch_object();
?>

<div class="content-wrapper">
    <div class="dashboard-header">
        <h1 class="welcome-message">Edit Program</h1>
        <div class="header-actions">
            <a href="programs.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Programs
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" id="editProgramForm">
                <div class="form-group">
                    <label class="form-label" for="program_code">Program Code</label>
                    <input class="form-control" type="text" id="program_code" 
                           value="<?php echo htmlspecialchars($program->program_code); ?>" 
                           readonly disabled>
                    <small class="text-muted">Program code cannot be changed</small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="program_name">Program Name</label>
                    <input class="form-control" type="text" name="program_name" id="program_name" 
                           value="<?php echo htmlspecialchars($program->program_name); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="program_type">Program Type</label>
                    <input class="form-control" type="text" id="program_type"
                           value="<?php echo htmlspecialchars((string)($program->program_type ?? 'N/A')); ?>"
                           readonly disabled>
                    <small class="text-muted">Managed via the program catalogue.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="period_mode">Registration Period</label>
                    <?php $currentPeriodMode = strtolower((string)($program->period_mode ?? 'semester')); ?>
                    <select class="form-control" name="period_mode" id="period_mode" required>
                        <option value="semester" <?php echo $currentPeriodMode !== 'term' ? 'selected' : ''; ?>>
                            Semester Based (2 per year)
                        </option>
                        <option value="term" <?php echo $currentPeriodMode === 'term' ? 'selected' : ''; ?>>
                            Term Based (3 per year)
                        </option>
                    </select>
                    <small class="text-muted">Controls how students on this program register (semester vs term intakes).</small>
                </div>

                <div class="btn-container">
                    <button type="submit" name="update_program" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Program
                    </button>
                    <a href="programs.php" class="btn btn-danger">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include "includes/footer.php"; ?>

<script>
$(document).ready(function() {
    // Form validation
    $('#editProgramForm').on('submit', function(e) {
        var programName = $('#program_name').val();
        var periodMode = $('#period_mode').val();

        if (!programName || !periodMode) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Please fill in all required fields!'
            });
            return false;
        }
    });
});
</script>
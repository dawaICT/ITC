<?php
include "includes/admin.php";
error_reporting(0);

if (isset($_GET['id'])) {
    $program_code = $_GET['id'];

    $query = "SELECT * FROM programs WHERE program_code = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("s", $program_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $program = $result->fetch_object();
}

function formatDuration($years): string {
    if ($years === null || $years === '' || (float)$years <= 0) {
        return 'N/A';
    }
    $years = round((float)$years, 2);
    if ($years < 1) {
        $months = max(1, (int)round($years * 12));
        return $months . " month" . ($months === 1 ? "" : "s");
    }
    $label = rtrim(rtrim(number_format($years, 2, '.', ''), '0'), '.');
    return $label . " year" . ($years == 1.0 ? "" : "s");
}

$page_title = 'Program Details';
require 'includes/header.php';
?>
<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4">
        <div class="dashboard-header mb-4">
            <div class="d-flex align-items-center gap-3">
                <a href="programs.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Programs
                </a>
                <h1 class="dashboard-title mb-0">Program Details</h1>
            </div>
        </div>

        <?php if (isset($program) && $program): ?>
        <div class="data-table-card" style="max-width:600px;">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Program Code</dt>
                    <dd class="col-sm-8"><?php echo htmlspecialchars($program->program_code ?? '', ENT_QUOTES, 'UTF-8'); ?></dd>

                    <dt class="col-sm-4 text-muted">Program Name</dt>
                    <dd class="col-sm-8"><?php echo htmlspecialchars($program->program_name ?? '', ENT_QUOTES, 'UTF-8'); ?></dd>

                    <dt class="col-sm-4 text-muted">Duration</dt>
                    <dd class="col-sm-8"><span class="badge bg-secondary"><?php echo htmlspecialchars(formatDuration($program->program_duration ?? null), ENT_QUOTES, 'UTF-8'); ?></span></dd>
                </dl>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">Program not found.</div>
        <?php endif; ?>
    </div>
</main>
<?php require 'includes/footer.php'; ?>

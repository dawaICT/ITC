<?php
$page_title = 'Course Fee Breakdown Setup';
require "includes/nav.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
        $error = 'CSRF validation failed.';
    } else {
        $action = $_POST['action'] ?? '';
    
    if ($action === 'link') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $fee_item_id = (int)($_POST['fee_item_id'] ?? 0);

        if ($course_id <= 0 || $fee_item_id <= 0) {
            $error = 'Both course and fee item are required.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO course_fee_breakdown (course_id, fee_item_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=id");
                $stmt->bind_param('ii', $course_id, $fee_item_id);
                if ($stmt->execute()) {
                    $message = 'Fee item linked to course successfully.';
                } else {
                    $error = 'A database error occurred. Please try again.';
                    error_log("Database error in accounts/fees_course_fee_breakdown.php: " . $db->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'unlink') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $fee_item_id = (int)($_POST['fee_item_id'] ?? 0);
        
        if ($course_id > 0 && $fee_item_id > 0) {
            $stmt = $db->prepare("DELETE FROM course_fee_breakdown WHERE course_id = ? AND fee_item_id = ? LIMIT 1");
            $stmt->bind_param('ii', $course_id, $fee_item_id);
            if ($stmt->execute()) {
                $message = 'Link removed successfully.';
            } else {
                $error = 'A database error occurred. Please try again.';
                error_log("Database error in accounts/fees_course_fee_breakdown.php: " . $db->error);
            }
            $stmt->close();
        }
    }
    }
}

// Fetch active courses
$activeCourses = [];
$res = $db->query("SELECT id, course_name, course_code FROM courses WHERE status = 'active' ORDER BY course_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $activeCourses[] = $row;
    }
    $res->free();
}

// Fetch active fee items
$activeFeeItems = [];
$res = $db->query("SELECT id, name, amount, academic_year, collection_type FROM fee_items WHERE status = 'active' ORDER BY academic_year DESC, name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $activeFeeItems[] = $row;
    }
    $res->free();
}

// Fetch current breakdown maps
$breakdown = [];
$query = "SELECT cfb.course_id, cfb.fee_item_id, c.course_name, c.course_code, fi.name AS item_name, fi.amount, fi.mandatory_status, fi.collection_type, fi.academic_year
          FROM course_fee_breakdown cfb
          INNER JOIN courses c ON cfb.course_id = c.id
          INNER JOIN fee_items fi ON cfb.fee_item_id = fi.id
          ORDER BY c.course_name ASC, fi.name ASC";
$res = $db->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $breakdown[$row['course_id']]['course_name'] = $row['course_name'];
        $breakdown[$row['course_id']]['course_code'] = $row['course_code'];
        $breakdown[$row['course_id']]['items'][] = [
            'id' => $row['fee_item_id'],
            'name' => $row['item_name'],
            'amount' => $row['amount'],
            'mandatory' => $row['mandatory_status'],
            'collection_type' => $row['collection_type'],
            'year' => $row['academic_year']
        ];
    }
    $res->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header finance-section mb-4 mt-2">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-chart-pie me-2 text-primary"></i>Course Fee Breakdowns</h1>
                <p class="text-muted mb-0">Link additional mandatory/optional fee items to active courses to structure their overall fee profiles.</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" onclick="openLinkModal()">
                    <i class="fas fa-plus me-2"></i>Link Fee Item
                </button>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php if (empty($breakdown)): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4 text-center py-5 text-muted">
                    <div class="card-body">
                        <i class="fas fa-project-diagram fa-3x mb-3 d-block"></i> No fee breakdowns mapped yet.
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($breakdown as $courseId => $cDetails): ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="fw-bold mb-0 text-primary"><?= htmlspecialchars($cDetails['course_name']) ?></h5>
                                <span class="badge bg-light text-dark font-monospace mt-1"><?= htmlspecialchars($cDetails['course_code']) ?></span>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($cDetails['items'] as $item): ?>
                                    <li class="list-group-item border-0 px-0 d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-bold text-dark mb-0 small"><?= htmlspecialchars($item['name']) ?></div>
                                            <div class="text-muted" style="font-size:0.75rem;">
                                                ZMW <?= number_format($item['amount'], 2) ?> | 
                                                <?= htmlspecialchars($item['collection_type']) ?> | 
                                                <?= htmlspecialchars($item['year']) ?>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($item['mandatory'] === 'mandatory'): ?>
                                                <span class="badge bg-soft-danger text-danger rounded-pill px-2">Mandatory</span>
                                            <?php else: ?>
                                                <span class="badge bg-soft-secondary text-secondary rounded-pill px-2">Optional</span>
                                            <?php endif; ?>
                                            
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="action" value="unlink">
                                                <input type="hidden" name="course_id" value="<?= htmlspecialchars($courseId) ?>">
                                                <input type="hidden" name="fee_item_id" value="<?= htmlspecialchars($item['id']) ?>">
                                                <button type="submit" class="btn btn-link text-danger p-0" title="Remove Link" onclick="return confirm('Remove this fee item from course?');">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="linkModal" tabindex="-1" aria-labelledby="linkModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="linkModalLabel">Link Fee Item to Course</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="link">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="l_course" class="form-label fw-semibold">Course</label>
                        <select class="form-select rounded-3" name="course_id" id="l_course" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach ($activeCourses as $c): ?>
                                <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['course_name']) ?> (<?= htmlspecialchars($c['course_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="l_item" class="form-label fw-semibold">Additional Fee Item</label>
                        <select class="form-select rounded-3" name="fee_item_id" id="l_item" required>
                            <option value="">-- Select Fee Item --</option>
                            <?php foreach ($activeFeeItems as $item): ?>
                                <option value="<?= htmlspecialchars($item['id']) ?>"><?= htmlspecialchars($item['name']) ?> (ZMW <?= number_format($item['amount'], 2) ?>) [<?= htmlspecialchars($item['academic_year']) ?>]</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Link Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// The unified layout gives `.main-content` `position: relative; z-index: 1`
// (so the body watermark shows through), which creates a stacking context that
// traps any modal inside it BELOW Bootstrap's body-level backdrop — the modal
// then renders grayed out and unclickable. Reparent the modal to <body> so it
// and the backdrop share the root stacking context and the modal sits on top.
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('linkModal');
    if (modalEl && modalEl.parentNode !== document.body) {
        document.body.appendChild(modalEl);
    }
});

function openLinkModal() {
    document.getElementById('l_course').value = '';
    document.getElementById('l_item').value = '';
    var myModal = new bootstrap.Modal(document.getElementById('linkModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

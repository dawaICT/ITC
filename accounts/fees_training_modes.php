<?php
$page_title = 'Manage Training Modes';
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
    
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['mode_name'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($name === '') {
            $error = 'Training mode name is required.';
        } else {
            try {
                // Check duplicate
                $check = $db->prepare("SELECT id FROM training_modes WHERE mode_name = ? AND id != ? LIMIT 1");
                $check->bind_param('si', $name, $id);
                $check->execute();
                $dup = $check->get_result()->num_rows > 0;
                $check->close();

                if ($dup) {
                    $error = "Training mode '$name' already exists.";
                } else {
                    if ($id > 0) {
                        $stmt = $db->prepare("UPDATE training_modes SET mode_name = ?, status = ? WHERE id = ?");
                        $stmt->bind_param('ssi', $name, $status, $id);
                        if ($stmt->execute()) {
                            $message = 'Training mode updated successfully.';
                        } else {
                            $error = 'A database error occurred. Please try again.';
                            error_log("Database error in accounts/fees_training_modes.php: " . $db->error);
                        }
                        $stmt->close();
                    } else {
                        $stmt = $db->prepare("INSERT INTO training_modes (mode_name, status) VALUES (?, ?)");
                        $stmt->bind_param('ss', $name, $status);
                        if ($stmt->execute()) {
                            $message = 'Training mode created successfully.';
                        } else {
                            $error = 'A database error occurred. Please try again.';
                            error_log("Database error in accounts/fees_training_modes.php: " . $db->error);
                        }
                        $stmt->close();
                    }
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
    }
}

// Fetch modes
$modes = [];
$res = $db->query("SELECT * FROM training_modes ORDER BY mode_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $modes[] = $row;
    }
    $res->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header finance-section mb-4 mt-2">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-book-open me-2 text-primary"></i>Manage Training Modes</h1>
                <p class="text-muted mb-0">Create and toggle active/inactive status for training modes (Full Time, Evening, Distance, etc.).</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus me-2"></i>Add Training Mode
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

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Mode Name</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($modes)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    <i class="fas fa-book-open fa-2x mb-2 d-block"></i> No training modes configured yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($modes as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['id']) ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($m['mode_name']) ?></td>
                                    <td>
                                        <?php if ($m['status'] === 'active'): ?>
                                            <span class="badge bg-success rounded-pill px-3 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill px-3 py-1">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-outline-primary btn-sm rounded-pill px-3" 
                                                onclick='openEditModal(<?= htmlspecialchars(json_encode($m)) ?>)'>
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="modeModal" tabindex="-1" aria-labelledby="modeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="modeModalLabel">Add Training Mode</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="mode_id" value="0">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="m_name" class="form-label fw-semibold">Mode Name</label>
                        <input type="text" class="form-control rounded-3" name="mode_name" id="m_name" required placeholder="e.g., Distance">
                    </div>
                    <div class="mb-3">
                        <label for="m_status" class="form-label fw-semibold">Status</label>
                        <select class="form-select rounded-3" name="status" id="m_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Save Changes</button>
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
    var modalEl = document.getElementById('modeModal');
    if (modalEl && modalEl.parentNode !== document.body) {
        document.body.appendChild(modalEl);
    }
});

function openAddModal() {
    document.getElementById('modeModalLabel').innerText = 'Add Training Mode';
    document.getElementById('mode_id').value = '0';
    document.getElementById('m_name').value = '';
    document.getElementById('m_status').value = 'active';
    var myModal = new bootstrap.Modal(document.getElementById('modeModal'));
    myModal.show();
}

function openEditModal(m) {
    document.getElementById('modeModalLabel').innerText = 'Edit Training Mode';
    document.getElementById('mode_id').value = m.id;
    document.getElementById('m_name').value = m.mode_name;
    document.getElementById('m_status').value = m.status;
    var myModal = new bootstrap.Modal(document.getElementById('modeModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
$page_title = 'Manage Additional Fee Items';
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
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $amount = isset($_POST['amount']) && is_numeric($_POST['amount']) ? (float)$_POST['amount'] : 0.00;
        $mandatory_status = $_POST['mandatory_status'] ?? 'mandatory';
        $collection_type = $_POST['collection_type'] ?? 'Institution Collected';
        $academic_year = trim($_POST['academic_year'] ?? '2026');
        $status = $_POST['status'] ?? 'active';

        if ($name === '' || $academic_year === '') {
            $error = 'Name and academic year are required.';
        } elseif ($amount < 0) {
            $error = 'Amount cannot be negative.';
        } else {
            try {
                // Check duplicate for name + academic_year
                $check = $db->prepare("SELECT id FROM fee_items WHERE name = ? AND academic_year = ? AND id != ? LIMIT 1");
                $check->bind_param('ssi', $name, $academic_year, $id);
                $check->execute();
                $dup = $check->get_result()->num_rows > 0;
                $check->close();

                if ($dup) {
                    $error = "Fee item '$name' already exists for Academic Year '$academic_year'.";
                } else {
                    if ($id > 0) {
                        // Update
                        $stmt = $db->prepare("UPDATE fee_items 
                            SET name = ?, description = ?, amount = ?, mandatory_status = ?, collection_type = ?, academic_year = ?, status = ? 
                            WHERE id = ?");
                        $stmt->bind_param('ssdssssi', $name, $description, $amount, $mandatory_status, $collection_type, $academic_year, $status, $id);
                        if ($stmt->execute()) {
                            $message = 'Fee item updated successfully.';
                        } else {
                            $error = 'A database error occurred. Please try again.';
                            error_log("Database error in accounts/fees_items.php: " . $db->error);
                        }
                        $stmt->close();
                    } else {
                        // Insert
                        $stmt = $db->prepare("INSERT INTO fee_items 
                            (name, description, amount, mandatory_status, collection_type, academic_year, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param('ssdssss', $name, $description, $amount, $mandatory_status, $collection_type, $academic_year, $status);
                        if ($stmt->execute()) {
                            $message = 'Fee item created successfully.';
                        } else {
                            $error = 'A database error occurred. Please try again.';
                            error_log("Database error in accounts/fees_items.php: " . $db->error);
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

// Fetch fee items
$feeItems = [];
$res = $db->query("SELECT * FROM fee_items ORDER BY academic_year DESC, name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $feeItems[] = $row;
    }
    $res->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header finance-section mb-4 mt-2">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-tags me-2 text-primary"></i>Additional Fee Items</h1>
                <p class="text-muted mb-0">Create and manage reusable fee items like Exam fees, ID cards, practicals, or external charges.</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus me-2"></i>Add Fee Item
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
                            <th>Name / Description</th>
                            <th>Academic Year</th>
                            <th>Amount</th>
                            <th>Requirement</th>
                            <th>Collection Type</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($feeItems)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fas fa-tags fa-2x mb-2 d-block"></i> No fee items configured yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($feeItems as $item): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($item['name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($item['description'] ?? '') ?></small>
                                    </td>
                                    <td><span class="badge bg-light text-dark font-monospace"><?= htmlspecialchars($item['academic_year']) ?></span></td>
                                    <td class="fw-bold text-primary">ZMW <?= number_format($item['amount'], 2) ?></td>
                                    <td>
                                        <?php if ($item['mandatory_status'] === 'mandatory'): ?>
                                            <span class="badge bg-danger rounded-pill">Mandatory</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill">Optional</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['collection_type'] === 'Institution Collected'): ?>
                                            <span class="badge bg-primary">Institution Collected</span>
                                        <?php elseif ($item['collection_type'] === 'External Payment'): ?>
                                            <span class="badge bg-warning text-dark">External Payment</span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-white">Informational Only</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['status'] === 'active'): ?>
                                            <span class="badge bg-success rounded-pill px-3 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill px-3 py-1">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-outline-primary btn-sm rounded-pill px-3" 
                                                onclick='openEditModal(<?= htmlspecialchars(json_encode($item)) ?>)'>
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
<div class="modal fade" id="itemModal" tabindex="-1" aria-labelledby="itemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="itemModalLabel">Add Fee Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="item_id" value="0">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="i_name" class="form-label fw-semibold">Item Name</label>
                        <input type="text" class="form-control rounded-3" name="name" id="i_name" required placeholder="e.g., ID Fee">
                    </div>
                    <div class="mb-3">
                        <label for="i_desc" class="form-label fw-semibold">Description (Optional)</label>
                        <textarea class="form-control rounded-3" name="description" id="i_desc" rows="2" placeholder="Brief explanation of the charge"></textarea>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label for="i_amount" class="form-label fw-semibold">Amount (ZMW)</label>
                            <input type="number" step="0.01" class="form-control rounded-3" name="amount" id="i_amount" required placeholder="0.00">
                        </div>
                        <div class="col-6">
                            <label for="i_year" class="form-label fw-semibold">Academic Year</label>
                            <input type="text" class="form-control rounded-3" name="academic_year" id="i_year" required value="2026">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="i_mandatory" class="form-label fw-semibold">Requirement Status</label>
                        <select class="form-select rounded-3" name="mandatory_status" id="i_mandatory">
                            <option value="mandatory">Mandatory</option>
                            <option value="optional">Optional</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="i_col" class="form-label fw-semibold">Collection Type</label>
                        <select class="form-select rounded-3" name="collection_type" id="i_col">
                            <option value="Institution Collected">Institution Collected (Affects student balance)</option>
                            <option value="External Payment">External Payment (Displayed but not in balance)</option>
                            <option value="Informational Only">Informational Only (For guidance only)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="i_status" class="form-label fw-semibold">Status</label>
                        <select class="form-select rounded-3" name="status" id="i_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Save Fee Item</button>
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
    var modalEl = document.getElementById('itemModal');
    if (modalEl && modalEl.parentNode !== document.body) {
        document.body.appendChild(modalEl);
    }
});

function openAddModal() {
    document.getElementById('itemModalLabel').innerText = 'Add Fee Item';
    document.getElementById('item_id').value = '0';
    document.getElementById('i_name').value = '';
    document.getElementById('i_desc').value = '';
    document.getElementById('i_amount').value = '';
    document.getElementById('i_mandatory').value = 'mandatory';
    document.getElementById('i_col').value = 'Institution Collected';
    document.getElementById('i_status').value = 'active';
    var myModal = new bootstrap.Modal(document.getElementById('itemModal'));
    myModal.show();
}

function openEditModal(item) {
    document.getElementById('itemModalLabel').innerText = 'Edit Fee Item';
    document.getElementById('item_id').value = item.id;
    document.getElementById('i_name').value = item.name;
    document.getElementById('i_desc').value = item.description || '';
    document.getElementById('i_amount').value = item.amount;
    document.getElementById('i_year').value = item.academic_year;
    document.getElementById('i_mandatory').value = item.mandatory_status;
    document.getElementById('i_col').value = item.collection_type;
    document.getElementById('i_status').value = item.status;
    var myModal = new bootstrap.Modal(document.getElementById('itemModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

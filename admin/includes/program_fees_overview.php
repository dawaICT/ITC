<?php
// Get fee structures for each program
$program_fees = [];
$sql = "SELECT 
            fs.*, 
            p.program_name,
            CONCAT('Year ', fs.year_of_study, ' - Semester ', fs.semester) as period
        FROM fee_structure fs
        JOIN programs p ON fs.program_code = p.program_code
        WHERE fs.status = 'active'
        ORDER BY p.program_code, fs.year_of_study, fs.semester, fs.fee_description";

if($result = $db->query($sql)) {
    while($row = $result->fetch_object()) {
        if(!isset($program_fees[$row->program_code])) {
            $program_fees[$row->program_code] = [
                'program_name' => $row->program_name,
                'fees' => []
            ];
        }
        $program_fees[$row->program_code]['fees'][] = $row;
    }
    $result->free();
}
?>

<!-- Program Fees Overview Card -->
<div class="data-table-card mt-4">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Program Fees Overview
            </h5>
            <div class="header-actions">
                <button class="btn btn-sm btn-primary" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-2"></i>Export
                </button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="programFeesTable">
                <thead class="table-light">
                    <tr>
                        <th>Program</th>
                        <th>Period</th>
                        <th>Fee Description</th>
                        <th>Amount (ZMK)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($program_fees)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4">
                                <div class="d-flex flex-column align-items-center">
                                    <i class="fas fa-folder-open fa-3x text-muted mb-2"></i>
                                    <h5 class="text-muted">No fee structures found</h5>
                                    <p class="text-muted small">Add a new fee structure to get started</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($program_fees as $program_code => $data): ?>
                            <tr class="table-primary">
                                <td colspan="5">
                                    <strong><?php echo htmlspecialchars($data['program_name']); ?> (<?php echo htmlspecialchars($program_code); ?>)</strong>
                                </td>
                            </tr>
                            <?php foreach($data['fees'] as $fee): ?>
                                <tr>
                                    <td></td>
                                    <td><?php echo htmlspecialchars($fee->period); ?></td>
                                    <td><?php echo htmlspecialchars($fee->fee_description); ?></td>
                                    <td>ZMK <?php echo number_format($fee->amount, 2); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary edit-fee" 
                                                    onclick="editFee(<?php echo $fee->id; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger delete-fee" 
                                                    onclick="deleteFee(<?php echo $fee->id; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function editFee(id) {
    window.location.href = `edit_fee_structure.php?id=${id}`;
}

function deleteFee(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This fee structure will be permanently deleted!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'ajax/delete_fee_structure.php',
                type: 'POST',
                data: { id: id },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: response.message
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while deleting the fee structure.'
                    });
                }
            });
        }
    });
}

function exportToExcel() {
    let table = document.querySelector('#programFeesTable');
    let html = table.outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    let downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    downloadLink.href = url;
    downloadLink.download = 'program_fees.xls';
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script> 

<div class="alert alert-info mt-3">
  <i class="fas fa-money-bill-wave"></i>
  For advanced budgeting, AR/AP and reporting, visit
  <a href="finance.php">Finance & Accounting</a>.
  
</div>
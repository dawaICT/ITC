<?php
$page_title = 'Receivables with Age Analysis';
require "includes/nav.php";
error_reporting(0);

// Enhancements: pre-initialize containers and timestamps
$records = [];
$lastUpdated = date('Y-m-d H:i:s');
$hasSemester = false;
if (isset($db)) {
	if ($check = $db->query("SHOW COLUMNS FROM student_program LIKE 'semester'")) {
		if ($check->num_rows > 0) { $hasSemester = true; }
		$check->free();
	}
}
?>
<div class="container-fluid px-4 portal-dashboard accounts-page receivables-page">
		<!-- Dashboard Header -->
		<div class="dashboard-header finance-section mb-4">
			<div class="row align-items-center">
				<div class="col">
					<h1 class="dashboard-title">Receivables with Age Analysis</h1>
					<p class="text-muted mb-0">Aging categories and export</p>
				</div>
			</div>
		</div>
	<!-- Receivables Content -->
		<div class="row g-4">
		<div class="col-12">
			<div class="data-table-card">
				<div class="card-header">
					<div class="d-flex justify-content-between align-items-center receivables-header-row">
							<h5 class="mb-0">
								<i class="fas fa-chart-bar me-2"></i>Report Manager | Receivables
							</h5>
						<div class="header-actions d-flex gap-2 receivables-actions">
							<button id="btnExportExcel" class="btn btn-sm btn-success d-flex align-items-center gap-2" type="button">
								<i class="fas fa-file-excel"></i> Export Excel
							</button>
							<button id="btnExportCsv" class="btn btn-sm btn-primary d-flex align-items-center gap-2" type="button">
								<i class="fas fa-file-csv"></i> Export CSV
							</button>
							<button id="btnAgingReport" class="btn btn-sm btn-warning d-flex align-items-center gap-2" type="button">
								<i class="fas fa-hourglass-half"></i> Aging Report
							</button>
							<button id="btnGenerateArPdf" class="btn btn-sm btn-secondary d-flex align-items-center gap-2" type="button">
								<i class="fas fa-file-pdf me-2"></i>Generate AR Summary (PDF)
							</button>
							<button id="btnRefresh" class="btn btn-sm btn-info d-flex align-items-center gap-2" type="button">
								<i class="fas fa-sync-alt"></i> Refresh
							</button>
							<div class="form-check form-switch d-flex align-items-center ms-2">
								<input class="form-check-input" type="checkbox" id="autoRefreshToggle">
								<label class="form-check-label small ms-2" for="autoRefreshToggle">Auto refresh</label>
							</div>
							<small class="text-muted ms-2">Last updated: <?php echo htmlspecialchars($lastUpdated); ?></small>
						</div>
					</div>
				</div>
				<div class="card-body">
							<div class="row">
                        <?php
                            $number = 1;
                            if($results = $db->query("SELECT
                                i.SID AS Sid,
                                st.Fname,
                                st.Lname,
                                COALESCE(prog.program_code, '') AS program_code,
                                GREATEST(SUM(i.amount) - COALESCE(p.total_paid, 0), 0) AS balance,
                                MAX(i.date_generated) AS created_at
                                FROM invoices i
                                LEFT JOIN (
                                    SELECT student_id, SUM(amount) AS total_paid
                                    FROM payments
                                    WHERE LOWER(status) IN ('completed', 'paid', 'success')
                                    GROUP BY student_id
                                ) p ON CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(i.SID USING utf8mb4) COLLATE utf8mb4_general_ci
                                INNER JOIN students st
                                    ON st.SID = CONVERT(i.SID USING utf8mb4) COLLATE utf8mb4_general_ci
                                LEFT JOIN (
                                    SELECT Sid, MAX(program_code) AS program_code
                                    FROM student_program
                                    GROUP BY Sid
                                ) prog ON prog.Sid COLLATE utf8mb4_unicode_ci = CONVERT(i.SID USING utf8mb4) COLLATE utf8mb4_unicode_ci
                                GROUP BY i.SID, st.Fname, st.Lname, prog.program_code, p.total_paid
                                HAVING balance > 0
                                ORDER BY created_at DESC")) {

                                if($count = $results->num_rows) {
                                    $totalBalance = 0;

                                    while($row = $results->fetch_object()){
                                            $records[] = $row;
                                        }

                                            $results->free();
                                        }
                                        else {
                                    echo '<h4 class="alert alert-danger">'."No records found.".'</h4>'.'<br>';
                                    die();
                                }
                            }

                        ?>

                        <!-- Advanced Filters -->
                        <div class="row g-3 mb-3 align-items-end receivables-filters">
                            <div class="col-12 col-md-4">
                                <label for="filterProgram" class="form-label">Program</label>
                                <select id="filterProgram" class="form-select">
                                    <option value="">All Programs</option>
                                    <?php
                                        $programSeen = array();
                                        foreach ($records as $recProg) {
                                            if (!in_array($recProg->program_code, $programSeen, true)) {
                                                $programSeen[] = $recProg->program_code;
                                            }
                                        }
                                        foreach ($programSeen as $progOpt) {
                                            echo '<option value="'.htmlspecialchars($progOpt).'">'.htmlspecialchars($progOpt).'</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                            <?php if ($hasSemester): ?>
                            <div class="col-12 col-md-4">
                                <label for="filterSemester" class="form-label">Semester</label>
                                <select id="filterSemester" class="form-select">
                                    <option value="">All Semesters</option>
                                    <?php
                                        $semesterSeen = array();
                                        foreach ($records as $recSem) {
                                            if (isset($recSem->semester) && $recSem->semester !== null && !in_array($recSem->semester, $semesterSeen, true)) {
                                                $semesterSeen[] = $recSem->semester;
                                            }
                                        }
                                        sort($semesterSeen);
                                        foreach ($semesterSeen as $semOpt) {
                                            echo '<option value="'.htmlspecialchars($semOpt).'">'.htmlspecialchars($semOpt).'</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-12 col-md-4">
                                <label for="filterStatus" class="form-label">Payment Status</label>
                                <select id="filterStatus" class="form-select">
                                    <option value="">All Statuses</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Overdue">Overdue</option>
                                </select>
                            </div>
                            <div class="col-12 d-flex gap-2 receivables-filter-actions">
                                <button id="btnClearFilters" type="button" class="btn btn-outline-secondary"><i class="fas fa-times"></i> Clear Filters</button>
                                <button id="btnRemindOverdue" type="button" class="btn btn-outline-danger"><i class="fas fa-bell"></i> Remind All Overdue</button>
                            </div>
                        </div>

                        <div id="print">
                            <div class="mb-4 text-center receivables-branding">
                                <h4><strong>Woodlands University</strong></h4>
                                <div class="mb-2">
                                    <img src="images/LOGO2.jpeg" alt="ITC Logo" class="img-fluid" style="height:72px;width:auto;max-width:100%">
                                </div>
                                <h4><strong>Accounts Receivables</strong></h4>
                            </div>
                            <div class="table-responsive receivables-table-wrap">
                                <table id="myTable" class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>SID</th>
                                        <th>First name</th>
                                        <th>Last name</th>
                                        <th>Program</th>
                                        <th>Latest Balance (ZMW)</th>
                                        <th>Aging</th>
                                        <th>Status</th>
                                        <th>Plan</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>

                            <?php
                            // Aging summary trackers
                            $agingCounts = array('0-30'=>0,'31-60'=>0,'61-90'=>0,'90+'=>0);
                            $agingSums = array('0-30'=>0.0,'31-60'=>0.0,'61-90'=>0.0,'90+'=>0.0);

                            foreach($records as $r) {
                            ?>
                                <tr<?php if ($hasSemester && isset($r->semester)) { echo ' data-semester="'.htmlspecialchars($r->semester).'"'; } ?>>
                                <td><?php echo $number++; ?></td>
                                <td><?php echo $r->Sid; ?></td>
                                <td><?php echo $r->Fname; ?></td>
                                <td><?php echo $r->Lname; ?></td>
                                <td><?php echo $r->program_code; ?></td>
                                <?php
                                    $createdAt = !empty($r->created_at) ? new DateTime($r->created_at) : new DateTime();
                                    $today = new DateTime();
                                    $agingDays = $today->diff($createdAt)->days;
                                    if ($agingDays <= 30) { $bucket = '0-30'; }
                                    elseif ($agingDays <= 60) { $bucket = '31-60'; }
                                    elseif ($agingDays <= 90) { $bucket = '61-90'; }
                                    else { $bucket = '90+'; }

                                    $agingCounts[$bucket]++;
                                    $agingSums[$bucket] += floatval($r->balance);

                                    $status = ($agingDays > 30) ? 'Overdue' : 'Pending';
                                    $overdueIcon = ($agingDays > 30) ? '<i class="fas fa-exclamation-triangle text-danger ms-2" title="Overdue"></i>' : '';
                                ?>
                                <td class="text-end" data-sort="<?php echo number_format($r->balance, 2, '.', ''); ?>"><?php echo number_format($r->balance, 2); ?> <?php echo $overdueIcon; ?></td>
                                <td><?php echo $agingDays; ?> days <span class="text-muted">(<?php echo $bucket; ?>)</span></td>
                                <td>
                                    <?php if($status === 'Overdue'): ?>
                                        <span class="badge bg-danger">Overdue</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info js-open-plan" data-sid="<?php echo htmlspecialchars($r->Sid); ?>" data-name="<?php echo htmlspecialchars($r->Fname.' '.$r->Lname); ?>" data-program="<?php echo htmlspecialchars($r->program_code); ?>" data-balance="<?php echo number_format($r->balance, 2); ?>" data-aging="<?php echo $agingDays; ?>" type="button">
                                        <i class="fas fa-tasks"></i> View Plan
                                    </button>
                                </td>

                                <td class="text-center">
                                    <div class="btn-group receivables-row-actions" role="group">
                                        <a href="#" class="btn btn-sm btn-primary rounded-pill">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <button class="btn btn-sm btn-outline-secondary js-open-reminder" data-sid="<?php echo htmlspecialchars($r->Sid); ?>" data-name="<?php echo htmlspecialchars($r->Fname.' '.$r->Lname); ?>" data-program="<?php echo htmlspecialchars($r->program_code); ?>" data-balance="<?php echo number_format($r->balance, 2); ?>" data-aging="<?php echo $agingDays; ?>" type="button">
                                            <i class="fas fa-bell"></i> Send Reminder
                                        </button>
                                    </div>
                                </td>
                                </tr>

                                <?php 

                                // Accumulate total balance
                                $totalBalance += $r->balance;

                                                                }
                                ?>
                                </tbody>
                            </table>
                            </div>
                            <!-- Aging Summary (toggle) -->
                            <div id="agingSummary" class="mt-3 d-none">
                                <div class="card">
                                    <div class="card-header bg-white">
                                        <strong>Aging Summary</strong>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle w-auto mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Aging Bucket</th>
                                                        <th class="text-end">Students</th>
                                                        <th class="text-end">Total (ZMW)</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>0 - 30 days</td>
                                                        <td class="text-end"><?php echo $agingCounts['0-30']; ?></td>
                                                        <td class="text-end"><?php echo number_format($agingSums['0-30'], 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>31 - 60 days</td>
                                                        <td class="text-end"><?php echo $agingCounts['31-60']; ?></td>
                                                        <td class="text-end"><?php echo number_format($agingSums['31-60'], 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>61 - 90 days</td>
                                                        <td class="text-end"><?php echo $agingCounts['61-90']; ?></td>
                                                        <td class="text-end"><?php echo number_format($agingSums['61-90'], 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>90+ days</td>
                                                        <td class="text-end"><?php echo $agingCounts['90+']; ?></td>
                                                        <td class="text-end"><?php echo number_format($agingSums['90+'], 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <table class="table table-hover align-middle mb-0">
                                        <tr>
                                            <th class="bg-primary text-white">Grand Total (ZMW)</th>
                                            <td class="fw-bold h5 text-end"><?php echo number_format($totalBalance, 2)?></td>
                                        </tr>
                                        <tr>
                                            <th>Filtered Total (ZMW)</th>
                                            <td id="filteredTotal" class="fw-bold h6 text-end">0.00</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            </div>
                        </div>
                    </div>
                </div>
		</div>
	</div>
</div>
<script>
$(document).ready(function(){
    var table = $('#myTable').DataTable({
        pageLength: 25,
        responsive: true,
        order: [[5, 'desc']],
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
        language: {
            search: "",
            searchPlaceholder: "Search receivables...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ records",
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                last: '<i class="fas fa-angle-double-right"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                previous: '<i class="fas fa-angle-left"></i>'
            }
        }
    });

    // Custom filtering for Program and Status
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
        var programFilter = $('#filterProgram').val();
        var statusFilter = $('#filterStatus').val();
        var program = data[4] || '';
        var status = $(table.row(dataIndex).node()).find('td:eq(7)').text().trim();

        if (programFilter && program !== programFilter) return false;
        if (statusFilter && status.indexOf(statusFilter) === -1) return false;
        return true;
    });

    function updateFilteredTotal(){
        var total = 0.0;
        table.rows({search:'applied'}).every(function(){
            var node = $(this.node());
            var balanceCell = node.find('td:eq(5)').text().replace(/[^0-9\.-]+/g, '');
            var val = parseFloat(balanceCell || '0');
            if (!isNaN(val)) total += val;
        });
        $('#filteredTotal').text(total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}));
    }

    $('#filterProgram, #filterStatus').on('change', function(){
        table.draw();
        updateFilteredTotal();
    });

    $('#btnClearFilters').on('click', function(){
        $('#filterProgram').val('');
        $('#filterStatus').val('');
        table.search('');
        table.draw();
        updateFilteredTotal();
    });

    table.on('draw', updateFilteredTotal);
    updateFilteredTotal();

    $('#btnAgingReport').on('click', function(){
        $('#agingSummary').toggleClass('d-none');
    });

    $('#btnRefresh').on('click', function(){
        window.location.reload();
    });

    $('#btnExportExcel').on('click', exportToExcel);
    $('#btnExportCsv').on('click', exportToCSV);
    $('#btnGenerateArPdf').on('click', generateARPDF);

    $(document).on('click', '.js-open-plan', function () {
        openPlanModal(this);
    });

    $(document).on('click', '.js-open-reminder', function () {
        openReminderModal(this);
    });

    $('#btnCopyReminder').on('click', copyReminder);

    $('#btnRemindOverdue').on('click', function () {
        var overdueButtons = Array.from(document.querySelectorAll('.js-open-reminder')).filter(function (btn) {
            var aging = parseInt(btn.getAttribute('data-aging') || '0', 10);
            return aging > 30;
        });

        if (!overdueButtons.length) {
            window.alert('No overdue students were found in the current result set.');
            return;
        }

        openReminderModal(overdueButtons[0]);
    });

    var refreshIntervalId = null;
    $('#autoRefreshToggle').on('change', function(){
        if (this.checked) {
            refreshIntervalId = setInterval(function(){ window.location.reload(); }, 300000);
        } else {
            if (refreshIntervalId) { clearInterval(refreshIntervalId); refreshIntervalId = null; }
        }
    });
});

function exportToExcel() {
    let table = document.querySelector('#myTable');
    let html = table.outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    let downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    downloadLink.href = url;
    downloadLink.download = 'receivables.xls';
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

function exportToCSV() {
    var table = document.getElementById('myTable');
    var rows = table.querySelectorAll('tr');
    var csv = [];
    for (var i = 0; i < rows.length; i++) {
        var cols = rows[i].querySelectorAll('th, td');
        var row = [];
        for (var j = 0; j < cols.length; j++) {
            if (j === cols.length - 1) continue; // Skip Action column
            var text = cols[j].innerText.replace(/\s+/g, ' ').trim();
            text = '"' + text.replace(/"/g, '""') + '"';
            row.push(text);
        }
        csv.push(row.join(','));
    }
    var csvContent = 'data:text/csv;charset=utf-8,' + csv.join('\n');
    var link = document.createElement('a');
    link.setAttribute('href', encodeURI(csvContent));
    link.setAttribute('download', 'receivables.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function openReminderModal(btn){
    var sid = btn.getAttribute('data-sid');
    var name = btn.getAttribute('data-name');
    var program = btn.getAttribute('data-program');
    var balance = btn.getAttribute('data-balance');
    var aging = btn.getAttribute('data-aging');
    var message = 'Dear ' + name + ' (SID ' + sid + '), you have an outstanding balance of ZMW ' + balance + ' for program ' + program + '. The balance is ' + aging + ' days old. Please make payment or contact the Accounts Office for a payment plan. Thank you.';
    document.getElementById('reminderStudent').textContent = name + ' (' + sid + ')';
    document.getElementById('reminderMessage').value = message;
    var sidInput = document.getElementById('reminderSid');
    if (sidInput) {
        sidInput.value = sid;
    }
    var modal = new bootstrap.Modal(document.getElementById('reminderModal'));
    modal.show();
}

function copyReminder(){
    var ta = document.getElementById('reminderMessage');
    ta.select();
    ta.setSelectionRange(0, 99999);
    document.execCommand('copy');
}

function openPlanModal(btn){
    var sid = btn.getAttribute('data-sid');
    var name = btn.getAttribute('data-name');
    document.getElementById('planStudent').textContent = name + ' (' + sid + ')';
    var modal = new bootstrap.Modal(document.getElementById('planModal'));
    modal.show();
}

function generateARPDF(){
    var params = new URLSearchParams();
    var p = document.getElementById('filterProgram');
    var s = document.getElementById('filterStatus');
    var sem = document.getElementById('filterSemester');
    if (p && p.value) params.append('program', p.value);
    if (s && s.value) params.append('status', s.value);
    if (sem && sem.value) params.append('semester', sem.value);
    var url = 'receivables_pdf.php' + (params.toString() ? ('?' + params.toString()) : '');
    window.open(url, '_blank');
}

function sendReminderAjax(payload, cb){
    try {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax/send_reminder.php');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function(){
            try {
                var res = JSON.parse(xhr.responseText || '{}');
                if (res && res.ok) { if (cb) cb(true); }
                else { if (cb) cb(false); }
            } catch(e){ if (cb) cb(false); }
        };
        var body = 'sid=' + encodeURIComponent(payload.sid) + '&channel=' + encodeURIComponent(payload.channel) + '&message=' + encodeURIComponent(payload.message || '');
        xhr.send(body);
    } catch(e){ if (cb) cb(false); }
}
</script>

<!-- Reminder Modal -->
<div class="modal fade" id="reminderModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Send Payment Reminder</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p class="mb-1"><strong>Student:</strong> <span id="reminderStudent"></span></p>
				<input type="hidden" id="reminderSid" value="">
				<label for="reminderMessage" class="form-label">Message</label>
				<textarea id="reminderMessage" class="form-control" rows="4"></textarea>
				<small class="text-muted">Copy this message into your email or SMS tool to notify the student.</small>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
				<button type="button" id="btnCopyReminder" class="btn btn-primary"><i class="fas fa-copy"></i> Copy Message</button>
			</div>
		</div>
	</div>
</div>

<!-- Payment Plan Modal -->
<div class="modal fade" id="planModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Payment Plan</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p class="mb-1"><strong>Student:</strong> <span id="planStudent"></span></p>
				<div class="alert alert-info mb-0">
					No payment plan on file. Use the Accounts module to create and track payment plans.
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

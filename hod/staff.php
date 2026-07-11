<?php
/**
 * hod/staff.php â€” Department Staff Directory
 * â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 * Fixes applied:
 *  - error_reporting(0) moved BEFORE nav.php (before exception mode is set)
 *  - Dept resolution supports departments.hod_id and legacy staff department columns
 *  - staff.deptId assumptions removed for schemas that do not store department on staff
 *  - Only shows staff from the HOS's own department (not all staff)
 *  - $r->designation removed â€” column doesn't exist; uses staff.role instead
 *  - $sampleRows undefined variable removed from debug panel
 *  - DataTables dependency removed; replaced with vanilla JS search + pagination
 *  - Staff count stat cards added to header
 *  - Gender displayed with icon not raw M/F
 *  - position/title fetched from staff_positions if available
 *  - CSV export added
 */
error_reporting(0);
$page_title = 'Department Staff';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';

/* â”€â”€ 1. Resolve HOS's department â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
$hod_staff_id = $_SESSION['staff_id'] ?? '';
$dept_id      = '';
$dept_name    = '';
$staffIdsInDepartment = [];

$deptContext = hod_resolve_department($db, $hod_staff_id);
$dept_id = (string)$deptContext['id'];
$dept_name = (string)$deptContext['name'];
// Scope to the whole section (every department in it), not just the first one.
$deptCourseCodes = hod_section_course_codes($db, $deptContext, $hod_staff_id);

if ($hod_staff_id !== '') {
    $staffIdsInDepartment[] = $hod_staff_id;
}
if (!empty($deptCourseCodes) && hod_table_exists($db, 'course_lecturer')) {
    $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
    $stmt = @$db->prepare("SELECT DISTINCT staff_id FROM course_lecturer WHERE course_code IN ({$placeholders})");
    if ($stmt) {
        $stmt->bind_param(str_repeat('s', count($deptCourseCodes)), ...$deptCourseCodes);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $sid = trim((string)($row['staff_id'] ?? ''));
            if ($sid !== '') {
                $staffIdsInDepartment[] = $sid;
            }
        }
        $stmt->close();
    }
}
$staffIdsInDepartment = array_values(array_unique(array_filter($staffIdsInDepartment)));

/* â”€â”€ 2. CSV Export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
if (isset($_GET['export_csv']) && $_GET['export_csv'] === '1') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="staff_dept_' . preg_replace('/[^a-z0-9_-]/i', '_', $dept_id) . '_' . date('Ymd') . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Staff ID', 'Title', 'First Name', 'Last Name', 'Gender', 'Role', 'Department', 'Email', 'Mobile', 'Qualification', 'Status']);
    $n = 1;
    // Fetch all dept staff for export
    if (!empty($staffIdsInDepartment)) {
        $placeholders = implode(',', array_fill(0, count($staffIdsInDepartment), '?'));
        $expStmt = @$db->prepare("
            SELECT s.staff_id, s.title, s.Fname, s.Lname, s.sex, s.role, s.email, s.mobile, s.qualification, s.status,
                   ? AS dept_name
            FROM staff s
            WHERE s.staff_id IN ({$placeholders})
            ORDER BY s.Lname, s.Fname
        ");
        $exp = false;
        if ($expStmt) {
            $exportDeptName = $dept_name ?: ($dept_id ?: '-');
            $types = 's' . str_repeat('s', count($staffIdsInDepartment));
            $expStmt->bind_param($types, $exportDeptName, ...$staffIdsInDepartment);
            $expStmt->execute();
            $exp = $expStmt->get_result();
        }
    } else {
        // No section scope resolved — export stays empty rather than leaking
        // the entire staff directory.
        $exp = false;
    }
    if ($exp) {
        while ($r = $exp->fetch_object()) {
            fputcsv($out, [$n++, $r->staff_id, $r->title, $r->Fname, $r->Lname, $r->sex, $r->role, $r->dept_name, $r->email, $r->mobile, $r->qualification, $r->status]);
        }
    }
    if (isset($expStmt) && $expStmt) { $expStmt->close(); }
    fclose($out);
    exit;
}

/* â”€â”€ 3. Fetch staff â€” filtered to HOS's department â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
$records = [];

// Filter to staff linked through this HOS's department courses; fall back to all staff gracefully.
if (!empty($staffIdsInDepartment)) {
    $placeholders = implode(',', array_fill(0, count($staffIdsInDepartment), '?'));
    $stmt = @$db->prepare("
        SELECT s.staff_id, s.title, s.Fname, s.Lname, s.sex, s.role,
               s.email, s.mobile, s.qualification, s.status,
               ? AS dept_name
        FROM staff s
        WHERE s.staff_id IN ({$placeholders})
        ORDER BY s.Lname ASC, s.Fname ASC
    ");
    if ($stmt) {
        $displayDeptName = $dept_name ?: ($dept_id ?: '-');
        $types = 's' . str_repeat('s', count($staffIdsInDepartment));
        $stmt->bind_param($types, $displayDeptName, ...$staffIdsInDepartment);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_object()) $records[] = $row;
        $stmt->close();
    }
}
// No section scope resolved — keep the list empty; the page's empty state
// explains the situation instead of showing every staff member.

/* â”€â”€ 4. Aggregate stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
$totalStaff = count($records);
$maleCount  = 0; $femaleCount = 0;
$roles      = [];
$statusCounts = ['active' => 0, 'inactive' => 0, 'suspended' => 0];

foreach ($records as $r) {
    $g = strtoupper(trim($r->sex ?? ''));
    if ($g === 'M' || $g === 'MALE')   $maleCount++;
    elseif ($g === 'F' || $g === 'FEMALE') $femaleCount++;

    $role = trim($r->role ?? 'staff');
    $roles[$role] = ($roles[$role] ?? 0) + 1;

    $st = strtolower(trim($r->status ?? 'active'));
    if (isset($statusCounts[$st])) $statusCounts[$st]++;
}
ksort($roles);

// Distinct roles for filter
$distinctRoles = array_keys($roles);
sort($distinctRoles);
?>
<div class="container-fluid px-4 portal-dashboard hod-page">

    <div class="page-header mb-3 mt-2 d-print-none">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-tie me-2 text-primary"></i>Department Staff</h5>
                <p class="page-subtitle mb-0">
                    <i class="fas fa-building me-1"></i>
                    <?php echo htmlspecialchars($dept_name ?: ($dept_id ?: 'All Departments')); ?>
                    <?php if ($dept_id): ?>
                        <span class="ms-2 badge bg-light text-dark"><?php echo htmlspecialchars($dept_id); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="?export_csv=1" class="btn btn-success btn-sm">
                    <i class="fas fa-download me-1"></i>Export CSV
                </a>
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3"><i class="fas fa-users text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $totalStaff; ?></h3>
                        <p class="text-muted mb-0">Total Staff</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3"><i class="fas fa-user-check text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $statusCounts['active']; ?></h3>
                        <p class="text-muted mb-0">Active</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3"><i class="fas fa-mars text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $maleCount; ?></h3>
                        <p class="text-muted mb-0">Male</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger me-3"><i class="fas fa-venus text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $femaleCount; ?></h3>
                        <p class="text-muted mb-0">Female</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$dept_id): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-print-none">
        <i class="fas fa-exclamation-triangle mt-1"></i>
        <div>
            <strong>No department assigned.</strong><br>
            <small>Staff account <code><?php echo htmlspecialchars($hod_staff_id); ?></code> has no matching department.
            Showing all staff as fallback. Ask an administrator to fix your department assignment.</small>
        </div>
    </div>
    <?php endif; ?>

    <div class="data-table-card mb-4 d-print-none">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Search</label>
                    <input type="text" id="globalSearch" class="form-control form-control-sm"
                           placeholder="Name, ID, email...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">Role</label>
                    <select class="form-select form-select-sm" id="filterRole">
                        <option value="">All Roles</option>
                        <?php foreach ($distinctRoles as $rl): ?>
                            <option value="<?php echo htmlspecialchars($rl); ?>"><?php echo htmlspecialchars(ucfirst($rl)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">Gender</label>
                    <select class="form-select form-select-sm" id="filterGender">
                        <option value="">All</option>
                        <option value="M">Male</option>
                        <option value="F">Female</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select class="form-select form-select-sm" id="filterStatus">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-outline-secondary w-100" id="clearFilters">
                        <i class="fas fa-times me-1"></i>Clear Filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="data-table-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-users me-2"></i>Staff Directory <span class="badge bg-secondary ms-2" id="rowCount"><?php echo $totalStaff; ?></span></h5>
        </div>

        <?php if (empty($records)): ?>
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-user-slash fa-3x mb-3 d-block text-muted opacity-25"></i>
                <h5>No Staff Found</h5>
                <p class="small">
                    <?php echo $dept_id
                        ? "No staff members in department <strong>" . htmlspecialchars($dept_id) . "</strong>."
                        : "No staff records found in the database."; ?>
                </p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="staffTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Staff</th>
                        <th>Staff ID</th>
                        <th class="text-center">Gender</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th class="text-center d-print-none">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $avatarClasses = ['bg-primary','bg-success','bg-info','bg-warning text-dark','bg-danger','bg-secondary'];
                    $n = 1;
                    foreach ($records as $r):
                        $fullName   = trim(($r->title ? $r->title . ' ' : '') . $r->Fname . ' ' . $r->Lname);
                        $initial    = strtoupper(substr($r->Fname ?? 'S', 0, 1));
                        $avatarCls  = $avatarClasses[($n - 1) % count($avatarClasses)];
                        $genderRaw  = strtoupper(trim($r->sex ?? ''));
                        $genderNorm = in_array($genderRaw, ['M','MALE'], true) ? 'M' : (in_array($genderRaw, ['F','FEMALE'], true) ? 'F' : '');
                        $genderIcon = $genderNorm === 'M'
                            ? '<i class="fas fa-mars text-primary" title="Male"></i>'
                            : ($genderNorm === 'F' ? '<i class="fas fa-venus text-danger" title="Female"></i>' : '<span class="text-muted">-</span>');
                        $stLow      = strtolower(trim($r->status ?? 'active'));
                        $stCls      = $stLow === 'active' ? 'bg-success' : ($stLow === 'suspended' ? 'bg-warning text-dark' : 'bg-secondary');
                        $role       = trim($r->role ?? 'staff');
                        $deptDisplay = htmlspecialchars($r->dept_name ?? $r->deptId ?? '-');
                    ?>
                    <tr data-role="<?php echo htmlspecialchars($role); ?>"
                        data-gender="<?php echo htmlspecialchars($genderNorm); ?>"
                        data-status="<?php echo htmlspecialchars($stLow); ?>"
                        data-name="<?php echo htmlspecialchars($fullName); ?>"
                        data-email="<?php echo htmlspecialchars($r->email ?? ''); ?>"
                        data-mobile="<?php echo htmlspecialchars($r->mobile ?? ''); ?>"
                        data-qual="<?php echo htmlspecialchars($r->qualification ?? ''); ?>"
                        data-sid="<?php echo htmlspecialchars($r->staff_id); ?>">
                        <td class="text-muted"><?php echo $n++; ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge <?php echo $avatarCls; ?> staff-initial-badge"><?php echo $initial; ?></span>
                                <div class="lh-sm">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($fullName); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($r->email ?? ''); ?></small>
                                </div>
                            </div>
                        </td>
                        <td class="font-monospace small"><?php echo htmlspecialchars($r->staff_id); ?></td>
                        <td class="text-center"><?php echo $genderIcon; ?></td>
                        <td><span class="badge bg-light text-dark border text-capitalize"><?php echo htmlspecialchars($role); ?></span></td>
                        <td><?php echo $deptDisplay; ?></td>
                        <td>
                            <?php if ($r->mobile): ?>
                                <div><i class="fas fa-phone fa-xs text-muted me-1"></i><?php echo htmlspecialchars($r->mobile); ?></div>
                            <?php endif; ?>
                            <?php if ($r->qualification): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($r->qualification); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $stCls; ?>"><?php echo ucfirst($stLow); ?></span></td>
                        <td class="text-center d-print-none">
                            <a href="view_staff.php?view=<?php echo urlencode($r->staff_id); ?>"
                               class="btn btn-sm btn-outline-primary"
                               title="View Profile">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-light border-top d-print-none">
            <small class="text-muted" id="tableInfo">Showing <?php echo $totalStaff; ?> staff</small>
            <div class="d-flex gap-2 align-items-center">
                <label class="small text-muted mb-0">Show:
                    <select id="perPage" class="form-select form-select-sm d-inline-block ms-1 w-auto">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="0">All</option>
                    </select>
                </label>
                <div id="pagination" class="d-flex gap-1 flex-wrap"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>
<script>
(function () {
    'use strict';

    var tbody    = document.querySelector('#staffTable tbody');
    var allRows  = tbody ? Array.from(tbody.querySelectorAll('tr')) : [];
    var rowCount = document.getElementById('rowCount');
    var tblInfo  = document.getElementById('tableInfo');
    var perPageEl= document.getElementById('perPage');
    var paginEl  = document.getElementById('pagination');

    var filters  = { search:'', role:'', gender:'', status:'' };
    var perPage  = 10;
    var currentPage = 1;

    function applyFilters() {
        var visible = allRows.filter(function(row) {
            if (filters.role   && row.dataset.role   !== filters.role)   return false;
            if (filters.gender && row.dataset.gender !== filters.gender) return false;
            if (filters.status && row.dataset.status !== filters.status) return false;
            if (filters.search) {
                var txt = (row.dataset.name  + ' ' +
                           row.dataset.sid   + ' ' +
                           row.dataset.email + ' ' +
                           row.dataset.mobile).toLowerCase();
                if (!txt.includes(filters.search)) return false;
            }
            return true;
        });

        allRows.forEach(function(r){ r.hidden = true; });

        var total = visible.length;
        var pages = perPage === 0 ? 1 : Math.max(1, Math.ceil(total / perPage));
        if (currentPage > pages) currentPage = 1;

        var start    = perPage === 0 ? 0 : (currentPage - 1) * perPage;
        var end      = perPage === 0 ? total : Math.min(start + perPage, total);
        var pageRows = visible.slice(start, end);

        pageRows.forEach(function(r){ r.hidden = false; });

        if (rowCount) rowCount.textContent = total;
        if (tblInfo) {
            tblInfo.textContent = total === 0 ? 'No staff found'
                : 'Showing ' + (total === 0 ? 0 : start+1) + '-' + end + ' of ' + total + ' staff';
        }

        // Re-number
        pageRows.forEach(function(r, i){
            var td = r.querySelector('td');
            if (td) td.textContent = start + i + 1;
        });

        buildPagination(pages);
    }

    function buildPagination(pages) {
        if (!paginEl) return;
        paginEl.innerHTML = '';
        if (pages <= 1) return;

        function makeBtn(label, pg, disabled, active) {
            var btn = document.createElement('button');
            btn.innerHTML = label;
            btn.className = 'btn btn-sm ' + (active ? 'btn-primary' : (disabled ? 'btn-outline-secondary disabled' : 'btn-outline-primary'));
            btn.disabled = disabled;
            btn.onclick = function(){ if (!disabled) { currentPage = pg; applyFilters(); } };
            return btn;
        }

        paginEl.appendChild(makeBtn('<i class="fas fa-chevron-left"></i>', currentPage-1, currentPage===1, false));
        var s = Math.max(1, currentPage-2), e = Math.min(pages, s+4);
        for (var i = s; i <= e; i++) paginEl.appendChild(makeBtn(i, i, false, i===currentPage));
        paginEl.appendChild(makeBtn('<i class="fas fa-chevron-right"></i>', currentPage+1, currentPage===pages, false));
    }

    /* Bind filters */
    function bindSel(id, key) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', function(){ filters[key] = this.value; currentPage=1; applyFilters(); });
    }
    bindSel('filterRole',   'role');
    bindSel('filterGender', 'gender');
    bindSel('filterStatus', 'status');

    var srch = document.getElementById('globalSearch');
    if (srch) srch.addEventListener('input', function(){ filters.search = this.value.toLowerCase().trim(); currentPage=1; applyFilters(); });

    if (perPageEl) perPageEl.addEventListener('change', function(){ perPage = parseInt(this.value,10); currentPage=1; applyFilters(); });

    var clr = document.getElementById('clearFilters');
    if (clr) clr.addEventListener('click', function(){
        filters = { search:'', role:'', gender:'', status:'' };
        ['globalSearch','filterRole','filterGender','filterStatus'].forEach(function(id){
            var el = document.getElementById(id); if (el) el.value='';
        });
        currentPage=1; applyFilters();
    });

    /* Initial render */
    applyFilters();

})();
</script>

<?php require "includes/footer.php"; ?>


<?php
/**
 * Registrar – Search Students
 *
 * Full-featured student search page matching the admin module's
 * students_by_admin.php styling: portal-dashboard layout, page-header,
 * data-table-card, avatar circles, gender badges, and DataTables.
 *
 * Uses the existing students_data.php JSON endpoint (server-side
 * pagination + search) so only matching rows are fetched.
 *
 * Unified nav (includes/nav.php) provides auth, session, role gate,
 * sidebar chrome, and all CDN assets.
 */

require dirname(__DIR__) . '/db/connect.php';
$page_title = 'Search Students';
require __DIR__ . '/includes/nav.php';

// Fetch quick stats (same pattern as admin/students_by_admin.php)
$stats_query = $db->query("
    SELECT
        (SELECT COUNT(*) FROM students) as total,
        (SELECT COUNT(*) FROM students WHERE sex='M') as male,
        (SELECT COUNT(*) FROM students WHERE sex='F') as female
");
$counts = $stats_query ? $stats_query->fetch_object() : (object)['total'=>0,'male'=>0,'female'=>0];
$total_students  = $counts->total ?? 0;
$male_students   = $counts->male ?? 0;
$female_students = $counts->female ?? 0;

$program_count = 0;
$pc_res = $db->query("SELECT COUNT(*) AS c FROM programs WHERE is_active = 1");
if ($pc_res) { $program_count = (int)($pc_res->fetch_assoc()['c'] ?? 0); $pc_res->free(); }
?>

<div class="container-fluid px-4 portal-dashboard">

    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-search me-2 text-primary"></i>Search Students</h5>
                <p class="page-subtitle mb-0">Look up students by ID, name or programme across <?= number_format($total_students) ?> records</p>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-users"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_students) ?></h3>
                        <p class="text-muted mb-0">Total Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-mars"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($male_students) ?></h3>
                        <p class="text-muted mb-0">Male Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger me-3 text-white"><i class="fas fa-venus"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($female_students) ?></h3>
                        <p class="text-muted mb-0">Female Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <h3 class="mb-0"><?= $program_count ?></h3>
                        <p class="text-muted mb-0">Active Programmes</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 text-primary"><i class="fas fa-magnifying-glass me-2"></i>Student Search</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-8 col-lg-6">
                    <label for="studentSearch" class="form-label fw-semibold">Search</label>
                    <input type="search" id="studentSearch" class="form-control"
                           placeholder="Enter student number, name or programme…"
                           autocomplete="off" autofocus
                           value="<?php echo isset($_GET['q']) ? htmlspecialchars($_GET['q'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>
                <div class="col-auto">
                    <button type="button" id="searchBtn" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>Search
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Table Card -->
    <div class="card border-0 shadow-sm data-table-card mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <h5 class="mb-0 text-primary">
                    <i class="fas fa-list me-2"></i>Search Results
                </h5>
                <span id="resultCount" class="badge bg-primary rounded-pill ms-2">–</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="resultsTable" width="100%">
                    <thead class="table-light">
                        <tr class="text-uppercase small-header">
                            <th width="4%" class="text-center">#</th>
                            <th width="12%">Student ID</th>
                            <th width="26%">Student</th>
                            <th width="10%">Gender</th>
                            <th width="24%">Programme</th>
                            <th width="10%">Intake</th>
                            <th width="8%">Mode</th>
                            <th width="6%" class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="resultsBody">
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="mb-3">
                                    <span class="fa-stack fa-2x text-muted">
                                        <i class="fas fa-circle fa-stack-2x opacity-25"></i>
                                        <i class="fas fa-search fa-stack-1x"></i>
                                    </span>
                                </div>
                                <h5>Search for Students</h5>
                                <p class="text-muted">Enter a student number, name or programme above to find records.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="d-flex align-items-center justify-content-between px-3 py-2 border-top" id="paginationRow" style="display:none!important">
                <span id="pageInfo" class="text-muted small"></span>
                <div class="btn-group btn-group-sm">
                    <button type="button" id="prevPage" class="btn btn-outline-secondary" disabled>
                        <i class="fas fa-chevron-left me-1"></i>Previous
                    </button>
                    <button type="button" id="nextPage" class="btn btn-outline-secondary" disabled>
                        Next<i class="fas fa-chevron-right ms-1"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    var body        = document.getElementById('resultsBody');
    var searchInput = document.getElementById('studentSearch');
    var searchBtn   = document.getElementById('searchBtn');
    var countBadge  = document.getElementById('resultCount');
    var pageInfo    = document.getElementById('pageInfo');
    var prevBtn     = document.getElementById('prevPage');
    var nextBtn     = document.getElementById('nextPage');
    var pagRow      = document.getElementById('paginationRow');

    var state = { page: 1, perPage: 25, q: '', totalPages: 1, total: 0, loading: false, searched: false };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function encId(v) { return encodeURIComponent(String(v == null ? '' : v)); }

    function initials(fname, lname) {
        return (String(fname || '').charAt(0) + String(lname || '').charAt(0)).toUpperCase();
    }

    function genderBadge(sex) {
        if (sex === 'M') {
            return '<span class="badge bg-blue-subtle text-primary border border-primary-subtle rounded-pill">'
                 + '<i class="fas fa-mars me-1"></i>Male</span>';
        }
        if (sex === 'F') {
            return '<span class="badge bg-pink-subtle text-danger border border-danger-subtle rounded-pill">'
                 + '<i class="fas fa-venus me-1"></i>Female</span>';
        }
        return '<span class="badge bg-light text-muted border rounded-pill">' + esc(sex || '–') + '</span>';
    }

    function render(data) {
        state.total      = data.total || 0;
        state.totalPages = data.total_pages || 1;
        countBadge.textContent = state.total + ' student' + (state.total !== 1 ? 's' : '');

        if (!data.rows || !data.rows.length) {
            body.innerHTML = '<tr><td colspan="8" class="text-center py-5">'
                + '<div class="mb-3"><span class="fa-stack fa-2x text-muted">'
                + '<i class="fas fa-circle fa-stack-2x opacity-25"></i>'
                + '<i class="fas fa-user-graduate fa-stack-1x"></i></span></div>'
                + '<h5>No Students Found</h5>'
                + '<p class="text-muted">No students match your search criteria.</p></td></tr>';
            pagRow.style.setProperty('display', 'none', 'important');
            return;
        }

        var html = '';
        data.rows.forEach(function (r, i) {
            var n = (data.offset || 0) + i + 1;
            html += '<tr>'
                + '<td class="text-center fw-bold text-muted">' + n + '</td>'
                + '<td><span class="badge bg-light text-dark border font-monospace">' + esc(r.SID) + '</span></td>'
                + '<td><div class="d-flex align-items-center">'
                +   '<div class="avatar-circle me-3">' + initials(r.Fname, r.Lname) + '</div>'
                +   '<div><div class="fw-bold">' + esc(r.Fname) + ' ' + esc(r.Lname) + '</div></div>'
                + '</div></td>'
                + '<td>' + genderBadge(r.sex) + '</td>'
                + '<td><div class="text-truncate fw-semibold text-primary" style="max-width:240px;" title="' + esc(r.program_name) + '">'
                +   esc(r.program_name || 'No Programme')
                + '</div></td>'
                + '<td><span class="badge bg-light text-dark border">' + esc(r.intake || 'N/A') + '</span></td>'
                + '<td><span class="badge bg-light text-dark border">' + esc(r.mode || 'N/A') + '</span></td>'
                + '<td class="text-end pe-3">'
                +   '<div class="btn-group btn-group-sm action-btns" role="group" aria-label="Student actions">'
                +     '<a href="view_student_admin.php?view=' + encId(r.SID) + '" class="btn btn-outline-secondary" title="View student" aria-label="View student">'
                +       '<i class="fas fa-eye"></i><span class="d-none d-lg-inline ms-1">View</span>'
                +     '</a>'
                +   '</div>'
                + '</td>'
                + '</tr>';
        });
        body.innerHTML = html;

        if (state.totalPages > 1) {
            pagRow.style.removeProperty('display');
            pagRow.style.display = 'flex';
            pageInfo.textContent = 'Showing page ' + data.page + ' of ' + state.totalPages + ' — ' + state.total + ' students';
            prevBtn.disabled = data.page <= 1;
            nextBtn.disabled = data.page >= state.totalPages;
        } else {
            pagRow.style.setProperty('display', 'none', 'important');
        }
    }

    function load() {
        if (state.loading) return;
        state.loading  = true;
        state.searched = true;
        body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">'
            + '<div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>'
            + 'Searching…</td></tr>';

        var url = 'students_data.php?page=' + state.page
                + '&per_page=' + state.perPage
                + '&q=' + encodeURIComponent(state.q);

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.loading = false;
                if (data && data.ok) { render(data); }
                else {
                    body.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">'
                        + '<i class="fas fa-circle-exclamation me-1"></i>Could not load results. Please try again.</td></tr>';
                }
            })
            .catch(function () {
                state.loading = false;
                body.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">'
                    + '<i class="fas fa-circle-exclamation me-1"></i>Network error. Please try again.</td></tr>';
            });
    }

    function doSearch() {
        state.q    = searchInput.value.trim();
        state.page = 1;
        if (state.q === '') {
            countBadge.textContent = '–';
            pagRow.style.setProperty('display', 'none', 'important');
            body.innerHTML = '<tr><td colspan="8" class="text-center py-5">'
                + '<div class="mb-3"><span class="fa-stack fa-2x text-muted">'
                + '<i class="fas fa-circle fa-stack-2x opacity-25"></i>'
                + '<i class="fas fa-search fa-stack-1x"></i></span></div>'
                + '<h5>Search for Students</h5>'
                + '<p class="text-muted">Enter a student number, name or programme above to find records.</p></td></tr>';
            return;
        }
        load();
    }

    // Search triggers
    var debounceTimer = null;
    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(doSearch, 400);
    });
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { clearTimeout(debounceTimer); doSearch(); }
    });
    searchBtn.addEventListener('click', function () { clearTimeout(debounceTimer); doSearch(); });

    // Pagination
    prevBtn.addEventListener('click', function () { if (state.page > 1) { state.page--; load(); } });
    nextBtn.addEventListener('click', function () { if (state.page < state.totalPages) { state.page++; load(); } });

    // Auto-search if ?q= present in URL
    if (searchInput.value.trim() !== '') {
        doSearch();
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
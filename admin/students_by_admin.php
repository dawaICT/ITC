<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once '../includes/db_connect.php';
$page_title = "Student Management";
require_once "includes/admin.php";

if (!isset($db)) {
    die("Database connection not established.");
}

/**
 * STATISTICS SECTION
 * Optimized to fetch counts efficiently
 */
$stats_query = $db->query("
    SELECT
        (SELECT COUNT(*) FROM students) as total,
        (SELECT COUNT(*) FROM students WHERE sex='M') as male,
        (SELECT COUNT(*) FROM students WHERE sex='F') as female
");
$counts = $stats_query->fetch_object();
$total_students  = $counts->total ?? 0;
$male_students   = $counts->male ?? 0;
$female_students = $counts->female ?? 0;

// Program statistics (only need the distinct program count for the stat card)
$program_stats = [];
$program_result = $db->query("SELECT p.program_name, COUNT(sp.Sid) as count
                              FROM programs p
                              LEFT JOIN student_program sp ON p.program_code = sp.program_code
                              GROUP BY p.program_name");
if ($program_result) {
    while($row = $program_result->fetch_object()) {
        $program_stats[$row->program_name] = $row->count;
    }
}

require_once 'includes/header.php';
?>
<style>
    /* Page-specific overrides — shared components in assets/css/dashboard.css */
    .page-title { letter-spacing: -0.02em; font-weight: 800 !important; }
    .action-btn-card i {
        font-size: 1.8rem;
        background: linear-gradient(135deg, var(--primary, #1B2A4A), var(--secondary, #C8102E));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin-bottom: 0.75rem;
    }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-graduate me-2 text-primary"></i>Student Registry Management</h5>
                <p class="page-subtitle mb-0">Total of <?= number_format($total_students) ?> students enrolled across all programs</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <a href="manage_admitted_students.php" class="btn btn-outline-primary">
                    <i class="fas fa-user-check me-1"></i>Admitted
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-home me-1"></i>Home
                </a>
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
                        <h3 class="mb-0"><?= count($program_stats) ?></h3>
                        <p class="text-muted mb-0">Total Programs</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions Grid -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="applicants.php" class="action-btn-card">
                <i class="fas fa-users"></i>
                <span>Applicants</span>
                <small>Review Applications</small>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="admitEnrolled_student.php" class="action-btn-card">
                <i class="fas fa-user-check"></i>
                <span>Admit Student</span>
                <small>Enroll Applicant</small>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="editStud_by_prog.php" class="action-btn-card">
                <i class="fas fa-layer-group"></i>
                <span>Batch Edit</span>
                <small>Mass Update Data</small>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="regNewStud.php" class="action-btn-card">
                <i class="fas fa-user-plus"></i>
                <span>Register New</span>
                <small>Registry Entry</small>
            </a>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="card border-0 shadow-sm data-table-card mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <h5 class="mb-0 text-primary">
                    <i class="fas fa-list me-2"></i>Registry Records
                </h5>
            </div>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-download me-1"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                    <li><a class="dropdown-item" href="export_students.php"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="#" onclick="window.print(); return false;"><i class="fas fa-print me-2"></i>Print</a></li>
                </ul>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="px-3 pt-3" style="max-width:360px;">
                <input type="search" id="studentSearch" class="form-control form-control-sm"
                       placeholder="Search by name, student no. or program…" autocomplete="off">
            </div>
            <div class="table-responsive">
                <table id="myTable" class="table table-hover align-middle mb-0" width="100%">
                    <thead class="table-light">
                        <tr class="text-uppercase small-header">
                            <th width="4%" class="text-center">#</th>
                            <th width="10%">Student ID</th>
                            <th width="26%">Student</th>
                            <th width="10%">Gender</th>
                            <th width="24%">Program</th>
                            <th width="10%">Intake</th>
                            <th width="10%" class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="studentsBody">
                        <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                <div id="studentsInfo" class="small text-muted">—</div>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="studentsPrev" disabled>Prev</button>
                    <button type="button" class="btn btn-outline-secondary" id="studentsNext" disabled>Next</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    var state = { page: 1, perPage: 25, q: '', totalPages: 1, timer: null };

    function renderRows(payload) {
        var body = document.getElementById('studentsBody');
        var rows = payload.rows || [];
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted">' +
                '<div class="mb-2"><i class="fas fa-user-graduate fa-2x opacity-50"></i></div>' +
                '<div>No students found.</div></td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function(r, i) {
            var sid = String(r.SID || '');
            var fname = String(r.Fname || '');
            var lname = String(r.Lname || '');
            var initials = ((fname.charAt(0) || '') + (lname.charAt(0) || '')).toUpperCase();
            var isMale = String(r.sex || '') === 'M';
            var genderBadge = isMale
                ? '<span class="badge bg-blue-subtle text-primary border border-primary-subtle rounded-pill"><i class="fas fa-mars me-1"></i>Male</span>'
                : '<span class="badge bg-pink-subtle text-danger border border-danger-subtle rounded-pill"><i class="fas fa-venus me-1"></i>Female</span>';
            var n = (payload.offset || 0) + i + 1;
            html += '<tr>' +
                '<td class="text-center fw-bold text-muted">' + n + '</td>' +
                '<td><span class="badge bg-light text-dark border font-monospace">' + esc(sid) + '</span></td>' +
                '<td><div class="d-flex align-items-center"><div class="avatar-circle me-3">' + esc(initials) + '</div>' +
                '<div><div class="fw-bold">' + esc(fname + ' ' + lname) + '</div>' +
                '<small class="text-muted">' + esc(r.email || '') + '</small></div></div></td>' +
                '<td>' + genderBadge + '</td>' +
                '<td><div class="text-truncate fw-semibold text-primary" style="max-width:240px;" title="' + esc(r.program_name || '') + '">' +
                esc(r.program_name || 'No Program') + '</div>' +
                '<small class="text-muted font-monospace">' + esc(r.program_code || '') + '</small></td>' +
                '<td><span class="badge bg-light text-dark border">' + esc(r.intake || 'N/A') + '</span></td>' +
                '<td class="text-end pe-3"><div class="btn-group btn-group-sm action-btns" role="group">' +
                '<a href="view_student.php?view=' + encodeURIComponent(sid) + '" class="btn btn-outline-secondary" title="View student"><i class="fas fa-eye"></i><span class="d-none d-lg-inline ms-1">View</span></a>' +
                '<a href="editStudent.php?update=' + encodeURIComponent(sid) + '" class="btn btn-outline-primary" title="Edit student"><i class="fas fa-pen"></i><span class="d-none d-lg-inline ms-1">Edit</span></a>' +
                '<button type="button" onclick="deleteStudent(' + JSON.stringify(sid) + ')" class="btn btn-outline-danger" title="Delete student"><i class="fas fa-trash-alt"></i><span class="d-none d-lg-inline ms-1">Delete</span></button>' +
                '</div></td></tr>';
        });
        body.innerHTML = html;
    }

    function load() {
        var url = 'students_data.php?page=' + state.page + '&per_page=' + state.perPage + '&q=' + encodeURIComponent(state.q);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(payload) {
                if (!payload || !payload.ok) {
                    throw new Error((payload && payload.error) || 'Load failed');
                }
                state.totalPages = payload.total_pages || 1;
                renderRows(payload);
                var start = payload.total ? (payload.offset + 1) : 0;
                var end = payload.offset + (payload.rows ? payload.rows.length : 0);
                document.getElementById('studentsInfo').textContent =
                    'Showing ' + start + '–' + end + ' of ' + payload.total + ' students';
                document.getElementById('studentsPrev').disabled = state.page <= 1;
                document.getElementById('studentsNext').disabled = state.page >= state.totalPages;
            })
            .catch(function(err) {
                document.getElementById('studentsBody').innerHTML =
                    '<tr><td colspan="7" class="text-center text-danger py-4">' + esc(err.message || 'Failed to load') + '</td></tr>';
            });
    }

    document.getElementById('studentsPrev').addEventListener('click', function() {
        if (state.page > 1) { state.page--; load(); }
    });
    document.getElementById('studentsNext').addEventListener('click', function() {
        if (state.page < state.totalPages) { state.page++; load(); }
    });
    document.getElementById('studentSearch').addEventListener('input', function(e) {
        clearTimeout(state.timer);
        state.timer = setTimeout(function() {
            state.q = e.target.value.trim();
            state.page = 1;
            load();
        }, 300);
    });

    window.deleteStudent = function(id) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Are you sure?',
                html: 'You are about to delete student <strong>' + esc(id) + '</strong>. This cannot be undone!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-trash-alt me-1"></i>Yes, delete it!',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then(function(result) {
                if (result.isConfirmed) {
                    window.location.href = 'delete_student.php?sid=' + encodeURIComponent(id);
                }
            });
        } else if (confirm('Permanently delete student ' + id + '?')) {
            window.location.href = 'delete_student.php?sid=' + encodeURIComponent(id);
        }
    };

    load();
})();
</script>

<?php require 'includes/footer.php'; ?>

<?php
/**
 * Short Course Registration (admin) — registration-focused view.
 * Reuses the shared, tested enrollment engine via ajax/short_course_ajax.php
 * (search_students, enroll_student, get_enrollments, remove_enrollment).
 * Course CRUD lives separately in short_courses.php.
 */
require "includes/admin.php";
$page_title = "Short Course Registration";

// CSRF token shared with ajax/short_course_ajax.php (it validates this on POST).
if (empty($_SESSION['sc_admin_csrf'])) {
    $_SESSION['sc_admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['sc_admin_csrf'];

// Active/upcoming short courses to register students into.
$courses = [];
$sql = "SELECT sc.id, sc.course_code, sc.course_name, sc.duration_value, sc.duration_unit,
               sc.start_date, sc.end_date, sc.max_capacity, sc.status,
               (SELECT COUNT(*) FROM short_course_enrollments e
                  WHERE e.short_course_id = sc.id AND e.status IN ('enrolled','active')) AS enrolled
        FROM short_courses sc
        WHERE sc.status IN ('active','upcoming')
        ORDER BY sc.status ASC, sc.course_name ASC";
if ($res = $db->query($sql)) {
    while ($r = $res->fetch_object()) { $courses[] = $r; }
    $res->free();
}

require "includes/header.php";
?>
<div class="container-fluid px-4 portal-dashboard">
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-certificate me-2 text-primary"></i>Short Course Registration</h5>
                <p class="page-subtitle mb-0">Enrol students into short courses for the current intake</p>
            </div>
            <div class="header-actions">
                <a href="short_courses.php" class="btn btn-outline-secondary shadow-sm">
                    <i class="fas fa-cog me-1"></i>Manage Short Courses
                </a>
            </div>
        </div>
    </div>

    <input type="hidden" id="scCsrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-list me-2"></i>Select Short Course</h6></div>
                <div class="card-body">
                    <?php if (empty($courses)): ?>
                        <div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>No active short courses. Create one in <a href="short_courses.php">Manage Short Courses</a>.</div>
                    <?php else: ?>
                    <label class="form-label small text-uppercase fw-bold text-muted">Short course</label>
                    <select id="courseSelect" class="form-select mb-3">
                        <option value="" disabled selected>Choose a course…</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= (int)$c->id ?>" data-cap="<?= (int)$c->max_capacity ?>" data-enrolled="<?= (int)$c->enrolled ?>">
                                <?= htmlspecialchars($c->course_code . ' — ' . $c->course_name . ' · ' . $c->duration_value . ' ' . ucfirst($c->duration_unit)) ?> (<?= (int)$c->enrolled ?>/<?= (int)$c->max_capacity ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label class="form-label small text-uppercase fw-bold text-muted">Find student to enrol</label>
                    <div class="input-group mb-2">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="studentSearch" class="form-control" placeholder="Name, Student ID or NRC…" autocomplete="off" disabled>
                    </div>
                    <div id="searchResults" class="list-group small"></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-user-check me-2"></i>Registered Students</h6>
                    <span id="enrolBadge" class="badge bg-secondary">0</span>
                </div>
                <div class="card-body">
                    <div id="enrolStatus" class="small text-muted mb-2">Select a course to view its registrations.</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="enrolTable" style="display:none;">
                            <thead class="table-light"><tr><th>Student ID</th><th>Name</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                            <tbody id="enrolBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const AJAX = 'ajax/short_course_ajax.php';
    const csrf = document.getElementById('scCsrf').value;
    const courseSelect = document.getElementById('courseSelect');
    const studentSearch = document.getElementById('studentSearch');
    const searchResults = document.getElementById('searchResults');
    const enrolBody = document.getElementById('enrolBody');
    const enrolTable = document.getElementById('enrolTable');
    const enrolStatus = document.getElementById('enrolStatus');
    const enrolBadge = document.getElementById('enrolBadge');
    let courseId = 0;

    if (!courseSelect) return;

    function esc(s){ return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    function loadEnrollments() {
        if (!courseId) return;
        fetch(`${AJAX}?action=get_enrollments&course_id=${courseId}`)
            .then(r => r.json())
            .then(d => {
                const list = d.enrollments || [];
                enrolBadge.textContent = list.length;
                if (!list.length) {
                    enrolTable.style.display = 'none';
                    enrolStatus.textContent = 'No students registered for this course yet.';
                    return;
                }
                enrolStatus.textContent = '';
                enrolTable.style.display = '';
                enrolBody.innerHTML = list.map(e => `
                    <tr>
                        <td>${esc(e.student_id)}</td>
                        <td>${esc((e.Fname||'') + ' ' + (e.Lname||''))}</td>
                        <td><span class="badge bg-info text-dark">${esc(e.status)}</span></td>
                        <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-remove="${esc(e.id)}"><i class="fas fa-user-minus"></i></button></td>
                    </tr>`).join('');
            })
            .catch(() => { enrolStatus.textContent = 'Could not load registrations.'; });
    }

    courseSelect.addEventListener('change', function () {
        courseId = parseInt(this.value, 10) || 0;
        studentSearch.disabled = !courseId;
        searchResults.innerHTML = '';
        studentSearch.value = '';
        loadEnrollments();
    });

    let searchTimer;
    studentSearch.addEventListener('input', function () {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        if (q.length < 2) { searchResults.innerHTML = ''; return; }
        searchTimer = setTimeout(() => {
            fetch(`${AJAX}?action=search_students&q=${encodeURIComponent(q)}&course_id=${courseId}`)
                .then(r => r.json())
                .then(d => {
                    const rows = d.results || [];
                    if (!rows.length) { searchResults.innerHTML = '<div class="list-group-item text-muted">No matching unregistered students.</div>'; return; }
                    searchResults.innerHTML = rows.map(s => `
                        <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-enroll="${esc(s.SID)}">
                            <span><strong>${esc(s.SID)}</strong> — ${esc((s.Fname||'')+' '+(s.Lname||''))}</span>
                            <i class="fas fa-user-plus text-primary"></i>
                        </button>`).join('');
                });
        }, 250);
    });

    searchResults.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-enroll]');
        if (!btn) return;
        const sid = btn.getAttribute('data-enroll');
        const fd = new FormData();
        fd.append('action', 'enroll_student');
        fd.append('course_id', courseId);
        fd.append('student_id', sid);
        fd.append('csrf_token', csrf);
        btn.disabled = true;
        fetch(AJAX, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) { studentSearch.value = ''; searchResults.innerHTML = ''; loadEnrollments(); }
                else { alert(d.message || 'Could not register student.'); btn.disabled = false; }
            })
            .catch(() => { alert('Network error.'); btn.disabled = false; });
    });

    enrolBody.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-remove]');
        if (!btn) return;
        if (!confirm('Remove this registration?')) return;
        const fd = new FormData();
        fd.append('action', 'remove_enrollment');
        fd.append('enrollment_id', btn.getAttribute('data-remove'));
        fd.append('csrf_token', csrf);
        fetch(AJAX, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { if (d.success) loadEnrollments(); else alert(d.message || 'Could not remove.'); });
    });
})();
</script>

<?php require "includes/footer.php"; ?>

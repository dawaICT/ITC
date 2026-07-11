<?php
$page_title = 'Students';
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/role_helpers.php';
wuc_require_systems_admin('/wucportal/registrar/search_student.php');
include 'add_student.php';
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>Students - ITC</title>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<meta charset="UTF-8">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
<script type="text/javascript" src="dist/js/bootstrap.min.js"></script>
<link rel="stylesheet" type="text/css" href="w3/w3.css">
    <link rel="stylesheet" type="text/css" href="css_main/admin.css">
    <link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-9 w3-card-4 w3-animate-right">
					<div class="w3-container">
					<h3>Students</h3>
					<hr>
					<a href="academic_reports.php"><button class="w3-btn w3-round w3-green w3-right">
					<span class="glyphicon glyphicon-stats"></span> Report manager</button></a>

					<br>
						<br>
							<h3 class="text-danger">
								<?php
								if (isset($_SESSION['successDel'])){

									echo $_SESSION['successDel'];
									session_unset();
								}
								 ?>
								<?php
								if (isset($_SESSION['successDrop'])){

									echo $_SESSION['successDrop'];
									session_unset();
								}
								 ?>
							</h3>

							<!-- Server-side search: only matching rows for the current page are
							     fetched, instead of loading the entire students table up front. -->
							<div class="mb-2" style="max-width:360px;">
								<input type="search" id="studentSearch" class="form-control"
								       placeholder="Search by name, student no. or program…"
								       autocomplete="off">
							</div>

							<table id="myTable" class="table table-hover align-middle">
								<thead class="table-light">
									<tr>
									<th>No.</th>
									<th>Student No</th>
										<th>Names</th>
										<th>Gender</th>
										<th>Program</th>
										<th>Level</th>
										<th>Intake</th>
										<th>Study mode</th>
										<th>Modify</th>
										<th class="w3-center">Action</th>
									</tr>
								</thead>
								<tbody id="studentsBody">
									<tr><td colspan="10" class="text-center text-muted py-3">Loading…</td></tr>
								</tbody>
								</table>

							<!-- Pagination controls -->
							<div class="d-flex align-items-center justify-content-between">
								<button type="button" id="prevPage" class="w3-btn w3-round w3-light-grey" disabled>
									<span class="glyphicon glyphicon-chevron-left"></span> Prev
								</button>
								<span id="pageInfo" class="text-muted small"></span>
								<button type="button" id="nextPage" class="w3-btn w3-round w3-light-grey" disabled>
									Next <span class="glyphicon glyphicon-chevron-right"></span>
								</button>
							</div>
					</div>
			</div>
			<div class="col-sm-2"></div>
		</div>
	</div>
	<script>
(function () {
    var body = document.getElementById('studentsBody');
    var searchInput = document.getElementById('studentSearch');
    var info = document.getElementById('pageInfo');
    var prevBtn = document.getElementById('prevPage');
    var nextBtn = document.getElementById('nextPage');
    var state = { page: 1, perPage: 25, q: '', totalPages: 1, offset: 0, loading: false };
    var debounceTimer = null;

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function encId(v) { return encodeURIComponent(String(v == null ? '' : v)); }

    function render(data) {
        if (!data.rows || !data.rows.length) {
            body.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">No student record found.</td></tr>';
        } else {
            var html = '';
            data.rows.forEach(function (r, i) {
                var n = (data.offset || 0) + i + 1;
                var sidUrl = encId(r.SID);
                html += '<tr>'
                    + '<td>' + n + '.</td>'
                    + '<td>' + esc(r.SID) + '</td>'
                    + '<td>' + esc(r.Fname) + ' ' + esc(r.Lname) + '</td>'
                    + '<td>' + esc(r.sex) + '</td>'
                    + '<td>' + esc(r.program_name) + '</td>'
                    + '<td></td>'
                    + '<td>' + esc(r.intake) + '</td>'
                    + '<td>' + esc(r.mode) + '</td>'
                    + '<td class="w3-center"><a class="btn bg-primary" href="editProgram.php?update=' + sidUrl + '"><span class="glyphicon glyphicon-check"></span></a></td>'
                    + '<td class="w3-center">'
                    + '<a class="btn w3-green" href="view_student_admin.php?view=' + sidUrl + '"><span class="glyphicon glyphicon-eye-open"></span></a> '
                    + '<a class="btn w3-red" href="deleteStudent.php?del=' + sidUrl + '"><span class="glyphicon glyphicon-remove"></span></a>'
                    + '</td>'
                    + '</tr>';
            });
            body.innerHTML = html;
        }
        state.totalPages = data.total_pages || 1;
        info.textContent = 'Page ' + data.page + ' of ' + (state.totalPages || 1) + ' — ' + (data.total || 0) + ' students';
        prevBtn.disabled = data.page <= 1;
        nextBtn.disabled = data.page >= state.totalPages;
    }

    function load() {
        if (state.loading) { return; }
        state.loading = true;
        body.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">Loading…</td></tr>';
        var url = 'students_data.php?page=' + state.page + '&per_page=' + state.perPage + '&q=' + encodeURIComponent(state.q);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.loading = false;
                if (data && data.ok) { render(data); }
                else { body.innerHTML = '<tr><td colspan="10" class="text-center text-danger py-3">Could not load students.</td></tr>'; }
            })
            .catch(function () {
                state.loading = false;
                body.innerHTML = '<tr><td colspan="10" class="text-center text-danger py-3">Could not load students.</td></tr>';
            });
    }

    if (searchInput) {
        // Debounce: only query 300ms after the user stops typing, so a burst of
        // keystrokes triggers one request instead of one request per character.
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                state.q = searchInput.value.trim();
                state.page = 1;
                load();
            }, 300);
        });
    }
    prevBtn.addEventListener('click', function () { if (state.page > 1) { state.page--; load(); } });
    nextBtn.addEventListener('click', function () { if (state.page < state.totalPages) { state.page++; load(); } });

    load();
})();
	</script>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>

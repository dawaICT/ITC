<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
// guard.php enforces login and idle timeout
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Library Catalog - ITC</title>
  <link rel="stylesheet" href="/wucportal/css/admin-style.css">
  <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
  <link rel="stylesheet" href="../css/consistent-styles.css">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>
<div class="content-wrapper portal-dashboard pt-3">
<div class="container-fluid">
  <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
      <h5 class="page-title mb-0"><i class="fas fa-book-reader me-2 text-primary"></i>Library Catalog</h5>
      <p class="page-subtitle mb-0 text-muted">Search library resources and manage your loans</p>
    </div>
    <div class="d-flex gap-2">
      <a href="digital_library.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">
        <i class="fas fa-laptop me-1"></i>Digital Library
      </a>
      <a href="index.php" class="btn btn-light border btn-sm rounded-pill px-3">
        <i class="fas fa-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
            <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-search me-2"></i>Search Items</h5>
        </div>
        <div class="card-body">
          <form id="searchForm" class="row g-2">
            <div class="col-md-6"><input class="form-control" name="q" placeholder="Title, author, ISBN, keywords"></div>
            <div class="col-md-3"><select class="form-select" name="type"><option value="">All Types</option><option>book</option><option>journal</option><option>video</option><option>audio</option></select></div>
            <div class="col-md-3"><button class="btn btn-primary w-100">Search</button></div>
            <div class="col-12">
              <div class="form-check form-switch small mt-1">
                <input class="form-check-input" type="checkbox" role="switch" id="scopeMine" name="scope" value="mine">
                <label class="form-check-label text-muted" for="scopeMine">Show resources for my registered courses only</label>
              </div>
            </div>
          </form>
          <div id="searchMsg" class="small text-muted mt-1"></div>
          <div class="table-responsive mt-3">
            <table class="table table-hover align-middle mb-0" id="itemsTable">
              <thead class="table-light"><tr><th>Title</th><th>Authors</th><th>Type</th><th>Year</th><th class="text-center">Copies</th><th class="text-end">Action</th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
            <h5 class="mb-0 text-success fw-bold"><i class="fas fa-book-open me-2"></i>My Loans</h5>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="loansTable">
                <thead class="table-light"><tr><th>Title</th><th>Due</th><th class="text-end">Action</th></tr></thead>
                <tbody></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- My Course Library: resources grouped by the student's registered courses -->
  <div class="row g-4 mt-1">
    <div class="col-12">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-bottom-0 pt-3 pb-0 d-flex justify-content-between align-items-center">
          <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-graduation-cap me-2"></i>My Course Library</h5>
          <span class="small text-muted">Books, digital resources and lecturer notes linked to your courses</span>
        </div>
        <div class="card-body">
          <div id="courseLibMsg" class="text-muted small">Loading your course resources…</div>
          <div class="accordion" id="courseLibAccordion"></div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- close content wrapper -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function fmt(d){ return new Date(d).toLocaleDateString(); }
function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
function loadCourseLibrary(){
  $.get('portal-js/library_portal_api.php', {action:'course_library'}, function(d){
    const acc = $('#courseLibAccordion').empty();
    const msg = $('#courseLibMsg');
    const courses = d.courses || [];
    if (!courses.length){ msg.text('No registered courses found, so no course resources are available yet.'); return; }
    const withRes = courses.filter(c => c.total > 0);
    msg.html(`You are registered for <strong>${courses.length}</strong> course(s); <strong>${withRes.length}</strong> currently have linked resources.`);
    courses.forEach((c, i) => {
      const rows = [];
      (c.items||[]).forEach(r => rows.push(`<tr><td><span class="badge bg-primary"><i class="fas fa-book me-1"></i>Book</span></td><td>${esc(r.title)}</td><td class="text-muted small">${esc(r.authors||'')}</td></tr>`));
      (c.digital||[]).forEach(r => rows.push(`<tr><td><span class="badge bg-success"><i class="fas fa-laptop me-1"></i>${esc(r.resource_type||'Digital')}</span></td><td>${r.url?`<a href="${esc(r.url)}" target="_blank" rel="noopener">${esc(r.title)}</a>`:esc(r.title)}</td><td class="text-muted small">${esc(r.subject||'')}</td></tr>`));
      (c.notes||[]).forEach(r => rows.push(`<tr><td><span class="badge bg-info text-dark"><i class="fas fa-file-lines me-1"></i>Notes</span></td><td>${r.url?`<a href="${esc(r.url)}" target="_blank" rel="noopener">${esc(r.topic)}</a>`:esc(r.topic)}</td><td class="text-muted small">${esc(r.dte||'')}</td></tr>`));
      const body = rows.length
        ? `<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">${rows.join('')}</table></div>`
        : `<div class="text-muted small fst-italic">No resources have been linked to this course yet.</div>`;
      acc.append(`
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#crsLib${i}">
              <span class="fw-semibold">${esc(c.course_name)}</span>
              <span class="badge bg-${c.total>0?'primary':'secondary'} rounded-pill ms-2">${c.total}</span>
            </button>
          </h2>
          <div id="crsLib${i}" class="accordion-collapse collapse" data-bs-parent="#courseLibAccordion">
            <div class="accordion-body">${body}</div>
          </div>
        </div>`);
    });
  }, 'json').fail(function(){ $('#courseLibMsg').text('Could not load course resources right now.'); });
}
function loadLoans(){
  $.get('portal-js/library_portal_api.php', {action:'my_loans'}, function(d){
    const tb=$('#loansTable tbody').empty();
    (d.results||[]).forEach(r=>tb.append(`<tr><td class="fw-medium">${r.title}</td><td class="text-muted small">${r.due_date?fmt(r.due_date):''}</td><td class="text-end">${r.can_renew?`<button class=\"btn btn-sm btn-outline-primary rounded-pill\" data-renew=\"${r.id}\"><i class="fas fa-sync-alt me-1"></i>Renew</button>`:''}</td></tr>`));
  }, 'json');
}
function load(qParams){
  $.get('portal-js/library_portal_api.php', qParams, function(d){
    const tb=$('#itemsTable tbody').empty();
    (d.results||[]).forEach(r=>{
      const available = parseInt(r.available_copies||0);
      const btn = available > 0 ? `<button class=\"btn btn-sm btn-success rounded-pill px-3\" data-borrow=\"${r.id}\"><i class="fas fa-hand-holding me-1"></i>Borrow</button>` : `<button class=\"btn btn-sm btn-outline-secondary rounded-pill px-3\" data-reserve=\"${r.id}\"><i class="fas fa-bookmark me-1"></i>Reserve</button>`;
      tb.append(`<tr>
        <td><div class="fw-bold">${r.title}</div></td>
        <td>${r.authors||''}</td>
        <td><span class="badge bg-light text-dark border">${r.item_type}</span></td>
        <td class="text-muted">${r.pub_year||'-'}</td>
        <td class="text-center">
            <span class="badge ${available > 0 ? 'bg-success' : 'bg-danger'} rounded-pill">${available}</span>
        </td>
        <td class="text-end">${btn}</td>
      </tr>`);
    });
  }, 'json');
}
$(function(){
  load({action:'search'}); loadLoans(); loadCourseLibrary();
  $('#searchForm').on('submit', function(e){ e.preventDefault(); const params=$(this).serialize()+'&action=search'; load(params); });
  $(document).on('click', '[data-reserve]', function(){ const id=$(this).data('reserve'); $.post('portal-js/library_portal_api.php', {action:'reserve', item_id:id}, function(d){ alert(d.message||'Reserved'); load({action:'search'}); }, 'json'); });
  $(document).on('click', '[data-borrow]', function(){ const id=$(this).data('borrow'); $.post('portal-js/library_portal_api.php', {action:'borrow', item_id:id}, function(d){ alert(d.message||'Borrowed'); load({action:'search'}); loadLoans(); }, 'json'); });
  $(document).on('click', '[data-renew]', function(){ const id=$(this).data('renew'); $.post('portal-js/library_portal_api.php', {action:'renew', loan_id:id}, function(d){ alert(d.message||'Renewed'); loadLoans(); }, 'json'); });
});
</script>
</body></html>




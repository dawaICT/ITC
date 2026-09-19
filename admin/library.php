<?php
// Admin layout
$page_title = "Library & Resource Management";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/permissions.php';

// Guard: require any library permission
if (!(canManageLibrary($_SESSION['staff_id']) || canCirculate($_SESSION['staff_id']) || canCatalog($_SESSION['staff_id']))) {
    echo '<div class="alert alert-danger m-3">Access denied. You do not have library permissions.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
?>

<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="mb-0">Library & Resource Management</h2>
    <div>
      <a href="https://z-library.biz/" target="_blank" rel="noopener noreferrer" class="btn btn-warning btn-sm"><i class="bi bi-book me-1"></i> Get Books</a>
      <a href="library_catalog.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-journal-text me-1"></i> Catalog</a>
      <a href="library_circulation.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-upc-scan me-1"></i> Circulation</a>
      <a href="library_fines.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cash-coin me-1"></i> Fines</a>
      <a href="library_digital.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-arrow-down me-1"></i> Digital</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="flex-grow-1">
              <h6 class="text-muted">Items</h6>
              <h3 id="stat_items">â€”</h3>
            </div>
            <i class="bi bi-bookshelf fs-2 text-primary"></i>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="flex-grow-1">
              <h6 class="text-muted">Copies Available</h6>
              <h3 id="stat_copies">â€”</h3>
            </div>
            <i class="bi bi-box-seam fs-2 text-success"></i>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="flex-grow-1">
              <h6 class="text-muted">Active Loans</h6>
              <h3 id="stat_loans">â€”</h3>
            </div>
            <i class="bi bi-arrow-left-right fs-2 text-warning"></i>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="flex-grow-1">
              <h6 class="text-muted">Outstanding Fines</h6>
              <h3 id="stat_fines">â€”</h3>
				</div>
            <i class="bi bi-currency-dollar fs-2 text-danger"></i>
					</div>
				</div>
			</div>
		</div>
	</div>

  <div class="card mt-4">
    <div class="card-header d-flex align-items-center">
      <i class="bi bi-search me-2"></i>
      Quick Search
    </div>
    <div class="card-body">
      <form id="searchForm" class="row g-2">
        <div class="col-md-4"><input type="text" class="form-control" name="q" placeholder="Title, author, ISBN, keywords"></div>
        <div class="col-md-2">
          <select class="form-select" name="type">
            <option value="">All Types</option>
            <option>book</option>
            <option>journal</option>
            <option>video</option>
            <option>audio</option>
          </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Search</button></div>
      </form>
      <div class="small text-muted mt-1">Tip: Use a barcode scanner to search by ISBN quickly.</div>
      <div class="table-responsive mt-3">
        <table class="table table-hover align-middle" id="resultsTable">
          <thead class="table-light">
            <tr>
              <th>Title</th><th>Authors</th><th>Type</th><th>Year</th><th>Copies</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
$(function() {
  function loadStats() {
    $.get('portal-js/library_api.php', {action: 'stats'}, function(d){
      if (!d) return;
      $('#stat_items').text(d.items ?? '0');
      $('#stat_copies').text(d.copies_available ?? '0');
      $('#stat_loans').text(d.active_loans ?? '0');
      $('#stat_fines').text(d.outstanding_fines ?? '0.00');
    }, 'json');
  }
  loadStats();

  $('#searchForm').on('submit', function(e){
    e.preventDefault();
    const params = $(this).serialize() + '&action=search';
    $.get('portal-js/library_api.php', params, function(d){
      const tbody = $('#resultsTable tbody').empty();
      (d.results || []).forEach(r => {
        tbody.append(`<tr><td>${r.title}</td><td>${r.authors||''}</td><td>${r.item_type}</td><td>${r.pub_year||''}</td><td>${r.available_copies||0}</td></tr>`);
      });
    }, 'json');
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

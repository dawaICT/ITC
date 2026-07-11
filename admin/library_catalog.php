<?php
$page_title = 'Library Catalog';
require_once __DIR__ . '/includes/library_chrome.php';
wuc_library_chrome_boot();
enforcePermission($_SESSION['staff_id'], 'library_catalog');
require_once __DIR__ . '/../includes/csrf_guard.php';
$csrfToken = wuc_ajax_csrf_token();
?>
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<meta name="csrf-header" content="X-CSRF-Token">

<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Cataloging</h3>
    <a href="<?= htmlspecialchars($GLOBALS['wuc_library_back_href']) ?>" class="btn btn-link">Back</a>
  </div>

  <div class="card">
    <div class="card-header">Add / Edit Item</div>
    <div class="card-body">
      <form method="post" action="portal-js/library_catalog_post.php" class="row g-3">
        <input type="hidden" name="id" value="">
        <div class="col-md-6"><label class="form-label">Title</label><input required class="form-control" name="title"></div>
        <div class="col-md-6"><label class="form-label">Authors</label><input class="form-control" name="authors"></div>
        <div class="col-md-3"><label class="form-label">ISBN</label><input class="form-control" name="isbn"></div>
        <div class="col-md-3"><label class="form-label">Type</label>
          <select class="form-select" name="item_type">
            <option>book</option><option>journal</option><option>video</option><option>audio</option><option>other</option>
          </select>
        </div>
        <div class="col-md-2"><label class="form-label">Year</label><input class="form-control" type="number" name="pub_year"></div>
        <div class="col-md-4"><label class="form-label">Publisher</label><input class="form-control" name="publisher"></div>
        <div class="col-md-12"><label class="form-label">Description</label><textarea class="form-control" name="description"></textarea></div>
        <div class="col-12"><button class="btn btn-primary">Save</button></div>
      </form>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header">Catalog Items</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="catalogTable">
          <thead class="table-light">
            <tr><th>Title</th><th>Authors</th><th>Type</th><th>Year</th><th>ISBN</th><th>Copies</th><th class="text-end">Course Links</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/library_link_modal.php'; ?>

<script>
$(function(){
  function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
  function loadCatalog(){
    $.get('portal-js/library_api.php', {action:'search'}, function(d){
      const tb = $('#catalogTable tbody').empty();
      (d.results||[]).forEach(r => tb.append(`<tr>
        <td>${esc(r.title)}</td><td>${esc(r.authors||'')}</td><td>${esc(r.item_type)}</td>
        <td>${esc(r.pub_year||'')}</td><td>${esc(r.isbn||'')}</td><td>${esc(r.available_copies||0)}</td>
        <td class="text-end"><button class="btn btn-sm btn-outline-primary" data-links="${r.id}" data-title="${esc(r.title)}"><i class="fas fa-link me-1"></i>Links</button></td>
      </tr>`));
    }, 'json');
  }
  // Links modal is provided by includes/library_link_modal.php (openLibraryLinks).
  $(document).on('click', '[data-links]', function(){ openLibraryLinks('item', $(this).data('links'), $(this).data('title')); });
  loadCatalog();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>



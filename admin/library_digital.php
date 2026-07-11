<?php
$page_title = 'Digital Library';
require_once __DIR__ . '/includes/library_chrome.php';
wuc_library_chrome_boot();
$digitalStaffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '';
if (!hasPermission($digitalStaffId, 'library_digital') && !(function_exists('isAdmin') && isAdmin($digitalStaffId))) {
  die("Access Denied: You don't have permission to perform this action.");
}

function admin_library_digital_table_exists(mysqli $db, string $table): bool {
  $safe = $db->real_escape_string($table);
  if ($res = @$db->query("SHOW TABLES LIKE '{$safe}'")) {
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
  }
  return false;
}

$hasRoleResourceTable = isset($db) && $db instanceof mysqli
  ? admin_library_digital_table_exists($db, 'library_digital_resource_roles')
  : false;

require_once __DIR__ . '/../includes/csrf_guard.php';
$csrfToken = wuc_ajax_csrf_token();
?>
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<meta name="csrf-header" content="X-CSRF-Token">

<div class="container-fluid">
  <div class="dashboard-header admin-section mb-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h3 class="dashboard-title mb-1">Digital Resources</h3>
        <p class="text-muted mb-0">Publish and monitor digital library links for students.</p>
      </div>
      <a href="<?= htmlspecialchars($GLOBALS['wuc_library_back_href']) ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
  </div>

  <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success_message'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['success_message']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['error_message'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error_message'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error_message']); ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">Add Resource</div>
    <div class="card-body">
      <form action="portal-js/library_digital_post.php" method="post" class="row g-3">
        <div class="col-md-6"><label class="form-label">Title</label><input required class="form-control" name="title"></div>
        <div class="col-md-3"><label class="form-label">Type</label>
          <select class="form-select" name="resource_type"><option>ebook</option><option>journal</option><option>video</option><option>audio</option><option>dataset</option></select>
        </div>
        <div class="col-md-3"><label class="form-label">Access Level</label>
          <select class="form-select" name="access_level">
            <option value="registered">Students / Registered</option>
            <option value="open">Open</option>
            <option value="campus">Campus</option>
            <?php if ($hasRoleResourceTable): ?><option value="role">Role</option><?php endif; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">URL</label><input class="form-control" name="url" type="url" placeholder="https://example.edu/resource.pdf"></div>
        <div class="col-md-6"><label class="form-label">Subject / Course Code</label><input class="form-control" name="subject" placeholder="Subject or course code"></div>
        <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description"></textarea></div>
        <?php if ($hasRoleResourceTable): ?>
        <div class="col-12 d-none" id="roleAccessWrap">
          <label class="form-label">Restrict to Roles (if Access Level is 'role')</label>
          <div class="row g-2">
            <div class="col-md-6">
              <select class="form-select" name="role_ids[]" multiple>
                <?php
                $rs = $db->query("SELECT PosID, PosName FROM positions ORDER BY PosName");
                while ($r = $rs->fetch_assoc()) echo '<option value="'.htmlspecialchars($r['PosID']).'">'.htmlspecialchars($r['PosName']).'</option>';
                ?>
              </select>
              <div class="form-text">Hold Ctrl/Cmd to select multiple roles</div>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <div class="col-12"><button class="btn btn-primary">Save</button></div>
      </form>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header">Resources</div>
    <div class="card-body">
      <div id="digitalAdminMessage" class="alert d-none" role="alert"></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="digitalTable">
          <thead class="table-light"><tr><th>Title</th><th>Type</th><th>Access</th><th>Subject</th><th>URL</th><th>Views</th><th>Downloads</th><th class="text-end">Course Links</th></tr></thead>
          <tbody><tr><td colspan="8" class="text-center text-muted py-4">Loading resources...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
$(function(){
  function esc(value){ return $('<div>').text(value == null ? '' : String(value)).html(); }
  function msg(type, text){
    $('#digitalAdminMessage').removeClass('d-none alert-danger alert-info').addClass('alert-' + type).text(text);
  }
  function load(){
    $.get('portal-js/library_digital_api.php', {action:'list'}, function(d){
      const tb=$('#digitalTable tbody').empty();
      if (d.error) {
        msg('danger', d.error);
        tb.append('<tr><td colspan="8" class="text-center text-muted py-4">Unable to load resources.</td></tr>');
        return;
      }
      const rows = d.results || [];
      if (!rows.length) {
        tb.append('<tr><td colspan="8" class="text-center text-muted py-4">No digital resources yet.</td></tr>');
        return;
      }
      rows.forEach(r=>{
        const link = r.url ? `<a target="_blank" rel="noopener" href="${esc(r.url)}">Open</a>` : '<span class="text-muted">No URL</span>';
        const linksBtn = `<button class="btn btn-sm btn-outline-primary" data-links="${esc(r.id)}" data-title="${esc(r.title)}"><i class="fas fa-link me-1"></i>Links</button>`;
        tb.append(`<tr><td>${esc(r.title)}</td><td>${esc(r.resource_type)}</td><td>${esc(r.access_level)}</td><td>${esc(r.subject||'')}</td><td>${link}</td><td>${esc(r.views||0)}</td><td>${esc(r.downloads||0)}</td><td class="text-end">${linksBtn}</td></tr>`);
      });
    }, 'json').fail(function(){
      msg('danger', 'Server error: could not load digital resources.');
      $('#digitalTable tbody').html('<tr><td colspan="8" class="text-center text-muted py-4">Unable to load resources.</td></tr>');
    });
  }
  load();
  $('select[name="access_level"]').on('change', function(){ $('#roleAccessWrap').toggleClass('d-none', this.value!=='role'); });
  // Links modal provided by includes/library_link_modal.php (openLibraryLinks).
  $(document).on('click', '[data-links]', function(){ openLibraryLinks('digital', $(this).data('links'), $(this).data('title')); });
});
</script>

<?php require __DIR__ . '/includes/library_link_modal.php'; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>



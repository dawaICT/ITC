<?php
/**
 * Shared "Academic Links" modal for the library admin pages.
 *
 * Both library_catalog.php (catalogue items) and library_digital.php (digital
 * resources) use this single modal + JS to link a resource to courses /
 * programmes / departments / public, instead of each page carrying its own copy.
 *
 * Requirements on the including page:
 *   - jQuery and Bootstrap 5 bundle are loaded (both library chrome paths do).
 *   - A CSRF meta tag is present:  <meta name="csrf-token" content="…">
 *   - The page calls  openLibraryLinks(kind, resourceId, title)  to open it,
 *     where kind is 'item' or 'digital'.
 *
 * All requests go to portal-js/library_api.php (link_options / resource_links /
 * link_add / link_remove), which enforces permissions and CSRF server-side.
 */
?>
<div class="modal fade" id="linkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-link me-2"></i>Academic Links — <span id="linkItemTitle"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Link this resource to the courses, programmes or departments whose students and lecturers should see it in their course library. Mark it <em>public</em> to make it institution-wide.</p>
        <div id="linkAlert" class="alert d-none py-2 small"></div>
        <form id="linkForm" class="row g-2 align-items-end mb-3">
          <input type="hidden" id="linkResourceId">
          <input type="hidden" id="linkKind" value="item">
          <div class="col-md-3">
            <label class="form-label small mb-0">Scope</label>
            <select class="form-select form-select-sm" id="linkScopeType">
              <option value="course">Course</option>
              <option value="programme">Programme</option>
              <option value="department">Department</option>
              <option value="public">Public (all)</option>
            </select>
          </div>
          <div class="col-md-5" id="linkTargetWrap">
            <label class="form-label small mb-0">Target</label>
            <select class="form-select form-select-sm" id="linkScopeRef"></select>
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">Visible to</label>
            <select class="form-select form-select-sm" id="linkVisibility">
              <option value="all">Everyone</option>
              <option value="students">Students</option>
              <option value="staff">Staff only</option>
            </select>
          </div>
          <div class="col-md-2">
            <button class="btn btn-sm btn-primary w-100" type="submit"><i class="fas fa-plus me-1"></i>Add</button>
          </div>
        </form>
        <table class="table table-sm align-middle" id="linkTable">
          <thead class="table-light"><tr><th>Scope</th><th>Target</th><th>Visible to</th><th class="text-end">Action</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const csrf = $('meta[name="csrf-token"]').attr('content') || '';
  const API = 'portal-js/library_api.php';
  let options = {courses:[], programmes:[], departments:[]};
  const refLabel = {};   // 'course:COM101' -> 'Communication Skills'
  function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }

  function loadOptions(){
    $.get(API, {action:'link_options'}, function(d){
      options = {courses:d.courses||[], programmes:d.programmes||[], departments:d.departments||[]};
      (options.courses||[]).forEach(c => refLabel['course:'+c.course_code] = c.course_name || c.course_code);
      (options.programmes||[]).forEach(p => refLabel['programme:'+p.program_code] = p.program_name || p.program_code);
      (options.departments||[]).forEach(x => refLabel['department:'+x.id] = x.department_name || ('Dept '+x.id));
    }, 'json');
  }

  function fillTargets(){
    const t = $('#linkScopeType').val();
    const sel = $('#linkScopeRef').empty();
    if (t === 'public'){ $('#linkTargetWrap').hide(); return; }
    $('#linkTargetWrap').show();
    let opts = [];
    if (t === 'course')          opts = (options.courses||[]).map(c => [c.course_code, `${c.course_code} — ${c.course_name||''}`]);
    else if (t === 'programme')  opts = (options.programmes||[]).map(p => [p.program_code, `${p.program_code} — ${p.program_name||''}`]);
    else if (t === 'department') opts = (options.departments||[]).map(x => [String(x.id), x.department_name||('Dept '+x.id)]);
    opts.forEach(([v,l]) => sel.append(`<option value="${esc(v)}">${esc(l)}</option>`));
  }

  function labelFor(scope, ref){
    if (scope === 'public') return '—';
    return refLabel[scope+':'+ref] ? `${ref} — ${refLabel[scope+':'+ref]}` : ref;
  }

  function loadLinks(rid){
    const kind = $('#linkKind').val();
    $.get(API, {action:'resource_links', kind:kind, resource_id:rid}, function(d){
      const tb = $('#linkTable tbody').empty();
      if (!(d.links||[]).length){ tb.append('<tr><td colspan="4" class="text-muted small fst-italic">No links yet.</td></tr>'); return; }
      d.links.forEach(l => tb.append(`<tr>
        <td><span class="badge bg-secondary text-capitalize">${esc(l.scope_type)}</span></td>
        <td>${esc(labelFor(l.scope_type, l.scope_ref))}</td>
        <td class="small text-muted text-capitalize">${esc(l.visibility)}</td>
        <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-unlink="${l.id}"><i class="fas fa-trash"></i></button></td>
      </tr>`));
    }, 'json');
  }

  // Public entry point used by the catalogue / digital pages.
  window.openLibraryLinks = function(kind, rid, title){
    $('#linkKind').val(kind === 'digital' ? 'digital' : 'item');
    $('#linkResourceId').val(rid);
    $('#linkItemTitle').text(title || '');
    $('#linkAlert').addClass('d-none');
    fillTargets(); loadLinks(rid);
    new bootstrap.Modal(document.getElementById('linkModal')).show();
  };

  $('#linkScopeType').on('change', fillTargets);
  $(document).on('click', '[data-unlink]', function(){
    if (!confirm('Remove this link?')) return;
    $.post(API, {action:'link_remove', link_id:$(this).data('unlink'), csrf_token:csrf}, function(){ loadLinks($('#linkResourceId').val()); }, 'json');
  });
  $('#linkForm').on('submit', function(e){
    e.preventDefault();
    const rid = $('#linkResourceId').val();
    $.post(API, {
      action:'link_add', kind:$('#linkKind').val(), resource_id:rid,
      scope_type:$('#linkScopeType').val(), scope_ref:$('#linkScopeRef').val(),
      visibility:$('#linkVisibility').val(), csrf_token:csrf
    }, function(){
      $('#linkAlert').removeClass('d-none alert-danger').addClass('alert-success').text('Link added.');
      loadLinks(rid);
    }, 'json').fail(function(x){
      $('#linkAlert').removeClass('d-none alert-success').addClass('alert-danger').text((x.responseJSON && x.responseJSON.error) || 'Could not add link.');
    });
  });

  $(loadOptions);
})();
</script>

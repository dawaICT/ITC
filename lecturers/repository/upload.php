<?php
require_once __DIR__ . '/_common.php';

repo_handle_upload($db, '/wucportal/lecturers/repository/my_uploads.php');
repo_lecturer_header('Upload Repository Material', 'Lecturer uploads are saved as pending until an administrator approves them.');
if (!repo_schema_ready($db)): ?>
    <div class="alert alert-info">
        The Digital Learning Repository database tables are not installed yet. Ask an administrator to apply
        <code>migrations/20260703_digital_learning_repository.sql</code> before uploading materials.
    </div>
<?php else:
    repo_render_upload_form($db, '/wucportal/lecturers/repository/upload.php');
endif;
?>
<section class="data-table-card mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-globe me-2"></i>Free Online Academic Resources</h5>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            These reputable free/open resources can be used when adding an external repository link. Always check the license and add proper attribution.
        </p>
        <div class="row g-3 repository-resource-grid">
            <div class="col-md-6 col-xl-4"><a class="btn btn-outline-primary w-100 text-start" target="_blank" rel="noopener" href="https://openstax.org/">OpenStax free textbooks</a></div>
            <div class="col-md-6 col-xl-4"><a class="btn btn-outline-primary w-100 text-start" target="_blank" rel="noopener" href="https://oercommons.org/">OER Commons</a></div>
            <div class="col-md-6 col-xl-4"><a class="btn btn-outline-primary w-100 text-start" target="_blank" rel="noopener" href="https://www.merlot.org/">MERLOT learning materials</a></div>
            <div class="col-md-6 col-xl-4"><a class="btn btn-outline-primary w-100 text-start" target="_blank" rel="noopener" href="https://www.doabooks.org/">Directory of Open Access Books</a></div>
            <div class="col-md-6 col-xl-4"><a class="btn btn-outline-primary w-100 text-start" target="_blank" rel="noopener" href="https://www.gutenberg.org/">Project Gutenberg</a></div>
            <div class="col-md-6 col-xl-4"><a class="btn btn-outline-primary w-100 text-start" target="_blank" rel="noopener" href="https://core.ac.uk/">CORE open access research</a></div>
            <div class="col-md-6 col-xl-4"><a class="btn btn-outline-primary w-100 text-start" target="_blank" rel="noopener" href="https://doaj.org/">DOAJ open access journals</a></div>
        </div>
    </div>
</section>
<?php
repo_lecturer_footer();

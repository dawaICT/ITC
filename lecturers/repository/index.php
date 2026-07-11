<?php
require_once __DIR__ . '/_common.php';

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'material_type' => trim((string)($_GET['material_type'] ?? '')),
    'course_code' => trim((string)($_GET['course_code'] ?? '')),
    'programme_code' => trim((string)($_GET['programme_code'] ?? '')),
    'sort' => trim((string)($_GET['sort'] ?? '')),
];
if ($filters['sort'] === 'views') $filters['most_viewed'] = true;
if ($filters['sort'] === 'downloads') $filters['most_downloaded'] = true;
$materials = repo_accessible_materials($db, $filters, 200);
$options = repo_lookup_options($db);

$totalMaterials = count($materials);
$totalViews = 0;
$totalDownloads = 0;
$courseCodes = [];
foreach ($materials as $material) {
    $totalViews += (int)($material['view_count'] ?? 0);
    $totalDownloads += (int)($material['download_count'] ?? 0);
    $courseCode = trim((string)($material['course_code'] ?? ''));
    if ($courseCode !== '') {
        $courseCodes[$courseCode] = true;
    }
}

repo_lecturer_header('Learning Repository', 'Browse approved teaching and learning resources with AI study support.');
?>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="data-table-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-info rounded-circle p-3 me-3"><i class="fas fa-folder-open text-white"></i></div>
                <div>
                    <h6 class="mb-1">Approved Resources</h6>
                    <div class="fw-bold"><?php echo $totalMaterials; ?></div>
                    <small class="text-muted">Matching current filters</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="data-table-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary rounded-circle p-3 me-3"><i class="fas fa-book text-white"></i></div>
                <div>
                    <h6 class="mb-1">Courses</h6>
                    <div class="fw-bold"><?php echo count($courseCodes); ?></div>
                    <small class="text-muted">Represented resources</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="data-table-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success rounded-circle p-3 me-3"><i class="fas fa-eye text-white"></i></div>
                <div>
                    <h6 class="mb-1">Total Views</h6>
                    <div class="fw-bold"><?php echo $totalViews; ?></div>
                    <small class="text-muted">Repository engagement</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="data-table-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-warning rounded-circle p-3 me-3"><i class="fas fa-download text-white"></i></div>
                <div>
                    <h6 class="mb-1">Downloads</h6>
                    <div class="fw-bold"><?php echo $totalDownloads; ?></div>
                    <small class="text-muted">Opened or downloaded</small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card search-form-card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-filter me-2"></i>
            Filter Repository Resources
        </h5>
    </div>
    <div class="card-body">
        <form method="GET" action="/wucportal/lecturers/repository/index.php">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="q" class="form-label">Search</label>
                        <input class="form-control" id="q" name="q" value="<?php echo repo_h($filters['q']); ?>" placeholder="Title, summary, keywords">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label for="material_type" class="form-label">Type</label>
                        <select class="form-select" id="material_type" name="material_type">
                            <option value="">All types</option>
                            <?php foreach (repo_material_types() as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected($filters['material_type'], $key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label for="course_code" class="form-label">Course</label>
                        <select class="form-select" id="course_code" name="course_code">
                            <option value="">All courses</option>
                            <?php foreach ($options['courses'] as $key => $label): ?>
                                <option value="<?php echo repo_h((string)$key); ?>"<?php echo repo_selected($filters['course_code'], (string)$key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label for="programme_code" class="form-label">Programme</label>
                        <select class="form-select" id="programme_code" name="programme_code">
                            <option value="">All programmes</option>
                            <?php foreach ($options['programmes'] as $key => $label): ?>
                                <option value="<?php echo repo_h((string)$key); ?>"<?php echo repo_selected($filters['programme_code'], (string)$key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label for="sort" class="form-label">Sort</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="">Newest</option>
                            <option value="views"<?php echo repo_selected($filters['sort'], 'views'); ?>>Most viewed</option>
                            <option value="downloads"<?php echo repo_selected($filters['sort'], 'downloads'); ?>>Most downloaded</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card results-card shadow mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-table me-2"></i>
            Approved Repository Resources
        </h5>
        <a class="btn btn-dark btn-sm" href="/wucportal/lecturers/repository/upload.php">
            <i class="fas fa-upload me-2"></i>Upload Material
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Resource</th>
                        <th>Type</th>
                        <th>Course</th>
                        <th>Visibility</th>
                        <th>Views</th>
                        <th>Downloads</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($materials): ?>
                        <?php foreach ($materials as $index => $material): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo repo_h((string)$material['title']); ?></strong>
                                    <small class="d-block text-muted">
                                        <?php echo repo_h(wuc_ai_truncate((string)($material['description'] ?? 'No description provided.'), 120)); ?>
                                    </small>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo repo_h(repo_material_types()[$material['material_type']] ?? (string)$material['material_type']); ?></span></td>
                                <td><?php echo repo_h((string)($material['course_code'] ?: 'General')); ?></td>
                                <td><?php echo repo_h(repo_visibility_levels()[$material['visibility']] ?? (string)$material['visibility']); ?></td>
                                <td><?php echo (int)$material['view_count']; ?></td>
                                <td><?php echo (int)$material['download_count']; ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a class="btn btn-sm btn-outline-primary" href="/wucportal/lecturers/repository/view.php?id=<?php echo (int)$material['id']; ?>">
                                            <i class="fas fa-eye me-1"></i>View
                                        </a>
                                        <a class="btn btn-sm btn-primary" href="/wucportal/lecturers/repository/download.php?id=<?php echo (int)$material['id']; ?>">
                                            <i class="fas fa-download me-1"></i>Open
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No approved repository resources match the selected criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
repo_lecturer_footer();

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

repo_student_header('Digital Learning Repository', 'Approved academic materials for your programme, courses, and study needs.');
if (!repo_table_exists($db, 'repository_materials')): ?>
    <div class="alert alert-info">
        The Digital Learning Repository is being prepared. Approved materials will appear here once the repository database tables are installed.
    </div>
<?php endif;
repo_filter_form($filters, $options, '/wucportal/students/repository/index.php');
repo_material_cards($db, $materials, '/wucportal/students/repository/view.php', '/wucportal/students/repository/download.php');
repo_student_footer();

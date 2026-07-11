<?php
require_once __DIR__ . '/_common.php';

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'material_type' => trim((string)($_GET['material_type'] ?? '')),
    'public' => true,
    'sort' => trim((string)($_GET['sort'] ?? '')),
];
if ($filters['sort'] === 'views') $filters['most_viewed'] = true;
if ($filters['sort'] === 'downloads') $filters['most_downloaded'] = true;
$materials = repo_accessible_materials($db, $filters, 200);
$options = repo_lookup_options($db);

repo_public_header('Public Learning Repository', 'Approved public reading resources and summaries from WUCPortal.');
repo_filter_form($filters, $options, '/wucportal/repository/index.php');
repo_material_cards($db, $materials, '/wucportal/repository/view.php', '/wucportal/repository/download.php');
repo_public_footer();

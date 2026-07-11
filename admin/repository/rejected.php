<?php
require_once __DIR__ . '/_common.php';

repo_handle_admin_action($db, '/wucportal/admin/repository/rejected.php');
$filters = repo_admin_filters_from_request('rejected');
$materials = repo_accessible_materials($db, $filters, 300);
$options = repo_lookup_options($db);

repo_admin_header('Rejected Repository Uploads', 'Review rejected uploads and rejection reasons.');
repo_filter_form($filters, $options, '/wucportal/admin/repository/rejected.php');
repo_render_admin_table($materials, '/wucportal/admin/repository/review.php');
repo_admin_footer();

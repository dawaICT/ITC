<?php
require_once __DIR__ . '/_common.php';

repo_handle_admin_action($db, '/wucportal/admin/repository/approved.php');
$filters = repo_admin_filters_from_request('approved');
$materials = repo_accessible_materials($db, $filters, 300);
$options = repo_lookup_options($db);

repo_admin_header('Approved Repository Materials', 'Manage published, unpublished, and archived approved learning resources.');
repo_filter_form($filters, $options, '/wucportal/admin/repository/approved.php');
repo_render_admin_table($materials, '/wucportal/admin/repository/review.php');
repo_admin_footer();

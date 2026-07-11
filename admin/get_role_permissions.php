<?php
require_once "includes/admin.php";
require_once "../includes/permissions.php";

if (!isset($_GET['role_id'])) {
    die("Role ID is required");
}

$role_id = $_GET['role_id'];

// Get all available permissions
$all_permissions_query = "SELECT permission_name, permission_description 
                         FROM role_permissions 
                         WHERE PosID = ?";

$stmt = $db->prepare($all_permissions_query);
$stmt->bind_param("s", $role_id);
$stmt->execute();
$result = $stmt->get_result();

$permissions = array();
while ($row = $result->fetch_object()) {
    $permissions[] = $row;
}

// Ensure core library permissions are visible for assignment
$core = [
    (object)['permission_name'=>'library_manage','permission_description'=>'Full library administration'],
    (object)['permission_name'=>'library_catalog','permission_description'=>'Add and maintain catalog records'],
    (object)['permission_name'=>'library_circulation','permission_description'=>'Checkout, returns, reservations'],
    (object)['permission_name'=>'library_fines','permission_description'=>'Assess and settle fines'],
    (object)['permission_name'=>'library_digital','permission_description'=>'Manage digital resources'],
    (object)['permission_name'=>'exam_view','permission_description'=>'View exam records and transcripts'],
    (object)['permission_name'=>'exam_enter_marks','permission_description'=>'Enter individual final exam marks'],
    (object)['permission_name'=>'exam_upload_results','permission_description'=>'Upload and process final exam result files'],
    (object)['permission_name'=>'exam_manage','permission_description'=>'Manage exam results, publishing, and exam administration']
];
$existing = array_map(function($p){ return $p->permission_name; }, $permissions);
foreach ($core as $c) {
    if (!in_array($c->permission_name, $existing, true)) { $permissions[] = $c; }
}

// Output permissions as checkboxes
echo '<div class="permission-list">';
echo '<h4>Current Permissions</h4>';

if (count($permissions) > 0) {
    foreach ($permissions as $perm) {
        echo '<div class="checkbox">';
        echo '<label>';
        $checked = in_array($perm->permission_name, $existing, true) ? 'checked' : '';
        echo '<input type="checkbox" name="permissions[]" value="' . htmlspecialchars($perm->permission_name) . '" ' . $checked . '> ';
        echo '<strong>' . htmlspecialchars($perm->permission_name) . '</strong>';
        echo '<p class="help-block">' . htmlspecialchars($perm->permission_description) . '</p>';
        echo '</label>';
        echo '</div>';
    }
} else {
    echo '<p>No permissions assigned to this role.</p>';
}

// Add section for new permissions
echo '<h4>Add New Permission</h4>';
echo '<div class="form-group">';
echo '<label for="new_permission">Permission Name:</label>';
echo '<input type="text" class="form-control" name="new_permission" id="new_permission" placeholder="e.g., view_courses">';
echo '</div>';
echo '<div class="form-group">';
echo '<label for="permission_description">Description:</label>';
echo '<textarea class="form-control" name="permission_description" id="permission_description" placeholder="Describe what this permission allows"></textarea>';
echo '</div>';
echo '</div>'; 

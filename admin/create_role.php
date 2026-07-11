<?php
require_once "includes/admin.php"; // Database connection $db
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roleName = trim($_POST['role_name'] ?? '');
    //$description = trim($_POST['description'] ?? ''); // Not in DB schema yet
    
    if (empty($roleName)) {
        die(json_encode(['success' => false, 'message' => 'Role name is required']));
    }

    $db->begin_transaction();

    try {
        // 1. Check if role exists
        $check = $db->prepare("SELECT PosID FROM positions WHERE PosName = ?");
        $check->bind_param("s", $roleName);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            throw new Exception("A role with this name already exists.");
        }
        $check->close();

        // Generate PosID (slug)
        $PosID = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $roleName));
        $PosID = trim($PosID, '_');

        // 2. Insert the new role
        // Schema: id, PosID, PosName. No description column.
        $insert = $db->prepare("INSERT INTO positions (PosID, PosName) VALUES (?, ?)");
        $insert->bind_param("ss", $PosID, $roleName);
        
        if (!$insert->execute()) {
            throw new Exception("Database error: Unable to create role.");
        }

        // 3. Optional: Copy base permissions if a template was selected
        if (!empty($_POST['base_permissions'])) {
            $baseRole = $_POST['base_permissions'];
            // Warning: Schema for role_permissions likely uses PosID (string) not int ID, based on previous analysis
            // "SELECT * FROM role_permissions WHERE PosID = ..."
            
            // Let's resolve the Base Role's PosID
            // Assuming base_permissions value is the PosName or we need to find it.
            // The modal options were "Administrator", "Editor", etc as values.
            // We need to find their PosID.
             $baseRes = $db->query("SELECT PosID FROM positions WHERE PosName = '" . $db->real_escape_string($baseRole) . "' LIMIT 1");
             if ($baseRes && $baseRow = $baseRes->fetch_object()) {
                 $basePosID = $baseRow->PosID;
                 
                 $copyQuery = "INSERT INTO role_permissions (PosID, permission_name) 
                               SELECT ?, permission_name FROM role_permissions 
                               WHERE PosID = ?";
                 $copyStmt = $db->prepare($copyQuery);
                 $copyStmt->bind_param("ss", $PosID, $basePosID);
                 $copyStmt->execute();
             }
        }

        $db->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $db->rollback();
        error_log('create_role error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save role. Please try again.']);
    }
}
?>

<?php
ob_start();
require_once "includes/admin.php";
ob_end_clean();
require_once dirname(__DIR__) . '/includes/portal_access.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

$response = ['success' => false, 'message' => ''];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        throw new Exception('Invalid CSRF token');
    }

    $sectionId = trim((string)($_POST['section_id'] ?? ($_POST['department_id'] ?? '')));
    $staffId = trim((string)($_POST['staff_id'] ?? ''));

    if ($sectionId === '') {
        throw new Exception('Section is required');
    }
    if ($staffId === '') {
        throw new Exception('Staff member is required');
    }

    $section = null;
    $stmt = $db->prepare("
        SELECT section_id, section_name, section_type, department_id
        FROM sections
        WHERE section_id = ? AND status = 'active'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $sectionId);
        $stmt->execute();
        $section = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$section) {
        $stmt = $db->prepare("
            SELECT department_id, department_name
            FROM departments
            WHERE department_id = ? AND status = 'active'
            LIMIT 1
        ");
        if (!$stmt) {
            throw new Exception('Database error: ' . $db->error);
        }
        $stmt->bind_param('s', $sectionId);
        $stmt->execute();
        $department = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$department) {
            throw new Exception('Section not found');
        }

        $section = [
            'section_id' => 'DEPT_' . $department['department_id'],
            'section_name' => $department['department_name'],
            'section_type' => 'academic',
            'department_id' => $department['department_id'],
        ];

        $stmt = $db->prepare("
            INSERT INTO sections (section_id, section_name, section_type, department_id, status)
            VALUES (?, ?, 'academic', ?, 'active')
            ON DUPLICATE KEY UPDATE
                section_name = VALUES(section_name),
                department_id = VALUES(department_id),
                status = 'active'
        ");
        if (!$stmt) {
            throw new Exception('Database error: ' . $db->error);
        }
        $stmt->bind_param('sss', $section['section_id'], $section['section_name'], $section['department_id']);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $db->prepare("SELECT staff_id, CONCAT(COALESCE(title, ''), ' ', Fname, ' ', Lname) AS full_name FROM staff WHERE staff_id = ? AND status IN ('active', 'enabled') LIMIT 1");
    if (!$stmt) {
        throw new Exception('Database error: ' . $db->error);
    }
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$staff) {
        throw new Exception('Active staff member not found');
    }

    $posId = null;
    $stmt = $db->prepare("SELECT PosID FROM positions WHERE LOWER(PosName) IN ('head of section', 'head of department') ORDER BY PosID LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($posId);
        $stmt->fetch();
        $stmt->close();
    }
    if (!$posId) {
        $stmt = $db->prepare("INSERT INTO positions (PosName, description, created_at, updated_at) VALUES ('Head of Section', 'Section head/chair', NOW(), NOW())");
        if (!$stmt || !$stmt->execute()) {
            throw new Exception('Unable to create HOS position');
        }
        $posId = $db->insert_id;
        $stmt->close();
    }

    $assignedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
    $db->begin_transaction();

    $stmt = $db->prepare("
        UPDATE staff_section_assignments
        SET status = 'inactive', updated_at = NOW()
        WHERE section_id = ? AND role_key = 'head_of_department' AND status = 'active'
    ");
    if (!$stmt) {
        throw new Exception('Database error: ' . $db->error);
    }
    $stmt->bind_param('s', $section['section_id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("
        UPDATE staff_section_assignments
        SET is_primary = 0, updated_at = NOW()
        WHERE staff_id = ? AND role_key = 'head_of_department' AND status = 'active'
    ");
    if (!$stmt) {
        throw new Exception('Database error: ' . $db->error);
    }
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("
        UPDATE staff_section_assignments
        SET status = 'inactive', is_primary = 0, updated_at = NOW()
        WHERE staff_id = ?
          AND section_id <> ?
          AND role_key = 'head_of_department'
          AND status = 'active'
    ");
    if (!$stmt) {
        throw new Exception('Database error: ' . $db->error);
    }
    $stmt->bind_param('ss', $staffId, $section['section_id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("
        INSERT INTO staff_section_assignments (staff_id, section_id, role_key, is_primary, status, assigned_by, assigned_at)
        VALUES (?, ?, 'head_of_department', 1, 'active', ?, NOW())
        ON DUPLICATE KEY UPDATE
            is_primary = 1,
            status = 'active',
            assigned_by = VALUES(assigned_by),
            assigned_at = NOW(),
            updated_at = NOW()
    ");
    if (!$stmt) {
        throw new Exception('Database error: ' . $db->error);
    }
    $stmt->bind_param('sss', $staffId, $section['section_id'], $assignedBy);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("
        INSERT INTO staff_positions (staff_id, PosID, created_at, updated_at)
        VALUES (?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");
    if (!$stmt) {
        throw new Exception('Database error: ' . $db->error);
    }
    $stmt->bind_param('si', $staffId, $posId);
    $stmt->execute();
    $stmt->close();

    $hosUserId = 0;
    $stmt = $db->prepare("SELECT user_id FROM users WHERE staff_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $hosUserId = (int)($userRow['user_id'] ?? 0);
        $stmt->close();
    }
    if ($hosUserId > 0) {
        wuc_grant_user_portal_access($db, $hosUserId, ['academic', 'elearning'], $assignedBy !== '' ? $assignedBy : 'system');
    }

    $hasAccessUserId = false;
    if ($col = $db->query("SHOW COLUMNS FROM access_right LIKE 'UserID'")) {
        $hasAccessUserId = $col->num_rows > 0;
        $col->free();
    }

    $currentAccess = '';
    $accessWhere = $hasAccessUserId ? 'staff_id = ? OR UserID = ?' : 'staff_id = ?';
    $stmt = $db->prepare("SELECT assigned_access FROM access_right WHERE {$accessWhere} LIMIT 1");
    if ($stmt) {
        if ($hasAccessUserId) {
            $stmt->bind_param('ss', $staffId, $staffId);
        } else {
            $stmt->bind_param('s', $staffId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $currentAccess = (string)($row['assigned_access'] ?? '');
        $stmt->close();
    }

    $preserveAccess = wuc_normalize_staff_role($currentAccess, false) === 'systems_admin';

    if (!$preserveAccess) {
        $access = 'Head of Section';
        $stmt = $hasAccessUserId
            ? $db->prepare("
                INSERT INTO access_right (staff_id, UserID, assigned_access, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    assigned_access = VALUES(assigned_access),
                    updated_at = NOW()
            ")
            : $db->prepare("
                INSERT INTO access_right (staff_id, assigned_access, created_at, updated_at)
                VALUES (?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    assigned_access = VALUES(assigned_access),
                    updated_at = NOW()
            ");
        if ($stmt) {
            if ($hasAccessUserId) {
                $stmt->bind_param('sss', $staffId, $staffId, $access);
            } else {
                $stmt->bind_param('ss', $staffId, $access);
            }
            $stmt->execute();
            $stmt->close();
        }
    }

    // Mirror the head onto departments.hod_id only when that column exists.
    // On this schema HOS lives in staff_section_assignments and departments has
    // no hod_id column, so this UPDATE (against a non-existent column) would
    // otherwise throw and roll back the entire assignment.
    if (!empty($section['department_id'])) {
        $deptHasHodId = false;
        if ($col = @$db->query("SHOW COLUMNS FROM departments LIKE 'hod_id'")) {
            $deptHasHodId = $col->num_rows > 0;
            $col->free();
        }
        if ($deptHasHodId) {
            $deptPkCol = 'department_id';
            if ($pk = @$db->query("SHOW COLUMNS FROM departments LIKE 'department_id'")) {
                if ($pk->num_rows === 0) {
                    $deptPkCol = 'id';
                }
                $pk->free();
            }
            $stmt = $db->prepare("UPDATE departments SET hod_id = ? WHERE `{$deptPkCol}` = ?");
            if (!$stmt) {
                throw new Exception('Database error: ' . $db->error);
            }
            $stmt->bind_param('ss', $staffId, $section['department_id']);
            $stmt->execute();
            $stmt->close();
        }
    }

    $db->commit();

    $response['success'] = true;
    $response['message'] = trim($staff['full_name']) . ' assigned as HOS for ' . $section['section_name'];
} catch (Throwable $e) {
    if (isset($db) && $db instanceof mysqli) {
        try {
            $db->rollback();
        } catch (Throwable $ignored) {
        }
    }
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    error_log('HOS assignment error: ' . $e->getMessage());
}

echo json_encode($response);
exit;

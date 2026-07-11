<?php
declare(strict_types=1);

/**
 * Department and academic leadership assignment helpers.
 *
 * Leadership assignment types (hod, dean) are stored in department_assignments.
 * RBAC roles (dean, head_of_department) remain in user_roles — do not conflate them.
 */

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/internal_staff_helpers.php';

if (!function_exists('wuc_department_assignment_types')) {
    function wuc_department_assignment_types(): array
    {
        return [
            'member' => 'Department Member',
            'hod' => 'Head of Section (HOD)',
            'dean' => 'Dean',
        ];
    }
}

if (!function_exists('wuc_is_leadership_assignment_type')) {
    function wuc_is_leadership_assignment_type(string $type): bool
    {
        return in_array(strtolower(trim($type)), ['hod', 'dean'], true);
    }
}

if (!function_exists('wuc_resolve_staff_target_by_number')) {
    /**
     * @return array<string,mixed>|null staff row keyed by staff.staff_id (business number)
     */
    function wuc_resolve_staff_target_by_number(mysqli $db, string $staffNumber): ?array
    {
        $staffNumber = trim($staffNumber);
        if ($staffNumber === '' || !wuc_table_exists($db, 'staff')) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT id, staff_id, Fname, Lname, deptId, role, status
               FROM staff
              WHERE staff_id = ?
              LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $staffNumber);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

if (!function_exists('wuc_department_exists')) {
    function wuc_department_exists(mysqli $db, int $departmentId): bool
    {
        if ($departmentId <= 0 || !wuc_table_exists($db, 'departments')) {
            return false;
        }

        $stmt = $db->prepare('SELECT 1 FROM departments WHERE id = ? AND status = "active" LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('wuc_fetch_department_assignments')) {
    function wuc_fetch_department_assignments(mysqli $db): array
    {
        if (!wuc_table_exists($db, 'department_assignments')) {
            return [];
        }

        $rows = [];
        $sql = 'SELECT da.*, s.Fname, s.Lname, s.id AS staff_db_id, d.department_name AS deptName
                  FROM department_assignments da
                  JOIN staff s ON s.staff_id = da.staff_id
                  JOIN departments d ON d.id = da.department_id
                 ORDER BY d.department_name ASC, da.id DESC';
        $res = $db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }

        return $rows;
    }
}

if (!function_exists('wuc_assign_department_to_staff')) {
    /**
     * @return array{ok:bool,message:string,audit:array<string,mixed>}
     */
    function wuc_assign_department_to_staff(mysqli $db, array $input): array
    {
        $targetStaffNumber = trim((string)($input['target_staff_number'] ?? ''));
        $departmentId = (int)($input['department_id'] ?? 0);
        $assignmentType = strtolower(trim((string)($input['assignment_type'] ?? 'member')));
        $confirmSelf = !empty($input['confirm_self_leadership']);
        $assignedBy = trim((string)($input['assigned_by'] ?? 'system'));

        $actorStaffNumber = trim((string)($input['actor_staff_number'] ?? ''));
        $actorUserId = (int)($input['actor_user_id'] ?? 0);

        $auditBase = [
            'status' => 'blocked',
            'assignment_type' => $assignmentType,
            'target_staff_number' => $targetStaffNumber,
            'new_department_id' => $departmentId,
            'actor_user_id' => $actorUserId,
            'actor_staff_number' => $actorStaffNumber,
        ];

        $allowedTypes = array_keys(wuc_department_assignment_types());
        if (!in_array($assignmentType, $allowedTypes, true)) {
            return [
                'ok' => false,
                'message' => 'Invalid assignment type selected.',
                'audit' => array_merge($auditBase, ['reason' => 'invalid_assignment_type']),
            ];
        }

        if ($targetStaffNumber === '' || $departmentId <= 0) {
            return [
                'ok' => false,
                'message' => 'Staff member and department are required.',
                'audit' => array_merge($auditBase, ['reason' => 'missing_required_fields']),
            ];
        }

        if (!wuc_table_exists($db, 'department_assignments')) {
            return [
                'ok' => false,
                'message' => 'Department assignments are not available on this installation.',
                'audit' => array_merge($auditBase, ['reason' => 'table_missing']),
            ];
        }

        $staff = wuc_resolve_staff_target_by_number($db, $targetStaffNumber);
        if (!$staff) {
            return [
                'ok' => false,
                'message' => 'The selected staff member does not exist.',
                'audit' => array_merge($auditBase, ['reason' => 'staff_not_found']),
            ];
        }

        $auditBase['target_staff_db_id'] = (int)($staff['id'] ?? 0);

        if (!wuc_department_exists($db, $departmentId)) {
            return [
                'ok' => false,
                'message' => 'The selected department does not exist or is inactive.',
                'audit' => array_merge($auditBase, ['reason' => 'department_not_found']),
            ];
        }

        $userStmt = $db->prepare('SELECT user_id FROM users WHERE staff_id = ? LIMIT 1');
        if ($userStmt) {
            $userStmt->bind_param('s', $targetStaffNumber);
            $userStmt->execute();
            $userRow = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
            if ($userRow) {
                $blockMsg = wuc_assert_internal_staff_user_for_roles_mgmt($db, (int)$userRow['user_id']);
                if ($blockMsg !== null) {
                    return [
                        'ok' => false,
                        'message' => $blockMsg,
                        'audit' => array_merge($auditBase, ['reason' => 'non_internal_staff_target']),
                    ];
                }
                $auditBase['target_user_id'] = (int)$userRow['user_id'];
            }
        }

        if (wuc_is_leadership_assignment_type($assignmentType)
            && $actorStaffNumber !== ''
            && strcasecmp($actorStaffNumber, $targetStaffNumber) === 0
            && !$confirmSelf
        ) {
            return [
                'ok' => false,
                'message' => 'Assigning yourself to a leadership role requires explicit confirmation.',
                'audit' => array_merge($auditBase, ['reason' => 'self_leadership_requires_confirmation']),
            ];
        }

        $oldDepartmentId = null;
        $existingStmt = $db->prepare(
            'SELECT department_id FROM department_assignments
              WHERE staff_id = ? AND assignment_type = ? AND status = "active"
              LIMIT 1'
        );
        if ($existingStmt) {
            $existingStmt->bind_param('ss', $targetStaffNumber, $assignmentType);
            $existingStmt->execute();
            $existingRow = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();
            if ($existingRow) {
                $oldDepartmentId = (int)$existingRow['department_id'];
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO department_assignments (staff_id, department_id, assignment_type, assigned_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = "active", assignment_type = VALUES(assignment_type), assigned_by = VALUES(assigned_by)'
        );
        if (!$stmt) {
            return [
                'ok' => false,
                'message' => 'Could not prepare department assignment.',
                'audit' => array_merge($auditBase, ['reason' => 'prepare_failed']),
            ];
        }

        $stmt->bind_param('siss', $targetStaffNumber, $departmentId, $assignmentType, $assignedBy);
        $executed = $stmt->execute();
        $stmt->close();

        if (!$executed) {
            return [
                'ok' => false,
                'message' => 'Department assignment could not be saved.',
                'audit' => array_merge($auditBase, ['reason' => 'execute_failed']),
            ];
        }

        if ($assignmentType === 'hod') {
            $updateStaff = $db->prepare("UPDATE staff SET deptId = ?, role = 'Head of Section' WHERE staff_id = ?");
            if ($updateStaff) {
                $updateStaff->bind_param('is', $departmentId, $targetStaffNumber);
                $updateStaff->execute();
                $updateStaff->close();
            }
        }

        return [
            'ok' => true,
            'message' => 'Department assignment saved successfully.',
            'audit' => array_merge($auditBase, [
                'status' => 'success',
                'old_department_id' => $oldDepartmentId,
                'new_department_id' => $departmentId,
            ]),
        ];
    }
}

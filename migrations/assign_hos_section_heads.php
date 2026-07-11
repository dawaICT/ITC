<?php
/**
 * Wire the Head of Section (HOS) accounts to their sections cleanly.
 *
 * Each section is run by ONE dedicated head_of_department account, and the two
 * accounts are kept separate so each HOS only sees their own section:
 *
 *   TRANSPORT  -> Transport Head of Section          (ITC910)
 *   ENGICT     -> Engineering/ICT Head of Section    (ITC904)
 *
 * This also repairs the previous state where the Transport Section had been
 * assigned to an Admissions-Officer account (ITC902), which the `hod` module
 * gate rejected, leaving the Transport HOS dashboard unreachable.
 *
 * The migration is idempotent: run it as often as needed.
 *
 *   php migrations/assign_hos_section_heads.php
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

/** Dedicated HOS accounts, keyed by the section they run. */
$hosAccounts = [
    'TRANSPORT' => [
        'staff_id' => 'ITC910',
        'first'    => 'Test',
        'last'     => 'Transport-HOS',
        'access'   => 'Head of Section',
    ],
    'ENGICT' => [
        'staff_id' => 'ITC904',
        'first'    => 'Test',
        'last'     => 'EngICT-HOS',
        'access'   => 'Head of Section',
    ],
];

$staffPassword = 'Test@12345';
$staffHash = password_hash($staffPassword, PASSWORD_DEFAULT);

$db->begin_transaction();

try {
    // 1. Make sure the two canonical sections exist and are active.
    $db->query("
        INSERT INTO sections (section_id, section_name, section_type, department_id, status)
        VALUES
            ('TRANSPORT', 'Transport Section', 'transport', NULL, 'active'),
            ('ENGICT', 'Engineering/ICT Section', 'academic', NULL, 'active')
        ON DUPLICATE KEY UPDATE
            section_name = VALUES(section_name),
            section_type = VALUES(section_type),
            status = 'active'
    ");

    // 2. Resolve the Head of Section position id (create if missing).
    $posId = null;
    if ($res = $db->query("SELECT PosID FROM positions WHERE LOWER(PosName) IN ('head of section', 'head of department') ORDER BY PosID LIMIT 1")) {
        if ($row = $res->fetch_assoc()) {
            $posId = (int)$row['PosID'];
        }
        $res->free();
    }
    if (!$posId) {
        $db->query("INSERT INTO positions (PosName, description, created_at, updated_at) VALUES ('Head of Section', 'Section head/chair', NOW(), NOW())");
        $posId = (int)$db->insert_id;
    }

    foreach ($hosAccounts as $sectionId => $acc) {
        $staffId = $acc['staff_id'];

        // 3. Ensure the dedicated HOS staff account exists with the right role.
        $email = strtolower($staffId) . '@example.com';
        $mobile = '26000000' . substr($staffId, -3);
        $role = 'head_of_department';
        $title = 'Mr.';
        $sex = 'M';
        $qualification = 'Test Account';

        $stmt = $db->prepare(
            'INSERT INTO staff (staff_id, title, Fname, Lname, sex, email, mobile, password, role, status, qualification, failed_attempts, lockout_until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, 0, NULL)
             ON DUPLICATE KEY UPDATE
                Fname = VALUES(Fname),
                Lname = VALUES(Lname),
                role = VALUES(role),
                status = "active"'
        );
        $stmt->bind_param('ssssssssss', $staffId, $title, $acc['first'], $acc['last'], $sex, $email, $mobile, $staffHash, $role, $qualification);
        $stmt->execute();
        $stmt->close();

        // Keep a usable password ONLY when the account is newly created, so we
        // never clobber a real password that an existing account already has.
        $stmt = $db->prepare('UPDATE staff SET password = ? WHERE staff_id = ? AND (password IS NULL OR password = "")');
        $stmt->bind_param('ss', $staffHash, $staffId);
        $stmt->execute();
        $stmt->close();

        // 4. Access right -> "Head of Section". The UserID column only exists
        //    in the legacy access_right schema, so probe for it first.
        static $accessHasUserId = null;
        if ($accessHasUserId === null) {
            $accessHasUserId = false;
            if ($res = $db->query("SHOW COLUMNS FROM access_right LIKE 'UserID'")) {
                $accessHasUserId = $res->num_rows > 0;
                $res->free();
            }
        }
        $stmt = $accessHasUserId
            ? $db->prepare(
                'INSERT INTO access_right (staff_id, UserID, assigned_access, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE assigned_access = VALUES(assigned_access), updated_at = NOW()'
            )
            : $db->prepare(
                'INSERT INTO access_right (staff_id, assigned_access, created_at, updated_at)
                 VALUES (?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE assigned_access = VALUES(assigned_access), updated_at = NOW()'
            );
        if ($accessHasUserId) {
            $stmt->bind_param('sss', $staffId, $staffId, $acc['access']);
        } else {
            $stmt->bind_param('ss', $staffId, $acc['access']);
        }
        $stmt->execute();
        $stmt->close();

        // 5. Position row.
        $stmt = $db->prepare('INSERT IGNORE INTO staff_positions (staff_id, PosID, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
        $stmt->bind_param('si', $staffId, $posId);
        $stmt->execute();
        $stmt->close();

        // 6. Retire ANY other active head on this section (e.g. the old
        //    Admissions account on Transport) so each section has one head.
        $stmt = $db->prepare(
            "UPDATE staff_section_assignments
             SET status = 'inactive', updated_at = NOW()
             WHERE section_id = ? AND role_key = 'head_of_department' AND status = 'active' AND staff_id <> ?"
        );
        $stmt->bind_param('ss', $sectionId, $staffId);
        $stmt->execute();
        $stmt->close();

        // 7. Assign the dedicated head to its section (active + primary).
        $stmt = $db->prepare(
            "INSERT INTO staff_section_assignments (staff_id, section_id, role_key, is_primary, status, assigned_by, assigned_at)
             VALUES (?, ?, 'head_of_department', 1, 'active', 'migration', NOW())
             ON DUPLICATE KEY UPDATE is_primary = 1, status = 'active', updated_at = NOW()"
        );
        $stmt->bind_param('ss', $staffId, $sectionId);
        $stmt->execute();
        $stmt->close();
    }

    // 8. Keep departments.hod_id in step with the academic section head.
    //    The live schema keys departments by `id` and has no hod_id column, so
    //    only sync when both legacy columns actually exist.
    $hasHodId = false;
    $hasDeptIdCol = false;
    if ($res = $db->query("SHOW COLUMNS FROM departments LIKE 'hod_id'")) {
        $hasHodId = $res->num_rows > 0;
        $res->free();
    }
    if ($res = $db->query("SHOW COLUMNS FROM departments LIKE 'department_id'")) {
        $hasDeptIdCol = $res->num_rows > 0;
        $res->free();
    }
    if ($hasHodId && $hasDeptIdCol) {
        $db->query("
            UPDATE departments d
            INNER JOIN sections s ON s.department_id = d.department_id
            INNER JOIN staff_section_assignments ssa
                ON ssa.section_id = s.section_id
               AND ssa.role_key = 'head_of_department'
               AND ssa.status = 'active'
            SET d.hod_id = ssa.staff_id
            WHERE s.section_id = 'ENGICT'
        ");
    }

    $db->commit();

    echo "HOS section heads wired:\n";
    foreach ($hosAccounts as $sectionId => $acc) {
        echo "  {$sectionId} -> {$acc['staff_id']} ({$acc['first']} {$acc['last']})\n";
    }
    echo "\nHOS login password (test accounts): {$staffPassword}\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "HOS section-head migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

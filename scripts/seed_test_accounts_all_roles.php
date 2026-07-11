<?php
/**
 * Seed local test logins for every ITC portal role plus one student.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts\seed_test_accounts_all_roles.php
 */

require_once __DIR__ . '/../db/connect.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script from the command line.\n";
    exit(1);
}

$staffPassword = 'Test@12345';
$studentPassword = 'Student@12345';
$staffHash = password_hash($staffPassword, PASSWORD_DEFAULT);
$studentHash = password_hash($studentPassword, PASSWORD_DEFAULT);

$roles = [
    ['id' => 'ITC900', 'first' => 'Test', 'last' => 'AllRoles', 'role' => 'Systems Admin', 'canonical' => 'systems_admin', 'all_roles' => true],
    ['id' => 'ITC901', 'first' => 'Test', 'last' => 'Admin', 'role' => 'Systems Admin', 'canonical' => 'systems_admin'],
    ['id' => 'ITC902', 'first' => 'Test', 'last' => 'Admissions', 'role' => 'Admission Officer', 'canonical' => 'admission_officer'],
    ['id' => 'ITC903', 'first' => 'Test', 'last' => 'Finance', 'role' => 'Accountant', 'canonical' => 'accountant'],
    ['id' => 'ITC904', 'first' => 'Test', 'last' => 'EngICT-HOS', 'role' => 'Head of Section', 'canonical' => 'head_of_department'],
    ['id' => 'ITC905', 'first' => 'Test', 'last' => 'Registrar', 'role' => 'Registrar', 'canonical' => 'registrar'],
    ['id' => 'ITC906', 'first' => 'Test', 'last' => 'Dean', 'role' => 'Dean', 'canonical' => 'dean'],
    ['id' => 'ITC907', 'first' => 'Test', 'last' => 'Lecturer', 'role' => 'Lecturer', 'canonical' => 'lecturer'],
    ['id' => 'ITC908', 'first' => 'Test', 'last' => 'Library', 'role' => 'Librarian', 'canonical' => 'librarian'],
    ['id' => 'ITC909', 'first' => 'Test', 'last' => 'Transport', 'role' => 'Transport Officer', 'canonical' => 'transport_officer'],
    ['id' => 'ITC910', 'first' => 'Test', 'last' => 'Transport-HOS', 'role' => 'Head of Section', 'canonical' => 'head_of_department'],
];

$transportPermissions = [
    'transport_view' => 'View the Transport Section module',
    'transport_manage' => 'Manage Transport Section records',
];

$student = [
    'id' => 'CSE26456789',
    'first' => 'Test',
    'last' => 'Student',
    'email' => 'test.student@example.com',
    'mobile' => '26000000900',
    'nrc' => '123456/78/9',
    'program_code' => 'CSE',
];

function requireTable(mysqli $db, string $table): void
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    if (!$exists) {
        throw new RuntimeException("Required table {$table} does not exist.");
    }
}

function ensurePosition(mysqli $db, string $role): int
{
    $stmt = $db->prepare('SELECT PosID FROM positions WHERE PosName = ? LIMIT 1');
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $stmt->bind_result($posId);
    if ($stmt->fetch()) {
        $stmt->close();
        return (int)$posId;
    }
    $stmt->close();

    $description = 'Local test role seeded for login verification';
    $insert = $db->prepare('INSERT INTO positions (PosName, description, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
    $insert->bind_param('ss', $role, $description);
    $insert->execute();
    $insert->close();

    return (int)$db->insert_id;
}

try {
    $db->begin_transaction();

    foreach (['user_credentials', 'student_login', 'programs', 'students', 'student_program', 'staff', 'positions', 'staff_positions', 'access_right'] as $table) {
        requireTable($db, $table);
    }

    $col = $db->query("SHOW COLUMNS FROM student_login LIKE 'Password'")->fetch_assoc();
    if ($col && stripos((string)$col['Type'], 'varchar(255)') === false) {
        $db->query('ALTER TABLE student_login MODIFY Password VARCHAR(255) NOT NULL');
    }

    $positionIds = [];
    foreach ($roles as $role) {
        $positionIds[$role['role']] = ensurePosition($db, $role['role']);
    }

    if (!empty($positionIds['Transport Officer'])) {
        $transportPosId = (int)$positionIds['Transport Officer'];
        $permStmt = $db->prepare(
            'INSERT INTO role_permissions_legacy (PosID, permission_name, permission_description)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE permission_description = VALUES(permission_description)'
        );
        foreach ($transportPermissions as $permName => $permDesc) {
            $permStmt->bind_param('iss', $transportPosId, $permName, $permDesc);
            $permStmt->execute();
        }
        $permStmt->close();
    }

    foreach ($roles as $role) {
        $staffId = $role['id'];
        $title = 'Mr.';
        $sex = 'M';
        $email = strtolower($staffId) . '@example.com';
        $mobile = '26000000' . substr($staffId, -3);
        $qualification = 'Test Account';
        $nrcPass = '900000/' . substr($staffId, -2) . '/1';

        $staff = $db->prepare(
            'INSERT INTO staff (staff_id, title, Fname, Lname, sex, email, mobile, nrc_pass, password, role, status, qualification, failed_attempts, lockout_until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, 0, NULL)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                Fname = VALUES(Fname),
                Lname = VALUES(Lname),
                sex = VALUES(sex),
                email = VALUES(email),
                mobile = VALUES(mobile),
                nrc_pass = VALUES(nrc_pass),
                password = VALUES(password),
                role = VALUES(role),
                status = "active",
                qualification = VALUES(qualification),
                failed_attempts = 0,
                lockout_until = NULL'
        );
        $staff->bind_param(
            'sssssssssss',
            $staffId,
            $title,
            $role['first'],
            $role['last'],
            $sex,
            $email,
            $mobile,
            $nrcPass,
            $staffHash,
            $role['canonical'],
            $qualification
        );
        $staff->execute();
        $staff->close();

        if ($db->query("SHOW TABLES LIKE 'users'")->num_rows > 0) {
            $userIns = $db->prepare(
                'INSERT INTO users (username, password, primary_role, staff_id, status)
                 VALUES (?, ?, ?, ?, "active")
                 ON DUPLICATE KEY UPDATE password = VALUES(password), primary_role = VALUES(primary_role), status = "active"'
            );
            if ($userIns) {
                $userIns->bind_param('ssss', $staffId, $staffHash, $role['canonical'], $staffId);
                $userIns->execute();
                $userIns->close();
            }
        }

        $creds = $db->prepare('INSERT INTO user_credentials (staff_id, pass) VALUES (?, ?) ON DUPLICATE KEY UPDATE pass = VALUES(pass)');
        $staffMd5 = md5($staffPassword);
        $creds->bind_param('ss', $staffId, $staffMd5);
        $creds->execute();
        $creds->close();

        $access = $db->prepare('DELETE FROM access_right WHERE staff_id = ? AND assigned_access = ?');
        $access->bind_param('ss', $staffId, $role['role']);
        $access->execute();
        $access->close();

        $access = $db->prepare('INSERT INTO access_right (staff_id, assigned_access, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
        $access->bind_param('ss', $staffId, $role['role']);
        $access->execute();
        $access->close();

        $assignedPositionIds = !empty($role['all_roles']) ? array_values($positionIds) : [$positionIds[$role['role']]];
        foreach ($assignedPositionIds as $posId) {
            $staffPos = $db->prepare(
                'INSERT IGNORE INTO staff_positions (staff_id, PosID, created_at, updated_at)
                 VALUES (?, ?, NOW(), NOW())'
            );
            $staffPos->bind_param('si', $staffId, $posId);
            $staffPos->execute();
            $staffPos->close();
        }
    }

    $db->query(
        'INSERT INTO programs (program_code, program_name, program_type, study_mode, program_duration, is_active)
         VALUES ("CSE", "Diploma in Computer Systems Engineering", "Diploma", "Full Time", 2, 1)
         ON DUPLICATE KEY UPDATE is_active = 1'
    );

    $studentInsert = $db->prepare(
        'INSERT INTO students (SID, title, Fname, Lname, sex, dob, nrc_pass, country, email, mobile, status, academic_year, program, intake, mode, year)
         VALUES (?, "Mr.", ?, ?, "M", "2000-01-01", ?, "Zambia", ?, ?, "active", "2026", ?, "January", "Full Time", 1)
         ON DUPLICATE KEY UPDATE
            Fname = VALUES(Fname),
            Lname = VALUES(Lname),
            nrc_pass = VALUES(nrc_pass),
            email = VALUES(email),
            mobile = VALUES(mobile),
            program = VALUES(program),
            status = "active"'
    );
    $studentInsert->bind_param('sssssss', $student['id'], $student['first'], $student['last'], $student['nrc'], $student['email'], $student['mobile'], $student['program_code']);
    $studentInsert->execute();
    $studentInsert->close();

    $studentProgram = $db->prepare(
        'INSERT INTO student_program (Sid, program_code, intake, mode, startYear, endYear, status, academic_year)
         VALUES (?, ?, "January", "Full Time", 2026, 2027, "active", "2026")
         ON DUPLICATE KEY UPDATE program_code = VALUES(program_code), status = "active", academic_year = "2026"'
    );
    $studentProgram->bind_param('ss', $student['id'], $student['program_code']);
    $studentProgram->execute();
    $studentProgram->close();

    $studentLogin = $db->prepare(
        'INSERT INTO student_login (Sid, Password)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE Password = VALUES(Password)'
    );
    $studentLogin->bind_param('ss', $student['id'], $studentHash);
    $studentLogin->execute();
    $studentLogin->close();

    if ($db->query("SHOW TABLES LIKE 'users'")->num_rows > 0) {
        $studentUser = $db->prepare(
            'INSERT INTO users (username, password, primary_role, student_id, status)
             VALUES (?, ?, "student", ?, "active")
             ON DUPLICATE KEY UPDATE password = VALUES(password), status = "active"'
        );
        if ($studentUser) {
            $studentUser->bind_param('sss', $student['id'], $studentHash, $student['id']);
            $studentUser->execute();
            $studentUser->close();
        }
    }

    $legacyCleanup = [
        ['student_login', 'Sid', 'STU900'],
        ['student_program', 'Sid', 'STU900'],
        ['students', 'SID', 'STU900'],
    ];
    for ($n = 900; $n <= 910; $n++) {
        $legacyCleanup[] = ['access_right', 'staff_id', 'WUC' . $n];
        $legacyCleanup[] = ['staff_positions', 'staff_id', 'WUC' . $n];
        $legacyCleanup[] = ['user_credentials', 'staff_id', 'WUC' . $n];
        $legacyCleanup[] = ['staff', 'staff_id', 'WUC' . $n];
    }
    foreach ($legacyCleanup as $cleanup) {
        [$table, $column, $value] = $cleanup;
        $stmt = $db->prepare("DELETE FROM `$table` WHERE `$column` = ?");
        $stmt->bind_param('s', $value);
        $stmt->execute();
        $stmt->close();
    }

    $db->commit();

    echo "Created/updated ITC test accounts.\n\n";
    echo "Staff password for all role accounts: {$staffPassword}\n";
    foreach ($roles as $role) {
        $suffix = !empty($role['all_roles']) ? ' (all staff_positions roles)' : '';
        echo "{$role['id']} | {$role['role']}{$suffix}\n";
    }
    echo "\nStudent password: {$studentPassword}\n";
    echo "{$student['id']} | Test Student\n";
} catch (Throwable $e) {
    $db->rollback();
    echo 'Error seeding test accounts: ' . $e->getMessage() . "\n";
    exit(1);
}

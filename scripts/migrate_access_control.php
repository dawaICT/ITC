<?php
declare(strict_types=1);

/**
 * Access Control Migration Script.
 * Provisions unified users, roles, modules, permissions, role_permissions,
 * user_roles, user_module_access, and department_assignments tables.
 * Syncs legacy staff and student credentials.
 */

// ad-hoc root connection for DDL operations
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_errno) {
    die("Connection failed: " . $db->connect_error . "\n");
}

echo "Starting access control database migration...\n";

// 1. Rename legacy role_permissions if it exists and hasn't been renamed already
$res = $db->query("SHOW TABLES LIKE 'role_permissions'");
if ($res && $res->num_rows > 0) {
    // Check if it's the legacy schema (contains PosID)
    $colRes = $db->query("SHOW COLUMNS FROM role_permissions LIKE 'PosID'");
    if ($colRes && $colRes->num_rows > 0) {
        echo "Renaming legacy role_permissions to role_permissions_legacy...\n";
        $db->query("DROP TABLE IF EXISTS role_permissions_legacy");
        if (!$db->query("RENAME TABLE role_permissions TO role_permissions_legacy")) {
            die("Failed to rename legacy role_permissions: " . $db->error . "\n");
        }
    }
}

// 2. Create new tables
$queries = [
    "CREATE TABLE IF NOT EXISTS roles (
        role_id INT AUTO_INCREMENT PRIMARY KEY,
        role_name VARCHAR(50) NOT NULL UNIQUE,
        role_label VARCHAR(100) NOT NULL,
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS modules (
        module_id INT AUTO_INCREMENT PRIMARY KEY,
        module_name VARCHAR(100) NOT NULL,
        module_key VARCHAR(50) NOT NULL UNIQUE,
        module_url VARCHAR(255) DEFAULT NULL,
        module_icon VARCHAR(50) DEFAULT NULL,
        parent_module_id INT DEFAULT NULL,
        display_order INT DEFAULT 0,
        status VARCHAR(20) DEFAULT 'active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS permissions (
        permission_id INT AUTO_INCREMENT PRIMARY KEY,
        permission_key VARCHAR(100) NOT NULL UNIQUE,
        permission_label VARCHAR(100) NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        primary_role VARCHAR(50) NOT NULL,
        staff_id VARCHAR(20) DEFAULT NULL,
        student_id VARCHAR(50) DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_staff_id (staff_id),
        KEY idx_student_id (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS user_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        role_id INT NOT NULL,
        assigned_by VARCHAR(50) DEFAULT 'system',
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) DEFAULT 'active',
        UNIQUE KEY uniq_user_role (user_id, role_id),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        module_id INT NOT NULL,
        permission_id INT NOT NULL,
        status VARCHAR(20) DEFAULT 'active',
        UNIQUE KEY uniq_role_module_perm (role_id, module_id, permission_id),
        FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
        FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS user_module_access (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        module_id INT NOT NULL,
        permission_id INT NOT NULL,
        access_scope VARCHAR(50) DEFAULT 'all',
        scope_value VARCHAR(255) DEFAULT NULL,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        assigned_by VARCHAR(50) DEFAULT 'system',
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) DEFAULT 'active',
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS department_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id VARCHAR(20) NOT NULL,
        department_id INT NOT NULL,
        assignment_type VARCHAR(50) DEFAULT 'member',
        assigned_by VARCHAR(50) DEFAULT 'system',
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) DEFAULT 'active',
        UNIQUE KEY uniq_staff_dept_type (staff_id, department_id, assignment_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
];

foreach ($queries as $q) {
    if (!$db->query($q)) {
        die("DDL execution failed: " . $db->error . "\nQuery: $q\n");
    }
}
echo "Tables provisioned successfully.\n";

// 3. Seed roles
$roles = [
    'systems_admin' => 'Systems Administrator',
    'registrar' => 'Registrar',
    'exams_officer' => 'Exams Officer',
    'dean' => 'Dean',
    'head_of_department' => 'Head of Section',
    'accountant' => 'Accountant',
    'admission_officer' => 'Admission Officer',
    'librarian' => 'Librarian',
    'lecturer' => 'Lecturer',
    'transport_officer' => 'Transport Officer',
    'student' => 'Student',
    'staff' => 'Staff'
];

foreach ($roles as $name => $label) {
    $stmt = $db->prepare("INSERT INTO roles (role_name, role_label) VALUES (?, ?) ON DUPLICATE KEY UPDATE role_label = ?");
    $stmt->bind_param('sss', $name, $label, $label);
    $stmt->execute();
    $stmt->close();
}
echo "Roles seeded.\n";

// 4. Seed modules
$modules = [
    ['Admissions', 'admissions', '/wucportal/admissions/index.php', 'fa-user-plus', 2],
    ['Student Registration', 'student_registration', '/wucportal/admissions/regNewStud.php', 'fa-user-graduate', 3],
    ['Student Records', 'student_records', '/wucportal/registrar/index.php', 'fa-clipboard-list', 4],
    ['Programmes', 'programmes', '/wucportal/admin/programs.php', 'fa-graduation-cap', 5],
    ['Courses', 'courses', '/wucportal/admin/course_catalogue.php', 'fa-book', 6],
    ['Lecturer Assignment', 'lecturer_assignment', '/wucportal/admin/assign_course.php', 'fa-chalkboard-teacher', 7],
    ['CA Upload', 'ca_upload', '/wucportal/lecturers/upload_ca.php', 'fa-upload', 8],
    ['CA Approval', 'ca_approval', '/wucportal/hod/index.php', 'fa-check-circle', 9],
    ['eLearning', 'elearning', '/wucportal/elearning/index.php', 'fa-laptop-code', 10],
    ['Fees', 'fees', '/wucportal/accounts/index.php', 'fa-calculator', 11],
    ['Reports', 'reports', '/wucportal/reports/index.php', 'fa-chart-bar', 12],
    ['Departments', 'departments', '/wucportal/admin/departments.php', 'fa-building', 13],
    ['Users and Roles', 'users_roles', '/wucportal/admin/users_roles.php', 'fa-users-cog', 14],
    ['Settings', 'settings', '/wucportal/admin/settings.php', 'fa-cogs', 15],
    ['Transport Section', 'transport', '/wucportal/transport.php', 'fa-bus', 16],
    ['Library', 'library', '/wucportal/library/index.php', 'fa-book-open', 17]
];

foreach ($modules as $mod) {
    $stmt = $db->prepare("INSERT INTO modules (module_name, module_key, module_url, module_icon, display_order) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE module_name = ?, module_url = ?, module_icon = ?, display_order = ?");
    $stmt->bind_param('ssssisssi', $mod[0], $mod[1], $mod[2], $mod[3], $mod[4], $mod[0], $mod[2], $mod[3], $mod[4]);
    $stmt->execute();
    $stmt->close();
}
echo "Modules seeded.\n";

// 5. Seed permissions
$actions = ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'upload', 'download', 'print', 'export', 'assign', 'manage'];
$permissionsList = [];

foreach ($modules as $mod) {
    $modKey = $mod[1];
    foreach ($actions as $act) {
        $key = "$modKey.$act";
        $label = ucwords("$act $modKey");
        $desc = "Allow $act action on $modKey module";
        $permissionsList[$key] = [$label, $desc];
    }
}

foreach ($permissionsList as $key => $info) {
    $stmt = $db->prepare("INSERT INTO permissions (permission_key, permission_label, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE permission_label = ?, description = ?");
    $stmt->bind_param('sssss', $key, $info[0], $info[1], $info[0], $info[1]);
    $stmt->execute();
    $stmt->close();
}
echo "Permissions seeded.\n";

// 6. Map role permissions
function grantPermissionsToRole(mysqli $db, string $roleName, array $permKeys): void {
    $rStmt = $db->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
    $rStmt->bind_param('s', $roleName);
    $rStmt->execute();
    $rRow = $rStmt->get_result()->fetch_assoc();
    $rStmt->close();
    if (!$rRow) return;
    $roleId = (int)$rRow['role_id'];

    foreach ($permKeys as $pk) {
        $parts = explode('.', $pk);
        if (count($parts) !== 2) continue;
        $modKey = $parts[0];

        $mStmt = $db->prepare("SELECT module_id FROM modules WHERE module_key = ? LIMIT 1");
        $mStmt->bind_param('s', $modKey);
        $mStmt->execute();
        $mRow = $mStmt->get_result()->fetch_assoc();
        $mStmt->close();
        if (!$mRow) continue;
        $moduleId = (int)$mRow['module_id'];

        $pStmt = $db->prepare("SELECT permission_id FROM permissions WHERE permission_key = ? LIMIT 1");
        $pStmt->bind_param('s', $pk);
        $pStmt->execute();
        $pRow = $pStmt->get_result()->fetch_assoc();
        $pStmt->close();
        if (!$pRow) continue;
        $permissionId = (int)$pRow['permission_id'];

        $iStmt = $db->prepare("INSERT INTO role_permissions (role_id, module_id, permission_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = 'active'");
        $iStmt->bind_param('iii', $roleId, $moduleId, $permissionId);
        $iStmt->execute();
        $iStmt->close();
    }
}

// Grant permissions based on roles
$allPerms = array_keys($permissionsList);
grantPermissionsToRole($db, 'systems_admin', $allPerms);

$registrarPerms = [
    'dashboard.view', 'student_records.view', 'student_records.create', 'student_records.edit', 'student_records.manage',
    'programmes.view', 'courses.view', 'lecturer_assignment.view', 'lecturer_assignment.manage', 'reports.view', 'departments.view'
];
grantPermissionsToRole($db, 'registrar', $registrarPerms);

$admissionsPerms = [
    'dashboard.view', 'admissions.view', 'admissions.create', 'admissions.edit', 'admissions.manage',
    'student_registration.view', 'student_registration.create', 'student_registration.edit', 'student_registration.manage',
    'programmes.view', 'courses.view'
];
grantPermissionsToRole($db, 'admission_officer', $admissionsPerms);

$hodPerms = [
    'dashboard.view', 'programmes.view', 'courses.view', 'ca_approval.view', 'ca_approval.approve', 'ca_approval.reject', 'reports.view'
];
grantPermissionsToRole($db, 'head_of_department', $hodPerms);

$deanPerms = [
    'dashboard.view', 'programmes.view', 'courses.view', 'ca_approval.view', 'reports.view'
];
grantPermissionsToRole($db, 'dean', $deanPerms);

$accountantPerms = [
    'dashboard.view', 'fees.view', 'fees.create', 'fees.edit', 'fees.manage', 'reports.view'
];
grantPermissionsToRole($db, 'accountant', $accountantPerms);

$libPerms = [
    'dashboard.view', 'library.view', 'library.manage'
];
grantPermissionsToRole($db, 'librarian', $libPerms);

$lecturerPerms = [
    'dashboard.view', 'ca_upload.view', 'ca_upload.upload', 'elearning.view', 'elearning.manage',
    // Lecturers are an academic-section role: the lecturer module nav gates on
    // canAccessAcademics() (programmes|courses|lecturer_assignment). Grant the
    // least-powerful academic module so genuine lecturers pass that gate.
    'lecturer_assignment.view'
];
grantPermissionsToRole($db, 'lecturer', $lecturerPerms);

$transPerms = [
    'dashboard.view', 'transport.view', 'transport.manage'
];
grantPermissionsToRole($db, 'transport_officer', $transPerms);

$studentPerms = [
    'dashboard.view', 'elearning.view'
];
grantPermissionsToRole($db, 'student', $studentPerms);

$staffPerms = [
    'dashboard.view'
];
grantPermissionsToRole($db, 'staff', $staffPerms);

echo "Role permissions mapped.\n";

// 7. Migrate legacy staff credentials & roles
if ($res = $db->query("SELECT * FROM staff")) {
    echo "Migrating staff members...\n";
    $roleMap = [
        'systems_admin' => 'systems_admin',
        'systems admin' => 'systems_admin',
        'system admin' => 'systems_admin',
        'system administrator' => 'systems_admin',
        'super_admin' => 'systems_admin',
        'superadmin' => 'systems_admin',
        'super admin' => 'systems_admin',
        'admin' => 'systems_admin',
        'administrator' => 'systems_admin',
        'administration' => 'systems_admin',
        'administrative' => 'systems_admin',
        'manager' => 'systems_admin',
        'director' => 'systems_admin',
        'lecturer' => 'lecturer',
        'assistant lecturer' => 'lecturer',
        'part time lecturer' => 'lecturer',
        'part-time lecturer' => 'lecturer',
        'tutor' => 'lecturer',
        'instructor' => 'lecturer',
        'head_of_department' => 'head_of_department',
        'head of department' => 'head_of_department',
        'head of section' => 'head_of_department',
        'hod' => 'head_of_department',
        'hos' => 'head_of_department',
        'dean' => 'dean',
        'registrar' => 'registrar',
        'exams_officer' => 'exams_officer',
        'exams' => 'exams_officer',
        'exam officer' => 'exams_officer',
        'exams officer' => 'exams_officer',
        'admission_officer' => 'admission_officer',
        'admission officer' => 'admission_officer',
        'admissions officer' => 'admission_officer',
        'admission' => 'admission_officer',
        'admissions' => 'admission_officer',
        'accountant' => 'accountant',
        'accounts' => 'accountant',
        'finance' => 'accountant',
        'finance officer' => 'accountant',
        'bursar' => 'accountant',
        'librarian' => 'librarian',
        'library staff' => 'librarian',
        'transport_officer' => 'transport_officer',
        'transport officer' => 'transport_officer',
        'transport' => 'transport_officer',
        'driver' => 'transport_officer',
        'driving instructor' => 'transport_officer',
        'staff' => 'staff'
    ];

    while ($staff = $res->fetch_assoc()) {
        $staffId = trim((string)$staff['staff_id']);
        if ($staffId === '') continue;

        $username = $staffId;
        $password = (string)$staff['password'];
        if ($password === '') {
            $password = password_hash('password123', PASSWORD_DEFAULT);
        }

        $primaryRaw = strtolower(trim((string)$staff['role']));
        $primaryRole = $roleMap[$primaryRaw] ?? 'staff';

        $uStmt = $db->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $uStmt->bind_param('s', $username);
        $uStmt->execute();
        $uRow = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();

        if ($uRow) {
            $userId = (int)$uRow['user_id'];
        } else {
            $insStmt = $db->prepare("INSERT INTO users (username, password, primary_role, staff_id, status) VALUES (?, ?, ?, ?, ?)");
            $status = (string)$staff['status'];
            if ($status === '') $status = 'active';
            $insStmt->bind_param('sssss', $username, $password, $primaryRole, $staffId, $status);
            $insStmt->execute();
            $userId = (int)$insStmt->insert_id;
            $insStmt->close();
        }

        $rolesToAssign = [$primaryRole];

        $spStmt = $db->prepare("SELECT p.PosName FROM staff_positions sp JOIN positions p ON p.PosID = sp.PosID WHERE sp.staff_id = ?");
        if ($spStmt) {
            $spStmt->bind_param('s', $staffId);
            $spStmt->execute();
            $spRes = $spStmt->get_result();
            while ($spRow = $spRes->fetch_assoc()) {
                $posRaw = strtolower(trim((string)$spRow['PosName']));
                $posRole = $roleMap[$posRaw] ?? null;
                if ($posRole && !in_array($posRole, $rolesToAssign, true)) {
                    $rolesToAssign[] = $posRole;
                }
            }
            $spStmt->close();
        }

        $arStmt = $db->prepare("SELECT assigned_access FROM access_right WHERE staff_id = ?");
        if ($arStmt) {
            $arStmt->bind_param('s', $staffId);
            $arStmt->execute();
            $arRes = $arStmt->get_result();
            while ($arRow = $arRes->fetch_assoc()) {
                $arRaw = strtolower(trim((string)$arRow['assigned_access']));
                $arRole = $roleMap[$arRaw] ?? null;
                if ($arRole && !in_array($arRole, $rolesToAssign, true)) {
                    $rolesToAssign[] = $arRole;
                }
            }
            $arStmt->close();
        }

        foreach ($rolesToAssign as $rName) {
            $rStmt = $db->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
            $rStmt->bind_param('s', $rName);
            $rStmt->execute();
            $rRow = $rStmt->get_result()->fetch_assoc();
            $rStmt->close();
            if ($rRow) {
                $roleId = (int)$rRow['role_id'];
                $urStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = 'active'");
                $urStmt->bind_param('ii', $userId, $roleId);
                $urStmt->execute();
                $urStmt->close();
            }
        }
    }
    echo "Staff members migrated successfully.\n";
}

// 8. Migrate legacy student credentials
if ($res = $db->query("SELECT * FROM student_login")) {
    echo "Migrating students...\n";

    $rStmt = $db->prepare("SELECT role_id FROM roles WHERE role_name = 'student' LIMIT 1");
    $rStmt->execute();
    $rRow = $rStmt->get_result()->fetch_assoc();
    $rStmt->close();
    $studentRoleId = $rRow ? (int)$rRow['role_id'] : null;

    while ($student = $res->fetch_assoc()) {
        $sid = trim((string)$student['Sid']);
        if ($sid === '') continue;

        $username = $sid;
        $password = (string)$student['Password'];

        $uStmt = $db->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $uStmt->bind_param('s', $username);
        $uStmt->execute();
        $uRow = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();

        if ($uRow) {
            $userId = (int)$uRow['user_id'];
        } else {
            $insStmt = $db->prepare("INSERT INTO users (username, password, primary_role, student_id, status) VALUES (?, ?, 'student', ?, 'active')");
            $insStmt->bind_param('sss', $username, $password, $sid);
            $insStmt->execute();
            $userId = (int)$insStmt->insert_id;
            $insStmt->close();
        }

        if ($studentRoleId !== null) {
            $urStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = 'active'");
            $urStmt->bind_param('ii', $userId, $studentRoleId);
            $urStmt->execute();
            $urStmt->close();
        }
    }
    echo "Students migrated successfully.\n";
}

// 9. Sync HOD department assignments from staff.deptId
if ($res = $db->query("SELECT staff_id, deptId, role FROM staff WHERE deptId IS NOT NULL AND deptId > 0")) {
    echo "Syncing department HOD assignments...\n";
    while ($row = $res->fetch_assoc()) {
        $staffId = trim((string)$row['staff_id']);
        $deptId = (int)$row['deptId'];
        $roleRaw = strtolower(trim((string)$row['role']));

        $isHod = in_array($roleRaw, ['head_of_department', 'head of section', 'hos', 'hod'], true);
        $type = $isHod ? 'hod' : 'member';

        $daStmt = $db->prepare("INSERT INTO department_assignments (staff_id, department_id, assignment_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = 'active'");
        $daStmt->bind_param('sis', $staffId, $deptId, $type);
        $daStmt->execute();
        $daStmt->close();
    }
    echo "Department assignments synced.\n";
}

echo "Database migration completed successfully!\n";
?>

<?php
declare(strict_types=1);

/**
 * Add the shared multi-portal access layer without replacing legacy login,
 * roles, permissions, or module tables.
 *
 * Run:
 *   C:\xampp\php\php.exe migrations\20260630_multi_portal_access_layer.php
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

function migration_column_exists(mysqli $db, string $table, string $column): bool
{
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $exists;
}

function migration_table_exists(mysqli $db, string $table): bool
{
    $table = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$table}'");
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $exists;
}

$db->begin_transaction();

try {
    $db->query("
        CREATE TABLE IF NOT EXISTS portals (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            portal_code VARCHAR(40) NOT NULL,
            portal_name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_portals_code (portal_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS user_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            person_type VARCHAR(40) NOT NULL,
            student_id VARCHAR(50) NULL,
            staff_id VARCHAR(50) NULL,
            applicant_id VARCHAR(50) NULL,
            display_name VARCHAR(160) NULL,
            profile_photo VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_profiles_user (user_id),
            KEY idx_user_profiles_student (student_id),
            KEY idx_user_profiles_staff (staff_id),
            CONSTRAINT fk_user_profiles_user
                FOREIGN KEY (user_id) REFERENCES users(user_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS user_portal_access (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            portal_id INT(11) NOT NULL,
            access_status VARCHAR(20) NOT NULL DEFAULT 'active',
            start_date DATE NULL,
            end_date DATE NULL,
            assigned_by VARCHAR(50) NULL DEFAULT 'system',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_portal (user_id, portal_id),
            KEY idx_user_portal_user (user_id),
            KEY idx_user_portal_portal (portal_id),
            KEY idx_user_portal_dates (access_status, start_date, end_date),
            CONSTRAINT fk_user_portal_user
                FOREIGN KEY (user_id) REFERENCES users(user_id)
                ON DELETE CASCADE,
            CONSTRAINT fk_user_portal_portal
                FOREIGN KEY (portal_id) REFERENCES portals(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS ai_contexts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            portal_id INT(11) NOT NULL,
            module_name VARCHAR(80) NOT NULL,
            user_role VARCHAR(80) NOT NULL,
            context_type VARCHAR(80) NOT NULL,
            rules TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ai_context (portal_id, module_name, user_role, context_type),
            KEY idx_ai_context_portal (portal_id),
            CONSTRAINT fk_ai_context_portal
                FOREIGN KEY (portal_id) REFERENCES portals(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!migration_column_exists($db, 'user_roles', 'portal_id')) {
        $db->query("ALTER TABLE user_roles ADD COLUMN portal_id INT(11) NULL AFTER role_id");
        $db->query("ALTER TABLE user_roles ADD KEY idx_user_roles_portal (portal_id)");
    }
    if (!migration_column_exists($db, 'role_permissions', 'portal_id')) {
        $db->query("ALTER TABLE role_permissions ADD COLUMN portal_id INT(11) NULL AFTER permission_id");
        $db->query("ALTER TABLE role_permissions ADD KEY idx_role_permissions_portal (portal_id)");
    }
    if (!migration_column_exists($db, 'sections', 'section_code')) {
        $db->query("ALTER TABLE sections ADD COLUMN section_code VARCHAR(30) NULL AFTER section_id");
        $db->query("UPDATE sections SET section_code = section_id WHERE section_code IS NULL OR section_code = ''");
    }
    if (!migration_column_exists($db, 'staff_section_assignments', 'start_date')) {
        $db->query("ALTER TABLE staff_section_assignments ADD COLUMN start_date DATE NULL AFTER is_primary");
    }
    if (!migration_column_exists($db, 'staff_section_assignments', 'end_date')) {
        $db->query("ALTER TABLE staff_section_assignments ADD COLUMN end_date DATE NULL AFTER start_date");
    }

    $portalStmt = $db->prepare("
        INSERT INTO portals (portal_code, portal_name, description, status)
        VALUES (?, ?, ?, 'active')
        ON DUPLICATE KEY UPDATE
            portal_name = VALUES(portal_name),
            description = VALUES(description),
            status = 'active'
    ");
    $portals = [
        ['academic', 'Academic Portal', 'Admissions, registration, finance, results, reports, library, HOS and staff administration.'],
        ['elearning', 'eLearning Portal', 'Teaching and learning workspace for courses, lessons, assignments, quizzes, discussions and progress.'],
        ['applicant', 'Applicant Portal', 'Prospective student applications and admissions follow-up.'],
        ['alumni', 'Alumni Portal', 'Graduate records, certificates, alumni services and engagement.'],
        ['employer', 'Employer Portal', 'Employer placement, internship and recruitment services.'],
        ['library', 'Library Portal', 'Library resources, circulation and digital collections.'],
    ];
    foreach ($portals as $portal) {
        $portalStmt->bind_param('sss', $portal[0], $portal[1], $portal[2]);
        $portalStmt->execute();
    }
    $portalStmt->close();

    $db->query("
        INSERT INTO sections (section_id, section_code, section_name, section_type, department_id, status)
        VALUES
            ('ENGICT', 'ENGICT', 'Engineering and ICT', 'academic', NULL, 'active'),
            ('TRANSPORT', 'TRANSPORT', 'Transport', 'transport', NULL, 'active')
        ON DUPLICATE KEY UPDATE
            section_code = VALUES(section_code),
            section_name = VALUES(section_name),
            section_type = VALUES(section_type),
            status = 'active'
    ");

    if (migration_column_exists($db, 'departments', 'section_id')) {
        $db->query("
            UPDATE departments
               SET section_id = 'TRANSPORT'
             WHERE section_id IS NULL
               AND LOWER(department_name) REGEXP 'transport|driver|fleet|vehicle|rtsa'
        ");
        $db->query("
            UPDATE departments
               SET section_id = 'ENGICT'
             WHERE section_id IS NULL
               AND LOWER(department_name) REGEXP 'ict|computer|automotive|mechanical|electrical|welding|plumbing|refrigeration|engineering'
        ");
    }

    $academicPortalId = (int)$db->query("SELECT id FROM portals WHERE portal_code = 'academic'")->fetch_assoc()['id'];
    $elearningPortalId = (int)$db->query("SELECT id FROM portals WHERE portal_code = 'elearning'")->fetch_assoc()['id'];

    $db->query("
        INSERT INTO user_portal_access (user_id, portal_id, access_status, start_date, assigned_by)
        SELECT u.user_id, {$academicPortalId}, 'active', CURDATE(), 'migration'
          FROM users u
         WHERE COALESCE(u.status, 'active') IN ('active', 'enabled')
        ON DUPLICATE KEY UPDATE access_status = VALUES(access_status)
    ");

    $db->query("
        INSERT INTO user_portal_access (user_id, portal_id, access_status, start_date, assigned_by)
        SELECT DISTINCT u.user_id, {$elearningPortalId}, 'active', CURDATE(), 'migration'
          FROM users u
          LEFT JOIN user_roles ur ON ur.user_id = u.user_id AND ur.status = 'active'
          LEFT JOIN roles r ON r.role_id = ur.role_id AND r.status = 'active'
         WHERE COALESCE(u.status, 'active') IN ('active', 'enabled')
           AND (
                u.primary_role IN ('systems_admin', 'lecturer', 'head_of_department')
                OR r.role_name IN ('systems_admin', 'lecturer', 'head_of_department')
                OR EXISTS (SELECT 1 FROM course_lecturer cl WHERE cl.staff_id = u.staff_id AND COALESCE(cl.status, 'active') = 'active')
                OR EXISTS (SELECT 1 FROM lecturer_course_assignments lca WHERE lca.staff_id = u.staff_id AND lca.status = 'active')
                OR EXISTS (SELECT 1 FROM course_registration cr WHERE cr.Sid = u.student_id AND COALESCE(cr.is_active, 1) = 1)
                OR EXISTS (SELECT 1 FROM student_courses sc WHERE sc.student_id = u.student_id AND sc.status IN ('registered', 'completed', 'incomplete'))
                OR EXISTS (
                    SELECT 1
                      FROM student_course_registrations scr
                      JOIN student_program sp ON sp.id = scr.student_programme_id
                     WHERE sp.Sid = u.student_id
                       AND scr.registration_status IN ('REGISTERED', 'COMPLETED', 'REPEATING')
                )
           )
        ON DUPLICATE KEY UPDATE access_status = VALUES(access_status)
    ");

    $db->query("
        INSERT INTO user_profiles (user_id, person_type, student_id, staff_id, display_name)
        SELECT u.user_id,
               CASE WHEN u.student_id IS NOT NULL THEN 'student'
                    WHEN u.staff_id IS NOT NULL THEN 'staff'
                    ELSE COALESCE(NULLIF(u.primary_role, ''), 'user') END,
               u.student_id,
               u.staff_id,
               COALESCE(
                   NULLIF(TRIM(CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, ''))), ''),
                   NULLIF(TRIM(CONCAT(COALESCE(st.Fname, ''), ' ', COALESCE(st.Lname, ''))), ''),
                   u.username
               )
          FROM users u
          LEFT JOIN students s ON s.SID = u.student_id
          LEFT JOIN staff st ON st.staff_id = u.staff_id
        ON DUPLICATE KEY UPDATE
            person_type = VALUES(person_type),
            student_id = VALUES(student_id),
            staff_id = VALUES(staff_id),
            display_name = VALUES(display_name)
    ");

    $contextStmt = $db->prepare("
        INSERT INTO ai_contexts (portal_id, module_name, user_role, context_type, rules, status)
        VALUES (?, ?, ?, ?, ?, 'active')
        ON DUPLICATE KEY UPDATE rules = VALUES(rules), status = 'active'
    ");
    $contexts = [
        [$academicPortalId, 'academic', 'student', 'Academic AI Assistant', 'Support profile, registration, fees, results, timetable, library and clearance guidance. Do not expose eLearning-only content unless the user has eLearning access.'],
        [$academicPortalId, 'academic', 'lecturer', 'Academic AI Assistant', 'Support assigned courses, student lists, attendance, CA upload, results submission, timetable and reports. Respect lecturer assignment scope.'],
        [$academicPortalId, 'hos', 'head_of_department', 'HOS AI Insights', 'Provide section-aware academic or transport insights only for the assigned HOS section. Never invent records.'],
        [$academicPortalId, 'finance', 'accountant', 'Finance AI Assistant', 'Explain fees and eligibility from authorized finance context only.'],
        [$academicPortalId, 'library', 'librarian', 'Library AI Assistant', 'Support library circulation and resource discovery without exposing restricted academic or finance records.'],
        [$elearningPortalId, 'learning', 'student', 'Learning AI Tutor', 'Tutor only registered course content, assignments, quizzes, practical tasks and study recommendations. Do not expose finance, admissions or registrar data.'],
        [$elearningPortalId, 'teaching', 'lecturer', 'AI Teaching Assistant', 'Support online course preparation, lessons, assignments, quizzes, discussions and learner progress only for assigned courses.'],
    ];
    foreach ($contexts as $context) {
        $contextStmt->bind_param('issss', $context[0], $context[1], $context[2], $context[3], $context[4]);
        $contextStmt->execute();
    }
    $contextStmt->close();

    $db->commit();
    echo "Multi-portal access layer is ready.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "Multi-portal migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

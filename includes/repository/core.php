<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/security.php';
require_once dirname(__DIR__) . '/role_helpers.php';
require_once dirname(__DIR__) . '/elearning_access.php';
require_once dirname(__DIR__) . '/ai_portal.php';
require_once dirname(__DIR__) . '/schema_guard.php';

if (!function_exists('repo_h')) {
    function repo_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function repo_material_types(): array
{
    return [
        'lecture_notes' => 'Lecture notes',
        'past_papers' => 'Past papers',
        'question_banks' => 'Question banks',
        'assignments' => 'Assignments',
        'practical_manuals' => 'Practical manuals',
        'slides' => 'Slides',
        'videos' => 'Videos',
        'external_links' => 'External links',
        'research_materials' => 'Research materials',
        'policies' => 'Policies',
        'other' => 'Other academic resources',
    ];
}

function repo_visibility_levels(): array
{
    return [
        'private' => 'Private',
        'department' => 'Department',
        'programme' => 'Programme',
        'course' => 'Course',
        'lecturers_only' => 'Lecturers only',
        'students_only' => 'Students only',
        'public' => 'Public',
    ];
}

function repo_statuses(): array
{
    return [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
    ];
}

function repo_table_exists(mysqli $db, string $table): bool
{
    try {
        if (function_exists('wuc_table_exists')) {
            return wuc_table_exists($db, $table);
        }
        $safe = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '{$safe}'");
        if (!$res) {
            return false;
        }
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    } catch (Throwable $e) {
        error_log('Repository table check failed for ' . $table . ': ' . $e->getMessage());
        return false;
    }
}

function repo_columns(mysqli $db, string $table): array
{
    static $cache = [];
    $key = strtolower($table);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $columns = [];
    try {
        $res = $db->query("SHOW COLUMNS FROM `{$table}`");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
            $res->free();
        }
    } catch (Throwable $e) {
        error_log('Repository column check failed for ' . $table . ': ' . $e->getMessage());
    }
    $cache[$key] = $columns;
    return $columns;
}

function repo_detect_column(mysqli $db, string $table, array $candidates): ?string
{
    $columns = repo_columns($db, $table);
    foreach ($candidates as $candidate) {
        $key = strtolower((string)$candidate);
        if (isset($columns[$key])) {
            return $columns[$key];
        }
    }
    return null;
}

function repo_ensure_schema(mysqli $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $queries = [
        "CREATE TABLE IF NOT EXISTS repository_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS repository_materials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            material_type VARCHAR(50) NOT NULL,
            upload_mode ENUM('file','link') NOT NULL DEFAULT 'file',
            file_path VARCHAR(500) NULL,
            external_url VARCHAR(700) NULL,
            original_filename VARCHAR(255) NULL,
            mime_type VARCHAR(150) NULL,
            file_size BIGINT NULL,
            checksum_sha256 CHAR(64) NULL,
            uploader_staff_id VARCHAR(50) NULL,
            department_id VARCHAR(80) NULL,
            programme_code VARCHAR(80) NULL,
            course_code VARCHAR(80) NULL,
            academic_year VARCHAR(20) NULL,
            year_of_study INT NULL,
            term VARCHAR(30) NULL,
            semester VARCHAR(30) NULL,
            requested_visibility VARCHAR(40) NOT NULL DEFAULT 'course',
            visibility VARCHAR(40) NOT NULL DEFAULT 'course',
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            rejection_reason TEXT NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            is_public_summary TINYINT(1) NOT NULL DEFAULT 0,
            approved_by VARCHAR(50) NULL,
            reviewed_at DATETIME NULL,
            ai_status VARCHAR(30) NOT NULL DEFAULT 'not_processed',
            ai_error TEXT NULL,
            view_count INT NOT NULL DEFAULT 0,
            download_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_repo_status (status, is_published, is_archived),
            INDEX idx_repo_scope (course_code, programme_code, department_id),
            INDEX idx_repo_uploader (uploader_staff_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS repository_material_categories (
            material_id INT NOT NULL,
            category_id INT NOT NULL,
            PRIMARY KEY (material_id, category_id),
            INDEX idx_repo_mc_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS repository_access_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            material_id INT NOT NULL,
            action VARCHAR(30) NOT NULL,
            user_role VARCHAR(30) NOT NULL,
            user_id VARCHAR(80) NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_repo_log_material (material_id, action),
            INDEX idx_repo_log_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS repository_ai_metadata (
            material_id INT PRIMARY KEY,
            summary MEDIUMTEXT NULL,
            keywords TEXT NULL,
            topics TEXT NULL,
            study_guide MEDIUMTEXT NULL,
            difficulty_level VARCHAR(50) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            error_message TEXT NULL,
            generated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS repository_ai_questions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            material_id INT NOT NULL,
            question_type VARCHAR(40) NOT NULL DEFAULT 'revision',
            question_text TEXT NOT NULL,
            answer_text TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_repo_aiq_material (material_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS repository_ai_search_index (
            material_id INT PRIMARY KEY,
            keywords_text MEDIUMTEXT NULL,
            embedding_json MEDIUMTEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    if (function_exists('wuc_ensure_tables')) {
        wuc_ensure_tables($db, $queries);
    }

    if (!repo_table_exists($db, 'repository_categories')) {
        return;
    }

    $defaults = [
        'Lecture notes', 'Past papers', 'Question banks', 'Assignments', 'Practical manuals',
        'Slides', 'Videos', 'External links', 'Research materials', 'Policies', 'Other academic resources',
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO repository_categories (name) VALUES (?)");
    if ($stmt) {
        foreach ($defaults as $name) {
            $stmt->bind_param('s', $name);
            $stmt->execute();
        }
        $stmt->close();
    }
}

function repo_schema_ready(mysqli $db): bool
{
    repo_ensure_schema($db);
    return repo_table_exists($db, 'repository_materials')
        && repo_table_exists($db, 'repository_categories')
        && repo_table_exists($db, 'repository_access_logs')
        && repo_table_exists($db, 'repository_ai_metadata')
        && repo_table_exists($db, 'repository_ai_questions')
        && repo_table_exists($db, 'repository_ai_search_index');
}

function repo_current_staff_id(): string
{
    return (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
}

function repo_is_admin(): bool
{
    return function_exists('isSystemsAdmin') && isSystemsAdmin();
}

function repo_user_role(): string
{
    if (!empty($_SESSION['Sid'])) {
        return 'student';
    }
    if (!empty($_SESSION['staff_id']) || !empty($_SESSION['user_id'])) {
        if (repo_is_admin()) {
            return 'admin';
        }
        if (function_exists('hasRole') && hasRole(ROLE_LECTURER)) {
            return 'lecturer';
        }
        if (function_exists('hasAnyRole') && hasAnyRole([ROLE_HEAD_OF_DEPARTMENT, ROLE_DEAN])) {
            return 'hod';
        }
        return 'staff';
    }
    return 'public';
}

function repo_fetch_material(mysqli $db, int $id): ?array
{
    repo_ensure_schema($db);
    if (!repo_table_exists($db, 'repository_materials')) {
        return null;
    }
    $stmt = $db->prepare("SELECT m.*, CONCAT(COALESCE(s.Fname,''),' ',COALESCE(s.Lname,'')) AS uploader_name
                            FROM repository_materials m
                            LEFT JOIN staff s ON s.staff_id = m.uploader_staff_id COLLATE utf8mb4_unicode_ci
                           WHERE m.id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function repo_student_programs(mysqli $db, string $sid): array
{
    $programs = [];
    if ($sid === '') {
        return [];
    }
    if (repo_table_exists($db, 'student_program')) {
        $sidCol = repo_detect_column($db, 'student_program', ['Sid', 'student_id', 'SID']);
        $programCol = repo_detect_column($db, 'student_program', ['program_code', 'program']);
        $statusCol = repo_detect_column($db, 'student_program', ['status']);
        if ($sidCol && $programCol) {
            $statusSql = $statusCol ? " AND (`{$statusCol}` IS NULL OR LOWER(TRIM(`{$statusCol}`)) IN ('active','current','registered'))" : '';
            $sql = "SELECT DISTINCT TRIM(`{$programCol}`) AS program_code FROM student_program WHERE `{$sidCol}` = ?{$statusSql}";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $sid);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    if (trim((string)$row['program_code']) !== '') {
                        $programs[] = trim((string)$row['program_code']);
                    }
                }
                $stmt->close();
            }
        }
    }
    if (repo_table_exists($db, 'students')) {
        $programCol = repo_detect_column($db, 'students', ['program', 'program_code']);
        if ($programCol) {
            if ($stmt = $db->prepare("SELECT `{$programCol}` AS program_code FROM students WHERE SID = ? LIMIT 1")) {
                $stmt->bind_param('s', $sid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && trim((string)$row['program_code']) !== '') {
                    $programs[] = trim((string)$row['program_code']);
                }
            }
        }
    }
    return array_values(array_unique($programs));
}

function repo_program_departments(mysqli $db, array $programCodes): array
{
    $programCodes = array_values(array_filter(array_unique(array_map('strval', $programCodes))));
    if ($programCodes === [] || !repo_table_exists($db, 'programs')) {
        return [];
    }
    $programCol = repo_detect_column($db, 'programs', ['program_code', 'code']);
    $deptCol = repo_detect_column($db, 'programs', ['department_id', 'deptId', 'department']);
    if (!$programCol || !$deptCol) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($programCodes), '?'));
    $types = str_repeat('s', count($programCodes));
    $departments = [];
    $stmt = $db->prepare("SELECT DISTINCT `{$deptCol}` AS department_id FROM programs WHERE `{$programCol}` IN ($ph)");
    if ($stmt) {
        $stmt->bind_param($types, ...$programCodes);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (trim((string)$row['department_id']) !== '') {
                $departments[] = trim((string)$row['department_id']);
            }
        }
        $stmt->close();
    }
    return array_values(array_unique($departments));
}

function repo_staff_departments(mysqli $db, string $staffId): array
{
    if ($staffId === '' || !repo_table_exists($db, 'staff')) {
        return [];
    }
    $deptCol = repo_detect_column($db, 'staff', ['deptId', 'department_id', 'department']);
    if (!$deptCol) {
        return [];
    }
    $departments = [];
    if ($stmt = $db->prepare("SELECT `{$deptCol}` AS department_id FROM staff WHERE staff_id = ? LIMIT 1")) {
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && trim((string)$row['department_id']) !== '') {
            $departments[] = trim((string)$row['department_id']);
        }
    }
    return $departments;
}

function repo_material_is_active(array $material): bool
{
    return strtolower((string)$material['status']) === 'approved'
        && (int)$material['is_published'] === 1
        && (int)$material['is_archived'] === 0;
}

function repo_can_access_material(mysqli $db, array $material, string $context = ''): bool
{
    $role = repo_user_role();
    if ($role === 'admin') {
        return true;
    }
    if (!repo_material_is_active($material)) {
        return false;
    }

    $visibility = strtolower((string)($material['visibility'] ?? 'private'));
    if ($visibility === 'public') {
        return true;
    }
    if ($role === 'public') {
        return false;
    }

    if ($role === 'student') {
        $sid = (string)($_SESSION['Sid'] ?? $_SESSION['student_id'] ?? '');
        if ($visibility === 'students_only') {
            return $sid !== '';
        }
        $programs = repo_student_programs($db, $sid);
        if ($visibility === 'programme') {
            return in_array((string)$material['programme_code'], $programs, true);
        }
        if ($visibility === 'course') {
            $course = trim((string)$material['course_code']);
            return $course === '' || isStudentEnrolledInCourse($db, $sid, $course);
        }
        if ($visibility === 'department') {
            $departments = repo_program_departments($db, $programs);
            return in_array((string)$material['department_id'], $departments, true);
        }
        return false;
    }

    $staffId = repo_current_staff_id();
    if ($staffId !== '' && (string)$material['uploader_staff_id'] === $staffId) {
        return true;
    }
    if ($role === 'lecturer' || $role === 'hod' || $role === 'staff') {
        if ($visibility === 'lecturers_only') {
            return $role === 'lecturer' || $role === 'hod';
        }
        if ($visibility === 'course') {
            $course = trim((string)$material['course_code']);
            return $course === '' || isLecturerAssignedToCourse($db, $staffId, $course);
        }
        if ($visibility === 'department') {
            return in_array((string)$material['department_id'], repo_staff_departments($db, $staffId), true);
        }
        if ($visibility === 'programme') {
            $course = trim((string)$material['course_code']);
            return $course !== '' && isLecturerAssignedToCourse($db, $staffId, $course);
        }
    }
    return false;
}

function repo_accessible_materials(mysqli $db, array $filters = [], int $limit = 200): array
{
    repo_ensure_schema($db);
    if (!repo_table_exists($db, 'repository_materials')) {
        return [];
    }
    $where = [];
    $types = '';
    $params = [];
    if (!repo_is_admin()) {
        $where[] = "m.status = 'approved'";
        $where[] = "m.is_published = 1";
        $where[] = "m.is_archived = 0";
    }
    foreach (['status', 'material_type', 'course_code', 'programme_code', 'department_id', 'academic_year', 'term', 'semester'] as $field) {
        if (isset($filters[$field]) && trim((string)$filters[$field]) !== '') {
            $where[] = "m.`{$field}` = ?";
            $types .= 's';
            $params[] = trim((string)$filters[$field]);
        }
    }
    if (!empty($filters['public'])) {
        $where[] = "m.visibility = 'public'";
    }
    $hasAiMetadata = repo_table_exists($db, 'repository_ai_metadata');
    $hasStaff = repo_table_exists($db, 'staff') && repo_detect_column($db, 'staff', ['staff_id']) !== null;
    if (isset($filters['q']) && trim((string)$filters['q']) !== '') {
        $q = '%' . trim((string)$filters['q']) . '%';
        if ($hasAiMetadata) {
            $where[] = "(m.title LIKE ? OR m.description LIKE ? OR ai.keywords LIKE ?)";
            $types .= 'sss';
            array_push($params, $q, $q, $q);
        } else {
            $where[] = "(m.title LIKE ? OR m.description LIKE ?)";
            $types .= 'ss';
            array_push($params, $q, $q);
        }
    }
    if (isset($filters['uploader_staff_id']) && trim((string)$filters['uploader_staff_id']) !== '') {
        $where[] = "m.uploader_staff_id = ?";
        $types .= 's';
        $params[] = trim((string)$filters['uploader_staff_id']);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $order = !empty($filters['most_downloaded']) ? 'm.download_count DESC' : (!empty($filters['most_viewed']) ? 'm.view_count DESC' : 'm.created_at DESC');
    $limit = max(1, min(500, $limit));
    $aiSelect = $hasAiMetadata ? 'ai.summary, ai.keywords' : 'NULL AS summary, NULL AS keywords';
    $aiJoin = $hasAiMetadata ? 'LEFT JOIN repository_ai_metadata ai ON ai.material_id = m.id' : '';
    $staffSelect = $hasStaff ? "CONCAT(COALESCE(s.Fname,''),' ',COALESCE(s.Lname,'')) AS uploader_name" : "m.uploader_staff_id AS uploader_name";
    $staffJoin = $hasStaff ? 'LEFT JOIN staff s ON s.staff_id = m.uploader_staff_id COLLATE utf8mb4_unicode_ci' : '';
    $sql = "SELECT m.*, {$aiSelect}, {$staffSelect}
              FROM repository_materials m
              {$aiJoin}
              {$staffJoin}
              {$whereSql}
             ORDER BY {$order}, m.id DESC
             LIMIT {$limit}";
    try {
        $stmt = $db->prepare($sql);
    } catch (Throwable $e) {
        error_log('Repository list prepare exception: ' . $e->getMessage());
        return [];
    }
    if (!$stmt) {
        error_log('Repository list prepare failed: ' . $db->error);
        return [];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    try {
        $stmt->execute();
        $res = $stmt->get_result();
    } catch (Throwable $e) {
        error_log('Repository list execute exception: ' . $e->getMessage());
        $stmt->close();
        return [];
    }
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        if (repo_can_access_material($db, $row, 'list')) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

function repo_lookup_options(mysqli $db): array
{
    $options = ['departments' => [], 'programmes' => [], 'courses' => [], 'categories' => []];
    if (repo_table_exists($db, 'departments')) {
        $id = repo_detect_column($db, 'departments', ['id', 'department_id', 'deptId']);
        $name = repo_detect_column($db, 'departments', ['department_name', 'deptName', 'name']);
        if ($id && $name && ($res = @$db->query("SELECT `{$id}` AS id, `{$name}` AS name FROM departments ORDER BY `{$name}`"))) {
            while ($row = $res->fetch_assoc()) {
                $options['departments'][(string)$row['id']] = (string)$row['name'];
            }
            $res->free();
        }
    }
    if (repo_table_exists($db, 'programs')) {
        $code = repo_detect_column($db, 'programs', ['program_code', 'code']);
        $name = repo_detect_column($db, 'programs', ['program_name', 'name']);
        if ($code && $name && ($res = @$db->query("SELECT `{$code}` AS code, `{$name}` AS name FROM programs ORDER BY `{$name}`"))) {
            while ($row = $res->fetch_assoc()) {
                $options['programmes'][(string)$row['code']] = (string)$row['name'];
            }
            $res->free();
        }
    }
    if (repo_table_exists($db, 'courses')) {
        $code = repo_detect_column($db, 'courses', ['course_code', 'code']);
        $name = repo_detect_column($db, 'courses', ['course_name', 'name']);
        if ($code && $name && ($res = @$db->query("SELECT `{$code}` AS code, `{$name}` AS name FROM courses ORDER BY `{$code}`"))) {
            while ($row = $res->fetch_assoc()) {
                $options['courses'][(string)$row['code']] = trim((string)$row['code'] . ' - ' . (string)$row['name']);
            }
            $res->free();
        }
    }
    repo_ensure_schema($db);
    if (repo_table_exists($db, 'repository_categories') && ($res = $db->query("SELECT id, name FROM repository_categories WHERE is_active = 1 ORDER BY name"))) {
        while ($row = $res->fetch_assoc()) {
            $options['categories'][(int)$row['id']] = (string)$row['name'];
        }
        $res->free();
    }
    return $options;
}

function repo_allowed_mimes(): array
{
    $office = ['application/octet-stream', 'application/zip', 'application/vnd.ms-office', 'application/x-cfb', 'application/x-ole-storage'];
    return [
        'pdf' => ['application/pdf'],
        'doc' => array_merge(['application/msword'], $office),
        'docx' => array_merge(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $office),
        'ppt' => array_merge(['application/vnd.ms-powerpoint'], $office),
        'pptx' => array_merge(['application/vnd.openxmlformats-officedocument.presentationml.presentation'], $office),
        'xls' => array_merge(['application/vnd.ms-excel'], $office),
        'xlsx' => array_merge(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $office),
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'mp4' => ['video/mp4', 'application/octet-stream'],
        'webm' => ['video/webm', 'application/octet-stream'],
        'mov' => ['video/quicktime', 'application/octet-stream'],
        'm4v' => ['video/x-m4v', 'video/mp4', 'application/octet-stream'],
    ];
}

function repo_validate_upload(array $file, array &$errors): ?array
{
    $blocked = ['php', 'phtml', 'exe', 'bat', 'sh', 'js', 'html', 'htaccess', 'sql'];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please select a file or choose external link mode.';
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed. Please try again.';
        return null;
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 50 * 1024 * 1024) {
        $errors[] = 'Files must be between 1 byte and 50 MB.';
        return null;
    }
    $original = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = repo_allowed_mimes();
    if ($ext === '' || in_array($ext, $blocked, true) || !isset($allowed[$ext])) {
        $errors[] = 'This file type is not allowed.';
        return null;
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $errors[] = 'The uploaded file could not be verified.';
        return null;
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($mime !== '' && !in_array($mime, $allowed[$ext], true)) {
            $errors[] = 'The uploaded file content does not match its extension.';
            return null;
        }
    }
    return [
        'original' => $original,
        'tmp' => $tmp,
        'ext' => $ext,
        'mime' => $mime ?: 'application/octet-stream',
        'size' => $size,
        'checksum' => hash_file('sha256', $tmp) ?: null,
    ];
}

function repo_store_upload(array $upload, array &$errors): ?array
{
    $dir = dirname(__DIR__, 2) . '/uploads/repository';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        $errors[] = 'Unable to create repository upload folder.';
        return null;
    }
    $filename = 'repo_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $upload['ext'];
    $target = $dir . '/' . $filename;
    if (!@move_uploaded_file((string)$upload['tmp'], $target)) {
        $errors[] = 'Unable to save uploaded file.';
        return null;
    }
    return ['absolute' => $target, 'relative' => 'uploads/repository/' . $filename];
}

function repo_material_icon(array $material): string
{
    if (($material['upload_mode'] ?? '') === 'link') {
        return 'fas fa-link';
    }
    $ext = strtolower(pathinfo((string)($material['original_filename'] ?? $material['file_path'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'fas fa-file-pdf text-danger';
    if (in_array($ext, ['doc', 'docx'], true)) return 'fas fa-file-word text-primary';
    if (in_array($ext, ['ppt', 'pptx'], true)) return 'fas fa-file-powerpoint text-warning';
    if (in_array($ext, ['xls', 'xlsx', 'csv'], true)) return 'fas fa-file-excel text-success';
    if (in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) return 'fas fa-file-video text-info';
    return 'fas fa-file-alt text-secondary';
}

function repo_log_access(mysqli $db, int $materialId, string $action): void
{
    repo_ensure_schema($db);
    if (!repo_table_exists($db, 'repository_access_logs') || !repo_table_exists($db, 'repository_materials')) {
        return;
    }
    $role = repo_user_role();
    $userId = (string)($_SESSION['Sid'] ?? $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt = $db->prepare("INSERT INTO repository_access_logs (material_id, action, user_role, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('isssss', $materialId, $action, $role, $userId, $ip, $agent);
        $stmt->execute();
        $stmt->close();
    }
    $column = $action === 'download' ? 'download_count' : 'view_count';
    $db->query("UPDATE repository_materials SET {$column} = {$column} + 1 WHERE id = " . (int)$materialId);
}

function repo_ai_fallback(array $material): array
{
    $title = (string)$material['title'];
    $description = trim((string)($material['description'] ?? ''));
    $summary = $description !== '' ? $description : 'This approved repository material supports academic study for ' . $title . '.';
    return [
        'summary' => $summary,
        'keywords' => $title,
        'topics' => $title,
        'study_guide' => "Review the material carefully, list the main concepts, define important terms, and test yourself using the revision questions.",
        'questions' => [
            'What are the main concepts covered in ' . $title . '?',
            'How can you apply the ideas from this material in coursework or practical work?',
            'Which sections require further reading before an assessment?',
        ],
    ];
}

function repo_process_ai(mysqli $db, int $materialId): bool
{
    repo_ensure_schema($db);
    if (!repo_schema_ready($db)) {
        return false;
    }
    $material = repo_fetch_material($db, $materialId);
    if (!$material || !repo_material_is_active($material)) {
        return false;
    }

    $excerpt = trim((string)$material['description']);
    if (($material['upload_mode'] ?? '') === 'file' && !empty($material['file_path'])) {
        $path = dirname(__DIR__, 2) . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$material['file_path']);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (is_file($path) && in_array($ext, ['txt', 'csv'], true)) {
            $excerpt .= "\n" . substr((string)file_get_contents($path), 0, 12000);
        }
    }

    $context = [
        'title' => $material['title'],
        'description' => $material['description'],
        'material_type' => $material['material_type'],
        'course_code' => $material['course_code'],
        'programme_code' => $material['programme_code'],
        'excerpt' => wuc_ai_truncate($excerpt, 12000),
        'rules' => [
            'approved_material_only' => true,
            'academic_support_only' => true,
            'no_exam_leakage' => true,
        ],
    ];
    $contextJson = wuc_ai_context_json($context, 16000);
    $fallback = repo_ai_fallback($material);
    $result = wuc_ai_generate($db, [
        'feature' => 'repository_material_processing',
        'user_role' => 'system',
        'user_id' => 'repository',
        'input_summary' => substr((string)$material['title'], 0, 200),
        'context_hash' => hash('sha256', $contextJson),
        'messages' => [
            ['role' => 'system', 'content' => 'You are an academic repository assistant. Use only the approved material context. Return concise Markdown with sections: Summary, Keywords, Topics, Study Guide, Revision Questions. Refuse unrelated or restricted exam-leakage content.'],
            ['role' => 'user', 'content' => "Approved material context:\n{$contextJson}"],
        ],
        'fallback' => static function () use ($fallback): string {
            return "## Summary\n{$fallback['summary']}\n\n## Keywords\n{$fallback['keywords']}\n\n## Topics\n{$fallback['topics']}\n\n## Study Guide\n{$fallback['study_guide']}\n\n## Revision Questions\n- " . implode("\n- ", $fallback['questions']);
        },
    ]);
    $text = (string)$result['text'];
    $keywords = trim((string)$material['title'] . ', ' . str_replace('_', ' ', (string)$material['material_type']) . ', ' . (string)$material['course_code']);
    $status = !empty($result['used_ai']) ? 'processed' : 'fallback';
    $difficulty = in_array((string)$material['material_type'], ['past_papers', 'question_banks', 'assignments'], true)
        ? 'assessment-focused'
        : (((int)($material['year_of_study'] ?? 0) >= 3) ? 'advanced' : 'foundational');
    $stmt = $db->prepare("REPLACE INTO repository_ai_metadata (material_id, summary, keywords, topics, study_guide, difficulty_level, status, error_message, generated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if ($stmt) {
        $topics = $keywords;
        $error = !empty($result['error']) ? (string)$result['error'] : null;
        $stmt->bind_param('isssssss', $materialId, $text, $keywords, $topics, $text, $difficulty, $status, $error);
        $stmt->execute();
        $stmt->close();
    }
    $db->query("DELETE FROM repository_ai_questions WHERE material_id = " . (int)$materialId);
    $questions = $fallback['questions'];
    if (preg_match_all('/^\s*(?:[-*]|\d+[.)])\s+(.+\?)\s*$/m', $text, $matches) && !empty($matches[1])) {
        $questions = array_slice($matches[1], 0, 8);
    }
    $stmtQ = $db->prepare("INSERT INTO repository_ai_questions (material_id, question_text) VALUES (?, ?)");
    if ($stmtQ) {
        foreach ($questions as $question) {
            $q = trim((string)$question);
            if ($q !== '') {
                $stmtQ->bind_param('is', $materialId, $q);
                $stmtQ->execute();
            }
        }
        $stmtQ->close();
    }
    $stmtIdx = $db->prepare("REPLACE INTO repository_ai_search_index (material_id, keywords_text) VALUES (?, ?)");
    if ($stmtIdx) {
        $indexText = trim((string)$material['title'] . ' ' . (string)$material['description'] . ' ' . $keywords . ' ' . $text);
        $stmtIdx->bind_param('is', $materialId, $indexText);
        $stmtIdx->execute();
        $stmtIdx->close();
    }
    $stmtM = $db->prepare("UPDATE repository_materials SET ai_status = ?, ai_error = NULL WHERE id = ?");
    if ($stmtM) {
        $stmtM->bind_param('si', $status, $materialId);
        $stmtM->execute();
        $stmtM->close();
    }
    return true;
}

function repo_format_size($bytes): string
{
    $size = (int)$bytes;
    if ($size <= 0) return 'n/a';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return number_format($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

function repo_serve_material(mysqli $db, int $materialId, string $dispositionMode = 'auto'): void
{
    $material = repo_fetch_material($db, $materialId);
    if (!$material || !repo_can_access_material($db, $material, 'download')) {
        http_response_code(403);
        exit('You are not allowed to access this material.');
    }
    repo_log_access($db, $materialId, 'download');
    if (($material['upload_mode'] ?? '') === 'link') {
        $url = (string)($material['external_url'] ?? '');
        if ($url !== '' && wuc_validate_external_http_url($url)) {
            header('Location: ' . $url);
            exit;
        }
        http_response_code(400);
        exit('Invalid external link.');
    }
    $relative = ltrim(str_replace('\\', '/', (string)($material['file_path'] ?? '')), '/');
    if ($relative === '' || strpos($relative, 'uploads/repository/') !== 0) {
        http_response_code(403);
        exit('Invalid file path.');
    }
    $root = realpath(dirname(__DIR__, 2));
    $allowedRoot = realpath(dirname(__DIR__, 2) . '/uploads/repository');
    $absolute = realpath(($root ?: dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if (!$absolute || !$allowedRoot || strpos($absolute, $allowedRoot) !== 0 || !is_file($absolute)) {
        http_response_code(404);
        exit('Uploaded file is missing.');
    }
    $mime = (string)($material['mime_type'] ?: (mime_content_type($absolute) ?: 'application/octet-stream'));
    $inlineTypes = ['application/pdf', 'video/mp4', 'video/webm', 'image/png', 'image/jpeg', 'text/plain'];
    $disposition = $dispositionMode === 'download' ? 'attachment' : (in_array($mime, $inlineTypes, true) ? 'inline' : 'attachment');
    $name = basename((string)($material['original_filename'] ?: $absolute));
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($absolute));
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($absolute);
    exit;
}

<?php
$page_title = 'Course Materials';
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';
require_once dirname(__DIR__) . '/includes/elearning_files.php';
require_once dirname(__DIR__) . '/includes/elearning_ui.php';
// DB connection ($db) is already loaded by guard.php

if (function_exists('wuc_should_show_error_details') && wuc_should_show_error_details()) {
    ini_set('display_errors', '0');
}

function lecturerMaterialsH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function lecturerMaterialsUploadErrorMessage(int $code): string
{
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'The material is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The material is larger than the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'The material was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
    ];

    return $messages[$code] ?? 'File upload failed.';
}

function lecturerMaterialsAllowedMimeTypes(): array
{
    $legacyOffice = [
        'application/octet-stream',
        'application/vnd.ms-office',
        'application/x-cfb',
        'application/x-ole-storage',
    ];
    $openXml = [
        'application/zip',
        'application/octet-stream',
        'application/vnd.ms-office',
    ];

    return [
        'pdf' => ['application/pdf'],
        'doc' => array_merge(['application/msword'], $legacyOffice),
        'docx' => array_merge([
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ], $openXml),
        'ppt' => array_merge(['application/vnd.ms-powerpoint'], $legacyOffice),
        'pptx' => array_merge([
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ], $openXml),
        'xls' => array_merge(['application/vnd.ms-excel'], $legacyOffice),
        'xlsx' => array_merge([
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], $openXml),
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'mp4' => ['video/mp4', 'application/octet-stream'],
        'webm' => ['video/webm', 'application/octet-stream'],
        'mov' => ['video/quicktime', 'application/octet-stream'],
        'm4v' => ['video/x-m4v', 'video/mp4', 'application/octet-stream'],
    ];
}

function lecturerMaterialsSchemaStatus(mysqli $db): array
{
    $missing = [];
    $requiredTables = ['lesson_notes', 'el_course_modules', 'el_contents', 'el_content_versions'];
    foreach ($requiredTables as $table) {
        if (!elearningTableExists($db, $table)) {
            $missing[] = $table . ' table';
        }
    }

    if (elearningTableExists($db, 'lesson_notes')) {
        $columns = elearningTableColumns($db, 'lesson_notes');
        foreach (['id', 'course_code', 'topic', 'url', 'dte', 'notes'] as $column) {
            if (!isset($columns[$column])) {
                $missing[] = 'lesson_notes.' . $column;
            }
        }
        foreach (['el_content_id', 'el_version_id', 'file_size'] as $column) {
            if (!isset($columns[$column])) {
                $missing[] = 'lesson_notes.' . $column;
            }
        }
    }

    if (elearningTableExists($db, 'el_contents')) {
        $columns = elearningTableColumns($db, 'el_contents');
        foreach (['module_id', 'content_type', 'title', 'current_version_id', 'created_by'] as $column) {
            if (!isset($columns[$column])) {
                $missing[] = 'el_contents.' . $column;
            }
        }
    }

    if (elearningTableExists($db, 'el_content_versions')) {
        $columns = elearningTableColumns($db, 'el_content_versions');
        foreach (['content_id', 'version_no', 'file_path', 'file_size', 'checksum_sha256', 'created_by'] as $column) {
            if (!isset($columns[$column])) {
                $missing[] = 'el_content_versions.' . $column;
            }
        }
    }

    return [
        'ready' => $missing === [],
        'missing' => $missing,
    ];
}

function lecturerMaterialsLessonNotesReadable(mysqli $db): bool
{
    if (!elearningTableExists($db, 'lesson_notes')) {
        return false;
    }

    $columns = elearningTableColumns($db, 'lesson_notes');
    foreach (['id', 'course_code', 'topic', 'url', 'dte', 'notes'] as $column) {
        if (!isset($columns[$column])) {
            return false;
        }
    }

    return true;
}

function lecturerMaterialsValidateUpload(array $file, array &$errors): ?array
{
    $fileError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fileError === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please select a material file.';
        return null;
    }
    if ($fileError !== UPLOAD_ERR_OK) {
        $errors[] = lecturerMaterialsUploadErrorMessage($fileError);
        return null;
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        $errors[] = 'The selected file appears to be empty.';
        return null;
    }
    if ($size > 50 * 1024 * 1024) {
        $errors[] = 'Maximum file size is 50 MB.';
        return null;
    }

    $original = (string) ($file['name'] ?? '');
    $tmp = (string) ($file['tmp_name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = lecturerMaterialsAllowedMimeTypes();
    if (!isset($allowed[$ext])) {
        $errors[] = 'Invalid file format. Allowed: ' . implode(', ', array_keys($allowed)) . '.';
        return null;
    }

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $errors[] = 'The uploaded file could not be verified.';
        return null;
    }

    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($mime !== '' && !in_array($mime, $allowed[$ext], true)) {
            $errors[] = 'The file type does not match the selected file extension.';
            return null;
        }
    }

    return [
        'original' => $original,
        'tmp' => $tmp,
        'ext' => $ext,
        'size' => $size,
        'mime' => $mime ?: null,
    ];
}

function lecturerMaterialsStoreUpload(array $upload, string $courseCode, array &$errors): ?array
{
    $uploadDir = dirname(__DIR__) . '/uploads/elearning';
    $legacyDir = __DIR__ . '/uploads/materials';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
        $errors[] = 'Unable to create the eLearning upload folder.';
        return null;
    }
    if (!is_dir($legacyDir) && !@mkdir($legacyDir, 0755, true)) {
        $errors[] = 'Unable to create the legacy materials folder.';
        return null;
    }

    $filename = elearningSafeUploadName($courseCode, (string) $upload['ext']);
    $target = $uploadDir . '/' . $filename;
    $legacyTarget = $legacyDir . '/' . $filename;
    if (!@move_uploaded_file((string) $upload['tmp'], $target)) {
        $errors[] = 'Unable to save uploaded file.';
        return null;
    }

    if (!@copy($target, $legacyTarget)) {
        @unlink($target);
        $errors[] = 'Unable to save the legacy material copy.';
        return null;
    }

    return [
        'filename' => $filename,
        'absolute' => $target,
        'legacy_absolute' => $legacyTarget,
        'relative' => 'uploads/elearning/' . $filename,
    ];
}

function lecturerMaterialsTypeFromExtension(string $extension): string
{
    if (in_array($extension, ['mp4', 'webm', 'mov', 'm4v'], true)) {
        return 'video';
    }
    if ($extension === 'pdf') {
        return 'pdf';
    }
    return 'docx';
}

function lecturerMaterialsIconForExtension(string $extension): array
{
    if ($extension === 'pdf') {
        return ['fas fa-file-pdf', 'text-danger'];
    }
    if (in_array($extension, ['doc', 'docx'], true)) {
        return ['fas fa-file-word', 'text-primary'];
    }
    if (in_array($extension, ['ppt', 'pptx'], true)) {
        return ['fas fa-file-powerpoint', 'text-warning'];
    }
    if (in_array($extension, ['xls', 'xlsx'], true)) {
        return ['fas fa-file-excel', 'text-success'];
    }
    if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        return ['fas fa-file-image', 'text-info'];
    }
    if (in_array($extension, ['mp4', 'webm', 'mov', 'm4v'], true)) {
        return ['fas fa-file-video', 'text-info'];
    }
    return ['fas fa-file', 'text-secondary'];
}

function lecturerMaterialsGetOrCreateModule(mysqli $db, string $courseCode, string $staffId, ?int $courseOfferingId = null): int
{
    $title = 'Course Materials';
    $hasOfferingColumn = elearningTableHasCourseOffering($db, 'el_course_modules');

    if ($hasOfferingColumn && $courseOfferingId !== null) {
        if ($stmt = $db->prepare("SELECT id FROM el_course_modules WHERE course_offering_id = ? AND title = ? LIMIT 1")) {
            $stmt->bind_param('is', $courseOfferingId, $title);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return (int) $row['id'];
            }
            $stmt->close();
        }
    }

    if ($stmt = $db->prepare("SELECT id FROM el_course_modules WHERE course_code = ? AND title = ? LIMIT 1")) {
        $stmt->bind_param('ss', $courseCode, $title);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $moduleId = (int) $row['id'];
            $stmt->close();
            if ($hasOfferingColumn && $courseOfferingId !== null) {
                if ($update = $db->prepare("UPDATE el_course_modules SET course_offering_id = ? WHERE id = ? AND course_offering_id IS NULL")) {
                    $update->bind_param('ii', $courseOfferingId, $moduleId);
                    $update->execute();
                    $update->close();
                }
            }
            return $moduleId;
        }
        $stmt->close();
    }

    $description = 'General resources uploaded from the lecturer materials page.';
    $position = 0;
    if ($hasOfferingColumn) {
        $stmt = $db->prepare("INSERT INTO el_course_modules (course_offering_id, course_code, title, description, position, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    } else {
        $stmt = $db->prepare("INSERT INTO el_course_modules (course_code, title, description, position, created_by) VALUES (?, ?, ?, ?, ?)");
    }
    if (!$stmt) {
        throw new RuntimeException('Could not prepare module create query: ' . $db->error);
    }
    if ($hasOfferingColumn) {
        $stmt->bind_param('isssis', $courseOfferingId, $courseCode, $title, $description, $position, $staffId);
    } else {
        $stmt->bind_param('sssis', $courseCode, $title, $description, $position, $staffId);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not create course materials module: ' . $error);
    }
    $moduleId = (int) $stmt->insert_id;
    $stmt->close();
    return $moduleId;
}

function lecturerMaterialsCreateElearningContent(mysqli $db, int $moduleId, string $contentType, string $title, string $description, ?string $mime, string $filePath, int $fileSize, string $checksum, string $staffId): array
{
    $captions = null;
    $stmt = $db->prepare("INSERT INTO el_contents (module_id, content_type, title, description, mime_type, captions_url, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare content create query: ' . $db->error);
    }
    $stmt->bind_param('issssss', $moduleId, $contentType, $title, $description, $mime, $captions, $staffId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not create eLearning content: ' . $error);
    }
    $contentId = (int) $stmt->insert_id;
    $stmt->close();

    $versionNo = 1;
    $stmt = $db->prepare("INSERT INTO el_content_versions (content_id, version_no, file_path, file_size, checksum_sha256, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare content version query: ' . $db->error);
    }
    $stmt->bind_param('iisiss', $contentId, $versionNo, $filePath, $fileSize, $checksum, $staffId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not create eLearning content version: ' . $error);
    }
    $versionId = (int) $stmt->insert_id;
    $stmt->close();

    $stmt = $db->prepare("UPDATE el_contents SET current_version_id = ? WHERE id = ?");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare current version query: ' . $db->error);
    }
    $stmt->bind_param('ii', $versionId, $contentId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not update current eLearning version: ' . $error);
    }
    $stmt->close();

    return [$contentId, $versionId];
}

$staffId = (string) ($_SESSION['staff_id'] ?? '');
if (!$staffId) {
    header('Location: /wucportal/staff_login.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wuc_verify_csrf();
}

$schemaStatus = lecturerMaterialsSchemaStatus($db);
$lessonNotesReadable = lecturerMaterialsLessonNotesReadable($db);

// Accept ?code= (new), ?view= (legacy), and ?course= (from myCourses.php)
$courseCode = trim((string) ($_GET['code'] ?? ($_GET['view'] ?? ($_GET['course'] ?? ''))));

if ($courseCode !== '') {
    wuc_lecturer_enforce_course_assignment($db, $courseCode);
}

// Get all courses assigned to this lecturer
$assignedCodes = getLecturerAssignedCourses($db, $staffId);

// Build course info map (code => name) with fallback lookups
$courseMap = [];
foreach (getLecturerCourseDetails($db, $staffId) as $courseDetail) {
    $code = (string) ($courseDetail['course_code'] ?? '');
    if ($code !== '') {
        $courseMap[$code] = (string) ($courseDetail['course_name'] ?? $code);
    }
}
foreach ($assignedCodes as $code) {
    if (!isset($courseMap[$code])) {
        $courseMap[$code] = $code;
    }
}
if (false && !empty($assignedCodes)) {
    // Try courses table first
    $placeholders = implode(',', array_fill(0, count($assignedCodes), '?'));
    $types = str_repeat('s', count($assignedCodes));
    
    $stmt = $db->prepare("SELECT course_code, course_name FROM courses WHERE course_code IN ($placeholders)");
    $stmt->bind_param($types, ...$assignedCodes);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $courseMap[$row['course_code']] = $row['course_name'];
    }
    $stmt->close();

    // Try program_courses for any missing
    $missing = array_diff($assignedCodes, array_keys($courseMap));
    if (!empty($missing)) {
        $pcCheck = $db->query("SHOW TABLES LIKE 'program_courses'");
        if ($pcCheck && $pcCheck->num_rows > 0) {
            $pcCheck->free();
            $ph2 = implode(',', array_fill(0, count($missing), '?'));
            $t2 = str_repeat('s', count($missing));
            $missingArr = array_values($missing);
            $stmt = $db->prepare("SELECT course_code, course_name FROM program_courses WHERE course_code IN ($ph2) GROUP BY course_code");
            $stmt->bind_param($t2, ...$missingArr);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $courseMap[$row['course_code']] = $row['course_name'];
            }
            $stmt->close();
        } elseif ($pcCheck) {
            $pcCheck->free();
        }
    }

    // Final fallback — use course code as name
    foreach ($assignedCodes as $code) {
        if (!isset($courseMap[$code])) {
            $courseMap[$code] = $code;
        }
    }
}

// If a specific course is selected, validate access
$selectedCourse = null;
if ($courseCode !== '') {
    if (!isLecturerAssignedToCourse($db, $staffId, $courseCode)) {
        header('Location: materials.php');
        exit;
    }
    $selectedCourse = (object)[
        'course_code' => $courseCode,
        'course_name' => $courseMap[$courseCode] ?? $courseCode
    ];
}

$errors = [];
$old = [
    'course_code' => $selectedCourse ? (string) $selectedCourse->course_code : '',
    'topic' => '',
    'url' => '',
    'dte' => date('Y-m-d'),
];

if (isset($_POST['submit'])) {
    $old = [
        'course_code' => trim((string) ($_POST['course_code'] ?? '')),
        'topic' => trim((string) ($_POST['topic'] ?? '')),
        'url' => trim((string) ($_POST['url'] ?? '')),
        'dte' => trim((string) ($_POST['dte'] ?? date('Y-m-d'))),
    ];

    $postCourseCode = $old['course_code'];
    $topic = $old['topic'];
    $url = $old['url'];
    $dte = $old['dte'];

    if (!$schemaStatus['ready']) {
        $errors[] = 'Material storage is not ready. Please ask the administrator to run migrations/20260609_lecturer_materials_elearning_schema.sql.';
    }
    if ($postCourseCode === '') {
        $errors[] = 'Course is required.';
    } elseif (!isLecturerAssignedToCourse($db, $staffId, $postCourseCode)) {
        $errors[] = 'You are not assigned to this course.';
    }
    if ($topic === '') {
        $errors[] = 'Topic is required.';
    } elseif (mb_strlen($topic) > 255) {
        $errors[] = 'Topic must be 255 characters or fewer.';
    }
    if ($url !== '' && !elearningValidateExternalUrl($url)) {
        $errors[] = 'Related URL must start with http:// or https://.';
    }

    $dateValue = DateTimeImmutable::createFromFormat('Y-m-d', $dte);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$dateValue || ($dateErrors !== false && ((int) $dateErrors['warning_count'] > 0 || (int) $dateErrors['error_count'] > 0))) {
        $errors[] = 'Invalid material date.';
    } else {
        $dte = $dateValue->format('Y-m-d');
    }

    $pendingUpload = null;
    if (isset($_FILES['notes']) && is_array($_FILES['notes'])) {
        $pendingUpload = lecturerMaterialsValidateUpload($_FILES['notes'], $errors);
    } else {
        $errors[] = 'Please select a material file.';
    }

    $storedUpload = null;
    if (!$errors && $pendingUpload !== null) {
        $storedUpload = lecturerMaterialsStoreUpload($pendingUpload, $postCourseCode, $errors);
    }

    if (!$errors && $storedUpload !== null) {
        $db->begin_transaction();
        try {
            $courseOfferingId = getLecturerCourseOfferingId($db, $staffId, $postCourseCode);
            $moduleId = lecturerMaterialsGetOrCreateModule($db, $postCourseCode, $staffId, $courseOfferingId);
            $contentType = lecturerMaterialsTypeFromExtension((string) $pendingUpload['ext']);
            $description = $url !== '' ? 'Related URL: ' . $url : '';
            $checksum = hash_file('sha256', (string) $storedUpload['absolute']);
            if ($checksum === false) {
                throw new RuntimeException('Could not calculate uploaded file checksum.');
            }

            [$contentId, $versionId] = lecturerMaterialsCreateElearningContent(
                $db,
                $moduleId,
                $contentType,
                $topic,
                $description,
                $pendingUpload['mime'],
                (string) $storedUpload['relative'],
                (int) $pendingUpload['size'],
                $checksum,
                $staffId
            );

            // An absent optional video URL is stored as an empty value. Using a
            // display label here makes downstream resource views treat it as a
            // relative link (for example /lecturers/Not%20available).
            $notesUrl = $url;
            $filename = (string) $storedUpload['filename'];
            $fileSize = (int) $pendingUpload['size'];
            if (elearningTableHasCourseOffering($db, 'lesson_notes')) {
                $stmt = $db->prepare('INSERT INTO lesson_notes (course_offering_id, course_code, topic, url, dte, notes, el_content_id, el_version_id, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            } else {
                $stmt = $db->prepare('INSERT INTO lesson_notes (course_code, topic, url, dte, notes, el_content_id, el_version_id, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            }
            if (!$stmt) {
                throw new RuntimeException('Could not prepare material note create query: ' . $db->error);
            }
            if (elearningTableHasCourseOffering($db, 'lesson_notes')) {
                $stmt->bind_param('isssssiii', $courseOfferingId, $postCourseCode, $topic, $notesUrl, $dte, $filename, $contentId, $versionId, $fileSize);
            } else {
                $stmt->bind_param('sssssiii', $postCourseCode, $topic, $notesUrl, $dte, $filename, $contentId, $versionId, $fileSize);
            }
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Could not create lesson note: ' . $error);
            }
            $stmt->close();

            $db->commit();
            wuc_flash('success', 'Material uploaded to eLearning successfully.');
            wuc_safe_redirect('materials.php?code=' . urlencode($postCourseCode) . '&uploaded=1');
        } catch (Throwable $e) {
            $db->rollback();
            @unlink((string) $storedUpload['absolute']);
            @unlink((string) $storedUpload['legacy_absolute']);
            error_log('lecturers/materials.php: material upload failed: ' . $e->getMessage());
            $errors[] = 'Unable to save this material to eLearning. Please try again or contact support.';
        }
    }

    if ($postCourseCode !== '' && isLecturerAssignedToCourse($db, $staffId, $postCourseCode)) {
        $courseCode = $postCourseCode;
        $selectedCourse = (object) [
            'course_code' => $postCourseCode,
            'course_name' => $courseMap[$postCourseCode] ?? $postCourseCode,
        ];
    }
}

$flash = wuc_get_flash();
$success = ($flash && ($flash['type'] ?? '') === 'success') ? (string) ($flash['message'] ?? '') : '';

// Handle material deletion
if (isset($_POST['delete_material'])) {
    $deleteId = (int) ($_POST['material_id'] ?? 0);
    if (!$lessonNotesReadable) {
        $errors[] = 'Material storage is not available.';
    } elseif ($deleteId > 0) {
        $noteColumns = elearningTableColumns($db, 'lesson_notes');
        $contentExpr = isset($noteColumns['el_content_id']) ? 'el_content_id' : 'NULL AS el_content_id';
        $versionExpr = isset($noteColumns['el_version_id']) ? 'el_version_id' : 'NULL AS el_version_id';
        $stmt = $db->prepare("SELECT course_code, notes, {$contentExpr}, {$versionExpr} FROM lesson_notes WHERE id = ?");
        if (!$stmt) {
            $errors[] = 'Unable to prepare material delete query.';
            error_log('lecturers/materials.php: material delete select prepare failed: ' . $db->error);
        } else {
            $stmt->bind_param('i', $deleteId);
            $stmt->execute();
            $delResult = $stmt->get_result();
            if ($delResult->num_rows > 0) {
                $delRow = $delResult->fetch_assoc();
                if (isLecturerAssignedToCourse($db, $staffId, $delRow['course_code'])) {
                    $filePath = __DIR__ . '/uploads/materials/' . $delRow['notes'];
                    $elearningPath = dirname(__DIR__) . '/uploads/elearning/' . $delRow['notes'];
                    $stmt2 = $db->prepare('DELETE FROM lesson_notes WHERE id = ?');
                    if (!$stmt2) {
                        $errors[] = 'Unable to prepare material delete query.';
                        error_log('lecturers/materials.php: material delete prepare failed: ' . $db->error);
                    } else {
                        $stmt2->bind_param('i', $deleteId);
                        if ($stmt2->execute()) {
                            $contentId = (int) ($delRow['el_content_id'] ?? 0);
                            $versionId = (int) ($delRow['el_version_id'] ?? 0);
                            if ($contentId > 0) {
                                if ($stmt3 = $db->prepare('DELETE FROM el_content_versions WHERE content_id = ?')) {
                                    $stmt3->bind_param('i', $contentId);
                                    $stmt3->execute();
                                    $stmt3->close();
                                }
                                if ($stmt3 = $db->prepare('DELETE FROM el_contents WHERE id = ?')) {
                                    $stmt3->bind_param('i', $contentId);
                                    $stmt3->execute();
                                    $stmt3->close();
                                }
                            } elseif ($versionId > 0) {
                                if ($stmt3 = $db->prepare('DELETE FROM el_content_versions WHERE id = ?')) {
                                    $stmt3->bind_param('i', $versionId);
                                    $stmt3->execute();
                                    $stmt3->close();
                                }
                            }
                            if (file_exists($filePath)) {
                                @unlink($filePath);
                            }
                            if (file_exists($elearningPath)) {
                                @unlink($elearningPath);
                            }
                            $success = 'Material deleted successfully.';
                        }
                        $stmt2->close();
                    }
                }
            }
            $stmt->close();
        }
    }
}

// Fetch lesson notes
$lessonNotes = [];
$elearningContents = [];
$targetCodes = $selectedCourse ? [$selectedCourse->course_code] : $assignedCodes;
$targetOfferingIds = $selectedCourse
    ? getLecturerCourseOfferingIds($db, $staffId, (string) $selectedCourse->course_code)
    : getLecturerCourseOfferingIds($db, $staffId);
$lessonNotesHasOffering = elearningTableHasCourseOffering($db, 'lesson_notes');
if ($lessonNotesReadable && $selectedCourse) {
    // Fetch for specific course
    if ($lessonNotesHasOffering && !empty($targetOfferingIds)) {
        $offeringPlaceholders = implode(',', array_fill(0, count($targetOfferingIds), '?'));
        $types = 's' . str_repeat('i', count($targetOfferingIds));
        $params = array_merge([$courseCode], $targetOfferingIds);
        $stmt = $db->prepare("SELECT * FROM lesson_notes WHERE course_code = ? AND (course_offering_id IN ($offeringPlaceholders) OR course_offering_id IS NULL) ORDER BY id DESC");
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt = $db->prepare("SELECT * FROM lesson_notes WHERE course_code = ? ORDER BY id DESC");
        $stmt->bind_param("s", $courseCode);
    }
    $stmt->execute();
    $notesResult = $stmt->get_result();
    while ($row = $notesResult->fetch_object()) {
        $lessonNotes[] = $row;
    }
    $stmt->close();
} elseif ($lessonNotesReadable) {
    // Fetch all materials for all assigned courses
    if (!empty($assignedCodes)) {
        $placeholders = implode(',', array_fill(0, count($assignedCodes), '?'));
        $types = str_repeat('s', count($assignedCodes));
        $params = $assignedCodes;
        $offeringSql = '';
        if ($lessonNotesHasOffering && !empty($targetOfferingIds)) {
            $offeringPlaceholders = implode(',', array_fill(0, count($targetOfferingIds), '?'));
            $offeringSql = " AND (course_offering_id IN ($offeringPlaceholders) OR course_offering_id IS NULL)";
            $types .= str_repeat('i', count($targetOfferingIds));
            $params = array_merge($params, $targetOfferingIds);
        }
        $stmt = $db->prepare("SELECT * FROM lesson_notes WHERE course_code IN ($placeholders){$offeringSql} ORDER BY id DESC");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $notesResult = $stmt->get_result();
        while ($row = $notesResult->fetch_object()) {
            $lessonNotes[] = $row;
        }
        $stmt->close();
    }
}

$lessonNotesElContentLinked = false;
if ($lessonNotesReadable) {
    $noteColumns = elearningTableColumns($db, 'lesson_notes');
    $lessonNotesElContentLinked = isset($noteColumns['el_content_id']);
}

if (!empty($targetCodes)
    && elearningTableExists($db, 'el_contents')
    && elearningTableExists($db, 'el_course_modules')
    && elearningTableExists($db, 'el_content_versions')
) {
    $lessonNotesJoinSql = $lessonNotesElContentLinked ? 'LEFT JOIN lesson_notes ln ON ln.el_content_id = c.id' : '';
    $lessonNotesFilterSql = $lessonNotesElContentLinked ? 'AND ln.id IS NULL' : '';
    $placeholders = implode(',', array_fill(0, count($targetCodes), '?'));
    $types = str_repeat('s', count($targetCodes));
    $params = $targetCodes;
    $moduleOfferingSql = '';
    if (elearningTableHasCourseOffering($db, 'el_course_modules') && !empty($targetOfferingIds)) {
        $offeringPlaceholders = implode(',', array_fill(0, count($targetOfferingIds), '?'));
        $moduleOfferingSql = " AND (m.course_offering_id IN ($offeringPlaceholders) OR m.course_offering_id IS NULL)";
        $types .= str_repeat('i', count($targetOfferingIds));
        $params = array_merge($params, $targetOfferingIds);
    }
    $sql = "
        SELECT c.id AS content_id,
               c.title,
               c.content_type,
               c.created_at,
               c.updated_at,
               m.course_code,
               m.title AS module_title,
               v.id AS version_id,
               v.file_path,
               v.file_size
        FROM el_contents c
        INNER JOIN el_course_modules m ON m.id = c.module_id
        LEFT JOIN el_content_versions v ON v.id = c.current_version_id
        {$lessonNotesJoinSql}
        WHERE m.course_code IN ($placeholders)
          {$moduleOfferingSql}
          {$lessonNotesFilterSql}
        ORDER BY c.updated_at DESC, c.id DESC
    ";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $contentsResult = $stmt->get_result();
        while ($row = $contentsResult->fetch_object()) {
            $elearningContents[] = $row;
        }
        $stmt->close();
    }
}

$totalMaterialCount = count($lessonNotes) + count($elearningContents);

require_once __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page materials-page">
    <!-- Dashboard Header -->
    <div class="dashboard-header lecturer-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">
                    <i class="fas fa-book-open me-2"></i>eLearning Materials
                </h1>
                <p class="text-muted">
                    <a href="/wucportal/elearning/courses.php" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>eLearning</a>
                    <?php if ($selectedCourse): ?>
                        <span class="mx-2">&rsaquo;</span>
                        <strong><?php echo htmlspecialchars($selectedCourse->course_code); ?></strong>
                        - <?php echo htmlspecialchars($selectedCourse->course_name); ?>
                    <?php else: ?>
                        <span class="mx-2">&rsaquo;</span> All Materials
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-auto">
                <?php if ($selectedCourse): ?>
                    <a href="materials.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-list me-1"></i>All Materials
                    </a>
                    <a href="/wucportal/elearning/manage.php?course_code=<?php echo urlencode($selectedCourse->course_code); ?>" class="btn btn-outline-primary">
                        <i class="fas fa-layer-group me-1"></i>eLearning Course
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <div class="fw-semibold mb-1"><i class="fas fa-exclamation-circle me-2"></i>Check the material details</div>
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo lecturerMaterialsH($error); ?></div>
            <?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="status">
            <i class="fas fa-check-circle me-2"></i><?php echo lecturerMaterialsH($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$schemaStatus['ready']): ?>
        <div class="alert alert-warning" role="alert">
            <div class="fw-semibold mb-1"><i class="fas fa-database me-2"></i>Material uploads need a schema update</div>
            Existing notes can still be viewed where the legacy table is available. New uploads are disabled until
            <code>migrations/20260609_lecturer_materials_elearning_schema.sql</code> is applied.
        </div>
    <?php endif; ?>

    <div class="assignment-command-bar mb-4" role="navigation" aria-label="Lecturer workflow navigation">
        <a class="command-link" href="assessments.php"><i class="fas fa-file-alt"></i><span>Submissions</span></a>
        <a class="command-link" href="upload_ca.php"><i class="fas fa-upload"></i><span>Upload CA</span></a>
        <a class="command-link" href="post_assign.php"><i class="fas fa-tasks"></i><span>Assignments</span></a>
        <a class="command-link active" href="materials.php" aria-current="page"><i class="fas fa-book-open"></i><span>Materials</span></a>
        <a class="command-link" href="viewCaRes.php"><i class="fas fa-eye"></i><span>CA Results</span></a>
    </div>

    <?php if ($selectedCourse): ?>
        <?php elearningCourseTabs($selectedCourse->course_code, 'materials'); ?>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="data-table-card h-100"><div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary rounded-circle p-3 me-3">
                        <i class="fas fa-file-alt fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo $totalMaterialCount; ?></h3>
                        <p class="stat-label mb-0">Total Materials</p>
                    </div>
                </div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="data-table-card h-100"><div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success rounded-circle p-3 me-3">
                        <i class="fas fa-book fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo $selectedCourse ? 1 : count($assignedCodes); ?></h3>
                        <p class="stat-label mb-0"><?php echo $selectedCourse ? 'Selected Course' : 'Total Courses'; ?></p>
                    </div>
                </div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="data-table-card h-100"><div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info rounded-circle p-3 me-3">
                        <i class="fas fa-calendar fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo count($elearningContents); ?></h3>
                        <p class="stat-label mb-0">Module Files</p>
                    </div>
                </div>
            </div></div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column: Upload Form -->
        <div class="col-lg-5 mb-4">
            <div class="card shadow">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-upload me-2"></i>Upload New Material</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($assignedCodes)): ?>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            No courses assigned. Contact your department to get course assignments.
                        </div>
                    <?php else: ?>
                    <form action="materials.php<?php echo $selectedCourse ? '?code=' . urlencode($selectedCourse->course_code) : ''; ?>" 
                          method="post" enctype="multipart/form-data" id="uploadForm">
                        <input type="hidden" name="csrf_token" value="<?php echo lecturerMaterialsH($_SESSION['csrf_token'] ?? ''); ?>">
                        <div class="mb-3">
                            <label for="course_code" class="form-label">Course <span class="text-danger">*</span></label>
                            <?php if ($selectedCourse): ?>
                                <input type="text" class="form-control" id="course_code" name="course_code" 
                                       value="<?php echo lecturerMaterialsH($selectedCourse->course_code); ?>" readonly>
                                <small class="text-muted"><?php echo lecturerMaterialsH($selectedCourse->course_name); ?></small>
                            <?php else: ?>
                                <select class="form-select" id="course_code" name="course_code" required>
                                    <option value="" <?php echo $old['course_code'] === '' ? 'selected' : ''; ?>>-- Select Course --</option>
                                    <?php foreach ($courseMap as $code => $name): ?>
                                        <option value="<?php echo lecturerMaterialsH($code); ?>" <?php echo strcasecmp($old['course_code'], (string) $code) === 0 ? 'selected' : ''; ?>>
                                            <?php echo lecturerMaterialsH($code); ?> - <?php echo lecturerMaterialsH($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="topic" class="form-label">Topic <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="topic" name="topic"
                                   maxlength="255"
                                   value="<?php echo lecturerMaterialsH($old['topic']); ?>"
                                   placeholder="Enter material title or lesson topic" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="dte" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="dte" name="dte" 
                                       value="<?php echo lecturerMaterialsH($old['dte']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="url" class="form-label">Related URL</label>
                                <input type="text" class="form-control" id="url" name="url" 
                                       value="<?php echo lecturerMaterialsH($old['url']); ?>"
                                       placeholder="https://...">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="notes" class="form-label">Upload File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="notes" name="notes" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.mp4,.webm,.mov,.m4v" required>
                            <small class="text-muted">PDF, Word, PowerPoint, Excel, images, or video files up to 50 MB.</small>
                        </div>
                        <div class="d-grid">
                            <button class="btn btn-primary" type="submit" name="submit" <?php echo !$schemaStatus['ready'] ? 'disabled' : ''; ?>>
                                <i class="fas fa-upload me-2"></i>Upload to eLearning
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Course Filter (only when viewing all) -->
            <?php if (!$selectedCourse && !empty($assignedCodes)): ?>
            <div class="card shadow mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter by Course</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <a href="materials.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-globe me-2"></i>All Courses
                            <span class="badge bg-primary rounded-pill float-end"><?php echo count($lessonNotes); ?></span>
                        </a>
                        <?php foreach ($courseMap as $code => $name): 
                            $count = 0;
                            foreach ($lessonNotes as $n) {
                                if ($n->course_code === $code) $count++;
                            }
                        ?>
                            <a href="materials.php?code=<?php echo urlencode($code); ?>" 
                               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span>
                                    <strong><?php echo htmlspecialchars($code); ?></strong>
                                    <small class="d-block text-muted"><?php echo htmlspecialchars($name); ?></small>
                                </span>
                                <span class="badge bg-secondary rounded-pill"><?php echo $count; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Materials List -->
        <div class="col-lg-7 mb-4">
            <?php if (!empty($elearningContents)): ?>
            <div class="data-table-card mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-layer-group me-2"></i>Existing eLearning Module Files</h5>
                        <span class="badge bg-info rounded-pill">
                            <?php echo count($elearningContents) . ' ' . (count($elearningContents) === 1 ? 'File' : 'Files'); ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <?php if (!$selectedCourse): ?><th width="14%">Course</th><?php endif; ?>
                                    <th>Title</th>
                                    <th width="18%">Module</th>
                                    <th width="14%">Type</th>
                                    <th width="16%">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($elearningContents as $content):
                                    $downloadUrl = !empty($content->version_id)
                                        ? '/wucportal/elearning/download.php?version_id=' . urlencode((string) $content->version_id)
                                        : '/wucportal/elearning/download.php?content_id=' . urlencode((string) $content->content_id);
                                ?>
                                <tr>
                                    <?php if (!$selectedCourse): ?>
                                    <td>
                                        <a href="materials.php?code=<?php echo urlencode($content->course_code); ?>" class="text-decoration-none fw-bold">
                                            <?php echo htmlspecialchars($content->course_code); ?>
                                        </a>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <strong><?php echo htmlspecialchars($content->title ?: 'Course Material'); ?></strong>
                                        <small class="d-block text-muted">
                                            Updated <?php echo htmlspecialchars((string) ($content->updated_at ?: $content->created_at)); ?>
                                        </small>
                                    </td>
                                    <td><small><?php echo htmlspecialchars((string) $content->module_title); ?></small></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string) $content->content_type); ?></span></td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-download me-1"></i>Open
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-file-alt me-2"></i>
                            <?php echo $selectedCourse 
                                ? 'Materials for ' . htmlspecialchars($selectedCourse->course_code) 
                                : 'All Uploaded Materials'; ?>
                        </h5>
                        <span class="badge bg-primary rounded-pill">
                            <?php echo count($lessonNotes) . ' ' . (count($lessonNotes) === 1 ? 'File' : 'Files'); ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($lessonNotes)): ?>
                    <div class="table-responsive">
                        <table id="materialsTable" class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="5%">#</th>
                                    <?php if (!$selectedCourse): ?><th width="12%">Course</th><?php endif; ?>
                                    <th>Topic</th>
                                    <th width="12%">Date</th>
                                    <th width="10%">Video</th>
                                    <th width="20%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lessonNotes as $index => $note): 
                                    $downloadUrl = '';
                                    if (!empty($note->el_version_id)) {
                                        $downloadUrl = '/wucportal/elearning/download.php?version_id=' . urlencode((string) $note->el_version_id);
                                    } elseif (!empty($note->el_content_id)) {
                                        $downloadUrl = '/wucportal/elearning/download.php?content_id=' . urlencode((string) $note->el_content_id);
                                    } else {
                                        $downloadUrl = 'uploads/materials/' . rawurlencode((string) $note->notes);
                                    }
                                    $fileExists = !empty($note->el_version_id)
                                        || !empty($note->el_content_id)
                                        || file_exists(__DIR__ . '/uploads/materials/' . $note->notes);
                                    $ext = strtolower(pathinfo($note->notes, PATHINFO_EXTENSION));
                                    [$iconClass, $iconColor] = lecturerMaterialsIconForExtension($ext);
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <?php if (!$selectedCourse): ?>
                                    <td>
                                        <a href="materials.php?code=<?php echo urlencode($note->course_code); ?>" 
                                           class="text-decoration-none fw-bold">
                                            <?php echo htmlspecialchars($note->course_code); ?>
                                        </a>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <i class="<?php echo $iconClass; ?> <?php echo $iconColor; ?> me-2"></i>
                                        <?php echo htmlspecialchars($note->topic); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($note->notes); ?></small>
                                    </td>
                                    <td><small><?php echo htmlspecialchars($note->dte); ?></small></td>
                                    <td>
                                        <?php if (!empty($note->url) && $note->url !== 'Not available'): ?>
                                        <a href="<?php echo htmlspecialchars($note->url); ?>" target="_blank" 
                                           class="btn btn-sm btn-outline-primary" title="Watch Video">
                                            <i class="fas fa-play-circle"></i>
                                        </a>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($fileExists): ?>
                                            <a href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" 
                                               class="btn btn-outline-success" title="Download">
                                                <i class="fas fa-download me-1"></i>Download
                                            </a>
                                            <?php else: ?>
                                            <button class="btn btn-outline-secondary" disabled title="File not found">
                                                <i class="fas fa-exclamation-triangle me-1"></i>Missing
                                            </button>
                                            <?php endif; ?>
                                            <form method="post" class="d-inline" 
                                                  onsubmit="return confirm('Are you sure you want to delete this material?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="material_id" value="<?php echo $note->id; ?>">
                                                <button type="submit" name="delete_material" class="btn btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-cloud-upload-alt fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No materials uploaded yet</h5>
                        <p class="text-muted">
                            <?php if ($selectedCourse): ?>
                                Use the form to upload lesson materials for 
                                <strong><?php echo htmlspecialchars($selectedCourse->course_code); ?></strong>.
                            <?php else: ?>
                                Select a course and upload your first lesson material.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const $table = $('#materialsTable');
    if ($table.length > 0 && $table.find('tbody tr').length > 0) {
        $table.DataTable({
            responsive: true,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search materials...",
                zeroRecords: "No matching materials found",
                info: "Showing _START_ to _END_ of _TOTAL_ materials",
                lengthMenu: "Show _MENU_ per page"
            },
            dom: '<"top"lf>rt<"bottom"ip><"clear">',
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            pageLength: 10,
            order: [[<?php echo $selectedCourse ? '0' : '1'; ?>, 'asc']],
            columnDefs: [
                {orderable: false, targets: [-1]},
                {searchable: false, targets: [0, -1, -2]}
            ]
        });
    }

    // Initialize tooltips
    $('[title]').tooltip();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>



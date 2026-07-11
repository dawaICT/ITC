<?php
$page_title = 'View Course';
// Production-safe error handling: log, don't print raw errors to users.
// (The old code force-enabled display_errors, leaking warnings/stack traces on
// a live page.) Details are shown only when the portal explicitly allows it.
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';
if (function_exists('wuc_should_show_error_details') && wuc_should_show_error_details()) {
    ini_set('display_errors', '0');
}
// NOTE: nav.php (which emits the full page <head> + sidebar) is included LATER,
// only after all redirects and the upload handler have run, so header()
// redirects and the post/redirect/get flow work without "headers already sent".

$staffId = $_SESSION['staff_id'] ?? null;
// Accept ?code=, ?view= (legacy), and ?course= (consistency)
$courseCode = trim($_GET['code'] ?? ($_GET['view'] ?? ($_GET['course'] ?? '')));

if ($courseCode !== '') {
    wuc_lecturer_enforce_course_assignment($db, $courseCode);
}

function lecturerViewCourseLegacyOutlineReady(mysqli $db): bool
{
    if (!elearningTableExists($db, 'course_contents')) {
        return false;
    }

    $columns = elearningTableColumns($db, 'course_contents');
    return isset($columns['course_code'], $columns['course_contents']);
}

function lecturerViewCourseModernOutlineReady(mysqli $db): bool
{
    foreach (['el_course_modules', 'el_contents', 'el_content_versions'] as $table) {
        if (!elearningTableExists($db, $table)) {
            return false;
        }
    }

    $moduleColumns = elearningTableColumns($db, 'el_course_modules');
    foreach (['id', 'course_code', 'title', 'description', 'position', 'created_by'] as $column) {
        if (!isset($moduleColumns[$column])) {
            return false;
        }
    }

    $contentColumns = elearningTableColumns($db, 'el_contents');
    foreach (['id', 'module_id', 'content_type', 'title', 'description', 'mime_type', 'current_version_id', 'created_by'] as $column) {
        if (!isset($contentColumns[$column])) {
            return false;
        }
    }

    $versionColumns = elearningTableColumns($db, 'el_content_versions');
    foreach (['id', 'content_id', 'version_no', 'file_path', 'file_size', 'checksum_sha256', 'created_by'] as $column) {
        if (!isset($versionColumns[$column])) {
            return false;
        }
    }

    return true;
}

function lecturerViewCourseGetOrCreateOutlineModule(mysqli $db, string $courseCode, string $staffId, ?int $courseOfferingId = null): int
{
    $title = 'Course Outline';
    $hasOfferingColumn = elearningTableHasCourseOffering($db, 'el_course_modules');

    if ($hasOfferingColumn && $courseOfferingId !== null) {
        if ($stmt = $db->prepare('SELECT id FROM el_course_modules WHERE course_offering_id = ? AND title = ? LIMIT 1')) {
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

    if ($stmt = $db->prepare('SELECT id FROM el_course_modules WHERE course_code = ? AND title = ? LIMIT 1')) {
        $stmt->bind_param('ss', $courseCode, $title);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $moduleId = (int) $row['id'];
            $stmt->close();
            if ($hasOfferingColumn && $courseOfferingId !== null) {
                if ($update = $db->prepare('UPDATE el_course_modules SET course_offering_id = ? WHERE id = ? AND course_offering_id IS NULL')) {
                    $update->bind_param('ii', $courseOfferingId, $moduleId);
                    $update->execute();
                    $update->close();
                }
            }
            return $moduleId;
        }
        $stmt->close();
    }

    $description = 'Course outline files uploaded from the lecturer course view.';
    $position = 0;
    if ($hasOfferingColumn) {
        $stmt = $db->prepare('INSERT INTO el_course_modules (course_offering_id, course_code, title, description, position, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    } else {
        $stmt = $db->prepare('INSERT INTO el_course_modules (course_code, title, description, position, created_by) VALUES (?, ?, ?, ?, ?)');
    }
    if (!$stmt) {
        throw new RuntimeException('Could not prepare course outline module query: ' . $db->error);
    }
    if ($hasOfferingColumn) {
        $stmt->bind_param('isssis', $courseOfferingId, $courseCode, $title, $description, $position, $staffId);
    } else {
        $stmt->bind_param('sssis', $courseCode, $title, $description, $position, $staffId);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not create course outline module: ' . $error);
    }
    $moduleId = (int) $stmt->insert_id;
    $stmt->close();

    return $moduleId;
}

function lecturerViewCourseContentTypeFromExtension(string $extension): string
{
    if ($extension === 'pdf') {
        return 'pdf';
    }
    if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        return 'image';
    }
    return 'docx';
}

/**
 * Human-readable message for every PHP file-upload error code. The old handler
 * only reacted to UPLOAD_ERR_OK, so an oversized/partial/blocked upload produced
 * NO feedback at all — the lecturer clicked Upload and nothing happened.
 */
function lecturerViewCourseUploadErrorMessage(int $code): string
{
    $messages = [
        UPLOAD_ERR_INI_SIZE   => 'The file is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'The file is larger than the form upload limit.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION  => 'A server extension blocked the upload.',
    ];

    return $messages[$code] ?? 'File upload failed. Please try again.';
}

/**
 * Real-content check: confirm the detected MIME type is consistent with the
 * file extension. Tolerant of the generic types finfo reports for OOXML/legacy
 * Office documents (zip / ole-storage / octet-stream) to avoid false rejects.
 * Returns true when finfo is unavailable so it never blocks a valid upload on
 * a server without the extension.
 */
function lecturerViewCourseMimeMatchesExt(string $ext, ?string $mime): bool
{
    if ($mime === null || $mime === '') {
        return true;
    }
    $legacyOffice = ['application/octet-stream', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/x-cfb'];
    $ooxml = ['application/zip', 'application/octet-stream', 'application/vnd.ms-office'];
    $map = [
        'pdf'  => ['application/pdf'],
        'doc'  => array_merge(['application/msword'], $legacyOffice),
        'docx' => array_merge(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $ooxml),
        'ppt'  => array_merge(['application/vnd.ms-powerpoint'], $legacyOffice),
        'pptx' => array_merge(['application/vnd.openxmlformats-officedocument.presentationml.presentation'], $ooxml),
        'xls'  => array_merge(['application/vnd.ms-excel'], $legacyOffice),
        'xlsx' => array_merge(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $ooxml),
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
    ];
    if (!isset($map[$ext])) {
        return true;
    }
    return in_array($mime, $map[$ext], true);
}

function lecturerViewCourseCreateOutlineContent(mysqli $db, int $moduleId, string $contentType, string $title, ?string $mime, string $filePath, int $fileSize, string $checksum, string $staffId): void
{
    $description = 'Course outline document.';
    $stmt = $db->prepare('INSERT INTO el_contents (module_id, content_type, title, description, mime_type, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare course outline content query: ' . $db->error);
    }
    $stmt->bind_param('isssss', $moduleId, $contentType, $title, $description, $mime, $staffId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not create course outline content: ' . $error);
    }
    $contentId = (int) $stmt->insert_id;
    $stmt->close();

    $versionNo = 1;
    $stmt = $db->prepare('INSERT INTO el_content_versions (content_id, version_no, file_path, file_size, checksum_sha256, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare course outline version query: ' . $db->error);
    }
    $stmt->bind_param('iisiss', $contentId, $versionNo, $filePath, $fileSize, $checksum, $staffId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not create course outline version: ' . $error);
    }
    $versionId = (int) $stmt->insert_id;
    $stmt->close();

    $stmt = $db->prepare('UPDATE el_contents SET current_version_id = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare course outline current version query: ' . $db->error);
    }
    $stmt->bind_param('ii', $versionId, $contentId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not update course outline current version: ' . $error);
    }
    $stmt->close();
}

function lecturerViewCourseFetchOutlines(mysqli $db, string $courseCode, array $courseOfferingIds = []): array
{
    $outlines = [];
    if (lecturerViewCourseLegacyOutlineReady($db)) {
        $stmt = $db->prepare('SELECT id, course_code, course_contents, uploaded_at FROM course_contents WHERE course_code = ? ORDER BY id DESC');
        if (!$stmt) {
            return $outlines;
        }
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $outlineRes = $stmt->get_result();
        while ($row = $outlineRes->fetch_object()) {
            $row->title = 'Course Outline Document';
            $row->download_url = 'uploads/materials/' . rawurlencode((string) $row->course_contents);
            $outlines[] = $row;
        }
        $stmt->close();
        return $outlines;
    }

    if (!lecturerViewCourseModernOutlineReady($db)) {
        return $outlines;
    }

    $offeringSql = '';
    $types = 's';
    $params = [$courseCode];
    if (elearningTableHasCourseOffering($db, 'el_course_modules') && !empty($courseOfferingIds)) {
        $offeringPlaceholders = implode(',', array_fill(0, count($courseOfferingIds), '?'));
        $offeringSql = " AND (m.course_offering_id IN ($offeringPlaceholders) OR m.course_offering_id IS NULL)";
        $types .= str_repeat('i', count($courseOfferingIds));
        $params = array_merge($params, $courseOfferingIds);
    }

    $stmt = $db->prepare("
        SELECT c.id AS content_id,
               c.title,
               c.created_at AS uploaded_at,
               v.id AS version_id
        FROM el_contents c
        INNER JOIN el_course_modules m ON m.id = c.module_id
        LEFT JOIN el_content_versions v ON v.id = c.current_version_id
        WHERE m.course_code = ? AND m.title = 'Course Outline'
          {$offeringSql}
        ORDER BY c.created_at DESC, c.id DESC
    ");
    if (!$stmt) {
        return $outlines;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $outlineRes = $stmt->get_result();
    while ($row = $outlineRes->fetch_object()) {
        $downloadParam = !empty($row->version_id)
            ? 'version_id=' . urlencode((string) $row->version_id)
            : 'content_id=' . urlencode((string) $row->content_id);
        $row->download_url = '/wucportal/elearning/download.php?' . $downloadParam;
        $outlines[] = $row;
    }
    $stmt->close();

    return $outlines;
}

// CSRF verification for any POST (the modal upload form now sends a token).
// Previously the handler had NO CSRF check at all — a cross-site form could
// push files into a lecturer's course.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wuc_verify_csrf();
}

// Upload feedback is collected here and rendered inside the page (after nav),
// so we no longer echo alerts before the document head is even open.
$uploadErrors = [];

if (!$staffId || $courseCode === '') {
    require_once __DIR__ . '/includes/nav.php';
    echo '<div class="container-fluid px-4 portal-dashboard"><div class="alert alert-danger m-4">
            <h4 class="alert-heading"><i class="fas fa-exclamation-circle me-2"></i>Course Code Required</h4>
            <p>No course code was provided. Please return to the dashboard and select a course.</p>
            <hr>
            <a href="myCourses.php" class="btn btn-primary"><i class="fas fa-arrow-left me-2"></i>Back to My Courses</a>
          </div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Verify lecturer is assigned to this course. Safe to redirect — nav (and thus
// any output) has not been emitted yet.
if (!isLecturerAssignedToCourse($db, $staffId, $courseCode)) {
    wuc_safe_redirect('myCourses.php');
}

// Handle course outline upload
if (isset($_POST['submit'])) {
    $postCourseCode = trim($_POST['course_code'] ?? '');
    $file = $_FILES['course_contents'] ?? null;
    $fileError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

    $allowedExts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
    $maxBytes = 10 * 1024 * 1024; // 10 MB

    if ($postCourseCode === '' || !isLecturerAssignedToCourse($db, $staffId, $postCourseCode)) {
        $uploadErrors[] = 'You are not assigned to this course.';
    } elseif ($fileError === UPLOAD_ERR_NO_FILE) {
        $uploadErrors[] = 'Please select a file to upload.';
    } elseif ($fileError !== UPLOAD_ERR_OK) {
        $uploadErrors[] = lecturerViewCourseUploadErrorMessage($fileError);
    } elseif (!lecturerViewCourseLegacyOutlineReady($db) && !lecturerViewCourseModernOutlineReady($db)) {
        $uploadErrors[] = 'Course outline storage is not configured. Please contact the system administrator.';
    } else {
        $fileName = basename((string) $file['name']);
        $tmpFile = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExts, true)) {
            $uploadErrors[] = 'Invalid file format. Allowed: ' . implode(', ', $allowedExts) . '.';
        } elseif ($size <= 0) {
            $uploadErrors[] = 'The selected file appears to be empty.';
        } elseif ($size > $maxBytes) {
            $uploadErrors[] = 'Maximum file size is 10 MB.';
        } elseif ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
            $uploadErrors[] = 'The uploaded file could not be verified.';
        } else {
            $mime = null;
            if (function_exists('finfo_open') && ($finfo = finfo_open(FILEINFO_MIME_TYPE))) {
                $mime = (string) finfo_file($finfo, $tmpFile);
                finfo_close($finfo);
            }
            if (!lecturerViewCourseMimeMatchesExt($ext, $mime)) {
                $uploadErrors[] = 'The file content does not match its extension.';
            } else {
                $uploadDir = __DIR__ . '/uploads/materials/';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
                $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                $destPath = $uploadDir . $safeName;

                if (!@move_uploaded_file($tmpFile, $destPath)) {
                    $uploadErrors[] = 'Could not save the uploaded file. Please try again.';
                } else {
                    $uploaded = false;
                    if (lecturerViewCourseLegacyOutlineReady($db)) {
                        if ($stmt = $db->prepare("INSERT INTO course_contents (course_code, course_contents) VALUES (?, ?)")) {
                            $stmt->bind_param("ss", $postCourseCode, $safeName);
                            $uploaded = $stmt->execute();
                            $stmt->close();
                        }
                    } else {
                        $elearningDir = dirname(__DIR__) . '/uploads/elearning/';
                        if (!is_dir($elearningDir)) { @mkdir($elearningDir, 0775, true); }
                        $elearningPath = $elearningDir . $safeName;
                        if (@copy($destPath, $elearningPath)) {
                            $db->begin_transaction();
                            try {
                                $courseOfferingId = getLecturerCourseOfferingId($db, (string) $staffId, $postCourseCode);
                                $moduleId = lecturerViewCourseGetOrCreateOutlineModule($db, $postCourseCode, (string) $staffId, $courseOfferingId);
                                $checksum = hash_file('sha256', $elearningPath);
                                if ($checksum === false) {
                                    throw new RuntimeException('Could not calculate uploaded file checksum.');
                                }
                                lecturerViewCourseCreateOutlineContent(
                                    $db,
                                    $moduleId,
                                    lecturerViewCourseContentTypeFromExtension($ext),
                                    'Course Outline Document',
                                    $mime ?: null,
                                    'uploads/elearning/' . $safeName,
                                    filesize($elearningPath) ?: $size,
                                    $checksum,
                                    (string) $staffId
                                );
                                $db->commit();
                                $uploaded = true;
                            } catch (Throwable $e) {
                                $db->rollback();
                                @unlink($elearningPath);
                                error_log('viewCourse.php course outline upload failed: ' . $e->getMessage());
                            }
                        }
                    }

                    if ($uploaded) {
                        // Post/redirect/get: avoids a duplicate upload on refresh
                        // and lets the modal close cleanly with a flash message.
                        wuc_flash('success', 'Course outline uploaded successfully.');
                        wuc_safe_redirect('viewCourse.php?code=' . urlencode($courseCode));
                    } else {
                        @unlink($destPath);
                        $uploadErrors[] = 'Course outline upload could not be saved. Please try again or contact support.';
                    }
                }
            }
        }
    }
}

$flash = wuc_get_flash();
$uploadSuccess = ($flash && ($flash['type'] ?? '') === 'success') ? (string) ($flash['message'] ?? '') : '';

// Fetch course details — try courses table first, then fallback to program_courses, then stub
$course = null;
$stmt = $db->prepare("SELECT * FROM courses WHERE course_code = ?");
$stmt->bind_param("s", $courseCode);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $course = $result->fetch_object();
}
$stmt->close();

// Fallback: check program_courses table
if (!$course) {
    $pcCheck = $db->query("SHOW TABLES LIKE 'program_courses'");
    if ($pcCheck && $pcCheck->num_rows > 0) {
        $pcCheck->free();
        $stmt = $db->prepare("SELECT course_code, course_name FROM program_courses WHERE course_code = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $courseCode);
            $stmt->execute();
            $pcResult = $stmt->get_result();
            if ($pcResult->num_rows > 0) {
                $course = $pcResult->fetch_object();
            }
            $stmt->close();
        }
    } elseif ($pcCheck) {
        $pcCheck->free();
    }
}

// Final fallback: if lecturer is assigned to this course, create a stub object
if (!$course) {
    if (isLecturerAssignedToCourse($db, $staffId, $courseCode)) {
        $course = new stdClass();
        $course->course_code = $courseCode;
        $course->course_name = $courseCode;
    }
}

if (!$course) {
    require_once __DIR__ . '/includes/nav.php';
    echo '<div class="container-fluid px-4 portal-dashboard"><div class="alert alert-warning m-4">Course not found. <a href="myCourses.php">Back to My Courses</a></div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Fetch lesson notes for this course
$lessonNotes = [];
$stmt = $db->prepare("SELECT ln.*, COALESCE(c.course_name, ?) AS course_name FROM lesson_notes ln 
    LEFT JOIN courses c ON c.course_code = ln.course_code 
    WHERE ln.course_code = ? ORDER BY ln.id DESC");
$courseName = $course->course_name ?? $courseCode;
$stmt->bind_param("ss", $courseName, $courseCode);
$stmt->execute();
$notesResult = $stmt->get_result();
while ($row = $notesResult->fetch_object()) {
    $lessonNotes[] = $row;
}
$stmt->close();

// Now that all redirects and the upload handler have run, emit the page chrome.
require_once __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <?php if (!empty($uploadErrors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <div class="fw-semibold mb-1"><i class="fas fa-exclamation-circle me-2"></i>Course outline upload failed</div>
            <?php foreach (array_unique($uploadErrors) as $uploadError): ?>
                <div><?php echo htmlspecialchars($uploadError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($uploadSuccess)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="status">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($uploadSuccess, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <div>
                <a href="myCourses.php" class="text-white text-decoration-none">
                    <i class="fas fa-arrow-left me-2"></i>Back to My Courses
                </a>
                <span class="text-white mx-2">|</span>
                <span class="text-white fw-bold"><?php echo htmlspecialchars($course->course_code); ?></span>
                <span class="text-white"> - <?php echo htmlspecialchars($course->course_name); ?></span>
            </div>
        </div>
        <div class="card-body">
            <div class="action-buttons mb-4">
                <button type="button" class="btn btn-primary">
                    <i class="fas fa-book me-2"></i>Content
                </button>
                <button type="button"
                        class="btn btn-primary"
                        id="openCourseOutlineModalButton">
                    <i class="fas fa-file-alt me-2"></i>Course Outline
                </button>
                <div class="dropdown d-inline-block">
                    <button class="btn btn-primary dropdown-toggle" type="button" id="uploadDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-upload me-2"></i>Upload
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="uploadDropdown">
                        <li>
                            <a class="dropdown-item" href="#" id="openCourseOutlineModalLink">
                                <i class="fas fa-file-alt me-2"></i>Course Outline
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="materials.php?code=<?php echo urlencode($course->course_code); ?>">
                                <i class="fas fa-book me-2"></i>Lesson Material
                            </a>
                        </li>
                    </ul>
                </div>
                <a href="upload_ca.php?course=<?php echo urlencode($course->course_code); ?>" class="btn btn-warning">
                    <i class="fas fa-upload me-2"></i>Upload CA
                </a>
            </div>

            <!-- Course Outline Section -->
            <div class="card mb-4 border-info">
                <div class="card-header bg-info text-white py-2">
                    <h6 class="mb-0"><i class="fas fa-file-alt me-2"></i>Course Outline</h6>
                </div>
                <div class="card-body py-2">
                    <?php
                    // Fetch course outline
                    $outlines = lecturerViewCourseFetchOutlines($db, $courseCode, getLecturerCourseOfferingIds($db, (string) $staffId, $courseCode));
                    ?>
                    <?php if (!empty($outlines)): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($outlines as $outline): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                    <span>
                                        <i class="fas fa-file-pdf text-danger me-2"></i>
                                        <?php echo htmlspecialchars($outline->title ?? 'Course Outline Document'); ?> (Uploaded: <?php echo isset($outline->uploaded_at) ? htmlspecialchars($outline->uploaded_at) : 'N/A'; ?>)
                                    </span>
                                    <div class="btn-group">
                                        <a href="<?php echo htmlspecialchars($outline->download_url ?? '#'); ?>" 
                                           target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-download me-1"></i>Download
                                        </a>
                                        <!-- Delete functionality could be added here -->
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0 font-italic small">No course outline uploaded yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($lessonNotes)): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date Posted</th>
                            <th>Course Code</th>
                            <th>Course Name</th>
                            <th>Lesson Topic</th>
                            <th>Video Link</th>
                            <th>Download</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lessonNotes as $note): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($note->dte); ?></td>
                            <td><?php echo htmlspecialchars($note->course_code); ?></td>
                            <td><?php echo htmlspecialchars($note->course_name); ?></td>
                            <td><?php echo htmlspecialchars($note->topic); ?></td>
                            <td>
                                <?php if (!empty($note->url) && $note->url !== 'Not available'): ?>
                                <a href="<?php echo htmlspecialchars($note->url); ?>" target="_blank" class="btn btn-primary btn-sm">
                                    <i class="fas fa-external-link-alt me-1"></i>Open Link
                                </a>
                                <?php else: ?>
                                <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="uploads/materials/<?php echo htmlspecialchars($note->notes); ?>" 
                                   target="_blank" class="btn btn-warning btn-sm">
                                    <i class="fas fa-download me-1"></i>Notes
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                No lesson materials have been uploaded for this course yet.
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.portal-dashboard -->

<!-- ====================================================================
     Course Outline Upload Modal — custom overlay.
     Rendered OUTSIDE .portal-dashboard on purpose: that wrapper carries
     position:relative; z-index:1 (css/unified-sidebar.css), which opens a
     stacking context. A modal nested inside it gets trapped *below* the
     body-level backdrop and renders greyed-out / disabled.
     Here the dim backdrop and the dialog are SEPARATE sibling elements, each
     with its own z-index. Opacity lives only on the backdrop — no ancestor of
     the dialog is dimmed — so the form stays fully opaque, centered and
     clickable.
===================================================================== -->
<style>
    .outline-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1090;            /* above the sidebar (z-index ~1001) */
        background-color: #000;
        opacity: 0.45;            /* opacity lives ONLY on the backdrop */
    }
    .outline-modal {
        position: fixed;
        inset: 0;
        z-index: 1100;            /* strictly above the backdrop */
        display: flex;
        align-items: center;      /* vertical centering   */
        justify-content: center;  /* horizontal centering */
        padding: 1rem;
        overflow-y: auto;
    }
    /* Hidden state needs higher specificity than the display rules above so the
       [hidden] attribute reliably wins. */
    .outline-modal[hidden],
    .outline-modal-backdrop[hidden] { display: none; }
    .outline-modal-dialog { width: 100%; max-width: 520px; margin: auto; }
    .outline-modal-content {
        background-color: #fff;
        border-radius: var(--wuc-radius-lg, 0.75rem);
        box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.3);
        opacity: 1;               /* dialog is always fully opaque */
        overflow: hidden;
    }
    .outline-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--wuc-border, #dee2e6);
    }
    .outline-modal-header .modal-title { margin: 0; }
    .outline-modal-body { padding: 1.25rem; }
    body.outline-modal-open { overflow: hidden; }
</style>

<div id="courseOutlineBackdrop" class="outline-modal-backdrop" hidden></div>
<div id="courseOutlineModal" class="outline-modal" role="dialog" aria-modal="true"
     aria-labelledby="courseOutlineModalLabel" hidden>
    <div class="outline-modal-dialog">
        <div class="outline-modal-content">
            <div class="outline-modal-header">
                <h5 class="modal-title" id="courseOutlineModalLabel">Upload Course Outline</h5>
                <button type="button" class="btn-close" data-outline-dismiss aria-label="Close"></button>
            </div>
            <div class="outline-modal-body">
                <?php if (!empty($uploadErrors)): ?>
                    <div class="alert alert-danger py-2" role="alert">
                        <div class="fw-semibold mb-1">
                            <i class="fas fa-exclamation-circle me-2"></i>Upload failed
                        </div>
                        <?php foreach (array_unique($uploadErrors) as $uploadError): ?>
                            <div><?php echo htmlspecialchars($uploadError, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form action="viewCourse.php?code=<?php echo urlencode($courseCode); ?>" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="mb-3">
                        <label for="course_code" class="form-label">Course Code</label>
                        <input type="text" class="form-control" id="course_code" name="course_code"
                               value="<?php echo htmlspecialchars($courseCode); ?>" required readonly>
                    </div>
                    <div class="mb-3">
                        <label for="course_contents" class="form-label">Upload File</label>
                        <input type="file" class="form-control" id="course_contents" name="course_contents"
                               accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png" required>
                        <div class="invalid-feedback">Please select a file</div>
                        <small class="text-muted">Accepted formats: PDF, Word, PowerPoint, Excel or images (Max: 10&nbsp;MB)</small>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-outline-dismiss>Cancel</button>
                        <button type="submit" name="submit" class="btn btn-primary">
                            <i class="fas fa-upload me-2"></i>Upload
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal    = document.getElementById('courseOutlineModal');
    var backdrop = document.getElementById('courseOutlineBackdrop');
    if (!modal || !backdrop) {
        return;
    }

    function openModal(event) {
        if (event) { event.preventDefault(); }
        backdrop.hidden = false;
        modal.hidden = false;
        document.body.classList.add('outline-modal-open');
        var firstField = modal.querySelector('input[type="file"], button[type="submit"]');
        if (firstField) { firstField.focus(); }
    }

    function closeModal() {
        modal.hidden = true;
        backdrop.hidden = true;
        document.body.classList.remove('outline-modal-open');
    }

    // Open triggers: the toolbar "Course Outline" button and the Upload dropdown link.
    ['openCourseOutlineModalButton', 'openCourseOutlineModalLink'].forEach(function (id) {
        var trigger = document.getElementById(id);
        if (trigger) { trigger.addEventListener('click', openModal); }
    });

    // Dismiss: the X, the Cancel button, a click on the backdrop, or Escape.
    modal.querySelectorAll('[data-outline-dismiss]').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) { closeModal(); }
    });

    // Bootstrap-style client validation for the upload form.
    var form = modal.querySelector('form.needs-validation');
    if (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    }

    // An upload was attempted but rejected — reopen so the lecturer sees the
    // inline error context and can retry without re-navigating.
    <?php if (!empty($uploadErrors)): ?>
    openModal();
    <?php endif; ?>
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

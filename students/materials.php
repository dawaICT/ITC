<?php
$page_title = 'Course Materials';
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Start session and load dependencies
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/guard.php'; // Guard handles auth
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';
require_once dirname(__DIR__) . '/includes/short_course_student.php';
if (function_exists('wuc_should_show_error_details') && wuc_should_show_error_details()) {
    ini_set('display_errors', '0');
}

// Accept ?code=, ?view= (legacy), and ?course=
$courseCode = trim($_GET['code'] ?? ($_GET['view'] ?? ($_GET['course'] ?? '')));
$studentId = $_SESSION['Sid'] ?? $_SESSION['student_id'] ?? null;

if (!$studentId) {
    header('Location: /wucportal/student_login.php');
    exit;
}

// Short courses are a separate subsystem (short_course_enrollments + their own
// content). If the requested course is a short course this student is enrolled
// in, send them to the short-course content view rather than the academic one.
if ($courseCode !== '') {
    foreach (sc_student_enrolments($db, (string)$studentId) as $scEnrolment) {
        if (strcasecmp((string)($scEnrolment['course_code'] ?? ''), $courseCode) === 0) {
            $scId = (int)($scEnrolment['short_course_id'] ?? 0);
            $targetUrl = $scId > 0 ? ('short_courses.php?id=' . urlencode((string)$scId)) : 'short_courses.php';
            header('Location: ' . $targetUrl);
            exit;
        }
    }
} elseif (isShortCourseStudent($db, (string)$studentId)) {
    header('Location: short_courses.php');
    exit;
}

// Get all enrolled courses for this student
$enrolledCodes = getStudentEnrolledCourses($db, $studentId);

// Build course info map (code => name)
$courseMap = [];
if (!empty($enrolledCodes)) {
    // Try courses table
    $placeholders = implode(',', array_fill(0, count($enrolledCodes), '?'));
    $types = str_repeat('s', count($enrolledCodes));
    
    $stmt = $db->prepare("SELECT course_code, course_name FROM courses WHERE course_code IN ($placeholders)");
    $stmt->bind_param($types, ...$enrolledCodes);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $courseMap[$row['course_code']] = $row['course_name'];
    }
    $stmt->close();

    // Try program_courses for missing
    $missing = array_diff($enrolledCodes, array_keys($courseMap));
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

    // Fallback
    foreach ($enrolledCodes as $code) {
        if (!isset($courseMap[$code])) {
            $courseMap[$code] = $code;
        }
    }
}

// Validate selected course access
$selectedCourse = null;
if ($courseCode !== '') {
    if (!in_array($courseCode, $enrolledCodes)) {
        // Double check via strict check in case getStudentEnrolledCourses missed something (cache/logic)
        if (!isStudentEnrolledInCourse($db, $studentId, $courseCode)) {
            $error = "You are not enrolled in course: " . htmlspecialchars($courseCode);
        } else {
            // It is valid, add to lists
            $enrolledCodes[] = $courseCode;
            $courseMap[$courseCode] = $courseCode; // Name might be missing but access is granted
        }
    }
    
    if (!isset($error)) {
        $selectedCourse = (object)[
            'course_code' => $courseCode,
            'course_name' => $courseMap[$courseCode] ?? $courseCode
        ];
    }
}

// Fetch materials
$lessonNotes = [];
$outlines = [];

if (empty($enrolledCodes) && !$selectedCourse) {
    // No courses enrolled
} else {
    // 1. Fetch Lesson Notes
    $targetCodes = $selectedCourse ? [$selectedCourse->course_code] : $enrolledCodes;
    
    if (!empty($targetCodes)) {
        $placeholders = implode(',', array_fill(0, count($targetCodes), '?'));
        $types = str_repeat('s', count($targetCodes));
        
        // Notes
        $stmt = $db->prepare("SELECT * FROM lesson_notes WHERE course_code IN ($placeholders) ORDER BY dte DESC, id DESC");
        $stmt->bind_param($types, ...$targetCodes);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_object()) {
            $lessonNotes[] = $row;
        }
        $stmt->close();

        // Current e-learning materials live in el_contents/el_content_versions. Fall back
        // to the old course_contents table only when that legacy table exists.
        $hasLegacyContents = false;
        if ($tableCheck = $db->query("SHOW TABLES LIKE 'course_contents'")) {
            $hasLegacyContents = $tableCheck->num_rows > 0;
            $tableCheck->free();
        }

        if ($hasLegacyContents) {
            $stmt = $db->prepare("SELECT * FROM course_contents WHERE course_code IN ($placeholders) ORDER BY id DESC");
            $stmt->bind_param($types, ...$targetCodes);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_object()) {
                $row->title = 'Course Syllabus / Outline';
                $row->download_url = '../lecturers/uploads/materials/' . rawurlencode((string)$row->course_contents);
                $outlines[] = $row;
            }
            $stmt->close();
        } else {
            $stmt = $db->prepare("
                SELECT c.id AS content_id,
                       c.title,
                       c.content_type,
                       c.created_at AS uploaded_at,
                       m.course_code,
                       v.id AS version_id
                FROM el_contents c
                INNER JOIN el_course_modules m ON m.id = c.module_id
                LEFT JOIN el_content_versions v ON v.id = c.current_version_id
                WHERE m.course_code IN ($placeholders)
                ORDER BY c.created_at DESC, c.id DESC
            ");
            $stmt->bind_param($types, ...$targetCodes);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_object()) {
                $downloadParam = !empty($row->version_id)
                    ? 'version_id=' . urlencode((string)$row->version_id)
                    : 'content_id=' . urlencode((string)$row->content_id);
                $row->download_url = '/wucportal/elearning/download.php?' . $downloadParam;
                $outlines[] = $row;
            }
            $stmt->close();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $page_title; ?></title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="/wucportal/css/admin-style.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="content-wrapper">
        <div class="container-fluid">
            <!-- Breadcrumbs -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="myCourses.php">My Courses</a></li>
                    <?php if ($selectedCourse): ?>
                        <li class="breadcrumb-item"><a href="materials.php">Materials</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($selectedCourse->course_code); ?></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page">All Materials</li>
                    <?php endif; ?>
                </ol>
            </nav>

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="fas fa-book-open text-primary me-2"></i>
                        <?php echo $selectedCourse ? htmlspecialchars($selectedCourse->course_name) : 'Course Materials'; ?>
                    </h1>
                    <p class="text-muted mb-0">
                        <?php echo $selectedCourse ? htmlspecialchars($selectedCourse->course_code) : 'Access learning resources for your enrolled courses'; ?>
                    </p>
                </div>
                <div>
                    <?php if ($selectedCourse): ?>
                        <a href="materials.php" class="btn btn-outline-secondary">
                            <i class="fas fa-list me-2"></i>View All Courses
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger shadow-sm">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php else: ?>

                <!-- Stats/Filter Row -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card shadow-sm h-100 border-0 border-start border-4 border-primary">
                            <div class="card-body">
                                <h6 class="text-muted text-uppercase small mb-2">Total Resources</h6>
                                <h2 class="mb-0"><?php echo count($lessonNotes) + count($outlines); ?></h2>
                            </div>
                        </div>
                    </div>
                    <?php if (!$selectedCourse): ?>
                    <div class="col-md-9">
                        <div class="card shadow-sm h-100 border-0">
                            <div class="card-body d-flex align-items-center">
                                <i class="fas fa-filter text-muted me-3"></i>
                                <select class="form-select border-0 bg-light" onchange="if(this.value) window.location.href='materials.php?code='+encodeURIComponent(this.value)">
                                    <option value="">Filter by Course...</option>
                                    <?php foreach ($courseMap as $code => $name): ?>
                                        <option value="<?php echo htmlspecialchars($code); ?>">
                                            <?php echo htmlspecialchars($code); ?> - <?php echo htmlspecialchars($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- E-learning files (if any) -->
                <?php if (!empty($outlines)): ?>
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 text-primary"><i class="fas fa-file-contract me-2"></i>Shared E-learning Files</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($outlines as $outline): ?>
                            <div class="list-group-item px-4 py-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">
                                        <?php if (!$selectedCourse): ?>
                                            <span class="badge bg-light text-dark border me-2"><?php echo htmlspecialchars($outline->course_code); ?></span>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($outline->title ?? 'Course Material'); ?>
                                    </h6>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i> Uploaded: <?php echo isset($outline->uploaded_at) ? htmlspecialchars($outline->uploaded_at) : 'Recent'; ?>
                                    </small>
                                </div>
                                <a href="<?php echo htmlspecialchars($outline->download_url ?? '#', ENT_QUOTES, 'UTF-8'); ?>" 
                                   target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                    <i class="fas fa-download me-1"></i> Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Lesson Notes -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-success"><i class="fas fa-chalkboard-teacher me-2"></i>Lesson Notes & Resources</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($lessonNotes)): ?>
                            <div class="text-center py-5">
                                <div class="mb-3">
                                    <i class="fas fa-folder-open fa-3x text-muted"></i>
                                </div>
                                <h6 class="text-muted">No lesson materials found</h6>
                                <p class="text-muted small">Materials uploaded by your lecturers will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table id="materialsTable" class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="12%">Date</th>
                                            <?php if (!$selectedCourse): ?><th width="10%">Course</th><?php endif; ?>
                                            <th width="30%">Topic</th>
                                            <th width="25%">Resource</th>
                                            <th width="15%" class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($lessonNotes as $note): 
                                            $ext = pathinfo($note->notes, PATHINFO_EXTENSION);
                                            $icon = 'fa-file';
                                            $color = 'text-secondary';
                                            if (in_array(strtolower($ext), ['pdf'])) { $icon = 'fa-file-pdf'; $color = 'text-danger'; }
                                            elseif (in_array(strtolower($ext), ['doc','docx'])) { $icon = 'fa-file-word'; $color = 'text-primary'; }
                                            elseif (in_array(strtolower($ext), ['ppt','pptx'])) { $icon = 'fa-file-powerpoint'; $color = 'text-warning'; }
                                        ?>
                                        <tr>
                                            <td class="text-muted small"><?php echo htmlspecialchars($note->dte); ?></td>
                                            <?php if (!$selectedCourse): ?>
                                                <td><a href="materials.php?code=<?php echo urlencode($note->course_code); ?>" class="badge bg-secondary text-decoration-none"><?php echo htmlspecialchars($note->course_code); ?></a></td>
                                            <?php endif; ?>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($note->topic); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($note->notes); ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($note->url) && $note->url !== 'Not available'): ?>
                                                    <a href="<?php echo htmlspecialchars($note->url); ?>" target="_blank" class="text-decoration-none">
                                                        <i class="fab fa-youtube text-danger me-1"></i> Video / Link
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted"><i class="fas <?php echo $icon; ?> me-1"></i> Document</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php
                                                    $noteDownloadUrl = '../lecturers/uploads/materials/' . rawurlencode((string)$note->notes);
                                                    $noteFileExists = false;
                                                    if (!empty($note->el_version_id)) {
                                                        $noteDownloadUrl = '/wucportal/elearning/download.php?version_id=' . urlencode((string)$note->el_version_id);
                                                        $noteFileExists = true;
                                                    } elseif (!empty($note->el_content_id)) {
                                                        $noteDownloadUrl = '/wucportal/elearning/download.php?content_id=' . urlencode((string)$note->el_content_id);
                                                        $noteFileExists = true;
                                                    } elseif (!empty($note->notes)) {
                                                        $noteFileExists = file_exists(dirname(__DIR__) . '/lecturers/uploads/materials/' . (string)$note->notes);
                                                    }
                                                ?>
                                                <?php if ($noteFileExists): ?>
                                                <a href="<?php echo htmlspecialchars($noteDownloadUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                   target="_blank" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-download me-1"></i> Get
                                                </a>
                                                <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="File not found">
                                                    <i class="fas fa-exclamation-triangle me-1"></i> Missing
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
    $(document).ready(function() {
        if ($('#materialsTable tbody tr').length > 0) {
            $('#materialsTable').DataTable({
                responsive: true,
                language: { searchPlaceholder: "Search materials..." },
                pageLength: 25,
                order: [[0, 'desc']] // Sort by date descending
            });
        }
    });
    </script>
</body>
</html>
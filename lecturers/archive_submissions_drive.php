<?php
$page_title = 'Submission Online Archive';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/assignment_storage.php';
require_once __DIR__ . '/includes/nav.php';

function archive_drive_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function archive_drive_bind(mysqli_stmt $stmt, string $types, array &$params): bool
{
    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    return $stmt->bind_param($types, ...$refs);
}

function archive_drive_filter_sql(mysqli $db, string $staffId, string $courseCode, string $studentSid, int $limit, string &$types, array &$params): string
{
    $types = '';
    $params = [];
    $where = [
        "s.file_path IS NOT NULL",
        "s.file_path <> ''",
        "(s.drive_file_id IS NULL OR s.drive_file_id = '')",
        "(s.archive_file_path IS NULL OR s.archive_file_path = '')",
    ];

    $courseFilter = elearningLecturerCourseInFilter($db, $staffId, 'a.course_code');
    if (!$courseFilter['has_access']) {
        return "SELECT NULL AS submission_id LIMIT 0";
    }
    $types .= $courseFilter['types'];
    $params = array_merge($params, $courseFilter['params']);

    if ($courseCode !== '') {
        $where[] = 'UPPER(TRIM(a.course_code)) = UPPER(TRIM(?))';
        $types .= 's';
        $params[] = $courseCode;
    }
    if ($studentSid !== '') {
        $where[] = 's.Sid = ?';
        $types .= 's';
        $params[] = $studentSid;
    }

    return "SELECT
                s.id AS submission_id,
                s.assignment_id,
                s.Sid,
                s.submitted_at,
                s.file_path,
                s.drive_file_id,
                s.drive_web_url,
                s.archive_file_path,
                s.archive_original_name,
                s.archive_moved_at,
                a.course_code,
                a.title AS assignment_title,
                a.created_by,
                st.Fname,
                st.Lname
            FROM el_submissions s
            INNER JOIN el_assignments a ON a.id = s.assignment_id
            LEFT JOIN students st ON st.SID = s.Sid
            WHERE " . implode(' AND ', $where) . $courseFilter['clause'] . "
            ORDER BY s.submitted_at ASC, s.id ASC
            LIMIT " . max(1, min(500, $limit));
}

try {
    assignmentStorageEnsureSchema($db);
} catch (Throwable $e) {
    error_log('lecturers/archive_submissions_drive.php: assignment schema ensure failed: ' . $e->getMessage());
}

$staffId = (string)($_SESSION['staff_id'] ?? '');
$status = assignmentStorageConfigStatus();
$selectedCourse = trim((string)($_POST['course_code'] ?? $_GET['course_code'] ?? ''));
$selectedStudent = trim((string)($_POST['student_sid'] ?? $_GET['student_sid'] ?? ''));
$batchLimit = max(1, min(500, (int)($_POST['batch_limit'] ?? $_GET['batch_limit'] ?? 100)));
$messages = [];
$results = [];
$onlineBundle = null;

$courses = [];
$courseFilter = elearningLecturerCourseInFilter($db, $staffId, 'a.course_code');
if ($courseFilter['has_access']) {
    $courseSql = "SELECT DISTINCT a.course_code, COALESCE(c.course_name, a.course_code) AS course_name
        FROM el_assignments a
        LEFT JOIN courses c ON UPPER(TRIM(c.course_code)) = UPPER(TRIM(a.course_code))
        WHERE 1=1{$courseFilter['clause']}
        ORDER BY a.course_code";
    if ($stmt = $db->prepare($courseSql)) {
        $types = $courseFilter['types'];
        $params = $courseFilter['params'];
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        if ($res = $stmt->get_result()) {
            while ($row = $res->fetch_assoc()) {
                $courses[] = $row;
            }
        }
        $stmt->close();
    }
}

$students = [];
if ($courseFilter['has_access'] && elearningTableExists($db, 'el_submissions')) {
    $studentSql = "SELECT DISTINCT s.Sid, st.Fname, st.Lname
        FROM el_submissions s
        INNER JOIN el_assignments a ON a.id = s.assignment_id
        LEFT JOIN students st ON st.SID = s.Sid
        WHERE s.file_path IS NOT NULL AND s.file_path <> ''{$courseFilter['clause']}
        ORDER BY st.Fname, st.Lname, s.Sid";
    if ($stmt = $db->prepare($studentSql)) {
        $types = $courseFilter['types'];
        $params = $courseFilter['params'];
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        if ($res = $stmt->get_result()) {
            while ($row = $res->fetch_assoc()) {
                $students[] = $row;
            }
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    wuc_verify_csrf();
    if (!$status['drive_ready']) {
        $messages[] = ['type' => 'danger', 'text' => $status['drive_message']];
    } else {
        $types = '';
        $params = [];
        $sql = archive_drive_filter_sql($db, $staffId, $selectedCourse, $selectedStudent, $batchLimit, $types, $params);
        if ($stmt = $db->prepare($sql)) {
            archive_drive_bind($stmt, $types, $params);
            $stmt->execute();
            $rows = [];
            if ($res = $stmt->get_result()) {
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            $stmt->close();

            foreach ($rows as $row) {
                $archive = assignmentStorageArchiveSubmission($db, $row);
                $results[] = [
                    'student' => trim((string)($row['Fname'] ?? '') . ' ' . (string)($row['Lname'] ?? '')) ?: (string)$row['Sid'],
                    'sid' => (string)$row['Sid'],
                    'course' => (string)$row['course_code'],
                    'assignment' => (string)$row['assignment_title'],
                    'ok' => (bool)$archive['ok'],
                    'message' => (string)$archive['message'],
                    'url' => (string)($archive['web_url'] ?? ''),
                    'archive_path' => (string)($archive['archive_file_path'] ?? ''),
                ];
            }

            $bundleFiles = [];
            foreach ($results as $result) {
                if (!empty($result['archive_path']) && is_file((string)$result['archive_path'])) {
                    $bundleFiles[] = [
                        'path' => (string)$result['archive_path'],
                        'sid' => (string)$result['sid'],
                        'course' => (string)$result['course'],
                        'assignment' => (string)$result['assignment'],
                    ];
                }
            }
            if ($bundleFiles) {
                $labelParts = ['wuc_submissions', $staffId];
                if ($selectedCourse !== '') {
                    $labelParts[] = $selectedCourse;
                }
                if ($selectedStudent !== '') {
                    $labelParts[] = $selectedStudent;
                }
                $onlineBundle = assignmentStorageCreateOnlineBundle($bundleFiles, implode('_', $labelParts));
                if (empty($onlineBundle['ok'])) {
                    $messages[] = ['type' => 'warning', 'text' => 'Files were moved, but the online upload bundle could not be created: ' . ($onlineBundle['message'] ?? 'Unknown error')];
                }
            }

            $messages[] = [
                'type' => $results ? 'success' : 'info',
                'text' => $results
                    ? count($results) . ' submission file(s) processed. Failed rows are listed below.'
                    : 'No local submission files matched the selected lecturer/student filters.',
            ];
        } else {
            $messages[] = ['type' => 'danger', 'text' => 'Could not prepare archive query.'];
            error_log('lecturers/archive_submissions_drive.php: prepare failed: ' . $db->error);
        }
    }
}

$previewRows = [];
$types = '';
$params = [];
$previewSql = archive_drive_filter_sql($db, $staffId, $selectedCourse, $selectedStudent, 25, $types, $params);
if ($stmt = $db->prepare($previewSql)) {
    archive_drive_bind($stmt, $types, $params);
    $stmt->execute();
    if ($res = $stmt->get_result()) {
        while ($row = $res->fetch_assoc()) {
            $previewRows[] = $row;
        }
    }
    $stmt->close();
}
?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page">
    <div class="dashboard-header lecturer-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Submission Online Archive</h1>
                <p class="text-muted mb-0">Move assignment files for your assigned courses out of portal uploads into external archive storage.</p>
            </div>
            <div class="col-auto">
                <a class="btn btn-outline-secondary" href="assessments.php">
                    <i class="fas fa-arrow-left me-2"></i>Back to Submissions
                </a>
            </div>
        </div>
    </div>

    <div class="assignment-command-bar mb-4" role="navigation" aria-label="Assessment navigation">
        <a class="command-link" href="assessments.php"><i class="fas fa-file-alt"></i><span>Student Submissions</span></a>
        <a class="command-link" href="post_assign.php"><i class="fas fa-tasks"></i><span>Post Assignments</span></a>
        <a class="command-link active" href="archive_submissions_drive.php" aria-current="page"><i class="fas fa-cloud-arrow-up"></i><span>Online Archive</span></a>
        <a class="command-link" href="upload_ca.php"><i class="fas fa-upload"></i><span>Upload CA</span></a>
        <a class="command-link" href="viewCaRes.php"><i class="fas fa-eye"></i><span>View CA Results</span></a>
    </div>

    <div class="alert alert-<?php echo $status['drive_ready'] ? 'success' : 'warning'; ?>">
        <i class="fas fa-box-archive me-2"></i><?php echo archive_drive_h($status['drive_message']); ?>
        <?php if (!empty($status['online_archive_url'])): ?>
            <div class="mt-3">
                <a class="btn btn-sm btn-outline-primary" href="<?php echo archive_drive_h($status['online_archive_url']); ?>" target="_blank" rel="noopener">
                    <i class="fab fa-google-drive me-2"></i>Open <?php echo archive_drive_h($status['online_archive_provider'] ?? 'Google Drive'); ?>
                </a>
                <span class="small text-muted ms-2">After moving files, upload the generated ZIP bundle to your online archive.</span>
            </div>
        <?php endif; ?>
        <?php if (!empty($status['service_account_email'])): ?>
            <div class="small mt-2">
                Service account: <code><?php echo archive_drive_h($status['service_account_email']); ?></code>
                <?php if (!empty($status['drive_root_folder_id'])): ?>
                    <span class="ms-2">Root folder: <code><?php echo archive_drive_h($status['drive_root_folder_id']); ?></code></span>
                <?php else: ?>
                    <span class="ms-2">No root folder ID set; files will be created in the service account Drive.</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!$status['drive_ready']): ?>
            <div class="small mt-2">
                Local config: <code>E:\xampp\htdocs\wucportal\config\assignment_storage.local.php</code>
            </div>
        <?php endif; ?>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?php echo archive_drive_h($message['type']); ?>">
            <?php echo archive_drive_h($message['text']); ?>
        </div>
    <?php endforeach; ?>

    <div class="data-table-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Archive Filter</h5>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="csrf_token" value="<?php echo archive_drive_h($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="col-lg-4">
                    <label class="form-label" for="course_code">Course</label>
                    <select class="form-select" id="course_code" name="course_code">
                        <option value="">All assigned courses</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?php echo archive_drive_h($course['course_code']); ?>" <?php echo $selectedCourse === (string)$course['course_code'] ? 'selected' : ''; ?>>
                                <?php echo archive_drive_h($course['course_code'] . ' - ' . $course['course_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-4">
                    <label class="form-label" for="student_sid">Student</label>
                    <select class="form-select" id="student_sid" name="student_sid">
                        <option value="">All students</option>
                        <?php foreach ($students as $student): ?>
                            <?php $name = trim((string)($student['Fname'] ?? '') . ' ' . (string)($student['Lname'] ?? '')) ?: (string)$student['Sid']; ?>
                            <option value="<?php echo archive_drive_h($student['Sid']); ?>" <?php echo $selectedStudent === (string)$student['Sid'] ? 'selected' : ''; ?>>
                                <?php echo archive_drive_h($name . ' (' . $student['Sid'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label" for="batch_limit">Batch size</label>
                    <input class="form-control" id="batch_limit" name="batch_limit" type="number" min="1" max="500" value="<?php echo (int)$batchLimit; ?>">
                </div>
                <div class="col-lg-2">
                    <button class="btn btn-primary w-100" type="submit" <?php echo $status['drive_ready'] ? '' : 'disabled'; ?>>
                        <i class="fas fa-box-archive me-2"></i>Move Files
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($results): ?>
        <div class="data-table-card mb-4">
            <div class="card-header"><h5 class="mb-0">Archive Results</h5></div>
            <div class="card-body table-responsive">
                <?php if ($onlineBundle && !empty($onlineBundle['ok'])): ?>
                    <div class="alert alert-success">
                        <div><strong>Online upload bundle ready:</strong> <?php echo archive_drive_h($onlineBundle['count']); ?> file(s)</div>
                        <div class="small mt-1">Bundle: <code><?php echo archive_drive_h(basename((string)$onlineBundle['path'])); ?></code></div>
                        <?php if (!empty($status['online_archive_url'])): ?>
                            <a class="btn btn-sm btn-primary mt-2" href="<?php echo archive_drive_h($status['online_archive_url']); ?>" target="_blank" rel="noopener">
                                <i class="fab fa-google-drive me-2"></i>Upload to <?php echo archive_drive_h($status['online_archive_provider'] ?? 'Google Drive'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <table class="table table-hover align-middle">
                    <thead class="table-light"><tr><th>Student</th><th>Course</th><th>Assignment</th><th>Status</th><th>Online</th></tr></thead>
                    <tbody>
                    <?php foreach ($results as $result): ?>
                        <tr>
                            <td><?php echo archive_drive_h($result['student'] . ' (' . $result['sid'] . ')'); ?></td>
                            <td><?php echo archive_drive_h($result['course']); ?></td>
                            <td><?php echo archive_drive_h($result['assignment']); ?></td>
                            <td><span class="badge bg-<?php echo $result['ok'] ? 'success' : 'danger'; ?>"><?php echo archive_drive_h($result['message']); ?></span></td>
                            <td>
                                <?php if ($result['url'] !== ''): ?>
                                    <a href="<?php echo archive_drive_h($result['url']); ?>" target="_blank">Open</a>
                                <?php elseif (!empty($result['archive_path'])): ?>
                                    <small class="text-muted">Bundled for upload</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="data-table-card">
        <div class="card-header"><h5 class="mb-0">Pending Local Files</h5></div>
        <div class="card-body">
            <?php if (!$previewRows): ?>
                <div class="alert alert-info mb-0">No pending local submission files match the current filters.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light"><tr><th>Student</th><th>Course</th><th>Assignment</th><th>Submitted</th><th>Local File</th></tr></thead>
                        <tbody>
                        <?php foreach ($previewRows as $row): ?>
                            <?php $studentName = trim((string)($row['Fname'] ?? '') . ' ' . (string)($row['Lname'] ?? '')) ?: (string)$row['Sid']; ?>
                            <tr>
                                <td><?php echo archive_drive_h($studentName); ?><br><small class="text-muted"><?php echo archive_drive_h($row['Sid']); ?></small></td>
                                <td><?php echo archive_drive_h($row['course_code']); ?></td>
                                <td><?php echo archive_drive_h($row['assignment_title']); ?></td>
                                <td><?php echo archive_drive_h($row['submitted_at']); ?></td>
                                <td><small><?php echo archive_drive_h($row['file_path']); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted mb-0">Preview is limited to 25 rows. The batch size controls how many matching files are moved per run.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

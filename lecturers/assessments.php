<?php
$page_title = 'Assessments';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/assignment_storage.php';

function lecturer_assess_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function lecturer_assess_url(?string $path): string
{
    return assignmentStoragePublicUrl($path);
}

function lecturer_assess_fetch_records(mysqli $db, string $staffId): array
{
    if ($staffId === ''
        || !elearningTableExists($db, 'el_submissions')
        || !elearningTableExists($db, 'el_assignments')) {
        return [];
    }

    $filter = elearningLecturerCourseInFilter($db, $staffId, 'a.course_code');
    if (!$filter['has_access']) {
        return [];
    }

    $gradesJoin = '';
    if (elearningTableExists($db, 'el_grades')) {
        $gradesJoin = 'LEFT JOIN (
                SELECT g.*
                FROM el_grades g
                INNER JOIN (
                    SELECT assignment_id, Sid, MAX(id) AS max_id
                    FROM el_grades
                    GROUP BY assignment_id, Sid
                ) latest ON latest.max_id = g.id
            ) g ON g.assignment_id = s.assignment_id AND g.Sid = s.Sid';
    } else {
        $gradesJoin = 'LEFT JOIN (
                SELECT NULL AS id, NULL AS assignment_id, NULL AS Sid,
                       NULL AS total_points, NULL AS feedback,
                       NULL AS graded_at, NULL AS graded_by
                LIMIT 0
            ) g ON 1=0';
    }

    $sql = "SELECT
                s.id AS submission_id,
                s.assignment_id,
                s.Sid,
                s.submitted_at,
                s.file_path,
                s.text_body,
                s.turnitin_score,
                s.storage_provider,
                s.drive_file_id,
                s.drive_web_url,
                s.drive_folder_id,
                s.archive_file_path,
                s.archive_original_name,
                s.archive_moved_at,
                s.local_file_deleted_at,
                s.ai_detector_provider,
                s.ai_score,
                s.ai_status,
                s.ai_report,
                s.ai_checked_at,
                a.course_code,
                a.title AS assignment_title,
                a.description AS assignment_description,
                a.due_at,
                c.course_name,
                st.Fname,
                st.Lname,
                g.total_points,
                g.feedback,
                g.graded_at,
                g.graded_by
            FROM el_submissions s
            INNER JOIN el_assignments a ON a.id = s.assignment_id
            LEFT JOIN courses c ON UPPER(TRIM(c.course_code)) = UPPER(TRIM(a.course_code))
            LEFT JOIN students st ON st.SID = s.Sid
            {$gradesJoin}
            WHERE 1=1{$filter['clause']}
            ORDER BY s.submitted_at DESC, s.id DESC
            LIMIT 500";

    $records = [];
    try {
        if ($stmt = $db->prepare($sql)) {
            $types = $filter['types'];
            $params = $filter['params'];
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            if ($res = $stmt->get_result()) {
                while ($row = $res->fetch_object()) {
                    $records[] = $row;
                }
            }
            $stmt->close();
        } else {
            error_log('lecturers/assessments.php: submissions query prepare failed: ' . $db->error);
        }
    } catch (Throwable $e) {
        error_log('lecturers/assessments.php: submissions query failed: ' . $e->getMessage());
    }

    return $records;
}

$staffId = (string)($_SESSION['staff_id'] ?? '');
try {
    assignmentStorageEnsureSchema($db);
} catch (Throwable $e) {
    error_log('lecturers/assessments.php: assignment schema ensure failed: ' . $e->getMessage());
}

$records = [];
$courses = [];
$totalSubmissions = 0;
$dueToday = 0;
$recentSubmissions = 0;
$gradedCount = 0;
$driveArchivedCount = 0;
$aiReviewCount = 0;
$assignedCourses = getLecturerAssignedCourses($db, $staffId);

if ($staffId !== '') {
    $records = lecturer_assess_fetch_records($db, $staffId);
}

$totalSubmissions = count($records);
$today = date('Y-m-d');
$lastWeek = date('Y-m-d', strtotime('-7 days'));

foreach ($records as $record) {
    $courseCode = (string)($record->course_code ?? '');
    if (!isset($courses[$courseCode])) {
        $courses[$courseCode] = [
            'name' => $record->course_name ?: $courseCode,
            'count' => 0,
            'graded' => 0,
        ];
    }
    $courses[$courseCode]['count']++;

    if ($record->graded_at !== null && $record->graded_at !== '') {
        $gradedCount++;
        $courses[$courseCode]['graded']++;
    }

    $dueDate = !empty($record->due_at) ? date('Y-m-d', strtotime($record->due_at)) : '';
    $submitDate = !empty($record->submitted_at) ? date('Y-m-d', strtotime($record->submitted_at)) : '';
    if ($dueDate === $today) {
        $dueToday++;
    }
    if ($submitDate >= $lastWeek && $submitDate <= $today) {
        $recentSubmissions++;
    }
    if (!empty($record->drive_file_id) || !empty($record->drive_web_url)) {
        $driveArchivedCount++;
    }
    if (in_array((string)($record->ai_status ?? ''), ['medium', 'high'], true)) {
        $aiReviewCount++;
    }
}

require_once __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page assessments-page">
    <div class="dashboard-header lecturer-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Assessments</h1>
                <p class="text-muted">Receive student assignment submissions and award marks.</p>
            </div>
            <div class="col-auto">
                <a class="btn btn-primary" href="post_assign.php">
                    <i class="fas fa-plus me-2"></i>Post Assignment
                </a>
                <a class="btn btn-outline-primary ms-2" href="archive_submissions_drive.php">
                    <i class="fab fa-google-drive me-2"></i>Archive to Drive
                </a>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="data-table-card h-100"><div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="stat-icon bg-lecturer rounded-circle p-3 me-3">
                        <i class="fas fa-file-alt fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo number_format($totalSubmissions); ?></h3>
                        <p class="stat-label mb-0">Submissions</p>
                    </div>
                </div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="data-table-card h-100"><div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="stat-icon bg-warning rounded-circle p-3 me-3">
                        <i class="fas fa-clock fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo number_format($dueToday); ?></h3>
                        <p class="stat-label mb-0">Due Today</p>
                    </div>
                </div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="data-table-card h-100"><div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="stat-icon bg-info rounded-circle p-3 me-3">
                        <i class="fas fa-calendar-check fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo number_format($recentSubmissions); ?></h3>
                        <p class="stat-label mb-0">Recent</p>
                    </div>
                </div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="data-table-card h-100"><div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="stat-icon bg-success rounded-circle p-3 me-3">
                        <i class="fab fa-google-drive fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo number_format($driveArchivedCount); ?></h3>
                        <p class="stat-label mb-0">On Drive</p>
                    </div>
                </div>
            </div></div>
        </div>
    </div>

    <div class="assignment-command-bar mb-4" role="navigation" aria-label="Assessment navigation">
        <a class="command-link active" href="assessments.php" aria-current="page">
            <i class="fas fa-file-alt"></i><span>Student Submissions</span>
        </a>
        <a class="command-link" href="post_assign.php">
            <i class="fas fa-tasks"></i><span>Post Assignments</span>
        </a>
        <a class="command-link" href="ai_question_bank.php">
            <i class="fas fa-wand-magic-sparkles"></i><span>AI Question Bank</span>
        </a>
        <a class="command-link" href="upload_ca.php">
            <i class="fas fa-upload"></i><span>Upload CA</span>
        </a>
        <a class="command-link" href="viewCaRes.php">
            <i class="fas fa-eye"></i><span>View CA Results</span>
        </a>
        <a class="command-link" href="archive_submissions_drive.php">
            <i class="fab fa-google-drive"></i><span>Drive Archive</span>
        </a>
    </div>

    <?php if (!$assignedCourses): ?>
        <div class="alert alert-warning">
            <i class="fas fa-info-circle me-2"></i>No active courses are assigned to your lecturer account.
        </div>
    <?php endif; ?>

    <?php if ($courses): ?>
        <div class="row mb-4">
            <?php foreach ($courses as $code => $course): ?>
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="data-table-card h-100">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-book me-2"></i><?php echo lecturer_assess_h($code); ?></h5>
                                <span class="badge bg-primary rounded-pill"><?php echo number_format($course['count']); ?> submissions</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <h6 class="mb-3"><?php echo lecturer_assess_h($course['name']); ?></h6>
                            <p class="mb-0 text-muted"><?php echo number_format($course['graded']); ?> marked</p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Student Submissions</h5>
        </div>
        <div class="card-body">
            <?php if (!$records): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No student has submitted an assignment for your assigned courses yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="submissionsTable" class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Assignment</th>
                                <th>Course</th>
                                <th>Submitted</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th>AI Review</th>
                                <th>Mark</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $index => $r): ?>
                                <?php
                                $studentName = trim((string)($r->Fname ?? '') . ' ' . (string)($r->Lname ?? ''));
                                if ($studentName === '') {
                                    $studentName = (string)$r->Sid;
                                }
                                $submittedTs = !empty($r->submitted_at) ? strtotime($r->submitted_at) : null;
                                $dueTs = !empty($r->due_at) ? strtotime($r->due_at) : null;
                                $isLate = $submittedTs && $dueTs && $submittedTs > $dueTs;
                                $isGraded = $r->graded_at !== null && $r->graded_at !== '';
                                $status = $isLate ? 'Late' : 'On Time';
                                $statusClass = $isLate ? 'warning' : 'success';
                                $downloadUrl = assignmentStorageSubmissionUrl($r);
                                $isDriveFile = !empty($r->drive_web_url) || !empty($r->drive_file_id);
                                $isArchivedFile = !$isDriveFile && !empty($r->archive_file_path);
                                $aiStatus = (string)($r->ai_status ?? '');
                                $aiScore = $r->ai_score !== null ? (float)$r->ai_score : null;
                                $aiBadgeClass = $aiStatus === 'high' ? 'danger' : ($aiStatus === 'medium' ? 'warning text-dark' : ($aiStatus === 'low' ? 'success' : 'secondary'));
                                $gradeUrl = 'grade_assignment.php?submission_id=' . (int)$r->submission_id;
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <strong><?php echo lecturer_assess_h($studentName); ?></strong><br>
                                        <small class="text-muted"><?php echo lecturer_assess_h($r->Sid); ?></small>
                                    </td>
                                    <td><?php echo lecturer_assess_h($r->assignment_title); ?></td>
                                    <td>
                                        <span class="fw-bold"><?php echo lecturer_assess_h($r->course_code); ?></span><br>
                                        <small class="text-muted"><?php echo lecturer_assess_h($r->course_name ?: $r->course_code); ?></small>
                                    </td>
                                    <td><?php echo $submittedTs ? date('M d, Y H:i', $submittedTs) : 'Unknown'; ?></td>
                                    <td><?php echo $dueTs ? date('M d, Y H:i', $dueTs) : 'No due date'; ?></td>
                                    <td><span class="badge bg-<?php echo $statusClass; ?> rounded-pill"><?php echo $status; ?></span></td>
                                    <td>
                                        <?php if ($aiStatus !== ''): ?>
                                            <span class="badge bg-<?php echo lecturer_assess_h($aiBadgeClass); ?>" title="<?php echo lecturer_assess_h($r->ai_report ?? ''); ?>">
                                                <?php echo lecturer_assess_h(ucfirst($aiStatus)); ?>
                                                <?php if ($aiScore !== null): ?> <?php echo lecturer_assess_h(number_format($aiScore, 0)); ?>%<?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not checked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isGraded): ?>
                                            <span class="badge bg-success"><?php echo lecturer_assess_h(number_format((float)$r->total_points, 2)); ?>/100</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <?php if (!empty($r->file_path) || !empty($r->drive_web_url) || !empty($r->archive_file_path)): ?>
                                                <a href="<?php echo lecturer_assess_h($downloadUrl); ?>" target="_blank" class="btn btn-primary btn-sm" title="<?php echo $isDriveFile ? 'Open from Google Drive' : ($isArchivedFile ? 'Download from archive' : 'Open submission'); ?>">
                                                    <i class="<?php echo $isDriveFile ? 'fab fa-google-drive' : ($isArchivedFile ? 'fas fa-box-archive' : 'fas fa-download'); ?>"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-success btn-sm view-details"
                                                    data-student="<?php echo lecturer_assess_h($studentName); ?>"
                                                    data-sid="<?php echo lecturer_assess_h($r->Sid); ?>"
                                                    data-assignment="<?php echo lecturer_assess_h($r->assignment_title); ?>"
                                                    data-course="<?php echo lecturer_assess_h($r->course_code); ?>"
                                                    data-submitted="<?php echo lecturer_assess_h($r->submitted_at); ?>"
                                                    data-due="<?php echo lecturer_assess_h($r->due_at ?: ''); ?>"
                                                    data-file="<?php echo lecturer_assess_h($downloadUrl); ?>"
                                                    data-storage="<?php echo lecturer_assess_h($isDriveFile ? 'Google Drive' : ($isArchivedFile ? 'Local archive' : 'Portal storage')); ?>"
                                                    data-ai-status="<?php echo lecturer_assess_h($aiStatus ?: 'Not checked'); ?>"
                                                    data-ai-score="<?php echo lecturer_assess_h($aiScore !== null ? number_format($aiScore, 1) . '%' : ''); ?>"
                                                    data-ai-report="<?php echo lecturer_assess_h($r->ai_report ?: 'No AI review report available.'); ?>"
                                                    data-text="<?php echo lecturer_assess_h($r->text_body ?: ''); ?>"
                                                    title="View details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="<?php echo lecturer_assess_h($gradeUrl); ?>" class="btn btn-warning btn-sm" title="Award marks">
                                                <i class="fas fa-star"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="submissionDetailsModal" tabindex="-1" aria-labelledby="submissionDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-lecturer text-white">
                <h5 class="modal-title" id="submissionDetailsModalLabel">Submission Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-2">Student</h6>
                        <p class="mb-0 fw-bold" id="modal-student"></p>
                        <p class="mb-0 small text-muted" id="modal-sid"></p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-2">Course</h6>
                        <p class="mb-0 fw-bold" id="modal-course"></p>
                    </div>
                </div>
                <div class="mb-4">
                    <h6 class="text-muted mb-2">Assignment</h6>
                    <p class="mb-0" id="modal-assignment"></p>
                </div>
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-2">Submitted</h6>
                        <p class="mb-0" id="modal-submitted"></p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-2">Due</h6>
                        <p class="mb-0" id="modal-due"></p>
                    </div>
                </div>
                <div class="mb-4" id="modal-file-wrap">
                    <h6 class="text-muted mb-2">Submission File</h6>
                    <a href="#" id="modal-filelink" target="_blank" class="btn btn-sm btn-primary">
                        <i class="fas fa-download me-2"></i>Open File
                    </a>
                    <span class="badge bg-light text-dark ms-2" id="modal-storage"></span>
                </div>
                <div class="mb-4">
                    <h6 class="text-muted mb-2">AI Writing Review</h6>
                    <p class="mb-1">
                        <span class="badge bg-secondary" id="modal-ai-status"></span>
                        <span class="small text-muted ms-2" id="modal-ai-score"></span>
                    </p>
                    <div class="p-3 rounded bg-light small" id="modal-ai-report"></div>
                </div>
                <div class="mb-2">
                    <h6 class="text-muted mb-2">Text Response</h6>
                    <div class="p-3 rounded bg-light" id="modal-text"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    if ($('#submissionsTable').length && $.fn.DataTable) {
        $('#submissionsTable').DataTable({
            responsive: true,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search submissions...",
                zeroRecords: "No matching submissions found",
                info: "Showing _START_ to _END_ of _TOTAL_ submissions",
                lengthMenu: "Show _MENU_ submissions per page"
            },
            dom: '<"top"lf>rt<"bottom"ip><"clear">',
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            pageLength: 10,
            order: [[4, 'desc']]
        });
    }

    $('.view-details').on('click', function() {
        const student = $(this).data('student') || '';
        const sid = $(this).data('sid') || '';
        const assignment = $(this).data('assignment') || '';
        const course = $(this).data('course') || '';
        const submitted = $(this).data('submitted') || '';
        const due = $(this).data('due') || 'No due date';
        const file = $(this).data('file') || '';
        const storage = $(this).data('storage') || '';
        const aiStatus = $(this).data('ai-status') || 'Not checked';
        const aiScore = $(this).data('ai-score') || '';
        const aiReport = $(this).data('ai-report') || 'No AI review report available.';
        const text = $(this).data('text') || 'No text response provided.';

        $('#modal-student').text(student);
        $('#modal-sid').text(sid);
        $('#modal-assignment').text(assignment);
        $('#modal-course').text(course);
        $('#modal-submitted').text(submitted);
        $('#modal-due').text(due);
        $('#modal-text').text(text);
        $('#modal-storage').text(storage);
        $('#modal-ai-status').text(aiStatus);
        $('#modal-ai-score').text(aiScore);
        $('#modal-ai-report').text(aiReport);

        if (file && file !== '#') {
            $('#modal-file-wrap').show();
            $('#modal-filelink').attr('href', file);
        } else {
            $('#modal-file-wrap').hide();
        }

        const modal = new bootstrap.Modal(document.getElementById('submissionDetailsModal'));
        modal.show();
    });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
error_reporting(0);
$page_title = 'CA Manager';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';
$csrfToken = wuc_csrf_token();

// Initialize records array
$records = array();
$hodCourseCodes = array();
$departmentName = '';

// Get department ID of current HOD
$departmentId = '';
$hodStaffId = $_SESSION['staff_id'] ?? '';
$nonAcademicHosSection = false;
$sectionType = '';

$detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        if ($res = @$db->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $db->real_escape_string($col) . "'")) {
            if ($res->num_rows > 0) {
                $res->free();
                return $col;
            }
            $res->free();
        }
    }
    return null;
};

$tableExists = function(mysqli $db, string $table): bool {
    if ($res = @$db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
};

$staffDeptCol = $detectColumn($db, 'staff', ['department_id', 'DeptID', 'deptId']);
$deptIdCol = $detectColumn($db, 'departments', ['department_id', 'id', 'DeptID']);
$deptNameCol = $detectColumn($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
$deptHodCol = $detectColumn($db, 'departments', ['hod_id', 'HODID', 'hodId']);
$programDeptCol = $detectColumn($db, 'programs', ['department_id', 'DeptID', 'deptId', 'department_code']);

if ($hodStaffId !== '' && $deptHodCol && $deptIdCol) {
    $deptNameSelect = $deptNameCol ? "`{$deptNameCol}` AS department_name" : "'' AS department_name";
    $stmt = @$db->prepare("
        SELECT `{$deptIdCol}` AS dept_id, {$deptNameSelect}
        FROM departments
        WHERE CAST(`{$deptHodCol}` AS CHAR) = CAST(? AS CHAR)
           OR CAST(`{$deptHodCol}` AS CHAR) = (
               SELECT CAST(id AS CHAR) FROM staff WHERE staff_id = ? LIMIT 1
           )
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $hodStaffId, $hodStaffId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_object()) {
            $departmentId = (string)($row->dept_id ?? '');
            $departmentName = (string)($row->department_name ?? $departmentId);
        }
        $stmt->close();
    }
}

if ($departmentId === '' && $hodStaffId !== '' && $staffDeptCol && $deptIdCol) {
    $deptNameSelect = $deptNameCol ? "d.`{$deptNameCol}` AS department_name" : "'' AS department_name";
    $stmt = @$db->prepare("
        SELECT s.`{$staffDeptCol}` AS dept_id, {$deptNameSelect}
        FROM staff s
        LEFT JOIN departments d ON CAST(s.`{$staffDeptCol}` AS CHAR) = CAST(d.`{$deptIdCol}` AS CHAR)
        WHERE s.staff_id = ? LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $hodStaffId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_object()) {
            $departmentId = (string)($row->dept_id ?? '');
            $departmentName = (string)($row->department_name ?? $departmentId);
        }
        $stmt->close();
    }
}

if ($departmentId !== '') {
    $_SESSION['dept_id'] = $departmentId;
}

$deptContext = hod_resolve_department($db, (string)$hodStaffId);
$sectionType = (string)($deptContext['section_type'] ?? '');
$nonAcademicHosSection = ($sectionType !== '' && $sectionType !== 'academic');
if ((string)$deptContext['id'] !== '') {
    $departmentId = (string)$deptContext['id'];
    $departmentName = (string)$deptContext['name'];
    $_SESSION['dept_id'] = $departmentId;
} elseif ($sectionType !== '' && $sectionType !== 'academic') {
    $departmentName = (string)$deptContext['name'];
}

// Course scope spans every department in the HOS's section, not just the first.
foreach (hod_section_department_ids($deptContext) as $sectionDeptId) {
    if ($programDeptCol) {
        $stmt = @$db->prepare("
            SELECT DISTINCT pc.course_code
            FROM programs p
            INNER JOIN program_courses pc ON pc.program_code = p.program_code
            WHERE CAST(p.`{$programDeptCol}` AS CHAR) = CAST(? AS CHAR)
        ");
        if ($stmt) {
            $stmt->bind_param('s', $sectionDeptId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && $row = $res->fetch_assoc()) {
                $hodCourseCodes[] = $row['course_code'];
            }
            $stmt->close();
        }
    }
}

if ($hodStaffId !== '' && $tableExists($db, 'course_lecturer')) {
    $stmt = @$db->prepare("
        SELECT DISTINCT course_code
        FROM course_lecturer
        WHERE staff_id = ?
          AND course_code IS NOT NULL
          AND course_code <> ''
    ");
    if ($stmt) {
        $stmt->bind_param('s', $hodStaffId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $hodCourseCodes[] = $row['course_code'];
        }
        $stmt->close();
    }
}
$hodCourseCodes = array_values(array_unique(array_filter($hodCourseCodes)));

// Fetch assessment data
if (!empty($hodCourseCodes)) {
    $placeholders = implode(',', array_fill(0, count($hodCourseCodes), '?'));
    $types = str_repeat('s', count($hodCourseCodes));
    $stmt = @$db->prepare("SELECT * FROM semester_assessment WHERE Course_Code IN ({$placeholders}) ORDER BY `Year` DESC, semester DESC, Course_Code ASC, Sid ASC");
    if ($stmt) {
        $stmt->bind_param($types, ...$hodCourseCodes);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($results && $row = $results->fetch_object()) {
            $records[] = $row;
        }
        $stmt->close();
    }
}
// No course scope resolved: leave the list empty rather than exposing every
// section's assessment records.
$number = 1;
?>

<div class="container-fluid px-4 portal-dashboard hod-page">
    <div class="page-header mb-3 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-clipboard-check me-2 text-primary"></i>Continuous Assessment Manager</h5>
                <p class="page-subtitle mb-0">Review and approve student assessment scores</p>
            </div>
            <div>
                <a href="approvedCA.php" class="btn btn-success btn-sm">
                    <i class="fas fa-check-circle me-1"></i>View Approved CAs
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3">
                        <i class="fas fa-tasks text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-0"><?php echo count($records); ?></h3>
                        <p class="text-muted mb-0">Pending Assessments</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3">
                        <i class="fas fa-check-circle text-white"></i>
                    </div>
                    <div>
						                          <?php
												// Get count of approved CAs for this HOS scope when the legacy table exists.
												$approvedCount = 0;
												$approvedTable = $tableExists($db, 'approved_assessments') ? 'approved_assessments' : ($tableExists($db, 'approved_ca') ? 'approved_ca' : '');
												if ($approvedTable !== '' && !$nonAcademicHosSection) {
													if (!empty($hodCourseCodes)) {
														$approvedCourseCol = $detectColumn($db, $approvedTable, ['Course_Code', 'course_code', 'courseId']);
														if ($approvedCourseCol) {
															$approvedPlaceholders = implode(',', array_fill(0, count($hodCourseCodes), '?'));
															$approvedTypes = str_repeat('s', count($hodCourseCodes));
															$approvedStmt = @$db->prepare("SELECT COUNT(*) AS total FROM `{$approvedTable}` WHERE `{$approvedCourseCol}` IN ({$approvedPlaceholders})");
															if ($approvedStmt) {
																$approvedStmt->bind_param($approvedTypes, ...$hodCourseCodes);
																$approvedStmt->execute();
																$approvedResult = $approvedStmt->get_result();
																if ($approvedResult && $approvedRow = $approvedResult->fetch_assoc()) {
																	$approvedCount = (int)($approvedRow['total'] ?? 0);
																}
																$approvedStmt->close();
															}
														}
													}
													// No course scope: keep the count at 0 instead of
													// counting every section's approved assessments.
												}
												?>
                        <h3 class="mb-0"><?php echo number_format($approvedCount); ?></h3>
                        <p class="text-muted mb-0">Approved Assessments</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3">
                        <i class="fas fa-calendar-alt text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-0"><?php echo date('Y'); ?></h3>
                        <p class="text-muted mb-0">Current Year</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3">
                        <i class="fas fa-book text-white"></i>
                    </div>
                    <div>
						                          <?php 
                        // Get count of distinct courses
                        $courseCodes = [];
                        foreach ($records as $record) {
                            if (!empty($record->Course_Code)) {
                                $courseCodes[] = (string)$record->Course_Code;
                            }
                        }
                        $courseCount = count(array_unique($courseCodes));
                        ?>
                        <h3 class="mb-0"><?php echo number_format($courseCount); ?></h3>
                        <p class="text-muted mb-0">Courses</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters Card -->
    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="courseFilter" class="form-label">Course</label>
                    <select class="form-select" id="courseFilter">
                        <option value="">All Courses</option>
                        <!-- Course options would be populated via JavaScript -->
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="yearFilter" class="form-label">Academic Year</label>
                    <select class="form-select" id="yearFilter">
                        <option value="">All Years</option>
                        <!-- Year options would be populated via JavaScript -->
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="scoreFilter" class="form-label">Min Score</label>
                    <input type="range" class="form-range" id="scoreFilter" min="0" max="40" value="0">
                    <div class="d-flex justify-content-between">
                        <span>0</span>
                        <span id="scoreValue">0</span>
                        <span>40</span>
						    </div>
					</div>
				</div>
			</div>
			</div>

    <!-- Assessment Table Card -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-clipboard-check me-2"></i>Pending Assessments
                </h5>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($records)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php if ($nonAcademicHosSection): ?>
                        <?php echo htmlspecialchars($departmentName ?: 'This section'); ?> is not linked to academic continuous assessments.
                    <?php elseif ($departmentId !== '' && empty($hodCourseCodes)): ?>
                        No courses are linked to your section yet.
                    <?php else: ?>
                        No pending continuous assessments found.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="assessmentTable" class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="5%">#</th>
                                <th width="12%">Student ID</th>
                                <th width="12%">Course Code</th>
                                <th width="8%">A1</th>
                                <th width="8%">A2</th>
                                <th width="8%">T1</th>
                                <th width="8%">T2</th>
                                <th width="10%">Total CA</th>
                                <th width="10%">Year</th>
                                <th width="10%">Status</th>
                                <th width="12%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($records as $r): ?>
                                <tr>
                                    <td><?php echo $number++; ?></td>
                                    <td><?php echo htmlspecialchars($r->Sid); ?></td>
                                    <td><?php echo htmlspecialchars($r->Course_Code); ?></td>
                                    <td><?php echo htmlspecialchars($r->A1); ?></td>
                                    <td><?php echo htmlspecialchars($r->A2); ?></td>
                                    <td><?php echo htmlspecialchars($r->T1); ?></td>
                                    <td><?php echo htmlspecialchars($r->T2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $r->Total_CA < 16 ? 'danger' : ($r->Total_CA < 24 ? 'warning' : 'success'); ?> rounded-pill">
                                            <?php echo htmlspecialchars($r->Total_CA); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($r->Year); ?></td>
                                    <?php
                                        $st = wuc_result_normalize_status($r->status ?? null);
                                        $trans = wuc_result_allowed_transitions($st);
                                    ?>
                                    <td>
                                        <span class="badge <?php echo wuc_result_status_badge($st); ?>"><?php echo htmlspecialchars($st); ?></span>
                                        <?php if ($st === 'Rejected' && !empty($r->rejection_reason)): ?>
                                            <div class="small text-danger mt-1"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars((string)$r->rejection_reason); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <?php if (in_array('Approved', $trans, true)): ?>
                                            <form action="approveCA.php" method="post" class="approve-ca d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="assessment_id" value="<?php echo (int)$r->id; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-sm btn-success" data-bs-toggle="tooltip" data-bs-placement="top" title="Approve">
                                                <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if (in_array('Published', $trans, true)): ?>
                                            <button class="btn btn-sm btn-primary publish-ca" data-id="<?php echo (int)$r->id; ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="Publish to students"><i class="fas fa-bullhorn"></i></button>
                                            <?php endif; ?>
                                            <?php if (in_array('Rejected', $trans, true)): ?>
                                            <button class="btn btn-sm btn-warning reject-ca" data-id="<?php echo (int)$r->id; ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="Return for correction"><i class="fas fa-rotate-left"></i></button>
                                            <?php endif; ?>
                                            <?php
                                            $finalCalc = wuc_result_compute($db, $r->Sid, $r->Total_CA, $r->Exam);
                                            $hasExam = wuc_result_exam_written($r->Exam);
                                            $finalMark = $hasExam ? $finalCalc['final'] : '';
                                            $finalGrade = $hasExam ? $finalCalc['grade'] : 'NE';
                                            $finalRemark = $hasExam ? $finalCalc['remark'] : 'Not Examined';
                                            ?>
                                            <button class="btn btn-sm btn-info view-details"
                                                data-id="<?php echo htmlspecialchars((string)$r->id, ENT_QUOTES, 'UTF-8'); ?>" 
                                                data-student="<?php echo htmlspecialchars((string)$r->Sid, ENT_QUOTES, 'UTF-8'); ?>" 
                                                data-course="<?php echo htmlspecialchars((string)$r->Course_Code, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-exam="<?php echo htmlspecialchars($r->Exam !== null ? (string)$r->Exam : ''); ?>"
                                                data-final-mark="<?php echo htmlspecialchars((string)$finalMark); ?>"
                                                data-final-grade="<?php echo htmlspecialchars($finalGrade); ?>"
                                                data-final-remark="<?php echo htmlspecialchars($finalRemark); ?>"
                                                data-internal-status="<?php echo htmlspecialchars($r->internal_moderation_status ?? 'pending'); ?>"
                                                data-internal-notes="<?php echo htmlspecialchars($r->internal_moderation_notes ?? ''); ?>"
                                                data-external-status="<?php echo htmlspecialchars($r->external_moderation_status ?? 'pending'); ?>"
                                                data-external-notes="<?php echo htmlspecialchars($r->external_moderation_notes ?? ''); ?>"
                                                data-external-moderator="<?php echo htmlspecialchars($r->external_moderator_id ?? ''); ?>"
                                                data-bs-toggle="tooltip" data-bs-placement="top" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-primary edit-ca" data-id="<?php echo $r->id?>" data-a1="<?php echo htmlspecialchars($r->A1); ?>" data-a2="<?php echo htmlspecialchars($r->A2); ?>" data-t1="<?php echo htmlspecialchars($r->T1); ?>" data-t2="<?php echo htmlspecialchars($r->T2); ?>" title="Edit Scores">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger delete-ca" data-id="<?php echo $r->id?>" title="Delete Record">
                                                <i class="fas fa-trash"></i>
                                            </button>
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

<!-- Assessment Details Modal -->
<div class="modal fade" id="assessmentDetailsModal" tabindex="-1" aria-labelledby="assessmentDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="assessmentDetailsModalLabel">Assessment Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="assessmentDetailsContent">
                <!-- Details will be loaded here via AJAX -->
                <div class="text-center py-5">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <form action="approveCA.php" method="post" id="approveForm" class="approve-ca d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="assessment_id" id="approveAssessmentId" value="">
                    <input type="hidden" name="action" value="approve">
                <button type="submit" id="approveLink" class="btn btn-success">
                    <i class="fas fa-check me-2"></i>Approve Assessment
                </button>
                </form>
            </div>
        </div>
		</div>
	</div>

<!-- DataTables Initialization and Custom Scripts -->
	<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    const $assessmentTable = $('#assessmentTable');
    if ($assessmentTable.length && $.fn.DataTable) {
        const table = $assessmentTable.DataTable({
            responsive: true,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search assessments...",
                zeroRecords: "No matching assessments found",
                info: "Showing _START_ to _END_ of _TOTAL_ assessments",
                lengthMenu: "Show _MENU_ assessments per page"
            },
            dom: '<"top"lf>rt<"bottom"ip><"clear">',
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            pageLength: 10,
            columnDefs: [
                {orderable: false, targets: [10]},
                {searchable: false, targets: [0, 10]}
            ]
        });

        const courses = [];
        const years = [];

        table.column(2).data().unique().sort().each(function(value) {
            courses.push(String(value).trim());
        });

        table.column(8).data().unique().sort().each(function(value) {
            years.push(String(value).trim());
        });

        courses.forEach(function(course) {
            $('#courseFilter').append(new Option(course, course));
        });

        years.forEach(function(year) {
            $('#yearFilter').append(new Option(year, year));
        });

        $('#courseFilter, #yearFilter').on('change', function() {
            const courseVal = $('#courseFilter').val();
            const yearVal = $('#yearFilter').val();

            table.columns().search('').draw();

            if (courseVal) {
                table.column(2).search(courseVal).draw();
            }

            if (yearVal) {
                table.column(8).search(yearVal).draw();
            }
        });

        $('#scoreFilter').on('input', function() {
            const value = $(this).val();
            $('#scoreValue').text(value);

            $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(filterFn) {
                return !filterFn.caManagerScoreFilter;
            });

            const scoreFilter = function(settings, data) {
                if (settings.nTable !== $assessmentTable[0]) {
                    return true;
                }
                const totalScore = parseFloat(String(data[7]).replace(/[^\d.-]/g, '')) || 0;
                return totalScore >= value;
            };
            scoreFilter.caManagerScoreFilter = true;
            $.fn.dataTable.ext.search.push(scoreFilter);

            table.draw();
        });
    }
    
    // Handle view details button click
    $(document).on('click', '.view-details', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var studentId = $(this).data('student');
        var courseCode = $(this).data('course');
        var exam = $(this).data('exam');
        var finalMark = $(this).data('final-mark');
        var finalGrade = $(this).data('final-grade');
        var finalRemark = $(this).data('final-remark');
        var internalStatus = $(this).data('internal-status') || 'pending';
        var internalNotes = $(this).data('internal-notes') || '';
        var externalStatus = $(this).data('external-status') || 'pending';
        var externalNotes = $(this).data('external-notes') || '';
        var externalModerator = $(this).data('external-moderator') || '';
        
        $('#approveAssessmentId').val(id);
        
        var row = $(this).closest('tr');
        var mockData = {
            id: id,
            student: studentId,
            course: courseCode,
            a1: row.find('td:eq(3)').text(),
            a2: row.find('td:eq(4)').text(),
            t1: row.find('td:eq(5)').text(),
            t2: row.find('td:eq(6)').text(),
            total: row.find('td:eq(7)').text().trim(),
            year: row.find('td:eq(8)').text(),
            exam: exam,
            finalMark: finalMark,
            finalGrade: finalGrade,
            finalRemark: finalRemark,
            internalStatus: internalStatus,
            internalNotes: internalNotes,
            externalStatus: externalStatus,
            externalNotes: externalNotes,
            externalModerator: externalModerator
        };
        
        // Calculate grade based on total CA score
        var grade = '';
        var totalScore = parseFloat(mockData.total);
        
        if (totalScore >= 32) grade = 'Excellent';
        else if (totalScore >= 24) grade = 'Good';
        else if (totalScore >= 16) grade = 'Satisfactory';
        else grade = 'Needs Improvement';
        
        // Display assessment details
        var detailsHtml = `
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-light">
                    <h5 class="mb-0 font-weight-bold"><i class="fas fa-file-invoice me-2 text-primary"></i>Assessment Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-2"><strong>Student ID:</strong> ${mockData.student}</p>
                            <p class="mb-2"><strong>Course Code:</strong> ${mockData.course}</p>
                            <p class="mb-2"><strong>Academic Year:</strong> ${mockData.year}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-2"><strong>Total CA Score:</strong> ${mockData.total}/40</p>
                            <p class="mb-2"><strong>CA Level:</strong> <span class="badge bg-secondary">${grade}</span></p>
                            <p class="mb-2"><strong>Final Examination:</strong> ${mockData.exam !== '' && mockData.exam !== null ? mockData.exam + '%' : '<span class="text-danger">NE (Not Examined)</span>'}</p>
                        </div>
                    </div>
                    ${mockData.exam !== '' && mockData.exam !== null ? `
                    <div class="alert alert-info mt-3 mb-0 py-2 d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Calculated Final Result:</strong> <span class="h5 mb-0 font-weight-bold ms-1 text-primary">${mockData.finalMark}%</span>
                        </div>
                        <div>
                            <strong>Grade:</strong> <span class="badge bg-purple ms-1">${mockData.finalGrade}</span>
                            <span class="text-muted small ms-1">(${mockData.finalRemark})</span>
                        </div>
                    </div>
                    ` : ''}
                </div>
            </div>
            
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-light">
                    <h5 class="mb-0 font-weight-bold"><i class="fas fa-table me-2 text-primary"></i>CA Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Assessment Type</th>
                                    <th class="text-center">Score</th>
                                    <th class="text-center">Max</th>
                                    <th class="text-center">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Assignment 1</td>
                                    <td class="text-center">${mockData.a1}</td>
                                    <td class="text-center">10</td>
                                    <td class="text-center">${(mockData.a1 / 10 * 100).toFixed(0)}%</td>
                                </tr>
                                <tr>
                                    <td>Assignment 2</td>
                                    <td class="text-center">${mockData.a2}</td>
                                    <td class="text-center">10</td>
                                    <td class="text-center">${(mockData.a2 / 10 * 100).toFixed(0)}%</td>
                                </tr>
                                <tr>
                                    <td>Test 1</td>
                                    <td class="text-center">${mockData.t1}</td>
                                    <td class="text-center">10</td>
                                    <td class="text-center">${(mockData.t1 / 10 * 100).toFixed(0)}%</td>
                                </tr>
                                <tr>
                                    <td>Test 2</td>
                                    <td class="text-center">${mockData.t2}</td>
                                    <td class="text-center">10</td>
                                    <td class="text-center">${(mockData.t2 / 10 * 100).toFixed(0)}%</td>
                                </tr>
                                <tr class="table-success">
                                    <td><strong>Total</strong></td>
                                    <td class="text-center"><strong>${mockData.total}</strong></td>
                                    <td class="text-center"><strong>40</strong></td>
                                    <td class="text-center"><strong>${(mockData.total / 40 * 100).toFixed(0)}%</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Audit & Moderation Controls Form -->
            <form action="approveCA.php" method="post" class="card shadow-sm border-0 bg-light p-3">
                <input type="hidden" name="csrf_token" value="${$('input[name=csrf_token]').first().val() || ''}">
                <input type="hidden" name="assessment_id" value="${mockData.id}">
                <input type="hidden" name="action" value="moderate">
                
                <h6 class="mb-3 text-purple font-weight-bold" style="font-size: 1rem;"><i class="fas fa-user-shield"></i> Exam & Results Moderation</h6>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold mb-1">Internal Moderation Status</label>
                        <select name="internal_status" class="form-select form-select-sm" required>
                            <option value="pending" ${mockData.internalStatus === 'pending' ? 'selected' : ''}>Pending Review</option>
                            <option value="approved" ${mockData.internalStatus === 'approved' ? 'selected' : ''}>Approved (Satisfactory)</option>
                            <option value="rejected" ${mockData.internalStatus === 'rejected' ? 'selected' : ''}>Rejected (Needs Correction)</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold mb-1">Internal Moderation Notes</label>
                        <textarea name="internal_notes" class="form-control form-control-sm" rows="1" placeholder="Review comments...">${mockData.internalNotes}</textarea>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label font-weight-bold mb-1">External Moderation Status</label>
                        <select name="external_status" class="form-select form-select-sm" required>
                            <option value="pending" ${mockData.externalStatus === 'pending' ? 'selected' : ''}>Pending Review</option>
                            <option value="approved" ${mockData.externalStatus === 'approved' ? 'selected' : ''}>Approved (Verified)</option>
                            <option value="rejected" ${mockData.externalStatus === 'rejected' ? 'selected' : ''}>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label font-weight-bold mb-1">External Moderator Name</label>
                        <input type="text" name="external_moderator" class="form-control form-control-sm" value="${mockData.externalModerator}" placeholder="e.g. TEVETA Moderator">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label font-weight-bold mb-1">External Moderation Notes</label>
                        <textarea name="external_notes" class="form-control form-control-sm" rows="1" placeholder="Review comments...">${mockData.externalNotes}</textarea>
                    </div>
                </div>
                
                <div class="text-end mt-2">
                    <button type="submit" class="btn btn-sm btn-purple"><i class="fas fa-save me-1"></i>Save Moderation & Update Status</button>
                </div>
            </form>
        `;
        
        $('#assessmentDetailsContent').html(detailsHtml);
        var modal = new bootstrap.Modal(document.getElementById('assessmentDetailsModal'));
        modal.show();
    });
    
    // Confirmation for approve buttons
    $(document).on('submit', '.approve-ca', function(e) {
        e.preventDefault();
        var approveForm = this;
        
        Swal.fire({
            title: 'Approve Assessment?',
            text: 'This will approve the continuous assessment score and make it final.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, approve it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#198754'
        }).then((result) => {
            if (result.isConfirmed) {
                approveForm.submit();
            }
        });
    });

    // Edit CA inline (modal via SweetAlert)
    $(document).on('click', '.edit-ca', function(e){
        e.preventDefault();
        const id = $(this).data('id');
        const a1 = $(this).data('a1');
        const a2 = $(this).data('a2');
        const t1 = $(this).data('t1');
        const t2 = $(this).data('t2');
        Swal.fire({
            title: 'Edit CA Scores',
            html: '<div class="row g-2 text-start">'
                +'<div class="col-6"><label>A1</label><input id="sw_a1" type="number" min="0" max="10" step="0.1" class="form-control" value="'+a1+'"></div>'
                +'<div class="col-6"><label>A2</label><input id="sw_a2" type="number" min="0" max="10" step="0.1" class="form-control" value="'+a2+'"></div>'
                +'<div class="col-6"><label>T1</label><input id="sw_t1" type="number" min="0" max="10" step="0.1" class="form-control" value="'+t1+'"></div>'
                +'<div class="col-6"><label>T2</label><input id="sw_t2" type="number" min="0" max="10" step="0.1" class="form-control" value="'+t2+'"></div>'
                +'</div>',
            showCancelButton: true,
            confirmButtonText: 'Save',
            preConfirm: () => ({
                A1: parseFloat(document.getElementById('sw_a1').value || '0'),
                A2: parseFloat(document.getElementById('sw_a2').value || '0'),
                T1: parseFloat(document.getElementById('sw_t1').value || '0'),
                T2: parseFloat(document.getElementById('sw_t2').value || '0')
            })
        }).then((res)=>{
            if (res.isConfirmed) {
                const fd = new FormData();
                fd.append('csrf_token', caCsrf);
                fd.append('id', id);
                fd.append('A1', res.value.A1);
                fd.append('A2', res.value.A2);
                fd.append('T1', res.value.T1);
                fd.append('T2', res.value.T2);
                fetch('api_ca_update.php', { method:'POST', body: fd })
                    .then(r=>r.json())
                    .then(d=>{ if (d.success) { Swal.fire('Saved','CA updated','success').then(()=>location.reload()); } else { Swal.fire('Error', d.error||'Update failed','error'); } })
                    .catch(()=> Swal.fire('Error','Network error','error'));
            }
        });
    });

    // Workflow: publish / return-for-correction (POST to approveCA.php)
    const caCsrf = <?php echo json_encode($csrfToken); ?>;
    function caPostDecision(id, action, reason) {
        const f = document.createElement('form');
        f.method = 'post';
        f.action = 'approveCA.php';
        const add = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
        add('csrf_token', caCsrf);
        add('assessment_id', id);
        add('action', action);
        if (reason != null) add('reason', reason);
        document.body.appendChild(f);
        f.submit();
    }
    $(document).on('click', '.publish-ca', function(){
        const id = $(this).data('id');
        Swal.fire({ title:'Publish result?', text:'Students will be able to see this result.', icon:'question', showCancelButton:true, confirmButtonText:'Publish', confirmButtonColor:'#198754' })
            .then((res)=>{ if (res.isConfirmed) caPostDecision(id, 'publish', null); });
    });
    $(document).on('click', '.reject-ca', function(){
        const id = $(this).data('id');
        Swal.fire({ title:'Return for correction', input:'textarea', inputLabel:'Reason', inputPlaceholder:'Explain what needs correcting…', showCancelButton:true, confirmButtonText:'Return', confirmButtonColor:'#fd7e14', inputValidator:(v)=> (!v || !v.trim()) ? 'A reason is required' : undefined })
            .then((res)=>{ if (res.isConfirmed) caPostDecision(id, 'reject', res.value); });
    });

    // Delete CA
    $(document).on('click', '.delete-ca', function(e){
        e.preventDefault();
        const id = $(this).data('id');
        Swal.fire({ title:'Delete record?', text:'This cannot be undone.', icon:'warning', showCancelButton:true, confirmButtonText:'Delete' })
            .then((res)=>{
                if (res.isConfirmed) {
                    const fd = new FormData(); fd.append('csrf_token', caCsrf); fd.append('id', id);
                    fetch('api_ca_delete.php', { method:'POST', body: fd })
                        .then(r=>r.json())
                        .then(d=>{ if (d.success) { Swal.fire('Deleted','Record removed','success').then(()=>location.reload()); } else { Swal.fire('Error', d.error||'Delete failed','error'); } })
                        .catch(()=> Swal.fire('Error','Network error','error'));
                }
            });
    });
});
</script>
<?php require "includes/footer.php"; ?>


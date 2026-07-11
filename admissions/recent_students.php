<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';

// Auth check
if (!checkSessionTimeout() || !isAdminAuthenticated()) {
    header("Location: /wucportal/staff_login.php");
    exit();
}

$page_title = "Recently Admitted Students";
require "includes/nav.php";
?>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <h1 class="dashboard-title"><i class="fas fa-user-clock me-2"></i>Recently Admitted Students</h1>
        <p class="text-muted">View the latest students admitted to the university.</p>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-primary">Latest Admissions</h5>
            <div class="d-flex gap-2">
                <a href="regNewStud.php" class="btn btn-sm btn-success"><i class="fas fa-plus me-2"></i>Register New</a>
                <a href="students.php" class="btn btn-sm btn-outline-secondary">View All Students</a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="recentTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Student ID</th>
                            <th>Name</th>
                            <th>Program</th>
                            <th>Intake</th>
                            <th>Mode</th>
                            <th>Admission Date</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query = "
                            SELECT
                                s.SID,
                                s.Fname,
                                s.Lname,
                                s.dte_adm,
                                s.created_at,
                                MAX(p.program_name) AS program_name,
                                MAX(sp.intake) AS intake,
                                MAX(sp.mode) AS mode,
                                GROUP_CONCAT(
                                    DISTINCT CONCAT(sc.course_code, ' - ', sc.course_name)
                                    ORDER BY sce.enrollment_date DESC
                                    SEPARATOR ', '
                                ) AS short_course_names,
                                SUBSTRING_INDEX(
                                    GROUP_CONCAT(sc.delivery_mode ORDER BY sce.enrollment_date DESC SEPARATOR ','),
                                    ',',
                                    1
                                ) AS short_course_mode,
                                MAX(sce.enrollment_date) AS short_enrollment_date
                            FROM students s
                            LEFT JOIN student_program sp
                                ON TRIM(UPPER(s.SID)) = TRIM(UPPER(sp.Sid))
                                AND COALESCE(sp.status, 'active') <> 'inactive'
                            LEFT JOIN programs p
                                ON TRIM(UPPER(sp.program_code)) = TRIM(UPPER(p.program_code))
                            LEFT JOIN short_course_enrollments sce
                                ON TRIM(UPPER(s.SID)) = TRIM(UPPER(sce.student_id))
                                AND sce.status IN ('enrolled', 'active', 'completed')
                            LEFT JOIN short_courses sc
                                ON sce.short_course_id = sc.id
                            WHERE COALESCE(s.status, 'active') <> 'inactive'
                            GROUP BY s.SID, s.Fname, s.Lname, s.dte_adm, s.created_at
                            ORDER BY COALESCE(s.dte_adm, MAX(sce.enrollment_date), s.created_at) DESC, s.SID DESC
                            LIMIT 50";
                        
                        $result = $db->query($query);
                        
                        if ($result && $result->num_rows > 0):
                            while ($row = $result->fetch_assoc()):
                                $name = htmlspecialchars($row['Fname'] . ' ' . $row['Lname']);
                                $academicProgram = trim((string)($row['program_name'] ?? ''));
                                $shortCourseNames = trim((string)($row['short_course_names'] ?? ''));
                                $isShortCourseOnly = $academicProgram === '' && $shortCourseNames !== '';
                                $program = htmlspecialchars($academicProgram !== '' ? $academicProgram : ($shortCourseNames !== '' ? $shortCourseNames : 'Not Assigned'));
                                $sid = htmlspecialchars($row['SID']);
                                $intake = htmlspecialchars($row['intake'] ?? ($isShortCourseOnly ? 'Short Course' : '-'));
                                $mode = htmlspecialchars($row['mode'] ?? ($row['short_course_mode'] ?? '-'));
                                $dateSource = $row['dte_adm'] ?: ($row['short_enrollment_date'] ?: ($row['created_at'] ?? null));
                                $date = $dateSource ? date('M d, Y', strtotime($dateSource)) : '-';
                                
                                if ($program === 'Not Assigned') {
                                    $program_badge = '<span class="badge bg-secondary">Unassigned</span>';
                                } elseif ($isShortCourseOnly) {
                                    $program_badge = '<span class="badge bg-success bg-opacity-10 text-success text-wrap" style="max-width: 250px;"><i class="fas fa-certificate me-1"></i>' . $program . '</span>';
                                } else {
                                    $program_badge = '<span class="badge bg-primary bg-opacity-10 text-primary text-wrap" style="max-width: 250px;">'. $program .'</span>';
                                }
                        ?>
                        <tr>
                            <td class="ps-4 fw-bold text-primary"><?= $sid ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm bg-light rounded-circle me-2 d-flex align-items-center justify-content-center" style="width:32px;height:32px">
                                        <i class="fas fa-user-graduate text-secondary"></i>
                                    </div>
                                    <span class="fw-medium"><?= $name ?></span>
                                </div>
                            </td>
                            <td><?= $program_badge ?></td>
                            <td class="text-muted"><?= $intake ?></td>
                            <td><span class="badge bg-light text-dark border"><?= $mode ?></span></td>
                            <td class="text-muted small"><?= $date ?></td>
                            <td class="text-end pe-4">
                                <a href="view_student.php?view=<?= $sid ?>" class="btn btn-sm btn-light text-primary border" title="View Profile">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php 
                            endwhile; 
                            $result->free();
                        else:
                        ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 opacity-50"></i>
                                <p>No students found.</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-light text-muted small text-center">
            Showing last 50 admissions. For older records, use the <a href="students.php">All Students</a> page.
        </div>
    </div>
</div>

<?php require "includes/footer.php"; ?>


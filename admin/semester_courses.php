<?php

require dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_period_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/course_availability_helpers.php';
error_reporting(0);

if(!empty($_POST)){
    if(isset($_POST["course_code"], $_POST["program_code"], $_POST["semester"])) {

        $course_code = trim($_POST["course_code"]);
        $program_code = trim($_POST["program_code"]);
        $semester = trim($_POST["semester"]);
        $credits = isset($_POST["credits"]) ? (int)$_POST["credits"] : 3;

        // Check if course already exists for this program (UNIQUE constraint on program_code + course_code)
        $check="SELECT * FROM program_courses WHERE course_code=? AND program_code=?";
        $check_stmt = $db->prepare($check);
        $check_stmt->bind_param("ss", $course_code, $program_code);

        if ($check_stmt->execute()) {
            $check_result = $check_stmt->get_result();
            $existing_course = $check_result->fetch_assoc();
        }

        if ($existing_course) {
            echo"<script>alert('Ooops! This course has already been added to this program')</script>";
            echo"<script>window.open('semester.php','_self')</script>";
        } else {
            if (!empty($course_code) && !empty($program_code)) {
                $year = max(1, (int)($_POST['year'] ?? 1));
                $semester = trim((string)($_POST['semester'] ?? ''));
                $periodSpecific = !empty($_POST['period_specific']);
                $deliveryPeriod = ($periodSpecific && $semester !== '') ? (int)$semester : null;

                if ($periodSpecific && ($deliveryPeriod === null || $deliveryPeriod < 1)) {
                    echo "<script>alert('Select a delivery period when limiting to one period only.')</script>";
                    echo "<script>window.open('semester.php','_self')</script>";
                    exit;
                }
                if ($periodSpecific) {
                    $alignment = wuc_validate_curriculum_period($db, $program_code, $year, (string)$deliveryPeriod);
                    if (!$alignment['ok']) {
                        echo "<script>alert(" . json_encode($alignment['reason']) . ")</script>";
                        echo "<script>window.open('semester.php','_self')</script>";
                        exit;
                    }
                }

                $result = wuc_insert_program_course_assignment(
                    $db,
                    $program_code,
                    $course_code,
                    $year,
                    $deliveryPeriod,
                    $periodSpecific
                );

                if ($result['ok']) {
                    echo "<script>alert('Course added successfully')</script>";
                    echo"<script>window.open('semester.php','_self')</script>";
                } else {
                    echo "<script>alert('Submission failed: " . addslashes($result['message']) . "')</script>";
                    echo"<script>window.open('semester.php','_self')</script>";
                }
            } else {
                echo "<script>alert('Please fill in all required fields!')</script>";
                echo"<script>window.open('semester.php','_self')</script>";
            }
        }
    }
}

// Function to display semester courses
function display_semester_courses() {
    global $db;

    $programCols = [];
    if ($programMeta = $db->query("SHOW COLUMNS FROM programs")) {
        while ($col = $programMeta->fetch_assoc()) {
            $programCols[strtolower((string)$col['Field'])] = (string)$col['Field'];
        }
        $programMeta->free();
    }
    if (isset($programCols['structure_type'])) {
        $studyModeExpr = wuc_program_period_mode_sql('p');
    } elseif (isset($programCols['period_mode'])) {
        $studyModeExpr = "COALESCE(NULLIF(p.`{$programCols['period_mode']}`, ''), 'semester')";
    } elseif (isset($programCols['period_type'])) {
        $studyModeExpr = "COALESCE(NULLIF(p.`{$programCols['period_type']}`, ''), 'semester')";
    } else {
        $studyModeExpr = "'semester'";
    }

    // Fetch all program courses with related information
    $sql = "SELECT pc.id, pc.program_code, pc.course_code, pc.semester, COALESCE(c.credits, 0) as credits,
                   pc.course_name as pc_course_name,
                   p.program_name,
                   {$studyModeExpr} as study_mode,
                   COALESCE(c.course_name, pc.course_name, pc.course_code) as course_name
            FROM program_courses pc
            LEFT JOIN programs p ON pc.program_code = p.program_code
            LEFT JOIN courses c ON pc.course_code = c.course_code
            ORDER BY p.program_name, pc.semester, course_name";

    $result = $db->query($sql);

    if (!$result) {
        echo '<div class="alert alert-danger">Error loading program courses: ' . $db->error . '</div>';
        return;
    }

    $semester_courses = [];
    while ($row = $result->fetch_assoc()) {
        // Ensure program_name is never null (fallback to program_code)
        $row['program_name'] = !empty($row['program_name']) ? $row['program_name'] : $row['program_code'];
        // Ensure study_mode has a fallback
        $row['study_mode'] = !empty($row['study_mode']) ? $row['study_mode'] : 'semester';
        // Use the best available course name
        $row['course_name'] = !empty($row['course_name']) ? $row['course_name'] :
                             (!empty($row['pc_course_name']) ? $row['pc_course_name'] : $row['course_code']);
        $semester_courses[] = $row;
    }

    if (empty($semester_courses)) {
        echo '<div class="empty-state-container">
                <div class="empty-state-content">
                    <div class="empty-state-icon mb-4">
                        <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 120px; height: 120px;">
                            <i class="fas fa-graduation-cap fa-4x text-primary"></i>
                        </div>
                    </div>
                    <h3 class="empty-state-title text-muted mb-3">No Program Courses Yet</h3>
                    <p class="empty-state-message text-muted mb-4" style="max-width: 500px; margin: 0 auto; line-height: 1.6;">
                        Get started by adding courses to different semesters or terms for your programs.
                        This will help you organize your academic curriculum effectively.
                    </p>
                    <div class="empty-state-actions">
                        <button type="button" class="btn btn-primary btn-lg me-3" id="getStartedBtn">
                            <i class="fas fa-plus me-2"></i>Add Your First Course
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="learnMoreBtn">
                            <i class="fas fa-info-circle me-2"></i>Learn More
                        </button>
                    </div>
                    <div class="empty-state-features mt-5">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="feature-item text-center">
                                    <div class="feature-icon mb-3">
                                        <i class="fas fa-calendar-alt fa-2x text-info"></i>
                                    </div>
                                    <h6 class="feature-title">Organize by Semester</h6>
                                    <p class="feature-description small text-muted">
                                        Assign courses to specific semesters for better academic planning
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="feature-item text-center">
                                    <div class="feature-icon mb-3">
                                        <i class="fas fa-book fa-2x text-success"></i>
                                    </div>
                                    <h6 class="feature-title">Track Credits</h6>
                                    <p class="feature-description small text-muted">
                                        Monitor credit distribution across programs and semesters
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="feature-item text-center">
                                    <div class="feature-icon mb-3">
                                        <i class="fas fa-search fa-2x text-warning"></i>
                                    </div>
                                    <h6 class="feature-title">Easy Management</h6>
                                    <p class="feature-description small text-muted">
                                        Search, filter, and manage courses with powerful tools
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
              </div>

              <style>
                .empty-state-container {
                    min-height: 500px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 3rem 1rem;
                }

                .empty-state-content {
                    text-align: center;
                    max-width: 800px;
                }

                .empty-state-icon {
                    animation: float 3s ease-in-out infinite;
                }

                @keyframes float {
                    0%, 100% { transform: translateY(0px); }
                    50% { transform: translateY(-10px); }
                }

                .empty-state-title {
                    font-size: 1.75rem;
                    font-weight: 600;
                }

                .empty-state-actions .btn {
                    border-radius: 25px;
                    padding: 0.75rem 2rem;
                    font-weight: 500;
                    transition: all 0.3s ease;
                }

                .empty-state-actions .btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                }

                .feature-item {
                    padding: 1.5rem;
                    border-radius: 10px;
                    transition: all 0.3s ease;
                }

                .feature-item:hover {
                    background-color: rgba(0,123,255,0.05);
                    transform: translateY(-5px);
                }

                .feature-icon {
                    display: inline-block;
                    width: 60px;
                    height: 60px;
                    background: rgba(0,123,255,0.1);
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0 auto;
                }

                .feature-title {
                    font-weight: 600;
                    color: #495057;
                    margin-bottom: 0.5rem;
                }

                .feature-description {
                    line-height: 1.5;
                }

                /* Responsive adjustments */
                @media (max-width: 768px) {
                    .empty-state-container {
                        min-height: 400px;
                        padding: 2rem 1rem;
                    }

                    .empty-state-features .col-md-4 {
                        margin-bottom: 2rem;
                    }

                    .empty-state-actions .btn {
                        display: block;
                        width: 100%;
                        margin-bottom: 1rem;
                    }

                    .empty-state-actions .btn:last-child {
                        margin-bottom: 0;
                    }
                }
              </style>

              <script>
                // Handle empty state buttons
                document.addEventListener("DOMContentLoaded", function() {
                    const getStartedBtn = document.getElementById("getStartedBtn");
                    const learnMoreBtn = document.getElementById("learnMoreBtn");

                    if (getStartedBtn) {
                        getStartedBtn.addEventListener("click", function() {
                            // Trigger the add new course modal
                            const addBtn = document.getElementById("addNewBtn");
                            if (addBtn) {
                                addBtn.click();
                            }
                        });
                    }

                    if (learnMoreBtn) {
                        learnMoreBtn.addEventListener("click", function() {
                            // Show information about semester courses
                            Swal.fire({
                                title: "About Semester Courses",
                                html: `
                                    <div class="text-start">
                                        <p><strong>Program Courses</strong> allow you to:</p>
                                        <ul class="text-start">
                                            <li>Organize courses by semesters or terms</li>
                                            <li>Assign courses to specific programs</li>
                                            <li>Track credit distribution</li>
                                            <li>Manage course prerequisites</li>
                                            <li>Generate curriculum reports</li>
                                        </ul>
                                        <p class="mt-3">Start by adding courses for each program. Semester-based programs use Semester 1 & 2, while term-based programs use Term 1, 2, & 3.</p>
                                    </div>
                                `,
                                icon: "info",
                                confirmButtonText: "Got it!"
                            });
                        });
                    }
                });
              </script>';
        return;
    }

    // Render courses as a data table
    echo '<div class="table-responsive" id="coursesContainer">
            <table class="table table-hover align-middle mb-0" id="coursesTable">
                <thead class="table-light">
                    <tr class="bg-light">
                        <th style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAllCheckbox" title="Select all">
                        </th>
                        <th class="sortable-header" data-sort="course_code" style="cursor:pointer;">
                            <i class="fas fa-hashtag me-1 text-muted"></i>Code
                            <i class="fas fa-sort ms-1 text-muted sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="course_name" style="cursor:pointer;">
                            <i class="fas fa-book me-1 text-muted"></i>Course Name
                            <i class="fas fa-sort ms-1 text-muted sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="program_name" style="cursor:pointer;">
                            <i class="fas fa-graduation-cap me-1 text-muted"></i>Program
                            <i class="fas fa-sort ms-1 text-muted sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="semester" style="cursor:pointer; width: 140px;">
                            <i class="fas fa-calendar me-1 text-muted"></i>Period
                            <i class="fas fa-sort ms-1 text-muted sort-icon"></i>
                        </th>
                        <th class="sortable-header text-center" data-sort="credits" style="cursor:pointer; width: 90px;">
                            <i class="fas fa-star me-1 text-muted"></i>Credits
                            <i class="fas fa-sort ms-1 text-muted sort-icon"></i>
                        </th>
                        <th class="text-center" style="width: 100px;">Mode</th>
                        <th class="text-center" style="width: 130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>';

    foreach ($semester_courses as $index => $course) {
        $studyMode = $course['study_mode'] ?? 'semester';
        $periodNum = $course['semester'];

        // Period label and badge color
        if ($studyMode === 'term') {
            $periodLabel = 'Term ' . $periodNum;
            $periodBadgeColors = [1 => 'bg-purple', 2 => 'bg-orange', 3 => 'bg-teal'];
            $periodBadge = $periodBadgeColors[$periodNum] ?? 'bg-secondary';
        } else {
            $periodLabel = 'Semester ' . $periodNum;
            $periodBadgeColors = [1 => 'bg-info', 2 => 'bg-success'];
            $periodBadge = $periodBadgeColors[$periodNum] ?? 'bg-primary';
        }

        $modeBadge = $studyMode === 'term'
            ? '<span class="badge rounded-pill" style="background-color:#6f42c1;font-size:0.7rem;">Term</span>'
            : '<span class="badge bg-info rounded-pill" style="font-size:0.7rem;">Semester</span>';

        $rowClass = $index % 2 === 0 ? '' : 'bg-light';

        echo '<tr class="course-row ' . $rowClass . '"
                  data-id="' . $course['id'] . '"
                  data-course-code="' . htmlspecialchars($course['course_code']) . '"
                  data-course-name="' . htmlspecialchars($course['course_name']) . '"
                  data-program-name="' . htmlspecialchars($course['program_name']) . '"
                  data-program-code="' . htmlspecialchars($course['program_code']) . '"
                  data-semester="' . $periodNum . '"
                  data-credits="' . $course['credits'] . '"
                  data-study-mode="' . htmlspecialchars($studyMode) . '"
                  data-period-label="' . htmlspecialchars($periodLabel) . '">
                <td>
                    <input type="checkbox" class="form-check-input course-select-checkbox"
                           value="' . $course['id'] . '"
                           data-course-id="' . $course['id'] . '">
                </td>
                <td>
                    <span class="badge bg-primary font-monospace">' . htmlspecialchars($course['course_code']) . '</span>
                </td>
                <td>
                    <div class="fw-semibold text-dark">' . htmlspecialchars($course['course_name']) . '</div>
                </td>
                <td>
                    <div class="text-truncate" style="max-width: 200px;" title="' . htmlspecialchars($course['program_name']) . '">
                        ' . htmlspecialchars($course['program_name']) . '
                    </div>
                </td>
                <td>
                    <span class="badge ' . $periodBadge . ' text-white">' . $periodLabel . '</span>
                </td>
                <td class="text-center">
                    <span class="badge bg-secondary rounded-pill">' . $course['credits'] . '</span>
                </td>
                <td class="text-center">' . $modeBadge . '</td>
                <td class="text-center">
                    <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-outline-info btn-sm edit-course-btn"
                                data-id="' . $course['id'] . '"
                                data-program="' . htmlspecialchars($course['program_name']) . '"
                                data-program-code="' . htmlspecialchars($course['program_code']) . '"
                                data-course="' . htmlspecialchars($course['course_name']) . '"
                                data-course-code="' . htmlspecialchars($course['course_code']) . '"
                                data-semester="' . $course['semester'] . '"
                                data-credits="' . $course['credits'] . '"
                                data-study-mode="' . htmlspecialchars($studyMode) . '"
                                title="Edit Course">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-outline-primary btn-sm view-course-btn"
                                data-id="' . $course['id'] . '"
                                data-program="' . htmlspecialchars($course['program_name']) . '"
                                data-course="' . htmlspecialchars($course['course_name']) . '"
                                data-course-code="' . htmlspecialchars($course['course_code']) . '"
                                data-semester="' . $course['semester'] . '"
                                data-credits="' . $course['credits'] . '"
                                data-study-mode="' . htmlspecialchars($studyMode) . '"
                                title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm delete-course-btn"
                                data-id="' . $course['id'] . '"
                                data-course="' . htmlspecialchars($course['course_name']) . '"
                                title="Remove Course">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </td>
              </tr>';
    }

    echo '      </tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-3 px-2">
              <div class="text-muted small" id="tableInfo">
                  Showing ' . count($semester_courses) . ' course(s) &middot;
                  Total Credits: ' . array_sum(array_column($semester_courses, 'credits')) . '
              </div>
          </div>';
}
?>

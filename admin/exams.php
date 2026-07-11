<?php
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/assessment_weighting_helpers.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';

if (function_exists('canAccessExams') && !canAccessExams()) {
    $_SESSION['errorMssg'] = 'Access denied. You do not have permission to access exams.';
    header('Location: index.php');
    exit();
}

require "includes/header.php";
echo '<link rel="stylesheet" href="css/exams.css">';
?>
<?php

// Proper error handling for production
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1);
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/error.log');

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}
$csrf_token = $_SESSION['csrf_token'];

// Initialize variables
$student = null;
$transcript_data = [];
$error_msg = "";
$search_sid = "";
$program_type = "semester"; // Default
$display_period = "";

if (!function_exists('admin_exams_detect_column')) {
    /**
     * Detect the first existing column from a candidate list.
     */
    function admin_exams_detect_column(mysqli $db, string $table, array $candidates): ?string {
        foreach ($candidates as $col) {
            $colEsc = $db->real_escape_string($col);
            $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$colEsc}'");
            if ($res && $res->num_rows > 0) {
                $res->free();
                return $col;
            }
            if ($res) {
                $res->free();
            }
        }
        return null;
    }
}

if (!function_exists('admin_exams_program_department_join')) {
    /**
     * Build a schema-safe join between programs and departments.
     */
    function admin_exams_program_department_join(mysqli $db): string {
        $progFk = admin_exams_detect_column($db, 'programs', ['department_id', 'deptId', 'department_code']);
        if (!$progFk) {
            return '';
        }

        $deptCandidatesByProgFk = [
            'department_id' => ['department_id', 'id', 'DeptID'],
            'deptId' => ['deptId', 'department_id', 'department_code'],
            'department_code' => ['department_code', 'deptId', 'department_id'],
        ];

        foreach ($deptCandidatesByProgFk[$progFk] as $deptKey) {
            if (admin_exams_detect_column($db, 'departments', [$deptKey])) {
                return "LEFT JOIN departments d ON p.`{$progFk}` = d.`{$deptKey}`";
            }
        }

        return '';
    }
}

if (!function_exists('admin_exams_school_name_expr')) {
    /**
     * Build a safe school_name expression that only references existing columns.
     */
    function admin_exams_school_name_expr(mysqli $db, bool $hasDepartmentJoin): string {
        if (!$hasDepartmentJoin) {
            return "'N/A'";
        }

        $nameCol = admin_exams_detect_column($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
        $facultyCol = admin_exams_detect_column($db, 'departments', ['faculty']);

        $parts = [];
        if ($nameCol) {
            $parts[] = "NULLIF(TRIM(d.`{$nameCol}`), '')";
        }
        if ($facultyCol) {
            $parts[] = "NULLIF(TRIM(d.`{$facultyCol}`), '')";
        }
        $parts[] = "'N/A'";

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (empty($submitted_token) || !hash_equals($csrf_token, $submitted_token)) {
        $error_msg = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $search_sid = trim($_POST['StudentNumber'] ?? '');
        $program_type = $_POST['programType'] ?? 'semester';
    
    // Construct Display Period String
    if ($program_type === 'semester') {
        $sem = $_POST['semester'] ?? 'all';
        $year = $_POST['academicYear'] ?? date('Y');
        $display_period = ($sem === 'all' ? "All End-of-Semester Assessments" : "End of Semester $sem") . ", $year";
    } elseif ($program_type === 'termly') {
        $year = $_POST['academicYearTermly'] ?? 'all';
        $display_period = ($year === 'all' ? "All End-of-Term Assessments" : "End of Term Assessments, $year");
    } elseif ($program_type === 'short_course') {
        $year = $_POST['academicYearShort'] ?? 'all';
        $display_period = ($year === 'all' ? "All Short Course Tests" : "Short Course Tests, $year");
    } else {
        $display_period = "Combined Academic Years (Full History)";
    }

    if (!empty($search_sid)) {
        // 1. Fetch Student Info with schema-safe department/faculty join
        $res_info = null;
        try {
            $deptJoinSql = admin_exams_program_department_join($db);
            $schoolNameExpr = admin_exams_school_name_expr($db, $deptJoinSql !== '');
            $sql_info = "SELECT s.*, p.program_name, p.program_code,
                               {$schoolNameExpr} AS school_name
                         FROM students s
                         LEFT JOIN student_program sp ON s.Sid = sp.Sid
                         LEFT JOIN programs p ON sp.program_code = p.program_code
                         {$deptJoinSql}
                         WHERE s.Sid = ?";

            $stmt_info = $db->prepare($sql_info);
            $stmt_info->bind_param("s", $search_sid);
            $stmt_info->execute();
            $res_info = $stmt_info->get_result();
        } catch (Throwable $e) {
            error_log("admin/exams.php student info query failed: " . $e->getMessage());
            $error_msg = "Unable to fetch student information right now. Please try again.";
        }
        
        if ($res_info && $res_info->num_rows > 0) {
            $student = $res_info->fetch_assoc();
            // Normalize SID
            if (!isset($student['SID'])) {
                if (isset($student['Sid'])) $student['SID'] = $student['Sid'];
                elseif (isset($student['sid'])) $student['SID'] = $student['sid'];
            }

            // 2. Build marks query with dynamic filters
            $sql_marks = "SELECT 
                            e.Year, e.semester, e.Course_Code, c.course_name, 
                            COALESCE(e.Exam_marks, e.Total_marks) as Exam_Mark, sa.Total_CA as CA_Mark,
                            " . (admin_exams_detect_column($db, 'exams', ['programme_type']) ? "e.programme_type" : "'yearly' AS programme_type") . ",
                            " . (admin_exams_detect_column($db, 'exams', ['assessment_date']) ? "e.assessment_date" : "NULL AS assessment_date") . ",
                            c.credits as Credits 
                          FROM exams e
                          JOIN courses c ON e.Course_Code = c.course_code
                          LEFT JOIN semester_assessment sa ON (
                                e.Sid = sa.Sid 
                                AND e.Course_Code = sa.Course_Code 
                                AND e.Year = sa.Year 
                                AND e.semester = sa.semester
                          )
                          WHERE e.Sid = ? ";

            $params = ["s"];
            $param_values = [$search_sid];
            $additional_conditions = [];

            // Add filters based on selected scope
            $hasProgrammeType = admin_exams_detect_column($db, 'exams', ['programme_type']) !== null;
            if ($hasProgrammeType && $program_type === 'short_course') {
                $additional_conditions[] = "e.programme_type = 'short_course'";
            } elseif ($hasProgrammeType && in_array($program_type, ['semester', 'termly'], true)) {
                $additional_conditions[] = "COALESCE(e.programme_type, 'yearly') <> 'short_course'";
            }

            if ($program_type === 'semester' && isset($_POST['semester']) && $_POST['semester'] !== 'all') {
                $additional_conditions[] = "e.semester = ?";
                $params[0] .= "i";
                $param_values[] = (int)$_POST['semester'];
            }
            
            if ($program_type === 'semester' && !empty($_POST['academicYear'])) {
                $additional_conditions[] = "e.Year = ?";
                $params[0] .= "s";
                $param_values[] = $_POST['academicYear'];
            }
            
            if ($program_type === 'termly' && !empty($_POST['academicYearTermly']) && $_POST['academicYearTermly'] !== 'all') {
                $additional_conditions[] = "e.Year = ?";
                $params[0] .= "s";
                $param_values[] = $_POST['academicYearTermly'];
            }

            if ($program_type === 'short_course' && !empty($_POST['academicYearShort']) && $_POST['academicYearShort'] !== 'all') {
                $additional_conditions[] = "e.Year = ?";
                $params[0] .= "s";
                $param_values[] = $_POST['academicYearShort'];
            }
            
            if (!empty($additional_conditions)) {
                $sql_marks .= " AND " . implode(" AND ", $additional_conditions);
            }
            
            $sql_marks .= " ORDER BY e.Year ASC, e.semester ASC, e.Course_Code ASC";

            try {
                $stmt_marks = $db->prepare($sql_marks);
                if (!$stmt_marks) {
                    throw new RuntimeException("Prepare failed: " . $db->error);
                }

                // bind_param requires references when using dynamic argument lists
                $bind_args = [];
                $bind_args[] = $params[0];
                foreach ($param_values as $key => $val) {
                    $bind_args[] = &$param_values[$key];
                }
                if (!call_user_func_array([$stmt_marks, 'bind_param'], $bind_args)) {
                    throw new RuntimeException("bind_param failed: " . $stmt_marks->error);
                }

                if (!$stmt_marks->execute()) {
                    throw new RuntimeException("Execute failed: " . $stmt_marks->error);
                }

                $res_marks = $stmt_marks->get_result();
                while ($row = $res_marks->fetch_assoc()) {
                    $result = getFinalResult($db, $search_sid, $row['Course_Code'], $row['CA_Mark'], $row['Exam_Mark']);

                    $row['Final_Mark'] = $result['final_mark'] ?? 0;
                    $row['Weighting_Label'] = $result['weighting_label'] ?? '';
                    $row['Grade'] = $result['grade_letter'] ?? 'E';
                    $row['Classification'] = $result['classification'] ?? 'Fail';
                    $row['Status'] = ($row['Grade'] !== 'E' && $row['Grade'] !== 'F') ? 'PASS' : 'FAIL';

                    $transcript_data[$row['Year']][$row['semester']][] = $row;
                }
            } catch (Throwable $e) {
                error_log("admin/exams.php marks query failed: " . $e->getMessage());
                $error_msg = "Unable to load transcript records right now. Please try again.";
            }
        } else {
            $error_msg = "Student not found with ID: " . htmlspecialchars($search_sid);
        }
    }
    } // Close CSRF validation else block
}

// Universal PHP Grading Logic (Database Driven with Fallback)
function getFinalResult($db, $sid, $course_code, $ca_score, $exam_score) {
    $ca_score = floatval($ca_score);
    $exam_score = floatval($exam_score);
    $policy = assessment_weighting_policy_for_student($db, (string)$sid);

    $config = [
        'ca_weight' => $policy['is_weighted'] ? $policy['ca_weight'] : 100,
        'exam_weight' => $policy['is_weighted'] ? $policy['exam_weight'] : 100,
        'pass_mark' => 50,
    ];

    try {
        $stmt = $db->prepare("SELECT ca_weight, exam_weight, pass_mark FROM course_configs WHERE course_code = ?");
        if ($stmt && !$policy['is_weighted']) {
            $stmt->bind_param("s", $course_code);
            $stmt->execute();
            $res = $stmt->get_result();
            
            if ($res->num_rows > 0) {
                $config = $res->fetch_assoc();
            }
        }
    } catch (Exception $e) {
        // Use defaults
    }

    // Calculate weighted total
    $final_mark = $policy['is_weighted']
        ? assessment_weighting_total($db, (string)$sid, $ca_score, $exam_score)
        : (($ca_score * ($config['ca_weight']/100)) + ($exam_score * ($config['exam_weight']/100)));

    // Determine grade from database
    try {
        $stmt = $db->prepare("SELECT classification, grade_letter FROM grading_scales 
                            WHERE ? BETWEEN min_score AND max_score AND scale_name = 'Generic'");
        if ($stmt) {
            $rounded_mark = round($final_mark);
            $stmt->bind_param("i", $rounded_mark);
            $stmt->execute();
            $grade_res = $stmt->get_result();
            
            if ($grade_res->num_rows > 0) {
                $grade = $grade_res->fetch_assoc();
                $grade['final_mark'] = round($final_mark, 2);
                $grade['weighting_label'] = $policy['label'];
                return $grade;
            }
        }
    } catch (Exception $e) {
        // Fallback to default grading
    }

    // Fallback grading scale
    $total = $final_mark;
    $base = ['final_mark' => round($final_mark, 2), 'weighting_label' => $policy['label']];
    if ($total >= 90) return $base + ['grade_letter' => 'A+', 'classification' => 'Distinction'];
    if ($total >= 80) return $base + ['grade_letter' => 'A', 'classification' => 'Distinction'];
    if ($total >= 75) return $base + ['grade_letter' => 'B+', 'classification' => 'Merit'];
    if ($total >= 70) return $base + ['grade_letter' => 'B', 'classification' => 'Merit'];
    if ($total >= 65) return $base + ['grade_letter' => 'B-', 'classification' => 'Credit'];
    if ($total >= 60) return $base + ['grade_letter' => 'C+', 'classification' => 'Credit'];
    if ($total >= 50) return $base + ['grade_letter' => 'C', 'classification' => 'Pass'];
    if ($total >= 45) return $base + ['grade_letter' => 'D', 'classification' => 'Bare Pass'];
    
    return $base + ['grade_letter' => 'E', 'classification' => 'Fail'];
}
?>

<script>
    function toggleProgramType() {
        var programType = document.getElementById('programType').value;
        var semesterDiv = document.getElementById('semesterOptions');
        var termDiv = document.getElementById('termOptions');
        var shortDiv = document.getElementById('shortCourseOptions');
        var combinedDiv = document.getElementById('combinedOption');
        
        semesterDiv.style.display = 'none';
        termDiv.style.display = 'none';
        if (shortDiv) shortDiv.style.display = 'none';
        combinedDiv.style.display = 'none';
        
        if (programType === 'semester') {
            semesterDiv.style.display = 'table-row';
        } else if (programType === 'termly') {
            termDiv.style.display = 'table-row';
        } else if (programType === 'short_course' && shortDiv) {
            shortDiv.style.display = 'table-row';
        } else if (programType === 'combined') {
            combinedDiv.style.display = 'table-row';
        }
    }
    
    // Form validation and initialization
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize program type display
        toggleProgramType();
        
        // Bootstrap form validation
        var forms = document.querySelectorAll('.needs-validation');
        Array.prototype.slice.call(forms).forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    });
</script>

<div class="container-fluid px-4 portal-dashboard">
    
    <!-- Search Form Section -->
    <div id="printNone" class="no-print mb-5">
        <!-- Page Header -->
        <div class="page-header mb-4 mt-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h5 class="page-title mb-0"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Transcript Generation</h5>
                    <p class="page-subtitle mb-0">Prepare and print official academic transcripts for students</p>
                </div>
                <div class="header-actions">
                    <a href="students_by_admin.php" class="btn btn-outline-secondary shadow-sm">
                        <i class="fas fa-users me-1"></i>Registry
                    </a>
                </div>
            </div>
        </div>

        <div class="search-card">
            <div class="text-center mb-4">
                <div class="stat-icon bg-purple-soft text-purple mx-auto mb-3" style="width: 70px; height: 70px; font-size: 1.5rem;">
                    <i class="fas fa-search"></i>
                </div>
                <h5 class="fw-bold mb-1">Generate Transcript</h5>
                <p class="text-muted small">Enter student details and select the generation period</p>
            </div>

            <form action="" method="post" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="row g-4 justify-content-center">
                    <div class="col-md-5">
                        <label class="form-label fw-bold small text-muted text-uppercase">Student Number</label>
                        <div class="input-group input-group-lg shadow-sm rounded-10">
                            <span class="input-group-text border-end-0"><i class="fas fa-id-card"></i></span>
                            <input name='StudentNumber' id='StudentNumber' class="form-control form-control-lg border-start-0" 
                                   type="text" placeholder="e.g. 20261001" value="<?php echo htmlspecialchars($search_sid); ?>" 
                                   required pattern="[A-Za-z0-9\/-]{3,}">
                        </div>
                        <div class="invalid-feedback">Enter a valid student number (letters, numbers, -, /).</div>
                    </div>
                    
                    <div class="col-md-5">
                        <label class="form-label fw-bold small text-muted text-uppercase">Generation Scope</label>
                        <div class="input-group input-group-lg shadow-sm rounded-10">
                            <span class="input-group-text border-end-0"><i class="fas fa-layer-group"></i></span>
                            <select name='programType' id='programType' class="form-select form-select-lg border-start-0" onchange="toggleProgramType()">
                                <option value='semester' <?php echo $program_type=='semester' ? 'selected' : ''; ?>>End of Semester</option>
                                <option value='termly' <?php echo $program_type=='termly' ? 'selected' : ''; ?>>End of Term</option>
                                <option value='short_course' <?php echo $program_type=='short_course' ? 'selected' : ''; ?>>Short Course Test</option>
                                <option value='combined' <?php echo $program_type=='combined' ? 'selected' : ''; ?>>Combined History</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-lg-10">
                        <table class="table table-hover align-middle details-table mb-0">
                            <tr id="semesterOptions" style="display: none;">
                                <td width="25%" class="align-middle fw-bold small text-muted text-uppercase">Period Details:</td>
                                <td>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <select name='semester' class="form-select">
                                                <option value='all'>All Semesters</option>
                                                <option value='1' <?php echo ($_POST['semester'] ?? '') == '1' ? 'selected' : ''; ?>>Semester 1</option>
                                                <option value='2' <?php echo ($_POST['semester'] ?? '') == '2' ? 'selected' : ''; ?>>Semester 2</option>
                                                <option value='3' <?php echo ($_POST['semester'] ?? '') == '3' ? 'selected' : ''; ?>>Semester 3</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <span class="input-group-text small">Year</span>
                                                <select name='academicYear' class="form-select">
                                                    <?php 
                                                    $current_year = date('Y');
                                                    for($y=$current_year; $y>=2020; $y--): 
                                                        $selected = (($_POST['academicYear'] ?? $current_year) == $y) ? 'selected' : '';
                                                    ?>
                                                        <option value='<?php echo $y; ?>' <?php echo $selected; ?>>
                                                            <?php echo $y; ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            
                            <tr id="termOptions" style="display: none;">
                                <td class="align-middle fw-bold small text-muted text-uppercase">Selected Year:</td>
                                <td>
                                    <select name='academicYearTermly' class="form-select">
                                        <option value='all'>All Academic Years</option>
                                        <?php 
                                        for($y=$current_year; $y>=2020; $y--): 
                                            $selected = (($_POST['academicYearTermly'] ?? '') == $y) ? 'selected' : '';
                                        ?>
                                            <option value='<?php echo $y; ?>' <?php echo $selected; ?>>
                                                <?php echo $y; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </td>
                            </tr>

                            <tr id="shortCourseOptions" style="display: none;">
                                <td class="align-middle fw-bold small text-muted text-uppercase">Test Year:</td>
                                <td>
                                    <select name='academicYearShort' class="form-select">
                                        <option value='all'>All Test Years</option>
                                        <?php
                                        for($y=$current_year; $y>=2020; $y--):
                                            $selected = (($_POST['academicYearShort'] ?? '') == $y) ? 'selected' : '';
                                        ?>
                                            <option value='<?php echo $y; ?>' <?php echo $selected; ?>>
                                                <?php echo $y; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr id="combinedOption" style="display: none;">
                                <td class="align-middle fw-bold small text-muted text-uppercase">Scope Note:</td>
                                <td>
                                    <div class="alert alert-info border-0 shadow-none mb-0 py-2 px-3 small">
                                        <i class="fas fa-info-circle me-2"></i>Full academic history will be compiled into a single document.
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="col-lg-10 mt-4 text-center">
                        <button name='Search' type='submit' class="btn btn-primary btn-lg px-5 shadow-sm rounded-pill">
                            <i class="fas fa-file-invoice me-2"></i>Generate Official Transcript
                        </button>
                    </div>
                </div>
            </form>
            
            <?php if ($error_msg): ?>
                <div class="alert alert-danger mt-4 border-0 shadow-sm">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Printable Transcript -->
    <?php if ($student && !empty($transcript_data)): ?>
    <div id="divPrint" class="exams-fade-in">
        <div class="text-end mb-3 no-print">
             <button onclick="window.print()" class="btn btn-dark btn-sm">
                <i class="fas fa-print me-2"></i>Print Official Transcript
            </button>
            <button onclick="downloadPDF()" class="btn btn-success ms-2">
                <i class="fas fa-download me-2"></i>Download PDF
            </button>
        </div>

        <div id="transcriptContent" class="paper shadow-lg">
             <!-- Header -->
            <table width='100%' class="table table-hover align-middle header-table mb-4">
                <tr>
                    <td colspan='3' class="text-center border-0 p-3">
                        <img src='../images/itc_logo.png' style='height: 100px;' alt='ITC Logo'/>
                        <div class="mt-2 text-uppercase" style="font-family: 'Merriweather', serif;">
                            <h3 class="fw-bold mb-0 text-nowrap" style="color:#003366">Industrial Training Centre</h3>
                            <div class="fs-5 text-muted fw-bold">Official Transcript</div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td colspan='3' class="p-0">
                         <div class="row g-0 border border-dark p-3">
                            <div class="col-md-7">
                                <table class="table table-hover align-middle w-100 info-table">
                                    <tr><td width="120" class="fw-bold">Name:</td><td><?php echo htmlspecialchars(($student['Fname'] ?? '') . ' ' . ($student['Lname'] ?? '')); ?></td></tr>
                                    <tr><td class="fw-bold">Student No:</td><td><?php echo htmlspecialchars($student['SID'] ?? ''); ?></td></tr>
                                    <tr><td class="fw-bold">Program:</td><td><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></td></tr>
                                    <tr><td class="fw-bold">School:</td><td><?php echo htmlspecialchars($student['school_name'] ?? 'N/A'); ?></td></tr>
                                    <tr><td class="fw-bold">Academic Period:</td><td><?php echo htmlspecialchars($display_period); ?></td></tr>
                                </table>
                            </div>
                            <div class="col-md-5 text-end small fw-bold text-secondary">
                                INDUSTRIAL TRAINING CENTRE<br>
                                2457 Main St, Lusaka, Zambia<br>
                                info@itc.edu.zm
                            </div>
                         </div>
                    </td>
                </tr>
            </table>
            
            <!-- Results Listing -->
            <?php foreach ($transcript_data as $year => $semesters): ?>
                <?php foreach ($semesters as $semester => $courses): 
                    $fail_count = 0;
                    foreach ($courses as $c) {
                        if ($c['Status'] !== 'PASS') {
                            $fail_count++;
                        }
                    }

                    $semester_comment = "Proceed";
                    $comment_class = "fw-bold align-middle bg-white";

                    if ($fail_count == 0) {
                        $semester_comment = "Proceed";
                    } elseif ($fail_count <= 2) {
                        $semester_comment = "Proceed and Repeat";
                        $comment_class .= " text-warning"; 
                    } else {
                        $semester_comment = "Repeat";
                        $comment_class .= " text-danger";
                    }

                    $first_row = true;
                    $row_span = count($courses);
                ?>
                
                <div class="semester-block mb-4">
                    <div class="p-2 fw-bold text-uppercase border border-dark border-bottom-0" style="background-color:#FEE6CA; font-family: 'Merriweather', serif;">
                         ACADEMIC YEAR <?php echo $year; ?> - SEMESTER <?php echo $semester; ?>
                    </div>
                    <table class="table table-hover align-middle border-dark mb-0 transcript-results-table">
                        <thead class="table-light">
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Grade</th>
                                <th class="text-center">Comment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $course): ?>
                            <tr>
                                <td class="text-nowrap"><?php echo htmlspecialchars($course['Course_Code']); ?></td>
                                <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                <td class="fw-bold text-center"><?php echo $course['Grade']; ?></td>
                                <?php if ($first_row): ?>
                                    <td class="text-center <?php echo $comment_class; ?>" rowspan="<?php echo $row_span; ?>">
                                        <?php echo $semester_comment; ?>
                                    </td>
                                    <?php $first_row = false; ?>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
             
             <!-- Signatures -->
             <div class="signatures mt-5 pt-5">
                <table width="100%">
                    <tr>
                        <td width="50%" class="text-center align-bottom">
                            <div class="border-bottom border-dark mb-2 w-50 mx-auto" style="min-width: 150px;"></div>
                            <strong>REGISTRAR</strong>
                        </td>
                        <td width="50%" class="text-center align-bottom">
                            <div class="border-bottom border-dark mb-2 w-50 mx-auto" style="min-width: 150px;"></div>
                            <strong>DEPUTY VICE CHANCELLOR</strong>
                        </td>
                    </tr>
                </table>
             </div>
             
             <!-- Footer Note -->
             <div class="mt-4 pt-3 border-top text-center small text-muted">
                <i>This is an official document. Any alterations render it invalid.</i>
             </div>
        </div>
    </div>
    
    <script>
    function downloadPDF() {
        // You can implement PDF generation here using jsPDF or window.print()
        // For now, just trigger print dialog
        window.print();
    }
    </script>
    
    <?php elseif ($student && empty($transcript_data)): ?>
        <div class="alert alert-info mt-4">
            <i class="fas fa-info-circle me-2"></i>Student found but no academic records available for the selected period.
        </div>
    <?php endif; ?>

</div>

<?php require_once "includes/footer.php"; ?>


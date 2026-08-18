<?php
/**
 * Consolidated short-course enrollment actions.
 *
 * Single implementation shared by the admin and admissions AJAX endpoints.
 * Each endpoint performs its own authentication and CSRF check, then delegates
 * the actual work here via sc_run_enrollment_action(). This replaces the two
 * near-identical (and previously divergent) copies of the switch.
 *
 * Supported actions: search_students, enroll_student, create_enroll,
 * get_enrollments, update_enrollment, remove_enrollment.
 */

require_once __DIR__ . '/short_course_db.php';
require_once __DIR__ . '/student_id_generator.php';
// Shared credential creator (strictly create-if-missing): a short-course
// student must be able to log in the moment they are enrolled.
require_once __DIR__ . '/../admissions/includes/registration_handlers.php';

if (!function_exists('sc_action_course_code')) {
    /** Resolve a short course's course_code from its numeric id, or null. */
    function sc_action_course_code(mysqli $db, int $courseId): ?string
    {
        if ($courseId <= 0) {
            return null;
        }
        if ($stmt = $db->prepare("SELECT course_code FROM short_courses WHERE id = ? LIMIT 1")) {
            $stmt->bind_param('i', $courseId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && $row['course_code'] !== '') {
                return (string)$row['course_code'];
            }
        }
        return null;
    }
}

if (!function_exists('sc_action_current_academic_year')) {
    /** Current academic year from portal_settings, falling back to the year. */
    function sc_action_current_academic_year(mysqli $db): string
    {
        $ay = date('Y');
        if ($stmt = $db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = 'current_academic_year' LIMIT 1")) {
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && $res->num_rows) {
                    $val = (string)$res->fetch_assoc()['setting_value'];
                    if ($val !== '') { $ay = $val; }
                }
            }
            $stmt->close();
        }
        return $ay;
    }
}

if (!function_exists('sc_run_enrollment_action')) {
    function sc_run_enrollment_action(mysqli $db, string $action, string $staffId): void
    {
        switch ($action) {

            // ─── Search students for enrollment ────────────────────────────
            case 'search_students':
                $q = trim($_GET['q'] ?? '');
                $courseId = (int)($_GET['course_id'] ?? 0);
                if (strlen($q) < 2) {
                    echo json_encode(['results' => []]);
                    return;
                }
                $like = "%{$q}%";
                $stmt = $db->prepare("
                    SELECT s.SID, s.Fname, s.Lname, s.mobile, s.email
                    FROM students s
                    WHERE (s.SID LIKE ? OR s.Fname LIKE ? OR s.Lname LIKE ? OR s.nrc_pass LIKE ?)
                      AND s.SID COLLATE utf8mb4_unicode_ci NOT IN (
                          SELECT student_id COLLATE utf8mb4_unicode_ci FROM short_course_enrollments WHERE short_course_id = ?
                      )
                    LIMIT 15
                ");
                $stmt->bind_param('ssssi', $like, $like, $like, $like, $courseId);
                $stmt->execute();
                $res = $stmt->get_result();
                $results = [];
                while ($r = $res->fetch_assoc()) {
                    $results[] = $r;
                }
                $stmt->close();
                echo json_encode(['results' => $results]);
                return;

            // ─── Enroll existing student ───────────────────────────────────
            case 'enroll_student':
                $courseId  = (int)($_POST['course_id'] ?? 0);
                $studentId = trim($_POST['student_id'] ?? '');
                $notes     = trim($_POST['notes'] ?? '');

                if (!$courseId || !$studentId) {
                    echo json_encode(['success' => false, 'message' => 'Course and student are required.']);
                    return;
                }

                $result = sc_enroll_student_locked($db, $courseId, $studentId, $staffId, $notes);
                if (!empty($result['success'])) {
                    // Ensure login exists for the enrolled student (create-if-missing).
                    if ($cred = $db->prepare('SELECT nrc_pass, email FROM students WHERE SID = ? LIMIT 1')) {
                        $cred->bind_param('s', $studentId);
                        $cred->execute();
                        $credRow = $cred->get_result()->fetch_assoc();
                        $cred->close();
                        if ($credRow) {
                            admissionsEnsureStudentLogin($db, $studentId, (string)($credRow['nrc_pass'] ?? ''), $credRow['email'] ?? null);
                        }
                    }
                }
                echo json_encode($result);
                return;

            // ─── Create new student and enroll ─────────────────────────────
            case 'create_enroll':
                $courseId = (int)($_POST['course_id'] ?? 0);
                $fname   = trim($_POST['fname'] ?? '');
                $lname   = trim($_POST['lname'] ?? '');
                $sex     = in_array($_POST['sex'] ?? '', ['M', 'F']) ? $_POST['sex'] : 'M';
                $nrc     = trim($_POST['nrc_pass'] ?? '');
                $mobile  = trim($_POST['mobile'] ?? '');
                $email   = trim($_POST['email'] ?? '');
                $country = trim($_POST['country'] ?? 'Zambia');
                $dob     = !empty($_POST['dob']) ? $_POST['dob'] : null;
                $notes   = trim($_POST['notes'] ?? '');

                if (!$courseId || !$fname || !$lname) {
                    echo json_encode(['success' => false, 'message' => 'Course, first name, and last name are required.']);
                    return;
                }

                // Check NRC duplicate
                if ($nrc !== '') {
                    $dupCheck = $db->prepare("SELECT SID FROM students WHERE nrc_pass = ? LIMIT 1");
                    $dupCheck->bind_param('s', $nrc);
                    $dupCheck->execute();
                    $dupCheck->store_result();
                    if ($dupCheck->num_rows > 0) {
                        $dupCheck->bind_result($existingSid);
                        $dupCheck->fetch();
                        $dupCheck->close();
                        echo json_encode(['success' => false, 'message' => "NRC already exists for student $existingSid. Use 'Enroll Existing Student' instead."]);
                        return;
                    }
                    $dupCheck->close();
                }

                // The unified ITC generator derives the number from the NRC, so a
                // usable NRC is mandatory. Fail with a friendly message instead of
                // letting the generator's exception bubble up.
                if (wuc_extract_nrc6($nrc) === null) {
                    echo json_encode(['success' => false, 'message' => 'A valid NRC/Passport number (at least 6 digits) is required to generate the student number.']);
                    return;
                }

                // Short-course students use the SCA programme code with the
                // current year and the last six NRC digits.
                $SID = wuc_make_student_number($db, 'SCA', $nrc, (int)date('Y'));

                $db->begin_transaction();
                try {
                    // 1. Insert student (schema-flexible)
                    sc_insert($db, 'students', [
                        'SID'             => $SID,
                        'Fname'           => $fname,
                        'Lname'           => $lname,
                        'sex'             => $sex,
                        'nrc_pass'        => $nrc,
                        'mobile'          => $mobile,
                        'email'           => $email,
                        'country'         => $country,
                        'dob'             => $dob,
                        'status'          => 'active',
                        'dte_adm'         => date('Y-m-d H:i:s'),
                        'enrollment_date' => date('Y-m-d H:i:s'),
                    ]);

                    // 2. Create login account via the shared credential creator —
                    //    initial password = NRC (consistent with every other
                    //    registration path), forced change on first login.
                    $defaultPw = $nrc;
                    admissionsEnsureStudentLogin($db, $SID, $nrc, $email !== '' ? $email : null);
                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollback();
                    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                    return;
                }

                // 3. Capacity-safe enroll (own transaction — must run after student commit)
                $enrollResult = sc_enroll_student_locked($db, $courseId, $SID, $staffId, $notes);
                if (empty($enrollResult['success'])) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Student created (ID: ' . $SID . ') but enrollment failed: '
                            . ($enrollResult['message'] ?? 'unknown error')
                            . '. Use Enroll Existing Student to finish.',
                        'student_id' => $SID,
                        'default_password' => $defaultPw,
                    ]);
                    return;
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Student created (ID: $SID) and enrolled. Initial portal password = their NRC; they must change it on first login.",
                    'student_id' => $SID,
                    'default_password' => $defaultPw,
                ]);
                return;

            // ─── Get enrollments for a course ──────────────────────────────
            case 'get_enrollments':
                $courseId = (int)($_GET['course_id'] ?? 0);
                if (!$courseId) {
                    echo json_encode(['enrollments' => []]);
                    return;
                }
                $stmt = $db->prepare("
                    SELECT e.id, e.student_id, s.Fname, s.Lname, s.mobile, s.email, s.sex,
                           e.enrollment_date, e.completion_date, e.status, e.certificate_issued, e.notes
                    FROM short_course_enrollments e
                    JOIN students s ON e.student_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci
                    WHERE e.short_course_id = ?
                    ORDER BY e.enrollment_date DESC
                ");
                $stmt->bind_param('i', $courseId);
                $stmt->execute();
                $res = $stmt->get_result();
                $enrollments = [];
                while ($r = $res->fetch_assoc()) {
                    $enrollments[] = $r;
                }
                $stmt->close();
                echo json_encode(['enrollments' => $enrollments, 'count' => count($enrollments)]);
                return;

            // ─── Update enrollment status ──────────────────────────────────
            case 'update_enrollment':
                $enrollId = (int)($_POST['enrollment_id'] ?? 0);
                $status   = in_array($_POST['status'] ?? '', ['enrolled', 'active', 'completed', 'withdrawn', 'expired'])
                            ? $_POST['status'] : null;
                $certIssued = isset($_POST['certificate_issued']) ? (int)$_POST['certificate_issued'] : null;
                $completionDate = !empty($_POST['completion_date']) ? $_POST['completion_date'] : null;

                if (!$enrollId) {
                    echo json_encode(['success' => false, 'message' => 'Enrollment ID required.']);
                    return;
                }

                $updates = [];
                $params  = [];
                $types   = '';

                if ($status !== null) {
                    $updates[] = 'status = ?';
                    $params[]  = $status;
                    $types    .= 's';
                }
                if ($certIssued !== null) {
                    $updates[] = 'certificate_issued = ?';
                    $params[]  = $certIssued;
                    $types    .= 'i';
                }
                if ($completionDate !== null) {
                    $updates[] = 'completion_date = ?';
                    $params[]  = $completionDate;
                    $types    .= 's';
                } elseif ($status === 'completed') {
                    $updates[] = 'completion_date = CURDATE()';
                }

                if (empty($updates)) {
                    echo json_encode(['success' => false, 'message' => 'Nothing to update.']);
                    return;
                }

                $sql = "UPDATE short_course_enrollments SET " . implode(', ', $updates) . " WHERE id = ?";
                $types .= 'i';
                $params[] = $enrollId;

                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $ok = $stmt->execute();
                $stmt->close();

                echo json_encode(['success' => $ok, 'message' => $ok ? 'Enrollment updated.' : 'Update failed.']);
                return;

            // ─── Remove enrollment ─────────────────────────────────────────
            case 'remove_enrollment':
                $enrollId = (int)($_POST['enrollment_id'] ?? 0);
                if (!$enrollId) {
                    echo json_encode(['success' => false, 'message' => 'Enrollment ID required.']);
                    return;
                }
                $stmt = $db->prepare("DELETE FROM short_course_enrollments WHERE id = ?");
                $stmt->bind_param('i', $enrollId);
                $ok = $stmt->execute();
                $stmt->close();
                echo json_encode(['success' => $ok, 'message' => $ok ? 'Enrollment removed.' : 'Removal failed.']);
                return;

            // ─── Lecturer assignment ───────────────────────────────────────
            // Short courses live outside the mainstream courses/program_courses
            // model, so admin/assign_course.php cannot reach them. The short-
            // course CA workflow (sc_ca_staff_courses / sc_ca_staff_owns) keys
            // on course_lecturer(course_code, staff_id, status), so the lecturer
            // link is created here — directly against the short course's code.

            case 'get_lecturers':
                $courseId = (int)($_GET['course_id'] ?? 0);
                $code = sc_action_course_code($db, $courseId);
                if ($code === null) {
                    echo json_encode(['success' => false, 'message' => 'Course not found.', 'assigned' => [], 'available' => []]);
                    return;
                }
                // Currently assigned lecturers for this short course.
                $assigned = [];
                if ($stmt = $db->prepare("
                    SELECT cl.id, cl.staff_id,
                           TRIM(CONCAT(COALESCE(s.title,''),' ',COALESCE(s.Fname,''),' ',COALESCE(s.Lname,''))) AS name,
                           cl.status
                    FROM course_lecturer cl
                    LEFT JOIN staff s ON s.staff_id = cl.staff_id
                    WHERE cl.course_code = ?
                    ORDER BY name, cl.staff_id
                ")) {
                    $stmt->bind_param('s', $code);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) { $assigned[] = $r; }
                    $stmt->close();
                }
                // Candidate lecturers: staff holding a position whose name
                // contains "lecturer" (same rule as admin/assign_course.php).
                $available = [];
                $lq = "SELECT DISTINCT s.staff_id,
                              TRIM(CONCAT(COALESCE(s.title,''),' ',COALESCE(s.Fname,''),' ',COALESCE(s.Lname,''))) AS name
                       FROM staff s
                       INNER JOIN staff_positions sp ON s.staff_id = sp.staff_id
                       INNER JOIN positions p ON sp.PosID = p.PosID
                       WHERE LOWER(TRIM(p.PosName)) LIKE '%lecturer%'
                       ORDER BY name, s.staff_id";
                if ($lres = $db->query($lq)) {
                    while ($r = $lres->fetch_assoc()) { $available[] = $r; }
                    $lres->free();
                }
                echo json_encode(['success' => true, 'course_code' => $code, 'assigned' => $assigned, 'available' => $available]);
                return;

            case 'assign_lecturer':
                $courseId   = (int)($_POST['course_id'] ?? 0);
                $lecturerId = trim($_POST['staff_id'] ?? '');
                $result = sc_assign_lecturer_to_short_course($db, $courseId, $lecturerId);
                echo json_encode($result);
                return;

            case 'unassign_lecturer':
                $assignId = (int)($_POST['assignment_id'] ?? 0);
                $courseId = (int)($_POST['course_id'] ?? 0);
                if (!$assignId) {
                    echo json_encode(['success' => false, 'message' => 'Assignment ID required.']);
                    return;
                }
                // Scope the delete to this short course's code so a stray id can
                // never remove a mainstream (programme) assignment.
                $code = sc_action_course_code($db, $courseId);
                if ($code !== null) {
                    $stmt = $db->prepare("DELETE FROM course_lecturer WHERE id = ? AND course_code = ?");
                    $stmt->bind_param('is', $assignId, $code);
                } else {
                    $stmt = $db->prepare("DELETE FROM course_lecturer WHERE id = ?");
                    $stmt->bind_param('i', $assignId);
                }
                $ok = $stmt->execute();
                $affected = $db->affected_rows;
                $stmt->close();
                echo json_encode(['success' => $ok && $affected > 0, 'message' => ($ok && $affected > 0) ? 'Lecturer unassigned.' : 'Assignment not found.']);
                return;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
                return;
        }
    }
}

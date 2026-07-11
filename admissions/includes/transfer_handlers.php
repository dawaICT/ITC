<?php
/**
 * AJAX Handlers for Transfer Student Registration
 */

/**
 * Generate Student ID for Transfer Students
 * Uses the shared generator from includes/student_id_generator.php
 */
require_once __DIR__ . '/../../includes/student_id_generator.php';

// Shared upload rules (allowed types, 5MB cap, real-content MIME check).
require_once __DIR__ . '/../../includes/upload_validator.php';

// Opaque URL identifiers (wuc_encode_id). The AJAX path in regOldStud.php
// exits BEFORE nav.php loads security.php, so require it explicitly here.
require_once __DIR__ . '/../../includes/security.php';

// Shared admissions helpers — provides admissionsEnsureStudentLogin() so a
// transfer student gets portal credentials the moment their record is created.
require_once __DIR__ . '/registration_handlers.php';

// Alias so existing calls to generateTransferStudentId still work
if (!function_exists('generateTransferStudentId')) {
    function generateTransferStudentId(mysqli $db, string $program_code, string $semester, string $academic_year, string $nrc = ''): string {
        return generateStudentId($db, $program_code, $semester, $academic_year, $nrc);
    }
}

/**
 * Fetch Transfer Students
 */
function handleGetTransferStudents($db) {
    try {
        $query = "SELECT s.*, 
                         (SELECT COUNT(*) FROM student_program sp WHERE sp.Sid = s.SID) as program_count 
                  FROM students s 
                  WHERE s.is_transfer = 1 
                  ORDER BY s.dte_adm DESC 
                  LIMIT 50";
        
        $result = $db->query($query);
        if (!$result) throw new Exception($db->error);

        $students = [];
        while ($row = $result->fetch_assoc()) {
            // Opaque, tamper-proof token for action links so the raw SID never
            // appears in a URL. The SID stays in the payload only for display.
            $row['token'] = wuc_encode_id($row['SID'] ?? '', 'student');
            $students[] = $row;
        }

        return ['success' => true, 'data' => $students];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Handle Transfer Student Registration
 */
function handleTransferRegistration($db, $input, $files) {
    try {
        // 1. Validation
        $required_fields = ['full_name', 'nrc_pass', 'dob', 'sex', 'mobile', 'h_addre', 'school', 'transfer_credits'];
        $missing = [];
        foreach ($required_fields as $field) {
            if (empty($input[$field])) $missing[] = $field;
        }
        if (!empty($missing)) throw new Exception("Missing fields: " . implode(', ', $missing));

        // Sanitize
        $title = trim($input["title"] ?? 'Mr');
        $full_name = trim($input["full_name"]);
        
        // Split name
        $names = preg_split('/\s+/', $full_name, 2);
        $Fname = $names[0];
        $Lname = $names[1] ?? '';
        
        $sex = trim($input["sex"]);
        $nrc_pass = trim($input["nrc_pass"]);
        $country = trim($input["country"] ?? 'Zambia');
        $dob = trim($input["dob"]);
        $mobile = trim($input["mobile"]);
        $email = trim($input["email"] ?? '');
        // FIX: students.status is the ACCOUNT status the login flow checks
        // (must be 'active'/'enabled' to sign in). The old code wrote the
        // marital status ('Single') here, which locked every transfer student
        // out of the portal with "account is not active".
        $status = 'active';
        $h_addre = trim($input["h_addre"]);
        $p_addre = trim($input["p_addre"] ?? '');
        $sponsor = trim($input["sponsor"] ?? 'Self');
        $next_kin = trim($input["next_kin"] ?? '');
        $next_kin_mobile = trim($input["next_kin_mobile"] ?? '');
        $relat = trim($input["relat"] ?? 'Other');
        $school = trim($input["school"]);
        $academic_year = trim($input["academic_year"] ?? date('Y'));
        
        $is_transfer = 1;
        $transfer_from = trim($input["transfer_from"] ?? $school); // Default to school if empty
        $transfer_credits = (int)($input["transfer_credits"]);
        $transfer_program = trim($input["transfer_program"] ?? '');
        $transfer_letter = trim($input["transfer_letter"] ?? '');

        // 2. Duplicate Check
        $check = $db->prepare("SELECT SID FROM students WHERE nrc_pass = ? LIMIT 1");
        $check->bind_param("s", $nrc_pass);
        $check->execute();
        if ($check->get_result()->num_rows > 0) throw new Exception("Duplicate NRC/Passport found.");
        $check->close();

        // 3. File Uploads
        $upload_dir = dirname(__DIR__, 2) . '/admissions/uploads/';
        $profile_dir = dirname(__DIR__, 2) . '/admissions/uploads/profile/';
        
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
        if (!file_exists($profile_dir)) mkdir($profile_dir, 0755, true);

        $profile_image = 'default_profile.png';
        $results_file = 'pending_results.pdf';
        $nrc_file = 'pending_nrc.pdf';
        
        $is_bulk = isset($input['bulk_import']) && $input['bulk_import'] == '1';

        if (!$is_bulk) {
            // Shared validation: enforces allowed types, 5MB cap and real MIME content.
            $handle_upload = function($key, $dir, $prefix, $kind) use ($files) {
                if (isset($files[$key]) && ($files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    try {
                        $ext = wucValidateUpload($files[$key], $kind);
                    } catch (RuntimeException $e) {
                        return false;
                    }
                    $name = $prefix . '_' . md5(uniqid(rand(), true)) . '.' . $ext;
                    if (move_uploaded_file($files[$key]['tmp_name'], $dir . $name)) return $name;
                }
                return false;
            };

            if ($p = $handle_upload('profile_image', $profile_dir, 'p', 'image')) $profile_image = $p;
            if ($r = $handle_upload('results', $upload_dir, 'r', 'document')) $results_file = $r;
            if ($n = $handle_upload('nrc_file', $upload_dir, 'n', 'document')) $nrc_file = $n;

            // Require files for single reg
            if (!$p || !$r || !$n) throw new Exception("File upload failed. Valid PDF/Image (max 5MB) required for all 3 documents.");
        }

        // 4. Generate ID
        $program_code = "TRANSFER";
        $semester = date('m') >= 7 ? 2 : 1;
        $SID = generateTransferStudentId($db, $program_code, (string)$semester, $academic_year, $nrc_pass);

        // 5. Insert
        // FIX (FATAL): the old INSERT named columns that do not exist in the live
        // students table (grade, dte1, dte2, transfer_program, transfer_letter),
        // so every transfer registration died with an Unknown-column error.
        // The column list below matches the real schema exactly.
        $insert_query = "INSERT INTO students (
            SID, title, Fname, Lname, sex, nrc_pass, country, dob,
            mobile, email, status, h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, school,
            profile_image, results, nrc_file, dte_adm, is_transfer, transfer_from, transfer_credits, academic_year
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)";

        $stmt = $db->prepare($insert_query);
        $stmt->bind_param("sssssssssssssssssssssisis",
            $SID, $title, $Fname, $Lname, $sex, $nrc_pass, $country, $dob,
            $mobile, $email, $status, $h_addre, $p_addre, $sponsor, $next_kin, $next_kin_mobile, $relat, $school,
            $profile_image, $results_file, $nrc_file,
            $is_transfer, $transfer_from, $transfer_credits, $academic_year
        );

        if ($stmt->execute()) {
            // The destination programme (transfer_program) has no column on the
            // students table; it is applied at the admission step. Keep a trace
            // so the information the form collected is not lost silently.
            if ($transfer_program !== '' || $transfer_letter !== '') {
                error_log("Transfer registration $SID: requested program='$transfer_program', letter ref='$transfer_letter'");
            }

            // FIX (CRITICAL): create portal credentials immediately (initial
            // password = NRC, forced change on first login). Previously transfer
            // students had no student_login row and could never sign in.
            admissionsEnsureStudentLogin($db, $SID, $nrc_pass, $email !== '' ? $email : null);

            return [
                'success' => true,
                'message' => "Transfer student registered successfully!",
                // Opaque token for the post-registration redirect; the raw SID
                // is no longer placed in the browser URL.
                'token' => wuc_encode_id($SID, 'student')
            ];
        } else {
            throw new Exception("Database error: " . $stmt->error);
        }

    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>

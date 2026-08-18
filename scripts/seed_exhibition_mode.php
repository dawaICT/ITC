<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';
require_once __DIR__ . '/../includes/helpers/student_provisioning.php';
require_once __DIR__ . '/../includes/portal_access.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!in_array('--apply', $argv, true)) {
    echo "Dry run only. This will seed eight EXH-* exhibition identities and linked journey data.\n";
    echo "Run with --apply to commit. Existing non-EXH records are never changed.\n";
    exit(0);
}

const EXHIBITION_PASSWORD = 'Exhibition@2026';
const EXHIBITION_PROGRAM = 'ICT-001';
const EXHIBITION_YEAR = 2026;

$staffAccounts = [
    ['EXH-ADMIN-001', 'Amina', 'Phiri', 'systems_admin', 'Systems Administrator'],
    ['EXH-ADM-001', 'Brian', 'Mulenga', 'admission_officer', 'Admissions Officer'],
    ['EXH-REG-001', 'Chanda', 'Banda', 'registrar', 'Registrar'],
    ['EXH-LEC-001', 'Daniel', 'Mwansa', 'lecturer', 'Lecturer'],
    ['EXH-EMP-001', 'Esther', 'Zulu', 'employer', 'Employer Partner'],
];

function exh_user_id(mysqli $db, string $username): int
{
    $stmt = $db->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['user_id'] ?? 0);
}

function exh_seed_student(mysqli $db, string $sid, string $first, string $last, string $email, string $nrc): int
{
    $stmt = $db->prepare(
        "INSERT INTO students
            (SID,title,Fname,Lname,sex,dob,country,nrc_pass,mobile,email,status,academic_year,program,intake,mode,year,dte_adm)
         VALUES (?, 'Ms.', ?, ?, 'F', '2001-04-12', 'Zambia', ?, '0970000001', ?, 'active', '2026', ?, 'January', 'Full Time', 1, CURDATE())
         ON DUPLICATE KEY UPDATE Fname=VALUES(Fname),Lname=VALUES(Lname),email=VALUES(email),nrc_pass=VALUES(nrc_pass),
             status='active',academic_year='2026',program=VALUES(program),intake='January',mode='Full Time',year=1"
    );
    $program = EXHIBITION_PROGRAM;
    $stmt->bind_param('ssssss', $sid, $first, $last, $nrc, $email, $program);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        "INSERT INTO student_program
            (Sid,program_code,intake,mode,startYear,endYear,status,registration_date,academic_year,term,year_of_study,semester)
         VALUES (?, ?, 'January', 'Full Time', 2026, 2026, 'active', CURDATE(), '2026', 'Semester 1', 1, 1)
         ON DUPLICATE KEY UPDATE status='active',academic_year='2026',year_of_study=1,semester=1"
    );
    $stmt->bind_param('ss', $sid, $program);
    $stmt->execute();
    $stmt->close();

    $account = wuc_provision_student_account($db, $sid, [
        'plain_password' => EXHIBITION_PASSWORD,
        'only_create_login' => false,
        'assigned_by' => 'exhibition_seed',
    ]);
    if (!$account['ok']) {
        throw new RuntimeException("Unable to provision {$sid}: " . implode('; ', $account['messages']));
    }
    $readyLogin = $db->prepare('UPDATE student_login SET must_change_password = 0 WHERE Sid = ?');
    $readyLogin->bind_param('s', $sid);
    $readyLogin->execute();
    $readyLogin->close();

    $courses = $db->prepare(
        "SELECT course_code, year, semester FROM program_courses
         WHERE program_code = ? AND year = 1 ORDER BY semester, course_code"
    );
    $courses->bind_param('s', $program);
    $courses->execute();
    $courseRows = $courses->get_result()->fetch_all(MYSQLI_ASSOC);
    $courses->close();
    if (!$courseRows) {
        throw new RuntimeException('ICT-001 has no mapped courses; exhibition student was not seeded.');
    }
    $registration = $db->prepare(
        "INSERT INTO course_registration
            (Sid,course_code,semester,Year,academic_year,status,tuition_total,amount_paid,is_active)
         VALUES (?, ?, ?, ?, 2026, 'active', 8500.00, 5000.00, 1)
         ON DUPLICATE KEY UPDATE status='active',tuition_total=8500.00,amount_paid=5000.00,is_active=1"
    );
    foreach ($courseRows as $course) {
        $semester = (int)$course['semester'];
        $year = (int)$course['year'];
        $code = (string)$course['course_code'];
        $registration->bind_param('ssii', $sid, $code, $semester, $year);
        $registration->execute();
    }
    $registration->close();
    wuc_grant_user_portal_access($db, (int)$account['user_id'], ['academic', 'elearning'], 'exhibition_seed');
    return (int)$account['user_id'];
}

try {
    $programCheck = $db->prepare("SELECT 1 FROM programs WHERE program_code = ? AND is_active = 1 LIMIT 1");
    $program = EXHIBITION_PROGRAM;
    $programCheck->bind_param('s', $program);
    $programCheck->execute();
    $validProgram = $programCheck->get_result()->num_rows === 1;
    $programCheck->close();
    if (!$validProgram) {
        throw new RuntimeException('Required live programme ICT-001 is missing or inactive.');
    }

    $db->begin_transaction();
    $db->query("INSERT INTO portal_settings (setting_key,setting_value) VALUES ('exhibition_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $hash = password_hash(EXHIBITION_PASSWORD, PASSWORD_DEFAULT);

    foreach ($staffAccounts as [$staffId, $first, $last, $role, $qualification]) {
        $email = strtolower($staffId) . '@exhibition.test';
        $mobile = '097' . str_pad((string)(crc32($staffId) % 10000000), 7, '0', STR_PAD_LEFT);
        $nrc = 'EXH/' . substr(hash('sha256', $staffId), 0, 10);
        $stmt = $db->prepare(
            "INSERT INTO staff (staff_id,title,Fname,Lname,sex,nrc_pass,mobile,email,country,qualification,password,role,status,failed_attempts,lockout_until)
             VALUES (?, 'Ms.', ?, ?, 'F', ?, ?, ?, 'Zambia', ?, ?, ?, 'active', 0, NULL)
             ON DUPLICATE KEY UPDATE Fname=VALUES(Fname),Lname=VALUES(Lname),mobile=VALUES(mobile),email=VALUES(email),
                 qualification=VALUES(qualification),password=VALUES(password),role=VALUES(role),status='active',failed_attempts=0,lockout_until=NULL"
        );
        $stmt->bind_param('sssssssss', $staffId, $first, $last, $nrc, $mobile, $email, $qualification, $hash, $role);
        $stmt->execute();
        $stmt->close();
        $result = wuc_provision_staff_account($db, $staffId, $role, EXHIBITION_PASSWORD, 'exhibition_seed');
        if (!$result['ok']) {
            throw new RuntimeException("Unable to provision {$staffId}: " . implode('; ', $result['messages']));
        }
    }

    $studentUserId = exh_seed_student($db, 'EXH-STU-001', 'Faith', 'Tembo', 'exh-student@exhibition.test', 'EXH/STU/001');
    $alumniUserId = exh_seed_student($db, 'EXH-ALU-001', 'Grace', 'Sakala', 'exh-alumni@exhibition.test', 'EXH/ALU/001');
    wuc_grant_user_portal_access($db, $alumniUserId, ['alumni'], 'exhibition_seed');

    $db->query("DELETE FROM semester_assessment WHERE Sid IN ('EXH-STU-001','EXH-ALU-001')");
    $assessment = $db->prepare(
        "INSERT INTO semester_assessment
            (Sid,Course_Code,A1,A2,A3,T1,T2,Exam,Total_CA,status,approved_by,approved_at,published_by,published_at,
             internal_moderation_status,external_moderation_status,semester,Year,program_type,posted_by,submitted_by,submitted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Published', 'EXH-REG-001', NOW(), 'EXH-REG-001', NOW(),
                 'approved','approved','1','2026','semester','EXH-LEC-001','EXH-LEC-001',NOW())"
    );
    $marks = [
        ['DCSE-101', 78, 82, 75, 76, 81, 79, 79],
        ['DCSE-103', 69, 74, 72, 70, 73, 76, 73],
        ['DCSE-104', 84, 80, 86, 82, 85, 88, 85],
    ];
    foreach (['EXH-STU-001', 'EXH-ALU-001'] as $sid) {
        foreach ($marks as [$course, $a1, $a2, $a3, $t1, $t2, $exam, $total]) {
            $assessment->bind_param('ssddddddd', $sid, $course, $a1, $a2, $a3, $t1, $t2, $exam, $total);
            $assessment->execute();
        }
    }
    $assessment->close();

    $db->query("DELETE FROM attendance_logs WHERE Sid IN ('EXH-STU-001','EXH-ALU-001')");
    $db->query("INSERT INTO attendance_logs (Sid,course_code,timestamp,source) VALUES
        ('EXH-STU-001','DCSE-101','2026-07-13 08:00:00','manual'),
        ('EXH-STU-001','DCSE-101','2026-07-15 08:00:00','biometric'),
        ('EXH-STU-001','DCSE-103','2026-07-16 10:00:00','app'),
        ('EXH-STU-001','DCSE-104','2026-07-17 09:00:00','manual')");

    // Exhibition student photo (local SVG — uploads/ is runtime storage).
    $profileDir = dirname(__DIR__) . '/uploads/profile';
    if (!is_dir($profileDir)) {
        @mkdir($profileDir, 0775, true);
    }
    $profileSvg = $profileDir . '/EXH-STU-001.svg';
    if (!is_file($profileSvg)) {
        file_put_contents(
            $profileSvg,
            '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160">'
            . '<circle cx="80" cy="80" r="80" fill="#6f42c1"/>'
            . '<text x="80" y="96" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="56" font-weight="700" fill="#ffffff">FT</text>'
            . '</svg>'
        );
    }
    $db->query("UPDATE students SET profile_image='EXH-STU-001.svg' WHERE SID='EXH-STU-001'");

    // Seed one eLearning material for DCSE-101 (module → content → version → lesson_notes).
    $materialFile = 'EXH_DCSE-101_network_intro.pdf';
    $elearningDir = dirname(__DIR__) . '/uploads/elearning';
    $legacyDir = dirname(__DIR__) . '/lecturers/uploads/materials';
    if (!is_dir($elearningDir)) {
        @mkdir($elearningDir, 0775, true);
    }
    if (!is_dir($legacyDir)) {
        @mkdir($legacyDir, 0775, true);
    }
    $materialAbs = $elearningDir . '/' . $materialFile;
    if (!is_file($materialAbs) || filesize($materialAbs) < 1000) {
        $sourceCandidates = glob($legacyDir . '/*.pdf') ?: [];
        $source = null;
        foreach ($sourceCandidates as $candidate) {
            if (filesize($candidate) > 1000) {
                $source = $candidate;
                break;
            }
        }
        if ($source === null) {
            // Minimal valid PDF so demo download still works offline.
            $minimalPdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
            file_put_contents($materialAbs, $minimalPdf);
        } else {
            copy($source, $materialAbs);
        }
    }
    $legacyAbs = $legacyDir . '/' . $materialFile;
    if (!is_file($legacyAbs)) {
        @copy($materialAbs, $legacyAbs);
    }
    $materialSize = (int)@filesize($materialAbs);
    $materialChecksum = hash_file('sha256', $materialAbs) ?: str_repeat('0', 64);
    $materialRelative = 'uploads/elearning/' . $materialFile;

    // Remove prior exhibition material rows (idempotent reseed).
    $priorNotes = $db->prepare("SELECT el_content_id FROM lesson_notes WHERE notes = ? OR topic LIKE 'Exhibition:%'");
    $priorNotes->bind_param('s', $materialFile);
    $priorNotes->execute();
    $priorContentIds = [];
    foreach ($priorNotes->get_result()->fetch_all(MYSQLI_ASSOC) as $priorRow) {
        $cid = (int)($priorRow['el_content_id'] ?? 0);
        if ($cid > 0) {
            $priorContentIds[] = $cid;
        }
    }
    $priorNotes->close();
    $db->query("DELETE FROM lesson_notes WHERE notes = '" . $db->real_escape_string($materialFile) . "' OR topic LIKE 'Exhibition:%'");
    foreach (array_unique($priorContentIds) as $contentId) {
        $delVersions = $db->prepare('DELETE FROM el_content_versions WHERE content_id = ?');
        $delVersions->bind_param('i', $contentId);
        $delVersions->execute();
        $delVersions->close();
        $delContent = $db->prepare('DELETE FROM el_contents WHERE id = ?');
        $delContent->bind_param('i', $contentId);
        $delContent->execute();
        $delContent->close();
    }

    $moduleTitle = 'Exhibition Course Materials';
    $moduleId = 0;
    $findModule = $db->prepare("SELECT id FROM el_course_modules WHERE course_code='DCSE-101' AND title=? LIMIT 1");
    $findModule->bind_param('s', $moduleTitle);
    $findModule->execute();
    $moduleRow = $findModule->get_result()->fetch_assoc();
    $findModule->close();
    if ($moduleRow) {
        $moduleId = (int)$moduleRow['id'];
    } else {
        $createModule = $db->prepare(
            "INSERT INTO el_course_modules (course_code, title, description, position, created_by)
             VALUES ('DCSE-101', ?, 'Exhibition Mode sample learning materials for DCSE-101.', 0, 'EXH-LEC-001')"
        );
        $createModule->bind_param('s', $moduleTitle);
        $createModule->execute();
        $moduleId = (int)$createModule->insert_id;
        $createModule->close();
    }

    $contentTitle = 'Exhibition: Network Fundamentals Reading';
    $contentDesc = 'Sample PDF for the exhibition learner journey (DCSE-101).';
    $contentType = 'pdf';
    $mimeType = 'application/pdf';
    $createdBy = 'EXH-LEC-001';
    $createContent = $db->prepare(
        "INSERT INTO el_contents (module_id, content_type, title, description, mime_type, captions_url, created_by)
         VALUES (?, ?, ?, ?, ?, NULL, ?)"
    );
    $createContent->bind_param('isssss', $moduleId, $contentType, $contentTitle, $contentDesc, $mimeType, $createdBy);
    $createContent->execute();
    $contentId = (int)$createContent->insert_id;
    $createContent->close();

    $versionNo = 1;
    $createVersion = $db->prepare(
        "INSERT INTO el_content_versions (content_id, version_no, file_path, file_size, checksum_sha256, created_by)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $createVersion->bind_param('iisiss', $contentId, $versionNo, $materialRelative, $materialSize, $materialChecksum, $createdBy);
    $createVersion->execute();
    $versionId = (int)$createVersion->insert_id;
    $createVersion->close();

    $setCurrent = $db->prepare('UPDATE el_contents SET current_version_id = ? WHERE id = ?');
    $setCurrent->bind_param('ii', $versionId, $contentId);
    $setCurrent->execute();
    $setCurrent->close();

    $topic = $contentTitle;
    $notesUrl = 'Not available';
    $dte = '2026-07-10';
    $createNote = $db->prepare(
        "INSERT INTO lesson_notes (course_code, topic, url, dte, notes, el_content_id, el_version_id, file_size)
         VALUES ('DCSE-101', ?, ?, ?, ?, ?, ?, ?)"
    );
    $createNote->bind_param('ssssiii', $topic, $notesUrl, $dte, $materialFile, $contentId, $versionId, $materialSize);
    $createNote->execute();
    $createNote->close();

    $db->query("DELETE FROM invoice_items WHERE invoice_number='EXH-INV-STU-001'");
    $db->query("DELETE FROM invoices WHERE student_id='EXH-STU-001' AND academic_year='2026' AND semester='1'");
    $db->query("INSERT INTO invoices (invoice_number,student_id,SID,program_code,semester,status,academic_year,year_of_study,amount,amount_paid,balance,payment_status)
                VALUES ('EXH-INV-STU-001','EXH-STU-001','EXH-STU-001','ICT-001','1','Pending','2026',1,8500.00,5000.00,3500.00,'PARTIAL')");
    $db->query("INSERT INTO invoice_items (invoice_number,item_type,description,amount,quantity,total)
                VALUES ('EXH-INV-STU-001','tuition','2026 Semester 1 tuition',8000.00,1,8000.00),
                       ('EXH-INV-STU-001','registration','Registration fee',500.00,1,500.00)");
    $db->query("DELETE FROM payments WHERE receipt_no='EXH-RCP-001'");
    $db->query("INSERT INTO payments (student_id,receipt_no,amount,method,status,description,posted_by,academic_year,semester)
                VALUES ('EXH-STU-001','EXH-RCP-001',5000.00,'Bank Transfer','approved','Exhibition partial tuition payment','EXH-ADMIN-001','2026',1)");

    $db->query("DELETE FROM course_lecturer WHERE staff_id='EXH-LEC-001' AND course_code IN ('DCSE-101','DCSE-103')");
    $db->query("INSERT INTO course_lecturer (staff_id,course_code,program_code,academic_year,year_of_study,semester,status)
                VALUES ('EXH-LEC-001','DCSE-101','ICT-001','2026',1,'1','active'),
                       ('EXH-LEC-001','DCSE-103','ICT-001','2026',1,'1','active')");

    $db->query("INSERT INTO student_clearance (student_id,finance_cleared,library_cleared,academic_cleared,admin_cleared,graduation_status,graduation_year)
                VALUES ('EXH-ALU-001',1,1,1,1,'Graduated',2025)
                ON DUPLICATE KEY UPDATE finance_cleared=1,library_cleared=1,academic_cleared=1,admin_cleared=1,graduation_status='Graduated',graduation_year=2025");
    $db->query("INSERT INTO alumni_certificates (student_id,certificate_code,program_code,graduation_year,date_issued,status)
                VALUES ('EXH-ALU-001','EXH-CERT-2025-001','ICT-001',2025,'2025-12-12','Approved')
                ON DUPLICATE KEY UPDATE student_id=VALUES(student_id),program_code=VALUES(program_code),graduation_year=2025,date_issued='2025-12-12',status='Approved'");
    $db->query("INSERT INTO alumni_employment_tracking (student_id,current_company,job_title,employment_status)
                VALUES ('EXH-ALU-001','ZedTech Solutions','Junior Network Technician','Employed')
                ON DUPLICATE KEY UPDATE current_company=VALUES(current_company),job_title=VALUES(job_title),employment_status=VALUES(employment_status)");

    $employerUserId = exh_user_id($db, 'EXH-EMP-001');
    $profile = $db->prepare(
        "INSERT INTO employer_profiles (user_id,company_name,industry,location,contact_person,contact_email,contact_phone,status,created_by)
         VALUES (?, 'ZedTech Solutions', 'Information Technology', 'Lusaka', 'Esther Zulu', 'exh-emp-001@exhibition.test', '0977001001', 'approved', 'EXH-ADMIN-001')
         ON DUPLICATE KEY UPDATE company_name=VALUES(company_name),industry=VALUES(industry),location=VALUES(location),
             contact_person=VALUES(contact_person),contact_email=VALUES(contact_email),contact_phone=VALUES(contact_phone),status='approved'"
    );
    $profile->bind_param('i', $employerUserId);
    $profile->execute();
    $profile->close();
    $deleteInternship = $db->prepare("DELETE FROM employer_internships WHERE logged_by_user_id = ? AND student_id='EXH-STU-001'");
    $deleteInternship->bind_param('i', $employerUserId);
    $deleteInternship->execute();
    $deleteInternship->close();
    $internship = $db->prepare(
        "INSERT INTO employer_internships (student_id,company_name,supervisor_name,start_date,end_date,status,performance_rating,feedback,logged_by_user_id)
         VALUES ('EXH-STU-001','ZedTech Solutions','Esther Zulu','2026-06-01','2026-08-31','Active',4,'Strong practical troubleshooting and professional communication.',?)"
    );
    $internship->bind_param('i', $employerUserId);
    $internship->execute();
    $internship->close();

    $applicantEmail = 'exh-applicant@exhibition.test';
    $db->query("DELETE FROM online_applicants WHERE email='exh-applicant@exhibition.test'");
    $db->query("INSERT INTO online_applicants
        (title,Fname,Lname,sex,nrc_pass,country,dob,mobile,email,status,h_addre,p_addre,sponsor,next_kin,next_kin_mobile,relat,program,intake,mode,year,results,nrc_file,deposit_slip)
        VALUES ('Mr.','Henry','Lungu','M','EXH/APP/001','Zambia','2002-03-15','0970000002','exh-applicant@exhibition.test','pending',
                'Lusaka','Lusaka','Self','Mary Lungu','0970000003','Parent','ICT-001','January 2026','Full Time','2026','exh-results.pdf','exh-nrc.pdf','exh-deposit.pdf')");
    $applicationId = (int)$db->insert_id;
    $applicantHash = password_hash(EXHIBITION_PASSWORD, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO users (username,password,primary_role,status) VALUES (?,?,'applicant','active')
         ON DUPLICATE KEY UPDATE password=VALUES(password),primary_role='applicant',staff_id=NULL,student_id=NULL,status='active'"
    );
    $stmt->bind_param('ss', $applicantEmail, $applicantHash);
    $stmt->execute();
    $stmt->close();
    $applicantUserId = exh_user_id($db, $applicantEmail);
    $applicantKey = 'APP-' . $applicationId;
    $stmt = $db->prepare(
        "INSERT INTO user_profiles (user_id,person_type,applicant_id,display_name)
         VALUES (?,'applicant',?,'Henry Lungu')
         ON DUPLICATE KEY UPDATE person_type='applicant',applicant_id=VALUES(applicant_id),display_name='Henry Lungu'"
    );
    $stmt->bind_param('is', $applicantUserId, $applicantKey);
    $stmt->execute();
    $stmt->close();
    wuc_grant_user_portal_access($db, $applicantUserId, ['applicant'], 'exhibition_seed');

    $db->query("DELETE FROM portal_alerts WHERE entity_type='exhibition_demo'");
    $alert = $db->prepare(
        "INSERT INTO portal_alerts (user_id,user_role,source_portal,target_portal,alert_type,severity,title,message,entity_type,entity_id,action_url,target_page,expires_at,status)
         VALUES (?,?,'academic','academic','exhibition_update','info',?,?,'exhibition_demo',?,?,?,DATE_ADD(NOW(), INTERVAL 90 DAY),'unread')"
    );
    $alerts = [
        ['EXH-STU-001','student','Assessment published','Your DCSE-101 result is now available.','DCSE-101','/wucportal/students/continuousAssessment.php'],
        ['EXH-LEC-001','lecturer','Class ready','Your DCSE-101 class list and teaching tools are ready.','DCSE-101','/wucportal/lecturers/index.php'],
        ['EXH-ADM-001','admission_officer','Application awaiting review','A complete ICT-001 application is ready for review',(string)$applicationId,'/wucportal/admissions/applicants.php'],
    ];
    foreach ($alerts as [$user, $role, $title, $message, $entity, $url]) {
        $alert->bind_param('sssssss', $user, $role, $title, $message, $entity, $url, $url);
        $alert->execute();
    }
    $alert->close();

    $db->commit();
    echo "Exhibition Mode seeded successfully.\n\n";
    echo "Shared password: " . EXHIBITION_PASSWORD . "\n";
    echo "Applicant: exh-applicant@exhibition.test (Applicant Login)\n";
    echo "Student: EXH-STU-001 (Student Login)\n";
    echo "Lecturer: EXH-LEC-001 (Staff Login)\n";
    echo "Admissions: EXH-ADM-001 (Staff Login)\n";
    echo "Registrar: EXH-REG-001 (Staff Login)\n";
    echo "Admin: EXH-ADMIN-001 (Staff Login)\n";
    echo "Employer: EXH-EMP-001 (Staff Login)\n";
    echo "Alumni: EXH-ALU-001 (Student Login)\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Exhibition seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

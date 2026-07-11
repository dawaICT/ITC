<?php
/**
 * Seed one demo online applicant for manual admissions UI testing.
 *
 * Run: c:\xampp\php\php.exe scripts/seed_demo_applicant.php
 * Then open: http://localhost/wucportal/admissions/applicants.php
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require dirname(__DIR__) . '/db/connect.php';

$email = 'demo.applicant@wuc.test';
$nrc = '999888/77/6';
$mobile = '0976999888';

$db->query("DELETE FROM online_applicants WHERE email = '{$email}' OR nrc_pass = '{$nrc}'");
$db->query("DELETE FROM processed_applicants WHERE email = '{$email}' OR nrc_pass = '{$nrc}'");

$programRow = $db->query("SELECT program_code FROM programs WHERE COALESCE(is_active, 1) = 1 ORDER BY program_code LIMIT 1")->fetch_assoc();
$programCode = (string)($programRow['program_code'] ?? 'CSE');
$intake = 'January ' . date('Y');
$year = (string)date('Y');

$stmt = $db->prepare("INSERT INTO online_applicants
    (title, Fname, Lname, sex, nrc_pass, country, dob, mobile, email, status,
     h_addre, p_addre, sponsor, program, intake, mode, year)
    VALUES ('Mr', 'Demo', 'Applicant', 'M', ?, 'Zambia', '2001-06-15', ?, ?, 'pending',
            '123 Demo Street, Lusaka', '123 Demo Street, Lusaka', 'Self', ?, ?, 'Full-time', ?)");
$stmt->bind_param('ssssss', $nrc, $mobile, $email, $programCode, $intake, $year);
$stmt->execute();
$applicantId = $stmt->insert_id;
$stmt->close();

echo "Demo applicant seeded successfully.\n\n";
echo "Applicant ID : {$applicantId}\n";
echo "Name         : Demo Applicant\n";
echo "Email        : {$email}\n";
echo "NRC          : {$nrc}\n";
echo "Programme    : {$programCode}\n";
echo "Intake       : {$intake}\n\n";
echo "Next steps:\n";
echo "  1. Log in as admissions staff at http://localhost/wucportal/staff_login.php\n";
echo "  2. Open http://localhost/wucportal/admissions/applicants.php\n";
echo "  3. Accept the application — this runs admitProcessedApplicant() automatically\n";
echo "  4. Student can then log in with generated SID + NRC as initial password\n";

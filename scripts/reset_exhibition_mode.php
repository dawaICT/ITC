<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/schema_guard.php';
require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$confirmed = in_array('--apply', $argv, true) && in_array('--confirm=RESET-EXHIBITION', $argv, true);
if (!$confirmed) {
    echo "No changes made. This reset removes only EXH-* identities and exhibition-linked rows.\n";
    echo "Run with: --apply --confirm=RESET-EXHIBITION\n";
    exit(0);
}

function exh_delete_where(mysqli $db, string $table, string $column, array $values): int
{
    if (!wuc_table_exists($db, $table) || $values === []) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $stmt = $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
    $types = str_repeat('s', count($values));
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $count = max(0, $stmt->affected_rows);
    $stmt->close();
    return $count;
}

$students = ['EXH-STU-001', 'EXH-ALU-001'];
$staff = ['EXH-ADMIN-001', 'EXH-ADM-001', 'EXH-REG-001', 'EXH-LEC-001', 'EXH-EMP-001'];

try {
    $db->begin_transaction();

    $invoiceNumbers = [];
    $stmt = $db->prepare("SELECT invoice_number FROM invoices WHERE student_id IN ('EXH-STU-001','EXH-ALU-001')");
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $invoiceNumbers[] = (string)$row['invoice_number'];
    }
    $stmt->close();
    exh_delete_where($db, 'invoice_items', 'invoice_number', $invoiceNumbers);

    $employerUserId = 0;
    $stmt = $db->prepare("SELECT user_id FROM users WHERE username='EXH-EMP-001' LIMIT 1");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $employerUserId = (int)($row['user_id'] ?? 0);
    if ($employerUserId > 0) {
        $stmt = $db->prepare('DELETE FROM employer_internships WHERE logged_by_user_id = ?');
        $stmt->bind_param('i', $employerUserId);
        $stmt->execute();
        $stmt->close();
    }

    foreach ([
        ['semester_assessment', 'Sid'], ['attendance_logs', 'Sid'], ['course_registration', 'Sid'],
        ['student_courses', 'student_id'],
        ['payments', 'student_id'], ['invoices', 'student_id'], ['notifications', 'recipient_student_id'],
        ['student_clearance', 'student_id'], ['alumni_certificates', 'student_id'],
        ['alumni_employment_tracking', 'student_id'], ['student_login', 'Sid'], ['student_program', 'Sid'],
    ] as [$table, $column]) {
        exh_delete_where($db, $table, $column, $students);
    }
    exh_delete_where($db, 'users', 'student_id', $students);
    exh_delete_where($db, 'students', 'SID', $students);

    exh_delete_where($db, 'course_lecturer', 'staff_id', $staff);
    foreach ($staff as $staffId) {
        $result = wuc_deprovision_staff_account($db, $staffId);
        if (!$result['ok']) {
            throw new RuntimeException("Could not remove exhibition staff {$staffId}: " . implode('; ', $result['messages']));
        }
    }

    $applicantEmail = 'exh-applicant@exhibition.test';
    $stmt = $db->prepare("DELETE FROM users WHERE username=? AND primary_role='applicant'");
    $stmt->bind_param('s', $applicantEmail);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('DELETE FROM online_applicants WHERE email=?');
    $stmt->bind_param('s', $applicantEmail);
    $stmt->execute();
    $stmt->close();

    $db->query("DELETE FROM portal_alerts WHERE entity_type='exhibition_demo' OR user_id LIKE 'EXH-%'");
    $db->query("UPDATE portal_settings SET setting_value='0' WHERE setting_key='exhibition_mode'");

    // Remove exhibition eLearning sample content only.
    if (wuc_table_exists($db, 'lesson_notes')) {
        $materialFile = 'EXH_DCSE-101_network_intro.pdf';
        $prior = $db->prepare("SELECT el_content_id FROM lesson_notes WHERE notes = ? OR topic LIKE 'Exhibition:%'");
        $prior->bind_param('s', $materialFile);
        $prior->execute();
        $contentIds = [];
        foreach ($prior->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $cid = (int)($row['el_content_id'] ?? 0);
            if ($cid > 0) {
                $contentIds[] = $cid;
            }
        }
        $prior->close();
        $delNotes = $db->prepare("DELETE FROM lesson_notes WHERE notes = ? OR topic LIKE 'Exhibition:%'");
        $delNotes->bind_param('s', $materialFile);
        $delNotes->execute();
        $delNotes->close();
        foreach (array_unique($contentIds) as $contentId) {
            if (wuc_table_exists($db, 'el_content_versions')) {
                $stmt = $db->prepare('DELETE FROM el_content_versions WHERE content_id = ?');
                $stmt->bind_param('i', $contentId);
                $stmt->execute();
                $stmt->close();
            }
            if (wuc_table_exists($db, 'el_contents')) {
                $stmt = $db->prepare('DELETE FROM el_contents WHERE id = ?');
                $stmt->bind_param('i', $contentId);
                $stmt->execute();
                $stmt->close();
            }
        }
        if (wuc_table_exists($db, 'el_course_modules')) {
            $db->query("DELETE FROM el_course_modules WHERE title='Exhibition Course Materials' AND course_code='DCSE-101' AND created_by='EXH-LEC-001'");
        }
    }

    $db->commit();
    echo "Exhibition data reset completed. Non-EXH records were not targeted.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Exhibition reset failed and was rolled back: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

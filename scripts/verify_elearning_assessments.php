<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_assessment_security.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function verify_column(mysqli $db, string $table, string $column): void
{
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)($row['cnt'] ?? 0) < 1) {
        throw new RuntimeException("Missing column {$table}.{$column}");
    }
}

function verify_pick_course(mysqli $db): string
{
    foreach (['courses', 'program_courses'] as $table) {
        $res = @$db->query("SHOW TABLES LIKE '{$table}'");
        if (!$res || $res->num_rows < 1) {
            continue;
        }
        $res->free();
        $column = $table === 'courses' ? 'course_code' : 'course_code';
        $result = @$db->query("SELECT {$column} AS course_code FROM {$table} WHERE {$column} IS NOT NULL AND {$column}<>'' LIMIT 1");
        if ($result && ($row = $result->fetch_assoc())) {
            $result->free();
            return (string)$row['course_code'];
        }
    }
    return 'VERIFY101';
}

elearningAssessmentEnsureSchema($db);

foreach ([
    ['el_quizzes', 'settings_json'],
    ['el_assignments', 'settings_json'],
    ['el_attempts', 'malpractice_flags_json'],
    ['el_attempts', 'time_spent_seconds'],
    ['el_submissions', 'plagiarism_score'],
    ['el_submissions', 'plagiarism_status'],
] as $required) {
    verify_column($db, $required[0], $required[1]);
}

$courseCode = verify_pick_course($db);
$staffId = 'VERIFY_STAFF';
$sid = 'VERIFY_STUDENT';
$settings = elearningAssessmentQuizSettingsFromPost([
    'max_attempts' => '1',
    'time_limit' => '30',
    'shuffle_questions' => '1',
    'shuffle_options' => '1',
    'require_fullscreen' => '1',
    'disable_copy_paste' => '1',
]);
$settings['available_from'] = date('Y-m-d H:i:s', time() - 60);
$settingsJson = elearningAssessmentJson($settings);
$dueAt = date('Y-m-d H:i:s', time() + 3600);
$title = 'Verifier Assessment ' . date('YmdHis');
$description = 'Verifier row rolled back.';
$assessmentType = 'quiz';
$attachmentPath = null;
$timeLimit = 30;
$shuffleQuestions = 1;
$shuffleOptions = 1;
$isPublished = 1;

$db->begin_transaction();
try {
    $stmt = $db->prepare("INSERT INTO el_quizzes (course_code, title, description, due_at, assessment_type, attachment_path, time_limit_minutes, shuffle_questions, shuffle_options, is_published, settings_json, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('ssssssiiiiss', $courseCode, $title, $description, $dueAt, $assessmentType, $attachmentPath, $timeLimit, $shuffleQuestions, $shuffleOptions, $isPublished, $settingsJson, $staffId);
    $stmt->execute();
    $quizId = (int)$stmt->insert_id;
    $stmt->close();

    $quiz = [
        'id' => $quizId,
        'due_at' => $dueAt,
        'settings_json' => $settingsJson,
    ];
    $availability = elearningAssessmentQuizAvailability($db, $quiz, $sid);
    if (empty($availability['ok'])) {
        throw new RuntimeException('Quiz availability failed: ' . ($availability['reason'] ?? 'unknown'));
    }

    $attemptId = elearningAssessmentFindOrCreateAttempt($db, $quizId, $sid);
    if ($attemptId <= 0) {
        throw new RuntimeException('Attempt creation failed.');
    }
    $attemptIdAgain = elearningAssessmentFindOrCreateAttempt($db, $quizId, $sid);
    if ($attemptId !== $attemptIdAgain) {
        throw new RuntimeException('Active attempt was not reused.');
    }

    $assignmentSettingsJson = elearningAssessmentJson(elearningAssessmentAssignmentSettingsFromPost([
        'allow_text_response' => '1',
        'allow_file_upload' => '1',
        'ai_check_enabled' => '1',
        'plagiarism_check_enabled' => '1',
        'allowed_file_types' => 'pdf,docx,txt',
        'max_file_mb' => '10',
    ]));
    $stmt = $db->prepare("INSERT INTO el_assignments (course_code, title, description, due_at, assessment_type, attachment_path, settings_json, created_by) VALUES (?,?,?,?,?,?,?,?)");
    $assignmentType = 'assignment';
    $stmt->bind_param('ssssssss', $courseCode, $title, $description, $dueAt, $assignmentType, $attachmentPath, $assignmentSettingsJson, $staffId);
    $stmt->execute();
    $assignmentId = (int)$stmt->insert_id;
    $stmt->close();

    $sampleText = str_repeat('Academic integrity requires original writing with cited sources and independently reasoned analysis. ', 8);
    $stmt = $db->prepare("INSERT INTO el_submissions (assignment_id, Sid, submitted_at, text_body) VALUES (?,?,NOW(),?)");
    $otherSid = 'VERIFY_OTHER';
    $stmt->bind_param('iss', $assignmentId, $otherSid, $sampleText);
    $stmt->execute();
    $stmt->close();

    $plagiarism = elearningAssessmentRunPlagiarismCheck($db, $assignmentId, $sid, $sampleText);
    if (($plagiarism['status'] ?? '') !== 'high' || (float)($plagiarism['score'] ?? 0) < 75) {
        throw new RuntimeException('Plagiarism similarity check did not flag the copied sample.');
    }

    $db->rollback();
    echo "OK: eLearning assessment schema, settings, attempt reuse, and plagiarism checks validated.\n";
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

<?php
$mode = $argv[1] ?? '';

if ($mode === 'lecturer') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/wucportal/elearning/recordings.php';
    $_SERVER['PHP_SELF'] = '/wucportal/elearning/recordings.php';
    $_GET['course_code'] = 'GEN101';
    session_id('verifylecturerrec');
    session_start();
    $_SESSION['staff_id'] = 'WUC900';
    $_SESSION['user_id'] = 'WUC900';
    $_SESSION['role'] = 'lecturer';
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = str_repeat('a', 64);
    ob_start();
    require __DIR__ . '/../elearning/recordings.php';
    $html = ob_get_clean();
    echo (strpos($html, 'Recorded Videos') !== false
        && strpos($html, 'Portal Recorder') !== false
        && strpos($html, 'Checking browser recording formats') !== false)
        ? "lecturer_render_ok\n"
        : "lecturer_render_missing\n";
    exit;
}

if ($mode === 'student') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/wucportal/students/elearning/recordings.php';
    $_SERVER['PHP_SELF'] = '/wucportal/students/elearning/recordings.php';
    $_GET['course_code'] = 'GEN101';
    session_id('verifystudentrec');
    session_start();
    $_SESSION['Sid'] = 'STU900';
    $_SESSION['user_role'] = 'student';
    $_SESSION['last_activity'] = time();
    require_once __DIR__ . '/../db/connect.php';
    require_once __DIR__ . '/../includes/elearning_recordings.php';
    elearningEnsureRecordingSchema($db);
    $db->begin_transaction();
    try {
        $courseCode = 'GEN101';
        $title = 'Validation render recording';
        $description = 'Temporary render row';
        $driveUrl = 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz123456/view?usp=sharing';
        $fileId = elearningExtractGoogleDriveFileId($driveUrl);
        $recordedAt = date('Y-m-d H:i:s');
        $durationMinutes = 10;
        $videoFormat = 'video/mp4';
        $isPublished = 1;
        $createdBy = 'WUC900';
        $stmt = $db->prepare("INSERT INTO el_recorded_videos
            (course_code, title, description, drive_file_id, drive_url, recorded_at, duration_minutes, video_format, playback_provider, is_published, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'google_drive', ?, ?)");
        $stmt->bind_param('ssssssisis', $courseCode, $title, $description, $fileId, $driveUrl, $recordedAt, $durationMinutes, $videoFormat, $isPublished, $createdBy);
        $stmt->execute();
        $stmt->close();

        ob_start();
        require __DIR__ . '/../students/elearning/recordings.php';
        $html = ob_get_clean();
        echo (strpos($html, 'Recorded Videos') !== false
            && strpos($html, 'format-badge') !== false
            && strpos($html, 'MP4') !== false)
            ? "student_render_ok\n"
            : "student_render_missing\n";
    } finally {
        $db->rollback();
        $cleanupTitle = 'Validation render recording';
        if ($cleanup = $db->prepare("DELETE FROM el_recorded_videos WHERE title = ? AND created_by = 'WUC900'")) {
            $cleanup->bind_param('s', $cleanupTitle);
            $cleanup->execute();
            $cleanup->close();
        }
    }
    exit;
}

fwrite(STDERR, "Usage: php scripts/verify_elearning_recording_pages.php lecturer|student\n");
exit(2);

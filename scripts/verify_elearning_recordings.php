<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_recordings.php';

$staffId = 'WUC900';
$studentId = 'STU900';
$courseCode = 'GEN101';
$driveUrl = 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz123456/view?usp=sharing';

$result = [
    'lecturer_access' => canLecturerAccessElearningCourse($db, $staffId, $courseCode),
    'student_access' => canStudentAccessElearningCourse($db, $studentId, $courseCode),
    'drive_file_id' => elearningExtractGoogleDriveFileId($driveUrl),
    'mp4_format' => elearningNormalizeVideoFormat('video/mp4;codecs=h264,aac'),
    'webm_format' => elearningNormalizeVideoFormat('webm'),
    'format_label' => elearningVideoFormatLabel('video/mp4;codecs=h264,aac'),
];

elearningEnsureRecordingSchema($db);
$db->begin_transaction();
try {
    $title = 'Validation recording';
    $description = 'Temporary validation row';
    $fileId = elearningExtractGoogleDriveFileId($driveUrl);
    $recordedAt = date('Y-m-d H:i:s');
    $durationMinutes = 12;
    $videoFormat = elearningNormalizeVideoFormat('video/mp4;codecs=h264,aac');
    $isPublished = 1;

    $stmt = $db->prepare("INSERT INTO el_recorded_videos
        (course_code, title, description, drive_file_id, drive_url, recorded_at, duration_minutes, video_format, playback_provider, is_published, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'google_drive', ?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }
    $stmt->bind_param('ssssssisis', $courseCode, $title, $description, $fileId, $driveUrl, $recordedAt, $durationMinutes, $videoFormat, $isPublished, $staffId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Insert failed: ' . $stmt->error);
    }
    $recordingId = (int)$stmt->insert_id;
    $stmt->close();

    $updatedTitle = 'Validation recording updated';
    $updatedFormat = elearningNormalizeVideoFormat('webm');
    $stmt = $db->prepare("UPDATE el_recorded_videos
        SET title = ?, description = ?, drive_file_id = ?, drive_url = ?, recorded_at = ?, duration_minutes = ?, video_format = ?, playback_provider = 'google_drive', is_published = ?
        WHERE id = ? AND course_code = ? AND created_by = ?");
    if (!$stmt) {
        throw new RuntimeException('Update prepare failed: ' . $db->error);
    }
    $stmt->bind_param('sssssisiiss', $updatedTitle, $description, $fileId, $driveUrl, $recordedAt, $durationMinutes, $updatedFormat, $isPublished, $recordingId, $courseCode, $staffId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Update failed: ' . $stmt->error);
    }
    $stmt->close();

    $recordings = elearningGetPublishedRecordingsForCourse($db, $courseCode);
    $result['published_recordings_for_course'] = count($recordings);
    $result['preview_url'] = elearningGoogleDrivePreviewUrl($recordings[0]['drive_file_id'] ?? null, $recordings[0]['drive_url'] ?? '');
    $result['stored_format_label'] = elearningVideoFormatLabel($recordings[0]['video_format'] ?? null);
    $result['update_path_format_label'] = elearningVideoFormatLabel($updatedFormat);
    $result['student_notifications_created'] = elearningNotifyCourseRecording($db, $courseCode, $title);

    $stmt = $db->prepare("SELECT COUNT(*) AS total FROM el_student_notifications WHERE course_code = ? AND type = 'recording_published'");
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $result['notifications_in_transaction'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
} finally {
    $db->rollback();
}

$stmt = $db->prepare("SELECT COUNT(*) AS total FROM el_recorded_videos WHERE title IN ('Validation recording', 'Validation recording updated')");
$stmt->execute();
$result['recordings_after_rollback'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

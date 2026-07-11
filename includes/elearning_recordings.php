<?php
require_once __DIR__ . '/elearning_access.php';
require_once __DIR__ . '/elearning_live_sessions.php';

function elearningEnsureRecordingSchema(mysqli $db): void
{
    if (elearningTableExists($db, 'el_recorded_videos')
        && elearningColumnExists($db, 'el_recorded_videos', 'video_format')
        && elearningColumnExists($db, 'el_recorded_videos', 'playback_provider')) {
        return;
    }
    error_log('el_recorded_videos schema is incomplete; run migrations.');
}

function elearningExtractGoogleDriveFileId(string $url): ?string
{
    $url = trim(str_replace(["\r", "\n", "\0"], '', $url));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (!in_array($scheme, ['http', 'https'], true) || !in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
        return null;
    }

    $path = (string)parse_url($url, PHP_URL_PATH);
    if (preg_match('#/file/d/([A-Za-z0-9_-]{10,})#', $path, $m)) {
        return $m[1];
    }
    if (preg_match('#/open/([A-Za-z0-9_-]{10,})#', $path, $m)) {
        return $m[1];
    }

    $query = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    $id = (string)($query['id'] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{10,}$/', $id)) {
        return $id;
    }

    return null;
}

function elearningIsGoogleDriveVideoUrl(string $url): bool
{
    return elearningExtractGoogleDriveFileId($url) !== null;
}

function elearningNormalizeVideoFormat(string $value): ?string
{
    $value = strtolower(trim(str_replace(["\r", "\n", "\0"], '', $value)));
    if ($value === '') {
        return null;
    }

    $aliases = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'mov' => 'video/quicktime',
        'quicktime' => 'video/quicktime',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'ogv' => 'video/ogg',
    ];
    $value = $aliases[$value] ?? $value;

    $allowedPrefixes = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'application/vnd.google-apps.video',
    ];
    foreach ($allowedPrefixes as $prefix) {
        if ($value === $prefix || strpos($value, $prefix . ';') === 0) {
            return substr($value, 0, 80);
        }
    }

    return null;
}

function elearningVideoFormatLabel(?string $format): string
{
    $format = elearningNormalizeVideoFormat((string)$format);
    if ($format === null) {
        return 'Drive video';
    }
    if (strpos($format, 'video/mp4') === 0) {
        return 'MP4';
    }
    if (strpos($format, 'video/webm') === 0) {
        return 'WebM';
    }
    if (strpos($format, 'video/ogg') === 0) {
        return 'Ogg video';
    }
    if (strpos($format, 'video/quicktime') === 0) {
        return 'QuickTime';
    }
    return 'Drive video';
}

function elearningGoogleDrivePreviewUrl(?string $fileId, string $fallbackUrl): string
{
    $fileId = trim((string)$fileId);
    if ($fileId !== '') {
        return 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/preview';
    }
    return $fallbackUrl;
}

function elearningNormalizeOptionalDateTime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    return elearningNormalizeDateTimeInput($value);
}

function elearningGetPublishedRecordingsForCourse(mysqli $db, string $courseCode, array $courseOfferingIds = []): array
{
    elearningEnsureRecordingSchema($db);
    $recordings = [];
    $types = 's';
    $params = [$courseCode];
    $offeringSql = elearningOfferingScopeCondition($db, 'el_recorded_videos', null, $courseOfferingIds, $types, $params);
    $sql = "SELECT * FROM el_recorded_videos
            WHERE UPPER(TRIM(course_code)) = UPPER(TRIM(?))
              {$offeringSql}
              AND is_published = 1
            ORDER BY COALESCE(recorded_at, created_at) DESC, id DESC";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $recordings[] = $row;
        }
        $stmt->close();
    }
    return $recordings;
}

function elearningGetPublishedRecordingsForCourses(mysqli $db, array $courseCodes, array $courseOfferingIds = []): array
{
    elearningEnsureRecordingSchema($db);
    $codes = [];
    foreach ($courseCodes as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $codes[] = $code;
        }
    }
    $codes = array_values(array_unique($codes));
    if (!$codes) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    $params = $codes;
    $offeringSql = elearningOfferingScopeCondition($db, 'el_recorded_videos', null, $courseOfferingIds, $types, $params);
    $recordings = [];
    $sql = "SELECT * FROM el_recorded_videos
            WHERE course_code IN ($placeholders)
              {$offeringSql}
              AND is_published = 1
            ORDER BY COALESCE(recorded_at, created_at) DESC, id DESC";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $recordings[] = $row;
        }
        $stmt->close();
    }
    return $recordings;
}

function elearningNotifyCourseRecording(mysqli $db, string $courseCode, string $title, ?int $courseOfferingId = null): int
{
    $body = 'A recorded class video for ' . $courseCode . ' is now available: ' . $title . '.';
    $count = 0;
    foreach (elearningGetCourseStudentIds($db, $courseCode, $courseOfferingId) as $studentId) {
        if (elearningCreateStudentNotification($db, $studentId, $courseCode, null, 'recording_published', 'New recorded class available', $body, 'elearning/recordings.php?course_code=' . rawurlencode($courseCode), $courseOfferingId)) {
            $count++;
        }
    }
    return $count;
}

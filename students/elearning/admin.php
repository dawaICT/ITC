<?php
// Localhost-only admin page to create a course and enroll a student (dev/testing only)
if (php_sapi_name() !== 'cli' && ($_SERVER['SERVER_NAME'] ?? '') !== 'localhost') {
    die('Access restricted to localhost');
}

require_once __DIR__ . '/../../db/connect.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $db->real_escape_string($_POST['code'] ?? '');
    $title = $db->real_escape_string($_POST['title'] ?? '');
    $desc = $db->real_escape_string($_POST['description'] ?? '');
    $sid = $db->real_escape_string($_POST['sid'] ?? '');

    if ($code && $title) {
        $db->query("INSERT INTO elearning_courses (code,title,description) SELECT '$code','$title','$desc' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM elearning_courses WHERE code='$code')");
        $courseRes = $db->query("SELECT id FROM elearning_courses WHERE code='$code' LIMIT 1");
        $course = $courseRes->fetch_object();
        $courseId = $course->id ?? null;

        if ($courseId && $sid) {
            // enroll student by SID
            $db->query("INSERT INTO elearning_enrollments (student_id, course_id, enrolled_at)
                SELECT s.id, $courseId, NOW() FROM students s WHERE s.SID = '$sid' AND NOT EXISTS (SELECT 1 FROM elearning_enrollments e WHERE e.student_id = s.id AND e.course_id = $courseId)");
            $message = 'Course created and enrollment attempted.';
        } else {
            $message = 'Course created. Provide a valid SID to enroll.';
        }
    } else {
        $message = 'Code and title are required.';
    }
}

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>eLearning Admin (dev)</title>
<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<h2>eLearning Admin (localhost only)</h2>
<?php if ($message) echo '<p>' . htmlspecialchars($message) . '</p>'; ?>
<form method="post">
  <label>Course Code: <input name="code"></label><br>
  <label>Title: <input name="title"></label><br>
  <label>Description: <textarea name="description"></textarea></label><br>
  <label>Enroll SID (optional): <input name="sid"></label><br>
  <button type="submit">Create / Enroll</button>
</form>
<p><a href="/wucportal/students/">Back to students</a></p>
</body>
</html>

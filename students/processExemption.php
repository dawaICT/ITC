<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';

if (!isset($_POST['submit'])) {
    header('Location: apply_exemption.php');
    exit;
}

$CoRegID = trim((string)($_POST['CoRegID'] ?? ''));
$Sid = trim((string)($_POST['Sid'] ?? ''));
$course_code = trim((string)($_POST['course_code'] ?? ''));
$sessionSid = (string)($_SESSION['Sid'] ?? '');

if ($Sid === '' || $sessionSid === '' || $Sid !== $sessionSid) {
    echo '<h4 class="alert alert-danger text-center">Invalid session. Please log in again.</h4>';
    exit;
}

if ($CoRegID === '' || $course_code === '') {
    echo '<h4 class="alert alert-danger text-center">Missing application details.</h4>';
    exit;
}

$ownStmt = $db->prepare('SELECT 1 FROM course_registration WHERE id = ? AND Sid = ? AND course_code = ? LIMIT 1');
if (!$ownStmt) {
    echo '<h4 class="alert alert-danger text-center">Could not verify course registration.</h4>';
    exit;
}
$ownStmt->bind_param('sss', $CoRegID, $Sid, $course_code);
$ownStmt->execute();
$ownsCourse = $ownStmt->get_result()->num_rows > 0;
$ownStmt->close();

if (!$ownsCourse) {
    echo '<h4 class="alert alert-danger text-center">This course registration does not belong to your account.</h4>';
    exit;
}

require_once __DIR__ . '/../includes/manual_entry_guards.php';

if (!guard_exemption_not_applied($db, $Sid, $course_code)) {
    echo '<h4 class="alert alert-warning text-center">You have already applied for exemption in ' . htmlspecialchars($course_code, ENT_QUOTES) . '.</h4>';
    exit;
}

if (!isset($_FILES['support_doc']) || ($_FILES['support_doc']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo '<h4 class="alert alert-danger text-center">Supporting document is required (PDF or JPG, max 2MB).</h4>';
    exit;
}

$tmpName = $_FILES['support_doc']['tmp_name'];
$origName = basename((string)$_FILES['support_doc']['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'pdf'], true)) {
    echo "<script>alert('Sorry, invalid file format. Upload files in PDF or JPG only.')</script>";
    exit;
}

if (($_FILES['support_doc']['size'] ?? 0) > 2 * 1024 * 1024) {
    echo '<h4 class="alert alert-danger text-center">File was not uploaded. Limit file size is 2MB.</h4>';
    exit;
}

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
$storedName = $safeName . '_' . time() . '.' . $ext;
$path = $uploadDir . $storedName;

if (!move_uploaded_file($tmpName, $path)) {
    echo '<h4 class="alert alert-danger text-center">File upload failed. Please try again.</h4>';
    exit;
}

$insert = $db->prepare('INSERT INTO exemption (CoRegID, Sid, course_code, support_doc) VALUES (?, ?, ?, ?)');
if (!$insert) {
    echo '<h4 class="alert alert-danger text-center">Could not save exemption application.</h4>';
    exit;
}
$insert->bind_param('ssss', $CoRegID, $Sid, $course_code, $storedName);
if ($insert->execute()) {
    echo '<h4 class="alert alert-success text-center">You have successfully applied for exemption in ' . htmlspecialchars($course_code, ENT_QUOTES) . '.</h4>';
} else {
    echo '<h4 class="alert alert-danger text-center">Could not save exemption application.</h4>';
}
$insert->close();

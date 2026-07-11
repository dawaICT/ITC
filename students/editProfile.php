<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db/connect.php';

$sid = (string)($_SESSION['Sid'] ?? '');
if ($sid === '') {
    header('Location: ../student_login.php');
    exit;
}

$message = null;
$messageType = 'success';

if (isset($_POST['update_profile'])) {
    $postedSid = trim((string)($_POST['SID'] ?? ''));

    if ($postedSid === '' || !hash_equals($sid, $postedSid)) {
        $message = 'Invalid student account selected.';
        $messageType = 'danger';
    } elseif (empty($_FILES['profile_image']) || ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $message = 'Please choose a profile image to upload.';
        $messageType = 'danger';
    } else {
        $file = $_FILES['profile_image'];
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($ext, $allowed, true)) {
            $message = 'Upload a valid image file: JPG, PNG, GIF, or WEBP.';
            $messageType = 'danger';
        } else {
            $uploadsDir = realpath(__DIR__ . '/../uploads/profile');
            if ($uploadsDir === false) {
                $uploadsDir = __DIR__ . '/../uploads/profile';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                }
            }

            $safeSid = preg_replace('/[^A-Za-z0-9_-]/', '_', $sid);
            $filename = 'profile_' . $safeSid . '_' . time() . '.' . $ext;
            $target = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

            if (move_uploaded_file((string)$file['tmp_name'], $target)) {
                if ($stmt = $db->prepare('UPDATE students SET profile_image = ? WHERE SID = ?')) {
                    $stmt->bind_param('ss', $filename, $sid);
                    $stmt->execute();
                    $stmt->close();
                    $_SESSION['student_profile_image'] = $filename;
                    $message = 'Profile picture updated successfully.';
                } else {
                    $message = 'Profile picture was uploaded, but the account could not be updated.';
                    $messageType = 'danger';
                }
            } else {
                $message = 'Profile picture could not be uploaded.';
                $messageType = 'danger';
            }
        }
    }
}

$student = null;
if ($stmt = $db->prepare('SELECT SID, Fname, Lname, email, mobile, profile_image FROM students WHERE SID = ? LIMIT 1')) {
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$profileImage = '/wucportal/images/favicon.png';
if (!empty($student['profile_image'])) {
    $candidate = __DIR__ . '/../uploads/profile/' . basename((string)$student['profile_image']);
    if (is_file($candidate)) {
        $profileImage = '/wucportal/uploads/profile/' . rawurlencode(basename((string)$student['profile_image']));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Profile Picture - ITC</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/css/project-reusable.css">

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper portal-dashboard student-account-page px-3 py-4">
    <div class="container-fluid">
        <div class="dashboard-header student-section mb-4">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="dashboard-title">Edit Profile Picture</h1>
                    <p class="text-muted mb-0">Update the profile image shown across your student account.</p>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-outline-secondary" onclick="history.back()">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </button>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-xl-7 col-lg-8">
                <section class="profile-card shadow-sm">
                    <div class="row g-4 align-items-center">
                        <div class="col-sm-4 text-center">
                            <img
                                id="profilePreview"
                                src="<?php echo htmlspecialchars($profileImage); ?>"
                                alt="Current profile"
                                class="profile-image"
                                loading="lazy"
                                onerror="this.onerror=null;this.src='/wucportal/images/favicon.png';"
                            >
                            <h5 class="mt-3 mb-1">
                                <?php echo htmlspecialchars(trim((string)($student['Fname'] ?? '') . ' ' . (string)($student['Lname'] ?? '')) ?: $sid); ?>
                            </h5>
                            <span class="badge bg-primary"><?php echo htmlspecialchars($sid); ?></span>
                        </div>
                        <div class="col-sm-8">
                            <form action="editProfile.php" method="post" enctype="multipart/form-data" id="profileForm">
                                <input type="hidden" name="SID" value="<?php echo htmlspecialchars($sid); ?>">

                                <div class="mb-3">
                                    <label for="profile_image" class="form-label">Profile image</label>
                                    <input type="file" class="form-control" id="profile_image" name="profile_image" accept="image/*" required>
                                    <div class="form-text">Use a clear JPG, PNG, GIF, or WEBP image.</div>
                                </div>

                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-primary" type="submit" name="update_profile">
                                        <i class="fas fa-upload me-2"></i>Update Profile
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="history.back()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>

<script>
document.getElementById('profile_image')?.addEventListener('change', function (event) {
    const file = event.target.files && event.target.files[0];
    if (!file) return;
    const preview = document.getElementById('profilePreview');
    preview.src = URL.createObjectURL(file);
});
</script>
</body>
</html>

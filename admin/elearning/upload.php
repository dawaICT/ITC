<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
// Load captions helper if available; fall back to a no-op to avoid hard failure
$__capPath = dirname(__DIR__, 2) . '/includes/captions.php';
if (file_exists($__capPath)) {
    require_once $__capPath;
} else {
    if (!function_exists('generate_captions_if_enabled')) {
        function generate_captions_if_enabled(string $absolutePath, string $mimeType): ?string { return null; }
    }
}
elearning_require_role(['systems_admin','lecturer']);

require_once __DIR__ . '/../../db/connect.php';
$config = require __DIR__ . '/../../config/elearning.php';

$err = null; $ok = null;

// CSRF token (same convention used across the portal: login, ajax_upload_handler.php).
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Allowed uploads per content type. Extensions are the authoritative gate;
// MIME (sniffed from the file itself, not the client) is a secondary check.
$allowedUploads = [
    'pdf'   => ['ext' => ['pdf'],                          'mime' => ['application/pdf']],
    'docx'  => ['ext' => ['docx', 'doc'],                  'mime' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword', 'application/zip']],
    'video' => ['ext' => ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'], 'mime' => ['video/']],
    'scorm' => ['ext' => ['zip'],                          'mime' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream']],
    'html'  => ['ext' => ['html', 'htm'],                  'mime' => ['text/html']],
    'link'  => ['ext' => ['url', 'txt', 'html', 'htm'],    'mime' => []],
];
$maxSizes = ['video' => 512 * 1024 * 1024, 'scorm' => 256 * 1024 * 1024];
$defaultMax = 50 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A POST body larger than post_max_size arrives empty; surface a clear message.
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $err = 'The upload exceeded the server size limit (post_max_size). Use a smaller file or raise the limit.';
    } elseif (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $err = 'Invalid or expired form token. Please refresh the page and try again.';
    } else {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $ctype = trim($_POST['content_type'] ?? 'pdf');
        $file = $_FILES['file'] ?? null;

        if ($moduleId <= 0) {
            $err = 'Select a module.';
        } elseif (!isset($allowedUploads[$ctype])) {
            $err = 'Unsupported content type.';
        } elseif ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $err = 'Select a file.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please retry.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload stopped by a PHP extension.',
            ];
            $err = $uploadErrors[$file['error']] ?? 'Upload failed.';
        } else {
            // Confirm the module still exists before storing anything.
            $modOk = false;
            if ($chk = $db->prepare("SELECT id FROM lms_modules WHERE id = ?")) {
                $chk->bind_param('i', $moduleId);
                $chk->execute();
                $modOk = (bool)$chk->get_result()->fetch_assoc();
                $chk->close();
            }

            $rules = $allowedUploads[$ctype];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $maxSize = $maxSizes[$ctype] ?? $defaultMax;
            // Sniff MIME from the actual file, not the client-supplied $file['type'].
            $mime = mime_content_type($file['tmp_name']) ?: '';
            $mimeOk = empty($rules['mime']) || $mime === '';
            foreach ($rules['mime'] as $allowedMime) {
                if (str_starts_with($mime, $allowedMime)) { $mimeOk = true; break; }
            }

            if (!$modOk) {
                $err = 'The selected module no longer exists.';
            } elseif ($ext === '' || !in_array($ext, $rules['ext'], true)) {
                $err = 'Invalid file type for "' . $ctype . '". Allowed: ' . implode(', ', $rules['ext']) . '.';
            } elseif ($file['size'] > $maxSize) {
                $err = 'File is too large. Maximum for this type is ' . round($maxSize / 1048576) . ' MB.';
            } elseif (!$mimeOk) {
                $err = 'File contents (' . $mime . ') do not match the selected content type.';
            } else {
                $root = rtrim($config['storage_root'], '/');
                $destDir = $root . '/' . $moduleId;
                if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                    $err = 'Failed to create the storage folder.';
                } else {
                    $fname = uniqid('cnt_', true) . '.' . $ext;
                    $abs = $destDir . '/' . $fname;
                    if (!move_uploaded_file($file['tmp_name'], $abs)) {
                        $err = 'Failed to save file.';
                    } else {
                        $relPath = 'uploads/elearning/' . $moduleId . '/' . $fname;
                        $user = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'system';

                        // Next version number for this module.
                        $version = 1;
                        if ($vstmt = $db->prepare("SELECT COALESCE(MAX(version),0)+1 AS v FROM lms_contents WHERE module_id = ?")) {
                            $vstmt->bind_param('i', $moduleId);
                            $vstmt->execute();
                            $version = (int)($vstmt->get_result()->fetch_assoc()['v'] ?? 1);
                            $vstmt->close();
                        }
                        // Supersede the previous current version.
                        if ($up = $db->prepare("UPDATE lms_contents SET is_current = 0 WHERE module_id = ?")) {
                            $up->bind_param('i', $moduleId);
                            $up->execute();
                            $up->close();
                        }

                        $storedMime = $mime !== '' ? $mime : null;
                        $stmt = $db->prepare("INSERT INTO lms_contents (module_id, content_type, storage_path, mime_type, version, is_current, created_by) VALUES (?,?,?,?,?,1,?)");
                        $stmt->bind_param('isssis', $moduleId, $ctype, $relPath, $storedMime, $version, $user);
                        if ($stmt->execute()) {
                            if ($ctype === 'video') { generate_captions_if_enabled($abs, (string)$storedMime); }
                            // Rotate the token to prevent accidental re-submission.
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            $ok = 'File uploaded successfully (version ' . $version . ').';
                        } else {
                            @unlink($abs); // roll back the orphaned file on DB failure
                            $err = 'Database insert failed.';
                        }
                    }
                }
            }
        }
    }
}

// Fetch latest modules for quick selection
$modules = [];
if ($res = $db->query("SELECT id, course_code, module_code, title FROM lms_modules ORDER BY updated_at DESC LIMIT 200")) {
  while ($row = $res->fetch_assoc()) { $modules[] = $row; }
  $res->free();
}

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../css/admin-dashboard.css" />
<div class="container-fluid px-4 portal-dashboard">
  <h2 class="mb-3">Upload Content</h2>
  <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Module</label>
            <select class="form-select" name="module_id" required>
              <option value="">Select module</option>
              <?php foreach ($modules as $m): ?>
                <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['course_code'] . ' - ' . $m['module_code'] . ' - ' . $m['title']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Type</label>
            <select class="form-select" name="content_type">
              <option value="pdf">PDF</option>
              <option value="docx">DOCX</option>
              <option value="video">Video (MP4)</option>
              <option value="scorm">SCORM 1.2/2004</option>
              <option value="html">HTML</option>
              <option value="link">External Link</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">File</label>
            <input type="file" class="form-control" name="file" required />
            <small class="text-muted">PDF, DOCX, MP4/WebM/MOV video, SCORM (.zip), or HTML. Large videos may require a higher server upload limit.</small>
          </div>
          <div class="col-md-2 d-flex align-items-end justify-content-end">
            <button class="btn btn-primary" type="submit">Upload</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>




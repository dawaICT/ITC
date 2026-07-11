<?php
$page_title = 'Recorded Videos';
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_ui.php';
require_once __DIR__ . '/../includes/elearning_recordings.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = trim((string)($_GET['course_code'] ?? $_POST['course_code'] ?? ''));
if (!$staffId || $courseCode === '') {
    die('Unauthorized');
}

// Allow assigned lecturers OR admin-permission holders to reach this course.
// Only redirect out when the user is neither assigned nor permitted. Per-record
// edit/delete stays owner-scoped (see the POST handlers and action buttons below).
$canManageRecordings = canLecturerAccessElearningCourse($db, $staffId, $courseCode)
    || hasPermission($staffId, 'elearn_admin_all');
if (!$canManageRecordings) {
    enforceLecturerCourseAccess($db, $staffId, $courseCode); // redirects out
}
elearningEnsureRecordingSchema($db);
$courseOfferingIds = getLecturerCourseOfferingIds($db, $staffId, $courseCode);
$courseOfferingId = $courseOfferingIds[0] ?? null;
$recordingsHaveOffering = elearningTableHasCourseOffering($db, 'el_recorded_videos');

$errors = [];
$infoMsg = trim((string)($_GET['notice'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wuc_verify_csrf();
    $action = trim((string)($_POST['action'] ?? 'save_recording'));

    if ($action === 'delete_recording') {
        $recordingId = (int)($_POST['recording_id'] ?? 0);
        if ($recordingId <= 0) {
            $errors[] = 'Invalid recording selected.';
        } else {
            $deleteTypes = 'iss';
            $deleteParams = [$recordingId, $courseCode, $staffId];
            $deleteOfferingSql = elearningOfferingScopeCondition($db, 'el_recorded_videos', null, $courseOfferingIds, $deleteTypes, $deleteParams);
        }
        if (!$errors && ($stmt = $db->prepare("DELETE FROM el_recorded_videos WHERE id = ? AND course_code = ? AND created_by = ? {$deleteOfferingSql}"))) {
            $stmt->bind_param($deleteTypes, ...$deleteParams);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $stmt->close();
                header('Location: recordings.php?course_code=' . urlencode($courseCode) . '&notice=' . urlencode('Recording removed from the portal. The Google Drive file was not deleted.'));
                exit;
            }
            $stmt->close();
            $errors[] = 'Recording was not found or could not be removed.';
        } elseif (!$errors) {
            $errors[] = 'Failed to prepare delete: ' . $db->error;
        }
    } elseif ($action === 'toggle_publish') {
        $recordingId = (int)($_POST['recording_id'] ?? 0);
        $isPublished = isset($_POST['is_published']) ? 1 : 0;
        if ($recordingId <= 0) {
            $errors[] = 'Invalid recording selected.';
        } else {
            $publishTypes = 'iiss';
            $publishParams = [$isPublished, $recordingId, $courseCode, $staffId];
            $publishOfferingSql = elearningOfferingScopeCondition($db, 'el_recorded_videos', null, $courseOfferingIds, $publishTypes, $publishParams);
        }
        if (!$errors && ($stmt = $db->prepare("UPDATE el_recorded_videos SET is_published = ? WHERE id = ? AND course_code = ? AND created_by = ? {$publishOfferingSql}"))) {
            $stmt->bind_param($publishTypes, ...$publishParams);
            if ($stmt->execute()) {
                $stmt->close();
                if ($isPublished === 1) {
                    elearningNotifyCourseRecording($db, $courseCode, 'Recorded class video', $courseOfferingId);
                }
                header('Location: recordings.php?course_code=' . urlencode($courseCode) . '&notice=' . urlencode($isPublished ? 'Recording published to registered students.' : 'Recording hidden from students.'));
                exit;
            }
            $stmt->close();
            $errors[] = 'Failed to update recording.';
        } elseif (!$errors) {
            $errors[] = 'Failed to prepare update: ' . $db->error;
        }
    } else {
        $recordingId = (int)($_POST['recording_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $driveUrl = trim((string)($_POST['drive_url'] ?? ''));
        $recordedAt = elearningNormalizeOptionalDateTime((string)($_POST['recorded_at'] ?? ''));
        $durationRaw = trim((string)($_POST['duration_minutes'] ?? ''));
        $durationMinutes = $durationRaw === '' ? null : max(1, min(1440, (int)$durationRaw));
        $videoFormatRaw = trim((string)($_POST['video_format'] ?? ''));
        $videoFormat = elearningNormalizeVideoFormat($videoFormatRaw);
        $isPublished = isset($_POST['is_published']) ? 1 : 0;
        $driveFileId = elearningExtractGoogleDriveFileId($driveUrl);

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if ($driveFileId === null) {
            $errors[] = 'Paste a valid Google Drive video file link such as https://drive.google.com/file/d/.../view.';
        }
        if (trim((string)($_POST['recorded_at'] ?? '')) !== '' && $recordedAt === null) {
            $errors[] = 'Recorded date/time is invalid.';
        }
        if ($videoFormatRaw !== '' && $videoFormat === null) {
            $errors[] = 'Video format is not supported. Use MP4, WebM, Ogg, or QuickTime.';
        }

        if (!$errors) {
            if ($recordingId > 0) {
                $updateTypes = 'sssssisiiss';
                $updateParams = [$title, $description, $driveFileId, $driveUrl, $recordedAt, $durationMinutes, $videoFormat, $isPublished, $recordingId, $courseCode, $staffId];
                $updateOfferingSql = elearningOfferingScopeCondition($db, 'el_recorded_videos', null, $courseOfferingIds, $updateTypes, $updateParams);
                $sql = "UPDATE el_recorded_videos
                        SET title = ?, description = ?, drive_file_id = ?, drive_url = ?, recorded_at = ?, duration_minutes = ?, video_format = ?, playback_provider = 'google_drive', is_published = ?
                        WHERE id = ? AND course_code = ? AND created_by = ? {$updateOfferingSql}";
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param($updateTypes, ...$updateParams);
                    if ($stmt->execute()) {
                        $stmt->close();
                        if ($isPublished === 1) {
                            elearningNotifyCourseRecording($db, $courseCode, $title, $courseOfferingId);
                        }
                        header('Location: recordings.php?course_code=' . urlencode($courseCode) . '&notice=' . urlencode('Recording updated.'));
                        exit;
                    }
                    $errors[] = 'Failed to update recording: ' . $stmt->error;
                    $stmt->close();
                } else {
                    $errors[] = 'Failed to prepare update: ' . $db->error;
                }
            } else {
                if ($recordingsHaveOffering) {
                    $sql = "INSERT INTO el_recorded_videos
                            (course_offering_id, course_code, title, description, drive_file_id, drive_url, recorded_at, duration_minutes, video_format, playback_provider, is_published, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'google_drive', ?, ?)";
                } else {
                    $sql = "INSERT INTO el_recorded_videos
                            (course_code, title, description, drive_file_id, drive_url, recorded_at, duration_minutes, video_format, playback_provider, is_published, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'google_drive', ?, ?)";
                }
                if ($stmt = $db->prepare($sql)) {
                    if ($recordingsHaveOffering) {
                        $stmt->bind_param('issssssisis', $courseOfferingId, $courseCode, $title, $description, $driveFileId, $driveUrl, $recordedAt, $durationMinutes, $videoFormat, $isPublished, $staffId);
                    } else {
                        $stmt->bind_param('ssssssisis', $courseCode, $title, $description, $driveFileId, $driveUrl, $recordedAt, $durationMinutes, $videoFormat, $isPublished, $staffId);
                    }
                    if ($stmt->execute()) {
                        $stmt->close();
                        $count = $isPublished === 1 ? elearningNotifyCourseRecording($db, $courseCode, $title, $courseOfferingId) : 0;
                        $notice = $isPublished === 1 ? "Recording saved and {$count} student notification(s) created." : 'Recording saved as hidden.';
                        header('Location: recordings.php?course_code=' . urlencode($courseCode) . '&notice=' . urlencode($notice));
                        exit;
                    }
                    $errors[] = 'Failed to save recording: ' . $stmt->error;
                    $stmt->close();
                } else {
                    $errors[] = 'Failed to prepare save: ' . $db->error;
                }
            }
        }
    }
}

$recordings = [];
// Course-scoped list so co-teachers, HODs and admins see every recording for the
// course. Edit/publish/delete stay owner-scoped (enforced in the POST handlers and
// the action buttons below).
$recordingTypes = 's';
$recordingParams = [$courseCode];
$recordingOfferingSql = elearningOfferingScopeCondition($db, 'el_recorded_videos', null, $courseOfferingIds, $recordingTypes, $recordingParams);
if ($stmt = $db->prepare("SELECT * FROM el_recorded_videos WHERE course_code = ? {$recordingOfferingSql} ORDER BY COALESCE(recorded_at, created_at) DESC, id DESC")) {
    $stmt->bind_param($recordingTypes, ...$recordingParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $recordings[] = $row;
    }
    $stmt->close();
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

<div class="elearning-shell">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-record-vinyl"></i> Recorded Videos</h1>
            <p class="elearning-subtitle">Course: <?php echo htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div class="elearning-actions">
            <a class="btn btn-secondary" href="https://drive.google.com/drive/my-drive" target="_blank" rel="noopener noreferrer">
                <i class="fab fa-google-drive"></i> Open Drive
            </a>
        </div>
    </div>
    <?php elearningCourseTabs($courseCode, 'recordings'); ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($infoMsg !== ''): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($infoMsg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <section class="elearning-panel">
                <div class="elearning-panel-header"><strong>Portal Recorder</strong></div>
                <div class="elearning-panel-body">
                    <div class="mb-3">
                        <video id="recordPreview" class="w-100 rounded border bg-dark" playsinline muted controls style="min-height: 220px;"></video>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button class="btn btn-outline-primary" type="button" id="startCamera"><i class="fas fa-camera"></i> Camera</button>
                        <button class="btn btn-outline-primary" type="button" id="startScreen"><i class="fas fa-desktop"></i> Screen</button>
                        <button class="btn btn-danger" type="button" id="startRecording" disabled><i class="fas fa-circle"></i> Record</button>
                        <button class="btn btn-secondary" type="button" id="stopRecording" disabled><i class="fas fa-stop"></i> Stop</button>
                    </div>
                    <div id="recordingStatus" class="alert alert-light border small mb-3">
                        Record in this browser, download the video, upload it to your Google Drive, then paste the shared Drive file link below. No video file is uploaded to the portal.
                    </div>
                    <div id="formatStatus" class="alert alert-secondary small mb-3">
                        Checking browser recording formats...
                    </div>
                    <a class="btn btn-success d-none" id="downloadRecording" download="class-recording.webm">
                        <i class="fas fa-download"></i> Download Recording
                    </a>
                </div>
            </section>
        </div>
        <div class="col-lg-7">
            <section class="elearning-panel">
                <div class="elearning-panel-header"><strong>Add Google Drive Video</strong></div>
                <div class="elearning-panel-body">
                    <form method="post" id="recordingForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="save_recording">
                        <input type="hidden" name="recording_id" id="recordingId" value="0">
                        <input type="hidden" name="video_format" id="recordingFormat" value="">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="recordingTitle">Title</label>
                                <input class="form-control" type="text" name="title" id="recordingTitle" maxlength="255" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="recordedAt">Recorded At</label>
                                <input class="form-control" type="datetime-local" name="recorded_at" id="recordedAt">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="driveUrl">Google Drive Video Link</label>
                                <input class="form-control" type="url" name="drive_url" id="driveUrl" placeholder="https://drive.google.com/file/d/.../view" required>
                                <small class="elearning-muted">Set the Drive file sharing to allow your students to view it.</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="durationMinutes">Duration (minutes)</label>
                                <input class="form-control" type="number" name="duration_minutes" id="durationMinutes" min="1" max="1440">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="formatLabel">Video Format</label>
                                <input class="form-control" type="text" id="formatLabel" value="Drive video" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="recordingDescription">Description</label>
                                <input class="form-control" type="text" name="description" id="recordingDescription" maxlength="1000">
                            </div>
                            <div class="col-12">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_published" id="isPublished" checked>
                                    <span class="form-check-label">Publish to registered students</span>
                                </label>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save Recording</button>
                                <button class="btn btn-outline-secondary" type="button" id="resetRecordingForm"><i class="fas fa-rotate-left"></i> Clear</button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>

    <section class="elearning-panel mt-3">
        <div class="elearning-panel-header"><strong>Saved Recordings</strong></div>
        <div class="elearning-panel-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th>
                            <th>Recorded</th>
                            <th>Duration</th>
                            <th>Format</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recordings): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No recorded videos have been added for this course.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recordings as $recording): ?>
                            <?php
                            $previewUrl = elearningGoogleDrivePreviewUrl($recording['drive_file_id'] ?? null, (string)$recording['drive_url']);
                            $recordedLabel = !empty($recording['recorded_at']) ? date('M d, Y h:i A', strtotime((string)$recording['recorded_at'])) : date('M d, Y h:i A', strtotime((string)$recording['created_at']));
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars((string)$recording['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (!empty($recording['description'])): ?>
                                        <div class="elearning-muted"><?php echo htmlspecialchars((string)$recording['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($recordedLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo !empty($recording['duration_minutes']) ? (int)$recording['duration_minutes'] . ' min' : 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars(elearningVideoFormatLabel($recording['video_format'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ((int)$recording['is_published'] === 1): ?>
                                        <span class="badge bg-success">Published</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                                        <i class="fas fa-play"></i> Preview
                                    </a>
                                    <?php if ((string)($recording['created_by'] ?? '') === (string)$staffId): ?>
                                    <button class="btn btn-sm btn-outline-secondary edit-recording" type="button"
                                            data-id="<?php echo (int)$recording['id']; ?>"
                                            data-title="<?php echo htmlspecialchars((string)$recording['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-description="<?php echo htmlspecialchars((string)$recording['description'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-drive-url="<?php echo htmlspecialchars((string)$recording['drive_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-recorded-at="<?php echo !empty($recording['recorded_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string)$recording['recorded_at'])), ENT_QUOTES, 'UTF-8') : ''; ?>"
                                            data-duration="<?php echo htmlspecialchars((string)($recording['duration_minutes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-video-format="<?php echo htmlspecialchars((string)($recording['video_format'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-format-label="<?php echo htmlspecialchars(elearningVideoFormatLabel($recording['video_format'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-published="<?php echo (int)$recording['is_published']; ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="recording_id" value="<?php echo (int)$recording['id']; ?>">
                                        <input type="hidden" name="action" value="toggle_publish">
                                        <?php if ((int)$recording['is_published'] !== 1): ?>
                                            <input type="hidden" name="is_published" value="1">
                                            <button class="btn btn-sm btn-outline-success" type="submit"><i class="fas fa-eye"></i> Publish</button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-warning" type="submit"><i class="fas fa-eye-slash"></i> Hide</button>
                                        <?php endif; ?>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this recording from the portal? The Google Drive video file will remain in Drive.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="recording_id" value="<?php echo (int)$recording['id']; ?>">
                                        <input type="hidden" name="action" value="delete_recording">
                                        <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fas fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
<script>
(function(){
    var preview = document.getElementById('recordPreview');
    var statusBox = document.getElementById('recordingStatus');
    var startCamera = document.getElementById('startCamera');
    var startScreen = document.getElementById('startScreen');
    var startRecording = document.getElementById('startRecording');
    var stopRecording = document.getElementById('stopRecording');
    var downloadLink = document.getElementById('downloadRecording');
    var formatStatus = document.getElementById('formatStatus');
    var recordingFormat = document.getElementById('recordingFormat');
    var formatLabel = document.getElementById('formatLabel');
    var stream = null;
    var recorder = null;
    var chunks = [];
    var selectedFormat = null;

    var candidateFormats = [
        { mimeType: 'video/mp4;codecs=h264,aac', extension: 'mp4', label: 'MP4 (H.264/AAC)' },
        { mimeType: 'video/mp4;codecs=avc1.42E01E,mp4a.40.2', extension: 'mp4', label: 'MP4 (H.264/AAC)' },
        { mimeType: 'video/mp4', extension: 'mp4', label: 'MP4' },
        { mimeType: 'video/webm;codecs=vp8,opus', extension: 'webm', label: 'WebM (VP8/Opus)' },
        { mimeType: 'video/webm;codecs=vp9,opus', extension: 'webm', label: 'WebM (VP9/Opus)' },
        { mimeType: 'video/webm', extension: 'webm', label: 'WebM' },
        { mimeType: 'video/ogg;codecs=theora,opus', extension: 'ogv', label: 'Ogg video' }
    ];

    function setStatus(message, type) {
        statusBox.className = 'alert alert-' + (type || 'light') + ' border small mb-3';
        statusBox.textContent = message;
    }
    function setFormat(format) {
        selectedFormat = format;
        var value = format ? format.mimeType : '';
        recordingFormat.value = value;
        formatLabel.value = format ? format.label : 'Drive video';
        if (!format) {
            formatStatus.className = 'alert alert-warning small mb-3';
            formatStatus.textContent = 'This browser does not expose MediaRecorder formats. You can still add an existing Google Drive video link.';
            return;
        }
        var message = 'This browser will record ' + format.label + ' and download .' + format.extension + '. ';
        message += format.extension === 'mp4'
            ? 'MP4 gives the broadest compatibility across common video players.'
            : 'Upload to Google Drive so Drive can preview/transcode it, and keep the Drive fallback link available.';
        formatStatus.className = 'alert alert-info small mb-3';
        formatStatus.textContent = message;
    }
    function chooseRecordingFormat() {
        if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
            setFormat(null);
            return null;
        }
        for (var i = 0; i < candidateFormats.length; i++) {
            if (MediaRecorder.isTypeSupported(candidateFormats[i].mimeType)) {
                setFormat(candidateFormats[i]);
                return candidateFormats[i];
            }
        }
        setFormat(null);
        return null;
    }
    function stopStream() {
        if (stream) {
            stream.getTracks().forEach(function(track){ track.stop(); });
        }
        stream = null;
    }
    function attachStream(nextStream) {
        stopStream();
        stream = nextStream;
        preview.srcObject = stream;
        preview.muted = true;
        preview.play().catch(function(){});
        startRecording.disabled = selectedFormat === null;
        setStatus('Preview ready. Start recording when prepared.', 'info');
    }

    startCamera.addEventListener('click', function(){
        navigator.mediaDevices.getUserMedia({ video: true, audio: true })
            .then(attachStream)
            .catch(function(){ setStatus('Camera or microphone access was blocked.', 'warning'); });
    });
    startScreen.addEventListener('click', function(){
        navigator.mediaDevices.getDisplayMedia({ video: true, audio: true })
            .then(attachStream)
            .catch(function(){ setStatus('Screen recording access was blocked.', 'warning'); });
    });
    startRecording.addEventListener('click', function(){
        if (!stream || typeof MediaRecorder === 'undefined') {
            setStatus('Recording is not available in this browser.', 'warning');
            return;
        }
        if (!selectedFormat) {
            setStatus('This browser cannot record a compatible video format. Use an existing MP4/Drive recording link instead.', 'warning');
            return;
        }
        chunks = [];
        recorder = new MediaRecorder(stream, { mimeType: selectedFormat.mimeType });
        recorder.ondataavailable = function(event) {
            if (event.data && event.data.size > 0) { chunks.push(event.data); }
        };
        recorder.onstop = function() {
            var blob = new Blob(chunks, { type: selectedFormat.mimeType });
            var url = URL.createObjectURL(blob);
            var stamp = new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');
            preview.srcObject = null;
            preview.src = url;
            preview.muted = false;
            downloadLink.href = url;
            downloadLink.download = 'class-recording-' + stamp + '.' + selectedFormat.extension;
            downloadLink.classList.remove('d-none');
            stopStream();
            recordingFormat.value = selectedFormat.mimeType;
            formatLabel.value = selectedFormat.label;
            setStatus('Recording ready as ' + selectedFormat.label + '. Download it, upload it to Google Drive, then paste the Drive file link.', 'success');
        };
        recorder.start();
        startRecording.disabled = true;
        stopRecording.disabled = false;
        downloadLink.classList.add('d-none');
        setStatus('Recording in progress...', 'danger');
    });
    stopRecording.addEventListener('click', function(){
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
        stopRecording.disabled = true;
    });

    function clearForm() {
        document.getElementById('recordingId').value = '0';
        document.getElementById('recordingTitle').value = '';
        document.getElementById('recordingDescription').value = '';
        document.getElementById('driveUrl').value = '';
        document.getElementById('recordedAt').value = '';
        document.getElementById('durationMinutes').value = '';
        document.getElementById('recordingFormat').value = selectedFormat ? selectedFormat.mimeType : '';
        document.getElementById('formatLabel').value = selectedFormat ? selectedFormat.label : 'Drive video';
        document.getElementById('isPublished').checked = true;
        document.getElementById('recordingTitle').focus();
    }
    document.getElementById('resetRecordingForm').addEventListener('click', clearForm);
    document.querySelectorAll('.edit-recording').forEach(function(button){
        button.addEventListener('click', function(){
            document.getElementById('recordingId').value = button.dataset.id || '0';
            document.getElementById('recordingTitle').value = button.dataset.title || '';
            document.getElementById('recordingDescription').value = button.dataset.description || '';
            document.getElementById('driveUrl').value = button.dataset.driveUrl || '';
            document.getElementById('recordedAt').value = button.dataset.recordedAt || '';
            document.getElementById('durationMinutes').value = button.dataset.duration || '';
            document.getElementById('recordingFormat').value = button.dataset.videoFormat || '';
            document.getElementById('formatLabel').value = button.dataset.formatLabel || 'Drive video';
            document.getElementById('isPublished').checked = button.dataset.published === '1';
            document.getElementById('recordingForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    chooseRecordingFormat();
})();
</script>
</div>
</div>
</body>
</html>

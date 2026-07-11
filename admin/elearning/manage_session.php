<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
elearning_require_role(['systems_admin','lecturer']);

require_once __DIR__ . '/../../db/connect.php';

$sessionId = (int)($_GET['id'] ?? 0);
$err = null; $ok = null;
$user = $_SESSION['staff_id'] ?? '';

if ($sessionId <= 0) {
    header('Location: sessions.php');
    exit;
}

// Fetch session details
$stmt = $db->prepare("SELECT * FROM lms_sessions WHERE id = ?");
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    header('Location: sessions.php');
    exit;
}

// Authorization check: Verify this lecturer owns the session or is admin
if ($session['created_by'] !== $user && !in_array($_SESSION['role'] ?? '', ['systems_admin'])) {
    http_response_code(403);
    die('You do not have permission to manage this session.');
}

// CSRF Protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Handle status updates and admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'start_session':
            $stmt = $db->prepare("UPDATE lms_sessions SET status = 'live', actual_start_time = NOW() WHERE id = ?");
            $stmt->bind_param('i', $sessionId);
            if ($stmt->execute()) {
                $ok = 'Session started successfully!';
                $session['status'] = 'live';
            }
            $stmt->close();
            break;
            
        case 'end_session':
            $stmt = $db->prepare("UPDATE lms_sessions SET status = 'completed', actual_end_time = NOW() WHERE id = ?");
            $stmt->bind_param('i', $sessionId);
            if ($stmt->execute()) {
                $ok = 'Session ended successfully!';
                $session['status'] = 'completed';
            }
            $stmt->close();
            break;
            
        case 'update_recording':
            $recordingUrl = trim($_POST['recording_url'] ?? '');
            $stmt = $db->prepare("UPDATE lms_sessions SET recording_url = ?, recording_uploaded_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $recordingUrl, $sessionId);
            if ($stmt->execute()) {
                $ok = 'Recording link saved!';
                $session['recording_url'] = $recordingUrl;
            }
            $stmt->close();
            break;
            
        case 'approve_student':
            $studentId = $_POST['student_id'] ?? '';
            $stmt = $db->prepare("INSERT INTO lms_waiting_room_approvals (session_id, student_id, approved_by) VALUES (?, ?, ?)");
            $stmt->bind_param('iss', $sessionId, $studentId, $user);
            if ($stmt->execute()) {
                $ok = 'Student approved to join session!';
            }
            $stmt->close();
            break;
            
        case 'revoke_link':
            $linkId = (int)($_POST['link_id'] ?? 0);
            $stmt = $db->prepare("UPDATE lms_student_meeting_links SET is_revoked = 1, revoked_at = NOW() WHERE id = ? AND session_id = ?");
            $stmt->bind_param('ii', $linkId, $sessionId);
            if ($stmt->execute()) {
                $ok = 'Student access revoked successfully!';
            }
            $stmt->close();
            break;
            
        case 'kick_student':
            $studentId = $_POST['student_id'] ?? '';
            // Revoke all active links for this student
            $stmt = $db->prepare("UPDATE lms_student_meeting_links SET is_revoked = 1, revoked_at = NOW() WHERE student_id = ? AND session_id = ? AND is_revoked = 0");
            $stmt->bind_param('si', $studentId, $sessionId);
            if ($stmt->execute()) {
                // Log the kick action
                $stmt2 = $db->prepare("INSERT INTO lms_meeting_access_attempts (session_id, student_id, ip_address, access_granted, denial_reason) VALUES (?, ?, ?, 0, 'Removed by instructor')");
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $stmt2->bind_param('iss', $sessionId, $studentId, $ip);
                $stmt2->execute();
                $stmt2->close();
                
                $ok = 'Student removed from session!';
            }
            $stmt->close();
            break;
            
        case 'generate_code':
            // Generate format: xxx-xxxx-xxx (lowercase letters only, like Google Meet)
            $chars = 'abcdefghijklmnopqrstuvwxyz';
            // Use random characters (str_shuffle only shuffles, so use random selection)
            $code = '';
            for ($i = 0; $i < 3; $i++) $code .= $chars[random_int(0, 25)];
            $code .= '-';
            for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, 25)];
            $code .= '-';
            for ($i = 0; $i < 3; $i++) $code .= $chars[random_int(0, 25)];
            
            if ($session['provider'] === 'google_meet') {
                $stmt = $db->prepare("UPDATE lms_sessions SET google_meet_id = ? WHERE id = ?");
                $stmt->bind_param('si', $code, $sessionId);
                if ($stmt->execute()) {
                    $ok = 'Google Meet Code generated: ' . $code;
                    $session['google_meet_id'] = $code;
                }
            } else {
                $stmt = $db->prepare("UPDATE lms_sessions SET provider_meeting_id = ? WHERE id = ?");
                $stmt->bind_param('si', $code, $sessionId);
                if ($stmt->execute()) {
                    $ok = 'Meeting Code generated: ' . $code;
                    $session['provider_meeting_id'] = $code;
                }
            }
            $stmt->close();
            break;

        case 'update_meet_link':
            $url = trim($_POST['meet_url'] ?? '');
            $code = '';
            
            // Extract code from URL (meet.google.com/xxx-xxxx-xxx)
            if (preg_match('/meet\.google\.com\/([a-z0-9\-]+)/i', $url, $matches)) {
                $code = $matches[1];
            } else {
                // assume only code was pasted
                $code = $url;
            }
            // Strip any remaining slashes or query params
            $code = explode('?', $code)[0];
            $code = trim($code, '/');
            
            // Validate the code format (typically: xxx-xxxx-xxx - lowercase letters only)
            // Google Meet codes use 3-4-3 lowercase letters pattern (e.g., abc-defg-hij)
            if (empty($code)) {
                $err = 'Please provide a valid Google Meet link or code.';
            } elseif (!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $code)) {
                $err = 'Invalid Google Meet code format. Expected format: xxx-xxxx-xxx using lowercase letters only (e.g., abc-defg-hij)';
            } else {
                $stmt = $db->prepare("UPDATE lms_sessions SET google_meet_id = ? WHERE id = ?");
                $stmt->bind_param('si', $code, $sessionId);
                if ($stmt->execute()) {
                    $ok = 'Google Meet Link updated successfully! Code: ' . $code;
                    $session['google_meet_id'] = $code;
                } else {
                    $err = 'Failed to update meeting link.';
                }
                $stmt->close();
            }
            break;
    }
}

// Fetch current participants (students who have generated links)
$participants = [];
$stmt = $db->prepare("
    SELECT sml.*, s.Fname as first_name, s.Lname as last_name, s.SID as Sid, 
           CASE 
               WHEN sml.used_at IS NOT NULL THEN 'Joined'
               WHEN sml.expires_at < NOW() THEN 'Expired'
               WHEN sml.is_revoked = 1 THEN 'Revoked'
               ELSE 'Ready'
           END as link_status
    FROM lms_student_meeting_links sml
    JOIN students s ON sml.student_id = s.SID
    WHERE sml.session_id = ?
    ORDER BY sml.generated_at DESC
");
if (!$stmt) {
    die('Prepare failed: ' . $db->error);
}
$stmt->bind_param('i', $sessionId);
if (!$stmt->execute()) {
    die('Execute failed: ' . $stmt->error);
}
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $participants[] = $row;
}

// Fetch waiting room queue (if enabled)
$waitingQueue = [];
if ($session['waiting_room_enabled'] ?? false) {
    $stmt = $db->prepare("
        SELECT aa.*, s.Fname as first_name, s.Lname as last_name, s.SID as Sid
        FROM lms_meeting_access_attempts aa
        JOIN students s ON aa.student_id = s.SID
        WHERE aa.session_id = ? 
        AND aa.access_granted = 0
        AND aa.denial_reason = 'waiting_for_approval'
        ORDER BY aa.attempt_time DESC
    ");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $waitingQueue[] = $row;
    }
}

// Fetch attendance/access attempts
$accessLog = [];
$stmt = $db->prepare("
    SELECT aa.*, s.Fname as first_name, s.Lname as last_name, s.SID as Sid
    FROM lms_meeting_access_attempts aa
    LEFT JOIN students s ON aa.student_id = s.SID
    WHERE aa.session_id = ?
    ORDER BY aa.attempt_time DESC
    LIMIT 50
");
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $accessLog[] = $row;
}

// Extract meeting code based on provider
$meetingCode = ($session['provider'] === 'google_meet') 
    ? ($session['google_meet_id'] ?? '') 
    : ($session['provider_meeting_id'] ?? '');

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../css/admin-dashboard.css" />
<style>
.camera-preview-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.9);
    z-index: 9999;
    display: none;
}
.camera-preview-content {
    position: relative;
    max-width: 900px;
    margin: 50px auto;
    background: white;
    border-radius: 8px;
    padding: 30px;
}
.lecturer-video-preview {
    width: 100%;
    height: 400px;
    background: #000;
    border-radius: 8px;
    object-fit: cover;
}
.device-indicator {
    display: inline-flex;
    align-items: center;
    padding: 8px 15px;
    border-radius: 20px;
    margin: 5px;
}
.device-indicator.active {
    background: #d4edda;
    color: #155724;
}
.device-indicator.inactive {
    background: #f8d7da;
    color: #721c24;
}
.participant-item {
    border-bottom: 1px solid #dee2e6;
    padding: 10px;
    transition: background 0.2s;
}
.participant-item:hover {
    background: #f8f9fa;
}
.participant-status {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 8px;
}
.participant-status.online {
    background: #28a745;
}
.participant-status.offline {
    background: #6c757d;
}
.auto-refresh-indicator {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #007bff;
    color: white;
    padding: 10px 20px;
    border-radius: 20px;
    font-size: 0.9rem;
    z-index: 1000;
}
</style>
<div class="container-fluid px-4 portal-dashboard">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Session: <?php echo htmlspecialchars($session['topic']); ?></h2>
    <a href="sessions.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
  
  <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

  <!-- Session Control Panel -->
  <div class="row mb-4">
    <div class="col-md-8">
      <div class="card">
        <div class="card-header">Session Information</div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-6">
              <p><strong>Course:</strong> <?php echo htmlspecialchars($session['course_code']); ?></p>
              <p><strong>Start Time:</strong> <?php echo htmlspecialchars($session['start_time']); ?></p>
              <p><strong>Duration:</strong> <?php echo (int)$session['duration_minutes']; ?> minutes</p>
            </div>
            <div class="col-md-6">
              <p><strong>Status:</strong> 
                <?php 
                  $statusClass = match($session['status']) {
                    'live' => 'primary',
                    'completed' => 'success',
                    default => 'secondary'
                  };
                ?>
                <span class="badge bg-<?php echo $statusClass; ?>"><?php echo ucfirst($session['status']); ?></span>
              </p>
              <p>
                <strong>Meeting Code:</strong> 
                <?php if ($session['provider'] === 'google_meet'): ?>
                  <!-- Google Meet: Must enter valid link manually -->
                  <?php if ($meetingCode): ?>
                    <code class="user-select-all"><?php echo htmlspecialchars($meetingCode); ?></code>
                    <br>
                    <small class="text-muted">
                      Internal Room: <code>lms-session-<?php echo (int)$session['id']; ?></code>
                    </small>
                  <?php else: ?>
                    <span class="text-muted fst-italic">Not defined</span>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#updateLinkModal" title="Paste your Google Meet Link">
                    <i class="fas fa-link"></i> Update Link
                  </button>
                <?php else: ?>
                  <!-- Internal: Can generate random codes -->
                  <?php if ($meetingCode): ?>
                    <code class="user-select-all"><?php echo htmlspecialchars($meetingCode); ?></code>
                  <?php else: ?>
                    <span class="text-muted fst-italic">Not defined</span>
                    <form method="post" class="d-inline ms-2">
                      <input type="hidden" name="action" value="generate_code" />
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>" />
                      <button class="btn btn-sm btn-outline-secondary" type="submit" title="Generate random meeting code">
                        <i class="fas fa-magic"></i> Generate
                      </button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </p>
              <p><strong>Max Participants:</strong> <?php echo (int)($session['max_participants'] ?? 100); ?></p>
              <p>
                <?php if ($session['recording_enabled'] ?? false): ?>
                  <i class="fas fa-record-vinyl text-danger"></i> Recording Enabled
                <?php endif; ?>
                <?php if ($session['waiting_room_enabled'] ?? false): ?>
                  <i class="fas fa-door-open text-info"></i> Waiting Room Active
                <?php endif; ?>
              </p>
            </div>
          </div>
          
          <!-- Lecturer Device Status Panel -->
          <div class="mt-3 p-3 bg-light rounded">
            <h6 class="mb-3"><i class="fas fa-broadcast-tower me-2"></i>Your Equipment Status</h6>
            <div class="d-flex flex-wrap">
              <div class="device-indicator inactive" id="cameraIndicator">
                <i class="fas fa-video me-2"></i>
                <span>Camera: Not Tested</span>
              </div>
              <div class="device-indicator inactive" id="micIndicator">
                <i class="fas fa-microphone me-2"></i>
                <span>Microphone: Not Tested</span>
              </div>
            </div>
            <button class="btn btn-outline-primary btn-sm mt-2" id="testEquipmentBtn">
              <i class="fas fa-check-circle me-1"></i>Test Camera & Microphone
            </button>
          </div>
          
            <!-- Session Actions Area -->
            <div class="mt-3">
              <?php if ($session['provider'] === 'google_meet'): ?>
                <!-- Google Meet Handling -->
                <?php if ($session['status'] !== 'live'): ?>
                  <?php if (empty($meetingCode)): ?>
                    <div class="alert alert-warning mb-3">
                      <i class="fas fa-exclamation-triangle me-2"></i>
                      Please add a Google Meet link before starting the session.
                    </div>
                  <?php endif; ?>
                  <form method="post" class="d-inline" id="startSessionForm" onsubmit="return validateStartSession();">
                    <input type="hidden" name="action" value="start_session" />
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>" />
                    <button class="btn btn-success" type="submit" id="startSessionBtn" 
                            title="Start Session" 
                            <?php echo empty($meetingCode) ? 'disabled' : ''; ?>>
                      <i class="fas fa-play"></i> Start Session
                    </button>
                  </form>
                <?php else: ?>
                  <?php
                    $fallbackMeet = !empty($session['google_meet_id']) ? ('https://meet.google.com/' . $session['google_meet_id']) : 'https://meet.google.com/new';
                    $launchUrl = $session['start_url'] ?: ($session['join_url'] ?: $fallbackMeet);
                    if (($session['provider'] ?? '') === 'zoom' && preg_match('#^https?://(?:[a-z0-9-]+\.)?zoom\.us/j/\d+$#i', (string)$launchUrl)) {
                      $launchUrl = 'https://zoom.us/join';
                    }
                  ?>
                  <a href="<?php echo htmlspecialchars($launchUrl); ?>" target="_blank" class="btn btn-primary">
                    <i class="fas fa-external-link-alt"></i> Launch Meeting
                  </a>
                  <div class="text-muted small mt-1">Platform meeting opens in a new window.</div>
                <?php endif; ?>

              <?php else: // Internal Provider (Embedded) ?>
                
                <?php if ($session['status'] !== 'live'): ?>
                  <form method="post" class="d-inline" id="startSessionForm">
                    <input type="hidden" name="action" value="start_session" />
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>" />
                    <button class="btn btn-success" type="submit" id="startSessionBtn" title="Start Live Classroom">
                      <i class="fas fa-broadcast-tower"></i> Start Live Classroom
                    </button>
                  </form>
                <?php endif; ?>

              <?php endif; ?>

              <!-- Common End Session Button -->
              <?php if ($session['status'] === 'live'): ?>
                <form method="post" class="d-inline ms-2">
                  <input type="hidden" name="action" value="end_session" />
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>" />
                  <button class="btn btn-danger" type="submit" onclick="return confirm('Are you sure you want to end this session for everyone?');">
                    <i class="fas fa-stop"></i> End Session
                  </button>
                </form>
              <?php endif; ?>

              <button class="btn btn-info ms-2" data-bs-toggle="modal" data-bs-target="#recordingModal">
                <i class="fas fa-video"></i> Manage Recording
              </button>
            </div>
        </div>
      </div>
    </div>

    <!-- Embedded Meeting Container (Internal Only) -->
    <?php if ($session['provider'] !== 'google_meet' && $session['status'] === 'live'): ?>
    <div class="col-12 mt-4">
      <div class="card shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>Live Classroom</h5>
          <span class="badge bg-danger animate__animated animate__pulse animate__infinite">LIVE ON AIR</span>
        </div>
        <div class="card-body p-0">
          <div id="meet-embed-container" style="height: 650px; width: 100%; background: #000;"></div>
        </div>
      </div>
    </div>
    <script src='https://meet.jit.si/external_api.js'></script>
    <script>
      let jitsiApi = null;
      
      document.addEventListener('DOMContentLoaded', function() {
        const domain = "meet.jit.si";
        // Create safe room name (sanitized)
        const roomName = "WUC_Live_" + <?php echo json_encode($session['course_code'] . '_' . $sessionId); ?>;
        
        const options = {
          roomName: roomName.replace(/[^a-zA-Z0-9_\-]/g, '_'), // Ensure clean room name
          width: "100%",
          height: 650,
          parentNode: document.querySelector('#meet-embed-container'),
          userInfo: {
            displayName: "Instructor (Host)"
          },
          configOverwrite: { 
            startWithAudioMuted: false, 
            startWithVideoMuted: false,
            prejoinPageEnabled: false
          },
          interfaceConfigOverwrite: {
            TOOLBAR_BUTTONS: [
              'microphone', 'camera', 'closedcaptions', 'desktop', 'fullscreen',
              'fodeviceselection', 'hangup', 'profile', 'chat', 'recording',
              'livestreaming', 'etherpad', 'sharedvideo', 'settings', 'raisehand',
              'videoquality', 'filmstrip', 'invite', 'feedback', 'stats', 'shortcuts',
              'tileview', 'videobackgroundblur', 'download', 'help', 'mute-everyone',
              'security'
            ],
            SHOW_JITSI_WATERMARK: false
          }
        };
        
        jitsiApi = new JitsiMeetExternalAPI(domain, options);
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
          if (jitsiApi) {
            jitsiApi.dispose();
            jitsiApi = null;
          }
        });
      });
    </script>
    <?php endif; ?>
    
    <div class="col-md-4">
      <div class="card">
        <div class="card-header">Live Stats</div>
        <div class="card-body text-center">
          <h1 class="display-4"><?php echo count(array_filter($participants, fn($p) => $p['link_status'] === 'Joined')); ?></h1>
          <p class="text-muted">Active Participants</p>
          <hr>
          <p><strong><?php echo count($participants); ?></strong> Total Links Generated</p>
          <?php if ($session['waiting_room_enabled']): ?>
            <p class="text-warning"><strong><?php echo count($waitingQueue); ?></strong> Waiting for Approval</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Waiting Room (if enabled) -->
  <?php if ($session['waiting_room_enabled'] && count($waitingQueue) > 0): ?>
  <div class="card mb-4">
    <div class="card-header bg-warning text-dark">
      <i class="fas fa-door-open"></i> Waiting Room Queue
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Student</th>
              <th>Student ID</th>
              <th>Waiting Since</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($waitingQueue as $w): ?>
              <tr>
                <td><?php echo htmlspecialchars($w['first_name'] . ' ' . $w['last_name']); ?></td>
                <td><?php echo htmlspecialchars($w['Sid']); ?></td>
                <td><?php echo htmlspecialchars($w['attempt_time']); ?></td>
                <td>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="approve_student" />
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>" />
                    <input type="hidden" name="student_id" value="<?php echo $w['student_id']; ?>" />
                    <button class="btn btn-sm btn-success" type="submit">
                      <i class="fas fa-check"></i> Approve
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Participants -->
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Active Participants (<?php echo count($participants); ?>)</span>
      <button class="btn btn-sm btn-outline-primary" onclick="refreshParticipants()">
        <i class="fas fa-sync-alt"></i> Refresh
      </button>
    </div>
    <div class="card-body" id="participantsTableContainer">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Student</th>
              <th>Student ID</th>
              <th>Link Status</th>
              <th>Generated At</th>
              <th>Joined At</th>
              <th>Expires At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($participants as $p): ?>
              <tr class="participant-item">
                <td>
                  <span class="participant-status <?php echo ($p['link_status'] === 'Joined') ? 'online' : 'offline'; ?>"></span>
                  <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                </td>
                <td><?php echo htmlspecialchars($p['Sid']); ?></td>
                <td>
                  <?php
                    $statusBadge = match($p['link_status']) {
                      'Joined' => 'success',
                      'Ready' => 'primary',
                      'Expired' => 'secondary',
                      'Revoked' => 'danger',
                      default => 'secondary'
                    };
                  ?>
                  <span class="badge bg-<?php echo $statusBadge; ?>"><?php echo $p['link_status']; ?></span>
                </td>
                <td><?php echo htmlspecialchars($p['generated_at']); ?></td>
                <td><?php echo $p['used_at'] ? htmlspecialchars($p['used_at']) : '—'; ?></td>
                <td><?php echo htmlspecialchars($p['expires_at']); ?></td>
                <td>
                  <?php if ($p['link_status'] !== 'Revoked'): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Revoke this student\'s access?');">
                      <input type="hidden" name="action" value="revoke_link" />
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>" />
                      <input type="hidden" name="link_id" value="<?php echo $p['id']; ?>" />
                      <button class="btn btn-sm btn-warning" type="submit" title="Revoke link">
                        <i class="fas fa-ban"></i>
                      </button>
                    </form>
                    <?php if ($p['link_status'] === 'Joined'): ?>
                      <form method="post" class="d-inline ms-1" onsubmit="return confirm('Remove this student from the session?');">
                        <input type="hidden" name="action" value="kick_student" />
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>" />
                        <input type="hidden" name="student_id" value="<?php echo $p['student_id']; ?>" />
                        <button class="btn btn-sm btn-danger" type="submit" title="Remove from session">
                          <i class="fas fa-user-times"></i>
                        </button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Access Log / Attendance -->
  <div class="card">
    <div class="card-header">Access Log & Attendance</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Time</th>
              <th>Student</th>
              <th>Status</th>
              <th>Reason</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accessLog as $log): ?>
              <tr>
                <td><?php echo htmlspecialchars($log['attempt_time']); ?></td>
                <td>
                  <?php if ($log['student_id']): ?>
                    <?php echo htmlspecialchars(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')); ?>
                    <small class="text-muted">(<?php echo htmlspecialchars($log['Sid'] ?? ''); ?>)</small>
                  <?php else: ?>
                    <span class="text-muted">Unknown</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($log['access_granted']): ?>
                    <span class="badge bg-success"><i class="fas fa-check"></i> Granted</span>
                  <?php else: ?>
                    <span class="badge bg-danger"><i class="fas fa-times"></i> Denied</span>
                  <?php endif; ?>
                </td>
                <td><small><?php echo htmlspecialchars($log['denial_reason'] ?? 'Success'); ?></small></td>
                <td><small class="text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></small></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Recording Modal -->
<div class="modal fade" id="recordingModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Session Recording</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="update_recording" />
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>" />
        <div class="modal-body">
          <?php if ($session['recording_url']): ?>
            <div class="alert alert-success">
              <i class="fas fa-check-circle"></i> Recording available
              <br><a href="<?php echo htmlspecialchars($session['recording_url']); ?>" target="_blank">View Recording</a>
            </div>
          <?php endif; ?>
          
          <div class="mb-3">
            <label class="form-label">Recording URL (Google Drive/YouTube)</label>
            <input type="url" class="form-control" name="recording_url" 
                   value="<?php echo htmlspecialchars($session['recording_url'] ?? ''); ?>" 
                   placeholder="https://drive.google.com/..." />
            <div class="form-text">Paste the shareable link to your Google Meet recording</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Recording Link</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Update Google Meet Link Modal -->
<div class="modal fade" id="updateLinkModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Google Meet Link</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="update_meet_link" />
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>" />
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Paste Google Meet Link</label>
            <input type="text" class="form-control" name="meet_url" required
                   placeholder="https://meet.google.com/abc-defg-hij" 
                   value="<?php echo $session['google_meet_id'] ? 'https://meet.google.com/'.$session['google_meet_id'] : ''; ?>"
                   pattern=".*(meet\.google\.com\/[a-z]{3}-[a-z]{4}-[a-z]{3}|^[a-z]{3}-[a-z]{4}-[a-z]{3}$).*"
                   title="Enter full Google Meet URL or just the meeting code (e.g., abc-defg-hij)">
            <div class="form-text">
              The portal now hosts meetings in the internal live room. External meeting links are optional.<br>
              <strong>Expected format:</strong> <code>https://meet.google.com/abc-defg-hij</code><br>
              <small class="text-muted">The code should be 3 lowercase letters, dash, 4 lowercase letters, dash, 3 lowercase letters</small>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Link</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Store interval ID for cleanup
let autoRefreshInterval = null;

// Auto-refresh participant count every 30 seconds
autoRefreshInterval = setInterval(function() {
  // Check if any modal is open (Bootstrap adds 'modal-open' to body)
  const isBootstrapModalOpen = document.body.classList.contains('modal-open');
  
  // Check if our custom camera modal is open
  const cameraModal = document.getElementById('cameraModal');
  const isCameraModalOpen = cameraModal && cameraModal.style.display !== 'none';
  
  // Only refresh if no modals are open
  if (!isBootstrapModalOpen && !isCameraModalOpen) {
     console.log('Refreshing session data...');
     location.reload();
  } else {
     console.log('Auto-refresh skipped: User interaction detected');
  }
}, 30000);

// Clean up interval on page unload
window.addEventListener('beforeunload', function() {
  if (autoRefreshInterval) {
    clearInterval(autoRefreshInterval);
  }
});

// Camera & Microphone Test Modal
const cameraModal = document.createElement('div');
cameraModal.className = 'camera-preview-modal';
cameraModal.id = 'cameraModal';
cameraModal.innerHTML = `
  <div class="camera-preview-content">
    <h3 class="mb-3"><i class="fas fa-video me-2"></i>Equipment Check</h3>
    <p class="text-muted">Test your camera and microphone before starting the session</p>
    
    <video id="lecturerVideoPreview" class="lecturer-video-preview mb-3" autoplay muted playsinline></video>
    
    <div class="mb-3">
      <label class="form-label">Select Camera</label>
      <select class="form-select" id="cameraSelect">
        <option>Loading cameras...</option>
      </select>
    </div>
    
    <div class="mb-3">
      <label class="form-label">Select Microphone</label>
      <select class="form-select" id="microphoneSelect">
        <option>Loading microphones...</option>
      </select>
    </div>
    
    <div class="mb-3">
      <div class="progress" style="height: 30px;">
        <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="audioLevelBar" style="width: 0%">
          <span id="audioLevelText">Speak to test microphone</span>
        </div>
      </div>
    </div>
    
    <div class="d-flex justify-content-between">
      <button class="btn btn-secondary" onclick="closeCamera()">
        <i class="fas fa-times me-1"></i>Cancel
      </button>
      <button class="btn btn-success" onclick="confirmEquipment()">
        <i class="fas fa-check me-1"></i>Equipment Working
      </button>
    </div>
  </div>
`;
document.body.appendChild(cameraModal);

let videoStream = null;
let audioStream = null;
let audioContext = null;
let analyser = null;
let animationId = null;
let equipmentTestInitialized = false;

// Prevent duplicate event listeners
const testBtn = document.getElementById('testEquipmentBtn');
if (testBtn && !equipmentTestInitialized) {
  testBtn.addEventListener('click', async () => {
    // Clean up any existing streams first
    cleanupStreams();
    document.getElementById('cameraModal').style.display = 'block';
    await startEquipmentTest();
  });
  equipmentTestInitialized = true;
}

function cleanupStreams() {
  if (videoStream) {
    videoStream.getTracks().forEach(track => track.stop());
    videoStream = null;
  }
  if (audioStream) {
    audioStream.getTracks().forEach(track => track.stop());
    audioStream = null;
  }
  if (audioContext) {
    audioContext.close();
    audioContext = null;
  }
  if (animationId) {
    cancelAnimationFrame(animationId);
    animationId = null;
  }
}

async function startEquipmentTest() {
  try {
    // Request camera
    videoStream = await navigator.mediaDevices.getUserMedia({ 
      video: { width: { ideal: 1280 }, height: { ideal: 720 } } 
    });
    document.getElementById('lecturerVideoPreview').srcObject = videoStream;
    
    // Request microphone
    audioStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    
    // Setup audio visualization
    audioContext = new (window.AudioContext || window.webkitAudioContext)();
    analyser = audioContext.createAnalyser();
    const source = audioContext.createMediaStreamSource(audioStream);
    analyser.fftSize = 256;
    source.connect(analyser);
    
    const bufferLength = analyser.frequencyBinCount;
    const dataArray = new Uint8Array(bufferLength);
    
    function updateAudioLevel() {
      animationId = requestAnimationFrame(updateAudioLevel);
      analyser.getByteFrequencyData(dataArray);
      
      const average = dataArray.reduce((sum, value) => sum + value, 0) / bufferLength;
      const percentage = (average / 255) * 100;
      
      document.getElementById('audioLevelBar').style.width = percentage + '%';
      if (percentage > 5) {
        document.getElementById('audioLevelText').textContent = `Level: ${Math.round(percentage)}%`;
      }
    }
    updateAudioLevel();
    
    // Load devices
    await loadDevicesList();
    
  } catch (error) {
    console.error('Equipment test error:', error);
    alert('Failed to access camera or microphone. Please check your browser permissions.');
    closeCamera();
  }
}

async function loadDevicesList() {
  try {
    const devices = await navigator.mediaDevices.enumerateDevices();
    
    const cameras = devices.filter(d => d.kind === 'videoinput');
    const microphones = devices.filter(d => d.kind === 'audioinput');
    
    const cameraSelect = document.getElementById('cameraSelect');
    cameraSelect.innerHTML = cameras.map(d => 
      `<option value="${d.deviceId}">${d.label || 'Camera ' + (cameras.indexOf(d) + 1)}</option>`
    ).join('');
    
    const micSelect = document.getElementById('microphoneSelect');
    micSelect.innerHTML = microphones.map(d => 
      `<option value="${d.deviceId}">${d.label || 'Microphone ' + (microphones.indexOf(d) + 1)}</option>`
    ).join('');
    
    // Remove old event listeners by cloning (prevents duplicates)
    const newCameraSelect = cameraSelect.cloneNode(true);
    cameraSelect.parentNode.replaceChild(newCameraSelect, cameraSelect);
    
    const newMicSelect = micSelect.cloneNode(true);
    micSelect.parentNode.replaceChild(newMicSelect, micSelect);
    
    // Handle device changes
    newCameraSelect.addEventListener('change', async (e) => {
      if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
      }
      try {
        videoStream = await navigator.mediaDevices.getUserMedia({ 
          video: { deviceId: { exact: e.target.value } } 
        });
        document.getElementById('lecturerVideoPreview').srcObject = videoStream;
      } catch (error) {
        console.error('Camera switch error:', error);
      }
    });
    
    newMicSelect.addEventListener('change', async (e) => {
      if (audioStream) {
        audioStream.getTracks().forEach(track => track.stop());
      }
      if (audioContext) {
        audioContext.close();
      }
      try {
        audioStream = await navigator.mediaDevices.getUserMedia({ 
          audio: { deviceId: { exact: e.target.value } } 
        });
        
        // Reconnect audio analyzer
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioContext.createAnalyser();
        const source = audioContext.createMediaStreamSource(audioStream);
        analyser.fftSize = 256;
        source.connect(analyser);
      } catch (error) {
        console.error('Microphone switch error:', error);
      }
    });
    
  } catch (error) {
    console.error('Error loading devices:', error);
  }
}

function closeCamera() {
  cleanupStreams();
  const modal = document.getElementById('cameraModal');
  if (modal) {
    modal.style.display = 'none';
  }
}

function confirmEquipment() {
  // Update indicators
  document.getElementById('cameraIndicator').className = 'device-indicator active';
  document.getElementById('cameraIndicator').innerHTML = '<i class="fas fa-video me-2"></i><span>Camera: Ready</span>';
  
  document.getElementById('micIndicator').className = 'device-indicator active';
  document.getElementById('micIndicator').innerHTML = '<i class="fas fa-microphone me-2"></i><span>Microphone: Ready</span>';
  
  // Enable start session button
  const startBtn = document.getElementById('startSessionBtn');
  if (startBtn) {
    startBtn.disabled = false;
    startBtn.title = 'Start your live session';
  }
  
  closeCamera();
  alert('Equipment check complete! You can now start the session.');
}

// Auto-refresh indicator
const refreshIndicator = document.createElement('div');
refreshIndicator.className = 'auto-refresh-indicator';
refreshIndicator.innerHTML = '<i class="fas fa-sync-alt fa-spin me-2"></i>Auto-refreshing...';
refreshIndicator.style.display = 'none';
document.body.appendChild(refreshIndicator);

function showRefreshIndicator() {
  refreshIndicator.style.display = 'block';
  setTimeout(() => {
    refreshIndicator.style.display = 'none';
  }, 1000);
}

function refreshParticipants() {
  showRefreshIndicator();
  location.reload();
}

function validateStartSession() {
  <?php if ($session['provider'] === 'google_meet'): ?>
  const meetingCode = <?php echo json_encode($meetingCode); ?>;
  if (!meetingCode || meetingCode.trim() === '') {
    alert('Please add a Google Meet link before starting the session.\n\nClick the "Update Link" button to paste your Google Meet link.');
    return false;
  }
  <?php endif; ?>
  return true;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


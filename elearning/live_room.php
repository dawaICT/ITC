<?php
error_reporting(0);
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/portal_access.php';

checkStaffAuth();
wuc_require_portal_access($db, 'elearning');

$staffId = $_SESSION['user_id'] ?? '';
$displayName = 'Portal User';
$userRole = 'moderator';

if ($staffId !== '' && ($stmt = $db->prepare("SELECT Fname, Lname FROM staff WHERE staff_id = ? LIMIT 1"))) {
	$stmt->bind_param('s', $staffId);
	$stmt->execute();
	$row = $stmt->get_result()->fetch_assoc();
	$stmt->close();
	if ($row) {
		$displayName = trim(($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? ''));
	}
}
if ($displayName === '' || $displayName === 'Portal User') {
	$displayName = (string)$staffId;
}

$platform = trim($_GET['platform'] ?? 'google_meet');
if (!in_array($platform, ['zoom', 'teams', 'google_meet'], true)) {
	$platform = 'google_meet';
}

$room = strtolower(trim($_GET['room'] ?? ''));
$room = preg_replace('/[^a-z0-9-]/', '', $room);
if ($room === '') {
	$room = 'wucportal-' . date('YmdHis');
}

$platformLabel = ucwords(str_replace('_', ' ', $platform));
$jitsiRoom = 'wucportal-' . substr($room, 0, 72);
$defaultReturnUrl = STAFF_DASHBOARD;
$returnUrl = trim((string)($_GET['return'] ?? ''));
if ($returnUrl === '' || preg_match('#^(?:https?:)?//#i', $returnUrl)) {
	$returnUrl = $defaultReturnUrl;
} elseif ($returnUrl[0] !== '/') {
	$returnUrl = '/' . ltrim($returnUrl, './');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Live Room - <?php echo htmlspecialchars($platformLabel); ?></title>
	<link rel="stylesheet" href="/wucportal/css/admin-style.css">
	<style>
		body { margin: 0; background: #f3f6fb; font-family: Arial, sans-serif; }
		.live-room-header { padding: 14px 18px; background: #1f3a56; color: #fff; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
		.live-room-title { font-size: 16px; font-weight: 600; }
		.live-room-note { font-size: 13px; opacity: 0.9; }
		.role-badge { background: #0f8f55; color: #fff; padding: 3px 8px; border-radius: 12px; font-size: 11px; margin-left: 8px; vertical-align: middle; }
		.portal-back-btn { border: 1px solid rgba(255,255,255,0.45); color: #fff; text-decoration: none; padding: 7px 10px; border-radius: 6px; font-size: 13px; white-space: nowrap; }
		.portal-back-btn:hover { background: rgba(255,255,255,0.12); color: #fff; }
		.live-room-wrap { height: calc(100vh - 56px); }
		#jitsi-container { width: 100%; height: 100%; }
	</style>
</head>
<body>
	<div class="live-room-header">
		<div class="live-room-title">ITC Portal Live Room</div>
		<div class="live-room-note">
			Platform: <?php echo htmlspecialchars($platformLabel); ?> | Room: <?php echo htmlspecialchars($room); ?> | User: <?php echo htmlspecialchars($displayName); ?>
			<?php if ($userRole === 'moderator'): ?><span class="role-badge">Moderator</span><?php endif; ?>
		</div>
		<a class="portal-back-btn" href="<?php echo htmlspecialchars($returnUrl); ?>" id="portalBackBtn">Back to Portal</a>
	</div>
	<div class="live-room-wrap">
		<div id="jitsi-container"></div>
	</div>
	<script src="https://meet.jit.si/external_api.js"></script>
	<script>
	(function(){
		var domain = 'meet.jit.si';
		var role = <?php echo json_encode($userRole); ?>;
		var name = <?php echo json_encode($displayName); ?>;
		var roomName = <?php echo json_encode($jitsiRoom); ?>;
		var returnUrl = <?php echo json_encode($returnUrl); ?>;
		var left = false;
		var goBack = function(){
			if (left) { return; }
			left = true;
			window.location.href = returnUrl;
		};
		var options = {
			roomName: roomName,
			parentNode: document.getElementById('jitsi-container'),
			width: '100%',
			height: '100%',
			userInfo: { displayName: name },
			configOverwrite: {
				prejoinPageEnabled: false,
				startWithAudioMuted: false,
				startWithVideoMuted: false
			}
		};
		var api = new JitsiMeetExternalAPI(domain, options);
		api.executeCommand('displayName', name);
		api.addEventListener('videoConferenceLeft', goBack);
		api.addEventListener('readyToClose', goBack);
		api.addEventListener('conferenceTerminated', goBack);
		document.getElementById('portalBackBtn').addEventListener('click', function(){
			try { api.dispose(); } catch (e) {}
		});
	})();
	</script>
</body>
</html>


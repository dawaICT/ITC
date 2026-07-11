<?php
$page_title = 'Live Sessions';
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_ui.php';
require_once __DIR__ . '/../includes/elearning_live_sessions.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = $_GET['course_code'] ?? '';
if (!$staffId) { die('Unauthorized'); }
if (!$courseCode) {
	wuc_safe_redirect('/wucportal/elearning/courses.php');
}

// Allow assigned lecturers OR admin-permission holders to reach this course.
// Only redirect out when the user is neither assigned nor permitted.
$canSchedule = canLecturerAccessElearningCourse($db, $staffId, $courseCode)
	|| hasPermission($staffId, 'elearn_schedule_sessions')
	|| hasPermission($staffId, 'elearn_admin_all');
if (!$canSchedule) {
	enforceLecturerCourseAccess($db, $staffId, $courseCode); // redirects out
}
$courseOfferingIds = getLecturerCourseOfferingIds($db, $staffId, $courseCode);
$courseOfferingId = $courseOfferingIds[0] ?? null;
$errors = [];
$infoMsg = '';
elearningEnsureLiveSessionLinkTable($db);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	if (!$canSchedule) {
		$errors[] = "You don't have permission to schedule sessions.";
	} else {
		wuc_verify_csrf();
		$action = trim((string)($_POST['action'] ?? 'schedule'));

		if ($action === 'cancel_session') {
			$sessionId = (int)($_POST['session_id'] ?? 0);
			$reason = trim((string)($_POST['reason'] ?? ''));
			if ($sessionId <= 0) {
				$errors[] = 'Invalid session selected.';
			}
			if (!$errors) {
				$cancelTypes = 'sis';
				$cancelParams = [$reason, $sessionId, $courseCode];
				$cancelOfferingSql = elearningOfferingScopeCondition($db, 'el_live_sessions', null, $courseOfferingIds, $cancelTypes, $cancelParams);
				if ($stmt = $db->prepare("UPDATE el_live_sessions SET status='cancelled', status_reason=?, status_updated_at=NOW() WHERE id=? AND course_code=? {$cancelOfferingSql}")) {
					$stmt->bind_param($cancelTypes, ...$cancelParams);
					if ($stmt->execute() && $stmt->affected_rows > 0) {
						$stmt->close();
						if ($revoke = $db->prepare("UPDATE el_live_session_links SET revoked_at=NOW() WHERE session_id=? AND revoked_at IS NULL")) {
							$revoke->bind_param('i', $sessionId);
							$revoke->execute();
							$revoke->close();
						}
						$body = 'A live session for ' . $courseCode . ' was cancelled.' . ($reason !== '' ? ' Reason: ' . $reason : '');
						$count = elearningNotifyCourseStudents($db, $courseCode, $sessionId, 'live_session_cancelled', 'Live session cancelled', $body, $courseOfferingId);
						header('Location: sessions.php?course_code=' . urlencode($courseCode) . '&notice=' . urlencode("Session cancelled. {$count} student notification(s) created."));
						exit;
					}
					$stmt->close();
					$errors[] = 'Session was not found or could not be cancelled.';
				} else {
					$errors[] = 'Failed to prepare cancellation: ' . $db->error;
				}
			}
		} elseif ($action === 'postpone_session') {
			$sessionId = (int)($_POST['session_id'] ?? 0);
			$newStart = elearningNormalizeDateTimeInput((string)($_POST['new_start_time'] ?? ''));
			$newEnd = elearningNormalizeDateTimeInput((string)($_POST['new_end_time'] ?? ''));
			$reason = trim((string)($_POST['reason'] ?? ''));
			if ($sessionId <= 0 || $newStart === null || $newEnd === null) {
				$errors[] = 'Select a valid session and new start/end time.';
			} elseif (strtotime($newEnd) <= strtotime($newStart)) {
				$errors[] = 'New end time must be after the new start time.';
			}
			if (!$errors) {
				$current = null;
				$currentTypes = 'is';
				$currentParams = [$sessionId, $courseCode];
				$currentOfferingSql = elearningOfferingScopeCondition($db, 'el_live_sessions', null, $courseOfferingIds, $currentTypes, $currentParams);
				if ($stmt = $db->prepare("SELECT start_time, end_time FROM el_live_sessions WHERE id=? AND course_code=? {$currentOfferingSql} LIMIT 1")) {
					$stmt->bind_param($currentTypes, ...$currentParams);
					$stmt->execute();
					$current = $stmt->get_result()->fetch_assoc();
					$stmt->close();
				}
				if (!$current) {
					$errors[] = 'Session was not found.';
				} else {
					$postponeTypes = 'sssis';
					$postponeParams = [$newStart, $newEnd, $reason, $sessionId, $courseCode];
					$postponeOfferingSql = elearningOfferingScopeCondition($db, 'el_live_sessions', null, $courseOfferingIds, $postponeTypes, $postponeParams);
					$stmt = $db->prepare("UPDATE el_live_sessions SET postponed_from_start=COALESCE(postponed_from_start, start_time), postponed_from_end=COALESCE(postponed_from_end, end_time), start_time=?, end_time=?, status='postponed', status_reason=?, status_updated_at=NOW() WHERE id=? AND course_code=? {$postponeOfferingSql}");
				}
				if (!$errors && $stmt) {
					$stmt->bind_param($postponeTypes, ...$postponeParams);
					if ($stmt->execute()) {
						$stmt->close();
						if ($revoke = $db->prepare("UPDATE el_live_session_links SET revoked_at=NOW() WHERE session_id=? AND revoked_at IS NULL")) {
							$revoke->bind_param('i', $sessionId);
							$revoke->execute();
							$revoke->close();
						}
						$body = 'A live session for ' . $courseCode . ' was postponed to ' . date('M d, Y h:i A', strtotime($newStart)) . ' - ' . date('h:i A', strtotime($newEnd)) . '.';
						if ($reason !== '') { $body .= ' Reason: ' . $reason; }
						$count = elearningNotifyCourseStudents($db, $courseCode, $sessionId, 'live_session_postponed', 'Live session postponed', $body, $courseOfferingId);
						header('Location: sessions.php?course_code=' . urlencode($courseCode) . '&notice=' . urlencode("Session postponed. {$count} student notification(s) created."));
						exit;
					}
					$stmt->close();
					$errors[] = 'Failed to postpone session.';
				}
			}
		} else {
		$platform = trim($_POST['platform'] ?? 'internal');
		if ($platform === 'meet') { $platform = 'google_meet'; }
		if (!in_array($platform, ['internal','zoom','teams','google_meet'], true)) { $platform = 'internal'; }

		$topic = trim($_POST['topic'] ?? '');
		$startRaw = trim($_POST['start_time'] ?? '');
		$endRaw = trim($_POST['end_time'] ?? '');
		$joinUrl = trim($_POST['join_url'] ?? ''); $roomName = null;

		// Normalize datetime-local to MySQL DATETIME (YYYY-MM-DD HH:MM:SS)
		$start = elearningNormalizeDateTimeInput($startRaw);
		$end = elearningNormalizeDateTimeInput($endRaw);
		if ($start === null) {
			$errors[] = 'Invalid start time format.';
		}
		if ($end === null) {
			$errors[] = 'Invalid end time format.';
		} elseif (!$errors && $start !== null && strtotime($end) <= strtotime($start)) {
			$errors[] = 'End time must be after the start time.';
		}

		if ($topic === '') { $errors[] = 'Topic is required.'; }
		if ($platform === 'internal') {
				$roomName = elearningGenerateInternalRoomName($courseCode);
				$joinUrl = elearningInternalRoomUrl($roomName);
			} elseif ($joinUrl === '' || !elearningAllowedMeetingHost($platform, $joinUrl)) {
			$errors[] = 'Enter a real meeting URL for the selected platform: Google Meet code links, Teams meetup-join links, or Zoom /j/ meeting links.';
		}

		if (!$errors) {
			if (elearningTableHasCourseOffering($db, 'el_live_sessions')) {
				$stmt = $db->prepare("INSERT INTO el_live_sessions (course_offering_id, course_code, platform, topic, start_time, end_time, join_url, host_url, external_meeting_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
			} else {
				$stmt = $db->prepare("INSERT INTO el_live_sessions (course_code, platform, topic, start_time, end_time, join_url, host_url, external_meeting_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
			}
			if ($stmt) {
				$bound = elearningTableHasCourseOffering($db, 'el_live_sessions')
					? $stmt->bind_param('isssssssss', $courseOfferingId, $courseCode, $platform, $topic, $start, $end, $joinUrl, $joinUrl, $roomName, $staffId)
					: $stmt->bind_param('sssssssss', $courseCode, $platform, $topic, $start, $end, $joinUrl, $joinUrl, $roomName, $staffId);
				if ($bound && $stmt->execute()) {
					$sessionId = (int)$stmt->insert_id;
					$stmt->close();
					$body = 'A new live session for ' . $courseCode . ' was scheduled: ' . $topic . ' on ' . date('M d, Y h:i A', strtotime($start)) . ' - ' . date('h:i A', strtotime($end)) . '.';
					$count = elearningNotifyCourseStudents($db, $courseCode, $sessionId, 'live_session_scheduled', 'New live session scheduled', $body, $courseOfferingId);
					header('Location: sessions.php?course_code=' . urlencode($courseCode) . '&notice=' . urlencode("Session scheduled. {$count} student notification(s) created."));
					exit;
				} else {
					$errors[] = 'Failed to save session: ' . $db->error;
					$stmt && $stmt->close();
				}
			} else {
				$errors[] = 'Failed to prepare statement: ' . $db->error;
			}
		}
		}
	}
}

$sessions = [];
$sessionTypes = 's';
$sessionParams = [$courseCode];
$sessionOfferingSql = elearningOfferingScopeCondition($db, 'el_live_sessions', null, $courseOfferingIds, $sessionTypes, $sessionParams);
if ($stmt = $db->prepare("SELECT * FROM el_live_sessions WHERE course_code=? {$sessionOfferingSql} ORDER BY start_time DESC")) {
	$stmt->bind_param($sessionTypes, ...$sessionParams);
	$stmt->execute(); $res = $stmt->get_result();
	while ($row = $res->fetch_assoc()) { $sessions[] = $row; }
	$stmt->close();
}

if (!empty($_GET['notice'])) {
	$infoMsg = trim((string)$_GET['notice']);
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

<div class="elearning-shell">
	<div class="elearning-header">
		<div>
			<h1 class="elearning-title"><i class="fas fa-video"></i> Live Sessions</h1>
			<p class="elearning-subtitle">Course: <?php echo htmlspecialchars($courseCode); ?></p>
		</div>
	</div>
	<?php elearningCourseTabs($courseCode, 'sessions'); ?>
	<?php if ($errors): ?>
		<div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
	<?php endif; ?>
	<?php if ($infoMsg): ?>
		<div class="alert alert-info"><?php echo htmlspecialchars($infoMsg); ?></div>
	<?php endif; ?>
	<div class="card">
		<div class="card-header"><strong>Schedule New</strong></div>
		<div class="card-body">
			<?php if (!$canSchedule): ?>
				<div class="alert alert-warning mb-3">You don't have permission to schedule sessions.</div>
			<?php endif; ?>
			<form method="post">
				<input type="hidden" name="action" value="schedule">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
				<div class="row g-3 align-items-end">
					<div class="col-md-2">
						<label class="form-label">Platform</label>
						<select name="platform" id="platform" class="form-select" <?php echo !$canSchedule ? 'disabled' : ''; ?>>
							<option value="zoom">Zoom</option>
							<option value="teams">Teams</option>
							<option value="internal" selected>Portal Room (in-system)</option>
								<option value="google_meet">Google Meet</option>
						</select>
					</div>
					<div class="col-md-3">
						<label class="form-label">Topic</label>
						<input class="form-control" type="text" name="topic" id="topic" required <?php echo !$canSchedule ? 'disabled' : ''; ?>>
					</div>
					<div class="col-md-2">
						<label class="form-label">Start Time</label>
						<input class="form-control" type="datetime-local" name="start_time" id="start_time" required <?php echo !$canSchedule ? 'disabled' : ''; ?>>
					</div>
					<div class="col-md-2">
						<label class="form-label">End Time</label>
						<input class="form-control" type="datetime-local" name="end_time" id="end_time" required <?php echo !$canSchedule ? 'disabled' : ''; ?>>
					</div>
					<div class="col-md-4">
						<label class="form-label">Join URL</label>
						<div class="input-group">
							<input class="form-control" type="url" name="join_url" id="join_url" placeholder="Auto-generated for Portal Room; paste a real URL for external platforms" <?php echo !$canSchedule ? 'disabled' : ''; ?>>
							<button class="btn btn-outline-secondary" type="button" id="btn-generate-link" title="Open provider meeting page" <?php echo !$canSchedule ? 'disabled' : ''; ?>><i class="fas fa-external-link-alt"></i></button>
						</div>
						<small class="elearning-muted">Create the meeting in the provider, paste its real join URL here, then students receive secure portal links.</small>
					</div>
					<div class="col-md-1">
						<button class="btn btn-primary" type="submit" <?php echo !$canSchedule ? 'disabled' : ''; ?>><i class="fas fa-plus"></i></button>
					</div>
				</div>
			</form>
		</div>
	</div>
	<div class="card mt-3">
		<div class="card-header"><strong>Upcoming/Recent Sessions</strong></div>
		<div class="card-body p-0">
			<div class="table-responsive">
				<table class="table table-hover align-middle mb-0 sessions-table">
					<thead class="table-light">
						<tr>
							<th>Topic</th>
							<th>Platform</th>
							<th>Start Time</th>
							<th>End Time</th>
							<th>Status</th>
							<th class="text-center">Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php if (count($sessions) === 0): ?>
							<tr>
								<td colspan="6" class="text-center py-4 text-muted">
									<i class="fas fa-calendar-times fa-2x mb-2"></i><br>
									No sessions scheduled yet.
								</td>
							</tr>
						<?php else: ?>
							<?php foreach ($sessions as $s): 
								$startTime = strtotime($s['start_time']);
								$endTime = !empty($s['end_time']) ? strtotime($s['end_time']) : ($startTime + 3600);
								$now = time();
								$isPast = $endTime < $now;
								$isNow = ($startTime <= $now && $endTime >= $now);
								$status = strtolower((string)($s['status'] ?? 'scheduled'));
								$isCancelled = $status === 'cancelled';
								$isPostponed = $status === 'postponed';
								$meetingUrlValid = elearningAllowedMeetingHost((string)$s['platform'], (string)$s['join_url']);
								$platformLabel = $s['platform'] === 'internal' ? 'Portal Room' : ucwords(str_replace('_', ' ', $s['platform']));
								$platformIcon = match($s['platform']) {
									'zoom' => 'fa-video',
									'teams' => 'fa-microsoft',
									'google_meet' => 'fa-google',
										'internal' => 'fa-chalkboard-teacher',
									default => 'fa-link'
								};
							?>
								<tr>
									<td><strong><?php echo htmlspecialchars($s['topic']); ?></strong></td>
									<td>
										<span class="badge bg-secondary">
											<i class="fas <?php echo $platformIcon; ?> me-1"></i>
											<?php echo htmlspecialchars($platformLabel); ?>
										</span>
									</td>
									<td><?php echo date('M d, Y \a\t h:i A', $startTime); ?></td>
									<td><?php echo date('M d, Y \a\t h:i A', $endTime); ?></td>
									<td>
										<?php if ($isCancelled): ?>
											<span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Cancelled</span>
										<?php elseif ($isPostponed): ?>
											<span class="badge bg-warning text-dark"><i class="fas fa-calendar-day me-1"></i>Postponed</span>
										<?php elseif ($isNow): ?>
											<span class="badge bg-success"><i class="fas fa-circle-dot me-1"></i>Live Now</span>
										<?php elseif ($isPast): ?>
											<span class="badge bg-secondary"><i class="fas fa-check me-1"></i>Completed</span>
										<?php else: ?>
											<span class="badge bg-primary"><i class="fas fa-clock me-1"></i>Upcoming</span>
										<?php endif; ?>
									</td>
									<td class="text-center">
										<?php if ($meetingUrlValid && !$isCancelled): ?>
											<a href="join_session.php?session_id=<?php echo (int)$s['id']; ?>&amp;course_code=<?php echo urlencode($courseCode); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary" title="Open the same meeting assigned to students">
												<i class="fas fa-external-link-alt"></i> Join
											</a>
										<?php else: ?>
											<span class="badge bg-warning text-dark" title="Paste a real provider meeting URL when creating the session.">Invalid URL</span>
										<?php endif; ?>
										<?php if (!$isCancelled): ?>
											<button class="btn btn-sm btn-outline-warning mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#postpone-<?php echo (int)$s['id']; ?>" aria-expanded="false">
												<i class="fas fa-calendar-plus"></i> Postpone
											</button>
											<form class="d-inline" method="post" onsubmit="return confirm('Cancel this live session and notify students?');">
												<input type="hidden" name="action" value="cancel_session">
												<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
												<input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
												<input type="hidden" name="reason" value="Cancelled by lecturer">
												<button class="btn btn-sm btn-outline-danger mt-1" type="submit">
													<i class="fas fa-ban"></i> Cancel
												</button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
								<?php if (!$isCancelled): ?>
									<tr class="collapse" id="postpone-<?php echo (int)$s['id']; ?>">
										<td colspan="6">
											<form method="post" class="elearning-inline-form">
												<input type="hidden" name="action" value="postpone_session">
												<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
												<input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
												<label>
													<span>New Start</span>
													<input class="form-control" type="datetime-local" name="new_start_time" required>
												</label>
												<label>
													<span>New End</span>
													<input class="form-control" type="datetime-local" name="new_end_time" required>
												</label>
												<label>
													<span>Reason</span>
													<input class="form-control" type="text" name="reason" maxlength="450" placeholder="Optional reason">
												</label>
												<button class="btn btn-warning" type="submit">
													<i class="fas fa-calendar-check"></i> Save Postponement
												</button>
											</form>
										</td>
									</tr>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
<script>
(function(){
	function providerPage(platform){
		switch(platform){
			case 'google_meet': return 'https://meet.google.com/new';
			case 'zoom': return 'https://zoom.us/meeting/schedule';
			case 'teams': return 'https://teams.microsoft.com/v2/';
			default: return '';
		}
	}
	document.getElementById('btn-generate-link').addEventListener('click', function(){
		var platform = document.getElementById('platform').value;
		var url = providerPage(platform);
		if(url){ window.open(url, '_blank', 'noopener,noreferrer'); }
	});
})();
</script>
</div>
</div>
<script>
(function(){
	var pf = document.getElementById('platform');
	var ju = document.getElementById('join_url');
	if (!pf || !ju) return;
	var col = ju.closest('.col-md-4');
	function sync(){
		var ext = pf.value !== 'internal';
		if (col) col.style.display = ext ? '' : 'none';
		ju.required = ext;
	}
	pf.addEventListener('change', sync);
	sync();
})();
</script>
</body>
</html>


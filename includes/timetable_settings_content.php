<?php
require_once __DIR__ . '/timetable_management.php';

ttm_ensure_schema($db);

$ttmAllowedCourseCodes = isset($ttmAllowedCourseCodes) && is_array($ttmAllowedCourseCodes) ? $ttmAllowedCourseCodes : null;
$ttmPageTitle = isset($ttmPageTitle) ? (string)$ttmPageTitle : 'Timetable Settings';
$ttmScopeLabel = isset($ttmScopeLabel) ? (string)$ttmScopeLabel : 'All courses';
$ttmCreatedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'system');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$selectedYear = max(1, min(7, (int)($_GET['year_of_study'] ?? $_POST['year_of_study'] ?? 1)));
$selectedSemester = max(1, min(4, (int)($_GET['semester'] ?? $_POST['semester'] ?? 1)));
$flash = ['type' => '', 'message' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        $flash = ['type' => 'danger', 'message' => 'The request could not be verified. Refresh the page and try again.'];
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            [$errors, $data] = ttm_validate_schedule_input($_POST, $ttmAllowedCourseCodes);
            if ($errors) {
                $flash = ['type' => 'danger', 'message' => implode(' ', $errors)];
            } else {
                [$ok, $message] = ttm_save_schedule($db, $data, $ttmCreatedBy, (int)($_POST['schedule_id'] ?? 0));
                $flash = ['type' => $ok ? 'success' : 'danger', 'message' => $message];
                $selectedYear = (int)$data['year_of_study'];
                $selectedSemester = (int)$data['semester'];
            }
        } elseif ($action === 'delete') {
            [$ok, $message] = ttm_cancel_schedule($db, (int)($_POST['schedule_id'] ?? 0), $ttmAllowedCourseCodes);
            $flash = ['type' => $ok ? 'success' : 'danger', 'message' => $message];
        }
    }
}

$courses = ttm_courses($db, $ttmAllowedCourseCodes);
$lecturers = ttm_lecturers($db, $ttmAllowedCourseCodes);
$timeSlots = ttm_time_slots($db);
$rooms = ttm_room_options($db);
$schedules = ttm_fetch_schedules($db, $selectedYear, $selectedSemester, $ttmAllowedCourseCodes);
$scheduledCourses = array_unique(array_map(static fn($row) => (string)$row['course_code'], $schedules));
$scheduledLecturers = array_unique(array_filter(array_map(static fn($row) => (string)$row['lecturer_id'], $schedules)));
?>
<style>
.ttm-page .hero-panel {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 18px 20px;
    margin-bottom: 18px;
}
.ttm-page .metric {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 14px;
    background: #fff;
}
.ttm-page .metric span { display: block; color: #64748b; font-size: .78rem; text-transform: uppercase; font-weight: 700; }
.ttm-page .metric strong { display: block; color: #0f172a; font-size: 1.45rem; line-height: 1.2; }
.ttm-page .section-panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 18px;
}
.ttm-page .section-panel .panel-header {
    padding: 14px 16px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.ttm-page .section-panel .panel-body { padding: 16px; }
.ttm-page .slot-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.ttm-page .slot-pill {
    border: 1px solid #dbe3ef;
    background: #f8fafc;
    border-radius: 6px;
    padding: 6px 10px;
    font-size: .82rem;
}
.ttm-page .slot-pill.break-marker {
    border-style: dashed;
    background: #fff7ed;
    color: #9a3412;
    cursor: default;
}
.ttm-page .table th { white-space: nowrap; color: #475569; font-size: .78rem; text-transform: uppercase; }
.ttm-page .type-badge { text-transform: capitalize; }
@media print {
    .ttm-page .no-print, .sidebar, .navbar, .topbar { display: none !important; }
    .ttm-page .section-panel, .ttm-page .metric, .ttm-page .hero-panel { border: 0; box-shadow: none; }
}
</style>

<div class="container-fluid px-4 portal-dashboard ttm-page">
    <div class="hero-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="page-title mb-1"><i class="fas fa-calendar-alt me-2 text-primary"></i><?php echo ttm_h($ttmPageTitle); ?></h1>
                <p class="text-muted mb-0">Create class timetable entries used by student and lecturer timetable views.</p>
                <small class="text-muted">Scope: <?php echo ttm_h($ttmScopeLabel); ?></small>
            </div>
            <div class="d-flex gap-2 no-print">
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
                <a class="btn btn-outline-primary" href="?year_of_study=<?php echo (int)$selectedYear; ?>&semester=<?php echo (int)$selectedSemester; ?>"><i class="fas fa-sync-alt me-1"></i>Refresh</a>
            </div>
        </div>
    </div>

    <?php if ($flash['message'] !== ''): ?>
        <div class="alert alert-<?php echo ttm_h($flash['type']); ?> no-print"><?php echo ttm_h($flash['message']); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-3 col-6"><div class="metric"><span>Entries</span><strong><?php echo count($schedules); ?></strong></div></div>
        <div class="col-md-3 col-6"><div class="metric"><span>Courses</span><strong><?php echo count($scheduledCourses); ?></strong></div></div>
        <div class="col-md-3 col-6"><div class="metric"><span>Lecturers</span><strong><?php echo count($scheduledLecturers); ?></strong></div></div>
        <div class="col-md-3 col-6"><div class="metric"><span>Period</span><strong>Y<?php echo (int)$selectedYear; ?> / P<?php echo (int)$selectedSemester; ?></strong></div></div>
    </div>

    <div class="section-panel no-print">
        <div class="panel-header">
            <h5 class="mb-0"><i class="fas fa-sliders-h me-2 text-primary"></i>Filter</h5>
        </div>
        <div class="panel-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Year of Study</label>
                    <select class="form-select" name="year_of_study">
                        <?php for ($i = 1; $i <= 7; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $selectedYear === $i ? 'selected' : ''; ?>>Year <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Academic Period</label>
                    <select class="form-select" name="semester">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $selectedSemester === $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100" type="submit"><i class="fas fa-search me-1"></i>Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="section-panel no-print">
        <div class="panel-header">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2 text-primary"></i><span id="ttmFormTitle">Add Timetable Entry</span></h5>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="ttmResetBtn"><i class="fas fa-undo me-1"></i>Reset</button>
        </div>
        <div class="panel-body">
            <?php if (empty($courses)): ?>
                <div class="alert alert-warning mb-0">No courses are available for this timetable scope.</div>
            <?php else: ?>
            <form method="post" id="ttmScheduleForm" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo ttm_h($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="schedule_id" id="schedule_id" value="0">
                <div class="col-lg-4">
                    <label class="form-label">Course</label>
                    <select class="form-select" name="course_code" id="course_code" required>
                        <option value="">Select course</option>
                        <?php foreach ($courses as $course): ?>
                            <?php $courseType = (string)($course['course_type'] ?? 'academic'); ?>
                            <option value="<?php echo ttm_h($course['course_code']); ?>" data-course-type="<?php echo ttm_h($courseType); ?>">
                                <?php echo ttm_h($course['course_code'] . ' - ' . $course['course_name'] . ($courseType === 'short_course' ? ' (Short Course)' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="shortCourseTimeHint" style="display:none;">Short courses can use any start and end time. The quick slots are optional.</div>
                </div>
                <div class="col-lg-4">
                    <label class="form-label">Lecturer</label>
                    <select class="form-select" name="lecturer_id" id="lecturer_id">
                        <option value="">Not assigned</option>
                        <?php foreach ($lecturers as $lecturer): ?>
                            <?php $name = trim(($lecturer['title'] ?? '') . ' ' . ($lecturer['Fname'] ?? '') . ' ' . ($lecturer['Lname'] ?? '')); ?>
                            <option value="<?php echo ttm_h($lecturer['staff_id']); ?>"><?php echo ttm_h($name . ' (' . $lecturer['staff_id'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-4">
                    <label class="form-label">Class Type</label>
                    <select class="form-select" name="schedule_type" id="schedule_type">
                        <?php foreach (ttm_schedule_types() as $value => $label): ?>
                            <option value="<?php echo ttm_h($value); ?>"><?php echo ttm_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <select class="form-select" name="year_of_study" id="year_of_study">
                        <?php for ($i = 1; $i <= 7; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $selectedYear === $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Academic Period</label>
                    <select class="form-select" name="semester" id="semester">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $selectedSemester === $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Day</label>
                    <select class="form-select" name="day_of_week" id="day_of_week" required>
                        <option value="">Select day</option>
                        <?php foreach (ttm_days() as $day): ?>
                            <option value="<?php echo ttm_h($day); ?>"><?php echo ttm_h($day); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Start</label>
                    <input type="time" class="form-control" name="start_time" id="start_time" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">End</label>
                    <input type="time" class="form-control" name="end_time" id="end_time" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Room / Venue</label>
                    <input class="form-control" name="room" id="room" list="ttmRooms" placeholder="Room 1">
                    <datalist id="ttmRooms">
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?php echo ttm_h($room['value']); ?>"><?php echo ttm_h($room['label']); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Title / Note</label>
                    <input class="form-control" name="title" id="title" maxlength="160" placeholder="Optional class title or note">
                </div>
                <?php if (!empty($timeSlots)): ?>
                    <div class="col-12">
                        <label class="form-label d-block">Quick Time Slots</label>
                        <div class="slot-pills">
                            <?php foreach ($timeSlots as $slot): ?>
                                <button type="button" class="slot-pill <?php echo (($slot['type'] ?? 'class') === 'break') ? 'break-marker' : ''; ?>"
                                    <?php if (($slot['type'] ?? 'class') === 'class'): ?>
                                        data-start="<?php echo ttm_h(substr((string)$slot['start_time'], 0, 5)); ?>" data-end="<?php echo ttm_h(substr((string)$slot['end_time'], 0, 5)); ?>"
                                    <?php else: ?>
                                        disabled
                                    <?php endif; ?>>
                                    <?php echo ttm_h($slot['slot_name'] . ' ' . substr((string)$slot['start_time'], 0, 5) . '-' . substr((string)$slot['end_time'], 0, 5)); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save Timetable</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="section-panel">
        <div class="panel-header">
            <h5 class="mb-0"><i class="fas fa-table me-2 text-primary"></i>Timetable Entries</h5>
            <span class="badge bg-light text-dark border"><?php echo count($schedules); ?> row(s)</span>
        </div>
        <div class="panel-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th>Time</th>
                            <th>Course</th>
                            <th>Lecturer</th>
                            <th>Room</th>
                            <th>Type</th>
                            <th class="text-end no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No timetable has been set for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $row): ?>
                            <tr>
                                <td><?php echo ttm_h($row['day_of_week']); ?></td>
                                <td class="fw-semibold"><?php echo ttm_h($row['start_time'] . ' - ' . $row['end_time']); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo ttm_h($row['course_code']); ?></div>
                                    <small class="text-muted"><?php echo ttm_h($row['course_name']); ?></small>
                                </td>
                                <td><?php echo $row['lecturer_name'] !== '' ? ttm_h($row['lecturer_name']) : '<span class="text-muted">Not assigned</span>'; ?></td>
                                <td><?php echo $row['room'] !== '' ? ttm_h($row['room']) : '<span class="text-muted">-</span>'; ?></td>
                                <td><span class="badge bg-primary type-badge"><?php echo ttm_h($row['schedule_type'] ?: 'lecture'); ?></span></td>
                                <td class="text-end no-print">
                                    <button type="button" class="btn btn-sm btn-outline-secondary ttm-edit"
                                        data-id="<?php echo (int)$row['id']; ?>"
                                        data-course="<?php echo ttm_h($row['course_code']); ?>"
                                        data-lecturer="<?php echo ttm_h($row['lecturer_id']); ?>"
                                        data-type="<?php echo ttm_h($row['schedule_type']); ?>"
                                        data-year="<?php echo (int)$row['year_of_study']; ?>"
                                        data-semester="<?php echo (int)$row['semester']; ?>"
                                        data-day="<?php echo ttm_h($row['day_of_week']); ?>"
                                        data-start="<?php echo ttm_h($row['start_time']); ?>"
                                        data-end="<?php echo ttm_h($row['end_time']); ?>"
                                        data-room="<?php echo ttm_h($row['room']); ?>"
                                        data-title="<?php echo ttm_h($row['title']); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this timetable entry?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo ttm_h($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="schedule_id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="year_of_study" value="<?php echo (int)$selectedYear; ?>">
                                        <input type="hidden" name="semester" value="<?php echo (int)$selectedSemester; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
  const form = document.getElementById('ttmScheduleForm');
  if (!form) return;
  const setValue = (id, value) => {
    const field = document.getElementById(id);
    if (field) field.value = value || '';
  };
  const courseSelect = document.getElementById('course_code');
  const shortHint = document.getElementById('shortCourseTimeHint');
  const syncCourseHint = () => {
    const selected = courseSelect?.selectedOptions?.[0];
    if (shortHint) shortHint.style.display = selected?.dataset.courseType === 'short_course' ? '' : 'none';
  };
  courseSelect?.addEventListener('change', syncCourseHint);
  syncCourseHint();
  document.querySelectorAll('.slot-pill').forEach((button) => {
    button.addEventListener('click', () => {
      setValue('start_time', button.dataset.start);
      setValue('end_time', button.dataset.end);
    });
  });
  document.querySelectorAll('.ttm-edit').forEach((button) => {
    button.addEventListener('click', () => {
      setValue('schedule_id', button.dataset.id);
      setValue('course_code', button.dataset.course);
      setValue('lecturer_id', button.dataset.lecturer);
      setValue('schedule_type', button.dataset.type || 'lecture');
      setValue('year_of_study', button.dataset.year);
      setValue('semester', button.dataset.semester);
      setValue('day_of_week', button.dataset.day);
      setValue('start_time', button.dataset.start);
      setValue('end_time', button.dataset.end);
      setValue('room', button.dataset.room);
      setValue('title', button.dataset.title);
      document.getElementById('ttmFormTitle').textContent = 'Edit Timetable Entry';
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
  document.getElementById('ttmResetBtn')?.addEventListener('click', () => {
    form.reset();
    setValue('schedule_id', '0');
    document.getElementById('ttmFormTitle').textContent = 'Add Timetable Entry';
  });
})();
</script>

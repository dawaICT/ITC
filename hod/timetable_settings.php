<?php
$page_title = 'Section Timetable Settings';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/timetable_management.php';

$hodStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$department = hod_resolve_department($db, $hodStaffId);
// Scope to every department in the HOS's section, not just the first.
$courseCodes = hod_section_course_codes($db, $department, $hodStaffId);
if (ttm_table_exists($db, 'short_courses')) {
    if ($res = @$db->query("SELECT course_code FROM short_courses WHERE status IN ('active','upcoming')")) {
        while ($row = $res->fetch_assoc()) {
            $code = trim((string)($row['course_code'] ?? ''));
            if ($code !== '') {
                $courseCodes[] = $code;
            }
        }
        $res->free();
    }
}
$courseCodes = array_values(array_unique(array_filter($courseCodes)));

$ttmPageTitle = 'Section Timetable Settings';
$ttmScopeLabel = !empty($department['name']) ? (string)$department['name'] : 'Assigned HOS courses';
$ttmAllowedCourseCodes = $courseCodes;

require_once dirname(__DIR__) . '/includes/timetable_settings_content.php';

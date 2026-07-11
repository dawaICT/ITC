<?php
$page_title = 'Timetable Settings';
require_once __DIR__ . '/includes/nav.php';

$ttmPageTitle = 'Timetable Settings';
$ttmScopeLabel = 'All student and lecturer course schedules';
$ttmAllowedCourseCodes = null;

require_once dirname(__DIR__) . '/includes/timetable_settings_content.php';


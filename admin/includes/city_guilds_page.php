<?php
/**
 * Shared controller for the City & Guilds module pages.
 *
 * Each City & Guilds workspace is now its own file (Overview, Exports,
 * Enrolment & Units, Assessment & Support, Verification & QA, Learners). They
 * all include this bootstrap first: it enforces access, handles the CSV export
 * and every POST action, and loads the shared data set. POSTs redirect back to
 * the page that submitted them, so each form keeps working from its own file.
 *
 * No HTML is emitted here. After including this file a page calls header.php,
 * then includes city_guilds_nav.php for the title bar + sub-navigation.
 */

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/../../includes/role_helpers.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/city_guilds_helpers.php';

cg_ensure_schema($db);

$canAccessCityGuilds = function_exists('canManageCityGuilds') && canManageCityGuilds();

if (!$canAccessCityGuilds) {
    $_SESSION['errorMssg'] = 'Access denied. You do not have permission to manage City & Guilds records.';
    header('Location: index.php');
    exit;
}

// CSV (Walled Garden) export streams and exits before any output.
if (isset($_GET['export'])) {
    cg_stream_csv_export($db, (string)$_GET['export'], trim((string)($_GET['cohort'] ?? '')));
}

// Self-page for POST-redirect-GET. basename keeps the redirect on whichever
// City & Guilds page submitted the form.
$cgSelf = basename($_SERVER['PHP_SELF']);
if ($cgSelf === '' || strpos($cgSelf, 'city_guilds') !== 0) {
    $cgSelf = 'city_guilds.php';
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'enroll') {
            cg_enroll_learner($db, $_POST);
            $_SESSION['successMssg'] = 'City & Guilds learner enrolled successfully.';
        } elseif ($action === 'save_unit') {
            cg_save_unit($db, $_POST);
            $_SESSION['successMssg'] = 'City & Guilds unit saved successfully.';
        } elseif ($action === 'assign_unit') {
            cg_assign_unit($db, $_POST);
            $_SESSION['successMssg'] = 'Learner unit assignment scheduled successfully.';
        } elseif ($action === 'record_assessment') {
            cg_record_assessment($db, $_POST);
            $_SESSION['successMssg'] = 'Assessment recorded and queued for internal verification.';
        } elseif ($action === 'verify_assessment') {
            cg_verify_assessment($db, $_POST);
            $_SESSION['successMssg'] = 'Assessment verified and learner progress updated.';
        } elseif ($action === 'record_support') {
            cg_record_support($db, $_POST);
            $_SESSION['successMssg'] = 'Learner support intervention recorded.';
        } elseif ($action === 'save_qa') {
            cg_save_qa_record($db, $_POST);
            $_SESSION['successMssg'] = 'Quality assurance record saved.';
        } else {
            $_SESSION['errorMssg'] = 'Unsupported City & Guilds action.';
        }
    } catch (Throwable $e) {
        $_SESSION['errorMssg'] = $e->getMessage();
    }

    header('Location: ' . $cgSelf);
    exit;
}

// Shared data set. Loading all of it keeps every page self-contained and the
// select dropdowns populated regardless of which workspace is open.
$counts = cg_dashboard_counts($db);
$students = cg_students_for_select($db);
$staff = cg_staff_for_select($db);
$units = cg_units($db);
$learners = cg_learners($db);
$assignments = cg_assignments($db);
$pendingAssessments = cg_pending_assessments($db);
$qaRecords = cg_recent_qa_records($db);
$cohorts = cg_cohorts($db);

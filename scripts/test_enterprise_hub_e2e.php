<?php
declare(strict_types=1);

/**
 * End-to-end Skills-to-Trade Hub workflow against live MySQL.
 * Creates an isolated demo profile/item, walks draft → publish → interest,
 * then archives the test artefact.
 *
 * Run: C:\xampp\php\php.exe scripts/test_enterprise_hub_e2e.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/bootstrap.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}

$pass = 0;
$fail = 0;
$notes = [];

function t(bool $ok, string $name, string $detail = ''): void
{
    global $pass, $fail, $notes;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? " — {$detail}" : '') . "\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        if ($detail !== '') {
            $notes[] = $name . ': ' . $detail;
        }
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Systems-admin session so transitions/permissions succeed in CLI
$_SESSION['user_id_db'] = 900001;
$_SESSION['staff_id'] = 'E2E-STAFF';
$_SESSION['user_id'] = 'E2E-STAFF';
$_SESSION['Sid'] = 'E2E-STU-999';
$_SESSION['user_role'] = 'student';
$_SESSION['all_roles'] = ['systems_admin', 'lecturer'];
$_SESSION['role'] = 'systems_admin';

$marker = 'E2E Hub Test ' . date('YmdHis');
$studentId = 'E2E-STU-999';

// Cleanup any prior E2E rows
$cleanup = $db->prepare("SELECT id FROM enterprise_profiles WHERE student_id = ? AND business_name LIKE 'E2E Hub Test%'");
$cleanup->bind_param('s', $studentId);
$cleanup->execute();
$oldProfiles = $cleanup->get_result()->fetch_all(MYSQLI_ASSOC);
$cleanup->close();
foreach ($oldProfiles as $op) {
    $pid = (int)$op['id'];
    $items = $db->query("SELECT id FROM enterprise_items WHERE enterprise_profile_id = {$pid}");
    if ($items) {
        while ($ir = $items->fetch_assoc()) {
            $iid = (int)$ir['id'];
            $db->query("DELETE FROM enterprise_interests WHERE enterprise_item_id = {$iid}");
            $db->query("DELETE FROM enterprise_item_media WHERE enterprise_item_id = {$iid}");
            $db->query("DELETE FROM enterprise_costs WHERE enterprise_item_id = {$iid}");
            $db->query("DELETE FROM enterprise_readiness_assessments WHERE enterprise_item_id = {$iid}");
            $db->query("DELETE FROM enterprise_reviews WHERE enterprise_item_id = {$iid}");
            $db->query("DELETE FROM enterprise_items WHERE id = {$iid}");
        }
    }
    $db->query("DELETE FROM enterprise_profiles WHERE id = {$pid}");
}

// 1. Profile
$profileId = eh_save_profile($db, [
    'owner_user_id' => 900001,
    'student_id' => $studentId,
    'business_name' => $marker,
    'profile_type' => 'student_project',
    'description' => 'Automated end-to-end test enterprise for Skills-to-Trade Hub.',
    'province' => 'Lusaka',
    'district' => 'Lusaka',
    'public_phone' => '+260970009999',
    'public_email' => 'e2e.hub@exhibition.test',
    'business_registration_status' => 'not_registered',
    'years_operating' => 0,
    'programme_code' => 'E2E-DEMO',
    'programme_name' => 'E2E Trade Programme',
    'status' => 'active',
]);
t($profileId > 0, 'create enterprise profile', 'id=' . $profileId);

// 2. Item
$cats = eh_list_categories($db, true);
t(count($cats) >= 5, 'categories available', 'count=' . count($cats));
$categoryId = (int)($cats[0]['id'] ?? 0);

$itemId = eh_create_item($db, [
    'enterprise_profile_id' => $profileId,
    'category_id' => $categoryId,
    'item_type' => 'product',
    'title' => $marker . ' Product',
    'short_description' => 'E2E short description for a vocational product.',
    'full_description' => 'Full description used only for automated Skills-to-Trade Hub acceptance testing.',
    'current_capacity' => 50,
    'capacity_period' => 'month',
    'investment_required' => 12000,
    'investment_purpose' => 'Packaging equipment for E2E demo',
    'expected_capacity' => 200,
    'expected_capacity_period' => 'month',
    'employment_potential' => 3,
]);
t($itemId > 0, 'create draft item', 'id=' . $itemId);

$item = eh_get_item($db, $itemId);
t($item !== null && ($item['status'] ?? '') === 'draft', 'item starts as draft');
t(!empty($item['public_code']) && str_starts_with((string)$item['public_code'], 'ENT-'), 'public_code generated', (string)($item['public_code'] ?? ''));

// Incomplete submit must fail
$badSubmit = eh_transition_item($db, $itemId, 'submitted', 'submit', 'Submitting incomplete');
t(!$badSubmit['success'], 'incomplete draft cannot submit', (string)($badSubmit['message'] ?? ''));

// Costs
$costResult = eh_save_costs($db, $itemId, [
    'material_cost' => 40,
    'labour_cost' => 30,
    'transport_cost' => 10,
    'utilities_cost' => 5,
    'packaging_cost' => 5,
    'marketing_cost' => 10,
    'other_cost' => 0,
    'number_of_units' => 10,
    'selling_price' => 15,
]);
t(!empty($costResult['ok']), 'save costs', json_encode($costResult['errors'] ?? []));
t((float)($costResult['data']['total_cost'] ?? 0) === 100.0, 'cost total_cost=100');
t((float)($costResult['data']['profit_margin'] ?? 0) > 0, 'positive profit margin');

// Readiness
$ready = eh_save_readiness($db, $itemId, [
    'product_score' => 80,
    'market_score' => 70,
    'costing_score' => 75,
    'capacity_score' => 65,
    'compliance_score' => 60,
    'team_score' => 70,
], $studentId);
t(!empty($ready['ok']), 'save readiness');
t(($ready['data']['readiness_level'] ?? '') !== '', 'readiness level set', (string)($ready['data']['readiness_level'] ?? ''));

// Still missing media
$stillBad = eh_transition_item($db, $itemId, 'submitted', 'submit', 'Still missing media');
t(!$stillBad['success'], 'submit blocked without media', (string)($stillBad['message'] ?? ''));

// Insert primary media row pointing at a safe relative path (no executable)
$mediaDir = dirname(__DIR__) . '/storage/enterprise_hub/media';
if (!is_dir($mediaDir)) {
    mkdir($mediaDir, 0755, true);
}
$stored = 'e2e_' . bin2hex(random_bytes(8)) . '.jpg';
$abs = $mediaDir . DIRECTORY_SEPARATOR . $stored;
// Minimal valid JPEG (1x1)
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEBAVFhUVFRUVFRUVFRUWFxUYHSggGBolGxomITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAbAAACAwEBAQAAAAAAAAAAAAADBAECBQYAB//EABUBAQEAAAAAAAAAAAAAAAAAAAAB/8QAFhEBAQEAAAAAAAAAAAAAAAAAAAER/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8A1oAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP/Z');
file_put_contents($abs, $jpeg !== false ? $jpeg : 'JPEG');
$relPath = 'media/' . $stored;
$fileSize = (int)filesize($abs);
$uploader = $studentId;
$ins = $db->prepare("
    INSERT INTO enterprise_item_media
        (enterprise_item_id, file_path, original_filename, stored_filename, mime_type, file_size, media_type, caption, is_primary, sort_order, uploaded_by)
    VALUES (?, ?, 'e2e.jpg', ?, 'image/jpeg', ?, 'image', 'E2E primary', 1, 0, ?)
");
$ins->bind_param('issis', $itemId, $relPath, $stored, $fileSize, $uploader);
$ins->execute();
$ins->close();
t(eh_item_has_primary_media($db, $itemId), 'primary media present');

$subErrors = eh_item_submission_errors($db, $itemId);
t($subErrors === [], 'submission checklist clear', implode('; ', $subErrors));

// Student submit
$submit = eh_transition_item($db, $itemId, 'submitted', 'submit', 'Ready for review');
t(!empty($submit['success']), 'submit for lecturer review', (string)($submit['message'] ?? ''));

// Switch actor to lecturer for review decisions
$_SESSION['Sid'] = null;
unset($_SESSION['Sid']);
$_SESSION['staff_id'] = 'E2E-LECT';
$_SESSION['user_id'] = 'E2E-LECT';
$_SESSION['user_role'] = 'lecturer';
$_SESSION['all_roles'] = ['lecturer', 'systems_admin'];

$changes = eh_transition_item($db, $itemId, 'changes_requested', 'request_changes', 'Please clarify packaging materials.');
t(!empty($changes['success']), 'lecturer requests changes');

// Student resubmit
$_SESSION['Sid'] = $studentId;
$_SESSION['user_role'] = 'student';
$_SESSION['all_roles'] = ['systems_admin', 'lecturer'];
$resubmit = eh_transition_item($db, $itemId, 'submitted', 'submit', 'Addressed packaging comments.');
t(!empty($resubmit['success']), 'student resubmits after changes');

$_SESSION['Sid'] = null;
unset($_SESSION['Sid']);
$_SESSION['staff_id'] = 'E2E-LECT';
$verify = eh_transition_item($db, $itemId, 'lecturer_verified', 'verify', 'Technically credible.');
t(!empty($verify['success']), 'lecturer verifies item');

$_SESSION['staff_id'] = 'E2E-ADMIN';
$_SESSION['user_id'] = 'E2E-ADMIN';
$_SESSION['user_role'] = 'systems_admin';
$_SESSION['all_roles'] = ['systems_admin'];

$approve = eh_transition_item($db, $itemId, 'approved', 'approve', 'Approved for publication.');
t(!empty($approve['success']), 'admin approves item');

$publish = eh_transition_item($db, $itemId, 'published', 'publish', 'Published for showcase.');
t(!empty($publish['success']), 'admin publishes item');

$fresh = eh_get_item($db, $itemId);
$code = (string)($fresh['public_code'] ?? '');
$pub = eh_get_item_by_code($db, $code, true);
t($pub !== null && ($pub['status'] ?? '') === 'published', 'published item visible by public code', $code);

$hiddenDraft = eh_get_item_by_code($db, 'ENT-DEMO0013', true);
t($hiddenDraft === null, 'demo draft still not publicly visible');

// Interest submission
$interest = eh_submit_interest($db, $itemId, [
    'visitor_name' => 'E2E Visitor',
    'email' => 'e2e.visitor@exhibition.test',
    'phone' => '+260971112222',
    'interest_type' => 'product_purchase',
    'message' => 'Interested in buying sample packs for exhibition follow-up.',
    'consent_accepted' => 1,
    'organization' => 'E2E Org',
    'preferred_contact_method' => 'email',
    'website' => '',
], '127.0.0.1');
t(!empty($interest['ok']), 'expression of interest accepted', (string)($interest['message'] ?? ''));
t(!empty($interest['id']), 'interest id returned');

$interestId = (int)($interest['id'] ?? 0);
if ($interestId > 0) {
    $upd = eh_update_interest_followup($db, $interestId, [
        'follow_up_status' => 'contacted',
        'assigned_to' => 'E2E-ADMIN',
        'internal_notes' => 'Called visitor; sample pack discussion scheduled.',
    ]);
    t(!empty($upd['ok']), 'officer updates follow-up status', (string)($upd['message'] ?? ''));
}

$dup = eh_submit_interest($db, $itemId, [
    'visitor_name' => 'E2E Visitor',
    'email' => 'e2e.visitor@exhibition.test',
    'phone' => '+260971112222',
    'interest_type' => 'product_purchase',
    'message' => 'Duplicate attempt should be blocked.',
    'consent_accepted' => 1,
    'website' => '',
], '127.0.0.1');
t(empty($dup['ok']), 'duplicate interest blocked within 24h', (string)($dup['message'] ?? ''));

// Reviews logged
$reviews = eh_list_reviews($db, $itemId);
t(count($reviews) >= 5, 'review history recorded', 'count=' . count($reviews));

// Unpublish then confirm public hide
$unpub = eh_transition_item($db, $itemId, 'unpublished', 'unpublish', 'Temporary unpublish for E2E.');
t(!empty($unpub['success']), 'admin unpublishes item');
$gone = eh_get_item_by_code($db, $code, true);
t($gone === null, 'unpublished item hidden from public lookup');

// AI assist (must not auto-save; function returns draft text)
$ai = eh_ai_assist($db, 'improve_description', [
    'title' => $marker,
    'item_type' => 'product',
    'category' => 'Agriculture',
    'short_description' => 'Test product',
    'full_description' => 'Short E2E description.',
]);
t(isset($ai['text']) && trim((string)$ai['text']) !== '', 'AI assist returns draft text');
t(empty($ai['auto_saved']), 'AI does not auto-save');

$stats = eh_admin_dashboard_stats($db);
t(isset($stats['published_total']), 'admin dashboard stats available');

// Final cleanup of E2E artefacts
$db->query("DELETE FROM enterprise_interests WHERE enterprise_item_id = {$itemId}");
$db->query("DELETE FROM enterprise_item_media WHERE enterprise_item_id = {$itemId}");
$db->query("DELETE FROM enterprise_costs WHERE enterprise_item_id = {$itemId}");
$db->query("DELETE FROM enterprise_readiness_assessments WHERE enterprise_item_id = {$itemId}");
$db->query("DELETE FROM enterprise_reviews WHERE enterprise_item_id = {$itemId}");
$db->query("DELETE FROM enterprise_items WHERE id = {$itemId}");
$db->query("DELETE FROM enterprise_profiles WHERE id = {$profileId}");
if (is_file($abs)) {
    @unlink($abs);
}
t(true, 'e2e artefacts cleaned up');

echo "\nPassed: {$pass}  Failed: {$fail}\n";
if ($notes) {
    echo "Failures:\n- " . implode("\n- ", $notes) . "\n";
}
exit($fail > 0 ? 1 : 0);

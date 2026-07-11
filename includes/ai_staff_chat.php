<?php
declare(strict_types=1);

/**
 * Conversational AI chat endpoint for staff (lecturers, admin, admissions, …).
 *
 * One endpoint for all staff modules. Generic staff-session auth + CSRF, light
 * portal context, role-aware system prompt. Backed by the shared chat core.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/chatbot_db.php';
if (function_exists('wuc_configure_session_cookie')) {
    wuc_configure_session_cookie();
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/ai_chat_core.php';
require_once __DIR__ . '/role_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Generic staff auth: any logged-in staff/admin session.
$isStaff = !empty($_SESSION['user_id']) || !empty($_SESSION['staff_id']) || (($_SESSION['index'] ?? '') === 'admin');
if (!$isStaff) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not authorised.']);
    exit;
}

$staffId = (string)($_SESSION['staff_id'] ?? ($_SESSION['user_id'] ?? 'staff'));
$staffName = trim((string)($_SESSION['user_name'] ?? ($_SESSION['name'] ?? '')));

// Determine primary role from session if available, mapping to chatbot role label
$sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$roleLabel = 'staff member';

if ($sessionRole !== '') {
    $roleMap = [
        'systems_admin' => 'admin',
        'admin' => 'admin',
        'lecturer' => 'lecturer',
        'head_of_department' => 'hod',
        'hod' => 'hod',
        'dean' => 'dean',
        'registrar' => 'registrar',
        'admission_officer' => 'admissions',
        'admissions' => 'admissions',
        'accountant' => 'accounts',
        'accounts' => 'accounts',
        'finance' => 'accounts',
        'librarian' => 'librarian'
    ];
    if (isset($roleMap[$sessionRole])) {
        $roleLabel = $roleMap[$sessionRole];
    }
}

if (function_exists('canAccessAdmin') || function_exists('canAccessAdmissions')) {
    // role_helpers may already be loaded by a prior include.
}
$scopeHint = '';
$rawPeek = file_get_contents('php://input');
if ($rawPeek !== '' && ($peek = json_decode($rawPeek, true)) && is_array($peek)) {
    $scopeHint = strtolower(trim((string)($peek['scope'] ?? '')));
    // Re-seed php://input for the core by stuffing into $_POST so the core reads it.
    foreach (['message', 'csrf_token', 'reset', 'action'] as $k) {
        if (isset($peek[$k]) && !isset($_POST[$k])) {
            $_POST[$k] = $peek[$k];
        }
    }
}

$allowedScopes = ['admin', 'admissions', 'lecturer', 'registrar', 'dean', 'hod', 'accounts', 'librarian'];
// Fallback/refinement: if no session role was mapped, or if we have an explicit allowed scope hint, use scopeHint
if ($roleLabel === 'staff member' && in_array($scopeHint, $allowedScopes, true)) {
    $roleLabel = $scopeHint;
}

// Light, guarded portal context (counts only — safe aggregates).
function wuc_staff_ctx_count(mysqli $db, string $sql): int
{
    if ($res = @$db->query($sql)) {
        $row = $res->fetch_row();
        $res->free();
        return (int)($row[0] ?? 0);
    }
    return 0;
}
function wuc_staff_table_exists(mysqli $db, string $t): bool
{
    $t = $db->real_escape_string($t);
    $r = @$db->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}

$ctx = [
    'role' => $roleLabel,
    'staff_id' => $staffId,
    'staff_name' => $staffName !== '' ? $staffName : null
];

if (wuc_staff_table_exists($db, 'students')) {
    $ctx['total_students'] = wuc_staff_ctx_count($db, "SELECT COUNT(*) FROM students");
}
if (wuc_staff_table_exists($db, 'programs')) {
    $ctx['active_programs'] = wuc_staff_ctx_count($db, "SELECT COUNT(*) FROM programs WHERE is_active = 1 OR is_active IS NULL");
}
if (wuc_staff_table_exists($db, 'processed_applicants')) {
    $ctx['processed_applicants'] = wuc_staff_ctx_count($db, "SELECT COUNT(*) FROM processed_applicants");
}

// Lecturer-specific context
if ($roleLabel === 'lecturer') {
    $riskEngineFile = __DIR__ . '/academic_risk_engine.php';
    if (file_exists($riskEngineFile)) {
        try {
            require_once $riskEngineFile;
            $riskSummary = wuc_academic_risk_lecturer_summary($db, $staffId);
            $alerts = wuc_academic_risk_lecturer_alerts($db, $staffId);
            
            $ctx['assigned_courses'] = $riskSummary['assigned_courses'] ?? [];
            $ctx['total_students_taught'] = $riskSummary['students_checked'] ?? 0;
            $ctx['student_risk_counts'] = [
                'high' => $riskSummary['high'] ?? 0,
                'medium' => $riskSummary['medium'] ?? 0,
                'low' => $riskSummary['low'] ?? 0
            ];
            $ctx['top_risk_students'] = array_map(static function($s) {
                return [
                    'name' => $s['name'],
                    'risk_score' => $s['score'],
                    'risk_level' => $s['level'],
                    'recommended_action' => $s['action']
                ];
            }, $riskSummary['top_learners'] ?? []);
            
            $ctx['recent_academic_alerts'] = array_map(static function($a) {
                return [
                    'student_id' => $a['student_id'] ?? '',
                    'student_name' => $a['student_name'] ?? '',
                    'course_code' => $a['course_code'] ?? '',
                    'risk_score' => $a['risk_score'] ?? 0,
                    'risk_level' => $a['risk_level'] ?? 'Low',
                    'risk_factors' => $a['risk_factors'] ?? []
                ];
            }, $alerts);
        } catch (Throwable $e) {
            error_log('ai_staff_chat lecturer risk context failed: ' . $e->getMessage());
        }
    }
}

// Department head or Dean context
if (($roleLabel === 'hod' || $roleLabel === 'dean') && isset($_SESSION['deptId'])) {
    $riskEngineFile = __DIR__ . '/academic_risk_engine.php';
    if (file_exists($riskEngineFile)) {
        try {
            require_once $riskEngineFile;
            $deptSummary = wuc_academic_risk_department_summary($db, (int)$_SESSION['deptId']);
            $ctx['department_risk_counts'] = [
                'high' => $deptSummary['high'] ?? 0,
                'medium' => $deptSummary['medium'] ?? 0,
                'low' => $deptSummary['low'] ?? 0
            ];
            $ctx['department_students_checked'] = $deptSummary['students_checked'] ?? 0;
        } catch (Throwable $e) {
            error_log('ai_staff_chat dept risk context failed: ' . $e->getMessage());
        }
    }
}

// Map roles to guidance on how the AI can assist
$roleAssistanceGuides = [
    'admin' => 'Help the Systems Administrator manage the system: add/edit staff members, configure roles and permissions, view audit logs, adjust system settings, manage courses globally, and run system health checks.',
    'admissions' => 'Help the Admissions Officer manage applicant data: process new applications, view admissions metrics, update applicant status, draft acceptance/rejection letters, and navigate the admissions pipeline.',
    'lecturer' => 'Help the Lecturer manage their workspace: view assigned courses, track student attendance, review and grade assignments/submissions, upload learning materials, view student progression insights, and check student academic risks or alerts.',
    'registrar' => 'Help the Registrar manage academic records: register students for semesters, manage program enrollments, update student status, handle course registrations, and review student progression reports.',
    'dean' => 'Help the Dean manage school-wide academic progression: review department risk summaries, inspect progression alerts, monitor faculty and student counts, and analyze academic reports across programs.',
    'hod' => 'Help the Head of Department/Section manage departmental operations: review departmental student risk levels, verify course assignments, inspect student progression alerts, and manage transport operations if in charge of transport.',
    'accounts' => 'Help the Accountant/Finance Officer manage student accounts: record bank payments, view invoice statuses, set fee structures, apply late fees, review cost centers, approve expenses, and check outstanding receivables.',
    'librarian' => 'Help the Librarian manage library resources: search catalog materials, check book loans, track overdue returns, and handle study space bookings.',
    'staff member' => 'Help staff use the portal effectively: admissions and applicant processing, student registration, course and lecturer assignment, CA/exam grading, fees and finance, reports, and where features live in the portal.'
];

$displayRoleName = function_exists('getRoleDisplayName') && $sessionRole !== '' ? getRoleDisplayName($sessionRole) : $roleLabel;
$assistanceGuide = $roleAssistanceGuides[$roleLabel] ?? $roleAssistanceGuides['staff member'];

$systemPrompt = "You are the ITC Staff Assistant. You are speaking with " . ($staffName !== '' ? $staffName : 'a staff member') . " (Staff ID: {$staffId}), who is logged in as a {$displayRoleName}.\n\n";
$systemPrompt .= "Your role-specific focus: {$assistanceGuide}\n\n";
$systemPrompt .= 'Answer questions accurately using the supplied portal data when relevant. Write in clear, formal language appropriate for an academic institution. Use structured headings or tables only when the content genuinely requires it. Avoid decorative formatting, excessive bullet points, bold overuse, emojis, and phrases that sound scripted or artificially generated such as "Certainly", "I hope this helps", or "As an AI". Never invent student names, grades, balances, or records not present in the context — direct the user to the correct portal module instead. Be direct, calm, and professionally restrained in tone.';

// Handle history action (read-only, returns recent conversation for widget reload)
if (($_POST['action'] ?? '') === 'history') {
    $token = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    if (empty($_SESSION['chatbot_schema_checked'])) {
        wuc_chatbot_ensure_schema($db);
        $_SESSION['chatbot_schema_checked'] = true;
    }
    $dbRole   = 'staff';
    $dbType   = $roleLabel;
    $hConvoId = wuc_chatbot_get_or_create_convo($db, $staffId, $dbRole, $dbType);
    $msgs     = $hConvoId !== null ? wuc_chatbot_load_history($db, $hConvoId, 12) : [];
    require_once __DIR__ . '/ai_markdown.php';
    $rendered = [];
    foreach ($msgs as $m) {
        $rendered[] = [
            'role' => $m['role'],
            'html' => $m['role'] === 'assistant'
                ? '<div class="ai-output">' . wuc_ai_render_markdown($m['content']) . '</div>'
                : htmlspecialchars($m['content'], ENT_QUOTES, 'UTF-8'),
        ];
    }
    echo json_encode(['ok' => true, 'messages' => $rendered]);
    exit;
}

wuc_ai_chat_respond($db, [
    'role'          => 'staff:' . $roleLabel,
    'user_id'       => $staffId,
    'feature'       => 'staff_ai_chat',
    'rate_key'      => 'staff_ai_chat',
    'rate_limit'    => 40,
    'system_prompt' => $systemPrompt,
    'context_json'  => wuc_ai_context_json($ctx, 4000),
    'fallback'      => static function () use ($ctx): string {
        $s = $ctx['total_students'] ?? '—';
        $p = $ctx['active_programs'] ?? '—';
        return "I can't reach the AI service right now. Quick portal snapshot:\n\n- **Students:** {$s}\n- **Active programs:** {$p}\n\nPlease try again shortly.";
    },
]);

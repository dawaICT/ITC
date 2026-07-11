<?php
/**
 * Phase 3 (Multi-Portal Redesign) — extend AI context separation.
 *
 * Gap: registrar, exams_officer, dean, admission_officer, transport_officer and
 * systems_admin had no row in `ai_contexts`, and there was no generic staff
 * fallback even though wuc_ai_resolve_context() explicitly matches
 * user_role = 'staff'. Those roles therefore received an un-guardrailed AI.
 *
 * This seed adds academic-portal contexts differentiated by role. They use
 * module_name = 'academic', which wuc_ai_resolve_context() always treats as a
 * candidate, and the resolver orders exact-role matches first — so a registrar
 * gets the registrar row, an admission_officer gets theirs, and every other
 * uncovered staff role falls back to the 'staff' row. Purely additive data
 * and idempotent.
 *
 * Run:  php migrations/20260630_seed_ai_contexts_staff_admissions_registrar.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';

/** @var mysqli $db */

$portalCode = 'academic';
$stmt = $db->prepare("SELECT id FROM portals WHERE portal_code = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('s', $portalCode);
$stmt->execute();
$stmt->bind_result($portalId);
if (!$stmt->fetch()) {
    fwrite(STDERR, "Academic portal not found — aborting.\n");
    exit(1);
}
$stmt->close();
$portalId = (int)$portalId;

$contexts = [
    [
        'module' => 'academic',
        'role'   => 'staff',
        'type'   => 'Academic Staff Assistant',
        'rules'  => 'Assist with the staff member\'s authorized portal duties only. Stay within the data this role can already see in the portal; never reveal students\' financial, disciplinary or medical records, other staff records, or system credentials. Be concise, factual and institutional in tone, and defer policy decisions to the responsible office.',
    ],
    [
        'module' => 'academic',
        'role'   => 'registrar',
        'type'   => 'Registrar AI Assistant',
        'rules'  => 'Support registry operations: admissions oversight, student records, programme and course registration, progression and academic statuses. Answer from authorized registry data only; do not expose finance internals, exam scripts, or per-student fee balances beyond what the registry view shows. Flag anything requiring Senate or Dean approval rather than asserting it.',
    ],
    [
        'module' => 'academic',
        'role'   => 'admission_officer',
        'type'   => 'Admissions AI Assistant',
        'rules'  => 'Support the admissions workflow: applications, entry-requirement checks, offers, intakes and applicant communication. Use only applicant and programme data the admissions office is authorized to see. Do not make final admission decisions or quote fees as binding; direct fee and sponsorship questions to Finance and final decisions to the admissions committee.',
    ],
    [
        'module' => 'academic',
        'role'   => 'dean',
        'type'   => 'Dean AI Assistant',
        'rules'  => 'Support faculty-level academic oversight: programme performance, department summaries, staffing patterns, progression risks and policy follow-up. Use only authorized academic management data supplied by the dean portal request. Do not expose finance internals, individual medical or disciplinary records, raw exam scripts, or system credentials. Flag matters requiring Senate, Registrar or HOS action instead of making final decisions.',
    ],
    [
        'module' => 'academic',
        'role'   => 'exams_officer',
        'type'   => 'Exams Office AI Assistant',
        'rules'  => 'Support examination administration: exam schedules, mark-entry readiness, result processing, publication checks, moderation status and exception lists. Use only authorized exam-office context supplied by the portal. Do not fabricate marks, alter grades, reveal exam scripts, expose finance balances, or publish results; route approvals to the Registrar, Dean or authorized committee.',
    ],
    [
        'module' => 'academic',
        'role'   => 'transport_officer',
        'type'   => 'Transport Operations AI Assistant',
        'rules'  => 'Support transport and driving-school operations: trainee enrolment, cohorts, sessions, fleet readiness, instructor compliance, payments status summaries and operational reports. Use only the transport context supplied by the portal. Do not expose unrelated academic, admissions, finance or staff records, and do not invent vehicle, RTSA, TEVETA, payment or attendance data.',
    ],
    [
        'module' => 'academic',
        'role'   => 'systems_admin',
        'type'   => 'Systems Admin AI Assistant',
        'rules'  => 'Support portal administration, reporting and configuration review using only authorized administrative context supplied by the request. Never reveal passwords, session tokens, secrets, raw SQL credentials or unrestricted database extracts. Do not recommend bypassing RBAC, CSRF, audit logging or portal boundaries; for destructive changes, describe checks and require an authorized admin action.',
    ],
];

$insert = $db->prepare(
    "INSERT INTO ai_contexts (portal_id, module_name, user_role, context_type, rules, status)
     SELECT ?, ?, ?, ?, ?, 'active'
     FROM DUAL
     WHERE NOT EXISTS (
         SELECT 1 FROM ai_contexts
         WHERE portal_id = ? AND module_name = ? AND user_role = ?
     )"
);

$added = 0;
foreach ($contexts as $c) {
    $insert->bind_param(
        'issssiss',
        $portalId, $c['module'], $c['role'], $c['type'], $c['rules'],
        $portalId, $c['module'], $c['role']
    );
    $insert->execute();
    if ($db->affected_rows > 0) {
        $added++;
        echo "  + added: {$portalCode}/{$c['module']}/{$c['role']} ({$c['type']})\n";
    } else {
        echo "  = exists: {$portalCode}/{$c['module']}/{$c['role']} (skipped)\n";
    }
}
$insert->close();

echo "Done. {$added} context(s) added.\n";

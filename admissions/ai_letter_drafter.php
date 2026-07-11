<?php
declare(strict_types=1);

// ===== Session & authentication (admissions pattern) =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    setFlashMessage('error', 'Session expired or unauthorized access');
    header('Location: /wucportal/staff_login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function ai_letter_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Return all programs indexed by program_code for fast lookup.
 */
function ai_letter_programs(mysqli $db): array
{
    $out = [];
    if ($res = @$db->query("SELECT program_code, program_name, program_type, period_mode FROM programs ORDER BY program_name")) {
        while ($row = $res->fetch_assoc()) {
            $code = trim((string)$row['program_code']);
            if ($code !== '') {
                $out[$code] = $row;
            }
        }
        $res->free();
    }
    return $out;
}

/**
 * Recent processed applicants with full program name resolved via JOIN.
 * Tries program_code first, falls back to program column as a code.
 */
function ai_letter_recent(mysqli $db): array
{
    $out = [];
    $sql = "SELECT pa.id, pa.Fname, pa.Lname, pa.program, pa.program_code, pa.intake, pa.mode, pa.status,
                   COALESCE(p1.program_name, p2.program_name) AS resolved_program_name
            FROM processed_applicants pa
            LEFT JOIN programs p1 ON p1.program_code = pa.program_code
            LEFT JOIN programs p2 ON p2.program_code = pa.program
            ORDER BY pa.id DESC LIMIT 100";
    if ($res = @$db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        $res->free();
    }
    return $out;
}

/**
 * Load a single processed applicant with full program details resolved.
 */
function ai_letter_load(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare(
        "SELECT pa.id, pa.title, pa.Fname, pa.Lname, pa.program, pa.program_code, pa.intake, pa.mode, pa.year,
                COALESCE(p1.program_name, p2.program_name)   AS resolved_program_name,
                COALESCE(p1.program_type, p2.program_type)   AS resolved_program_type,
                COALESCE(p1.period_mode,  p2.period_mode)    AS resolved_period_mode
         FROM processed_applicants pa
         LEFT JOIN programs p1 ON p1.program_code = pa.program_code
         LEFT JOIN programs p2 ON p2.program_code = pa.program
         WHERE pa.id = ? LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Resolve the best display name for the programme from an applicant row.
 * Priority: DB-joined name → `programs` map → `program` column raw value → code.
 */
function ai_letter_resolve_program_name(array $applicant, array $programs): string
{
    // 1. From JOIN result
    $joined = trim((string)($applicant['resolved_program_name'] ?? ''));
    if ($joined !== '') {
        return $joined;
    }

    // 2. From programs map keyed by program_code
    $code = trim((string)($applicant['program_code'] ?? ''));
    if ($code !== '' && isset($programs[$code])) {
        return (string)$programs[$code]['program_name'];
    }

    // 3. Try the `program` column value as a code key
    $altCode = trim((string)($applicant['program'] ?? ''));
    if ($altCode !== '' && isset($programs[$altCode])) {
        return (string)$programs[$altCode]['program_name'];
    }

    // 4. Fall back to whatever is stored in `program` (code or partial name)
    if ($altCode !== '') {
        return $altCode;
    }

    return $code !== '' ? $code : 'the programme';
}

/**
 * Resolve the best effective program_code for reference generation.
 * Uses program_code column, then program column, then 'GEN'.
 */
function ai_letter_resolve_code(array $applicant): string
{
    $code = trim((string)($applicant['program_code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $alt = trim((string)($applicant['program'] ?? ''));
    return $alt !== '' ? $alt : 'GEN';
}

/**
 * Calculate TEVETA academic term for a given date.
 * TEVETA Zambia runs three terms per academic year:
 *   Term 1 → January – April
 *   Term 2 → May – August
 *   Term 3 → September – December
 *
 * Returns current term, next intake term, and start month of next term.
 */
function ai_letter_teveta_term(?string $referenceDate = null): array
{
    $ts    = $referenceDate ? strtotime($referenceDate) : time();
    $month = (int)date('n', $ts);
    $year  = (int)date('Y', $ts);

    if ($month <= 4) {
        $currentTerm = 1;
    } elseif ($month <= 8) {
        $currentTerm = 2;
    } else {
        $currentTerm = 3;
    }

    // Next intake is the following term; wrap to Term 1 of next year after Term 3.
    $nextTerm = $currentTerm + 1;
    $nextYear = $year;
    if ($nextTerm > 3) {
        $nextTerm = 1;
        $nextYear = $year + 1;
    }

    $termStartMonths = [1 => 'January', 2 => 'May', 3 => 'September'];
    $termEndMonths   = [1 => 'April',   2 => 'August', 3 => 'December'];

    return [
        'current_label'  => "Term {$currentTerm} {$year}",
        'next_label'     => "Term {$nextTerm} {$nextYear}",
        'next_start'     => $termStartMonths[$nextTerm] . ' ' . $nextYear,
        'next_end'       => $termEndMonths[$nextTerm] . ' ' . $nextYear,
        'next_term_num'  => $nextTerm,
        'next_year'      => $nextYear,
    ];
}

$letterTypes = [
    'acceptance'             => 'Offer of Admission (Acceptance)',
    'conditional_acceptance' => 'Conditional Offer of Admission',
    'rejection'              => 'Regret / Unsuccessful Application',
    'request_documents'      => 'Request for Outstanding Documents',
];

function ai_letter_fallback(string $type, array $merge): string
{
    $name    = $merge['{{full_name}}']    ?? 'Applicant';
    $program = $merge['{{program}}']      ?? 'the programme';
    $date    = $merge['{{date}}']         ?? date('j F Y');
    $ref     = $merge['{{reference}}']    ?? '';
    $dl14    = $merge['{{deadline_14}}']  ?? date('j F Y', strtotime('+14 days'));
    $dl30    = $merge['{{deadline_30}}']  ?? date('j F Y', strtotime('+30 days'));
    $officer = $merge['{{officer_name}}'] ?? 'Admissions Officer';
    $term    = $merge['{{teveta_term}}']  ?? '';
    $intakeLine = $term !== '' ? " for {$term}" : ' for the upcoming intake';

    if ($type === 'rejection') {
        return "INDUSTRIAL TRAINING CENTRE\n"
             . "Admissions Office\n"
             . "P.O. Box 34755, Lusaka, Zambia\n"
             . "Email: admissions@itc.edu.zm\n\n"
             . "Date: {$date}\n"
             . "Ref: {$ref}\n\n"
             . "Dear {$name},\n\n"
             . "RE: APPLICATION FOR ADMISSION TO THE " . strtoupper($program) . " PROGRAMME\n\n"
             . "Thank you for your interest in studying at the Industrial Training Centre. We appreciate the time you took to submit your application{$intakeLine}.\n\n"
             . "After careful review of your academic qualifications and application details, we regret to inform you that we are unable to offer you admission to the {$program} programme at this stage.\n\n"
             . "We receive a high volume of qualified applications each semester, and our intake capacity remains limited. We encourage you to improve your qualifications or re-apply for future intakes.\n\n"
             . "We wish you the very best in your future academic pursuits.\n\n"
             . "Yours faithfully,\n\n"
             . "[Signature]\n\n"
             . "{$officer}\n"
             . "Admissions Officer\n"
             . "For/Director\n"
             . "[Official Stamp Area]";
    }

    if ($type === 'request_documents') {
        return "INDUSTRIAL TRAINING CENTRE\n"
             . "Admissions Office\n"
             . "P.O. Box 34755, Lusaka, Zambia\n"
             . "Email: admissions@itc.edu.zm\n\n"
             . "Date: {$date}\n"
             . "Ref: {$ref}\n\n"
             . "Dear {$name},\n\n"
             . "RE: OUTSTANDING DOCUMENTS FOR THE " . strtoupper($program) . " PROGRAMME APPLICATION\n\n"
             . "Thank you for your application to enrol in the {$program} programme at the Industrial Training Centre{$intakeLine}.\n\n"
             . "We have reviewed your application and find that we require additional documents to finalise our assessment. Please submit certified copies of the following documents to the Admissions Office by {$dl14}:\n\n"
             . "- [Please list outstanding documents here, e.g. Grade 12 Certificate / NRC / Receipt]\n\n"
             . "Please submit these documents either in person or via email to admissions@itc.edu.zm. Note that your application cannot proceed to an admission decision until all outstanding documents are received.\n\n"
             . "Thank you for your prompt attention to this matter.\n\n"
             . "Yours faithfully,\n\n"
             . "[Signature]\n\n"
             . "{$officer}\n"
             . "Admissions Officer\n"
             . "For/Director\n"
             . "[Official Stamp Area]";
    }

    if ($type === 'conditional_acceptance') {
        return "INDUSTRIAL TRAINING CENTRE\n"
             . "Admissions Office\n"
             . "P.O. Box 34755, Lusaka, Zambia\n"
             . "Email: admissions@itc.edu.zm\n\n"
             . "Date: {$date}\n"
             . "Ref: {$ref}\n\n"
             . "Dear {$name},\n\n"
             . "RE: CONDITIONAL OFFER OF ADMISSION TO THE " . strtoupper($program) . " PROGRAMME\n\n"
             . "We are pleased to offer you conditional admission to the {$program} programme at the Industrial Training Centre{$intakeLine}.\n\n"
             . "This offer of admission is subject to you satisfying the following conditions before registration:\n\n"
             . "- [Please specify conditions here, e.g. presentation of original certificates / medical certificate]\n\n"
             . "To accept this offer and secure your place, you must pay the non-refundable registration deposit of [Amount] by {$dl14}. Payment must be made through our approved bank accounts at [Bank Name] (Account No: [Account Number]) or via mobile money channels. An official receipt will be issued upon presentation of the deposit slip.\n\n"
             . "Please report to the Registrar's Office for final enrolment and registration by {$dl30}.\n\n"
             . "Congratulations on your conditional admission. We look forward to welcoming you to the Centre.\n\n"
             . "Yours faithfully,\n\n"
             . "[Signature]\n\n"
             . "{$officer}\n"
             . "Admissions Officer\n"
             . "For/Director\n"
             . "[Official Stamp Area]";
    }

    // Default 'acceptance'
    return "INDUSTRIAL TRAINING CENTRE\n"
         . "Admissions Office\n"
         . "P.O. Box 34755, Lusaka, Zambia\n"
         . "Email: admissions@itc.edu.zm\n\n"
         . "Date: {$date}\n"
         . "Ref: {$ref}\n\n"
         . "Dear {$name},\n\n"
         . "RE: OFFER OF ADMISSION TO THE " . strtoupper($program) . " PROGRAMME\n\n"
         . "We are pleased to offer you admission to the {$program} programme at the Industrial Training Centre{$intakeLine}.\n\n"
         . "To accept this offer and secure your place, you must pay the non-refundable registration deposit of [Amount] by {$dl14}. Payment must be made through our approved bank accounts at [Bank Name] (Account No: [Account Number]) or via mobile money channels. An official receipt must be obtained from the finance office upon presentation of the payment slip.\n\n"
         . "You are expected to report for registration and commencement of classes by {$dl30}. Please bring original copies of your academic qualifications and national identity documents for verification.\n\n"
         . "Congratulations on your admission to the Centre. We wish you success in your studies.\n\n"
         . "Yours faithfully,\n\n"
         . "[Signature]\n\n"
         . "{$officer}\n"
         . "Admissions Officer\n"
         . "For/Director\n"
         . "[Official Stamp Area]";
}

$programs  = ai_letter_programs($db);
$recent    = ai_letter_recent($db);
$aiStatus  = wuc_ai_local_status();
$officerId = (string)($_SESSION['staff_id'] ?? ($_SESSION['user_name'] ?? 'admissions'));

$admissions_name = '';
$staff_id = $_SESSION['staff_id'] ?? null;
if ($staff_id && isset($db)) {
    $stmt = $db->prepare("SELECT Fname, Lname FROM staff WHERE staff_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $staff_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if ($res) {
            $admissions_name = trim(($res['Fname'] ?? '') . ' ' . ($res['Lname'] ?? ''));
        }
        $stmt->close();
    }
}
if ($admissions_name === '' && isset($_SESSION['user_name'])) {
    $admissions_name = trim((string)$_SESSION['user_name']);
}
if ($admissions_name === '') {
    $admissions_name = 'Admissions Officer';
}

$errors     = [];
$letter     = null;
$usedAi     = false;
$resultMeta = null;

$old = [
    'applicant_id' => (int)($_POST['applicant_id'] ?? 0),
    'full_name'    => '',
    'program_code' => '',
    'intake'       => '',
    'letter_type'  => (string)($_POST['letter_type'] ?? 'acceptance'),
    'notes'        => trim((string)($_POST['notes'] ?? '')),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    }

    $applicant = null;
    if ($old['applicant_id'] <= 0) {
        $errors[] = 'Please select a processed applicant from the list.';
    } else {
        $applicant = ai_letter_load($db, $old['applicant_id']);
        if ($applicant === null) {
            $errors[] = 'Selected applicant could not be found.';
        } else {
            $old['full_name']    = trim(
                (string)($applicant['title'] ?? '') . ' '
                . (string)($applicant['Fname'] ?? '') . ' '
                . (string)($applicant['Lname'] ?? '')
            );
            // Use resolved code (program_code column, then program column)
            $old['program_code'] = ai_letter_resolve_code($applicant);
            $old['intake']       = trim((string)($applicant['intake'] ?? ''));
        }
    }

    if (!isset($letterTypes[$old['letter_type']])) {
        $old['letter_type'] = 'acceptance';
    }

    $rate = wuc_ai_rate_limit('admissions_letter_drafter', 20, 3600);
    if (!$rate['ok']) {
        $errors[] = 'Too many AI requests. Try again in about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
    }

    if (!$errors) {
        // Resolve full programme name with JOIN-first priority
        $programName = ai_letter_resolve_program_name($applicant, $programs);

        // Determine whether this is a TEVETA term-based programme (non-short-course).
        // Default to true when period_mode is unresolved: all ITC academic programmes
        // run on the TEVETA term calendar; short courses live in short_courses, not here.
        $periodMode  = trim((string)($applicant['resolved_period_mode'] ?? ''));
        $isTermBased = ($periodMode === 'term' || $periodMode === '');
        $teveta      = ai_letter_teveta_term();

        // Build intake label: prefer stored intake value; for term-based programmes
        // add TEVETA term context; for others keep the stored value or "upcoming intake".
        $storedIntake = $old['intake'] !== '' ? $old['intake'] : '';
        if ($isTermBased) {
            // If intake column already looks like a month/term keep it; always append TEVETA term.
            $intakeLabel = $teveta['next_label'] . ' (' . $teveta['next_start'] . ' – ' . $teveta['next_end'] . ')';
        } else {
            $intakeLabel = $storedIntake !== '' ? $storedIntake : 'the upcoming intake';
        }

        $progCode  = $old['program_code'] !== '' ? $old['program_code'] : 'GEN';
        $reference = 'ITC/ADM/' . $progCode . '/' . date('Y') . '/' . str_pad(
            (string)($old['applicant_id'] ?: random_int(100, 999)),
            4,
            '0',
            STR_PAD_LEFT
        );

        $deadline14 = date('j F Y', strtotime('+14 days'));
        $deadline30 = date('j F Y', strtotime('+30 days'));

        // Local merge map — personal identifiers are NEVER sent to the AI.
        $merge = [
            '{{full_name}}'    => $old['full_name'],
            '{{program}}'      => $programName,
            '{{intake}}'       => $intakeLabel,
            '{{teveta_term}}'  => $isTermBased ? $teveta['next_label'] : '',
            '{{date}}'         => date('j F Y'),
            '{{reference}}'    => $reference,
            '{{institution}}'  => 'ITC Industrial Training Centre',
            '{{deadline_14}}'  => $deadline14,
            '{{deadline_30}}'  => $deadline30,
            '{{officer_name}}' => $admissions_name,
        ];

        // Context passed to AI: NO personal name. Non-identifying details + placeholders only.
        $context = [
            'letter_type'         => $old['letter_type'],
            'program'             => $programName,
            'program_type'        => trim((string)($applicant['resolved_program_type'] ?? '')),
            'intake'              => $intakeLabel,
            'is_term_based'       => $isTermBased,
            'teveta_term'         => $isTermBased ? $teveta['next_label'] : null,
            'institution'         => 'ITC Industrial Training Centre',
            'officer_notes'       => wuc_ai_truncate($old['notes'], 1500),
            'placeholders_to_use' => [
                '{{full_name}}', '{{date}}', '{{reference}}', '{{program}}',
                '{{intake}}', '{{teveta_term}}', '{{institution}}',
                '{{deadline_14}}', '{{deadline_30}}', '{{officer_name}}',
            ],
            'rules' => [
                'use_placeholders_for_personal_fields' => true,
                'do_not_invent_a_name_or_id'           => true,
                'professional_formal_tone'              => true,
            ],
        ];
        $contextJson = wuc_ai_context_json($context, 8000);

        $termNote = $isTermBased
            ? ' The programme is TEVETA-accredited and runs on a term calendar; reference the intake as {{intake}} (which is ' . $teveta['next_label'] . ') wherever relevant.'
            : '';

        $typeGuidance = [
            'acceptance' => 'Write a warm, professional admission offer letter. Congratulate the applicant on admission to the {{program}} programme, outline clear enrolment guidelines, instruct deposit payment by {{deadline_14}}, note the class reporting date as {{deadline_30}}, explain registration and verification requirements, note the signing officer is {{officer_name}}, and keep the tone official and credible.' . $termNote,
            'conditional_acceptance' => 'Write a professional conditional admission offer letter for the {{program}} programme. Offer admission subject to clearly listed conditions (from the officer notes), instruct deposit payment by {{deadline_14}} and reporting/enrolment by {{deadline_30}}, note the signing officer is {{officer_name}}, and explain registration details.' . $termNote,
            'rejection' => 'Write a polite, formal regret letter for the {{program}} programme. Empathise and explain the application was unsuccessful due to capacity limits. Be encouraging, suggest re-application for future sessions, and sign as {{officer_name}}.' . $termNote,
            'request_documents' => 'Write a formal request for outstanding documents (from the officer notes) for the {{program}} programme. State that the application cannot proceed without them, request certified submissions via email or in person by {{deadline_14}}, and sign as {{officer_name}}.' . $termNote,
        ][$old['letter_type']];

        $result = wuc_ai_generate($db, [
            'feature'       => 'admissions_letter_drafter',
            'user_role'     => 'admissions',
            'user_id'       => $officerId,
            'input_summary' => $old['letter_type'] . ' | ' . $old['program_code'],
            'context_hash'  => hash('sha256', $contextJson),
            'messages'      => [
                [
                    'role'    => 'system',
                    'content' => 'You draft official letters for the Admissions Office of ITC Industrial Training Centre, a TEVETA-accredited vocational college in Lusaka, Zambia. '
                               . 'Use ONLY the supplied JSON. For any personal field (applicant name, date, reference, deadlines, officer name) you MUST use the exact placeholder tokens provided (e.g. {{full_name}}, {{date}}, {{reference}}, {{deadline_14}}, {{deadline_30}}, {{officer_name}}, {{teveta_term}}) — never invent real names or dates. '
                               . 'Use formal Zambian/British institutional language: "programme" instead of "program" and "enrolment" instead of "enrollment". '
                               . 'Always state the full programme name ({{program}}) clearly in the subject line and body. '
                               . 'When is_term_based is true, reference the TEVETA intake term ({{intake}}) precisely in the opening paragraph. '
                               . 'Avoid generic, robotic, or AI-generated wording. Do not use unnecessary bold text or overly promotional phrases. '
                               . 'Structure the letter cleanly: header details (Industrial Training Centre, P.O. Box 34755, Lusaka), reference, date, salutation, subject line, body paragraphs, closing, signature line, officer title (signed by {{officer_name}}), and stamp area.',
                ],
                [
                    'role'    => 'user',
                    'content' => "Letter request (JSON):\n{$contextJson}\n\nTask: {$typeGuidance}",
                ],
            ],
            'fallback' => static function () use ($old, $merge): string {
                return ai_letter_fallback($old['letter_type'], $merge);
            },
        ]);

        // Local placeholder merge — personal identifiers inserted here, off the AI path.
        $letterText = strtr((string)$result['text'], $merge);
        // Catch common variant spellings the model might emit.
        $letterText = preg_replace('/\{\{\s*full[_\s]?name\s*\}\}/i',       $merge['{{full_name}}'],    $letterText);
        $letterText = preg_replace('/\{\{\s*date\s*\}\}/i',                  $merge['{{date}}'],         $letterText);
        $letterText = preg_replace('/\{\{\s*reference\s*\}\}/i',             $merge['{{reference}}'],    $letterText);
        $letterText = preg_replace('/\{\{\s*deadline[_\s]?14\s*\}\}/i',      $merge['{{deadline_14}}'],  $letterText);
        $letterText = preg_replace('/\{\{\s*deadline[_\s]?30\s*\}\}/i',      $merge['{{deadline_30}}'],  $letterText);
        $letterText = preg_replace('/\{\{\s*officer[_\s]?name\s*\}\}/i',     $merge['{{officer_name}}'], $letterText);
        $letterText = preg_replace('/\{\{\s*teveta[_\s]?term\s*\}\}/i',      $merge['{{teveta_term}}'],  $letterText);
        $letterText = preg_replace('/\{\{\s*intake\s*\}\}/i',                $merge['{{intake}}'],       $letterText);
        $letterText = preg_replace('/\{\{\s*program(?:me)?\s*\}\}/i',        $merge['{{program}}'],      $letterText);
        $letterText = str_replace('*', '', $letterText);

        $letter     = $letterText;
        $usedAi     = (bool)$result['used_ai'];
        $resultMeta = $result;
    }
}

require "includes/nav.php";
?>

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="mb-1"><i class="fas fa-envelope-open-text me-2" style="color:#2E3190;"></i>AI Letter Drafter</h3>
            <p class="text-muted mb-0">Generate professional admission, conditional, regret and document-request letters.</p>
        </div>
        <span class="badge <?php echo $aiStatus['model_ready'] ? 'bg-success' : 'bg-secondary'; ?> fs-6">
            <i class="fas fa-robot me-1"></i><?php echo $aiStatus['model_ready'] ? 'AI online' : 'Fallback mode'; ?>
        </span>
    </div>

    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="fas fa-shield-halved mt-1"></i>
        <div class="small">
            <strong>Privacy by design:</strong> the applicant's name is merged into the letter <em>locally</em> and is
            never sent to the AI. Always review and sign off before sending. AI drafts are a starting point, not final.
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach (array_unique($errors) as $e): ?>
                <div><?php echo ai_letter_h($e); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong><i class="fas fa-sliders me-2"></i>Letter details</strong></div>
                <div class="card-body">
                    <form method="post" action="ai_letter_drafter.php">
                        <input type="hidden" name="csrf_token" value="<?php echo ai_letter_h($_SESSION['csrf_token'] ?? ''); ?>">

                        <div class="mb-3">
                            <label class="form-label" for="applicant_id">Processed applicant</label>
                            <select class="form-select" id="applicant_id" name="applicant_id" required>
                                <option value="">— Select processed applicant —</option>
                                <?php foreach ($recent as $a):
                                    // Show full program name if resolved, else fall back to raw value
                                    $displayProgram = trim((string)($a['resolved_program_name'] ?? ''));
                                    if ($displayProgram === '') {
                                        $displayProgram = trim((string)($a['program'] ?? $a['program_code'] ?? ''));
                                    }
                                    $displayName = trim($a['Fname'] . ' ' . $a['Lname']);
                                    $label = $displayName . ($displayProgram !== '' ? ' — ' . $displayProgram : '');
                                ?>
                                    <option value="<?php echo (int)$a['id']; ?>" <?php echo $old['applicant_id'] === (int)$a['id'] ? 'selected' : ''; ?>>
                                        <?php echo ai_letter_h($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="letter_type">Letter type</label>
                            <select class="form-select" id="letter_type" name="letter_type">
                                <?php foreach ($letterTypes as $v => $label): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $old['letter_type'] === $v ? 'selected' : ''; ?>><?php echo ai_letter_h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="notes">Notes / conditions / required documents <span class="text-muted">(optional)</span></label>
                            <textarea class="form-control" id="notes" name="notes" rows="4" maxlength="1500"
                                placeholder="e.g. Condition: pass maths placement test. OR: missing Grade 12 certificate and NRC copy."><?php echo ai_letter_h($old['notes']); ?></textarea>
                        </div>

                        <button class="btn btn-primary w-100" type="submit" style="background:#2E3190;border-color:#2E3190;">
                            <i class="fas fa-wand-magic-sparkles me-2"></i>Draft letter
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="fas fa-file-lines me-2"></i>Draft letter</strong>
                    <?php if ($letter !== null): ?>
                        <div class="d-flex align-items-center gap-2">
                            <small class="text-muted">
                                <?php echo $usedAi ? ai_letter_h($resultMeta['model']) . ' · ' . number_format($resultMeta['duration_ms'] / 1000, 1) . 's' : 'Fallback'; ?>
                            </small>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="aiLetterCopy()"><i class="fas fa-copy me-1"></i>Copy</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="aiLetterPrint()"><i class="fas fa-print me-1"></i>Print</button>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($letter === null): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-envelope-open-text fa-3x mb-3 opacity-25"></i>
                            <p class="mb-1 fw-semibold">No letter yet</p>
                            <p class="small mb-0">Fill in the details and click <em>Draft letter</em>.</p>
                        </div>
                    <?php else: ?>
                        <?php if (!$usedAi): ?>
                            <div class="alert alert-warning small py-2"><?php echo ai_letter_h(wuc_ai_fallback_notice($resultMeta)); ?></div>
                        <?php endif; ?>
                        <textarea id="aiLetterText" class="form-control" rows="20" style="font-family:Georgia,serif;line-height:1.6;"><?php echo ai_letter_h($letter); ?></textarea>
                        <div class="form-text mt-2">Edit as needed, then Copy or Print. Review before sending.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
function aiLetterCopy() {
    var t = document.getElementById('aiLetterText');
    if (!t) return;
    t.select();
    document.execCommand('copy');
}
function aiLetterPrint() {
    var t = document.getElementById('aiLetterText');
    if (!t) return;

    var escapeHtml = function(str) {
        return str.replace(/[&<>]/g, function(c) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;'}[c];
        });
    };

    var paras = t.value.split(/\n\s*\n/);
    for (var i = 0; i < paras.length; i++) {
        var cleanPara = paras[i].replace(/\*/g, '');
        var lines = cleanPara.split('\n');
        for (var j = 0; j < lines.length; j++) {
            var cleanLine = lines[j].trim();
            if (/^\s*RE\s*:/i.test(cleanLine)) {
                lines[j] = '<strong>' + escapeHtml(cleanLine) + '</strong>';
            } else {
                lines[j] = escapeHtml(lines[j]);
            }
        }
        var paraContent = lines.join('<br>');
        paras[i] = '<p style="margin: 0 0 14px 0; text-align: justify;">' + paraContent + '</p>';
    }
    var letterContent = paras.join('');

    var w = window.open('', '_blank');
    w.document.write('<!DOCTYPE html><html><head><title>Print Letter</title>'
        + '<style>'
        + 'body { font-family: "Georgia", serif; font-size: 13.5px; line-height: 1.6; margin: 0; padding: 25px; color: #000; background: #fff; }'
        + '.letterhead { text-align: center; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 25px; }'
        + '.logo-row { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; }'
        + 'img.report-logo { height: 60px; width: auto; margin: 0 auto; }'
        + '.institution-block { text-align: center; }'
        + '.institution-name { font-weight: 700; font-size: 15px; text-transform: uppercase; line-height: 1.1; color: #000; }'
        + '.institution-tag { font-size: 9.5px; color: #444; font-weight: 400; margin-top: 2px; }'
        + '.letter-body { width: 100%; }'
        + '@media print { .no-print { display: none !important; } }'
        + '</style></head><body>'
        + '<div class="letterhead">'
        + '  <div class="logo-row">'
        + '    <img class="report-logo" src="/wucportal/images/itc_logo.png" alt="ITC Logo">'
        + '    <div class="institution-block">'
        + '      <div class="institution-name">Industrial Training Centre</div>'
        + '      <div class="institution-tag">Official Document &middot; Admissions Office</div>'
        + '    </div>'
        + '  </div>'
        + '</div>'
        + '<div class="letter-body">'
        + letterContent
        + '</div>'
        + '</body></html>');
    w.document.close();
    w.focus();
    w.onload = function() {
        w.print();
    };
}
</script>

<?php require "includes/footer.php"; ?>

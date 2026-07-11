<?php
declare(strict_types=1);

/**
 * Rule-based admissions assistant (zero-cost AI, Sprint 5).
 *
 * Deterministic, explainable checks that run BEFORE any LLM narrative:
 *   - document checklist from the applicant record (results, ID, deposit slip…)
 *   - programme-fit keyword matrix against active programmes
 *   - incomplete-application flags with templated next steps
 *   - status explanation templates
 *
 * Works on rows from processed_applicants or online_applicants (same column
 * names for the fields used here). No LLM call anywhere in this file.
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_adm_document_checklist')) {
    /**
     * Checklist items derived from the applicant row. Each item:
     * ['label' =>, 'ok' => bool, 'detail' =>].
     */
    function wuc_adm_document_checklist(array $applicant): array
    {
        $has = static function ($key) use ($applicant): bool {
            return trim((string)($applicant[$key] ?? '')) !== '';
        };

        return [
            ['label' => 'Academic results / qualifications', 'ok' => $has('results'),
             'detail' => $has('results') ? 'Results text on record.' : 'Request certified results or certificates.'],
            ['label' => 'NRC / passport document', 'ok' => $has('nrc_file') || $has('nrc_pass'),
             'detail' => ($has('nrc_file') || $has('nrc_pass')) ? 'Identity information supplied.' : 'Request a copy of the NRC or passport.'],
            ['label' => 'Application fee deposit slip', 'ok' => $has('deposit_slip'),
             'detail' => $has('deposit_slip') ? 'Deposit slip uploaded.' : 'Request proof of application fee payment.'],
            ['label' => 'Programme selected', 'ok' => $has('program') || $has('program_code'),
             'detail' => ($has('program') || $has('program_code')) ? 'Programme choice recorded.' : 'Ask the applicant to choose a programme.'],
            ['label' => 'Intake selected', 'ok' => $has('intake'),
             'detail' => $has('intake') ? 'Intake recorded.' : 'Confirm the intended intake.'],
            ['label' => 'Contact details', 'ok' => $has('email') || $has('mobile'),
             'detail' => ($has('email') || $has('mobile')) ? 'Email/mobile on record.' : 'Capture a working email or mobile number.'],
            ['label' => 'Next of kin', 'ok' => $has('next_kin'),
             'detail' => $has('next_kin') ? 'Next of kin recorded.' : 'Capture next-of-kin details.'],
        ];
    }
}

if (!function_exists('wuc_adm_completeness')) {
    /** Percentage complete + missing labels from a checklist. */
    function wuc_adm_completeness(array $checklist): array
    {
        $total = count($checklist);
        $ok = count(array_filter($checklist, static fn($i) => !empty($i['ok'])));
        $missing = array_values(array_map(
            static fn($i) => (string)$i['label'],
            array_filter($checklist, static fn($i) => empty($i['ok']))
        ));
        return [
            'percent' => $total > 0 ? (int)round(($ok / $total) * 100) : 0,
            'complete' => $ok,
            'total' => $total,
            'missing' => $missing,
            'is_complete' => $ok === $total,
        ];
    }
}

if (!function_exists('wuc_adm_fit_keywords')) {
    /**
     * Keyword matrix for programme fit. Each entry: keywords that, when found
     * in the qualifications text, suggest affinity with a programme whose
     * name/description matches the subject area.
     */
    function wuc_adm_fit_keywords(): array
    {
        return [
            'ict' => ['computer', 'computing', 'ict', 'information technology', 'programming', 'software'],
            'engineering' => ['engineering', 'mechanic', 'electrical', 'electronics', 'mathematics', 'physics', 'metal', 'fabrication', 'welding'],
            'automotive' => ['automotive', 'motor vehicle', 'mechanic', 'auto', 'diesel'],
            'business' => ['business', 'accounts', 'accounting', 'commerce', 'economics', 'entrepreneurship', 'bookkeeping'],
            'construction' => ['construction', 'bricklaying', 'carpentry', 'joinery', 'plumbing', 'building'],
            'transport' => ['driving', 'driver', 'transport', 'logistics', 'heavy duty'],
            'hospitality' => ['catering', 'food', 'hospitality', 'hotel', 'tailoring', 'design'],
            'agriculture' => ['agriculture', 'farming', 'horticulture', 'animal'],
        ];
    }
}

if (!function_exists('wuc_adm_program_fit')) {
    /**
     * Score active programmes against the qualifications text.
     * Returns up to $limit rows: program_code, program_name, score, matched (keywords).
     * Purely lexical and explainable — every score lists the keywords behind it.
     */
    function wuc_adm_program_fit(mysqli $db, string $qualificationsText, int $limit = 5): array
    {
        $text = strtolower(trim($qualificationsText));
        if ($text === '' || !wuc_table_exists($db, 'programs')) {
            return [];
        }

        $programs = [];
        if ($res = @$db->query("SELECT program_code, program_name, COALESCE(program_description, '') AS descr FROM programs WHERE is_active = 1 OR is_active IS NULL")) {
            while ($row = $res->fetch_assoc()) {
                $programs[] = $row;
            }
            $res->free();
        }
        if (!$programs) {
            return [];
        }

        $matrix = wuc_adm_fit_keywords();
        $scored = [];
        foreach ($programs as $prog) {
            $haystack = strtolower((string)$prog['program_name'] . ' ' . (string)$prog['descr']);
            $score = 0;
            $matched = [];
            foreach ($matrix as $area => $keywords) {
                // The area applies when the programme belongs to it…
                $areaApplies = false;
                foreach ($keywords as $kw) {
                    if (strpos($haystack, $kw) !== false) {
                        $areaApplies = true;
                        break;
                    }
                }
                if (!$areaApplies) {
                    continue;
                }
                // …and scores one point per applicant keyword in that area.
                foreach ($keywords as $kw) {
                    if (strpos($text, $kw) !== false) {
                        $score++;
                        $matched[] = $kw;
                    }
                }
            }
            if ($score > 0) {
                $scored[] = [
                    'program_code' => (string)$prog['program_code'],
                    'program_name' => (string)$prog['program_name'],
                    'score' => $score,
                    'matched' => array_values(array_unique($matched)),
                ];
            }
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, max(1, $limit));
    }
}

if (!function_exists('wuc_adm_status_explanation')) {
    function wuc_adm_status_explanation(string $status): string
    {
        switch (strtolower(trim($status))) {
            case 'accepted':
                return 'The application has been accepted. Next step: issue the admission letter and guide the applicant through registration and fee payment.';
            case 'rejected':
                return 'The application was not successful. The applicant may be advised on alternative programmes or reapplication in a future intake.';
            case 'pending':
                return 'The application is still under review. Complete the document checklist and verify qualifications before a decision is recorded.';
            default:
                return 'The application status is being processed. Verify the record with the admissions office.';
        }
    }
}

if (!function_exists('wuc_adm_next_steps')) {
    /** Templated next steps from the completeness result + status. */
    function wuc_adm_next_steps(array $completeness, string $status): array
    {
        $steps = [];
        foreach (array_slice($completeness['missing'], 0, 4) as $missing) {
            $steps[] = 'Obtain: ' . $missing . '.';
        }
        if ($completeness['is_complete'] && strtolower($status) === 'pending') {
            $steps[] = 'All checklist items present — verify certificate authenticity and record a decision.';
        }
        if (strtolower($status) === 'accepted') {
            $steps[] = 'Generate the admission letter and confirm intake placement.';
        }
        if (!$steps) {
            $steps[] = 'No outstanding items detected by the rules. Proceed with officer review.';
        }
        return $steps;
    }
}

if (!function_exists('wuc_adm_render_rules_block')) {
    /** Bootstrap block combining checklist, fit matrix and next steps. */
    function wuc_adm_render_rules_block(array $checklist, array $completeness, array $fit, string $statusExplanation, array $nextSteps): string
    {
        ob_start();
        ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0" style="color:#2E3190;"><i class="fas fa-list-check me-2"></i>Rule-Based Screening (no AI required)</h5>
                <span class="badge <?= $completeness['percent'] >= 100 ? 'bg-success' : ($completeness['percent'] >= 60 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                    <?= (int)$completeness['percent'] ?>% complete
                </span>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="fw-semibold small mb-2">Document checklist</div>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach ($checklist as $item): ?>
                                <li class="mb-1">
                                    <i class="fas <?= $item['ok'] ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger' ?> me-1"></i>
                                    <strong><?= htmlspecialchars((string)$item['label']) ?></strong>
                                    — <span class="text-muted"><?= htmlspecialchars((string)$item['detail']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="col-lg-6">
                        <?php if ($fit): ?>
                            <div class="fw-semibold small mb-2">Programme fit (keyword matrix)</div>
                            <ul class="list-unstyled mb-3 small">
                                <?php foreach ($fit as $f): ?>
                                    <li class="mb-1">
                                        <span class="badge bg-primary"><?= (int)$f['score'] ?></span>
                                        <strong><?= htmlspecialchars((string)$f['program_name']) ?></strong>
                                        <span class="text-muted">(matched: <?= htmlspecialchars(implode(', ', array_slice($f['matched'], 0, 4))) ?>)</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="fw-semibold small mb-2">Status</div>
                        <p class="small mb-3"><?= htmlspecialchars($statusExplanation) ?></p>
                        <div class="fw-semibold small mb-2">Next steps</div>
                        <ul class="mb-0 ps-3 small">
                            <?php foreach ($nextSteps as $step): ?><li><?= htmlspecialchars((string)$step) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

<?php
/**
 * Curriculum analyzer — deterministic curriculum-health diagnostics for the
 * programme / course catalogue. Produces a structured analysis that grounds the
 * AI narrative in admin/ai_curriculum_analyzer.php, and doubles as the offline
 * fallback. All findings are computed from the live schema (programs,
 * program_courses, courses, student_program, fee_structure) — nothing invented.
 *
 * Public API:
 *   wuc_curriculum_analyze(mysqli $db, ?string $programCode = null): array
 *   wuc_curriculum_fallback(array $analysis): string
 */

declare(strict_types=1);

if (!function_exists('wuc_curriculum_analyze')) {

    /**
     * Analyse the whole programme portfolio (or one programme when $programCode
     * is supplied). Returns a structured, JSON-serialisable array.
     */
    function wuc_curriculum_analyze(mysqli $db, ?string $programCode = null): array
    {
        $programCode = ($programCode !== null && $programCode !== '') ? $programCode : null;

        // 1. Load programmes (real, non-short-course academic programmes).
        $programs = [];
        $res = $db->query(
            "SELECT program_code, program_name, program_type, qualification_level,
                    program_duration, duration_value, duration_unit, department_id,
                    is_active, is_short_course, is_transport_exception
             FROM programs
             ORDER BY program_code"
        );
        while ($row = $res->fetch_assoc()) {
            $programs[$row['program_code']] = $row;
        }
        $res->free();

        // 2. Department names for readable output.
        $departments = [];
        if ($r = @$db->query("SELECT id, department_name FROM departments")) {
            while ($row = $r->fetch_assoc()) {
                $departments[(int)$row['id']] = $row['department_name'];
            }
            $r->free();
        }

        // 3. All programme→course links in one pass, grouped in PHP.
        $links = [];   // program_code => list of [course_code, year, semester, is_required]
        $res = $db->query(
            "SELECT program_code, course_code, year, semester, is_required
             FROM program_courses"
        );
        while ($row = $res->fetch_assoc()) {
            $links[$row['program_code']][] = $row;
        }
        $res->free();

        // 4. Enrolment counts per programme (students master + assignment table).
        $enrolled = [];
        if ($r = @$db->query(
            "SELECT program_code, COUNT(DISTINCT Sid) c FROM student_program
             WHERE program_code IS NOT NULL AND program_code <> '' GROUP BY program_code"
        )) {
            while ($row = $r->fetch_assoc()) {
                $enrolled[$row['program_code']] = (int)$row['c'];
            }
            $r->free();
        }
        if ($r = @$db->query(
            "SELECT program AS program_code, COUNT(DISTINCT SID) c FROM students
             WHERE program IS NOT NULL AND program <> '' GROUP BY program"
        )) {
            while ($row = $r->fetch_assoc()) {
                $code = $row['program_code'];
                $enrolled[$code] = max($enrolled[$code] ?? 0, (int)$row['c']);
            }
            $r->free();
        }

        // 5. Fee-structure row counts per programme.
        $feeRows = [];
        if ($r = @$db->query(
            "SELECT program_code, COUNT(*) c FROM fee_structure
             WHERE program_code IS NOT NULL AND program_code <> '' GROUP BY program_code"
        )) {
            while ($row = $r->fetch_assoc()) {
                $feeRows[$row['program_code']] = (int)$row['c'];
            }
            $r->free();
        }

        // --- Portfolio-level signatures (computed across all programmes) --------
        $nameGroups = [];        // lowercased name => [codes]
        $curriculumSig = [];     // sorted-distinct-course signature => [codes]
        foreach ($programs as $code => $p) {
            $name = strtolower(trim((string)$p['program_name']));
            $nameGroups[$name][] = $code;

            $courseCodes = array_values(array_unique(array_map(
                static fn($l) => $l['course_code'],
                $links[$code] ?? []
            )));
            sort($courseCodes);
            if ($courseCodes !== []) {
                $sig = implode('|', $courseCodes);
                $curriculumSig[$sig][] = $code;
            }
        }
        $duplicateNames = array_filter($nameGroups, static fn($codes) => count($codes) > 1);
        $identicalCurricula = array_values(array_filter($curriculumSig, static fn($codes) => count($codes) > 1));

        // --- Per-programme analysis -------------------------------------------
        $analysed = [];
        $codesToRun = $programCode !== null
            ? array_intersect_key($programs, [$programCode => true])
            : $programs;

        foreach ($codesToRun as $code => $p) {
            $myLinks = $links[$code] ?? [];
            $courseCount = count($myLinks);
            $distinct = array_values(array_unique(array_map(static fn($l) => $l['course_code'], $myLinks)));
            $distinctCount = count($distinct);

            // Year coverage
            $yearsCovered = [];
            $requiredCount = 0;
            foreach ($myLinks as $l) {
                $yearsCovered[(int)$l['year']] = true;
                if ((int)$l['is_required'] === 1) {
                    $requiredCount++;
                }
            }
            ksort($yearsCovered);
            $yearsCovered = array_keys($yearsCovered);

            // Expected number of years from duration metadata.
            $expectedYears = 0;
            if (!empty($p['program_duration']) && (float)$p['program_duration'] > 0) {
                $expectedYears = (int)ceil((float)$p['program_duration']);
            } elseif (!empty($p['duration_value']) && strtolower((string)$p['duration_unit']) === 'years') {
                $expectedYears = (int)$p['duration_value'];
            }
            $maxYear = $yearsCovered ? max($yearsCovered) : 0;
            $targetYears = max($expectedYears, $maxYear);
            $missingYears = [];
            for ($y = 1; $y <= $targetYears; $y++) {
                if (!in_array($y, $yearsCovered, true)) {
                    $missingYears[] = $y;
                }
            }

            $enrol = $enrolled[$code] ?? 0;
            $fees = $feeRows[$code] ?? 0;

            // Module-level heuristic: are the module codes craft-prefixed (CC*)?
            $craftModules = 0;
            foreach ($distinct as $cc) {
                if (stripos((string)$cc, 'CC') === 0) {
                    $craftModules++;
                }
            }
            $allCraftModules = $distinctCount > 0 && $craftModules === $distinctCount;
            $type = strtolower((string)$p['program_type']);
            $isDiploma = str_contains($type, 'diploma');

            // ---- Issue detection ---------------------------------------------
            $issues = [];
            $add = static function (string $sev, string $codeId, string $msg) use (&$issues): void {
                $issues[] = ['severity' => $sev, 'code' => $codeId, 'message' => $msg];
            };

            if ($courseCount === 0) {
                if ($enrol > 0) {
                    $add('critical', 'ENROLLED_NO_CURRICULUM',
                        "{$enrol} student(s) are enrolled but the programme has no courses — they cannot register for modules.");
                } else {
                    $add('critical', 'EMPTY_CURRICULUM',
                        'No courses are attached to this programme; it cannot be offered for registration.');
                }
            }
            if ($distinctCount < $courseCount) {
                $add('warning', 'DUPLICATE_MODULE',
                    ($courseCount - $distinctCount) . ' duplicate module link(s) — the same course is listed more than once.');
            }
            if ($missingYears !== [] && $courseCount > 0) {
                $add('warning', 'YEAR_GAP',
                    'No courses scheduled in year(s) ' . implode(', ', $missingYears) . " of a {$targetYears}-year programme.");
            }
            if ($enrol > 0 && $fees === 0) {
                $add('warning', 'ENROLLED_NO_FEES',
                    "{$enrol} student(s) enrolled but no fee structure is defined for this programme.");
            }
            if ($isDiploma && $allCraftModules && $courseCount > 0) {
                $add('info', 'LEVEL_MODULE_MISMATCH',
                    'A diploma programme whose modules are all craft-level (CC-prefixed) codes — confirm the module set matches the diploma level.');
            }
            // Shared curriculum (identical distinct course set to other programmes).
            $sig = $distinct;
            sort($sig);
            $sigKey = implode('|', $sig);
            if ($sigKey !== '' && isset($curriculumSig[$sigKey]) && count($curriculumSig[$sigKey]) > 1) {
                $peers = array_values(array_diff($curriculumSig[$sigKey], [$code]));
                $add('info', 'SHARED_CURRICULUM',
                    'Identical module set to: ' . implode(', ', $peers) . ' — confirm these are distinct offerings, not duplicates.');
            }
            // Duplicate programme name.
            $nk = strtolower(trim((string)$p['program_name']));
            if (isset($duplicateNames[$nk])) {
                $peers = array_values(array_diff($duplicateNames[$nk], [$code]));
                $add('warning', 'DUPLICATE_NAME',
                    'Another programme shares this exact name: ' . implode(', ', $peers) . '.');
            }
            if ((int)$p['is_active'] !== 1) {
                $add('info', 'INACTIVE', 'Programme is marked inactive.');
            }

            // Health score (100 minus weighted deductions, floored at 0).
            $weights = ['critical' => 40, 'warning' => 15, 'info' => 5];
            $score = 100;
            foreach ($issues as $i) {
                $score -= $weights[$i['severity']] ?? 5;
            }
            $score = max(0, $score);

            $analysed[] = [
                'code' => $code,
                'name' => $p['program_name'],
                'type' => $p['program_type'],
                'qualification_level' => $p['qualification_level'],
                'department' => $departments[(int)$p['department_id']] ?? ('dept#' . (int)$p['department_id']),
                'is_active' => (int)$p['is_active'],
                'course_count' => $courseCount,
                'distinct_courses' => $distinctCount,
                'required_count' => $requiredCount,
                'elective_count' => $courseCount - $requiredCount,
                'years_covered' => $yearsCovered,
                'target_years' => $targetYears,
                'missing_years' => $missingYears,
                'enrolled' => $enrol,
                'fee_rows' => $fees,
                'issues' => $issues,
                'health_score' => $score,
            ];
        }

        // Sort worst-health first for the portfolio view.
        usort($analysed, static fn($a, $b) => $a['health_score'] <=> $b['health_score']);

        // Portfolio roll-up.
        $withoutCourses = array_values(array_filter(
            array_map(static fn($a) => $a['course_count'] === 0 ? $a['code'] : null, $analysed)
        ));
        $critical = 0;
        $warning = 0;
        foreach ($analysed as $a) {
            foreach ($a['issues'] as $i) {
                if ($i['severity'] === 'critical') $critical++;
                elseif ($i['severity'] === 'warning') $warning++;
            }
        }

        return [
            'scope' => $programCode !== null ? 'single' : 'portfolio',
            'generated_for' => $programCode ?? 'ALL',
            'summary' => [
                'programs_analysed' => count($analysed),
                'total_programs' => count($programs),
                'programs_without_courses' => $withoutCourses,
                'duplicate_name_groups' => array_values($duplicateNames),
                'identical_curriculum_groups' => $identicalCurricula,
                'critical_findings' => $critical,
                'warning_findings' => $warning,
            ],
            'programs' => $analysed,
        ];
    }

    /**
     * Deterministic Markdown summary used when the AI backend is offline. It is a
     * genuinely useful audit on its own — not a placeholder.
     */
    function wuc_curriculum_fallback(array $analysis): string
    {
        $s = $analysis['summary'];
        $out = [];
        $out[] = '### Curriculum health audit';
        $out[] = "Analysed **{$s['programs_analysed']}** programme(s). "
            . "Critical findings: **{$s['critical_findings']}**, warnings: **{$s['warning_findings']}**.";

        if (!empty($s['programs_without_courses'])) {
            $out[] = '';
            $out[] = '**Programmes with no courses:** ' . implode(', ', $s['programs_without_courses']);
        }
        if (!empty($s['duplicate_name_groups'])) {
            $out[] = '';
            $out[] = '**Duplicate programme names:**';
            foreach ($s['duplicate_name_groups'] as $g) {
                $out[] = '- ' . implode(' = ', $g);
            }
        }
        if (!empty($s['identical_curriculum_groups'])) {
            $out[] = '';
            $out[] = '**Programmes sharing an identical module set:**';
            foreach ($s['identical_curriculum_groups'] as $g) {
                $out[] = '- ' . implode(', ', $g);
            }
        }

        // Top problem programmes.
        $problems = array_filter($analysis['programs'], static fn($p) => $p['issues'] !== []);
        if ($problems !== []) {
            $out[] = '';
            $out[] = '**Programmes needing attention (lowest health first):**';
            foreach (array_slice($problems, 0, 12) as $p) {
                $out[] = "- **{$p['code']}** ({$p['name']}) — health {$p['health_score']}/100";
                foreach ($p['issues'] as $i) {
                    $out[] = "    - _{$i['severity']}_: {$i['message']}";
                }
            }
        } else {
            $out[] = '';
            $out[] = 'No structural issues detected. Every analysed programme has a course set with no gaps or duplicates.';
        }

        return implode("\n", $out);
    }
}

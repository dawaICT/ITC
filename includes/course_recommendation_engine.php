<?php
declare(strict_types=1);

/**
 * Rule-based course recommendation engine (zero-cost AI, Sprint 6).
 *
 * Pure curriculum logic, fully explainable — no LLM anywhere:
 *   student_program      → the student's programme
 *   program_courses      → curriculum (course per year/semester, is_required)
 *   course_registration  → what the student already registered (Sid!)
 *   semester_assessment  → failed attempts (Total_CA scaled, latest per course)
 *   students.year        → current year of study
 *
 * Recommendation types:
 *   retake  — a registered course whose latest result is below the pass mark
 *   missing — a curriculum course for the student's current/earlier years
 *             that has never been registered
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_crec_scaled_mark')) {
    function wuc_crec_scaled_mark($totalCa): ?float
    {
        if (!is_numeric($totalCa)) {
            return null;
        }
        $totalCa = (float)$totalCa;
        $scale = $totalCa <= 40 ? 40.0 : 100.0;
        return round(min(100, ($totalCa / $scale) * 100), 2);
    }
}

if (!function_exists('wuc_course_recommendations')) {
    /**
     * Returns ['program_code', 'year_of_study', 'retake' => [...], 'missing' => [...],
     * 'notes' => [...]]. Each recommendation: course_code, course_name, year,
     * semester, is_required, reason.
     */
    function wuc_course_recommendations(mysqli $db, string $studentId): array
    {
        $out = [
            'program_code' => '',
            'year_of_study' => 1,
            'period_label' => 'Semester',
            'retake' => [],
            'missing' => [],
            'notes' => [],
        ];

        $studentId = trim($studentId);
        if ($studentId === '' || !wuc_table_exists($db, 'student_program') || !wuc_table_exists($db, 'program_courses')) {
            $out['notes'][] = 'Curriculum tables are unavailable, so no recommendations can be produced.';
            return $out;
        }

        // Programme + current year of study.
        if ($stmt = $db->prepare('SELECT program_code FROM student_program WHERE Sid = ? ORDER BY id DESC LIMIT 1')) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $out['program_code'] = trim((string)($row['program_code'] ?? ''));
        }
        if ($out['program_code'] === '') {
            $out['notes'][] = 'No programme assignment found for this student.';
            return $out;
        }

        // Term-based programmes (ITC certificates/diplomas run 3 terms per year)
        // store the term number in program_courses.semester — label it "Term" so
        // the guidance matches how the student's programme is actually organised.
        try {
            if (wuc_table_exists($db, 'programs')
                && ($stmt = $db->prepare('SELECT COALESCE(period_mode, "") AS period_mode, COALESCE(uses_terms, 0) AS uses_terms
                                          FROM programs WHERE program_code = ? LIMIT 1'))) {
                $stmt->bind_param('s', $out['program_code']);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                if (strtolower(trim((string)($row['period_mode'] ?? ''))) === 'term'
                    || (int)($row['uses_terms'] ?? 0) === 1) {
                    $out['period_label'] = 'Term';
                }
            }
        } catch (Throwable $e) {
            // Installs without the period columns keep the "Semester" default.
        }
        if (wuc_table_exists($db, 'students') && ($stmt = $db->prepare('SELECT COALESCE(year, 1) AS year FROM students WHERE SID = ? LIMIT 1'))) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $out['year_of_study'] = max(1, (int)($row['year'] ?? 1));
        }

        // Curriculum for the programme (academic-year ownership; period is metadata only).
        $curriculum = [];
        require_once __DIR__ . '/helpers/academic_period_helpers.php';
        for ($curYear = 1; $curYear <= $out['year_of_study']; $curYear++) {
            foreach (getCoursesForProgramYearOfStudy($db, $out['program_code'], $curYear) as $row) {
                $code = trim((string)($row['course_code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $curriculum[$code] = [
                    'course_code' => $code,
                    'course_name' => (string)($row['course_name'] ?? ''),
                    'year' => $curYear,
                    'semester' => (int)($row['term_number'] ?? $row['semester_number'] ?? $row['period_number'] ?? 1),
                    'is_required' => !empty($row['is_core']) || (int)($row['is_required'] ?? 1) === 1,
                ];
            }
        }

        if (!$curriculum) {
            $hasCourses = wuc_table_exists($db, 'courses');
            $catalogCodeCol = 'course_code';
            $catalogNameCol = 'course_name';
            if ($hasCourses) {
                require_once __DIR__ . '/../students/includes/RegistrationDataService.php';
                $regDataSvc = new RegistrationDataService($db);
                $catalog = $regDataSvc->getCourseCatalogColumnMap();
                $catalogCodeCol = $catalog['course_code'];
                $catalogNameCol = $catalog['course_name'] ?? 'course_name';
            }
            $nameSelect = $catalogNameCol
                ? "COALESCE(c.`{$catalogNameCol}`, '') AS course_name"
                : "'' AS course_name";
            $joinSql = $hasCourses
                ? "LEFT JOIN courses c ON TRIM(UPPER(c.`{$catalogCodeCol}`)) = TRIM(UPPER(pc.course_code))"
                : '';
            $sql = $hasCourses
                ? "SELECT pc.course_code, pc.year, pc.semester, pc.is_required, {$nameSelect}
                   FROM program_courses pc {$joinSql}
                   WHERE pc.program_code = ? AND pc.year <= ? ORDER BY pc.year, pc.semester, pc.course_code"
                : 'SELECT course_code, year, semester, is_required, "" AS course_name
                   FROM program_courses WHERE program_code = ? AND year <= ? ORDER BY year, semester, course_code';
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('si', $out['program_code'], $out['year_of_study']);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $code = trim((string)($row['course_code'] ?? ''));
                    if ($code !== '') {
                        $curriculum[$code] = $row;
                    }
                }
                $stmt->close();
            }
            if ($hasCourses && !empty($curriculum)) {
                $regDataSvc = $regDataSvc ?? new RegistrationDataService($db);
                $catalogNames = $regDataSvc->lookupCourseCatalogNames(array_keys($curriculum));
                foreach ($catalogNames as $code => $name) {
                    if ($name !== '') {
                        $curriculum[$code]['course_name'] = $name;
                    }
                }
            }
        }
        if (!$curriculum) {
            $out['notes'][] = 'No curriculum courses are defined for programme ' . $out['program_code'] . '.';
            return $out;
        }

        // Everything the student ever registered.
        $registered = [];
        if (wuc_table_exists($db, 'course_registration') && ($stmt = $db->prepare('SELECT DISTINCT course_code FROM course_registration WHERE Sid = ?'))) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $registered[trim((string)($row['course_code'] ?? ''))] = true;
            }
            $stmt->close();
        }

        // Latest result per course (for retake detection).
        $latestMark = [];
        if (wuc_table_exists($db, 'semester_assessment') && ($stmt = $db->prepare('SELECT Course_Code, Total_CA FROM semester_assessment WHERE Sid = ? ORDER BY id ASC'))) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $code = trim((string)($row['Course_Code'] ?? ''));
                $mark = wuc_crec_scaled_mark($row['Total_CA']);
                if ($code !== '' && $mark !== null) {
                    $latestMark[$code] = $mark; // ascending order → last write wins = latest attempt
                }
            }
            $stmt->close();
        }

        foreach ($curriculum as $code => $course) {
            $courseYear = max(1, (int)($course['year'] ?? 1));
            $item = [
                'course_code' => $code,
                'course_name' => (string)($course['course_name'] ?? ''),
                'year' => $courseYear,
                'semester' => (int)($course['semester'] ?? 1),
                'is_required' => (int)($course['is_required'] ?? 1) === 1,
            ];

            if (isset($registered[$code])) {
                if (isset($latestMark[$code]) && $latestMark[$code] < 50) {
                    $item['reason'] = 'Latest result is ' . $latestMark[$code] . '% — below the 50% pass mark. A retake is advised.';
                    $out['retake'][] = $item;
                }
                continue;
            }

            // Only surface missing courses up to the student's current year.
            if ($courseYear <= $out['year_of_study']) {
                $item['reason'] = ($item['is_required'] ? 'Required' : 'Elective')
                    . ' Year ' . $courseYear . ' / ' . $out['period_label'] . ' ' . $item['semester']
                    . ' course in your ' . $out['program_code'] . ' curriculum that you have not registered yet.';
                $out['missing'][] = $item;
            }
        }

        // Required courses first, then by year/semester.
        $sorter = static function (array $a, array $b): int {
            return [$b['is_required'], -$a['year'], -$a['semester']] <=> [$a['is_required'], -$b['year'], -$b['semester']];
        };
        usort($out['missing'], $sorter);
        usort($out['retake'], $sorter);

        if (!$out['missing'] && !$out['retake']) {
            $out['notes'][] = 'Your registrations match the curriculum for your current year — nothing outstanding was detected.';
        }

        return $out;
    }
}

if (!function_exists('wuc_course_recommendations_persist')) {
    /** Store active recommendations in ai_recommendations (deduped per course, 30-day window). */
    function wuc_course_recommendations_persist(mysqli $db, string $studentId, array $recs): int
    {
        if (!wuc_table_exists($db, 'ai_recommendations')) {
            return 0;
        }
        $created = 0;
        foreach (['retake', 'missing'] as $type) {
            foreach ($recs[$type] ?? [] as $item) {
                $code = (string)$item['course_code'];
                try {
                    $exists = 0;
                    if ($stmt = $db->prepare("SELECT COUNT(*) AS total FROM ai_recommendations
                                              WHERE target_user_id = ? AND recommendation_type = ? AND entity_id = ?
                                                AND status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")) {
                        $recType = 'course_' . $type;
                        $stmt->bind_param('sss', $studentId, $recType, $code);
                        $stmt->execute();
                        $row = $stmt->get_result()->fetch_assoc() ?: [];
                        $stmt->close();
                        $exists = (int)($row['total'] ?? 0);
                    }
                    if ($exists > 0) {
                        continue;
                    }
                    $reasonsJson = json_encode([$item['reason']], JSON_UNESCAPED_UNICODE) ?: '[]';
                    if ($stmt = $db->prepare("INSERT INTO ai_recommendations
                        (target_user_id, target_role, recommendation_type, entity_type, entity_id, reasons_json)
                        VALUES (?, 'student', ?, 'course', ?, ?)")) {
                        $recType = 'course_' . $type;
                        $stmt->bind_param('ssss', $studentId, $recType, $code, $reasonsJson);
                        if ($stmt->execute()) {
                            $created++;
                        }
                        $stmt->close();
                    }
                } catch (Throwable $e) {
                    error_log('wuc_course_recommendations_persist failed for ' . $code . ': ' . $e->getMessage());
                }
            }
        }
        return $created;
    }
}

if (!function_exists('wuc_course_recommendations_render_card')) {
    function wuc_course_recommendations_render_card(array $recs, bool $compact = false): string
    {
        $retake = $recs['retake'] ?? [];
        $missing = $recs['missing'] ?? [];
        if (!$retake && !$missing && $compact) {
            return '';
        }

        $renderList = static function (array $items, string $badgeClass, string $badgeText) use ($compact): string {
            $html = '<ul class="list-unstyled mb-0 small">';
            foreach (array_slice($items, 0, $compact ? 4 : 12) as $item) {
                $html .= '<li class="mb-2"><span class="badge bg-' . $badgeClass . '">' . htmlspecialchars($badgeText) . '</span> '
                    . '<strong>' . htmlspecialchars((string)$item['course_code']) . '</strong>'
                    . ($item['course_name'] !== '' ? ' — ' . htmlspecialchars((string)$item['course_name']) : '')
                    . '<br><span class="text-muted">' . htmlspecialchars((string)$item['reason']) . '</span></li>';
            }
            $html .= '</ul>';
            return $html;
        };

        ob_start();
        ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="fas fa-route me-2"></i>Course Recommendations</h5>
                <span class="badge bg-primary"><?= htmlspecialchars((string)($recs['program_code'] ?? '')) ?></span>
            </div>
            <div class="card-body">
                <?php if ($retake): ?>
                    <div class="fw-semibold small mb-2 text-danger"><i class="fas fa-rotate-left me-1"></i>Suggested retakes</div>
                    <?= $renderList($retake, 'danger', 'Retake') ?>
                    <?php if ($missing): ?><hr><?php endif; ?>
                <?php endif; ?>
                <?php if ($missing): ?>
                    <div class="fw-semibold small mb-2"><i class="fas fa-plus-circle me-1"></i>Curriculum courses not yet registered</div>
                    <?= $renderList($missing, 'warning text-dark', 'Missing') ?>
                <?php endif; ?>
                <?php if (!$retake && !$missing): ?>
                    <div class="text-muted small"><i class="fas fa-circle-check me-1"></i>
                        <?= htmlspecialchars(implode(' ', $recs['notes'] ?? []) ?: 'No outstanding curriculum items detected.') ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted small mt-3">
                        Rule-based guidance from your programme curriculum — confirm choices with your registrar or advisor before registering.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

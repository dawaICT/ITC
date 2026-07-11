<?php
declare(strict_types=1);

/**
 * Section/department-scoped report insights for HOD/HOS pages (Sprint 4).
 *
 * Template-driven, explainable rules over live tables — no LLM required:
 *   departments → programs → student_program → semester_assessment
 *   course_lecturer for lecturer workload, course_registration for CA coverage,
 *   student_risk_summary (latest per student) for progression issues.
 *
 * Scope comes from the caller (hos_section_helpers / department_assignments),
 * so RBAC decisions stay where they already live.
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_hos_scaled_ca')) {
    function wuc_hos_scaled_ca($totalCa): ?float
    {
        if (!is_numeric($totalCa)) {
            return null;
        }
        $totalCa = (float)$totalCa;
        $scale = $totalCa <= 40 ? 40.0 : 100.0;
        return round(min(100, ($totalCa / $scale) * 100), 2);
    }
}

if (!function_exists('wuc_hos_scope_programs')) {
    /** Program codes owned by the scoped departments. */
    function wuc_hos_scope_programs(mysqli $db, array $departmentIds): array
    {
        $departmentIds = array_values(array_filter(array_map('intval', $departmentIds), static fn($v) => $v > 0));
        if (!$departmentIds || !wuc_table_exists($db, 'programs')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
        $types = str_repeat('i', count($departmentIds));
        $programs = [];
        if ($stmt = $db->prepare("SELECT program_code FROM programs WHERE department_id IN ($placeholders)")) {
            $stmt->bind_param($types, ...$departmentIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $code = trim((string)($row['program_code'] ?? ''));
                if ($code !== '') {
                    $programs[$code] = true;
                }
            }
            $stmt->close();
        }
        return array_keys($programs);
    }
}

if (!function_exists('wuc_hos_report_insights')) {
    /**
     * Build the full insight set for a department scope.
     * Returns program_performance, failure_hotspots, lecturer_workload,
     * missing_ca_courses, progression, warnings, actions.
     */
    function wuc_hos_report_insights(mysqli $db, array $departmentIds): array
    {
        $insights = [
            'programs_in_scope' => 0,
            'program_performance' => [],
            'failure_hotspots' => [],
            'lecturer_workload' => [],
            'missing_ca_courses' => [],
            'progression' => ['high' => 0, 'medium' => 0, 'low' => 0],
            'warnings' => [],
            'actions' => [],
        ];

        $programs = wuc_hos_scope_programs($db, $departmentIds);
        $insights['programs_in_scope'] = count($programs);
        if (!$programs) {
            $insights['warnings'][] = 'No programmes are linked to this scope, so programme-level insights are unavailable.';
            return $insights;
        }

        $pPh = implode(',', array_fill(0, count($programs), '?'));
        $pTypes = str_repeat('s', count($programs));

        // --- Programme performance: avg scaled CA + fail rate per programme ---
        if (wuc_table_exists($db, 'student_program') && wuc_table_exists($db, 'semester_assessment')) {
            $sql = "SELECT sp.program_code, sa.Total_CA
                    FROM student_program sp
                    INNER JOIN semester_assessment sa ON sa.Sid = sp.Sid
                    WHERE sp.program_code IN ($pPh)";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($pTypes, ...$programs);
                $stmt->execute();
                $res = $stmt->get_result();
                $byProgram = [];
                while ($row = $res->fetch_assoc()) {
                    $mark = wuc_hos_scaled_ca($row['Total_CA']);
                    if ($mark === null) {
                        continue;
                    }
                    $code = (string)$row['program_code'];
                    $byProgram[$code]['marks'][] = $mark;
                }
                $stmt->close();
                foreach ($byProgram as $code => $data) {
                    $marks = $data['marks'];
                    $fails = count(array_filter($marks, static fn($m) => $m < 50));
                    $insights['program_performance'][] = [
                        'program_code' => $code,
                        'records' => count($marks),
                        'average' => round(array_sum($marks) / count($marks), 2),
                        'fail_rate' => round(($fails / count($marks)) * 100, 1),
                    ];
                }
                usort($insights['program_performance'], static fn($a, $b) => $a['average'] <=> $b['average']);
            }
        }

        // --- Course failure hotspots (≥3 records, fail rate ≥ 40%) ---
        if (wuc_table_exists($db, 'program_courses') && wuc_table_exists($db, 'semester_assessment')) {
            $sql = "SELECT sa.Course_Code, sa.Total_CA
                    FROM semester_assessment sa
                    INNER JOIN (SELECT DISTINCT course_code FROM program_courses WHERE program_code IN ($pPh)) pc
                        ON pc.course_code = sa.Course_Code";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($pTypes, ...$programs);
                $stmt->execute();
                $res = $stmt->get_result();
                $byCourse = [];
                while ($row = $res->fetch_assoc()) {
                    $mark = wuc_hos_scaled_ca($row['Total_CA']);
                    if ($mark === null) {
                        continue;
                    }
                    $byCourse[(string)$row['Course_Code']][] = $mark;
                }
                $stmt->close();
                foreach ($byCourse as $code => $marks) {
                    if (count($marks) < 3) {
                        continue;
                    }
                    $fails = count(array_filter($marks, static fn($m) => $m < 50));
                    $failRate = round(($fails / count($marks)) * 100, 1);
                    if ($failRate >= 40) {
                        $insights['failure_hotspots'][] = [
                            'course_code' => $code,
                            'records' => count($marks),
                            'fail_rate' => $failRate,
                            'average' => round(array_sum($marks) / count($marks), 2),
                        ];
                    }
                }
                usort($insights['failure_hotspots'], static fn($a, $b) => $b['fail_rate'] <=> $a['fail_rate']);
                $insights['failure_hotspots'] = array_slice($insights['failure_hotspots'], 0, 8);
            }
        }

        // --- Lecturer workload (courses + learners per lecturer in scope) ---
        if (wuc_table_exists($db, 'course_lecturer') && wuc_table_exists($db, 'course_registration')) {
            $sql = "SELECT cl.staff_id,
                           CONCAT(COALESCE(st.Fname, ''), ' ', COALESCE(st.Lname, '')) AS lecturer_name,
                           COUNT(DISTINCT cl.course_code) AS courses,
                           COUNT(DISTINCT cr.Sid) AS learners
                    FROM course_lecturer cl
                    LEFT JOIN staff st ON st.staff_id = cl.staff_id
                    LEFT JOIN course_registration cr ON cr.course_code = cl.course_code AND COALESCE(cr.is_active, 1) = 1
                    WHERE COALESCE(cl.status, 'active') = 'active'
                      AND (cl.program_code IN ($pPh)
                           OR cl.course_code IN (SELECT DISTINCT course_code FROM program_courses WHERE program_code IN ($pPh)))
                    GROUP BY cl.staff_id, lecturer_name
                    ORDER BY courses DESC, learners DESC
                    LIMIT 15";
            if ($stmt = $db->prepare($sql)) {
                $params = array_merge($programs, $programs);
                $stmt->bind_param($pTypes . $pTypes, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $insights['lecturer_workload'][] = [
                        'staff_id' => (string)$row['staff_id'],
                        'name' => trim((string)($row['lecturer_name'] ?? '')) ?: (string)$row['staff_id'],
                        'courses' => (int)$row['courses'],
                        'learners' => (int)$row['learners'],
                    ];
                }
                $stmt->close();
            }
        }

        // --- Missing CA uploads: scoped courses with registered learners but no marks ---
        if (wuc_table_exists($db, 'program_courses') && wuc_table_exists($db, 'course_registration')) {
            $hasAssessment = wuc_table_exists($db, 'semester_assessment');
            $sql = "SELECT pc.course_code,
                           COUNT(DISTINCT cr.Sid) AS registered"
                . ($hasAssessment ? ", (SELECT COUNT(DISTINCT sa.Sid) FROM semester_assessment sa WHERE sa.Course_Code = pc.course_code) AS with_ca" : ", 0 AS with_ca")
                . " FROM (SELECT DISTINCT course_code FROM program_courses WHERE program_code IN ($pPh)) pc
                    INNER JOIN course_registration cr ON cr.course_code = pc.course_code AND COALESCE(cr.is_active, 1) = 1
                    GROUP BY pc.course_code
                    HAVING COUNT(DISTINCT cr.Sid) > 0 AND with_ca < COUNT(DISTINCT cr.Sid)
                    ORDER BY (COUNT(DISTINCT cr.Sid) - with_ca) DESC
                    LIMIT 10";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($pTypes, ...$programs);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $insights['missing_ca_courses'][] = [
                        'course_code' => (string)$row['course_code'],
                        'registered' => (int)$row['registered'],
                        'with_ca' => (int)$row['with_ca'],
                        'missing' => max(0, (int)$row['registered'] - (int)$row['with_ca']),
                    ];
                }
                $stmt->close();
            }
        }

        // --- Progression issues from the latest risk snapshot per student ---
        if (wuc_table_exists($db, 'student_risk_summary') && wuc_table_exists($db, 'student_program')) {
            $sql = "SELECT r.risk_level, COUNT(*) AS total
                    FROM student_risk_summary r
                    INNER JOIN (SELECT student_id, MAX(id) AS max_id FROM student_risk_summary GROUP BY student_id) latest
                        ON latest.max_id = r.id
                    INNER JOIN student_program sp ON sp.Sid = r.student_id
                    WHERE sp.program_code IN ($pPh)
                    GROUP BY r.risk_level";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($pTypes, ...$programs);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $key = strtolower((string)($row['risk_level'] ?? ''));
                    if (isset($insights['progression'][$key])) {
                        $insights['progression'][$key] = (int)($row['total'] ?? 0);
                    }
                }
                $stmt->close();
            }
        }

        // --- Template warnings and recommended actions ---
        if ($insights['failure_hotspots']) {
            $top = $insights['failure_hotspots'][0];
            $insights['warnings'][] = 'Course ' . $top['course_code'] . ' has a ' . $top['fail_rate'] . '% fail rate across ' . $top['records'] . ' records.';
            $insights['actions'][] = 'Review teaching and assessment for the flagged failure-hotspot courses; consider moderation or remedial classes.';
        }
        if ($insights['missing_ca_courses']) {
            $totalMissing = array_sum(array_column($insights['missing_ca_courses'], 'missing'));
            $insights['warnings'][] = $totalMissing . ' registered learner-course record(s) have no CA marks uploaded.';
            $insights['actions'][] = 'Follow up CA uploads with the responsible lecturers before the submission deadline.';
        }
        if ($insights['progression']['high'] > 0) {
            $insights['warnings'][] = $insights['progression']['high'] . ' learner(s) in scope are currently scored High academic risk.';
            $insights['actions'][] = 'Prioritise intervention for High-risk learners (see the risk watchlist), then monitor Medium-risk weekly.';
        }
        if (!$insights['warnings']) {
            $insights['warnings'][] = 'No rule-based warnings triggered for this scope with the available data.';
        }

        return $insights;
    }
}

if (!function_exists('wuc_hos_persist_report_insights')) {
    /** Persist the structured block to report_insights for audit/history. */
    function wuc_hos_persist_report_insights(mysqli $db, string $reportKey, string $scopeType, string $scopeId, array $insights, string $generatedBy): void
    {
        if (!wuc_table_exists($db, 'report_insights')) {
            return;
        }
        try {
            $summaryJson = json_encode([
                'programs_in_scope' => $insights['programs_in_scope'],
                'program_performance' => $insights['program_performance'],
                'lecturer_workload' => $insights['lecturer_workload'],
                'progression' => $insights['progression'],
            ], JSON_UNESCAPED_UNICODE) ?: '{}';
            $warningsJson = json_encode($insights['warnings'], JSON_UNESCAPED_UNICODE) ?: null;
            $trendsJson = json_encode($insights['failure_hotspots'], JSON_UNESCAPED_UNICODE) ?: null;
            $actionsJson = json_encode($insights['actions'], JSON_UNESCAPED_UNICODE) ?: null;

            $stmt = $db->prepare(
                'INSERT INTO report_insights (report_key, scope_type, scope_id, summary_json, warnings_json, trends_json, actions_json, generated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt) {
                $stmt->bind_param('ssssssss', $reportKey, $scopeType, $scopeId, $summaryJson, $warningsJson, $trendsJson, $actionsJson, $generatedBy);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('wuc_hos_persist_report_insights failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_hos_render_report_insights')) {
    function wuc_hos_render_report_insights(array $insights, string $scopeLabel = ''): string
    {
        ob_start();
        ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="fas fa-lightbulb me-2"></i>Section Insights (Rule-Based)</h5>
                <?php if ($scopeLabel !== ''): ?><span class="badge bg-primary"><?= htmlspecialchars($scopeLabel) ?></span><?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="p-3 rounded-3 bg-primary bg-opacity-10">
                            <div class="text-muted small">Programmes in scope</div>
                            <div class="fs-4 fw-bold text-primary"><?= (int)$insights['programs_in_scope'] ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 rounded-3 bg-danger bg-opacity-10">
                            <div class="text-muted small">High-risk learners</div>
                            <div class="fs-4 fw-bold text-danger"><?= (int)$insights['progression']['high'] ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 rounded-3 bg-warning bg-opacity-10">
                            <div class="text-muted small">Failure hotspots</div>
                            <div class="fs-4 fw-bold text-warning"><?= count($insights['failure_hotspots']) ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 rounded-3 bg-info bg-opacity-10">
                            <div class="text-muted small">Courses missing CA</div>
                            <div class="fs-4 fw-bold text-info"><?= count($insights['missing_ca_courses']) ?></div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <?php if ($insights['program_performance']): ?>
                    <div class="col-lg-6">
                        <div class="fw-semibold small mb-2">Programme performance (lowest first)</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Programme</th><th>Records</th><th>Average</th><th>Fail rate</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($insights['program_performance'], 0, 6) as $p): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars((string)$p['program_code']) ?></strong></td>
                                        <td><?= (int)$p['records'] ?></td>
                                        <td class="<?= $p['average'] < 50 ? 'text-danger fw-semibold' : '' ?>"><?= htmlspecialchars((string)$p['average']) ?>%</td>
                                        <td><?= htmlspecialchars((string)$p['fail_rate']) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($insights['lecturer_workload']): ?>
                    <div class="col-lg-6">
                        <div class="fw-semibold small mb-2">Lecturer workload</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Lecturer</th><th>Courses</th><th>Learners</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($insights['lecturer_workload'], 0, 6) as $l): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars((string)$l['name']) ?></strong> <small class="text-muted"><?= htmlspecialchars((string)$l['staff_id']) ?></small></td>
                                        <td><?= (int)$l['courses'] ?></td>
                                        <td><?= (int)$l['learners'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($insights['failure_hotspots']): ?>
                    <div class="col-lg-6">
                        <div class="fw-semibold small mb-2">Course failure hotspots</div>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach ($insights['failure_hotspots'] as $h): ?>
                                <li class="mb-1"><span class="badge bg-danger"><?= htmlspecialchars((string)$h['fail_rate']) ?>%</span>
                                    <strong><?= htmlspecialchars((string)$h['course_code']) ?></strong>
                                    — avg <?= htmlspecialchars((string)$h['average']) ?>% over <?= (int)$h['records'] ?> records</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if ($insights['missing_ca_courses']): ?>
                    <div class="col-lg-6">
                        <div class="fw-semibold small mb-2">CA upload compliance</div>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach ($insights['missing_ca_courses'] as $m): ?>
                                <li class="mb-1"><span class="badge bg-warning text-dark"><?= (int)$m['missing'] ?> missing</span>
                                    <strong><?= htmlspecialchars((string)$m['course_code']) ?></strong>
                                    — <?= (int)$m['with_ca'] ?>/<?= (int)$m['registered'] ?> learners have marks</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>

                <hr>
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="fw-semibold small mb-2 text-warning"><i class="fas fa-triangle-exclamation me-1"></i>Warnings</div>
                        <ul class="mb-0 ps-3 small">
                            <?php foreach ($insights['warnings'] as $w): ?><li><?= htmlspecialchars((string)$w) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ($insights['actions']): ?>
                    <div class="col-lg-6">
                        <div class="fw-semibold small mb-2 text-success"><i class="fas fa-list-check me-1"></i>Recommended actions</div>
                        <ul class="mb-0 ps-3 small">
                            <?php foreach ($insights['actions'] as $a): ?><li><?= htmlspecialchars((string)$a) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

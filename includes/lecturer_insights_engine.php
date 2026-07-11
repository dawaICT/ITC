<?php
declare(strict_types=1);

/**
 * Deterministic lecturer dashboard insights (zero-cost AI, Sprint 3).
 *
 * Pure rules over live tables — no LLM involved:
 *   course_lecturer      → assigned courses
 *   course_registration  → registered learners per course (Sid, is_active)
 *   semester_assessment  → CA marks (Total_CA on a 40- or 100-point scale)
 *
 * Also feeds lecturer_task_alerts so missing CA uploads become actionable
 * dashboard tasks instead of silent gaps.
 */

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/portal_alerts.php';
require_once __DIR__ . '/helpers/lecturer_course_helpers.php';

if (!function_exists('wuc_lecturer_scaled_mark')) {
    /** Same Total_CA scaling convention as the academic risk engine. */
    function wuc_lecturer_scaled_mark($totalCa): ?float
    {
        if (!is_numeric($totalCa)) {
            return null;
        }
        $totalCa = (float)$totalCa;
        $scale = $totalCa <= 40 ? 40.0 : 100.0;
        return round(min(100, ($totalCa / $scale) * 100), 2);
    }
}

if (!function_exists('wuc_lecturer_insights')) {
    /**
     * Per-course deterministic stats for one lecturer.
     *
     * Returns ['courses' => [...], 'totals' => [...]] where each course row has:
     * course_code, registered, with_ca, missing_ca, class_average, highest,
     * lowest, pass_count, fail_count, is_weak.
     */
    function wuc_lecturer_insights(mysqli $db, string $staffId): array
    {
        $result = [
            'courses' => [],
            'totals' => [
                'assigned_courses' => 0,
                'registered_students' => 0,
                'missing_ca' => 0,
                'weak_courses' => 0,
            ],
        ];

        $staffId = trim($staffId);
        if ($staffId === '' || !wuc_table_exists($db, 'course_lecturer') || !wuc_table_exists($db, 'course_registration')) {
            return $result;
        }
        $hasAssessment = wuc_table_exists($db, 'semester_assessment');

        $courses = wuc_lecturer_resolved_course_codes($db, $staffId);
        $result['totals']['assigned_courses'] = count($courses);
        if (!$courses) {
            return $result;
        }

        $distinctRegistered = [];

        foreach ($courses as $code) {
            $row = [
                'course_code' => $code,
                'registered' => 0,
                'with_ca' => 0,
                'missing_ca' => 0,
                'class_average' => null,
                'highest' => null,
                'lowest' => null,
                'pass_count' => 0,
                'fail_count' => 0,
                'is_weak' => false,
            ];

            $registeredSids = [];
            if ($stmt = $db->prepare('SELECT DISTINCT Sid FROM course_registration WHERE course_code = ? AND COALESCE(is_active, 1) = 1')) {
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $sid = trim((string)($r['Sid'] ?? ''));
                    if ($sid !== '') {
                        $registeredSids[$sid] = true;
                        $distinctRegistered[$sid] = true;
                    }
                }
                $stmt->close();
            }
            $row['registered'] = count($registeredSids);

            if ($hasAssessment) {
                $marks = [];
                $withCa = [];
                if ($stmt = $db->prepare('SELECT Sid, Total_CA FROM semester_assessment WHERE Course_Code = ?')) {
                    $stmt->bind_param('s', $code);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) {
                        $sid = trim((string)($r['Sid'] ?? ''));
                        if ($sid !== '') {
                            $withCa[$sid] = true;
                        }
                        $mark = wuc_lecturer_scaled_mark($r['Total_CA']);
                        if ($mark !== null) {
                            $marks[] = $mark;
                        }
                    }
                    $stmt->close();
                }

                $row['with_ca'] = count(array_intersect_key($registeredSids, $withCa));
                if ($marks) {
                    $row['class_average'] = round(array_sum($marks) / count($marks), 2);
                    $row['highest'] = max($marks);
                    $row['lowest'] = min($marks);
                    foreach ($marks as $mark) {
                        if ($mark >= 50) {
                            $row['pass_count']++;
                        } else {
                            $row['fail_count']++;
                        }
                    }
                    $row['is_weak'] = $row['class_average'] < 50;
                }
            }

            $row['missing_ca'] = max(0, $row['registered'] - $row['with_ca']);

            $result['totals']['missing_ca'] += $row['missing_ca'];
            if ($row['is_weak']) {
                $result['totals']['weak_courses']++;
            }
            $result['courses'][] = $row;
        }

        $result['totals']['registered_students'] = count($distinctRegistered);

        return $result;
    }
}

if (!function_exists('wuc_lecturer_generate_task_alerts')) {
    /**
     * Turn missing-CA gaps into open lecturer_task_alerts rows (one per course,
     * skipped while an open alert for the same course/task already exists).
     * Returns the number of alerts created.
     */
    function wuc_lecturer_generate_task_alerts(mysqli $db, string $staffId, array $insights): int
    {
        $staffId = trim($staffId);
        if ($staffId === '' || !wuc_table_exists($db, 'lecturer_task_alerts')) {
            return 0;
        }

        $created = 0;
        foreach ($insights['courses'] ?? [] as $course) {
            $code = (string)($course['course_code'] ?? '');
            $missing = (int)($course['missing_ca'] ?? 0);
            if ($code === '' || $missing <= 0) {
                continue;
            }

            try {
                $exists = 0;
                if ($stmt = $db->prepare("SELECT COUNT(*) AS total FROM lecturer_task_alerts WHERE staff_id = ? AND task_type = 'missing_ca_upload' AND course_code = ? AND status = 'open'")) {
                    $stmt->bind_param('ss', $staffId, $code);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc() ?: [];
                    $stmt->close();
                    $exists = (int)($row['total'] ?? 0);
                }
                if ($exists > 0) {
                    continue;
                }

                $message = $missing . ' registered learner(s) in ' . $code . ' have no CA marks uploaded yet.';
                $severity = $missing >= 5 ? 'critical' : 'warning';
                if ($stmt = $db->prepare("INSERT INTO lecturer_task_alerts (staff_id, task_type, course_code, message, severity) VALUES (?, 'missing_ca_upload', ?, ?, ?)")) {
                    $stmt->bind_param('ssss', $staffId, $code, $message, $severity);
                    if ($stmt->execute()) {
                        $created++;
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                error_log('wuc_lecturer_generate_task_alerts failed for ' . $code . ': ' . $e->getMessage());
            }
        }

        if ($created > 0) {
            wuc_ai_decision_log($db, [
                'feature' => 'lecturer_insights_engine',
                'decision_type' => 'task_alerts_created',
                'entity_type' => 'staff',
                'entity_id' => $staffId,
                'outcome' => $created . ' missing-CA task alert(s) created',
            ]);
        }

        return $created;
    }
}

if (!function_exists('wuc_lecturer_open_task_alerts')) {
    function wuc_lecturer_open_task_alerts(mysqli $db, string $staffId, int $limit = 10): array
    {
        $staffId = trim($staffId);
        if ($staffId === '' || !wuc_table_exists($db, 'lecturer_task_alerts')) {
            return [];
        }
        $limit = max(1, min(25, $limit));
        try {
            $sql = "SELECT id, task_type, course_code, message, severity, created_at
                    FROM lecturer_task_alerts
                    WHERE staff_id = ? AND status = 'open'
                    ORDER BY FIELD(severity, 'critical', 'warning', 'info'), created_at DESC
                    LIMIT {$limit}";
            if (!$stmt = $db->prepare($sql)) {
                return [];
            }
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $res = $stmt->get_result();
            $alerts = [];
            while ($row = $res->fetch_assoc()) {
                $alerts[] = $row;
            }
            $stmt->close();
            return $alerts;
        } catch (Throwable $e) {
            error_log('wuc_lecturer_open_task_alerts failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('wuc_lecturer_insights_render_panel')) {
    function wuc_lecturer_insights_render_panel(array $insights, array $taskAlerts = []): string
    {
        $totals = $insights['totals'] ?? [];
        $courses = $insights['courses'] ?? [];
        if (!($totals['assigned_courses'] ?? 0)) {
            return '';
        }

        ob_start();
        ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="fas fa-chart-column me-2"></i>Class Insights (Rule-Based)</h5>
                <span class="badge bg-primary"><?= (int)$totals['assigned_courses'] ?> course(s)</span>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-primary bg-opacity-10">
                            <div class="text-muted small">Registered learners</div>
                            <div class="fs-4 fw-bold text-primary"><?= (int)$totals['registered_students'] ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-warning bg-opacity-10">
                            <div class="text-muted small">Learners missing CA</div>
                            <div class="fs-4 fw-bold text-warning"><?= (int)$totals['missing_ca'] ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-danger bg-opacity-10">
                            <div class="text-muted small">Weak courses (avg &lt; 50%)</div>
                            <div class="fs-4 fw-bold text-danger"><?= (int)$totals['weak_courses'] ?></div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Registered</th>
                                <th>Missing CA</th>
                                <th>Class avg</th>
                                <th>High / Low</th>
                                <th>Pass / Fail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $course): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$course['course_code']) ?></strong>
                                        <?php if (!empty($course['is_weak'])): ?>
                                            <span class="badge bg-danger ms-1">Weak</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)$course['registered'] ?></td>
                                    <td>
                                        <?php if ((int)$course['missing_ca'] > 0): ?>
                                            <span class="text-warning fw-semibold"><?= (int)$course['missing_ca'] ?></span>
                                        <?php else: ?>
                                            <span class="text-success">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $course['class_average'] !== null ? htmlspecialchars((string)$course['class_average']) . '%' : '<span class="text-muted">—</span>' ?></td>
                                    <td class="small">
                                        <?= $course['highest'] !== null ? htmlspecialchars((string)$course['highest']) . ' / ' . htmlspecialchars((string)$course['lowest']) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td class="small">
                                        <span class="text-success"><?= (int)$course['pass_count'] ?></span> /
                                        <span class="text-danger"><?= (int)$course['fail_count'] ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($taskAlerts): ?>
                    <div class="mt-3">
                        <div class="fw-semibold small mb-2"><i class="fas fa-list-check me-1"></i>Open teaching tasks</div>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach ($taskAlerts as $alert): ?>
                                <li class="mb-1">
                                    <span class="badge bg-<?= htmlspecialchars(wuc_portal_alert_severity_class((string)$alert['severity'])) ?>"><?= htmlspecialchars((string)$alert['course_code']) ?></span>
                                    <?= htmlspecialchars((string)$alert['message']) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

<?php
declare(strict_types=1);

/**
 * eLearning engagement insights (zero-cost AI, Sprint 10).
 *
 * Deterministic engagement scoring over live eLearning tables:
 *   el_course_modules/el_contents → published material per course
 *   el_analytics_events           → what a student has actually opened
 *   el_course_progress            → lesson completion percentage
 *   el_assignments/el_submissions → outstanding coursework
 *   semester_assessment           → weak courses → revision topics
 *
 * Student side: unread materials, incomplete lessons, pending assignments,
 * inactivity, CA-linked revision list, templated study checklist.
 * Lecturer side: per-course engagement summary with inactive learner counts.
 */

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/helpers/lecturer_course_helpers.php';

if (!function_exists('wuc_el_student_insights')) {
    function wuc_el_student_insights(mysqli $db, string $studentId): array
    {
        $out = [
            'courses' => [],
            'unread_materials' => 0,
            'incomplete_courses' => [],
            'pending_assignments' => 0,
            'days_inactive' => null,
            'revision_topics' => [],
            'checklist' => [],
        ];

        $studentId = trim($studentId);
        if ($studentId === '' || !wuc_table_exists($db, 'course_registration')) {
            return $out;
        }

        // Registered courses.
        $courses = [];
        if ($stmt = $db->prepare('SELECT DISTINCT course_code FROM course_registration WHERE Sid = ? AND COALESCE(is_active, 1) = 1')) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $code = trim((string)($row['course_code'] ?? ''));
                if ($code !== '') {
                    $courses[] = $code;
                }
            }
            $stmt->close();
        }
        $out['courses'] = $courses;
        if (!$courses) {
            $out['checklist'][] = 'Register your courses to unlock eLearning materials.';
            return $out;
        }
        $ph = implode(',', array_fill(0, count($courses), '?'));
        $types = str_repeat('s', count($courses));

        // Unread materials: published content in registered courses minus opened content.
        if (wuc_table_exists($db, 'el_course_modules') && wuc_table_exists($db, 'el_contents')) {
            $totalContent = 0;
            $sql = "SELECT COUNT(*) AS total
                    FROM el_contents c
                    INNER JOIN el_course_modules m ON m.id = c.module_id
                    WHERE m.course_code IN ($ph)
                      AND (m.release_at IS NULL OR m.release_at <= NOW())";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$courses);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                $totalContent = (int)($row['total'] ?? 0);
            }
            $viewed = 0;
            if ($totalContent > 0 && wuc_table_exists($db, 'el_analytics_events')) {
                if ($stmt = $db->prepare("SELECT COUNT(DISTINCT content_id) AS total FROM el_analytics_events
                                          WHERE actor_type = 'student' AND actor_id = ? AND content_id IS NOT NULL")) {
                    $stmt->bind_param('s', $studentId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc() ?: [];
                    $stmt->close();
                    $viewed = (int)($row['total'] ?? 0);
                }
            }
            $out['unread_materials'] = max(0, $totalContent - $viewed);
        }

        // Incomplete lessons.
        if (wuc_table_exists($db, 'el_course_progress')) {
            $sql = "SELECT course_code, progress_percent FROM el_course_progress
                    WHERE Sid = ? AND course_code IN ($ph) AND progress_percent < 100";
            if ($stmt = $db->prepare($sql)) {
                $params = array_merge([$studentId], $courses);
                $stmt->bind_param('s' . $types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $out['incomplete_courses'][] = [
                        'course_code' => (string)$row['course_code'],
                        'progress' => round((float)$row['progress_percent'], 1),
                    ];
                }
                $stmt->close();
            }
        }

        // Pending assignments (due, not submitted).
        if (wuc_table_exists($db, 'el_assignments') && wuc_table_exists($db, 'el_submissions')) {
            $sql = "SELECT COUNT(DISTINCT a.id) - COUNT(DISTINCT s.id) AS pending
                    FROM el_assignments a
                    LEFT JOIN el_submissions s ON s.assignment_id = a.id AND s.Sid = ?
                    WHERE a.course_code IN ($ph)";
            if ($stmt = $db->prepare($sql)) {
                $params = array_merge([$studentId], $courses);
                $stmt->bind_param('s' . $types, ...$params);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                $out['pending_assignments'] = max(0, (int)($row['pending'] ?? 0));
            }
        }

        // Inactivity.
        if (wuc_table_exists($db, 'el_analytics_events')) {
            if ($stmt = $db->prepare("SELECT MAX(created_at) AS last_at FROM el_analytics_events WHERE actor_type = 'student' AND actor_id = ?")) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                if (!empty($row['last_at'])) {
                    $out['days_inactive'] = (int)(new DateTime((string)$row['last_at']))->diff(new DateTime())->format('%a');
                }
            }
        }

        // Revision topics from weak CA (scaled < 50).
        if (wuc_table_exists($db, 'semester_assessment')) {
            $sql = "SELECT Course_Code, Total_CA FROM semester_assessment WHERE Sid = ? AND Total_CA IS NOT NULL";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $res = $stmt->get_result();
                $weak = [];
                while ($row = $res->fetch_assoc()) {
                    if (!is_numeric($row['Total_CA'])) {
                        continue;
                    }
                    $ca = (float)$row['Total_CA'];
                    $scale = $ca <= 40 ? 40.0 : 100.0;
                    if (($ca / $scale) * 100 < 50) {
                        $weak[trim((string)$row['Course_Code'])] = true;
                    }
                }
                $stmt->close();
                $out['revision_topics'] = array_keys($weak);
            }
        }

        // Templated study checklist.
        if ($out['unread_materials'] > 0) {
            $out['checklist'][] = 'Open the ' . $out['unread_materials'] . ' course material(s) you have not viewed yet.';
        }
        foreach (array_slice($out['incomplete_courses'], 0, 3) as $c) {
            $out['checklist'][] = 'Continue ' . $c['course_code'] . ' — currently at ' . $c['progress'] . '% progress.';
        }
        if ($out['pending_assignments'] > 0) {
            $out['checklist'][] = 'Submit your ' . $out['pending_assignments'] . ' outstanding assignment(s) before the deadline.';
        }
        foreach (array_slice($out['revision_topics'], 0, 3) as $topic) {
            $out['checklist'][] = 'Revise ' . $topic . ' — your last assessment was below the pass mark.';
        }
        if ($out['days_inactive'] !== null && $out['days_inactive'] > 7) {
            $out['checklist'][] = 'You have not used eLearning for ' . $out['days_inactive'] . ' day(s) — a short session today keeps you on track.';
        }
        if (!$out['checklist']) {
            $out['checklist'][] = 'You are up to date — keep the routine going with a regular revision slot.';
        }

        return $out;
    }
}

if (!function_exists('wuc_el_lecturer_engagement')) {
    /** Per-course engagement summary for a lecturer's assigned courses. */
    function wuc_el_lecturer_engagement(mysqli $db, string $staffId): array
    {
        $out = ['courses' => []];
        $staffId = trim($staffId);
        if ($staffId === '' || !wuc_table_exists($db, 'course_lecturer') || !wuc_table_exists($db, 'course_registration')) {
            return $out;
        }

        $courses = wuc_lecturer_resolved_course_codes($db, $staffId);

        $hasProgress = wuc_table_exists($db, 'el_course_progress');
        $hasEvents = wuc_table_exists($db, 'el_analytics_events');

        foreach ($courses as $code) {
            $row = ['course_code' => $code, 'enrolled' => 0, 'avg_progress' => null, 'inactive_learners' => 0];

            $sids = [];
            if ($stmt = $db->prepare('SELECT DISTINCT Sid FROM course_registration WHERE course_code = ? AND COALESCE(is_active, 1) = 1')) {
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $sid = trim((string)($r['Sid'] ?? ''));
                    if ($sid !== '') {
                        $sids[] = $sid;
                    }
                }
                $stmt->close();
            }
            $row['enrolled'] = count($sids);

            if ($hasProgress && ($stmt = $db->prepare('SELECT AVG(progress_percent) AS avg_p, COUNT(*) AS n FROM el_course_progress WHERE course_code = ?'))) {
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                if ((int)($r['n'] ?? 0) > 0) {
                    $row['avg_progress'] = round((float)$r['avg_p'], 1);
                }
            }

            if ($hasEvents && $sids) {
                $ph = implode(',', array_fill(0, count($sids), '?'));
                $sql = "SELECT COUNT(DISTINCT actor_id) AS active FROM el_analytics_events
                        WHERE actor_type = 'student' AND actor_id IN ($ph)
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)";
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param(str_repeat('s', count($sids)), ...$sids);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc() ?: [];
                    $stmt->close();
                    $row['inactive_learners'] = max(0, $row['enrolled'] - (int)($r['active'] ?? 0));
                }
            }

            $out['courses'][] = $row;
        }

        return $out;
    }
}

if (!function_exists('wuc_el_render_student_checklist')) {
    function wuc_el_render_student_checklist(array $insights): string
    {
        if (empty($insights['courses'])) {
            return '';
        }
        ob_start();
        ?>
        <article class="card">
            <div class="card-hdr">
                <h3><i class="fas fa-graduation-cap"></i> Study Checklist</h3>
                <?php if (($insights['days_inactive'] ?? null) !== null): ?>
                    <span class="badge <?= $insights['days_inactive'] > 7 ? 'bg-warning text-dark' : 'bg-success' ?>">
                        Active <?= (int)$insights['days_inactive'] ?>d ago
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <ul class="mb-0 ps-3 small">
                    <?php foreach (array_slice($insights['checklist'], 0, 6) as $item): ?>
                        <li class="mb-1"><?= htmlspecialchars((string)$item) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </article>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('wuc_el_render_lecturer_engagement')) {
    function wuc_el_render_lecturer_engagement(array $engagement): string
    {
        $courses = $engagement['courses'] ?? [];
        if (!$courses) {
            return '';
        }
        ob_start();
        ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="fas fa-signal me-2"></i>eLearning Engagement</h5>
                <span class="badge bg-primary"><?= count($courses) ?> course(s)</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Course</th><th>Enrolled</th><th>Avg progress</th><th>Inactive 14d+</th></tr></thead>
                        <tbody>
                            <?php foreach ($courses as $c): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars((string)$c['course_code']) ?></strong></td>
                                    <td><?= (int)$c['enrolled'] ?></td>
                                    <td><?= $c['avg_progress'] !== null ? htmlspecialchars((string)$c['avg_progress']) . '%' : '<span class="text-muted">—</span>' ?></td>
                                    <td>
                                        <?php if ((int)$c['inactive_learners'] > 0): ?>
                                            <span class="text-warning fw-semibold"><?= (int)$c['inactive_learners'] ?></span>
                                        <?php else: ?>
                                            <span class="text-success">0</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-muted small mt-2">Inactive = no eLearning activity logged in the last 14 days.</div>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

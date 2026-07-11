<?php
declare(strict_types=1);
/**
 * Shared training-reports engine — recruitment / throughput / instructor
 * allocation / cohort progress, with CSV export and AI narrative summaries.
 *
 * Used by role-scoped wrapper pages (admin, admissions) so each role sees the
 * reports it needs without duplicating SQL. Assumes the caller has already
 * authenticated and provided $db; the wrapper includes nav/footer chrome.
 *
 * Usage:
 *   require_once dirname(__DIR__).'/includes/training_reports_engine.php';
 *   trx_handle_csv($db, $opts);          // BEFORE any HTML output
 *   ... include nav ...
 *   trx_render($db, $opts);              // renders pills + filters + table + AI
 *   ... include footer ...
 *
 * $opts: ['allowed'=>[keys], 'base_url'=>'training_reports.php', 'role'=>'admin',
 *         'heading'=>'Training Reports']
 */

require_once __DIR__ . '/ai_portal.php';
require_once __DIR__ . '/audit.php';

if (!function_exists('trx_h')) {
    function trx_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('trx_query')) {
    function trx_query(mysqli $db, string $sql, string $types = '', array $params = []): array
    {
        if ($types === '') {
            $res = @$db->query($sql);
            if (!$res) { error_log('trx_query: ' . $db->error); return []; }
            $rows = []; while ($r = $res->fetch_assoc()) { $rows[] = $r; } $res->free(); return $rows;
        }
        $stmt = $db->prepare($sql);
        if (!$stmt) { error_log('trx_query prepare: ' . $db->error); return []; }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = []; while ($res && $r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
        return $rows;
    }
}
if (!function_exists('trx_fmt')) {
    function trx_fmt(string $type, $v): string
    {
        if ($v === null || $v === '') { return $type === 'int' || $type === 'num1' ? '0' : '—'; }
        switch ($type) {
            case 'int': return number_format((float)$v);
            case 'num1': return number_format((float)$v, 1);
            case 'money': return 'ZMW ' . number_format((float)$v, 2);
            case 'badge': return '<span class="badge bg-secondary text-capitalize">' . trx_h(str_replace('_', ' ', (string)$v)) . '</span>';
            default: return trx_h($v);
        }
    }
}
if (!function_exists('trx_csv')) {
    function trx_csv(string $type, $v): string
    {
        if ($v === null) { return ''; }
        if ($type === 'money' || $type === 'num1') { return (string)(float)$v; }
        return (string)$v;
    }
}

if (!function_exists('trx_report_defs')) {
    function trx_report_defs(): array
    {
        return [
            'recruitment_funnel' => [
                'title' => 'Recruitment Funnel',
                'portal' => 'academic',
                'icon' => 'fas fa-filter',
                'note' => 'Trainees recruited in the period, how many started (reported) and how many were trained (completed), by programme.',
                'columns' => [
                    ['campus_name', 'Campus', 'text'], ['program_code', 'Program', 'text'], ['program_name', 'Programme', 'text'],
                    ['recruited', 'Recruited', 'int'], ['reported', 'Reported/Started', 'int'], ['trained', 'Trained', 'int'],
                    ['withdrawn', 'Withdrawn', 'int'], ['failed', 'Failed', 'int'], ['completion_rate', 'Completion', 'text'],
                ],
                'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
                    $sql = "SELECT ca.campus_name, p.program_code, p.program_name,
                               COUNT(e.id) AS recruited, SUM(e.status IN ('active','completed')) AS reported,
                               SUM(e.status='completed') AS trained, SUM(e.status='withdrawn') AS withdrawn, SUM(e.status='failed') AS failed,
                               CONCAT(ROUND(100*SUM(e.status='completed')/NULLIF(COUNT(e.id),0)),'%') AS completion_rate
                            FROM transport_enrollments e
                            INNER JOIN transport_cohorts c ON c.id=e.cohort_id
                            INNER JOIN transport_programs p ON p.id=c.program_id
                            INNER JOIN transport_campuses ca ON ca.id=c.campus_id
                            WHERE e.enrollment_date BETWEEN ? AND ?";
                    $t='ss'; $pr=[$from,$to];
                    if ($campusId>0){ $sql.=" AND c.campus_id=?"; $t.='i'; $pr[]=$campusId; }
                    $sql.=" GROUP BY p.id, ca.id ORDER BY recruited DESC, ca.campus_name, p.program_code";
                    return trx_query($db,$sql,$t,$pr);
                },
            ],
            'training_throughput' => [
                'title' => 'Training Throughput',
                'portal' => 'academic',
                'icon' => 'fas fa-people-arrows',
                'note' => 'Per-cohort outcomes: enrolled, in-progress, trained, dropped and certificates issued.',
                'columns' => [
                    ['campus_name','Campus','text'],['cohort_name','Cohort','text'],['program_code','Program','text'],
                    ['cohort_status','Cohort','badge'],['enrolled','Enrolled','int'],['in_progress','In progress','int'],
                    ['trained','Trained','int'],['dropped','Dropped','int'],['certified','Certified','int'],
                ],
                'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
                    $sql = "SELECT ca.campus_name, c.cohort_name, p.program_code, c.status AS cohort_status,
                               COUNT(e.id) AS enrolled, SUM(e.status='active') AS in_progress, SUM(e.status='completed') AS trained,
                               SUM(e.status IN ('withdrawn','failed')) AS dropped, SUM(e.certificate_issued=1) AS certified
                            FROM transport_cohorts c
                            INNER JOIN transport_programs p ON p.id=c.program_id
                            INNER JOIN transport_campuses ca ON ca.id=c.campus_id
                            LEFT JOIN transport_enrollments e ON e.cohort_id=c.id";
                    $t=''; $pr=[];
                    if ($campusId>0){ $sql.=" WHERE c.campus_id=?"; $t='i'; $pr[]=$campusId; }
                    $sql.=" GROUP BY c.id ORDER BY ca.campus_name, c.start_date DESC, c.cohort_name";
                    return trx_query($db,$sql,$t,$pr);
                },
            ],
            'instructor_allocation' => [
                'title' => 'Instructor Allocation (Who Trains What)',
                'portal' => 'academic',
                'icon' => 'fas fa-chalkboard-user',
                'note' => 'Which instructor delivers which programmes/cohorts, with sessions, contact hours and trainees reached.',
                'columns' => [
                    ['campus_name','Campus','text'],['instructor','Instructor','text'],['programmes','Programmes','text'],
                    ['cohorts','Cohorts','int'],['sessions','Sessions','int'],['contact_hours','Contact Hrs','num1'],['trainees','Trainees','int'],
                ],
                'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
                    $sql = "SELECT ca.campus_name, i.full_name AS instructor,
                               GROUP_CONCAT(DISTINCT p.program_code ORDER BY p.program_code SEPARATOR ', ') AS programmes,
                               COUNT(DISTINCT s.cohort_id) AS cohorts, COUNT(s.id) AS sessions,
                               COALESCE(SUM(CASE WHEN s.status<>'cancelled' THEN s.contact_hours ELSE 0 END),0) AS contact_hours,
                               COUNT(DISTINCT e.trainee_id) AS trainees
                            FROM transport_sessions s
                            INNER JOIN transport_instructors i ON i.id=s.instructor_id
                            INNER JOIN transport_cohorts c ON c.id=s.cohort_id
                            INNER JOIN transport_programs p ON p.id=c.program_id
                            INNER JOIN transport_campuses ca ON ca.id=c.campus_id
                            LEFT JOIN transport_enrollments e ON e.cohort_id=c.id
                            WHERE s.session_date BETWEEN ? AND ?";
                    $t='ss'; $pr=[$from,$to];
                    if ($campusId>0){ $sql.=" AND c.campus_id=?"; $t.='i'; $pr[]=$campusId; }
                    $sql.=" GROUP BY i.id ORDER BY sessions DESC, i.full_name";
                    return trx_query($db,$sql,$t,$pr);
                },
            ],
            'cohort_progress' => [
                'title' => 'Cohort Progress & Attendance',
                'portal' => 'academic',
                'icon' => 'fas fa-list-check',
                'note' => 'Per-cohort: enrolled, how many reported (attended ≥1 session), sessions held, attendance rate, passes and certificates.',
                'columns' => [
                    ['campus_name','Campus','text'],['cohort_name','Cohort','text'],['program_code','Program','text'],
                    ['enrolled','Enrolled','int'],['reported','Reported','int'],['sessions_held','Sessions','int'],
                    ['attendance_rate','Attendance','text'],['passes','Passed','int'],['certified','Certified','int'],
                ],
                'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
                    $sql = "SELECT ca.campus_name, c.cohort_name, p.program_code,
                               (SELECT COUNT(*) FROM transport_enrollments e WHERE e.cohort_id=c.id) AS enrolled,
                               (SELECT COUNT(DISTINCT a.enrollment_id) FROM transport_session_attendance a INNER JOIN transport_sessions s2 ON s2.id=a.session_id WHERE s2.cohort_id=c.id AND a.status IN ('present','late')) AS reported,
                               (SELECT COUNT(*) FROM transport_sessions s WHERE s.cohort_id=c.id AND s.status<>'cancelled') AS sessions_held,
                               (SELECT CONCAT(ROUND(100*SUM(a.status IN ('present','late'))/NULLIF(COUNT(*),0)),'%') FROM transport_session_attendance a INNER JOIN transport_sessions s3 ON s3.id=a.session_id WHERE s3.cohort_id=c.id) AS attendance_rate,
                               (SELECT COUNT(*) FROM transport_assessments at INNER JOIN transport_enrollments e2 ON e2.id=at.enrollment_id WHERE e2.cohort_id=c.id AND at.result='pass') AS passes,
                               (SELECT COUNT(*) FROM transport_enrollments e3 WHERE e3.cohort_id=c.id AND e3.certificate_issued=1) AS certified
                            FROM transport_cohorts c
                            INNER JOIN transport_programs p ON p.id=c.program_id
                            INNER JOIN transport_campuses ca ON ca.id=c.campus_id";
                    $t=''; $pr=[];
                    if ($campusId>0){ $sql.=" WHERE c.campus_id=?"; $t='i'; $pr[]=$campusId; }
                    $sql.=" ORDER BY ca.campus_name, c.start_date DESC, c.cohort_name";
                    return trx_query($db,$sql,$t,$pr);
                },
            ],
        ];
    }
}

if (!function_exists('trx_reports_for_portal')) {
    /**
     * Report keys visible in a given portal. Each report def carries a 'portal'
     * tag ('*' = every portal); this returns the keys whose tag matches the
     * supplied portal (or the active $_SESSION['current_portal']). Used to scope
     * report access to the portal the user is operating in — part of the
     * multi-portal redesign's portal-aware access model.
     */
    function trx_reports_for_portal(?string $portalCode = null): array
    {
        $portal = strtolower(trim((string)($portalCode
            ?? ($_SESSION['current_portal'] ?? 'academic'))));
        if ($portal === '') { $portal = 'academic'; }

        $keys = [];
        foreach (trx_report_defs() as $key => $def) {
            $reportPortal = strtolower(trim((string)($def['portal'] ?? 'academic')));
            if ($reportPortal === '*' || $reportPortal === $portal) {
                $keys[] = $key;
            }
        }
        return $keys;
    }
}

if (!function_exists('trx_inputs')) {
    function trx_inputs(array $opts): array
    {
        $defs = trx_report_defs();
        // Fail-safe scoping: when a caller does not pin an explicit allow-list,
        // default to the reports for the active portal rather than every report,
        // so a wrapper can never accidentally expose another portal's reports.
        $allowed = $opts['allowed'] ?? trx_reports_for_portal($opts['portal'] ?? null);
        $key = (string)($_GET['report'] ?? $_POST['report'] ?? '');
        if ($key !== '' && !in_array($key, $allowed, true)) { $key = ''; }
        $today = date('Y-m-d');
        $from = (string)($_GET['from'] ?? $_POST['from'] ?? date('Y-01-01'));
        $to = (string)($_GET['to'] ?? $_POST['to'] ?? $today);
        if (!DateTimeImmutable::createFromFormat('Y-m-d', $from)) { $from = date('Y-01-01'); }
        if (!DateTimeImmutable::createFromFormat('Y-m-d', $to)) { $to = $today; }
        $campusId = (int)($_GET['campus'] ?? $_POST['campus'] ?? 0);
        return [$defs, $allowed, $key, $from, $to, $campusId];
    }
}

if (!function_exists('trx_handle_csv')) {
    function trx_handle_csv(mysqli $db, array $opts): void
    {
        $exportKey = (string)($_GET['export'] ?? '');
        if ($exportKey === '') { return; }
        [$defs, $allowed, , $from, $to, $campusId] = trx_inputs($opts);
        if (!in_array($exportKey, $allowed, true) || !isset($defs[$exportKey])) { return; }
        $report = $defs[$exportKey];
        $rows = $report['builder']($db, $from, $to, $campusId);
        if (function_exists('audit_log_current_user')) {
            audit_log_current_user($db, 'reports.training.export', [
                'report' => $exportKey,
                'from' => $from,
                'to' => $to,
                'campus_id' => $campusId,
                'rows' => count($rows),
                'role_scope' => (string)($opts['role'] ?? ''),
                'portal' => (string)($opts['portal'] ?? ($_SESSION['current_portal'] ?? '')),
            ]);
        }
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="training_' . $exportKey . '_' . $from . '_to_' . $to . '.csv"');
        }
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, [$report['title']]);
        fputcsv($out, ['Period', $from . ' to ' . $to]);
        fputcsv($out, ['Generated', date('Y-m-d H:i')]);
        fputcsv($out, []);
        fputcsv($out, array_map(static fn($c) => $c[1], $report['columns']));
        foreach ($rows as $row) {
            $line = [];
            foreach ($report['columns'] as [$f, , $ty]) { $line[] = trx_csv($ty, $row[$f] ?? ''); }
            fputcsv($out, $line);
        }
        if (!$rows) { fputcsv($out, ['No data for the selected criteria.']); }
        fclose($out);
        exit;
    }
}

if (!function_exists('trx_ai_summary')) {
    function trx_ai_summary(mysqli $db, array $report, array $rows, string $role): array
    {
        $context = [
            'report' => $report['title'],
            'role' => $role,
            'columns' => array_map(static fn($c) => $c[1], $report['columns']),
            'rows' => array_slice($rows, 0, 40),
            'rules' => ['summarize_only' => true, 'no_invented_numbers' => true],
        ];
        $ctxJson = wuc_ai_context_json($context, 14000);
        return wuc_ai_generate($db, [
            'feature' => 'training_report_summary',
            'user_role' => $role,
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'staff'),
            'input_summary' => $report['title'],
            'context_hash' => hash('sha256', $ctxJson),
            'messages' => [
                ['role' => 'system', 'content' => 'You summarise an ITC transport training report for a ' . $role
                    . '. Use ONLY the supplied rows. Give a concise narrative: headline recruited/trained/reported numbers, '
                    . 'notable programmes/cohorts, attendance or completion concerns, and 2-3 recommended actions. '
                    . 'Use short Markdown. Never invent figures not in the data.'],
                ['role' => 'user', 'content' => "Report data (JSON):\n{$ctxJson}\n\nWrite the summary."],
            ],
            'fallback' => static function () use ($report, $rows): string {
                return '**' . $report['title'] . "** — " . count($rows) . " row(s). AI summary unavailable; review the table below.";
            },
        ]);
    }
}

if (!function_exists('trx_render')) {
    function trx_render(mysqli $db, array $opts): void
    {
        [$defs, $allowed, $key, $from, $to, $campusId] = trx_inputs($opts);
        $baseUrl = (string)($opts['base_url'] ?? 'training_reports.php');
        $role = (string)($opts['role'] ?? 'staff');
        $heading = (string)($opts['heading'] ?? 'Training Reports');
        if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
        $csrf = (string)$_SESSION['csrf_token'];

        $campuses = trx_query($db, "SELECT id, campus_name FROM transport_campuses ORDER BY campus_name");

        // AI summary on POST
        $aiResult = null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_summary'
            && hash_equals($csrf, (string)($_POST['csrf_token'] ?? '')) && $key !== '' && isset($defs[$key])) {
            $rowsForAi = $defs[$key]['builder']($db, $from, $to, $campusId);
            $aiResult = trx_ai_summary($db, $defs[$key], $rowsForAi, $role);
        }

        echo '<div class="container-fluid py-3">';
        echo '<h3 class="mb-1"><i class="fas fa-chart-line me-2"></i>' . trx_h($heading) . '</h3>';
        echo '<p class="text-muted">Recruitment, throughput, instructor allocation and attendance reports.</p>';

        // Report pills
        echo '<div class="d-flex flex-wrap gap-2 mb-3">';
        foreach ($allowed as $k) {
            if (!isset($defs[$k])) { continue; }
            $active = $k === $key;
            echo '<a class="btn btn-sm ' . ($active ? 'btn-primary' : 'btn-outline-secondary') . '" href="'
                . trx_h($baseUrl . '?report=' . $k . '&from=' . $from . '&to=' . $to . ($campusId ? '&campus=' . $campusId : ''))
                . '"><i class="' . trx_h($defs[$k]['icon']) . ' me-1"></i>' . trx_h($defs[$k]['title']) . '</a>';
        }
        echo '</div>';

        if ($key === '') {
            echo '<div class="alert alert-info">Select a report above to view and export it.</div></div>';
            return;
        }
        $report = $defs[$key];

        // Filters
        echo '<form method="get" class="row g-2 align-items-end mb-3">';
        echo '<input type="hidden" name="report" value="' . trx_h($key) . '">';
        echo '<div class="col-auto"><label class="form-label small mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="' . trx_h($from) . '"></div>';
        echo '<div class="col-auto"><label class="form-label small mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="' . trx_h($to) . '"></div>';
        echo '<div class="col-auto"><label class="form-label small mb-1">Campus</label><select name="campus" class="form-select form-select-sm"><option value="0">All campuses</option>';
        foreach ($campuses as $c) {
            echo '<option value="' . (int)$c['id'] . '"' . ($campusId === (int)$c['id'] ? ' selected' : '') . '>' . trx_h($c['campus_name']) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col-auto"><button class="btn btn-sm btn-primary">Apply</button></div>';
        echo '<div class="col-auto"><a class="btn btn-sm btn-outline-success" href="' . trx_h($baseUrl . '?export=' . $key . '&from=' . $from . '&to=' . $to . ($campusId ? '&campus=' . $campusId : '')) . '"><i class="fas fa-file-csv me-1"></i>CSV</a></div>';
        echo '</form>';

        $rows = $report['builder']($db, $from, $to, $campusId);

        // Structured rule-based insight block (Sprint 7): summary, warnings,
        // trends and actions computed from the rows — always available, even
        // with AI offline. Persisted only when the AI summary is requested so
        // report_insights keeps one row per deliberate generation.
        try {
            require_once __DIR__ . '/report_insights_engine.php';
            $trxInsights = wuc_report_insights_build($report, $rows);
            if ($aiResult !== null) {
                wuc_report_insights_persist(
                    $db,
                    'training_' . $key,
                    'campus',
                    (string)$campusId,
                    $from . ' to ' . $to,
                    $trxInsights,
                    (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'staff')
                );
            }
            echo wuc_report_insights_render($trxInsights);
        } catch (Throwable $e) {
            error_log('trx_render insights failed: ' . $e->getMessage());
        }

        // AI summary card
        echo '<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">'
            . '<strong><i class="fas fa-wand-magic-sparkles me-2"></i>AI summary</strong>'
            . '<form method="post" class="m-0"><input type="hidden" name="csrf_token" value="' . trx_h($csrf) . '">'
            . '<input type="hidden" name="action" value="ai_summary"><input type="hidden" name="report" value="' . trx_h($key) . '">'
            . '<input type="hidden" name="from" value="' . trx_h($from) . '"><input type="hidden" name="to" value="' . trx_h($to) . '"><input type="hidden" name="campus" value="' . (int)$campusId . '">'
            . '<button class="btn btn-sm btn-outline-primary">Generate</button></form></div>';
        if ($aiResult !== null) {
            echo '<div class="card-body">';
            if (empty($aiResult['used_ai'])) { echo '<div class="alert alert-warning small py-2">' . trx_h(wuc_ai_fallback_notice($aiResult)) . '</div>'; }
            echo wuc_ai_output_block((string)$aiResult['text']);
            echo '</div>';
        } else {
            echo '<div class="card-body text-muted small">Click <strong>Generate</strong> for a plain-English summary of this report.</div>';
        }
        echo '</div>';

        // Table
        echo '<div class="card"><div class="card-header"><strong>' . trx_h($report['title']) . '</strong>'
            . '<div class="text-muted small">' . trx_h($report['note']) . '</div></div>';
        echo '<div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle"><thead><tr>';
        foreach ($report['columns'] as [$f, $label]) { echo '<th>' . trx_h($label) . '</th>'; }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="' . count($report['columns']) . '" class="text-muted text-center py-4">No data for the selected criteria.</td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($report['columns'] as [$f, , $ty]) { echo '<td>' . trx_fmt($ty, $row[$f] ?? null) . '</td>'; }
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></div>';
        echo '</div>';
    }
}

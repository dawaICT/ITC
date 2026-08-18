<?php
/**
 * Shared Student Risk Watchlist UI (Admin + Registrar).
 * Caller must load nav/chrome and academic_risk_engine.php; $db must be available.
 */
declare(strict_types=1);

if (!isset($db) || !($db instanceof mysqli)) {
    throw new RuntimeException('risk_watchlist_content requires $db');
}

$riskTableReady = function_exists('wuc_risk_table_exists') && wuc_risk_table_exists($db, 'student_risk_summary');

$levelFilter = isset($_GET['level']) ? trim((string)$_GET['level']) : '';
if (!in_array($levelFilter, ['High', 'Medium', 'Low'], true)) {
    $levelFilter = '';
}
$searchTerm = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if (strlen($searchTerm) > 80) {
    $searchTerm = substr($searchTerm, 0, 80);
}

$rows = [];
$levelCounts = ['High' => 0, 'Medium' => 0, 'Low' => 0];

if ($riskTableReady) {
    try {
        $countSql = "SELECT r.risk_level, COUNT(*) AS total
                     FROM student_risk_summary r
                     INNER JOIN (SELECT student_id, MAX(id) AS max_id FROM student_risk_summary GROUP BY student_id) latest
                        ON latest.max_id = r.id
                     GROUP BY r.risk_level";
        if ($res = $db->query($countSql)) {
            while ($row = $res->fetch_assoc()) {
                $lvl = (string)($row['risk_level'] ?? '');
                if (isset($levelCounts[$lvl])) {
                    $levelCounts[$lvl] = (int)($row['total'] ?? 0);
                }
            }
            $res->free();
        }

        $where = [];
        $types = '';
        $params = [];
        if ($levelFilter !== '') {
            $where[] = 'r.risk_level = ?';
            $types .= 's';
            $params[] = $levelFilter;
        }
        if ($searchTerm !== '') {
            $where[] = "(r.student_id LIKE ? OR CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, '')) LIKE ?)";
            $types .= 'ss';
            $like = '%' . $searchTerm . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT r.student_id, r.program_code, r.risk_score, r.risk_level, r.risk_reason,
                       r.recommended_action, r.data_quality, r.generated_at,
                       CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, '')) AS student_name
                FROM student_risk_summary r
                INNER JOIN (SELECT student_id, MAX(id) AS max_id FROM student_risk_summary GROUP BY student_id) latest
                    ON latest.max_id = r.id
                LEFT JOIN students s ON s.SID = r.student_id
                {$whereSql}
                ORDER BY FIELD(r.risk_level, 'High', 'Medium', 'Low'), r.risk_score DESC, r.generated_at DESC
                LIMIT 200";
        if ($stmt = $db->prepare($sql)) {
            if ($types !== '' && function_exists('wuc_risk_bind_values')) {
                wuc_risk_bind_values($stmt, $types, $params);
            } elseif ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('risk_watchlist_content query failed: ' . $e->getMessage());
        $rows = [];
    }
}
?>

<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h4 class="dashboard-title mb-1"><i class="fas fa-heart-pulse me-2"></i>Student Risk Watchlist</h4>
            <div class="text-muted small">
                Explainable rule-based scores from the academic risk engine — attendance, marks, coursework,
                eLearning activity, fees, and registration completeness. Read-only: decisions stay with staff.
            </div>
        </div>
        <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 220px;"
                   placeholder="Search student ID or name" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>">
            <select name="level" class="form-select form-select-sm" style="max-width: 150px;">
                <option value="">All levels</option>
                <?php foreach (['High', 'Medium', 'Low'] as $lvl): ?>
                    <option value="<?= $lvl ?>" <?= $levelFilter === $lvl ? 'selected' : '' ?>><?= $lvl ?> risk</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        </form>
    </div>

    <div class="row g-3 mb-3">
        <?php foreach ([['High', 'danger'], ['Medium', 'warning'], ['Low', 'success']] as [$lvl, $cls]): ?>
            <div class="col-md-4">
                <div class="p-3 rounded-3 bg-<?= $cls ?> bg-opacity-10 border">
                    <div class="text-muted small"><?= $lvl ?>-risk learners (latest score)</div>
                    <div class="fs-3 fw-bold text-<?= $cls ?>"><?= (int)$levelCounts[$lvl] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$riskTableReady): ?>
        <div class="alert alert-warning">
            The risk summary table has not been migrated yet. Apply
            <code>migrations/2026_06_25_ai_academic_risk.sql</code> as the migrator user, then run
            <code>php scripts/batch_risk_rescore.php</code>.
        </div>
    <?php elseif (!$rows): ?>
        <div class="alert alert-light border">
            <i class="fas fa-circle-check me-1"></i>No risk snapshots match this filter.
            Run <code>php scripts/batch_risk_rescore.php</code> to refresh scores for all active students.
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="fas fa-list-check me-2"></i>Latest Risk Snapshots</h5>
                <span class="badge bg-primary"><?= count($rows) ?> shown</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Learner</th>
                            <th>Programme</th>
                            <th>Level</th>
                            <th>Score</th>
                            <th style="min-width: 280px;">Why (stored reasons)</th>
                            <th style="min-width: 220px;">Recommended action</th>
                            <th>Scored</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $cls = function_exists('wuc_academic_risk_level_class')
                                ? wuc_academic_risk_level_class((string)$row['risk_level'])
                                : 'secondary';
                            $reasons = array_filter(array_map('trim', explode("\n", (string)($row['risk_reason'] ?? ''))));
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars(trim((string)($row['student_name'] ?? '')) ?: (string)$row['student_id'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars((string)$row['student_id'], ENT_QUOTES, 'UTF-8') ?></small>
                                </td>
                                <td><?= htmlspecialchars((string)($row['program_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge bg-<?= htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$row['risk_level'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="fw-bold text-<?= htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') ?>"><?= (int)$row['risk_score'] ?></td>
                                <td>
                                    <ul class="mb-0 ps-3 small">
                                        <?php foreach (array_slice($reasons, 0, 4) as $reason): ?>
                                            <li><?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                                <td class="small"><?= htmlspecialchars((string)($row['recommended_action'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small text-muted"><?= htmlspecialchars((string)($row['generated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

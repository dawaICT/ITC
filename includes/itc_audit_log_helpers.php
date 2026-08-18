<?php
/**
 * Canonical audit log viewer helpers.
 */

function itc_audit_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function itc_audit_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function itc_audit_normalize_filters(array $source): array
{
    $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
    $dateFrom = trim((string)($source['date_from'] ?? ''));
    $dateTo = trim((string)($source['date_to'] ?? ''));

    return [
        'user_id' => trim((string)($source['user_id'] ?? '')),
        'module' => trim((string)($source['module'] ?? '')),
        'action' => trim((string)($source['action'] ?? '')),
        'record_id' => trim((string)($source['record_id'] ?? '')),
        'q' => trim((string)($source['q'] ?? '')),
        'date_from' => preg_match($datePattern, $dateFrom) ? $dateFrom : '',
        'date_to' => preg_match($datePattern, $dateTo) ? $dateTo : '',
        'page' => max(1, (int)($source['page'] ?? 1)),
        'per_page' => min(100, max(20, (int)($source['per_page'] ?? 50))),
    ];
}

function itc_audit_filter_sql(array $filters, string &$types, array &$params): string
{
    $where = ['1=1'];
    $likeFilters = [
        'user_id' => 'al.user_id',
        'module' => 'al.module',
        'action' => 'al.action',
        'record_id' => 'al.record_id',
    ];

    foreach ($likeFilters as $key => $column) {
        if ($filters[$key] === '') {
            continue;
        }
        $where[] = "{$column} LIKE ?";
        $types .= 's';
        $params[] = '%' . $filters[$key] . '%';
    }

    if ($filters['q'] !== '') {
        $where[] = "(al.old_value LIKE ? OR al.new_value LIKE ? OR al.user_agent LIKE ? OR al.ip_address LIKE ?)";
        $types .= 'ssss';
        $q = '%' . $filters['q'] . '%';
        array_push($params, $q, $q, $q, $q);
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'al.created_at >= ?';
        $types .= 's';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'al.created_at <= ?';
        $types .= 's';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    return implode(' AND ', $where);
}

function itc_audit_query(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Audit query prepare failed: ' . $db->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function itc_audit_fetch(mysqli $db, array $filters): array
{
    $types = '';
    $params = [];
    $where = itc_audit_filter_sql($filters, $types, $params);
    $perPage = (int)$filters['per_page'];
    $offset = ((int)$filters['page'] - 1) * $perPage;

    $countRows = itc_audit_query($db, "SELECT COUNT(*) AS total FROM audit_logs al WHERE {$where}", $types, $params);
    $total = (int)($countRows[0]['total'] ?? 0);

    $dataTypes = $types . 'ii';
    $dataParams = array_merge($params, [$perPage, $offset]);
    $rows = itc_audit_query(
        $db,
        "SELECT al.id, al.user_id, al.action, al.module, al.record_id, al.old_value, al.new_value,
                al.ip_address, al.user_agent, al.created_at,
                TRIM(CONCAT(COALESCE(sf.Fname, ''), ' ', COALESCE(sf.Lname, ''))) AS staff_name
         FROM audit_logs al
         LEFT JOIN staff sf ON sf.staff_id = al.user_id
         WHERE {$where}
         ORDER BY al.created_at DESC, al.id DESC
         LIMIT ? OFFSET ?",
        $dataTypes,
        $dataParams
    );

    return [
        'rows' => $rows,
        'total' => $total,
        'total_pages' => max(1, (int)ceil($total / $perPage)),
    ];
}

function itc_audit_fetch_export(mysqli $db, array $filters): array
{
    $types = '';
    $params = [];
    $where = itc_audit_filter_sql($filters, $types, $params);
    return itc_audit_query(
        $db,
        "SELECT al.id, al.user_id, al.action, al.module, al.record_id, al.old_value, al.new_value,
                al.ip_address, al.user_agent, al.created_at
         FROM audit_logs al
         WHERE {$where}
         ORDER BY al.created_at DESC, al.id DESC
         LIMIT 5000",
        $types,
        $params
    );
}

function itc_audit_options(mysqli $db): array
{
    return [
        'modules' => itc_audit_query($db, "SELECT DISTINCT module AS value FROM audit_logs WHERE module <> '' ORDER BY module LIMIT 200"),
        'actions' => itc_audit_query($db, "SELECT DISTINCT action AS value FROM audit_logs WHERE action <> '' ORDER BY action LIMIT 300"),
    ];
}

function itc_audit_stats(mysqli $db): array
{
    $rows = itc_audit_query(
        $db,
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
            COUNT(DISTINCT user_id) AS users
         FROM audit_logs"
    );
    return $rows[0] ?? ['total' => 0, 'today' => 0, 'week' => 0, 'users' => 0];
}

function itc_audit_preview($value, int $max = 180): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        $text = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
}

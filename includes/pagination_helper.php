<?php
/**
 * Reusable server-side pagination + search helper for JSON list endpoints.
 *
 * Centralises the "count + page-of-rows + bound search" logic so every list
 * endpoint (students, staff, payments, CA records, logs, …) ships only the
 * current page and only the columns it needs, using prepared statements for
 * all user input. Keeps the endpoints thin and consistent.
 *
 * Usage:
 *   $result = wuc_paginate($db, [
 *       'select'      => "s.SID, s.Fname, s.Lname",
 *       'from'        => "FROM students s INNER JOIN programs p ON ...",
 *       'search_cols' => ['s.SID', 's.Fname', 's.Lname'],   // optional
 *       'base_where'  => "s.status = ?",                     // optional
 *       'base_params' => ['active'], 'base_types' => 's',    // optional
 *       'order_by'    => "s.Lname ASC, s.Fname ASC",         // optional
 *   ]);
 *   echo json_encode($result);
 *
 * Reads page / per_page / q from $_GET. Never interpolates user input into SQL.
 */

if (!function_exists('wuc_paginate')) {
    function wuc_paginate(mysqli $db, array $opts): array
    {
        $select     = (string) ($opts['select'] ?? '*');
        $from       = (string) ($opts['from'] ?? '');
        $searchCols = isset($opts['search_cols']) && is_array($opts['search_cols']) ? $opts['search_cols'] : [];
        $baseWhere  = trim((string) ($opts['base_where'] ?? ''));
        $baseParams = isset($opts['base_params']) && is_array($opts['base_params']) ? $opts['base_params'] : [];
        $baseTypes  = (string) ($opts['base_types'] ?? '');
        $orderBy    = trim((string) ($opts['order_by'] ?? ''));
        $defaultPer = (int) ($opts['default_per'] ?? 25);
        $maxPer     = (int) ($opts['max_per'] ?? 100);

        if ($from === '') {
            return ['ok' => false, 'error' => 'missing_from', 'rows' => []];
        }

        $page    = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : $defaultPer;
        $perPage = max(5, min($maxPer, $perPage));
        $search  = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $offset  = ($page - 1) * $perPage;

        // Build WHERE = base_where AND (search across search_cols)
        $clauses = [];
        $types   = $baseTypes;
        $params  = $baseParams;

        if ($baseWhere !== '') {
            $clauses[] = '(' . $baseWhere . ')';
        }
        if ($search !== '' && !empty($searchCols)) {
            $like  = '%' . $search . '%';
            $parts = [];
            foreach ($searchCols as $col) {
                $parts[] = $col . ' LIKE ?';
                $types  .= 's';
                $params[] = $like;
            }
            $clauses[] = '(' . implode(' OR ', $parts) . ')';
        }
        $where = $clauses ? (' WHERE ' . implode(' AND ', $clauses)) : '';

        // Total count
        $total = 0;
        if ($stmt = $db->prepare("SELECT COUNT(*) AS c " . $from . $where)) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        }

        // Page of rows
        $rows      = [];
        $order     = $orderBy !== '' ? (' ORDER BY ' . $orderBy) : '';
        $dataSql   = "SELECT " . $select . ' ' . $from . $where . $order . " LIMIT ? OFFSET ?";
        $dataTypes = $types . 'ii';
        $dataParams = array_merge($params, [$perPage, $offset]);
        if ($stmt = $db->prepare($dataSql)) {
            $stmt->bind_param($dataTypes, ...$dataParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }

        return [
            'ok'          => true,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            'offset'      => $offset,
            'rows'        => $rows,
        ];
    }
}

<?php
/**
 * Get fee structures grouped by program
 * Returns fees organized by program for cleaner display
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$root_path = dirname(dirname(dirname(__FILE__)));
require_once $root_path . '/db/connect.php';

header('Content-Type: application/json');

function accounts_ajax_columns(mysqli $db, string $table): array
{
    $columns = [];
    $safeTable = $db->real_escape_string($table);
    try {
        if ($result = $db->query("SHOW COLUMNS FROM `{$safeTable}`")) {
            while ($row = $result->fetch_assoc()) {
                $columns[] = (string)$row['Field'];
            }
            $result->free();
        }
    } catch (Throwable $e) {
        return [];
    }
    return $columns;
}

function accounts_ajax_has_column(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function accounts_ajax_table_exists(mysqli $db, string $table): bool
{
    $safeTable = $db->real_escape_string($table);
    try {
        $result = $db->query("SHOW TABLES LIKE '{$safeTable}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $feeColumns = accounts_ajax_columns($db, 'fee_structure');
    if (empty($feeColumns)) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'stats' => ['total' => 0, 'programs' => 0, 'active' => 0, 'inactive' => 0],
            'total_programs' => 0,
            'total_fees' => 0,
        ]);
        exit;
    }

    // Check if programs table has period_type column
    $hasPeriodType = false;
    $programColumns = accounts_ajax_columns($db, 'programs');
    $hasPeriodType = accounts_ajax_has_column($programColumns, 'period_type');
    $hasPeriodMode = accounts_ajax_has_column($programColumns, 'period_mode');
    $hasEntityType = accounts_ajax_has_column($feeColumns, 'entity_type');
    $hasShortCourseId = accounts_ajax_has_column($feeColumns, 'short_course_id');
    $hasFeeType = accounts_ajax_has_column($feeColumns, 'fee_type');
    $hasCourseCode = accounts_ajax_has_column($feeColumns, 'course_code');
    $canJoinShortCourses = $hasShortCourseId && accounts_ajax_table_exists($db, 'short_courses');
    $entityExpression = $hasEntityType ? "fs.entity_type" : "'program'";
    $shortCourseCodeExpression = $canJoinShortCourses ? "sc.course_code" : "NULL";
    $shortCourseNameExpression = $canJoinShortCourses ? "sc.course_name" : "NULL";
    $periodExpression = $hasPeriodType
        ? "p.period_type"
        : ($hasPeriodMode ? "p.period_mode" : "'semester'");

    // Build base query
    $selectFields = "fs.*,
        {$entityExpression} AS resolved_entity_type,
        CASE
            WHEN {$entityExpression} = 'short_course' THEN {$shortCourseCodeExpression}
            ELSE fs.program_code
        END AS group_code,
        CASE
            WHEN {$entityExpression} = 'short_course' THEN {$shortCourseNameExpression}
            ELSE p.program_name
        END AS group_name,
        CASE
            WHEN {$entityExpression} = 'short_course' THEN NULL
            ELSE {$periodExpression}
        END AS period_type";
    $query = "SELECT $selectFields 
              FROM fee_structure fs 
              LEFT JOIN programs p ON fs.program_code = p.program_code ";
    if ($canJoinShortCourses) {
        $query .= "LEFT JOIN short_courses sc ON fs.short_course_id = sc.id ";
    }
    $query .= "WHERE 1=1";

    // Apply filters
    $filterProgram = $_POST['filterProgram'] ?? '';
    $filterYear = $_POST['filterYear'] ?? '';
    $filterStatus = $_POST['filterStatus'] ?? '';
    $filterEntityType = $_POST['filterEntityType'] ?? '';

    if ($filterProgram !== '') {
        $filterProgramEscaped = $db->real_escape_string($filterProgram);
        if ($canJoinShortCourses && $hasEntityType) {
            $query .= " AND (
                (fs.entity_type = 'program' AND fs.program_code = '" . $filterProgramEscaped . "')
                OR
                (fs.entity_type = 'short_course' AND sc.course_code = '" . $filterProgramEscaped . "')
            )";
        } else {
            $query .= " AND fs.program_code = '" . $filterProgramEscaped . "'";
        }
    }
    if ($filterYear !== '' && accounts_ajax_has_column($feeColumns, 'year_of_study')) {
        $query .= " AND fs.year_of_study = " . intval($filterYear);
    }
    if ($filterStatus !== '' && accounts_ajax_has_column($feeColumns, 'status')) {
        $query .= " AND fs.status = '" . $db->real_escape_string($filterStatus) . "'";
    }
    if (in_array($filterEntityType, ['program', 'short_course'], true) && $hasEntityType) {
        $query .= " AND fs.entity_type = '" . $db->real_escape_string($filterEntityType) . "'";
    } elseif ($filterEntityType === 'short_course' && !$hasEntityType) {
        $query .= " AND 1 = 0";
    }

    // Order by program, year, semester for clean grouping
    $orderParts = ["resolved_entity_type", "group_code"];
    foreach (['year_of_study', 'semester', 'fee_description'] as $column) {
        if (accounts_ajax_has_column($feeColumns, $column)) {
            $orderParts[] = "fs.`{$column}`";
        }
    }
    $query .= " ORDER BY " . implode(', ', $orderParts);

    $result = $db->query($query);
    if (!$result) {
        throw new Exception("Query failed: " . $db->error);
    }

    // Group fees by program
    $groupedData = [];
    $programIndex = [];
    $stats = [
        'total' => 0,
        'programs' => 0,
        'active' => 0,
        'inactive' => 0
    ];

    while ($row = $result->fetch_assoc()) {
        $entityType = $row['resolved_entity_type'] ?? $row['entity_type'] ?? 'program';
        $groupCode = $row['group_code'] ?? $row['program_code'];
        $groupKey = $entityType . ':' . $groupCode;
        
        // Create program group if it doesn't exist
        if (!isset($programIndex[$groupKey])) {
            $programIndex[$groupKey] = count($groupedData);
            $groupedData[] = [
                'program_code' => $groupCode,
                'program_name' => $row['group_name'] ?? $groupCode,
                'entity_type' => $entityType,
                'fees' => []
            ];
        }

        $stats['total']++;
        if (($row['status'] ?? '') === 'active') {
            $stats['active']++;
        } elseif (($row['status'] ?? '') === 'inactive') {
            $stats['inactive']++;
        }
        
        // Add fee to program group
        $index = $programIndex[$groupKey];
        $groupedData[$index]['fees'][] = [
            'id' => (int)$row['id'],
            'year_of_study' => $row['year_of_study'] !== null ? (int)$row['year_of_study'] : null,
            'semester' => $row['semester'] !== null ? (int)$row['semester'] : null,
            'fee_description' => $row['fee_description'],
            'amount' => (float)$row['amount'],
            'status' => $row['status'] ?? 'active',
            'period_type' => $row['period_type'] ?? 'semester',
            'course_code' => $hasCourseCode ? ($row['course_code'] ?? null) : null,
            'fee_type' => $hasFeeType ? ($row['fee_type'] ?? null) : null,
            'short_course_id' => $hasShortCourseId && $row['short_course_id'] !== null ? (int)$row['short_course_id'] : null,
            'entity_type' => $entityType
        ];
    }
    $result->free();
    $stats['programs'] = count($groupedData);

    echo json_encode([
        'success' => true,
        'data' => $groupedData,
        'stats' => $stats,
        'total_programs' => count($groupedData),
        'total_fees' => array_sum(array_map(function($p) { return count($p['fees']); }, $groupedData))
    ]);

} catch (Exception $e) {
    error_log("Fee structure grouped error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching fee structures',
        'data' => []
    ]);
}
?>

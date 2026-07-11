<?php
// Suppress any PHP output before JSON response
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once "../includes/admin.php";

// Discard any output from admin.php
ob_end_clean();

header('Content-Type: application/json');

try {
    // Detect which active-state column exists (status vs is_active vs none)
    $activeFilter = '';
    $checkStatus = $db->query("SHOW COLUMNS FROM programs LIKE 'status'");
    $checkIsActive = $db->query("SHOW COLUMNS FROM programs LIKE 'is_active'");

    if ($checkStatus && $checkStatus->num_rows > 0) {
        $activeFilter = "WHERE status = 'active'";
    } elseif ($checkIsActive && $checkIsActive->num_rows > 0) {
        $activeFilter = "WHERE is_active = 1";
    }
    // If neither column exists, return all programs (no filter)

    $programCols = [];
    if ($programMeta = $db->query("SHOW COLUMNS FROM programs")) {
        while ($col = $programMeta->fetch_assoc()) {
            $programCols[strtolower((string)$col['Field'])] = (string)$col['Field'];
        }
        $programMeta->free();
    }
    if (isset($programCols['period_mode'])) {
        $studyModeExpr = "COALESCE(NULLIF(`{$programCols['period_mode']}`, ''), 'semester')";
    } elseif (isset($programCols['period_type'])) {
        $studyModeExpr = "COALESCE(NULLIF(`{$programCols['period_type']}`, ''), 'semester')";
    } elseif (isset($programCols['study_mode'])) {
        $studyModeExpr = "CASE WHEN LOWER(COALESCE(`{$programCols['study_mode']}`, '')) IN ('term','termly') THEN 'term' ELSE 'semester' END";
    } else {
        $studyModeExpr = "'semester'";
    }

    $query = "SELECT program_code, program_name, $studyModeExpr as study_mode
              FROM programs
              $activeFilter
              ORDER BY program_name";

    $result = $db->query($query);

    if (!$result) {
        throw new Exception($db->error);
    }

    $programs = [];
    while ($row = $result->fetch_assoc()) {
        $programs[] = [
            'program_code' => $row['program_code'],
            'program_name' => $row['program_name'],
            'study_mode' => $row['study_mode']
        ];
    }

    echo json_encode([
        'success' => true,
        'programs' => $programs
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

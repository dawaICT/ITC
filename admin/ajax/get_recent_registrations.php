<?php
define('IS_SCRIPT', true);
require_once __DIR__ . '/../includes/admin.php';
header('Content-Type: application/json');

try {
    $count = 0;
    // Detect available timestamp column
    $col = null;
    if ($r = $db->query("SHOW COLUMNS FROM semester_registration LIKE 'registration_date'")) {
        if ($r->num_rows > 0) { $col = 'registration_date'; }
        $r->free();
    }
    if (!$col) {
        if ($r = $db->query("SHOW COLUMNS FROM semester_registration LIKE 'created_at'")) {
            if ($r->num_rows > 0) { $col = 'created_at'; }
            $r->free();
        }
    }
    if (!$col) {
        if ($r = $db->query("SHOW COLUMNS FROM semester_registration LIKE 'updated_at'")) {
            if ($r->num_rows > 0) { $col = 'updated_at'; }
            $r->free();
        }
    }
    if ($col) {
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM semester_registration WHERE $col >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM semester_registration");
    }
    if ($stmt && $stmt->execute()) {
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $count = (int)($row['c'] ?? 0);
    }
    echo json_encode(['count' => $count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch']);
}


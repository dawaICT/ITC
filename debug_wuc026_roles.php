<?php
require_once "db/connect.php";
require_once "includes/id_helpers.php";

$staff_id = 'WUC026';
echo "=== Debugging Role Resolution for $staff_id ===\n\n";

// 1. Fetch positions
$stmt = $db->prepare("
    SELECT DISTINCT p.PosName, sp.PosID 
    FROM staff_positions sp 
    JOIN positions p ON p.PosID = sp.PosID 
    WHERE sp.staff_id = ?
");
$stmt->bind_param('s', $staff_id);
$stmt->execute();
$result = $stmt->get_result();

$allowedRoleNames = [
    'lecturer', 'head of department', 'hod', 'dean', 'registrar', 
    'vice chancellor', 'vc', 'dvc', 'deputy vc', 'administrator', 'admin', 
    'admissions', 'librarian', 'library', 'accountant', 'accounts', 'bursar'
];

echo "Allowed Role Keywords: " . implode(', ', $allowedRoleNames) . "\n\n";

while ($row = $result->fetch_assoc()) {
    $posName = $row['PosName'];
    $posID = $row['PosID'];
    
    echo "Position Found: [$posID] $posName\n";
    
    // Test Whitelist Check
    $normalizedPos = strtolower($posName);
    $isAllowed = false;
    foreach ($allowedRoleNames as $allowed) {
        if (strpos($normalizedPos, $allowed) !== false) {
            $isAllowed = true;
            echo "  -> Matched allowed keyword: '$allowed'\n";
            break;
        }
    }
    
    if ($isAllowed) {
        $path = resolveRoleModulePath($posID, $posName);
        echo "  -> Resolved Path: '$path'\n";
        
        // Simulating dashboard check
        $sanitized = sanitizeModulePath_Debug($path);
        echo "  -> Sanitized Path: " . ($sanitized ? "'$sanitized'" : "NULL (Blocked)") . "\n";
    } else {
        echo "  -> BLOCKED: Role name not in allowed whitelist.\n";
    }
    echo "---------------------------------------------------\n";
}

// Helper from dashboard.php for testing
function sanitizeModulePath_Debug(string $path): ?string {
    $allowed_bases = [
        'lecturers', 'admin', 'admissions', 'hod', 'dean', 
        'registrar', 'library', 'vc', 'dvc', 'accounts'
    ];
    
    if ($path === 'index.php') return $path;

    foreach ($allowed_bases as $base) {
        if ($path === $base || strpos($path, $base . '/') === 0) {
            return $path;
        }
    }
    return null;
}
?>

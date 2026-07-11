<?php
require_once __DIR__ . '/../db/connect.php';

echo "Connected to DB: OK\n";

// Check tables
$tables = ['fee_structures','fee_structure','program_fees'];
foreach ($tables as $t) {
    $r = $db->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='" . $db->real_escape_string($t) . "'");
    $c = ($r && ($row = $r->fetch_assoc())) ? (int)$row['c'] : 0;
    echo sprintf("Table %-15s: %s\n", $t, $c ? 'exists' : 'missing');
}

// If fee_structures exists, show counts and sample rows
$r = $db->query("SHOW TABLES LIKE 'fee_structures'");
if ($r && $r->num_rows > 0) {
    $q = $db->query("SELECT COUNT(*) AS cnt FROM fee_structures");
    $cnt = ($q) ? (int)$q->fetch_assoc()['cnt'] : 0;
    echo "fee_structures rows: " . $cnt . "\n";
    $s = $db->query("SELECT program_code, academic_year, year_of_study, semester, fee_description, amount, status FROM fee_structures ORDER BY program_code LIMIT 10");
    if ($s) {
        echo "Sample fee_structures:\n";
        while ($row = $s->fetch_assoc()) {
            echo implode(' | ', $row) . "\n";
        }
    }
}

// If fee_structures is missing, try to create it from db/fee_structure.sql
$r = $db->query("SHOW TABLES LIKE 'fee_structures'");
if ($r && $r->num_rows === 0) {
    echo "fee_structures missing — creating simplified table\n";
    $create = "CREATE TABLE IF NOT EXISTS fee_structures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_code VARCHAR(20) NOT NULL,
        academic_year VARCHAR(20) DEFAULT NULL,
        year_of_study INT NOT NULL DEFAULT 1,
        semester INT NOT NULL DEFAULT 1,
        fee_description VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    if (!$db->query($create)) {
        echo "Failed to create simplified fee_structures: " . $db->error . "\n";
    } else {
        echo "Created simplified fee_structures table. Inserting sample rows...\n";
        $samples = [
            ['BSCS', '2025/2026', 1, 1, 'Tuition Fee', 50000.00],
            ['BSCS', '2025/2026', 1, 1, 'Library Fee', 2000.00],
            ['BSIT', '2025/2026', 1, 1, 'Tuition Fee', 45000.00],
            ['BSIT', '2025/2026', 1, 1, 'Library Fee', 2000.00]
        ];
        $stmt = $db->prepare("INSERT INTO fee_structures (program_code, academic_year, year_of_study, semester, fee_description, amount) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($samples as $samp) {
            $stmt->bind_param('ssiisd', $samp[0], $samp[1], $samp[2], $samp[3], $samp[4], $samp[5]);
            $stmt->execute();
        }
        echo "Inserted sample fee rows.\n";
    }
}

// If program_fees exists, show sample
$r2 = $db->query("SHOW TABLES LIKE 'program_fees'");
if ($r2 && $r2->num_rows > 0) {
    echo "program_fees exists. sample rows:\n";
    $s2 = $db->query("SELECT * FROM program_fees LIMIT 5");
    if ($s2) {
        while ($row = $s2->fetch_assoc()) {
            echo json_encode($row) . "\n";
        }
    }
}

// Show counts and columns for program_fees and fee_structure
$tablesToInspect = ['program_fees', 'fee_structure'];
foreach ($tablesToInspect as $t) {
    $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'");
    if ($r && $r->num_rows > 0) {
        $q = $db->query("SELECT COUNT(*) AS cnt FROM {$t}");
        $cnt = ($q) ? (int)$q->fetch_assoc()['cnt'] : 0;
        echo "\nTable {$t} has {$cnt} rows\n";
        $cols = $db->query("SHOW COLUMNS FROM {$t}");
        if ($cols) {
            echo "Columns: ";
            $carr = [];
            while ($col = $cols->fetch_assoc()) { $carr[] = $col['Field']; }
            echo implode(', ', $carr) . "\n";
        }
    }
}

?>
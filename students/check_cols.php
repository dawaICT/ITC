<?php
require_once dirname(__DIR__) . '/db/connect.php';

try {
    $tables = ['programs', 'departments'];
    foreach ($tables as $table) {
        echo "Table: $table\n";
        $result = $db->query("SHOW COLUMNS FROM `$table`");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                echo " - " . $row['Field'] . "\n";
            }
        } else {
            echo "Failed to query table $table: " . $db->error . "\n";
        }
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
?>

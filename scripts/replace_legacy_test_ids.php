<?php
/**
 * Replace exact legacy demo IDs in the local portal database:
 *   STU900 -> CSE26456789
 *   WUC900 -> ITC900
 *
 * This intentionally updates exact column values only. It does not rewrite
 * partial strings, file names, comments, migration scripts, or generated text.
 */

require_once __DIR__ . '/../db/connect.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script from the command line.\n";
    exit(1);
}

$replacements = [
    'STU900' => 'CSE26456789',
    'WUC900' => 'ITC900',
];

$db->begin_transaction();
try {
    $cols = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')
         ORDER BY TABLE_NAME, COLUMN_NAME"
    );

    $changes = [];
    while ($col = $cols->fetch_assoc()) {
        $table = str_replace('`', '``', (string)$col['TABLE_NAME']);
        $column = str_replace('`', '``', (string)$col['COLUMN_NAME']);

        foreach ($replacements as $old => $new) {
            $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$column}` = ?");
            if (!$countStmt) {
                continue;
            }
            $countStmt->bind_param('s', $old);
            $countStmt->execute();
            $count = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $countStmt->close();
            if ($count === 0) {
                continue;
            }

            $update = $db->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?");
            $update->bind_param('ss', $new, $old);
            $update->execute();
            $affected = $update->affected_rows;
            $update->close();
            $changes[] = "{$table}.{$column}: {$old} -> {$new} ({$affected})";
        }
    }

    $db->commit();
    if (!$changes) {
        echo "No legacy demo IDs found.\n";
    } else {
        echo "Updated legacy demo IDs:\n";
        foreach ($changes as $change) {
            echo " - {$change}\n";
        }
    }
} catch (Throwable $e) {
    $db->rollback();
    echo 'Error replacing legacy IDs: ' . $e->getMessage() . "\n";
    exit(1);
}

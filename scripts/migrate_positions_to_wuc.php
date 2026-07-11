<?php
// One-time migration script: Convert non-WUC PosIDs to WUC### while preserving role names
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/id_helpers.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script via CLI for safety. Example: php scripts/migrate_positions_to_wuc.php\n";
    exit(1);
}

$db->begin_transaction();
try {
    // Fetch positions that do not start with WUC
    $res = $db->query("SELECT PosID, PosName FROM positions WHERE PosID NOT REGEXP '^WUC[0-9]+' ORDER BY PosID");
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $old = $row['PosID'];
        $new = generateNextPosId($db);
        // Ensure uniqueness
        while (!$db->query("SELECT 1 FROM positions WHERE PosID='".$db->real_escape_string($new)."' LIMIT 1")->num_rows === 0) {
            $new = generateNextPosId($db);
        }
        $map[$old] = $new;
        // Insert new position row first
        $ins = $db->prepare('INSERT INTO positions (PosID, PosName) VALUES (?, ?)');
        $ins->bind_param('ss', $new, $row['PosName']);
        $ins->execute();
    }

    // Remap staff_positions
    foreach ($map as $old => $new) {
        $upd = $db->prepare('UPDATE IGNORE staff_positions SET PosID=? WHERE PosID=?');
        $upd->bind_param('ss', $new, $old);
        $upd->execute();
    }

    // Optionally keep legacy rows or delete them; here we delete the old ones once remapped
    foreach ($map as $old => $_) {
        $db->query("DELETE FROM positions WHERE PosID='".$db->real_escape_string($old)."' LIMIT 1");
    }

    $db->commit();
    echo "Migration complete. Updated PosIDs: ".json_encode($map, JSON_PRETTY_PRINT)."\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "Migration failed: ".$e->getMessage()."\n");
    exit(1);
}



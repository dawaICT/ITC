<?php
/**
 * Remove legacy WUC-prefixed staff IDs from the portal database.
 *
 * For each WUC### account:
 *   - If ITC### already exists, re-point FK references to ITC### and delete the
 *     duplicate WUC identity rows (staff, credentials, positions, etc.).
 *   - Otherwise rename WUC### -> ITC### everywhere.
 *
 * Dry run by default. Pass --commit to apply.
 *
 *   php scripts/remove_wuc_staff_ids.php
 *   php scripts/remove_wuc_staff_ids.php --commit
 */

require_once __DIR__ . '/../db/connect.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script from the command line.\n";
    exit(1);
}

$COMMIT = in_array('--commit', $argv, true);

function out(string $message = ''): void
{
    echo $message . "\n";
}

/** Tables where WUC### rows should be deleted when ITC### already exists. */
$identityTables = [
    ['staff', 'staff_id'],
    ['user_credentials', 'staff_id'],
    ['staff_positions', 'staff_id'],
    ['access_right', 'staff_id'],
    ['users', 'staff_id'],
    ['user_profiles', 'staff_id'],
];

/** Collect every WUC### staff_id currently in staff. */
$wucIds = [];
$res = $db->query("SELECT staff_id FROM staff WHERE staff_id REGEXP '^WUC[0-9]{3}$' ORDER BY staff_id");
while ($row = $res->fetch_assoc()) {
    $wucIds[] = $row['staff_id'];
}

if (!$wucIds) {
    out('No WUC-prefixed staff IDs found.');
    exit(0);
}

out('WUC staff IDs to remove: ' . implode(', ', $wucIds));
out('Mode: ' . ($COMMIT ? 'COMMIT' : 'DRY RUN'));
out(str_repeat('-', 72));

/** All text columns that might store a staff id or username. */
$refColumns = [];
$cols = $db->query(
    "SELECT TABLE_NAME, COLUMN_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')
     ORDER BY TABLE_NAME, COLUMN_NAME"
);
while ($col = $cols->fetch_assoc()) {
    $refColumns[] = [(string)$col['TABLE_NAME'], (string)$col['COLUMN_NAME']];
}

$identityLookup = [];
foreach ($identityTables as [$table, $column]) {
    $identityLookup["{$table}.{$column}"] = true;
}

$db->begin_transaction();
try {
    foreach ($wucIds as $wucId) {
        $itcId = 'ITC' . substr($wucId, 3);
        $itcExists = false;
        $check = $db->prepare('SELECT 1 FROM staff WHERE staff_id = ? LIMIT 1');
        $check->bind_param('s', $itcId);
        $check->execute();
        $check->store_result();
        $itcExists = $check->num_rows > 0;
        $check->close();

        out(sprintf('%s -> %s (%s)', $wucId, $itcId, $itcExists ? 'merge into existing ITC' : 'rename'));

        foreach ($refColumns as [$table, $column]) {
            $key = "{$table}.{$column}";
            $tableEsc = str_replace('`', '``', $table);
            $columnEsc = str_replace('`', '``', $column);

            if ($itcExists && isset($identityLookup[$key])) {
                $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM `{$tableEsc}` WHERE `{$columnEsc}` = ?");
                $countStmt->bind_param('s', $wucId);
                $countStmt->execute();
                $count = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
                $countStmt->close();
                if ($count === 0) {
                    continue;
                }
                out("  DELETE {$count} from {$table}.{$column} where = {$wucId}");
                if ($COMMIT) {
                    $del = $db->prepare("DELETE FROM `{$tableEsc}` WHERE `{$columnEsc}` = ?");
                    $del->bind_param('s', $wucId);
                    $del->execute();
                    $del->close();
                }
                continue;
            }

            $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM `{$tableEsc}` WHERE `{$columnEsc}` = ?");
            $countStmt->bind_param('s', $wucId);
            $countStmt->execute();
            $count = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $countStmt->close();
            if ($count === 0) {
                continue;
            }

            // Skip if renaming would collide with an existing ITC row in the same table.
            if (!$itcExists) {
                out("  UPDATE {$count} in {$table}.{$column}: {$wucId} -> {$itcId}");
                if ($COMMIT) {
                    $upd = $db->prepare("UPDATE `{$tableEsc}` SET `{$columnEsc}` = ? WHERE `{$columnEsc}` = ?");
                    $upd->bind_param('ss', $itcId, $wucId);
                    $upd->execute();
                    $upd->close();
                }
                continue;
            }

            // ITC exists: re-point references unless the target row already exists (users.username).
            if ($table === 'users' && $column === 'username') {
                out("  DELETE {$count} duplicate username {$wucId} from users");
                if ($COMMIT) {
                    $del = $db->prepare("DELETE FROM users WHERE username = ?");
                    $del->bind_param('s', $wucId);
                    $del->execute();
                    $del->close();
                }
                continue;
            }

            out("  UPDATE {$count} in {$table}.{$column}: {$wucId} -> {$itcId}");
            if ($COMMIT) {
                $upd = $db->prepare("UPDATE `{$tableEsc}` SET `{$columnEsc}` = ? WHERE `{$columnEsc}` = ?");
                $upd->bind_param('ss', $itcId, $wucId);
                $upd->execute();
                $upd->close();
            }
        }

        // Lowercase email aliases on staff (wuc901@example.com).
        $oldEmail = strtolower($wucId) . '@example.com';
        $newEmail = strtolower($itcId) . '@example.com';
        $emailStmt = $db->prepare('SELECT COUNT(*) AS c FROM staff WHERE email = ?');
        $emailStmt->bind_param('s', $oldEmail);
        $emailStmt->execute();
        $emailCount = (int)($emailStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $emailStmt->close();
        if ($emailCount > 0) {
            if ($itcExists) {
                out("  DELETE staff email {$oldEmail} (ITC account already owns {$newEmail})");
                if ($COMMIT) {
                    $del = $db->prepare('DELETE FROM staff WHERE staff_id = ?');
                    $del->bind_param('s', $wucId);
                    $del->execute();
                    $del->close();
                }
            } else {
                out("  UPDATE staff email {$oldEmail} -> {$newEmail}");
                if ($COMMIT) {
                    $upd = $db->prepare('UPDATE staff SET email = ? WHERE email = ?');
                    $upd->bind_param('ss', $newEmail, $oldEmail);
                    $upd->execute();
                    $upd->close();
                }
            }
        }
    }

    if ($COMMIT) {
        $db->commit();
        out(str_repeat('-', 72));
        out('Migration committed.');
    } else {
        $db->rollback();
        out(str_repeat('-', 72));
        out('DRY RUN only. Re-run with --commit to apply.');
    }
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

// Verify no WUC staff IDs remain.
$remaining = (int)($db->query("SELECT COUNT(*) AS c FROM staff WHERE staff_id LIKE 'WUC%'")->fetch_assoc()['c'] ?? 0);
if ($COMMIT) {
    out('Remaining WUC staff rows: ' . $remaining);
    exit($remaining === 0 ? 0 : 1);
}

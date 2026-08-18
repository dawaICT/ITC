<?php
declare(strict_types=1);

/**
 * Detach the Skills and Enterprise Portal from WUCPortal.
 *
 * Historical memberships and enterprise records are intentionally preserved.
 * The standalone application owns new accounts and listings in its own database.
 */

require_once dirname(__DIR__) . '/db/connect.php';

$portalCode = 'enterprise';
$inactive = 'inactive';
$stmt = $db->prepare('UPDATE portals SET status = ? WHERE portal_code = ?');
$stmt->bind_param('ss', $inactive, $portalCode);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

echo "Enterprise portal detached from WUCPortal ({$affected} row changed)." . PHP_EOL;

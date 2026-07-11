<?php
declare(strict_types=1);
/**
 * Idempotent installer for fleet-management extensions:
 *  - cost fields (fuel total_cost, maintenance cost, vehicle acquisition)
 *  - policy register + audit log (Compliance & Policy module)
 * Run: C:\xampp\php\php.exe transport\scripts\install_fleet_management.php
 */
define('IS_SCRIPT', true);
require_once __DIR__ . '/../../db/connect.php';

function fm_col_exists(mysqli $db, string $table, string $col): bool
{
    $stmt = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}
function fm_add_col(mysqli $db, string $table, string $col, string $def): void
{
    if (fm_col_exists($db, $table, $col)) { echo "  skip {$table}.{$col} (exists)\n"; return; }
    if ($db->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}")) {
        echo "  OK   added {$table}.{$col}\n";
    } else {
        echo "  FAIL {$table}.{$col}: " . $db->error . "\n";
    }
}
function fm_run(mysqli $db, string $sql, string $label): void
{
    echo ($db->query($sql) ? "  OK   {$label}\n" : "  FAIL {$label}: " . $db->error . "\n");
}

echo "Fleet-management installer\n==========================\n";

// 1. Cost & lifecycle fields
fm_add_col($db, 'transport_fuel_logs', 'unit_cost', 'DECIMAL(10,2) NULL AFTER amount_added');
fm_add_col($db, 'transport_fuel_logs', 'total_cost', 'DECIMAL(10,2) NULL AFTER unit_cost');
fm_add_col($db, 'transport_maintenance_logs', 'cost', 'DECIMAL(10,2) NULL AFTER status');
fm_add_col($db, 'transport_vehicles', 'acquisition_date', 'DATE NULL');
fm_add_col($db, 'transport_vehicles', 'acquisition_cost', 'DECIMAL(12,2) NULL');
fm_add_col($db, 'transport_vehicles', 'replacement_mileage', 'INT NULL');

// 2. Policy register
fm_run($db, "
    CREATE TABLE IF NOT EXISTS transport_policies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        category ENUM('vehicle_use','maintenance','safety','driver','fuel','disposal','compliance','other') NOT NULL DEFAULT 'other',
        owner VARCHAR(120) NULL,
        description TEXT NULL,
        effective_date DATE NULL,
        review_date DATE NULL,
        document_path VARCHAR(255) NULL,
        status ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_review (review_date), KEY idx_cat (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'transport_policies');

// 3. Lightweight audit log
fm_run($db, "
    CREATE TABLE IF NOT EXISTS transport_audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        entity VARCHAR(60) NOT NULL,
        entity_id INT NULL,
        action VARCHAR(60) NOT NULL,
        actor VARCHAR(80) NULL,
        notes VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_entity (entity, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'transport_audit_log');

// 4. Seed a few baseline policies (only if table empty)
$count = (int)$db->query("SELECT COUNT(*) c FROM transport_policies")->fetch_assoc()['c'];
if ($count === 0) {
    $seed = [
        ['Vehicle Use & Authorisation Policy', 'vehicle_use', 'Defines who may operate fleet/training vehicles and acceptable use.'],
        ['Preventive Maintenance Policy', 'maintenance', 'Service intervals tied to mileage/hours; pre-use checks mandatory before dispatch.'],
        ['Defensive Driving & Safety Policy', 'safety', 'TEVETA defensive-driving standards, speed limits and incident reporting duties.'],
        ['Fuel Management Policy', 'fuel', 'Fuel logging per vehicle, anomaly review and misuse handling.'],
        ['Vehicle Disposal & Replacement Policy', 'disposal', 'Lifecycle review and end-of-life disposal criteria.'],
        ['RTSA/TEVETA Compliance Policy', 'compliance', 'Licensing, COF/insurance renewals and audit recordkeeping.'],
    ];
    $ins = $db->prepare("INSERT INTO transport_policies (title, category, description, effective_date, review_date, status) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL 1 YEAR),'active')");
    foreach ($seed as $s) {
        $ins->bind_param('sss', $s[0], $s[1], $s[2]);
        $ins->execute();
    }
    $ins->close();
    echo "  OK   seeded " . count($seed) . " baseline policies\n";
} else {
    echo "  skip policy seed ({$count} already present)\n";
}

echo "\nDone.\n";

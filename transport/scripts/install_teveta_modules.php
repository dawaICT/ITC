<?php
declare(strict_types=1);
/**
 * Idempotent installer for the TEVETA curriculum, assessment-support and
 * trainee-licensing extensions to the Transport module.
 *
 * Run:  C:\xampp\php\php.exe transport\scripts\install_teveta_modules.php
 * Safe to run repeatedly.
 */
define('IS_SCRIPT', true);
require_once __DIR__ . '/../../db/connect.php';

function tev_run(mysqli $db, string $sql, string $label): void
{
    if ($db->query($sql)) {
        echo "  OK   {$label}\n";
    } else {
        echo "  FAIL {$label}: " . $db->error . "\n";
    }
}

echo "TEVETA transport module installer\n=================================\n";

// 1. TEVETA learning-outcome catalogue ---------------------------------------
tev_run($db, "
    CREATE TABLE IF NOT EXISTS transport_curriculum_outcomes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        title VARCHAR(160) NOT NULL,
        description TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'transport_curriculum_outcomes');

// 2. Curriculum modules (syllabus per program) -------------------------------
tev_run($db, "
    CREATE TABLE IF NOT EXISTS transport_curriculum_modules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_id INT NOT NULL,
        outcome_id INT NULL,
        title VARCHAR(200) NOT NULL,
        delivery_type ENUM('theory','practical','assessment') NOT NULL DEFAULT 'theory',
        contact_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
        sequence INT NOT NULL DEFAULT 1,
        version VARCHAR(20) NOT NULL DEFAULT '1.0',
        status ENUM('active','archived') NOT NULL DEFAULT 'active',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_prog (program_id),
        KEY idx_outcome (outcome_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'transport_curriculum_modules');

// 3. Trainee licences / medical clearances (RTSA lifecycle) ------------------
tev_run($db, "
    CREATE TABLE IF NOT EXISTS transport_trainee_licences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainee_id INT NOT NULL,
        licence_type ENUM('medical','provisional','full','psv','forklift','other') NOT NULL DEFAULT 'provisional',
        licence_number VARCHAR(80) NULL,
        issue_date DATE NULL,
        expiry_date DATE NULL,
        status ENUM('valid','expired','pending','revoked') NOT NULL DEFAULT 'valid',
        document_path VARCHAR(255) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_trainee (trainee_id),
        KEY idx_expiry (expiry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'transport_trainee_licences');

// 4. Seed TEVETA outcomes ----------------------------------------------------
$outcomes = [
    ['AL', 'Adult-learner theory & teaching methodology', 'Handle adult learners, apply appropriate pedagogy, assess competencies and document skills acquired.'],
    ['DD', 'Defensive driving', 'Roadway scanning, reaction/stopping distances, speed management, hazard prediction, night driving and crash prevention.'],
    ['FA', 'First aid', 'Administer and teach basic first aid for road incidents.'],
    ['TL', 'Traffic law & Traffic Act instruction', 'Interpret traffic laws, safety regulations and the Traffic Act as it relates to driving-school operations.'],
    ['VS', 'Vehicle systems & operation', 'Identify vehicle components and teach correct operation and handling techniques.'],
    ['BE', 'Business ethics & entrepreneurship', 'Adhere to business ethics and develop entrepreneurial skills for the transport sector.'],
];
$stmt = $db->prepare("INSERT INTO transport_curriculum_outcomes (code, title, description) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description)");
foreach ($outcomes as $o) {
    $stmt->bind_param('sss', $o[0], $o[1], $o[2]);
    $stmt->execute();
}
$stmt->close();
echo "  OK   seeded " . count($outcomes) . " TEVETA outcomes\n";

// Map outcome code -> id
$outcomeId = [];
$res = $db->query("SELECT id, code FROM transport_curriculum_outcomes");
while ($r = $res->fetch_assoc()) {
    $outcomeId[$r['code']] = (int)$r['id'];
}

// 5. Seed module templates per program (only if a program has no modules) ----
function tev_seed_modules(mysqli $db, int $programId, array $modules, array $outcomeId): int
{
    $check = $db->prepare("SELECT COUNT(*) c FROM transport_curriculum_modules WHERE program_id = ?");
    $check->bind_param('i', $programId);
    $check->execute();
    $has = (int)($check->get_result()->fetch_assoc()['c'] ?? 0);
    $check->close();
    if ($has > 0) {
        return 0;
    }

    $seq = 1;
    foreach ($modules as $m) {
        $oidVal = $outcomeId[$m[0]] ?? null;   // may be null
        $title  = (string)$m[1];
        $type   = (string)$m[2];
        $hours  = (float)$m[3];
        $ins = $db->prepare("INSERT INTO transport_curriculum_modules (program_id, outcome_id, title, delivery_type, contact_hours, sequence) VALUES (?,?,?,?,?,?)");
        // types: program_id i, outcome_id i, title s, delivery_type s, contact_hours d, sequence i
        $ins->bind_param('iissdi', $programId, $oidVal, $title, $type, $hours, $seq);
        $ins->execute();
        $ins->close();
        $seq++;
    }
    return count($modules);
}

// Template sets keyed by keyword
$instructorTpl = [
    ['AL','Adult-learner theory & teaching methodology','theory',8],
    ['DD','Defensive driving principles','theory',6],
    ['DD','Defensive driving practical','practical',8],
    ['FA','First aid for road incidents','theory',4],
    ['TL','Traffic law & the Traffic Act','theory',6],
    ['VS','Vehicle systems & operation','theory',6],
    ['BE','Business ethics & entrepreneurship','theory',4],
    ['AL','Final teaching assessment','assessment',2],
];
$motorcycleTpl = [
    ['VS','Pre-ride inspection & controls','theory',2],
    ['TL','Traffic law for riders','theory',1],
    ['DD','Hazard avoidance theory','theory',2],
    ['DD','Clutch, braking & cornering practical','practical',6],
    ['DD','Road riding & hazard avoidance practical','practical',4],
    ['FA','Basic first aid','theory',1],
];
$forkliftTpl = [
    ['VS','Equipment stability & load handling','theory',2],
    ['DD','Site-specific hazards & safe operation','theory',1.5],
    ['VS','Hands-on operation & evaluation','practical',1],
    ['FA','Workplace first aid basics','theory',0.5],
];
$truckTpl = [
    ['VS','Vehicle characteristics & preventive checks','theory',3],
    ['TL','Company driving policy & traffic law','theory',2],
    ['DD','Defensive driving techniques','theory',3],
    ['DD','Backing & manoeuvring practical','practical',6],
    ['FA','First aid & incident response','theory',1],
];
$defaultTpl = [
    ['TL','Traffic law & regulations','theory',3],
    ['DD','Defensive driving','theory',3],
    ['DD','Practical driving','practical',8],
    ['VS','Vehicle systems & operation','theory',2],
    ['FA','First aid basics','theory',1],
];

$programs = $db->query("SELECT id, program_code, program_name, program_type, license_class FROM transport_programs");
$seeded = 0; $skipped = 0;
while ($p = $programs->fetch_assoc()) {
    $hay = strtolower($p['program_name'] . ' ' . $p['program_type'] . ' ' . ($p['license_class'] ?? '') . ' ' . $p['program_code']);
    if (strpos($hay, 'instructor') !== false) {
        $tpl = $instructorTpl;
    } elseif (preg_match('/motor|rider|bike|class a/', $hay)) {
        $tpl = $motorcycleTpl;
    } elseif (strpos($hay, 'forklift') !== false) {
        $tpl = $forkliftTpl;
    } elseif (preg_match('/truck|class c|commercial|heavy|psv|class ce/', $hay)) {
        $tpl = $truckTpl;
    } else {
        $tpl = $defaultTpl;
    }
    $n = tev_seed_modules($db, (int)$p['id'], $tpl, $outcomeId);
    if ($n > 0) { $seeded++; } else { $skipped++; }
}
echo "  OK   seeded modules for {$seeded} programs ({$skipped} already had modules)\n";

echo "\nDone.\n";

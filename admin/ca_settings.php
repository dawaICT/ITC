<?php
$page_title = "CA Settings";
require "includes/admin.php";
require "includes/header.php";

// Link the admin dashboard stylesheet
// Ensure settings table exists. The DML-only app user cannot run DDL, so this is
// a best-effort guard: it no-ops when the table already exists and never throws.
if (isset($db) && $db instanceof mysqli) {
    wuc_ensure_tables($db, ["CREATE TABLE IF NOT EXISTS portal_settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"]);
}

// Helper Functions
$getSetting = function(mysqli $db, string $key, string $default = '1'): string {
    $val = $default;
    if ($st = @$db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = ? LIMIT 1")) {
        $st->bind_param('s', $key);
        if ($st->execute()) { $res = $st->get_result(); if ($res && $res->num_rows) { $val = (string)$res->fetch_assoc()['setting_value']; } }
        $st->close();
    }
    return $val;
};

$setSetting = function(mysqli $db, string $key, string $value): void {
    if ($st = @$db->prepare("INSERT INTO portal_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")) {
        $st->bind_param('ss', $key, $value);
        $st->execute();
        $st->close();
    }
};

// --- DEFINING THE STRUCTURE ---
$assessmentStructures = [
    'Common' => [
        'label' => 'General Assessments (All Modes)',
        'color' => 'bg-primary', // Purple
        'items' => ['CA1', 'CA2']
    ],
    'Semester' => [
        'label' => 'Semester Mode Only',
        'color' => 'bg-info', // Blue
        'items' => ['Test'] 
    ],
    'Term' => [
        'label' => 'Term Mode Only',
        'color' => 'bg-success', // Green
        'items' => ['Test1', 'Test2', 'Exam']
    ]
];

// Flatten valid keys for processing
$validComponents = [];
foreach ($assessmentStructures as $struct) {
    $validComponents = array_merge($validComponents, $struct['items']);
}

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manual = isset($_POST['ca_upload_manual_enabled']) ? '1' : '0';
    $csv = isset($_POST['ca_upload_csv_enabled']) ? '1' : '0';
    $enforce = isset($_POST['enforce_ca_payment']) ? '1' : '0';
    $minPercent = isset($_POST['min_ca_paid_percent']) ? max(0, min(100, (float)$_POST['min_ca_paid_percent'])) : 50;
    $currentAy = trim($_POST['current_academic_year'] ?? '');
    $currentSem = trim($_POST['current_semester'] ?? '');
    $enforceTerm = isset($_POST['enforce_current_term']) ? '1' : '0';

    foreach ($validComponents as $comp) {
        $setSetting($db, "ca_{$comp}_enabled", isset($_POST["ca_{$comp}_enabled"]) ? '1' : '0');
        $start = trim($_POST["ca_{$comp}_start"] ?? '');
        $dur = (string)max(1, (int)($_POST["ca_{$comp}_duration_days"] ?? 5));
        
        $setSetting($db, "ca_{$comp}_start", $start);
        $setSetting($db, "ca_{$comp}_duration_days", $dur);
    }

    $setSetting($db, 'ca_upload_manual_enabled', $manual);
    $setSetting($db, 'ca_upload_csv_enabled', $csv);
    $setSetting($db, 'enforce_ca_payment', $enforce);
    $setSetting($db, 'min_ca_paid_percent', (string)$minPercent);
    if ($currentAy !== '') { $setSetting($db, 'current_academic_year', $currentAy); }
    if ($currentSem !== '') { $setSetting($db, 'current_semester', $currentSem); }
    $setSetting($db, 'enforce_current_term', $enforceTerm);
    $saved = true;
}

// Load Settings
$manualCurrent = $getSetting($db, 'ca_upload_manual_enabled', '1');
$csvCurrent = $getSetting($db, 'ca_upload_csv_enabled', '1');
$currentAyCur = $getSetting($db, 'current_academic_year', date('Y') . '/' . (date('Y')+1));
$currentSemCur = $getSetting($db, 'current_semester', ((int)date('n')<=6 ? '1':'2'));
$enforceCaCur = $getSetting($db, 'enforce_ca_payment', '1');
$minCaPercentCur = $getSetting($db, 'min_ca_paid_percent', '50');
$enforceTermCur = $getSetting($db, 'enforce_current_term', '0');

// Load dynamic defaults
$caDefaults = [];
foreach ($validComponents as $comp) {
    $caDefaults[$comp] = [
        'enabled' => $getSetting($db, "ca_{$comp}_enabled", '1'),
        'start' => $getSetting($db, "ca_{$comp}_start", ''),
        'dur' => $getSetting($db, "ca_{$comp}_duration_days", '5'),
    ];
}
?>

<style>
/* --- Enhanced Styles --- */
:root {
    --primary-color: #6f42c1;
    --primary-light: #8c68cd;
    --bg-page: #f4f6f9;
    --card-border: #e9ecef;
    --text-dark: #2c3e50;
    --text-muted: #6c757d;
}

body {
    background-color: var(--bg-page);
    color: var(--text-dark);
}

.dashboard-header {
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #dee2e6;
}

.dashboard-title {
    font-weight: 700;
    color: var(--primary-color);
}

/* Card Styling */
.settings-card {
    background: #fff;
    border: 1px solid var(--card-border);
    border-radius: 0.75rem;
    box-shadow: 0 2px 12px rgba(0,0,0,0.03);
    margin-bottom: 2rem;
    overflow: hidden;
}

.settings-card-header {
    background: #fff;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--card-border);
    display: flex;
    align-items: center;
}

.settings-card-header h5 {
    margin: 0;
    font-weight: 600;
    color: var(--primary-color);
}

/* Group Labels (Separators) */
.group-section {
    position: relative;
    padding: 1.5rem;
}

.group-label {
    display: inline-block;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-weight: 700;
    color: var(--text-muted);
    background: #f8f9fa;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    margin-bottom: 1rem;
    border: 1px solid var(--card-border);
}

/* Window/Item Cards */
.window-card {
    background: #fff;
    border: 1px solid var(--card-border);
    border-radius: 0.5rem;
    padding: 1.25rem;
    margin-bottom: 1rem;
    transition: all 0.2s ease;
}

.window-card:hover {
    border-color: var(--primary-light);
    box-shadow: 0 4px 12px rgba(111, 66, 193, 0.08);
}

/* Dim card when disabled */
.window-card.disabled-state {
    background-color: #fafafa;
    opacity: 0.8;
}

.icon-circle {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
    color: white;
    margin-right: 1rem;
}

/* Form Polish */
.form-label {
    font-size: 0.85rem;
    font-weight: 500;
    margin-bottom: 0.4rem;
}

.input-group-text {
    background-color: #f8f9fa;
    border-color: #ced4da;
    color: var(--text-muted);
}

.form-control:focus {
    border-color: var(--primary-light);
    box-shadow: 0 0 0 0.2rem rgba(111, 66, 193, 0.15);
}

/* Toggle Switch Polish */
.form-check-input {
    cursor: pointer;
    width: 3em; 
    height: 1.5em;
}
.form-check-input:checked {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}
</style>

<div class="container-fluid px-4 pt-4">
    <div class="dashboard-header">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="dashboard-title"><i class="fas fa-cogs me-2"></i>CA Settings</h2>
                <p class="text-muted mb-0">Configure academic sessions and assessment windows.</p>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-10">
            <form method="post" id="settingsForm">
                
                <div class="settings-card">
                    <div class="settings-card-header">
                        <i class="fas fa-sliders-h me-2 text-primary"></i>
                        <h5>General Configuration</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="p-3 bg-light rounded-3 h-100 border">
                                    <h6 class="fw-bold text-dark mb-3">Upload Methods</h6>
                                    
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div>
                                            <label class="form-check-label fw-bold" for="manualToggle">Manual Entry</label>
                                            <div class="small text-muted">Type marks directly in grid</div>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="manualToggle" name="ca_upload_manual_enabled" <?php echo ($manualCurrent === '1') ? 'checked' : ''; ?>>
                                        </div>
                                    </div>
                                    <hr class="my-2">
                                    <div class="d-flex align-items-center justify-content-between mt-3">
                                        <div>
                                            <label class="form-check-label fw-bold" for="csvToggle">CSV Upload</label>
                                            <div class="small text-muted">Bulk import via Excel/CSV</div>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="csvToggle" name="ca_upload_csv_enabled" <?php echo ($csvCurrent === '1') ? 'checked' : ''; ?>>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="p-3 bg-light rounded-3 h-100 border">
                                    <h6 class="fw-bold text-dark mb-3">Academic Period</h6>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label text-muted small text-uppercase fw-bold">Academic Year</label>
                                            <input type="text" class="form-control" name="current_academic_year" value="<?php echo htmlspecialchars($currentAyCur); ?>" placeholder="e.g. 2025/2026">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label text-muted small text-uppercase fw-bold">Current Session</label>
                                            <select class="form-select" name="current_semester">
                                                <option value="1" <?php echo ($currentSemCur==='1')?'selected':''; ?>>First (Sem 1 / Term 1)</option>
                                                <option value="2" <?php echo ($currentSemCur==='2')?'selected':''; ?>>Second (Sem 2 / Term 2)</option>
                                                <option value="3" <?php echo ($currentSemCur==='3')?'selected':''; ?>>Third (Term 3 Only)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-4 mt-1">
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded-3 h-100 border">
                                        <h6 class="fw-bold text-dark mb-3">CA Payment Enforcement</h6>
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <div>
                                                <label class="form-check-label fw-bold" for="enforcePayToggle">Require CA Payment</label>
                                                <div class="small text-muted">Block CA access until fees are paid</div>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="enforcePayToggle" name="enforce_ca_payment" <?php echo ($enforceCaCur === '1') ? 'checked' : ''; ?>>
                                            </div>
                                        </div>
                                        <hr class="my-2">
                                        <label class="form-label text-muted small text-uppercase fw-bold mt-2">Minimum Paid Percentage</label>
                                        <div class="input-group">
                                            <input type="number" min="0" max="100" step="1" class="form-control" name="min_ca_paid_percent" value="<?php echo htmlspecialchars($minCaPercentCur); ?>">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded-3 h-100 border">
                                        <h6 class="fw-bold text-dark mb-3">Term Enforcement</h6>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <label class="form-check-label fw-bold" for="enforceTermToggle">Enforce Current Term</label>
                                                <div class="small text-muted">Restrict entry to the active session/term selected above</div>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="enforceTermToggle" name="enforce_current_term" <?php echo ($enforceTermCur === '1') ? 'checked' : ''; ?>>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="settings-card-header">
                        <i class="fas fa-calendar-alt me-2 text-primary"></i>
                        <h5>Assessment Windows</h5>
                    </div>
                    <div class="card-body p-0">
                        
                        <?php foreach ($assessmentStructures as $groupKey => $group): ?>
                            
                            <div class="group-section">
                                <div class="group-label">
                                    <i class="fas fa-layer-group me-1"></i> <?php echo $group['label']; ?>
                                </div>
                                
                                <?php foreach ($group['items'] as $comp): 
                                    $cfg = $caDefaults[$comp]; 
                                    $isEnabled = $cfg['enabled'] === '1';
                                    
                                    // Dynamic descriptions
                                    $desc = "General Assessment";
                                    if(strpos($group['label'], 'Semester') !== false) $desc = "Semester Mode Only";
                                    if(strpos($group['label'], 'Term') !== false) $desc = "Term Mode Only";
                                ?>
                                <div class="window-card <?php echo $isEnabled ? '' : 'disabled-state'; ?>" id="card_<?php echo $comp; ?>">
                                    <div class="d-flex align-items-center justify-content-between mb-4">
                                        <div class="d-flex align-items-center">
                                            <div class="icon-circle <?php echo $group['color']; ?> shadow-sm">
                                                <?php echo substr($comp, 0, 2); ?>
                                            </div>
                                            <div>
                                                <h6 class="mb-0 fw-bold text-dark" style="font-size: 1.1rem;"><?php echo $comp; ?></h6>
                                                <small class="text-muted"><?php echo $desc; ?></small>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" 
                                                   id="toggle_<?php echo $comp; ?>" 
                                                   name="ca_<?php echo $comp; ?>_enabled" 
                                                   <?php echo $isEnabled ? 'checked' : ''; ?>
                                                   onchange="toggleCardState('<?php echo $comp; ?>')">
                                        </div>
                                    </div>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted small text-uppercase fw-bold">Start Date & Time</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-clock text-muted"></i></span>
                                                <input type="text" class="form-control border-start-0 ps-0" name="ca_<?php echo $comp; ?>_start" 
                                                       placeholder="YYYY-MM-DD HH:MM:SS" 
                                                       value="<?php echo htmlspecialchars($cfg['start']); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-muted small text-uppercase fw-bold">Duration</label>
                                            <div class="input-group">
                                                <input type="number" min="1" class="form-control border-end-0" name="ca_<?php echo $comp; ?>_duration_days" value="<?php echo htmlspecialchars($cfg['dur']); ?>">
                                                <span class="input-group-text bg-white">Days</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if($groupKey !== array_key_last($assessmentStructures)): ?>
                                <hr class="m-0" style="opacity: 0.1;">
                            <?php endif; ?>

                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="d-flex justify-content-end mb-5">
                    <button type="submit" class="btn btn-primary btn-lg shadow px-5 rounded-pill fw-bold">
                        <i class="fas fa-save me-2"></i>Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Simple script to visually dim the card when toggle is off
function toggleCardState(comp) {
    const checkbox = document.getElementById('toggle_' + comp);
    const card = document.getElementById('card_' + comp);
    if (checkbox.checked) {
        card.classList.remove('disabled-state');
    } else {
        card.classList.add('disabled-state');
    }
}

<?php if ($saved): ?>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        title: 'Configuration Saved',
        text: 'The assessment windows have been updated successfully.',
        icon: 'success',
        confirmButtonColor: '#6f42c1',
        timer: 3000
    });
});
<?php endif; ?>
</script>

<?php require "includes/footer.php"; ?>

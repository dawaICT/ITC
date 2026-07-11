<?php
// START SESSION IF NOT STARTED (Crucial for CSRF)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
elearning_require_role(['systems_admin','lecturer','head_of_department']);

require_once __DIR__ . '/../../db/connect.php';

// GENERATE CSRF TOKEN
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$err = null; $ok = null;

// --- BACKEND ACTION HANDLERS ---

// Helper function to verify CSRF
function verify_csrf() {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        throw new Exception("Security Token Mismatch (CSRF). Please refresh and try again.");
    }
}

function elearning_content_file_paths(mysqli $db, array $contentIds): array {
    $contentIds = array_values(array_filter(array_map('intval', $contentIds), fn($id) => $id > 0));
    if (empty($contentIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
    $types = str_repeat('i', count($contentIds));
    $stmt = $db->prepare("SELECT file_path FROM el_content_versions WHERE content_id IN ($placeholders) AND file_path IS NOT NULL AND file_path <> ''");
    if (!$stmt) {
        throw new Exception('Failed to prepare content file lookup.');
    }

    $stmt->bind_param($types, ...$contentIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $paths = [];
    while ($row = $res->fetch_assoc()) {
        $paths[] = $row['file_path'];
    }
    $stmt->close();

    return array_values(array_unique($paths));
}

function elearning_delete_content_rows(mysqli $db, array $contentIds): array {
    $contentIds = array_values(array_filter(array_map('intval', $contentIds), fn($id) => $id > 0));
    if (empty($contentIds)) {
        return ['deleted' => 0, 'files' => []];
    }

    $filesToDelete = elearning_content_file_paths($db, $contentIds);
    $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
    $types = str_repeat('i', count($contentIds));

    $stmt = $db->prepare("DELETE FROM el_content_versions WHERE content_id IN ($placeholders)");
    if (!$stmt) {
        throw new Exception('Failed to prepare content version cleanup.');
    }
    $stmt->bind_param($types, ...$contentIds);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("DELETE FROM el_contents WHERE id IN ($placeholders)");
    if (!$stmt) {
        throw new Exception('Failed to prepare content cleanup.');
    }
    $stmt->bind_param($types, ...$contentIds);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    return ['deleted' => max(0, $deleted), 'files' => $filesToDelete];
}

function elearning_remove_files(array $relativePaths): void {
    $root = realpath(__DIR__ . '/../../');
    if ($root === false) {
        return;
    }

    foreach ($relativePaths as $relativePath) {
        $relativePath = ltrim(str_replace(['\\', "\0"], ['/', ''], (string)$relativePath), '/');
        if ($relativePath === '') {
            continue;
        }

        $fullPath = realpath($root . DIRECTORY_SEPARATOR . $relativePath);
        if ($fullPath && str_starts_with($fullPath, $root) && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

function elearning_format_date($value, string $format, string $fallback = 'N/A'): string {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : $fallback;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf(); // Check security first
        $action = $_POST['action'] ?? '';

        // 1. DELETE CONTENT (And physical file)
        if ($action === 'delete_content') {
            $contentId = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
            if ($contentId > 0) {
                $db->begin_transaction();
                try {
                    $cleanup = elearning_delete_content_rows($db, [$contentId]);
                    $db->commit();
                    elearning_remove_files($cleanup['files']);
                    $ok = $cleanup['deleted'] > 0
                        ? 'Content and associated files deleted successfully.'
                        : 'Content item was not found.';
                } catch (Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
            }
        }

        // 2. DELETE MODULE (And cascade delete content)
        elseif ($action === 'delete_module') {
            $moduleId = isset($_POST['module_id']) ? (int)$_POST['module_id'] : 0;
            if ($moduleId > 0) {
                $db->begin_transaction();
                try {
                    $stmt = $db->prepare("SELECT id FROM el_contents WHERE module_id = ?");
                    if (!$stmt) {
                        throw new Exception('Failed to prepare module content lookup.');
                    }
                    $stmt->bind_param('i', $moduleId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $contentIds = [];
                    while($row = $res->fetch_assoc()) {
                        $contentIds[] = (int)$row['id'];
                    }
                    $stmt->close();

                    $cleanup = elearning_delete_content_rows($db, $contentIds);

                    // Delete Module Row
                    $stmt = $db->prepare("DELETE FROM el_course_modules WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception('Failed to prepare module delete.');
                    }
                    $stmt->bind_param('i', $moduleId);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();

                    // Clean up files after successful DB commit
                    elearning_remove_files($cleanup['files']);

                    $ok = 'Module and all associated content deleted successfully.';
                } catch (Exception $e) {
                    $db->rollback();
                    $err = 'Failed to delete module: ' . $e->getMessage();
                }
            }
        }

        // 3. CLEANUP SESSIONS
        elseif ($action === 'cleanup_old_sessions') {
            $daysOld = isset($_POST['days_old']) ? (int)$_POST['days_old'] : 90;
            $stmt = $db->prepare("DELETE FROM el_live_sessions WHERE start_time < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->bind_param('i', $daysOld);
            if ($stmt->execute()) {
                $ok = "Cleaned up {$stmt->affected_rows} old session(s).";
            } else {
                $err = 'Failed to cleanup sessions: ' . $stmt->error;
            }
            $stmt->close();
        }

        // 4. CLEANUP ORPHANED CONTENT
        elseif ($action === 'cleanup_orphaned_content') {
            $orphanIds = [];
            $res = $db->query("SELECT c.id FROM el_contents c LEFT JOIN el_course_modules m ON c.module_id = m.id WHERE m.id IS NULL");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $orphanIds[] = (int)$row['id'];
                }
                $res->free();
            }

            if (empty($orphanIds)) {
                $ok = 'No orphaned content items were found.';
            } else {
                $db->begin_transaction();
                try {
                    $cleanup = elearning_delete_content_rows($db, $orphanIds);
                    $db->commit();
                    elearning_remove_files($cleanup['files']);
                    $ok = "Cleaned up {$cleanup['deleted']} orphaned content item(s).";
                } catch (Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
            }
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

// --- DATA FETCHING ---

$stats = ['modules' => 0, 'contents' => 0, 'sessions' => 0, 'assessments' => 0, 'forums' => 0];

// Use a loop for simple counts to keep code dry
$queries = [
    'modules' => "SELECT COUNT(*) as cnt FROM el_course_modules",
    'contents' => "SELECT COUNT(*) as cnt FROM el_contents",
    'sessions' => "SELECT COUNT(*) as cnt FROM el_live_sessions"
];

foreach ($queries as $key => $sql) {
    if ($res = $db->query($sql)) {
        $stats[$key] = (int)($res->fetch_assoc()['cnt'] ?? 0);
    }
}

// Check tables that might not exist yet
$optionalTables = [
    'assessments' => 'el_assessments',
    'forums' => 'lms_forums'
];

foreach ($optionalTables as $key => $table) {
    $check = $db->query("SHOW TABLES LIKE '$table'");
    if ($check && $check->num_rows > 0) {
        if ($res = $db->query("SELECT COUNT(*) as cnt FROM $table")) {
            $stats[$key] = (int)($res->fetch_assoc()['cnt'] ?? 0);
        }
    }
}

// Fetch Data for Lists
$recentModules = $db->query("SELECT id, course_code, title, created_at FROM el_course_modules ORDER BY created_at DESC LIMIT 10");
$recentContents = $db->query("SELECT c.id, c.module_id, c.title, c.content_type, c.created_at, m.course_code 
                              FROM el_contents c 
                              LEFT JOIN el_course_modules m ON c.module_id = m.id 
                              ORDER BY c.created_at DESC LIMIT 10");
$orphanedContents = [];
$orphanedResult = $db->query("SELECT c.id, c.module_id, c.title, v.file_path
                              FROM el_contents c
                              LEFT JOIN el_course_modules m ON c.module_id = m.id
                              LEFT JOIN el_content_versions v ON v.id = c.current_version_id
                              WHERE m.id IS NULL
                              ORDER BY c.id DESC");
if ($orphanedResult) {
    while ($row = $orphanedResult->fetch_assoc()) {
        $orphanedContents[] = $row;
    }
    $orphanedResult->free();
}

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="../css/admin-dashboard.css" />
<style>
    .stat-box { color: white; padding: 20px; border-radius: 10px; text-align: center; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    .stat-box h3 { font-size: 2rem; margin: 10px 0; font-weight: bold; }
    .stat-box p { margin: 0; opacity: 0.95; font-weight: 500; }
    .bg-gradient-1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .bg-gradient-2 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .bg-gradient-3 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .bg-gradient-4 { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
    .danger-zone { border: 1px solid #f5c6cb; border-radius: 8px; padding: 25px; background-color: #fff8f8; }
</style>

<div class="container-fluid px-4 portal-dashboard py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-primary"><i class="fas fa-graduation-cap"></i> eLearning Management</h2>
        <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($err); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($ok): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($ok); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-box bg-gradient-1">
                <p>Modules</p>
                <h3><?php echo number_format($stats['modules']); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box bg-gradient-2">
                <p>Content Items</p>
                <h3><?php echo number_format($stats['contents']); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box bg-gradient-3">
                <p>Live Sessions</p>
                <h3><?php echo number_format($stats['sessions']); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box bg-gradient-4">
                <p>Assessments</p>
                <h3><?php echo number_format($stats['assessments']); ?></h3>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4" id="elearningTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Overview</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="modules-tab" data-bs-toggle="tab" data-bs-target="#modules" type="button" role="tab">Modules</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="contents-tab" data-bs-toggle="tab" data-bs-target="#contents" type="button" role="tab">Contents</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="maintenance-tab" data-bs-toggle="tab" data-bs-target="#maintenance" type="button" role="tab">Maintenance</button>
        </li>
    </ul>

    <div class="tab-content" id="elearningTabContent">
        
        <div class="tab-pane fade show active" id="overview" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header bg-light fw-bold">System Status</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="text-muted mb-3">Quick Stats</h5>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Total Modules <span class="badge bg-primary rounded-pill"><?php echo $stats['modules']; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Content Items <span class="badge bg-primary rounded-pill"><?php echo $stats['contents']; ?></span>
                                </li>
                                <?php if (!empty($orphanedContents)): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center list-group-item-danger">
                                        <span><i class="fas fa-exclamation-triangle me-1"></i>Orphaned Items</span>
                                        <span class="badge bg-danger rounded-pill"><?php echo count($orphanedContents); ?></span>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="col-md-6 border-start">
                            <h5 class="text-muted mb-3">Quick Actions</h5>
                            <div class="d-grid gap-2">
                                <a href="modules.php" class="btn btn-outline-primary"><i class="fas fa-book"></i> Manage Modules</a>
                                <a href="upload.php" class="btn btn-outline-primary"><i class="fas fa-cloud-upload-alt"></i> Upload Content</a>
                                <a href="sessions.php" class="btn btn-outline-primary"><i class="fas fa-video"></i> Schedule Sessions</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="modules" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center bg-white">
                    <h5 class="mb-0">Recent Modules</h5>
                    <a href="modules.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Title</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recentModules && $recentModules->num_rows > 0): ?>
                                    <?php while($mod = $recentModules->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($mod['course_code']); ?></td>
                                        <td><?php echo htmlspecialchars($mod['title']); ?></td>
                                        <td><small class="text-muted"><?php echo elearning_format_date($mod['created_at'] ?? '', 'M d, Y'); ?></small></td>
                                        <td>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('WARNING: Deleting this module will delete ALL associated files and content. Are you sure?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_module">
                                                <input type="hidden" name="module_id" value="<?php echo $mod['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-3">No modules found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="contents" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center bg-white">
                    <h5 class="mb-0">Recent Content</h5>
                    <a href="upload.php" class="btn btn-sm btn-primary">Upload New</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Course</th>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recentContents && $recentContents->num_rows > 0): ?>
                                    <?php while($cont = $recentContents->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($cont['course_code'] ?? 'N/A'); ?></span></td>
                                        <td><?php echo htmlspecialchars($cont['title']); ?></td>
                                        <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($cont['content_type']); ?></span></td>
                                        <td><small class="text-muted"><?php echo elearning_format_date($cont['created_at'] ?? '', 'M d'); ?></small></td>
                                        <td>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this content permanently?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_content">
                                                <input type="hidden" name="content_id" value="<?php echo $cont['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center py-3">No content found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($orphanedContents)): ?>
                        <div class="p-3 bg-warning bg-opacity-10 border-top border-warning">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                <div>
                                    <h6 class="text-warning-emphasis mb-1"><i class="fas fa-exclamation-triangle"></i> Orphaned Content Detected</h6>
                                    <p class="small mb-0">These items point to modules that no longer exist. Clean them up to remove broken student material links.</p>
                                </div>
                                <form method="post" onsubmit="return confirm('Clean up all orphaned content items and their uploaded files?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="cleanup_orphaned_content">
                                    <button type="submit" class="btn btn-sm btn-warning text-dark fw-semibold">Clean Up All</button>
                                </form>
                            </div>
                            <?php foreach($orphanedContents as $orphan): ?>
                                <div class="d-flex justify-content-between align-items-center mb-1 border-bottom pb-1">
                                    <small>
                                        <?php echo htmlspecialchars($orphan['title'] ?? 'Untitled content', ENT_QUOTES, 'UTF-8'); ?>
                                        <span class="text-muted">(ID: <?php echo (int)$orphan['id']; ?>, missing module: <?php echo (int)$orphan['module_id']; ?>)</span>
                                    </small>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this orphaned content item and its uploaded files?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="delete_content">
                                        <input type="hidden" name="content_id" value="<?php echo (int)$orphan['id']; ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-danger text-decoration-none p-0">Remove</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="maintenance" role="tabpanel">
            <div class="card shadow-sm border-danger">
                <div class="card-header bg-danger text-white">
                    <i class="fas fa-tools"></i> Maintenance Operations
                </div>
                <div class="card-body">
                    <div class="danger-zone">
                        <h5 class="text-danger fw-bold"><i class="fas fa-exclamation-triangle"></i> Danger Zone</h5>
                        <p class="text-muted">These operations cannot be undone.</p>
                        
                        <div class="mt-4">
                            <form method="post" onsubmit="return confirm('Are you sure? This will remove all session logs older than the selected days.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="cleanup_old_sessions">
                                
                                <label class="form-label fw-bold">Cleanup Old Live Sessions</label>
                                <div class="input-group" style="max-width: 400px;">
                                    <span class="input-group-text">Remove older than</span>
                                    <input type="number" name="days_old" class="form-control" value="90" min="30" required>
                                    <span class="input-group-text">days</span>
                                    <button type="submit" class="btn btn-danger">Execute Cleanup</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


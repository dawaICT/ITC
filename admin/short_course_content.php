<?php
/**
 * Short Course Content — manage modules (lessons) and their materials
 * (uploaded files or external links) for a single short course.
 */
require "includes/admin.php";

$page_title = "Short Course Content";
$staffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '';

if (empty($_SESSION['scc_csrf'])) {
    $_SESSION['scc_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['scc_csrf'];
function scc_valid_csrf(?string $t): bool {
    return is_string($t) && isset($_SESSION['scc_csrf']) && hash_equals($_SESSION['scc_csrf'], $t);
}

$UPLOAD_DIR = dirname(__DIR__) . '/uploads/short_courses';
$UPLOAD_URL = '/wucportal/uploads/short_courses';

function scc_safe_html(?string $html): string {
    if ($html === null || $html === '') return '';
    // Strip script/style blocks and event handlers; keep a generous set of presentation tags
    $allowed = '<p><br><strong><b><em><i><u><s><a><ul><ol><li><blockquote><pre><code><h1><h2><h3><h4><h5><h6><span><div><img><table><thead class="table-light"><tbody><tr><td><th><hr>';
    $clean = strip_tags($html, $allowed);
    // Remove on* event attributes
    $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
    // Disallow javascript: URLs
    $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', '$1="#"', $clean);
    return $clean;
}

function scc_store_upload(array $file, string $dir, string $relBase): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return [false, 'No file selected.'];
    if ($file['error'] !== UPLOAD_ERR_OK) return [false, 'Upload failed (code ' . $file['error'] . ').'];
    if ($file['size'] > 25 * 1024 * 1024) return [false, 'File exceeds the 25 MB limit.'];
    $allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','csv','zip','jpg','jpeg','png','gif','mp4','mp3'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return [false, 'File type ".' . htmlspecialchars($ext) . '" is not allowed.'];
    if (!is_uploaded_file($file['tmp_name'])) return [false, 'Invalid upload.'];
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) return [false, 'Could not create upload directory.'];
    $fname = 'sc_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $fname)) return [false, 'Could not store the file.'];
    return [true, $relBase . '/' . $fname];
}

// Resolve the target short course.
$courseId = (int)($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$course = null;
if ($courseId > 0) {
    $cs = $db->prepare("SELECT id, course_code, course_name FROM short_courses WHERE id = ?");
    $cs->bind_param("i", $courseId);
    $cs->execute();
    $course = $cs->get_result()->fetch_object();
}

// ─── Handle POST actions before output ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $course) {
    if (!scc_valid_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['errorMsg'] = "Security token mismatch. Please refresh and try again.";
        header("Location: short_course_content.php?course_id=" . $courseId);
        exit();
    }
    $action = $_POST['action'];

    if ($action === 'add_module') {
        $title   = trim($_POST['title'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $pub     = isset($_POST['is_published']) ? 1 : 0;
        if ($title === '') {
            $_SESSION['errorMsg'] = "Module title is required.";
        } elseif (mb_strlen($title) > 255) {
            $_SESSION['errorMsg'] = "Module title is too long (max 255 characters).";
        } else {
            $posRes = $db->prepare("SELECT COALESCE(MAX(position), 0) + 1 AS p FROM short_course_modules WHERE short_course_id = ?");
            $posRes->bind_param("i", $courseId);
            $posRes->execute();
            $pos = (int)$posRes->get_result()->fetch_object()->p;
            $ins = $db->prepare("INSERT INTO short_course_modules (short_course_id, title, description, content, position, is_published, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param("isssiis", $courseId, $title, $desc, $content, $pos, $pub, $staffId);
            $_SESSION[$ins->execute() ? 'successMsg' : 'errorMsg'] = $ins->error ?: "Module added.";
        }
    } elseif ($action === 'edit_module') {
        $id      = (int)($_POST['module_id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $pub     = isset($_POST['is_published']) ? 1 : 0;
        if ($title === '') {
            $_SESSION['errorMsg'] = "Module title is required.";
        } elseif (mb_strlen($title) > 255) {
            $_SESSION['errorMsg'] = "Module title is too long (max 255 characters).";
        } else {
            $upd = $db->prepare("UPDATE short_course_modules SET title = ?, description = ?, content = ?, is_published = ? WHERE id = ? AND short_course_id = ?");
            $upd->bind_param("sssiii", $title, $desc, $content, $pub, $id, $courseId);
            $_SESSION[$upd->execute() ? 'successMsg' : 'errorMsg'] = $upd->error ?: "Module updated.";
        }
    } elseif ($action === 'delete_module') {
        $id = (int)($_POST['module_id'] ?? 0);
        // Remove local files for this module's materials, then the rows.
        $fr = $db->prepare("SELECT url, material_type FROM short_course_materials WHERE module_id = ? AND short_course_id = ?");
        $fr->bind_param("ii", $id, $courseId);
        $fr->execute();
        $fres = $fr->get_result();
        while ($mat = $fres->fetch_object()) {
            if ($mat->material_type === 'file' && $mat->url && strpos($mat->url, $UPLOAD_URL) === 0) {
                $p = dirname(__DIR__) . substr($mat->url, strlen('/wucportal'));
                if (is_file($p)) @unlink($p);
            }
        }
        $db->query("DELETE FROM short_course_materials WHERE module_id = " . (int)$id . " AND short_course_id = " . (int)$courseId);
        $dm = $db->prepare("DELETE FROM short_course_modules WHERE id = ? AND short_course_id = ?");
        $dm->bind_param("ii", $id, $courseId);
        $_SESSION[$dm->execute() ? 'successMsg' : 'errorMsg'] = $dm->error ?: "Module deleted.";
    } elseif ($action === 'move_module') {
        $id  = (int)($_POST['module_id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        // Load ordered ids and swap positions with the neighbour.
        $ord = $db->query("SELECT id, position FROM short_course_modules WHERE short_course_id = " . (int)$courseId . " ORDER BY position, id");
        $rows = [];
        while ($r = $ord->fetch_object()) { $rows[] = $r; }
        $idx = null;
        foreach ($rows as $i => $r) { if ((int)$r->id === $id) { $idx = $i; break; } }
        if ($idx !== null) {
            $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
            if ($swap >= 0 && $swap < count($rows)) {
                $a = $rows[$idx]; $b = $rows[$swap];
                $u = $db->prepare("UPDATE short_course_modules SET position = ? WHERE id = ?");
                $u->bind_param("ii", $b->position, $a->id); $u->execute();
                $u->bind_param("ii", $a->position, $b->id); $u->execute();
                $_SESSION['successMsg'] = "Module reordered.";
            }
        }
    } elseif ($action === 'add_material') {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $type     = ($_POST['material_type'] ?? 'file') === 'link' ? 'link' : 'file';
        // Confirm the module belongs to this course.
        $mok = $db->prepare("SELECT id FROM short_course_modules WHERE id = ? AND short_course_id = ?");
        $mok->bind_param("ii", $moduleId, $courseId);
        $mok->execute();
        if (!$mok->get_result()->fetch_object()) {
            $_SESSION['errorMsg'] = "Module not found.";
        } elseif ($title === '') {
            $_SESSION['errorMsg'] = "Material title is required.";
        } elseif (mb_strlen($title) > 255) {
            $_SESSION['errorMsg'] = "Material title is too long (max 255 characters).";
        } else {
            $url = null; $ok = true;
            if ($type === 'link') {
                $url = trim($_POST['external_url'] ?? '');
                if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                    $ok = false; $_SESSION['errorMsg'] = "Enter a valid URL (including http:// or https://).";
                }
            } else {
                [$ok, $res] = scc_store_upload($_FILES['material_file'] ?? [], $UPLOAD_DIR, $UPLOAD_URL);
                if ($ok) { $url = $res; } else { $_SESSION['errorMsg'] = $res; }
            }
            if ($ok) {
                $ins = $db->prepare("INSERT INTO short_course_materials (module_id, short_course_id, title, material_type, url, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->bind_param("iissss", $moduleId, $courseId, $title, $type, $url, $staffId);
                $_SESSION[$ins->execute() ? 'successMsg' : 'errorMsg'] = $ins->error ?: "Material added.";
            }
        }
    } elseif ($action === 'delete_material') {
        $id = (int)($_POST['material_id'] ?? 0);
        $fr = $db->prepare("SELECT url, material_type FROM short_course_materials WHERE id = ? AND short_course_id = ?");
        $fr->bind_param("ii", $id, $courseId);
        $fr->execute();
        if ($mat = $fr->get_result()->fetch_object()) {
            if ($mat->material_type === 'file' && $mat->url && strpos($mat->url, $UPLOAD_URL) === 0) {
                $p = dirname(__DIR__) . substr($mat->url, strlen('/wucportal'));
                if (is_file($p)) @unlink($p);
            }
            $dm = $db->prepare("DELETE FROM short_course_materials WHERE id = ? AND short_course_id = ?");
            $dm->bind_param("ii", $id, $courseId);
            $_SESSION[$dm->execute() ? 'successMsg' : 'errorMsg'] = $dm->error ?: "Material removed.";
        } else {
            $_SESSION['errorMsg'] = "Material not found.";
        }
    }
    header("Location: short_course_content.php?course_id=" . $courseId);
    exit();
}

// ─── Load modules + materials ────────────────────────────────────────────
$modules = [];
if ($course) {
    $ms = $db->prepare("SELECT * FROM short_course_modules WHERE short_course_id = ? ORDER BY position, id");
    $ms->bind_param("i", $courseId);
    $ms->execute();
    $mres = $ms->get_result();
    while ($m = $mres->fetch_object()) { $m->materials = []; $modules[(int)$m->id] = $m; }
    if ($modules) {
        $matRes = $db->query("SELECT * FROM short_course_materials WHERE short_course_id = " . (int)$courseId . " ORDER BY id");
        while ($mat = $matRes->fetch_object()) {
            if (isset($modules[(int)$mat->module_id])) { $modules[(int)$mat->module_id]->materials[] = $mat; }
        }
    }
}
$moduleList = array_values($modules);

require "includes/header.php";
?>
<link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
<style>
    .modal-header.admin-modal { background: linear-gradient(135deg, #6f42c1 0%, #4e2a84 100%) !important; border: none !important; }
    .module-card { border: 1px solid #eef0f4; border-radius: 12px; background: #fff; }
    .module-card .module-head { padding: 14px 18px; border-bottom: 1px solid #f1f3f7; }
    .module-card .module-body { padding: 14px 18px; }
    .material-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid #f0f2f6; border-radius: 8px; margin-bottom: 8px; }
    .material-row .mat-icon { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #f3eefc; color: #6f42c1; flex: 0 0 auto; }
    .pos-pill { font-family: monospace; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 1px 7px; font-size: .8rem; }
    .module-content-body { background: #fafbfc; border: 1px solid #eef0f4; border-radius: 8px; padding: 10px 14px; margin-top: 8px; font-size: .92rem; color: #495057; }
    .module-content-body img { max-width: 100%; height: auto; border-radius: 6px; }
    .quill-host { background:#fff; min-height: 180px; }
    .quill-host .ql-editor { min-height: 160px; }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-layer-group me-2 text-primary"></i>Short Course Content</h5>
                <p class="page-subtitle mb-0">
                    <?php if ($course): ?>
                        Modules &amp; materials for <strong><?= htmlspecialchars($course->course_code) ?></strong> — <?= htmlspecialchars($course->course_name) ?>
                    <?php else: ?>
                        Select a short course to manage its content
                    <?php endif; ?>
                </p>
            </div>
            <div class="header-actions d-flex gap-2">
                <?php if ($course): ?>
                    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addModuleModal">
                        <i class="fas fa-plus me-1"></i>Add Module
                    </button>
                <?php endif; ?>
                <a href="short_courses.php" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i>Short Courses
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['successMsg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($_SESSION['successMsg']); unset($_SESSION['successMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['errorMsg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($_SESSION['errorMsg']); unset($_SESSION['errorMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$course): ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>No short course selected. Go to <a href="short_courses.php">Short Courses</a> and click <strong>Manage Content</strong>.</div>
    <?php elseif (empty($moduleList)): ?>
        <div class="data-table-card"><div class="card-body text-center py-5">
            <i class="fas fa-layer-group fa-3x text-muted mb-3 d-block" style="opacity:.2;"></i>
            <h5 class="text-muted">No modules yet</h5>
            <p class="text-muted">Click "Add Module" to create the first lesson/topic for this course.</p>
        </div></div>
    <?php else: ?>
        <?php foreach ($moduleList as $idx => $m): ?>
            <div class="module-card mb-3">
                <div class="module-head d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <span class="pos-pill me-2"><?= $idx + 1 ?></span>
                        <strong><?= htmlspecialchars($m->title) ?></strong>
                        <?php if (!$m->is_published): ?><span class="badge bg-secondary-subtle text-secondary border ms-2">Draft</span><?php endif; ?>
                        <?php if ($m->description): ?><div class="text-muted small mt-1"><?= nl2br(htmlspecialchars($m->description)) ?></div><?php endif; ?>
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="action" value="move_module"><input type="hidden" name="module_id" value="<?= (int)$m->id ?>"><input type="hidden" name="dir" value="up">
                            <button class="btn btn-sm btn-light border" title="Move up" <?= $idx === 0 ? 'disabled' : '' ?>><i class="fas fa-arrow-up"></i></button>
                        </form>
                        <form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="action" value="move_module"><input type="hidden" name="module_id" value="<?= (int)$m->id ?>"><input type="hidden" name="dir" value="down">
                            <button class="btn btn-sm btn-light border" title="Move down" <?= $idx === count($moduleList) - 1 ? 'disabled' : '' ?>><i class="fas fa-arrow-down"></i></button>
                        </form>
                        <button class="btn btn-sm btn-outline-primary edit-module"
                            data-module='<?= htmlspecialchars(json_encode(["id" => $m->id, "title" => $m->title, "description" => $m->description, "content" => $m->content ?? '', "is_published" => $m->is_published]), ENT_QUOTES) ?>'
                            data-bs-toggle="modal" data-bs-target="#editModuleModal" title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-outline-success add-material"
                            data-module-id="<?= (int)$m->id ?>" data-module-title="<?= htmlspecialchars($m->title) ?>"
                            data-bs-toggle="modal" data-bs-target="#addMaterialModal" title="Add material"><i class="fas fa-paperclip"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this module and its materials?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="action" value="delete_module"><input type="hidden" name="module_id" value="<?= (int)$m->id ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
                <div class="module-body">
                    <?php if (!empty($m->content)): ?>
                        <div class="module-content-body"><?= scc_safe_html($m->content) ?></div>
                    <?php endif; ?>
                    <?php if (empty($m->materials)): ?>
                        <div class="text-muted small mt-2"><i class="fas fa-info-circle me-1"></i>No materials yet. Use the <i class="fas fa-paperclip"></i> button to add a file or link.</div>
                    <?php else: ?>
                        <?php foreach ($m->materials as $mat): ?>
                            <div class="material-row">
                                <span class="mat-icon"><i class="fas fa-<?= $mat->material_type === 'link' ? 'link' : 'file' ?>"></i></span>
                                <div class="flex-grow-1">
                                    <a href="<?= htmlspecialchars($mat->url) ?>" target="_blank" rel="noopener" class="fw-semibold text-decoration-none"><?= htmlspecialchars($mat->title) ?></a>
                                    <div class="text-muted small"><?= $mat->material_type === 'link' ? 'External link' : 'Downloadable file' ?></div>
                                </div>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove this material?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="action" value="delete_material"><input type="hidden" name="material_id" value="<?= (int)$mat->id ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="fas fa-times"></i></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($course): ?>
<!-- Add Module Modal -->
<div class="modal fade" id="addModuleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header admin-modal text-white">
            <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Module</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST" class="needs-validation" novalidate id="addModuleForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <input type="hidden" name="action" value="add_module">
            <input type="hidden" name="content" id="addModuleContent">
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Module Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" required maxlength="255" placeholder="e.g. Week 1 — Introduction"></div>
                <div class="mb-3"><label class="form-label">Short Description</label>
                    <textarea class="form-control" name="description" rows="2" placeholder="One-line summary shown in lists"></textarea></div>
                <div class="mb-3"><label class="form-label">Lesson Content / Body</label>
                    <div id="addModuleQuill" class="quill-host"></div>
                    <div class="form-text">Rich text: format headings, lists, links, images. Saved as HTML.</div>
                </div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_published" id="addPub" checked>
                    <label class="form-check-label" for="addPub">Published (visible to enrolled students)</label></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Module</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Edit Module Modal -->
<div class="modal fade" id="editModuleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header admin-modal text-white">
            <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Module</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST" class="needs-validation" novalidate id="editModuleForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <input type="hidden" name="action" value="edit_module">
            <input type="hidden" name="module_id" id="editModuleId">
            <input type="hidden" name="content" id="editModuleContent">
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Module Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" id="editModuleTitle" required maxlength="255"></div>
                <div class="mb-3"><label class="form-label">Short Description</label>
                    <textarea class="form-control" name="description" id="editModuleDesc" rows="2"></textarea></div>
                <div class="mb-3"><label class="form-label">Lesson Content / Body</label>
                    <div id="editModuleQuill" class="quill-host"></div>
                </div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_published" id="editModulePub">
                    <label class="form-check-label" for="editModulePub">Published (visible to enrolled students)</label></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Add Material Modal -->
<div class="modal fade" id="addMaterialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header admin-modal text-white">
            <h5 class="modal-title"><i class="fas fa-paperclip me-2"></i>Add Material — <span id="addMaterialModuleTitle"></span></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <input type="hidden" name="action" value="add_material">
            <input type="hidden" name="module_id" id="addMaterialModuleId">
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" required maxlength="255" placeholder="e.g. Lecture slides">
                    <div class="invalid-feedback">Material title is required (max 255 characters).</div></div>
                <div class="mb-3"><label class="form-label">Type</label>
                    <select class="form-select" id="materialType" name="material_type">
                        <option value="file" selected>Upload file</option>
                        <option value="link">External link</option>
                    </select></div>
                <div class="mb-3" id="fileField">
                    <label class="form-label">File</label>
                    <input type="file" class="form-control" name="material_file" id="materialFile" required
                           accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.csv,.zip,.jpg,.jpeg,.png,.gif,.mp4,.mp3">
                    <div class="form-text">Max 25 MB. PDF, Office docs, images, zip, mp4/mp3.</div>
                    <div class="invalid-feedback">Please choose a file to upload.</div>
                </div>
                <div class="mb-3 d-none" id="linkField">
                    <label class="form-label">URL</label>
                    <input type="url" class="form-control" name="external_url" id="materialUrl" placeholder="https://…">
                    <div class="invalid-feedback">Enter a valid URL including http:// or https://.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Add Material</button>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<script>
// Initialize Quill editors for module add/edit.
var quillToolbar = [
    [{ header: [1, 2, 3, false] }],
    ['bold', 'italic', 'underline', 'strike'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    ['link', 'blockquote', 'code-block'],
    [{ align: [] }],
    ['clean']
];
var addQuill = null, editQuill = null;
if (window.Quill && document.getElementById('addModuleQuill')) {
    addQuill = new Quill('#addModuleQuill', { theme: 'snow', modules: { toolbar: quillToolbar }, placeholder: 'Lesson notes, instructions, examples, links…' });
}
if (window.Quill && document.getElementById('editModuleQuill')) {
    editQuill = new Quill('#editModuleQuill', { theme: 'snow', modules: { toolbar: quillToolbar } });
}
var addForm = document.getElementById('addModuleForm');
if (addForm) {
    addForm.addEventListener('submit', function () {
        if (addQuill) {
            var html = addQuill.root.innerHTML.trim();
            document.getElementById('addModuleContent').value = (html === '<p><br></p>') ? '' : html;
        }
    });
}
var editForm = document.getElementById('editModuleForm');
if (editForm) {
    editForm.addEventListener('submit', function () {
        if (editQuill) {
            var html = editQuill.root.innerHTML.trim();
            document.getElementById('editModuleContent').value = (html === '<p><br></p>') ? '' : html;
        }
    });
}

document.querySelectorAll('.edit-module').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var m = JSON.parse(this.dataset.module);
        document.getElementById('editModuleId').value = m.id;
        document.getElementById('editModuleTitle').value = m.title;
        document.getElementById('editModuleDesc').value = m.description || '';
        document.getElementById('editModulePub').checked = (parseInt(m.is_published) === 1);
        if (editQuill) {
            editQuill.root.innerHTML = m.content || '';
        }
    });
});
document.querySelectorAll('.add-material').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('addMaterialModuleId').value = this.dataset.moduleId;
        document.getElementById('addMaterialModuleTitle').textContent = this.dataset.moduleTitle;
    });
});
var matType = document.getElementById('materialType');
if (matType) {
    var fileInput = document.getElementById('materialFile');
    var urlInput = document.getElementById('materialUrl');
    var applyMatType = function () {
        var isLink = matType.value === 'link';
        document.getElementById('fileField').classList.toggle('d-none', isLink);
        document.getElementById('linkField').classList.toggle('d-none', !isLink);
        if (fileInput) { fileInput.required = !isLink; }
        if (urlInput) { urlInput.required = isLink; }
    };
    matType.addEventListener('change', applyMatType);
    applyMatType();
}

// Reset the Add Module modal each time it opens (clear stale Quill state).
var addModalEl = document.getElementById('addModuleModal');
if (addModalEl) {
    addModalEl.addEventListener('show.bs.modal', function () {
        if (addForm) { addForm.reset(); addForm.classList.remove('was-validated'); }
        if (addQuill) { addQuill.setContents([]); }
    });
}

// Bootstrap client-side validation for all content forms
document.querySelectorAll('.needs-validation').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
        form.classList.add('was-validated');
    }, false);
});

// Scroll the success/error banner into view after a redirect so the user sees the outcome.
window.addEventListener('DOMContentLoaded', function () {
    var a = document.querySelector('.alert.alert-success, .alert.alert-danger, .alert.alert-warning');
    if (a) { a.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
});
</script>

<?php require_once "includes/footer.php"; ?>

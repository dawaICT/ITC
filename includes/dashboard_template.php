<?php
/**
 * Unified Admin Dashboard Template
 * 
 * This template provides a consistent dashboard layout for all admin user types:
 * - Admin, Lecturer, HOS, Accounts, Admissions, etc.
 * 
 * Required variables to be set before including this file:
 * - $dashboard_title: string - The dashboard title (e.g., "Admin Dashboard", "Lecturer Dashboard")
 * - $dashboard_subtitle: string - The subtitle text
 * - $user_role: string - The role badge text (e.g., "Administrator", "Lecturer", "HOS")
 * - $user_role_class: string - CSS class for role badge color (e.g., "bg-purple", "bg-lecturer", "bg-finance")
 * - $stat_cards: array - Array of stat card configurations
 * - $quick_modules: array - Array of quick access module configurations
 * - $show_announcements: bool - Whether to show announcements section
 * - $announcements: array - Array of announcement data (optional)
 * - $profile_link: string - Link to view profile
 * - $edit_profile_link: string - Link to edit profile
 * 
 * Optional:
 * - $header_section_class: string - CSS class for header section (e.g., "admin-section", "finance-section")
 * - $stat_icon_class: string - Default CSS class for stat icons (e.g., "bg-primary", "bg-lecturer")
 * - $additional_profile_fields: array - Extra fields to show in profile table
 * - $show_profile_upload: bool - Whether to show profile image upload button
 */

// Set defaults
$dashboard_title = $dashboard_title ?? 'Dashboard';
$dashboard_subtitle = $dashboard_subtitle ?? 'Welcome to your portal';
$user_role = $user_role ?? 'Staff';
$user_role_class = $user_role_class ?? 'bg-primary';
$header_section_class = $header_section_class ?? 'admin-section';
$stat_icon_class = $stat_icon_class ?? 'bg-primary';
$stat_cards = $stat_cards ?? [];
$quick_modules = $quick_modules ?? [];
$show_announcements = $show_announcements ?? false;
$announcements = $announcements ?? [];
$profile_link = $profile_link ?? '#';
$edit_profile_link = $edit_profile_link ?? '#';
$show_profile_card = $show_profile_card ?? true;
$show_stat_cards = $show_stat_cards ?? true;
$show_dashboard_announcements = $show_dashboard_announcements ?? true;
$show_profile_upload = $show_profile_upload ?? true;
$additional_profile_fields = $additional_profile_fields ?? [];
$dashboard_container_class = trim((string)($dashboard_container_class ?? ''));

// Helper function to detect columns (if not already defined)
if (!function_exists('dashboard_detect_column')) {
    function dashboard_detect_column(mysqli $db, string $table, array $candidates): ?string {
        foreach ($candidates as $col) {
            if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '".$db->real_escape_string($col)."'")) {
                if ($res->num_rows > 0) { $res->free(); return $col; }
                $res->free();
            }
        }
        return null;
    }
}

// FIX: Read staff identifier from either new ('user_id') or legacy ('staff_id') session key.
$staffSessionId = $_SESSION['user_id'] ?? ($_SESSION['staff_id'] ?? null);

// Fetch staff record if not already provided
if (!isset($staff_record) && !empty($staffSessionId) && isset($db) && $db instanceof mysqli) {
    $staffDeptCol = dashboard_detect_column($db, 'staff', ['DeptID', 'deptId', 'department_id']);
    $deptIdNumericCol = dashboard_detect_column($db, 'departments', ['DeptID', 'id', 'department_id']);
    $deptIdCodeCol = dashboard_detect_column($db, 'departments', ['deptId', 'department_code']);
    $deptNameCol = dashboard_detect_column($db, 'departments', ['DeptName', 'deptName', 'department_name', 'name']);

    $deptJoin = '';
    if ($staffDeptCol === 'department_id' && $deptIdNumericCol) {
        $deptJoin = "LEFT JOIN departments d ON s.`department_id` = d.`{$deptIdNumericCol}`";
    } elseif (($staffDeptCol === 'deptId' || $staffDeptCol === 'DeptID') && ($deptIdCodeCol || $deptIdNumericCol)) {
        if ($deptIdCodeCol && $staffDeptCol !== 'DeptID') {
            $deptJoin = "LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdCodeCol}`";
        } elseif ($deptIdNumericCol && $staffDeptCol === 'DeptID') {
            $deptJoin = "LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdNumericCol}`";
        }
    }
    $deptNameExpr = ($deptJoin !== '' && $deptNameCol) ? "d.`{$deptNameCol}`" : "NULL";

    $sql = "SELECT s.*, {$deptNameExpr} AS deptName FROM staff s {$deptJoin} WHERE s.staff_id = ? LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        // FIX: Bind resolved session ID so profile card does not fail after user_id-only login.
        $staffSessionIdStr = (string)$staffSessionId;
        $stmt->bind_param('s', $staffSessionIdStr);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $staff_record = $res->fetch_object();
            }
        }
        $stmt->close();
    }

    // FIX: Fallback to internal numeric staff.id when legacy sessions carry DB id instead of staff_id code.
    if (!isset($staff_record) && ctype_digit((string)$staffSessionId)) {
        $fallbackSql = "SELECT s.*, {$deptNameExpr} AS deptName FROM staff s {$deptJoin} WHERE s.id = ? LIMIT 1";
        if ($fallbackStmt = $db->prepare($fallbackSql)) {
            $staffNumericId = (int)$staffSessionId;
            $fallbackStmt->bind_param('i', $staffNumericId);
            if ($fallbackStmt->execute()) {
                $fallbackRes = $fallbackStmt->get_result();
                if ($fallbackRes && $fallbackRes->num_rows > 0) {
                    $staff_record = $fallbackRes->fetch_object();
                }
            }
            $fallbackStmt->close();
        }
    }
}

// Get profile image
$root_url = $root_url ?? '/wucportal';
$profile_img = $root_url . '/images/itc_logo.png';
if (isset($staff_record) && !empty($staff_record->profile_image)) {
    $rawPath = trim($staff_record->profile_image);
    $isUrl = (preg_match('/^https?:\/\//i', $rawPath) === 1);
    if ($isUrl) {
        $profile_img = $rawPath;
    } else {
        $webPath = '/' . ltrim($rawPath, '/');
        $documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', DIRECTORY_SEPARATOR);
        $fsPath = $documentRoot . str_replace('/', DIRECTORY_SEPARATOR, $webPath);
        if (is_file($fsPath)) {
            $profile_img = $webPath;
        }
    }
}
?>

<div class="container-fluid px-4 portal-dashboard<?php echo $dashboard_container_class !== '' ? ' ' . htmlspecialchars($dashboard_container_class) : ''; ?>">
    <!-- Dashboard Header -->
    <div class="dashboard-header <?php echo htmlspecialchars($header_section_class); ?> mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><?php echo htmlspecialchars($dashboard_title); ?></h1>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($dashboard_subtitle); ?></p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <button class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($show_profile_card): ?>
    <!-- Profile Card Section -->
    <div class="row g-4 mb-4">
        <?php if (isset($staff_record)): ?>
        <div class="col-12">
            <div class="profile-card shadow-sm">
                <div class="row align-items-center">
                    <div class="col-md-3 text-center">
                        <div class="profile-image-container position-relative mb-3">
                            <img src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile" class="profile-image" loading="lazy" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($root_url); ?>/images/itc_logo.png';">
                            <?php if ($show_profile_upload): ?>
                            <button type="button" class="btn btn-sm btn-primary position-absolute bottom-0 end-0" data-bs-toggle="modal" data-bs-target="#uploadProfileModal">
                                <i class="fas fa-camera"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <h5 class="mb-2"><?php echo htmlspecialchars($staff_record->title ?? ''); ?> <?php echo htmlspecialchars($staff_record->Fname ?? ''); ?> <?php echo htmlspecialchars($staff_record->Lname ?? ''); ?></h5>
                        <span class="badge <?php echo htmlspecialchars($user_role_class); ?>"><?php echo htmlspecialchars($user_role); ?></span>
                    </div>
                    <div class="col-md-9">
                        <div class="table-responsive">
                            <table class="table info-table">
                                <tr>
                                    <th><i class="fas fa-id-badge me-2"></i>Staff ID</th>
                                    <td><?php echo htmlspecialchars($staff_record->staff_id ?? ''); ?></td>
                                </tr>
                                <tr>
                                    <th><i class="fas fa-building me-2"></i>Department</th>
                                    <td><?php echo htmlspecialchars($staff_record->deptName ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th><i class="fas fa-envelope me-2"></i>Email</th>
                                    <td><?php echo htmlspecialchars($staff_record->email ?? ''); ?></td>
                                </tr>
                                <tr>
                                    <th><i class="fas fa-phone me-2"></i>Mobile</th>
                                    <td><?php echo htmlspecialchars($staff_record->mobile ?? ''); ?></td>
                                </tr>
                                <tr>
                                    <th><i class="fas fa-venus-mars me-2"></i>Gender</th>
                                    <td><?php echo htmlspecialchars($staff_record->sex ?? ''); ?></td>
                                </tr>
                                <?php foreach ($additional_profile_fields as $field): ?>
                                <tr>
                                    <th><i class="<?php echo htmlspecialchars($field['icon'] ?? 'fas fa-info'); ?> me-2"></i><?php echo htmlspecialchars($field['label']); ?></th>
                                    <td><?php echo htmlspecialchars($field['value'] ?? ''); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <div class="mt-3">
                            <a href="<?php echo htmlspecialchars($profile_link); ?>" class="btn btn-outline-primary me-2">
                                <i class="fas fa-user me-2"></i>View Profile
                            </a>
                            <a href="<?php echo htmlspecialchars($edit_profile_link); ?>" class="btn btn-primary">
                                <i class="fas fa-edit me-2"></i>Update Profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="col-12">
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Could not retrieve staff information. Please contact the system administrator.
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <?php if ($show_stat_cards && !empty($stat_cards)): ?>
    <div class="row g-4 mb-4">
        <?php foreach ($stat_cards as $card): ?>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="stat-icon <?php echo htmlspecialchars($card['bg_class'] ?? $stat_icon_class); ?> rounded-circle p-3 me-3">
                        <i class="<?php echo htmlspecialchars($card['icon']); ?> fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-0"><?php echo htmlspecialchars($card['value']); ?></h3>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($card['label']); ?></p>
                    </div>
                </div>
                <?php if (!empty($card['link'])): ?>
                <div class="mt-3 text-end">
                    <a href="<?php echo htmlspecialchars($card['link']); ?>" class="btn btn-sm btn-outline-primary">
                        <?php echo htmlspecialchars($card['link_text'] ?? 'View'); ?> <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Quick Access Modules -->
    <?php if (!empty($quick_modules)): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <h5 class="mb-3 text-muted fw-bold text-uppercase small ls-1">Quick Actions</h5>
            <div class="row g-3">
                <?php foreach ($quick_modules as $module): ?>
                <div class="col-xl-3 col-md-4 col-sm-6">
                    <a href="<?php echo htmlspecialchars($module['link']); ?>" class="text-decoration-none">
                        <div class="card h-100 border-0 shadow-sm hover-elevate">
                            <div class="card-body d-flex align-items-center p-3">
                                <div class="rounded-3 p-3 me-3 <?php echo htmlspecialchars($module['bg_class'] ?? 'bg-primary'); ?> text-white d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                    <i class="<?php echo htmlspecialchars($module['icon']); ?> fa-lg"></i>
                                </div>
                                <div>
                                    <h6 class="card-title text-dark mb-1 fw-bold"><?php echo htmlspecialchars($module['title']); ?></h6>
                                    <p class="small text-muted mb-0"><?php echo htmlspecialchars($module['description']); ?></p>
                                </div>
                                <div class="ms-auto text-muted">
                                    <i class="fas fa-chevron-right small"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Announcements Section -->
    <?php if ($show_dashboard_announcements && $show_announcements && !empty($announcements)): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 text-primary">
                        <i class="fas fa-newspaper me-2"></i>Announcements
                    </h5>
                </div>
                <div class="card-body">
                    <?php foreach ($announcements as $announcement): ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="card-title fw-bold">
                                <i class="<?php echo htmlspecialchars($announcement['icon'] ?? 'fas fa-bullhorn'); ?> me-2 text-<?php echo htmlspecialchars($announcement['color'] ?? 'primary'); ?>"></i>
                                <?php echo htmlspecialchars($announcement['title']); ?>
                            </h6>
                            <p class="card-text"><?php echo htmlspecialchars($announcement['content']); ?></p>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <span class="badge bg-<?php echo htmlspecialchars($announcement['badge_color'] ?? 'primary'); ?>"><?php echo htmlspecialchars($announcement['badge'] ?? 'Notice'); ?></span>
                                <small class="text-muted"><?php echo htmlspecialchars($announcement['date'] ?? 'Today'); ?></small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Extra Dashboard Content -->
    <?php if (isset($extra_dashboard_content)): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <?php echo $extra_dashboard_content; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Profile Upload Modal -->
<?php if ($show_profile_upload && isset($staff_record)): ?>
<div class="modal fade" id="uploadProfileModal" tabindex="-1" aria-labelledby="uploadProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadProfileModalLabel">Upload Profile Picture</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="uploadAlert" class="alert d-none"></div>
                <form id="profileUploadForm" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="profile_image" class="form-label">Select Image</label>
                        <input type="file" class="form-control" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/gif">
                        <div class="form-text">Maximum file size: 5MB. Allowed formats: JPG, PNG, GIF.</div>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const profileForm = document.getElementById('profileUploadForm');
    const uploadAlert = document.getElementById('uploadAlert');
    
    if(profileForm) {
        profileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(profileForm);
            
            uploadAlert.classList.remove('d-none', 'alert-success', 'alert-danger');
            uploadAlert.classList.add('alert-info');
            uploadAlert.textContent = 'Uploading image...';
            
            fetch('profile_upload.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                uploadAlert.classList.remove('alert-info');
                if(data.success) {
                    uploadAlert.classList.add('alert-success');
                    uploadAlert.textContent = data.message;
                    const profileImages = document.querySelectorAll('.profile-image');
                    profileImages.forEach(img => { img.src = data.file_path; });
                    profileForm.reset();
                    setTimeout(() => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('uploadProfileModal'));
                        modal.hide();
                    }, 2000);
                } else {
                    uploadAlert.classList.add('alert-danger');
                    uploadAlert.textContent = data.message || 'An error occurred while uploading the image.';
                }
            })
            .catch(error => {
                uploadAlert.classList.remove('alert-info');
                uploadAlert.classList.add('alert-danger');
                uploadAlert.textContent = 'An error occurred while processing your request.';
                console.error('Error:', error);
            });
        });
    }
});
</script>
<?php endif; ?>




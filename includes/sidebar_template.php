<?php
// Sidebar Template - Unified sidebar for all modules
// This file should be included in all pages that need the sidebar

// Get current page info for active state
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Define user info if not already set
if (!isset($sidebarUserName) || !isset($sidebarUserRole)) {
    $sidebarUserName = 'Staff Member';
    $sidebarUserRole = 'User';
    $sidebarUserAvatar = '/wucportal/images/avatar.png';
    
    // Try to get user info from session
    if (isset($_SESSION['staff_id']) && isset($db) && $db instanceof mysqli) {
        $stmt = $db->prepare("SELECT title, Fname, Lname FROM staff WHERE staff_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $_SESSION['staff_id']);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows) {
                $row = $res->fetch_assoc();
                $sidebarUserName = trim(($row['title'] ?? '') . ' ' . ($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? ''));
            }
            $stmt->close();
        }
    }
}

// Define base paths
$root_path = '/wucportal';
$module_path = isset($module_path) ? $module_path : '';
?>

<!-- Mobile Toggle Button -->
<button class="sidebar-toggle d-md-none">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo-container">
            <img src="<?php echo $root_path; ?>/images/favicon.png" alt="Logo" class="logo">
            <span class="logo-text">ITC</span>
        </div>
    </div>
    
    <div class="sidebar-content">
        <!-- Dynamic Navigation Sections -->
        <?php if (isset($sidebar_sections) && is_array($sidebar_sections)): ?>
            <?php foreach ($sidebar_sections as $section): ?>
                <div class="nav-section">
                    <div class="nav-section-title"><?php echo htmlspecialchars($section['title']); ?></div>
                    <?php foreach ($section['items'] as $item): ?>
                        <?php 
                        $isActive = false;
                        if (isset($item['active_check'])) {
                            $isActive = $item['active_check']();
                        } else {
                            $isActive = ($current_page == basename($item['link']));
                        }
                        ?>
                        <a href="<?php echo $item['link']; ?>" class="nav-item <?php echo $isActive ? 'active' : ''; ?>">
                            <i class="<?php echo $item['icon']; ?>"></i>
                            <span><?php echo htmlspecialchars($item['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <img src="<?php echo htmlspecialchars($sidebarUserAvatar); ?>" alt="avatar">
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($sidebarUserName); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($sidebarUserRole); ?></div>
                </div>
            </div>
            <div class="quick-actions">
                <a href="#" class="quick-action-btn"><i class="fas fa-user"></i> Profile</a>
                <?php if (isset($_SESSION['all_roles']) && is_array($_SESSION['all_roles']) && count($_SESSION['all_roles']) > 1): ?>
                <a href="/wucportal/role_selection.php" class="quick-action-btn" title="Switch Workspace">
                    <i class="fas fa-exchange-alt"></i> Switch
                </a>
                <?php endif; ?>
                <?php 
                if (empty($_SESSION['csrf_token'])) {
                    try {
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } catch (Exception $e) {
                        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
                    }
                }
                ?>
                <form method="POST" action="/wucportal/logout.php" style="display:inline; width: 48%;">
                    <input type="hidden" name="target" value="staff">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <button type="submit" class="quick-action-btn" style="width: 100%; border: none; cursor: pointer; text-align: center; display: flex; justify-content: center; align-items: center; gap: 8px; font-family: inherit; font-size: inherit;">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>

<script>
// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            document.body.classList.toggle('sidebar-open');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (!sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('show');
                document.body.classList.remove('sidebar-open');
            }
        });
    }
});
</script>

<?php
// includes/navbar.php
?>
<aside class="sidebar" role="navigation" aria-label="Main navigation">
    <div class="sidebar-header">
        <div class="logo-container">
            <i class="fas fa-graduation-cap logo-icon"></i>
            <span class="logo-text">Industrial training college</span>
        </div>
        <button class="mobile-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                <a href="index.php">
                    <i class="fas fa-home"></i> <span>Dashboard</span>
                </a>
            </li>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'courseReg.php' ? 'active' : ''; ?>">
                <a href="courseReg.php">
                    <i class="fas fa-book-open"></i> <span>Course Registration</span>
                </a>
            </li>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'exams.php' ? 'active' : ''; ?>">
                <a href="exams.php">
                    <i class="fas fa-edit"></i> <span>Exams</span>
                </a>
            </li>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'results.php' ? 'active' : ''; ?>">
                <a href="results.php">
                    <i class="fas fa-poll"></i> <span>Results</span>
                </a>
            </li>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'finances.php' ? 'active' : ''; ?>">
                <a href="finances.php">
                    <i class="fas fa-wallet"></i> <span>Finances</span>
                </a>
            </li>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                <a href="editProfile.php?update=<?php echo urlencode($_SESSION['Sid'] ?? ''); ?>">
                    <i class="fas fa-user"></i> <span>My Profile</span>
                </a>
            </li>
            <li>
                <a href="studentLogout.php">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</aside>

<!-- Sidebar styles are in assets/css/layout.css (loaded via assets/css/main.css) -->

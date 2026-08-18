<?php
session_start();
// Add your authentication check here
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITC Portal</title>
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <!-- Add Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2>ITC Portal</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="index.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="semesterReg_stud.php"><i class="fas fa-calendar-alt"></i> Semester Registration</a></li>
            <li><a href="studentAccount.php"><i class="fas fa-user-cog"></i> Account Settings</a></li>
            <li><a href="studentPasswordReset.php"><i class="fas fa-key"></i> Reset Password</a></li>
            <li><a href="logout.php?to=staff"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="content-header">
            <h1>Welcome to ITC Portal</h1>
            <p>Manage your academic journey with ease</p>
        </div>
        <div class="content-body">
            <!-- Content will be injected here -->
            <?php
            if (isset($content)) {
                echo $content;
            }
            ?>
        </div>
    </main>

    <!-- Mobile Menu Toggle Button -->
    <button id="sidebarToggle" class="sidebar-toggle">
        <i class="fas fa-bars"></i>
    </button>

    <script>
        // Sidebar Toggle for Mobile
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('show');
            document.querySelector('.main-content').classList.toggle('sidebar-active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !sidebarToggle.contains(event.target) &&
                sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
                document.querySelector('.main-content').classList.remove('sidebar-active');
            }
        });
    </script>
</body>
</html> 
<!-- Modern Bootstrap 5 Student Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-success shadow-sm sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="student_dashboard.php">
        <i class="fas fa-graduation-cap me-2"></i>ITC Student
    </a>
    
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#studentNavbar" 
            aria-controls="studentNavbar" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="studentNavbar">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link" href="student_dashboard.php">
            <i class="fas fa-tachometer-alt me-1"></i> Dashboard
          </a>
        </li>
        
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fas fa-book me-1"></i> My Courses
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="MyCourses.php">BCOM112</a></li>
            <li><a class="dropdown-item" href="#">BBA110</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fas fa-pencil-alt me-1"></i> Academic
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="#">Semester Registration</a></li>
            <li><a class="dropdown-item" href="#">Academic Calendar</a></li>
          </ul>
        </li>

        <li class="nav-item">
            <a class="nav-link" href="student_fees.php">
                <i class="fas fa-wallet me-1"></i> Fees
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="final_results.php">
                <i class="fas fa-chart-line me-1"></i> Results
            </a>
        </li>
      </ul>
      
      <ul class="navbar-nav ms-auto">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <img src="images/college.jpg" alt="Profile" class="rounded-circle me-2" width="32" height="32" style="object-fit:cover; border: 2px solid rgba(255,255,255,0.5);">
            <span>Mulenga Yumba</span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><span class="dropdown-header">Student Account</span></li>
            <li><a class="dropdown-item" href="engineering.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
            <li><a class="dropdown-item" href="construction.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="index.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<!-- Additional Custom Styles for Navbar -->
<style>
.navbar-dark .navbar-nav .nav-link {
    font-size: 0.95rem;
    font-weight: 500;
    padding-left: 1rem;
    padding-right: 1rem;
}
.navbar-dark .navbar-nav .nav-link:hover {
    background-color: rgba(255,255,255,0.1);
    border-radius: 0.25rem;
}
.dropdown-menu {
    border: none;
    border-radius: 0.5rem;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
}
</style>
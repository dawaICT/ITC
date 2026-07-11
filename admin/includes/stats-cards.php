<?php
// Function to format numbers (e.g., 1000 to 1K)
function formatNumber($number) {
    if ($number >= 1000000) {
        return round($number / 1000000, 1) . 'M';
    }
    if ($number >= 1000) {
        return round($number / 1000, 1) . 'K';
    }
    return $number;
}

// Get statistics from database
function getStatistics($db) {
    $stats = [];

    // Total Students
    $result = $db->query("SELECT COUNT(*) as total FROM students");
    $stats['total_students'] = $result->fetch_object()->total;

    // Total Enrolled This Semester
    $result = $db->query("SELECT COUNT(*) as total FROM semester_registration WHERE semester = (SELECT MAX(semester) FROM semester_registration)");
    $stats['enrolled_this_sem'] = $result->fetch_object()->total;

    // Total Programs
    $result = $db->query("SELECT COUNT(*) as total FROM programs");
    $stats['total_programs'] = $result->fetch_object()->total;

    // Total Departments
    $result = $db->query("SELECT COUNT(*) as total FROM departments");
    $stats['total_departments'] = $result->fetch_object()->total;

    return $stats;
}

// Get the statistics
$stats = getStatistics($db);
?>

<!-- Statistics Grid -->
<link rel="stylesheet" href="css/stats-cards.css">

<div class="stats-section">
    <div class="stats-grid">
        <!-- Total Students Card -->
        <div class="stat-card theme-purple">
            <div class="stat-icon">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="stat-value"><?php echo formatNumber($stats['total_students']); ?></div>
            <div class="stat-label">Total Students</div>
            <div class="stat-trend trend-up">
                <i class="bi bi-graph-up-arrow"></i>
                <span>12% increase</span>
            </div>
            <div class="stat-footer">
                Updated today
            </div>
        </div>

        <!-- Enrolled This Semester Card -->
        <div class="stat-card theme-blue">
            <div class="stat-icon">
                <i class="bi bi-person-check-fill"></i>
            </div>
            <div class="stat-value"><?php echo formatNumber($stats['enrolled_this_sem']); ?></div>
            <div class="stat-label">Enrolled This Semester</div>
            <div class="stat-trend trend-up">
                <i class="bi bi-graph-up-arrow"></i>
                <span>5% increase</span>
            </div>
            <div class="stat-footer">
                Current semester
            </div>
        </div>

        <!-- Total Programs Card -->
        <div class="stat-card theme-green">
            <div class="stat-icon">
                <i class="bi bi-book-fill"></i>
            </div>
            <div class="stat-value"><?php echo formatNumber($stats['total_programs']); ?></div>
            <div class="stat-label">Total Programs</div>
            <div class="stat-trend">
                <i class="bi bi-dash"></i>
                <span>Stable</span>
            </div>
            <div class="stat-footer">
                Active programs
            </div>
        </div>

        <!-- Total Departments Card -->
        <div class="stat-card theme-orange">
            <div class="stat-icon">
                <i class="bi bi-building"></i>
            </div>
            <div class="stat-value"><?php echo formatNumber($stats['total_departments']); ?></div>
            <div class="stat-label">Departments</div>
            <div class="stat-trend">
                <i class="bi bi-dash"></i>
                <span>Stable</span>
            </div>
            <div class="stat-footer">
                Active departments
            </div>
        </div>
    </div>
</div>

<!-- Optional: Add animation script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Animate numbers on scroll
    const stats = document.querySelectorAll('.stat-value');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    });

    stats.forEach(stat => observer.observe(stat));
});
</script> 
/**
 * WUC Portal - Sidebar Toggle
 * Version: 1.0
 * Description: Handles sidebar toggle functionality for responsive design
 */
document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const sidebar = document.querySelector('.sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    const backdrop = document.querySelector('.sidebar-backdrop');
    const body = document.body;
    
    // Create backdrop if it doesn't exist
    if (!backdrop) {
        const backdropElement = document.createElement('div');
        backdropElement.className = 'sidebar-backdrop';
        body.appendChild(backdropElement);
    }
    
    // Function to toggle sidebar
    const toggleSidebar = () => {
        sidebar.classList.toggle('show');
        document.querySelector('.sidebar-backdrop').classList.toggle('show');
        body.classList.toggle('sidebar-open');
    };
    
    // Event listeners
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }
    
    document.querySelector('.sidebar-backdrop').addEventListener('click', toggleSidebar);
    
    // Prevent clicking on active links and close sidebar on navigation item click (mobile only)
    const navItems = document.querySelectorAll('.sidebar .nav-item, .sidebar .menu-item');
    
    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            // Prevent navigation if the link is already active
            if (item.classList.contains('active')) {
                e.preventDefault();
                return false;
            }
            
            // Close sidebar on mobile
            if (window.innerWidth < 992 && sidebar.classList.contains('show')) {
                toggleSidebar();
            }
        });
    });
    
    // Close sidebar when ESC key is pressed
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) {
            toggleSidebar();
        }
    });
    
    // Handle window resize
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 992 && sidebar.classList.contains('show')) {
            sidebar.classList.remove('show');
            document.querySelector('.sidebar-backdrop').classList.remove('show');
            body.classList.remove('sidebar-open');
        }
    });
});

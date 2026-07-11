/**
 * Sidebar Toggle Functionality
 * Handles mobile sidebar menu slide-in/out with smooth animations
 */
(function() {
    'use strict';

    if (window.WUC_UNIFIED_SIDEBAR_CONTROLLER) {
        return;
    }

    // Initialize sidebar toggle functionality
    const initSidebarToggle = function() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainWrapper = document.querySelector('.main-wrapper');
        const body = document.body;

        if (!sidebarToggle || !sidebar || sidebarToggle.dataset.sidebarToggleBound === 'true') return;
        sidebarToggle.dataset.sidebarToggleBound = 'true';

        // Toggle sidebar visibility on button click
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            // Toggle both 'active' and 'show' classes for CSS compatibility
            sidebar.classList.toggle('active');
            sidebar.classList.toggle('show');
            body.classList.toggle('sidebar-open');
            
            // Update ARIA accessibility attribute
            const isExpanded = sidebar.classList.contains('active');
            sidebarToggle.setAttribute('aria-expanded', isExpanded);
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth < 992) {
                const isClickInsideSidebar = sidebar.contains(e.target);
                const isClickOnToggle = sidebarToggle.contains(e.target);
                
                if (!isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    sidebar.classList.remove('show');
                    body.classList.remove('sidebar-open');
                    sidebarToggle.setAttribute('aria-expanded', 'false');
                }
            }
        });

        // Handle window resize - auto-close sidebar when switching to desktop
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (window.innerWidth >= 992) {
                    sidebar.classList.remove('active');
                    sidebar.classList.remove('show');
                    body.classList.remove('sidebar-open');
                }
            }, 250);
        });

        // Prevent body scroll when sidebar is open on mobile
        const toggleBodyScroll = function() {
            if (window.innerWidth < 992 && sidebar.classList.contains('active')) {
                body.style.overflow = 'hidden';
            } else {
                body.style.overflow = '';
            }
        };

        // Watch for sidebar class changes
        const observer = new MutationObserver(toggleBodyScroll);
        observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebarToggle);
    } else {
        initSidebarToggle();
    }
})();

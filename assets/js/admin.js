/**
 * WUC Portal Admin JS
 * Handles sidebar toggling, responsive behavior, and UI interactions
 */

document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const sidebar = document.querySelector('.sidebar');
    const pageContainer = document.querySelector('.page-container');
    const topbar = document.querySelector('.topbar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    
    // Set animation delays for sidebar menu items
    document.querySelectorAll('.sidebar-menu li').forEach((item, index) => {
        item.style.setProperty('--animation-order', index + 1);
    });
    
    // Functions
    function toggleSidebar() {
        sidebar.classList.toggle('active');
        if (window.innerWidth <= 992) {
            document.body.classList.toggle('sidebar-open');
        } else {
            pageContainer.classList.toggle('collapsed');
            topbar.classList.toggle('collapsed');
        }
    }

    function closeSidebarOnOutsideClick(e) {
        if (window.innerWidth <= 992 && 
            !sidebar.contains(e.target) && 
            !sidebarToggle.contains(e.target) &&
            sidebar.classList.contains('active')) {
            toggleSidebar();
        }
    }

    function handleResize() {
        if (window.innerWidth <= 992) {
            sidebar.classList.remove('active');
            pageContainer.classList.remove('collapsed');
            topbar.classList.remove('collapsed');
        } else {
            document.body.classList.remove('sidebar-open');
        }
    }
    
    // Add page transition effect
    function addPageTransition() {
        const transition = document.createElement('div');
        transition.className = 'page-transition';
        document.body.appendChild(transition);
        
        setTimeout(() => {
            transition.style.opacity = '0';
            setTimeout(() => {
                transition.remove();
            }, 500);
        }, 50);
    }
    
    // Make tables and cards appear with animation
    function addEntryAnimations() {
        const tables = document.querySelectorAll('.table-responsive');
        const cards = document.querySelectorAll('.card');
        
        tables.forEach((table, index) => {
            table.style.animationDelay = `${index * 0.1}s`;
            table.classList.add('animate-in');
        });
        
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
            card.classList.add('animate-in');
        });
    }

    // Event listeners
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }
    
    document.addEventListener('click', closeSidebarOnOutsideClick);
    window.addEventListener('resize', handleResize);
    
    // Add page transition on page load
    addPageTransition();
    
    // Add entry animations
    addEntryAnimations();
    
    // Add page transition for internal links and prevent active link refresh
    document.querySelectorAll('a').forEach(link => {
        if (link.href && link.href.includes(window.location.origin) && !link.href.includes('#')) {
            link.addEventListener('click', function(e) {
                const target = this.getAttribute('href');
                if (target && !target.startsWith('#') && !this.getAttribute('target')) {
                    e.preventDefault();
                    const transition = document.createElement('div');
                    transition.className = 'page-transition-out';
                    document.body.appendChild(transition);
                    
                    setTimeout(() => {
                        window.location.href = target;
                    }, 300);
                }
            });
        }
    });

    // DataTables initialization (if present)
    if ($.fn.DataTable) {
        $('.datatable').DataTable({
            responsive: true,
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf', 'print'
            ]
        });
    }

    // Initialize Bootstrap tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    if (tooltipTriggerList.length) {
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    // Add smooth scrolling to internal links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href !== "#") {
                e.preventDefault();
                document.querySelector(href).scrollIntoView({
                    behavior: 'smooth'
                });
            }
        });
    });

    // Collapsible card functionality
    document.querySelectorAll('.card-header .collapse-icon').forEach(icon => {
        icon.addEventListener('click', function() {
            const card = this.closest('.card');
            const cardBody = card.querySelector('.card-body');
            const cardFooter = card.querySelector('.card-footer');
            
            this.classList.toggle('collapsed');
            
            if (cardBody) {
                cardBody.style.display = cardBody.style.display === 'none' ? 'block' : 'none';
            }
            
            if (cardFooter) {
                cardFooter.style.display = cardFooter.style.display === 'none' ? 'block' : 'none';
            }
        });
    });
}); 

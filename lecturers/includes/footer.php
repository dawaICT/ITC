<?php
// Lecturers module footer
// Closes the main-wrapper divs opened by nav_unified.php and the HTML document
?>
	</div><!-- End main-content -->
</div><!-- End main-wrapper -->

<!-- Sidebar Backdrop -->
<div class="sidebar-backdrop"></div>

<script>
(function(){
    var toggle = document.querySelector('.sidebar-toggle');
    var sidebar = document.querySelector('.sidebar');
    var backdrop = document.querySelector('.sidebar-backdrop');
    function setSidebarState(open){
        if(!sidebar) return;
        sidebar.classList.toggle('show', open);
        sidebar.classList.toggle('active', open);
        document.body.classList.toggle('sidebar-open', open);
        document.body.style.overflow = open ? 'hidden' : '';
        if(toggle){ toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        if(backdrop){ backdrop.classList.toggle('show', open); }
    }
    function toggleSidebar(){
        if(!sidebar) return;
        setSidebarState(!sidebar.classList.contains('show') && !sidebar.classList.contains('active'));
    }
    if (toggle && sidebar && toggle.dataset.sidebarToggleBound !== 'true') {
        toggle.dataset.sidebarToggleBound = 'true';
        toggle.addEventListener('click', function(e){ e.preventDefault(); toggleSidebar(); });
    }
    if (backdrop && backdrop.dataset.sidebarBackdropBound !== 'true') {
        backdrop.dataset.sidebarBackdropBound = 'true';
        backdrop.addEventListener('click', function(){ setSidebarState(false); });
    }
    
    // Prevent navigation when clicking on already active links
    document.querySelectorAll('.sidebar .nav-item, .sidebar .menu-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
            if (item.classList.contains('active')) {
                e.preventDefault();
                return false;
            }
        });
    });

    // Bootstrap "needs-validation" handler. Several lecturer pages use
    // <form class="needs-validation" novalidate> (viewCourse, post_assign,
    // myStudent, viewCaRes, grade_assignment) but no script ever enabled it,
    // so `novalidate` silently DISABLED native validation: required fields and
    // .invalid-feedback never fired and empty forms submitted to a server-side
    // dead-end. This restores client validation across the whole module.
    document.querySelectorAll('form.needs-validation').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>
</body>
</html>


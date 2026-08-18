<?php
// Lecturers module footer
// Closes the main-wrapper divs opened by nav_unified.php and the HTML document
?>
	</div><!-- End main-content -->
</div><!-- End main-wrapper -->


<script>
(function(){
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


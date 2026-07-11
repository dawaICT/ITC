<?php
// Admin module footer
// Closes the main-wrapper divs opened by nav_unified.php and the HTML document
?>
	</div><!-- End main-content -->
</div><!-- End main-wrapper -->
<?php
if (!empty($admin_footer_before_body_close) && is_string($admin_footer_before_body_close)) {
    echo $admin_footer_before_body_close;
}
?>
<script>
/*
 * Modal stacking fix (admin-wide).
 * The unified layout wraps page content in .main-content, which has
 * `position: relative; z-index: 1` (css/unified-sidebar.css) and therefore
 * creates a stacking context. A Bootstrap modal authored inside that wrapper
 * gets trapped BELOW the body-level .modal-backdrop, so the dialog looks dimmed
 * by its own overlay. Reparenting each modal to <body> on show moves it out of
 * the trapping context (Bootstrap's own guidance), so the dialog (z-index 1055)
 * renders above the backdrop (z-index 1050).
 */
document.addEventListener('show.bs.modal', function (event) {
    var modal = event.target;
    if (modal && modal.classList && modal.classList.contains('modal') && modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
}, true);
</script>
</body>
</html>

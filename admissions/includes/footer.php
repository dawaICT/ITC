<?php
// Admissions module footer
// Closes the main-wrapper divs opened by nav_unified.php and the HTML document
?>
	</div><!-- End main-content -->
</div><!-- End main-wrapper -->

<?php
// Pages may set $admissions_footer_before_body_close to emit markup (e.g. modals)
// outside the main-content/main-wrapper divs so Bootstrap stacking works correctly.
// Mirrors $admin_footer_before_body_close in admin/includes/footer.php.
if (!empty($admissions_footer_before_body_close) && is_string($admissions_footer_before_body_close)) {
    echo $admissions_footer_before_body_close;
}
?>
<script>
/*
 * Modal stacking fix (admissions-wide).
 * The unified layout wraps page content in .main-content / .main-wrapper, which
 * have `position: relative; z-index: 1` (css/unified-sidebar.css) and therefore
 * create a stacking context. A Bootstrap modal authored inside that wrapper gets
 * trapped BELOW the body-level .modal-backdrop, so the dialog looks dimmed by its
 * own overlay and the fixed sidebar (a body-level element) shows through above it.
 * Reparenting each modal to <body> on show (Bootstrap's own guidance) moves it out
 * of the trapping context, so the dialog (z-index 1055) renders above the backdrop
 * (z-index 1050). Mirrors the fix in admin/includes/footer.php.
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

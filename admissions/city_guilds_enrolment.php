<?php
/**
 * City & Guilds — Enrolment & Units (Admissions view).
 *
 * This is the admissions-chrome counterpart to admin/city_guilds_enrolment.php.
 * It shares the EXACT same backend controller (admin/includes/city_guilds_page.php)
 * — access gate (canManageCityGuilds, which includes admission officers), CSV
 * export, every POST action, and the data set — and the same form body partial.
 * Only the surrounding navigation/branding differs, so an admissions officer is
 * no longer dropped into the Systems Administrator layout.
 *
 * The controller emits no HTML and exits on POST/export, so it must run before
 * the admissions nav (which renders the page chrome and enforces admissions
 * access). POSTs redirect back here via basename($_SERVER['PHP_SELF']).
 */

require_once dirname(__DIR__) . '/admin/includes/city_guilds_page.php';

$page_title = 'City & Guilds Enrolment';
require __DIR__ . '/includes/nav.php';
?>

<main class="content-wrapper pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4 portal-dashboard">

  <div class="dashboard-header admissions-section mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title"><i class="fas fa-certificate me-2"></i>City &amp; Guilds Enrolment</h1>
        <p class="text-muted mb-0">Register candidates, maintain City &amp; Guilds units, and schedule unit delivery.</p>
      </div>
    </div>
  </div>

  <?php if (!empty($_SESSION['successMssg'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?php echo cg_h($_SESSION['successMssg']); unset($_SESSION['successMssg']); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['errorMssg'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <?php echo cg_h($_SESSION['errorMssg']); unset($_SESSION['errorMssg']); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <?php require dirname(__DIR__) . '/admin/includes/city_guilds_enrolment_forms.php'; ?>

</div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>

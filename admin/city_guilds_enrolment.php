<?php
// City & Guilds — Enrolment & Units (register candidate, define units, schedule).
require_once __DIR__ . '/includes/city_guilds_page.php';
require_once __DIR__ . '/includes/header.php';

$cgTitle = 'City & Guilds — Enrolment & Units';
$cgSubtitle = 'Register candidates, maintain City & Guilds units, and schedule unit delivery.';
?>

<div class="container-fluid px-4 portal-dashboard">
  <?php require __DIR__ . '/includes/city_guilds_nav.php'; ?>

  <?php require __DIR__ . '/includes/city_guilds_enrolment_forms.php'; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

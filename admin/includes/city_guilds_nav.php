<?php
/**
 * Shared chrome for the City & Guilds module pages: stylesheet, page title bar,
 * and flash messages.
 *
 * Navigation between the separate workspace files is provided by the sidebar
 * (see admin/includes/nav.php), so no in-page sub-navigation is rendered here.
 *
 * Set $cgTitle and (optionally) $cgSubtitle before including. Include AFTER
 * header.php and AFTER opening the .container-fluid wrapper.
 */

if (!isset($cgTitle)) { $cgTitle = 'City & Guilds Management'; }
if (!isset($cgSubtitle)) { $cgSubtitle = ''; }
?>
<link rel="stylesheet" href="../css/admin-dashboard.css">

<style>
  /* Word-wrapping safeguards: long values (emails, unit titles, qualification
     codes, candidate numbers) must wrap and fit rather than overflow or clip. */
  .portal-dashboard .table th,
  .portal-dashboard .table td {
    overflow-wrap: anywhere;
    word-break: break-word;
    white-space: normal;
    vertical-align: top;
  }
  .portal-dashboard small { overflow-wrap: anywhere; word-break: break-word; }
  .portal-dashboard .dashboard-title { overflow-wrap: anywhere; }

  /* Stat cards: in the tight 6-across grid the icon must not squash and the
     number/label must wrap and fit rather than overflow the card. */
  .portal-dashboard .stat-card { min-width: 0; padding: 1.1rem; }
  .portal-dashboard .stat-card .d-flex { min-width: 0; width: 100%; }
  .portal-dashboard .stat-card .d-flex > div { min-width: 0; }
  .portal-dashboard .stat-icon { flex: 0 0 auto; }
  .portal-dashboard .stat-card h3 {
    font-size: 1.15rem;
    line-height: 1.1;
    margin-bottom: 0.15rem;
    overflow-wrap: anywhere;
    word-break: break-word;
  }
  .portal-dashboard .stat-card p {
    font-size: 0.8rem;
    line-height: 1.2;
    overflow-wrap: anywhere;
    word-break: break-word;
  }

  /* Card headers with an action button: wrap on narrow widths so the title and
     the "view all" button never overflow the header bar. */
  .portal-dashboard .card-header.d-flex { flex-wrap: wrap; gap: .5rem; }
  .portal-dashboard .card-header h5 { overflow-wrap: anywhere; min-width: 0; }
</style>

<div class="dashboard-header admin-section mb-4">
  <div class="row align-items-center">
    <div class="col">
      <h1 class="dashboard-title"><i class="fas fa-certificate me-2"></i><?php echo cg_h($cgTitle); ?></h1>
      <?php if ($cgSubtitle !== ''): ?>
        <p class="text-muted mb-0"><?php echo cg_h($cgSubtitle); ?></p>
      <?php endif; ?>
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

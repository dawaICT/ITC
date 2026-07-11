<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
require_once __DIR__ . '/../../includes/elearning_events.php';
elearning_require_role(['systems_admin','lecturer','head_of_department','dean']);

require_once __DIR__ . '/../../db/connect.php';

$engagement = get_recent_event_counts($db, 14);
$inactive = get_inactive_students($db, 14, 50);

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../css/admin-dashboard.css" />
<div class="container-fluid px-4 portal-dashboard">
  <h2 class="mb-3">Learning Analytics</h2>
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">Engagement (last 14 days)</div>
        <div class="card-body">
          <?php if (empty($engagement)): ?>
            <p class="text-muted">No events yet.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($engagement as $type => $cnt): ?>
                <li><strong><?php echo htmlspecialchars($type); ?></strong>: <?php echo (int)$cnt; ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">Potentially at-risk (inactive 14+ days)</div>
        <div class="card-body table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light"><tr><th>SID</th><th>Name</th></tr></thead>
            <tbody>
              <?php foreach ($inactive as $s): ?>
                <tr>
                  <td><?php echo htmlspecialchars($s['Sid']); ?></td>
                  <td><?php echo htmlspecialchars(($s['Fname'] ?? '') . ' ' . ($s['Lname'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>




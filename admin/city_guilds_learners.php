<?php
// City & Guilds — Learner progress dashboard.
require_once __DIR__ . '/includes/city_guilds_page.php';
require_once __DIR__ . '/includes/header.php';

$cgTitle = 'City & Guilds — Learners';
$cgSubtitle = 'Progress dashboard across all registered City & Guilds learners.';
?>

<div class="container-fluid px-4 portal-dashboard">
  <?php require __DIR__ . '/includes/city_guilds_nav.php'; ?>

  <div class="data-table-card">
    <div class="card-header">
      <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Learner Progress Dashboard</h5>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Candidate</th>
              <th>Student</th>
              <th>Qualification</th>
              <th>Cohort</th>
              <th>Progress</th>
              <th>Contact</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($learners)): ?>
              <tr><td colspan="6" class="text-muted">No City & Guilds learners are registered yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($learners as $learner): ?>
              <?php
                $total = (int)($learner['assessments_total'] ?? 0);
                $verified = (int)($learner['assessments_verified'] ?? 0);
                $percent = $total > 0 ? (int)round(($verified / $total) * 100) : 0;
              ?>
              <tr>
                <td><strong><?php echo cg_h($learner['candidate_number']); ?></strong></td>
                <td>
                  <?php echo cg_h(trim(($learner['Fname'] ?? '') . ' ' . ($learner['Lname'] ?? ''))); ?><br>
                  <small class="text-muted"><?php echo cg_h($learner['SID']); ?></small>
                </td>
                <td><?php echo cg_h($learner['qualification_code']); ?></td>
                <td><?php echo cg_h($learner['cohort']); ?></td>
                <td style="min-width: 180px;">
                  <div class="d-flex justify-content-between small mb-1">
                    <span><?php echo cg_h($learner['progress_status']); ?></span>
                    <span><?php echo $percent; ?>%</span>
                  </div>
                  <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $percent; ?>%;" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                  </div>
                </td>
                <td>
                  <small><?php echo cg_h($learner['email']); ?></small><br>
                  <small class="text-muted"><?php echo cg_h($learner['mobile']); ?></small>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
// City & Guilds — Overview (landing page for the module).
require_once __DIR__ . '/includes/city_guilds_page.php';
require_once __DIR__ . '/includes/header.php';

$cgTitle = 'City & Guilds Management';
$cgSubtitle = 'Candidate registration, unit scheduling, assessment verification, support, QA evidence, and Walled Garden CSV exports.';
?>

<div class="container-fluid px-4 portal-dashboard">
  <?php require __DIR__ . '/includes/city_guilds_nav.php'; ?>

  <div class="row g-3 mb-4">
    <div class="col-xl-2 col-md-4 col-6">
      <div class="stat-card h-100">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-primary"><i class="fas fa-users text-white"></i></div>
          <div><h3><?php echo (int)$counts['learners']; ?></h3><p class="text-muted mb-0">Learners</p></div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
      <div class="stat-card h-100">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-success"><i class="fas fa-user-check text-white"></i></div>
          <div><h3><?php echo (int)$counts['active']; ?></h3><p class="text-muted mb-0">Active</p></div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
      <div class="stat-card h-100">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-info"><i class="fas fa-award text-white"></i></div>
          <div><h3><?php echo (int)$counts['completed']; ?></h3><p class="text-muted mb-0">Completed</p></div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
      <div class="stat-card h-100">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-warning"><i class="fas fa-clipboard-check text-white"></i></div>
          <div><h3><?php echo (int)$counts['pending_verification']; ?></h3><p class="text-muted mb-0">Pending IQA</p></div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
      <div class="stat-card h-100">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-secondary"><i class="fas fa-hands-helping text-white"></i></div>
          <div><h3><?php echo (int)$counts['support_followups']; ?></h3><p class="text-muted mb-0">Follow-ups</p></div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
      <div class="stat-card h-100">
        <div class="d-flex align-items-center">
          <div class="stat-icon bg-dark"><i class="fas fa-folder-open text-white"></i></div>
          <div><h3><?php echo (int)$counts['qa_records']; ?></h3><p class="text-muted mb-0">QA Records</p></div>
        </div>
      </div>
    </div>
  </div>

  <?php
    // Read-only "recent activity" previews for the landing page. The full data
    // lives on the dedicated workspace pages; here we show the latest few.
    $recentLearners = array_slice($learners, 0, 6);
    $recentPending = array_slice($pendingAssessments, 0, 6);
    $recentSchedule = array_slice($assignments, 0, 6);
    $recentQa = array_slice($qaRecords, 0, 6);
  ?>

  <div class="row g-4">
    <div class="col-xl-6">
      <div class="data-table-card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Recent Learners</h5>
          <a href="city_guilds_learners.php" class="btn btn-sm btn-light">View all <i class="fas fa-arrow-right ms-1"></i></a>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr><th>Candidate</th><th>Student</th><th>Qualification</th><th>Progress</th></tr>
              </thead>
              <tbody>
                <?php if (empty($recentLearners)): ?>
                  <tr><td colspan="4" class="text-muted">No learners registered yet. Start on the <a href="city_guilds_enrolment.php">Enrolment &amp; Units</a> page.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentLearners as $learner): ?>
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
                    <td style="min-width: 130px;">
                      <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted"><?php echo cg_h($learner['progress_status']); ?></span>
                        <span><?php echo $percent; ?>%</span>
                      </div>
                      <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $percent; ?>%;" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-6">
      <div class="data-table-card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Pending Internal Verification</h5>
          <a href="city_guilds_verification.php" class="btn btn-sm btn-light">Verify <i class="fas fa-arrow-right ms-1"></i></a>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr><th>Learner</th><th>Assessment</th><th>Due</th></tr>
              </thead>
              <tbody>
                <?php if (empty($recentPending)): ?>
                  <tr><td colspan="3" class="text-muted">Nothing awaiting verification.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentPending as $assessment): ?>
                  <tr>
                    <td>
                      <strong><?php echo cg_h($assessment['candidate_number']); ?></strong><br>
                      <small class="text-muted"><?php echo cg_h(trim(($assessment['Fname'] ?? '') . ' ' . ($assessment['Lname'] ?? ''))); ?></small>
                    </td>
                    <td>
                      <?php echo cg_h($assessment['title']); ?><br>
                      <small class="text-muted"><?php echo cg_h($assessment['assessment_type'] . ' / ' . $assessment['assessment_mode']); ?></small>
                    </td>
                    <td><?php echo cg_h($assessment['date_due'] ? date('Y-m-d', strtotime($assessment['date_due'])) : 'N/A'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mt-1">
    <div class="col-xl-6">
      <div class="data-table-card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Recent Unit Schedule</h5>
          <a href="city_guilds_enrolment.php" class="btn btn-sm btn-light">Schedule <i class="fas fa-arrow-right ms-1"></i></a>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr><th>Learner</th><th>Unit</th><th>Start</th></tr>
              </thead>
              <tbody>
                <?php if (empty($recentSchedule)): ?>
                  <tr><td colspan="3" class="text-muted">No units scheduled yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentSchedule as $assignment): ?>
                  <tr>
                    <td>
                      <strong><?php echo cg_h($assignment['candidate_number']); ?></strong><br>
                      <small class="text-muted"><?php echo cg_h(trim(($assignment['Fname'] ?? '') . ' ' . ($assignment['Lname'] ?? ''))); ?></small>
                    </td>
                    <td><?php echo cg_h(trim(($assignment['unit_code'] ?? '') . ' ' . ($assignment['unit_title'] ?? '')) ?: 'Unit'); ?></td>
                    <td><?php echo cg_h($assignment['schedule_start'] ? date('Y-m-d H:i', strtotime($assignment['schedule_start'])) : 'TBC'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-6">
      <div class="data-table-card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Recent QA Evidence</h5>
          <a href="city_guilds_verification.php" class="btn btn-sm btn-light">Manage <i class="fas fa-arrow-right ms-1"></i></a>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr><th>Type</th><th>Title</th><th>Date</th></tr>
              </thead>
              <tbody>
                <?php if (empty($recentQa)): ?>
                  <tr><td colspan="3" class="text-muted">No QA records saved yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentQa as $record): ?>
                  <tr>
                    <td><?php echo cg_h(ucwords(str_replace('_', ' ', $record['record_type']))); ?></td>
                    <td><?php echo cg_h($record['title']); ?></td>
                    <td><?php echo cg_h($record['evidence_date'] ?: date('Y-m-d', strtotime($record['created_at']))); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

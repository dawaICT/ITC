<?php
// City & Guilds — Assessment & Support (record assessments, log learner support).
require_once __DIR__ . '/includes/city_guilds_page.php';
require_once __DIR__ . '/includes/header.php';

$cgTitle = 'City & Guilds — Assessment & Support';
$cgSubtitle = 'Record formative/summative assessments and capture learner support interventions.';
?>

<div class="container-fluid px-4 portal-dashboard">
  <?php require __DIR__ . '/includes/city_guilds_nav.php'; ?>

  <div class="row g-4">
    <div class="col-xl-6">
      <div class="data-table-card h-100">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Record Assessment</h5></div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <input type="hidden" name="action" value="record_assessment">
            <div class="col-md-6">
              <label class="form-label">Learner</label>
              <select name="assessment_learner_id" class="form-select" required>
                <option value="">Select learner</option>
                <?php foreach ($learners as $learner): ?>
                  <option value="<?php echo (int)$learner['id']; ?>"><?php echo cg_h($learner['candidate_number'] . ' - ' . $learner['Fname'] . ' ' . $learner['Lname']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Linked assignment</label>
              <select name="assessment_assignment_id" class="form-select">
                <option value="">No linked assignment</option>
                <?php foreach ($assignments as $assignment): ?>
                  <option value="<?php echo (int)$assignment['id']; ?>">
                    <?php echo cg_h($assignment['candidate_number'] . ' / ' . ($assignment['unit_code'] ?? 'Unit') . ' / #' . $assignment['id']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Assessment title</label>
              <input type="text" name="assessment_title" class="form-control" maxlength="190" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Type</label>
              <select name="assessment_type" class="form-select">
                <option value="FORMATIVE">Formative</option>
                <option value="SUMMATIVE">Summative</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Mode</label>
              <select name="assessment_mode" class="form-select">
                <option value="practical">Practical task</option>
                <option value="written">Written exam</option>
                <option value="portfolio">Portfolio</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Due date</label>
              <input type="datetime-local" name="date_due" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Assessment notes</label>
              <textarea name="assessment_notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-primary" type="submit"><i class="fas fa-save me-2"></i>Record Assessment</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-xl-6">
      <div class="data-table-card h-100">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-hands-helping me-2"></i>Learner Support</h5></div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <input type="hidden" name="action" value="record_support">
            <div class="col-md-6">
              <label class="form-label">Learner</label>
              <select name="support_learner_id" class="form-select" required>
                <option value="">Select learner</option>
                <?php foreach ($learners as $learner): ?>
                  <option value="<?php echo (int)$learner['id']; ?>"><?php echo cg_h($learner['candidate_number'] . ' - ' . $learner['Fname'] . ' ' . $learner['Lname']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Support type</label>
              <input type="text" name="intervention_type" class="form-control" maxlength="120" placeholder="Mentoring, catch-up, accessibility" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Support date</label>
              <input type="date" name="support_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Follow-up date</label>
              <input type="date" name="follow_up_date" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="support_notes" class="form-control" rows="3" required></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-outline-primary" type="submit"><i class="fas fa-notes-medical me-2"></i>Record Support</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

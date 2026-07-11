<?php
// City & Guilds — Verification & QA (internal verification queue + QA evidence).
require_once __DIR__ . '/includes/city_guilds_page.php';
require_once __DIR__ . '/includes/header.php';

$cgTitle = 'City & Guilds — Verification & QA';
$cgSubtitle = 'Internal verification of assessments and quality assurance evidence records.';
?>

<div class="container-fluid px-4 portal-dashboard">
  <?php require __DIR__ . '/includes/city_guilds_nav.php'; ?>

  <div class="row g-4">
    <div class="col-xl-6">
      <div class="data-table-card h-100">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-check-double me-2"></i>Internal Verification Queue</h5></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>Learner</th>
                  <th>Assessment</th>
                  <th>Due</th>
                  <th>Verify</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($pendingAssessments)): ?>
                  <tr><td colspan="4" class="text-muted">No assessments are pending verification.</td></tr>
                <?php endif; ?>
                <?php foreach ($pendingAssessments as $assessment): ?>
                  <tr>
                    <td>
                      <strong><?php echo cg_h($assessment['candidate_number']); ?></strong><br>
                      <small><?php echo cg_h(trim(($assessment['Fname'] ?? '') . ' ' . ($assessment['Lname'] ?? ''))); ?></small>
                    </td>
                    <td>
                      <?php echo cg_h($assessment['title']); ?><br>
                      <small class="text-muted"><?php echo cg_h($assessment['assessment_type'] . ' / ' . $assessment['assessment_mode']); ?></small>
                    </td>
                    <td><?php echo cg_h($assessment['date_due'] ? date('Y-m-d', strtotime($assessment['date_due'])) : 'N/A'); ?></td>
                    <td style="min-width: 260px;">
                      <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="verify_assessment">
                        <input type="hidden" name="assessment_id" value="<?php echo (int)$assessment['id']; ?>">
                        <div class="col-5"><input class="form-control form-control-sm" name="grade" placeholder="Grade" required></div>
                        <div class="col-7">
                          <select class="form-select form-select-sm" name="verifier_id">
                            <option value="<?php echo cg_h(cg_current_staff_id()); ?>">Current staff</option>
                            <?php foreach ($staff as $person): ?>
                              <option value="<?php echo cg_h($person['staff_id']); ?>"><?php echo cg_h($person['Fname'] . ' ' . $person['Lname']); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-12"><input class="form-control form-control-sm" name="verification_notes" placeholder="Verification note"></div>
                        <div class="col-12"><button class="btn btn-sm btn-success w-100" type="submit"><i class="fas fa-check me-1"></i>Verify</button></div>
                      </form>
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
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Quality Assurance Evidence</h5></div>
        <div class="card-body">
          <form method="post" class="row g-3 mb-3">
            <input type="hidden" name="action" value="save_qa">
            <div class="col-md-6">
              <label class="form-label">Record type</label>
              <select name="qa_record_type" class="form-select">
                <option value="tutor_qualification">Tutor qualification</option>
                <option value="assessment_plan">Assessment plan</option>
                <option value="iqa_report">IQA report</option>
                <option value="external_quality_assurance">External QA</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Evidence date</label>
              <input type="date" name="evidence_date" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Title</label>
              <input type="text" name="qa_title" class="form-control" maxlength="190" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Related staff</label>
              <select name="qa_staff_id" class="form-select">
                <option value="">Not staff-specific</option>
                <?php foreach ($staff as $person): ?>
                  <option value="<?php echo cg_h($person['staff_id']); ?>"><?php echo cg_h($person['Fname'] . ' ' . $person['Lname'] . ' - ' . $person['staff_id']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">File/reference</label>
              <input type="text" name="file_reference" class="form-control" maxlength="255">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="qa_notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-outline-primary" type="submit"><i class="fas fa-folder-plus me-2"></i>Save QA Record</button>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light"><tr><th>Type</th><th>Title</th><th>Date</th></tr></thead>
              <tbody>
                <?php if (empty($qaRecords)): ?>
                  <tr><td colspan="3" class="text-muted">No QA records saved yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($qaRecords as $record): ?>
                  <tr>
                    <td><?php echo cg_h(str_replace('_', ' ', $record['record_type'])); ?></td>
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

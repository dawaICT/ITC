<?php
/**
 * Shared City & Guilds Enrolment form body (Register Candidate, Unit, Assign &
 * Schedule). Rendered identically by the admin workspace
 * (admin/city_guilds_enrolment.php) and the admissions view
 * (admissions/city_guilds_enrolment.php) so both post to the SAME backend
 * controller (admin/includes/city_guilds_page.php) — only the surrounding
 * chrome differs.
 *
 * Expects from the controller: $students, $learners, $units, $staff, and cg_h().
 * The including page provides the .container-fluid wrapper and the page heading.
 */
if (!function_exists('cg_h')) { return; }
?>
<div class="row g-4">
  <div class="col-xl-4">
    <div class="data-table-card h-100">
      <div class="card-header"><h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Register Candidate</h5></div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="enroll">
          <div class="col-12">
            <label class="form-label">Existing student</label>
            <select name="SID" class="form-select" required>
              <option value="">Select student</option>
              <?php foreach ($students as $student): ?>
                <option value="<?php echo cg_h($student['SID']); ?>">
                  <?php echo cg_h(trim(($student['Fname'] ?? '') . ' ' . ($student['Lname'] ?? '')) . ' - ' . $student['SID']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Qualification code</label>
            <input type="text" name="qualification_code" class="form-control" maxlength="80" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Cohort</label>
            <input type="text" name="cohort" class="form-control" maxlength="80" placeholder="2026-JUN" required>
          </div>
          <div class="col-12">
            <label class="form-label">Candidate number</label>
            <input type="text" name="candidate_number" class="form-control" maxlength="80" required>
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
          <div class="col-12">
            <button class="btn btn-primary w-100" type="submit"><i class="fas fa-save me-2"></i>Enroll Learner</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="data-table-card h-100">
      <div class="card-header"><h5 class="mb-0"><i class="fas fa-book-open me-2"></i>City &amp; Guilds Unit</h5></div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="save_unit">
          <div class="col-md-6">
            <label class="form-label">Qualification code</label>
            <input type="text" name="unit_qualification_code" class="form-control" maxlength="80" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Unit code</label>
            <input type="text" name="unit_code" class="form-control" maxlength="80" required>
          </div>
          <div class="col-12">
            <label class="form-label">Unit title</label>
            <input type="text" name="unit_title" class="form-control" maxlength="190" required>
          </div>
          <div class="col-12">
            <label class="form-label">Syllabus reference</label>
            <input type="text" name="syllabus_reference" class="form-control" maxlength="190">
          </div>
          <div class="col-12">
            <button class="btn btn-outline-primary w-100" type="submit"><i class="fas fa-book me-2"></i>Save Unit</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="data-table-card h-100">
      <div class="card-header"><h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Assign &amp; Schedule</h5></div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="assign_unit">
          <div class="col-12">
            <label class="form-label">Learner</label>
            <select name="assignment_learner_id" class="form-select" required>
              <option value="">Select learner</option>
              <?php foreach ($learners as $learner): ?>
                <option value="<?php echo (int)$learner['id']; ?>">
                  <?php echo cg_h($learner['candidate_number'] . ' - ' . trim(($learner['Fname'] ?? '') . ' ' . ($learner['Lname'] ?? ''))); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Unit</label>
            <select name="assignment_unit_id" class="form-select" required>
              <option value="">Select unit</option>
              <?php foreach ($units as $unit): ?>
                <option value="<?php echo (int)$unit['id']; ?>">
                  <?php echo cg_h($unit['qualification_code'] . ' / ' . $unit['unit_code'] . ' - ' . $unit['unit_title']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Instructor / tutor</label>
            <select name="instructor_id" class="form-select" required>
              <option value="">Select staff</option>
              <?php foreach ($staff as $person): ?>
                <option value="<?php echo cg_h($person['staff_id']); ?>">
                  <?php echo cg_h(trim(($person['title'] ?? '') . ' ' . ($person['Fname'] ?? '') . ' ' . ($person['Lname'] ?? '')) . ' - ' . $person['staff_id']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Start</label>
            <input type="datetime-local" name="schedule_start" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">End</label>
            <input type="datetime-local" name="schedule_end" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label">Location</label>
            <input type="text" name="location" class="form-control" maxlength="150">
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea name="assignment_notes" class="form-control" rows="2"></textarea>
          </div>
          <div class="col-12">
            <button class="btn btn-outline-primary w-100" type="submit"><i class="fas fa-calendar-plus me-2"></i>Schedule Unit</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

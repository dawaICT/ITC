<!-- Registration Modal -->
<div class="modal fade" id="registrationModal" tabindex="-1" aria-labelledby="registrationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="registrationModalLabel">
                    <i class="fas fa-user-plus me-2"></i>New Student Registration
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Progress Bar -->
                <div class="mb-4">
                    <div class="progress" style="height: 3px;">
                        <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <span class="small step-indicator active" data-step="1">Personal</span>
                        <span class="small step-indicator" data-step="2">Next of Kin</span>
                        <span class="small step-indicator" data-step="3">Academic</span>
                        <span class="small step-indicator" data-step="4">Bursary</span>
                        <span class="small step-indicator" data-step="5">Documents</span>
                        <span class="small step-indicator" data-step="6">Review</span>
                    </div>
                </div>

                <form id="registrationForm" method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                    <input type="hidden" name="semester" id="semester_hidden" value="<?php echo $semester; ?>">

                    <!-- Step 1: Personal Information -->
                    <div class="step-content" id="step1">
                        <h5 class="text-primary mb-4">Personal Information</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fname" class="form-label">First Name</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="fname" name="fname" required>
                                </div>
                                <div class="invalid-feedback">Please provide a first name.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="lname" class="form-label">Last Name</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="lname" name="lname" required>
                                </div>
                                <div class="invalid-feedback">Please provide a last name.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="gender" class="form-label">Gender</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-venus-mars"></i></span>
                                    <select class="form-select" id="gender" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="M">Male</option>
                                        <option value="F">Female</option>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Please select a gender.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="dob" class="form-label">Date of Birth</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                    <input type="date" class="form-control" id="dob" name="dob" required>
                                </div>
                                <div class="invalid-feedback">Please provide a date of birth.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                                <div class="invalid-feedback">Please provide a valid email address.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="tel" class="form-control" id="phone" name="phone" required>
                                </div>
                                <div class="invalid-feedback">Please provide a phone number.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="nrc" class="form-label">NRC Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-id-badge"></i></span>
                                    <input type="text" class="form-control" id="nrc" name="nrc" required>
                                </div>
                                <div class="invalid-feedback">Please provide NRC number (must contain at least 6 digits).</div>
                            </div>

                            <div class="col-md-6">
                                <label for="address" class="form-label">Residential Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                    <input type="text" class="form-control" id="address" name="address">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Next of Kin Information -->
                    <div class="step-content d-none" id="step2">
                        <h5 class="text-primary mb-4">Next of Kin Information</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="nok_fname" class="form-label">First Name</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="nok_fname" name="nok_fname" required>
                                </div>
                                <div class="invalid-feedback">Please provide next of kin's first name.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="nok_lname" class="form-label">Last Name</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="nok_lname" name="nok_lname" required>
                                </div>
                                <div class="invalid-feedback">Please provide next of kin's last name.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="nok_relationship" class="form-label">Relationship</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-heart"></i></span>
                                    <select class="form-select" id="nok_relationship" name="nok_relationship" required>
                                        <option value="">Select Relationship</option>
                                        <option value="Parent">Parent</option>
                                        <option value="Sibling">Sibling</option>
                                        <option value="Spouse">Spouse</option>
                                        <option value="Guardian">Guardian</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Please select a relationship.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="nok_phone" class="form-label">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="tel" class="form-control" id="nok_phone" name="nok_phone" required>
                                </div>
                                <div class="invalid-feedback">Please provide next of kin's phone number.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="nok_email" class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control" id="nok_email" name="nok_email">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="nok_address" class="form-label">Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                    <input type="text" class="form-control" id="nok_address" name="nok_address">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Academic Information -->
                    <div class="step-content d-none" id="step3">
                        <h5 class="text-primary mb-4">Academic Information</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="program" class="form-label">Program</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-graduation-cap"></i></span>
                                    <select class="form-select" id="program" name="program" required>
                                        <option value="">Select Program</option>
                                        <?php foreach($programs as $program): ?>
                                            <option value="<?php echo htmlspecialchars($program->program_code); ?>">
                                                <?php echo htmlspecialchars($program->program_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Please select a program.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="academic_year" class="form-label">Academic Year</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                    <input type="text" class="form-control" id="academic_year" 
                                           value="<?php echo $academic_year; ?>" readonly>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="semester_term_select" class="form-label">
                                    <span id="semester_term_label">Semester/Term</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                    <select class="form-select" id="semester_term_select" name="semester" required>
                                        <option value="">Select Program First</option>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Please select a semester/term.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="previous_school" class="form-label">Previous School/Institution</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-school"></i></span>
                                    <input type="text" class="form-control" id="previous_school" name="previous_school">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="qualification" class="form-label">Entry Qualification</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-certificate"></i></span>
                                    <select class="form-select" id="qualification" name="qualification">
                                        <option value="">Select Qualification</option>
                                        <option value="Grade 12">Grade 12</option>
                                        <option value="Certificate">Certificate</option>
                                        <option value="Diploma">Diploma</option>
                                        <option value="Degree">Degree</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="entry_year" class="form-label">Year of Entry</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                    <input type="number" class="form-control" id="entry_year" name="entry_year" min="1990" max="2030">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: Bursary Information -->
                    <div class="step-content d-none" id="step4">
                        <h5 class="text-primary mb-4">Bursary Information</h5>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="bursary_percentage" class="form-label">Bursary Percentage</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-percent"></i></span>
                                    <select class="form-select" id="bursary_percentage" name="bursary_percentage">
                                        <option value="0">0% - No Bursary</option>
                                        <option value="25">25%</option>
                                        <option value="50">50%</option>
                                        <option value="75">75%</option>
                                        <option value="100">100% - Full Bursary</option>
                                    </select>
                                </div>
                                <small class="text-muted">Select the bursary percentage to be applied to fees</small>
                            </div>

                            <div class="col-md-12">
                                <div class="alert alert-info" role="alert">
                                    <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Fee Calculation</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Original Total Fees:</strong>
                                            <div id="original_fees">ZMW 0.00</div>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Subsidy Amount:</strong>
                                            <div id="subsidy_amount">ZMW 0.00</div>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <strong>Student Pays:</strong>
                                            <div class="text-success fw-bold" id="student_amount">ZMW 0.00</div>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Bursary Account Credits:</strong>
                                            <div class="text-primary fw-bold" id="bursary_account">ZMW 0.00</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 5: Document Uploads -->
                    <div class="step-content d-none" id="step5">
                        <h5 class="text-primary mb-4">Document Uploads</h5>
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i>Upload clear copies of your documents. Maximum file size: 5MB. Accepted formats: PDF, JPG, PNG
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="profile_photo" class="form-label">Profile Photo</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-image"></i></span>
                                    <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png" required>
                                </div>
                                <small class="text-muted">JPG or PNG (5MB max)</small>
                                <div class="invalid-feedback">Please upload a profile photo.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="academic_results" class="form-label">Academic Results/Transcript</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-file-pdf"></i></span>
                                    <input type="file" class="form-control" id="academic_results" name="academic_results" accept="application/pdf,image/jpeg,image/png" required>
                                </div>
                                <small class="text-muted">PDF, JPG or PNG (5MB max)</small>
                                <div class="invalid-feedback">Please upload your academic results.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="id_copy" class="form-label">National ID / Passport Copy</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                    <input type="file" class="form-control" id="id_copy" name="id_copy" accept="application/pdf,image/jpeg,image/png">
                                </div>
                                <small class="text-muted">PDF, JPG or PNG (5MB max)</small>
                            </div>

                            <div class="col-md-6">
                                <label for="certificate" class="form-label">Entry Certificate/Qualification</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-certificate"></i></span>
                                    <input type="file" class="form-control" id="certificate" name="certificate" accept="application/pdf,image/jpeg,image/png">
                                </div>
                                <small class="text-muted">PDF, JPG or PNG (5MB max)</small>
                            </div>
                        </div>
                    </div>

                    <!-- Step 6: Review Information -->
                    <div class="step-content d-none" id="step6">
                        <h5 class="text-primary mb-4">Review Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <strong><i class="fas fa-user"></i> Personal Information</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-hover align-middle">
                                            <tr>
                                                <td><strong>Student ID:</strong></td>
                                                <td><span id="review_student_id" class="badge bg-primary">Auto-generated</span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Name:</strong></td>
                                                <td><span id="review_name"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Gender:</strong></td>
                                                <td><span id="review_gender"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Date of Birth:</strong></td>
                                                <td><span id="review_dob"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Email:</strong></td>
                                                <td><span id="review_email"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Phone:</strong></td>
                                                <td><span id="review_phone"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>NRC:</strong></td>
                                                <td><span id="review_nrc"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Address:</strong></td>
                                                <td><span id="review_address"></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <strong><i class="fas fa-heart"></i> Next of Kin</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-hover align-middle">
                                            <tr>
                                                <td><strong>Name:</strong></td>
                                                <td><span id="review_nok_name"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Relationship:</strong></td>
                                                <td><span id="review_nok_relationship"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Phone:</strong></td>
                                                <td><span id="review_nok_phone"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Email:</strong></td>
                                                <td><span id="review_nok_email"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Address:</strong></td>
                                                <td><span id="review_nok_address"></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mt-3">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <strong><i class="fas fa-graduation-cap"></i> Academic Information</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-hover align-middle">
                                            <tr>
                                                <td><strong>Program:</strong></td>
                                                <td><span id="review_program"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Academic Year:</strong></td>
                                                <td><span id="review_academic_year"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Semester/Term:</strong></td>
                                                <td><span id="review_semester"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Qualification:</strong></td>
                                                <td><span id="review_qualification"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Previous School:</strong></td>
                                                <td><span id="review_previous_school"></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mt-3">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <strong><i class="fas fa-percent"></i> Bursary Information</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-hover align-middle">
                                            <tr>
                                                <td><strong>Bursary %:</strong></td>
                                                <td><span id="review_bursary_percentage" class="badge bg-info"></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <strong><i class="fas fa-file-upload"></i> Documents</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-hover align-middle">
                                            <tr>
                                                <td><strong>Profile Photo:</strong></td>
                                                <td><span id="review_profile_photo"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Academic Results:</strong></td>
                                                <td><span id="review_academic_results"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>ID Copy:</strong></td>
                                                <td><span id="review_id_copy"></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Certificate:</strong></td>
                                                <td><span id="review_certificate"></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <h6 class="mb-3">Selected Courses</h6>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Course Code</th>
                                                <th>Course Name</th>
                                                <th>Credits</th>
                                                <th class="text-end">Fee (ZMW)</th>
                                            </tr>
                                        </thead>
                                        <tbody id="courseList">
                                            <tr>
                                                <td colspan="4" class="text-center text-muted">Select program to view courses</td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="3" class="text-end">Total Fees:</th>
                                                <th class="text-end">ZMW <span id="totalFee">0.00</span></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <div class="card bg-light-info border-info">
                                    <div class="card-header bg-info text-white">
                                        <strong><i class="fas fa-calculator"></i> Fee Summary with Bursary</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-hover align-middle mb-0">
                                            <tr>
                                                <td><strong>Original Total Fees:</strong></td>
                                                <td class="text-end"><span id="summary_original_fees">ZMW 0.00</span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Subsidy Amount:</strong></td>
                                                <td class="text-end text-danger"><span id="summary_subsidy_amount">- ZMW 0.00</span></td>
                                            </tr>
                                            <tr class="table-active">
                                                <td><strong>Amount Student Pays:</strong></td>
                                                <td class="text-end"><strong class="text-success"><span id="summary_student_amount">ZMW 0.00</span></strong></td>
                                            </tr>
                                            <tr class="table-active">
                                                <td><strong>Bursary Account Credits:</strong></td>
                                                <td class="text-end"><strong class="text-primary"><span id="summary_bursary_account">ZMW 0.00</span></strong></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <div class="alert alert-info" role="alert">
                                    <i class="fas fa-info-circle"></i> Please review all information carefully before submitting. You will not be able to edit after submission.
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="prevStep" disabled>
                    <i class="fas fa-arrow-left me-2"></i>Previous
                </button>
                <button type="button" class="btn btn-primary" id="nextStep">
                    Next<i class="fas fa-arrow-right ms-2"></i>
                </button>
                <button type="submit" form="registrationForm" class="btn btn-success d-none" id="submitBtn">
                    <i class="fas fa-save me-2"></i>Register Student
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.step-indicator {
    color: #6c757d;
    position: relative;
    padding-top: 10px;
}

.step-indicator.active {
    color: #0d6efd;
    font-weight: 600;
}

.step-indicator.completed {
    color: #198754;
}

.step-indicator::before {
    content: '';
    position: absolute;
    top: -3px;
    left: 50%;
    transform: translateX(-50%);
    width: 12px;
    height: 12px;
    background-color: #fff;
    border: 2px solid #6c757d;
    border-radius: 50%;
}

.step-indicator.active::before {
    border-color: #0d6efd;
    background-color: #0d6efd;
}

.step-indicator.completed::before {
    border-color: #198754;
    background-color: #198754;
}

.modal-xl {
    max-width: 1140px;
}

@media (max-width: 768px) {
    .step-indicator {
        font-size: 0.75rem;
    }
}
</style>

<script>
$(document).ready(function() {
    let currentStep = 1;
    const totalSteps = 6;

    // Initialize form validation
    const form = document.getElementById('registrationForm');

    // File upload validation and preview
    $('#profile_photo').change(function() {
        const file = this.files[0];
        if (file) {
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!allowedTypes.includes(file.type)) {
                alert('Profile photo must be JPG or PNG');
                $(this).val('');
                return;
            }
            if (file.size > maxSize) {
                alert('Profile photo must be less than 5MB');
                $(this).val('');
                return;
            }
        }
    });

    $('#academic_results').change(function() {
        const file = this.files[0];
        if (file) {
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!allowedTypes.includes(file.type)) {
                alert('Academic results must be PDF, JPG, or PNG');
                $(this).val('');
                return;
            }
            if (file.size > maxSize) {
                alert('Academic results must be less than 5MB');
                $(this).val('');
                return;
            }
        }
    });

    $('#id_copy').change(function() {
        const file = this.files[0];
        if (file) {
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!allowedTypes.includes(file.type)) {
                alert('ID copy must be PDF, JPG, or PNG');
                $(this).val('');
                return;
            }
            if (file.size > maxSize) {
                alert('ID copy must be less than 5MB');
                $(this).val('');
                return;
            }
        }
    });

    $('#certificate').change(function() {
        const file = this.files[0];
        if (file) {
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!allowedTypes.includes(file.type)) {
                alert('Certificate must be PDF, JPG, or PNG');
                $(this).val('');
                return;
            }
            if (file.size > maxSize) {
                alert('Certificate must be less than 5MB');
                $(this).val('');
                return;
            }
        }
    });

    // Handle step navigation
    $('#nextStep').click(function() {
        if (validateCurrentStep()) {
            if (currentStep < totalSteps) {
                currentStep++;
                updateStepDisplay();
            }
        }
    });

    $('#prevStep').click(function() {
        if (currentStep > 1) {
            currentStep--;
            updateStepDisplay();
        }
    });

    function validateCurrentStep() {
        const currentStepElement = document.getElementById(`step${currentStep}`);
        const inputs = currentStepElement.querySelectorAll('input, select, textarea');
        let isValid = true;

        inputs.forEach(input => {
            if (input.required && !input.value) {
                input.classList.add('is-invalid');
                isValid = false;
            } else {
                input.classList.remove('is-invalid');
            }
        });

        // Special validation for NRC in step 1
        if (currentStep === 1) {
            const nrc = $('#nrc').val();
            const digits = nrc.replace(/\D/g, '');
            if (digits.length < 6) {
                $('#nrc').addClass('is-invalid');
                isValid = false;
            } else {
                $('#nrc').removeClass('is-invalid');
            }
        }

        // Special validation for NOK phone in step 2
        if (currentStep === 2) {
            const nok_phone = $('#nok_phone').val();
            const nok_digits = nok_phone.replace(/\D/g, '');
            if (nok_digits.length < 9) {
                $('#nok_phone').addClass('is-invalid');
                isValid = false;
            } else {
                $('#nok_phone').removeClass('is-invalid');
            }
        }

        // Special validation for phone in step 1
        if (currentStep === 1) {
            const phone = $('#phone').val();
            const digits = phone.replace(/\D/g, '');
            if (digits.length < 9) {
                $('#phone').addClass('is-invalid');
                isValid = false;
            } else {
                $('#phone').removeClass('is-invalid');
            }
        }

        // File upload validation in step 5
        if (currentStep === 5) {
            const profilePhoto = document.getElementById('profile_photo').files;
            const academicResults = document.getElementById('academic_results').files;
            
            if (!profilePhoto.length) {
                $('#profile_photo').addClass('is-invalid');
                isValid = false;
            } else {
                $('#profile_photo').removeClass('is-invalid');
            }

            if (!academicResults.length) {
                $('#academic_results').addClass('is-invalid');
                isValid = false;
            } else {
                $('#academic_results').removeClass('is-invalid');
            }

            // Validate file sizes (5MB max)
            const maxSize = 5 * 1024 * 1024;
            const allFiles = Array.from(currentStepElement.querySelectorAll('input[type="file"]'));
            
            allFiles.forEach(fileInput => {
                if (fileInput.files.length > 0) {
                    if (fileInput.files[0].size > maxSize) {
                        fileInput.classList.add('is-invalid');
                        isValid = false;
                        alert(`${fileInput.name} exceeds 5MB limit`);
                    } else {
                        fileInput.classList.remove('is-invalid');
                    }
                }
            });
        }

        return isValid;
    }

    function updateStepDisplay() {
        // Update progress bar
        const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
        $('.progress-bar').css('width', `${progress}%`);

        // Update step indicators
        $('.step-indicator').removeClass('active completed');
        for (let i = 1; i <= totalSteps; i++) {
            if (i < currentStep) {
                $(`.step-indicator[data-step="${i}"]`).addClass('completed');
            } else if (i === currentStep) {
                $(`.step-indicator[data-step="${i}"]`).addClass('active');
            }
        }

        // Show/hide steps
        $('.step-content').addClass('d-none');
        $(`#step${currentStep}`).removeClass('d-none');

        // Update buttons
        $('#prevStep').prop('disabled', currentStep === 1);
        if (currentStep === totalSteps) {
            $('#nextStep').addClass('d-none');
            $('#submitBtn').removeClass('d-none');
            updateReviewInfo();
        } else {
            $('#nextStep').removeClass('d-none');
            $('#submitBtn').addClass('d-none');
        }
    }

    function updateReviewInfo() {
        // Generate preview student ID (matches server logic)
        const year2 = new Date().getFullYear().toString().slice(-2);
        const semester = ($('#semester_hidden').val() || '1').padStart(2, '0');
        const program = $('#program').val() || 'XX';

        // CRC32 lookup table (matches PHP's crc32 function)
        function crc32(str) {
            const table = new Uint32Array(256);
            for (let i = 0; i < 256; i++) {
                let c = i;
                for (let j = 0; j < 8; j++) {
                    c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
                }
                table[i] = c;
            }
            let crc = 0xFFFFFFFF;
            for (let i = 0; i < str.length; i++) {
                crc = table[(crc ^ str.charCodeAt(i)) & 0xFF] ^ (crc >>> 8);
            }
            return (crc ^ 0xFFFFFFFF) | 0; // signed 32-bit
        }

        const programHash = Math.abs(crc32(program)) % 100;
        const sequence = '0001'; // preview only, server uses actual count

        const previewStudentId = `${year2}${semester}${programHash.toString().padStart(2, '0')}${sequence}`;

        // Personal Information
        $('#review_student_id').text(previewStudentId);
        $('#review_name').text($('#fname').val() + ' ' + $('#lname').val());
        $('#review_gender').text($('#gender option:selected').text());
        $('#review_dob').text($('#dob').val());
        $('#review_email').text($('#email').val());
        $('#review_phone').text($('#phone').val());
        $('#review_nrc').text($('#nrc').val());
        $('#review_address').text($('#address').val() || 'Not provided');

        // Next of Kin
        $('#review_nok_name').text($('#nok_fname').val() + ' ' + $('#nok_lname').val());
        $('#review_nok_relationship').text($('#nok_relationship option:selected').text());
        $('#review_nok_phone').text($('#nok_phone').val());
        $('#review_nok_email').text($('#nok_email').val() || 'Not provided');
        $('#review_nok_address').text($('#nok_address').val() || 'Not provided');

        // Academic Information
        $('#review_program').text($('#program option:selected').text());
        $('#review_academic_year').text($('#academic_year').val());
        const semesterValue = $('#semester_term_select option:selected').text();
        $('#review_semester').text(semesterValue || 'Not selected');
        $('#review_qualification').text($('#qualification option:selected').text() || 'Not specified');
        $('#review_previous_school').text($('#previous_school').val() || 'Not provided');

        // Bursary Information
        const bursaryPercentage = $('#bursary_percentage').val() || 0;
        $('#review_bursary_percentage').text(bursaryPercentage + '%');

        // Documents
        const profilePhoto = $('#profile_photo')[0].files[0];
        const academicResults = $('#academic_results')[0].files[0];
        const idCopy = $('#id_copy')[0].files[0];
        const certificate = $('#certificate')[0].files[0];

        $('#review_profile_photo').html(profilePhoto ? 
            '<i class="fas fa-check-circle text-success"></i> ' + profilePhoto.name : 
            '<span class="badge bg-warning">Required</span>');
        
        $('#review_academic_results').html(academicResults ? 
            '<i class="fas fa-check-circle text-success"></i> ' + academicResults.name : 
            '<span class="badge bg-warning">Required</span>');
        
        $('#review_id_copy').html(idCopy ? 
            '<i class="fas fa-check-circle text-success"></i> ' + idCopy.name : 
            '<span class="badge bg-secondary">Optional</span>');
        
        $('#review_certificate').html(certificate ? 
            '<i class="fas fa-check-circle text-success"></i> ' + certificate.name : 
            '<span class="badge bg-secondary">Optional</span>');

        // Calculate fees with bursary
        const totalFees = parseFloat($('#totalFee').text()) || 0;
        const bursaryPercentageValue = parseFloat(bursaryPercentage);
        const subsidyAmount = (totalFees * bursaryPercentageValue) / 100;
        const studentAmount = totalFees - subsidyAmount;
        const bursaryAmount = subsidyAmount;

        // Update review fee summary
        $('#summary_original_fees').text('ZMW ' + totalFees.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#summary_subsidy_amount').text('- ZMW ' + subsidyAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#summary_student_amount').text('ZMW ' + studentAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#summary_bursary_account').text('ZMW ' + bursaryAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    }

    // Update semester/term dropdown based on program selection
    $('#program').change(function() {
        const program = $('#program').val();
        const $semesterSelect = $('#semester_term_select');
        const $label = $('#semester_term_label');
        
        if (program && programsData[program]) {
            const studyMode = programsData[program].period_mode;
            $semesterSelect.empty();
            
            if (studyMode === 'semester') {
                $label.text('Semester');
                $semesterSelect.append('<option value="">Select Semester</option>');
                $semesterSelect.append('<option value="1">Semester 1</option>');
                $semesterSelect.append('<option value="2">Semester 2</option>');
            } else if (studyMode === 'term') {
                $label.text('Term');
                $semesterSelect.append('<option value="">Select Term</option>');
                $semesterSelect.append('<option value="1">Term 1</option>');
                $semesterSelect.append('<option value="2">Term 2</option>');
                $semesterSelect.append('<option value="3">Term 3</option>');
            }
            $semesterSelect.removeClass('is-invalid');
        } else {
            $semesterSelect.empty().append('<option value="">Select Program First</option>');
            $label.text('Semester/Term');
        }
    });
    
    // Load courses when program or semester changes
    $('#program, #semester_term_select').change(function() {
        const program = $('#program').val();
        const semester = $('#semester_term_select').val();

        if (program && semester) {
            $.ajax({
                url: 'get_courses.php',
                method: 'POST',
                data: { program: program, semester: semester },
                dataType: 'json',
                success: function(data) {
                    let html = '';
                    let total = 0;

                    if (data.error) {
                        alert('Error: ' + data.error);
                        return;
                    }

                    if (data.length === 0) {
                        html = '<tr><td colspan="4" class="text-center text-muted">No courses found for this program and semester</td></tr>';
                    } else {
                        data.forEach(function(course) {
                            html += `
                                <tr>
                                    <td>${course.course_code}</td>
                                    <td>${course.course_name}</td>
                                    <td>${course.credits}</td>
                                    <td class="text-end">${course.course_fee.toFixed(2)}</td>
                                </tr>
                            `;
                            total += parseFloat(course.course_fee);
                        });
                    }

                    $('#courseList').html(html);
                    $('#totalFee').text(total.toFixed(2));
                    updateBursaryCalculation();
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    alert('Error loading courses. Please try again.');
                }
            });
        }
    });

    // Update bursary calculation when percentage changes
    $('#bursary_percentage').change(function() {
        updateBursaryCalculation();
    });

    function updateBursaryCalculation() {
        const totalFees = parseFloat($('#totalFee').text()) || 0;
        const bursaryPercentage = parseFloat($('#bursary_percentage').val()) || 0;
        
        const subsidyAmount = (totalFees * bursaryPercentage) / 100;
        const studentAmount = totalFees - subsidyAmount;
        const bursaryAmount = subsidyAmount;

        // Update step 4 display
        $('#original_fees').text('ZMW ' + totalFees.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#subsidy_amount').text('ZMW ' + subsidyAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#student_amount').text('ZMW ' + studentAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#bursary_account').text('ZMW ' + bursaryAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    }

    // Form validation and submission
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    });

    // NRC validation
    $('#nrc').on('input', function() {
        const nrc = this.value;
        const digits = nrc.replace(/\D/g, '');
        if (digits.length < 6) {
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    // Phone number validation
    $('#phone, #nok_phone').on('input', function() {
        this.value = this.value.replace(/[^0-9+\-\s]/g, '');
        const digits = this.value.replace(/\D/g, '');
        if (digits.length < 9 && this.value) {
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    // Client-side validation for date of birth
    $('#dob').on('change', function() {
        const dob = new Date(this.value);
        const today = new Date();
        const age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            age--;
        }
        
        if (age < 16) {
            alert('Student must be at least 16 years old');
            this.value = '';
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    // Reset form when modal is closed
    $('#registrationModal').on('hidden.bs.modal', function() {
        const form = document.getElementById('registrationForm');
        form.reset();
        form.classList.remove('was-validated');
        
        // Reset all invalid classes
        $('.is-invalid').removeClass('is-invalid');
        
        // Reset course list and total
        $('#courseList').html('<tr><td colspan="4" class="text-center text-muted">Select program to view courses</td></tr>');
        $('#totalFee').text('0.00');
        
        // Reset semester/term dropdown
        $('#semester_term_select').empty().append('<option value="">Select Program First</option>');
        
        // Reset step indicators
        currentStep = 1;
        updateStepDisplay();
    });
});
</script>

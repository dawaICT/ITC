<!-- Registration Modal Component -->
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
                    <div class="d-flex justify-content-between mt-2">
                        <span class="small step-indicator active" data-step="1">Personal</span>
                        <span class="small step-indicator" data-step="2">Contact</span>
                        <span class="small step-indicator" data-step="3">Academic</span>
                        <span class="small step-indicator" data-step="4">Review</span>
                    </div>
                </div>

                <form id="registrationForm" method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                    <input type="hidden" name="semester" id="semester_hidden" value="<?php echo $semester ?? '1'; ?>">

                    <!-- Step 1: Personal Information -->
                    <div class="step-content" id="step1">
                        <h5 class="text-primary mb-4">Personal Information</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fname" class="form-label">First Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="fname" name="fname" required>
                                </div>
                                <div class="invalid-feedback">Please provide a first name.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="lname" class="form-label">Last Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="lname" name="lname" required>
                                </div>
                                <div class="invalid-feedback">Please provide a last name.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
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
                                <label for="dob" class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                    <input type="date" class="form-control" id="dob" name="dob" required>
                                </div>
                                <div class="invalid-feedback">Please provide a date of birth.</div>
                            </div>

                            <div class="col-md-12">
                                <label for="nrc" class="form-label">NRC Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-id-badge"></i></span>
                                    <input type="text" class="form-control" id="nrc" name="nrc" placeholder="e.g., 123456/10/1" required>
                                </div>
                                <small class="text-muted">Must contain at least 6 digits</small>
                                <div class="invalid-feedback">Please provide NRC number (must contain at least 6 digits).</div>
                            </div>

                            <div class="col-12">
                                <hr class="my-3">
                                <h6 class="text-secondary mb-3">Document Uploads</h6>
                            </div>

                            <div class="col-md-4">
                                <label for="nrc_file" class="form-label">NRC Copy</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-file-pdf"></i></span>
                                    <input type="file" class="form-control" id="nrc_file" name="nrc_file" accept="<?= wucUploadAcceptAttr('document') ?>">
                                </div>
                                <small class="text-muted">PDF, JPG, PNG, or WebP (Max 5MB)</small>
                            </div>

                            <div class="col-md-4">
                                <label for="results_file" class="form-label">Grade 12 Results</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-file-alt"></i></span>
                                    <input type="file" class="form-control" id="results_file" name="results_file" accept="<?= wucUploadAcceptAttr('document') ?>">
                                </div>
                                <small class="text-muted">PDF, JPG, PNG, or WebP (Max 5MB)</small>
                            </div>

                            <div class="col-md-4">
                                <label for="profile_image" class="form-label">Profile Photo</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-camera"></i></span>
                                    <input type="file" class="form-control" id="profile_image" name="profile_image" accept="<?= wucUploadAcceptAttr('image') ?>">
                                </div>
                                <small class="text-muted">JPG, PNG, or WebP (Max 5MB)</small>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Contact Information -->
                    <div class="step-content d-none" id="step2">
                        <h5 class="text-primary mb-4">Contact Information</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                                <div class="invalid-feedback">Please provide a valid email address.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="tel" class="form-control" id="phone" name="phone" placeholder="+260 XXX XXX XXX" required>
                                </div>
                                <small class="text-muted">Must contain 9 to 15 digits</small>
                                <div class="invalid-feedback">Please provide a phone number (9 to 15 digits).</div>
                            </div>

                            <div class="col-md-12">
                                <label for="address" class="form-label">Residential Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                    <textarea class="form-control" id="address" name="address" rows="2" placeholder="House number, street, town/city"></textarea>
                                </div>
                            </div>

                            <div class="col-12">
                                <hr class="my-3">
                                <h6 class="text-secondary mb-3">Next of Kin</h6>
                            </div>

                            <div class="col-md-6">
                                <label for="nok_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user-friends"></i></span>
                                    <input type="text" class="form-control" id="nok_name" name="nok_name" placeholder="Next of kin full name" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="nok_phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="tel" class="form-control" id="nok_phone" name="nok_phone" placeholder="+260 XXX XXX XXX" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="nok_relationship" class="form-label">Relationship <span class="text-danger">*</span></label>
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
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Academic Information -->
                    <div class="step-content d-none" id="step3">
                        <h5 class="text-primary mb-4">Academic Information</h5>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="program" class="form-label">Program <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-graduation-cap"></i></span>
                                    <select class="form-select" id="program" name="program" required>
                                        <option value="">Select Program</option>
                                        <?php if (isset($programs)): ?>
                                            <?php foreach($programs as $program): ?>
                                                <option value="<?php echo htmlspecialchars($program->program_code); ?>">
                                                    <?php echo htmlspecialchars($program->program_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Please select a program.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="academic_year" class="form-label">Academic Year</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                    <input type="number" class="form-control" id="academic_year" name="entry_year"
                                           value="<?php echo $academic_year ?? date('Y'); ?>" min="2000" max="<?= date('Y') + 1 ?>" readonly>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="semester_term_select" class="form-label">
                                    <span id="semester_term_label">Semester/Term</span> <span class="text-danger">*</span>
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
                                <label for="mode" class="form-label">Mode of Study</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-book-reader"></i></span>
                                    <select class="form-select" id="mode" name="mode">
                                        <option value="Full-time">Full-time</option>
                                        <option value="Part-time">Part-time (Evening)</option>
                                        <option value="Distance">Distance Learning</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="previous_school" class="form-label">Previous School/Institution</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-school"></i></span>
                                    <input type="text" class="form-control" id="previous_school" name="previous_school" placeholder="Last attended school">
                                </div>
                            </div>

                            <div class="col-12 mt-4">
                                <h6 class="text-secondary mb-3">Course Details</h6>
                                <div id="coursesLoading" class="text-center text-muted d-none">
                                    <i class="fas fa-spinner fa-spin"></i> Loading courses...
                                </div>
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
                                            <tr><td colspan="4" class="text-center text-muted">Select program and semester to view courses</td></tr>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-light">
                                                <td colspan="3" class="text-end fw-bold">Total Fees:</td>
                                                <td class="text-end fw-bold">ZMW <span id="totalFee">0.00</span></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: Review Information -->
                    <div class="step-content d-none" id="step4">
                        <h5 class="text-primary mb-4">Review Information</h5>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Please review all information carefully before submitting.
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-user me-2"></i>Personal Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <dl class="row mb-0 small">
                                            <dt class="col-sm-5">Student ID:</dt>
                                            <dd class="col-sm-7" id="review_student_id"><span class="badge bg-primary">Auto-Generated</span></dd>

                                            <dt class="col-sm-5">Name:</dt>
                                            <dd class="col-sm-7" id="review_name">-</dd>

                                            <dt class="col-sm-5">Gender:</dt>
                                            <dd class="col-sm-7" id="review_gender">-</dd>

                                            <dt class="col-sm-5">Date of Birth:</dt>
                                            <dd class="col-sm-7" id="review_dob">-</dd>

                                            <dt class="col-sm-5">NRC:</dt>
                                            <dd class="col-sm-7" id="review_nrc">-</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-file-upload me-2"></i>Uploaded Documents</h6>
                                    </div>
                                    <div class="card-body">
                                        <dl class="row mb-0 small">
                                            <dt class="col-sm-5">NRC Copy:</dt>
                                            <dd class="col-sm-7" id="review_nrc_file"><span class="text-muted">Not uploaded</span></dd>

                                            <dt class="col-sm-5">Grade 12 Results:</dt>
                                            <dd class="col-sm-7" id="review_results_file"><span class="text-muted">Not uploaded</span></dd>

                                            <dt class="col-sm-5">Profile Photo:</dt>
                                            <dd class="col-sm-7" id="review_profile_image"><span class="text-muted">Not uploaded</span></dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-address-book me-2"></i>Contact Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <dl class="row mb-0 small">
                                            <dt class="col-sm-5">Email:</dt>
                                            <dd class="col-sm-7" id="review_email">-</dd>

                                            <dt class="col-sm-5">Phone:</dt>
                                            <dd class="col-sm-7" id="review_phone">-</dd>

                                            <dt class="col-sm-5">Address:</dt>
                                            <dd class="col-sm-7" id="review_address">-</dd>

                                            <dt class="col-sm-5">Next of Kin:</dt>
                                            <dd class="col-sm-7" id="review_nok_name">-</dd>

                                            <dt class="col-sm-5">NOK Phone:</dt>
                                            <dd class="col-sm-7" id="review_nok_phone">-</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-graduation-cap me-2"></i>Academic Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <dl class="row mb-0 small">
                                                    <dt class="col-sm-5">Program:</dt>
                                                    <dd class="col-sm-7" id="review_program">-</dd>

                                                    <dt class="col-sm-5">Academic Year:</dt>
                                                    <dd class="col-sm-7" id="review_academic_year">-</dd>

                                                    <dt class="col-sm-5">Semester/Term:</dt>
                                                    <dd class="col-sm-7" id="review_semester">-</dd>
                                                </dl>
                                            </div>
                                            <div class="col-md-6">
                                                <dl class="row mb-0 small">
                                                    <dt class="col-sm-5">Mode:</dt>
                                                    <dd class="col-sm-7" id="review_mode">-</dd>

                                                    <dt class="col-sm-5">Previous School:</dt>
                                                    <dd class="col-sm-7" id="review_previous_school">-</dd>

                                                    <dt class="col-sm-5">Total Courses:</dt>
                                                    <dd class="col-sm-7" id="review_total_courses">0</dd>
                                                </dl>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <div class="card border-success">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="mb-0"><i class="fas fa-dollar-sign me-2"></i>Fee Summary</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row text-center">
                                            <div class="col-md-6">
                                                <p class="text-muted mb-1 small">Total Course Fees</p>
                                                <h4 class="text-success mb-0">ZMW <span id="review_total_fees">0.00</span></h4>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="text-muted mb-1 small">Payment Status</p>
                                                <h5 class="mb-0"><span class="badge bg-warning text-dark">Pending</span></h5>
                                            </div>
                                        </div>
                                    </div>
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
    font-size: 0.85rem;
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
        font-size: 0.7rem;
    }
}

.input-group-text {
    min-width: 42px;
    justify-content: center;
}
</style>

<script>
// Encode programs data for JavaScript
<?php if (isset($programs_data)): ?>
const programsData = <?php echo json_encode($programs_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
<?php else: ?>
const programsData = {};
<?php endif; ?>

$(document).ready(function() {
    let currentStep = 1;
    const totalSteps = 4;

    // Initialize form validation
    const form = document.getElementById('registrationForm');

    // File upload validation
    // Shared upload rules (mirror of includes/upload_validator.php).
    const UPLOAD_MIME = {
        image: <?= wucUploadMimeListJson('image') ?>,
        document: <?= wucUploadMimeListJson('document') ?>
    };
    const UPLOAD_MAX_BYTES = <?= WUC_UPLOAD_MAX_BYTES ?>;
    const UPLOAD_MAX_MB = Math.round(UPLOAD_MAX_BYTES / 1048576);
    // Some browsers report image/jpg instead of image/jpeg.
    const jpgAlias = (list) => list.includes('image/jpeg') ? list.concat('image/jpg') : list;

    function validateAdminUpload(input, kind, label) {
        const file = input.files[0];
        if (!file) return;
        const allowed = jpgAlias(UPLOAD_MIME[kind] || []);
        if (allowed.length && !allowed.includes(file.type)) {
            alert(label + ' has an invalid file type.');
            $(input).val('');
            return;
        }
        if (file.size > UPLOAD_MAX_BYTES) {
            alert(label + ' must be less than ' + UPLOAD_MAX_MB + 'MB');
            $(input).val('');
            return;
        }
    }

    $('#nrc_file').change(function() { validateAdminUpload(this, 'document', 'NRC file'); });
    $('#results_file').change(function() { validateAdminUpload(this, 'document', 'Results file'); });
    $('#profile_image').change(function() { validateAdminUpload(this, 'image', 'Profile image'); });

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
        const inputs = currentStepElement.querySelectorAll('input[required], select[required], textarea[required]');
        let isValid = true;

        inputs.forEach(input => {
            if (!input.value || input.value.trim() === '') {
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

            // Validate age (must be at least 16)
            const dob = new Date($('#dob').val());
            const today = new Date();
            const age = today.getFullYear() - dob.getFullYear();
            if (age < 16) {
                $('#dob').addClass('is-invalid');
                alert('Student must be at least 16 years old');
                isValid = false;
            }
        }

        // Special validation for phone in step 2
        if (currentStep === 2) {
            const phone = $('#phone').val();
            const digits = phone.replace(/\D/g, '');
            if (digits.length < 9 || digits.length > 15) {
                $('#phone').addClass('is-invalid');
                isValid = false;
            } else {
                $('#phone').removeClass('is-invalid');
            }
        }

        return isValid;
    }

    function updateStepDisplay() {
        // Update progress bar
        const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
        $('.progress-bar').css('width', `${progress}%`).attr('aria-valuenow', progress);

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
        // Personal Information
        $('#review_name').text($('#fname').val() + ' ' + $('#lname').val());
        $('#review_gender').text($('#gender option:selected').text());
        $('#review_dob').text($('#dob').val());
        $('#review_nrc').text($('#nrc').val());

        // Uploaded Documents
        const nrcFile = $('#nrc_file')[0].files[0];
        const resultsFile = $('#results_file')[0].files[0];
        const profileImage = $('#profile_image')[0].files[0];

        if (nrcFile) {
            $('#review_nrc_file').html(`<i class="fas fa-check-circle text-success"></i> ${nrcFile.name}`);
        } else {
            $('#review_nrc_file').html('<span class="text-muted">Not uploaded</span>');
        }

        if (resultsFile) {
            $('#review_results_file').html(`<i class="fas fa-check-circle text-success"></i> ${resultsFile.name}`);
        } else {
            $('#review_results_file').html('<span class="text-muted">Not uploaded</span>');
        }

        if (profileImage) {
            $('#review_profile_image').html(`<i class="fas fa-check-circle text-success"></i> ${profileImage.name}`);
        } else {
            $('#review_profile_image').html('<span class="text-muted">Not uploaded</span>');
        }

        // Contact Information
        $('#review_email').text($('#email').val());
        $('#review_phone').text($('#phone').val());
        $('#review_address').text($('#address').val() || 'Not provided');
        $('#review_nok_name').text($('#nok_name').val() || 'Not provided');
        $('#review_nok_phone').text($('#nok_phone').val() || 'Not provided');

        // Academic Information
        $('#review_program').text($('#program option:selected').text());
        $('#review_academic_year').text($('#academic_year').val());
        $('#review_semester').text($('#semester_term_select option:selected').text() || 'Not selected');
        $('#review_mode').text($('#mode option:selected').text());
        $('#review_previous_school').text($('#previous_school').val() || 'Not provided');
        $('#review_total_courses').text($('#courseList tr').length || 0);
        $('#review_total_fees').text($('#totalFee').text());
    }

    // Update semester/term dropdown based on program selection
    $('#program').change(function() {
        const program = $(this).val();
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
                
                // Auto-select calculated semester
                if(typeof defaultSemester !== 'undefined') {
                    $semesterSelect.val(defaultSemester);
                }
            } else if (studyMode === 'term') {
                $label.text('Term');
                $semesterSelect.append('<option value="">Select Term</option>');
                $semesterSelect.append('<option value="1">Term 1</option>');
                $semesterSelect.append('<option value="2">Term 2</option>');
                $semesterSelect.append('<option value="3">Term 3</option>');
                
                // Auto-select calculated term
                if(typeof defaultTerm !== 'undefined') {
                    $semesterSelect.val(defaultTerm);
                }
            } else {
                $label.text('Semester');
                $semesterSelect.append('<option value="">Select Semester</option>');
                $semesterSelect.append('<option value="1">Semester 1</option>');
                $semesterSelect.append('<option value="2">Semester 2</option>');
                
                // Default fallback
                if(typeof defaultSemester !== 'undefined') {
                    $semesterSelect.val(defaultSemester);
                }
            }
            $semesterSelect.removeClass('is-invalid');
            
            // Trigger change to load courses automatically if value set
            if($semesterSelect.val()) {
                $semesterSelect.trigger('change');
            } else {
                // Update hidden field
                $('#semester_hidden').val($semesterSelect.val());
            }
        } else {
            $semesterSelect.empty().append('<option value="">Select Program First</option>');
            $label.text('Semester/Term');
        }
    });

    // Update hidden semester field when semester changes
    $('#semester_term_select').change(function() {
        $('#semester_hidden').val($(this).val());
    });
    
    // Load courses when program or semester changes
    $('#program, #semester_term_select').change(function() {
        const program = $('#program').val();
        const semester = $('#semester_term_select').val();

        if (program && semester) {
            $('#coursesLoading').removeClass('d-none');
            
            $.ajax({
                url: 'ajax/get_courses.php',
                method: 'POST',
                data: { program: program, semester: semester },
                dataType: 'json',
                success: function(data) {
                    $('#coursesLoading').addClass('d-none');
                    let html = '';
                    let total = 0;

                    if (data.error) {
                        html = '<tr><td colspan="4" class="text-center text-danger">Error: ' + data.error + '</td></tr>';
                    } else if (data.length === 0) {
                        html = '<tr><td colspan="4" class="text-center text-muted">No courses found for this program and semester</td></tr>';
                    } else {
                        data.forEach(function(course) {
                            const fee = parseFloat(course.course_fee) || 0;
                            html += `
                                <tr>
                                    <td>${course.course_code}</td>
                                    <td>${course.course_name}</td>
                                    <td>${course.credits || 3}</td>
                                    <td class="text-end">${fee.toFixed(2)}</td>
                                </tr>
                            `;
                            total += fee;
                        });
                    }

                    $('#courseList').html(html);
                    $('#totalFee').text(total.toFixed(2));
                },
                error: function(xhr, status, error) {
                    $('#coursesLoading').addClass('d-none');
                    console.error('AJAX Error:', status, error);
                    $('#courseList').html('<tr><td colspan="4" class="text-center text-danger">Error loading courses. Please try again.</td></tr>');
                }
            });
        }
    });

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
        if (digits.length < 6 && this.value) {
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    // Phone number validation
    $('#phone, #nok_phone').on('input', function() {
        this.value = this.value.replace(/[^0-9+\-\s]/g, '');
        const digits = this.value.replace(/\D/g, '');
        if ((this.id === 'phone' || this.id === 'nok_phone') && this.value && (digits.length < 9 || digits.length > 15)) {
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    // Client-side validation for date of birth
    $('#dob').on('change', function() {
        const dob = new Date(this.value);
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            age--;
        }
        
        if (age < 16) {
            alert('Student must be at least 16 years old');
            this.value = '';
            $(this).addClass('is-invalid');
        } else if (age > 100) {
            alert('Invalid date of birth');
            this.value = '';
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    // Reset form when modal is closed
    $('#registrationModal').on('hidden.bs.modal', function() {
        form.reset();
        form.classList.remove('was-validated');
        
        // Reset all invalid classes
        $('.is-invalid').removeClass('is-invalid');
        
        // Reset course list and total
        $('#courseList').html('<tr><td colspan="4" class="text-center text-muted">Select program and semester to view courses</td></tr>');
        $('#totalFee').text('0.00');
        
        // Reset semester/term dropdown
        $('#semester_term_select').empty().append('<option value="">Select Program First</option>');
        
        // Reset step indicators
        currentStep = 1;
        updateStepDisplay();
    });

    // Initialize first step display
    updateStepDisplay();
});
</script>

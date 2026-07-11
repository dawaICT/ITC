<?php
require "includes/nav.php";
ini_set('display_errors', '0');
error_reporting(E_ALL);
?>

<main class="container-fluid px-4 py-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Add Online Student</h1>
                <p class="text-muted">Process and register online student applications</p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <a href="processedApp.php" class="btn btn-outline-light">
                        <i class="bi bi-arrow-left me-2"></i>Back to Processed Apps
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>New Student Registration Form</h5>
                    </div>
                </div>
                <div class="card-body">

                <!-- Session Messages -->
                <?php
                    if (isset($_SESSION['invalidFormat'])){
                        echo '<div class="alert alert-danger" role="alert">'. $_SESSION['invalidFormat'].'</div>';
                        unset($_SESSION['invalidFormat']); 
                    }
                    if (isset($_SESSION['errorMessage'])){
                        echo '<div class="alert alert-danger" role="alert">'. $_SESSION['errorMessage'].'</div>';
                        unset($_SESSION['errorMessage']); 
                    }
                    if (isset($_SESSION['successMessage'])){
                        echo '<div class="alert alert-success" role="alert">'. $_SESSION['successMessage'].'</div>';
                        unset($_SESSION['successMessage']); 
                    }
                ?>

                <div class="form-container">
                    <!-- Progress bar -->
                    <div class="progressbar">
                        <div class="progress" id="progress"></div>
                        <div class="progress-step progress-step-active" data-title="Basic Info"></div>
                        <div class="progress-step" data-title="Academic Info"></div>
                        <div class="progress-step" data-title="Complete Registration"></div>
                    </div>

					<?php
					$records = [];
					if(isset($_POST['view']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']){
						$viewID = $_POST['view'];
						$stmt = $db->prepare("SELECT * FROM processed_applicants WHERE id = ?");
						if ($stmt) {
							$stmt->bind_param("i", $viewID);
							$stmt->execute();
							$result = $stmt->get_result();
							while ($row = $result->fetch_object()) {
								$records[] = $row;
							}
							$result->free();
							$stmt->close();
						} else {
							echo '<div class="alert alert-danger">Database query failed.</div>';
							exit;
						}
						if (empty($records)) {
							echo '<div class="alert alert-danger">No applicant found with the given ID.</div>';
							exit;
						}
					} else {
						header('Location: processedApp.php');
						exit;
					}
					?>

					<?php foreach($records as $r) { ?>
					<!-- Steps -->
					<form action="processForm.php" class="form" method="POST" role="form" enctype="multipart/form-data">
						<!-- Hidden fields for required data -->
						<input type="hidden" name="intake" value="<?php echo htmlspecialchars($r->intake ?? ''); ?>">
						<input type="hidden" name="program_code" value="<?php echo htmlspecialchars($r->program ?? ''); ?>">
						<input type="hidden" name="mode" value="<?php echo htmlspecialchars($r->mode ?? ''); ?>">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
						<input type="hidden" name="applicant_id" value="<?php echo htmlspecialchars($viewID ?? ''); ?>">
						
						<div class="form-step form-step-active">
							<h4 class="mb-4">Basic Information</h4>
							<div class="row">
								<div class="col-md-6">
									<div class="form-group">
										<label for="Fname" class="form-label">First Name <span class="text-danger">*</span></label>
										<input type="text" class="form-control" name="Fname" autofocus id="Fname" value="<?php echo htmlspecialchars($r->Fname ?? ''); ?>" autocomplete="off" required>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group">
										<label for="Lname" class="form-label">Last Name <span class="text-danger">*</span></label>
										<input type="text" class="form-control" name="Lname" id="Lname" value="<?php echo htmlspecialchars($r->Lname ?? ''); ?>" autocomplete="off" required>
									</div>
								</div>
							</div>
							
							<div class="row">
								<div class="col-md-6">
									<div class="form-group">
										<label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
										<select class="form-control" name="gender" id="gender" required>
											<option value="">Select Gender</option>
											<option value="M" <?php echo ($r->sex ?? '') == 'M' ? 'selected' : ''; ?>>Male</option>
											<option value="F" <?php echo ($r->sex ?? '') == 'F' ? 'selected' : ''; ?>>Female</option>
										</select> 
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group">
										<label for="dob" class="form-label">Date of Birth <span class="text-danger">*</span></label>
										<input type="date" class="form-control" name="dob" id="dob" value="<?php echo htmlspecialchars($r->dob ?? ''); ?>" autocomplete="off" required>
									</div>
								</div>
							</div>
							
							<div class="form-group">
								<label for="nrc_pass" class="form-label">NRC/Passport No <span class="text-danger">*</span></label>
								<input type="text" class="form-control" name="nrc_pass" id="nrc_pass" value="<?php echo htmlspecialchars($r->nrc_pass ?? ''); ?>" autocomplete="off" required>
							</div>
							
							<div class="row">
								<div class="col-md-6">
									<div class="form-group">
										<label for="mobile" class="form-label">Mobile <span class="text-danger">*</span></label>
										<input type="tel" class="form-control" name="mobile" id="mobile" value="<?php echo htmlspecialchars($r->mobile ?? ''); ?>" pattern="[0-9]{10}" required>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group">
										<label for="email" class="form-label">Email <span class="text-danger">*</span></label>
										<input type="email" class="form-control" name="email" id="email" value="<?php echo htmlspecialchars($r->email ?? ''); ?>" autocomplete="off" required>
									</div>
								</div>
							</div>
							
							<div class="form-group">
								<label for="address" class="form-label">Residential Address <span class="text-danger">*</span></label>
								<input type="text" class="form-control" name="address" id="address" value="<?php echo htmlspecialchars($r->h_addre ?? ''); ?>" autocomplete="off" required>
							</div>
							
							<div class="d-flex justify-content-end mt-4">
								<a href="#" class="btn btn-primary btn-next">Next <i class="bi bi-arrow-right ms-2"></i></a>
							</div>
						</div>

						<div class="form-step">
							<h4 class="mb-4">Academic & Financial Information</h4>
							
							<div class="form-group">
								<label for="school" class="form-label">Secondary School Attended <span class="text-danger">*</span></label>
								<input type="text" class="form-control" name="school" id="school" value="<?php echo htmlspecialchars($r->school ?? ''); ?>" autocomplete="off" required>
							</div>
							
							<div class="row">
								<div class="col-md-6">
									<div class="form-group">
										<label for="grade" class="form-label">Highest Grade <span class="text-danger">*</span></label>
										<select class="form-control" name="grade" id="grade" required>
											<option value="">Select Grade</option>
											<option value="G12" <?php echo ($r->grade ?? '') == 'G12' ? 'selected' : ''; ?>>Grade 12</option>
											<option value="G9" <?php echo ($r->grade ?? '') == 'G9' ? 'selected' : ''; ?>>Grade 9</option>
											<option value="G7" <?php echo ($r->grade ?? '') == 'G7' ? 'selected' : ''; ?>>Grade 7</option>
										</select> 
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group">
										<label for="completion_year" class="form-label">Completion Year <span class="text-danger">*</span></label>
										<input type="month" class="form-control" name="completion_year" id="completion_year" value="<?php echo htmlspecialchars($r->dte2 ?? ''); ?>" required>
									</div>
								</div>
							</div>
							
							<div class="row mb-4">
								<div class="col-md-6">
									<div class="form-group">
										<label for="english_grade" class="form-label">English Grade (1-9) <span class="text-danger">*</span></label>
										<input type="number" class="form-control" name="english_grade" id="english_grade" min="1" max="9" value="<?php echo htmlspecialchars($r->english ?? ''); ?>" required>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group">
										<label for="math_grade" class="form-label">Math Grade (1-9) <span class="text-danger">*</span></label>
										<input type="number" class="form-control" name="math_grade" id="math_grade" min="1" max="9" value="<?php echo htmlspecialchars($r->math ?? ''); ?>" required>
									</div>
								</div>
							</div>
							
							<h5 class="mb-3">Sponsorship & Bursary</h5>
							
							<div class="row">
								<div class="col-md-6">
									<div class="form-group">
										<label for="sponsor_type" class="form-label">Sponsor Type <span class="text-danger">*</span></label>
										<select class="form-control" name="sponsor_type" id="sponsor_type" required>
											<option value="">Select Sponsor</option>
											<option value="self" <?php echo ($r->sponsor ?? '') == 'Self' ? 'selected' : ''; ?>>Self-Sponsored</option>
											<option value="family" <?php echo ($r->sponsor ?? '') == 'Family' ? 'selected' : ''; ?>>Family</option>
											<option value="bursary" <?php echo ($r->sponsor ?? '') == 'Bursary' ? 'selected' : ''; ?>>Bursary</option>
											<option value="scholarship" <?php echo ($r->sponsor ?? '') == 'Scholarship' ? 'selected' : ''; ?>>Scholarship</option>
										</select>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group">
										<label for="bursary_percentage" class="form-label">Bursary Percentage <small class="text-muted">(Tuition fee deduction only)</small></label>
										<select class="form-control" name="bursary_percentage" id="bursary_percentage">
											<option value="0" selected>0% - No Bursary</option>
											<option value="20">20% Bursary</option>
											<option value="40">40% Bursary</option>
											<option value="50">50% Bursary</option>
										</select>
										<small class="form-text text-muted">Applies only to tuition fees. Other fees remain payable.</small>
									</div>
								</div>
							</div>
							
							<h5 class="mt-4 mb-3">Next of Kin Information</h5>
							
							<div class="row">
								<div class="col-md-6">
									<div class="form-group">
										<label for="next_kin_name" class="form-label">Next of Kin Name <span class="text-danger">*</span></label>
										<input type="text" class="form-control" name="next_kin_name" id="next_kin_name" value="<?php echo htmlspecialchars($r->next_kin ?? ''); ?>" required>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group">
										<label for="next_kin_phone" class="form-label">Next of Kin Phone <span class="text-danger">*</span></label>
										<input type="tel" class="form-control" name="next_kin_phone" id="next_kin_phone" value="<?php echo htmlspecialchars($r->next_kin_mobile ?? ''); ?>" pattern="[0-9]{10}" required>
									</div>
								</div>
							</div>
							
							<div class="form-group">
								<label for="relationship" class="form-label">Relationship <span class="text-danger">*</span></label>
								<select class="form-control" name="relationship" id="relationship" required>
									<option value="">Select Relationship</option>
									<option value="parent" <?php echo ($r->relat ?? '') == 'Father' || ($r->relat ?? '') == 'Mother' ? 'selected' : ''; ?>>Parent</option>
									<option value="sibling" <?php echo ($r->relat ?? '') == 'Sister' || ($r->relat ?? '') == 'Brother' ? 'selected' : ''; ?>>Sibling</option>
									<option value="spouse" <?php echo ($r->relat ?? '') == 'Spouse' ? 'selected' : ''; ?>>Spouse</option>
									<option value="relative" <?php echo ($r->relat ?? '') == 'Cousin' || ($r->relat ?? '') == 'Aunt' || ($r->relat ?? '') == 'Uncle' ? 'selected' : ''; ?>>Relative</option>
									<option value="other" <?php echo ($r->relat ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
								</select>
							</div>
							
							<div class="d-flex justify-content-between mt-4">
								<a href="#" class="btn btn-outline-secondary btn-prev"><i class="bi bi-arrow-left me-2"></i> Previous</a>
								<a href="#" class="btn btn-primary btn-next">Next <i class="bi bi-arrow-right ms-2"></i></a>
							</div>
						</div>

						<div class="form-step">
							<h4 class="mb-4">Final Registration</h4>
							
							<div class="alert alert-info">
								<h5><i class="bi bi-info-circle me-2"></i>Registration Summary</h5>
								<p class="mb-0">Review all information before submission. You'll need to upload the following documents:</p>
							</div>
							
							<div class="row mb-4">
								<div class="col-md-6">
									<div class="card border">
										<div class="card-body">
											<h6 class="card-title">Program Details</h6>
											<p class="mb-1"><strong>Intake:</strong> <?php echo htmlspecialchars($r->intake ?? 'N/A'); ?></p>
											<p class="mb-1"><strong>Program:</strong> <?php echo htmlspecialchars($r->program ?? 'N/A'); ?></p>
											<p class="mb-0"><strong>Study Mode:</strong> <?php echo htmlspecialchars($r->mode ?? 'N/A'); ?></p>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="card border">
										<div class="card-body">
											<h6 class="card-title">Financial Summary</h6>
											<p class="mb-1"><strong>Sponsor:</strong> <span id="sponsorDisplay">Self-Sponsored</span></p>
											<p class="mb-1"><strong>Bursary:</strong> <span id="bursaryDisplay">0%</span></p>
											<p class="mb-0 text-muted"><small>Bursary applies to tuition fees only</small></p>
										</div>
									</div>
								</div>
							</div>
							
							<h5 class="mb-3">Required Documents</h5>
							
							<div class="row">
								<div class="col-md-4">
									<div class="form-group">
										<label for="nrc_file" class="form-label">NRC/Passport Copy <span class="text-danger">*</span></label>
										<input type="file" class="form-control" name="nrc_file" id="nrc_file" accept=".pdf,.jpg,.jpeg,.png" required>
										<small class="form-text text-muted">PDF or image (Max: 2MB)</small>
									</div>
								</div>
								<div class="col-md-4">
									<div class="form-group">
										<label for="certificate_file" class="form-label">Certificate/Results <span class="text-danger">*</span></label>
										<input type="file" class="form-control" name="certificate_file" id="certificate_file" accept=".pdf,.jpg,.jpeg,.png" required>
										<small class="form-text text-muted">PDF or image (Max: 5MB)</small>
									</div>
								</div>
								<div class="col-md-4">
									<div class="form-group">
										<label for="profile_image" class="form-label">Passport Photo</label>
										<input type="file" class="form-control" name="profile_image" id="profile_image" accept="image/*">
										<small class="form-text text-muted">Optional (Max: 1MB)</small>
									</div>
								</div>
							</div>
							
							<div class="form-group mt-4">
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="confirm_info" required>
									<label class="form-check-label" for="confirm_info">
										I confirm that all information provided is accurate and complete
									</label>
								</div>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="agree_terms" required>
									<label class="form-check-label" for="agree_terms">
										I agree to the terms and conditions of student registration
									</label>
								</div>
							</div>
							
							<div class="d-flex justify-content-between mt-4">
								<a href="#" class="btn btn-outline-secondary btn-prev"><i class="bi bi-arrow-left me-2"></i> Previous</a>
								<button type="submit" name="submit" class="btn btn-success" id="submitBtn" disabled>
									<i class="bi bi-check-circle me-2"></i> Complete Registration
								</button>
							</div>
						</div>
                    </form><br>
                    <?php } ?>
                </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// Enable/disable submit button based on checkboxes
document.addEventListener('DOMContentLoaded', function() {
    const confirmCheckbox = document.getElementById('confirm_info');
    const termsCheckbox = document.getElementById('agree_terms');
    const submitBtn = document.getElementById('submitBtn');
    const sponsorSelect = document.getElementById('sponsor_type');
    const bursarySelect = document.getElementById('bursary_percentage');
    const sponsorDisplay = document.getElementById('sponsorDisplay');
    const bursaryDisplay = document.getElementById('bursaryDisplay');
    
    // Update display fields
    function updateSummary() {
        const sponsorText = sponsorSelect.options[sponsorSelect.selectedIndex].text;
        const bursaryText = bursarySelect.options[bursarySelect.selectedIndex].text;
        sponsorDisplay.textContent = sponsorText;
        bursaryDisplay.textContent = bursaryText;
    }
    
    if(sponsorSelect && bursarySelect) {
        sponsorSelect.addEventListener('change', updateSummary);
        bursarySelect.addEventListener('change', updateSummary);
        updateSummary(); // Initial update
    }
    
    // Checkbox validation
    function validateCheckboxes() {
        if (confirmCheckbox && termsCheckbox && submitBtn) {
            submitBtn.disabled = !(confirmCheckbox.checked && termsCheckbox.checked);
        }
    }
    
    if(confirmCheckbox && termsCheckbox) {
        confirmCheckbox.addEventListener('change', validateCheckboxes);
        termsCheckbox.addEventListener('change', validateCheckboxes);
    }
    
    // Multi-step form navigation (simplified version)
    const nextButtons = document.querySelectorAll('.btn-next');
    const prevButtons = document.querySelectorAll('.btn-prev');
    const formSteps = document.querySelectorAll('.form-step');
    const progressSteps = document.querySelectorAll('.progress-step');
    
    let currentStep = 0;
    
    function updateFormSteps() {
        formSteps.forEach((step, index) => {
            step.classList.toggle('form-step-active', index === currentStep);
        });
        
        progressSteps.forEach((step, index) => {
            step.classList.toggle('progress-step-active', index <= currentStep);
        });
        
        // Update progress bar width
        const progress = document.getElementById('progress');
        if (progress) {
            progress.style.width = `${((currentStep) / (formSteps.length - 1)) * 100}%`;
        }
    }
    
    nextButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            // Validate current step before proceeding
            const currentFormStep = formSteps[currentStep];
            const inputs = currentFormStep.querySelectorAll('input[required], select[required]');
            let isValid = true;
            
            inputs.forEach(input => {
                if (!input.checkValidity()) {
                    isValid = false;
                    input.reportValidity();
                }
            });
            
            if (isValid && currentStep < formSteps.length - 1) {
                currentStep++;
                updateFormSteps();
            }
        });
    });
    
    prevButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            if (currentStep > 0) {
                currentStep--;
                updateFormSteps();
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const alertInstance = new bootstrap.Alert(alert);
            setTimeout(() => {
                alertInstance.close();
            }, 5000);
        });
    }, 100);
});
</script>

<?php require 'includes/footer.php'; ?>

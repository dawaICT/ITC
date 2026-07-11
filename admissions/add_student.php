<?php
// This legacy form posted field names that processForm.php does not read, so
// every submission failed validation. New-student registration lives on
// regNewStud.php (single + bulk, shared backend handler) — send staff there.
header('Location: regNewStud.php');
exit();

$page_title = 'Student Registration Form';
require "includes/nav.php";

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
?>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Student Registration</h1>
                <p class="text-muted">Add a new student to the system</p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <a href="students.php" class="btn btn-outline-secondary d-flex align-items-center gap-2">
                        <i class="fas fa-arrow-left"></i>Back to Students
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration Form -->
    <div class="form-container">
        <div class="form-header">
            <h2>New Student Registration</h2>
            <p>Please complete all required information</p>
        </div>
        
        <!-- Session Messages -->
          <?php
        if (isset($_SESSION['invalidFormat'])) {
            echo '<div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>' . $_SESSION['invalidFormat'] . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
              session_unset(); 
            }
        
        if (isset($_SESSION['errorMessage'])) {
            echo '<div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>' . $_SESSION['errorMessage'] . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
              session_unset(); 
            }
            ?>

        <!-- Progress Bar -->
          <div class="progressbar">
            <div class="progress" id="progress"></div>
            <div class="progress-step progress-step-active" data-title="Student Information"></div>
            <div class="progress-step" data-title="Contact Information"></div>
            <div class="progress-step" data-title="Academic Background"></div>
            <div class="progress-step" data-title="Sponsorship"></div>
            <div class="progress-step" data-title="Documents"></div>
          </div>

        <!-- Multi-step Form -->
        <form action="processForm.php" class="multi-step-form" method="POST" role="form" enctype="multipart/form-data">
            <!-- Step 1: Student Information -->
          <div class="form-step form-step-active">
                <div class="row">
                    <div class="col-md-6">
                <div class="form-group">
                            <label for="title">Title</label>
                            <select class="form-select" name="title" id="title">
                                <option selected disabled>Select title</option>
                        <option>Mr.</option>
                        <option>Mrs.</option>
                        <option>Ms.</option>
                        <option>Mss.</option>
                        <option>Dr.</option>
                        <option>Prof.</option>
                        <option>Sir.</option>
                        <option>Eng.</option>
                      </select> 
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="Fname">First Name</label>
                            <input type="text" class="form-control" name="Fname" id="Fname" 
                                placeholder="Enter first name" autocomplete="off" required
                                data-error-msg="First name is required">
                        </div>
                </div>
                    <div class="col-md-6">
                <div class="form-group">
                            <label for="Lname">Last Name</label>
                            <input type="text" class="form-control" name="Lname" id="Lname" 
                                placeholder="Enter last name" autocomplete="off" required
                                data-error-msg="Last name is required">
                        </div>
                </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                <div class="form-group">
                            <label for="sex">Gender</label>
                            <select class="form-select" name="sex" id="sex">
                                <option value="M">Male</option>
                                <option value="F">Female</option>
                      </select> 
                </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="dob">Date of Birth</label>
                            <input type="date" class="form-control" name="dob" id="dob" required
                                data-error-msg="Date of birth is required">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                <div class="form-group">
                            <label for="country">Country of Origin</label>
                            <select class="form-select" id="country" name="country" required
                                data-error-msg="Country is required">
                                <option selected disabled>Select country</option>
                     <option value="Afganistan">Afghanistan</option>
                     <option value="Albania">Albania</option>
                     <option value="Algeria">Algeria</option>
                     <option value="Zambia">Zambia</option>
                     <option value="Zimbabwe">Zimbabwe</option>
                  </select>
                </div>
                    </div>
                    <div class="col-md-6">
                <div class="form-group">
                            <label for="nationality">Nationality</label>
                            <input type="text" class="form-control" name="nationality" id="nationality" 
                                placeholder="Enter nationality" required
                                data-error-msg="Nationality is required">
                        </div>
                </div>
                </div>
                
                  <div class="form-group">
                    <label for="nrc">National ID / Passport Number</label>
                    <input type="text" class="form-control" name="nrc" id="nrc" 
                        placeholder="Enter NRC or passport number" required
                        data-error-msg="NRC/Passport number is required">
                </div>
                
                <div class="btns-group">
                    <button type="button" class="btn btn-primary btn-next">
                        <i class="fas fa-arrow-right"></i> Next
                    </button>
            </div>
          </div>
            
            <!-- Step 2: Contact Information -->
          <div class="form-step">
                <div class="row">
                    <div class="col-md-6">
            <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" class="form-control" name="email" id="email" 
                                placeholder="Enter email address" required
                                data-error-msg="Valid email address is required">
                        </div>
                </div>
                    <div class="col-md-6">
                <div class="form-group">
                            <label for="mobile">Mobile Number</label>
                            <input type="tel" class="form-control" name="mobile" id="mobile" 
                                placeholder="Enter mobile number" required
                                data-error-msg="Valid mobile number is required">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="address">Street Address</label>
                    <input type="text" class="form-control" name="address" id="address" 
                        placeholder="Enter street address" required
                        data-error-msg="Street address is required">
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                <div class="form-group">
                            <label for="city">City/Town</label>
                            <input type="text" class="form-control" name="city" id="city" 
                                placeholder="Enter city" required
                                data-error-msg="City is required">
                        </div>
                </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="province">Province/State</label>
                            <input type="text" class="form-control" name="province" id="province" 
                                placeholder="Enter province/state" required
                                data-error-msg="Province is required">
              </div>
          </div>
                    <div class="col-md-4">
            <div class="form-group">
                            <label for="postal">Postal Code</label>
                            <input type="text" class="form-control" name="postal" id="postal" 
                                placeholder="Enter postal code">
                        </div>
                    </div>
            </div>
                
            <div class="form-group">
                    <label for="emergency_contact">Emergency Contact Person</label>
                    <input type="text" class="form-control" name="emergency_contact" id="emergency_contact" 
                        placeholder="Enter name of emergency contact" required
                        data-error-msg="Emergency contact name is required">
          </div>
                
            <div class="form-group">
                    <label for="emergency_mobile">Emergency Contact Number</label>
                    <input type="tel" class="form-control" name="emergency_mobile" id="emergency_mobile" 
                        placeholder="Enter emergency contact number" required
                        data-error-msg="Emergency contact number is required">
                </div>
                
                <div class="btns-group">
                    <button type="button" class="btn btn-outline-primary btn-prev">
                        <i class="fas fa-arrow-left"></i> Previous
                    </button>
                    <button type="button" class="btn btn-primary btn-next">
                        <i class="fas fa-arrow-right"></i> Next
                    </button>
                </div>
            </div>
            
            <!-- Step 3: Academic Background -->
            <div class="form-step">
            <div class="form-group">
                    <label for="high_school">Last School Attended</label>
                    <input type="text" class="form-control" name="high_school" id="high_school" 
                        placeholder="Enter name of last school attended" required
                        data-error-msg="School name is required">
            </div>
                
          <div class="row">
                    <div class="col-md-6">
                  <div class="form-group">
                            <label for="completion_year">Year of Completion</label>
                            <input type="number" class="form-control" name="completion_year" id="completion_year" 
                                placeholder="Year" required min="1990" max="2030"
                                data-error-msg="Valid completion year is required">
                  </div>
              </div>
                    <div class="col-md-6">
                  <div class="form-group">
                            <label for="qualification">Qualification Obtained</label>
                            <input type="text" class="form-control" name="qualification" id="qualification" 
                                placeholder="Enter qualification" required
                                data-error-msg="Qualification is required">
                        </div>
                  </div>
                </div>
                
                  <div class="form-group">
                    <label for="program_code">Program Applying For</label>
                    <select class="form-select" name="program_code" id="program_code" required
                        data-error-msg="Program selection is required">
                        <option selected disabled>Select program</option>
                        <?php
                        // Fetch available programs
                        if($programResults = $db->query("SELECT * FROM programs ORDER BY program_name ASC")) {
                            while($program = $programResults->fetch_object()) {
                                echo '<option value="'.$program->program_code.'">'.$program->program_name.'</option>';
                            }
                            $programResults->free();
                        }
                        ?>
                    </select>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                    <div class="form-group">
                            <label for="intake">Intake</label>
                            <select class="form-select" name="intake" id="intake" required
                                data-error-msg="Intake selection is required">
                                <option selected disabled>Select intake</option>
                                <option>January</option>
                                <option>May</option>
                                <option>September</option>
                            </select>
                  </div>
                  </div>
                    <div class="col-md-6">
                      <div class="form-group">
                            <label for="mode">Mode of Study</label>
                            <select class="form-select" name="mode" id="mode" required
                                data-error-msg="Mode of study is required">
                                <option selected disabled>Select mode</option>
                                <option>Full Time</option>
                                <option>Part Time</option>
                                <option>Distance</option>
                                <option>Online</option>
                            </select>
                        </div>
                      </div>
                  </div>
                
            <div class="btns-group">
                    <button type="button" class="btn btn-outline-primary btn-prev">
                        <i class="fas fa-arrow-left"></i> Previous
                    </button>
                    <button type="button" class="btn btn-primary btn-next">
                        <i class="fas fa-arrow-right"></i> Next
                    </button>
            </div>
          </div>
            
            <!-- Step 4: Sponsorship -->
          <div class="form-step">
          <div class="form-group">
                    <label>Sponsorship Type</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="sponsorship_type" 
                            id="sponsor_self" value="Self" checked>
                        <label class="form-check-label" for="sponsor_self">
                            Self-Sponsored
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="sponsorship_type" 
                            id="sponsor_org" value="Organization">
                        <label class="form-check-label" for="sponsor_org">
                            Organization-Sponsored
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="sponsorship_type" 
                            id="sponsor_other" value="Other">
                        <label class="form-check-label" for="sponsor_other">
                            Other
                        </label>
                    </div>
                </div>
                
                <div class="sponsor-details mt-4">
                <div class="form-group">
                        <label for="sponsor_name">Sponsor Name/Organization</label>
                        <input type="text" class="form-control" name="sponsor_name" id="sponsor_name" 
                            placeholder="Enter sponsor name or organization">
                </div>
                    
                <div class="form-group">
                        <label for="sponsor_contact">Sponsor Contact</label>
                        <input type="text" class="form-control" name="sponsor_contact" id="sponsor_contact" 
                            placeholder="Enter sponsor contact information">
                </div>
                    
                <div class="form-group">
                        <label for="sponsor_address">Sponsor Address</label>
                        <textarea class="form-control" name="sponsor_address" id="sponsor_address" 
                            placeholder="Enter sponsor address" rows="3"></textarea>
                    </div>
                </div>
                
            <div class="btns-group">
                    <button type="button" class="btn btn-outline-primary btn-prev">
                        <i class="fas fa-arrow-left"></i> Previous
                    </button>
                    <button type="button" class="btn btn-primary btn-next">
                        <i class="fas fa-arrow-right"></i> Next
                    </button>
            </div>
          </div>
            
            <!-- Step 5: Documents -->
          <div class="form-step">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="profile_image">Profile Photo</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" name="profile_image" id="profile_image">
                                <label class="custom-file-label" for="profile_image">Choose file</label>
                            </div>
                            <small class="text-muted">Maximum size: 2MB. Formats: JPG, PNG</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="nrc_copy">National ID/Passport Copy</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" name="nrc_copy" id="nrc_copy" required
                                    data-error-msg="ID/Passport copy is required">
                                <label class="custom-file-label" for="nrc_copy">Choose file</label>
                            </div>
                            <small class="text-muted">Maximum size: 5MB. Format: PDF preferred</small>
                        </div>
                    </div>
                </div>
                
          <div class="form-group">
                    <label for="certificate">Academic Certificate</label>
                    <div class="custom-file">
                        <input type="file" class="custom-file-input" name="certificate" id="certificate" required
                            data-error-msg="Academic certificate is required">
                        <label class="custom-file-label" for="certificate">Choose file</label>
                    </div>
                    <small class="text-muted">Maximum size: 5MB. Format: PDF preferred</small>
            </div>
                
            <div class="form-group">
                    <label for="other_docs">Other Supporting Documents</label>
                    <div class="custom-file">
                        <input type="file" class="custom-file-input" name="other_docs" id="other_docs">
                        <label class="custom-file-label" for="other_docs">Choose file</label>
                    </div>
                    <small class="text-muted">Maximum size: 5MB. Format: PDF preferred</small>
                </div>
                
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="confirm_info" name="confirm_info" required
                        data-error-msg="You must confirm your information">
                    <label class="form-check-label" for="confirm_info">
                        I confirm that all information provided is accurate and complete
                    </label>
            </div>
                
            <div class="btns-group">
                    <button type="button" class="btn btn-outline-primary btn-prev">
                        <i class="fas fa-arrow-left"></i> Previous
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check-circle"></i> Submit Registration
                    </button>
            </div>
          </div>
        </form>
    </div>
  </div>

<!-- Include modernized form scripts -->

<script src="js/modernScriptForm.js" defer></script>

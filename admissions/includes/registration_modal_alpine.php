<?php
/**
 * Inline Single-Student Registration Wizard
 * -----------------------------------------
 * REDESIGN: this was previously a Bootstrap modal (#registrationModal). It has
 * been converted to an on-page wizard rendered inside the registration page and
 * toggled with the Alpine `view` state (`view === 'single'`). This removes the
 * modal/backdrop class of bugs entirely and behaves cleanly on mobile.
 *
 * IMPORTANT: all field names (form.*), validation rules, and the submit payload
 * are IDENTICAL to the modal version — only the presentation changed, so the
 * backend handler (handleNewStudentRegistration) is untouched.
 *
 * Steps:  1 Personal · 2 Next of Kin · 3 Program & Fees · 4 Documents · 5 Review
 */
?>
<div class="card shadow-sm reg-card" id="singleRegForm" x-show="view === 'single'" x-cloak>
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>New Student Registration</h5>
        <button type="button" class="btn btn-sm btn-light" @click="closeForms()">
            <i class="fas fa-times me-1"></i> Cancel
        </button>
    </div>

    <div class="card-body">
        <!-- Step indicator -->
        <ul class="nav nav-pills nav-justified mb-3 reg-stepper">
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{'active': step === 1, 'done': step > 1}" @click="goToStep(1)">
                    <span class="reg-step-num">1</span><span class="reg-step-label">Personal</span>
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{'active': step === 2, 'done': step > 2}" @click="goToStep(2)">
                    <span class="reg-step-num">2</span><span class="reg-step-label">Next of Kin</span>
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{'active': step === 3, 'done': step > 3}" @click="goToStep(3)">
                    <span class="reg-step-num">3</span><span class="reg-step-label">Program &amp; Fees</span>
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{'active': step === 4, 'done': step > 4}" @click="goToStep(4)">
                    <span class="reg-step-num">4</span><span class="reg-step-label">Documents</span>
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{'active': step === 5}" @click="goToStep(5)">
                    <span class="reg-step-num">5</span><span class="reg-step-label">Review</span>
                </button>
            </li>
        </ul>
        <div class="progress mb-4 reg-progress" role="progressbar" aria-label="Registration progress">
            <div class="progress-bar" :style="`width: ${((step - 1) / 4) * 100}%`"></div>
        </div>

        <form @submit.prevent="submitForm" enctype="multipart/form-data" autocomplete="off">

            <!-- Step 1: Personal Details -->
            <div x-show="step === 1" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" :class="{'is-invalid': errors.fname}" x-model="form.fname" required>
                    <div class="invalid-feedback">First name is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" :class="{'is-invalid': errors.lname}" x-model="form.lname" required>
                    <div class="invalid-feedback">Last name is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <select class="form-select" :class="{'is-invalid': errors.gender}" x-model="form.gender" required>
                        <option value="">Select Gender</option>
                        <option value="M">Male</option>
                        <option value="F">Female</option>
                    </select>
                    <div class="invalid-feedback">Gender is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" :class="{'is-invalid': errors.dob}" x-model="form.dob" required>
                    <div class="invalid-feedback">Enter a valid date of birth (age 16&ndash;100)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" :class="{'is-invalid': errors.email}" x-model="form.email" required>
                    <div class="invalid-feedback">A valid email is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control" :class="{'is-invalid': errors.phone}" x-model="form.phone" placeholder="+260 ..." required>
                    <div class="invalid-feedback">Phone must have 9&ndash;15 digits</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">NRC / Passport <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" :class="{'is-invalid': errors.nrc}" x-model="form.nrc" required>
                    <div class="invalid-feedback">NRC/Passport must have 6+ digits</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" x-model="form.address" rows="1"></textarea>
                </div>
            </div>

            <!-- Step 2: Next of Kin -->
            <div x-show="step === 2" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">NOK First Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" :class="{'is-invalid': errors.nok_fname}" x-model="form.nok_fname" required>
                    <div class="invalid-feedback">Required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">NOK Last Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" :class="{'is-invalid': errors.nok_lname}" x-model="form.nok_lname" required>
                    <div class="invalid-feedback">Required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Relationship <span class="text-danger">*</span></label>
                    <select class="form-select" :class="{'is-invalid': errors.nok_relationship}" x-model="form.nok_relationship" required>
                        <option value="">Select Relationship</option>
                        <option value="Parent">Parent</option>
                        <option value="Guardian">Guardian</option>
                        <option value="Spouse">Spouse</option>
                        <option value="Sibling">Sibling</option>
                        <option value="Other">Other</option>
                    </select>
                    <div class="invalid-feedback">Required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">NOK Phone <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control" :class="{'is-invalid': errors.nok_phone}" x-model="form.nok_phone" required>
                    <div class="invalid-feedback">Required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">NOK Email</label>
                    <input type="email" class="form-control" x-model="form.nok_email">
                </div>
                <div class="col-md-6">
                    <label class="form-label">NOK Address</label>
                    <textarea class="form-control" x-model="form.nok_address" rows="1"></textarea>
                </div>
            </div>

            <!-- Step 3: Program & Fees -->
            <div x-show="step === 3" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Program <span class="text-danger">*</span></label>
                    <select class="form-select" :class="{'is-invalid': errors.program}" x-model="form.program" @change="updateSemesterOptions" required>
                        <option value="">Select Program</option>
                        <template x-for="(prog, code) in programsData" :key="code">
                            <option :value="code" x-text="prog.name"></option>
                        </template>
                    </select>
                    <div class="invalid-feedback">Program is required</div>
                </div>

                <!-- Short Course Specific Fields -->
                <template x-if="form.program && programsData[form.program].academic_structure === 'short_course'">
                    <div class="col-md-6">
                        <label class="form-label">Intake Batch / Start Date <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" :class="{'is-invalid': errors.intake_batch}" x-model="form.intake_batch" placeholder="e.g. June 2026 Batch" required>
                        <div class="invalid-feedback">Intake batch or start date is required</div>
                    </div>
                </template>
                <template x-if="form.program && programsData[form.program].academic_structure === 'short_course'">
                    <div class="col-md-6">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" x-model="form.start_date">
                    </div>
                </template>
                <template x-if="form.program && programsData[form.program].academic_structure === 'short_course'">
                    <div class="col-md-6">
                        <label class="form-label">Duration</label>
                        <div class="input-group">
                            <input type="number" class="form-control" x-model.number="form.duration_value">
                            <select class="form-select" x-model="form.duration_unit">
                                <option value="days">Days</option>
                                <option value="weeks">Weeks</option>
                                <option value="months">Months</option>
                                <option value="years">Years</option>
                            </select>
                        </div>
                    </div>
                </template>

                <!-- Term / Semester Specific Fields -->
                <template x-if="form.program && programsData[form.program].academic_structure !== 'short_course'">
                    <div class="col-md-6">
                        <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" :class="{'is-invalid': errors.academic_year}" x-model="form.academic_year" placeholder="e.g. 2026" required>
                        <div class="invalid-feedback">Academic year is required</div>
                    </div>
                </template>
                <template x-if="form.program && programsData[form.program].academic_structure !== 'short_course'">
                    <div class="col-md-6">
                        <label class="form-label">Year of Study <span class="text-danger">*</span></label>
                        <select class="form-select" :class="{'is-invalid': errors.year_of_study}" x-model="form.year_of_study" required>
                            <option value="1">Year 1</option>
                            <option value="2">Year 2</option>
                            <option value="3">Year 3</option>
                            <option value="4">Year 4</option>
                        </select>
                        <div class="invalid-feedback">Year of study is required</div>
                    </div>
                </template>

                <!-- Term Select (For Certificate/Diploma) -->
                <template x-if="form.program && (programsData[form.program].academic_structure === 'certificate_term' || programsData[form.program].academic_structure === 'diploma_term')">
                    <div class="col-md-6">
                        <label class="form-label">Term <span class="text-danger">*</span></label>
                        <select class="form-select" :class="{'is-invalid': errors.semester}" x-model="form.semester" required>
                            <option value="">Select Term</option>
                            <option value="1">Term 1</option>
                            <option value="2">Term 2</option>
                            <option value="3">Term 3</option>
                        </select>
                        <div class="invalid-feedback">Term is required</div>
                    </div>
                </template>

                <!-- Semester Select (For Transport Exception) -->
                <template x-if="form.program && programsData[form.program].academic_structure === 'semester_exception'">
                    <div class="col-md-6">
                        <label class="form-label">Semester <span class="text-danger">*</span></label>
                        <select class="form-select" :class="{'is-invalid': errors.semester}" x-model="form.semester" required>
                            <option value="">Select Semester</option>
                            <option value="1">Semester 1 (Jan)</option>
                            <option value="2">Semester 2 (Jul)</option>
                        </select>
                        <div class="invalid-feedback">Semester is required</div>
                        <div class="form-text text-info mt-1"><i class="fas fa-info-circle me-1"></i>Note: For Transport & Logistics, 6 months constitutes one semester, with 2 semesters per year.</div>
                    </div>
                </template>

                <div class="col-md-6">
                    <label class="form-label">Study Mode <span class="text-danger">*</span></label>
                    <select class="form-select" :class="{'is-invalid': errors.mode}" x-model="form.mode" required>
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                        <option value="Distance">Distance</option>
                    </select>
                    <div class="invalid-feedback">Study mode is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Entry Year</label>
                    <input type="number" class="form-control" :class="{'is-invalid': errors.entry_year}" x-model.number="form.entry_year" min="2000" max="<?= date('Y') + 1 ?>">
                    <div class="invalid-feedback">Entry year must be between 2000 and <?= date('Y') + 1 ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Previous School</label>
                    <input type="text" class="form-control" x-model="form.previous_school">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Highest Qualification</label>
                    <input type="text" class="form-control" x-model="form.qualification">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Sponsor / Bursary</label>
                    <select class="form-select" x-model="form.sponsor"
                            @change="form.bursary_percentage = ['TEVETA','CDF'].includes(form.sponsor) ? '100' : '0'">
                        <option value="Self">Self-sponsored</option>
                        <option value="Parent/Guardian">Parent / Guardian</option>
                        <option value="TEVETA">TEVETA (100% bursary)</option>
                        <option value="CDF">CDF (100% bursary)</option>
                        <option value="Other">Other</option>
                    </select>
                    <div class="form-text" x-show="['TEVETA','CDF'].includes(form.sponsor)">
                        <i class="fas fa-info-circle me-1"></i>Full (100%) bursary — the invoice will be zero.
                    </div>
                </div>
            </div>

            <!-- Step 4: Documents -->
            <div x-show="step === 4" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Profile Photo</label>
                    <input type="file" class="form-control" :class="{'is-invalid': errors.profile_photo}" @change="handleFileUpload($event, 'profile_photo')" accept="<?= wucUploadAcceptAttr('image') ?>">
                    <div class="form-text">Max 5MB (JPG/PNG/WebP)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Academic Results</label>
                    <input type="file" class="form-control" :class="{'is-invalid': errors.academic_results}" @change="handleFileUpload($event, 'academic_results')" accept="<?= wucUploadAcceptAttr('document') ?>">
                    <div class="form-text">Max 5MB (PDF/JPG/PNG/WebP)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">NRC / ID Copy</label>
                    <input type="file" class="form-control" @change="handleFileUpload($event, 'id_copy')" accept="<?= wucUploadAcceptAttr('document') ?>">
                    <div class="form-text">Max 5MB (PDF/JPG/PNG/WebP)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Certificate</label>
                    <input type="file" class="form-control" @change="handleFileUpload($event, 'certificate')" accept="<?= wucUploadAcceptAttr('document') ?>">
                    <div class="form-text">Max 5MB (PDF/JPG/PNG/WebP)</div>
                </div>
            </div>

            <!-- Step 5: Review & Confirm -->
            <div x-show="step === 5">
                <div class="alert alert-info d-flex align-items-center">
                    <i class="fas fa-clipboard-check me-2"></i>
                    Please review the details below, then click <strong class="mx-1">Register Student</strong>.
                </div>
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="reg-review-block">
                            <div class="reg-review-head">
                                <span><i class="fas fa-user me-2"></i>Personal</span>
                                <button type="button" class="btn btn-sm btn-link p-0" @click="goToStep(1)">Edit</button>
                            </div>
                            <dl class="reg-review-list">
                                <dt>Name</dt><dd x-text="(form.fname + ' ' + form.lname).trim() || '—'"></dd>
                                <dt>Gender</dt><dd x-text="form.gender === 'M' ? 'Male' : (form.gender === 'F' ? 'Female' : '—')"></dd>
                                <dt>Date of Birth</dt><dd x-text="form.dob || '—'"></dd>
                                <dt>Email</dt><dd x-text="form.email || '—'"></dd>
                                <dt>Phone</dt><dd x-text="form.phone || '—'"></dd>
                                <dt>NRC/Passport</dt><dd x-text="form.nrc || '—'"></dd>
                                <dt>Address</dt><dd x-text="form.address || '—'"></dd>
                            </dl>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="reg-review-block">
                            <div class="reg-review-head">
                                <span><i class="fas fa-people-arrows me-2"></i>Next of Kin</span>
                                <button type="button" class="btn btn-sm btn-link p-0" @click="goToStep(2)">Edit</button>
                            </div>
                            <dl class="reg-review-list">
                                <dt>Name</dt><dd x-text="(form.nok_fname + ' ' + form.nok_lname).trim() || '—'"></dd>
                                <dt>Relationship</dt><dd x-text="form.nok_relationship || '—'"></dd>
                                <dt>Phone</dt><dd x-text="form.nok_phone || '—'"></dd>
                                <dt>Email</dt><dd x-text="form.nok_email || '—'"></dd>
                                <dt>Address</dt><dd x-text="form.nok_address || '—'"></dd>
                            </dl>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="reg-review-block">
                            <div class="reg-review-head">
                                <span><i class="fas fa-graduation-cap me-2"></i>Program &amp; Fees</span>
                                <button type="button" class="btn btn-sm btn-link p-0" @click="goToStep(3)">Edit</button>
                            </div>
                            <dl class="reg-review-list">
                                <dt>Program</dt><dd x-text="getProgramName(form.program) || '—'"></dd>
                                <dt>Semester/Term</dt><dd x-text="semesterLabel(form.program, form.semester) || '—'"></dd>
                                <dt>Study Mode</dt><dd x-text="form.mode || '—'"></dd>
                                <dt>Entry Year</dt><dd x-text="form.entry_year || '—'"></dd>
                                <dt>Sponsor</dt><dd x-text="form.sponsor || '—'"></dd>
                                <dt>Bursary</dt><dd x-text="(Number(form.bursary_percentage) || 0) + '%'"></dd>
                            </dl>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="reg-review-block">
                            <div class="reg-review-head">
                                <span><i class="fas fa-paperclip me-2"></i>Documents</span>
                                <button type="button" class="btn btn-sm btn-link p-0" @click="goToStep(4)">Edit</button>
                            </div>
                            <dl class="reg-review-list">
                                <dt>Profile Photo</dt><dd x-text="files.profile_photo ? files.profile_photo.name : 'Not attached'"></dd>
                                <dt>Academic Results</dt><dd x-text="files.academic_results ? files.academic_results.name : 'Not attached'"></dd>
                                <dt>NRC/ID Copy</dt><dd x-text="files.id_copy ? files.id_copy.name : 'Not attached'"></dd>
                                <dt>Certificate</dt><dd x-text="files.certificate ? files.certificate.name : 'Not attached'"></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Wizard footer -->
            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <button type="button" class="btn btn-outline-secondary" @click="prevStep" x-show="step > 1">
                    <i class="fas fa-arrow-left me-1"></i> Previous
                </button>
                <span x-show="step === 1"></span>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" @click="nextStep" x-show="step < 5">
                        Next <i class="fas fa-arrow-right ms-1"></i>
                    </button>
                    <button type="submit" class="btn btn-success" x-show="step === 5" :disabled="submitting">
                        <span x-show="submitting"><span class="spinner-border spinner-border-sm me-2"></span>Registering...</span>
                        <span x-show="!submitting"><i class="fas fa-check-circle me-1"></i> Register Student</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

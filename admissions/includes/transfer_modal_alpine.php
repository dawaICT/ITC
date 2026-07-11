<?php
/**
 * Inline Transfer-Student Registration Wizard + Bulk Panel
 * --------------------------------------------------------
 * REDESIGN: this was previously a Bootstrap modal (#transferModal). It has been
 * converted to on-page panels toggled by the Alpine `view` state, mirroring the
 * New Student page (regNewStud.php) so both registration flows look and behave
 * the same. No modal => no backdrop => no stuck-overlay class of bugs.
 *
 * IMPORTANT: all field names (form.*), file keys, the bulk CSV mapping and the
 * submit payload are IDENTICAL to the modal version — only the presentation
 * changed, so the backend handlers (handleTransferRegistration,
 * handleGetTransferStudents) are untouched.
 *
 * Steps:  1 Personal · 2 Contact & Address · 3 Transfer Details · 4 Documents · 5 Review
 */
?>
<!-- ===================== SINGLE WIZARD VIEW ===================== -->
<div class="card shadow-sm reg-card" id="transferRegForm" x-show="view === 'single'" x-cloak>
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-exchange-alt me-2"></i>Register Transfer Student</h5>
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
                    <span class="reg-step-num">2</span><span class="reg-step-label">Contact &amp; Address</span>
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{'active': step === 3, 'done': step > 3}" @click="goToStep(3)">
                    <span class="reg-step-num">3</span><span class="reg-step-label">Transfer Details</span>
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

        <form @submit.prevent="submitSingle" enctype="multipart/form-data" autocomplete="off">

            <!-- Step 1: Personal Information -->
            <div x-show="step === 1" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" :class="{'is-invalid': errors.full_name}" x-model="form.full_name" placeholder="Enter full name" required>
                    <div class="invalid-feedback">Full name is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">NRC / Passport <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" :class="{'is-invalid': errors.nrc_pass}" x-model="form.nrc_pass" @input="form.nrc_pass = formatNrcInput($event.target.value)" placeholder="123456/11/1" required>
                    <div class="invalid-feedback">NRC/Passport is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" :class="{'is-invalid': errors.dob}" x-model="form.dob" required>
                    <div class="invalid-feedback">Date of birth is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <select class="form-select" :class="{'is-invalid': errors.sex}" x-model="form.sex" required>
                        <option value="">Select Gender</option>
                        <option value="M">Male</option>
                        <option value="F">Female</option>
                    </select>
                    <div class="invalid-feedback">Gender is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" x-model="form.email" placeholder="email@example.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Academic Year</label>
                    <input type="number" class="form-control" x-model="form.academic_year" min="2000" max="<?= date('Y') + 5 ?>">
                </div>
            </div>

            <!-- Step 2: Contact & Address -->
            <div x-show="step === 2" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control" :class="{'is-invalid': errors.mobile}" x-model="form.mobile" @input="form.mobile = formatMobileInput($event.target.value)" placeholder="0977000000" required>
                    <div class="invalid-feedback">Enter a valid 10-digit mobile number</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Address <span class="text-danger">*</span></label>
                    <textarea class="form-control" :class="{'is-invalid': errors.h_addre}" x-model="form.h_addre" rows="2" placeholder="Home address" required></textarea>
                    <div class="invalid-feedback">Address is required</div>
                </div>
            </div>

            <!-- Step 3: Transfer Details -->
            <div x-show="step === 3" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Previous School <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" :class="{'is-invalid': errors.school}" x-model="form.school" placeholder="Previous institution name" required>
                    <div class="invalid-feedback">Previous school is required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Transfer Credits <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" :class="{'is-invalid': errors.transfer_credits}" x-model="form.transfer_credits" min="0" placeholder="Credits to transfer" required>
                    <div class="invalid-feedback">Transfer credits are required</div>
                </div>
            </div>

            <!-- Step 4: Documents -->
            <div x-show="step === 4" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Profile Photo <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" :class="{'is-invalid': errors.profile_image}" @change="handleFile($event, 'profile_image')" accept="<?= wucUploadAcceptAttr('image') ?>">
                    <div class="form-text">Max 5MB (JPG/PNG/WebP)</div>
                    <div class="invalid-feedback">Profile photo is required</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Academic Results <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" :class="{'is-invalid': errors.results}" @change="handleFile($event, 'results')" accept="<?= wucUploadAcceptAttr('document') ?>">
                    <div class="form-text">Max 5MB (PDF/JPG/PNG/WebP)</div>
                    <div class="invalid-feedback">Academic results are required</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">NRC Copy <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" :class="{'is-invalid': errors.nrc_file}" @change="handleFile($event, 'nrc_file')" accept="<?= wucUploadAcceptAttr('document') ?>">
                    <div class="form-text">Max 5MB (PDF/JPG/PNG/WebP)</div>
                    <div class="invalid-feedback">NRC copy is required</div>
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
                                <dt>Full Name</dt><dd x-text="form.full_name || '—'"></dd>
                                <dt>NRC/Passport</dt><dd x-text="form.nrc_pass || '—'"></dd>
                                <dt>Date of Birth</dt><dd x-text="form.dob || '—'"></dd>
                                <dt>Gender</dt><dd x-text="form.sex === 'M' ? 'Male' : (form.sex === 'F' ? 'Female' : '—')"></dd>
                                <dt>Email</dt><dd x-text="form.email || '—'"></dd>
                                <dt>Academic Year</dt><dd x-text="form.academic_year || '—'"></dd>
                            </dl>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="reg-review-block">
                            <div class="reg-review-head">
                                <span><i class="fas fa-address-book me-2"></i>Contact &amp; Address</span>
                                <button type="button" class="btn btn-sm btn-link p-0" @click="goToStep(2)">Edit</button>
                            </div>
                            <dl class="reg-review-list">
                                <dt>Mobile</dt><dd x-text="form.mobile || '—'"></dd>
                                <dt>Address</dt><dd x-text="form.h_addre || '—'"></dd>
                            </dl>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="reg-review-block">
                            <div class="reg-review-head">
                                <span><i class="fas fa-exchange-alt me-2"></i>Transfer Details</span>
                                <button type="button" class="btn btn-sm btn-link p-0" @click="goToStep(3)">Edit</button>
                            </div>
                            <dl class="reg-review-list">
                                <dt>Previous School</dt><dd x-text="form.school || '—'"></dd>
                                <dt>Transfer Credits</dt><dd x-text="form.transfer_credits || '—'"></dd>
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
                                <dt>Profile Photo</dt><dd x-text="files.profile_image ? files.profile_image.name : 'Not attached'"></dd>
                                <dt>Academic Results</dt><dd x-text="files.results ? files.results.name : 'Not attached'"></dd>
                                <dt>NRC Copy</dt><dd x-text="files.nrc_file ? files.nrc_file.name : 'Not attached'"></dd>
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

<!-- ===================== BULK PANEL VIEW ===================== -->
<div class="card shadow-sm reg-card" id="transferBulkForm" x-show="view === 'bulk'" x-cloak>
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Bulk Transfer Import</h5>
        <button type="button" class="btn btn-sm btn-light" @click="closeForms()">
            <i class="fas fa-times me-1"></i> Cancel
        </button>
    </div>

    <div class="card-body">
        <div class="alert alert-info">
            <strong>Instructions:</strong>
            <ol class="mb-0">
                <li>Download the template CSV file below.</li>
                <li>Fill in student data (one student per row).</li>
                <li>Upload the completed CSV file.</li>
                <li>Wait for processing to complete.</li>
            </ol>
        </div>

        <div class="mb-3">
            <button type="button" class="btn btn-outline-primary" @click="downloadTemplate">
                <i class="fas fa-download me-2"></i>Download Template
            </button>
        </div>

        <div class="mb-3">
            <label class="form-label">Upload CSV File</label>
            <input type="file" class="form-control" x-ref="csvFile" accept=".csv">
            <div class="form-text">Upload completed CSV file with transfer student data</div>
        </div>

        <!-- Progress Bar -->
        <div x-show="bulk.processing">
            <div class="mb-3">
                <div class="progress" style="height: 25px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" :style="`width: ${bulk.percent}%`" x-text="`${bulk.percent}%`"></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header bg-light"><strong>Processing Log</strong></div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <template x-for="(log, index) in bulk.logs" :key="index">
                        <div :class="`alert alert-${log.type === 'success' ? 'success' : 'danger'} mb-2 p-2`" x-text="log.msg"></div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <div class="card-footer d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-outline-secondary" @click="closeForms()">Cancel</button>
        <button type="button" class="btn btn-success" @click="startBulkUpload" :disabled="bulk.processing">
            <span x-show="bulk.processing"><span class="spinner-border spinner-border-sm me-2"></span>Processing...</span>
            <span x-show="!bulk.processing"><i class="fas fa-upload me-2"></i>Start Import</span>
        </button>
    </div>
</div>

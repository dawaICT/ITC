<?php
/**
 * Inline Bulk-Student Registration Panel
 * --------------------------------------
 * REDESIGN: previously a Bootstrap modal (#bulkRegistrationModal). Converted to
 * an on-page panel toggled by the Alpine `view` state (`view === 'bulk'`).
 *
 * All bound state (bulkStudents[], bulkCommon, file inputs) and the submit
 * payload are unchanged from the modal version — only the presentation changed,
 * so the backend handler (handleBulkStudentRegistration) is untouched.
 */
?>
<div class="card shadow-sm reg-card" id="bulkRegForm" x-show="view === 'bulk'" x-cloak>
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Bulk Student Registration</h5>
        <button type="button" class="btn btn-sm btn-light" @click="closeForms()">
            <i class="fas fa-times me-1"></i> Cancel
        </button>
    </div>

    <form @submit.prevent="submitBulkRegistration" enctype="multipart/form-data">
        <div class="card-body">
            <!-- Instructions -->
            <div class="alert alert-info">
                <strong>Instructions:</strong>
                <ol class="mb-0">
                    <li>Fill in details for each student in the grid below.</li>
                    <li>Optionally upload profile photos and academic results in student order.</li>
                    <li>Click "Register All" to register every student at once.</li>
                    <li>Rows with errors are skipped and reported individually.</li>
                </ol>
            </div>

            <!-- Student grid -->
            <div class="mb-4">
                <button type="button" class="btn btn-sm btn-primary mb-3" @click="addStudentRow">
                    <i class="fas fa-plus me-1"></i>Add Student
                </button>

                <div class="table-responsive">
                    <table class="table table-hover align-middle small">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 32px;">#</th>
                                <th>First Name</th>
                                <th>Last Name</th>
                                <th style="width: 80px;">Gender</th>
                                <th style="width: 140px;">DOB</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>NRC</th>
                                <th>Program</th>
                                <th style="width: 130px;">Semester</th>
                                <th style="width: 40px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(student, index) in bulkStudents" :key="index">
                                <tr>
                                    <td x-text="index + 1"></td>
                                    <td><input type="text" class="form-control form-control-sm" x-model="student.fname" required></td>
                                    <td><input type="text" class="form-control form-control-sm" x-model="student.lname" required></td>
                                    <td>
                                        <select class="form-select form-select-sm" x-model="student.gender" required>
                                            <option value="M">M</option>
                                            <option value="F">F</option>
                                        </select>
                                    </td>
                                    <td><input type="date" class="form-control form-control-sm" x-model="student.dob" required></td>
                                    <td><input type="email" class="form-control form-control-sm" x-model="student.email" required></td>
                                    <td><input type="tel" class="form-control form-control-sm" x-model="student.phone" required placeholder="+260 ..."></td>
                                    <td><input type="text" class="form-control form-control-sm" x-model="student.nrc" required></td>
                                    <td>
                                        <select class="form-select form-select-sm" x-model="student.program" required>
                                            <option value="">Select</option>
                                            <template x-for="(prog, code) in programsData" :key="code">
                                                <option :value="code" x-text="prog.name"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm" x-model="student.semester" required>
                                            <option value="">Select</option>
                                            <template x-for="opt in getSemesterOptions(student.program)" :key="opt.val">
                                                <option :value="opt.val" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger" @click="removeStudentRow(index)" title="Remove row" :disabled="bulkStudents.length === 1">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small mb-0">
                    <span x-text="bulkStudents.length"></span> student row(s).
                </p>
            </div>

            <!-- File uploads -->
            <div class="mb-4">
                <h6 class="fw-bold mb-3">Upload Files for Each Student</h6>
                <p class="text-muted small mb-3">Optional uploads are matched in the same order as the students above.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Profile Photos (multiple files)</label>
                        <input type="file" class="form-control" name="profile_photos[]" @change="handleBulkFileUpload($event, 'profile')" multiple accept="<?= wucUploadAcceptAttr('image') ?>">
                        <div class="form-text">Max 5MB per file (JPG/PNG/WebP)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Academic Results (multiple files)</label>
                        <input type="file" class="form-control" name="academic_results[]" @change="handleBulkFileUpload($event, 'academic')" multiple accept="<?= wucUploadAcceptAttr('document') ?>">
                        <div class="form-text">Max 5MB per file (PDF/JPG/PNG/WebP)</div>
                    </div>
                </div>
            </div>

            <!-- Common fields -->
            <div class="mb-2 p-3 bg-light rounded">
                <h6 class="fw-bold mb-3">Common Information (applies to all students)</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Intake</label>
                        <select class="form-select" x-model="bulkCommon.intake">
                            <option value="">Auto-detect current intake</option>
                            <option value="January <?= date('Y') ?>">January <?= date('Y') ?></option>
                            <option value="June <?= date('Y') ?>">June <?= date('Y') ?></option>
                            <option value="January <?= date('Y') + 1 ?>">January <?= date('Y') + 1 ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Study Mode</label>
                        <select class="form-select" x-model="bulkCommon.mode">
                            <option value="Full-time">Full-time</option>
                            <option value="Part-time">Part-time</option>
                            <option value="Distance">Distance</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Entry Year</label>
                        <input type="number" class="form-control" x-model.number="bulkCommon.entry_year" min="2000" max="<?= date('Y') + 1 ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary" @click="closeForms()">Cancel</button>
            <button type="submit" class="btn btn-success" :disabled="isSubmittingBulk">
                <span x-show="isSubmittingBulk"><span class="spinner-border spinner-border-sm me-2"></span>Registering...</span>
                <span x-show="!isSubmittingBulk"><i class="fas fa-user-plus me-1"></i>Register All Students</span>
            </button>
        </div>
    </form>
</div>

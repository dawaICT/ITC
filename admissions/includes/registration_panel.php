<?php
/**
 * Shared new-student registration UI panel.
 *
 * Rendered identically by admissions/regNewStud.php and admin/regNewStud.php so
 * both modules present the SAME 5-step single wizard + bulk panel + recent list,
 * and submit to the SAME shared AJAX handlers. The only per-module difference is
 * the "Back to Students" link, supplied via $reg_back_url.
 *
 * Expects in scope: $csrf_token, $programs_data, and optionally $reg_back_url.
 */

$reg_back_url = isset($reg_back_url) && $reg_back_url !== '' ? $reg_back_url : 'students.php';
?>
<style>
    /* Inline registration wizard + panels. Scoped with reg-* prefixes. */
    [x-cloak] { display: none !important; }

    .reg-action-card {
        border: 1px solid #e9ecef;
        border-radius: .75rem;
        transition: transform .15s ease, box-shadow .15s ease;
        cursor: pointer;
        height: 100%;
    }
    .reg-action-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.08); }
    .reg-action-card .reg-action-icon {
        width: 3rem; height: 3rem; border-radius: .75rem;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1.25rem; color: #fff;
    }

    .reg-stepper .nav-link {
        display: flex; align-items: center; justify-content: center; gap: .5rem;
        color: #6c757d; background: #f1f3f5; border: 1px solid #e9ecef; font-weight: 500;
    }
    .reg-stepper .nav-link .reg-step-num {
        display: inline-flex; align-items: center; justify-content: center;
        width: 1.6rem; height: 1.6rem; border-radius: 50%;
        background: #dee2e6; color: #495057; font-size: .85rem; font-weight: 700; flex: 0 0 auto;
    }
    .reg-stepper .nav-link.active { background: var(--brand-primary, #2E3190); color: #fff; }
    .reg-stepper .nav-link.active .reg-step-num { background: #fff; color: var(--brand-primary, #2E3190); }
    .reg-stepper .nav-link.done { background: #e7f5ec; color: #198754; border-color: #cfe9d9; }
    .reg-stepper .nav-link.done .reg-step-num { background: #198754; color: #fff; }

    .reg-progress { height: 6px; }
    .reg-review-block { border: 1px solid #e9ecef; border-radius: .5rem; height: 100%; }
    .reg-review-head {
        display: flex; justify-content: space-between; align-items: center;
        padding: .6rem .9rem; background: #f8f9fa; border-bottom: 1px solid #e9ecef;
        font-weight: 600; border-radius: .5rem .5rem 0 0;
    }
    .reg-review-list { display: grid; grid-template-columns: auto 1fr; gap: .35rem .75rem; margin: 0; padding: .75rem .9rem; }
    .reg-review-list dt { color: #6c757d; font-weight: 500; }
    .reg-review-list dd { margin: 0; word-break: break-word; }

    @media (max-width: 576px) { .reg-stepper .reg-step-label { display: none; } }
</style>

<!-- Alpine.js Application -->
<div x-data="registrationApp()" x-init="init()" class="container-fluid px-4 py-4 portal-dashboard">

    <!-- Page header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <a href="<?= htmlspecialchars($reg_back_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="fas fa-arrow-left me-2"></i>Back to Students
                </a>
                <h1 class="dashboard-title">Student Registration</h1>
                <p class="text-muted mb-0">Register new students and manage their information</p>
            </div>
            <div class="col-auto" x-show="view === 'list'" x-cloak>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success" @click="openBulk()">
                        <i class="fas fa-users me-2"></i>Bulk Registration
                    </button>
                    <button type="button" class="btn btn-primary" @click="openSingle()">
                        <i class="fas fa-user-plus me-2"></i>New Registration
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== LIST VIEW ===================== -->
    <div x-show="view === 'list'" x-cloak>
        <!-- Quick action cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card reg-action-card" @click="openSingle()" role="button" tabindex="0" @keydown.enter="openSingle()">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="reg-action-icon" style="background: var(--brand-primary, #2E3190);">
                            <i class="fas fa-user-plus"></i>
                        </span>
                        <div>
                            <h5 class="mb-1">New Registration</h5>
                            <p class="text-muted small mb-0">Register a single student with a guided 5-step wizard.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card reg-action-card" @click="openBulk()" role="button" tabindex="0" @keydown.enter="openBulk()">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="reg-action-icon" style="background: #198754;">
                            <i class="fas fa-users"></i>
                        </span>
                        <div>
                            <h5 class="mb-1">Bulk Registration</h5>
                            <p class="text-muted small mb-0">Register many students at once from a single grid.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Registrations Table -->
        <div class="data-table-card">
            <div class="card-header">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="mb-0"><i class="fas fa-users me-2"></i>Recent Registrations</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <div class="input-group input-group-sm" style="width: 230px;">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" placeholder="Search name, ID, program..." x-model="search">
                        </div>
                        <button class="btn btn-sm btn-light" @click="fetchRecent" :disabled="loading">
                            <i class="fas fa-sync" :class="{'fa-spin': loading}"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>NRC</th>
                                <th>Program</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr x-show="loading && recentRegistrations.length === 0">
                                <td colspan="6" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status"></div>
                                </td>
                            </tr>
                            <template x-for="student in filteredRegistrations" :key="student.SID">
                                <tr>
                                    <td class="fw-bold" x-text="student.SID"></td>
                                    <td x-text="student.Fname + ' ' + student.Lname"></td>
                                    <!-- NRC is PII; mask all but the last 4 characters in the table. -->
                                    <td><code x-text="maskNrc(student.nrc_pass)" :title="'Full NRC visible on detail page'"></code></td>
                                    <td x-text="student.program_name"></td>
                                    <td>
                                        <span class="badge"
                                              :class="student.invoice_status === 'Paid' ? 'bg-success' : 'bg-warning'"
                                              x-text="student.invoice_status === 'Paid' ? 'Active' : 'Pending'">
                                        </span>
                                    </td>
                                    <td>
                                        <a :href="'view_student.php?id=' + student.SID" class="btn btn-sm btn-primary" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="!loading && recentRegistrations.length === 0">
                                <td colspan="6" class="text-center text-muted py-4">No recent registrations found</td>
                            </tr>
                            <tr x-show="!loading && recentRegistrations.length > 0 && filteredRegistrations.length === 0">
                                <td colspan="6" class="text-center text-muted py-4">No results match "<span x-text="search"></span>"</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== SINGLE WIZARD VIEW ===================== -->
    <?php include __DIR__ . '/registration_modal_alpine.php'; ?>

    <!-- ===================== BULK PANEL VIEW ===================== -->
    <?php include __DIR__ . '/bulk_registration_modal.php'; ?>

</div>

<!-- Scripts: only the libraries the unified layout does NOT already load. -->
<script src="https://cdn.jsdelivr.net/npm/axios@1.4.0/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.0/dist/cdn.min.js" defer></script>

<?php
// Both literals are emitted via json_encode with XSS-safe flags so a program
// name (or future token format) containing </script>, <, &, ', or " can never
// break out of this script block.
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
?>
<script>
const csrfToken = <?= json_encode($csrf_token, $jsonFlags) ?>;
const programsDataRaw = <?= json_encode($programs_data, $jsonFlags) ?>;
const ENTRY_YEAR_MIN = 2000;
const ENTRY_YEAR_MAX = <?= (int) date('Y') + 1 ?>;
// Allowed MIME types emitted from the shared upload validator so the client
// and server never drift. 'image' = profile photos, 'document' = results/id/cert.
const UPLOAD_MIME = {
    image: <?= wucUploadMimeListJson('image') ?>,
    document: <?= wucUploadMimeListJson('document') ?>
};
const UPLOAD_MAX_BYTES = <?= WUC_UPLOAD_MAX_BYTES ?>;

function registrationApp() {
    return {
        // A single `view` switch replaces Bootstrap modal instances.
        // No modal => no backdrop => no stuck-overlay class of bugs.
        view: 'list',           // 'list' | 'single' | 'bulk'
        step: 1,                // wizard step 1..5
        loading: false,
        submitting: false,
        search: '',
        programsData: programsDataRaw,
        recentRegistrations: [],

        // Single Registration Form Data
        form: {
            fname: '', lname: '', gender: '', dob: '', email: '', phone: '', nrc: '', address: '',
            nok_fname: '', nok_lname: '', nok_relationship: '', nok_phone: '', nok_email: '', nok_address: '',
            program: '', semester: '', mode: 'Full-time', previous_school: '', qualification: '',
            entry_year: new Date().getFullYear(), bursary_percentage: '0', sponsor: 'Self',
            academic_year: new Date().getFullYear(), year_of_study: '1', intake_batch: '', start_date: '',
            duration_value: '', duration_unit: ''
        },
        files: { profile_photo: null, academic_results: null, id_copy: null, certificate: null },
        errors: {},

        // Bulk Registration State
        bulkStudents: [
            { fname: '', lname: '', gender: 'M', dob: '', email: '', phone: '', nrc: '', program: '', semester: '' }
        ],
        bulkCommon: { intake: '', mode: 'Full-time', entry_year: new Date().getFullYear() },
        isSubmittingBulk: false,

        // -------- Derived data --------
        get semesterOptions() {
            return this.getSemesterOptions(this.form.program);
        },

        getSemesterOptions(programCode) {
            if (!programCode) return [];
            const mode = this.programsData[programCode]?.period_mode || 'semester';
            // Transport (rolling) courses admit any time of year and run for a fixed
            // duration from the registration date — a single rolling-intake option.
            if (mode === 'rolling') return [
                {val: '1', label: 'Rolling intake (starts at registration)'}
            ];
            return mode === 'term' ? [
                {val: '1', label: 'Term 1'},
                {val: '2', label: 'Term 2'},
                {val: '3', label: 'Term 3'}
            ] : [
                {val: '1', label: 'Semester 1 (Jan)'},
                {val: '2', label: 'Semester 2 (Jul)'}
            ];
        },

        semesterLabel(programCode, val) {
            const opt = this.getSemesterOptions(programCode).find(o => o.val === String(val));
            return opt ? opt.label : '';
        },

        get filteredRegistrations() {
            const q = this.search.trim().toLowerCase();
            if (!q) return this.recentRegistrations;
            return this.recentRegistrations.filter(s => {
                const hay = [
                    s.SID, s.Fname, s.Lname, (s.Fname + ' ' + s.Lname),
                    s.program_name, s.nrc_pass
                ].map(v => String(v ?? '').toLowerCase());
                return hay.some(v => v.includes(q));
            });
        },

        // -------- Lifecycle --------
        init() {
            const setup = () => this.fetchRecent();
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', setup, { once: true });
            } else {
                setup();
            }
        },

        // -------- View navigation --------
        openSingle() {
            this.resetForm();
            this.view = 'single';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        openBulk() {
            this.resetBulkForm();
            this.view = 'bulk';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        closeForms() {
            this.view = 'list';
            this.resetForm();
            this.resetBulkForm();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        // -------- Resets --------
        resetForm() {
            this.step = 1;
            this.form = {
                fname: '', lname: '', gender: '', dob: '', email: '', phone: '', nrc: '', address: '',
                nok_fname: '', nok_lname: '', nok_relationship: '', nok_phone: '', nok_email: '', nok_address: '',
                program: '', semester: '', mode: 'Full-time', previous_school: '', qualification: '',
                entry_year: new Date().getFullYear(), bursary_percentage: '0', sponsor: 'Self'
            };
            this.files = { profile_photo: null, academic_results: null, id_copy: null, certificate: null };
            this.errors = {};
            document.querySelectorAll('#singleRegForm input[type="file"]').forEach(el => { el.value = ''; });
        },
        resetBulkForm() {
            this.bulkStudents = [
                { fname: '', lname: '', gender: 'M', dob: '', email: '', phone: '', nrc: '', program: '', semester: '' }
            ];
            this.bulkCommon = { intake: '', mode: 'Full-time', entry_year: new Date().getFullYear() };
            document.querySelectorAll('#bulkRegForm input[type="file"]').forEach(el => { el.value = ''; });
        },

        // -------- Recent list --------
        async fetchRecent() {
            this.loading = true;
            try {
                const formData = new FormData();
                formData.append('action', 'get_recent');
                formData.append('csrf_token', csrfToken);

                const response = await axios.post(window.location.href, formData);
                if (response.data.success) {
                    this.recentRegistrations = response.data.data || [];
                } else {
                    throw new Error(response.data.message || 'Failed to load data');
                }
            } catch (e) {
                console.error('Fetch error:', e);
                Swal.fire('Error', 'Failed to load recent registrations', 'error');
            } finally {
                this.loading = false;
            }
        },

        // -------- File handling (single) --------
        handleFileUpload(event, fieldName) {
            const file = event.target.files[0];
            if (!file) { this.files[fieldName] = null; return; }

            // Shared rules (mirror of includes/upload_validator.php).
            const kind = fieldName === 'profile_photo' ? 'image' : 'document';
            const allowed = UPLOAD_MIME[kind] || [];
            const label = fieldName.replace(/_/g, ' ');

            if (file.size > UPLOAD_MAX_BYTES) {
                Swal.fire('Error', `${label} exceeds the ${Math.round(UPLOAD_MAX_BYTES / 1048576)}MB limit`, 'error');
                event.target.value = ''; this.files[fieldName] = null; this.errors[fieldName] = true; return;
            }
            if (allowed.length && !allowed.includes(file.type)) {
                Swal.fire('Error', `Invalid file type for ${label}`, 'error');
                event.target.value = ''; this.files[fieldName] = null; this.errors[fieldName] = true; return;
            }
            this.files[fieldName] = file;
            this.errors[fieldName] = false;
        },

        // -------- Bulk methods --------
        addStudentRow() {
            this.bulkStudents.push({ fname: '', lname: '', gender: 'M', dob: '', email: '', phone: '', nrc: '', program: '', semester: '' });
        },
        removeStudentRow(index) {
            if (this.bulkStudents.length > 1) this.bulkStudents.splice(index, 1);
        },
        handleBulkFileUpload(event, type) {
            console.log(`${type} files selected:`, event.target.files.length);
        },
        isValidEntryYear(value) {
            const year = Number(value);
            return Number.isInteger(year) && year >= ENTRY_YEAR_MIN && year <= ENTRY_YEAR_MAX;
        },

        async submitBulkRegistration() {
            this.isSubmittingBulk = true;
            try {
                if (this.bulkStudents.length === 0) {
                    Swal.fire('Error', 'Please add at least one student', 'error');
                    return;
                }
                if (!this.isValidEntryYear(this.bulkCommon.entry_year)) {
                    Swal.fire('Validation Error', `Entry year must be between ${ENTRY_YEAR_MIN} and ${ENTRY_YEAR_MAX}.`, 'warning');
                    return;
                }
                const isMissing = (s) => !s.fname || !s.lname || !s.gender || !s.dob ||
                    !s.email || !s.phone || !s.nrc || !s.program || !s.semester;
                const firstInvalidIndex = this.bulkStudents.findIndex(isMissing);
                if (firstInvalidIndex !== -1) {
                    Swal.fire('Validation Error',
                        `Row ${firstInvalidIndex + 1}: every column (gender, DOB, email, phone, NRC, program, semester) is required.`,
                        'warning');
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'bulk_register');
                formData.append('csrf_token', csrfToken);

                const studentsData = this.bulkStudents.map(s => ({
                    ...s,
                    intake: this.bulkCommon.intake || '',
                    mode: this.bulkCommon.mode,
                    entry_year: this.bulkCommon.entry_year
                }));
                formData.append('students', JSON.stringify(studentsData));

                const profileInput = document.querySelector('#bulkRegForm input[name="profile_photos[]"]');
                const academicInput = document.querySelector('#bulkRegForm input[name="academic_results[]"]');
                if (profileInput && profileInput.files.length > 0) {
                    for (let i = 0; i < profileInput.files.length; i++) formData.append('profile_photos[]', profileInput.files[i]);
                }
                if (academicInput && academicInput.files.length > 0) {
                    for (let i = 0; i < academicInput.files.length; i++) formData.append('academic_results[]', academicInput.files[i]);
                }

                const response = await axios.post(window.location.href, formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                });

                if (response.data.success) {
                    const escapeHtml = (s) => String(s ?? '')
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

                    const failedCount = Number(response.data.failed_count || 0);
                    const failedStudents = Array.isArray(response.data.failed_students) ? response.data.failed_students : [];
                    const studentIds = Array.isArray(response.data.student_ids) ? response.data.student_ids : [];

                    const failedBlock = failedCount > 0 ? `
                        <details class="mt-2" open>
                            <summary class="text-danger">
                                <strong>${failedCount} student${failedCount === 1 ? '' : 's'} failed — click for details</strong>
                            </summary>
                            <ul class="text-start mt-2">
                                ${failedStudents.map(f => `<li>#${escapeHtml(f.index)} ${escapeHtml(f.name || 'N/A')} — ${escapeHtml(f.error)}</li>`).join('')}
                            </ul>
                        </details>` : '';

                    this.view = 'list';

                    Swal.fire({
                        icon: failedCount > 0 ? 'warning' : 'success',
                        title: failedCount > 0 ? 'Bulk Registration: Partial Success' : 'Bulk Registration Complete!',
                        html: `
                            <p>${Number(response.data.registered_count || 0)} out of ${Number(response.data.total_count || 0)} students registered successfully</p>
                            ${studentIds.length > 0 ? `
                            <details>
                                <summary>View Student IDs</summary>
                                <ul class="text-start">${studentIds.map(id => `<li>${escapeHtml(id)}</li>`).join('')}</ul>
                            </details>` : ''}
                            ${failedBlock}
                        `,
                        confirmButtonText: 'OK'
                    });

                    this.fetchRecent();
                    this.resetBulkForm();
                } else {
                    throw new Error(response.data.message || 'Registration failed');
                }
            } catch (error) {
                console.error('Bulk registration error:', error);
                const msg = error.response?.data?.message || error.message || 'Bulk registration failed';
                Swal.fire('Error', msg, 'error');
            } finally {
                this.isSubmittingBulk = false;
            }
        },

        // -------- Single wizard validation/navigation --------
        validateStep(step) {
            this.errors = {};
            let isValid = true;
            const f = this.form;
            const digitsOnly = (v) => String(v ?? '').replace(/\D/g, '');

            if (step === 1) {
                if (!f.fname?.trim()) { this.errors.fname = true; isValid = false; }
                if (!f.lname?.trim()) { this.errors.lname = true; isValid = false; }
                if (!f.gender) { this.errors.gender = true; isValid = false; }
                if (!f.dob) {
                    this.errors.dob = true; isValid = false;
                } else {
                    const dob = new Date(f.dob);
                    if (isNaN(dob.getTime())) {
                        this.errors.dob = true; isValid = false;
                    } else {
                        const today = new Date();
                        let age = today.getFullYear() - dob.getFullYear();
                        const m = today.getMonth() - dob.getMonth();
                        if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
                        if (age < 16 || age > 100) { this.errors.dob = true; isValid = false; }
                    }
                }
                if (!f.email?.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) { this.errors.email = true; isValid = false; }
                if (digitsOnly(f.phone).length < 9 || digitsOnly(f.phone).length > 15) { this.errors.phone = true; isValid = false; }
                if (digitsOnly(f.nrc).length < 6) { this.errors.nrc = true; isValid = false; }
            }
            if (step === 2) {
                if (!f.nok_fname) { this.errors.nok_fname = true; isValid = false; }
                if (!f.nok_lname) { this.errors.nok_lname = true; isValid = false; }
                if (!f.nok_relationship) { this.errors.nok_relationship = true; isValid = false; }
                if (!f.nok_phone) { this.errors.nok_phone = true; isValid = false; }
            }
            if (step === 3) {
                if (!f.program) { this.errors.program = true; isValid = false; }
                if (!f.semester) { this.errors.semester = true; isValid = false; }
                if (!f.mode) { this.errors.mode = true; isValid = false; }
                if (!this.isValidEntryYear(Number(f.entry_year))) { this.errors.entry_year = true; isValid = false; }
                // Bursary is no longer manually entered — it is derived from the
                // sponsor (TEVETA/CDF = 100%, otherwise 0%), so there is nothing to validate here.
            }
            return isValid;
        },

        scrollToFirstError() {
            this.$nextTick(() => {
                document.querySelector('#singleRegForm .is-invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        },

        nextStep() {
            if (this.validateStep(this.step)) {
                if (this.step < 5) this.step++;
            } else {
                this.scrollToFirstError();
            }
        },
        prevStep() {
            if (this.step > 1) this.step--;
        },
        goToStep(target) {
            if (target <= this.step) { this.step = target; return; }
            // Walk forward, validating each step we pass through; stop at the first failure.
            for (let s = this.step; s < target; s++) {
                if (!this.validateStep(s)) { this.step = s; this.scrollToFirstError(); return; }
            }
            this.step = target;
        },

        async submitForm() {
            // Re-validate every rule-bearing step (1-3) before submitting.
            for (const s of [1, 2, 3]) {
                if (!this.validateStep(s)) {
                    this.step = s;
                    Swal.fire({
                        icon: 'warning', title: 'Validation Error',
                        text: `Step ${s}: please fill all required fields correctly.`,
                        toast: true, position: 'top-end', showConfirmButton: false, timer: 3000,
                    });
                    this.scrollToFirstError();
                    return;
                }
            }

            this.submitting = true;
            try {
                const formData = new FormData();
                formData.append('action', 'register');
                formData.append('csrf_token', csrfToken);

                Object.keys(this.form).forEach(key => { formData.append(key, this.form[key] || ''); });
                Object.keys(this.files).forEach(key => {
                    if (this.files[key]) formData.append(key, this.files[key], this.files[key].name);
                });

                const response = await axios.post(window.location.href, formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                });

                if (response.data.success) {
                    this.view = 'list';
                    Swal.fire({
                        icon: 'success',
                        title: 'Registration Successful!',
                        html: `Student ID: <strong>${response.data.student_id || 'N/A'}</strong><br>${response.data.message}`,
                        confirmButtonText: 'OK'
                    });
                    this.fetchRecent();
                    this.resetForm();
                } else {
                    throw new Error(response.data.message || 'Registration failed');
                }
            } catch (error) {
                console.error('Submission error:', error);
                const msg = error.response?.data?.message || error.message || 'Registration failed';
                Swal.fire('Registration Error', msg, 'error');
            } finally {
                this.submitting = false;
            }
        },

        // -------- Helpers --------
        getProgramName(code) {
            return this.programsData[code]?.name || code;
        },
        maskNrc(nrc) {
            const v = String(nrc ?? '');
            if (v.length <= 4) return v;
            return '****' + v.slice(-4);
        },
        updateSemesterOptions() {
            this.form.semester = '';
            const program = this.programsData[this.form.program];
            if (program) {
                if (program.academic_structure === 'short_course') {
                    this.form.duration_value = program.duration_value || '';
                    this.form.duration_unit = program.duration_unit || '';
                } else {
                    this.form.duration_value = '';
                    this.form.duration_unit = '';
                }
            }
        }
    };
}
</script>

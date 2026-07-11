<?php
// Determine if accessed as admin or admissions module
$is_admin = isset($is_admin_module) && $is_admin_module;

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '1800');
    session_start();
}

require_once dirname(__DIR__) . '/db/connect.php';

if ($is_admin) {
    // Admin module auth guard
    if (!isset($_SESSION['staff_id'])) {
        header('Location: ../staff_login.php');
        exit;
    }
} else {
    // Admissions module session & auth check
    require_once __DIR__ . '/includes/session_handler.php';
    if (!checkSessionTimeout(30)) {
        setFlashMessage('error', 'Your session has expired. Please log in again.');
        header('Location: /wucportal/staff_login.php');
        exit;
    }
    if (!isAdminAuthenticated()) {
        header('Location: /wucportal/staff_login.php');
        exit;
    }
}

require_once __DIR__ . '/includes/transfer_handlers.php';

// Generate CSRF token BEFORE any output
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle AJAX Requests WITH CSRF VALIDATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // CSRF VALIDATION
    if (!isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Security token mismatch. Please refresh the page.'
        ]);
        exit;
    }

    try {
        switch ($_POST['action']) {
            case 'register':
                echo json_encode(handleTransferRegistration($db, $_POST, $_FILES));
                break;
            case 'get_transfers':
                echo json_encode(handleGetTransferStudents($db));
                break;
            default:
                throw new Exception("Invalid action");
        }
    } catch (Throwable $e) {
        error_log("Transfer Registration Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A system error occurred. Please try again.']);
    }
    exit;
}

$page_title = 'Transfer Student Registration';
if ($is_admin) {
    require_once __DIR__ . '/../admin/includes/header.php';
} else {
    require __DIR__ . "/includes/nav.php";
}
?>

<style>
    /* Shared registration styles (mirror of regNewStud.php). Scoped with reg-* prefixes. */
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
<div x-data="transferApp()" x-init="init()" class="container-fluid px-4 py-4 portal-dashboard">

    <!-- Page header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <a href="<?php echo $is_admin ? 'students_by_admin.php' : 'students.php'; ?>" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="fas fa-arrow-left me-2"></i>Back to Students
                </a>
                <h1 class="dashboard-title">Transfer Student Registration</h1>
                <p class="text-muted mb-0">Register transfer students and manage their information</p>
            </div>
            <div class="col-auto" x-show="view === 'list'" x-cloak>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success" @click="openBulk()">
                        <i class="fas fa-users me-2"></i>Bulk Import
                    </button>
                    <button type="button" class="btn btn-primary" @click="openSingle()">
                        <i class="fas fa-user-plus me-2"></i>Register Transfer Student
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
                            <i class="fas fa-exchange-alt"></i>
                        </span>
                        <div>
                            <h5 class="mb-1">Register Transfer Student</h5>
                            <p class="text-muted small mb-0">Register a single transfer student with a guided 5-step wizard.</p>
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
                            <h5 class="mb-1">Bulk Import</h5>
                            <p class="text-muted small mb-0">Import many transfer students at once from a CSV file.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transfer Students Table -->
        <div class="data-table-card">
            <div class="card-header">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Transfer Students Registry</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <div class="input-group input-group-sm" style="width: 230px;">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" placeholder="Search name, ID, school..." x-model="search">
                        </div>
                        <button class="btn btn-sm btn-light" @click="fetchTransfers" :disabled="loading">
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
                                <th>Previous School</th>
                                <th>Credits</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr x-show="loading && transfers.length === 0">
                                <td colspan="7" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status"></div>
                                </td>
                            </tr>
                            <template x-for="student in filteredTransfers" :key="student.SID">
                                <tr>
                                    <td class="fw-bold" x-text="student.SID"></td>
                                    <td x-text="student.Fname + ' ' + student.Lname"></td>
                                    <!-- NRC is PII; mask all but the last 4 characters in the table. -->
                                    <td><code x-text="maskNrc(student.nrc_pass)" :title="'Full NRC visible on detail page'"></code></td>
                                    <td x-text="student.transfer_from || student.school || 'N/A'"></td>
                                    <td><span class="badge bg-secondary" x-text="student.transfer_credits || 0"></span></td>
                                    <td>
                                        <span class="badge"
                                              :class="student.program_count > 0 ? 'bg-success' : 'bg-warning'"
                                              x-text="student.program_count > 0 ? 'Admitted' : 'Pending'">
                                        </span>
                                    </td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><a class="dropdown-item" :href="'view_student.php?id=' + encodeURIComponent(student.token)"><i class="fas fa-eye text-primary me-2"></i>View</a></li>
                                                <li x-show="student.program_count == 0">
                                                    <a class="dropdown-item" :href="'admitStudent.php?sid=' + encodeURIComponent(student.token)"><i class="fas fa-check text-success me-2"></i>Admit</a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="!loading && transfers.length === 0">
                                <td colspan="7" class="text-center text-muted py-4">No transfer students found</td>
                            </tr>
                            <tr x-show="!loading && transfers.length > 0 && filteredTransfers.length === 0">
                                <td colspan="7" class="text-center text-muted py-4">No results match "<span x-text="search"></span>"</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== SINGLE WIZARD + BULK PANEL VIEWS ===================== -->
    <?php include 'includes/transfer_modal_alpine.php'; ?>

</div>

<!-- Scripts: only the libraries the layout (nav_unified.php) does NOT already load. -->
<script src="https://cdn.jsdelivr.net/npm/axios@1.4.0/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.0/dist/cdn.min.js" defer></script>

<?php
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
?>
<script>
const csrfToken = <?= json_encode($csrf_token, $jsonFlags) ?>;
const UPLOAD_MIME = {
    image: <?= wucUploadMimeListJson('image') ?>,
    document: <?= wucUploadMimeListJson('document') ?>
};
const UPLOAD_MAX_BYTES = <?= WUC_UPLOAD_MAX_BYTES ?>;

function transferApp() {
    return {
        // A single `view` switch replaces the old Bootstrap modal (mirrors regNewStud.php).
        view: 'list',           // 'list' | 'single' | 'bulk'
        step: 1,                // wizard step 1..5
        loading: false,
        submitting: false,
        search: '',
        transfers: [],

        // Single Registration Form Data
        form: {
            full_name: '', nrc_pass: '', dob: '', sex: '', mobile: '', email: '',
            h_addre: '', school: '', transfer_credits: '', academic_year: new Date().getFullYear()
        },
        files: { profile_image: null, results: null, nrc_file: null },
        errors: {},

        // Bulk CSV import state
        bulk: { processing: false, percent: 0, logs: [] },

        // -------- Derived data --------
        get filteredTransfers() {
            const q = this.search.trim().toLowerCase();
            if (!q) return this.transfers;
            return this.transfers.filter(s => {
                const hay = [
                    s.SID, s.Fname, s.Lname, (s.Fname + ' ' + s.Lname),
                    s.transfer_from, s.school, s.nrc_pass
                ].map(v => String(v ?? '').toLowerCase());
                return hay.some(v => v.includes(q));
            });
        },

        // -------- Lifecycle --------
        init() {
            const setup = () => this.fetchTransfers();
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
            this.resetBulk();
            this.view = 'bulk';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        closeForms() {
            this.view = 'list';
            this.resetForm();
            this.resetBulk();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        // -------- Resets --------
        resetForm() {
            this.step = 1;
            this.form = {
                full_name: '', nrc_pass: '', dob: '', sex: '', mobile: '', email: '',
                h_addre: '', school: '', transfer_credits: '', academic_year: new Date().getFullYear()
            };
            this.files = { profile_image: null, results: null, nrc_file: null };
            this.errors = {};
            document.querySelectorAll('#transferRegForm input[type="file"]').forEach(el => { el.value = ''; });
        },
        resetBulk() {
            this.bulk = { processing: false, percent: 0, logs: [] };
            const csv = this.$root.querySelector('[x-ref="csvFile"]');
            if (csv) csv.value = '';
        },

        // -------- Transfer list --------
        async fetchTransfers() {
            this.loading = true;
            try {
                const formData = new FormData();
                formData.append('action', 'get_transfers');
                formData.append('csrf_token', csrfToken);

                const res = await axios.post(window.location.href, formData);
                if (res.data.success) {
                    this.transfers = res.data.data || [];
                } else {
                    throw new Error(res.data.message || 'Failed to load data');
                }
            } catch (e) {
                console.error('Fetch transfers error:', e);
                Swal.fire('Error', 'Failed to load transfer students', 'error');
            } finally {
                this.loading = false;
            }
        },

        // -------- File handling (single) --------
        handleFile(event, key) {
            const file = event.target.files[0];
            if (!file) { this.files[key] = null; return; }

            const kind = key === 'profile_image' ? 'image' : 'document';
            const allowed = UPLOAD_MIME[kind] || [];
            const label = key.replace(/_/g, ' ');

            if (file.size > UPLOAD_MAX_BYTES) {
                Swal.fire('Error', `${label} exceeds the ${Math.round(UPLOAD_MAX_BYTES / 1048576)}MB limit`, 'error');
                event.target.value = ''; this.files[key] = null; this.errors[key] = true; return;
            }
            if (allowed.length && !allowed.includes(file.type)) {
                Swal.fire('Error', `Invalid file type for ${label}`, 'error');
                event.target.value = ''; this.files[key] = null; this.errors[key] = true; return;
            }
            this.files[key] = file;
            this.errors[key] = false;
        },

        // -------- Single wizard validation/navigation --------
        validateStep(step) {
            this.errors = {};
            let valid = true;
            const f = this.form;

            if (step === 1) {
                if (!f.full_name?.trim()) { this.errors.full_name = true; valid = false; }
                if (!f.nrc_pass?.trim()) { this.errors.nrc_pass = true; valid = false; }
                if (!f.dob) { this.errors.dob = true; valid = false; }
                if (!f.sex) { this.errors.sex = true; valid = false; }
            }
            if (step === 2) {
                if (!f.mobile?.trim() || !f.mobile.match(/^\d{10}$/)) { this.errors.mobile = true; valid = false; }
                if (!f.h_addre?.trim()) { this.errors.h_addre = true; valid = false; }
            }
            if (step === 3) {
                if (!f.school?.trim()) { this.errors.school = true; valid = false; }
                if (f.transfer_credits === '' || f.transfer_credits == null || parseInt(f.transfer_credits) < 0) {
                    this.errors.transfer_credits = true; valid = false;
                }
            }
            if (step === 4) {
                if (!this.files.profile_image) { this.errors.profile_image = true; valid = false; }
                if (!this.files.results) { this.errors.results = true; valid = false; }
                if (!this.files.nrc_file) { this.errors.nrc_file = true; valid = false; }
            }
            return valid;
        },

        scrollToFirstError() {
            this.$nextTick(() => {
                document.querySelector('#transferRegForm .is-invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        },

        nextStep() {
            if (this.validateStep(this.step)) {
                if (this.step < 5) this.step++;
            } else {
                Swal.fire({
                    toast: true, icon: 'warning', title: 'Please fill all required fields',
                    position: 'top-end', showConfirmButton: false, timer: 3000
                });
                this.scrollToFirstError();
            }
        },
        prevStep() {
            if (this.step > 1) this.step--;
        },
        goToStep(target) {
            if (target <= this.step) { this.step = target; return; }
            for (let s = this.step; s < target; s++) {
                if (!this.validateStep(s)) { this.step = s; this.scrollToFirstError(); return; }
            }
            this.step = target;
        },

        async submitSingle() {
            // Re-validate every rule-bearing step (1-4) before submitting.
            for (const s of [1, 2, 3, 4]) {
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

                Object.keys(this.form).forEach(k => { formData.append(k, this.form[k] || ''); });
                Object.keys(this.files).forEach(k => {
                    if (this.files[k]) formData.append(k, this.files[k], this.files[k].name);
                });

                const res = await axios.post(window.location.href, formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                });

                if (res.data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Registration Successful!',
                        text: res.data.message,
                        confirmButtonText: 'OK'
                    }).then(() => {
                        if (res.data.token) {
                            window.location.href = 'admitStudent.php?sid=' + encodeURIComponent(res.data.token);
                        } else {
                            this.view = 'list';
                            this.fetchTransfers();
                            this.resetForm();
                        }
                    });
                } else {
                    throw new Error(res.data.message || 'Registration failed');
                }
            } catch (e) {
                console.error('Submission error:', e);
                const msg = e.response?.data?.message || e.message || 'Registration failed';
                Swal.fire('Error', msg, 'error');
            } finally {
                this.submitting = false;
            }
        },

        // -------- Bulk CSV import --------
        downloadTemplate() {
            const csv = "FirstName,LastName,Sex,NRC,DOB,Mobile,Email,AcademicYear,School,TransferCredits\nJohn,Doe,M,123456/11/1,2000-01-01,0977000000,john@example.com,2025,UNZA,10";
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'transfer_template.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        async startBulkUpload() {
            const fileInput = this.$root.querySelector('[x-ref="csvFile"]');
            const file = fileInput?.files[0];

            if (!file) {
                Swal.fire('Error', 'Please select a CSV file', 'error');
                return;
            }

            this.bulk.processing = true;
            this.bulk.logs = [];
            this.bulk.percent = 0;

            try {
                const text = await file.text();
                const rows = text.split(/\r?\n/).filter(r => r.trim());

                if (rows.length < 2) {
                    Swal.fire('Error', 'Invalid CSV file format', 'error');
                    this.bulk.processing = false;
                    return;
                }

                const data = rows.slice(1).filter(row => row.trim());

                if (data.length === 0) {
                    Swal.fire('Error', 'No data rows found in CSV', 'error');
                    this.bulk.processing = false;
                    return;
                }

                let successCount = 0;
                let errorCount = 0;

                for (let i = 0; i < data.length; i++) {
                    const cols = data[i].split(',').map(c => c.trim());

                    if (cols.length < 10) {
                        this.bulk.logs.push({ type: 'error', msg: `✗ Row ${i + 2}: Invalid format` });
                        errorCount++;
                        continue;
                    }

                    const fd = new FormData();
                    fd.append('action', 'register');
                    fd.append('csrf_token', csrfToken);
                    fd.append('bulk_import', '1');

                    const get = (idx) => cols[idx] || '';

                    // Map CSV columns: FirstName,LastName,Sex,NRC,DOB,Mobile,Email,AcademicYear,School,TransferCredits
                    fd.append('full_name', get(0) + ' ' + get(1));
                    fd.append('sex', get(2));
                    fd.append('nrc_pass', get(3));
                    fd.append('dob', get(4));
                    fd.append('mobile', get(5));
                    fd.append('email', get(6));
                    fd.append('academic_year', get(7) || new Date().getFullYear());
                    fd.append('school', get(8));
                    fd.append('transfer_credits', get(9) || '0');

                    // Defaults for required fields
                    fd.append('title', 'Mr');
                    fd.append('country', 'Zambia');
                    fd.append('h_addre', 'N/A');

                    try {
                        const res = await axios.post(window.location.href, fd);
                        if (res.data.success) {
                            this.bulk.logs.push({ type: 'success', msg: `✓ ${get(0)} ${get(1)}: Success` });
                            successCount++;
                        } else {
                            this.bulk.logs.push({ type: 'error', msg: `✗ ${get(0)} ${get(1)}: ${res.data.message}` });
                            errorCount++;
                        }
                    } catch (e) {
                        const errorMsg = e.response?.data?.message || 'Network error';
                        this.bulk.logs.push({ type: 'error', msg: `✗ ${get(0)} ${get(1)}: ${errorMsg}` });
                        errorCount++;
                    }

                    this.bulk.percent = Math.round(((i + 1) / data.length) * 100);
                }

                Swal.fire({
                    icon: successCount > 0 ? 'success' : 'warning',
                    title: 'Import Complete!',
                    html: `
                        <p>Successfully imported: <strong>${successCount}</strong></p>
                        <p>Failed: <strong>${errorCount}</strong></p>
                        <p>Total: <strong>${data.length}</strong></p>
                    `,
                    confirmButtonText: 'OK'
                });

                this.view = 'list';
                this.fetchTransfers();
                this.resetBulk();
            } catch (e) {
                console.error('Bulk upload error:', e);
                Swal.fire('Error', 'Failed to process CSV file', 'error');
            } finally {
                this.bulk.processing = false;
            }
        },

        // -------- Helpers --------
        maskNrc(nrc) {
            const v = String(nrc ?? '');
            if (v.length <= 4) return v;
            return '****' + v.slice(-4);
        },
        formatNrcInput(val) {
            if (/[a-zA-Z]/.test(val)) {
                return val.slice(0, 20);
            }
            let clean = val.replace(/[^0-9]/g, '');
            if (clean.length > 9) {
                clean = clean.slice(0, 9);
            }
            let formatted = '';
            if (clean.length > 0) {
                formatted += clean.slice(0, 6);
            }
            if (clean.length > 6) {
                formatted += '/' + clean.slice(6, 8);
            }
            if (clean.length > 8) {
                formatted += '/' + clean.slice(8, 9);
            }
            return formatted;
        },
        formatMobileInput(val) {
            let clean = val.replace(/[^0-9]/g, '');
            if (clean.length > 10) {
                clean = clean.slice(0, 10);
            }
            return clean;
        }
    };
}
</script>

<?php
if ($is_admin) {
    require_once __DIR__ . '/../admin/includes/footer.php';
} else {
    require __DIR__ . '/includes/footer.php';
}
?>

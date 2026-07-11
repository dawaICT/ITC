<?php
// ===== CRITICAL FIX 1: START SESSION FIRST =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== CRITICAL FIX 2: AUTHENTICATION CHECK =====
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';

// Ensure session timeout is checked
if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }
    setFlashMessage('error', 'Session expired or unauthorized access');
    header('Location: /wucportal/staff_login.php');
    exit;
}

// Security headers (MUST come after session_start)
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data:;");

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// ===== CRITICAL FIX 3: CSRF TOKEN (AFTER SESSION START) =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Include Handlers
require_once "includes/applicant_handlers.php";

// ===== CRITICAL FIX 4: AJAX HANDLER WITH PROPER VALIDATION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean(); // Clean any previous output
    header('Content-Type: application/json');
    
    // CSRF Validation (FIXED: Use hash_equals for timing attack protection)
    if (!isset($_POST['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }
    
    // Input sanitization
    $action = filter_var($_POST['action'], FILTER_SANITIZE_STRING);
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    $response = ['success' => false, 'message' => 'Unknown action'];
    $user_name = $_SESSION['username'] ?? 'system';
    
    try {
        // Input validation
        if ($id <= 0 && in_array($action, ['get_details', 'accept', 'reject'])) {
            throw new Exception('Invalid applicant ID');
        }
        
        switch ($action) {
            case 'fetch_applicants':
                $response = handleGetApplicants($db);
                break;
                
            case 'get_stats':
                $response = handleGetStats($db);
                break;
                
            case 'get_details':
                $response = handleGetApplicantDetails($db, $id); // Uses sanitized ID
                break;
                
            case 'accept':
            case 'reject':
                // CRITICAL FIX: Standardize parameter name to match handler expectations
                $input = [
                    'applicant_id' => $id, // Handler expects 'applicant_id'
                    'action' => $action,
                    'processed_by' => $user_name,
                    'reason' => $_POST['reason'] ?? null
                ];
                // Handler signature: ($db, $input, $user_name)
                $response = handleProcessApplication($db, $input, $user_name);
                break;
                
            default:
                throw new Exception('Invalid action specified');
        }
    } catch (Exception $e) {
        error_log("Applicants AJAX Error ({$action}): " . $e->getMessage());
        $response = [
            'success' => false, 
            'message' => $WUC_ENV === 'development' ? $e->getMessage() : 'An error occurred processing your request'
        ];
    }
    
    echo json_encode($response);
    exit;
}

// Page setup
$page_title = 'Online Applications';
require_once "includes/nav.php";
?>

<!-- Bootstrap CSS (REQUIRED FOR MODALS) -->
<!-- Note: nav.php may already include Bootstrap, but explicit version ensures compatibility for this page's features -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="applicants.css" rel="stylesheet">

<style>
/* Existing styles PLUS critical fixes */
.avatar-circle { 
    display: inline-flex; 
    align-items: center; 
    justify-content: center;
    width: 40px; 
    height: 40px; 
    border-radius: 50%; 
    background-color: #4e73df; 
    color: white; 
    font-weight: 600; 
    font-size: 14px;
    flex-shrink: 0;
}
.border-left-warning { border-left: 4px solid #f6c23e !important; }
.border-left-success { border-left: 4px solid #1cc88a !important; }
.border-left-danger { border-left: 4px solid #e74a3b !important; }
.border-left-info { border-left: 4px solid #36b9cc !important; }
.cursor-pointer { cursor: pointer; }
.table-warning { background-color: #fff3cd !important; }
/* Accessibility fix */
[aria-busy="true"] { pointer-events: none; opacity: 0.8; }
</style>

<div x-data="applicantApp()" class="container-fluid px-4 py-4 portal-dashboard">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb bg-white p-3 rounded shadow-sm">
            <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Applicants</li>
            <li class="breadcrumb-item active" aria-current="page">Online Applications</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="h3 mb-1 text-gray-800">Online Applications</h1>
                <p class="text-muted mb-0">Review and process pending student applications</p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary" @click="fetchApplicants()" :disabled="loading" title="Refresh Data">
                        <i class="fas fa-sync-alt me-2" :class="{'fa-spin': loading}"></i>Refresh
                    </button>
                    <a href="processedApp.php" class="btn btn-primary">
                        <i class="fas fa-check-double me-2"></i>Processed Apps
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <template x-for="(card, index) in statsCards" :key="index">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card shadow h-100 py-2" :class="card.borderClass">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-uppercase mb-1" :class="card.textClass" x-text="card.title"></div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800" x-text="stats[card.key] || 0"></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-2x text-gray-300" :class="card.icon"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- Filter & Search -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 bg-white">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-inbox me-2"></i>Pending Applications</h6>
                </div>
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-gray-500"></i></span>
                        <input type="text" class="form-control border-start-0 bg-light" placeholder="Search by name, email, NRC..." x-model="search">
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">#</th>
                            <th width="20%">Applicant</th>
                            <th width="10%">Gender</th>
                            <th width="20%">Contact</th>
                            <th width="20%">Program</th>
                            <th width="15%">Date</th>
                            <th width="10%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Loading State -->
                        <tr x-show="loading && applicants.length === 0">
                            <td colspan="7" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status"></div>
                                <p class="mt-2 text-muted">Loading applications...</p>
                            </td>
                        </tr>

                        <!-- Empty State -->
                        <tr x-show="!loading && filteredApplicants.length === 0">
                            <td colspan="7" class="text-center py-5">
                                <span class="fa-stack fa-2x text-muted mb-2">
                                    <i class="fas fa-circle fa-stack-2x opacity-25"></i>
                                    <i class="fas fa-inbox fa-stack-1x"></i>
                                </span>
                                <h5>No applications found</h5>
                                <p class="text-muted" x-text="search ? 'Try adjusting your search terms' : 'All caught up! No pending applications.'"></p>
                            </td>
                        </tr>

                        <!-- Data Loop -->
                        <template x-for="(app, index) in filteredApplicants" :key="app.id">
                            <tr :class="{'table-warning': app.duplicate_count > 0}">
                                <td class="text-center fw-bold" x-text="index + 1"></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2"
                                             x-text="getInitials(app.Fname, app.Lname)"></div>
                                        <div>
                                            <div class="fw-bold text-dark" x-text="app.Fname + ' ' + app.Lname"></div>
                                            <template x-if="app.duplicate_count > 0">
                                                <small class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Possible duplicate</small>
                                            </template>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="fas" :class="app.sex === 'M' ? 'fa-mars text-primary' : 'fa-venus text-danger'"></i>
                                        <span x-text="app.sex" class="ms-1"></span>
                                    </span>
                                </td>
                                <td>
                                    <div class="small">
                                        <div class="mb-1 text-truncate" style="max-width: 200px;" :title="app.email">
                                            <i class="fas fa-envelope text-muted me-2"></i><span x-text="app.email"></span>
                                        </div>
                                        <div class="mb-1">
                                            <i class="fas fa-phone text-muted me-2"></i><span x-text="app.mobile"></span>
                                        </div>
                                        <div>
                                            <i class="fas fa-globe text-muted me-2"></i><span x-text="app.country"></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="small">
                                        <div class="fw-bold text-primary mb-1" x-text="app.program_name || app.program"></div>
                                        <div class="mb-1">
                                            <i class="fas fa-calendar-alt text-muted me-2"></i>
                                            <span x-text="app.intake + ' ' + app.year"></span>
                                        </div>
                                        <div><span class="badge bg-light text-dark border" x-text="app.mode"></span></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="small text-muted">
                                        <div><i class="fas fa-calendar-check me-1"></i><span x-text="formatDate(app.dte_adm)"></span></div>
                                        <div class="text-xs text-gray-500" x-text="formatTime(app.dte_adm)"></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a x-show="app.results" :href="'../online_services/uploads/' + app.results.split('/').pop()" 
                                           class="btn btn-outline-info" target="_blank" title="View Results" 
                                           :aria-label="'View Results for ' + app.Fname + ' ' + app.Lname">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <button class="btn btn-outline-success" @click="confirmAction(app, 'accept')" 
                                            title="Accept application" 
                                            :aria-label="'Accept application for ' + app.Fname + ' ' + app.Lname">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        
                                        <button class="btn btn-outline-danger" @click="confirmAction(app, 'reject')" 
                                            title="Reject application" 
                                            :aria-label="'Reject application for ' + app.Fname + ' ' + app.Lname">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        
                                        <button class="btn btn-outline-primary" @click="viewDetails(app.id)" 
                                            title="View Details" 
                                            :aria-label="'View Details for ' + app.Fname + ' ' + app.Lname">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white py-3">
                <div class="small text-muted" x-show="!loading">
                    Showing <span x-text="filteredApplicants.length"></span> of <span x-text="applicants.length"></span> applications
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Details Modal (FIXED) -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="viewModalLabel">
                        <i class="fas fa-address-card me-2"></i>
                        <span x-text="selectedApplicant ? `Application Details: ${selectedApplicant.Fname} ${selectedApplicant.Lname}` : 'Loading...'"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div x-show="loadingDetails" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Loading details...</p>
                    </div>
                    
                    <div x-show="!loadingDetails && selectedApplicant">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <h6 class="text-primary border-bottom pb-2">Personal Information</h6>
                                <table class="table table-hover align-middle">
                                    <tr><td class="text-muted">Full Name:</td><td class="fw-bold text-end" x-text="selectedApplicant?.Fname + ' ' + selectedApplicant?.Lname"></td></tr>
                                    <tr><td class="text-muted">Sex:</td><td class="text-end" x-text="selectedApplicant?.sex"></td></tr>
                                    <tr><td class="text-muted">NRC/Passport:</td><td class="text-end text-break" x-text="selectedApplicant?.nrc_pass"></td></tr>
                                    <tr><td class="text-muted">DOB:</td><td class="text-end" x-text="selectedApplicant?.dob || 'N/A'"></td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary border-bottom pb-2">Contact & Location</h6>
                                <table class="table table-hover align-middle">
                                    <tr><td class="text-muted">Mobile:</td><td class="text-end" x-text="selectedApplicant?.mobile"></td></tr>
                                    <tr><td class="text-muted">Email:</td><td class="text-end text-break" x-text="selectedApplicant?.email"></td></tr>
                                    <tr><td class="text-muted">Country:</td><td class="text-end" x-text="selectedApplicant?.country"></td></tr>
                                </table>
                            </div>
                            <div class="col-12 mt-3">
                                <h6 class="text-primary border-bottom pb-2">Program Details</h6>
                                <div class="card bg-light border-0">
                                    <div class="card-body">
                                        <div class="row text-center">
                                            <div class="col-md-6 mb-2">
                                                <small class="text-muted d-block">Program</small>
                                                <span class="fw-bold text-primary" x-text="selectedApplicant?.program_name || selectedApplicant?.program"></span>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <small class="text-muted d-block">Intake</small>
                                                <span class="fw-medium" x-text="selectedApplicant?.intake + ' ' + selectedApplicant?.year"></span>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <small class="text-muted d-block">Mode</small>
                                                <span class="badge bg-white text-dark border" x-text="selectedApplicant?.mode"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" @click="confirmAction(selectedApplicant, 'accept', true)">
                        <i class="fas fa-check me-2"></i>Accept
                    </button>
                    <button type="button" class="btn btn-danger" @click="confirmAction(selectedApplicant, 'reject', true)">
                        <i class="fas fa-times me-2"></i>Reject
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once "includes/footer.php"; ?>

<!-- FIXED SCRIPTS (NO TRAILING SPACES + CORRECT ORDER) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios@1.4.0/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.0/dist/cdn.min.js" defer></script>

<script>
function applicantApp() {
    return {
        applicants: [],
        stats: { pending: 0, accepted: 0, rejected: 0, duplicates: 0 },
        search: '',
        loading: false,
        loadingDetails: false,
        selectedApplicant: null,
        csrfToken: '<?= $csrf_token ?>',
        viewModal: null,
        modalOpen: false, // State variable for modal visibility
        
        statsCards: [
            { key: 'pending', title: 'Pending Review', borderClass: 'border-left-warning', textClass: 'text-warning', icon: 'fa-hourglass-half' },
            { key: 'accepted', title: 'Accepted', borderClass: 'border-left-success', textClass: 'text-success', icon: 'fa-check-circle' },
            { key: 'rejected', title: 'Rejected', borderClass: 'border-left-danger', textClass: 'text-danger', icon: 'fa-times-circle' },
            { key: 'duplicates', title: 'Potential Duplicates', borderClass: 'border-left-info', textClass: 'text-info', icon: 'fa-clone' }
        ],

        init() {
            // CRITICAL: Verify dependencies loaded
            if (typeof bootstrap === 'undefined' || 
                typeof axios === 'undefined' || 
                typeof Swal === 'undefined') {
                const errorMsg = 'Required libraries failed to load. Please refresh the page.';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Critical Error',
                        html: errorMsg + '<br><small class="text-muted">Check browser console for details</small>',
                        confirmButtonText: 'Refresh Page',
                        allowOutsideClick: false
                    }).then(() => location.reload());
                } else {
                    alert(errorMsg);
                    location.reload();
                }
                return;
            }
            
            // Initialize modal AFTER Bootstrap is confirmed loaded
            const modalEl = document.getElementById('viewModal');
            if (modalEl) {
                this.viewModal = new bootstrap.Modal(modalEl, { 
                    focus: true,
                    backdrop: 'static'
                });
                
                // Handle modal open/close events safely using states
                modalEl.addEventListener('show.bs.modal', () => {
                    this.modalOpen = true;
                });
                modalEl.addEventListener('hidden.bs.modal', () => {
                    this.selectedApplicant = null;
                    this.loadingDetails = false;
                    this.modalOpen = false;
                });
            }
            
            // Load data with error handling
            this.fetchApplicants().catch(err => this.handleLoadError(err, 'applicants'));
            this.fetchStats().catch(err => this.handleLoadError(err, 'stats'));
        },
        
        handleLoadError(error, context) {
            console.error(`Failed to load ${context}:`, error);
            const msg = context === 'applicants' 
                ? 'Failed to load applicant data. Please refresh the page.' 
                : 'Failed to load statistics';
            
            // Only show toast if page is already visible
            if (!this.loading && context === 'applicants') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'error',
                    title: msg,
                    showConfirmButton: false,
                    timer: 5000
                });
            }
        },

        async fetchApplicants() {
            this.loading = true;
            try {
                const response = await axios.post(window.location.href, new URLSearchParams({
                    action: 'fetch_applicants',
                    csrf_token: this.csrfToken
                }), {
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                
                if (!response.data.success) throw new Error(response.data.message || 'Unknown error');
                
                const rawApplicants = response.data.data || [];
                // CRITICAL FIX: Ensure array type + enhance duplicate detection (using rawApplicants to avoid empty this.applicants read)
                this.applicants = Array.isArray(rawApplicants) 
                    ? rawApplicants.map(app => {
                        const serverDupes = parseInt(app.duplicate_count) || 0;
                        const localDupes = this.countPotentialDuplicates(app, rawApplicants);
                        return {
                            ...app,
                            duplicate_count: Math.max(serverDupes, localDupes)
                        };
                    })
                    : [];
                    
                // Update duplicate stats (threshold is 1 to match row highlighting and server EXISTS matches)
                this.stats.duplicates = this.applicants.filter(a => a.duplicate_count > 0).length;
                
            } catch (error) {
                console.error('Fetch applicants error:', error);
                this.applicants = [];
                throw error; // Re-throw for init handler
            } finally {
                this.loading = false;
            }
        },
        
        // CRITICAL FIX: Accurate duplicate detection logic
        countPotentialDuplicates(currentApp, list) {
            if (!currentApp?.nrc_pass && !currentApp?.email) return 0;
            const targetList = list || this.applicants;
            
            return targetList.filter(app => 
                app.id !== currentApp.id && (
                    (app.nrc_pass && currentApp.nrc_pass && 
                     app.nrc_pass.toLowerCase() === currentApp.nrc_pass.toLowerCase()) ||
                    (app.email && currentApp.email && 
                     app.email.toLowerCase() === currentApp.email.toLowerCase())
                )
            ).length;
        },

        async fetchStats() {
            try {
                const response = await axios.post(window.location.href, new URLSearchParams({
                    action: 'get_stats',
                    csrf_token: this.csrfToken
                }), {
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                
                if (response.data.success && typeof response.data.data === 'object') {
                    this.stats = { 
                        ...this.stats, 
                        ...response.data.data,
                        // Avoid race-condition overwrite of client-computed duplicates
                        duplicates: this.applicants.length > 0 ? this.applicants.filter(a => a.duplicate_count > 0).length : response.data.data.duplicates
                    };
                }
            } catch (error) {
                console.error('Fetch stats error:', error);
                throw error;
            }
        },

        get filteredApplicants() {
            if (!this.search.trim()) return this.applicants;
            
            const term = this.search.toLowerCase().trim();
            return this.applicants.filter(app => 
                `${app.Fname} ${app.Lname}`.toLowerCase().includes(term) ||
                (app.email?.toLowerCase().includes(term) ?? false) ||
                (app.nrc_pass?.toLowerCase().includes(term) ?? false) ||
                (app.program?.toLowerCase().includes(term) ?? false) ||
                (app.mobile?.includes(term) ?? false)
            );
        },

        async viewDetails(id) {
            if (!id || id <= 0) {
                Swal.fire('Error', 'Invalid applicant ID', 'error');
                return;
            }
            
            this.selectedApplicant = null;
            this.loadingDetails = true;
            
            try {
                // Show modal FIRST (better UX)
                if (this.viewModal) this.viewModal.show();
                
                const response = await axios.post(window.location.href, new URLSearchParams({
                    action: 'get_details',
                    id: id,
                    csrf_token: this.csrfToken
                }), {
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                
                if (!response.data.success) throw new Error(response.data.message || 'Failed to load details');
                
                this.selectedApplicant = response.data.data;
            } catch (error) {
                console.error('View details error:', error);
                const msg = error.response?.data?.message || error.message || 'Failed to load applicant details';
                
                // Show error IN modal if open
                if (this.modalOpen) {
                    Swal.fire({
                        title: 'Error Loading Details',
                        html: `<small class="text-muted">${msg}</small>`,
                        icon: 'error',
                        confirmButtonText: 'Close & Refresh',
                        allowOutsideClick: false
                    }).then(() => {
                        if (this.viewModal) this.viewModal.hide();
                        this.fetchApplicants(); // Refresh list
                    });
                } else {
                    Swal.fire('Error', msg, 'error');
                }
            } finally {
                this.loadingDetails = false;
            }
        },

        confirmAction(applicant, action, fromModal = false) {
            if (!applicant?.id) {
                Swal.fire('Error', 'Invalid applicant selection', 'error');
                return;
            }
            
            // Close modal if coming from modal view
            if (fromModal && this.viewModal) this.viewModal.hide();
            
            const actionText = action === 'accept' ? 'Accept' : 'Reject';
            const icon = action === 'accept' ? 'success' : 'warning';
            const confirmColor = action === 'accept' ? '#1cc88a' : '#e74a3b';
            
            Swal.fire({
                title: `${actionText} Application?`,
                html: `
                    <div class="text-start">
                        <p>Are you sure you want to <strong>${actionText.toLowerCase()}</strong>:</p>
                        <div class="alert alert-info p-2 mb-3">
                            <strong>${applicant.Fname} ${applicant.Lname}</strong><br>
                            <small class="text-muted">${applicant.program} • ${applicant.email}</small>
                        </div>
                        ${action === 'reject' ? `
                        <div class="form-floating">
                            <textarea class="form-control" id="rejectionReason" placeholder="Reason for rejection" rows="3" required></textarea>
                            <label for="rejectionReason">Reason for rejection (required)</label>
                            <div class="invalid-feedback">Please provide a reason</div>
                        </div>
                        ` : ''}
                    </div>
                `,
                icon: icon,
                showCancelButton: true,
                confirmButtonColor: confirmColor,
                confirmButtonText: `Yes, ${actionText}`,
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    if (action === 'reject') {
                        const reason = document.getElementById('rejectionReason').value.trim();
                        if (!reason) {
                            Swal.showValidationMessage('Reason is required for rejection');
                            return false;
                        }
                        return { reason };
                    }
                    return true;
                },
                didOpen: () => {
                    if (action === 'reject') {
                        setTimeout(() => {
                            document.getElementById('rejectionReason')?.focus();
                        }, 100);
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    this.processApplication(
                        applicant.id, 
                        action, 
                        action === 'reject' ? result.value.reason : null
                    );
                } else if (fromModal && result.dismiss !== Swal.DismissReason.backdrop) {
                    // Reopen modal ONLY if user didn't click outside
                    setTimeout(() => {
                        if (this.viewModal) this.viewModal.show();
                    }, 300);
                }
            });
        },

        async processApplication(id, action, reason = null) {
            if (!id || id <= 0) {
                Swal.fire('Error', 'Invalid applicant ID', 'error');
                return;
            }
            
            try {
                const params = new URLSearchParams({
                    action: action,
                    id: id,
                    csrf_token: this.csrfToken
                });
                
                // Add reason for rejections
                if (reason) params.append('reason', reason);
                
                const response = await axios.post(window.location.href, params, {
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                
                if (!response.data.success) throw new Error(response.data.message || 'Operation failed');
                
                // Success feedback
                const actionText = action === 'accept' ? 'accepted' : 'rejected';
                Swal.fire({
                    icon: 'success',
                    title: `Application ${actionText}!`,
                    text: response.data.message || `Applicant has been ${actionText}`,
                    timer: 2500,
                    showConfirmButton: false
                });
                
                // Refresh data
                await Promise.all([
                    this.fetchApplicants(),
                    this.fetchStats()
                ]);
                
                // Close modal if open
                if (this.modalOpen && this.viewModal) this.viewModal.hide();
                
            } catch (error) {
                console.error('Process application error:', error);
                const msg = error.response?.data?.message || 
                           (error.message.includes('403') ? 'Security token expired. Please refresh the page.' : 
                           'Failed to process application');
                
                Swal.fire({
                    icon: 'error',
                    title: 'Processing Failed',
                    html: `<small class="text-muted">${msg}</small>`,
                    confirmButtonText: error.message.includes('403') ? 'Refresh Page' : 'OK'
                }).then((result) => {
                    if (result.isConfirmed && error.message.includes('403')) {
                        location.reload();
                    }
                });
            }
        },

        // Helper methods
        getInitials(fname, lname) {
            return ((fname?.charAt(0) || '') + (lname?.charAt(0) || '')).toUpperCase() || '??';
        },
        
        formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            try {
                return new Date(dateStr).toLocaleDateString('en-GB', {
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric'
                });
            } catch {
                return dateStr;
            }
        },
        
        formatTime(dateStr) {
            if (!dateStr) return '';
            try {
                return new Date(dateStr).toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch {
                return '';
            }
        }
    };
}
</script>

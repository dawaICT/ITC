<?php
/**
 * Library Module — Landing Page
 *
 * Routes guests to staff_login, students to their portal's library page, and
 * staff with any library_* permission to the library dashboard. The dashboard
 * is rendered through the shared includes/dashboard_template.php so it matches
 * every other staff module (Admin, Registrar, HOD, Accounts, Admissions, …):
 * the same ITC-navy header, profile card, stat cards, quick-action cards and
 * announcements — instead of a one-off purple layout.
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/permissions.php';

wuc_apply_security_headers(true);
if (session_status() === PHP_SESSION_NONE) {
    wuc_configure_session_cookie();
    session_start();
}

// Students belong in the student portal's library page — their session lives there.
if (isset($_SESSION['Sid'])) {
    header('Location: /wucportal/students/library.php');
    exit;
}

// Guests get bounced to the staff login.
if (!isset($_SESSION['staff_id']) && !isset($_SESSION['user_id'])) {
    $_SESSION['loginSuperadmin'] = 'Please login to access the Library.';
    header('Location: /wucportal/staff_login.php');
    exit;
}

$staffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'];

// Portal boundary (Phase 2b): require Library portal access and mark the active
// portal, so library permission checks become portal-aware. Staff without
// library portal access are redirected with a clear message instead of landing
// on an empty dashboard. Students were already routed to students/library.php.
require_once __DIR__ . '/../includes/portal_access.php';
wuc_require_portal_access($db, 'library');

// Per-area permissions drive both the stat-card links and the quick actions.
$canCat  = canCatalog($staffId)                        || canManageLibrary($staffId);
$canCirc = canCirculate($staffId)                      || canManageLibrary($staffId);
$canFine = hasPermission($staffId, 'library_fines')    || canManageLibrary($staffId);
$canDig  = hasPermission($staffId, 'library_digital')  || canManageLibrary($staffId);

$hasLibraryAccess = $canCat || $canCirc || $canFine || $canDig;

$page_title = 'Library';
$adminBase  = '/wucportal/admin';

// ── Library KPIs (only queried for staff who actually hold a library permission) ──
$catalogCount = $loansActive = $loansOverdue = $digitalCount = $unsettledFines = 0;
if ($hasLibraryAccess && isset($db) && $db instanceof mysqli) {
    $libCount = function (string $sql) use ($db): int {
        if ($res = $db->query($sql)) {
            $row = $res->fetch_row();
            $res->free();
            return (int) ($row[0] ?? 0);
        }
        return 0;
    };
    $catalogCount   = $libCount("SELECT COUNT(*) FROM library_items");
    $loansActive    = $libCount("SELECT COUNT(*) FROM library_loans WHERE returned_at IS NULL");
    $loansOverdue   = $libCount("SELECT COUNT(*) FROM library_loans WHERE returned_at IS NULL AND due_date < NOW()");
    $digitalCount   = $libCount("SELECT COUNT(*) FROM library_digital_resources");
    $unsettledFines = $libCount("SELECT COUNT(*) FROM library_fines WHERE settled = 0");
}

// Sidebar + portal chrome (also handles security headers, session, CSRF, etc.)
require __DIR__ . '/includes/nav.php';

if (!$hasLibraryAccess):
?>
  <div class="container-fluid px-4">
    <div class="card shadow-sm" style="max-width:540px;margin:4rem auto;">
      <div class="card-body text-center p-4">
        <div class="mb-3"><i class="fas fa-lock fa-3x text-danger"></i></div>
        <h4 class="mb-2">Library access not granted</h4>
        <p class="text-muted mb-3">
          Your account does not currently have any library permissions
          (<code>library_manage</code>, <code>library_catalog</code>,
          <code>library_circulation</code>, <code>library_fines</code>,
          or <code>library_digital</code>). Ask a systems administrator to
          grant the appropriate permission to your role.
        </p>
        <a href="/wucportal/portal_selection.php" class="btn btn-primary">
          <i class="fas fa-arrow-left me-1"></i>Back to Portal
        </a>
      </div>
    </div>
  </div>
<?php
else:
    // ── Unified dashboard configuration (mirrors every other staff module) ──
    $dashboard_title    = 'Library & Resource Management';
    $dashboard_subtitle = 'Catalog, circulation, fines and digital resources — all in one place.';
    $user_role          = 'Librarian';
    $user_role_class    = 'bg-primary';
    $stat_icon_class    = 'bg-primary';
    $header_section_class = 'admin-section';
    $profile_link       = '/wucportal/admin/profile.php';
    $edit_profile_link  = '/wucportal/admin/profile.php';
    // The library landing has no profile_upload.php of its own; profile photos
    // are managed from admin/profile.php, so hide the upload button here.
    $show_profile_upload = false;

    $stat_cards = [
        ['icon' => 'fas fa-book',             'value' => number_format($catalogCount), 'label' => 'Catalog Titles',    'bg_class' => 'bg-primary', 'link' => $adminBase . '/library_catalog.php?from=library',     'link_text' => 'Browse'],
        ['icon' => 'fas fa-right-left',       'value' => number_format($loansActive),  'label' => 'Items on Loan',     'bg_class' => 'bg-info',    'link' => $adminBase . '/library_circulation.php?from=library', 'link_text' => 'Open'],
        ['icon' => 'fas fa-clock',            'value' => number_format($loansOverdue),  'label' => 'Overdue Loans',     'bg_class' => 'bg-danger',  'link' => $adminBase . '/library_circulation.php?from=library', 'link_text' => 'Review'],
        ['icon' => 'fas fa-cloud-arrow-down', 'value' => number_format($digitalCount),  'label' => 'Digital Resources', 'bg_class' => 'bg-success', 'link' => $adminBase . '/library_digital.php?from=library',     'link_text' => 'Manage'],
    ];

    // Quick actions — only those the user holds a permission for, matching the sidebar.
    $quick_modules = [];
    if ($canCat) {
        $quick_modules[] = ['icon' => 'fas fa-journal-whills',  'title' => 'Catalog',     'description' => 'Add, edit and browse catalog records.',  'link' => $adminBase . '/library_catalog.php?from=library',     'bg_class' => 'bg-primary'];
    }
    if ($canCirc) {
        $quick_modules[] = ['icon' => 'fas fa-right-left',      'title' => 'Circulation', 'description' => 'Checkouts, returns and reservations.',    'link' => $adminBase . '/library_circulation.php?from=library', 'bg_class' => 'bg-info'];
    }
    if ($canFine) {
        $quick_modules[] = ['icon' => 'fas fa-coins',           'title' => 'Fines',       'description' => 'Assess and settle outstanding fines.',    'link' => $adminBase . '/library_fines.php?from=library',       'bg_class' => 'bg-warning'];
    }
    if ($canDig) {
        $quick_modules[] = ['icon' => 'fas fa-cloud-arrow-down', 'title' => 'Digital',    'description' => 'Manage e-books and digital resources.',   'link' => $adminBase . '/library_digital.php?from=library',     'bg_class' => 'bg-success'];
    }

    // Announcements / alerts (same shape the other dashboards use).
    $show_announcements = true;
    $announcements = [];
    if ($loansOverdue > 0) {
        $announcements[] = ['icon' => 'fas fa-triangle-exclamation', 'color' => 'danger', 'title' => 'Overdue Loans', 'content' => "{$loansOverdue} item(s) are overdue and awaiting return.", 'badge' => 'Circulation', 'badge_color' => 'danger', 'date' => 'Action required'];
    }
    if ($unsettledFines > 0) {
        $announcements[] = ['icon' => 'fas fa-coins', 'color' => 'warning', 'title' => 'Unsettled Fines', 'content' => "{$unsettledFines} fine(s) remain unsettled.", 'badge' => 'Fines', 'badge_color' => 'warning', 'date' => 'Pending'];
    }
    if (empty($announcements)) {
        $announcements[] = ['icon' => 'fas fa-check-double', 'color' => 'success', 'title' => 'Library Operations Normal', 'content' => 'No overdue items or unsettled fines. Catalog and circulation are up to date.', 'badge' => 'Normal', 'badge_color' => 'success', 'date' => 'Now'];
    }

    require_once dirname(__DIR__) . '/includes/dashboard_template.php';
endif;

require dirname(__DIR__) . '/includes/nav_unified_footer.php';

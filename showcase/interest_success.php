<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/bootstrap.php';

wuc_secure_session_start();
if (function_exists('wuc_security_headers')) {
    wuc_security_headers();
}

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$itemLink = ($code !== '' && preg_match('/^ENT-[A-Z0-9]{8}$/', $code))
    ? '/wucportal/showcase/item.php?code=' . rawurlencode($code)
    : '/wucportal/showcase/index.php';

$pageTitle = 'Interest received';
$pageDescription = 'Your expression of interest has been recorded.';
require __DIR__ . '/includes/public_header.php';
?>
<main class="eh-main">
<div class="container" style="max-width:640px">
    <div class="eh-filter-panel text-center py-5">
        <div class="mb-3">
            <i class="fas fa-check-circle fa-3x text-success"></i>
        </div>
        <h1 class="h4 mb-2">Thank you</h1>
        <p class="text-muted mb-3">
            Your expression of interest has been received. A member of the Skills-to-Trade team may follow up
            using the contact details you provided.
        </p>
        <p class="small text-muted mb-4">
            This confirmation does not constitute an offer of funding, investment, employment or partnership.
            Financial figures on listings are estimates only.
        </p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            <a class="btn btn-eh-primary" href="<?php echo eh_h($itemLink); ?>">Back to opportunity</a>
            <a class="btn btn-outline-secondary" href="/wucportal/showcase/index.php">Browse catalogue</a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>

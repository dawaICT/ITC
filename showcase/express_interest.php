<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/bootstrap.php';
require_once __DIR__ . '/includes/helpers.php';

wuc_secure_session_start();
if (function_exists('wuc_security_headers')) {
    wuc_security_headers();
}

$code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
$item = null;
$error = '';
$old = [
    'visitor_name' => '',
    'organization' => '',
    'email' => '',
    'phone' => '',
    'interest_type' => '',
    'investment_range' => '',
    'quantity_requested' => '',
    'message' => '',
    'preferred_contact_method' => 'email',
];

if ($code === '' || !preg_match('/^ENT-[A-Z0-9]{8}$/', $code)) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/includes/public_header.php';
    echo '<main class="eh-main"><div class="container"><div class="alert alert-warning">Opportunity not found.</div></div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
}

$item = eh_get_item_by_code($db, $code, true);
if (!$item) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/includes/public_header.php';
    echo '<main class="eh-main"><div class="container"><div class="alert alert-warning">This opportunity is not available.</div></div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
}

$interestTypes = eh_interest_types();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!wuc_validate_csrf($token)) {
        $error = 'Security check failed. Please refresh the page and try again.';
    } else {
        foreach ($old as $k => $_) {
            if (isset($_POST[$k])) {
                $old[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : (string)$_POST[$k];
            }
        }
        $input = [
            'visitor_name' => $old['visitor_name'],
            'organization' => $old['organization'],
            'email' => $old['email'],
            'phone' => $old['phone'],
            'interest_type' => $old['interest_type'],
            'investment_range' => $old['investment_range'],
            'quantity_requested' => $old['quantity_requested'],
            'message' => $old['message'],
            'preferred_contact_method' => $old['preferred_contact_method'],
            'consent_accepted' => !empty($_POST['consent_accepted']),
            'website' => (string)($_POST['website'] ?? ''), // honeypot
        ];
        $ip = function_exists('wuc_get_client_ip') ? wuc_get_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $result = eh_submit_interest($db, (int)$item['id'], $input, $ip);
        if (!empty($result['ok'])) {
            header('Location: /wucportal/showcase/interest_success.php?code=' . rawurlencode($code));
            exit;
        }
        $error = (string)($result['message'] ?? 'Unable to submit interest.');
    }
}

$csrf = wuc_csrf_token();
$pageTitle = 'Express interest';
$pageDescription = 'Submit an expression of interest for a published Skills-to-Trade opportunity.';
require __DIR__ . '/includes/public_header.php';
?>
<main class="eh-main">
<div class="container" style="max-width:720px">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/wucportal/showcase/index.php">Catalogue</a></li>
            <li class="breadcrumb-item">
                <a href="/wucportal/showcase/item.php?code=<?php echo rawurlencode($code); ?>"><?php echo eh_h($code); ?></a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">Express interest</li>
        </ol>
    </nav>

    <div class="eh-filter-panel">
        <h1 class="h4 mb-1">Express interest</h1>
        <p class="text-muted mb-3">
            Regarding <strong><?php echo eh_h((string)$item['title']); ?></strong>
            · <?php echo eh_h((string)($item['business_name'] ?? '')); ?>
        </p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?php echo eh_h($error); ?></div>
        <?php endif; ?>

        <form method="post" action="/wucportal/showcase/express_interest.php?code=<?php echo rawurlencode($code); ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
            <input type="hidden" name="code" value="<?php echo eh_h($code); ?>">

            <!-- Honeypot: leave blank -->
            <div class="visually-hidden-honeypot" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="mb-3">
                <label class="form-label" for="visitor_name">Full name <span class="text-danger">*</span></label>
                <input class="form-control" type="text" id="visitor_name" name="visitor_name" required maxlength="120"
                       value="<?php echo eh_h($old['visitor_name']); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="organization">Organisation</label>
                <input class="form-control" type="text" id="organization" name="organization" maxlength="160"
                       value="<?php echo eh_h($old['organization']); ?>">
            </div>
            <div class="row g-3">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="email">Email <span class="text-danger">*</span></label>
                    <input class="form-control" type="email" id="email" name="email" required maxlength="160"
                           value="<?php echo eh_h($old['email']); ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="phone">Phone <span class="text-danger">*</span></label>
                    <input class="form-control" type="tel" id="phone" name="phone" required maxlength="40"
                           value="<?php echo eh_h($old['phone']); ?>" placeholder="+260…">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="interest_type">Interest type <span class="text-danger">*</span></label>
                <select class="form-select" id="interest_type" name="interest_type" required>
                    <option value="">Select…</option>
                    <?php foreach ($interestTypes as $key => $label): ?>
                        <option value="<?php echo eh_h($key); ?>"
                            <?php echo $old['interest_type'] === $key ? 'selected' : ''; ?>>
                            <?php echo eh_h($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row g-3">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="investment_range">Investment range (optional)</label>
                    <input class="form-control" type="text" id="investment_range" name="investment_range" maxlength="80"
                           value="<?php echo eh_h($old['investment_range']); ?>" placeholder="e.g. ZMW 50,000 – 100,000">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="quantity_requested">Quantity (optional)</label>
                    <input class="form-control" type="number" id="quantity_requested" name="quantity_requested" min="0"
                           value="<?php echo eh_h($old['quantity_requested']); ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="preferred_contact_method">Preferred contact</label>
                <select class="form-select" id="preferred_contact_method" name="preferred_contact_method">
                    <?php foreach (['email' => 'Email', 'phone' => 'Phone', 'either' => 'Either'] as $k => $lab): ?>
                        <option value="<?php echo eh_h($k); ?>"
                            <?php echo $old['preferred_contact_method'] === $k ? 'selected' : ''; ?>>
                            <?php echo eh_h($lab); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="message">Message <span class="text-danger">*</span></label>
                <textarea class="form-control" id="message" name="message" rows="5" required maxlength="2000"><?php echo eh_h($old['message']); ?></textarea>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" value="1" id="consent_accepted" name="consent_accepted" required
                    <?php echo !empty($_POST['consent_accepted']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="consent_accepted">
                    I consent to ITC contacting me about this expression of interest and understand this is not a funding application or guarantee.
                </label>
            </div>
            <div class="eh-disclaimer mb-3">
                <?php echo eh_h(eh_disclaimer_finance()); ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-eh-primary" type="submit">
                    <i class="fas fa-paper-plane me-1"></i>Submit interest
                </button>
                <a class="btn btn-outline-secondary"
                   href="/wucportal/showcase/item.php?code=<?php echo rawurlencode($code); ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>

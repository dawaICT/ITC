<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!ep_public_directory_enabled($db)) {
    http_response_code(503);
    ep_public_page_begin('Unavailable');
    echo '<main class="container py-5"><div class="alert alert-warning">The public directory is temporarily unavailable.</div></main>';
    ep_public_page_end();
    exit;
}

$code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
$error = '';
$success = !empty($_GET['submitted']);
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

if (!ep_public_valid_code($code)) {
    http_response_code(404);
    ep_public_page_begin('Not found');
    echo '<main class="container py-5"><div class="alert alert-warning">Opportunity not found.</div></main>';
    ep_public_page_end();
    exit;
}

$opp = ep_get_opportunity_by_code($db, $code, true);
if (!$opp) {
    http_response_code(404);
    ep_public_page_begin('Not found');
    echo '<main class="container py-5"><div class="alert alert-warning">This opportunity is not published.</div></main>';
    ep_public_page_end();
    exit;
}

$interestTypes = ep_interest_types();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!function_exists('wuc_validate_csrf') || !wuc_validate_csrf($token)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        header('Location: /wucportal/opportunities/view.php?code=' . rawurlencode($code));
        exit;
    } else {
        foreach ($old as $k => $_) {
            if (isset($_POST[$k])) {
                $old[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : (string)$_POST[$k];
            }
        }
        $input = array_merge($old, [
            'consent_accepted' => !empty($_POST['consent_accepted']),
        ]);
        $ip = function_exists('wuc_get_client_ip') ? wuc_get_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $result = ep_submit_interest($db, (int)$opp['id'], $input, $ip);
        if (!empty($result['ok'])) {
            header('Location: /wucportal/opportunities/express_interest.php?code=' . rawurlencode($code) . '&submitted=1');
            exit;
        }
        $error = (string)($result['message'] ?? 'Unable to submit interest.');
    }
}

ep_public_page_begin('Express interest');
?>
<?php ep_public_disclaimer_banner(); ?>
<main class="container py-4" style="max-width:720px">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/wucportal/opportunities/index.php">Directory</a></li>
            <li class="breadcrumb-item"><a href="/wucportal/opportunities/view.php?code=<?= rawurlencode($code) ?>"><?= ep_h($code) ?></a></li>
            <li class="breadcrumb-item active">Express interest</li>
        </ol>
    </nav>

    <div class="ep-card">
        <h1 class="h4 mb-1">Express interest</h1>
        <p class="ep-muted mb-3">
            Regarding <strong><?= ep_h((string)$opp['title']) ?></strong>
            · <?= ep_h((string)($opp['business_name'] ?? '')) ?>
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success">Thank you. Your expression of interest has been recorded. The enterprise member will be notified through the portal.</div>
            <a class="btn btn-outline-primary" href="/wucportal/opportunities/view.php?code=<?= rawurlencode($code) ?>">Back to listing</a>
        <?php else: ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= ep_h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/wucportal/opportunities/express_interest.php?code=<?= rawurlencode($code) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= ep_h($epPublicCsrf) ?>">
                <input type="hidden" name="code" value="<?= ep_h($code) ?>">
                <div class="visually-hidden" aria-hidden="true">
                    <label for="website">Website</label>
                    <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="visitor_name">Full name <span class="text-danger">*</span></label>
                    <input class="form-control" type="text" id="visitor_name" name="visitor_name" required maxlength="120" value="<?= ep_h($old['visitor_name']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="organization">Organisation</label>
                    <input class="form-control" type="text" id="organization" name="organization" maxlength="160" value="<?= ep_h($old['organization']) ?>">
                </div>
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="email">Email <span class="text-danger">*</span></label>
                        <input class="form-control" type="email" id="email" name="email" required maxlength="160" value="<?= ep_h($old['email']) ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="phone">Phone <span class="text-danger">*</span></label>
                        <input class="form-control" type="tel" id="phone" name="phone" required maxlength="40" value="<?= ep_h($old['phone']) ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="interest_type">Interest type <span class="text-danger">*</span></label>
                    <select class="form-select" id="interest_type" name="interest_type" required>
                        <option value="">Select…</option>
                        <?php foreach ($interestTypes as $key => $label): ?>
                            <option value="<?= ep_h($key) ?>"<?= $old['interest_type'] === $key ? ' selected' : '' ?>><?= ep_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="investment_range">Investment range (optional)</label>
                        <input class="form-control" type="text" id="investment_range" name="investment_range" maxlength="80" value="<?= ep_h($old['investment_range']) ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="quantity_requested">Quantity (optional)</label>
                        <input class="form-control" type="number" id="quantity_requested" name="quantity_requested" min="0" value="<?= ep_h($old['quantity_requested']) ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="preferred_contact_method">Preferred contact</label>
                    <select class="form-select" id="preferred_contact_method" name="preferred_contact_method">
                        <?php foreach (['email' => 'Email', 'phone' => 'Phone', 'either' => 'Either', 'portal_mediated' => 'Portal-mediated'] as $k => $lab): ?>
                            <option value="<?= ep_h($k) ?>"<?= $old['preferred_contact_method'] === $k ? ' selected' : '' ?>><?= ep_h($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="message">Message <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="message" name="message" rows="5" required maxlength="4000"><?= ep_h($old['message']) ?></textarea>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" value="1" id="consent_accepted" name="consent_accepted" required>
                    <label class="form-check-label" for="consent_accepted">
                        I consent to ITC processing this enquiry and understand verification does not guarantee outcomes.
                    </label>
                </div>
                <p class="small ep-muted"><?= ep_h(ep_disclaimer_finance()) ?></p>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane me-1"></i>Submit interest</button>
                    <a class="btn btn-outline-secondary" href="/wucportal/opportunities/view.php?code=<?= rawurlencode($code) ?>">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>
<?php ep_public_page_end(); ?>

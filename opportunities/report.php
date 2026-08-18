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
$complaintTypes = [
    'misleading' => 'Misleading information',
    'fraud' => 'Suspected fraud',
    'copyright' => 'Copyright concern',
    'unavailable' => 'Product or service unavailable',
    'incorrect_contact' => 'Incorrect contact details',
    'inappropriate' => 'Inappropriate content',
    'safety' => 'Safety concern',
];
$old = [
    'complaint_type' => '',
    'description' => '',
    'reporter_name' => '',
    'reporter_email' => '',
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
        $result = ep_submit_complaint($db, [
            'enterprise_opportunity_id' => (int)$opp['id'],
            'complaint_type' => $old['complaint_type'],
            'description' => $old['description'],
            'reporter_name' => $old['reporter_name'],
            'reporter_email' => $old['reporter_email'],
        ]);
        if (!empty($result['ok'])) {
            header('Location: /wucportal/opportunities/report.php?code=' . rawurlencode($code) . '&submitted=1');
            exit;
        }
        $error = (string)($result['message'] ?? 'Could not submit report.');
    }
}

ep_public_page_begin('Report a concern');
?>
<?php ep_public_disclaimer_banner(); ?>
<main class="container py-4" style="max-width:720px">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/wucportal/opportunities/index.php">Directory</a></li>
            <li class="breadcrumb-item"><a href="/wucportal/opportunities/view.php?code=<?= rawurlencode($code) ?>"><?= ep_h($code) ?></a></li>
            <li class="breadcrumb-item active">Report</li>
        </ol>
    </nav>

    <div class="ep-card">
        <h1 class="h4 mb-1">Report a concern</h1>
        <p class="ep-muted mb-3">Listing: <strong><?= ep_h((string)$opp['title']) ?></strong> (<code><?= ep_h($code) ?></code>)</p>
        <p class="small ep-muted">Reports are reviewed privately. Do not include national ID numbers or other sensitive personal data in this form.</p>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= ep_h('Report submitted. It will be reviewed privately.') ?></div>
            <a class="btn btn-outline-primary" href="/wucportal/opportunities/view.php?code=<?= rawurlencode($code) ?>">Back to listing</a>
        <?php else: ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= ep_h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/wucportal/opportunities/report.php?code=<?= rawurlencode($code) ?>">
                <input type="hidden" name="csrf_token" value="<?= ep_h($epPublicCsrf) ?>">
                <input type="hidden" name="code" value="<?= ep_h($code) ?>">
                <div class="visually-hidden" aria-hidden="true">
                    <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="complaint_type">Issue type <span class="text-danger">*</span></label>
                    <select class="form-select" id="complaint_type" name="complaint_type" required>
                        <option value="">Select…</option>
                        <?php foreach ($complaintTypes as $k => $label): ?>
                            <option value="<?= ep_h($k) ?>"<?= $old['complaint_type'] === $k ? ' selected' : '' ?>><?= ep_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Description <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="description" name="description" rows="5" required maxlength="4000"><?= ep_h($old['description']) ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="reporter_name">Your name (optional)</label>
                    <input class="form-control" type="text" id="reporter_name" name="reporter_name" maxlength="120" value="<?= ep_h($old['reporter_name']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="reporter_email">Your email (optional)</label>
                    <input class="form-control" type="email" id="reporter_email" name="reporter_email" maxlength="160" value="<?= ep_h($old['reporter_email']) ?>">
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">Submit report</button>
                    <a class="btn btn-outline-secondary" href="/wucportal/opportunities/view.php?code=<?= rawurlencode($code) ?>">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>
<?php ep_public_page_end(); ?>

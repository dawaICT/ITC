<?php
declare(strict_types=1);

/**
 * Skills and Enterprise Portal — print-friendly QR label for published opportunities.
 */

$epGuardMode = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.publish') && !ep_can($db, 'enterprise.approve')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/published.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/qr_helper.php';

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$base = '/wucportal/enterprise/management';

if ($code === '' || !preg_match('/^ENT-[A-Z0-9]{8}$/', $code)) {
    $_SESSION['flash_error'] = 'Invalid opportunity code.';
    header('Location: ' . $base . '/published.php');
    exit;
}

$opp = ep_get_opportunity_by_code($db, $code, true);
if (!$opp) {
    $_SESSION['flash_error'] = 'Published opportunity not found for this code.';
    header('Location: ' . $base . '/published.php');
    exit;
}

$publicUrl = ep_public_opportunity_url($code);
$qrDataUri = wuc_qr_svg_data_uri($publicUrl, 5);
$category = (string)($opp['category_name'] ?? 'Uncategorised');
$title = (string)$opp['title'];

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Label — <?= ep_h($code) ?></title>
    <style>
        :root { --ink: #1a1a1a; --muted: #555; --line: #ccc; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Georgia, "Times New Roman", serif; color: var(--ink); background: #f5f5f5; }
        .toolbar {
            font-family: system-ui, sans-serif;
            padding: 12px 16px;
            background: #fff;
            border-bottom: 1px solid var(--line);
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .toolbar a, .toolbar button {
            font-size: 14px;
            padding: 8px 14px;
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: var(--ink);
        }
        .toolbar button.primary { background: #6f42c1; border-color: #6f42c1; color: #fff; }
        .sheet {
            width: 100mm;
            min-height: 70mm;
            margin: 24px auto;
            background: #fff;
            border: 1px solid var(--line);
            padding: 12mm 10mm;
            text-align: center;
        }
        .brand { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
        .title { font-size: 18px; font-weight: 700; line-height: 1.25; margin: 0 0 6px; }
        .category { font-size: 13px; color: var(--muted); margin-bottom: 12px; }
        .qr { margin: 0 auto 12px; width: 42mm; height: 42mm; }
        .qr img { width: 100%; height: 100%; display: block; }
        .code { font-family: ui-monospace, Consolas, monospace; font-size: 14px; font-weight: 700; letter-spacing: 0.04em; }
        .url { margin-top: 8px; font-size: 9px; word-break: break-all; color: var(--muted); font-family: system-ui, sans-serif; }
        .note { margin-top: 10px; font-size: 8px; color: var(--muted); font-family: system-ui, sans-serif; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .sheet { margin: 0; border: none; width: 100%; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print()">Print label</button>
        <a href="<?= ep_h($base) ?>/published.php">Published list</a>
        <a href="<?= ep_h($publicUrl) ?>" target="_blank" rel="noopener">Public page</a>
    </div>

    <div class="sheet">
        <div class="brand">ITC · Skills &amp; Enterprise Portal</div>
        <h1 class="title"><?= ep_h($title) ?></h1>
        <div class="category"><?= ep_h($category) ?></div>
        <div class="qr">
            <?php if ($qrDataUri !== ''): ?>
                <img src="<?= ep_h($qrDataUri) ?>" alt="QR code for <?= ep_h($code) ?>">
            <?php else: ?>
                <div style="border:1px dashed #999;padding:20px;font-size:12px;">QR unavailable</div>
            <?php endif; ?>
        </div>
        <div class="code"><?= ep_h($code) ?></div>
        <div class="url"><?= ep_h($publicUrl) ?></div>
        <div class="note"><?= ep_h('Institutional verification does not guarantee employment, sales, funding or investment returns.') ?></div>
    </div>
</body>
</html>

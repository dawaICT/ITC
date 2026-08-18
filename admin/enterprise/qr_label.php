<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Print-friendly QR label.
 */

$page_title = 'QR Label — Skills-to-Trade';
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.publish');

$itemId = (int)($_GET['id'] ?? 0);
$base = '/wucportal/admin/enterprise';

if ($itemId <= 0) {
    wuc_set_flash('error', 'Invalid item.');
    header('Location: ' . $base . '/published_items.php');
    exit;
}

$item = eh_get_item($db, $itemId);
if (!$item) {
    wuc_set_flash('error', 'Item not found.');
    header('Location: ' . $base . '/published_items.php');
    exit;
}

$publicUrl = eh_public_item_url((string)$item['public_code']);
$qrDataUri = wuc_qr_svg_data_uri($publicUrl, 5);
$category = (string)($item['category_name'] ?? 'Uncategorised');
$title = (string)$item['title'];
$code = (string)$item['public_code'];

// Standalone print layout (no portal chrome) for clean labels.
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Label — <?php echo eh_h($code); ?></title>
    <style>
        :root {
            --ink: #1a1a1a;
            --muted: #555;
            --line: #ccc;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background: #f5f5f5;
        }
        .toolbar {
            font-family: system-ui, sans-serif;
            padding: 12px 16px;
            background: #fff;
            border-bottom: 1px solid var(--line);
            display: flex;
            gap: 8px;
            align-items: center;
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
        .toolbar button.primary {
            background: #6f42c1;
            border-color: #6f42c1;
            color: #fff;
        }
        .sheet {
            width: 100mm;
            min-height: 70mm;
            margin: 24px auto;
            background: #fff;
            border: 1px solid var(--line);
            padding: 12mm 10mm;
            text-align: center;
        }
        .brand {
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .title {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.25;
            margin: 0 0 6px;
        }
        .category {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 12px;
        }
        .qr {
            margin: 0 auto 12px;
            width: 42mm;
            height: 42mm;
        }
        .qr img {
            width: 100%;
            height: 100%;
            display: block;
        }
        .code {
            font-family: ui-monospace, Consolas, monospace;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .url {
            margin-top: 8px;
            font-size: 9px;
            word-break: break-all;
            color: var(--muted);
            font-family: system-ui, sans-serif;
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .sheet {
                margin: 0;
                border: none;
                width: 100%;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print()">Print label</button>
        <a href="<?php echo eh_h($base); ?>/review.php?id=<?php echo (int)$itemId; ?>">Back to review</a>
        <a href="<?php echo eh_h($base); ?>/published_items.php">Published list</a>
    </div>

    <div class="sheet">
        <div class="brand">WUC · Skills-to-Trade Hub</div>
        <h1 class="title"><?php echo eh_h($title); ?></h1>
        <div class="category"><?php echo eh_h($category); ?></div>
        <div class="qr">
            <?php if ($qrDataUri !== ''): ?>
                <img src="<?php echo eh_h($qrDataUri); ?>" alt="QR code for <?php echo eh_h($code); ?>">
            <?php else: ?>
                <div style="border:1px dashed #999;padding:20px;font-size:12px;">QR unavailable</div>
            <?php endif; ?>
        </div>
        <div class="code"><?php echo eh_h($code); ?></div>
        <div class="url"><?php echo eh_h($publicUrl); ?></div>
    </div>
</body>
</html>
